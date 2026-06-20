<?php
/**
 * Webhook handler for Superman Links plugin
 * Sends updates to CRM when posts are saved
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Webhook {

    private $webhook_url;
    private $supabase_anon_key;

    public function __construct() {
        $this->webhook_url = get_option('superman_links_webhook_url', '');
        $this->supabase_anon_key = get_option('superman_links_supabase_key', '');
        // Hook into post save
        add_action('save_post', [$this, 'on_post_save'], 20, 3);

        // Hook into post delete/trash
        add_action('wp_trash_post', [$this, 'on_post_delete'], 10, 1);
        add_action('before_delete_post', [$this, 'on_post_delete'], 10, 1);

        // Notify CRM when our plugin is updated
        add_action('upgrader_process_complete', [$this, 'on_plugin_updated'], 10, 2);

        // Self-heal: notify CRM when the API key is regenerated/changed so the
        // CRM resyncs the key instead of silently 401ing every sync.
        add_action('update_option_superman_links_api_key', [$this, 'on_api_key_changed'], 10, 2);
    }

    /**
     * When the plugin API key changes (Regenerate button or manual edit), tell
     * the CRM with {old_key, new_key}. The CRM authenticates the rotation by the
     * OLD key (same trust bar as any sync call) and updates its stored key — so
     * a regenerated key no longer silently breaks the sync.
     */
    public function on_api_key_changed($old_value, $new_value) {
        // Initial set (empty -> key) is handled by the normal connection flow;
        // only a genuine rotation (an old key existed and changed) needs resync.
        if (empty($old_value) || $old_value === $new_value) {
            return;
        }
        if (empty($this->webhook_url) || empty($this->supabase_anon_key)) {
            return;
        }

        $payload = [
            'action' => 'key_rotated',
            'site_url' => get_site_url(),
            'api_key' => $new_value, // NEW key
            'old_key' => $old_value, // previous key — authenticates the rotation
            'plugin_version' => defined('SUPERMAN_LINKS_VERSION') ? SUPERMAN_LINKS_VERSION : null,
        ];

        wp_remote_post($this->webhook_url, [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
            ],
            'timeout' => 10,
            'blocking' => false,
            'sslverify' => true,
        ]);
    }

    /**
     * Tell the CRM a FRESH API key was just minted (e.g. after a host migration
     * or restore reset the option on a previously-connected site). Called inline
     * from the activation hook, where this class isn't instantiated yet, so it's
     * static and re-reads config from options (set moments earlier in activation).
     * Fire-and-forget; the CRM stages it as a pending key for human adoption and
     * never auto-trusts it. Distinct from on_api_key_changed() (a rotation of an
     * EXISTING key, authenticated by the old key) — a fresh mint has no old key.
     */
    public static function notify_key_minted($new_key) {
        $webhook_url  = get_option('superman_links_webhook_url', '');
        $supabase_key = get_option('superman_links_supabase_key', '');
        if (empty($webhook_url) || empty($supabase_key) || empty($new_key)) {
            return;
        }

        wp_remote_post($webhook_url, [
            'body' => wp_json_encode([
                'action' => 'key_minted',
                'site_url' => get_site_url(),
                'api_key' => $new_key,
                'plugin_version' => defined('SUPERMAN_LINKS_VERSION') ? SUPERMAN_LINKS_VERSION : null,
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $supabase_key,
            ],
            'timeout' => 10,
            'blocking' => false,
            'sslverify' => true,
        ]);
    }

    /**
     * Handle post save
     */
    public function on_post_save($post_id, $post, $update) {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Skip auto-drafts
        if ($post->post_status === 'auto-draft') {
            return;
        }

        // Only process published posts
        if ($post->post_status !== 'publish') {
            return;
        }

        // Only process standard post types
        $allowed_types = apply_filters('superman_links_webhook_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $allowed_types)) {
            return;
        }

        // Send the webhook
        $this->send_webhook('post_updated', $post_id, $post);
    }

    /**
     * Handle post deletion
     */
    public function on_post_delete($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Only process standard post types
        $allowed_types = apply_filters('superman_links_webhook_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $allowed_types)) {
            return;
        }

        // Send the webhook
        $this->send_webhook('post_deleted', $post_id, $post);
    }

    /**
     * Send webhook to CRM
     */
    private function send_webhook($action, $post_id, $post) {
        $api_key = get_option('superman_links_api_key', '');

        if (empty($api_key) || empty($this->webhook_url) || empty($this->supabase_anon_key)) {
            return;
        }

        // Get focus keyword
        $focus_keyword = $this->get_focus_keyword($post_id);

        // Build payload
        $payload = [
            'action' => $action,
            'site_url' => get_site_url(),
            'api_key' => $api_key,
            'plugin_version' => defined('SUPERMAN_LINKS_VERSION') ? SUPERMAN_LINKS_VERSION : null,
            'post' => [
                'id' => $post_id,
                'url' => get_permalink($post_id),
                'title' => $post->post_title,
                'focus_keyword' => $focus_keyword,
                'modified_at' => $post->post_modified_gmt,
            ],
        ];

        // Send request to CRM
        $args = [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
            ],
            'timeout' => 10,
            'blocking' => true, // Blocking to capture response for debugging
            'sslverify' => true,
        ];

        error_log('Superman Links Webhook: Sending to ' . $this->webhook_url);
        error_log('Superman Links Webhook: Payload - ' . wp_json_encode($payload));

        $response = wp_remote_post($this->webhook_url, $args);

        // Log response for debugging
        if (is_wp_error($response)) {
            error_log('Superman Links Webhook Error: ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            error_log('Superman Links Webhook Response: ' . $response_code . ' - ' . $response_body);
        }
    }

    /**
     * When our plugin is updated, notify the CRM of the new version.
     */
    public function on_plugin_updated($upgrader, $options) {
        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        // Check if our plugin was in the updated list
        $our_plugin = 'superman-links/superman-links.php';
        $plugins = isset($options['plugins']) ? $options['plugins'] : [];
        if (!is_array($plugins) || !in_array($our_plugin, $plugins)) {
            return;
        }

        $api_key = get_option('superman_links_api_key', '');
        if (empty($api_key) || empty($this->webhook_url) || empty($this->supabase_anon_key)) {
            return;
        }

        // Re-read the version constant (it may have been updated by the upgrader)
        // Since PHP already loaded the old constant, read it from the file directly
        $plugin_file = WP_PLUGIN_DIR . '/' . $our_plugin;
        $plugin_data = get_plugin_data($plugin_file, false, false);
        $new_version = isset($plugin_data['Version']) ? $plugin_data['Version'] : SUPERMAN_LINKS_VERSION;

        $payload = [
            'action' => 'plugin_version',
            'site_url' => get_site_url(),
            'api_key' => $api_key,
            'plugin_version' => $new_version,
        ];

        wp_remote_post($this->webhook_url, [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->supabase_anon_key,
            ],
            'timeout' => 10,
            'blocking' => false,
            'sslverify' => true,
        ]);
    }

    /**
     * Get focus keyword from RankMath or Yoast
     */
    private function get_focus_keyword($post_id) {
        // Try RankMath first
        $focus_keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);

        // Fall back to Yoast
        if (empty($focus_keyword)) {
            $focus_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        }

        // RankMath stores multiple keywords comma-separated, get the first one
        if (!empty($focus_keyword) && strpos($focus_keyword, ',') !== false) {
            $keywords = array_map('trim', explode(',', $focus_keyword));
            $focus_keyword = $keywords[0];
        }

        return $focus_keyword ?: null;
    }
}
