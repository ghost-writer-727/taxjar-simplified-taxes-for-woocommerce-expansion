<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit; 

class ZapierIntegration{
    private array $settings;
    private SettingsManager $settings_manager;

    public function __construct(){
        $this->settings_manager = SettingsManager::get_instance();
        $this->settings = $this->settings_manager->get_settings();
    
        add_action( 'taxjar_expansion_customer_certificate_updated', [$this, 'zap_updated_certificate'], 10, 2);
        add_action( 'taxjar_expansion_customer_501c3_updated', [$this, 'zap_updated_501c3'], 10, 2);
        add_action( 'taxjar_expansion_customer_expiration_updated', [$this, 'zap_updated_expiration'], 10, 2);
        
        if( $this->settings_manager->is_active() ){
            add_action( 'taxjar_expansion_customer_exemption_status_updated', [$this, 'zap_updated_exemption_status'], 10, 2);
        }
    }

    /**
     * Prep data to send to Zapier when a customer's certificate is updated
     * 
     * @param int $user_id
     * @param string $certificate
     * @return void
     */
    public function zap_updated_certificate( $user_id, $certificate ){
        $data = [
            'user_id' => $user_id,
            'certificate' => $certificate
        ];
        $this->send_to_zapier( 'certificate_zap', $data );
    }

    /**
     * Prep data to send to Zapier when a customer's 501c3 status is updated
     * 
     * @param int $user_id
     * @param bool $_501c3
     * @return void
     */
    public function zap_updated_501c3( $user_id, $is_501c3 ){
        $data = [
            'user_id' => $user_id,
            '501c3' => $is_501c3
        ];
        $this->send_to_zapier( '501c3_zap', $data );
    }

    /**
     * Prep data to send to Zapier when a customer's expiration date is updated
     * 
     * @param int $user_id
     * @param int $expiration - Unix timestamp
     */
    public function zap_updated_expiration( $user_id, $expiration ){
        $data = [
            'user_id'       => $user_id,
            'timestamp' 	=> $expiration,
            'Y-m-d' 		=> $expiration ? date( 'Y-m-d', $expiration ) : false,
            'date' 			=> $expiration ? date( 'M d, Y', $expiration) : false,
        ];
        $this->send_to_zapier( 'expiration_zap', $data );
    }

    /**
     * Prep data to send to Zapier when a customer's exemption status is updated
     * 
     * @param int $user_id
     * @param bool $exemption_status
     * @return void
     */
    public function zap_updated_exemption_status( $user_id, $exemption_status ){
        $data = [
            'user_id' => $user_id,
            'exemption_status' => $exemption_status
        ];
        $this->send_to_zapier( 'exempt_status_zap', $data );
    }

    /**
     * Send data to Zapier
     * 
     * @param string $zap_name
     * @param array $data
     * @return void
     */
    private function send_to_zapier( $zap_name, $data ){
        $zap_url = $this->settings[$zap_name];
        if( 
            $zap_url 
            && filter_var( $zap_url, FILTER_VALIDATE_URL )
        ){
            $response = Webhooker::send($zap_url, $data);
            if( !$response['success'] ){
                new AdminAlert( 'Zapier Integration Error:<br>' . print_r( $response, true ) );
            }
        }
    }
}