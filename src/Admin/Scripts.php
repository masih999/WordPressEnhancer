<?php
/**
 * Scripts management for Energy Analytics
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
 * Manages custom JavaScript scripts for energy calculations and ACF forms
 *
 * @package EA\Admin
 */
class Scripts {

    /**
     * Init hook.
     */
    public function init() {
        // Register meta boxes for the script post type.
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
        
        // Save meta box data.
        add_action( 'save_post_ea_script', array( $this, 'save_meta_box_data' ) );
        
        // Hook into ACF form data.
        add_filter( 'acf/input/form_data', array( $this, 'inject_custom_scripts' ), 10, 1 );
        
        // Enqueue calculation script for ACF forms.
        add_action( 'acf/input/admin_enqueue_scripts', array( $this, 'enqueue_calculation_script' ) );
        
        // Handle form submission data.
        add_action( 'acf/save_post', array( $this, 'save_energy_data' ), 20 );
    }

    /**
     * Register meta boxes for the script post type.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'ea_script_options',
            __( 'Script Options', 'energy-analytics' ),
            array( $this, 'render_script_options_meta_box' ),
            'ea_script',
            'normal',
            'high'
        );
        
        add_meta_box(
            'ea_script_code',
            __( 'JavaScript Code', 'energy-analytics' ),
            array( $this, 'render_script_code_meta_box' ),
            'ea_script',
            'normal',
            'high'
        );
    }

    /**
     * Render script options meta box.
     *
     * @param WP_Post $post The post object.
     */
    public function render_script_options_meta_box( $post ) {
        // Add a nonce field for security.
        wp_nonce_field( 'ea_script_meta_box', 'ea_script_meta_box_nonce' );
        
        // Get the current values if they exist.
        $target_form = get_post_meta( $post->ID, '_ea_target_form', true );
        $is_active = get_post_meta( $post->ID, '_ea_script_active', true );
        
        ?>
        <p>
            <label for="ea_target_form">
                <?php esc_html_e( 'Target Form ID', 'energy-analytics' ); ?>:
            </label>
            <input type="text" id="ea_target_form" name="ea_target_form" value="<?php echo esc_attr( $target_form ); ?>" class="widefat">
            <span class="description">
                <?php esc_html_e( 'Enter the ACF form ID to target, or leave empty to apply to all forms.', 'energy-analytics' ); ?>
            </span>
        </p>
        
        <p>
            <label for="ea_script_active">
                <input type="checkbox" id="ea_script_active" name="ea_script_active" value="1" <?php checked( $is_active, '1' ); ?>>
                <?php esc_html_e( 'Active', 'energy-analytics' ); ?>
            </label>
            <span class="description">
                <?php esc_html_e( 'Check to enable this script.', 'energy-analytics' ); ?>
            </span>
        </p>
        <?php
    }

