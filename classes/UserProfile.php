<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

/**
 * Class UserProfile
 * 
 * Shared properties, methods, and constants for managing user 
 * profiles from UserProfile_Admin.php and UserProfile_Front.php
 */
class UserProfile{
    private static $instance;
    private array $settings;
    private SettingsManager $settings_manager;
	private int $user_id_cache = 0;
    private string $certificates_path_cache = '';
    private string $certificates_url_cache = '';

    CONST IDS = [
        'certificate' => TAXJAR_EXPANSION_PLUGIN_SLUG . '-certificate',
        '501c3' => TAXJAR_EXPANSION_PLUGIN_SLUG . '-501c3',
        'expiration' => TAXJAR_EXPANSION_PLUGIN_SLUG . '-expiration',
        'delete_certificate' => TAXJAR_EXPANSION_PLUGIN_SLUG . '-delete-cert',
        'remove_certificate' => TAXJAR_EXPANSION_PLUGIN_SLUG . '-remove-certificate',
    ];
    CONST ROLE = 'tax_exempt';
    CONST TAX_EXEMPTION_TYPE_META_KEY = 'tax_exemption_type';

    public static function get_instance(){
        if( ! self::$instance ){
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        $this->settings_manager = SettingsManager::get_instance();
        $this->settings = $this->settings_manager->get_settings();

    }

    /**
     * Get the path to the certificates folder
     * 
     * @return string $path
     */
	public function get_certificates_path(){
		if( $this->certificates_path_cache ){
			return $this->certificates_path_cache;
		}
		$tje_upload_dir = $this->get_certificates_upload_dir();
		return $this->certificates_path_cache = $tje_upload_dir['path'] ?? '';
	}

    /**
     * Get the URL to the certificates folder
     * 
     * @return string $url
     */
    public function get_certificates_url(){
		if( $this->certificates_url_cache ){
			return $this->certificates_url_cache;
		}
		$tje_upload_dir = $this->get_certificates_upload_dir();
		return $this->certificates_url_cache = $tje_upload_dir['url'] ?? '';
    }

	/**
	 * Get the certificates upload directory
	 * 
	 * @return array $tje_upload_dir
	 */
	private function get_certificates_upload_dir(){
		add_filter( 'upload_dir', [$this, 'customize_wp_upload_dir'] );
		$tje_upload_dir = wp_upload_dir();
		remove_filter( 'upload_dir', [$this, 'customize_wp_upload_dir'] );
		return $tje_upload_dir;
	}

    /**
     * Register the tax_exempt role in WordPress
	 * 
	 * @return void
     */
    public function create_exempt_role(){
		add_role(
			self::ROLE,
			'Tax Exempt'
		);
	}
	
    /**
     * Save custom fields from the user profile (for use in the front end and admin)
     * 
     * @param int $user_id
     * @return void
     */
	public function save_custom_fields( $user_id ){
		// Store old status
		$old_status = $this->get_user_exemption_type( $user_id );

		// Certificate Upload
		$upload = $_FILES[self::IDS['certificate']] ?? false;
		if( 
			$upload 
			&& $upload['tmp_name']
			&& $upload['name']
		){
			$this->user_id_cache = $user_id;
			if( $certificate_uploaded = $this->upload_certificate( $upload ) ){
				$new_certificate = [
					'path' => $certificate_uploaded['file'],
					'url' => $certificate_uploaded['url'],
					'label' => $upload['name'],
					'type' => $certificate_uploaded['type'],
				];

				$old_certificate = $this->get_user_certificate( $user_id );

				$certificate_updated = update_user_meta( 
					$user_id, 
					self::IDS['certificate'], 
					$new_certificate
				);

				/** 
				 * Delete old certificates that are older than 2 years.
				 * This is to prevent the server from filling up with old certificates.
				 */
				/* NOTE: Not enabling this because the legal implications of deleting old files that may be required for an audit needs to be reconsidered.
				// Find all the old certs and delete them
				foreach( $old_certs as $old_cert_path ){
					if( 
						$old_cert_path
						&& file_exists( $old_cert_path )
						&& filemtime( $old_cert_path ) < strtotime( '-2 year' )
					){
						unlink( $old_cert_path );
					}
				} /** */

				do_action( 'taxjar_expansion_customer_certificate_updated', $user_id, $new_certificate, $old_certificate);
			} else {
				if( is_admin() ) {
					new AdminAlert( 'Certificate upload failed!' );
				}
			}

		} else if(
			isset( $_POST[self::IDS['delete_certificate']] )
			&& $_POST[self::IDS['delete_certificate']] == 'true'
		){
            $certificate_deleted = $this->delete_user_certificate( $user_id );
		}

		// 501c3 Exemption
		$is_501c3 = isset( $_POST[self::IDS['501c3']] ) ? true : false;
		if( 
			$is_501c3 != $this->get_user_501c3_status( $user_id )
		){
			$_501c3_updated = update_user_meta( 
				$user_id, 
				self::IDS['501c3'], 
				$is_501c3
			);
            do_action( 'taxjar_expansion_customer_501c3_updated', $user_id, $is_501c3);
		}

		// Expiration Date
		if( $is_501c3 ){
			$expiration = false;
		} else if( isset( $_POST[self::IDS['expiration']] ) ){
			$old_expiration = $this->get_user_expiration( $user_id );
			$expiration = $_POST[self::IDS['expiration']];
			$expiration = $expiration ? strtotime( $expiration . ' 11:59:59 PM' ) : false;
			if( $expiration && $expiration < time() ){
				if( !is_admin() ){
					wc_add_notice( 'Certificate has expired.', 'error' );
				}
			}
		}
		if( 
			isset( $expiration ) 
			&& $expiration != $this->get_user_expiration( $user_id )
		){
			$expiration_updated = update_user_meta(
				$user_id, 
				self::IDS['expiration'],
				$expiration
			);
            do_action( 'taxjar_expansion_customer_expiration_updated', $user_id, $expiration, $old_expiration);
		}

		// If any of these were modified, then recheck exemption status
		if( 
			isset( $certificate_updated )
            || isset( $certificate_deleted )
			|| isset( $_501c3_updated )
			|| isset( $expiration_updated )
		){
			$new_status = $this->evaluate_exempt_eligibility( $user_id, ( $certificate ?? null ), $is_501c3, ( $expiration ?? null ) );
			if( $new_status !== null ){
                do_action( 'taxjar_expansion_customer_exemption_status_updated', $user_id, $new_status, $old_status);

				if( ! is_admin() ){
					wc_add_notice( 'Tax status changed to ' . ($new_status ? 'Exempt' : 'Not Exempt') .'!', ($new_status ? 'success' : 'error') );
				}
			}
		}

		if( is_wp_error( TaxJarAPIIntegration::update_taxjar_customer_record( $user_id ) ) ){
			if( is_admin() ){
				new AdminAlert( 'TaxJar sync failed for user_id: ' . $user_id );
			}
		}
	}

	/**
	 * Prepare the certificate upload
	 * 
	 * @param int $user_id
	 * @param array $upload
	 * @return array( 
	 *   'file' => string filename,
	 *   'url' => string url,
	 *   'type' => string mime type,
	 * )
	 */
	private function upload_certificate( $upload ){		
		add_filter( 'upload_dir', [$this, 'customize_wp_upload_dir'] );
		
		foreach( ['update', 'my_account_save_tax_status'] as $action ){
			$upload_overrides = [
				'action' => $action
			];

			$file = wp_handle_upload( $upload, $upload_overrides );

			if( ! isset( $file['error'] ) || ! $file['error'] ){
				// If the file was uploaded successfully, then break the loop.
				break;
			}
		}

		remove_filter( 'upload_dir', [$this, 'customize_wp_upload_dir'] );

		if( isset( $file['error'] ) && $file['error'] ){
			if( is_admin() ){
				new AdminAlert( 'Certificate upload failed: ' . $file['error'] );
			} else {
				wc_add_notice( 'Certificate upload failed: ' . $file['error'], 'error' );
			}
			return false;
		}

		if( is_admin() ){
			new AdminAlert( 'Certificate uploaded: ' . $file['url'] );
		}
		return $file;
	}

	/**
	 * Customize the upload directory for certificates
	 * 
	 * @param array $wp_upload_dir return value from wp_upload_dir()
	 * @return array $tje_upload_dir
	 */
	public function customize_wp_upload_dir( $wp_upload_dir ){
		if( isset( $wp_upload_dir['error'] ) && $wp_upload_dir['error'] ){
			return $wp_upload_dir;
		}

		$certificates_subdir = '/tax-certificates';
		$user_subdir = $this->user_id_cache ? '/' . $this->user_id_cache : '';
		$subdir = $certificates_subdir . $user_subdir;
		$path = $wp_upload_dir['basedir'] . $subdir;

		if( ! file_exists( $path ) ){
			mkdir( $path, 0755, true );
		}

		$tje_upload_dir = [
			'path' => $path,
			'url' => $wp_upload_dir['baseurl'] . $subdir,
			'subdir' => $subdir,
			'basedir' => $wp_upload_dir['basedir'],
			'baseurl' => $wp_upload_dir['baseurl'],
			'error' => false,
		];

		return $tje_upload_dir;
	}

    /**
     * Delete a user's certificate
     * 
     * @param int $user_id
     * @return bool $certificate_deleted
     */
    private function delete_user_certificate( $user_id, $keep_file = true ){
		if( ! $keep_file ){
			$certificate = $this->get_user_certificate( $user_id );
			if( $certificate && file_exists( $certificate['path'] ) ){
				unlink( $certificate['path'] );
			}
		}

        $certificate_deleted = delete_user_meta( 
            $user_id, 
            self::IDS['certificate']
        );

        do_action( 'taxjar_expansion_customer_certificate_updated', $user_id, false);

		if( $certificate_deleted ){
			if( is_admin() ){
				new AdminAlert( 'Certificate removed.' );
			} else {
				wc_add_notice( 'Certificate removed.', 'success' );
			}
		}

        return $certificate_deleted;
    }

	/**
	 * Patch 2.2.1 for re-evaluate_exempt_eligibility
	 */
	public function patch_2_2_1() {
		$allowed_user_id = 788; // Only DPurifoy can run
		$current_user     = wp_get_current_user();

		if ( (int) $current_user->ID !== $allowed_user_id ) {
			return;
		}

		$patched_key         = 'taxjar_expansion_2_2_1_patched';
		$unpatched_users_key = 'taxjar_expansion_2_2_1_unpatched_users';

		if ( get_option( $patched_key ) === true ) {
			return;
		}

		$unpatched_users = get_option( $unpatched_users_key );
		if ( $unpatched_users === false ) {
			global $wpdb;

			$results = $wpdb->get_col("
				SELECT u.ID
				FROM {$wpdb->users} u
				INNER JOIN {$wpdb->usermeta} um_role
					ON um_role.user_id = u.ID
					AND um_role.meta_key = '{$wpdb->prefix}capabilities'
					AND um_role.meta_value LIKE '%tax_exempt%'
				LEFT JOIN {$wpdb->usermeta} um_type
					ON um_type.user_id = u.ID
					AND um_type.meta_key = 'tax_exemption_type'
				WHERE um_type.user_id IS NULL
			");

			$unpatched_users = array_map( 'intval', $results );
			update_option( $unpatched_users_key, $unpatched_users );
		}

		$x = 0;
		foreach ( $unpatched_users as $index => $user_id ) {
			$this->evaluate_exempt_eligibility( $user_id );
			unset( $unpatched_users[ $index ] );

			if ( ++$x >= 100 ) {
				break;
			}
		}

		if ( empty( $unpatched_users ) ) {
			update_option( $patched_key, true );
			delete_option( $unpatched_users_key );
		} else {
			update_option( $unpatched_users_key, array_values( $unpatched_users ) );
		}
	}

	 /**
	  * Evaluate if a user should be exempt
	  * 
	  * @param int $user_id
	  * @param array $certificate
	  * @param bool $is_501c3
	  * @param int $expiration
	  * @return bool|null $exempt Null if no change, $new_status_slug if changed and become or remains exempt, False if changed to not exempt
	  */
	private function evaluate_exempt_eligibility( $user_id, $certificate = null, $is_501c3 = null, $expiration = null ){		
        // This isn't being intializaed when this method is being called. So we have to do it again here.
        $this->settings_manager = SettingsManager::get_instance();

		// Are we auto_assigning? If not then bail.
		if( ! $this->settings_manager->is_active() ) return null;

		$certificate = $certificate !== null 
			? $certificate 
			: $this->get_user_certificate( $user_id );
		$expiration = $expiration !== null 
			? $expiration
			: $this->get_user_expiration( $user_id );
		$is_501c3 = $is_501c3 !== null 
			? $is_501c3 
			: $this->get_user_501c3_status( $user_id );

		// Check that all requirements are met.
		$invalidated = false;
		if( ! $this->is_valid_certificate( $certificate ) ){
			$invalidated = true;
			$this->delete_user_certificate( $user_id, false );
		}
		if( ! $this->is_valid_expiration( $expiration, $is_501c3 ) ){
			$invalidated = true;
		}

		$exempt = ! $invalidated;

		// Attempt to update the status of the user
		$update_status = $this->update_user_exemption_status( $user_id, $exempt );

		// If use wasn't changed because their status already matched, $update_status will be null.
		if( $update_status === null ){
			return $exempt;
		}

		// If the status was changed, then return the new status.
		return $update_status;
	}

	/**
	 * Get a user's certificate
	 * 
	 * @param int $user_id
	 * @return null | array( 'url' => string, 'path' => string, 'label' => string )
	 */
	public function get_user_certificate( $user_id ){
		$certificate = get_user_meta( $user_id, self::IDS['certificate'], true );
		return apply_filters( 'taxjar_expansion_get_user_certificate', $certificate, $user_id );
	}

	/**
	 * Get a user's 501c3 status
	 * 
	 * @param int $user_id
	 * @return bool
	 */
	public function get_user_501c3_status( $user_id ){
		$is_501c3 = get_user_meta( $user_id, self::IDS['501c3'], true );
		return apply_filters( 'taxjar_expansion_get_user_501c3_status', $is_501c3, $user_id );
	}

	/**
	 * Get a user's certificate expiration date
	 * 
	 * @param int $user_id
	 * @return int
	 */
	public function get_user_expiration( $user_id ){
		$user_expiration = get_user_meta( $user_id, self::IDS['expiration'], true ) ?: 0;
		return apply_filters( 'taxjar_expansion_get_user_expiration', $user_expiration, $user_id );
	}

	/**
	 * Get a user's exemption type
	 * 
	 * @param int $user_id
	 * @return string
	 */
	public function get_user_exemption_type( $user_id ){
		$exemption_type = get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true );
		return apply_filters( 'taxjar_expansion_get_user_exemption_type', $exemption_type, $user_id );
	}
	
	/**
	 * Check if a certificate is valid
	 * 
	 * @param array|string $file
	 * @return bool
	 */
	private function is_valid_certificate( $file ){
		if( 
			! is_array( $file ) 
			|| ! isset( $file['url'], $file['path'], $file['label'] )
			|| ! $file['url']
			|| ! $file['path']
			|| ! $file['label']
		){
			if( is_admin() ){
				new AdminAlert( 'Invalid certificate: ' . print_r( $file, true ) );
			} else {
				wc_add_notice( 'Invalid certificate.', 'error' );
			}
			return false;
		}

		// Check if file exists
		if( ! file_exists( $file['path'] ) ){
			new AdminAlert( 'Certificate file not found: ' . $file['path'] );
			return false;
		}

		if( ! $this->isUrlReachable( $file['url'] ) ){
			new AdminAlert( 'Certificate URL not reachable: ' . $file['url'] );
			return false;
		}

		return true;
	}

	private function isUrlReachable($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_NOBODY, true); // Make a HEAD request
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
		curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Set a timeout
	
		curl_exec($ch);
		$responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
	
		// Check if the response code is OK (200 range)
		return ($responseCode >= 200 && $responseCode < 300);
	}
	
