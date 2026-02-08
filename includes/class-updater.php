<?php
/**
 * GitHub-based auto-updater for Superman Links plugin
 *
 * Checks GitHub releases for new versions and enables
 * one-click updates from the WordPress dashboard.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $github_repo;
    private $github_api_url;
    private $cache_key = 'superman_links_update_check';
    private $cache_ttl = 43200; // 12 hours

    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->github_repo = 'SupermanServicesCA/superman-links-wp';
        $this->github_api_url = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    /**
     * Fetch the latest release info from GitHub
     */
    private function get_latest_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($this->github_api_url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Superman-Links-WordPress-Plugin',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response));

        if (empty($release) || empty($release->tag_name)) {
            return null;
        }

        set_transient($this->cache_key, $release, $this->cache_ttl);

        return $release;
    }

    /**
     * Check if an update is available
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        // Strip 'v' prefix from tag (e.g., v1.3.0 -> 1.3.0)
        $latest_version = ltrim($release->tag_name, 'v');
        $current_version = SUPERMAN_LINKS_VERSION;

        if (version_compare($latest_version, $current_version, '>')) {
            $download_url = $this->get_download_url($release);

            if ($download_url) {
                $transient->response[$this->plugin_slug] = (object) [
                    'slug' => 'superman-links-wp',
                    'plugin' => $this->plugin_slug,
                    'new_version' => $latest_version,
                    'url' => 'https://github.com/' . $this->github_repo,
                    'package' => $download_url,
                ];
            }
        }

        return $transient;
    }

    /**
     * Get the download URL from a release
     * Prefers a .zip asset named superman-links.zip, falls back to source zip
     */
    private function get_download_url($release) {
        // Check for an attached zip asset first
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fall back to GitHub's auto-generated source zip
        return $release->zipball_url ?? null;
    }

    /**
     * Provide plugin info for the WordPress plugin details modal
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== 'superman-links-wp') {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $latest_version = ltrim($release->tag_name, 'v');
        $plugin_data = get_plugin_data($this->plugin_file);

        return (object) [
            'name' => $plugin_data['Name'],
            'slug' => 'superman-links-wp',
            'version' => $latest_version,
            'author' => $plugin_data['Author'],
            'homepage' => 'https://github.com/' . $this->github_repo,
            'requires' => '5.0',
            'tested' => '6.7',
            'requires_php' => '7.4',
            'download_link' => $this->get_download_url($release),
            'sections' => [
                'description' => $plugin_data['Description'],
                'changelog' => $this->format_changelog($release),
            ],
            'last_updated' => $release->published_at ?? '',
        ];
    }

    /**
     * Format release notes as changelog HTML
     */
    private function format_changelog($release) {
        $body = $release->body ?? 'No release notes.';
        // Convert markdown-style lists to HTML
        $body = esc_html($body);
        $body = preg_replace('/^\*\s+(.+)$/m', '<li>$1</li>', $body);
        $body = preg_replace('/^-\s+(.+)$/m', '<li>$1</li>', $body);
        if (strpos($body, '<li>') !== false) {
            $body = '<ul>' . $body . '</ul>';
        }
        $body = nl2br($body);

        $version = ltrim($release->tag_name, 'v');
        return '<h4>' . esc_html($version) . '</h4>' . $body;
    }

    /**
     * After install, rename the extracted folder to match the expected plugin directory
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Only handle our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/superman-links-wp/';
        $wp_filesystem->move($result['destination'], $plugin_dir);
        $result['destination'] = $plugin_dir;

        // Re-activate plugin if it was active
        if (is_plugin_active($this->plugin_slug)) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
    }
}