    /**
     * Render script code meta box.
     *
     * @param WP_Post $post The post object.
     */
    public function render_script_code_meta_box( $post ) {
        // Get the current code if it exists.
        $code = get_post_meta( $post->ID, '_ea_script_code', true );
        
        ?>
        <p>
            <span class="description">
                <?php esc_html_e( 'Enter JavaScript code to be injected into ACF forms. The code will be wrapped in a self-executing function.', 'energy-analytics' ); ?>
            </span>
        </p>
        
        <textarea id="ea_script_code" name="ea_script_code" class="widefat code" rows="15"><?php echo esc_textarea( $code ); ?></textarea>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize CodeMirror if it's available
            if (typeof wp.codeEditor !== 'undefined') {
                var editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                editorSettings.codemirror = _.extend(
                    {},
                    editorSettings.codemirror,
                    {
                        mode: 'javascript',
                        lineNumbers: true,
                        indentUnit: 4,
                        indentWithTabs: true,
                        theme: 'default'
                    }
                );
                var editor = wp.codeEditor.initialize($('#ea_script_code'), editorSettings);
            }
        });
        </script>
        <?php
    }

    /**
     * Save meta box data.
     *
     * @param int $post_id The post ID.
     */
    public function save_meta_box_data( $post_id ) {
        // Check if our nonce is set.
        if ( ! isset( $_POST['ea_script_meta_box_nonce'] ) ) {
            return;
        }
        
        // Verify the nonce.
        if ( ! wp_verify_nonce( sanitize_key( $_POST['ea_script_meta_box_nonce'] ), 'ea_script_meta_box' ) ) {
            return;
        }
        
        // If this is an autosave, we don't want to do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        
        // Check the user's permissions.
        if ( ! current_user_can( 'manage_energy_scripts', $post_id ) ) {
            return;
        }
        
        // Save the target form.
        if ( isset( $_POST['ea_target_form'] ) ) {
            update_post_meta(
                $post_id,
                '_ea_target_form',
                sanitize_text_field( wp_unslash( $_POST['ea_target_form'] ) )
            );
        }
        
        // Save the active status.
        $is_active = isset( $_POST['ea_script_active'] ) ? '1' : '0';
        update_post_meta( $post_id, '_ea_script_active', $is_active );
        
        // Save the script code.
        if ( isset( $_POST['ea_script_code'] ) ) {
            // We're intentionally not heavily sanitizing the script code since it's controlled by admins
            // and needs to be executed as JS. But we're still stripping possibly dangerous scripts.
            $code = wp_unslash( $_POST['ea_script_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            update_post_meta( $post_id, '_ea_script_code', $code );
        }
    }

    /**
     * Inject custom scripts into ACF forms.
     *
     * @param array $form Form data.
     * @return array Modified form data.
     */
    public function inject_custom_scripts( $form ) {
        // Get all published active scripts.
        $scripts = get_posts( array(
            'post_type'      => 'ea_script',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'   => '_ea_script_active',
                    'value' => '1',
                ),
            ),
        ) );
        
        if ( empty( $scripts ) ) {
            return $form;
        }
        
        // Get current form ID.
        $form_id = $form['id'] ?? '';
        
        // Check each script.
        foreach ( $scripts as $script ) {
            $target_form = get_post_meta( $script->ID, '_ea_target_form', true );
            
            // Only inject if the script targets all forms or this specific form.
            if ( empty( $target_form ) || $target_form === $form_id ) {
                // Get script code.
                $code = get_post_meta( $script->ID, '_ea_script_code', true );
                
                if ( ! empty( $code ) ) {
                    // Generate a unique nonce for this script for CSP.
                    $nonce = wp_create_nonce( 'ea_script_' . $script->ID );
                    
                    // Add script to form.
                    ob_start();
                    ?>
                    <script nonce="<?php echo esc_attr( $nonce ); ?>">
                    (function() {
                        <?php echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    })();
                    </script>
                    <?php
                    $script_tag = ob_get_clean();
                    
                    // Output the script.
                    add_action( 'acf/input/form_head', function() use ( $script_tag ) {
                        echo $script_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    } );
                    
                    // Add CSP nonce to headers.
                    add_filter( 'script_loader_tag', function( $tag, $handle ) use ( $nonce ) {
                        if ( strpos( $tag, 'nonce=' ) === false ) {
                            $tag = str_replace( '<script', '<script nonce="' . esc_attr( $nonce ) . '"', $tag );
                        }
                        return $tag;
                    }, 10, 2 );
                }
            }
        }
        
        return $form;
    }

    /**
     * Enqueue calculation script for ACF forms.
     */
    public function enqueue_calculation_script() {
        // Check if this is an ACF form page.
        if ( ! function_exists( 'acf_get_form_data' ) ) {
            return;
        }
        
        $form = acf_get_form_data();
        
        if ( empty( $form ) ) {
            return;
        }
        
        // Get calculation script.
        $calculation_script = $this->get_calculation_script();
        
        if ( ! empty( $calculation_script ) ) {
            // Generate a nonce for CSP.
            $nonce = wp_create_nonce( 'ea_calculation_script' );
            
            // Enqueue script.
            wp_add_inline_script( 'acf-input', $calculation_script, 'after' );
            
            // Add CSP nonce to headers for this particular script.
            add_filter( 'script_loader_tag', function( $tag, $handle ) use ( $nonce ) {
                if ( 'acf-input' === $handle && strpos( $tag, 'nonce=' ) === false ) {
                    $tag = str_replace( '<script', '<script nonce="' . esc_attr( $nonce ) . '"', $tag );
                }
                return $tag;
            }, 10, 2 );
        }
    }

    /**
     * Get the calculation script.
     *
     * @return string The calculation script.
     */
    private function get_calculation_script() {
        // This would typically fetch from an option or setting.
        // For now, we'll return a basic energy calculation script.
        $script = <<<'EOT'
(function($) {
    // Energy calculation script
    acf.add_action('ready', function() {
        // Get all energy calculation fields
        var energyFields = $('[data-energy-calc]');
        
        if (energyFields.length === 0) {
            return;
        }
        
        // Set up listeners for each field
        energyFields.each(function() {
            var $field = $(this);
            var fieldKey = $field.data('key');
            var calcType = $field.data('energy-calc');
            
            // Find related fields based on data attributes
            var sourceFields = $('[data-energy-source="' + fieldKey + '"]');
            
            // Add listeners to source fields
            sourceFields.on('change', function() {
                calculateEnergy(sourceFields, $field, calcType);
            });
            
            // Initial calculation
            calculateEnergy(sourceFields, $field, calcType);
        });
        
        function calculateEnergy(sourceFields, targetField, calcType) {
            var total = 0;
            var weights = {};
            
            // Get values from source fields
            sourceFields.each(function() {
                var $source = $(this);
                var value = parseFloat($source.val()) || 0;
                var weight = parseFloat($source.data('energy-weight')) || 1;
                var sourceKey = $source.data('key');
                
                weights[sourceKey] = weight;
                total += value * weight;
            });
            
            // Apply calculation type
            var result = total;
            if (calcType === 'percentage') {
                // Calculate percentage based on weights
                result = (total / Object.keys(weights).length) * 100;
            } else if (calcType === 'average') {
                // Calculate weighted average
                result = total / Object.keys(weights).length;
            }
            
            // Set the result to the target field
            targetField.val(result.toFixed(2));
            targetField.trigger('change');
        }
    });
})(jQuery);
EOT;

        return $script;
    }

    /**
     * Save energy data on form submission.
     *
     * @param int $post_id The post ID.
     */
    public function save_energy_data( $post_id ) {
        // Check if this is an ACF form submission.
        if ( ! isset( $_POST['acf'] ) ) {
            return;
        }
        
        // Get form data.
        $form = acf_get_form_data();
        
        if ( empty( $form ) ) {
            return;
        }
        
        // Get form ID.
        $form_id = $form['id'] ?? '';
        
        // Get field values.
        $field_data = array();
        
        foreach ( $_POST['acf'] as $key => $value ) {
            // Skip internal ACF keys.
            if ( '_' === substr( $key, 0, 1 ) ) {
                continue;
            }
            
            // Get field.
            $field = acf_get_field( $key );
            
            if ( ! $field ) {
                continue;
            }
            
            // Add to field data.
            $field_data[ $field['name'] ] = $value;
        }
        
        // Save to database.
        if ( ! empty( $field_data ) ) {
            $this->log_energy_data( $form_id, $field_data );
        }
    }

    /**
     * Log energy data to database.
     *
     * @param string $form_id    The form ID.
     * @param array  $field_data The field data.
     */
    private function log_energy_data( $form_id, $field_data ) {
        global $wpdb;
        
        // Get current user.
        $user_id = get_current_user_id();
        
        // Prepare data.
        $data = array(
            'form_id'    => $form_id,
            'field_data' => wp_json_encode( $field_data ),
            'user_id'    => $user_id,
            'created_at' => current_time( 'mysql', true ),
        );
        
        // Insert into database.
        $wpdb->insert(
            $wpdb->prefix . 'energy_logs',
            $data,
            array(
                '%s', // form_id
                '%s', // field_data
                '%d', // user_id
                '%s', // created_at
            )
        );
    }
}
