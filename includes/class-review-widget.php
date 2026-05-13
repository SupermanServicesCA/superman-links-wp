<?php
/**
 * Review Widget - REST endpoint, shortcode, and rendering for Google Reviews carousel
 * v1.11.0: card-style (border/shadow), orientation (horizontal/vertical), accent color,
 *          autoplay, avatars, theme color pull-in, vertical max via shortcode attr.
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

    public function register_routes() {
        register_rest_route($this->namespace, '/reviews', [
            'methods' => 'POST',
            'callback' => [$this, 'store_reviews'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);
    }

    public function check_api_key($request) {
        $provided_key = $request->get_header('X-Superman-Links-Key');
        $stored_key = get_option('superman_links_api_key', '');

        if (empty($stored_key)) {
            return new WP_Error('missing_api_key', 'API key not configured.', ['status' => 500]);
        }
        if (empty($provided_key) || !hash_equals($stored_key, $provided_key)) {
            return new WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 401]);
        }
        return true;
    }

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

    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'max' => 0,
            'orientation' => '', // override: 'horizontal' | 'vertical'
        ], $atts, 'superman_reviews');

        $data = get_option($this->option_key, null);

        if (empty($data) || empty($data['reviews'])) {
            return '';
        }

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

        // Plus Jakarta Sans (matches the design system). Loaded once per page.
        wp_enqueue_style(
            'superman-review-widget-font',
            'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap',
            [],
            null
        );

        $reviews = $data['reviews'];
        $summary = isset($data['summary']) ? $data['summary'] : [];
        $config  = isset($data['config']) ? $data['config'] : [];

        // v2 config keys with legacy fallback
        $header_bg = !empty($config['header_bg_color']) ? $config['header_bg_color']
                    : (!empty($config['card_bg_color']) ? $config['card_bg_color'] : '#1a2b7c');
        $accent    = !empty($config['accent_color']) ? $config['accent_color']
                    : (!empty($config['star_color']) ? $config['star_color'] : '#e87010');
        $text_on_header = !empty($config['text_color']) ? $config['text_color'] : '#ffffff';

        $card_style  = (isset($config['card_style']) && in_array($config['card_style'], ['border', 'shadow'], true))
                       ? $config['card_style'] : 'shadow';

        // Orientation: shortcode attribute wins over saved config
        $orientation = !empty($atts['orientation']) && in_array($atts['orientation'], ['horizontal', 'vertical'], true)
                       ? $atts['orientation']
                       : ((isset($config['orientation']) && in_array($config['orientation'], ['horizontal', 'vertical'], true))
                          ? $config['orientation'] : 'horizontal');

        $autoplay     = isset($config['autoplay']) ? (bool)$config['autoplay'] : true;
        $show_avatars = isset($config['show_avatars']) ? (bool)$config['show_avatars'] : true;

        // Apply max: shortcode wins; in vertical mode, fall back to vertical_max_reviews from config
        $max = intval($atts['max']);
        if ($max <= 0 && $orientation === 'vertical' && !empty($config['vertical_max_reviews'])) {
            $max = intval($config['vertical_max_reviews']);
        }
        if ($max > 0) {
            $reviews = array_slice($reviews, 0, $max);
        }

        // Summary
        $average_rating     = isset($summary['average_rating']) ? floatval($summary['average_rating']) : 5.0;
        $total_review_count = isset($summary['total_review_count']) ? intval($summary['total_review_count']) : count($reviews);
        $rating_label       = isset($summary['rating_label']) ? $summary['rating_label'] : 'Excellent';
        $google_review_url  = isset($summary['google_review_url']) ? $summary['google_review_url'] : '';

        // Unique instance ID so multiple shortcodes on one page don't clash
        static $instance = 0;
        $instance++;
        $wid = 'srw-' . $instance;

        $orientation_attr = esc_attr($orientation);
        $card_style_attr  = esc_attr($card_style);

        // Per-instance CSS variables (scoped to this widget root only)
        $inline_css = sprintf(
            '#%1$s{--srw-header-bg:%2$s;--srw-accent:%3$s;--srw-text-on-header:%4$s;}',
            $wid,
            esc_attr($header_bg),
            esc_attr($accent),
            esc_attr($text_on_header)
        );

        ob_start();
        ?>
        <style><?php echo $inline_css; ?></style>
        <div id="<?php echo esc_attr($wid); ?>"
             class="superman-reviews-widget"
             data-orientation="<?php echo $orientation_attr; ?>"
             data-card-style="<?php echo $card_style_attr; ?>"
             data-autoplay="<?php echo ($autoplay && $orientation === 'horizontal') ? '1' : '0'; ?>"
             data-show-avatars="<?php echo $show_avatars ? '1' : '0'; ?>">

            <div class="srw-header">
                <div class="srw-header-left">
                    <div class="srw-eyebrow">Customer Reviews</div>
                    <div class="srw-rating-row">
                        <?php echo $this->render_stars($average_rating, 'srw-star-hdr', 22); ?>
                        <span class="srw-rating-num"><?php echo esc_html(number_format($average_rating, 1)); ?></span>
                        <span class="srw-rating-out">/ 5.0</span>
                    </div>
                    <div class="srw-summary">
                        <?php echo esc_html($rating_label); ?> &nbsp;&middot;&nbsp; Based on
                        <strong><?php echo esc_html(number_format($total_review_count)); ?></strong>
                        verified reviews
                    </div>
                </div>

                <?php if (!empty($google_review_url)) : ?>
                <a class="srw-google-cta"
                   href="<?php echo esc_url($google_review_url); ?>"
                   target="_blank"
                   rel="noopener noreferrer">
                    <?php echo $this->get_google_g_svg(20); ?>
                    <div class="srw-google-cta-text">
                        <div class="srw-google-cta-lead">View all</div>
                        <div class="srw-google-cta-main">
                            <span><?php echo esc_html(number_format($total_review_count)); ?> reviews on</span>
                            <?php echo $this->get_google_wordmark_inline(); ?>
                        </div>
                    </div>
                    <span class="srw-google-cta-arrow">&rarr;</span>
                </a>
                <?php endif; ?>
            </div>

            <div class="srw-body" role="region" aria-label="Customer Reviews">
                <div class="srw-track">
                    <?php foreach ($reviews as $i => $review) : ?>
                        <?php echo $this->render_review_card($review, $i); ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($orientation === 'horizontal') : ?>
                <div class="srw-pagination">
                    <button class="srw-nav srw-nav-prev" aria-label="Previous" type="button">&lsaquo;</button>
                    <div class="srw-dots" aria-hidden="true"></div>
                    <button class="srw-nav srw-nav-next" aria-label="Next" type="button">&rsaquo;</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_review_card($review, $index = 0) {
        $name        = isset($review['reviewer_name']) ? $review['reviewer_name'] : 'Anonymous';
        $photo_url   = isset($review['reviewer_photo_url']) ? $review['reviewer_photo_url'] : '';
        $comment     = isset($review['comment']) ? (string)$review['comment'] : '';
        $rating      = isset($review['star_rating']) ? intval($review['star_rating']) : 5;
        $create_time = isset($review['create_time']) ? $review['create_time'] : '';

        $relative_time = $this->get_relative_time($create_time);
        $initials = $this->get_initials($name);
        $avatar_bg = $this->get_avatar_color($name);

        $is_long = mb_strlen($comment) > 160;
        $short = $is_long ? rtrim(mb_substr($comment, 0, 160)) . '&hellip;' : '';

        ob_start();
        ?>
        <div class="srw-card" style="--srw-card-anim-delay: <?php echo esc_attr(($index % 3) * 0.06); ?>s;">
            <div class="srw-card-head">
                <div class="srw-avatar-wrap">
                    <?php if (!empty($photo_url)) : ?>
                        <img class="srw-avatar" src="<?php echo esc_url($photo_url); ?>" alt="" loading="lazy" />
                    <?php else : ?>
                        <div class="srw-avatar-fallback" style="background: <?php echo esc_attr($avatar_bg); ?>">
                            <?php echo esc_html($initials); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="srw-reviewer-info">
                    <div class="srw-name"><?php echo esc_html($name); ?></div>
                    <div class="srw-time" data-time="<?php echo esc_attr($create_time); ?>"><?php echo esc_html($relative_time); ?></div>
                </div>
                <div class="srw-google-icon"><?php echo $this->get_google_g_svg(20); ?></div>
            </div>

            <div class="srw-card-stars">
                <?php echo $this->render_stars($rating, 'srw-star-card', 15); ?>
            </div>

            <p class="srw-card-comment">
                <?php if ($is_long) : ?>
                    <span class="srw-comment-short"><?php echo esc_html($short); ?></span>
                    <span class="srw-comment-full" hidden><?php echo esc_html($comment); ?></span>
                    <button class="srw-read-more" type="button" data-more="More" data-less="Less">More</button>
                <?php else : ?>
                    <span><?php echo esc_html($comment); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_stars($rating, $class = '', $size = 16) {
        $output = '';
        $rating = max(0, min(5, floatval($rating)));
        $full = floor($rating);
        $half = ($rating - $full) >= 0.25 && ($rating - $full) < 0.75 ? 1 : 0;
        $extra_full = ($rating - $full) >= 0.75 ? 1 : 0;
        $full += $extra_full;
        if ($full > 5) $full = 5;
        $empty = 5 - $full - $half;

        $path = 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z';

        for ($i = 0; $i < $full; $i++) {
            $output .= sprintf(
                '<svg class="%1$s srw-star-full" width="%2$d" height="%2$d" viewBox="0 0 24 24"><path d="%3$s" fill="var(--srw-accent)"/></svg>',
                esc_attr($class), intval($size), $path
            );
        }
        if ($half) {
            $gid = 'srw-half-' . wp_unique_id();
            $output .= sprintf(
                '<svg class="%1$s srw-star-half" width="%2$d" height="%2$d" viewBox="0 0 24 24">
                  <defs><linearGradient id="%4$s"><stop offset="50%%" stop-color="var(--srw-accent)"/><stop offset="50%%" stop-color="#dde0ec"/></linearGradient></defs>
                  <path d="%3$s" fill="url(#%4$s)"/>
                </svg>',
                esc_attr($class), intval($size), $path, esc_attr($gid)
            );
        }
        for ($i = 0; $i < $empty; $i++) {
            $output .= sprintf(
                '<svg class="%1$s srw-star-empty" width="%2$d" height="%2$d" viewBox="0 0 24 24"><path d="%3$s" fill="#dde0ec"/></svg>',
                esc_attr($class), intval($size), $path
            );
        }
        return $output;
    }

    private function get_relative_time($date_string) {
        if (empty($date_string)) return '';
        $timestamp = strtotime($date_string);
        if ($timestamp === false) return '';

        $diff = max(0, time() - $timestamp);

        if ($diff < 60) return 'just now';
        if ($diff < 3600) { $m = floor($diff / 60); return $m . ($m === 1 ? ' minute ago' : ' minutes ago'); }
        if ($diff < 86400) { $h = floor($diff / 3600); return $h . ($h === 1 ? ' hour ago' : ' hours ago'); }
        if ($diff < 2592000) { $d = floor($diff / 86400); return $d . ($d === 1 ? ' day ago' : ' days ago'); }
        if ($diff < 31536000) { $mo = floor($diff / 2592000); return $mo . ($mo === 1 ? ' month ago' : ' months ago'); }
        $y = floor($diff / 31536000);
        return $y . ($y === 1 ? ' year ago' : ' years ago');
    }

    private function get_initials($name) {
        $name = trim($name);
        if (empty($name)) return '?';
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2));
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }

    private function get_avatar_color($name) {
        $colors = ['#e87010', '#2563eb', '#0284c7', '#6d28d9', '#059669', '#d81b60', '#0d9488', '#7c3aed'];
        $hash = crc32($name);
        return $colors[abs($hash) % count($colors)];
    }

    private function get_google_g_svg($size = 20) {
        return '<svg width="' . intval($size) . '" height="' . intval($size) . '" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
    }

    private function get_google_wordmark_inline() {
        return '<span class="srw-google-word">'
            . '<span style="color:#4285f4">G</span>'
            . '<span style="color:#ea4335">o</span>'
            . '<span style="color:#fbbc04">o</span>'
            . '<span style="color:#4285f4">g</span>'
            . '<span style="color:#34a853">l</span>'
            . '<span style="color:#ea4335">e</span>'
            . '</span>';
    }
}
