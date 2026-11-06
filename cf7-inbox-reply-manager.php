<?php
/**
 * Plugin Name: CF7 Inbox & Reply Manager
 * Plugin URI:  https://github.com/aldinosaid/cf7-inbox-reply-manager
 * Description: Stores Contact Form 7 submissions into a dedicated database inbox, enables direct admin replies, and integrates custom SMTP configuration.
 * Version:     1.0.0
 * Author:      Aldino Said
 * Author URI:  https://github.com/aldinosaid
 * License:     GPL-2.0-or-later
 * Text Domain: cf7-inbox-reply-manager
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants with cir_ prefix
define('CIR_VERSION', '1.0.0');
define('CIR_PLUGIN_FILE', __FILE__);
define('CIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CIR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CIR_GITHUB_USER', 'aldinosaid');
define('CIR_GITHUB_REPO', 'cf7-inbox-reply-manager');

// Autoloader for CIR Classes
spl_autoload_register(function ($class) {
    $prefix = 'CIR\\';
    $base_dir = CIR_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-cir-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Main Plugin Class
 */
final class CIR_Plugin {

    /**
     * Singleton instance
     *
     * @var CIR_Plugin|null
     */
    private static $instance = null;

    /**
     * Get class instance.
     *
     * @return CIR_Plugin
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->cir_register_hooks();
    }

    /**
     * Register activation hooks and plugin loading.
     */
    private function cir_register_hooks() {
        register_activation_hook(CIR_PLUGIN_FILE, ['\\CIR\\DB', 'activate']);

        add_action('plugins_loaded', [$this, 'cir_init_plugin']);
    }

    /**
     * Initialize plugin modules.
     */
    public function cir_init_plugin() {
        // Initialize CF7 form submission listener
        \CIR\CF7_Capture::init();

        // Initialize SMTP Mailer
        \CIR\Mailer::init();

        // Initialize Admin UI
        if (is_admin()) {
            \CIR\Admin_UI::init();

            // Initialize GitHub Updater
            new \CIR\GitHub_Updater(CIR_PLUGIN_FILE, CIR_GITHUB_USER, CIR_GITHUB_REPO);
        }
    }
}

/**
 * Returns the main instance of CIR_Plugin.
 *
 * @return CIR_Plugin
 */
function cir_plugin() {
    return CIR_Plugin::instance();
}

// Launch plugin
cir_plugin();

