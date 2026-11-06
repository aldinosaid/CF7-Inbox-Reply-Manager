<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles intercepting Contact Form 7 submissions.
 */
class CF7_Capture {

    /**
     * Register hook.
     */
    public static function init() {
        add_action('wpcf7_before_send_mail', [__CLASS__, 'cir_intercept_submission'], 10, 3);
    }

    /**
     * Intercept Contact Form 7 submission and save into custom DB table.
     *
     * @param \WPCF7_ContactForm $contact_form
     * @param bool               $abort
     * @param \WPCF7_Submission   $submission
     */
    public static function cir_intercept_submission($contact_form, &$abort = false, $submission = null) {
        if (!$submission) {
            $submission = \WPCF7_Submission::get_instance();
        }

        if (!$submission) {
            return;
        }

        $cir_posted_data = $submission->get_posted_data();
        if (empty($cir_posted_data)) {
            return;
        }

        $cir_form_id = $contact_form->id();

        // 1. Resolve Sender Name
        $cir_sender_name = '';
        $name_fields = ['your-name', 'name', 'full-name', 'sender-name', 'first-name'];
        foreach ($name_fields as $field) {
            if (!empty($cir_posted_data[$field])) {
                $cir_sender_name = is_array($cir_posted_data[$field]) ? implode(' ', $cir_posted_data[$field]) : $cir_posted_data[$field];
                break;
            }
        }

        // 2. Resolve Sender Email
        $cir_sender_email = '';
        $email_fields = ['your-email', 'email', 'sender-email', 'mail'];
        foreach ($email_fields as $field) {
            if (!empty($cir_posted_data[$field])) {
                $cir_sender_email = is_array($cir_posted_data[$field]) ? reset($cir_posted_data[$field]) : $cir_posted_data[$field];
                break;
            }
        }

        // If not matched by standard field name, scan posted data for valid email format
        if (empty($cir_sender_email)) {
            foreach ($cir_posted_data as $key => $val) {
                if (is_string($val) && is_email($val)) {
                    $cir_sender_email = $val;
                    break;
                }
            }
        }

        // 3. Resolve Message
        $cir_message = '';
        $message_fields = ['your-message', 'message', 'body', 'comments', 'comment', 'description'];
        foreach ($message_fields as $field) {
            if (!empty($cir_posted_data[$field])) {
                $cir_message = is_array($cir_posted_data[$field]) ? implode("\n", $cir_posted_data[$field]) : $cir_posted_data[$field];
                break;
            }
        }

        // If message field not explicitly matched, aggregate all non-system fields
        if (empty($cir_message)) {
            $cir_compiled = [];
            foreach ($cir_posted_data as $k => $v) {
                if (strpos($k, '_wpcf7') === 0 || strpos($k, '_wp_') === 0) {
                    continue;
                }
                $v_str = is_array($v) ? implode(', ', $v) : $v;
                $cir_compiled[] = ucfirst(str_replace(['-', '_'], ' ', $k)) . ': ' . $v_str;
            }
            $cir_message = implode("\n", $cir_compiled);
        }

        // Save to Database
        DB::insert_message([
            'form_id'      => $cir_form_id,
            'sender_name'  => $cir_sender_name,
            'sender_email' => $cir_sender_email,
            'message'      => $cir_message,
            'created_at'   => current_time('mysql'),
        ]);
    }
}

