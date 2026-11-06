<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database table creation, schema upgrades, and CRUD operations.
 */
class DB {

    /**
     * Get the custom inbox table name with WP table prefix.
     * Table name: {wpdb->prefix}cir_messages
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'cir_messages';
    }

    /**
     * Create or update database schema on plugin activation.
     */
    public static function activate() {
        global $wpdb;

        $cir_table_name = self::get_table_name();
        $cir_charset_collate = $wpdb->get_charset_collate();

        $cir_sql = "CREATE TABLE $cir_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sender_name varchar(255) NOT NULL DEFAULT '',
            sender_email varchar(255) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY sender_email (sender_email)
        ) $cir_charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($cir_sql);

        add_option('cir_db_version', CIR_VERSION);
    }

    /**
     * Insert a captured message into database.
     *
     * @param array $cir_data
     * @return int|false
     */
    public static function insert_message($cir_data) {
        global $wpdb;

        $cir_table_name = self::get_table_name();

        $cir_inserted = $wpdb->insert(
            $cir_table_name,
            [
                'form_id'      => absint($cir_data['form_id']),
                'sender_name'  => sanitize_text_field($cir_data['sender_name']),
                'sender_email' => sanitize_email($cir_data['sender_email']),
                'message'      => sanitize_textarea_field($cir_data['message']),
                'created_at'   => !empty($cir_data['created_at']) ? $cir_data['created_at'] : current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        if ($cir_inserted) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Retrieve a single message record by ID.
     *
     * @param int $cir_id
     * @return object|null
     */
    public static function get_message($cir_id) {
        global $wpdb;
        $cir_table_name = self::get_table_name();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $cir_table_name WHERE id = %d", absint($cir_id)));
    }

    /**
     * Delete a single message record by ID.
     *
     * @param int $cir_id
     * @return bool
     */
    public static function delete_message($cir_id) {
        global $wpdb;
        $cir_table_name = self::get_table_name();

        return (bool) $wpdb->delete($cir_table_name, ['id' => absint($cir_id)], ['%d']);
    }

    /**
     * Delete multiple messages by IDs.
     *
     * @param array $cir_ids
     * @return int Number of deleted rows
     */
    public static function delete_messages_bulk($cir_ids) {
        global $wpdb;

        if (empty($cir_ids) || !is_array($cir_ids)) {
            return 0;
        }

        $cir_ids = array_map('absint', $cir_ids);
        $cir_table_name = self::get_table_name();
        $placeholders = implode(',', array_fill(0, count($cir_ids), '%d'));

        $sql = "DELETE FROM $cir_table_name WHERE id IN ($placeholders)";
        return (int) $wpdb->query($wpdb->prepare($sql, $cir_ids));
    }
}

