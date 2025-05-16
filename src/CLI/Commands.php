<?php
/**
 * WP-CLI Commands for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\CLI;

use WP_CLI;
use WP_CLI_Command;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage Energy Analytics plugin.
 *
 * ## EXAMPLES
 *
 *     # Sync ACF fields from JSON file
 *     $ wp energy sync-fields
 *
 *     # Export energy data to CSV
 *     $ wp energy export --format=csv
 *
 *     # Export energy data to PDF for a specific time period
 *     $ wp energy export --format=pdf --period=month
 *
 * @package EA\CLI
 */
class Commands extends WP_CLI_Command {

    /**
     * Sync ACF field groups from JSON file.
     *
     * ## EXAMPLES
     *
     *     # Sync ACF fields from JSON file
     *     $ wp energy sync-fields
     *
     *     # Sync ACF fields from a specific file
     *     $ wp energy sync-fields --file=/path/to/file.json
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : Path to ACF JSON file. If not provided, uses the path from plugin settings.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function sync_fields( $args, $assoc_args ) {
        // Check if ACF is active.
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            WP_CLI::error( __( 'Advanced Custom Fields is not active. Please install and activate ACF to use this feature.', 'energy-analytics' ) );
        }

        // Get file path.
        $file_path = isset( $assoc_args['file'] ) ? $assoc_args['file'] : get_option( 'ea_acf_json_path', '' );

        if ( empty( $file_path ) ) {
            WP_CLI::error( __( 'ACF JSON path is not set. Please set it in the plugin settings or specify the --file parameter.', 'energy-analytics' ) );
        }

        // If path is relative, make it absolute.
        if ( strpos( $file_path, '/' ) !== 0 && strpos( $file_path, ':' ) !== 1 ) {
            $file_path = ABSPATH . $file_path;
        }

        // Check if file exists.
        if ( ! file_exists( $file_path ) ) {
            WP_CLI::error( sprintf( __( 'ACF JSON file not found: %s', 'energy-analytics' ), $file_path ) );
        }

        // Load JSON file.
        WP_CLI::log( sprintf( __( 'Loading ACF JSON file: %s', 'energy-analytics' ), $file_path ) );
        $json_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $field_groups = json_decode( $json_content, true );

        if ( ! is_array( $field_groups ) ) {
            WP_CLI::error( __( 'Invalid JSON format in ACF file.', 'energy-analytics' ) );
        }

        // Register field groups.
        $imported = 0;

        WP_CLI::log( __( 'Importing field groups...', 'energy-analytics' ) );
        foreach ( $field_groups as $field_group ) {
            acf_add_local_field_group( $field_group );
            $imported++;
            WP_CLI::log( sprintf( __( 'Imported field group: %s', 'energy-analytics' ), $field_group['title'] ?? 'Unnamed' ) );
        }

        WP_CLI::success( sprintf( __( 'Successfully imported %d field groups.', 'energy-analytics' ), $imported ) );
    }

    /**
     * Export energy data.
     *
     * ## EXAMPLES
     *
     *     # Export energy data to CSV
     *     $ wp energy export --format=csv
     *
     *     # Export energy data to PDF for a specific time period
     *     $ wp energy export --format=pdf --period=month
     *
     *     # Export energy data for a specific form
     *     $ wp energy export --format=json --form_id=energy_form_1
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Export format. Accepts: 'csv', 'pdf', 'json'.
     * ---
     * default: csv
     * options:
     *   - csv
     *   - pdf
     *   - json
     * ---
     *
     * [--period=<period>]
     * : Time period for export. Accepts: 'day', 'week', 'month', 'year', 'all'.
     * ---
     * default: month
     * options:
     *   - day
     *   - week
     *   - month
     *   - year
     *   - all
     * ---
     *
     * [--form_id=<form_id>]
     * : Filter by ACF form ID.
     *
     * [--output=<output>]
     * : Output file path. If not provided, file will be saved to the uploads directory.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function export( $args, $assoc_args ) {
        // Get parameters.
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'csv';
        $period = isset( $assoc_args['period'] ) ? $assoc_args['period'] : 'month';
        $form_id = isset( $assoc_args['form_id'] ) ? $assoc_args['form_id'] : '';
        $output = isset( $assoc_args['output'] ) ? $assoc_args['output'] : '';

        // Validate format.
        $valid_formats = array( 'csv', 'pdf', 'json' );
        if ( ! in_array( $format, $valid_formats, true ) ) {
            WP_CLI::error( sprintf( __( 'Invalid format: %s. Valid formats are: %s', 'energy-analytics' ), $format, implode( ', ', $valid_formats ) ) );
        }

        // Validate period.
        $valid_periods = array( 'day', 'week', 'month', 'year', 'all' );
        if ( ! in_array( $period, $valid_periods, true ) ) {
            WP_CLI::error( sprintf( __( 'Invalid period: %s. Valid periods are: %s', 'energy-analytics' ), $period, implode( ', ', $valid_periods ) ) );
        }

        // Get data from database.
        global $wpdb;
        $table_name = $wpdb->prefix . 'energy_logs';

        // Build query.
        $query = "SELECT * FROM {$table_name} WHERE 1=1";
        $query_args = array();

        // Add period filter.
        switch ( $period ) {
            case 'day':
                $query .= " AND created_at >= %s";
                $query_args[] = date( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
                break;

            case 'week':
                $query .= " AND created_at >= %s";
                $query_args[] = date( 'Y-m-d 00:00:00', strtotime( '-1 week' ) );
                break;

            case 'month':
                $query .= " AND created_at >= %s";
                $query_args[] = date( 'Y-m-d 00:00:00', strtotime( '-1 month' ) );
                break;

            case 'year':
                $query .= " AND created_at >= %s";
                $query_args[] = date( 'Y-m-d 00:00:00', strtotime( '-1 year' ) );
                break;

            case 'all':
            default:
                // No date filter for 'all'.
                break;
        }

        // Add form filter.
        if ( ! empty( $form_id ) ) {
            $query .= " AND form_id = %s";
            $query_args[] = $form_id;
        }

        // Order by date.
        $query .= " ORDER BY created_at DESC";

        // Prepare query if we have arguments.
        if ( ! empty( $query_args ) ) {
            $query = $wpdb->prepare( $query, $query_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // Get results.
        $results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ( empty( $results ) ) {
            WP_CLI::error( __( 'No data found for the specified criteria.', 'energy-analytics' ) );
        }

        WP_CLI::log( sprintf( __( 'Found %d records.', 'energy-analytics' ), count( $results ) ) );

        // Generate export based on format.
        switch ( $format ) {
            case 'csv':
                $file_info = $this->generate_csv_export( $results, $output );
                break;

            case 'pdf':
                $file_info = $this->generate_pdf_export( $results, $output );
                break;

            case 'json':
                $file_info = $this->generate_json_export( $results, $output );
                break;

            default:
                WP_CLI::error( __( 'Invalid export format specified.', 'energy-analytics' ) );
                break;
        }

        WP_CLI::success( sprintf( __( 'Export successful. File saved to: %s', 'energy-analytics' ), $file_info['path'] ) );
    }

    /**
     * Generate CSV export.
     *
     * @param array  $results Database results.
     * @param string $output  Output file path.
     * @return array File information.
     */
    private function generate_csv_export( $results, $output = '' ) {
        // Generate filename.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.csv';

        // Determine file path.
        if ( ! empty( $output ) ) {
            $file_path = $output;
        } else {
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/energy-analytics';

            // Create directory if it doesn't exist.
            if ( ! file_exists( $export_dir ) ) {
                wp_mkdir_p( $export_dir );

                // Add index.php to prevent directory listing.
                file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
            }

            $file_path = $export_dir . '/' . $filename;
        }

        // Open file for writing.
        $fp = fopen( $file_path, 'w' );

        if ( false === $fp ) {
            WP_CLI::error( sprintf( __( 'Could not create export file: %s', 'energy-analytics' ), $file_path ) );
        }

        // Write headers.
        fputcsv( $fp, array( 'ID', 'Form ID', 'User ID', 'Created At', 'Field Data' ) );

        // Write data.
        foreach ( $results as $result ) {
            fputcsv( $fp, array(
                $result->id,
                $result->form_id,
                $result->user_id,
                $result->created_at,
                $result->field_data,
            ) );
        }

        // Close file.
        fclose( $fp );

        return array(
            'path'     => $file_path,
            'filename' => $filename,
            'mime_type' => 'text/csv',
        );
    }

