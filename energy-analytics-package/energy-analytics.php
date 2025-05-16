<?php
/**
 * Energy Analytics
 *
 * @package           Energy_Analytics
 * @author            Energy Analytics Team
 * @copyright         2023 Energy Analytics
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Energy Analytics
 * Plugin URI:        https://example.com/energy-analytics
 * Description:       Enterprise-grade energy-usage reporting suite
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Energy Analytics Team
 * Author URI:        https://example.com
 * Text Domain:       energy-analytics
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:        https://example.com/energy-analytics/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants.
define( 'EA_VERSION', '1.0.0' );
define( 'EA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation hook.
 */
register_activation_hook( __FILE__, 'ea_activate' );

/**
 * Plugin activation callback.
 *
 * Checks PHP version and creates database tables.
 */
function ea_activate() {
    // Check PHP version.
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        wp_die(
            esc_html__( 'Energy Analytics requires PHP 7.4 or higher. Please upgrade your PHP version.', 'energy-analytics' ),
            esc_html__( 'Plugin Activation Error', 'energy-analytics' ),
            array( 'back_link' => true )
        );
    }

    // Create custom database tables.
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'energy_logs';
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        form_id varchar(100) NOT NULL,
        field_data longtext NOT NULL,
        user_id bigint(20) NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY form_id (form_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    dbDelta( $sql );

    // Register the energy_manager role.
    ea_register_roles();
    
    // Store current database version.
    update_option( 'ea_db_version', EA_VERSION );
}

/**
 * Register roles and capabilities.
 */
function ea_register_roles() {
    // Add the energy_manager role if it doesn't exist.
    if ( ! get_role( 'energy_manager' ) ) {
        add_role(
            'energy_manager',
            __( 'Energy Manager', 'energy-analytics' ),
            array(
                'read' => true,
            )
        );
    }
    
    // Define energy capabilities.
    $caps = array(
        'view_energy_reports',
        'edit_energy_reports',
        'export_energy_pdf',
        'view_energy_dashboard',
        'manage_energy_scripts',
    );
    
    // Get the energy_manager role.
    $role = get_role( 'energy_manager' );
    
    // Add capabilities to energy_manager.
    foreach ( $caps as $cap ) {
        $role->add_cap( $cap );
    }
    
    // Add energy capabilities to administrator.
    $admin = get_role( 'administrator' );
    
    foreach ( $caps as $cap ) {
        $admin->add_cap( $cap );
    }
}

/**
 * Deactivation hook.
 */
register_deactivation_hook( __FILE__, 'ea_deactivate' );

/**
 * Plugin deactivation callback.
 */
function ea_deactivate() {
    // Nothing specific to do on deactivation.
}

/**
 * Load plugin textdomain.
 */
function ea_load_textdomain() {
    load_plugin_textdomain( 'energy-analytics', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ea_load_textdomain' );

/**
 * Initialize the plugin.
 */
function ea_init() {
    // Check if composer autoload exists.
    if ( file_exists( EA_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
        require_once EA_PLUGIN_DIR . 'vendor/autoload.php';
        
        // Database migration check
        $current_version = get_option( 'ea_db_version', '0.0.0' );
        if ( version_compare( $current_version, EA_VERSION, '<' ) ) {
            ea_run_migrations( $current_version );
        }
        
        // Initialize plugin components.
        $admin = new EA\Admin\Admin();
        $admin->init();
        
        $scripts = new EA\Admin\Scripts();
        $scripts->init();
        
        // Initialize REST API endpoints.
        add_action( 'rest_api_init', function() {
            $endpoints = new EA\REST\Endpoints();
            $endpoints->register_routes();
        });
        
        // Register CLI commands (only if WP-CLI is available).
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'energy', new EA\CLI\Commands() );
        }
        
    } else {
        // Show admin notice if autoloader is missing.
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Energy Analytics requires Composer dependencies to be installed. Please run "composer install" in the plugin directory.', 'energy-analytics' ); ?></p>
            </div>
            <?php
        });
    }
}
add_action( 'plugins_loaded', 'ea_init' );

