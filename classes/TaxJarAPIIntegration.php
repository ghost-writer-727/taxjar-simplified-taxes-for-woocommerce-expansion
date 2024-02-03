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
        //add_action( 'taxjar_expansion_customer_exemption_status_updated', [$this, 'sync_customer'], 20, 2 );
		// add_filter( 'taxjar_valid_order_statuses_for_sync', [$this, 'add_additional_order_statuses_to_sync'] );

		//add_filter( 'taxjar_cart_exemption_type', [$this, 'set_cart_exemption_type'], 10, 2 );

		// add_filter( 'taxjar_order_calculation_exemption_type', [$this, 'set_order_calculation_exemption_type'], 10, 2 );

		add_filter( 'taxjar_tax_request_body', [$this, 'use_exemption_to_hash_cache'], 10, 2 );

    }

    /**
	 * Syncs a customer's exempt status with TaxJar
	 * 
	 * @param int $user_id
	 * @param string $exemption_type
	 * @return void
	 */
	public static function sync_customer( $user_id, $exemption_type ){
		error_log( 'sync_customer' );

		// Check if $user_id is a valid user
		if( ! get_userdata( $user_id ) ){
			return;
		}

		// Falsy $exemption_type values should be changed to 'non_exempt'
		$exemption_type = $exemption_type ?: 'non_exempt';

		$customer_record = new \TaxJar_Customer_Record($user_id);
		$customer_record->load_object();
		
		if( $api_data = self::is_valid_api_customer( $customer_record ) ){
			$local_data = $customer_record->get_data_from_object();
			if( $changes = self::merge_changes( $api_data, $local_data ) ){
				self::update_api_customer( $customer_record, $changes );
			}
		} else {
			self::create_new_api_customer( $customer_record );
		}
		return;
	}

	private static function is_valid_api_customer( \TaxJar_Customer_Record $customer_record ){
		
		$api_response = $customer_record->get_from_taxjar();

		// Make sure that $api_response is not a WP_Error
		if( is_wp_error( $api_response ) ){
			new AdminAlert( $api_response->get_error_message() );
			return false;
		}
		
		// API data
		$api_data = json_decode( $api_response['body'], true );
		$response_code = $api_response['response']['code'] ? intval( $api_response['response']['code'] ) : 0;
		$valid_response = $response_code >= 200 && $response_code < 300;

		if( ! $valid_response ){
			if( is_admin() ){
				new AdminAlert( 'TaxJar API response not valid. Response code: ' . $response_code );
			}
			return false;
		}

		$customer_data_from_api = $api_data['customer'] ?? false;

		if( ! is_array( $customer_data_from_api ) ){
			if( is_admin() ){
				new AdminAlert( 'TaxJar API response not an array.' );
			}
			return false;
		}

		return $customer_data_from_api;
	}

	/**
	 * Merge changes
	 * 
	 * @param array $old_data
	 * @param array $new_data
	 * @return array|false $merged Returns merged array if changes are detected, otherwise returns false.
	 */
	private static function merge_changes( array $old_data, array $new_data ){
		ksort( $old_data );
		ksort( $new_data );
		$merged = array_merge( $old_data, $new_data );
		if( $merged != $old_data ){
			error_log( 'merge_changes: changes detected');
			return $merged;
		}
		error_log( 'merge_changes: no changes');
		return false;
	}

	private static function create_new_api_customer( $customer_record ){
		error_log( 'create_new_api_customer' );

		// Check if we can get the customer's local data.
		if( is_wp_error( $customer_record ) ){
			error_log( 'create_new_api_customer: is_wp_error');
			return $customer_record;
		}

		$response = $customer_record->create_in_taxjar();
		error_log( print_r( $response, true ) );
	}

	private static function update_api_customer( $customer_record, $args ){
		error_log( 'update_api_customer' );

		if( $customer_record->should_sync() ){
			$repsonse = $customer_record->update_in_taxjar();
			error_log( print_r( $repsonse, true ) );
			return;
		} 			
		error_log( 'update_api_customer: should_sync is false');
	}

	/**
	 * Add additional order statuses to sync with TaxJar
	 * 
	 * @param array $syncable_statuses
	 * @return array $syncable_statuses
	 */
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

	/** 
	 * Force cart cache to include the user's exemption type in the unique key generation.
	 * 
	 * @param string $exemption_type
	 * @param WC_Cart $cart
	 * @return string $exemption_type
	 */
	public function set_cart_exemption_type( $exemption_type, $cart ){
		if( $cart ){
			if( $customer = $cart->get_customer() ){
				if( $user_id = $customer->get_id() ){
					$exemption_type = get_user_meta( $user_id, self::TAX_EXEMPTION_TYPE_META_KEY, true );
				}
			}
		}
		return $exemption_type;
	}

	/** 
	 * Force order cache to include the user's exemption type in the unique key generation.
	 * 
	 * @param string $exemption_type
	 * @param WC_Order $order
	 * @return string $exemption_type
	 */
	public function set_order_calculation_exemption_type( $exemption_type, $order ){
		if( $order ){
			if( $user_id = $order->get_user_id() ){
				$exemption_type = $this->user_profile->get_user_exemption_type( $user_id );
				error_log( 'set_order_calculation_exemption_type: ' . $exemption_type);
			}
		}
		return $exemption_type;
	}

	public function use_exemption_to_hash_cache( array $request_array, \TaxJar\Tax_Request_Body $request_obj ){

		$user_id = $request_array['customer_id'] ?? $request_array['customer_id'] ?: false;
		if( $user_id ){
			$exemption_type = $this->user_profile->get_user_exemption_type( $user_id );
			$request_array['exemption_type'] = $exemption_type ?: 'non_exempt';
		}

		return $request_array;

	}
		
}