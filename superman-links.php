<?php
/**
 * Plugin Name: Superman Links
 * Plugin URI: https://github.com/SupermanServicesCA/superman-links-wp
 * Description: Your bridge to Superman Links, courtesy of Superman SEO.
 * Version: 2.2.2
 * Author: Superman Services
 * Author URI: https://supermanservices.ca/website-design-and-development/
 * License: GPL v2 or later
 * Text Domain: superman-links
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SUPERMAN_LINKS_VERSION', '2.2.2');
define('SUPERMAN_LINKS_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include required files
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-settings.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-api.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-webhook.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-updater.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-review-widget.php';
require_once SUPERMAN_LINKS_PLUGIN_DIR . 'includes/class-theme-colors.php';

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

    // Initialize review widget
    new Superman_Links_Review_Widget();

    // Initialize theme colors endpoint
    new Superman_Links_Theme_Colors();
}
add_action('plugins_loaded', 'superman_links_init');

/**
 * Activation hook
 */
function superman_links_activate() {
    // Generate a default API key if none exists. Track whether we minted a FRESH
    // one so we can tell the CRM (below) — a re-mint after the option was lost
    // (host migration / restore / reinstall) silently forks the key the CRM
    // stores and breaks sync until reconciled.
    $minted_key = null;
    if (!get_option('superman_links_api_key')) {
        // DETERMINISTIC mint (drift-immune): derive the key from wp-config.php
        // salts instead of a fresh random value. wp-config salts (AUTH_KEY /
        // SECURE_AUTH_KEY) live in the filesystem, NOT in wp_options, so they
        // survive a wp_options wipe (host migration / DB restore / reinstall).
        // That means a re-mint reproduces the SAME key — so after the CRM adopts
        // it once, this site is drift-immune: re-activation yields the identical
        // key and sync never forks again. Only a migration that ALSO regenerates
        // the wp-config salts (rare) would produce a new key and re-drift.
        // We DO NOT touch an already-set key (the !get_option guard above), so
        // existing sites keep their original random key untouched — this only
        // affects NEW mints.
        if (defined('AUTH_KEY') && AUTH_KEY && defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) {
            $minted_key = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY . get_site_url() . 'superman-links-api-key'), 0, 32);
        } else {
            // Salts not defined (odd / non-standard install) — preserve the
            // original behavior so we never fail to mint a usable key.
            $minted_key = wp_generate_password(32, false);
        }
        update_option('superman_links_api_key', $minted_key);
    }

    // Set webhook config (stored in DB, not in source code)
    update_option('superman_links_webhook_url', base64_decode('aHR0cHM6Ly93aXJudHNranV1dnFrdnFic2ttYi5zdXBhYmFzZS5jby9mdW5jdGlvbnMvdjEvd29yZHByZXNzLXdlYmhvb2s='));
    update_option('superman_links_supabase_key', base64_decode('ZXlKaGJHY2lPaUpJVXpJMU5pSXNJblI1Y0NJNklrcFhWQ0o5LmV5SnBjM01pT2lKemRYQmhZbUZ6WlNJc0luSmxaaUk2SW5kcGNtNTBjMnRxZFhWMmNXdDJjV0p6YTIxaUlpd2ljbTlzWlNJNkltRnViMjRpTENKcFlYUWlPakUzTmpRNU56RTNPVGdzSW1WNGNDSTZNakE0TURVME56YzVPSDAueERkSXlWczNtTTgyY3ZjMDF1Rld4c1Y1Si1BSXQ4Wk5HSkU3WG1YZ0NCUQ=='));

    // If we just minted a FRESH key, notify the CRM immediately so the drift is
    // loud, not silent. WP routes a mint into an absent option through add_option
    // (NOT update_option_*), so the class-webhook self-heal can't observe it; and
    // the activation hook fires after plugins_loaded already passed, so the
    // webhook class isn't instantiated yet — hence this inline static call. The
    // CRM stages it as a PENDING key for human adoption (it never auto-trusts it).
    if ($minted_key !== null && class_exists('Superman_Links_Webhook')) {
        Superman_Links_Webhook::notify_key_minted($minted_key);
    }
}
register_activation_hook(__FILE__, 'superman_links_activate');

/**
 * On deactivation, clear the RankMath redirect-sync cron event so it doesn't linger.
 */
function superman_links_deactivate() {
    wp_clear_scheduled_hook('superman_links_rankmath_cron');
}
register_deactivation_hook(__FILE__, 'superman_links_deactivate');

/**
 * Add settings link on plugin page
 */
function superman_links_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=superman-links">' . __('Settings', 'superman-links') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'superman_links_settings_link');
