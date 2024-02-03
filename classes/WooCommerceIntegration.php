<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class WooCommerceIntegration{
	private SettingsManager $settings_manager;
	private UserProfile $user_profile;

    public function __construct(){
		$this->settings_manager = SettingsManager::get_instance();
		$this->user_profile = UserProfile::get_instance();

		// Filter the value of the option to calculate taxes in the event that we are globally disabling taxes
		add_filter( 'wc_tax_enabled' , [ $this, 'option_woocommerce_calc_taxes_override' ], PHP_INT_MAX );
    }
	
	/**
	 * Override the value of the option to calculate taxes in the event that we are globally disabling taxes
	 * 
	 * @param bool $value
	 * @return bool $value
	 */
	public function option_woocommerce_calc_taxes_override( $exempt ){
		// Don't override the original setting on the settings page.
		if( 
			$this->settings_manager->in_override_period()
			&& $this->settings_manager->is_active()
			&& ! $this->is_settings_page()
		){
			$exempt = false;
		}
		return $exempt;
	}
	
	/**
	 * Check if this is the page is a WooCommerce settings page
	 * 
	 * @return bool
	 */
	private function is_settings_page(){
		return is_admin() && isset($_GET['page']) && $_GET['page'] == 'wc-settings';
	}
}