	/**
	 * Check if exempt status is not expired
	 * 
	 * @param int $timestamp
	 * @param bool $is_501c3
	 * @return bool
	 */
	private function is_valid_expiration( $timestamp, $is_501c3 = false ){
		if( $is_501c3 ){
			return true;
		}
		if( !$timestamp || $timestamp < time() ){
			new AdminAlert( 'Expired tax certificate: ' . $timestamp . ' < ' . time() );
			return false;
		}
		return true;
	}
		
	 /**
	  * Update the user's exemption status
	  *	
	  * @param int $user_id
	  * @param bool $exempt
	  * @return bool|null $status Null if no change, $new_status_slug if changed and become or remains exempt, False if changed to not exempt
	  */
	private function update_user_exemption_status( $user_id, $exempt ){
        // $this->settings is not being initialized before this method is called. So we have to do it again here.
        $this->settings = $this->settings_manager->get_settings();

		$old_status = $this->get_user_exemption_type( $user_id );

		if( $exempt ){
			// Preserve user's exempt status or use fallback if isn't already exempt
			$new_status = $old_status ?: $this->settings['default_status'];
		} else {
			$new_status = '';
		}

		// Update Taxjar's meta field for the user
		$this->set_tax_jar_user_exempt_role_meta( $user_id, $new_status );
		
		// Add tax exempt role
		$this->set_user_exempt_role( $user_id, $exempt );

		// Use this to trigger TaxJar's native sync with TaxJar API function.
		do_action( 'taxjar_customer_exemption_settings_updated', $user_id );

		// Can be the new status or null if no change
		return $new_status === $old_status ? null : $new_status;
	}

