<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class UserProfile_Front extends UserProfile{
    CONST TAX_STATUS_TAB_SLUG = 'tax-status';
    CONST NONCE_ACTION = 'my_account_update_tax_status';
    CONST NONCE_NAME = TAXJAR_EXPANSION_PLUGIN_SLUG . '-tax_status_nonce';

    public function __construct(){
        add_action( 'init', [$this, 'create_exempt_role'] );

        add_action( 'init', [$this, 'register_my_account_tax_exemption_tab_url'] );
		// add_filter( 'query_vars', [$this, 'tax_exemption_query_vars'] );
        add_filter( 'woocommerce_account_menu_items', [$this, 'add_tax_exemption_tab'] );
        add_action( 'woocommerce_account_' . self::TAX_STATUS_TAB_SLUG . '_endpoint', [$this, 'print_fields_on_front_end'] );
        add_action( 'template_redirect',[$this, 'my_account_save_tax_status'] );
        
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_scripts'] );		
    }

	/**
	 * Register the tax status tab URL
	 * 
	 * @return void
	 */
	public function register_my_account_tax_exemption_tab_url(){
		add_rewrite_endpoint(
			self::TAX_STATUS_TAB_SLUG, 
			EP_ROOT | EP_PAGES 
		);
	}
	
	/**
	 * Add the query vars for the tax status tab
	 * 
	 * @param array $vars
	 * @return array $vars
	 */
	public function tax_exemption_query_vars( $vars ){
		$vars[] = self::TAX_STATUS_TAB_SLUG;
		return $vars;
	}
	
	/**
	 * Add the tax exemption tab to the My Account page
	 * 
	 * @param array $items
	 * @return array $items
	 */
	public function add_tax_exemption_tab( $items ){
		return
			array_slice( $items, 0, -1 )
			+  [ self::TAX_STATUS_TAB_SLUG => 'Tax Status' ]
			+ array_slice( $items, -1 );		
	}
	
	/**
	 * Print the tax status form fields on the front end
	 * 
	 * @param int $user_id
	 * @return void
	 */
	public function print_fields_on_front_end( $user_id = false ){
		$user_id = $user_id ?: get_current_user_id();
		
		$exempt_status = $this->get_user_exemption_type($user_id) ? '<span class="status-tax-exempt">Exempt</span>' : '<span class="status-non-exempt">Non-exempt</span>';		
		
		$certificate = $this->get_user_certificate( $user_id );
		$is_501c3 = $this->get_user_501c3_status( $user_id ) ? 1 : 0;
		$expiration = $this->get_user_expiration( $user_id );
		if( $expiration ) $expiration = date( 'Y-m-d', $expiration );
		?>
		<h6>Tax Status: <em><?php echo $exempt_status ?></em></h6>
		<hr>
		<form class="woocommerce-EditAccountForm <?php echo self::TAX_STATUS_TAB_SLUG ?>" action="" method="post" enctype="multipart/form-data">
			<fieldset>
				<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
					<label>Tax Exempt Certificate</label>
					<?php if( 
						$certificate
						&& isset( $certificate['url'], $certificate['label'] ) 
						&& $certificate['url'] 
						&& $certificate['label'] 
					){
						$button_label = 'Replace';
						$current_filename = $certificate['label'];
						$url = $this->download_certificate( $user_id );
						// NOTE: Note revealing the download url to the customer could be a privacy risk for customers discovering all other tax certificates and more. So only reveal the filename for the moment.
						$link = '<em>' . esc_html( $current_filename ) . '</em>';
						$link = '<a href="' . esc_url( $url ) . '" target="_blank">' . $link . '</a>';
					} else {
						$button_label = 'Upload';
						$current_filename = '';
						$url = '';
						$link = '';

					} ?>

					<label for="<?php echo self::IDS['certificate'] ?>" ><?php echo esc_html( $button_label ); ?></label>
					<span id="certificate_name"><?php echo $link; ?></span>
					<input type="file" accept='image/*,.pdf' class="" name="<?php echo self::IDS['certificate'] ?>" id="<?php echo self::IDS['certificate'] ?>" />

					<label for="<?php echo self::IDS['expiration'] ?>" class="<?php echo $is_501c3 ? 'grayed_out' : '' ?>">Expiration</label>
					<input 
						type="date" 
						class="woocommerce-Input woocommerce-Input--date input-date<?php echo $is_501c3 ? ' grayed_out' : '' ?>" 
						name="<?php echo self::IDS['expiration'] ?>" 
						id="<?php echo self::IDS['expiration'] ?>" 
						value="<?php echo esc_attr( $expiration ); ?>" 
						<?php echo $is_501c3 ? 'disabled' : '' ?>
					/>
					<br /><span class="description<?php echo $is_501c3 ? ' grayed_out' : '' ?>">Good through the end of this date.</span>
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
					<label for="<?php echo self::IDS['501c3'] ?>">Non-expiring 501c3 Certificate</label>
					<input type="checkbox" id="<?php echo self::IDS['501c3'] ?>" name="<?php echo self::IDS['501c3'] ?>" value="<?php echo esc_attr( $is_501c3 ); ?>" <?php checked( $is_501c3, 1 ); ?> /> <span>Is this a 501c3 Certificate without an expiration?</span>

				</p>
				<div class="clear" style="padding-bottom:1em"></div>
			</fieldset>
			<p>
				<?php
					wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				?>
				<button type="submit" class="woocommerce-Button button" name="my_account_save_tax_status" value="Save changes">Save changes</button>
				<input type="hidden" name="action" value="my_account_save_tax_status">
			</p>
		</form>
		<?php
	}
	
	/**
	 * Save the custom fields for the tax status tab
	 * 
	 * @return void
	 */
	public function my_account_save_tax_status(){
		if ( 
			! isset( $_POST['action'] ) 
			|| 	'my_account_save_tax_status' !== $_POST['action'] 
			|| 	! isset( $_POST['_wp_http_referer'] )
			|| 	$_POST['_wp_http_referer'] !== '/my-account/' . self::TAX_STATUS_TAB_SLUG . '/'
			|| 	! isset( $_POST[self::NONCE_NAME] )
			|| 	! wp_verify_nonce( $_POST[self::NONCE_NAME], self::NONCE_ACTION )
		) {
			return;
		}

		wc_nocache_headers();

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
            $this->save_custom_fields( $user_id );
        }
	}

	/**
	 * Determine if the current page is the tax status page
	 * 
	 * @return bool
	 */
    public function is_tax_status_page(){
        return is_account_page();
    }

	/**
	 * Enqueue the scripts and styles for the tax status tab
	 * 
	 * @return void
	 */
    public function enqueue_scripts(){
        if( $this->is_tax_status_page() ){
            wp_enqueue_style(
                TAXJAR_EXPANSION_PLUGIN_SLUG . '-profile-tax-status-tab',
                TAXJAR_EXPANSION_PLUGIN_URL . 'assets/css/profile-tax-status-tab.css',
                [],
                filemtime( TAXJAR_EXPANSION_PLUGIN_PATH . 'assets/css/profile-tax-status-tab.css' ),
                false // In footer?
            );

            wp_enqueue_script(
                TAXJAR_EXPANSION_PLUGIN_SLUG . '-profile-tax-status-tab',
                TAXJAR_EXPANSION_PLUGIN_URL . 'assets/js/profile-tax-status-tab.js',
                ['jquery'],
                filemtime( TAXJAR_EXPANSION_PLUGIN_PATH . 'assets/js/profile-tax-status-tab.js' ),
                false // In footer?
            );
        }
    }
}