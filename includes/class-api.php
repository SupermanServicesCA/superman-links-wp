<?php
/**
 * REST API endpoints for Superman Links plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_API {

    private $namespace = 'superman-links/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Handle CORS preflight requests early
        add_action('init', [$this, 'handle_preflight']);

        // Add CORS headers to REST responses
        add_filter('rest_pre_serve_request', [$this, 'add_cors_headers'], 15, 3);

        // LinkFinder push: hook save_post to schedule a delayed push event
        add_action('save_post', [$this, 'on_post_save_linkfinder_push'], 20, 3);
        add_action('superman_linkfinder_push_event', [$this, 'linkfinder_push_post']);

        // LinkFinder bulk push: WP-Cron tick handler
        add_action('superman_linkfinder_bulk_push_tick', [$this, 'linkfinder_bulk_push_tick']);

        // WP-Cron doesn't have a "every minute" schedule by default
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['minute'])) {
                $schedules['minute'] = ['interval' => 60, 'display' => __('Every Minute')];
            }
            return $schedules;
        });

        // Make our own internal page-capture loopback fetch bypass the page cache.
        // The X-Superman-Internal header was previously SET but never READ (a no-op
        // label). The on-page signal capture (tap-to-call / NAP / map / rendered
        // head schema) needs the LIVE rendered page, not a FlyingPress / SiteGround
        // SuperCacher / WP-Rocket cached or Security-Optimizer challenge copy. When
        // our marker header is present we declare the request uncacheable as early as
        // possible (this constructor runs on plugins_loaded) and force no-cache
        // response headers. Belt-and-suspenders with the cache-buster query param the
        // fetch appends (fetch_rendered_page_html). Whether a given host's full-page
        // cache honours this still needs a live probe — see the organic-foundation plan.
        // Require BOTH our marker header AND our own cache-buster query param so an
        // external request can't toggle the cache bypass by spoofing the header alone
        // (our loopback fetch always sends both). Worst case if abused is a per-request
        // cache miss, not poisoning — but gating on the param closes even that.
        if (!empty($_SERVER['HTTP_X_SUPERMAN_INTERNAL']) && isset($_GET['superman_nocache'])) {
            if (!defined('DONOTCACHEPAGE'))   { define('DONOTCACHEPAGE', true); }
            if (!defined('DONOTCACHEOBJECT')) { define('DONOTCACHEOBJECT', true); }
            if (!defined('DONOTCACHEDB'))     { define('DONOTCACHEDB', true); }
            add_action('send_headers', function () {
                if (function_exists('nocache_headers')) { nocache_headers(); }
            }, 0);
        }
    }

    /**
     * Handle CORS preflight OPTIONS requests
     */
    public function handle_preflight() {
        // Only handle OPTIONS requests to our API namespace
        if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/wp-json/superman-links/') === false) {
            return;
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: X-Superman-Links-Key, Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
        status_header(200);
        exit();
    }

    /**
     * Add CORS headers to REST API responses
     */
    public function add_cors_headers($served, $result, $request) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: X-Superman-Links-Key, Content-Type, Authorization');

        return $served;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Ping endpoint (no auth required)
        register_rest_route($this->namespace, '/ping', [
            'methods' => 'GET',
            'callback' => [$this, 'ping'],
            'permission_callback' => '__return_true',
        ]);

        // Get all pages
        register_rest_route($this->namespace, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_pages'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'post_type' => [
                    'default' => 'post,page',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'has_focus_keyword' => [
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Get single page
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // Update page focus keyword
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/focus-keyword', [
            'methods' => 'POST',
            'callback' => [$this, 'update_focus_keyword'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'focus_keyword' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Bulk update focus keywords by URL
        register_rest_route($this->namespace, '/pages/bulk-focus-keyword', [
            'methods' => 'POST',
            'callback' => [$this, 'bulk_update_focus_keywords'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Bulk update titles by URL
        register_rest_route($this->namespace, '/pages/bulk-title', [
            'methods' => 'POST',
            'callback' => [$this, 'bulk_update_titles'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // ==========================================
        // Elementor Template Endpoints
        // ==========================================

        // List all Elementor-built pages
        register_rest_route($this->namespace, '/elementor/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_elementor_pages'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'post_type' => [
                    'default' => 'page',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Download Elementor template for a page
        register_rest_route($this->namespace, '/elementor/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_elementor_template'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // Update existing page with Elementor template
        register_rest_route($this->namespace, '/elementor/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'update_elementor_template'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // Create new page from Elementor template
        register_rest_route($this->namespace, '/elementor/import', [
            'methods' => 'POST',
            'callback' => [$this, 'import_elementor_template'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // ==========================================
        // Blog Post Publishing Endpoint (v1.14.0+)
        // ==========================================

        // Publish a Content Writer draft as a native Gutenberg/HTML post
        register_rest_route($this->namespace, '/posts', [
            'methods' => 'POST',
            'callback' => [$this, 'create_post'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // LinkFinder bulk push admin endpoints
        register_rest_route($this->namespace, '/linkfinder/start', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_linkfinder_start'],
            'permission_callback' => [$this, 'check_admin_or_api_key'],
        ]);
        register_rest_route($this->namespace, '/linkfinder/stop', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_linkfinder_stop'],
            'permission_callback' => [$this, 'check_admin_or_api_key'],
        ]);
        register_rest_route($this->namespace, '/linkfinder/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_linkfinder_status'],
            'permission_callback' => [$this, 'check_admin_or_api_key'],
        ]);
        register_rest_route($this->namespace, '/linkfinder/tick', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_linkfinder_tick'],
            'permission_callback' => [$this, 'check_admin_or_api_key'],
        ]);

        // ==========================================
        // Internal Links Endpoints
        // ==========================================

        // Crawl internal links from all published content
        register_rest_route($this->namespace, '/internal-links', [
            'methods' => 'GET',
            'callback' => [$this, 'get_internal_links'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'per_page' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Insert an internal link into a page
        register_rest_route($this->namespace, '/internal-links', [
            'methods' => 'POST',
            'callback' => [$this, 'insert_internal_link'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'source_post_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'target_url' => [
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'anchor_text' => [
                    'required' => true,
                ],
                'match_context' => [
                    'required' => false,
                ],
            ],
        ]);

        // DELETE /internal-links — unwrap an existing anchor by target URL
        register_rest_route($this->namespace, '/internal-links', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_internal_link'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'source_post_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'target_url' => [
                    'required' => true,
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // ==========================================
        // Internal Link Juicer (ILJ) Integration
        // ==========================================

        // Check ILJ status
        register_rest_route($this->namespace, '/ilj/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ilj_status'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Get ILJ link index
        register_rest_route($this->namespace, '/ilj/index', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ilj_index'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'per_page' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Push keywords to ILJ
        register_rest_route($this->namespace, '/ilj/keywords', [
            'methods' => 'POST',
            'callback' => [$this, 'push_ilj_keywords'],
            'permission_callback' => [$this, 'check_api_key'],
            'args' => [
                'post_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route('superman-links/v1', '/version', [
            'methods' => 'GET',
            'callback' => [$this, 'get_plugin_version'],
            'permission_callback' => '__return_true',
        ]);

        // RankMath active redirects (for Content Silos redirect tracking)
        register_rest_route($this->namespace, '/redirects', [
            'methods' => 'GET',
            'callback' => [$this, 'get_rankmath_redirects'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Elementor capabilities (Pro status, available widgets, Site Kit snapshot)
        register_rest_route($this->namespace, '/elementor/capabilities', [
            'methods' => 'GET',
            'callback' => [$this, 'get_elementor_capabilities'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Patch one widget on an existing Elementor page (shallow-merge settings)
        register_rest_route($this->namespace, '/elementor/widget/(?P<post_id>\d+)/(?P<element_id>[a-f0-9]+)', [
            'methods' => 'PATCH',
            'callback' => [$this, 'patch_elementor_widget'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Batch-patch many widgets in one save (1× CSS regen instead of N)
        register_rest_route($this->namespace, '/elementor/widget/(?P<post_id>\d+)/batch', [
            'methods' => 'POST',
            'callback' => [$this, 'batch_patch_elementor_widgets'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);
    }

    /**
     * Return Elementor capabilities for the Page Builder pipeline.
     * - pro_active / pro_version / free_version
     * - available_widgets (registered widget type names)
     * - site_kit { fonts, colors } extracted from the active Elementor kit
     */
    public function get_elementor_capabilities($request) {
        if (!defined('ELEMENTOR_VERSION')) {
            return new WP_Error(
                'elementor_not_active',
                __('Elementor is not active on this site.', 'superman-links'),
                ['status' => 400]
            );
        }

        $pro_active  = defined('ELEMENTOR_PRO_VERSION');
        $pro_version = $pro_active ? ELEMENTOR_PRO_VERSION : null;
        $free_version = ELEMENTOR_VERSION;

        $available_widgets = [];
        if (class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if ($plugin && isset($plugin->widgets_manager)) {
                $widget_types = $plugin->widgets_manager->get_widget_types();
                if (is_array($widget_types)) {
                    $available_widgets = array_values(array_map('strval', array_keys($widget_types)));
                }
            }
        }

        $site_kit = [
            'fonts'  => [],
            'colors' => (object) [],
        ];

        $kit_id = (int) get_option('elementor_active_kit');
        if ($kit_id > 0) {
            $page_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
            if (is_string($page_settings)) {
                $page_settings = json_decode($page_settings, true);
            }
            if (is_array($page_settings)) {
                $color_map = [];
                $color_buckets = [];

                $collect_colors = function ($list) use (&$color_buckets) {
                    if (!is_array($list)) return;
                    foreach ($list as $entry) {
                        if (!is_array($entry)) continue;
                        $title = isset($entry['title']) ? strtolower((string) $entry['title']) : '';
                        $hex   = isset($entry['color']) ? (string) $entry['color'] : '';
                        if ($title === '' || $hex === '') continue;
                        $color_buckets[] = ['title' => $title, 'color' => $hex];
                    }
                };
                $collect_colors($page_settings['system_colors'] ?? []);
                $collect_colors($page_settings['custom_colors'] ?? []);

                $known = [
                    'primary'   => ['primary', 'brand'],
                    'secondary' => ['secondary'],
                    'accent'    => ['accent', 'cta'],
                    'text'      => ['text', 'body', 'foreground'],
                ];
                foreach ($color_buckets as $entry) {
                    foreach ($known as $key => $needles) {
                        if (isset($color_map[$key])) continue;
                        foreach ($needles as $needle) {
                            if (strpos($entry['title'], $needle) !== false) {
                                $color_map[$key] = $entry['color'];
                                break;
                            }
                        }
                    }
                }
                // Surface a couple of extras for the UI diff view
                $extras = [];
                foreach ($color_buckets as $entry) {
                    $hex = $entry['color'];
                    if (!in_array($hex, $color_map, true) && count($extras) < 4) {
                        $extras[] = $hex;
                    }
                }
                if (!empty($extras)) {
                    foreach ($extras as $i => $hex) {
                        $color_map['extra_' . ($i + 1)] = $hex;
                    }
                }
                if (!empty($color_map)) {
                    $site_kit['colors'] = $color_map;
                }

                $fonts = [];
                $typo = $page_settings['system_typography'] ?? [];
                if (is_array($typo)) {
                    foreach ($typo as $entry) {
                        if (!is_array($entry)) continue;
                        $family = $entry['typography_font_family'] ?? null;
                        if (is_string($family) && $family !== '' && !in_array($family, $fonts, true)) {
                            $fonts[] = $family;
                        }
                    }
                }
                $site_kit['fonts'] = array_values($fonts);
            }
        }

        return rest_ensure_response([
            'pro_active'        => $pro_active,
            'pro_version'       => $pro_version,
            'free_version'      => $free_version,
            'available_widgets' => $available_widgets,
            'site_kit'          => $site_kit,
        ]);
    }

    /**
     * Return the current plugin version.
     */
    public function get_plugin_version($request) {
        return new WP_REST_Response([
            'version' => SUPERMAN_LINKS_VERSION,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
        ], 200);
    }

    /**
     * Check API key authentication
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

        if (empty($provided_key) || $provided_key !== $stored_key) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid or missing API key.', 'superman-links'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Ping endpoint to test connection
     */
    public function ping($request) {
        return rest_ensure_response([
            'status' => 'ok',
            'plugin' => 'Superman Links',
            'version' => SUPERMAN_LINKS_VERSION,
            'wordpress' => get_bloginfo('version'),
            'site_name' => get_bloginfo('name'),
            'site_url' => get_site_url(),
            'rankmath_active' => $this->is_rankmath_active(),
            'yoast_active' => $this->is_yoast_active(),
            'elementor_active' => $this->is_elementor_active(),
            'elementor_version' => $this->get_elementor_version(),
            'ilj_active' => $this->is_ilj_active(),
            'ilj_version' => defined('ILJ_VERSION') ? ILJ_VERSION : null,
        ]);
    }

    /**
     * Get all pages with RankMath data
     */
    public function get_pages($request) {
        $post_types = array_map('trim', explode(',', $request->get_param('post_type')));
        $per_page = min($request->get_param('per_page'), 500); // Cap at 500
        $page = $request->get_param('page');
        $has_focus_keyword = $request->get_param('has_focus_keyword');

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        // If filtering by focus keyword, add meta query
        if ($has_focus_keyword) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'rank_math_focus_keyword',
                    'value' => '',
                    'compare' => '!=',
                ],
                [
                    'key' => '_yoast_wpseo_focuskw',
                    'value' => '',
                    'compare' => '!=',
                ],
            ];
        }

        $query = new WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $pages[] = $this->format_page_data($post);
        }

        return rest_ensure_response([
            'pages' => $pages,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'current_page' => $page,
            'per_page' => $per_page,
        ]);
    }

    /**
     * Get a single page by ID
     */
    public function get_page($request) {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error(
                'not_found',
                __('Page not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->format_page_data($post));
    }

    /**
     * Format page data for API response
     */
    private function format_page_data($post) {
        $post_id = $post->ID;

        // Get RankMath data
        $focus_keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $seo_score = get_post_meta($post_id, 'rank_math_seo_score', true);
        $pillar_content = get_post_meta($post_id, 'rank_math_pillar_content', true);
        $rankmath_title = get_post_meta($post_id, 'rank_math_title', true);
        $rankmath_description = get_post_meta($post_id, 'rank_math_description', true);

        // Fallback to Yoast if RankMath data not found
        if (empty($focus_keyword)) {
            $focus_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        }

        // Parse multiple focus keywords (RankMath stores them comma-separated)
        $focus_keywords = [];
        if (!empty($focus_keyword)) {
            $focus_keywords = array_map('trim', explode(',', $focus_keyword));
        }

        // Get word count
        $content = strip_tags($post->post_content);
        $word_count = str_word_count($content);

        return [
            'id' => $post_id,
            'url' => get_permalink($post_id),
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'published_at' => $post->post_date_gmt,
            'modified_at' => $post->post_modified_gmt,
            'author' => get_the_author_meta('display_name', $post->post_author),
            'word_count' => $word_count,
            'seo' => [
                'focus_keyword' => $focus_keywords[0] ?? null,
                'focus_keywords' => $focus_keywords,
                'seo_score' => $seo_score ? (int) $seo_score : null,
                'is_pillar' => $pillar_content === 'on',
                'meta_title' => $rankmath_title ?: null,
                'meta_description' => $rankmath_description ?: null,
            ],
            'featured_image' => get_the_post_thumbnail_url($post_id, 'full') ?: null,
            'categories' => $this->get_term_names($post_id, 'category'),
            'tags' => $this->get_term_names($post_id, 'post_tag'),
        ];
    }

    /**
     * Get term names for a post
     */
    private function get_term_names($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            return [];
        }
        return array_map(function($term) {
            return $term->name;
        }, $terms);
    }

    /**
     * Update focus keyword for a single page
     */
    public function update_focus_keyword($request) {
        $post_id = $request->get_param('id');
        $focus_keyword = $request->get_param('focus_keyword');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Page not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        $result = $this->set_focus_keyword($post_id, $focus_keyword);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'post_id' => $post_id,
            'focus_keyword' => $focus_keyword,
            'seo_plugin' => $result,
        ]);
    }

    /**
     * Bulk update focus keywords by URL
     */
    public function bulk_update_focus_keywords($request) {
        $body = $request->get_json_params();
        $pages = $body['pages'] ?? [];

        if (empty($pages) || !is_array($pages)) {
            return new WP_Error(
                'invalid_request',
                __('Pages array is required.', 'superman-links'),
                ['status' => 400]
            );
        }

        $results = [
            'updated' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($pages as $page_data) {
            $url = $page_data['url'] ?? '';
            $focus_keyword = $page_data['focus_keyword'] ?? '';

            if (empty($url) || empty($focus_keyword)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => 'Missing URL or focus keyword',
                ];
                continue;
            }

            // Find post by URL
            $post_id = url_to_postid($url);

            if (!$post_id) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => 'Page not found',
                ];
                continue;
            }

            $result = $this->set_focus_keyword($post_id, $focus_keyword);

            if (is_wp_error($result)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => $result->get_error_message(),
                ];
            } else {
                $results['updated']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => true,
                    'seo_plugin' => $result,
                ];
            }
        }

        return rest_ensure_response($results);
    }

    /**
     * Set focus keyword for a post (RankMath or Yoast)
     */
    private function set_focus_keyword($post_id, $focus_keyword) {
        // Try RankMath first
        if ($this->is_rankmath_active()) {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
            return 'rankmath';
        }

        // Fall back to Yoast
        if ($this->is_yoast_active()) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
            return 'yoast';
        }

        return new WP_Error(
            'no_seo_plugin',
            __('No supported SEO plugin found (RankMath or Yoast).', 'superman-links'),
            ['status' => 400]
        );
    }

    /**
     * Check if RankMath is active
     */
    private function is_rankmath_active() {
        return class_exists('RankMath');
    }

    /**
     * Check if Yoast is active
     */
    private function is_yoast_active() {
        return defined('WPSEO_VERSION');
    }

    /**
     * Bulk update post titles by URL
     */
    public function bulk_update_titles($request) {
        $body = $request->get_json_params();
        $pages = $body['pages'] ?? [];

        if (empty($pages) || !is_array($pages)) {
            return new WP_Error(
                'invalid_request',
                __('Pages array is required.', 'superman-links'),
                ['status' => 400]
            );
        }

        $results = [
            'updated' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($pages as $page_data) {
            $url = $page_data['url'] ?? '';
            $title = $page_data['title'] ?? '';

            if (empty($url) || empty($title)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => 'Missing URL or title',
                ];
                continue;
            }

            // Find post by URL
            $post_id = url_to_postid($url);

            if (!$post_id) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => 'Page not found',
                ];
                continue;
            }

            // Update the post title
            $update_result = wp_update_post([
                'ID' => $post_id,
                'post_title' => sanitize_text_field($title),
            ], true);

            if (is_wp_error($update_result)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'error' => $update_result->get_error_message(),
                ];
            } else {
                $results['updated']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => true,
                    'title' => $title,
                ];
            }
        }

        return rest_ensure_response($results);
    }

    // ==========================================
    // Elementor Template Methods
    // ==========================================

    /**
     * Check if Elementor is active
     */
    private function is_elementor_active() {
        return defined('ELEMENTOR_VERSION') || class_exists('\\Elementor\\Plugin');
    }

    /**
     * Get Elementor version
     */
    private function get_elementor_version() {
        if (defined('ELEMENTOR_VERSION')) {
            return ELEMENTOR_VERSION;
        }
        return null;
    }

    /**
     * Check if a post is built with Elementor
     */
    private function is_elementor_post($post_id) {
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        return $edit_mode === 'builder';
    }

    /**
     * Detect which page builder produced this post.
     * Returns one of: elementor, gutenberg, classic, divi, wpbakery,
     * beaver_builder, bricks, oxygen.
     */
    private function detect_builder( $post ) {
        if ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' ) {
            return 'elementor';
        }
        if ( get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
            return 'beaver_builder';
        }
        if ( get_post_meta( $post->ID, '_bricks_page_content_2', true ) ) {
            return 'bricks';
        }
        if ( get_post_meta( $post->ID, 'ct_builder_shortcodes', true ) ) {
            return 'oxygen';
        }
        if ( strpos( $post->post_content, '[et_pb_section' ) !== false ) {
            return 'divi';
        }
        if ( strpos( $post->post_content, '[vc_row' ) !== false ) {
            return 'wpbakery';
        }
        if ( strpos( $post->post_content, '<!-- wp:' ) !== false ) {
            return 'gutenberg';
        }
        return 'classic';
    }

    /**
     * Parse rendered HTML into structured text + links.
     * Returns: [ 'headings' => string[], 'paragraphs' => string[], 'links' => array, 'full_text' => string ]
     */
    private function parse_html_to_structured( $html, $base_url = '' ) {
        if ( empty( $html ) ) {
            return [
                'headings'    => [],
                'paragraphs'  => [],
                'links'       => [],
                'full_text'   => '',
                'image_count' => 0,
                'image_alts'  => [],
            ];
        }

        // Suppress libxml warnings on imperfect HTML
        $previous = libxml_use_internal_errors( true );
        $doc      = new DOMDocument();
        // UTF-8 hint
        $doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $xpath = new DOMXPath( $doc );

        // Strip non-content nodes
        foreach ( $xpath->query( '//script | //style | //noscript | //nav | //header | //footer | //*[@aria-hidden="true"]' ) as $node ) {
            $node->parentNode->removeChild( $node );
        }

        $headings   = [];
        $paragraphs = [];
        $links      = [];
        $seen_para  = [];

        // Headings
        foreach ( $xpath->query( '//h1 | //h2 | //h3 | //h4 | //h5 | //h6' ) as $node ) {
            $text = trim( preg_replace( '/\s+/', ' ', $this->extract_text_with_spaces($node) ) );
            if ( $text !== '' ) {
                $headings[] = $text;
            }
        }

        // Paragraphs and list items
        foreach ( $xpath->query( '//p | //li' ) as $node ) {
            $text = trim( preg_replace( '/\s+/', ' ', $this->extract_text_with_spaces($node) ) );
            if ( $text === '' || isset( $seen_para[ $text ] ) ) {
                continue;
            }
            $seen_para[ $text ] = true;
            $paragraphs[]       = $text;
        }

        // Images — count and extract alt texts
        $image_count = 0;
        $image_alts  = [];
        foreach ( $xpath->query( '//img[@src]' ) as $node ) {
            $image_count++;
            $alt = trim( $node->getAttribute( 'alt' ) );
            if ( $alt !== '' ) {
                $image_alts[] = $alt;
            }
        }

        // Links — capture url + anchor text + parent paragraph context
        foreach ( $xpath->query( '//a[@href]' ) as $node ) {
            $href = trim( $node->getAttribute( 'href' ) );
            if ( $href === '' || strpos( $href, '#' ) === 0 || strpos( $href, 'javascript:' ) === 0 ) {
                continue;
            }
            $anchor = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
            if ( $anchor === '' ) {
                continue;
            }
            // Resolve relative URLs
            $url = $href;
            if ( $base_url && ! preg_match( '/^https?:\/\//i', $url ) && strpos( $url, 'tel:' ) !== 0 && strpos( $url, 'mailto:' ) !== 0 ) {
                $url = rtrim( $base_url, '/' ) . '/' . ltrim( $url, '/' );
            }

            // Walk up to find parent <p> or <li> for context
            $context = '';
            $parent  = $node->parentNode;
            while ( $parent && ! in_array( strtolower( $parent->nodeName ), [ 'p', 'li', 'td', 'div', 'body' ], true ) ) {
                $parent = $parent->parentNode;
            }
            if ( $parent ) {
                $context = trim( preg_replace( '/\s+/', ' ', $parent->textContent ) );
                if ( strlen( $context ) > 200 ) {
                    $context = substr( $context, 0, 197 ) . '...';
                }
            }

            $links[] = [
                'url'     => $url,
                'anchor'  => $anchor,
                'context' => $context,
            ];
        }

        $full_text = trim( implode( "\n", array_merge( $headings, $paragraphs ) ) );

        return [
            'headings'    => $headings,
            'paragraphs'  => $paragraphs,
            'links'       => $links,
            'full_text'   => $full_text,
            'image_count' => $image_count,
            'image_alts'  => $image_alts,
        ];
    }

    /**
     * Render a post to HTML using whichever pipeline matches the builder.
     * Always returns a string (empty on failure).
     */
    private function render_post_to_html( $post, $builder ) {
        switch ( $builder ) {
            case 'elementor':
                // Elementor bypasses the_content; use its frontend renderer.
                // If Elementor is unexpectedly missing, fall back to the_content path.
                if ( class_exists( '\\Elementor\\Plugin' ) ) {
                    $instance = \Elementor\Plugin::$instance;
                    if ( $instance && isset( $instance->frontend ) ) {
                        return $instance->frontend->get_builder_content_for_display( $post->ID );
                    }
                }
                return apply_filters( 'the_content', $post->post_content );

            case 'divi':
                // Divi stores shortcodes that may not fully render in REST context.
                // Try native rendering first, then strip shortcodes, then fetch live page.
                $html = apply_filters( 'the_content', $post->post_content );
                $clean_text = trim( wp_strip_all_tags( $html ) );
                // If rendered HTML has real text (not just shortcode remnants), use it
                if ( strlen( $clean_text ) > 100 && substr_count( $clean_text, '[' ) < 5 ) {
                    return $html;
                }
                // Fallback: strip all shortcodes and re-render
                $stripped = strip_shortcodes( $post->post_content );
                $stripped_html = apply_filters( 'the_content', $stripped );
                $stripped_text = trim( wp_strip_all_tags( $stripped_html ) );
                if ( strlen( $stripped_text ) > 50 ) {
                    return $stripped_html;
                }
                // Last resort: fetch the live rendered page (same as Bricks/Oxygen)
                $response = wp_remote_get( get_permalink( $post->ID ), [
                    'timeout'   => 15,
                    'sslverify' => false,
                    'headers'   => [ 'X-Superman-Internal' => '1' ],
                ] );
                if ( ! is_wp_error( $response ) ) {
                    $body = wp_remote_retrieve_body( $response );
                    if ( preg_match( '/<main\b[^>]*>(.*?)<\/main>/is', $body, $m ) ) {
                        return $m[1];
                    }
                    if ( preg_match( '/<article\b[^>]*>(.*?)<\/article>/is', $body, $m ) ) {
                        return $m[1];
                    }
                    return $body;
                }
                return $stripped_html;

            case 'gutenberg':
            case 'classic':
            case 'wpbakery':
            case 'beaver_builder':
                // WordPress core handles all shortcode/block-based builders
                return apply_filters( 'the_content', $post->post_content );

            case 'bricks':
            case 'oxygen':
            default:
                // Universal fallback: fetch the rendered live URL via internal request
                $response = wp_remote_get( get_permalink( $post->ID ), [
                    'timeout'   => 15,
                    'sslverify' => false,
                    'headers'   => [ 'X-Superman-Internal' => '1' ],
                ] );
                if ( is_wp_error( $response ) ) {
                    return '';
                }
                $body = wp_remote_retrieve_body( $response );
                // Crude main-content extraction: prefer <main> > <article> > .entry-content > full body
                if ( preg_match( '/<main\b[^>]*>(.*?)<\/main>/is', $body, $m ) ) {
                    return $m[1];
                }
                if ( preg_match( '/<article\b[^>]*>(.*?)<\/article>/is', $body, $m ) ) {
                    return $m[1];
                }
                return $body;
        }
    }

    /**
     * Get all Elementor-built pages
     */
    public function get_elementor_pages($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error(
                'elementor_not_active',
                __('Elementor plugin is not active on this site.', 'superman-links'),
                ['status' => 400]
            );
        }

        $post_types = array_map('trim', explode(',', $request->get_param('post_type')));
        $per_page = min($request->get_param('per_page'), 500);
        $page = $request->get_param('page');

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_elementor_edit_mode',
                    'value' => 'builder',
                    'compare' => '=',
                ],
            ],
        ];

        $query = new WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $pages[] = $this->format_elementor_page_summary($post);
        }

        return rest_ensure_response([
            'pages' => $pages,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'current_page' => $page,
            'per_page' => $per_page,
        ]);
    }

    /**
     * Format Elementor page summary (for listing)
     */
    private function format_elementor_page_summary($post) {
        $post_id = $post->ID;
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        $template_type = get_post_meta($post_id, '_elementor_template_type', true);
        $elementor_version = get_post_meta($post_id, '_elementor_version', true);

        // Count widgets/elements
        $widget_count = 0;
        if (!empty($elementor_data)) {
            $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
            if (is_array($data)) {
                $widget_count = $this->count_elementor_widgets($data);
            }
        }

        return [
            'id' => $post_id,
            'url' => get_permalink($post_id),
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'modified_at' => $post->post_modified_gmt,
            'template_type' => $template_type ?: 'page',
            'elementor_version' => $elementor_version,
            'widget_count' => $widget_count,
            'featured_image' => get_the_post_thumbnail_url($post_id, 'thumbnail') ?: null,
        ];
    }

    /**
     * Count widgets in Elementor data recursively
     */
    private function count_elementor_widgets($elements) {
        $count = 0;
        foreach ($elements as $element) {
            if (isset($element['elType']) && $element['elType'] === 'widget') {
                $count++;
            }
            if (!empty($element['elements'])) {
                $count += $this->count_elementor_widgets($element['elements']);
            }
        }
        return $count;
    }

    /**
     * Download Elementor template for a page
     */
    public function get_elementor_template($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error(
                'elementor_not_active',
                __('Elementor plugin is not active on this site.', 'superman-links'),
                ['status' => 400]
            );
        }

        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Page not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        if (!$this->is_elementor_post($post_id)) {
            return new WP_Error(
                'not_elementor',
                __('This page is not built with Elementor.', 'superman-links'),
                ['status' => 400]
            );
        }

        // Get Elementor data
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        $template_type = get_post_meta($post_id, '_elementor_template_type', true);
        $elementor_version = get_post_meta($post_id, '_elementor_version', true);
        $elementor_css = get_post_meta($post_id, '_elementor_css', true);

        // Parse elementor_data if it's a string
        $parsed_data = null;
        if (!empty($elementor_data)) {
            $parsed_data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
        }

        // Parse page_settings if it's a string
        $parsed_settings = null;
        if (!empty($page_settings)) {
            $parsed_settings = is_string($page_settings) ? json_decode($page_settings, true) : $page_settings;
        }

        // Build the template export structure
        $template = [
            'version' => '1.0',
            'type' => 'superman-links-elementor-template',
            'source' => [
                'site_url' => get_site_url(),
                'post_id' => $post_id,
                'post_url' => get_permalink($post_id),
                'exported_at' => current_time('c'),
            ],
            'page' => [
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'template_type' => $template_type ?: 'page',
            ],
            'elementor' => [
                'version' => $elementor_version,
                'data' => $parsed_data,
                'page_settings' => $parsed_settings,
                'css' => $elementor_css,
            ],
            'seo' => $this->get_page_seo_data($post_id),
        ];

        return rest_ensure_response($template);
    }

    /**
     * Get SEO data for a page (helper for template export)
     */
    private function get_page_seo_data($post_id) {
        $focus_keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        if (empty($focus_keyword)) {
            $focus_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        }

        return [
            'focus_keyword' => $focus_keyword ?: null,
            'meta_title' => get_post_meta($post_id, 'rank_math_title', true) ?: null,
            'meta_description' => get_post_meta($post_id, 'rank_math_description', true) ?: null,
        ];
    }

    /**
     * Update existing page with Elementor template
     */
    public function update_elementor_template($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error(
                'elementor_not_active',
                __('Elementor plugin is not active on this site.', 'superman-links'),
                ['status' => 400]
            );
        }

        $post_id = $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Page not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        $body = $request->get_json_params();

        // Validate required fields
        if (empty($body['elementor']['data'])) {
            return new WP_Error(
                'missing_data',
                __('Elementor data is required.', 'superman-links'),
                ['status' => 400]
            );
        }

        return $this->apply_elementor_template($post_id, $body, false);
    }

    /**
     * Create new page from Elementor template
     */
    public function import_elementor_template($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error(
                'elementor_not_active',
                __('Elementor plugin is not active on this site.', 'superman-links'),
                ['status' => 400]
            );
        }

        $body = $request->get_json_params();

        // Validate required fields
        if (empty($body['elementor']['data'])) {
            return new WP_Error(
                'missing_data',
                __('Elementor data is required.', 'superman-links'),
                ['status' => 400]
            );
        }

        // Get page details from template or use defaults
        $title = $body['page']['title'] ?? 'Imported Template';
        $slug = $body['page']['slug'] ?? '';
        $post_type = $body['page']['post_type'] ?? 'page';
        $status = $body['page']['status'] ?? 'draft';

        // Validate post type
        if (!in_array($post_type, ['page', 'post'])) {
            $post_type = 'page';
        }

        // Validate status
        if (!in_array($status, ['publish', 'draft', 'pending', 'private'])) {
            $status = 'draft';
        }

        // Create the new post
        $post_data = [
            'post_title' => sanitize_text_field($title),
            'post_type' => $post_type,
            'post_status' => $status,
            'post_content' => '', // Elementor handles content
        ];

        if (!empty($slug)) {
            $post_data['post_name'] = sanitize_title($slug);
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return $this->apply_elementor_template($post_id, $body, true);
    }

    /**
     * Publish a Content Writer draft as a native Gutenberg/HTML post.
     *
     * Unlike /elementor/import (which writes JSON to meta and leaves
     * post_content empty), this writes a real HTML body through
     * wp_kses_post and returns the live permalink. Idempotent via the
     * _superman_draft_id post meta tag.
     */
    public function create_post($request) {
        $body = $request->get_json_params();

        $draft_id      = $body['draft_id'] ?? '';
        $title         = $body['title'] ?? '';
        $content_html  = $body['content_html'] ?? '';
        $slug          = $body['slug'] ?? '';
        $status        = $body['status'] ?? 'draft';
        $focus_keyword = $body['focus_keyword'] ?? '';
        $excerpt       = $body['excerpt'] ?? '';

        // Empty-body guard: never create a blank post.
        if (trim((string) $content_html) === '') {
            return new WP_Error(
                'empty_body',
                __('content_html is required and cannot be empty.', 'superman-links'),
                ['status' => 400]
            );
        }

        // Validate status; default to draft.
        if (!in_array($status, ['draft', 'publish'], true)) {
            $status = 'draft';
        }

        // Server-side idempotency + re-publish upsert: if a post already carries
        // this draft_id, UPDATE it in place rather than creating a duplicate.
        // Critically, a draft→live re-publish must FLIP the post to 'publish' —
        // the old code returned the existing draft unchanged, so re-publishing a
        // drafted post to go live silently left it a draft (and the content never
        // refreshed). This also closes the WP-success / audit-write-failure window.
        $existing_id = 0;
        if (!empty($draft_id)) {
            $existing = get_posts([
                'post_type'   => 'post',
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_superman_draft_id',
                'meta_value'  => $draft_id,
            ]);
            if (!empty($existing)) {
                $existing_id = (int) $existing[0];
            }
        }

        $post_data = [
            'post_status'  => $status,
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post($content_html),
            'post_excerpt' => sanitize_textarea_field($excerpt),
        ];

        if ($existing_id) {
            // Re-publish: update in place. Deliberately do NOT touch the slug —
            // the existing permalink is the key the staged tracked link points at,
            // so re-slugging here would orphan it.
            $existing_status = get_post_status($existing_id);

            // Never demote an already-published post back to draft via re-publish:
            // that would dark the public post while its promoted network_link/links
            // row stays 'live', so the CRM would report a healthy link that 404s.
            // Unpublishing is not this endpoint's job (do it in WP directly).
            if ($existing_status === 'publish' && $post_data['post_status'] === 'draft') {
                $post_data['post_status'] = 'publish';
            }

            // Stamp the go-live date when a draft is first promoted to publish, so
            // the post's publish date (and sitemap/feed ordering + freshness
            // signals) reflects when it actually went live — wp_update_post keeps
            // the original draft-creation post_date otherwise. Skip when already
            // published so a content-only refresh doesn't churn the date.
            if ($existing_status !== 'publish' && $post_data['post_status'] === 'publish') {
                $post_data['post_date']     = current_time('mysql');
                $post_data['post_date_gmt'] = current_time('mysql', 1);
                $post_data['edit_date']     = true;
            }

            $post_data['ID'] = $existing_id;
            $result = wp_update_post($post_data, true);
        } else {
            $post_data['post_type'] = 'post';
            if (!empty($slug)) {
                $post_data['post_name'] = sanitize_title($slug);
            }
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $post_id = $existing_id ?: (int) $result;

        // Tag the post with the draft id for idempotency on re-publish.
        if (!empty($draft_id)) {
            update_post_meta($post_id, '_superman_draft_id', sanitize_text_field($draft_id));
        }

        // Focus keyword: best-effort. If neither Rank Math nor Yoast is active
        // the helper returns a no_seo_plugin WP_Error — skip the keyword and
        // still publish (don't fail the post over a missing SEO plugin).
        if (!empty($focus_keyword)) {
            $kw_result = $this->set_focus_keyword($post_id, $focus_keyword);
            if (is_wp_error($kw_result)) {
                error_log('Superman Links: focus keyword skipped for post ' . $post_id . ' — ' . $kw_result->get_error_message());
            }
        }

        // Content push on publish: trigger the LinkFinder push inline so the
        // outbound links reach the webhook immediately, rather than waiting on
        // the ~5s WP-Cron event (which lags/stalls on low-traffic donor sites).
        // skip_fetch=true: don't run the 6s loopback full-page fetch inside this
        // foreground REST response — the async save_post push (cron, ~5s later)
        // captures the full-page on-page/schema signals without blocking publish.
        if (get_post_status($post_id) === 'publish') {
            $this->linkfinder_push_post($post_id, null, true);
        }

        return rest_ensure_response([
            'id'                => $post_id,
            'url'               => get_permalink($post_id),
            'status'            => get_post_status($post_id),
            'already_published' => $existing_id ? true : false,
            'updated'           => $existing_id ? true : false,
        ]);
    }

    /**
     * Apply Elementor template data to a post
     */
    private function apply_elementor_template($post_id, $template_data, $is_new = false) {
        $elementor_data = $template_data['elementor']['data'] ?? null;
        $page_settings = $template_data['elementor']['page_settings'] ?? null;
        $template_type = $template_data['page']['template_type'] ?? 'page';

        // Encode data as JSON if it's an array. `update_post_meta` runs
        // `wp_unslash` internally and will strip `\uXXXX` / `\/` escapes,
        // which leaves `_elementor_data` as invalid JSON and Elementor
        // silently drops every element on load. `wp_slash()` preserves them.
        $elementor_json = is_array($elementor_data) ? wp_json_encode($elementor_data) : $elementor_data;

        // Update Elementor meta
        update_post_meta($post_id, '_elementor_data', wp_slash($elementor_json));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $template_type);
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

        // Update page settings if provided
        if (!empty($page_settings)) {
            $settings_json = is_array($page_settings) ? wp_json_encode($page_settings) : $page_settings;
            update_post_meta($post_id, '_elementor_page_settings', wp_slash($settings_json));
        }

        // Apply SEO data if provided
        if (!empty($template_data['seo'])) {
            $this->apply_seo_data($post_id, $template_data['seo']);
        }

        // Regenerate Elementor CSS
        $this->regenerate_elementor_css($post_id);

        // Get the updated post
        $post = get_post($post_id);

        return rest_ensure_response([
            'success' => true,
            'post_id' => $post_id,
            'url' => get_permalink($post_id),
            'title' => $post->post_title,
            'status' => $post->post_status,
            'is_new' => $is_new,
            'elementor_version' => ELEMENTOR_VERSION,
            'message' => $is_new
                ? __('New page created from template.', 'superman-links')
                : __('Page updated with template.', 'superman-links'),
        ]);
    }

    /**
     * Apply SEO data to a post
     */
    private function apply_seo_data($post_id, $seo_data) {
        if (!empty($seo_data['focus_keyword'])) {
            $this->set_focus_keyword($post_id, $seo_data['focus_keyword']);
        }

        if ($this->is_rankmath_active()) {
            if (!empty($seo_data['meta_title'])) {
                update_post_meta($post_id, 'rank_math_title', sanitize_text_field($seo_data['meta_title']));
            }
            if (!empty($seo_data['meta_description'])) {
                update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field($seo_data['meta_description']));
            }
        }
    }

    // ==========================================
    // LinkFinder Push Methods (v1.6.0+)
    // ==========================================

    /**
     * Permission callback that allows EITHER an authenticated WP admin OR
     * a valid Superman Links API key. Used by the bulk push admin endpoints.
     */
    public function check_admin_or_api_key($request) {
        if (current_user_can('manage_options')) {
            return true;
        }
        return $this->check_api_key($request);
    }

    /**
     * Fetch the FULL rendered page for a post via an internal loopback request.
     *
     * Unlike render_post_to_html (which returns post-body / inner <main> only and
     * throws away the <head>), this returns the entire document so the caller can
     * read head schema/meta AND header/footer markup (tap-to-call, NAP, map) that
     * the structured parser strips. Returns '' on any failure — the caller treats
     * '' as "not measured" and writes the signal booleans NULL, never false.
     *
     * Cache handling: appends a unique cache-buster query param (forces a MISS on
     * caches that key on the full URL) and sends X-Superman-Internal (trips the
     * DONOTCACHEPAGE bypass wired in the constructor). Short timeout because this
     * can run inside the bulk tick loop.
     */
    private function fetch_rendered_page_html($post_id) {
        $permalink = get_permalink($post_id);
        if (!$permalink) {
            return '';
        }
        $bust = (strpos($permalink, '?') === false ? '?' : '&') . 'superman_nocache=' . time();
        $response = wp_remote_get($permalink . $bust, [
            'timeout'     => 6,
            'sslverify'   => false,
            'redirection' => 2,
            'headers'     => [ 'X-Superman-Internal' => '1' ],
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return '';
        }
        $body = wp_remote_retrieve_body($response);
        // Validity gate: a real rendered page closes </html>. A cache challenge,
        // a WAF block, or a truncated response won't — in which case we measured
        // nothing reliable, so signal it as such (caller writes the fetch-derived
        // booleans NULL). Schema/meta still fall back to the in-process Rank Math
        // path so they are never lost to a cache block.
        if (stripos($body, '</html>') === false || strlen($body) < 512) {
            return '';
        }
        return $body;
    }

    /**
     * Pull all ld+json @type strings out of a blob of HTML (head + body), walking
     * @graph nodes. Returns a flat, de-duplicated array of type strings.
     */
    private function extract_ldjson_types($html) {
        $types = [];
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            foreach ($matches[1] as $json_str) {
                $data = json_decode(trim($json_str), true);
                if (!is_array($data)) {
                    continue;
                }
                // Normalize to a list of schema nodes. A single ld+json block may be a
                // single object ({@type|@graph}) OR a top-level array of objects
                // ([{...},{...}]) — handle both, plus a nested @graph in either.
                $nodes = (isset($data['@type']) || isset($data['@graph'])) ? [$data] : $data;
                foreach ($nodes as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    if (isset($node['@type'])) {
                        foreach ((array) $node['@type'] as $t) { $types[] = $t; }
                    }
                    if (isset($node['@graph']) && is_array($node['@graph'])) {
                        foreach ($node['@graph'] as $g) {
                            if (is_array($g) && isset($g['@type'])) {
                                foreach ((array) $g['@type'] as $t) { $types[] = $t; }
                            }
                        }
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($types)));
    }

    /**
     * Is any of these schema @types a LocalBusiness (or a known LocalBusiness
     * subtype for our home-services verticals)?
     */
    private function schema_types_have_localbusiness($types) {
        foreach ($types as $t) {
            if (stripos($t, 'LocalBusiness') !== false) {
                return true;
            }
            if (in_array($t, [
                'Plumber', 'HVACBusiness', 'Electrician', 'ElectricalContractor',
                'GeneralContractor', 'HomeAndConstructionBusiness', 'RoofingContractor',
                'HousePainter', 'MovingCompany', 'Locksmith', 'ProfessionalService',
                'CleaningService', 'DryCleaningOrLaundry', 'PestControl',
            ], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain visible text of the <body> — strips <script>/<style> and all tags so
     * heuristics (e.g. postal-code matching) don't fire on minified JS/CSS, asset
     * hashes, element IDs, or cache-busted URLs that pepper raw HTML.
     */
    private function visible_body_text($html) {
        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        return wp_strip_all_tags($html);
    }

    /**
     * Capture the on-page / schema signals that the structured body parser can't
     * see. Hybrid by signal type (per the organic-foundation plan):
     *   - rendered_meta_description / schema_types / has_localbusiness_schema:
     *     prefer the LIVE rendered <head> (most faithful — what Google sees) and
     *     fall back to the in-process Rank Math values so these are NEVER lost to a
     *     cache block (cache-immune floor).
     *   - has_tel_link / has_map_embed / nap_on_page: header/footer markup that has
     *     no in-process source — only the full-page fetch can see it. NULL when the
     *     fetch fails (not false), so a cached/blocked page can't fabricate todos.
     *
     * $body_types = @types already extracted from the rendered post body (FAQ etc.).
     * Returns an assoc array of the signal fields for the push payload.
     */
    private function capture_rendered_signals($post, $body_types, $skip_fetch = false) {
        $post_id   = $post->ID;
        // $skip_fetch is set on foreground REST paths (e.g. inline publish) so a 6s
        // loopback can't block the response — the async save_post push captures the
        // full-page signals a few seconds later in cron context.
        $full_html = $skip_fetch ? '' : $this->fetch_rendered_page_html($post_id);
        $fetched   = ($full_html !== '');

        // ---- Approach B floor (in-process, cache-immune) -------------------
        // Rendered meta description: post-meta when set, else the resolved Rank
        // Math template (this is the gap — post-meta is NULL when templated).
        $b_desc = get_post_meta($post_id, 'rank_math_description', true);
        if (!$b_desc && class_exists('RankMath\\Helper')) {
            $tmpl = \RankMath\Helper::get_settings("titles.pt_{$post->post_type}_description");
            if ($tmpl) {
                $b_desc = wp_strip_all_tags(\RankMath\Helper::replace_vars($tmpl, get_post($post_id)));
            }
        }
        if (!$b_desc) {
            $b_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        }

        // Rank Math sitewide + per-page schema types (Approach B).
        $b_types     = [];
        $rm_titles   = get_option('rank-math-options-titles');
        $rm_general  = get_option('rank-math-options-general');
        if (is_array($rm_titles)) {
            $kg = $rm_titles['knowledgegraph_type'] ?? '';
            if ($kg === 'company')     { $b_types[] = 'Organization'; }
            elseif ($kg === 'person')  { $b_types[] = 'Person'; }
            if (!empty($rm_titles['local_business_type'])) {
                $b_types[] = 'LocalBusiness';
                $b_types[] = $rm_titles['local_business_type'];
            }
        }
        $b_types[] = 'WebSite'; // Rank Math emits WebSite schema sitewide by default
        if (is_array($rm_general) && (($rm_general['breadcrumbs'] ?? '') === 'on')) {
            $b_types[] = 'BreadcrumbList';
        }
        // Per-page Rank Math schema blocks (Service / FAQPage / Article / Product ...)
        $all_meta = get_post_meta($post_id);
        if (is_array($all_meta)) {
            foreach ($all_meta as $mk => $mv) {
                if (strncmp($mk, 'rank_math_schema_', 17) !== 0) {
                    continue;
                }
                $raw = is_array($mv) ? ($mv[0] ?? '') : $mv;
                $val = maybe_unserialize($raw);
                if (is_array($val) && !empty($val['@type'])) {
                    foreach ((array) $val['@type'] as $t) { $b_types[] = $t; }
                } else {
                    $suffix = preg_replace('/[-_]\d+$/', '', substr($mk, 17));
                    if ($suffix) { $b_types[] = $suffix; }
                }
            }
        }

        // ---- Approach A overlay (live head, most faithful) -----------------
        $head_types = $fetched ? $this->extract_ldjson_types($full_html) : [];
        $a_desc     = '';
        if ($fetched && preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $full_html, $hm)) {
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $hm[1], $tag)
                && preg_match('/content=["\'](.*?)["\']/is', $tag[0], $cm)) {
                $a_desc = trim(html_entity_decode($cm[1], ENT_QUOTES));
            }
        }

        // ---- Merge ---------------------------------------------------------
        // When we fetched the live page, the rendered <head> is the TRUTH: an empty
        // head means the page genuinely shows no meta description (-> NULL -> a real
        // gap the strategy should surface). The in-process Rank Math floor ($b_desc)
        // is used ONLY when we could NOT see the head (fetch failed) as a cache-immune
        // display fallback — it must never mask a genuinely-empty head, or the
        // "missing meta description" strategy would under-report. The strategy gates
        // on head_fetched so a floor value never drives a false "missing".
        if ($fetched) {
            $rendered_meta_description = ($a_desc !== '') ? $a_desc : null;
        } else {
            $rendered_meta_description = ($b_desc !== '') ? $b_desc : null;
        }

        $schema_types = array_values(array_unique(array_filter(array_merge(
            (array) $body_types, $head_types, $b_types
        ))));

        // has_localbusiness: definitively measurable in-process (RM settings/body) OR
        // confirmed in the live head — so it's always measured (true/false), never NULL.
        $has_localbusiness = $this->schema_types_have_localbusiness($schema_types);

        // Header/footer booleans — fetch-only. NULL when the fetch failed (the webhook
        // then PRESERVES the prior measurement rather than downgrading it to NULL).
        if ($fetched) {
            $has_tel_link  = (bool) preg_match('/href=["\']tel:/i', $full_html);
            $has_map_embed = (bool) preg_match('/<iframe[^>]+src=["\'][^"\']*(google\.com\/maps|maps\.google|maps\.googleapis|\/maps\/embed)/i', $full_html);
            // Structural NAP heuristic (NOT a canonical-NAP match — that's a Phase 2
            // CRM-side refinement): a PostalAddress in schema, or a real Canadian postal
            // code in the VISIBLE body text (not asset hashes / IDs / inline JS) that is
            // co-located with a tap-to-call link. Case-sensitive with a valid CA
            // first-letter class so minified tokens like "a1b2c3" can't false-positive.
            $visible_text     = $this->visible_body_text($full_html);
            $has_postal_addr  = (stripos($full_html, 'PostalAddress') !== false);
            $has_ca_postal    = (bool) preg_match('/\b[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z][ -]?\d[ABCEGHJ-NPRSTV-Z]\d\b/', $visible_text);
            $nap_on_page      = $has_postal_addr || ($has_tel_link && $has_ca_postal);
        } else {
            $has_tel_link  = null;
            $has_map_embed = null;
            $nap_on_page   = null;
        }

        return [
            'rendered_meta_description' => $rendered_meta_description,
            'schema_types'              => $schema_types,
            'has_localbusiness_schema'  => $has_localbusiness,
            'has_tel_link'              => $has_tel_link,
            'has_map_embed'             => $has_map_embed,
            'nap_on_page'               => $nap_on_page,
            // Did the loopback full-page fetch succeed on THIS push? Drives the
            // webhook's preserve-vs-write decision and gates the meta-description gap
            // strategy so a fetch failure is never read as "genuinely missing".
            'head_fetched'              => $fetched,
        ];
    }

    /**
     * Run extraction locally for one post and POST the structured payload to
     * the Supabase wordpress-webhook endpoint. Returns true on 2xx, false otherwise.
     *
     * If $session_id is provided, the webhook treats this as part of an active
     * bulk push session and bumps the sync counters.
     */
    public function linkfinder_push_post($post_id, $session_id = null, $skip_fetch = false) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return false;
        }
        if (!in_array($post->post_type, ['post', 'page'], true)) {
            return false;
        }

        $webhook_url  = get_option('superman_links_webhook_url');
        $api_key      = get_option('superman_links_api_key');
        $supabase_key = get_option('superman_links_supabase_key');
        if (!$webhook_url || !$api_key) {
            return false;
        }

        $builder  = $this->detect_builder($post);
        $html     = $this->render_post_to_html($post, $builder);
        $site_url = get_site_url();
        $parsed   = $this->parse_html_to_structured($html, $site_url);

        $title = get_the_title($post_id);
        if ($title && (empty($parsed['headings']) || $parsed['headings'][0] !== $title)) {
            array_unshift($parsed['headings'], $title);
            $parsed['full_text'] = $title . "\n" . $parsed['full_text'];
        }

        // Extract SEO signals from WordPress/Rank Math
        $meta_description = '';
        $meta_robots      = '';
        $canonical_url    = '';
        $schema_types     = [];

        // Try Rank Math first, then Yoast
        $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
        if ($rm_desc) {
            $meta_description = $rm_desc;
        } else {
            $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if ($yoast_desc) {
                $meta_description = $yoast_desc;
            }
        }

        $rm_robots = get_post_meta($post_id, 'rank_math_robots', true);
        if (is_array($rm_robots)) {
            $meta_robots = implode(', ', $rm_robots);
        }

        if (function_exists('wp_get_canonical_url')) {
            $canonical_url = wp_get_canonical_url($post_id) ?: '';
        }

        // Extract schema @type values from ld+json in the rendered HTML
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $schema_matches)) {
            foreach ($schema_matches[1] as $json_str) {
                $schema_data = json_decode(trim($json_str), true);
                if (is_array($schema_data) && isset($schema_data['@type'])) {
                    $types = (array) $schema_data['@type'];
                    $schema_types = array_merge($schema_types, $types);
                }
                if (is_array($schema_data) && isset($schema_data['@graph'])) {
                    foreach ($schema_data['@graph'] as $node) {
                        if (isset($node['@type'])) {
                            $types = (array) $node['@type'];
                            $schema_types = array_merge($schema_types, $types);
                        }
                    }
                }
            }
            $schema_types = array_unique($schema_types);
        }

        // Capture the full-page on-page/schema signals the body parser can't see
        // (rendered head schema + meta, tap-to-call / NAP / map embed). Hybrid: an
        // in-process Rank Math floor (cache-immune) overlaid with the live rendered
        // <head> when the loopback fetch succeeds. signals_schema_version is ALWAYS
        // sent so the webhook stamps signals_captured_at off the sentinel — never off
        // a boolean's truthiness (a measured has_tel_link=false must still count as
        // measured). A NULL boolean means the fetch couldn't be trusted (not absent).
        $signals = $this->capture_rendered_signals($post, array_values($schema_types), $skip_fetch);

        $payload = [
            'action'   => 'linkfinder_page_push',
            'site_url' => $site_url,
            'api_key'  => $api_key,
            'post'     => [
                'id'                => $post_id,
                'url'               => get_permalink($post_id),
                'title'             => $title,
                'modified_at'       => mysql_to_rfc3339($post->post_modified_gmt),
                'published_at'      => mysql_to_rfc3339($post->post_date_gmt),
                'builder'           => $builder,
                'headings'          => $parsed['headings'],
                'paragraphs'        => $parsed['paragraphs'],
                'links'             => $parsed['links'],
                'full_text'         => $parsed['full_text'],
                'content_hash'      => md5($post->post_modified_gmt . '|' . substr($html, 0, 50000)),
                'image_count'       => $parsed['image_count'],
                'image_alts'        => $parsed['image_alts'],
                'has_featured_image' => has_post_thumbnail($post_id),
                'meta_description'  => $meta_description,
                'meta_robots'       => $meta_robots,
                'canonical_url'     => $canonical_url,
                'schema_types'      => $signals['schema_types'],
                // Phase 1 full-page on-page/schema capture (v1.17.0). The webhook
                // routes these to a split UPDATE gated on signals_schema_version so a
                // versionless (old-plugin) push can't null a prior capture.
                'rendered_meta_description' => $signals['rendered_meta_description'],
                'has_localbusiness_schema'  => $signals['has_localbusiness_schema'],
                'has_tel_link'              => $signals['has_tel_link'],
                'has_map_embed'             => $signals['has_map_embed'],
                'nap_on_page'               => $signals['nap_on_page'],
                'head_fetched'              => $signals['head_fetched'],
                'signals_schema_version'    => 1,
            ],
        ];
        if ($session_id) {
            $payload['session_id'] = $session_id;
        }

        $headers = ['Content-Type' => 'application/json'];
        if ($supabase_key) {
            $headers['apikey']        = $supabase_key;
            $headers['Authorization'] = 'Bearer ' . $supabase_key;
        }

        $response = wp_remote_post($webhook_url, [
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('[Superman LinkFinder] push failed: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body = wp_remote_retrieve_body($response);
            error_log('[Superman LinkFinder] push failed: HTTP ' . $code . ' — ' . substr($body, 0, 500));
            return false;
        }

        return true;
    }

    /**
     * On every post save, schedule a delayed LinkFinder push (debounced via
     * WP-Cron — multiple saves of the same post within 5 sec collapse to one event).
     */
    public function on_post_save_linkfinder_push($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }
        if (!in_array($post->post_type, ['post', 'page'], true)) {
            return;
        }
        if (!get_option('superman_links_webhook_url')) {
            return;
        }

        // wp_schedule_single_event dedupes identical (hook + args) within the
        // same window, so rapid saves of the same post collapse automatically.
        if (!wp_next_scheduled('superman_linkfinder_push_event', [$post_id])) {
            wp_schedule_single_event(time() + 5, 'superman_linkfinder_push_event', [$post_id]);
        }
    }

    /**
     * Send a bulk session signal (start/complete) to the Supabase webhook.
     */
    private function push_bulk_signal($action, $session_id, $extra) {
        $webhook_url  = get_option('superman_links_webhook_url');
        $api_key      = get_option('superman_links_api_key');
        $supabase_key = get_option('superman_links_supabase_key');
        if (!$webhook_url || !$api_key) {
            return;
        }

        $payload = array_merge([
            'action'     => $action,
            'site_url'   => get_site_url(),
            'api_key'    => $api_key,
            'session_id' => $session_id,
        ], $extra);

        $headers = ['Content-Type' => 'application/json'];
        if ($supabase_key) {
            $headers['apikey']        = $supabase_key;
            $headers['Authorization'] = 'Bearer ' . $supabase_key;
        }

        wp_remote_post($webhook_url, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
        ]);
    }

    /**
     * Start a bulk push session. Reads all published post + page IDs into a
     * persistent queue and schedules the recurring tick.
     */
    public function linkfinder_bulk_push_start() {
        $query = new WP_Query([
            'post_type'      => ['post', 'page'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $post_ids = array_map('intval', $query->posts);
        wp_reset_postdata();

        $session_id = wp_generate_uuid4();
        $queue = [
            'session_id' => $session_id,
            'post_ids'   => array_values($post_ids),
            'total'      => count($post_ids),
            'done'       => 0,
            'started_at' => time(),
        ];
        update_option('superman_linkfinder_push_queue', $queue, false);

        // Notify Supabase: bulk start
        $this->push_bulk_signal('linkfinder_bulk_start', $session_id, ['total' => $queue['total']]);

        // Schedule recurring tick
        if (!wp_next_scheduled('superman_linkfinder_bulk_push_tick')) {
            wp_schedule_event(time() + 5, 'minute', 'superman_linkfinder_bulk_push_tick');
        }

        return $queue;
    }

    /**
     * Process up to 10 pages from the bulk push queue. Called by WP-Cron AND
     * by the admin page JS polling (so the queue keeps moving even if WP-Cron stalls).
     *
     * Batch lowered 30 -> 10 in v1.17.0: each push now does a per-post loopback
     * full-page fetch (on-page signal capture), so a 30-post batch could block for
     * minutes and trip PHP max_execution_time / WP-Cron timeouts on shared hosting
     * (SiteGround). Smaller batches + a short fetch timeout keep each tick bounded;
     * the JS poller and WP-Cron both advance the queue so total throughput is fine.
     */
    public function linkfinder_bulk_push_tick() {
        $queue = get_option('superman_linkfinder_push_queue');
        if (!$queue || empty($queue['post_ids'])) {
            wp_clear_scheduled_hook('superman_linkfinder_bulk_push_tick');
            return;
        }

        $batch = array_splice($queue['post_ids'], 0, 10);
        foreach ($batch as $post_id) {
            $this->linkfinder_push_post($post_id, $queue['session_id']);
            usleep(200000); // 200ms between pushes
        }
        $queue['done'] += count($batch);
        update_option('superman_linkfinder_push_queue', $queue, false);

        if (empty($queue['post_ids'])) {
            $this->push_bulk_signal('linkfinder_bulk_complete', $queue['session_id'], []);
            delete_option('superman_linkfinder_push_queue');
            wp_clear_scheduled_hook('superman_linkfinder_bulk_push_tick');
        }
    }

    /**
     * Manual stop — clear the queue and unschedule the tick.
     */
    public function linkfinder_bulk_push_stop() {
        delete_option('superman_linkfinder_push_queue');
        wp_clear_scheduled_hook('superman_linkfinder_bulk_push_tick');
    }

    /**
     * Read the current queue state for the admin UI.
     */
    public function linkfinder_bulk_push_status() {
        $queue = get_option('superman_linkfinder_push_queue');
        if (!$queue) {
            return ['status' => 'idle'];
        }
        return [
            'status'     => empty($queue['post_ids']) ? 'completing' : 'in_progress',
            'session_id' => $queue['session_id'],
            'total'      => $queue['total'],
            'done'       => $queue['done'],
            'remaining'  => count($queue['post_ids']),
            'started_at' => $queue['started_at'],
        ];
    }

    // REST endpoints for the admin page bulk push controls

    public function rest_linkfinder_start($request) {
        $queue = $this->linkfinder_bulk_push_start();
        return rest_ensure_response([
            'session_id' => $queue['session_id'],
            'total'      => $queue['total'],
        ]);
    }

    public function rest_linkfinder_stop($request) {
        $this->linkfinder_bulk_push_stop();
        return rest_ensure_response(['ok' => true]);
    }

    public function rest_linkfinder_status($request) {
        return rest_ensure_response($this->linkfinder_bulk_push_status());
    }

    public function rest_linkfinder_tick($request) {
        // Manual tick called from the admin page JS polling — keeps the queue
        // moving even if WP-Cron is unreliable.
        $this->linkfinder_bulk_push_tick();
        return rest_ensure_response($this->linkfinder_bulk_push_status());
    }

    // ==========================================
    // Internal Links Methods
    // ==========================================

    /**
     * Crawl all published posts/pages for internal links
     */
    public function get_internal_links($request) {
        $per_page = min($request->get_param('per_page'), 500);
        $page = $request->get_param('page');
        $site_url = get_site_url();
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);

        $links = [];

        // 1. Content links from posts/pages
        $query = new WP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        $posts_crawled = $query->found_posts;

        foreach ($query->posts as $post) {
            $source_url = get_permalink($post->ID);

            // Parse standard content links
            $content_links = $this->extract_links_from_html($post->post_content, $site_host);
            foreach ($content_links as $link) {
                $links[] = array_merge($link, [
                    'source_post_id' => $post->ID,
                    'source_url' => $source_url,
                    'category' => 'content',
                ]);
            }

            // Also parse Elementor JSON for links
            if ($this->is_elementor_post($post->ID)) {
                $elementor_links = $this->extract_elementor_links($post->ID, $site_host);
                foreach ($elementor_links as $link) {
                    $links[] = array_merge($link, [
                        'source_post_id' => $post->ID,
                        'source_url' => $source_url,
                        'category' => 'content',
                    ]);
                }
            }
        }
        wp_reset_postdata();

        // 2. Menu links
        $menu_links = $this->extract_menu_links($site_host);
        $links = array_merge($links, $menu_links);

        // 3. Footer widget links
        $footer_links = $this->extract_footer_links($site_host);
        $links = array_merge($links, $footer_links);

        // Deduplicate by source_url + target_url (keep first occurrence)
        $seen = [];
        $unique_links = [];
        foreach ($links as $link) {
            $key = ($link['source_url'] ?? '') . '|' . $link['target_url'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique_links[] = $link;
            }
        }

        // Paginate
        $total = count($unique_links);
        $offset = ($page - 1) * $per_page;
        $paginated = array_slice($unique_links, $offset, $per_page);

        return rest_ensure_response([
            'links' => array_values($paginated),
            'total' => $total,
            'posts_crawled' => $posts_crawled,
            'total_pages' => (int) ceil($total / max($per_page, 1)),
            'current_page' => $page,
            'per_page' => $per_page,
        ]);
    }

    /**
     * Insert an internal link into a page's content
     */
    public function insert_internal_link($request) {
        $source_post_id = $request->get_param('source_post_id');
        $target_url = $request->get_param('target_url');
        $anchor_text = $request->get_param('anchor_text');
        $anchor_text = wp_strip_all_tags(wp_kses_no_null(wp_check_invalid_utf8($anchor_text)));
        $match_context = $request->get_param('match_context');
        if ($match_context) {
            $match_context = wp_strip_all_tags(wp_kses_no_null(wp_check_invalid_utf8($match_context)));
        }

        $post = get_post($source_post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error(
                'not_found',
                __('Source post not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        // Check if link already exists in content or Elementor data
        $is_elementor = $this->is_elementor_post($source_post_id);
        $content_has_link = strpos($post->post_content, $target_url) !== false;
        $elementor_has_link = false;

        if ($is_elementor) {
            $elementor_data = get_post_meta($source_post_id, '_elementor_data', true);
            $elementor_has_link = !empty($elementor_data) && strpos($elementor_data, $target_url) !== false;
        }

        if ($content_has_link || $elementor_has_link) {
            return new WP_Error(
                'link_exists',
                __('Link to this URL already exists in the content.', 'superman-links'),
                ['status' => 409]
            );
        }

        if ($is_elementor) {
            $result = $this->insert_link_elementor($source_post_id, $target_url, $anchor_text, $match_context);
        } else {
            $result = $this->insert_link_standard($source_post_id, $target_url, $anchor_text, $match_context);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'source_post_id' => $source_post_id,
            'target_url' => $target_url,
            'anchor_text' => $anchor_text,
            'is_elementor' => $is_elementor,
            'mode' => is_array($result) && isset($result['mode']) ? $result['mode'] : 'append',
        ]);
    }

    /**
     * DELETE /internal-links — unwrap an <a> tag matching target_url.
     *
     * Walks the post HTML (or Elementor text-editor widgets), finds the
     * first <a href="$target_url"> element, and replaces it with its inner
     * text content. Saving the post fires save_post → wordpress-webhook
     * → wp_page_content re-index, which the CRM uses to confirm removal.
     *
     * Returns 200 on success, 404 with code=link_not_found if no matching
     * anchor was located (CRM treats this as "needs manual review").
     */
    public function delete_internal_link($request) {
        $source_post_id = $request->get_param('source_post_id');
        $target_url = $request->get_param('target_url');

        $post = get_post($source_post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error(
                'not_found',
                __('Source post not found.', 'superman-links'),
                ['status' => 404]
            );
        }

        $is_elementor = $this->is_elementor_post($source_post_id);
        $unwrapped_anywhere = false;

        if ($is_elementor) {
            $elementor_data = get_post_meta($source_post_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
                if (is_array($data)) {
                    if ($this->unwrap_in_elementor_text_editors($data, $target_url)) {
                        update_post_meta($source_post_id, '_elementor_data', wp_json_encode($data));
                        $this->regenerate_elementor_css($source_post_id);
                        $unwrapped_anywhere = true;
                    }
                }
            }
        }

        // Always also try post_content (Elementor pages may also have legacy
        // post_content, e.g. our previous "Related:" appends).
        if (!empty($post->post_content)) {
            $unwrapped_html = $this->unwrap_anchor_in_html($post->post_content, $target_url);
            if ($unwrapped_html !== null) {
                $result = wp_update_post([
                    'ID' => $source_post_id,
                    'post_content' => $unwrapped_html,
                ], true);
                if (is_wp_error($result)) {
                    return $result;
                }
                $unwrapped_anywhere = true;
            }
        }

        if (!$unwrapped_anywhere) {
            return new WP_Error(
                'link_not_found',
                __('No anchor pointing to that URL was found in the page content. The link may have been removed manually.', 'superman-links'),
                ['status' => 404]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'source_post_id' => $source_post_id,
            'target_url' => $target_url,
            'is_elementor' => $is_elementor,
        ]);
    }

    /**
     * Walk Elementor element tree, unwrapping the first matching anchor in
     * any text-editor widget. Mutates $elements in place. Returns true on
     * first successful unwrap.
     */
    private function unwrap_in_elementor_text_editors(&$elements, $target_url) {
        if (!is_array($elements)) return false;
        $unwrapped = false;
        foreach ($elements as &$el) {
            if (!is_array($el)) continue;
            if (!empty($el['elements'])) {
                if ($this->unwrap_in_elementor_text_editors($el['elements'], $target_url)) {
                    $unwrapped = true;
                    // Continue scanning siblings for additional matches in
                    // case the same anchor was inserted in multiple widgets,
                    // but for v1 we stop at first hit.
                    return true;
                }
            }
            if (isset($el['widgetType']) && $el['widgetType'] === 'text-editor') {
                $editor_html = $el['settings']['editor'] ?? '';
                $new_html = $this->unwrap_anchor_in_html($editor_html, $target_url);
                if ($new_html !== null) {
                    $el['settings']['editor'] = $new_html;
                    return true;
                }
            }
        }
        return $unwrapped;
    }

    /**
     * Unwrap the first <a href="$target_url"> in the given HTML by replacing
     * the anchor element with a text node containing its inner text. Returns
     * the modified HTML or null if no matching anchor was found.
     */
    private function unwrap_anchor_in_html($html, $target_url) {
        if (empty($html)) return null;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="superman-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) return null;

        $root = $doc->getElementById('superman-root');
        if (!$root) $root = $doc->documentElement;

        $xpath = new DOMXPath($doc);
        $anchors = $xpath->query('.//a[@href]', $root);
        $target_norm = $this->normalize_url_for_compare($target_url);

        $matched = null;
        foreach ($anchors as $a) {
            $href = $a->getAttribute('href');
            if ($this->normalize_url_for_compare($href) === $target_norm) {
                $matched = $a;
                break;
            }
        }

        if (!$matched) return null;

        // Special case: if this <a> is the only child of a paragraph that
        // looks like our legacy "Related:" insert, remove the whole <p>.
        $parent = $matched->parentNode;
        if (
            $parent && $parent->nodeName === 'p' &&
            $parent->getAttribute('class') === 'superman-internal-link'
        ) {
            $parent->parentNode->removeChild($parent);
        } else {
            // Replace the anchor with a text node of its inner text.
            $text_content = $matched->textContent;
            $text_node = $doc->createTextNode($text_content);
            $matched->parentNode->replaceChild($text_node, $matched);
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Normalize a URL for comparison: lowercase host, strip trailing slash,
     * strip www., preserve path + query.
     */
    private function normalize_url_for_compare($url) {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $host = strtolower(preg_replace('/^www\./', '', $parsed['host']));
        $path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
        if ($path === '') $path = '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        return $host . $path . $query;
    }

    /**
     * Extract text from a DOM node, inserting spaces between child element
     * boundaries. Prevents DOMDocument::textContent from concatenating
     * adjacent inline elements without whitespace (e.g. "rot.Carpenter").
     */
    private function extract_text_with_spaces($node) {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $childText = $this->extract_text_with_spaces($child);
                if ($childText !== '' && $text !== '' && !preg_match('/\s$/', $text) && !preg_match('/^\s/', $childText)) {
                    $text .= ' ';
                }
                $text .= $childText;
            }
        }
        return $text;
    }

    /**
     * Normalize whitespace for fuzzy text matching: collapse runs of
     * whitespace to a single space and trim. Used to align extracted plain
     * text against indexed sentence context.
     */
    private function normalize_whitespace($text) {
        return trim(preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Normalize typographic variants in a preg_quote'd token so matching
     * works regardless of whether WordPress texturized the content.
     * Covers: single quotes, double quotes, dashes, ellipsis.
     */
    private function normalize_quotes_for_regex($escaped_token) {
        // Single quotes / apostrophes: ' ' ' ′ `
        $single_class = "['\\x{2018}\\x{2019}\\x{2032}\\x{0060}]";
        $single_pattern = "/['\\x{2018}\\x{2019}\\x{2032}\\x{0060}]/u";
        $result = preg_replace($single_pattern, $single_class, $escaped_token);

        // Double quotes: " " " ″
        $double_class = "[\"\\x{201C}\\x{201D}\\x{2033}]";
        $double_pattern = "/[\"\\x{201C}\\x{201D}\\x{2033}]/u";
        $result = preg_replace($double_pattern, $double_class, $result);

        // Dashes: -- or — or – (em dash, en dash)
        // preg_quote escapes hyphens, so match the escaped form too
        $dash_class = "(?:\\-\\-|\\x{2014}|\\x{2013})";
        $result = preg_replace("/\\x{2014}|\\x{2013}/u", $dash_class, $result);
        $result = preg_replace("/\\\\-\\\\-/u", $dash_class, $result);

        // Ellipsis: ... or …
        $ellipsis_class = "(?:\\.{3}|\\x{2026})";
        $result = preg_replace("/\\x{2026}/u", $ellipsis_class, $result);
        $result = preg_replace("/\\\\\.\\\\\.\\\\\./u", $ellipsis_class, $result);

        return $result;
    }

    /**
     * Wrap the first occurrence of $anchor_text in $html with an <a> tag,
     * scoped to the region of $html whose plain-text contains $context.
     *
     * Returns the modified HTML on success or null if either the context
     * or the anchor could not be located. Operates over text nodes only,
     * so existing tags/attributes are never corrupted.
     */
    private function wrap_anchor_in_html($html, $context, $anchor_text, $target_url) {
        if (empty($html) || empty($anchor_text)) {
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Wrap to give DOMDocument a single root + force UTF-8 handling.
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="superman-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) {
            return null;
        }

        $root = $doc->getElementById('superman-root');
        if (!$root) {
            $root = $doc->documentElement;
        }

        // Walk all text nodes, building a flat plain-text string and tracking
        // (node, offset_in_node) for each character so we can map back.
        $text_nodes = [];
        $flat = '';
        $map  = []; // index in $flat -> [node_index, offset_in_node]
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('.//text()', $root);
        foreach ($nodes as $node) {
            // Skip text inside existing <a> tags — never nest links.
            $skip = false;
            for ($p = $node->parentNode; $p && $p !== $root; $p = $p->parentNode) {
                if ($p->nodeName === 'a') { $skip = true; break; }
            }
            if ($skip) continue;

            $text_nodes[] = $node;
            $idx = count($text_nodes) - 1;
            $value = $node->nodeValue;
            $len = strlen($value);
            for ($i = 0; $i < $len; $i++) {
                $map[strlen($flat) + $i] = [$idx, $i];
            }
            $flat .= $value;
        }

        if ($flat === '') {
            return null;
        }

        // Search the flat plain-text using a whitespace-tolerant regex so
        // line breaks / multiple spaces between words don't kill the match.
        // We track byte offsets back to the original $flat positions.

        $build_pattern = function ($needle) {
            $needle_norm = $this->normalize_whitespace($needle);
            if ($needle_norm === '') return null;
            $tokens = preg_split('/\s+/u', $needle_norm);
            $escaped = array_map(function ($t) {
                $quoted = preg_quote($t, '/');
                return $this->normalize_quotes_for_regex($quoted);
            }, $tokens);
            return '/' . implode('\s+', $escaped) . '/iu';
        };

        $context_norm = $this->normalize_whitespace($context);
        $context_start = -1;
        $context_end = -1;
        if ($context_norm !== '') {
            $pattern = $build_pattern($context_norm);
            if ($pattern && preg_match($pattern, $flat, $m, PREG_OFFSET_CAPTURE)) {
                $context_start = $m[0][1];
                $context_end = $context_start + strlen($m[0][0]);
            }
        }

        if ($context_norm !== '' && $context_start === -1) {
            // Strict mode: caller required context but it isn't on the page
            return null;
        }

        // Search for the anchor text — within the context range if we have
        // one, otherwise in the entire flat string.
        $haystack = $flat;
        $offset = 0;
        if ($context_start !== -1) {
            $haystack = substr($flat, $context_start, $context_end - $context_start);
            $offset = $context_start;
        }
        $anchor_pattern = $build_pattern($anchor_text);
        if (!$anchor_pattern || !preg_match($anchor_pattern, $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $anchor_start = $offset + $m[0][1];
        $anchor_end   = $anchor_start + strlen($m[0][0]);
        $matched_text = substr($flat, $anchor_start, $anchor_end - $anchor_start);

        // Map start/end back to (text_node, char offset). Both endpoints must
        // land inside text nodes that are in $map.
        if (!isset($map[$anchor_start]) || !isset($map[$anchor_end - 1])) {
            return null;
        }
        list($start_node_idx, $start_off) = $map[$anchor_start];
        list($end_node_idx, $end_off) = $map[$anchor_end - 1];
        $end_off += 1; // exclusive end

        // We only handle the case where the anchor sits inside a single text
        // node (overwhelmingly the common case). If the match spans multiple
        // text nodes (e.g. <strong> in the middle), bail out gracefully.
        if ($start_node_idx !== $end_node_idx) {
            return null;
        }

        $node = $text_nodes[$start_node_idx];
        $original = $node->nodeValue;
        $before = substr($original, 0, $start_off);
        $after  = substr($original, $end_off);

        // Build the replacement: text-before + <a>matched_text</a> + text-after
        $parent = $node->parentNode;
        if ($before !== '') {
            $parent->insertBefore($doc->createTextNode($before), $node);
        }
        $a = $doc->createElement('a');
        $a->setAttribute('href', $target_url);
        $a->appendChild($doc->createTextNode($matched_text));
        $parent->insertBefore($a, $node);
        if ($after !== '') {
            $parent->insertBefore($doc->createTextNode($after), $node);
        }
        $parent->removeChild($node);

        // Serialize the contents of #superman-root back to HTML.
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Diagnostic: is the context sentence un-matchable because part of it is
     * ALREADY a link? wrap_anchor_in_html() excludes text inside <a> tags, so a
     * sentence whose anchor (or any phrase) is already linked can never match and
     * returns null. Used only to produce an accurate error message — not for the
     * insert itself. Returns true if any existing <a> text sits inside $context.
     */
    private function sentence_overlaps_existing_link($html, $context) {
        if (empty($html) || empty($context)) {
            return false;
        }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="superman-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        $context_norm = strtolower($this->normalize_whitespace($context));
        if ($context_norm === '') {
            return false;
        }
        foreach ($xpath->query('//a') as $a) {
            $atext = strtolower($this->normalize_whitespace($a->textContent));
            if (strlen($atext) > 2 &&
                (strpos($context_norm, $atext) !== false || strpos($atext, $context_norm) !== false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract links from HTML string (shared helper for content + widgets)
     */
    private function extract_links_from_html($html, $site_host) {
        $links = [];

        if (empty($html)) {
            return $links;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<meta charset="UTF-8">' . $html);
        libxml_clear_errors();

        $anchors = $doc->getElementsByTagName('a');

        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            if (empty($href) || $href === '#') {
                continue;
            }

            $anchor_text = trim($anchor->textContent);

            // Resolve relative URLs
            if (strpos($href, '/') === 0) {
                $href = rtrim(get_site_url(), '/') . $href;
            }

            // Skip non-HTTP links (mailto, tel, javascript, etc.)
            if (strpos($href, 'http') !== 0) {
                continue;
            }

            // Check if same-domain
            $link_host = wp_parse_url($href, PHP_URL_HOST);
            if (!$link_host || $link_host !== $site_host) {
                continue;
            }

            // Get context from parent element
            $parent = $anchor->parentNode;
            $context = $parent ? mb_substr(trim($parent->textContent), 0, 200) : '';

            $target_post_id = url_to_postid($href);

            $links[] = [
                'target_url' => $href,
                'target_post_id' => $target_post_id ?: null,
                'anchor_text' => $anchor_text,
                'link_context' => $context,
            ];
        }

        return $links;
    }

    /**
     * Extract links from Elementor JSON data
     */
    private function extract_elementor_links($post_id, $site_host) {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);

        if (empty($elementor_data)) {
            return [];
        }

        $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
        if (!is_array($data)) {
            return [];
        }

        $links = [];
        $this->walk_elementor_for_links($data, $site_host, $links);

        return $links;
    }

    /**
     * Recursively walk Elementor data to find links
     */
    private function walk_elementor_for_links($elements, $site_host, &$links) {
        foreach ($elements as $element) {
            // Text-editor widgets contain HTML with links
            if (isset($element['widgetType']) && $element['widgetType'] === 'text-editor') {
                $editor_content = $element['settings']['editor'] ?? '';
                if (!empty($editor_content)) {
                    $extracted = $this->extract_links_from_html($editor_content, $site_host);
                    $links = array_merge($links, $extracted);
                }
            }

            // Button widgets may have internal link URLs
            if (isset($element['widgetType']) && $element['widgetType'] === 'button') {
                $url = $element['settings']['link']['url'] ?? '';
                if (!empty($url)) {
                    if (strpos($url, '/') === 0) {
                        $url = rtrim(get_site_url(), '/') . $url;
                    }
                    $link_host = wp_parse_url($url, PHP_URL_HOST);
                    if ($link_host && $link_host === $site_host) {
                        $links[] = [
                            'target_url' => $url,
                            'target_post_id' => url_to_postid($url) ?: null,
                            'anchor_text' => $element['settings']['text'] ?? '',
                            'link_context' => 'Elementor button widget',
                        ];
                    }
                }
            }

            // Recurse into child elements
            if (!empty($element['elements'])) {
                $this->walk_elementor_for_links($element['elements'], $site_host, $links);
            }
        }
    }

    /**
     * Extract links from WordPress navigation menus
     */
    private function extract_menu_links($site_host) {
        $links = [];
        $menus = wp_get_nav_menus();

        if (empty($menus)) {
            return $links;
        }

        // Identify footer menu locations
        $locations = get_nav_menu_locations();
        $footer_menu_ids = [];
        foreach ($locations as $location => $menu_id) {
            if (stripos($location, 'footer') !== false) {
                $footer_menu_ids[] = $menu_id;
            }
        }

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (!$items) {
                continue;
            }

            $is_footer = in_array($menu->term_id, $footer_menu_ids);
            $category = $is_footer ? 'footer' : 'menu';

            foreach ($items as $item) {
                $href = $item->url;
                if (empty($href) || $href === '#') {
                    continue;
                }

                $link_host = wp_parse_url($href, PHP_URL_HOST);
                if (!$link_host || $link_host !== $site_host) {
                    continue;
                }

                $target_post_id = url_to_postid($href);

                $links[] = [
                    'source_post_id' => null,
                    'source_url' => null,
                    'target_url' => $href,
                    'target_post_id' => $target_post_id ?: null,
                    'anchor_text' => $item->title,
                    'link_context' => 'Menu: ' . $menu->name,
                    'category' => $category,
                ];
            }
        }

        return $links;
    }

    /**
     * Extract links from footer sidebar widgets
     */
    private function extract_footer_links($site_host) {
        $links = [];
        $sidebars = wp_get_sidebars_widgets();

        if (empty($sidebars)) {
            return $links;
        }

        foreach ($sidebars as $sidebar_id => $widget_ids) {
            if (stripos($sidebar_id, 'footer') === false) {
                continue;
            }
            if (!is_array($widget_ids)) {
                continue;
            }

            foreach ($widget_ids as $widget_id) {
                $widget_content = $this->get_widget_content($widget_id);
                if (empty($widget_content)) {
                    continue;
                }

                $extracted = $this->extract_links_from_html($widget_content, $site_host);
                foreach ($extracted as $link) {
                    $links[] = array_merge($link, [
                        'source_post_id' => null,
                        'source_url' => null,
                        'category' => 'footer',
                    ]);
                }
            }
        }

        return $links;
    }

    /**
     * Get widget content by widget ID
     */
    private function get_widget_content($widget_id) {
        // Widget IDs are in format: type-number (e.g., "text-2", "custom_html-3")
        $parts = explode('-', $widget_id);
        $number = array_pop($parts);
        $type = implode('-', $parts);

        if (!is_numeric($number)) {
            return '';
        }

        $option = get_option("widget_{$type}");
        if (!is_array($option) || !isset($option[(int) $number])) {
            return '';
        }

        $instance = $option[(int) $number];

        // Return common content fields used by text/HTML widgets
        return $instance['text'] ?? $instance['content'] ?? $instance['html'] ?? '';
    }

    /**
     * Insert a link into a standard (non-Elementor) page
     */
    private function insert_link_standard($post_id, $target_url, $anchor_text, $match_context = null) {
        $post = get_post($post_id);

        // Try in-place wrap first when caller provided sentence context
        if (!empty($match_context)) {
            $wrapped = $this->wrap_anchor_in_html($post->post_content, $match_context, $anchor_text, $target_url);
            if ($wrapped !== null) {
                $result = wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $wrapped,
                ], true);
                if (is_wp_error($result)) {
                    return $result;
                }
                return ['mode' => 'wrap'];
            }
            // Strict mode: context was provided but the wrap failed. Distinguish the
            // two real causes so operators don't chase a phantom "stale index".
            if ($this->sentence_overlaps_existing_link($post->post_content, $match_context)) {
                return new WP_Error(
                    'anchor_already_linked',
                    __('That sentence already contains a link on this page, so the anchor cannot be wrapped (the plugin never nests links). Nothing was changed — edit the page manually if you want to repoint the existing link.', 'superman-links'),
                    ['status' => 422]
                );
            }
            return new WP_Error(
                'context_not_found',
                __('Could not locate that sentence in the page content. The page may use a builder/shortcode that stores text differently than it renders, or the content changed since the last sync.', 'superman-links'),
                ['status' => 422]
            );
        }

        // No context: legacy "Related:" append behavior
        $link_html = sprintf(
            "\n" . '<p class="superman-internal-link">Related: <a href="%s">%s</a></p>',
            esc_url($target_url),
            esc_html($anchor_text)
        );

        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $post->post_content . $link_html,
        ], true);

        if (is_wp_error($result)) {
            return $result;
        }

        return ['mode' => 'append'];
    }

    /**
     * Insert a link into an Elementor page's last text-editor widget
     */
    private function insert_link_elementor($post_id, $target_url, $anchor_text, $match_context = null) {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);

        if (empty($elementor_data)) {
            return $this->insert_link_standard($post_id, $target_url, $anchor_text, $match_context);
        }

        $data = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
        if (!is_array($data)) {
            return $this->insert_link_standard($post_id, $target_url, $anchor_text, $match_context);
        }

        // Try in-place wrap first when caller provided sentence context
        if (!empty($match_context)) {
            $wrapped = $this->wrap_in_elementor_text_editors($data, $match_context, $anchor_text, $target_url);
            if ($wrapped) {
                update_post_meta($post_id, '_elementor_data', wp_json_encode($data));
                $this->regenerate_elementor_css($post_id);
                return ['mode' => 'wrap'];
            }
            // Elementor text-editor widgets didn't contain the context —
            // fall back to post_content (handles theme-post-content widgets,
            // shortcode widgets, and other non-text-editor sources).
            return $this->insert_link_standard($post_id, $target_url, $anchor_text, $match_context);
        }

        // No context: legacy append-to-last-text-editor behavior
        $link_html = sprintf(
            '<p class="superman-internal-link">Related: <a href="%s">%s</a></p>',
            esc_url($target_url),
            esc_html($anchor_text)
        );

        $modified = $this->append_to_last_text_editor($data, $link_html);

        if (!$modified) {
            return $this->insert_link_standard($post_id, $target_url, $anchor_text, $match_context);
        }

        update_post_meta($post_id, '_elementor_data', wp_json_encode($data));
        $this->regenerate_elementor_css($post_id);

        return ['mode' => 'append'];
    }

    /**
     * Walk Elementor element tree and try to wrap the anchor inside the
     * first text-editor widget whose HTML contains the context. Mutates
     * $elements in place. Returns true on first successful wrap.
     */
    private function wrap_in_elementor_text_editors(&$elements, $context, $anchor_text, $target_url) {
        if (!is_array($elements)) return false;
        foreach ($elements as &$el) {
            if (!is_array($el)) continue;
            if (!empty($el['elements'])) {
                if ($this->wrap_in_elementor_text_editors($el['elements'], $context, $anchor_text, $target_url)) {
                    return true;
                }
            }
            if (isset($el['widgetType']) && $el['widgetType'] === 'text-editor') {
                $editor_html = $el['settings']['editor'] ?? '';
                $wrapped = $this->wrap_anchor_in_html($editor_html, $context, $anchor_text, $target_url);
                if ($wrapped !== null) {
                    $el['settings']['editor'] = $wrapped;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Recursively find and append HTML to the last text-editor widget
     */
    private function append_to_last_text_editor(&$elements, $html) {
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            // Check child elements first (depth-first, last element)
            if (!empty($elements[$i]['elements'])) {
                if ($this->append_to_last_text_editor($elements[$i]['elements'], $html)) {
                    return true;
                }
            }

            if (isset($elements[$i]['widgetType']) && $elements[$i]['widgetType'] === 'text-editor') {
                $elements[$i]['settings']['editor'] = ($elements[$i]['settings']['editor'] ?? '') . $html;
                return true;
            }
        }

        return false;
    }

    /**
     * Regenerate Elementor CSS for a post
     */
    private function regenerate_elementor_css($post_id) {
        // Check if Elementor's CSS regeneration is available
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                // Clear the CSS file
                $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
                if ($css_file) {
                    $css_file->delete();
                    $css_file->update();
                }

                // Also clear Elementor's internal CSS cache
                if (method_exists('\Elementor\Plugin', 'instance')) {
                    $elementor = \Elementor\Plugin::instance();
                    if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                        $elementor->files_manager->clear_cache();
                    }
                }
            } catch (Exception $e) {
                // Log error but don't fail the request
                error_log('Superman Links: Failed to regenerate Elementor CSS - ' . $e->getMessage());
            }
        }
    }

    // ==========================================
    // Internal Link Juicer (ILJ) Integration
    // ==========================================

    /**
     * Check if Internal Link Juicer is active
     */
    /**
     * Resolve a RankMath redirect pattern/target to a full URL. RankMath stores
     * sources/targets as either absolute URLs or home-relative paths.
     */
    private function superman_resolve_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }
        return home_url('/' . ltrim($value, '/'));
    }

    /**
     * GET /redirects — active RankMath redirects, exact-match sources only.
     *
     * Returns gracefully ({redirects: [], rankmath: false}) when RankMath / its
     * redirections table is absent, so a RankMath-less site is a clean no-op rather
     * than a SQL error. Only `comparison='exact'` sources are emitted — regex/contains/
     * start/end patterns don't map 1:1 to a single page URL (v1 limit). The CRM matches
     * each `source` URL to a content-silo node and follows it to `url_to`.
     */
    public function get_rankmath_redirects($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'rank_math_redirections';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return rest_ensure_response(['redirects' => [], 'rankmath' => false, 'count' => 0]);
        }

        $rows = $wpdb->get_results(
            "SELECT id, sources, url_to, header_code, status FROM `{$table}` WHERE status = 'active'"
        );

        $out = [];
        foreach ((array) $rows as $row) {
            $sources = maybe_unserialize($row->sources);
            if (!is_array($sources)) {
                continue;
            }
            $target = $this->superman_resolve_url($row->url_to);
            if ($target === '') {
                continue;
            }
            foreach ($sources as $src) {
                if (!is_array($src)) {
                    continue;
                }
                $comparison = isset($src['comparison']) ? $src['comparison'] : '';
                $pattern = isset($src['pattern']) ? $src['pattern'] : '';
                if ($comparison !== 'exact' || $pattern === '') {
                    continue;
                }
                $source_url = $this->superman_resolve_url($pattern);
                if ($source_url === '' || $source_url === $target) {
                    continue; // skip self-referential / unresolved
                }
                $out[] = [
                    'source' => $source_url,
                    'url_to' => $target,
                    'header_code' => isset($row->header_code) ? (string) $row->header_code : '301',
                ];
            }
        }

        return rest_ensure_response(['redirects' => $out, 'rankmath' => true, 'count' => count($out)]);
    }

    private function is_ilj_active() {
        return defined('ILJ_VERSION') || is_plugin_active('internal-links/wp-internal-linkjuicer.php');
    }

    /**
     * GET /ilj/status — Check ILJ installation status
     */
    public function get_ilj_status($request) {
        $active = $this->is_ilj_active();
        $version = defined('ILJ_VERSION') ? ILJ_VERSION : null;
        $index_count = 0;
        $configured_posts = 0;

        if ($active) {
            global $wpdb;

            // Count link index entries
            $table = $wpdb->prefix . 'ilj_linkindex';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($table_exists) {
                $index_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            }

            // Count posts with ILJ keywords configured
            $configured_posts = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'ilj_linkdefinition'"
            );
        }

        return rest_ensure_response([
            'installed' => $active,
            'active' => $active,
            'version' => $version,
            'index_count' => $index_count,
            'configured_posts' => $configured_posts,
        ]);
    }

    /**
     * GET /ilj/index — Read ILJ link index with resolved URLs
     */
    public function get_ilj_index($request) {
        if (!$this->is_ilj_active()) {
            return new WP_Error(
                'ilj_not_active',
                __('Internal Link Juicer is not installed or active.', 'superman-links'),
                ['status' => 400]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ilj_linkindex';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return new WP_Error(
                'ilj_no_table',
                __('ILJ link index table not found.', 'superman-links'),
                ['status' => 400]
            );
        }

        $per_page = min($request->get_param('per_page'), 500);
        $page = max($request->get_param('page'), 1);
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $total_pages = ceil($total / $per_page);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, link_from, link_to, type_from, type_to, anchor FROM `{$table}` ORDER BY id ASC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        // Collect unique post IDs to resolve URLs in bulk
        $post_ids = [];
        foreach ($rows as $row) {
            if ($row->type_from === 'post') $post_ids[] = (int) $row->link_from;
            if ($row->type_to === 'post') $post_ids[] = (int) $row->link_to;
        }
        $post_ids = array_unique($post_ids);

        // Build post ID → URL map
        $url_map = [];
        foreach ($post_ids as $pid) {
            $permalink = get_permalink($pid);
            if ($permalink) {
                $url_map[$pid] = $permalink;
            }
        }

        $links = [];
        foreach ($rows as $row) {
            $from_id = (int) $row->link_from;
            $to_id = (int) $row->link_to;

            $links[] = [
                'link_from_id' => $from_id,
                'link_from_url' => $url_map[$from_id] ?? null,
                'link_to_id' => $to_id,
                'link_to_url' => $url_map[$to_id] ?? null,
                'anchor' => $row->anchor,
                'type_from' => $row->type_from,
                'type_to' => $row->type_to,
            ];
        }

        return rest_ensure_response([
            'links' => $links,
            'total' => $total,
            'current_page' => $page,
            'total_pages' => (int) $total_pages,
        ]);
    }

    /**
     * POST /ilj/keywords — Push keywords to a post's ILJ configuration
     */
    public function push_ilj_keywords($request) {
        if (!$this->is_ilj_active()) {
            return new WP_Error(
                'ilj_not_active',
                __('Internal Link Juicer is not installed or active.', 'superman-links'),
                ['status' => 400]
            );
        }

        $post_id = $request->get_param('post_id');
        $keywords = $request->get_param('keywords');

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error(
                'not_found',
                __('Post not found or not published.', 'superman-links'),
                ['status' => 404]
            );
        }

        if (!is_array($keywords) || empty($keywords)) {
            return new WP_Error(
                'invalid_keywords',
                __('Keywords must be a non-empty array of strings.', 'superman-links'),
                ['status' => 400]
            );
        }

        // Sanitize keywords
        $new_keywords = array_map('sanitize_text_field', $keywords);
        $new_keywords = array_filter($new_keywords);

        // Read existing ILJ keywords
        $existing = get_post_meta($post_id, 'ilj_linkdefinition', true);
        if (!is_array($existing)) {
            $existing = [];
        }

        // Merge and deduplicate (case-insensitive)
        $existing_lower = array_map('strtolower', $existing);
        foreach ($new_keywords as $kw) {
            if (!in_array(strtolower($kw), $existing_lower, true)) {
                $existing[] = $kw;
                $existing_lower[] = strtolower($kw);
            }
        }

        update_post_meta($post_id, 'ilj_linkdefinition', $existing);

        // Trigger ILJ index rebuild for this post if the action exists
        if (has_action('ilj_after_keywords_update')) {
            do_action('ilj_after_keywords_update', $post_id);
        }

        return rest_ensure_response([
            'success' => true,
            'post_id' => $post_id,
            'keyword_count' => count($existing),
            'keywords' => $existing,
        ]);
    }

    // ==========================================
    // Elementor Widget Patch (v1.12.0)
    // ==========================================

    public function patch_elementor_widget($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error('elementor_not_active', __('Elementor is not active on this site.', 'superman-links'), ['status' => 400]);
        }

        $post_id    = (int) $request['post_id'];
        $element_id = (string) $request['element_id'];
        $body       = $request->get_json_params();
        $patch      = isset($body['settings_patch']) && is_array($body['settings_patch']) ? $body['settings_patch'] : null;
        $expected   = isset($body['expected_widget_type']) ? (string) $body['expected_widget_type'] : null;

        if (!$patch) {
            return new WP_Error('invalid_patch', __('settings_patch is required and must be a non-empty object.', 'superman-links'), ['status' => 400]);
        }

        $result = $this->apply_widget_patches($post_id, [[
            'element_id'           => $element_id,
            'settings_patch'       => $patch,
            'expected_widget_type' => $expected,
        ]]);

        if (is_wp_error($result)) {
            return $result;
        }

        $first = $result['ops'][0];
        return rest_ensure_response([
            'success'        => true,
            'post_id'        => $post_id,
            'element_id'     => $first['element_id'],
            'widget_type'    => $first['widget_type'],
            'settings'       => $first['settings'],
            'unknown_keys'   => $first['unknown_keys'],
            'dropped_keys'   => $first['dropped_keys'],
            'round_trip_ok'  => empty($first['dropped_keys']),
        ]);
    }

    public function batch_patch_elementor_widgets($request) {
        if (!$this->is_elementor_active()) {
            return new WP_Error('elementor_not_active', __('Elementor is not active on this site.', 'superman-links'), ['status' => 400]);
        }

        $post_id = (int) $request['post_id'];
        $body    = $request->get_json_params();
        $ops_raw = isset($body['ops']) && is_array($body['ops']) ? $body['ops'] : null;

        if (!$ops_raw) {
            return new WP_Error('invalid_batch', __('ops array is required.', 'superman-links'), ['status' => 400]);
        }

        $normalized = [];
        foreach ($ops_raw as $i => $op) {
            if (!is_array($op) || empty($op['elementId']) || empty($op['settingsPatch']) || !is_array($op['settingsPatch'])) {
                return new WP_Error('invalid_batch_op', sprintf('Op %d missing elementId or settingsPatch.', $i), ['status' => 400]);
            }
            $normalized[] = [
                'element_id'           => (string) $op['elementId'],
                'settings_patch'       => $op['settingsPatch'],
                'expected_widget_type' => isset($op['expectedWidgetType']) ? (string) $op['expectedWidgetType'] : null,
            ];
        }

        $result = $this->apply_widget_patches($post_id, $normalized);
        if (is_wp_error($result)) {
            return $result;
        }

        $any_dropped = false;
        foreach ($result['ops'] as $op) {
            if (!empty($op['dropped_keys'])) {
                $any_dropped = true;
                break;
            }
        }

        return rest_ensure_response([
            'success'       => true,
            'post_id'       => $post_id,
            'ops'           => $result['ops'],
            'round_trip_ok' => !$any_dropped,
        ]);
    }

    /**
     * Apply N patches atomically: validate all, merge all into one in-memory
     * tree, save once, round-trip verify. Returns WP_Error on any failure
     * (post untouched) or array with per-op results.
     */
    private function apply_widget_patches($post_id, array $ops) {
        if (get_post_status($post_id) === false) {
            return new WP_Error('post_not_found', sprintf('Post %d not found.', $post_id), ['status' => 404]);
        }

        // Validate each patch's keys before touching the tree.
        $validations = [];
        foreach ($ops as $i => $op) {
            $check = $this->validate_patch_keys($op['settings_patch']);
            if (!empty($check['banned'])) {
                return new WP_Error('invalid_patch', sprintf('Op %d patch contains banned keys: %s', $i, implode(', ', $check['banned'])), ['status' => 400]);
            }
            $validations[$i] = $check;
        }

        $tree = $this->read_elementor_tree($post_id);
        if ($tree === null) {
            return new WP_Error('elementor_data_missing', sprintf('Post %d has no Elementor data.', $post_id), ['status' => 404]);
        }

        // Pre-walk: locate every target, assert widget + type, then mutate.
        $merged_per_op = [];
        foreach ($ops as $i => $op) {
            $element = &$this->walk_elementor_tree($tree, $op['element_id']);
            if ($element === null) {
                unset($element);
                return new WP_Error('element_not_found', sprintf('Op %d: element %s not found in post %d.', $i, $op['element_id'], $post_id), ['status' => 404]);
            }
            if (!isset($element['elType']) || $element['elType'] !== 'widget') {
                unset($element);
                return new WP_Error('not_a_widget', sprintf('Op %d: element %s is %s, not a widget. Container patches are not supported in v1.', $i, $op['element_id'], $element['elType'] ?? 'unknown'), ['status' => 422]);
            }
            if ($op['expected_widget_type'] !== null && ($element['widgetType'] ?? '') !== $op['expected_widget_type']) {
                $actual = $element['widgetType'] ?? 'unknown';
                unset($element);
                return new WP_Error('widget_type_mismatch', sprintf('Op %d: expected widget_type=%s, got %s.', $i, $op['expected_widget_type'], $actual), ['status' => 409]);
            }

            $sanitized_patch = $this->sanitize_patch_strings($op['settings_patch']);
            $current_settings = isset($element['settings']) && is_array($element['settings']) ? $element['settings'] : [];
            $element['settings'] = array_merge($current_settings, $sanitized_patch);

            $merged_per_op[$i] = [
                'element_id'   => $op['element_id'],
                'widget_type'  => $element['widgetType'] ?? null,
                'settings'     => $element['settings'],
                'patch_sent'   => $sanitized_patch,
                'unknown_keys' => $validations[$i]['unknown'],
            ];
            unset($element);
        }

        // One save for the whole batch.
        $saved = $this->write_elementor_tree($post_id, $tree);
        if (!$saved) {
            return new WP_Error('save_failed', 'Failed to write _elementor_data.', ['status' => 500]);
        }

        // Round-trip verify: re-read and compare every requested key against
        // the stored settings. Surfaces silent-drop bugs (wp_slash regressions,
        // type rejections, etc.).
        $reread = $this->read_elementor_tree($post_id);
        if ($reread === null) {
            return new WP_Error('round_trip_failed', 'Wrote post meta but could not re-read.', ['status' => 500]);
        }

        $out_ops = [];
        foreach ($merged_per_op as $i => $merged) {
            $stored = $this->walk_elementor_tree($reread, $merged['element_id']);
            $stored_settings = ($stored && isset($stored['settings']) && is_array($stored['settings'])) ? $stored['settings'] : [];
            $dropped = [];
            foreach ($merged['patch_sent'] as $k => $v) {
                if (!array_key_exists($k, $stored_settings) || $stored_settings[$k] !== $v) {
                    $dropped[] = $k;
                }
            }
            $out_ops[] = [
                'element_id'   => $merged['element_id'],
                'widget_type'  => $merged['widget_type'],
                'settings'     => $stored_settings,
                'unknown_keys' => $merged['unknown_keys'],
                'dropped_keys' => $dropped,
            ];
        }

        return ['ops' => $out_ops];
    }

    /**
     * Read Elementor tree via document API with raw post_meta fallback.
     */
    private function read_elementor_tree($post_id) {
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                $document = \Elementor\Plugin::$instance->documents->get($post_id);
                if ($document) {
                    $data = $document->get_elements_data();
                    if (is_array($data) && !empty($data)) {
                        return $data;
                    }
                }
            } catch (Exception $e) {
                // fall through to post_meta path
            }
        }
        $raw = get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write Elementor tree via document save with raw post_meta fallback +
     * CSS cache bust. The wp_slash() wrap on the fallback is load-bearing —
     * see v1.8.1.
     */
    private function write_elementor_tree($post_id, array $tree) {
        $saved_via_document = false;
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                $document = \Elementor\Plugin::$instance->documents->get($post_id);
                if ($document && method_exists($document, 'save')) {
                    $saved_via_document = (bool) $document->save(['elements' => $tree]);
                }
            } catch (Exception $e) {
                $saved_via_document = false;
            }
        }

        if (!$saved_via_document) {
            $json = wp_json_encode($tree);
            if ($json === false) {
                return false;
            }
            update_post_meta($post_id, '_elementor_data', wp_slash($json));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            if (defined('ELEMENTOR_VERSION')) {
                update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
            }
            delete_post_meta($post_id, '_elementor_css');
            $cached = WP_CONTENT_DIR . '/uploads/elementor/css/post-' . (int) $post_id . '.css';
            if (file_exists($cached)) {
                @unlink($cached);
            }
        }

        // Belt-and-suspenders on both paths.
        $this->regenerate_elementor_css($post_id);
        return true;
    }

    /**
     * Recursive DFS for an element by id. Returns by reference so callers can
     * mutate in place. Returns null reference if not found (caller must
     * isset()-check after the call by assigning to a temp).
     */
    private function &walk_elementor_tree(array &$tree, $element_id) {
        $null = null;
        foreach ($tree as $i => &$node) {
            if (isset($node['id']) && $node['id'] === $element_id) {
                return $tree[$i];
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                $found = &$this->walk_elementor_tree($node['elements'], $element_id);
                if ($found !== null) {
                    return $found;
                }
                unset($found);
            }
        }
        return $null;
    }

    /**
     * Validate patch keys: hard-reject banned, soft-warn unknown.
     * Returns ['banned' => [...], 'unknown' => [...]].
     */
    private function validate_patch_keys(array $patch) {
        $banned = [];
        $unknown = [];
        $allowlist = $this->elementor_advanced_key_allowlist();
        $responsive_suffixes = ['_mobile', '_tablet', '_widescreen', '_laptop', '_mobile_extra', '_tablet_extra'];

        foreach ($patch as $key => $_v) {
            if ($key === '__globals__' || $key === '__dynamic__' || strpos($key, 'neb_content_protection_') === 0) {
                $banned[] = $key;
                continue;
            }
            // Strip responsive suffix to check base key.
            $base = $key;
            foreach ($responsive_suffixes as $suf) {
                $len = strlen($suf);
                if (substr($key, -$len) === $suf) {
                    $base = substr($key, 0, -$len);
                    break;
                }
            }
            if (in_array($base, $allowlist, true)) {
                continue;
            }
            // Group-control prefix match: many widget settings share prefixes
            // like `title_typography_*`, `border_*`. Accept any key with a
            // known prefix even if exact key isn't in the allowlist.
            $matched_prefix = false;
            foreach ($allowlist as $allowed) {
                if (strpos($base, $allowed . '_') === 0) {
                    $matched_prefix = true;
                    break;
                }
            }
            if (!$matched_prefix) {
                $unknown[] = $key;
            }
        }

        return ['banned' => $banned, 'unknown' => $unknown];
    }

    /**
     * Curated allowlist of common-base Elementor control keys + per-widget
     * top-level settings we see in the wild. Sourced from msrbuilds review
     * (common_advanced_keys) + our fleet-sample.json. Data, not code — copy
     * patterns, not GPL'd source.
     */
    private function elementor_advanced_key_allowlist() {
        return [
            // Common advanced
            '_margin', '_padding', '_element_id', '_css_classes', '_element_width',
            '_element_custom_width', '_element_vertical_align', '_position',
            '_z_index', 'css_classes', 'custom_css',
            // Background + overlay
            'background_background', 'background_color', 'background_image',
            'background_position', 'background_size', 'background_repeat',
            'background_attachment', 'background_xpos', 'background_ypos',
            'background_overlay_background', 'background_overlay_color',
            'background_overlay_image', 'background_overlay_opacity',
            // Border + shadow
            'border_border', 'border_width', 'border_color', 'border_radius',
            'box_shadow_box_shadow_type', 'box_shadow_box_shadow',
            // Motion FX + transforms + hide
            'motion_fx_motion_fx_scrolling', 'motion_fx_motion_fx_mouse',
            'transform', '_transform', 'hide_desktop', 'hide_tablet', 'hide_mobile',
            'animation', 'animation_duration', 'animation_delay',
            // Typography (group control prefix)
            'typography', 'typography_typography', 'typography_font_family',
            'typography_font_size', 'typography_font_weight', 'typography_line_height',
            'typography_letter_spacing', 'typography_text_transform', 'typography_font_style',
            'typography_text_decoration', 'typography_word_spacing',
            // Container layout
            'content_width', 'boxed_width', 'min_height', 'html_tag',
            'flex_direction', 'flex_justify_content', 'flex_align_items',
            'flex_gap', 'flex_wrap',
            // Per-widget common
            'title', 'title_color', 'header_size', 'align', 'text_align',
            'editor', 'text', 'link', 'url', 'is_external', 'nofollow',
            'image', 'caption', 'alt', 'image_size',
            'button_text', 'size', 'button_type', 'selected_icon',
            'items', 'tabs', 'icon_list',
            'title_text', 'description_text', 'view',
            // Nested-accordion specific
            'accordion_item_title_position_horizontal', 'accordion_item_title_icon_position',
            'title_tag', 'faq_schema', 'rank_math_add_faq_schema',
            'normal_title_color', 'active_title_color', 'icon_size',
            'accordion_border_normal_border', 'accordion_border_normal_width',
            'accordion_padding', 'content_border_border', 'content_border_width',
            'content_padding',
            'title_typography', 'title_typography_typography', 'title_typography_font_family',
            'title_typography_font_size', 'title_typography_font_weight',
            'description_typography', 'text_typography',
            // CSS filters
            'css_filters_css_filter', 'css_filter_blur', 'css_filter_brightness',
            'css_filter_contrast', 'css_filter_saturate', 'css_filter_hue',
            // Button-specific
            'text_padding', 'button_text_color', 'button_background_color',
            // Section heading
            '_title',
            // Shortcode + html
            'shortcode', 'html',
        ];
    }

    /**
     * Recursively run wp_kses_post on string values. URLs and known
     * non-content strings pass through (they're validated client-side).
     */
    private function sanitize_patch_strings(array $patch) {
        $out = [];
        foreach ($patch as $k => $v) {
            if (is_string($v)) {
                // Skip color/size/url-shaped strings: kses would mangle them
                // but they're already safe (no markup).
                if (preg_match('/^(#[0-9a-fA-F]{3,8}|https?:\/\/|\/\/|\/|tel:|mailto:|\d+(?:\.\d+)?(?:px|em|rem|vh|vw|%|s)?)$/', $v)) {
                    $out[$k] = $v;
                } else {
                    $out[$k] = wp_kses_post($v);
                }
            } elseif (is_array($v)) {
                $out[$k] = $this->sanitize_patch_strings($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
