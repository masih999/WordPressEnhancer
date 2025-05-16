<?php
/**
 * Uninstall handler for Energy Analytics
 *
 * This file is called when the plugin is uninstalled.
 * It cleans up the database tables and options based on user settings.
 *
 * @package Energy_Analytics
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Get the option that determines if we should delete all data.
$delete_data = get_option( 'ea_delete_data_on_uninstall', false );

// Only perform cleanup if the user has opted to delete all data.
if ( $delete_data ) {
    global $wpdb;

    // Delete the custom database table.
    $table_name = $wpdb->prefix . 'energy_logs';
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Delete all plugin options.
    $options = array(
        'ea_chart_colors',
        'ea_pdf_logo',
        'ea_cache_lifetime',
        'ea_acf_json_path',
        'ea_db_version',
        'ea_delete_data_on_uninstall',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Delete plugin transients.
    $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%ea_stats_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%ea_rate_limit_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Delete custom post type content.
    $script_posts = get_posts(
        array(
            'post_type'      => 'ea_script',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        )
    );

    foreach ( $script_posts as $post_id ) {
        wp_delete_post( $post_id, true );
    }

    // Remove the custom role.
    remove_role( 'energy_manager' );

    // Remove capabilities from other roles.
    $roles = array( 'administrator' );
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

    // Clear any cached files in the uploads directory.
    $upload_dir = wp_upload_dir();
    $export_dir = $upload_dir['basedir'] . '/energy-analytics';

    if ( file_exists( $export_dir ) && is_dir( $export_dir ) ) {
        $files = glob( $export_dir . '/*' );
        
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
        
        // Remove the directory.
        rmdir( $export_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_rmdir
    }
}
