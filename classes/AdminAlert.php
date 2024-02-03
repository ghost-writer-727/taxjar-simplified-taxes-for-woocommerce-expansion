<?php
namespace TaxJarExpansion;

defined( 'ABSPATH' ) || exit;

class AdminAlert{
    CONST TRANSIENT = 'taxjar-expansion-admin-message';
    private SettingsManager $settings_manager;
    private array $settings;

    public function __construct( $message = '', $type = 'error' ){
        $this->settings_manager = SettingsManager::get_instance();
        $this->settings = $this->settings_manager->get_settings();

        if( $message ){
            $this->set_admin_message( $message, $type );
        }
        add_action( 'admin_notices', [$this, 'print_admin_message'] );
    }

    /**
     * Validate the type of message
     * 
     * @param string $type
     * @return string
     */
    private function validate_type( $type ){
        if( !in_array( $type, ['error', 'warning', 'success', 'info'] ) ){
            $type = 'error';
        }
        return $type;
    }

    /**
     * Set the admin message
     * 
     * @param string $message
     * @param string $type
     * @return void
     */
    private function set_admin_message( $message, $type = 'error' ){
        set_transient( self::TRANSIENT, [
            'message' => $message,
            'type' => $type,
        ], 60 );
        if( $this->settings['log_admin_errors'] ){
            error_log( 'TaxJar Expansion - Admin Alert: ' . esc_html( $message ) );
        }
    }

    /**
     * Print the admin message
     * 
     * @return void
     */
    public function print_admin_message(){
        if( $data = get_transient( self::TRANSIENT ) ){
            ?>
            <div class="notice notice-<?php echo $this->validate_type($data['type']); ?> is-dismissible">
                <h3>TaxJar Expansion Plugin Alert</h3>
                <p><?php echo wp_kses_post( $data['message'] ); ?></p>
            </div>
            <?php
            delete_transient( self::TRANSIENT );
        }
    }

}