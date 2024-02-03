<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class SettingsManager {
	private static $instance;
    private array $settings;
    private string $section_id = TAXJAR_EXPANSION_PLUGIN_SLUG . '-settings-title';
	CONST OPTION_NAME = 'taxjar_expansion';

	public static function get_instance(){
		if( ! isset( self::$instance ) ){
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct(){
        $this->init();

		add_filter( 'plugin_action_links_' . plugin_basename(TAXJAR_EXPANSION_PLUGIN_FILE), [$this, 'action_links'], 10, 2 );

		add_filter( 'woocommerce_get_settings_taxjar-integration', [$this, 'register_additional_taxjar_settings'] );

		add_filter( 'option_woocommerce_taxjar-integration_settings' , [ $this, 'option_woocommerce_taxjar_integration_settings_override' ], PHP_INT_MAX );

		add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );
    }

	/**
	 * Initialize the settings
	 * 
	 * @return void
	 */
	private function init(){
		$default = [
			'statuses_to_sync' => ['wc-completed','wc-refunded'],
			'default_status' => '',
			'expiration_zap' => false,
			'501c3_zap' => false,
			'certificate_zap' => false,
			'exempt_status_zap' => false,
			'override_cutoff' => 'Aug 11, 1984 12:00am',
			'log_admin_errors' => false,
		];
		$this->settings = array_merge( $default, get_option( self::OPTION_NAME, [] ) );
		
		// Format settings
		$this->settings['override_cutoff'] = strtotime( $this->settings['override_cutoff'] );		
		$this->settings['log_admin_errors'] = 
			$this->settings['log_admin_errors'] === 'yes' 
			|| $this->settings['log_admin_errors'] === true 
			|| $this->settings['log_admin_errors'] === 'on' 
			|| $this->settings['log_admin_errors'] === 1;
 	}

	/**
	 * Get the settings
	 * 
	 * @return array
	 */
    public function get_settings(){
        return $this->settings;
    }

	/**
	 * Filter in additional settings for the TaxJar settings page
	 * 
	 * @param array $settings
	 * @return array $settings
	 */
	public function register_additional_taxjar_settings( $settings ){
		
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
                'id'    => $this->section_id,
			],
			[
				'title'	=> 'Statuses to Sync',
				'type'	=> 'multiselect',
				'desc'	=> 'Select which order statuses should be synced to TaxJar.',
				'class'	=> TAXJAR_EXPANSION_PLUGIN_SLUG.'-statuses_to_sync',
				'id'	=> self::OPTION_NAME.'[statuses_to_sync]',
				'options' => $order_statuses,
				'default' => ['wc-completed','wc-refunded'],
			],
			[
				'title'   => 'Default Exempt Status',
				'type'    => 'select',
				'desc'    => 'If this is enabled, the user will be auto-assigned theis status once they have uploaded a certificate and set a valid expiration date or indicated the certificate is a 501c3 certificate.',
				'id'      => self::OPTION_NAME.'[default_status]',
				'options' => $exemption_options,
			],
			[
				'title'   => 'Expiration Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When expiration date is updated, send data to Zapier.',
				'id'      => self::OPTION_NAME.'[expiration_zap]',
			],
			[
				'title'   => '501c3 Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When 501c3 type is updated, send data to Zapier.',
				'id'      => self::OPTION_NAME.'[501c3_zap]',
			],
			[
				'title'   => 'Certificate Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When certificate is uploaded, send data to Zapier.',
				'id'      => self::OPTION_NAME.'[certificate_zap]',
			],
			[
				'title'   => 'Exempt Status Zapier Catchhook',
				'type'    => 'text',
				'desc'    => 'When status is changed, send data to Zapier.',
				'id'      => self::OPTION_NAME.'[exempt_status_zap]',
			],
			[
				'title'		=> 'All exempt until',
				'type'		=> 'date',
				'desc'		=> 'Prevents WooCommerce and TaxJar from calculating taxes until after this date.',
				'id'		=> self::OPTION_NAME.'[override_cutoff]'
			],
			[
				'title'		=> 'Log Admin error messages',
				'type'		=> 'checkbox',
				'desc'		=> 'Log admin messages to the error logs.',
				'id'		=> self::OPTION_NAME.'[log_admin_errors]'
			],
		];
		$expansion_settings[] =
			[
				'type' => 'sectionend',
			];

		return array_merge($settings, $expansion_settings);
	}

	/**
	 * Add a link to the settings page on the plugin page
	 * 
	 * @param array $links
	 * @param string $file
	 * @return array $links
	 */
    public function action_links( $links, $file ) {
		if ( $file == plugin_basename(TAXJAR_EXPANSION_PLUGIN_FILE) ) {
			$settings_link = '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=taxjar-integration#' . $this->section_id . '">Settings</a>';
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	/**
	 * Checks if the features to auto-assign a default tax exempt status are active
	 * 
	 * @return bool
	 */
	public function is_active(){
		return $this->is_tax_exempt_status( $this->settings['default_status'] );
	}

	/**
	 * Checks if a status is a tax exempt status
	 * 
	 * @param string $status
	 * @return bool
	 */
	public function is_tax_exempt_status( $status ){
		$tax_exempt_statuses = [
			'government',
			'wholesale',
			'other'
		];
		
		return in_array( $status, $tax_exempt_statuses );
	}

	/**
	 * Checks if we're in the override period, where we force turn off all tax.
	 * 
	 * @return bool
	 */
	public function in_override_period(){
		if( time() <= $this->settings['override_cutoff'] ){
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Override the TaxJar settings to disable all tax calculations
	 * 
	 * @param array $value
	 * @return array $value
	 */
	public function option_woocommerce_taxjar_integration_settings_override( $value ){		
		// Check if we're supposed to be disabling all tax calculations right now.
		// But don't override the original setting on the settings page. So when conditions change, the desired behavior resumes.
		if( 
			$this->in_override_period()
			&& ! $this->is_settings_page()
		){
			$value['enabled'] = $value['api_calcs_enabled'] = $value['save_rates'] = $value['taxjar_download'] = wc_bool_to_string( false );
		}
		return $value;
	}

	/**
	 * Check if we're on the TaxJar settings page
	 * 
	 * @return bool
	 */
	public function is_settings_page(){
		// Check if this is the page is the TaxJar settings page
		if( 
			is_admin()
			&& isset( $_GET['page'] )
			&& $_GET['page'] == 'wc-settings'
			&& isset( $_GET['tab'] )
			&& $_GET['tab'] == 'taxjar-integration'
		){
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Enqueue scripts
	 * 
	 * @return void
	 */
	public function enqueue_scripts(){
		if( $this->is_settings_page() ){
			wp_enqueue_script( 
				TAXJAR_EXPANSION_PLUGIN_SLUG . '-settings',
				TAXJAR_EXPANSION_PLUGIN_URL . 'assets/js/taxjar-settings.js',
				['jquery'],
				filemtime( TAXJAR_EXPANSION_PLUGIN_PATH . 'assets/js/taxjar-settings.js' ),
				false // In footer? );
			);
		}
	}
}