<?php
/**
 * Energy Reports Table for listing energy data in admin
 *
 * @package Energy_Analytics
 */

namespace EA\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load WP_List_Table if not loaded.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class EnergyReportsTable
 * 
 * Creates a list table for displaying energy reports
 *
 * @package EA\Admin
 */
class EnergyReportsTable extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => __( 'Report', 'energy-analytics' ),
            'plural'   => __( 'Reports', 'energy-analytics' ),
            'ajax'     => false,
        ) );
    }

    /**
     * Get columns.
     *
     * @return array
     */
    public function get_columns() {
        $columns = array(
            'cb'         => '<input type="checkbox" />',
            'id'         => __( 'ID', 'energy-analytics' ),
            'form_id'    => __( 'Form', 'energy-analytics' ),
            'user_id'    => __( 'User', 'energy-analytics' ),
            'field_data' => __( 'Data', 'energy-analytics' ),
            'created_at' => __( 'Date', 'energy-analytics' ),
        );

        return $columns;
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'id'         => array( 'id', true ),
            'form_id'    => array( 'form_id', false ),
            'user_id'    => array( 'user_id', false ),
            'created_at' => array( 'created_at', false ),
        );

        return $sortable_columns;
    }

    /**
     * Prepare items.
     */
    public function prepare_items() {
        global $wpdb;

        // Process bulk actions.
        $this->process_bulk_action();

        // Table name.
        $table_name = $wpdb->prefix . 'energy_logs';

        // Column headers.
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );

        // Pagination.
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ( $current_page - 1 ) * $per_page;

        // Build the query.
        $orderby = ( ! empty( $_REQUEST['orderby'] ) ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'id';
        $order = ( ! empty( $_REQUEST['order'] ) ) ? strtoupper( sanitize_key( $_REQUEST['order'] ) ) : 'DESC';

        if ( ! in_array( $order, array( 'ASC', 'DESC' ) ) ) {
            $order = 'DESC';
        }

        if ( ! in_array( $orderby, array_keys( $this->get_sortable_columns() ) ) ) {
            $orderby = 'id';
        }

        // Apply search if any.
        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
        $where = '';

        if ( $search ) {
            $where = $wpdb->prepare(
                ' WHERE form_id LIKE %s OR field_data LIKE %s ',
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%'
            );
        }

        // Count total items.
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where" );

        // Get the items.
        $query = $wpdb->prepare(
            "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );

        $this->items = $wpdb->get_results( $query, ARRAY_A );

        // Set pagination args.
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }

    /**
     * Get bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'export_csv' => __( 'Export to CSV', 'energy-analytics' ),
        );
    }

    /**
     * Process bulk actions.
     */
    public function process_bulk_action() {
        // Security check.
        if ( isset( $_POST['_wpnonce'] ) && ! empty( $_POST['_wpnonce'] ) ) {
            $nonce = filter_input( INPUT_POST, '_wpnonce', FILTER_SANITIZE_STRING );
            $action = 'bulk-' . $this->_args['plural'];

            if ( ! wp_verify_nonce( $nonce, $action ) ) {
                wp_die( 'Security check failed' );
            }
        }

        $action = $this->current_action();

        switch ( $action ) {
            case 'export_csv':
                // Handled by the Admin class.
                break;
        }
    }

    /**
     * Default column renderer.
     *
     * @param array  $item        The current item.
     * @param string $column_name The current column name.
     *
     * @return string Column output.
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
                return esc_html( $item['id'] );

            case 'form_id':
                return esc_html( $item['form_id'] );

            case 'user_id':
                $user_info = get_userdata( $item['user_id'] );
                return $user_info ? esc_html( $user_info->display_name ) : __( 'Unknown User', 'energy-analytics' );

            case 'field_data':
                $field_data = json_decode( $item['field_data'], true );
                if ( is_array( $field_data ) ) {
                    $output = '<div class="ea-field-data-summary">';
                    $count = 0;
                    foreach ( $field_data as $key => $value ) {
                        if ( $count >= 3 ) {
                            $output .= '... ';
                            break;
                        }
                        $output .= '<span class="ea-field-key">' . esc_html( $key ) . ':</span> ';
                        $output .= '<span class="ea-field-value">' . esc_html( $value ) . '</span><br>';
                        $count++;
                    }
                    $output .= '</div>';
                    return $output;
                }
                return __( 'No data', 'energy-analytics' );

            case 'created_at':
                return esc_html( $item['created_at'] );

            default:
                return print_r( $item, true );
        }
    }

    /**
     * Column cb.
     *
     * @param array $item The current item.
     *
     * @return string Checkbox HTML.
     */
    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="report[]" value="%s" />',
            $item['id']
        );
    }

    /**
     * Column id with actions.
     *
     * @param array $item The current item.
     *
     * @return string Column output with actions.
     */
    public function column_id( $item ) {
        // Build row actions.
        $actions = array(
            'view'    => sprintf( '<a href="#" class="ea-view-report" data-id="%s">%s</a>', $item['id'], __( 'View', 'energy-analytics' ) ),
            'export'  => sprintf( '<a href="%s">%s</a>', wp_nonce_url( admin_url( 'admin-post.php?action=ea_export_report&id=' . $item['id'] ), 'ea_export_report_' . $item['id'] ), __( 'Export', 'energy-analytics' ) ),
        );

        // Return the title contents.
        return sprintf(
            '%1$s %2$s',
            esc_html( $item['id'] ),
            $this->row_actions( $actions )
        );
    }

    /**
     * Extra table nav.
     *
     * @param string $which Top or bottom.
     */
    public function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        // Add filter for form IDs.
        global $wpdb;
        $table_name = $wpdb->prefix . 'energy_logs';
        $form_ids = $wpdb->get_col( "SELECT DISTINCT form_id FROM $table_name ORDER BY form_id ASC" );

        if ( ! empty( $form_ids ) ) {
            $current_form_id = isset( $_REQUEST['form_id'] ) ? sanitize_text_field( $_REQUEST['form_id'] ) : '';
            ?>
            <div class="alignleft actions">
                <label for="filter-by-form-id" class="screen-reader-text"><?php esc_html_e( 'Filter by form', 'energy-analytics' ); ?></label>
                <select name="form_id" id="filter-by-form-id">
                    <option value=""><?php esc_html_e( 'All forms', 'energy-analytics' ); ?></option>
                    <?php foreach ( $form_ids as $form_id ) : ?>
                        <option value="<?php echo esc_attr( $form_id ); ?>" <?php selected( $current_form_id, $form_id ); ?>><?php echo esc_html( $form_id ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( __( 'Filter', 'energy-analytics' ), '', 'filter_action', false ); ?>
            </div>
            <?php
        }
    }

    /**
     * Generates content for a single row of the table.
     *
     * @param object $item The current item.
     */
    public function single_row( $item ) {
        echo '<tr class="ea-report-row" data-id="' . esc_attr( $item['id'] ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    /**
     * Get text to display when no items are found.
     */
    public function no_items() {
        esc_html_e( 'No energy reports found.', 'energy-analytics' );
    }
}