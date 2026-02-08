<?php
/**
 * Plugin Name: Superman Links
 * Plugin URI: https://github.com/brycehallcloud/superman-links-wp
 * Description: REST API bridge for Superman Links CRM - exposes page data, SEO metadata, and Elementor templates.
 * Version: 1.2.0
 * Author: Superman Links
 * License: GPL v2 or later
 * Text Domain: superman-links
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SUPERMAN_LINKS_VERSION', '1.2.0');
define('SUPERMAN_LINKS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include required files
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-api.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-webhook.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-updater.php';

/**
 * Initialize the plugin
 */
function superman_links_init() {
    // Initialize settings
    new Superman_Links_Settings();

    // Initialize API
    new Superman_Links_API();

    // Initialize webhooks
    new Superman_Links_Webhook();

    // Initialize auto-updater
    new Superman_Links_Updater(__FILE__);
}
add_action('plugins_loaded', 'superman_links_init');

/**
 * Activation hook
 */
function superman_links_activate() {
    // Generate a default API key if none exists
    if (!get_option('superman_links_api_key')) {
        update_option('superman_links_api_key', wp_generate_password(32, false));
    }
}
register_activation_hook(__FILE__, 'superman_links_activate');

/**
 * Add settings link on plugin page
 */
function superman_links_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=superman-links">' . __('Settings', 'superman-links') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'superman_links_settings_link');
