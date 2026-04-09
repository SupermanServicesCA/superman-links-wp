<?php
/**
 * Settings page for Superman Links plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Superman_Links_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('Superman Links', 'superman-links'),
            __('Superman Links', 'superman-links'),
            'manage_options',
            'superman-links',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('superman_links_settings', 'superman_links_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        add_settings_section(
            'superman_links_main',
            __('API Settings', 'superman-links'),
            [$this, 'section_callback'],
            'superman-links'
        );

        add_settings_field(
            'superman_links_api_key',
            __('API Key', 'superman-links'),
            [$this, 'api_key_callback'],
            'superman-links',
            'superman_links_main'
        );
    }

    /**
     * Section description
     */
    public function section_callback() {
        echo '<p>' . __('Configure your Superman Links CRM integration settings. Auto-sync is always enabled - any changes to post titles or focus keywords will be automatically pushed to Links.', 'superman-links') . '</p>';
    }

    /**
     * API key field
     */
    public function api_key_callback() {
        $api_key = get_option('superman_links_api_key', '');
        ?>
        <input type="text"
               id="superman_links_api_key"
               name="superman_links_api_key"
               value="<?php echo esc_attr($api_key); ?>"
               class="regular-text"
               readonly
        />
        <button type="button" class="button" onclick="regenerateApiKey()">
            <?php _e('Regenerate', 'superman-links'); ?>
        </button>
        <p class="description">
            <?php _e('Use this API key in your Superman Links CRM to authenticate requests.', 'superman-links'); ?>
        </p>
        <script>
        function regenerateApiKey() {
            if (confirm('<?php _e('Are you sure you want to regenerate the API key? The old key will stop working.', 'superman-links'); ?>')) {
                const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                let key = '';
                for (let i = 0; i < 32; i++) {
                    key += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('superman_links_api_key').value = key;
                document.getElementById('superman_links_api_key').removeAttribute('readonly');
            }
        }
        </script>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('superman_links_settings');
                do_settings_sections('superman-links');
                submit_button(__('Save Settings', 'superman-links'));
                ?>
            </form>

            <?php $this->render_linkfinder_section(); ?>

        </div>
        <?php
    }

    /**
     * LinkFinder Sync section — bulk push admin controls
     */
    public function render_linkfinder_section() {
        ?>
        <hr style="margin: 2em 0;" />
        <h2>LinkFinder Sync</h2>
        <p>
            Push all published pages to Superman Links CRM for anchor text search.
            Pages saved going forward sync automatically &mdash; only run this for the
            initial bulk import or after a content migration.
        </p>

        <div id="superman-linkfinder-status" style="margin: 1em 0;">Loading&hellip;</div>

        <p>
            <button type="button" class="button button-primary" id="superman-linkfinder-start">
                Push all pages to Superman CRM
            </button>
            <button type="button" class="button" id="superman-linkfinder-stop">
                Stop
            </button>
        </p>

        <script>
        (function () {
            const statusEl = document.getElementById('superman-linkfinder-status');
            const startBtn = document.getElementById('superman-linkfinder-start');
            const stopBtn  = document.getElementById('superman-linkfinder-stop');
            const restBase = '<?php echo esc_js(rest_url('superman-links/v1')); ?>';
            const nonce    = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';

            let pollHandle = null;

            async function api(path, method) {
                const r = await fetch(restBase + path, {
                    method: method || 'GET',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                });
                return r.json();
            }

            function renderStatus(s) {
                if (!s || s.status === 'idle') {
                    statusEl.innerHTML = '<p>No active sync. Click below to start.</p>';
                    return;
                }
                const pct = s.total > 0 ? Math.floor((s.done / s.total) * 100) : 0;
                statusEl.innerHTML =
                    '<p><strong>Syncing:</strong> ' + s.done + ' / ' + s.total + ' (' + pct + '%)</p>' +
                    '<progress value="' + s.done + '" max="' + s.total + '" style="width: 100%"></progress>';
            }

            async function refresh() {
                try {
                    const s = await api('/linkfinder/status');
                    renderStatus(s);
                    if (s.status === 'in_progress' || s.status === 'completing') {
                        // Manual tick to keep things moving even if WP-Cron stalls
                        api('/linkfinder/tick', 'POST').catch(function () {});
                        pollHandle = setTimeout(refresh, 5000);
                    } else if (pollHandle) {
                        clearTimeout(pollHandle);
                        pollHandle = null;
                    }
                } catch (e) {
                    statusEl.innerHTML = '<p style="color:#a00">Error loading status: ' + e.message + '</p>';
                }
            }

            startBtn.addEventListener('click', async function () {
                startBtn.disabled = true;
                try {
                    await api('/linkfinder/start', 'POST');
                } finally {
                    startBtn.disabled = false;
                }
                refresh();
            });

            stopBtn.addEventListener('click', async function () {
                await api('/linkfinder/stop', 'POST');
                refresh();
            });

            refresh();
        })();
        </script>
        <?php
    }
}
