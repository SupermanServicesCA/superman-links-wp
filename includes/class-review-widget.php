<?php
/**
 * Review Widget - REST endpoint, shortcode, and rendering for Google Reviews carousel
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Review_Widget {

    private $namespace = 'superman-links/v1';
    private $option_key = 'superman_links_reviews_data';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_shortcode('superman_reviews', [$this, 'render_shortcode']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/reviews', [
            'methods' => 'POST',
            'callback' => [$this, 'store_reviews'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);
    }

    /**
     * Validate API key (same pattern as class-api.php)
     */
    public function check_api_key($request) {
        $provided_key = $request->get_header('X-Superman-Links-Key');
        $stored_key = get_option('superman_links_api_key', '');

        if (empty($stored_key)) {
            return new WP_Error(
                'missing_api_key',
                __('API key not configured. Please set up the plugin.', 'superman-links'),
                ['status' => 500]
            );
        }

        if (empty($provided_key) || !hash_equals($stored_key, $provided_key)) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid API key.', 'superman-links'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Store reviews data from CRM
     */
    public function store_reviews($request) {
        $body = $request->get_json_params();

        $data = [
            'reviews' => isset($body['reviews']) ? $body['reviews'] : [],
            'summary' => isset($body['summary']) ? $body['summary'] : [],
            'config'  => isset($body['config']) ? $body['config'] : [],
            'updated_at' => current_time('mysql'),
        ];

        update_option($this->option_key, $data, false);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Reviews data stored successfully.',
            'review_count' => count($data['reviews']),
        ], 200);
    }

    /**
     * Render the [superman_reviews] shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'max' => 0,
        ], $atts, 'superman_reviews');

        $data = get_option($this->option_key, null);

        if (empty($data) || empty($data['reviews'])) {
            return '';
        }

        // Enqueue assets only when shortcode is present
        wp_enqueue_style(
            'superman-review-widget',
            plugins_url('assets/review-widget.css', dirname(__FILE__)),
            [],
            SUPERMAN_LINKS_VERSION
        );
        wp_enqueue_script(
            'superman-review-widget',
            plugins_url('assets/review-widget.js', dirname(__FILE__)),
            [],
            SUPERMAN_LINKS_VERSION,
            true
        );

        $reviews = $data['reviews'];
        $summary = isset($data['summary']) ? $data['summary'] : [];
        $config  = isset($data['config']) ? $data['config'] : [];

        // Apply max limit
        $max = intval($atts['max']);
        if ($max > 0) {
            $reviews = array_slice($reviews, 0, $max);
        }

        // Config defaults
        $card_bg_color = isset($config['card_bg_color']) ? $config['card_bg_color'] : '#1e3a5f';
        $text_color    = isset($config['text_color']) ? $config['text_color'] : '#ffffff';

        // Summary defaults
        $average_rating    = isset($summary['average_rating']) ? floatval($summary['average_rating']) : 5.0;
        $total_review_count = isset($summary['total_review_count']) ? intval($summary['total_review_count']) : count($reviews);
        $rating_label      = isset($summary['rating_label']) ? $summary['rating_label'] : 'Excellent';

        ob_start();
        ?>
        <div class="superman-reviews-widget">
            <div class="srw-header">
                <div class="srw-rating-label"><?php echo esc_html($rating_label); ?></div>
                <div class="srw-stars-header"><?php echo $this->render_stars($average_rating, 'srw-star-header'); ?></div>
                <div class="srw-summary">Based on <strong><?php echo esc_html($total_review_count); ?></strong> reviews</div>
                <div class="srw-google-logo"><?php echo $this->get_google_wordmark_svg(); ?></div>
            </div>
            <div class="srw-carousel" role="region" aria-label="Customer Reviews">
                <button class="srw-arrow srw-arrow-left" aria-label="Previous reviews" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
                <div class="srw-track">
                    <?php foreach ($reviews as $review) : ?>
                        <?php echo $this->render_review_card($review, $card_bg_color, $text_color); ?>
                    <?php endforeach; ?>
                </div>
                <button class="srw-arrow srw-arrow-right" aria-label="Next reviews" type="button">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single review card
     */
    private function render_review_card($review, $card_bg_color, $text_color) {
        $name       = isset($review['reviewer_name']) ? $review['reviewer_name'] : 'Anonymous';
        $photo_url  = isset($review['reviewer_photo_url']) ? $review['reviewer_photo_url'] : '';
        $comment    = isset($review['comment']) ? $review['comment'] : '';
        $rating     = isset($review['star_rating']) ? intval($review['star_rating']) : 5;
        $create_time = isset($review['create_time']) ? $review['create_time'] : '';

        $relative_time = $this->get_relative_time($create_time);

        ob_start();
        ?>
        <div class="srw-card" style="background-color: <?php echo esc_attr($card_bg_color); ?>; color: <?php echo esc_attr($text_color); ?>;">
            <div class="srw-card-top">
                <div class="srw-avatar-wrap">
                    <?php if (!empty($photo_url)) : ?>
                        <img class="srw-avatar" src="<?php echo esc_url($photo_url); ?>" alt="" loading="lazy" />
                    <?php else : ?>
                        <div class="srw-avatar-fallback" style="background: <?php echo esc_attr($this->get_avatar_color($name)); ?>"><?php echo esc_html(mb_strtoupper(mb_substr($name, 0, 1))); ?></div>
                    <?php endif; ?>
                </div>
                <div class="srw-reviewer-info">
                    <span class="srw-name"><?php echo esc_html($name); ?></span>
                    <span class="srw-time" data-time="<?php echo esc_attr($create_time); ?>"><?php echo esc_html($relative_time); ?></span>
                </div>
                <div class="srw-google-icon"><?php echo $this->get_google_g_svg(); ?></div>
            </div>
            <div class="srw-card-rating">
                <?php echo $this->render_stars($rating, 'srw-star-card'); ?>
                <span class="srw-verified">&#10003;</span>
            </div>
            <div class="srw-card-comment">
                <?php if (mb_strlen($comment) > 150) : ?>
                    <span class="srw-comment-short"><?php echo esc_html(mb_substr($comment, 0, 150)); ?>&hellip;</span>
                    <span class="srw-comment-full" style="display:none"><?php echo esc_html($comment); ?></span>
                    <button class="srw-read-more" type="button">Read more</button>
                <?php else : ?>
                    <span><?php echo esc_html($comment); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render star icons (full, half, empty)
     */
    private function render_stars($rating, $class = '') {
        $output = '';
        $full = floor($rating);
        $half = ($rating - $full) >= 0.25 && ($rating - $full) < 0.75 ? 1 : 0;
        $extra_full = ($rating - $full) >= 0.75 ? 1 : 0;
        $full += $extra_full;
        if ($full > 5) $full = 5;
        $empty = 5 - $full - $half;

        for ($i = 0; $i < $full; $i++) {
            $output .= '<svg class="' . esc_attr($class) . ' srw-star-full" width="20" height="20" viewBox="0 0 24 24" fill="#f9a825" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        }
        if ($half) {
            $output .= '<svg class="' . esc_attr($class) . ' srw-star-half" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="none"><defs><linearGradient id="srw-half-grad"><stop offset="50%" stop-color="#f9a825"/><stop offset="50%" stop-color="#e0e0e0"/></linearGradient></defs><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="url(#srw-half-grad)"/></svg>';
        }
        for ($i = 0; $i < $empty; $i++) {
            $output .= '<svg class="' . esc_attr($class) . ' srw-star-empty" width="20" height="20" viewBox="0 0 24 24" fill="#e0e0e0" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
        }

        return $output;
    }

    /**
     * Compute relative time from a date string
     */
    private function get_relative_time($date_string) {
        if (empty($date_string)) {
            return '';
        }

        $timestamp = strtotime($date_string);
        if ($timestamp === false) {
            return '';
        }

        $diff = time() - $timestamp;
        if ($diff < 0) $diff = 0;

        if ($diff < 60) return 'just now';
        if ($diff < 3600) {
            $m = floor($diff / 60);
            return $m . ($m === 1 ? ' minute ago' : ' minutes ago');
        }
        if ($diff < 86400) {
            $h = floor($diff / 3600);
            return $h . ($h === 1 ? ' hour ago' : ' hours ago');
        }
        if ($diff < 2592000) {
            $d = floor($diff / 86400);
            return $d . ($d === 1 ? ' day ago' : ' days ago');
        }
        if ($diff < 31536000) {
            $mo = floor($diff / 2592000);
            return $mo . ($mo === 1 ? ' month ago' : ' months ago');
        }
        $y = floor($diff / 31536000);
        return $y . ($y === 1 ? ' year ago' : ' years ago');
    }

    /**
     * Deterministic avatar color from name
     */
    private function get_avatar_color($name) {
        $colors = [
            '#e53935', '#d81b60', '#8e24aa', '#5e35b1',
            '#3949ab', '#1e88e5', '#00897b', '#43a047',
            '#f4511e', '#6d4c41',
        ];
        $hash = crc32($name);
        $index = abs($hash) % count($colors);
        return $colors[$index];
    }

    /**
     * Google "G" icon SVG
     */
    private function get_google_g_svg() {
        return '<svg width="24" height="24" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#34A853" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#FBBC05" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    }

    /**
     * Google wordmark SVG
     */
    private function get_google_wordmark_svg() {
        return '<svg width="80" height="26" viewBox="0 0 272 92"><path fill="#EA4335" d="M115.75 47.18c0 12.77-9.99 22.18-22.25 22.18s-22.25-9.41-22.25-22.18C71.25 34.32 81.24 25 93.5 25s22.25 9.32 22.25 22.18zm-9.74 0c0-7.98-5.79-13.44-12.51-13.44S80.99 39.2 80.99 47.18c0 7.9 5.79 13.44 12.51 13.44s12.51-5.55 12.51-13.44z"/><path fill="#FBBC05" d="M163.75 47.18c0 12.77-9.99 22.18-22.25 22.18s-22.25-9.41-22.25-22.18c0-12.85 9.99-22.18 22.25-22.18s22.25 9.32 22.25 22.18zm-9.74 0c0-7.98-5.79-13.44-12.51-13.44s-12.51 5.46-12.51 13.44c0 7.9 5.79 13.44 12.51 13.44s12.51-5.55 12.51-13.44z"/><path fill="#4285F4" d="M209.75 26.34v39.82c0 16.38-9.66 23.07-21.08 23.07-10.75 0-17.22-7.19-19.66-13.07l8.48-3.53c1.51 3.61 5.21 7.87 11.17 7.87 7.31 0 11.84-4.51 11.84-13v-3.19h-.34c-2.18 2.69-6.38 5.04-11.68 5.04-11.09 0-21.25-9.66-21.25-22.09 0-12.52 10.16-22.26 21.25-22.26 5.29 0 9.49 2.35 11.68 4.96h.34v-3.61h9.25zm-8.56 20.92c0-7.81-5.21-13.52-11.84-13.52-6.72 0-12.35 5.71-12.35 13.52 0 7.73 5.63 13.36 12.35 13.36 6.63 0 11.84-5.63 11.84-13.36z"/><path fill="#34A853" d="M225 3v65h-9.5V3h9.5z"/><path fill="#EA4335" d="M262.02 54.48l7.56 5.04c-2.44 3.61-8.32 9.83-18.48 9.83-12.6 0-22.01-9.74-22.01-22.18 0-13.19 9.49-22.18 20.92-22.18 11.51 0 17.14 9.16 18.98 14.11l1.01 2.52-29.65 12.28c2.27 4.45 5.8 6.72 10.75 6.72 4.96 0 8.4-2.44 10.92-6.14zm-23.27-7.98l19.82-8.23c-1.09-2.77-4.37-4.7-8.23-4.7-4.95 0-11.84 4.37-11.59 12.93z"/><path fill="#4285F4" d="M35.29 41.41V32H67c.31 1.64.47 3.58.47 5.68 0 7.06-1.93 15.79-8.15 22.01-6.05 6.3-13.78 9.66-24.02 9.66C16.32 69.35.36 53.89.36 34.91.36 15.93 16.32.47 35.3.47c10.5 0 17.98 4.12 23.6 9.49l-6.64 6.64c-4.03-3.78-9.49-6.72-16.97-6.72-13.86 0-24.7 11.17-24.7 25.03 0 13.86 10.84 25.03 24.7 25.03 8.99 0 14.11-3.61 17.39-6.89 2.66-2.66 4.41-6.46 5.1-11.65l-22.49.01z"/></svg>';
    }
}
