<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles Admin Menus, Inbox Listing, Reply Actions, and SMTP Settings pages.
 */
class Admin_UI {

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'cir_register_menus']);
        add_action('admin_init', [__CLASS__, 'cir_register_settings']);
        add_action('admin_init', [__CLASS__, 'cir_handle_actions']);
    }

    /**
     * Register Admin Menus under Contact (wpcf7).
     */
    public static function cir_register_menus() {
        // Inbox Page under Contact
        add_submenu_page(
            'wpcf7',
            __('CF7 Inbox', 'cf7-inbox-reply-manager'),
            __('CF7 Inbox', 'cf7-inbox-reply-manager'),
            'manage_options',
            'cir-inbox',
            [__CLASS__, 'cir_render_inbox_page']
        );

        // SMTP Settings sub-menu under Contact
        add_submenu_page(
            'wpcf7',
            __('Inbox SMTP Settings', 'cf7-inbox-reply-manager'),
            __('Inbox SMTP Settings', 'cf7-inbox-reply-manager'),
            'manage_options',
            'cir-smtp-settings',
            [__CLASS__, 'cir_render_smtp_page']
        );
    }

    /**
     * Register plugin settings with Settings API.
     */
    public static function cir_register_settings() {
        register_setting(
            'cir_smtp_settings_group',
            Mailer::OPTION_KEY,
            ['sanitize_callback' => [__CLASS__, 'cir_sanitize_smtp_settings']]
        );
    }

    /**
     * Sanitize SMTP settings array before saving.
     *
     * @param array $input
     * @return array
     */
    public static function cir_sanitize_smtp_settings($input) {
        $sanitized = [];
        $sanitized['smtp_enabled']    = !empty($input['smtp_enabled']) ? 1 : 0;
        $sanitized['smtp_host']       = sanitize_text_field($input['smtp_host'] ?? '');
        $sanitized['smtp_port']       = !empty($input['smtp_port']) ? absint($input['smtp_port']) : 587;
        $sanitized['smtp_encryption'] = in_array($input['smtp_encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $input['smtp_encryption'] : 'tls';
        $sanitized['smtp_auth']       = !empty($input['smtp_auth']) ? 1 : 0;
        $sanitized['smtp_username']   = sanitize_text_field($input['smtp_username'] ?? '');

        // Preserve password if empty on save
        $current = Mailer::get_settings();
        if (isset($input['smtp_password']) && $input['smtp_password'] !== '') {
            $sanitized['smtp_password'] = $input['smtp_password'];
        } else {
            $sanitized['smtp_password'] = $current['smtp_password'] ?? '';
        }

        $sanitized['from_email']      = sanitize_email($input['from_email'] ?? '');
        $sanitized['from_name']       = sanitize_text_field($input['from_name'] ?? '');

        return $sanitized;
    }

    /**
     * Process message deletions, bulk deletions, and reply submissions.
     */
    public static function cir_handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 1. Process Single Deletion
        if (isset($_GET['page']) && $_GET['page'] === 'cir-inbox' && isset($_GET['action']) && $_GET['action'] === 'delete') {
            $cir_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
            check_admin_referer('cir_delete_action_' . $cir_id);

            DB::delete_message($cir_id);

            wp_safe_redirect(add_query_arg(['page' => 'cir-inbox', 'cir_notice' => 'deleted'], admin_url('admin.php')));
            exit;
        }

        // 2. Process Bulk Actions
        if (isset($_POST['page']) && $_POST['page'] === 'cir-inbox') {
            $cir_action = '';
            if (isset($_POST['action']) && $_POST['action'] !== '-1') {
                $cir_action = sanitize_key($_POST['action']);
            } elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
                $cir_action = sanitize_key($_POST['action2']);
            }

            if ($cir_action === 'cir_bulk_delete' && !empty($_POST['cir_message_ids'])) {
                check_admin_referer('bulk-cirmessages');

                $ids = array_map('absint', (array) $_POST['cir_message_ids']);
                DB::delete_messages_bulk($ids);

                wp_safe_redirect(add_query_arg(['page' => 'cir-inbox', 'cir_notice' => 'bulk_deleted'], admin_url('admin.php')));
                exit;
            }
        }

        // 3. Process Reply Dispatch
        if (isset($_POST['cir_send_reply'])) {
            check_admin_referer('cir_send_reply_action');

            $cir_message_id = isset($_POST['message_id']) ? absint($_POST['message_id']) : 0;
            $cir_recipient  = isset($_POST['reply_to']) ? sanitize_email($_POST['reply_to']) : '';
            $cir_subject    = isset($_POST['reply_subject']) ? sanitize_text_field($_POST['reply_subject']) : '';
            $cir_body       = isset($_POST['reply_body']) ? sanitize_textarea_field($_POST['reply_body']) : '';

            if (empty($cir_recipient) || !is_email($cir_recipient) || empty($cir_body)) {
                wp_safe_redirect(add_query_arg([
                    'page'       => 'cir-inbox',
                    'action'     => 'reply',
                    'id'         => $cir_message_id,
                    'cir_notice' => 'reply_error',
                ], admin_url('admin.php')));
                exit;
            }

            $sent = Mailer::send_reply($cir_recipient, $cir_subject, $cir_body);

            if ($sent) {
                wp_safe_redirect(add_query_arg([
                    'page'       => 'cir-inbox',
                    'cir_notice' => 'reply_sent',
                ], admin_url('admin.php')));
            } else {
                wp_safe_redirect(add_query_arg([
                    'page'       => 'cir-inbox',
                    'action'     => 'reply',
                    'id'         => $cir_message_id,
                    'cir_notice' => 'reply_failed',
                ], admin_url('admin.php')));
            }
            exit;
        }
    }

    /**
     * Render the Inbox Admin Page.
     */
    public static function cir_render_inbox_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'cf7-inbox-reply-manager'));
        }

        $cir_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        $cir_id     = isset($_GET['id']) ? absint($_GET['id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Contact Form 7 - Inbox & Reply Manager', 'cf7-inbox-reply-manager') . '</h1>';

        // Display Notices
        self::cir_render_notices();

        if ($cir_action === 'reply' && $cir_id > 0) {
            self::cir_render_reply_view($cir_id);
        } else {
            self::cir_render_table_view();
        }

        echo '</div>';
    }

    /**
     * Render Notice Banners.
     */
    private static function cir_render_notices() {
        if (!isset($_GET['cir_notice'])) {
            return;
        }

        $notice = sanitize_key($_GET['cir_notice']);
        switch ($notice) {
            case 'deleted':
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Message successfully deleted.', 'cf7-inbox-reply-manager') . '</p></div>';
                break;
            case 'bulk_deleted':
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Selected messages deleted.', 'cf7-inbox-reply-manager') . '</p></div>';
                break;
            case 'reply_sent':
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Reply sent successfully!', 'cf7-inbox-reply-manager') . '</p></div>';
                break;
            case 'reply_error':
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Error: Please provide a valid recipient email and message body.', 'cf7-inbox-reply-manager') . '</p></div>';
                break;
            case 'reply_failed':
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to send reply. Please verify your SMTP settings or server mail setup.', 'cf7-inbox-reply-manager') . '</p></div>';
                break;
        }
    }

    /**
     * Render the Messages Table.
     */
    private static function cir_render_table_view() {
        $table = new Message_List_Table();
        $table->prepare_items();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=cir-inbox')); ?>">
            <input type="hidden" name="page" value="cir-inbox" />
            <?php
            $table->search_box(__('Search Messages', 'cf7-inbox-reply-manager'), 'cir-search');
            $table->display();
            ?>
        </form>
        <?php
    }

    /**
     * Render the Reply Form for a message.
     *
     * @param int $cir_id
     */
    private static function cir_render_reply_view($cir_id) {
        $message = DB::get_message($cir_id);

        if (!$message) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Message not found.', 'cf7-inbox-reply-manager') . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=cir-inbox')) . '" class="button">&larr; ' . esc_html__('Back to Inbox', 'cf7-inbox-reply-manager') . '</a></p>';
            return;
        }

        $form_title = $message->form_id ? get_the_title($message->form_id) : __('General', 'cf7-inbox-reply-manager');
        ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=cir-inbox')); ?>" class="button">&larr; <?php esc_html_e('Back to Inbox', 'cf7-inbox-reply-manager'); ?></a></p>

        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:15px;">
            <!-- Message Summary Card -->
            <div style="flex:1; min-width:320px; background:#fff; border:1px solid #ccd0d4; padding:20px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;"><?php esc_html_e('Received Message Details', 'cf7-inbox-reply-manager'); ?></h2>
                <table class="form-table" style="margin-top:0;">
                    <tr>
                        <th style="width:120px;"><?php esc_html_e('Sender Name', 'cf7-inbox-reply-manager'); ?></th>
                        <td><strong><?php echo esc_html($message->sender_name ?: '(No Name)'); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Email Address', 'cf7-inbox-reply-manager'); ?></th>
                        <td><a href="mailto:<?php echo esc_attr($message->sender_email); ?>"><?php echo esc_html($message->sender_email ?: '(No Email)'); ?></a></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Form Source', 'cf7-inbox-reply-manager'); ?></th>
                        <td><?php echo esc_html($form_title); ?> (ID: #<?php echo esc_html($message->form_id); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Received At', 'cf7-inbox-reply-manager'); ?></th>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message->created_at))); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Original Message', 'cf7-inbox-reply-manager'); ?></th>
                        <td>
                            <div style="background:#f9f9f9; border:1px solid #e5e5e5; padding:12px; border-radius:4px; max-height:260px; overflow-y:auto; white-space:pre-wrap; font-family:monospace;"><?php echo esc_html($message->message); ?></div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Reply Form Card -->
            <div style="flex:1.2; min-width:340px; background:#fff; border:1px solid #ccd0d4; padding:20px; box-shadow:0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;"><?php esc_html_e('Compose Reply', 'cf7-inbox-reply-manager'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=cir-inbox')); ?>">
                    <?php wp_nonce_field('cir_send_reply_action'); ?>
                    <input type="hidden" name="message_id" value="<?php echo esc_attr($message->id); ?>" />

                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row"><label for="reply_to"><?php esc_html_e('To Email', 'cf7-inbox-reply-manager'); ?></label></th>
                            <td>
                                <input name="reply_to" type="email" id="reply_to" value="<?php echo esc_attr($message->sender_email); ?>" class="regular-text" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="reply_subject"><?php esc_html_e('Subject', 'cf7-inbox-reply-manager'); ?></label></th>
                            <td>
                                <input name="reply_subject" type="text" id="reply_subject" value="<?php echo esc_attr('Re: ' . get_bloginfo('name') . ' Contact Inquiry'); ?>" class="regular-text" required />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="reply_body"><?php esc_html_e('Reply Message', 'cf7-inbox-reply-manager'); ?></label></th>
                            <td>
                                <textarea name="reply_body" id="reply_body" rows="10" class="large-text" required placeholder="<?php esc_attr_e('Write your response here...', 'cf7-inbox-reply-manager'); ?>"><?php echo esc_textarea("\n\n--- Original Message ---\n" . $message->message); ?></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="cir_send_reply" id="submit" class="button button-primary button-large" value="<?php esc_attr_e('Send Reply Email', 'cf7-inbox-reply-manager'); ?>" />
                        <a href="<?php echo esc_url(admin_url('admin.php?page=cir-inbox')); ?>" class="button button-secondary"><?php esc_html_e('Cancel', 'cf7-inbox-reply-manager'); ?></a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the SMTP Configuration Page.
     */
    public static function cir_render_smtp_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'cf7-inbox-reply-manager'));
        }

        $settings = Mailer::get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Inbox & Reply SMTP Configuration', 'cf7-inbox-reply-manager'); ?></h1>
            <p><?php esc_html_e('Configure custom SMTP credentials used when dispatching reply emails directly from the CF7 Inbox.', 'cf7-inbox-reply-manager'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('cir_smtp_settings_group');
                $key = Mailer::OPTION_KEY;
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Custom SMTP', 'cf7-inbox-reply-manager'); ?></th>
                        <td>
                            <label for="cir_smtp_enabled">
                                <input name="<?php echo esc_attr($key); ?>[smtp_enabled]" type="checkbox" id="cir_smtp_enabled" value="1" <?php checked(1, $settings['smtp_enabled']); ?> />
                                <?php esc_html_e('Route reply emails through this custom SMTP server', 'cf7-inbox-reply-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_smtp_host"><?php esc_html_e('SMTP Host', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[smtp_host]" type="text" id="cir_smtp_host" value="<?php echo esc_attr($settings['smtp_host']); ?>" class="regular-text" placeholder="smtp.gmail.com" />
                            <p class="description"><?php esc_html_e('Outgoing mail server address.', 'cf7-inbox-reply-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_smtp_port"><?php esc_html_e('SMTP Port', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[smtp_port]" type="number" id="cir_smtp_port" value="<?php echo esc_attr($settings['smtp_port']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Standard ports: 587 (TLS), 465 (SSL), or 25.', 'cf7-inbox-reply-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_smtp_encryption"><?php esc_html_e('Encryption', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr($key); ?>[smtp_encryption]" id="cir_smtp_encryption">
                                <option value="tls" <?php selected('tls', $settings['smtp_encryption']); ?>><?php esc_html_e('TLS', 'cf7-inbox-reply-manager'); ?></option>
                                <option value="ssl" <?php selected('ssl', $settings['smtp_encryption']); ?>><?php esc_html_e('SSL', 'cf7-inbox-reply-manager'); ?></option>
                                <option value="none" <?php selected('none', $settings['smtp_encryption']); ?>><?php esc_html_e('None', 'cf7-inbox-reply-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Authentication', 'cf7-inbox-reply-manager'); ?></th>
                        <td>
                            <label for="cir_smtp_auth">
                                <input name="<?php echo esc_attr($key); ?>[smtp_auth]" type="checkbox" id="cir_smtp_auth" value="1" <?php checked(1, $settings['smtp_auth']); ?> />
                                <?php esc_html_e('Yes, use SMTP authentication', 'cf7-inbox-reply-manager'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_smtp_username"><?php esc_html_e('SMTP Username', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[smtp_username]" type="text" id="cir_smtp_username" value="<?php echo esc_attr($settings['smtp_username']); ?>" class="regular-text" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_smtp_password"><?php esc_html_e('SMTP Password', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[smtp_password]" type="password" id="cir_smtp_password" value="<?php echo esc_attr($settings['smtp_password']); ?>" class="regular-text" autocomplete="new-password" />
                            <p class="description"><?php esc_html_e('Leave blank to keep the current stored password.', 'cf7-inbox-reply-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_from_email"><?php esc_html_e('From Email', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[from_email]" type="email" id="cir_from_email" value="<?php echo esc_attr($settings['from_email']); ?>" class="regular-text" placeholder="support@yourdomain.com" />
                            <p class="description"><?php esc_html_e('Email address used in the From header for outgoing replies.', 'cf7-inbox-reply-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cir_from_name"><?php esc_html_e('From Name', 'cf7-inbox-reply-manager'); ?></label></th>
                        <td>
                            <input name="<?php echo esc_attr($key); ?>[from_name]" type="text" id="cir_from_name" value="<?php echo esc_attr($settings['from_name']); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Display name used in the From header.', 'cf7-inbox-reply-manager'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save SMTP Settings', 'cf7-inbox-reply-manager')); ?>
            </form>
        </div>
        <?php
    }
}

