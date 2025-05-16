<?php
/**
 * Scripts class for Energy Analytics
 *
 * @package Energy_Analytics
 */

namespace EA\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Scripts
 * 
 * Handles custom JavaScript scripts for energy calculations
 *
 * @package EA\Admin
 */
class Scripts {

    /**
     * Init hook.
     */
    public function init() {
        add_action( 'add_meta_boxes', array( $this, 'add_script_meta_boxes' ) );
        add_action( 'save_post_ea_script', array( $this, 'save_script_meta' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_script_assets' ) );
    }

    /**
     * Add meta boxes for the script post type.
     */
    public function add_script_meta_boxes() {
        add_meta_box(
            'ea_script_info',
            __( 'Script Information', 'energy-analytics' ),
            array( $this, 'render_script_info_meta_box' ),
            'ea_script',
            'side',
            'high'
        );

        add_meta_box(
            'ea_script_test',
            __( 'Test Script', 'energy-analytics' ),
            array( $this, 'render_script_test_meta_box' ),
            'ea_script',
            'normal',
            'high'
        );
    }

    /**
     * Render script info meta box.
     *
     * @param \WP_Post $post The post object.
     */
    public function render_script_info_meta_box( $post ) {
        // Add nonce for security.
        wp_nonce_field( 'ea_save_script_meta', 'ea_script_meta_nonce' );

        // Get current values.
        $version = get_post_meta( $post->ID, '_ea_script_version', true );
        $author = get_post_meta( $post->ID, '_ea_script_author', true );
        $category = get_post_meta( $post->ID, '_ea_script_category', true );

        // Default values.
        if ( empty( $version ) ) {
            $version = '1.0.0';
        }

        if ( empty( $author ) ) {
            $author = wp_get_current_user()->display_name;
        }

        $categories = array(
            'utility' => __( 'Utility Functions', 'energy-analytics' ),
            'calculation' => __( 'Energy Calculations', 'energy-analytics' ),
            'conversion' => __( 'Unit Conversion', 'energy-analytics' ),
            'report' => __( 'Reporting', 'energy-analytics' ),
            'other' => __( 'Other', 'energy-analytics' ),
        );

        ?>
        <p>
            <label for="ea_script_version"><?php esc_html_e( 'Version:', 'energy-analytics' ); ?></label>
            <input type="text" id="ea_script_version" name="ea_script_version" value="<?php echo esc_attr( $version ); ?>" class="widefat">
        </p>

        <p>
            <label for="ea_script_author"><?php esc_html_e( 'Author:', 'energy-analytics' ); ?></label>
            <input type="text" id="ea_script_author" name="ea_script_author" value="<?php echo esc_attr( $author ); ?>" class="widefat">
        </p>

        <p>
            <label for="ea_script_category"><?php esc_html_e( 'Category:', 'energy-analytics' ); ?></label>
            <select id="ea_script_category" name="ea_script_category" class="widefat">
                <?php foreach ( $categories as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $category, $value ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    /**
     * Render script test meta box.
     *
     * @param \WP_Post $post The post object.
     */
    public function render_script_test_meta_box( $post ) {
        ?>
        <div class="ea-script-test-container">
            <p><?php esc_html_e( 'Here you can test your script by providing sample input data and checking the results.', 'energy-analytics' ); ?></p>
            
            <div class="ea-script-test-input">
                <h4><?php esc_html_e( 'Test Input (JSON):', 'energy-analytics' ); ?></h4>
                <textarea id="ea-script-test-input" class="widefat" rows="5">{"energy_consumption": 150, "source_electricity": 100, "source_gas": 30, "source_renewable": 20}</textarea>
            </div>
            
            <div class="ea-script-test-buttons">
                <button type="button" id="ea-run-script" class="button button-primary"><?php esc_html_e( 'Run Script', 'energy-analytics' ); ?></button>
                <button type="button" id="ea-clear-output" class="button"><?php esc_html_e( 'Clear Output', 'energy-analytics' ); ?></button>
            </div>
            
            <div class="ea-script-test-output">
                <h4><?php esc_html_e( 'Output:', 'energy-analytics' ); ?></h4>
                <pre id="ea-script-test-output" class="ea-script-output"></pre>
            </div>

            <div class="ea-script-test-console">
                <h4><?php esc_html_e( 'Console:', 'energy-analytics' ); ?></h4>
                <pre id="ea-script-test-console" class="ea-script-console"></pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Run script button.
            $('#ea-run-script').on('click', function() {
                var scriptContent = wp.codeEditor.codemirror.instances[$('#content').attr('id')].getValue();
                var inputData = $('#ea-script-test-input').val();
                
                // Clear previous output.
                $('#ea-script-test-output').empty();
                $('#ea-script-test-console').empty();
                
                try {
                    // Parse input data.
                    var data = JSON.parse(inputData);
                    
                    // Create a console log capture.
                    var originalConsoleLog = console.log;
                    var consoleOutput = [];
                    
                    console.log = function() {
                        consoleOutput.push(Array.from(arguments).join(' '));
                        originalConsoleLog.apply(console, arguments);
                    };
                    
                    // Create the function from the script content.
                    var scriptFunction = new Function('data', scriptContent);
                    
                    // Run the script.
                    var result = scriptFunction(data);
                    
                    // Display the result.
                    $('#ea-script-test-output').text(JSON.stringify(result, null, 2));
                    
                    // Display console output.
                    $('#ea-script-test-console').text(consoleOutput.join('\n'));
                    
                    // Restore original console log.
                    console.log = originalConsoleLog;
                } catch (error) {
                    $('#ea-script-test-output').text('Error: ' + error.message);
                }
            });
            
            // Clear output button.
            $('#ea-clear-output').on('click', function() {
                $('#ea-script-test-output').empty();
                $('#ea-script-test-console').empty();
            });
        });
        </script>
        
        <style>
        .ea-script-test-container {
            margin-top: 15px;
        }
        .ea-script-test-input,
        .ea-script-test-output,
        .ea-script-test-console {
            margin-bottom: 20px;
        }
        .ea-script-test-buttons {
            margin: 15px 0;
        }
        .ea-script-output,
        .ea-script-console {
            background: #f1f1f1;
            padding: 10px;
            border: 1px solid #ddd;
            max-height: 200px;
            overflow: auto;
        }
        </style>
        <?php
    }

    /**
     * Save script meta data.
     *
     * @param int $post_id The post ID.
     */
    public function save_script_meta( $post_id ) {
        // Check if our nonce is set.
        if ( ! isset( $_POST['ea_script_meta_nonce'] ) ) {
            return;
        }

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $_POST['ea_script_meta_nonce'], 'ea_save_script_meta' ) ) {
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check the user's permissions.
        if ( ! current_user_can( 'manage_energy_scripts', $post_id ) ) {
            return;
        }

        // Save the version.
        if ( isset( $_POST['ea_script_version'] ) ) {
            update_post_meta( $post_id, '_ea_script_version', sanitize_text_field( $_POST['ea_script_version'] ) );
        }

        // Save the author.
        if ( isset( $_POST['ea_script_author'] ) ) {
            update_post_meta( $post_id, '_ea_script_author', sanitize_text_field( $_POST['ea_script_author'] ) );
        }

        // Save the category.
        if ( isset( $_POST['ea_script_category'] ) ) {
            update_post_meta( $post_id, '_ea_script_category', sanitize_key( $_POST['ea_script_category'] ) );
        }
    }

    /**
     * Enqueue script assets.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_script_assets( $hook ) {
        global $post_type;

        // Only load on the script post type.
        if ( 'ea_script' !== $post_type ) {
            return;
        }

        // Enqueue the script editor assets.
        wp_enqueue_style( 'ea-script-editor-styles', EA_PLUGIN_URL . 'assets/css/script-editor.css', array(), EA_VERSION );
    }
}