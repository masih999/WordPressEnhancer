<?php
/**
 * Admin class for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Admin
 * 
 * Handles admin menus, list tables, and settings
 *
 * @package EA\Admin
 */
class Admin {

    /**
     * Init hook.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_ea_export_pdf', array( $this, 'handle_pdf_export' ) );
        add_action( 'admin_post_sync_acf_fields', array( $this, 'sync_acf_fields' ) );
    }

    /**
     * Register admin menus.
     */
    public function register_admin_menus() {
        // Add top-level menu.
        add_menu_page(
            __( 'Energy Analytics', 'energy-analytics' ),
            __( 'Energy Analytics', 'energy-analytics' ),
            'view_energy_reports',
            'energy-analytics',
            array( $this, 'render_reports_page' ),
            'dashicons-chart-line',
            25
        );

        // Add sub-menus.
        add_submenu_page(
            'energy-analytics',
            __( 'Energy Reports', 'energy-analytics' ),
            __( 'Energy Reports', 'energy-analytics' ),
            'view_energy_reports',
            'energy-analytics',
            array( $this, 'render_reports_page' )
        );

        add_submenu_page(
            'energy-analytics',
            __( 'Analytics Dashboard', 'energy-analytics' ),
            __( 'Analytics Dashboard', 'energy-analytics' ),
            'view_energy_dashboard',
            'energy-analytics-dashboard',
            array( $this, 'render_dashboard_page' )
        );

        // Add Custom Scripts submenu.
        add_submenu_page(
            'energy-analytics',
            __( 'Custom Scripts', 'energy-analytics' ),
            __( 'Custom Scripts', 'energy-analytics' ),
            'manage_energy_scripts',
            'edit.php?post_type=ea_script',
            null
        );

        // Add Settings submenu.
        add_submenu_page(
            'energy-analytics',
            __( 'Settings', 'energy-analytics' ),
            __( 'Settings', 'energy-analytics' ),
            'manage_options',
            'energy-analytics-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render Reports page.
     */
    public function render_reports_page() {
        // Check user capabilities.
        if ( ! current_user_can( 'view_energy_reports' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'energy-analytics' ) );
        }

        // Create an instance of our list table.
        require_once EA_PLUGIN_DIR . 'src/Admin/EnergyReportsTable.php';
        $reports_table = new \EA\Admin\EnergyReportsTable();
        $reports_table->prepare_items();

        // Handle bulk export if requested.
        if ( isset( $_POST['action'] ) && 'export_csv' === $_POST['action'] ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'bulk-reports' ) ) {
                wp_die( 'Security check failed' );
            }
            $this->export_csv( isset( $_POST['report'] ) ? array_map( 'absint', $_POST['report'] ) : array() );
        }

