<?php
if ( ! defined( 'ABSPATH' ) )  exit; // Exit if accessed directly
/*
Plugin Name: TaxJar - Sales Tax Automation for WooCommerce - Expansion
Plugin URI: http://www.danielpurifoy.com
Description: Enhances the capabilities of the TaxJar plugin to make it more fully integrated with WooCommerce. • Add certificate upload & expiration dates to user profile, which can be sent to Zapier. • Add support to sync additional order statuses. • Auto-assign a default tax exempt status based on certificate & expiration. • Use Zapier to pass expiration date updates directly to TaxJar, Sheets, etc. • Use Zapier to copy the certificate file to Dropbox, AWS, etc. • Create a temporary tax exempt period for all users (for onboarding) • Create a user role for those who are tax exempt for helping with conditional theme elements and settings.
Version: 1.6.1
Requires at least: 5.5
Requires PHP: 7.3
Author: Daniel Purifoy
******************
*/

//include 'dap.php';
class dap_woocommerce_taxjar_expansion{
	private static $instance;
	private $slug = 'taxjar_expansion';
	private $role = 'tax_exempt';
	private $my_account_tax_status_page_slug = 'tax-status';
	private $log_active = false;
	private $plugin_url;
	private $plugin_dir;
	private $settings;
	private $certificates_path;
	private $certificates_url;
	CONST TAX_EXEMPTION_TYPE_META_KEY = 'tax_exemption_type';
	
	public static function get_instance(){
		if( null === self::$instance ){
			self::$instance = new self();
		}
		return self::$instance;
	}	
	
	private function __construct(){
		/*// ONLY FOR TESTING. FORCES TAXJAR TO BE ACTIVISH EVEN WITHOUT AN API KEY
		add_filter( 'taxjar_enabled', '__return_true', PHP_INT_MAX);
		add_filter( 'taxjar_nexus_check', '__return_true', PHP_INT_MAX );
		/**/

		/*** Plugin Framework ***/
		// Set Static Variables
		$this->plugin_dir = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );
		
		// Basics
		register_activation_hook( __FILE__, [$this, 'activate'] );
		add_filter( 'plugin_action_links', [$this, 'action_links'], 10, 2 );		
		add_action( 'admin_notices', [$this, 'dependency_notification'] );
		
		// Scripts & Styles
		add_action( 'init', [$this, 'register_scripts'] );
		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_scripts'] );		
		add_action( 'admin_init', [$this, 'register_scripts_admin'] );
		add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts_admin'] );

		// Misc
		add_action( 'init', [$this, 'create_exempt_role'] );
		add_action( 'admin_init', [$this, 'create_exempt_role'] );
		
		/*** SETTINGS ***/
		$this->get_settings();
		add_filter( 'woocommerce_get_settings_taxjar-integration', [$this, 'print_additional_taxjar_settings'] );
		add_action( 'wp_login', [$this, 'recalculate_cart_totals'], 10, 2 );
		add_action( 'wp_logout', [$this, 'recalculate_cart_totals'] );

		/*** ADMIN: PROFILE ***/
		add_action( 'user_edit_form_tag', [$this, 'print_profile_form_tags'] );
		// This ensures our action is registered just after TaxJar's so the sections are together in Edit User
		add_action( 'woocommerce_integrations_init', function(){
			add_action( 'edit_user_profile', [$this, 'print_fields_on_back_end'] );
			add_action( 'show_user_profile', [$this, 'print_fields_on_back_end'] );
		},21);

		// System will be looking for disabled fields, but won't find them. Add this in so it doesn't through an error about the key not existing.
		add_action( 'personal_options_update', [$this, 'patch_disabled_select_on_back_end'] ); 
		add_action( 'edit_user_profile_update', [$this, 'patch_disabled_select_on_back_end'] );

		// Save back end custom meta. Must happen after 'tax_exemption_type' is saved by taxjar in order to override it.
		add_action('personal_options_update', [$this, 'save_custom_fields'], 11 ); 
		add_action('edit_user_profile_update', [$this, 'save_custom_fields'], 11 );
		
		/*** FRONT END: MY ACCOUNT ***/
		add_action( 'init', [$this, 'register_my_account_tax_exemption_tab_url'] );
//		add_filter( 'query_vars', [$this, 'tax_exemption_query_vars'] );
		add_filter( 'woocommerce_account_menu_items', [$this, 'add_tax_exemption_tab'] );
		add_action( 'woocommerce_account_' . $this->my_account_tax_status_page_slug . '_endpoint', [$this, 'print_fields_on_front_end'] );
		add_action( 'template_redirect',[$this, 'my_account_save_tax_status'] );

		/*** Cart ***/
		add_filter( 'taxjar_cart_exemption_type', [$this, 'set_cart_exemption_type_to_patch_cache_problem'], 10, 2 );

		/*** TEMPORARY ALL-TAX FREE OVERRIDE ***/	
		// Filter the options that tell TaxJar to calculate taxes.
		add_filter( 'option_woocommerce_taxjar-integration_settings' , [ $this, 'maybe_turn_taxjar_calculations_off' ], PHP_INT_MAX );
		add_filter( 'option_woocommerce_calc_taxes' , [ $this, 'maybe_turn_wc_tax_calculations_off' ], PHP_INT_MAX );
		
