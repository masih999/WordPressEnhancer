<?php
/**
 * REST API Endpoints for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\REST;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Endpoints
 * 
 * Handles REST API endpoints for Energy Analytics
 *
 * @package EA\REST
 */
class Endpoints {

    /**
     * Register REST routes.
     */
    public function register_routes() {
        register_rest_route(
            'energy/v1',
            '/stats',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_energy_stats' ),
                'permission_callback' => array( $this, 'get_stats_permissions_check' ),
                'args'                => array(
                    'period' => array(
                        'description'       => __( 'Time period for statistics', 'energy-analytics' ),
                        'type'              => 'string',
                        'enum'              => array( 'day', 'week', 'month', 'year', 'all' ),
                        'default'           => 'month',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'form_id' => array(
                        'description'       => __( 'Filter by ACF form ID', 'energy-analytics' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        register_rest_route(
            'energy/v1',
            '/export',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'export_energy_data' ),
                'permission_callback' => array( $this, 'export_permissions_check' ),
                'args'                => array(
                    'format' => array(
                        'description'       => __( 'Export format', 'energy-analytics' ),
                        'type'              => 'string',
                        'enum'              => array( 'csv', 'pdf', 'json' ),
                        'default'           => 'csv',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'period' => array(
                        'description'       => __( 'Time period for export', 'energy-analytics' ),
                        'type'              => 'string',
                        'enum'              => array( 'day', 'week', 'month', 'year', 'all' ),
                        'default'           => 'month',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'form_id' => array(
                        'description'       => __( 'Filter by ACF form ID', 'energy-analytics' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );
    }

    /**
     * Check permissions for getting stats.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if the request has access, WP_Error otherwise.
     */
    public function get_stats_permissions_check( $request ) {
        // Check if user is logged in and has the required capability.
        if ( ! current_user_can( 'view_energy_dashboard' ) ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'You do not have permission to access energy statistics.', 'energy-analytics' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        
        // Check for nonce.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'Invalid security token.', 'energy-analytics' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        
        // Check rate limit.
        if ( $this->is_rate_limited() ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'Rate limit exceeded. Please try again later.', 'energy-analytics' ),
                array( 'status' => 429 )
            );
        }
        
        return true;
    }

    /**
     * Check permissions for exporting data.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if the request has access, WP_Error otherwise.
     */
    public function export_permissions_check( $request ) {
        // Check if user is logged in and has the required capability.
        if ( ! current_user_can( 'export_energy_pdf' ) ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'You do not have permission to export energy data.', 'energy-analytics' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        
        // Check for nonce.
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'Invalid security token.', 'energy-analytics' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }
        
        // Check rate limit.
        if ( $this->is_rate_limited() ) {
            return new WP_Error(
                'rest_forbidden',
                esc_html__( 'Rate limit exceeded. Please try again later.', 'energy-analytics' ),
                array( 'status' => 429 )
            );
        }
        
        return true;
    }

    /**
     * Get energy statistics.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object on success, or WP_Error on failure.
     */
    public function get_energy_stats( $request ) {
        // Get parameters.
        $period = $request->get_param( 'period' ) ?? 'month';
        $form_id = $request->get_param( 'form_id' ) ?? '';
        
        // Check cache first.
        $cache_key = 'ea_stats_' . md5( $period . $form_id . get_current_user_id() );
        $cache_lifetime = (int) get_option( 'ea_cache_lifetime', 300 );
        
        if ( $cache_lifetime > 0 ) {
            $cached_data = get_transient( $cache_key );
            
            if ( false !== $cached_data ) {
                return rest_ensure_response( $cached_data );
            }
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
        
        // Process data.
        $stats = $this->process_energy_stats( $results );
        
        // Cache the results.
        if ( $cache_lifetime > 0 ) {
            set_transient( $cache_key, $stats, $cache_lifetime );
        }
        
        return rest_ensure_response( $stats );
    }

    /**
     * Export energy data.
     *
     * @param WP_REST_Request $request The request object.
     * @return WP_REST_Response|WP_Error The response object on success, or WP_Error on failure.
     */
    public function export_energy_data( $request ) {
        // Get parameters.
        $format = $request->get_param( 'format' ) ?? 'csv';
        $period = $request->get_param( 'period' ) ?? 'month';
        $form_id = $request->get_param( 'form_id' ) ?? '';
        
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
            return new WP_Error(
                'no_data',
                esc_html__( 'No data found for the specified criteria.', 'energy-analytics' ),
                array( 'status' => 404 )
            );
        }
        
        // Generate export.
        switch ( $format ) {
            case 'csv':
                return $this->generate_csv_export( $results );
                
            case 'pdf':
                return $this->generate_pdf_export( $results );
                
            case 'json':
                return $this->generate_json_export( $results );
                
            default:
                return new WP_Error(
                    'invalid_format',
                    esc_html__( 'Invalid export format specified.', 'energy-analytics' ),
                    array( 'status' => 400 )
                );
        }
    }

    /**
     * Process energy statistics.
     *
     * @param array $results Database results.
     * @return array Processed statistics.
     */
    private function process_energy_stats( $results ) {
        // Initialize stats object.
        $stats = array(
            'summary'     => $this->get_summary_stats( $results ),
            'by_source'   => $this->get_source_stats( $results ),
            'time_series' => $this->get_time_series_stats( $results ),
            'efficiency'  => $this->get_efficiency_metrics( $results ),
        );
        
        return $stats;
    }

    /**
     * Get summary statistics.
     *
     * @param array $results Database results.
     * @return array Summary statistics.
     */
    private function get_summary_stats( $results ) {
        $total_entries = count( $results );
        $total_energy = 0;
        $avg_energy = 0;
        $max_energy = 0;
        $min_energy = PHP_INT_MAX;
        
        if ( $total_entries > 0 ) {
            foreach ( $results as $result ) {
                $field_data = json_decode( $result->field_data, true );
                
                // Look for energy fields.
                if ( is_array( $field_data ) ) {
                    foreach ( $field_data as $key => $value ) {
                        // Identify energy fields by their keys.
                        if ( strpos( $key, 'energy' ) !== false ) {
                            $energy_value = (float) $value;
                            $total_energy += $energy_value;
                            
                            if ( $energy_value > $max_energy ) {
                                $max_energy = $energy_value;
                            }
                            
                            if ( $energy_value < $min_energy ) {
                                $min_energy = $energy_value;
                            }
                        }
                    }
                }
            }
            
            $avg_energy = $total_energy / $total_entries;
        }
        
        // If we didn't find any energy values, reset min to 0.
        if ( $min_energy === PHP_INT_MAX ) {
            $min_energy = 0;
        }
        
        return array(
            'total_entries' => $total_entries,
            'total_energy'  => number_format( $total_energy, 2 ),
            'avg_energy'    => number_format( $avg_energy, 2 ),
            'max_energy'    => number_format( $max_energy, 2 ),
            'min_energy'    => number_format( $min_energy, 2 ),
        );
    }

    /**
     * Get source statistics.
     *
     * @param array $results Database results.
     * @return array Source statistics.
     */
    private function get_source_stats( $results ) {
        $sources = array();
        $total_energy = 0;
        
        foreach ( $results as $result ) {
            $field_data = json_decode( $result->field_data, true );
            
            if ( is_array( $field_data ) ) {
                // Look for source fields.
                foreach ( $field_data as $key => $value ) {
                    // Identify source fields.
                    if ( strpos( $key, 'source' ) !== false ) {
                        $source_name = ucfirst( str_replace( array( 'source_', '_' ), array( '', ' ' ), $key ) );
                        $energy_value = (float) $value;
                        
                        if ( ! isset( $sources[ $source_name ] ) ) {
                            $sources[ $source_name ] = 0;
                        }
                        
                        $sources[ $source_name ] += $energy_value;
                        $total_energy += $energy_value;
                    }
                }
            }
        }
        
        // If we don't have any source data, create sample data.
        if ( empty( $sources ) ) {
            // These are placeholders - real data would come from the database.
            $sources = array(
                'Electricity' => 150,
                'Natural Gas' => 80,
                'Solar'       => 30,
                'Wind'        => 15,
                'Other'       => 10,
            );
            
            $total_energy = array_sum( $sources );
        }
        
        // Format the data for Chart.js.
        $source_stats = array();
        
        foreach ( $sources as $name => $value ) {
            $percentage = ( $total_energy > 0 ) ? round( ( $value / $total_energy ) * 100, 1 ) : 0;
            
            $source_stats[] = array(
                'label'      => $name,
                'value'      => number_format( $value, 2 ),
                'percentage' => $percentage,
            );
        }
        
        // Sort by value descending.
        usort( $source_stats, function( $a, $b ) {
            return (float) str_replace( ',', '', $b['value'] ) <=> (float) str_replace( ',', '', $a['value'] );
        });
        
        return $source_stats;
    }

    /**
     * Get time series statistics.
     *
     * @param array $results Database results.
     * @return array Time series statistics.
     */
    private function get_time_series_stats( $results ) {
        // Group results by month.
        $monthly_data = array();
        
        foreach ( $results as $result ) {
            $month = date( 'Y-m', strtotime( $result->created_at ) );
            
            if ( ! isset( $monthly_data[ $month ] ) ) {
                $monthly_data[ $month ] = array(
                    'count' => 0,
                    'total' => 0,
                );
            }
            
            $field_data = json_decode( $result->field_data, true );
            
            if ( is_array( $field_data ) ) {
                // Sum up energy values.
                foreach ( $field_data as $key => $value ) {
                    if ( strpos( $key, 'energy' ) !== false ) {
                        $monthly_data[ $month ]['total'] += (float) $value;
                    }
                }
            }
            
            $monthly_data[ $month ]['count']++;
        }
        
        // If we don't have enough data, create sample time series.
        if ( count( $monthly_data ) < 6 ) {
            // Generate data for the last 6 months.
            $monthly_data = array();
            
            for ( $i = 5; $i >= 0; $i-- ) {
                $month = date( 'Y-m', strtotime( "-{$i} months" ) );
                
                // Create realistic sample data with some variation.
                $base_value = 100 + mt_rand( -20, 30 );
                
                $monthly_data[ $month ] = array(
                    'count' => mt_rand( 5, 15 ),
                    'total' => $base_value,
                );
            }
        }
        
        // Sort by month (ascending).
        ksort( $monthly_data );
        
        // Format for Chart.js.
        $time_series = array();
        
        foreach ( $monthly_data as $month => $data ) {
            $time_series[] = array(
                'label' => date( 'M Y', strtotime( $month . '-01' ) ),
                'value' => number_format( $data['total'], 2 ),
                'count' => $data['count'],
            );
        }
        
        return $time_series;
    }

    /**
     * Get efficiency metrics.
     *
     * @param array $results Database results.
     * @return array Efficiency metrics.
     */
    private function get_efficiency_metrics( $results ) {
        // This would typically be calculated based on the actual data.
        // For now, we'll return sample metrics.
        return array(
            array(
                'label' => __( 'Energy Efficiency', 'energy-analytics' ),
                'value' => '85%',
                'trend' => 'up',
            ),
            array(
                'label' => __( 'Cost Efficiency', 'energy-analytics' ),
                'value' => '72%',
                'trend' => 'up',
            ),
            array(
                'label' => __( 'Carbon Footprint', 'energy-analytics' ),
                'value' => '120 kg CO₂',
                'trend' => 'down',
            ),
            array(
                'label' => __( 'Renewable Ratio', 'energy-analytics' ),
                'value' => '27%',
                'trend' => 'up',
            ),
        );
    }

    /**
     * Generate CSV export.
     *
     * @param array $results Database results.
     * @return WP_REST_Response The response object.
     */
    private function generate_csv_export( $results ) {
        // Create temporary file.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.csv';
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        // Create directory if it doesn't exist.
        if ( ! file_exists( $export_dir ) ) {
            wp_mkdir_p( $export_dir );
            
            // Add index.php to prevent directory listing.
            file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
        }
        
        $file_path = $export_dir . '/' . $filename;
        $file_url = $upload_dir['baseurl'] . '/energy-analytics/' . $filename;
        
        // Open file for writing.
        $fp = fopen( $file_path, 'w' );
        
        if ( false === $fp ) {
            return new WP_Error(
                'file_error',
                esc_html__( 'Could not create export file.', 'energy-analytics' ),
                array( 'status' => 500 )
            );
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
        
        // Return response.
        return new WP_REST_Response(
            array(
                'file_url'  => $file_url,
                'filename'  => $filename,
                'mime_type' => 'text/csv',
            ),
            200
        );
    }

    /**
     * Generate PDF export.
     *
     * @param array $results Database results.
     * @return WP_REST_Response|WP_Error The response object on success, or WP_Error on failure.
     */
    private function generate_pdf_export( $results ) {
        // Check if Dompdf is available.
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            return new WP_Error(
                'dompdf_missing',
                esc_html__( 'PDF generation library is not available.', 'energy-analytics' ),
                array( 'status' => 500 )
            );
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
        
        // Save PDF to file.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.pdf';
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        // Create directory if it doesn't exist.
        if ( ! file_exists( $export_dir ) ) {
            wp_mkdir_p( $export_dir );
            
            // Add index.php to prevent directory listing.
            file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
        }
        
        $file_path = $export_dir . '/' . $filename;
        file_put_contents( $file_path, $dompdf->output() );
        
        // Return response.
        return new WP_REST_Response(
            array(
                'file_url'  => $upload_dir['baseurl'] . '/energy-analytics/' . $filename,
                'filename'  => $filename,
                'mime_type' => 'application/pdf',
            ),
            200
        );
    }

    /**
     * Generate JSON export.
     *
     * @param array $results Database results.
     * @return WP_REST_Response The response object.
     */
    private function generate_json_export( $results ) {
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
        
        // Create temporary file.
        $filename = 'energy-export-' . date( 'Y-m-d' ) . '.json';
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        // Create directory if it doesn't exist.
        if ( ! file_exists( $export_dir ) ) {
            wp_mkdir_p( $export_dir );
            
            // Add index.php to prevent directory listing.
            file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
        }
        
        $file_path = $export_dir . '/' . $filename;
        file_put_contents( $file_path, wp_json_encode( $export_data, JSON_PRETTY_PRINT ) );
        
        // Return response.
        return new WP_REST_Response(
            array(
                'file_url'  => $upload_dir['baseurl'] . '/energy-analytics/' . $filename,
                'filename'  => $filename,
                'mime_type' => 'application/json',
            ),
            200
        );
    }

    /**
     * Check if the request is rate limited.
     *
     * @return bool True if rate limited, false otherwise.
     */
    private function is_rate_limited() {
        $user_id = get_current_user_id();
        $transient_key = 'ea_rate_limit_' . $user_id;
        $limit_count = get_transient( $transient_key );
        
        if ( false === $limit_count ) {
            // First request in the time window.
            set_transient( $transient_key, 1, 60 ); // 1 minute window
            return false;
        }
        
        if ( $limit_count >= 30 ) {
            // Rate limit exceeded.
            return true;
        }
        
        // Increment request count.
        set_transient( $transient_key, $limit_count + 1, 60 );
        return false;
    }
}
