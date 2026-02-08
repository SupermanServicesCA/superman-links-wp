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
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
     * Apply Elementor template data to a post
     */
    private function apply_elementor_template($post_id, $template_data, $is_new = false) {
        $elementor_data = $template_data['elementor']['data'] ?? null;
        $page_settings = $template_data['elementor']['page_settings'] ?? null;
        $template_type = $template_data['page']['template_type'] ?? 'page';

        // Encode data as JSON if it's an array
        $elementor_json = is_array($elementor_data) ? wp_json_encode($elementor_data) : $elementor_data;

        // Update Elementor meta
        update_post_meta($post_id, '_elementor_data', $elementor_json);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $template_type);
        update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

        // Update page settings if provided
        if (!empty($page_settings)) {
            $settings_json = is_array($page_settings) ? wp_json_encode($page_settings) : $page_settings;
            update_post_meta($post_id, '_elementor_page_settings', $settings_json);
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
}
