<?php
/**
 * WP-CLI Commands for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\CLI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Commands
 * 
 * Registers and handles WP-CLI commands
 *
 * @package EA\CLI
 */
class Commands {

    /**
     * Lists energy reports.
     *
     * ## OPTIONS
     *
     * [--count=<number>]
     * : Number of reports to show.
     * ---
     * default: 10
     * ---
     *
     * [--format=<format>]
     * : Output format. One of table, csv, json, yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     # List 10 most recent energy reports
     *     wp energy list-reports
     *
     *     # Export 50 reports as JSON
     *     wp energy list-reports --count=50 --format=json
     *
     * @when after_wp_load
     *
     * @param array $args       Indexed array of positional arguments.
     * @param array $assoc_args Named array of options.
     */
    public function list_reports( $args, $assoc_args ) {
        global $wpdb;
        
        $count = isset( $assoc_args['count'] ) ? absint( $assoc_args['count'] ) : 10;
        
        // Get reports from database.
        $table_name = $wpdb->prefix . 'energy_logs';
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            $count
        );
        
        $reports = $wpdb->get_results( $query );
        
        if ( empty( $reports ) ) {
            \WP_CLI::warning( 'No energy reports found.' );
            return;
        }
        
        // Format the reports for display.
        $data = array();
        foreach ( $reports as $report ) {
            $user_info = get_userdata( $report->user_id );
            $username = $user_info ? $user_info->display_name : 'Unknown User';
            
            $field_data = json_decode( $report->field_data, true );
            $energy_consumption = isset( $field_data['energy_consumption'] ) ? $field_data['energy_consumption'] : 'N/A';
            
            $data[] = array(
                'ID' => $report->id,
                'Form' => $report->form_id,
                'User' => $username,
                'Date' => $report->created_at,
                'Consumption' => $energy_consumption,
            );
        }
        
