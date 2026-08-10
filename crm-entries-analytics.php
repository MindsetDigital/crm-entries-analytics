<?php
/**
 * Plugin Name: CRM Entries Analytics
 * Description: Dashboard moderno de analítica y consulta para Contact Form Entries de CRM Perks.
 * Version: 1.4.0
 * Author: Mindset Digital
 * Text Domain: crm-entries-analytics
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: contact-form-entries
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CEA_VERSION', '1.4.0' );
define( 'CEA_FILE', __FILE__ );
define( 'CEA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CEA_URL', plugin_dir_url( __FILE__ ) );
define( 'CEA_CAPABILITY', 'cea_view_analytics' );

require_once CEA_PATH . 'includes/class-cea-db.php';
require_once CEA_PATH . 'includes/class-cea-rest.php';
require_once CEA_PATH . 'includes/class-cea-admin.php';
require_once CEA_PATH . 'includes/class-cea-report.php';

final class CEA_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'boot' ) );
        add_action( 'admin_init', array( __CLASS__, 'sync_capabilities' ) );
    }

    public function boot() {
        $db = new CEA_DB();
        new CEA_REST( $db );

        if ( is_admin() ) {
            new CEA_Admin( $db );
            new CEA_Report( $db );
        }
    }

    public static function activate() {
        self::sync_capabilities();
    }

    public static function sync_capabilities() {
        if ( ! function_exists( 'wp_roles' ) ) {
            return;
        }

        $roles = wp_roles();
        if ( ! $roles ) {
            return;
        }

        foreach ( $roles->role_objects as $role ) {
            $can_read_entries = $role->has_cap( 'manage_options' ) || $role->has_cap( 'vxcf_leads_read_entries' );

            if ( $can_read_entries && ! $role->has_cap( CEA_CAPABILITY ) ) {
                $role->add_cap( CEA_CAPABILITY );
            }
        }
    }
}

register_activation_hook( __FILE__, array( 'CEA_Plugin', 'activate' ) );
CEA_Plugin::instance();
