<?php
/**
 * Uninstall script for Energy Analytics
 *
 * @package Energy_Analytics
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete custom database table.
global $wpdb;
$table_name = $wpdb->prefix . 'energy_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

// Delete options.
$options = array(
    'ea_chart_colors',
    'ea_pdf_logo',
    'ea_cache_lifetime',
    'ea_acf_json_path',
    'ea_db_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Delete transients.
delete_transient( 'ea_energy_stats' );

// Delete uploaded files.
$upload_dir = wp_upload_dir();
$energy_dir = $upload_dir['basedir'] . '/energy-analytics';
if ( file_exists( $energy_dir ) ) {
    $this->delete_directory( $energy_dir );
}

/**
 * Delete a directory and its contents recursively.
 *
 * @param string $dir Path to the directory.
 */
function ea_delete_directory( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $objects = scandir( $dir );
    foreach ( $objects as $object ) {
        if ( $object === '.' || $object === '..' ) {
            continue;
        }

        $path = $dir . '/' . $object;
        if ( is_dir( $path ) ) {
            ea_delete_directory( $path );
        } else {
            unlink( $path );
        }
    }

    rmdir( $dir );
}

// Remove custom post types.
$posts = get_posts( array(
    'post_type'      => 'ea_script',
    'posts_per_page' => -1,
    'post_status'    => 'any',
) );

foreach ( $posts as $post ) {
    wp_delete_post( $post->ID, true );
}

// Remove capabilities from roles.
$roles = array( 'administrator', 'energy_manager' );
$caps = array(
    'view_energy_reports',
    'edit_energy_reports',
    'export_energy_pdf',
    'view_energy_dashboard',
    'manage_energy_scripts',
);

foreach ( $roles as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) {
        foreach ( $caps as $cap ) {
            $role->remove_cap( $cap );
        }
    }
}

// Remove the energy_manager role.
remove_role( 'energy_manager' );