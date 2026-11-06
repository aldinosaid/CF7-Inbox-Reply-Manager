<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles SMTP options and sending reply emails using phpmailer_init.
 */
class Mailer {

    const OPTION_KEY = 'cir_smtp_settings';

    /**
     * Flag whether custom SMTP should be applied to current outgoing mail.
     *
     * @var bool
     */
    private static $cir_is_replying = false;

    /**
     * Initialize Mailer hook.
     */
    public static function init() {
        add_action('phpmailer_init', [__CLASS__, 'cir_configure_phpmailer']);
    }

    /**
     * Get saved SMTP settings merged with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        $cir_defaults = [
            'smtp_enabled'    => 0,
            'smtp_host'       => '',
            'smtp_port'       => 587,
            'smtp_encryption' => 'tls',
            'smtp_auth'       => 1,
            'smtp_username'   => '',
            'smtp_password'   => '',
            'from_email'      => '',
            'from_name'       => get_bloginfo('name'),
        ];

        $cir_options = get_option(self::OPTION_KEY, []);
        return wp_parse_args($cir_options, $cir_defaults);
    }

    /**
     * Configure PHPMailer instance.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public static function cir_configure_phpmailer($phpmailer) {
        $cir_settings = self::get_settings();

        // Only configure SMTP if explicitly enabled in settings or during a CIR reply dispatch
        if (empty($cir_settings['smtp_enabled']) && !self::$cir_is_replying) {
            return;
        }

        if (empty($cir_settings['smtp_host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = sanitize_text_field($cir_settings['smtp_host']);
        $phpmailer->Port = absint($cir_settings['smtp_port']);

        if (!empty($cir_settings['smtp_encryption']) && in_array($cir_settings['smtp_encryption'], ['ssl', 'tls'], true)) {
            $phpmailer->SMTPSecure = $cir_settings['smtp_encryption'];
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        if (!empty($cir_settings['smtp_auth'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = sanitize_text_field($cir_settings['smtp_username']);
            $phpmailer->Password = $cir_settings['smtp_password'];
        } else {
            $phpmailer->SMTPAuth = false;
        }

        // Configure sender headers if specified
        if (!empty($cir_settings['from_email']) && is_email($cir_settings['from_email'])) {
            $phpmailer->From = sanitize_email($cir_settings['from_email']);
            $phpmailer->FromName = !empty($cir_settings['from_name']) ? sanitize_text_field($cir_settings['from_name']) : get_bloginfo('name');
        }
    }

    /**
     * Send an email reply to a sender.
     *
     * @param string $cir_to
     * @param string $cir_subject
     * @param string $cir_body
     * @param array  $cir_headers
     * @return bool
     */
    public static function send_reply($cir_to, $cir_subject, $cir_body, $cir_headers = []) {
        self::$cir_is_replying = true;

        $cir_settings = self::get_settings();
        $default_headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        if (!empty($cir_settings['from_email']) && is_email($cir_settings['from_email'])) {
            $cir_from_name = !empty($cir_settings['from_name']) ? $cir_settings['from_name'] : get_bloginfo('name');
            $default_headers[] = 'From: ' . $cir_from_name . ' <' . $cir_settings['from_email'] . '>';
        }

        $combined_headers = array_merge($default_headers, (array) $cir_headers);

        $html_content = wpautop(esc_html($cir_body));

        $sent = wp_mail($cir_to, $cir_subject, $html_content, $combined_headers);

        self::$cir_is_replying = false;

        return $sent;
    }
}

