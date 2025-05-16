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
        
        // Handle ACF JSON file upload
        add_action( 'admin_init', array( $this, 'handle_acf_json_upload' ) );
    }
    
    /**
     * Handle ACF JSON file upload.
     */
    public function handle_acf_json_upload() {
        // Check if form was submitted
        if ( isset( $_POST['ea_upload_acf_file'] ) ) {
            // Verify nonce
            if ( ! isset( $_POST['ea_upload_acf_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ea_upload_acf_nonce'] ), 'ea_upload_acf_json' ) ) {
                add_settings_error(
                    'ea_acf_json_path',
                    'ea_nonce_error',
                    __( 'Security check failed.', 'energy-analytics' ),
                    'error'
                );
                return;
            }
            
            // Check if file was uploaded
            if ( ! isset( $_FILES['ea_acf_json_file'] ) || $_FILES['ea_acf_json_file']['error'] !== UPLOAD_ERR_OK ) {
                add_settings_error(
                    'ea_acf_json_path',
                    'ea_upload_error',
                    __( 'No file was uploaded or there was an error with the upload.', 'energy-analytics' ),
                    'error'
                );
                return;
            }
            
            // Check file type
            $file_type = wp_check_filetype( basename( $_FILES['ea_acf_json_file']['name'] ), array( 'json' => 'application/json' ) );
            if ( empty( $file_type['ext'] ) || $file_type['ext'] !== 'json' ) {
                add_settings_error(
                    'ea_acf_json_path',
                    'ea_file_type_error',
                    __( 'The uploaded file must be a JSON file.', 'energy-analytics' ),
                    'error'
                );
                return;
            }
            
            // Create upload directory if it doesn't exist
            $upload_dir = wp_upload_dir();
            $acf_dir = $upload_dir['basedir'] . '/energy-analytics/acf-json';
            
            if ( ! file_exists( $acf_dir ) ) {
                wp_mkdir_p( $acf_dir );
                
                // Add index.php to prevent directory listing
                file_put_contents( $acf_dir . '/index.php', '<?php // Silence is golden' );
            }
            
            // Generate filename based on date
            $filename = 'acf-fields-' . date( 'Y-m-d-H-i-s' ) . '.json';
            $file_path = $acf_dir . '/' . $filename;
            
            // Move uploaded file
            if ( move_uploaded_file( $_FILES['ea_acf_json_file']['tmp_name'], $file_path ) ) {
                // Update option with new file path
                update_option( 'ea_acf_json_path', $file_path );
                
                add_settings_error(
                    'ea_acf_json_path',
                    'ea_upload_success',
                    __( 'ACF JSON file uploaded successfully. Path has been updated.', 'energy-analytics' ),
                    'success'
                );
                
                // Validate JSON file
                $json_content = file_get_contents($file_path);
                $acf_data = json_decode($json_content, true);
                
                if (!$acf_data || !is_array($acf_data)) {
                    add_settings_error(
                        'ea_acf_json_path',
                        'ea_json_error',
                        __( 'Warning: The uploaded file does not contain valid ACF field data.', 'energy-analytics' ),
                        'warning'
                    );
                }
                
                // Redirect to prevent form resubmission
                wp_safe_redirect( add_query_arg( 'settings-updated', 'true', wp_get_referer() ) );
                exit;
            } else {
                add_settings_error(
                    'ea_acf_json_path',
                    'ea_save_error',
                    __( 'There was an error saving the uploaded file.', 'energy-analytics' ),
                    'error'
                );
            }
        }
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
        <p><?php esc_html_e( 'Customize the appearance of charts and PDF exports.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render performance settings section.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_performance_section( $args ) {
        ?>
        <p><?php esc_html_e( 'Configure caching and performance settings.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render ACF settings section.
     *
     * @param array $args Arguments passed to the callback.
     */
    public function render_acf_section( $args ) {
        ?>
        <p><?php esc_html_e( 'Configure Advanced Custom Fields integration.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render color field.
     *
     * @param array $args Field arguments.
     */
    public function render_color_field( $args ) {
        $colors = get_option( 'ea_chart_colors', array() );
        $value = isset( $colors[ $args['color_key'] ] ) ? $colors[ $args['color_key'] ] : '';
        
        ?>
        <input type="color" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_chart_colors[<?php echo esc_attr( $args['color_key'] ); ?>]" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="ea-color-picker">
        <?php
    }

    /**
     * Render PDF logo field.
     *
     * @param array $args Field arguments.
     */
    public function render_pdf_logo_field( $args ) {
        $value = get_option( 'ea_pdf_logo', '' );
        
        ?>
        <input type="url" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_pdf_logo" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="regular-text">
        <p class="description"><?php esc_html_e( 'URL to the logo image to use in PDF exports.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render cache lifetime field.
     *
     * @param array $args Field arguments.
     */
    public function render_cache_lifetime_field( $args ) {
        $value = get_option( 'ea_cache_lifetime', 300 );
        
        ?>
        <input type="number" 
               id="<?php echo esc_attr( $args['label_for'] ); ?>" 
               name="ea_cache_lifetime" 
               value="<?php echo esc_attr( $value ); ?>" 
               class="small-text"
               min="0"
               step="1">
        <p class="description"><?php esc_html_e( 'The time in seconds that API responses should be cached. Set to 0 to disable caching.', 'energy-analytics' ); ?></p>
        <?php
    }

    /**
     * Render ACF JSON upload field.
     *
     * @param array $args Field arguments.
     */
    public function render_acf_json_path_field( $args ) {
        $option_name = 'ea_acf_json_path';
        $value = get_option( $option_name, '' );
        
        // Add nonce for file upload
        wp_nonce_field( 'ea_upload_acf_json', 'ea_upload_acf_nonce' );
        
        ?>
        <div class="ea-acf-upload-container">
            <p>
                <input type="text" 
                       id="<?php echo esc_attr( $args['label_for'] ); ?>" 
                       name="<?php echo esc_attr( $option_name ); ?>" 
                       value="<?php echo esc_attr( $value ); ?>" 
                       class="regular-text" 
                       placeholder="<?php esc_attr_e( 'Path to ACF JSON file', 'energy-analytics' ); ?>">
            </p>
            
            <p class="description">
                <?php esc_html_e( 'Enter the path to your ACF JSON file, or upload a new file below.', 'energy-analytics' ); ?>
            </p>
            
            <div class="ea-upload-form">
                <label for="ea_acf_json_file" class="ea-upload-label">
                    <?php esc_html_e( 'Upload ACF JSON File:', 'energy-analytics' ); ?>
                </label>
                <input type="file" name="ea_acf_json_file" id="ea_acf_json_file" accept=".json">
                <input type="submit" name="ea_upload_acf_file" class="button button-secondary" 
                       value="<?php esc_attr_e( 'Upload and Save', 'energy-analytics' ); ?>">
            </div>
        </div>
        <?php
    }

    /**
     * Sanitize chart colors.
     *
     * @param array $colors Array of color values.
     * @return array Sanitized array of color values.
     */
    public function sanitize_chart_colors( $colors ) {
        if ( ! is_array( $colors ) ) {
            return array();
        }
        
        $sanitized = array();
        
        foreach ( $colors as $key => $color ) {
            if ( preg_match( '/^#([a-f0-9]{6}|[a-f0-9]{3})$/i', $color ) ) {
                $sanitized[ sanitize_key( $key ) ] = $color;
            }
        }
        
        return $sanitized;
    }

    /**
     * Export reports to CSV.
     *
     * @param array $report_ids Array of report IDs to export.
     */
    public function export_csv( $report_ids = array() ) {
        global $wpdb;
        
        // Set up the filename.
        $filename = 'energy-reports-' . date( 'Y-m-d' ) . '.csv';
        
        // Set the headers to force a download.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        
        // Create a file pointer.
        $output = fopen( 'php://output', 'w' );
        
        // Add BOM for UTF-8 CSV.
        fputs( $output, "\xEF\xBB\xBF" );
        
        // Get the reports from the database.
        $table_name = $wpdb->prefix . 'energy_logs';
        
        if ( ! empty( $report_ids ) ) {
            $report_ids_string = implode( ',', array_map( 'intval', $report_ids ) );
            $query = "SELECT * FROM $table_name WHERE id IN ($report_ids_string) ORDER BY created_at DESC";
        } else {
            $query = "SELECT * FROM $table_name ORDER BY created_at DESC";
        }
        
        $reports = $wpdb->get_results( $query, ARRAY_A );
        
        if ( empty( $reports ) ) {
            // Output a message if no reports are found.
            fputcsv( $output, array( __( 'No reports found.', 'energy-analytics' ) ) );
            fclose( $output );
            exit;
        }
        
        // Set up the CSV header row.
        $header = array( 'ID', 'Form ID', 'User ID', 'Created At' );
        
        // Add field data headers from the first report.
        $first_report = $reports[0];
        $field_data = json_decode( $first_report['field_data'], true );
        
        if ( is_array( $field_data ) ) {
            foreach ( array_keys( $field_data ) as $field_key ) {
                $header[] = $field_key;
            }
        }
        
        // Write the header row.
        fputcsv( $output, $header );
        
        // Write the data rows.
        foreach ( $reports as $report ) {
            $row = array(
                $report['id'],
                $report['form_id'],
                $report['user_id'],
                $report['created_at'],
            );
            
            // Add field data values.
            $field_data = json_decode( $report['field_data'], true );
            
            if ( is_array( $field_data ) ) {
                foreach ( array_keys( $field_data ) as $field_key ) {
                    $row[] = isset( $field_data[ $field_key ] ) ? $field_data[ $field_key ] : '';
                }
            }
            
            fputcsv( $output, $row );
        }
        
        fclose( $output );
        exit;
    }

    /**
     * Handle PDF export via AJAX.
     */
    public function handle_pdf_export() {
        // Check nonce.
        if ( ! check_ajax_referer( 'ea_export_pdf', false, false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'energy-analytics' ) ) );
        }
        
        // Check user capabilities.
        if ( ! current_user_can( 'export_energy_pdf' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to export PDFs.', 'energy-analytics' ) ) );
        }
        
        // Get export type.
        $export_type = isset( $_POST['export_type'] ) ? sanitize_key( $_POST['export_type'] ) : 'reports';
        
        // Handle different export types.
        if ( 'dashboard' === $export_type ) {
            $filename = 'energy-dashboard-' . date( 'Y-m-d' ) . '.pdf';
            $html = $this->generate_dashboard_pdf_html();
        } else {
            // Default to reports export.
            $report_ids = isset( $_POST['reports'] ) ? array_map( 'absint', $_POST['reports'] ) : array();
            $filename = 'energy-reports-' . date( 'Y-m-d' ) . '.pdf';
            $html = $this->generate_reports_pdf_html( $report_ids );
        }
        
        // Generate the PDF.
        $pdf_file = $this->generate_pdf( $html, $filename );
        
        // Check if PDF generation was successful.
        if ( is_wp_error( $pdf_file ) ) {
            wp_send_json_error( array( 'message' => $pdf_file->get_error_message() ) );
        }
        
        // Return the PDF URL.
        wp_send_json_success( array(
            'file_url' => $pdf_file['url'],
            'filename' => $filename,
        ) );
    }

    /**
     * Generate HTML for reports PDF.
     *
     * @param array $report_ids Array of report IDs to include in the PDF.
     * @return string HTML content for the PDF.
     */
    private function generate_reports_pdf_html( $report_ids = array() ) {
        global $wpdb;
        
        // Get the reports from the database.
        $table_name = $wpdb->prefix . 'energy_logs';
        
        if ( ! empty( $report_ids ) ) {
            $report_ids_string = implode( ',', array_map( 'intval', $report_ids ) );
            $query = "SELECT * FROM $table_name WHERE id IN ($report_ids_string) ORDER BY created_at DESC";
        } else {
            $query = "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 100";
        }
        
        $reports = $wpdb->get_results( $query, ARRAY_A );
        
        // Start building the HTML.
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>' . esc_html__( 'Energy Reports', 'energy-analytics' ) . '</title>
            <style>
                body {
                    font-family: sans-serif;
                    color: #333;
                    line-height: 1.5;
                }
                h1 {
                    color: #0073aa;
                    text-align: center;
                    margin-bottom: 30px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 30px;
                }
                th {
                    background-color: #0073aa;
                    color: #fff;
                    font-weight: bold;
                    text-align: left;
                    padding: 8px;
                }
                td {
                    border-bottom: 1px solid #ddd;
                    padding: 8px;
                }
                tr:nth-child(even) {
                    background-color: #f2f2f2;
                }
            </style>
        </head>
        <body>
            <h1>' . esc_html__( 'Energy Reports', 'energy-analytics' ) . '</h1>
        ';
        
        // Add the logo if it exists.
        $logo_url = get_option( 'ea_pdf_logo', '' );
        if ( ! empty( $logo_url ) ) {
            $html .= '<div style="text-align: center; margin-bottom: 20px;"><img src="' . esc_url( $logo_url ) . '" style="max-width: 200px; max-height: 100px;"></div>';
        }
        
        // Add the date and company name.
        $html .= '<p><strong>' . esc_html__( 'Date:', 'energy-analytics' ) . '</strong> ' . date_i18n( get_option( 'date_format' ) ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Company:', 'energy-analytics' ) . '</strong> ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        
        if ( empty( $reports ) ) {
            $html .= '<p>' . esc_html__( 'No reports found.', 'energy-analytics' ) . '</p>';
        } else {
            // Build the table.
            $html .= '<table>
                <thead>
                    <tr>
                        <th>' . esc_html__( 'ID', 'energy-analytics' ) . '</th>
                        <th>' . esc_html__( 'Form', 'energy-analytics' ) . '</th>
                        <th>' . esc_html__( 'User', 'energy-analytics' ) . '</th>
                        <th>' . esc_html__( 'Date', 'energy-analytics' ) . '</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ( $reports as $report ) {
                $user_info = get_userdata( $report['user_id'] );
                $username = $user_info ? $user_info->display_name : __( 'Unknown User', 'energy-analytics' );
                
                $html .= '<tr>
                    <td>' . esc_html( $report['id'] ) . '</td>
                    <td>' . esc_html( $report['form_id'] ) . '</td>
                    <td>' . esc_html( $username ) . '</td>
                    <td>' . esc_html( $report['created_at'] ) . '</td>
                </tr>';
            }
            
            $html .= '</tbody></table>';
            
            // Add detailed report information.
            $html .= '<h2>' . esc_html__( 'Detailed Reports', 'energy-analytics' ) . '</h2>';
            
            foreach ( $reports as $report ) {
                $user_info = get_userdata( $report['user_id'] );
                $username = $user_info ? $user_info->display_name : __( 'Unknown User', 'energy-analytics' );
                
                $html .= '<div style="border: 1px solid #ddd; padding: 15px; margin-bottom: 20px;">
                    <h3>' . sprintf( esc_html__( 'Report #%d', 'energy-analytics' ), $report['id'] ) . '</h3>
                    <p><strong>' . esc_html__( 'Form:', 'energy-analytics' ) . '</strong> ' . esc_html( $report['form_id'] ) . '</p>
                    <p><strong>' . esc_html__( 'User:', 'energy-analytics' ) . '</strong> ' . esc_html( $username ) . '</p>
                    <p><strong>' . esc_html__( 'Date:', 'energy-analytics' ) . '</strong> ' . esc_html( $report['created_at'] ) . '</p>
                    <h4>' . esc_html__( 'Field Data:', 'energy-analytics' ) . '</h4>';
                
                $field_data = json_decode( $report['field_data'], true );
                
                if ( is_array( $field_data ) && ! empty( $field_data ) ) {
                    $html .= '<table style="width: 100%; margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th>' . esc_html__( 'Field', 'energy-analytics' ) . '</th>
                                <th>' . esc_html__( 'Value', 'energy-analytics' ) . '</th>
                            </tr>
                        </thead>
                        <tbody>';
                    
                    foreach ( $field_data as $key => $value ) {
                        $html .= '<tr>
                            <td>' . esc_html( $key ) . '</td>
                            <td>' . esc_html( $value ) . '</td>
                        </tr>';
                    }
                    
                    $html .= '</tbody></table>';
                } else {
                    $html .= '<p>' . esc_html__( 'No field data available.', 'energy-analytics' ) . '</p>';
                }
                
                $html .= '</div>';
            }
        }
        
        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Generate HTML for dashboard PDF.
     *
     * @return string HTML content for the PDF.
     */
    private function generate_dashboard_pdf_html() {
        // Get chart colors.
        $colors = get_option( 'ea_chart_colors', array(
            'primary'    => '#0073aa',
            'secondary'  => '#00a0d2',
            'tertiary'   => '#72aee6',
            'quaternary' => '#00ba88',
        ) );
        
        // Start building the HTML.
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>' . esc_html__( 'Energy Dashboard', 'energy-analytics' ) . '</title>
            <style>
                body {
                    font-family: sans-serif;
                    color: #333;
                    line-height: 1.5;
                }
                h1 {
                    color: #0073aa;
                    text-align: center;
                    margin-bottom: 30px;
                }
                .chart-container {
                    margin-bottom: 40px;
                }
                .chart-title {
                    color: #0073aa;
                    margin-top: 0;
                    margin-bottom: 15px;
                    text-align: center;
                }
                .chart-placeholder {
                    width: 100%;
                    height: 300px;
                    background-color: #f5f5f5;
                    border: 1px solid #ddd;
                    text-align: center;
                    padding-top: 120px;
                    color: #666;
                }
                .summary-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 30px;
                }
                .summary-table th {
                    background-color: #0073aa;
                    color: #fff;
                    font-weight: bold;
                    text-align: left;
                    padding: 8px;
                }
                .summary-table td {
                    border-bottom: 1px solid #ddd;
                    padding: 8px;
                }
                .summary-table tr:nth-child(even) {
                    background-color: #f2f2f2;
                }
            </style>
        </head>
        <body>
            <h1>' . esc_html__( 'Energy Analytics Dashboard', 'energy-analytics' ) . '</h1>
        ';
        
        // Add the logo if it exists.
        $logo_url = get_option( 'ea_pdf_logo', '' );
        if ( ! empty( $logo_url ) ) {
            $html .= '<div style="text-align: center; margin-bottom: 20px;"><img src="' . esc_url( $logo_url ) . '" style="max-width: 200px; max-height: 100px;"></div>';
        }
        
        // Add the date and company name.
        $html .= '<p><strong>' . esc_html__( 'Date:', 'energy-analytics' ) . '</strong> ' . date_i18n( get_option( 'date_format' ) ) . '</p>';
        $html .= '<p><strong>' . esc_html__( 'Company:', 'energy-analytics' ) . '</strong> ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        
        // Add a summary table.
        $html .= '<h2>' . esc_html__( 'Energy Consumption Summary', 'energy-analytics' ) . '</h2>';
        
        // In a real implementation, this would fetch data from the database.
        // For now, we'll use placeholder data.
        $html .= '
        <table class="summary-table">
            <thead>
                <tr>
                    <th>' . esc_html__( 'Energy Source', 'energy-analytics' ) . '</th>
                    <th>' . esc_html__( 'Consumption (kWh)', 'energy-analytics' ) . '</th>
                    <th>' . esc_html__( 'Percentage', 'energy-analytics' ) . '</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>' . esc_html__( 'Electricity', 'energy-analytics' ) . '</td>
                    <td>1,250.5</td>
                    <td>62.5%</td>
                </tr>
                <tr>
                    <td>' . esc_html__( 'Gas', 'energy-analytics' ) . '</td>
                    <td>550.3</td>
                    <td>27.5%</td>
                </tr>
                <tr>
                    <td>' . esc_html__( 'Renewable', 'energy-analytics' ) . '</td>
                    <td>200.0</td>
                    <td>10.0%</td>
                </tr>
                <tr>
                    <td><strong>' . esc_html__( 'Total', 'energy-analytics' ) . '</strong></td>
                    <td><strong>2,000.8</strong></td>
                    <td><strong>100.0%</strong></td>
                </tr>
            </tbody>
        </table>
        ';
        
        // Add chart placeholders.
        $html .= '
        <div class="chart-container">
            <h2 class="chart-title">' . esc_html__( 'Energy Usage Over Time', 'energy-analytics' ) . '</h2>
            <div class="chart-placeholder">' . esc_html__( 'Time Series Chart', 'energy-analytics' ) . '</div>
        </div>
        
        <div class="chart-container">
            <h2 class="chart-title">' . esc_html__( 'Energy Distribution', 'energy-analytics' ) . '</h2>
            <div class="chart-placeholder">' . esc_html__( 'Pie Chart', 'energy-analytics' ) . '</div>
        </div>
        
        <div class="chart-container">
            <h2 class="chart-title">' . esc_html__( 'Consumption by Source', 'energy-analytics' ) . '</h2>
            <div class="chart-placeholder">' . esc_html__( 'Bar Chart', 'energy-analytics' ) . '</div>
        </div>
        
        <div class="chart-container">
            <h2 class="chart-title">' . esc_html__( 'Efficiency Metrics', 'energy-analytics' ) . '</h2>
            <div class="chart-placeholder">' . esc_html__( 'Radar Chart', 'energy-analytics' ) . '</div>
        </div>
        ';
        
        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Generate PDF from HTML.
     *
     * @param string $html    HTML content for the PDF.
     * @param string $filename Filename for the PDF.
     * @return array|WP_Error Array with file URL and path on success, WP_Error on failure.
     */
    private function generate_pdf( $html, $filename ) {
        // Create upload directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/energy-analytics';
        
        if ( ! file_exists( $pdf_dir ) ) {
            wp_mkdir_p( $pdf_dir );
            
            // Add index.php to prevent directory listing
            file_put_contents( $pdf_dir . '/index.php', '<?php // Silence is golden' );
        }
        
        // Define the file path
        $filepath = $pdf_dir . '/' . $filename;
        
        // In a real implementation, we would use a PDF library like mPDF or DOMPDF
        // For this demo, let's just save the HTML as a file
        file_put_contents( $filepath, $html );
        
        // Return the URL and path
        return array(
            'path' => $filepath,
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
        
        // Get JSON path from settings.
        $json_path = get_option( 'ea_acf_json_path', '' );
        
        if ( empty( $json_path ) ) {
            // Redirect back with an error.
            wp_safe_redirect( add_query_arg( 
                array( 'page' => 'energy-analytics-settings', 'error' => 'no_json_path' ), 
                admin_url( 'admin.php' ) 
            ) );
            exit;
        }
        
        if ( ! file_exists( $json_path ) ) {
            // Redirect back with an error.
            wp_safe_redirect( add_query_arg( 
                array( 'page' => 'energy-analytics-settings', 'error' => 'file_not_found' ), 
                admin_url( 'admin.php' ) 
            ) );
            exit;
        }
        
        // Read the JSON file.
        $json_data = file_get_contents( $json_path );
        $acf_data = json_decode( $json_data, true );
        
        if ( ! $acf_data || ! is_array( $acf_data ) ) {
            // Redirect back with an error.
            wp_safe_redirect( add_query_arg( 
                array( 'page' => 'energy-analytics-settings', 'error' => 'invalid_json' ), 
                admin_url( 'admin.php' ) 
            ) );
            exit;
        }
        
        // Import field groups.
        $success = 0;
        $errors = 0;
        
        foreach ( $acf_data as $field_group ) {
            // Check if required keys exist
            if ( ! isset( $field_group['key'] ) || ! isset( $field_group['title'] ) || ! isset( $field_group['fields'] ) ) {
                $errors++;
                continue;
            }
            
            try {
                // Register the field group
                acf_add_local_field_group( $field_group );
                $success++;
            } catch ( Exception $e ) {
                $errors++;
                error_log( 'Error importing ACF field group: ' . $e->getMessage() );
            }
        }
        
        // Redirect back with results.
        wp_safe_redirect( add_query_arg( 
            array( 
                'page' => 'energy-analytics', 
                'sync_success' => $success,
                'sync_errors' => $errors,
            ), 
            admin_url( 'admin.php' ) 
        ) );
        exit;
    }
}