	/**
	 * Add user's tax exempt role
	 * 
	 * @param int|string $user_id
	 * @param string $status
	 */
    private function set_tax_jar_user_exempt_role_meta( $user_id, $status ){
        update_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, $status );
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
					if( ! in_array( self::ROLE, $_POST['md_multiple_roles'] ) ){
						$_POST['md_multiple_roles'][] = self::ROLE;
					}
				} else {
					if( in_array( self::ROLE, $_POST['md_multiple_roles'] ) ){
						foreach( $_POST['md_multiple_roles'] as $key => $role ){
							if( $role == self::ROLE ){
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
					if( ! in_array( self::ROLE, $roles ) ){
						$_POST['ure_other_roles'] .= ', tax_exempt';
					}
				} else {
					$_POST['ure_other_roles'] = '';
					foreach( $roles as $role_id ){
						if( $role_id != self::ROLE ){
							$_POST['ure_other_roles'] .= $role_id . ', ';
						}
					}
					$_POST['ure_other_roles'] = rtrim( $_POST['ure_other_roles'], ',' );
				}
			}
		}
		
		// Always just add/remove the role from the user, regardless of other user role plugins.
		$user = new \WP_User( $user_id );
		if( $exempt ){
			$user->add_role(self::ROLE);
		} else {
			$user->remove_role(self::ROLE);
		}
	}

	/**
	 * Check if a user is exempt
	 * 
	 * @param int $user_id
	 * @return bool
	 */
    public function user_is_exempt( $user_id ){
        return $this->settings_manager->is_tax_exempt_status( $this->get_user_exemption_type( $user_id) );
    }

	/**
	 * Get the user's tax exemption status
	 * 
	 * @param int $user_id
	 * @return string
	 */
	public function download_certificate( $user_id ){
		return DirectDownload::generate_secure_certificate_download_link( $user_id );
	}

}