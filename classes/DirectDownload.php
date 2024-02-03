<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class DirectDownload {
    private static $instance;
    private array $settings;
    private SettingsManager $settings_manager;
    private UserProfile $user_profile;

    CONST ROUTE_NAMESPACE = 'taxjar-expansion/v1';
    CONST ROUTE_NAME = 'certificate-download';
    CONST SECURE_TOKEN_KEY = 'tje_secure_certificate_download';
	CONST TOKEN_PARAM = 'tje';
    CONST TARGET_USER_ID_KEY = 'target_user_id';
    CONST CURRENT_USER_ID_KEY = 'current_user_id';

    public static function get_instance(){
        if( ! self::$instance ){
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct(){
        $this->settings_manager = SettingsManager::get_instance();
        $this->settings = $this->settings_manager->get_settings();
        $this->user_profile = UserProfile::get_instance();

        add_action( 'rest_api_init', [$this, 'register_certificate_download_route'] );
    }

    /**
	 * Generate a secure download link for a user's certificate
	 * 
	 * @param int $target_user_id
	 * @return string $url
	 */
	public static function generate_secure_certificate_download_link( $target_user_id ){
        $current_user_id = get_current_user_id();
        $token = self::generate_token( $target_user_id, $current_user_id );
        return add_query_arg( 
            [ self::TOKEN_PARAM => $token ], 
            rest_url( self::ROUTE_NAMESPACE .'/' . self::ROUTE_NAME . '/' . $target_user_id . '/' . $current_user_id )
        );
	}

	/**
	 * Register the certificate download route
	 * 
	 * @return void
	 */
	public function register_certificate_download_route(){
		register_rest_route( 
            self::ROUTE_NAMESPACE, 
            '/' . self::ROUTE_NAME . '/(?P<' . self::TARGET_USER_ID_KEY . '>\d+)/(?P<' . self::CURRENT_USER_ID_KEY . '>\d+)',
            [
                'methods' => 'GET',
                'callback' => [$this, 'direct_download'],
                'permission_callback' => [$this, 'is_secure_request'],
            ]
        );
	}

	/**
	 * Create a token hash for a secure download request
	 * 
	 * @param int|string $target_user_id
	 * @return string $token
	 */
	public static function generate_token( $target_user_id, $current_user_id ){
		return hash( 'sha256', self::SECURE_TOKEN_KEY . $target_user_id . $current_user_id );
	}

	/**
	 * Check if token matches the expected token
	 * 
	 * @param int|string $target_user_id
     * @param int|string $current_user_id
	 * @param string $token
	 */
	public static function is_valid_token( $target_user_id, $current_user_id, $token ){
		return $token === self::generate_token( $target_user_id, $current_user_id );
	}

	/**
	 * Verify that the incoming request is a secure download request
	 * 
     * @param \WP_REST_Request $request
	 * @return bool
	 */
	public static function is_secure_request( $request ){
		$target_user_id = $request->get_param( self::TARGET_USER_ID_KEY );
        $current_user_id = $request->get_param( self::CURRENT_USER_ID_KEY );
        if( 
            ! $target_user_id 
            || ! $current_user_id
            || ! self::can_user_edit_users($target_user_id, $current_user_id)
        ){
            return false;
        }

        $token = $request->get_param( self::TOKEN_PARAM );
		return self::is_valid_token( $target_user_id, $current_user_id, $token );
	}

    /**
     * Check if the user can edit users
     * 
     * @param int $target_user_id
     * @param int $current_user_id
     * @return bool
     */
    private static function can_user_edit_users($target_user_id, $current_user_id) {
        $current_user = get_userdata($current_user_id);
        if( $current_user instanceof \WP_User ){
            if( 
                $current_user->ID === $target_user_id
                || $current_user->has_cap('edit_users')
            ){
                return true;
            }
        }
        return false;
    }

	/**
	 * Securely download a user's certificate
	 * 
     * @param \WP_REST_Request $request
	 * @return void
	 */
	public function direct_download( $request ){
		$target_user_id = $request->get_param( self::TARGET_USER_ID_KEY );

		$certificate = $this->user_profile->get_user_certificate($target_user_id);
		if( ! $certificate ){
			return new \WP_REST_Response( 'Certificate data not found.', 404 );
		}

		// Download the file to the user's computer
		$certificate_path = $certificate['path'];
		$certificate_label = $certificate['label'];

		// Check if the file exists
		if( ! file_exists( $certificate_path ) ){
			return new \WP_REST_Response( $certificate_label . ' not found.', 404 );
		}

		// Check if the file is readable
		if( ! is_readable( $certificate_path ) ){
			return new \WP_REST_Response( $certificate_label . ' not readable.', 404 );
		}

        $certificate_label = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $certificate_label);
        $certificate_label = trim($certificate_label);
        $certificate_label = mb_substr($certificate_label, 0, 250);


		// Send the file to the user
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . $certificate_label . '"; filename*=UTF-8\'\'' . rawurlencode( $certificate_label ) );
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize( $certificate_path ) );
		readfile( $certificate_path );

		exit;
	}
}