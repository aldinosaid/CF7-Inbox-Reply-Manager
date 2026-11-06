<?php
namespace CIR;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automatic updates and version checking directly from GitHub Releases.
 */
class GitHub_Updater {

    /**
     * GitHub repository username/organization.
     *
     * @var string
     */
    private $username;

    /**
     * GitHub repository name.
     *
     * @var string
     */
    private $repository;

    /**
     * Main plugin file path.
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin slug (e.g. cf7-inbox-reply-manager).
     *
     * @var string
     */
    private $plugin_slug;

    /**
     * Current plugin version.
     *
     * @var string
     */
    private $current_version;

    /**
     * Cached release info from GitHub API.
     *
     * @var object|null
     */
    private $github_response = null;

    /**
     * Constructor.
     *
     * @param string $plugin_file Full path to the main plugin file.
     * @param string $username    GitHub repository owner.
     * @param string $repository  GitHub repository name.
     */
    public function __construct($plugin_file, $username, $repository) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename($plugin_file);
        $this->username        = $username;
        $this->repository      = $repository;
        $this->current_version = CIR_VERSION;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'cir_check_update']);
        add_filter('plugins_api', [$this, 'cir_plugin_popup'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'cir_post_install'], 10, 3);
    }

    /**
     * Fetch latest release information from GitHub API.
     *
     * @return object|false
     */
    private function cir_get_latest_release() {
        if (!is_null($this->github_response)) {
            return $this->github_response;
        }

        $transient_key = 'cir_gh_latest_release';
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->username, $this->repository);

        $args = [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ];

        // Optional GitHub Personal Access Token if defined by user in wp-config.php
        if (defined('CIR_GITHUB_TOKEN') && CIR_GITHUB_TOKEN) {
            $args['headers']['Authorization'] = 'Bearer ' . CIR_GITHUB_TOKEN;
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (!empty($data) && !empty($data->tag_name)) {
            $this->github_response = $data;
            // Cache release data for 6 hours
            set_transient($transient_key, $data, 6 * HOUR_IN_SECONDS);
            return $data;
        }

        return false;
    }

    /**
     * Hook into WordPress plugin update check.
     *
     * @param object $transient
     * @return object
     */
    public function cir_check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->cir_get_latest_release();
        if (!$release) {
            return $transient;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        if (version_compare($this->current_version, $latest_version, '<')) {
            $package_url = '';

            // Check if there is an uploaded zip in release assets
            if (!empty($release->assets) && is_array($release->assets)) {
                foreach ($release->assets as $asset) {
                    if (isset($asset->name) && substr($asset->name, -4) === '.zip') {
                        $package_url = $asset->browser_download_url;
                        break;
                    }
                }
            }

            // Fallback to release zipball if no specific asset zip was attached
            if (empty($package_url)) {
                $package_url = $release->zipball_url;
            }

            $plugin_data = [
                'id'            => $this->plugin_slug,
                'slug'          => dirname($this->plugin_slug),
                'plugin'        => $this->plugin_slug,
                'new_version'   => $latest_version,
                'url'           => $release->html_url,
                'package'       => $package_url,
                'icons'         => [],
                'banners'       => [],
                'tested'        => get_bloginfo('version'),
                'requires_php'  => '7.4',
            ];

            $transient->response[$this->plugin_slug] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Populate View Details popup modal in WordPress admin.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return object|false
     */
    public function cir_plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $release = $this->cir_get_latest_release();
        if (!$release) {
            return $result;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        $package_url = '';
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (isset($asset->name) && substr($asset->name, -4) === '.zip') {
                    $package_url = $asset->browser_download_url;
                    break;
                }
            }
        }
        if (empty($package_url)) {
            $package_url = $release->zipball_url;
        }

        $res = new \stdClass();
        $res->name           = 'CF7 Inbox & Reply Manager';
        $res->slug           = dirname($this->plugin_slug);
        $res->version        = $latest_version;
        $res->author         = '<a href="https://github.com/' . esc_attr($this->username) . '">Aldino Said</a>';
        $res->homepage       = sprintf('https://github.com/%s/%s', $this->username, $this->repository);
        $res->download_link  = $package_url;
        $res->tested         = get_bloginfo('version');
        $res->requires_php   = '7.4';
        $res->last_updated   = $release->published_at ?? current_time('mysql');
        $res->sections       = [
            'description' => 'CF7 Inbox & Reply Manager captures Contact Form 7 submissions, provides an admin inbox with WP_List_Table, direct replies, and custom SMTP routing.',
            'changelog'   => !empty($release->body) ? nl2br(esc_html($release->body)) : 'Latest release from GitHub.',
        ];

        return $res;
    }

    /**
     * Ensure the extracted plugin directory matches the original plugin folder name.
     *
     * GitHub zipball downloads unpack into repository-tag (e.g. repo-v1.0.1).
     * This moves/renames it back to cf7-inbox-reply-manager so WordPress keeps it activated.
     *
     * @param bool  $true
     * @param array $hook_extra
     * @param array $result
     * @return array
     */
    public function cir_post_install($true, $hook_extra, $result) {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }

        $proper_destination = WP_PLUGIN_DIR . '/' . dirname($this->plugin_slug);

        // Move unpacked folder to proper destination
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination;

        // Re-activate plugin if it was active
        if (is_plugin_active($this->plugin_slug)) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
    }
}