    /**
     * Generate PDF export.
     *
     * @param array  $results Database results.
     * @param string $output  Output file path.
     * @return array File information.
     */
    private function generate_pdf_export( $results, $output = '' ) {
        // Check if Dompdf is available.
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            WP_CLI::error( __( 'PDF generation library is not available. Please run "composer install" in the plugin directory.', 'energy-analytics' ) );
        }

        // Create HTML content.
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <title><?php esc_html_e( 'Energy Analytics Export', 'energy-analytics' ); ?></title>
            <style>
                body {
                    font-family: sans-serif;
                    color: #333;
                    line-height: 1.5;
                    font-size: 12px;
                }
                h1 {
                    color: #0073aa;
                    font-size: 20px;
                    margin-bottom: 20px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #0073aa;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                th {
                    background-color: #f1f1f1;
                    text-align: left;
                    padding: 8px;
                    font-size: 12px;
                    border: 1px solid #ddd;
                }
                td {
                    padding: 8px;
                    border: 1px solid #ddd;
                    font-size: 11px;
                }
                .logo {
                    text-align: right;
                    margin-bottom: 20px;
                }
                .logo img {
                    max-width: 150px;
                    max-height: 50px;
                }
                .footer {
                    margin-top: 30px;
                    text-align: center;
                    font-size: 10px;
                    color: #666;
                }
            </style>
        </head>
        <body>
            <?php
            $logo_url = get_option( 'ea_pdf_logo', '' );
            if ( ! empty( $logo_url ) ) :
            ?>
            <div class="logo">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Company Logo', 'energy-analytics' ); ?>">
            </div>
            <?php endif; ?>
            
