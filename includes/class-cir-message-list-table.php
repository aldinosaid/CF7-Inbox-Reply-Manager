<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom WP_List_Table to display saved Contact Form 7 messages.
 */
class Message_List_Table extends \WP_List_Table {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct([
            'singular' => 'cir_message',
            'plural'   => 'cir_messages',
            'ajax'     => false,
        ]);
    }

    /**
     * List Table columns.
     *
     * @return array
     */
    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />',
            'sender_name'  => __('Sender', 'cf7-inbox-reply-manager'),
            'sender_email' => __('Email', 'cf7-inbox-reply-manager'),
            'message'      => __('Message', 'cf7-inbox-reply-manager'),
            'form_id'      => __('Form Source', 'cf7-inbox-reply-manager'),
            'created_at'   => __('Received Date', 'cf7-inbox-reply-manager'),
        ];
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return [
            'sender_name' => ['sender_name', false],
            'created_at'  => ['created_at', true],
        ];
    }

    /**
     * Bulk actions checkbox column.
     *
     * @param object $item
     * @return string
     */
    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="cir_message_ids[]" value="%d" />',
            $item->id
        );
    }

    /**
     * Sender Name column with row actions.
     *
     * @param object $item
     * @return string
     */
    protected function column_sender_name($item) {
        $cir_reply_nonce  = wp_create_nonce('cir_reply_action_' . $item->id);
        $cir_delete_nonce = wp_create_nonce('cir_delete_action_' . $item->id);

        $cir_reply_url  = admin_url('admin.php?page=cir-inbox&action=reply&id=' . $item->id . '&_wpnonce=' . $cir_reply_nonce);
        $cir_delete_url = admin_url('admin.php?page=cir-inbox&action=delete&id=' . $item->id . '&_wpnonce=' . $cir_delete_nonce);

        $cir_display_name = !empty($item->sender_name) ? esc_html($item->sender_name) : '<em>' . esc_html__('(No Name)', 'cf7-inbox-reply-manager') . '</em>';

        $cir_actions = [
            'reply'  => sprintf('<a href="%s" style="font-weight:600; color:#2271b1;">%s</a>', esc_url($cir_reply_url), esc_html__('Reply', 'cf7-inbox-reply-manager')),
            'delete' => sprintf('<a href="%s" onclick="return confirm(\'%s\');" style="color:#b32d2e;">%s</a>', esc_url($cir_delete_url), esc_js(__('Are you sure you want to delete this message?', 'cf7-inbox-reply-manager')), esc_html__('Delete', 'cf7-inbox-reply-manager')),
        ];

        return sprintf('<strong>%1$s</strong> %2$s', $cir_display_name, $this->row_actions($cir_actions));
    }

    /**
     * Sender Email column.
     *
     * @param object $item
     * @return string
     */
    protected function column_sender_email($item) {
        if (empty($item->sender_email)) {
            return '<em>' . esc_html__('(None)', 'cf7-inbox-reply-manager') . '</em>';
        }
        return sprintf('<a href="mailto:%1$s">%1$s</a>', esc_attr($item->sender_email));
    }

    /**
     * Message excerpt column.
     *
     * @param object $item
     * @return string
     */
    protected function column_message($item) {
        $cir_preview = wp_trim_words($item->message, 18, '...');
        return esc_html($cir_preview);
    }

    /**
     * Form ID / Form name column.
     *
     * @param object $item
     * @return string
     */
    protected function column_form_id($item) {
        if (empty($item->form_id)) {
            return esc_html__('General / Unknown', 'cf7-inbox-reply-manager');
        }
        $cir_form_title = get_the_title($item->form_id);
        if ($cir_form_title) {
            return sprintf(
                '<a href="%s">%s <span style="color:#666;">(#%d)</span></a>',
                esc_url(admin_url('admin.php?page=wpcf7&post=' . $item->form_id . '&action=edit')),
                esc_html($cir_form_title),
                $item->form_id
            );
        }
        return '#' . esc_html($item->form_id);
    }

    /**
     * Date column formatted with WP date/time settings.
     *
     * @param object $item
     * @return string
     */
    protected function column_created_at($item) {
        $timestamp = strtotime($item->created_at);
        $formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        return esc_html($formatted);
    }

    /**
     * Default column fallback.
     *
     * @param object $item
     * @param string $column_name
     * @return mixed
     */
    protected function column_default($item, $column_name) {
        return isset($item->$column_name) ? esc_html($item->$column_name) : '';
    }

    /**
     * Bulk actions dropdown options.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return [
            'cir_bulk_delete' => __('Delete', 'cf7-inbox-reply-manager'),
        ];
    }

    /**
     * Query data and prepare items.
     */
    public function prepare_items() {
        global $wpdb;

        $cir_table_name = DB::get_table_name();
        $per_page = 20;

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Search Filter
        $cir_search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $cir_where = 'WHERE 1=1';
        $cir_params = [];

        if (!empty($cir_search)) {
            $like = '%' . $wpdb->esc_like($cir_search) . '%';
            $cir_where .= ' AND (sender_name LIKE %s OR sender_email LIKE %s OR message LIKE %s)';
            $cir_params[] = $like;
            $cir_params[] = $like;
            $cir_params[] = $like;
        }

        // Sorting
        $cir_orderby = isset($_GET['orderby']) ? sanitize_sql_orderby(wp_unslash($_GET['orderby'])) : 'created_at';
        $cir_order   = (isset($_GET['order']) && strtoupper(wp_unslash($_GET['order'])) === 'ASC') ? 'ASC' : 'DESC';

        $valid_order_keys = ['sender_name', 'created_at', 'id', 'sender_email'];
        if (!in_array($cir_orderby, $valid_order_keys, true)) {
            $cir_orderby = 'created_at';
        }

        // Count Total
        $cir_count_query = "SELECT COUNT(*) FROM $cir_table_name $cir_where";
        if (!empty($cir_params)) {
            $total_items = (int) $wpdb->get_var($wpdb->prepare($cir_count_query, $cir_params));
        } else {
            $total_items = (int) $wpdb->get_var($cir_count_query);
        }

        // Pagination
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Query Records
        $cir_items_query = "SELECT * FROM $cir_table_name $cir_where ORDER BY $cir_orderby $cir_order LIMIT %d OFFSET %d";
        $cir_paged_params = array_merge($cir_params, [$per_page, $offset]);
        $this->items = $wpdb->get_results($wpdb->prepare($cir_items_query, $cir_paged_params));

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }
}

