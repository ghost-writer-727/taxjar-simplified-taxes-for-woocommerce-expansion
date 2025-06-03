<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

/**
 * Class ExpiringAlerts
 * 
 * Run a Cron daily to check for expiring exempt statuses and send alerts to Zapier
 * 
 * @package TaxJarExpansion
 */
class ExpiringAlerts {
    const CRON_NAME = 'taxjar-expansion-expiration-alerts';
    const ALERTED_KEY = 'taxjar-expansion-expiration-alerted';
    const ALERT_ACTION = 'taxjar-expansion-expiration-alert';

    private static $instance;
    private $expiring_users_cache = null;

    public static function get_instance(){
        if( ! self::$instance ){
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        $this->add_cron();

        add_action( self::CRON_NAME, [$this, 'send_alerts'] );
        add_action( 'plugins_loaded', [$this, 'send_alerts'] );

        add_action( 'taxjar_expansion_customer_expiration_updated', [$this, 'expiration_updated'], 10, 3);
    }

    public function add_cron(){
        if( ! wp_next_scheduled( self::CRON_NAME ) && $start_time = strtotime( '8am', time() ) ){
            wp_schedule_event( $start_time, 'daily', self::CRON_NAME );
        }
    }

    public function send_alerts(){
        $users = $this->get_potential_expiring_users();
        foreach( $users as $user_id ){
            // Create an action to trigger ZapierIntegration
            do_action(self::ALERT_ACTION, $user_id);

            // Set that this user has already been alerted
            $this->set_user_alerted_expiration( $user_id );
        }
    }

    /**
     * Get all users that will expire in expiring_status_zap_days or sooner
     * 
     * @return [$user_id, ...]
     */
    private function get_potential_expiring_users(){
        if( $this->expiring_users_cache !== null ){
            return $this->expiring_users_cache;
        }

        $settings = SettingsManager::get_instance()->get_settings();
        $expiration_threshold = time() + ( ($settings['expiring_status_zap_days'] + 1) * 86400 );

        global $wpdb;        
        $sql = "
            SELECT u.ID 
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} type_meta ON type_meta.user_id = u.ID 
                AND type_meta.meta_key = %s 
                AND type_meta.meta_value != ''
            LEFT JOIN {$wpdb->usermeta} 501c3_meta ON 501c3_meta.user_id = u.ID 
                AND 501c3_meta.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} expiration_meta ON expiration_meta.user_id = u.ID 
                AND expiration_meta.meta_key = %s
            LEFT JOIN {$wpdb->usermeta} alerted_meta ON alerted_meta.user_id = u.ID 
                AND alerted_meta.meta_key = %s
            WHERE (501c3_meta.meta_value IS NULL OR 501c3_meta.meta_value != '1')
              AND (expiration_meta.meta_value IS NULL OR expiration_meta.meta_value <= %d)
              AND alerted_meta.meta_value IS NULL
        ";
        
        $this->expiring_users_cache = $wpdb->get_col( $wpdb->prepare(
            $sql,
            UserProfile::TAX_EXEMPTION_TYPE_META_KEY,
            UserProfile::IDS['501c3'],
            UserProfile::IDS['expiration'],
            self::ALERTED_KEY,
            $expiration_threshold
        ));
        
        return $this->expiring_users_cache;

    }

    /**
     * Get the expiration timestamp that a user has already been alerted about
     * 
     * @param int $user_id
     * @return int
     */
    private function get_user_alerted_expiration( $user_id ){
        return get_user_meta( $user_id, self::ALERTED_KEY, true ) ?: 0;
    }

    /**
     * Set the expiration timestamp that a user has already been alerted about
     * 
     * @param int $user_id
     * @return void
     */
    private function set_user_alerted_expiration( $user_id ){
        $user_profile = UserProfile::get_instance();
        $expiration = $user_profile->get_user_expiration( $user_id );
        update_user_meta( $user_id, self::ALERTED_KEY, $expiration );
    }

    /**
     * Reset the alert for a user
     * 
     * @param int $user_id
     * @return void
     */
    public function reset_user_alerted_expiration( $user_id ){
        delete_user_meta( $user_id, self::ALERTED_KEY );
    }

    /**
     * If a user’s expiration got extended forward into the future, allow them to be alerted again.
     * 
     * @param int $user_id
     * @param int $new_expiration
     * @param int $old_expiration
     * @return void
     */
    public function expiration_updated( $user_id, $new_expiration, $old_expiration ) {
        $current_time = time();
        $new_expiration_int = intval( $new_expiration );
        $old_expiration_int = intval( $old_expiration );
    
        if( $new_expiration_int > $old_expiration_int && $new_expiration_int >= $current_time ) {
            $this->reset_user_alerted_expiration( $user_id );
        }
    }
}