		/*** ADDITIONAL INTEGRATIONS ***/
		add_action( 'dap_do_file_offloaded', [$this, 'update_offloaded_url'], 10, 4 );

		/*** ALLOW OTHER ORDER STATUSES TO SYNC ***/
		add_filter( 'taxjar_valid_order_statuses_for_sync', [$this, 'add_additional_order_statuses_to_sync'] );
		
	}
	
	/* Plugin Framework */
	
	// Basics
	public function activate(){
		// Need to flush rewrites to handle the newly registered My Account/Tax Status url properly.
		add_action( 'init', function(){
			flush_rewrite_rules();
		}, 99);
	}
	
	public function action_links( $links, $file ) {
		if ( $file == plugin_basename(__FILE__) ) {
			$settings_link = '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=taxjar-integration#taxjar_expansion[default_status]">Settings</a>';
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	public function dependency_notification(){
		if( ! class_exists('MDMR_Checklist_Controller') ){ ?>
			<div class="notice notice-warning is-dismissible">
				<p><strong>Taxjar Expansion</strong> recommends using <a href="https://wordpress.org/plugins/multiple-roles/" target="_blank">Multiple Roles</a> by Christian Neumann to properly support the <em>Tax Exempt</em> user role functionality.</p>
			</div>
		<?php }
	}

	// Scripts & Styles
	public function register_scripts(){
		$uploads_location = wp_upload_dir();
		$sub_folder = '/tax-certificates/';
		$this->certificates_path = $uploads_location['basedir'] . $sub_folder;
		$this->certificates_url = $uploads_location['baseurl'] . $sub_folder;

		wp_register_script( 
			$this->slug,
			$this->plugin_url . 'assets/script.js',
			['jquery'],
			filemtime( $this->plugin_dir . 'assets/script.js' ),
			false // In footer?
		);
		wp_register_style( 
			$this->slug,
			$this->plugin_url . 'assets/style.css',
			[],
			filemtime( $this->plugin_dir . 'assets/style.css' ),
			'all' // Which screen format does this apply to? all || print || screen || etc
		);
	}
	public function enqueue_scripts(){		
		wp_enqueue_script( $this->slug );
		wp_enqueue_style( $this->slug );
	}
	
	public function register_scripts_admin(){
		wp_register_script( 
			$this->slug . '-user-edit',
			$this->plugin_url . 'assets/user-edit.js',
			['jquery'],
			filemtime( $this->plugin_dir . 'assets/user-edit.js' ),
			false // In footer?
		);
		wp_register_style( 
			$this->slug . '-user-edit',
			$this->plugin_url . 'assets/user-edit.css',
			[],
			filemtime( $this->plugin_dir . 'assets/user-edit.css' ),
			'all' // Which screen format does this apply to? all || print || screen || etc
		);
		wp_localize_script(
			$this->slug . '-user-edit',
			'taxjarExpansion',
			[
				'autoAssign' => $this->settings['default_status'],
			]
		);
		wp_register_script( 
			$this->slug . '-settings',
			$this->plugin_url . 'assets/settings.js',
			['jquery'],
			filemtime( $this->plugin_dir . 'assets/settings.js' ),
			false // In footer?
		);
	}
	public function enqueue_scripts_admin(){
		// Check if this is the user edit or profile edit page
		$screen = get_current_screen();
		if( 
			$screen 
			&& isset( $screen->id ) 
			&& in_array( $screen->id, ['user-edit', 'profile'] )
		){
			wp_enqueue_script( $this->slug . '-user-edit' );
			wp_enqueue_style( $this->slug . '-user-edit' );

			// localize constants
			wp_localize_script(
				$this->slug . '-user-edit',
				'taxjarExpansion',
				[
					'taxExemptionTypeMetaKey' => self::TAX_EXEMPTION_TYPE_META_KEY,
				]
			);
			return;
		}

		// Check if this is the TaxJar settings page
		if( 
			$screen 
			&& isset( $screen->id ) 
			&& $screen->id == 'woocommerce_page_wc-settings'
			&& isset( $_GET['tab'] )
			&& $_GET['tab'] == 'taxjar-integration'
		){
			wp_enqueue_script( $this->slug . '-settings' );
			return;
		}
	}

	// Misc
	public function create_exempt_role(){
		add_role(
			$this->role,
			'Tax Exempt'
		);
	}
	
	private function log($message, $line = false ){
		if( $this->log_active ){
			error_log( '-----------------' );
			error_log( __FILE__ . '::' . $line );
			error_log( print_r( $message, true ) );
		}
	}
	
	/*** SETTINGS ***/
	
	// Add settings fields to TaxJar's settings
	public function print_additional_taxjar_settings( $settings ){
		
		$exemption_options = [
			'' 				=> '(Disabled)',
			'wholesale' 	=> 'Wholesale', 
			'government' 	=> 'Government', 
			'other' 		=> 'Other',
		];

		$order_statuses = wc_get_order_statuses();
		
		$expansion_settings = [
			[
				'title' => 'Taxjar Expansion Settings',
				'type'  => 'title',
				'desc'  => '',
			],
			[
				'title'	=> 'Statuses to Sync',
				'type'	=> 'multiselect',
				'desc'	=> 'Select which order statuses should be synced to TaxJar.',
				'class'	=> $this->slug.'-statuses_to_sync',
				'id'	=> $this->slug.'[statuses_to_sync]',
				'options' => $order_statuses,
				'default' => ['wc-completed','wc-refunded'],
			],
			[
				'title'   => 'Default Exempt Status',
				'type'    => 'select',
				'desc'    => 'If this is enabled, the user will be auto-assigned theis status once they have uploaded a certificate and set a valid expiration date or indicated the certificate is a 501c3 certificate.',
				'id'      => $this->slug.'[default_status]',
				'options' => $exemption_options,
			],
			[
				'title'   => 'Expiration Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When expiration date is updated, send data to Zapier.',
				'id'      => $this->slug.'[expiration_zap]',
			],
			[
				'title'   => '501c3 Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When 501c3 type is updated, send data to Zapier.',
				'id'      => $this->slug.'[501c3_zap]',
			],
			[
				'title'   => 'Certificate Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When certificate is uploaded, send data to Zapier.',
				'id'      => $this->slug.'[certificate_zap]',
			],
			[
				'title'   => 'Exempt Status Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When status is changed, send data to Zapier.',
				'id'      => $this->slug.'[exempt_status_zap]',
			],
			[
				'title'		=> 'All exempt until',
				'type'		=> 'date',
				'desc'		=> 'Prevents WooCommerce and TaxJar from calculating taxes until after this date.',
				'id'		=> $this->slug.'[override_cutoff]'
			],

		];
		$expansion_settings[] =
			[
				'type' => 'sectionend',
			];

		return array_merge($settings, $expansion_settings);
	}
	
	// Update $this->settings property.
	private function get_settings(){
		$default = [
			'statuses_to_sync' => ['wc-completed','wc-refunded'],
			'default_status' => '',
			'expiration_zap' => false,
			'501c3_zap' => false,
			'certificate_zap' => false,
			'exempt_status_zap' => false,
			'override_cutoff' => 'Aug 11, 1984 12:00am',
		];
		$this->settings = array_merge( $default, get_option( $this->slug, [] ) );
		
		$this->settings['override_cutoff'] = strtotime( $this->settings['override_cutoff'] );		
	}
	
	/*** ADMIN: PROFILE ***/
	public function print_profile_form_tags(){
		?>
			enctype="multipart/form-data"
		<?php
	}
	public function print_fields_on_back_end( $user ){
		$user_id = $user->ID;

		$certificate = get_user_meta( $user_id, $this->slug . '-certificate', true );
		$is_501c3 = get_user_meta( $user_id, $this->slug . '-501c3', true ) ? 1 : 0;
		$expiration = get_user_meta( $user_id, $this->slug . '-expiration', true );
		if( $expiration ) $expiration = date( 'Y-m-d', $expiration );
		?>
		<h2>TaxJar Sales Tax Exemptions Expansion</h2>
		<table class="form-table">
			<tr>
				<th><label>Tax Exempt Certificate</label></th>
				<?php 

				/*
				The label for #certificate looks like a button and is clickable to trigger the certificate file input.
				The #certificate-name is the text that displays the current filename & is updated with the filename when a file is selected.
				The #remove-certificate is the "x" following #certificate-name that removes the certificate by the following process:
					1. The #remove-certificate is hidden.
					2. When the #certificate-name is emptied.
					3. The #delete-cert input is populated with the word "true"

				When a file is uploaded:
					1. The #certificate-name is updated with the filename.
					2. #remove-certificate is shown.
					3. #delete-cert is emptied.

				Upon loading of the page, the #certificate-name is populated with the current filename, but the #certificate file input is empty. Therefore we are using a delete cert flag to know the difference between an empty certificate that should be a left alone and a certificate that should be deleted.
				Upon saving, it will only delete the certificate if #delete-cert is populated with the word "true".
				*/

				$remove_button_visible = false;
				if( 
					$certificate
					&& isset( $certificate['url'], $certificate['label'] ) 
					&& $certificate['url'] 
					&& $certificate['label'] 
				){
					$button_label = 'Replace';
					$current_filename = $certificate['label'];
					$url = $certificate['url'];
					$link = '<a href="' . $url . '" target="_blank" download >' . $current_filename . '</a>';
					$remove_button_visible = true;
			
				} else {
					$button_label = 'Upload';
					$current_filename = '';
					$url = '';
					$link = '';
				} 				
				?>
				<td>
					<label for="<?php echo $this->slug ?>-certificate" class="button button-secondary"><?php echo $button_label; ?></label>
					<p>
						<span id="certificate_name" class="description"><?php echo $link; ?></span> 
						<span id="<?php echo $this->slug ?>-remove-certificate" style="<?php echo $remove_button_visible ? '' : 'display:none;' ?>">x</span></p>
					<input type="file" accept='image/*,.pdf' class="" name="<?php echo $this->slug ?>-certificate" id="<?php echo $this->slug ?>-certificate" />
					<input type="text" class="" name="<?php echo $this->slug ?>-delete-cert" id="<?php echo $this->slug ?>-delete-cert" />
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo $this->slug ?>-501c3">Non-expiring 501c3 Certificate</label></th>
				<td>
					<input type="checkbox" id="<?php echo $this->slug ?>-501c3" name="<?php echo $this->slug ?>-501c3" value="<?php echo $is_501c3; ?>" <?php checked( $is_501c3, 1 ); ?> />
					<p class="description">Is this a 501c3 Certificate without an expiration?</p>
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo $this->slug ?>-expiration">Expiration</label></th>
				<td>
					<input 
						type="date" 
						class="woocommerce-Input woocommerce-Input--date input-date<?php echo $is_501c3 ? ' grayed_out' : '' ?>" 
						name="<?php echo $this->slug ?>-expiration" 
						id="<?php echo $this->slug ?>-expiration" 
						value="<?php echo $expiration; ?>"
						<?php echo $is_501c3 ? 'disabled' : '' ?>
					/>
					<p class="description<?php echo $is_501c3 ? ' grayed_out' : '' ?>">Good through the end of this date.</p>
				</td>
			</tr>
		</table>
		<?php
	}
	
	// We're disabling this field in js, so it won't generate a $_POST item like it should. We need to create the key so TaxJar plugin doesn't throw any errors.
	public function patch_disabled_select_on_back_end( $user_id ){
		if( $this->settings['default_status'] ){
			if( ! isset($_POST[self::TAX_EXEMPTION_TYPE_META_KEY] ) )
				// It doesn't actually matter what we send since we're auto-assigning later, but it must be set.
				$_POST[self::TAX_EXEMPTION_TYPE_META_KEY] = false;
		}
		if( ! isset($_POST[$this->slug . '-expiration'] ) ){
			$_POST[ $this->slug . '-expiration' ] = false;
		}
	}
	
	/*** FRONT END: MY ACCOUNT ***/
	
	// Register the URL with Wordpress
	public function register_my_account_tax_exemption_tab_url(){
		add_rewrite_endpoint(
			$this->my_account_tax_status_page_slug, 
			EP_ROOT | EP_PAGES 
		);
	}
	
	// No clue what this does, but some website said I needed it.
	public function tax_exemption_query_vars( $vars ){
		$vars[] = $this->my_account_tax_status_page_slug;
		return $vars;
	}
	
	// Add the actual tab
	public function add_tax_exemption_tab( $items ){
		return
			array_slice( $items, 0, -1 )
			+  [ $this->my_account_tax_status_page_slug => 'Tax Status' ]
			+ array_slice( $items, -1 );		
	}
	
	// Print fields
	public function print_fields_on_front_end( $user_id = false ){
		$user_id = $user_id ?: get_current_user_id();
		
		$exempt_status = get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true ) ? '<span class="status-tax-exempt">Exempt</span>' : '<span class="status-non-exempt">Non-exempt</span>';		
		
		$certificate = get_user_meta( $user_id, $this->slug . '-certificate', true );
		$is_501c3 = get_user_meta( $user_id, $this->slug . '-501c3', true ) ? 1 : 0;
		$expiration = get_user_meta( $user_id, $this->slug . '-expiration', true );
		if( $expiration ) $expiration = date( 'Y-m-d', $expiration );
		?>
		<h6>Tax Status: <em><?php echo $exempt_status ?></em></h6>
		<hr>
		<form class="woocommerce-EditAccountForm <?php echo $this->my_account_tax_status_page_slug ?>" action="" method="post" enctype="multipart/form-data">
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
						$url = $certificate['url'];
						// Note revealing the download url to the customer could be a privacy risk for customers discovering all other tax certificates and more.
						$link = '<em>' . $current_filename . '</em>';

					} else {
						$button_label = 'Upload';
						$current_filename = '';
						$url = '';
						$link = '';

					} ?>

					<label for="<?php echo $this->slug ?>-certificate" ><?php echo $button_label; ?></label>
					<span id="certificate_name"><?php echo $link; ?></span>
					<input type="file" accept='image/*,.pdf' class="" name="<?php echo $this->slug ?>-certificate" id="<?php echo $this->slug ?>-certificate" />

					<label for="<?php echo $this->slug ?>-expiration" class="<?php echo $is_501c3 ? 'grayed_out' : '' ?>">Expiration</label>
					<input 
						type="date" 
						class="woocommerce-Input woocommerce-Input--date input-date<?php echo $is_501c3 ? ' grayed_out' : '' ?>" 
						name="<?php echo $this->slug ?>-expiration" 
						id="<?php echo $this->slug ?>-expiration" 
						value="<?php echo $expiration; ?>" 
						<?php echo $is_501c3 ? 'disabled' : '' ?>
					/>
					<br /><span class="description<?php echo $is_501c3 ? ' grayed_out' : '' ?>">Good through the end of this date.</span>
				</p>
				<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
					<label for="<?php echo $this->slug ?>-501c3">Non-expiring 501c3 Certificate</label>
					<input type="checkbox" id="<?php echo $this->slug ?>-501c3" name="<?php echo $this->slug ?>-501c3" value="<?php echo $is_501c3; ?>" <?php checked( $is_501c3, 1 ); ?> /> <span>Is this a 501c3 Certificate without an expiration?</span>

				</p>
				<div class="clear" style="padding-bottom:1em"></div>
			</fieldset>
			<p>
				<?php
					wp_nonce_field( 'my_account_update_tax_status', $this->slug . '-tax_status_nonce' );
				?>
				<button type="submit" class="woocommerce-Button button" name="my_account_save_tax_status" value="Save changes">Save changes</button>
				<input type="hidden" name="action" value="my_account_save_tax_status">
			</p>
		</form>
		<?php
	}
	
	public function my_account_save_tax_status(){
		if ( 
				! isset( $_POST['action'] ) 
			|| 	'my_account_save_tax_status' !== $_POST['action'] 
			|| 	! isset( $_POST['_wp_http_referer'] )
			|| 	$_POST['_wp_http_referer'] !== '/my-account/tax-status/'
			|| 	! isset( $_POST['taxjar_expansion-tax_status_nonce'] )
			|| 	! wp_verify_nonce( $_POST[ 'taxjar_expansion-tax_status_nonce' ], 'my_account_update_tax_status' )
		) {
			return;
		}

		wc_nocache_headers();

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}
		
		$this->save_custom_fields( $user_id );
	}
	
	/*** ADMIN + FRONT END USER META ***/

	// Saves meta entered into back end or front end.
	public function save_custom_fields( $user_id ){
		$zaps = [];

		// Certificate Upload
		$upload = $_FILES[$this->slug . '-certificate'] ?? false;
		if( 
			$upload 
			&& $upload['tmp_name']
			&& $upload['name']
		){
			// Ensure the folder exists
			$target_dir = $this->certificates_path . $user_id;
			if( file_exists( $target_dir ) || mkdir( $target_dir, 0777, true ) ){

				$extension = '.' . pathinfo( $upload['name'], PATHINFO_EXTENSION );
				$unique_name = time() . '-' . substr($upload['name'], 0, -strlen($extension) ) . $extension;
				$unique_name = filter_var($unique_name, FILTER_SANITIZE_URL);
				$target_path = $target_dir . '/' . $unique_name;
				$target_url = $this->certificates_url . $user_id . '/' . $unique_name;

				if (
					file_exists( $upload["tmp_name"] )
					&& @move_uploaded_file( $upload["tmp_name"], $target_path ) 
				){
					// if( !is_admin() ) wc_add_notice( "Certificate file ". htmlspecialchars( basename( upload["name"])). " successfully uploaded." );
					$certificate = [
						'path' => $target_path,
						'url' => $target_url,
						'label' => $upload["name"],
						'name' => $unique_name,
					];
					$old_cert = get_user_meta( $user_id, $this->slug . '-certificate', true );
					if( 
						! $old_cert
						|| ! is_array( $old_cert )
						|| ! isset( $old_cert['url'] )
						|| ! $certificate['url'] != $old_cert['url']
					){
						$zaps['certificate_zap'] = [
							'certificate' => $certificate,
							'user_id'	=> $user_id,
						];
						$updated_certificate = update_user_meta( 
							$user_id, 
							$this->slug . '-certificate', 
							$certificate
						);
					}
				} else {
					if( !is_admin() ) {
						wc_add_notice( 'Certificate upload failed!', 'error' );
					} else {
						$this->log( 'Certificate upload failed!', __LINE__ );
					}
				}
			} else {
				if( !is_admin() ){
					wc_add_notice( 'Unable to create certificate directory!', 'error');
				} else {
					$this->log( 'Unable to create certificate directory!', __LINE__ );
				}
			}
		} else if(
			isset( $_POST[$this->slug . '-delete-cert'] )
			&& $_POST[$this->slug . '-delete-cert'] == 'true'
		){
			/* Saving these so we can inspect or recover them if needed for auditing purposes.
			// Find the previous cert and delete it
			$certificate = get_user_meta( $user_id, $this->slug . '-certificate', true );
			if( 
				$certificate
				&& isset( $certificate['path'] )
				&& file_exists( $certificate['path'] )
			){
				unlink( $certificate['path'] );
			}
			*/
			$zaps['certificate_zap'] = [
				'certificate' => false,
				'user_id'	=> $user_id,
			]; 
			$updated_certificate = delete_user_meta( 
				$user_id, 
				$this->slug . '-certificate'
			);
		}

		// 501c3 Exemption
		$is_501c3 = isset( $_POST[$this->slug . '-501c3'] ) ? true : false;
		if( 
			$is_501c3 != get_user_meta( $user_id, $this->slug . '-501c3', true )
		){
			$zaps['501c3_zap'] = [
				'user_id' => $user_id,
				'501c3' => $is_501c3,
			];
			$updated_501c3 = update_user_meta( 
				$user_id, 
				$this->slug . '-501c3', 
				$is_501c3
			);
		}

		// Expiration Date
		if( $is_501c3 ){
			$expiration = false;
		} else if( isset( $_POST[$this->slug . '-expiration'] ) ){
			$expiration = $_POST[$this->slug . '-expiration'];
			$expiration = $expiration ? strtotime( $expiration . ' 11:59:59 PM' ) : false;
			if( $expiration && $expiration < time() ){
				if( !is_admin() ){
					wc_add_notice( 'Certificate has expired.', 'error' );
				}
			}
		}
		if( 
			isset( $expiration ) 
			&& $expiration != get_user_meta( $user_id, $this->slug . '-expiration', true )
		){
			$zaps['expiration_zap'] = [
				[
					'timestamp' 	=> $expiration,
					'Y-m-d' 		=> $expiration ? date( 'Y-m-d', $expiration ) : false,
					'date' 			=> $expiration ? date( 'M d, Y', $expiration) : false,
					'user_id'		=> $user_id,
					]
			];
			$updated_expiration = update_user_meta(
				$user_id, 
				$this->slug . '-expiration',
				$expiration
			);
		}

		// If any of these were modified, then recheck exemption status
		if( 
			isset( $updated_certificate )
			|| isset( $updated_501c3 )
			|| isset( $updated_expiration )
		){
			$new_status = $this->update_exempt_status( $user_id, $certificate ?? null, $is_501c3, $expiration ?? null );
			if( $new_status !== null ){
				$zaps['exempt_status_zap'] = [
					'status' => $new_status,
					'uset_id' => $user_id
				];
			}
		}

		foreach( $zaps as $zap => $data ){
			$this->send_to_zapier( $this->settings[ $zap ], $data );
		}
	}

	// Reset cart tax based on login
	// Args coordinated to make it compatable with a variety of hooks
	public function recalculate_cart_totals( $ignore = null, $user_id = false ){
		/* if( $user_id instanceOf WP_User ){
			$user_id = $user_id->ID;
		}
		if( $user_id && is_numeric( $user_id ) && $user_id != 0 ){
			$exempt = $this->is_tax_exempt_status( 
				get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true )
			);
		} else {
			$exempt = false;
		} /**/
		if ( class_exists( 'WooCommerce' ) ) {
			// Get the WooCommerce cart instance
			$cart = WC()->cart;
			if( $cart ){
				/*// Update customer exempt status in current session
				WC()->customer->set_is_vat_exempt($exempt); /**/

				// Get taxes to clear cached values
				$cart->get_taxes();

				// Recalculate the cart totals
				$cart->calculate_totals();
			}
		}
	}

	/*** Cart ***/
	/* This forces the cache to include the user's exemption type in the unique key generation, preventing the issue here: https://github.com/taxjar/taxjar-woocommerce-plugin/issues/243 */
	public function set_cart_exemption_type_to_patch_cache_problem( $exemption_type, $cart ){
		if( $cart ){
			if( $customer = $cart->get_customer() ){
				if( $user_id = $customer->get_id() ){
					$exemption_type = get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true );
				}
			}
		}
		return $exemption_type;
	}

	/*** TEMPORARY ALL-TAX FREE OVERRIDE ***/	
	
	public function maybe_turn_taxjar_calculations_off( $value ){		
		// Are we bypassing all calculations?
		if( 
			$this->in_override_period()
		){
			// Make sure we preserve the settings page
			if( 
				! ( 
					is_admin()
					&& isset($_GET['page'])
					&& $_GET['page'] == 'wc-settings'
				)
			){
				$value['enabled'] = $value['api_calcs_enabled'] = $value['save_rates'] = false;
			}
		}
		return $value;
	}
	public function maybe_turn_wc_tax_calculations_off( $value ){
		// Are we bypassing all calculations?
		if( 
			$this->in_override_period()
			&& $status = $this->tax_exempt_default_set()
		){
			// Make sure we preserve the settings page
			if( 
				! ( 
					is_admin()
					&& isset($_GET['page'])
					&& $_GET['page'] == 'wc-settings'
				)
			){
				$value = false;
			}
		}
		return $value;
	}
	
	private function in_override_period(){
		if( time() <= $this->settings['override_cutoff'] ){
			return true;
		} else {
			return false;
		}
	}
	
	private function tax_exempt_default_set(){
		return $this->is_tax_exempt_status( $this->settings['default_status'] );
	}
	
	/*** UTILITIES ***/
	
	//// EVALUATIONS ////
	
	/*
	 * Check that a given status is indeed tax exempt
	 *
	 * $status
	 *
	 * Returns: true | false
	 */
	private function is_tax_exempt_status( $status ){
		$tax_exempt_statuses = [
			'government',
			'wholesale',
			'other'
		];
		
		return in_array( $status, $tax_exempt_statuses );
	}
	
	/*
	 * Evaluate if a user should be exempt
	 * $user_id
	 *
	 * Return the new status:
	 * Null if no change
	 * New Status if changed and become or remains exempt
	 * False if changed to not exempt
	 */
	private function update_exempt_status( $user_id, $certificate = null, $is_501c3 = null, $expiration = null ){		
		// Are we auto_assigning? If not then bail.
		if( ! $this->settings['default_status'] ) return null;

		$certificate = $certificate !== null 
			? $certificate 
			: get_user_meta( $user_id, $this->slug . '-certificate', true );
		$expiration = $expiration !== null 
			? $expiration
			: get_user_meta( $user_id, $this->slug . '-expiration', true );
		$is_501c3 = $is_501c3 !== null 
			? $is_501c3 
			: get_user_meta( $user_id, $this->slug . '-501c3', true );
		// Check that all requirements are met.
		if( 
			$this->is_valid_certificate( $certificate )
			&&	$this->is_valid_expiration( $expiration, $is_501c3 )
		){	
			$exempt = true;
		} else {
			$exempt = false;
		}

		// Attempt to update the status of the user
		$update_status = $this->update_user_exemption_status( $user_id, $exempt );

		// If use wasn't changed because their status already matched, $update_status will be null.
		if( $update_status === null ){
			return $exempt;
		}

		// If the status was changed, then return the new status.
		return $update_status;
	}
	
	private function is_valid_certificate( $file ){
		if( is_array( $file ) ){
			if( ! isset( $file['url'] ) ){
				$file = false;
			} else {
				$file = $file['url'];
			}
		}
		// If there's no file
		if( ! $file || !filter_var( $file, FILTER_VALIDATE_URL ) ){
			$this->log( 'File location invalid: ' . $file, __LINE__ );
			return false;
		}
		return true;
	}
	
	private function is_valid_expiration( $timestamp, $is_501c3 = false ){
		if( $is_501c3 ){
			return true;
		}
		if( !$timestamp || $timestamp < time() ){
			$this->log( 'Expired tax certificate: ' . $timestamp . ' < ' . time(), __LINE__ );
			return false;
		}
		return true;
	}
		
	//// MAKE DATABASE CHANGES ////
	
	/*
	 * Update the meta for tax_exemption_type
	 * and assign the tax exempt role.
	 *
	 * $user_id
	 * $exempt = true | false
	 * 
	 * Returns null if no status change
	 * Returns true if new status status changed and is exempt
	 * Returns false if status changed to not exempt
	 */
	private function update_user_exemption_status( $user_id, $exempt ){
		$old_status = get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true );

		if( $exempt ){
			// Preserve user's exempt status or use fallback if isn't already exempt
			$status = $old_status ?: $this->settings['default_status'];
		} else {
			$status = '';
		}

		// Update Taxjar's meta field for the user
		update_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, $status );
		
		// Add tax exempt role
		$this->set_user_exempt_role( $user_id, $exempt );

		// Use this to trigger TaxJar's native sync with TaxJar API function.
		do_action( 'taxjar_customer_exemption_settings_updated', $user_id, $status );

		$this->sync_customer_with_taxjar($user_id, $status);

		// Fix the cart totals if needed.
		$this->recalculate_cart_totals( false, $user_id );

		// Can be the new status or false
		return $status;
	}

	/**
	 * Syncs a customer's exempt status with TaxJar
	 * 
	 * @param int $user_id
	 * @param string $new_status
	 * @return void
	 */
	private function sync_customer_with_taxjar( $user_id, $new_status ){
		$customer_record = new TaxJar_Customer_Record($user_id);
		$api_response = $customer_record->get_from_taxjar();
		// Make sure that $api_response is not a WP_Error
		if( is_wp_error( $api_response ) ){
			$this->log( $api_response->get_error_message(), __LINE__ );
			return;
		}
		
		// Local WP_User data
		$customer_record->load_object();
		$customer_data_from_local = $customer_record->get_data_from_object();

		// API data
		$api_data = json_decode( $api_response['body'], true );
		$customer_data_from_api = $api_data['customer'] ?? false;

		if( ! is_array( $customer_data_from_api ) ){
			$this->log( 'TaxJar API response not an array.', __LINE__ );
			return;
		}

		// Make sure the customer exists in the TaxJar API
		// $api_response['response']['code'] == 404 if customer not found
		if ( $customer_data_from_api ) {
			// Make sure that local data has the correct status
			if(
				! isset( $customer_data_from_local['exemption_type'] ) 
				|| $customer_data_from_local['exemption_type'] != $new_status 
			){
				$this->log( 'Local data does not have the correct status. Local data status: ' . $customer_data_from_local['exemption_type'] . '. Expected status: ' . $new_status . '.', __LINE__ );
				return;
			}

			// Sort arrays by key so we can compare them
			ksort( $customer_data_from_local );
			ksort( $customer_data_from_api );

			// Compare the local data to the API data
			if( $customer_data_from_local != $customer_data_from_api ){
				// If they don't match, update the API
				$customer_record->update_in_taxjar();
			}
		
			return;
		}

		// Create a new customer in TaxJar API
		// This shouldn't fire if TaxJar is creating a new TaxJar customer when the user is created.
		$customer_record->create_in_taxjar();
	}

	/**
	 * Add user's tax exempt role
	 * 
	 * @param int|string $user_id
	 * @param bool $status
	 *
	 * Grant any non false $status the tax exempt role. Supports
	 * Multiple Roles plugin and User Role Editor plugin.
	 */
	private function set_user_exempt_role( $user_id, $exempt ){
		// Multuple Roles plugin passes an array of role slugs, so we need to intercept it and add/remove the tax exempt role to suit our purposes.
		if( class_exists('MDMR_Checklist_Controller') ){
			// Are we updating anything that might affect user roles?
			if( isset( $_POST['md_multiple_roles'] ) ){
				if( $exempt ){
					if( ! in_array( $this->role, $_POST['md_multiple_roles'] ) ){
						$_POST['md_multiple_roles'][] = $this->role;
					}
				} else {
					if( in_array( $this->role, $_POST['md_multiple_roles'] ) ){
						foreach( $_POST['md_multiple_roles'] as $key => $role ){
							if( $role == $this->role ){
								unset( $_POST['md_multiple_roles'][$key] );
							}
						}
					}
				}
			}
		}
		
		// User Role Editor accesses a string of roles separated by a comma during do_action( 'profile_update' ). So we need to override the string to add/remove tax_exempt.
		if( class_exists('URE_User_Other_Roles') ){
			if( isset( $_POST['ure_other_roles'] ) ){
				$roles = explode(',', str_replace(' ', '', $_POST['ure_other_roles'] ) );
				if( $exempt ){
					if( ! in_array( $this->role, $roles ) ){
						$_POST['ure_other_roles'] .= ', tax_exempt';
					}
				} else {
					$_POST['ure_other_roles'] = '';
					foreach( $roles as $role_id ){
						if( $role_id != $this->role ){
							$_POST['ure_other_roles'] .= $role_id . ', ';
						}
					}
					$_POST['ure_other_roles'] = rtrim( $_POST['ure_other_roles'], ',' );
				}
			}
		}
		
		// Always just add/remove the role from the user, regardless of other user role plugins.
		$user = new WP_User( $user_id );
		if( $exempt ){
			$user->add_role($this->role);
		} else {
			$user->remove_role($this->role);
		}
	}
		
	/*** ADDITIONAL INTEGRATIONS ***/
	
	//// ZAPIER ////
	private function send_to_zapier( $webhook, $data ){
		if ( ! $webhook ){
			$response = [
				'error' => false,
				'message' => 'Notice: Webhook URL not present.'
			]; 
		} elseif ( !filter_var( $webhook, FILTER_VALIDATE_URL ) ){ 
			$response = [
				'error' => true,
				'message' => 'Error: Webhook URL not valid.'
			];
		} else {
			//Send to Zapier!
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $webhook );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );  
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
			curl_setopt( $ch, CURLOPT_POST, 1 );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
			$response = curl_exec( $ch );
			curl_close( $ch );

			$response = json_decode($response,true);

			if ( $response['status'] == 'success' ){
				$response['message'] = 'Data sent to ' . $webhook . ' successfully.';
			} elseif ( $response ) {
				$response = [
					'error' => true,
					'message' => 'Data send to ' . $webhook . ' failed: ' . $response['status'],
					'reponse' => $response,
				];
			} else {
				$response = [
					'error' => true,
					'message' => 'Data sent to ' . $webhook . ' with no response.'
				];
			}
		}
		
		if( isset( $response['error'] ) && $response['error'] == true ){
			$response['data'] = $data;
			$this->log( $response, __LINE__ );
		}		
	}
	
	//// DIRECTORY OFFLOADER ////
	
	// If file is offloaded, update the db
	public function update_offloaded_url( $new_url, $full_path, $remote_path, $item ){
		// Check if the offloaded file belongs to this plugin.
		$pos = strpos( $full_path, $this->certificates_path );
		if( $pos === false ) return;
		
		// Get the user_id from the path
		$parts = explode( '/', $full_path );
		$user_id = $parts[ count($parts) - 2 ];
		
		// Get file info
		$file_meta = get_user_meta( $user_id, $this->slug . '-certificate', true );
		
		// Check if the offloaded file is truly the current certificate before updating user meta
		if( $full_path !== $file_meta['path'] ) return;
		
		// If we've made it this far, then replace the file location data on the user meta.
		$file_meta['url'] = $new_url;
		$file_meta['path'] = false;
		update_user_meta( $user_id, $this->slug . '-certificate', $file_meta );
	}

	public function add_additional_order_statuses_to_sync( $syncable_statuses ){
		// DEFAULT: $syncable_statuses = ['completed', 'refunded'];

		// Remove the WooCommerce prefix from all statuses in array
		return array_map( function( $status ){
			if( strpos( $status, 'wc-' ) === 0 ){
				return substr( $status, 3 );
			} else {
				return $status;
			}
		}, $this->settings['statuses_to_sync'] );
	}
}
dap_woocommerce_taxjar_expansion::get_instance();