            <h1><?php esc_html_e( 'Energy Analytics Export', 'energy-analytics' ); ?></h1>
            
            <p>
                <?php
                /* translators: %s: Current date in date_format */
                printf( esc_html__( 'Generated on: %s', 'energy-analytics' ), esc_html( date_i18n( get_option( 'date_format' ) ) ) );
                ?>
            </p>
            
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'energy-analytics' ); ?></th>
                        <th><?php esc_html_e( 'Form', 'energy-analytics' ); ?></th>
                        <th><?php esc_html_e( 'User', 'energy-analytics' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'energy-analytics' ); ?></th>
                        <th><?php esc_html_e( 'Data', 'energy-analytics' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $results as $result ) : ?>
                        <tr>
                            <td><?php echo esc_html( $result->id ); ?></td>
                            <td><?php echo esc_html( $result->form_id ); ?></td>
                            <td>
                                <?php
                                $user = get_user_by( 'id', $result->user_id );
                                echo esc_html( $user ? $user->display_name : __( 'Unknown', 'energy-analytics' ) );
                                ?>
                            </td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $result->created_at ) ) ); ?></td>
                            <td>
                                <?php
                                $field_data = json_decode( $result->field_data, true );
                                if ( is_array( $field_data ) ) {
                                    foreach ( $field_data as $key => $value ) {
                                        if ( is_array( $value ) ) {
                                            $value = implode( ', ', $value );
                                        }
                                        echo esc_html( $key ) . ': ' . esc_html( $value ) . '<br>';
                                    }
                                } else {
                                    echo esc_html( $result->field_data );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="footer">
                <p><?php esc_html_e( 'Generated by Energy Analytics Plugin', 'energy-analytics' ); ?></p>
            </div>
        </body>
        </html>
        <?php
        $html = ob_get_clean();

        // Initialize Dompdf.
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper( 'A4', 'landscape' );
        $dompdf->loadHtml( $html );
        $dompdf->render();

        // Generate filename.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.pdf';

        // Determine file path.
        if ( ! empty( $output ) ) {
            $file_path = $output;
        } else {
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/energy-analytics';

            // Create directory if it doesn't exist.
            if ( ! file_exists( $export_dir ) ) {
                wp_mkdir_p( $export_dir );

                // Add index.php to prevent directory listing.
                file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
            }

            $file_path = $export_dir . '/' . $filename;
        }

        // Save PDF to file.
        file_put_contents( $file_path, $dompdf->output() );

        return array(
            'path'     => $file_path,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
        );
    }

    /**
     * Generate JSON export.
     *
     * @param array  $results Database results.
     * @param string $output  Output file path.
     * @return array File information.
     */
    private function generate_json_export( $results, $output = '' ) {
        // Process the data.
        $export_data = array();

        foreach ( $results as $result ) {
            $field_data = json_decode( $result->field_data, true );

            $export_data[] = array(
                'id'         => $result->id,
                'form_id'    => $result->form_id,
                'user_id'    => $result->user_id,
                'created_at' => $result->created_at,
                'field_data' => $field_data,
            );
        }

        // Generate filename.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.json';

        // Determine file path.
        if ( ! empty( $output ) ) {
            $file_path = $output;
        } else {
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/energy-analytics';

            // Create directory if it doesn't exist.
            if ( ! file_exists( $export_dir ) ) {
                wp_mkdir_p( $export_dir );

                // Add index.php to prevent directory listing.
                file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
            }

            $file_path = $export_dir . '/' . $filename;
        }

        // Save JSON to file.
        file_put_contents( $file_path, wp_json_encode( $export_data, JSON_PRETTY_PRINT ) );

        return array(
            'path'     => $file_path,
            'filename' => $filename,
            'mime_type' => 'application/json',
        );
    }
}

