<?php
/*
Plugin Name: TaxJar - Sales Tax Automation for WooCommerce - Expansion
Plugin URI: http://www.danielpurifoy.com
Description: Enhances the capabilities of the TaxJar plugin to make it more fully integrated with WooCommerce. • Add certificate upload & expiration dates to user profile, which can be sent to Zapier. • Add support to sync additional order statuses. • Auto-assign a default tax exempt status based on certificate & expiration. • Use Zapier to pass expiration date updates directly to TaxJar, Sheets, etc. • Use Zapier to copy the certificate file to Dropbox, AWS, etc. • Create a temporary tax exempt period for all users (for onboarding) • Create a user role for those who are tax exempt for helping with conditional theme elements and settings.
Version: 2.1.4
Requires at least: 5.5
Requires PHP: 8.0
Author: Daniel Purifoy
*/
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

define( 'TAXJAR_EXPANSION_PLUGIN_FILE', __FILE__ );
define( 'TAXJAR_EXPANSION_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXJAR_EXPANSION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TAXJAR_EXPANSION_PLUGIN_SLUG', 'taxjar_expansion' );

require 'submodules/class-Webhooker.php';
require 'classes/AdminAlert.php';
require 'classes/DirectDownload.php';
require 'classes/DirectoryOffloaderIntegration.php';
require 'classes/SettingsManager.php';
require 'classes/TaxJarAPIIntegration.php';
require 'classes/UserProfile.php';
require 'classes/UserProfile_Admin.php';
require 'classes/UserProfile_Front.php';
require 'classes/WooCommerceIntegration.php';
require 'classes/ZapierIntegration.php';

class Plugin {
	CONST DB_VERSION = '2';
	CONST DB_VERSION_OPTION = 'taxjar_expansion_db_version';
	private DirectDownload $direct_download;
	private DirectoryOffloaderIntegration $directory_offloader_integration;
	private SettingsManager $settings_manager;
	private TaxJarAPIIntegration $taxjar_api_integration;
	private UserProfile $user_profile;
	private UserProfile_Admin $user_profile_admin;
	private UserProfile_Front $user_profile_front;
	private WooCommerceIntegration $woocommerce_integration;
	private ZapierIntegration $zapier_integration;

	public function __construct(){
		$test_mode = false;
		if( $test_mode ){
			add_filter( 'taxjar_enabled', '__return_true', PHP_INT_MAX);
			add_filter( 'taxjar_nexus_check', '__return_true', PHP_INT_MAX );
		}

		$this->direct_download = DirectDownload::get_instance();
		$this->directory_offloader_integration = new DirectoryOffloaderIntegration();
		$this->settings_manager = SettingsManager::get_instance();
		$this->taxjar_api_integration = new TaxJarAPIIntegration();
		$this->user_profile = UserProfile::get_instance();
		$this->user_profile_admin = new UserProfile_Admin();
		$this->user_profile_front = new UserProfile_Front();
		$this->woocommerce_integration = new WooCommerceIntegration();
		$this->zapier_integration = new ZapierIntegration();

		$db_version = (int) get_option( self::DB_VERSION_OPTION, 1 );
		if ( version_compare( $db_version, self::DB_VERSION, '<' ) ) {
			$this->upgrade( $db_version );
		}
		add_action( 'admin_notices', [$this, 'dependency_notification'] );
	}

	/**
	 * Upgrade db changes
	 * 
	 * @return void
	 */
	public function upgrade( $previous_version ){
		// Need to flush rewrites to handle the newly registered My Account/Tax Status url properly.
		add_action( 'init', function(){
			flush_rewrite_rules();
		}, 99);

		if( $previous_version === 1 ){
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Dependency notification
	 * 
	 * @return void
	 */
	public function dependency_notification(){
		if( 
			! class_exists('MDMR_Checklist_Controller')
			&& (
				$this->settings_manager->is_settings_page()
				|| $this->user_profile_admin->is_user_page()
				|| $this->is_plugins_page()
			)
		){ 
            $message = '<strong>Taxjar Expansion</strong> recommends using <a href="https://wordpress.org/plugins/multiple-roles/" target="_blank">Multiple Roles</a> by Christian Neumann to properly support the <em>Tax Exempt</em> user role functionality.';
            $type = 'warning';
            new AdminAlert( $message, $type );
        }

		// Check if taxjar is active
		if(
			! class_exists('WC_Taxjar')
			&& (
				$this->settings_manager->is_settings_page()
				|| $this->user_profile_admin->is_user_page()
				|| $this->is_plugins_page()
			)
		){
			$message = '<strong>Taxjar Expansion</strong> requires <a href="https://wordpress.org/plugins/taxjar-simplified-taxes-for-woocommerce/" target="_blank">TaxJar - Sales Tax Automation for WooCommerce</a> by TaxJar to be installed and activated.';
			$type = 'error';
			new AdminAlert( $message, $type );
		}

		// Check if WooCommerce is active
		if(
			! class_exists('WooCommerce')
			&& (
				$this->settings_manager->is_settings_page()
				|| $this->user_profile_admin->is_user_page()
				|| $this->is_plugins_page()
			)
		){
			$message = '<strong>Taxjar Expansion</strong> requires <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a> by Automattic to be installed and activated.';
			$type = 'error';
			new AdminAlert( $message, $type );
		}
	}

	/**
	 * Check if we're on the plugins page
	 * 
	 * @return bool
	 */
	private function is_plugins_page(){
		return isset($_GET['page']) && $_GET['page'] === 'plugins.php';
	}
}
new Plugin();
