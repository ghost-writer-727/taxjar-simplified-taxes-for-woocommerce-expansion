<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class UserProfile_Admin extends UserProfile{
    private array $settings;
    private SettingsManager $settings_manager;

    public function __construct(){
        $this->settings_manager = SettingsManager::get_instance();
        $this->settings = $this->settings_manager->get_settings();

		add_action( 'admin_init', [$this, 'create_exempt_role'] );

        add_action( 'user_edit_form_tag', [$this, 'print_profile_form_tags'] );
        add_action( 'woocommerce_integrations_init', function(){
            add_action( 'show_user_profile', [$this, 'print_user_profile_fields'] );
            add_action( 'edit_user_profile', [$this, 'print_user_profile_fields'] );
        }, 21);

        add_action( 'personal_options_update', [$this, 'patch_disabled_select'] ); 
		add_action( 'edit_user_profile_update', [$this, 'patch_disabled_select'] );

		// Save back end custom meta. Must happen after 'tax_exemption_type' is saved by taxjar in order to override it.
        add_action( 'personal_options_update', [$this, 'save_custom_fields'], 11 );
        add_action( 'edit_user_profile_update', [$this, 'save_custom_fields'], 11 );

        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );
    }

    /**
     * Add form tag to user profile form so we can upload files
     * 
     * @return void
     */
    public function print_profile_form_tags(){
		?>
			enctype="multipart/form-data"
		<?php
	}

    /**
     * Print user profile fields after woocommerce_integrations_init 
     * so that it's next to the other TaxJar settings
     * 
     * @param WP_User $user
     * @return void
     */
	public function print_user_profile_fields( $user ){
		$user_id = $user->ID;

		$certificate = get_user_meta( $user_id, self::IDS['certificate'], true );
		$is_501c3 = get_user_meta( $user_id, self::IDS['501c3'], true ) ? 1 : 0;
		$expiration = get_user_meta( $user_id, self::IDS['expiration'], true );
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
					$current_filename = esc_html( $certificate['label'] );
					$url = $certificate['url'];
					$link = '<a href="' . esc_attr( $url ) . '" target="_blank" download >' . $current_filename . '</a>';
					$remove_button_visible = true;
			
				} else {
					$button_label = 'Upload';
					$current_filename = '';
					$url = '';
					$link = '';
				} 				
				?>
				<td>
					<label for="<?php echo self::IDS['certificate'] ?>" class="button button-secondary"><?php echo esc_html( $button_label ); ?></label>
					<p>
						<span id="certificate_name" class="description"><?php echo $link; ?></span> 
						<span id="<?php echo self::IDS['remove_certificate'] ?>" style="<?php echo $remove_button_visible ? '' : 'display:none;' ?>">x</span></p>
					<input type="file" accept='image/*,.pdf' class="" name="<?php echo self::IDS['certificate'] ?>" id="<?php echo self::IDS['certificate'] ?>" />
					<input type="text" class="" name="<?php echo self::IDS['delete_certificate'] ?>" id="<?php echo self::IDS['delete_certificate'] ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo self::IDS['501c3'] ?>">Non-expiring 501c3 Certificate</label></th>
				<td>
					<input type="checkbox" id="<?php echo self::IDS['501c3'] ?>" name="<?php echo self::IDS['501c3'] ?>" value="<?php echo esc_attr( $is_501c3 ); ?>" <?php checked( $is_501c3, 1 ); ?> />
					<p class="description">Is this a 501c3 Certificate without an expiration?</p>
				</td>
			</tr>
			<tr>
				<th><label for="<?php echo self::IDS['expiration'] ?>">Expiration</label></th>
				<td>
					<input 
						type="date" 
						class="woocommerce-Input woocommerce-Input--date input-date" 
						name="<?php echo self::IDS['expiration'] ?>" 
						id="<?php echo self::IDS['expiration'] ?>" 
						value="<?php echo esc_attr( $expiration ); ?>"
					/>
					<p class="description">Good through the end of this date.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Enqueue scripts and styles for user profile admin page
	 * 
	 * @return void
	 */
    public function enqueue_scripts(){
		if( $this->is_user_page() ){
			wp_enqueue_style( 
				TAXJAR_EXPANSION_PLUGIN_SLUG . '-user-edit',
				TAXJAR_EXPANSION_PLUGIN_URL . 'assets/css/user-edit.css',
				[],
				filemtime( TAXJAR_EXPANSION_PLUGIN_PATH . 'assets/css/user-edit.css' ),
				false // In footer?	
			 );
			wp_enqueue_script( 
				TAXJAR_EXPANSION_PLUGIN_SLUG . '-user-edit',
				TAXJAR_EXPANSION_PLUGIN_URL . 'assets/js/user-edit.js',
				['jquery'],
				filemtime( TAXJAR_EXPANSION_PLUGIN_PATH . 'assets/js/user-edit.js' ),
				false // In footer?
			 );
			wp_localize_script(
				TAXJAR_EXPANSION_PLUGIN_SLUG . '-user-edit',
				'taxjarExpansion',
				[
					'taxExemptionTypeMetaKey' => self::TAX_EXEMPTION_TYPE_META_KEY,
					'autoAssign' => $this->settings['default_status'],
				]
			);
		}
    }

	/**
	 * Check if we're on the user profile or user edit admin pages
	 * 
	 * @return bool
	 */
    public function is_user_page(){
        $screen = get_current_screen();
        if( in_array( $screen->id, ['user-edit', 'profile'] ) ){
            return true;
        }
    }

	/**
	 * Make sure this $_POST field is set to prevent errors
	 * 
	 * @param int $user_id
	 * @return void
	 */
	// We're disabling this field in js, so it won't generate a $_POST item like it should. We need to create the key so TaxJar plugin doesn't throw any errors.
	public function patch_disabled_select( $user_id ){
        if( ! isset($_POST[self::TAX_EXEMPTION_TYPE_META_KEY] ) ){
            // It doesn't actually matter what we send since we're auto-assigning later, but it must be set.
            $_POST[self::TAX_EXEMPTION_TYPE_META_KEY] = false;
        }
        if( ! isset($_POST[self::IDS['expiration']] ) ){
            $_POST[ self::IDS['expiration'] ] = false;
        }
	}
}