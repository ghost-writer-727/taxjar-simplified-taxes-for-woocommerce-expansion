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
        add_action( ExpiringAlerts::ALERT_ACTION, [$this, 'zap_upcoming_expiration']);
        
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
        if( empty($this->settings['certificate_zap']) ) return;
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
        if( empty($this->settings['501c3_zap']) ) return;
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
        if( empty($this->settings['expiration_zap']) ) return;
        $data = [
            'user_id'       => $user_id,
            'timestamp' 	=> $expiration,
            'Y-m-d' 		=> $expiration ? date( 'Y-m-d', $expiration ) : false,
            'date' 			=> $expiration ? date( 'M d, Y', $expiration) : false,
        ];
        $this->send_to_zapier( 'expiration_zap', $data );
    }

    /**
     * Prep data to send to Zapier when a customer's expiration date is about to expire
     * 
     * @param int $user_id
     * @return void
     */
    public function zap_upcoming_expiration( $user_id ){
        if( empty($this->settings['expiration_zap']) ) return;
        $user_profile = UserProfile::get_instance();
        $expiration_timestamp = $user_profile->get_user_expiration( $user_id );
        if( !$expiration_timestamp ) return;

        $expiration_date = date( 'M d, Y', $expiration_timestamp );
        $user = get_user_by( 'id', $user_id );
        $data = [
            'user_id' => $user_id,
            'user_email' => $user->user_email,
            'user_first_name' => $user->first_name,
            'user_last_name' => $user->last_name,
            'expiration_timestamp' => $expiration_timestamp,
            'expiration_date' => $expiration_date,
            'days_left' => floor( ( $expiration_timestamp - time() ) / 86400 )
        ];
        $this->send_to_zapier( 'expiring_status_zap', $data );
    }

    /**
     * Prep data to send to Zapier when a customer's exemption status is updated
     * 
     * @param int $user_id
     * @param bool $exemption_status
     * @return void
     */
    public function zap_updated_exemption_status( $user_id, $exemption_status ){
        if( empty($this->settings['exempt_status_zap']) ) return;
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
        $zap_url = empty($this->settings[$zap_name]) ? false : $this->settings[$zap_name];
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