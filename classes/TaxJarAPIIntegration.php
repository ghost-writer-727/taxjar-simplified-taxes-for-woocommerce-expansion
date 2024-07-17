<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class TaxJarAPIIntegration{
	private array $settings;
	private UserProfile $user_profile;
	CONST TAX_EXEMPTION_TYPE_META_KEY = 'tax_exemption_type';

    public function __construct(){
		$this->settings = SettingsManager::get_instance()->get_settings();
		$this->user_profile = UserProfile::get_instance();
		add_filter( 'taxjar_tax_request_body', [$this, 'use_exemption_to_hash_cache'], 10, 2 );
		add_action( 'taxjar_expansion_customer_exemption_status_updated', [$this, 'update_taxjar_customer_record'], 10 );

    }

	/**
	 * TaxJar uses a hash of the request body 
	 * as the cache key for tax calculations
	 * via `\TaxJar\Cache` class.
	 * 
	 * We add the exemption type to the request body
	 * to ensure that there are different cached results
	 * based on whether a cart or order is tax exempt.
	 * 
	 * @param array $request_array
	 * @param \TaxJar\Tax_Request_Body $request_obj
	 * @return array
	 */
	public function use_exemption_to_hash_cache( array $request_array, \TaxJar\Tax_Request_Body $request_obj ){


		$user_id = isset( $request_array['customer_id'] ) && $request_array['customer_id'] 
			? $request_array['customer_id']
			: false;
		if( $user_id ){
			$exemption_type = $this->user_profile->get_user_exemption_type( $user_id );
			$request_array['exemption_type'] = $exemption_type ?: 'non_exempt';
		}

		return $request_array;

	}

	private function update_taxjar_customer_record($user_id) {
		$customer_record = new \TaxJar_Customer_Record($user_id);
	
		// Load existing customer record if it exists
		if ($customer_record->get_from_taxjar()) {
			$result = $customer_record->update_in_taxjar();
		} else {
			$result = $customer_record->create_in_taxjar();
		}
	
		if (is_wp_error($result)) {
			error_log("TaxJar sync failed for user_id: $user_id. Error: " . $result->get_error_message());
		} else {
			error_log("TaxJar sync successful for user_id: $user_id.");
		}
	}
		
}