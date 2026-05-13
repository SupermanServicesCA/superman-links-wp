<?php
/**
 * Theme Colors endpoint - returns the active theme's color palette from
 * theme.json (block themes) or Elementor global colors (classic themes).
 * Lets the CRM auto-seed Review Widget styling from each client's actual brand.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Theme_Colors {

    private $namespace = 'superman-links/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/theme-colors', [
            'methods' => 'GET',
            'callback' => [$this, 'get_theme_colors'],
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

    public function get_theme_colors() {
        // 1. Try theme.json first (block themes, WP 5.9+)
        if (function_exists('wp_get_global_settings')) {
            $palette = wp_get_global_settings(['color', 'palette']);

            // wp_get_global_settings can return either a flat array or { theme: [...], custom: [...] }
            $colors = [];
            if (is_array($palette)) {
                if (isset($palette['theme']) && is_array($palette['theme'])) {
                    $colors = array_merge($colors, $palette['theme']);
                }
                if (isset($palette['custom']) && is_array($palette['custom'])) {
                    $colors = array_merge($colors, $palette['custom']);
                }
                // Flat array fallback
                if (empty($colors) && isset($palette[0])) {
                    $colors = $palette;
                }
            }

            $normalized = $this->normalize_palette($colors);
            if (!empty($normalized)) {
                return rest_ensure_response([
                    'source' => 'theme.json',
                    'theme' => get_stylesheet(),
                    'colors' => $normalized,
                ]);
            }
        }

        // 2. Fall back to Elementor global colors
        if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
            $elementor_colors = $this->get_elementor_colors();
            if (!empty($elementor_colors)) {
                return rest_ensure_response([
                    'source' => 'elementor',
                    'theme' => get_stylesheet(),
                    'colors' => $elementor_colors,
                ]);
            }
        }

        return rest_ensure_response([
            'source' => 'none',
            'theme' => get_stylesheet(),
            'colors' => [],
        ]);
    }

    private function normalize_palette($palette) {
        $out = [];
        foreach ($palette as $entry) {
            if (!is_array($entry) || empty($entry['color'])) continue;
            $out[] = [
                'slug' => isset($entry['slug']) ? sanitize_title($entry['slug']) : '',
                'name' => isset($entry['name']) ? sanitize_text_field($entry['name']) : '',
                'color' => sanitize_hex_color($entry['color']) ?: $entry['color'],
            ];
        }
        return $out;
    }

    private function get_elementor_colors() {
        try {
            if (!class_exists('\Elementor\Plugin')) return [];

            $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
            if (!$kit) return [];

            $settings = $kit->get_settings();
            $system_colors = isset($settings['system_colors']) ? $settings['system_colors'] : [];
            $custom_colors = isset($settings['custom_colors']) ? $settings['custom_colors'] : [];
            $all = array_merge($system_colors, $custom_colors);

            $out = [];
            foreach ($all as $entry) {
                if (empty($entry['color'])) continue;
                $out[] = [
                    'slug' => isset($entry['_id']) ? sanitize_title($entry['_id']) : '',
                    'name' => isset($entry['title']) ? sanitize_text_field($entry['title']) : '',
                    'color' => sanitize_hex_color($entry['color']) ?: $entry['color'],
                ];
            }
            return $out;
        } catch (Exception $e) {
            return [];
        }
    }
}
