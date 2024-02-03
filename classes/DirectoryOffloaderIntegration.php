<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class DirectoryOffloaderIntegration{
    private UserProfile $user_profile;

    public function __construct(){
        $this->user_profile = UserProfile::get_instance();
        add_action( 'dap_do_file_offloaded', [$this, 'update_offloaded_url'], 10, 4 );
    }

	/**
	 * Updates the offloaded URL in the user meta
	 * 
	 * @param string $new_url
	 * @param string $full_path
	 * @param string $remote_path
	 * @param array $item
	 * @return void
	 */
    public function update_offloaded_url( $new_url, $full_path, $remote_path, $item ){
		// Santize $new_url
		if( !is_string($new_url) ) return;

		// Check if the offloaded file belongs to this plugin.
		$pos = strpos( $full_path, $this->user_profile->get_certificates_path() );
		if( $pos === false ) return;
		
		// Get the user_id from the path
		$parts = explode( '/', $full_path );
		$user_id = $parts[ count($parts) - 2 ];

		// Validate $user_id
		if( !is_numeric($user_id) ) return;

		// Get file info
		$file_meta = get_user_meta( $user_id, $this->user_profile::IDS['certificate'], true );
		
		// Check if the offloaded file is truly the current certificate before updating user meta
		if( $full_path !== $file_meta['path'] ) return;
		
		// If we've made it this far, then replace the file location data on the user meta.
		$file_meta['url'] = $new_url;
		$file_meta['path'] = false;
		update_user_meta( $user_id, $this->user_profile::IDS['certificate'], $file_meta );
	}
}