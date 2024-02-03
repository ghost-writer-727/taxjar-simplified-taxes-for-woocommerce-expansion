<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class WooCommerceIntegration{
	private SettingsManager $settings_manager;
	private UserProfile $user_profile;

    public function __construct(){
		$this->settings_manager = SettingsManager::get_instance();
		$this->user_profile = UserProfile::get_instance();

		if( $this->settings_manager->is_active() ){
			/* NOTE: Session Cart tax exemption status is handled by TajJarIntegration.php
			This should be removed after successful testing. */
			// Update session cart tax
			//add_action( 'woocommerce_before_calculate_totals', [$this, 'set_cart_exemption'], 100 );
			// add_action('woocommerce_order_item_after_calculate_taxes', [$this, 'set_order_item_exemption'], 100);
			// add_action('woocommerce_order_item_shipping_after_calculate_taxes', [$this, 'set_order_item_exemption'], 100);
			// add_action('woocommerce_order_item_fee_after_calculate_taxes', [$this, 'set_order_item_exemption'], 100);
			/**/

			// Set the tax exemption status of a WC_Cart
			// add_filter( 'woocommerce_order_is_vat_exempt', [$this, 'set_order_exemption'], 100, 2 );
		}
/*
		add_action( 'taxjar_expansion_customer_exemption_status_updated', function( $user_id, $exemption_type){

			error_log( 'taxjar_expansion_customer_exemption_status_updated: ' . $user_id . ' - ' . $exemption_type );

			$exempt = $this->settings_manager->is_tax_exempt_status($exemption_type);
			// Get WC_Customer object
			// $customer = new \WC_Customer( $user_id );
			// $customer->set_is_vat_exempt( $this->settings_manager->is_tax_exempt_status($exemption_type) );
			// Get all orders that are associated with the user
			$orders = wc_get_orders( [
				'customer' => $user_id,
				'limit' => -1,
			] );

			// Set the tax exemption status of each order
			foreach( $orders as $order ){

				// Get the data store for the order
				$data_store = $order->get_data_store();

				if( method_exists( $data_store, 'update')){
					// Update the order
					$data_store->update( $order );
					error_log( 'Updated order: ' . $order->get_id() );
				} else {
					error_log( 'Data store does not have update method' );
				}

				// Check if the data store has the clear_caches method
				if (method_exists($data_store, 'clear_caches')) {
					// Clear caches for the order
					$data_store->clear_caches($order);
					error_log( 'Cleared cache for order: ' . $order->get_id() );
				} else {
					error_log( 'Data store does not have clear_caches method' );
				}

				// get all order items
				$order_items = $order->get_items();

				wc_save_order_items( $order->get_id(), $order_items );
				foreach( $order_items as $order_item ){
					error_log( print_r( $order_item , true ) );
					// get data store for order item
					$order_item_data_store = $order_item->get_data_store();
					// Check if the data store has the clear_caches method
					if (method_exists($order_item_data_store, 'clear_caches')) {
						// Clear caches for the order item
						$order_item_data_store->clear_caches($order_item);
						error_log( 'Cleared cache for order item: ' . $order_item->get_id() );
					} else {
						error_log( 'Item Data store does not have clear_caches method' );
					}
				}
			}
		}, 100, 2 ); /** */

		// Filter the value of the option to calculate taxes in the event that we are globally disabling taxes
		// add_filter( 'wc_tax_enabled' , [ $this, 'option_woocommerce_calc_taxes_override' ], PHP_INT_MAX );
    }
	
	/**
	 * Set the exemption status of an order (not a cart)
	 * 
	 * @param bool $is_exempt
	 * @param WC_Order $order
	 * @return bool $is_exempt
	 */
	public function set_order_exemption( $is_exempt, $order ){
		if( $user = $order->get_user() ){
			$is_exempt = $this->user_profile->user_is_exempt( $user->ID );
		}
		
		return $is_exempt;
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
		
		
		public function set_cart_exemption( $cart ){
			$customer = $cart->get_customer(); // WC_Customer
			$user_id = $customer->get_id();
			if( is_user_logged_in() ){
				$exempt = $this->user_profile->user_is_exempt( $user_id );
				error_log( 'set_cart_exemption: ' . ($exempt ? 'TRUE' : 'FALSE') );
				// Get the customer's user ID
				$customer->set_is_vat_exempt($exempt);
			}
		}
		//*
		public function set_order_item_exemption( \WC_Order_Item | \WC_Order_Item_Shipping | \WC_Order_Item_Fee $order_item ){
			$order = $order_item->get_order();
			$user = $order->get_user();
			if( $user ){
				$exempt = $this->user_profile->user_is_exempt( $user->ID );
				error_log( 'set_order_item_exemption: ' . ($exempt ? 'TRUE' : 'FALSE') );
				if( $exempt ){
					$order_item->set_taxes( false );
				}
			}
		}
		/**/

		public function maybe_disable_wc_tax( $enabled ){
			// Get cart user
			if( $cart = WC()->cart ){
				$customer = $cart->get_customer(); // WC_Customer
				$user_id = $customer->get_id();
				// Check if cart user is exempt
				$exempt = $this->user_profile->user_is_exempt( $user_id );
				error_log( 'maybe_disable_wc_tax: ' . ($exempt ? 'TRUE' : 'FALSE') );
			}
			return $enabled;
		}
}