        // Display the reports in requested format.
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        
        \WP_CLI\Utils\format_items( $format, $data, array( 'ID', 'Form', 'User', 'Date', 'Consumption' ) );
    }

    /**
     * Shows details for a specific energy report.
     *
     * ## OPTIONS
     *
     * <id>
     * : The ID of the report to show.
     *
     * [--format=<format>]
     * : Output format. One of table, csv, json, yaml.
     * ---
     * default: yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     # Show details for report with ID 123
     *     wp energy show-report 123
     *
     * @when after_wp_load
     *
     * @param array $args       Indexed array of positional arguments.
     * @param array $assoc_args Named array of options.
     */
    public function show_report( $args, $assoc_args ) {
        global $wpdb;
        
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a report ID.' );
        }
        
        $id = absint( $args[0] );
        
        // Get the report.
        $table_name = $wpdb->prefix . 'energy_logs';
        $report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
        
        if ( ! $report ) {
            \WP_CLI::error( "Report with ID $id not found." );
        }
        
        // Get user info.
        $user_info = get_userdata( $report->user_id );
        $username = $user_info ? $user_info->display_name : 'Unknown User';
        
        // Decode field data.
        $field_data = json_decode( $report->field_data, true );
        
        // Format the data.
        $data = array(
            'ID' => $report->id,
            'Form ID' => $report->form_id,
            'User ID' => $report->user_id,
            'Username' => $username,
            'Created At' => $report->created_at,
            'Field Data' => $field_data,
        );
        
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'yaml';
        
        if ( 'table' === $format ) {
            // For table format, flatten the field data.
            $flat_data = array(
                'ID' => $report->id,
                'Form ID' => $report->form_id,
                'User ID' => $report->user_id,
                'Username' => $username,
                'Created At' => $report->created_at,
            );
            
            foreach ( $field_data as $key => $value ) {
                $flat_data[ $key ] = $value;
            }
            
            \WP_CLI\Utils\format_items( 'table', array( $flat_data ), array_keys( $flat_data ) );
        } else {
            \WP_CLI::print_value( $data, array( 'format' => $format ) );
        }
    }

    /**
     * Imports energy data from a CSV file.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the CSV file to import.
     *
     * [--form-id=<form-id>]
     * : The form ID to use for imported records.
     * ---
     * default: imported_form
     * ---
     *
     * [--user-id=<user-id>]
     * : The user ID to associate with imported records.
     * ---
     * default: 1
     * ---
     *
     * [--dry-run]
     * : Just show what would be imported without actually importing.
     *
     * ## EXAMPLES
     *
     *     # Import data from energy-data.csv
     *     wp energy import energy-data.csv --form-id=energy_form_1 --user-id=2
     *
     *     # Preview import without making changes
     *     wp energy import energy-data.csv --dry-run
     *
     * @when after_wp_load
     *
     * @param array $args       Indexed array of positional arguments.
     * @param array $assoc_args Named array of options.
     */
    public function import( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please provide a CSV file path.' );
        }
        
        $file_path = $args[0];
        
        if ( ! file_exists( $file_path ) ) {
            \WP_CLI::error( "File not found: $file_path" );
        }
        
        $form_id = isset( $assoc_args['form-id'] ) ? $assoc_args['form-id'] : 'imported_form';
        $user_id = isset( $assoc_args['user-id'] ) ? absint( $assoc_args['user-id'] ) : 1;
        $dry_run = isset( $assoc_args['dry-run'] );
        
        // Open the CSV file.
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            \WP_CLI::error( "Could not open file: $file_path" );
        }
        
        // Read the header row.
        $header = fgetcsv( $handle );
        if ( ! $header ) {
            \WP_CLI::error( 'Could not read CSV header row.' );
        }
        
        // Remove BOM if present.
        $header[0] = str_replace( "\xEF\xBB\xBF", '', $header[0] );
        
        // Prepare for import.
        global $wpdb;
        $table_name = $wpdb->prefix . 'energy_logs';
        $imported = 0;
        $skipped = 0;
        
        // Start progress bar.
        $progress = \WP_CLI\Utils\make_progress_bar( 'Importing data', filesize( $file_path ) );
        
        // Process each row.
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            // Skip blank rows.
            if ( empty( $row ) || count( $row ) !== count( $header ) ) {
                $skipped++;
                continue;
            }
            
            // Create field data array.
            $field_data = array();
            foreach ( $row as $index => $value ) {
                if ( isset( $header[ $index ] ) ) {
                    $field_data[ $header[ $index ] ] = $value;
                }
            }
            
            if ( $dry_run ) {
                \WP_CLI::log( 'Would import: ' . print_r( $field_data, true ) );
            } else {
                $result = $wpdb->insert(
                    $table_name,
                    array(
                        'form_id' => $form_id,
                        'field_data' => wp_json_encode( $field_data ),
                        'user_id' => $user_id,
                        'created_at' => current_time( 'mysql' ),
                    )
                );
                
                if ( $result ) {
                    $imported++;
                } else {
                    $skipped++;
                    \WP_CLI::warning( 'Failed to import row: ' . print_r( $field_data, true ) );
                }
            }
            
            $progress->tick();
        }
        
        $progress->finish();
        fclose( $handle );
        
        if ( $dry_run ) {
            \WP_CLI::success( "Dry run completed. Would import $imported records." );
        } else {
            \WP_CLI::success( "Import completed. Imported $imported records, skipped $skipped." );
        }
        
        // Clear cache.
        delete_transient( 'ea_energy_stats' );
    }

    /**
     * Exports energy data to a CSV file.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : Path to the output CSV file. If not provided, output will be sent to STDOUT.
     *
     * [--form-id=<form-id>]
     * : Filter by form ID.
     *
     * [--limit=<limit>]
     * : Limit the number of records to export.
     * ---
     * default: 1000
     * ---
     *
     * ## EXAMPLES
     *
     *     # Export data to energy-export.csv
     *     wp energy export energy-export.csv
     *
     *     # Export only data from a specific form
     *     wp energy export --form-id=energy_form_1
     *
     * @when after_wp_load
     *
     * @param array $args       Indexed array of positional arguments.
     * @param array $assoc_args Named array of options.
     */
    public function export( $args, $assoc_args ) {
        global $wpdb;
        
        $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 1000;
        $form_id = isset( $assoc_args['form-id'] ) ? $assoc_args['form-id'] : '';
        
        // Build the query.
        $table_name = $wpdb->prefix . 'energy_logs';
        $query = "SELECT * FROM $table_name";
        
        if ( ! empty( $form_id ) ) {
            $query .= $wpdb->prepare( " WHERE form_id = %s", $form_id );
        }
        
        $query .= " ORDER BY created_at DESC";
        
        if ( $limit > 0 ) {
            $query .= $wpdb->prepare( " LIMIT %d", $limit );
        }
        
        $reports = $wpdb->get_results( $query );
        
        if ( empty( $reports ) ) {
            \WP_CLI::warning( 'No energy reports found.' );
            return;
        }
        
        // Open the output file or use STDOUT.
        $output = isset( $args[0] ) ? fopen( $args[0], 'w' ) : STDOUT;
        if ( ! $output ) {
            \WP_CLI::error( "Could not open output file: {$args[0]}" );
        }
        
        // Add BOM for UTF-8 CSV.
        fputs( $output, "\xEF\xBB\xBF" );
        
        // Get all possible fields from the first report.
        $first_report = $reports[0];
        $field_data = json_decode( $first_report->field_data, true );
        $header = array( 'id', 'form_id', 'user_id', 'created_at' );
        
        foreach ( array_keys( $field_data ) as $field_key ) {
            $header[] = $field_key;
        }
        
        // Write the header row.
        fputcsv( $output, $header );
        
        // Write the data rows.
        foreach ( $reports as $report ) {
            $row = array(
                $report->id,
                $report->form_id,
                $report->user_id,
                $report->created_at,
            );
            
            $field_data = json_decode( $report->field_data, true );
            
            foreach ( array_keys( $field_data ) as $field_key ) {
                $row[] = isset( $field_data[ $field_key ] ) ? $field_data[ $field_key ] : '';
            }
            
            fputcsv( $output, $row );
        }
        
        if ( $output !== STDOUT ) {
            fclose( $output );
            \WP_CLI::success( "Exported " . count( $reports ) . " records to {$args[0]}" );
        }
    }

    /**
     * Clears the energy stats cache.
     *
     * ## EXAMPLES
     *
     *     # Clear the stats cache
     *     wp energy clear-cache
     *
     * @when after_wp_load
     */
    public function clear_cache() {
        delete_transient( 'ea_energy_stats' );
        \WP_CLI::success( 'Energy analytics cache cleared.' );
    }
}