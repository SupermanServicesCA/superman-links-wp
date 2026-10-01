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

    /**
     * Ed25519 public key that every release zip must verify against (v2.4.0).
     *
     * The private half lives outside GitHub, on the machine that runs
     * scripts/release-signing/sign-release.mjs. So a leaked GitHub token or
     * account can publish a release, but no site will install it.
     * Rotating this key needs one release signed with the OLD key that ships
     * the new one. If the private key is lost, sites need a manual zip upload.
     */
    const RELEASE_PUBLIC_KEY = 'tclKTrxGHP+lViaKIrwiz87K56so+NpjopfNP9GEp2o=';
    const ZIP_ASSET = 'superman-links.zip';
    const SIG_ASSET = 'superman-links.zip.sig';

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
        add_filter('upgrader_pre_download', [$this, 'verify_signed_package'], 10, 4);

        // Admin-only force-check: ?superman_force_check=1 clears the 12h cache.
        // Useful when a GitHub release just shipped and you don't want to wait.
        add_action('admin_init', [$this, 'maybe_force_check']);
    }

    /**
     * Admin hook: when a staff user hits any admin page with
     * ?superman_force_check=1, drop the update transient so the next
     * `pre_set_site_transient_update_plugins` pass re-queries GitHub.
     */
    public function maybe_force_check() {
        if (!current_user_can('update_plugins')) {
            return;
        }
        // Presence test only — the value is never read. Kept as a bare GET on
        // purpose: ?superman_force_check=1 on any admin URL is the documented
        // support step, and requiring a nonce would break that URL.
        if (empty($_GET['superman_force_check'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recycled
            return;
        }
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        if (function_exists('wp_admin_notice')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>Superman Links: update cache flushed. Visit Plugins page to see the new version.</p></div>';
            });
        }
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
     *
     * Populates BOTH $transient->response (when an update is available) and
     * $transient->no_update (when current). WordPress 5.5+ requires the
     * plugin to appear in one of these for the auto-update toggle to render
     * on the Plugins screen — otherwise WP treats us as outside the update
     * system and hides the control.
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
        $download_url = $this->get_download_url($release);

        $item = (object) [
            'id' => 'github.com/' . $this->github_repo,
            'slug' => 'superman-links-wp',
            'plugin' => $this->plugin_slug,
            'new_version' => $latest_version,
            'url' => 'https://github.com/' . $this->github_repo,
            'package' => $download_url ?: '',
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.7',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        if (version_compare($latest_version, $current_version, '>') && $download_url) {
            $transient->response[$this->plugin_slug] = $item;
        } else {
            // Mark as current so the auto-update toggle is shown.
            $transient->no_update[$this->plugin_slug] = $item;
        }

        return $transient;
    }

    /**
     * The signed zip and its signature on a release, or null when either is
     * missing. Before v2.4.0 this fell back to GitHub's zipball; now a release
     * without both assets is not offered as an update at all.
     */
    private function get_signed_assets($release) {
        $zip = null;
        $sig = null;
        foreach (($release->assets ?? []) as $asset) {
            if (($asset->name ?? '') === self::ZIP_ASSET) {
                $zip = $asset->browser_download_url;
            } elseif (($asset->name ?? '') === self::SIG_ASSET) {
                $sig = $asset->browser_download_url;
            }
        }
        return ($zip && $sig) ? ['zip' => $zip, 'sig' => $sig] : null;
    }

    /**
     * Get the download URL from a release: the signed zip, or null.
     */
    private function get_download_url($release) {
        $assets = $this->get_signed_assets($release);
        return $assets ? $assets['zip'] : null;
    }

    /**
     * upgrader_pre_download: for OUR package only, download it, check the
     * Ed25519 signature, and hand WordPress the verified file. Any other
     * package passes through untouched. Fails closed: no signature, a bad
     * signature, or no sodium means the update is refused and the site keeps
     * the version it has.
     */
    public function verify_signed_package($reply, $package, $upgrader = null, $hook_extra = []) {
        if ($reply !== false || !is_string($package)) {
            return $reply;
        }
        $is_ours = (is_array($hook_extra) && ($hook_extra['plugin'] ?? '') === $this->plugin_slug)
            || strpos($package, 'github.com/' . $this->github_repo . '/') !== false
            || strpos($package, 'api.github.com/repos/' . $this->github_repo . '/') !== false;
        if (!$is_ours) {
            return $reply;
        }

        $release = $this->get_latest_release();
        $assets = $release ? $this->get_signed_assets($release) : null;
        if (!$assets || $package !== $assets['zip']) {
            return new WP_Error('superman_links_unsigned', 'Superman Links: this update has no valid signature, so it was not installed.');
        }

        $sig_response = wp_remote_get($assets['sig'], ['timeout' => 15]);
        if (is_wp_error($sig_response) || wp_remote_retrieve_response_code($sig_response) !== 200) {
            return new WP_Error('superman_links_sig_fetch', 'Superman Links: could not download the update signature, so the update was not installed.');
        }
        $signature = base64_decode(trim(wp_remote_retrieve_body($sig_response)), true);

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($package);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $data = file_get_contents($tmp);
        if ($data === false || !$this->signature_valid($data, $signature)) {
            @unlink($tmp);
            return new WP_Error('superman_links_bad_signature', 'Superman Links: the update signature does not match, so it was not installed.');
        }
        return $tmp;
    }

    /**
     * True only when $signature is a valid Ed25519 signature of $data under
     * RELEASE_PUBLIC_KEY (or $public_key_b64, for the test harness).
     */
    public function signature_valid($data, $signature, $public_key_b64 = null) {
        $public_key = base64_decode($public_key_b64 ?? self::RELEASE_PUBLIC_KEY, true);
        if (!is_string($signature) || strlen($signature) !== 64 || !is_string($public_key) || strlen($public_key) !== 32) {
            return false;
        }
        // WordPress 5.2+ ships sodium_compat for hosts without ext-sodium.
        if (!function_exists('sodium_crypto_sign_verify_detached') && defined('ABSPATH') && defined('WPINC')
            && file_exists(ABSPATH . WPINC . '/sodium_compat/autoload.php')) {
            require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $data, $public_key);
        } catch (\Throwable $e) {
            return false;
        }
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
        // The signed zip (v2.4.0+) already unpacks to superman-links-wp/, so
        // only move when the upgrader put it somewhere else (old zipballs).
        if (untrailingslashit($result['destination']) !== untrailingslashit($plugin_dir)) {
            $wp_filesystem->move($result['destination'], $plugin_dir);
        }
        $result['destination'] = $plugin_dir;

        // Re-activate plugin if it was active
        if (is_plugin_active($this->plugin_slug)) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
    }
}