/**
 * Run database migrations.
 *
 * @param string $from_version The current database version.
 */
function ea_run_migrations( $from_version ) {
    // Implement version-specific migrations here.
    
    // Update the database version.
    update_option( 'ea_db_version', EA_VERSION );
}

/**
 * Register custom post type for energy scripts.
 */
function ea_register_post_types() {
    register_post_type( 'ea_script', array(
        'labels'              => array(
            'name'                  => __( 'Energy Scripts', 'energy-analytics' ),
            'singular_name'         => __( 'Energy Script', 'energy-analytics' ),
            'menu_name'             => __( 'Energy Scripts', 'energy-analytics' ),
            'add_new'               => __( 'Add New', 'energy-analytics' ),
            'add_new_item'          => __( 'Add New Script', 'energy-analytics' ),
            'edit'                  => __( 'Edit', 'energy-analytics' ),
            'edit_item'             => __( 'Edit Script', 'energy-analytics' ),
            'new_item'              => __( 'New Script', 'energy-analytics' ),
            'view'                  => __( 'View Script', 'energy-analytics' ),
            'view_item'             => __( 'View Script', 'energy-analytics' ),
            'search_items'          => __( 'Search Scripts', 'energy-analytics' ),
            'not_found'             => __( 'No scripts found', 'energy-analytics' ),
            'not_found_in_trash'    => __( 'No scripts found in trash', 'energy-analytics' ),
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => false, // We'll add it under our custom menu
        'supports'            => array( 'title', 'editor', 'revisions' ),
        'capability_type'     => 'post',
        'capabilities'        => array(
            'edit_post'           => 'manage_energy_scripts',
            'read_post'           => 'manage_energy_scripts',
            'delete_post'         => 'manage_energy_scripts',
            'edit_posts'          => 'manage_energy_scripts',
            'edit_others_posts'   => 'manage_energy_scripts',
            'publish_posts'       => 'manage_energy_scripts',
            'read_private_posts'  => 'manage_energy_scripts',
        ),
        'hierarchical'        => false,
        'has_archive'         => false,
        'menu_position'       => null,
        'map_meta_cap'        => true,
    ) );
}
add_action( 'init', 'ea_register_post_types' );

/**
 * Enqueue admin assets.
 */
function ea_enqueue_admin_assets() {
    $screen = get_current_screen();
    
    // Only load assets on plugin pages
    if ( strpos( $screen->id, 'energy-analytics' ) !== false || $screen->post_type === 'ea_script' ) {
        
        // Core styles
        wp_enqueue_style(
            'ea-admin-styles',
            EA_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            filemtime( EA_PLUGIN_DIR . 'assets/css/admin.css' )
        );
        
        // Chart.js
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
            array(),
            '3.9.1',
            true
        );
        
        // Plugin charts script
        wp_enqueue_script(
            'ea-charts',
            EA_PLUGIN_URL . 'assets/js/ea-charts.js',
            array( 'jquery', 'chartjs' ),
            filemtime( EA_PLUGIN_DIR . 'assets/js/ea-charts.js' ),
            true
        );
        
        // Localize chart script with nonce and REST URL
        wp_localize_script( 'ea-charts', 'eaCharts', array(
            'apiUrl'       => esc_url_raw( rest_url( 'energy/v1/stats' ) ),
            'restNonce'    => wp_create_nonce( 'wp_rest' ),
            'colors'       => get_option( 'ea_chart_colors', array(
                'primary'   => '#0073aa',
                'secondary' => '#00a0d2',
                'tertiary'  => '#72aee6',
                'quaternary'=> '#00ba88',
            )),
        ));
        
        // CodeMirror for script editing
        if ( $screen->post_type === 'ea_script' ) {
            wp_enqueue_code_editor( array( 'type' => 'text/javascript' ) );
            wp_enqueue_script( 'ea-script-editor', EA_PLUGIN_URL . 'assets/js/script-editor.js', array( 'jquery' ), filemtime( EA_PLUGIN_DIR . 'assets/js/script-editor.js' ), true );
        }
    }
}
add_action( 'admin_enqueue_scripts', 'ea_enqueue_admin_assets' );
