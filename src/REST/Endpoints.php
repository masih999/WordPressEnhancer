<?php
/**
 * REST API Endpoints for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\REST;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Endpoints
 * 
 * Registers and handles REST API endpoints
 *
 * @package EA\REST
 */
class Endpoints {

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        register_rest_route( 'energy/v1', '/stats', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_energy_stats' ),
            'permission_callback' => array( $this, 'get_energy_stats_permissions_check' ),
        ) );

        register_rest_route( 'energy/v1', '/reports', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_energy_reports' ),
            'permission_callback' => array( $this, 'get_energy_reports_permissions_check' ),
        ) );

        register_rest_route( 'energy/v1', '/report/(?P<id>\d+)', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_energy_report' ),
            'permission_callback' => array( $this, 'get_energy_reports_permissions_check' ),
        ) );

        register_rest_route( 'energy/v1', '/submit', array(
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'submit_energy_data' ),
            'permission_callback' => array( $this, 'submit_energy_data_permissions_check' ),
        ) );
    }

    /**
     * Check if a given request has access to get stats.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return bool|\WP_Error
     */
    public function get_energy_stats_permissions_check( $request ) {
        return current_user_can( 'view_energy_dashboard' );
    }

    /**
     * Check if a given request has access to get reports.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return bool|\WP_Error
     */
    public function get_energy_reports_permissions_check( $request ) {
        return current_user_can( 'view_energy_reports' );
    }

    /**
     * Check if a given request has access to submit data.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return bool|\WP_Error
     */
    public function submit_energy_data_permissions_check( $request ) {
        return current_user_can( 'edit_energy_reports' );
    }

    /**
     * Get energy statistics.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return \WP_REST_Response
     */
    public function get_energy_stats( $request ) {
        global $wpdb;
        
        // Get cache lifetime.
        $cache_lifetime = get_option( 'ea_cache_lifetime', 300 );
        
        // Try to get stats from cache.
        $cache_key = 'ea_energy_stats';
        $stats = get_transient( $cache_key );
        
        if ( false === $stats || 0 === $cache_lifetime ) {
            // Cache expired or disabled, fetch fresh data.
            $table_name = $wpdb->prefix . 'energy_logs';
            
            // Get time series data (most recent 12 entries).
            $time_series_query = "
                SELECT DATE_FORMAT(created_at, '%Y-%m-%d') as date, 
                       SUM(JSON_EXTRACT(field_data, '$.energy_consumption')) as value
                FROM $table_name
                WHERE JSON_EXTRACT(field_data, '$.energy_consumption') IS NOT NULL
                GROUP BY date
                ORDER BY date DESC
                LIMIT 12
            ";
            $time_series = $wpdb->get_results( $time_series_query );
            
            // Reverse the order for chronological display.
            $time_series = array_reverse( $time_series );
            
            // Get energy sources data.
            $sources_query = "
                SELECT 'Electricity' as name, 
                       SUM(JSON_EXTRACT(field_data, '$.source_electricity')) as value,
                       ROUND(SUM(JSON_EXTRACT(field_data, '$.source_electricity')) / 
                             SUM(JSON_EXTRACT(field_data, '$.energy_consumption')) * 100, 1) as percentage
                FROM $table_name
                WHERE JSON_EXTRACT(field_data, '$.source_electricity') IS NOT NULL
                
                UNION ALL
                
                SELECT 'Gas' as name, 
                       SUM(JSON_EXTRACT(field_data, '$.source_gas')) as value,
                       ROUND(SUM(JSON_EXTRACT(field_data, '$.source_gas')) / 
                             SUM(JSON_EXTRACT(field_data, '$.energy_consumption')) * 100, 1) as percentage
                FROM $table_name
                WHERE JSON_EXTRACT(field_data, '$.source_gas') IS NOT NULL
                
                UNION ALL
                
                SELECT 'Renewable' as name, 
                       SUM(JSON_EXTRACT(field_data, '$.source_renewable')) as value,
                       ROUND(SUM(JSON_EXTRACT(field_data, '$.source_renewable')) / 
                             SUM(JSON_EXTRACT(field_data, '$.energy_consumption')) * 100, 1) as percentage
                FROM $table_name
                WHERE JSON_EXTRACT(field_data, '$.source_renewable') IS NOT NULL
            ";
            $energy_sources = $wpdb->get_results( $sources_query );
            
            // Sample efficiency metrics (these would typically come from calculations based on the data).
            $efficiency_metrics = array(
                array(
                    'name' => __( 'Usage Efficiency', 'energy-analytics' ),
                    'value' => 75,
                ),
                array(
                    'name' => __( 'Cost Efficiency', 'energy-analytics' ),
                    'value' => 68,
                ),
                array(
                    'name' => __( 'Energy Savings', 'energy-analytics' ),
                    'value' => 42,
                ),
                array(
                    'name' => __( 'Carbon Footprint', 'energy-analytics' ),
                    'value' => 60,
                ),
                array(
                    'name' => __( 'Resource Utilization', 'energy-analytics' ),
                    'value' => 85,
                ),
            );
            
            // Build the stats array.
            $stats = array(
                'time_series' => $time_series,
                'energy_sources' => $energy_sources,
                'efficiency_metrics' => $efficiency_metrics,
            );
            
            // Cache the stats if caching is enabled.
            if ( $cache_lifetime > 0 ) {
                set_transient( $cache_key, $stats, $cache_lifetime );
            }
        }
        
        return rest_ensure_response( $stats );
    }

    /**
     * Get energy reports.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return \WP_REST_Response
     */
    public function get_energy_reports( $request ) {
        global $wpdb;
        
        // Get params.
        $per_page = isset( $request['per_page'] ) ? absint( $request['per_page'] ) : 20;
        $page = isset( $request['page'] ) ? absint( $request['page'] ) : 1;
        $offset = ( $page - 1 ) * $per_page;
        
        // Get the reports.
        $table_name = $wpdb->prefix . 'energy_logs';
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        $reports = $wpdb->get_results( $query );
        
        // Get total count.
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        
        // Process reports.
        $data = array();
        foreach ( $reports as $report ) {
            $report_data = $this->prepare_report_for_response( $report );
            $data[] = $report_data;
        }
        
        // Set response headers.
        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );
        
        return $response;
    }

    /**
     * Get a single energy report.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_energy_report( $request ) {
        global $wpdb;
        
        // Get the report ID.
        $id = $request['id'];
        
        // Get the report.
        $table_name = $wpdb->prefix . 'energy_logs';
        $report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );
        
        if ( ! $report ) {
            return new \WP_Error( 'rest_report_not_found', __( 'Report not found.', 'energy-analytics' ), array( 'status' => 404 ) );
        }
        
        // Process the report.
        $data = $this->prepare_report_for_response( $report );
        
        return rest_ensure_response( $data );
    }

    /**
     * Submit energy data.
     *
     * @param \WP_REST_Request $request Full data about the request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function submit_energy_data( $request ) {
        global $wpdb;
        
        // Get the submitted data.
        $form_id = sanitize_text_field( $request['form_id'] );
        $field_data = $request['field_data'];
        
        if ( empty( $form_id ) || empty( $field_data ) ) {
            return new \WP_Error( 'rest_missing_data', __( 'Missing required data.', 'energy-analytics' ), array( 'status' => 400 ) );
        }
        
        // Validate the field data.
        if ( ! is_array( $field_data ) ) {
            return new \WP_Error( 'rest_invalid_data', __( 'Field data must be an array.', 'energy-analytics' ), array( 'status' => 400 ) );
        }
        
        // Insert the data.
        $table_name = $wpdb->prefix . 'energy_logs';
        $result = $wpdb->insert(
            $table_name,
            array(
                'form_id' => $form_id,
                'field_data' => wp_json_encode( $field_data ),
                'user_id' => get_current_user_id(),
                'created_at' => current_time( 'mysql' ),
            )
        );
        
        if ( false === $result ) {
            return new \WP_Error( 'rest_db_error', __( 'Error saving data to database.', 'energy-analytics' ), array( 'status' => 500 ) );
        }
        
        // Clear stats cache.
        delete_transient( 'ea_energy_stats' );
        
        // Return success.
        return rest_ensure_response( array(
            'success' => true,
            'id' => $wpdb->insert_id,
            'message' => __( 'Energy data submitted successfully.', 'energy-analytics' ),
        ) );
    }

    /**
     * Prepare a report for API response.
     *
     * @param object $report The report object.
     * @return array The prepared report.
     */
    private function prepare_report_for_response( $report ) {
        // Get user data.
        $user_info = get_userdata( $report->user_id );
        $username = $user_info ? $user_info->display_name : __( 'Unknown User', 'energy-analytics' );
        
        // Decode field data.
        $field_data = json_decode( $report->field_data, true );
        
        // Build the response.
        $data = array(
            'id' => (int) $report->id,
            'form_id' => $report->form_id,
            'user_id' => (int) $report->user_id,
            'username' => $username,
            'created_at' => $report->created_at,
            'field_data' => $field_data,
        );
        
        return $data;
    }
}