        // Output the page content.
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Energy Reports', 'energy-analytics' ); ?></h1>
            
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=sync_acf_fields' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Sync ACF Fields', 'energy-analytics' ); ?>
            </a>
            <?php endif; ?>
            
            <?php if ( current_user_can( 'export_energy_pdf' ) ) : ?>
            <button id="ea-export-pdf" class="page-title-action">
                <?php esc_html_e( 'Export to PDF', 'energy-analytics' ); ?>
            </button>
            <?php endif; ?>
            
            <hr class="wp-header-end">
            
            <form id="energy-reports-form" method="post">
                <?php $reports_table->display(); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Handle PDF export
            $('#ea-export-pdf').on('click', function(e) {
                e.preventDefault();
                
                var selectedReports = $('input[name="report[]"]:checked').map(function() {
                    return $(this).val();
                }).get();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ea_export_pdf',
                        reports: selectedReports,
                        _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ea_export_pdf' ) ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Create a temporary link to download the PDF
                            var link = document.createElement('a');
                            link.href = response.data.file_url;
                            link.download = response.data.filename;
                            link.click();
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e( 'An error occurred during the export process', 'energy-analytics' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Dashboard page.
     */
    public function render_dashboard_page() {
        // Check user capabilities.
        if ( ! current_user_can( 'view_energy_dashboard' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'energy-analytics' ) );
        }

        // Generate a nonce for inline scripts.
        $nonce = wp_create_nonce( 'ea_chart_js' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Energy Analytics Dashboard', 'energy-analytics' ); ?></h1>
            
            <?php if ( current_user_can( 'export_energy_pdf' ) ) : ?>
            <button id="ea-dashboard-export-pdf" class="button button-primary">
                <?php esc_html_e( 'Export Dashboard to PDF', 'energy-analytics' ); ?>
            </button>
            <?php endif; ?>
            
            <div class="ea-dashboard-container">
                <div class="ea-chart-row">
                    <div class="ea-chart-col">
                        <div class="ea-chart-card">
                            <h2><?php esc_html_e( 'Energy Usage Over Time', 'energy-analytics' ); ?></h2>
                            <div class="ea-chart-container">
                                <canvas id="energyUsageChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="ea-chart-col">
                        <div class="ea-chart-card">
                            <h2><?php esc_html_e( 'Energy Distribution', 'energy-analytics' ); ?></h2>
                            <div class="ea-chart-container">
                                <canvas id="energyDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ea-chart-row">
                    <div class="ea-chart-col">
                        <div class="ea-chart-card">
                            <h2><?php esc_html_e( 'Consumption by Source', 'energy-analytics' ); ?></h2>
                            <div class="ea-chart-container">
                                <canvas id="energySourceChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="ea-chart-col">
                        <div class="ea-chart-card">
                            <h2><?php esc_html_e( 'Efficiency Metrics', 'energy-analytics' ); ?></h2>
                            <div class="ea-chart-container">
                                <canvas id="efficiencyMetricsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script nonce="<?php echo esc_attr( $nonce ); ?>">
        jQuery(document).ready(function($) {
            // Initialize charts once the page is ready
            eaChartsInit();
            
            // Handle PDF export for dashboard
            $('#ea-dashboard-export-pdf').on('click', function(e) {
                e.preventDefault();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ea_export_pdf',
                        export_type: 'dashboard',
                        _wpnonce: '<?php echo esc_js( wp_create_nonce( 'ea_export_pdf' ) ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Create a temporary link to download the PDF
                            var link = document.createElement('a');
                            link.href = response.data.file_url;
                            link.download = response.data.filename;
                            link.click();
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e( 'An error occurred during the export process', 'energy-analytics' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Settings page.
     */
    public function render_settings_page() {
        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'energy-analytics' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Energy Analytics Settings', 'energy-analytics' ); ?></h1>
            
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                settings_fields( 'energy_analytics_settings' );
                do_settings_sections( 'energy-analytics-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        // Register settings.
        register_setting(
            'energy_analytics_settings',
            'ea_chart_colors',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_chart_colors' ),
                'default'           => array(
                    'primary'    => '#0073aa',
                    'secondary'  => '#00a0d2',
                    'tertiary'   => '#72aee6',
                    'quaternary' => '#00ba88',
                ),
            )
        );

        register_setting(
            'energy_analytics_settings',
            'ea_pdf_logo',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            )
        );

        register_setting(
            'energy_analytics_settings',
            'ea_cache_lifetime',
            array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 300, // 5 minutes
            )
        );

        register_setting(
            'energy_analytics_settings',
            'ea_acf_json_path',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        // Add settings sections.
        add_settings_section(
            'ea_appearance_settings',
            __( 'Appearance Settings', 'energy-analytics' ),
            array( $this, 'render_appearance_section' ),
            'energy-analytics-settings'
        );

        add_settings_section(
            'ea_performance_settings',
            __( 'Performance Settings', 'energy-analytics' ),
            array( $this, 'render_performance_section' ),
            'energy-analytics-settings'
        );

        add_settings_section(
            'ea_acf_settings',
            __( 'ACF Settings', 'energy-analytics' ),
            array( $this, 'render_acf_section' ),
            'energy-analytics-settings'
        );

        // Add settings fields.
        add_settings_field(
            'ea_chart_primary_color',
            __( 'Primary Chart Color', 'energy-analytics' ),
            array( $this, 'render_color_field' ),
            'energy-analytics-settings',
            'ea_appearance_settings',
            array( 'label_for' => 'ea_chart_primary_color', 'color_key' => 'primary' )
        );

        add_settings_field(
            'ea_chart_secondary_color',
            __( 'Secondary Chart Color', 'energy-analytics' ),
            array( $this, 'render_color_field' ),
            'energy-analytics-settings',
            'ea_appearance_settings',
            array( 'label_for' => 'ea_chart_secondary_color', 'color_key' => 'secondary' )
        );

        add_settings_field(
            'ea_chart_tertiary_color',
            __( 'Tertiary Chart Color', 'energy-analytics' ),
            array( $this, 'render_color_field' ),
            'energy-analytics-settings',
            'ea_appearance_settings',
            array( 'label_for' => 'ea_chart_tertiary_color', 'color_key' => 'tertiary' )
        );

        add_settings_field(
            'ea_chart_quaternary_color',
            __( 'Quaternary Chart Color', 'energy-analytics' ),
            array( $this, 'render_color_field' ),
            'energy-analytics-settings',
            'ea_appearance_settings',
            array( 'label_for' => 'ea_chart_quaternary_color', 'color_key' => 'quaternary' )
        );

        add_settings_field(
            'ea_pdf_logo',
            __( 'PDF Logo URL', 'energy-analytics' ),
            array( $this, 'render_pdf_logo_field' ),
            'energy-analytics-settings',
            'ea_appearance_settings',
            array( 'label_for' => 'ea_pdf_logo' )
        );

        add_settings_field(
            'ea_cache_lifetime',
            __( 'Cache Lifetime (seconds)', 'energy-analytics' ),
            array( $this, 'render_cache_lifetime_field' ),
            'energy-analytics-settings',
            'ea_performance_settings',
            array( 'label_for' => 'ea_cache_lifetime' )
        );

        add_settings_field(
            'ea_acf_json_path',
            __( 'ACF JSON Path', 'energy-analytics' ),
            array( $this, 'render_acf_json_path_field' ),
            'energy-analytics-settings',
            'ea_acf_settings',
            array( 'label_for' => 'ea_acf_json_path' )
        );
    }

    /**
     * Render appearance settings section.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_appearance_section( $args ) {
        ?>
        <p><?php esc_html_e( 'Configure the appearance settings for charts and PDF exports.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render performance settings section.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_performance_section( $args ) {
        ?>
        <p><?php esc_html_e( 'Configure performance settings for caching and data processing.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render ACF settings section.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_acf_section( $args ) {
        ?>
        <p><?php esc_html_e( 'Configure Advanced Custom Fields integration settings.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render color field.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_color_field( $args ) {
        $colors = get_option( 'ea_chart_colors', array() );
        $color_key = $args['color_key'];
        $value = isset( $colors[ $color_key ] ) ? $colors[ $color_key ] : '';
        
        ?>
        <input type="color" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_chart_colors[<?php echo esc_attr( $color_key ); ?>]" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="ea-color-field">
        <?php
    }

    /**
     * Render PDF logo field.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_pdf_logo_field( $args ) {
        $value = get_option( 'ea_pdf_logo', '' );
        
        ?>
        <input type="url" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_pdf_logo" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text">
        <p class="description"><?php esc_html_e( 'Enter the URL for the logo to be displayed in PDF exports.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render cache lifetime field.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_cache_lifetime_field( $args ) {
        $value = get_option( 'ea_cache_lifetime', 300 );
        
        ?>
        <input type="number" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_cache_lifetime" 
               value="<?php echo esc_attr( $value ); ?>" 
               min="0" 
               step="1" 
               class="small-text">
        <p class="description"><?php esc_html_e( 'Time in seconds to cache statistical data. Set to 0 to disable caching.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render ACF JSON path field.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_acf_json_path_field( $args ) {
        $value = get_option( 'ea_acf_json_path', '' );
        
        ?>
        <input type="text" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_acf_json_path" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text">
        <p class="description"><?php esc_html_e( 'Path to ACF JSON file for synchronization. Can be absolute or relative to WordPress root.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Sanitize chart colors.
     *
     * @param array $input The value being sanitized.
     * @return array Sanitized value.
     */
    public function sanitize_chart_colors( $input ) {
        $sanitized_input = array();
        
        $default_colors = array(
            'primary'    => '#0073aa',
            'secondary'  => '#00a0d2',
            'tertiary'   => '#72aee6',
            'quaternary' => '#00ba88',
        );
        
        foreach ( $default_colors as $key => $default_color ) {
            if ( isset( $input[ $key ] ) && ! empty( $input[ $key ] ) ) {
                // Verify this is a valid hex color
                if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $input[ $key ] ) ) {
                    $sanitized_input[ $key ] = $input[ $key ];
                } else {
                    $sanitized_input[ $key ] = $default_color;
                    add_settings_error(
                        'ea_chart_colors',
                        'ea_chart_colors_error',
                        /* translators: %s: color field name */
                        sprintf( __( 'Invalid color format for %s. Using default.', 'energy-analytics' ), $key ),
                        'error'
                    );
                }
            } else {
                $sanitized_input[ $key ] = $default_color;
            }
        }
        
        return $sanitized_input;
    }

    /**
     * Export reports to CSV.
     *
     * @param array $report_ids Array of report IDs to export.
     */
    public function export_csv( $report_ids = array() ) {
        global $wpdb;
        
        // Check capabilities.
        if ( ! current_user_can( 'export_energy_pdf' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to export reports.', 'energy-analytics' ) );
        }
        
        $table_name = $wpdb->prefix . 'energy_logs';
        
        // Build query.
        $sql = "SELECT * FROM {$table_name}";
        if ( ! empty( $report_ids ) ) {
            $report_ids_str = implode( ',', array_map( 'absint', $report_ids ) );
            $sql .= " WHERE id IN ({$report_ids_str})";
        }
        $sql .= " ORDER BY created_at DESC";
        
        // Get data.
        $data = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        
        if ( empty( $data ) ) {
            wp_die( esc_html__( 'No data to export.', 'energy-analytics' ) );
        }
        
        // Set headers for CSV download.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=energy-reports-' . date( 'Y-m-d' ) . '.csv' );
        
        // Create a file pointer connected to the output stream.
        $output = fopen( 'php://output', 'w' );
        
        // Output the column headings.
        fputcsv( $output, array_keys( $data[0] ) );
        
        // Output the data rows.
        foreach ( $data as $row ) {
            // Decode JSON field data
            if ( isset( $row['field_data'] ) && ! empty( $row['field_data'] ) ) {
                $field_data = json_decode( $row['field_data'], true );
                if ( is_array( $field_data ) ) {
                    $row['field_data'] = wp_json_encode( $field_data );
                }
            }
            
            fputcsv( $output, $row );
        }
        
        // Close the file pointer and exit
        fclose( $output );
        exit;
    }

    /**
     * Handle PDF export via AJAX.
     */
    public function handle_pdf_export() {
        // Check nonce.
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'ea_export_pdf' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'energy-analytics' ) ) );
        }
        
        // Check capabilities.
        if ( ! current_user_can( 'export_energy_pdf' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to export PDF.', 'energy-analytics' ) ) );
        }
        
        // Determine export type.
        $export_type = isset( $_POST['export_type'] ) ? sanitize_text_field( wp_unslash( $_POST['export_type'] ) ) : 'reports';
        
        // Get report IDs if specified.
        $report_ids = array();
        if ( isset( $_POST['reports'] ) && is_array( $_POST['reports'] ) ) {
            $report_ids = array_map( 'absint', $_POST['reports'] );
        }
        
        // Generate PDF based on export type.
        if ( 'dashboard' === $export_type ) {
            $result = $this->generate_dashboard_pdf();
        } else {
            $result = $this->generate_reports_pdf( $report_ids );
        }
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        
        wp_send_json_success( array(
            'file_url'  => $result['url'],
            'filename'  => $result['filename'],
        ) );
    }

    /**
     * Generate reports PDF.
     *
     * @param array $report_ids Array of report IDs to include.
     * @return array|WP_Error PDF file info or error.
     */
    private function generate_reports_pdf( $report_ids = array() ) {
        // Check if Dompdf is available.
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            return new \WP_Error( 'dompdf_missing', __( 'PDF generation library is not available.', 'energy-analytics' ) );
        }
        
        // Get report data.
        global $wpdb;
        $table_name = $wpdb->prefix . 'energy_logs';
        
        // Build query.
        $sql = "SELECT * FROM {$table_name}";
        if ( ! empty( $report_ids ) ) {
            $report_ids_str = implode( ',', array_map( 'absint', $report_ids ) );
            $sql .= " WHERE id IN ({$report_ids_str})";
        }
        $sql .= " ORDER BY created_at DESC";
        
        // Get data.
        $reports = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        
        if ( empty( $reports ) ) {
            return new \WP_Error( 'no_data', __( 'No reports data to export.', 'energy-analytics' ) );
        }
        
        // Generate HTML content.
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <title><?php esc_html_e( 'Energy Reports', 'energy-analytics' ); ?></title>
            <style>
                body { font-family: sans-serif; color: #333; line-height: 1.5; }
                h1 { color: #0073aa; border-bottom: 1px solid #0073aa; padding-bottom: 5px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background-color: #f1f1f1; text-align: left; padding: 8px; border: 1px solid #ddd; }
                td { padding: 8px; border: 1px solid #ddd; }
                .logo { text-align: right; margin-bottom: 20px; }
                .logo img { max-width: 200px; }
                .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #666; }
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
            
            <h1><?php esc_html_e( 'Energy Reports', 'energy-analytics' ); ?></h1>
            
            <p>
                <?php
                /* translators: %s: Current date in Y-m-d format */
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
                    <?php foreach ( $reports as $report ) : ?>
                        <tr>
                            <td><?php echo esc_html( $report->id ); ?></td>
                            <td><?php echo esc_html( $report->form_id ); ?></td>
                            <td>
                                <?php
                                $user = get_user_by( 'id', $report->user_id );
                                echo esc_html( $user ? $user->display_name : __( 'Unknown', 'energy-analytics' ) );
                                ?>
                            </td>
                            <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $report->created_at ) ) ); ?></td>
                            <td>
                                <?php
                                $field_data = json_decode( $report->field_data, true );
                                if ( is_array( $field_data ) ) {
                                    foreach ( $field_data as $key => $value ) {
                                        if ( is_array( $value ) ) {
                                            $value = implode( ', ', $value );
                                        }
                                        echo esc_html( $key ) . ': ' . esc_html( $value ) . '<br>';
                                    }
                                } else {
                                    echo esc_html( $report->field_data );
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
        
        // Generate file name.
        $filename = 'energy-reports-' . date( 'Y-m-d' ) . '.pdf';
        
        // Save the PDF to a temporary file.
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        // Create directory if it doesn't exist.
        if ( ! file_exists( $pdf_dir ) ) {
            wp_mkdir_p( $pdf_dir );
            
            // Create an index.php file to prevent directory listing.
            file_put_contents( $pdf_dir . '/index.php', '<?php // Silence is golden.' );
        }
        
        $file_path = $pdf_dir . '/' . $filename;
        file_put_contents( $file_path, $dompdf->output() );
        
        // Return PDF info.
        return array(
            'path'     => $file_path,
            'url'      => $upload_dir['baseurl'] . '/energy-analytics/' . $filename,
            'filename' => $filename,
        );
    }

    /**
     * Generate dashboard PDF.
     *
     * @return array|WP_Error PDF file info or error.
     */
    private function generate_dashboard_pdf() {
        // Check if Dompdf is available.
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            return new \WP_Error( 'dompdf_missing', __( 'PDF generation library is not available.', 'energy-analytics' ) );
        }
        
        // Get dashboard data - use the REST endpoint to get the same data as the dashboard.
        $endpoints = new \EA\REST\Endpoints();
        $stats = $endpoints->get_energy_stats( array() );
        
        if ( is_wp_error( $stats ) ) {
            return $stats;
        }
        
        // Generate HTML content.
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <title><?php esc_html_e( 'Energy Analytics Dashboard', 'energy-analytics' ); ?></title>
            <style>
                body { font-family: sans-serif; color: #333; line-height: 1.5; }
                h1 { color: #0073aa; border-bottom: 1px solid #0073aa; padding-bottom: 5px; }
                h2 { color: #0073aa; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background-color: #f1f1f1; text-align: left; padding: 8px; border: 1px solid #ddd; }
                td { padding: 8px; border: 1px solid #ddd; }
                .logo { text-align: right; margin-bottom: 20px; }
                .logo img { max-width: 200px; }
                .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #666; }
                .chart-section { margin-bottom: 30px; }
                .data-table { margin-bottom: 30px; }
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
            
            <h1><?php esc_html_e( 'Energy Analytics Dashboard', 'energy-analytics' ); ?></h1>
            
            <p>
                <?php
                /* translators: %s: Current date in Y-m-d format */
                printf( esc_html__( 'Generated on: %s', 'energy-analytics' ), esc_html( date_i18n( get_option( 'date_format' ) ) ) );
                ?>
            </p>
            
            <div class="chart-section">
                <h2><?php esc_html_e( 'Energy Usage Summary', 'energy-analytics' ); ?></h2>
                
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Metric', 'energy-analytics' ); ?></th>
                                <th><?php esc_html_e( 'Value', 'energy-analytics' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( isset( $stats['summary'] ) ) : ?>
                                <?php foreach ( $stats['summary'] as $key => $value ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></td>
                                        <td><?php echo esc_html( $value ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="chart-section">
                <h2><?php esc_html_e( 'Energy Consumption by Source', 'energy-analytics' ); ?></h2>
                
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Source', 'energy-analytics' ); ?></th>
                                <th><?php esc_html_e( 'Consumption', 'energy-analytics' ); ?></th>
                                <th><?php esc_html_e( 'Percentage', 'energy-analytics' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( isset( $stats['by_source'] ) ) : ?>
                                <?php foreach ( $stats['by_source'] as $source ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $source['label'] ); ?></td>
                                        <td><?php echo esc_html( $source['value'] ); ?></td>
                                        <td><?php echo esc_html( $source['percentage'] ); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="chart-section">
                <h2><?php esc_html_e( 'Monthly Energy Trends', 'energy-analytics' ); ?></h2>
                
                <div class="data-table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Month', 'energy-analytics' ); ?></th>
                                <th><?php esc_html_e( 'Consumption', 'energy-analytics' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( isset( $stats['time_series'] ) ) : ?>
                                <?php foreach ( $stats['time_series'] as $point ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $point['label'] ); ?></td>
                                        <td><?php echo esc_html( $point['value'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="footer">
                <p><?php esc_html_e( 'Generated by Energy Analytics Plugin', 'energy-analytics' ); ?></p>
            </div>
        </body>
        </html>
        <?php
        $html = ob_get_clean();
        
        // Initialize Dompdf.
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->loadHtml( $html );
        $dompdf->render();
        
        // Generate file name.
        $filename = 'energy-dashboard-' . date( 'Y-m-d' ) . '.pdf';
        
        // Save the PDF to a temporary file.
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        // Create directory if it doesn't exist.
        if ( ! file_exists( $pdf_dir ) ) {
            wp_mkdir_p( $pdf_dir );
            
            // Create an index.php file to prevent directory listing.
            file_put_contents( $pdf_dir . '/index.php', '<?php // Silence is golden.' );
        }
        
        $file_path = $pdf_dir . '/' . $filename;
        file_put_contents( $file_path, $dompdf->output() );
        
        // Return PDF info.
        return array(
            'path'     => $file_path,
            'url'      => $upload_dir['baseurl'] . '/energy-analytics/' . $filename,
            'filename' => $filename,
        );
    }

    /**
     * Sync ACF fields from JSON file.
     */
    public function sync_acf_fields() {
        // Check capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to sync ACF fields.', 'energy-analytics' ) );
        }
        
        // Check if ACF is active.
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            wp_die(
                esc_html__( 'Advanced Custom Fields is not active. Please install and activate ACF to use this feature.', 'energy-analytics' ),
                esc_html__( 'ACF Not Found', 'energy-analytics' ),
                array( 'back_link' => true )
            );
        }
        
        // Get ACF JSON path from settings.
        $json_path = get_option( 'ea_acf_json_path', '' );
        
        if ( empty( $json_path ) ) {
            wp_die(
                esc_html__( 'ACF JSON path is not set. Please set it in the plugin settings.', 'energy-analytics' ),
                esc_html__( 'Configuration Error', 'energy-analytics' ),
                array( 'back_link' => true )
            );
        }
        
        // If path is relative, make it absolute.
        if ( strpos( $json_path, '/' ) !== 0 && strpos( $json_path, ':' ) !== 1 ) {
            $json_path = ABSPATH . $json_path;
        }
        
        // Check if file exists.
        if ( ! file_exists( $json_path ) ) {
            wp_die(
                esc_html__( 'ACF JSON file not found. Please check the path in settings.', 'energy-analytics' ),
                esc_html__( 'File Not Found', 'energy-analytics' ),
                array( 'back_link' => true )
            );
        }
        
        // Load JSON file.
        $json_content = file_get_contents( $json_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $field_groups = json_decode( $json_content, true );
        
        if ( ! is_array( $field_groups ) ) {
            wp_die(
                esc_html__( 'Invalid JSON format in ACF file.', 'energy-analytics' ),
                esc_html__( 'Invalid Format', 'energy-analytics' ),
                array( 'back_link' => true )
            );
        }
        
        // Register field groups.
        $imported = 0;
        
        foreach ( $field_groups as $field_group ) {
            acf_add_local_field_group( $field_group );
            $imported++;
        }
        
        // Redirect back with success message.
        wp_safe_redirect( add_query_arg(
            array(
                'page'    => 'energy-analytics-settings',
                'message' => 'acf_sync_success',
                'count'   => $imported,
            ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
