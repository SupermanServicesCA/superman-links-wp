<?php
/**
 * Webhook handler for Superman Links plugin
 * Sends updates to CRM when posts are saved
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Webhook {

    // Hardcoded webhook URL for Superman Links CRM
    private $webhook_url = 'https://wirntskjuuvqkvqbskmb.supabase.co/functions/v1/wordpress-webhook';

    // Supabase anon key for authentication
    private $supabase_anon_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Indpcm50c2tqdXV2cWt2cWJza21iIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjQ5NzE3OTgsImV4cCI6MjA4MDU0Nzc5OH0.xDdIyVs3mM82cvc01uFWxsV5J-AIt8ZNGJE7XmXgCBQ';

    public function __construct() {
        // Hook into post save
        add_action('save_post', [$this, 'on_post_save'], 20, 3);

        // Hook into post delete/trash
        add_action('wp_trash_post', [$this, 'on_post_delete'], 10, 1);
        add_action('before_delete_post', [$this, 'on_post_delete'], 10, 1);
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

        if (empty($api_key)) {
            return;
        }

        // Get focus keyword
        $focus_keyword = $this->get_focus_keyword($post_id);

        // Build payload
        $payload = [
            'action' => $action,
            'site_url' => get_site_url(),
            'api_key' => $api_key,
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
