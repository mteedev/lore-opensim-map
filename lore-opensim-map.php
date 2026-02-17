<?php
/**
 * Plugin Name: L.O.R.E. - Leaflet OpenSimulator Regional Explorer
 * Plugin URI:  https://nerdypappy.com/lore
 * Description: A modern, interactive OpenSimulator grid map plugin powered by Leaflet.js. Features region search, teleport links, batch sync with progress bar, and fully customizable colors.
 * Version:     1.0.1
 * Author:      Gundahar Bravin
 * Author URI:  https://nerdypappy.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lore-opensim-map
 */

if (!defined('ABSPATH')) exit;

define('LORE_VERSION',    '1.0.1');
define('LORE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LORE_PLUGIN_DIR', plugin_dir_path(__FILE__));

class LORE_OpenSim_Map {

    public function __construct() {
        add_action('init',                array($this, 'init'));
        add_action('wp_enqueue_scripts',  array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_shortcode('lore_map',         array($this, 'map_shortcode'));
        add_action('admin_menu',          array($this, 'admin_menu'));
        add_action('admin_init',          array($this, 'admin_init'));

        // Public + Private AJAX handlers
        foreach (array('lore_get_regions', 'lore_get_region_info') as $action) {
            add_action('wp_ajax_'        . $action, array($this, 'ajax_' . $action));
            add_action('wp_ajax_nopriv_' . $action, array($this, 'ajax_' . $action));
        }
        add_action('wp_ajax_lore_sync_batch', array($this, 'ajax_lore_sync_batch'));
        
        // Cron for auto-sync
        add_action('lore_daily_sync', array($this, 'cron_sync_regions'));
        register_activation_hook(__FILE__, array($this, 'activate_cron'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_cron'));
    }

    /* ------------------------------------------------------------------ */
    /*  INIT / TABLE                                                        */
    /* ------------------------------------------------------------------ */

    public function init() {
        $this->create_table();
    }

    private function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'lore_regions';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE $table (
            id           mediumint(9)  NOT NULL AUTO_INCREMENT,
            region_uuid  varchar(36)   NOT NULL,
            region_name  varchar(255)  NOT NULL,
            region_x     int(11)       NOT NULL,
            region_y     int(11)       NOT NULL,
            server_uri   varchar(255)  NOT NULL,
            status       varchar(20)   DEFAULT 'active',
            last_updated datetime      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY region_uuid (region_uuid)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ------------------------------------------------------------------ */
    /*  ENQUEUE                                                             */
    /* ------------------------------------------------------------------ */

    public function enqueue_scripts() {
        wp_enqueue_style('leaflet-css',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
        wp_enqueue_script('leaflet-js',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);

        wp_enqueue_style('lore-css',
            LORE_PLUGIN_URL . 'assets/lore.css', array(), LORE_VERSION);
        wp_enqueue_script('lore-js',
            LORE_PLUGIN_URL . 'assets/lore.js', array('jquery', 'leaflet-js'), LORE_VERSION, true);

        // Pass settings to JS
        wp_localize_script('lore-js', 'lore_settings', array(
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('lore_nonce'),
            'plugin_url'     => LORE_PLUGIN_URL,
            'accent_color'   => get_option('lore_accent_color',  '#2563eb'),
            'button_color'   => get_option('lore_button_color',  '#7c3aed'),
            'register_url'   => get_option('lore_register_url',  ''),
            'grid_name'      => get_option('lore_grid_name',     'OpenSimulator Grid'),
        ));
    }

    public function admin_enqueue_scripts($hook) {
        if ($hook !== 'settings_page_lore-opensim-map') return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    /* ------------------------------------------------------------------ */
    /*  SHORTCODE                                                           */
    /* ------------------------------------------------------------------ */

    public function map_shortcode($atts) {
        $atts = shortcode_atts(array(
            'width'    => '100%',
            'height'   => '600px',
            'zoom'     => get_option('lore_default_zoom',     '3'),
            'center_x' => get_option('lore_center_x',        '1000'),
            'center_y' => get_option('lore_center_y',        '1000'),
            'grid_url' => get_option('lore_grid_url',        ''),
        ), $atts);

        $map_id = 'lore-map-' . uniqid();

        ob_start(); ?>
        <div id="<?php echo esc_attr($map_id); ?>"
             class="lore-map-container"
             style="width:<?php echo esc_attr($atts['width']); ?>;height:<?php echo esc_attr($atts['height']); ?>;"
             data-zoom="<?php echo esc_attr($atts['zoom']); ?>"
             data-center-x="<?php echo esc_attr($atts['center_x']); ?>"
             data-center-y="<?php echo esc_attr($atts['center_y']); ?>"
             data-grid-url="<?php echo esc_attr($atts['grid_url']); ?>">
        </div>
        <script>
        jQuery(document).ready(function() {
            initLORE('<?php echo esc_js($map_id); ?>');
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------ */
    /*  ADMIN MENU                                                          */
    /* ------------------------------------------------------------------ */

    public function admin_menu() {
        add_options_page(
            'L.O.R.E. Settings',
            'L.O.R.E. Map',
            'manage_options',
            'lore-opensim-map',
            array($this, 'admin_page')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  ADMIN SETTINGS REGISTRATION                                         */
    /* ------------------------------------------------------------------ */

    public function admin_init() {
        $settings = array(
            // Grid
            'lore_grid_name', 'lore_grid_url', 'lore_register_url',
            // Database
            'lore_db_host', 'lore_db_name', 'lore_db_user', 'lore_db_password',
            // Map
            'lore_center_x', 'lore_center_y', 'lore_default_zoom',
            'lore_tile_url',
            // Appearance
            'lore_accent_color', 'lore_button_color',
            // Featured region
            'lore_featured_region', 'lore_featured_marker',
            // Auto-sync
            'lore_auto_sync_enabled',
        );
        foreach ($settings as $s) {
            register_setting('lore_settings', $s);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  ADMIN PAGE                                                          */
    /* ------------------------------------------------------------------ */

    public function admin_page() {
        $accent = get_option('lore_accent_color', '#2563eb');
        $button = get_option('lore_button_color', '#7c3aed');
        ?>
        <div class="wrap">

            <!-- Header -->
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding:20px;background:linear-gradient(135deg,#1e1b4b,#312e81);border-radius:12px;">
                <img src="<?php echo LORE_PLUGIN_URL; ?>assets/compass.png" style="width:80px;height:80px;border-radius:50%;" alt="L.O.R.E.">
                <div>
                    <h1 style="color:#fbbf24;margin:0;font-size:28px;letter-spacing:2px;">L.O.R.E.</h1>
                    <p style="color:#c7d2fe;margin:4px 0 0;font-size:13px;">Leaflet OpenSimulator Regional Explorer &mdash; v<?php echo LORE_VERSION; ?></p>
                    <p style="color:#818cf8;margin:2px 0 0;font-size:12px;">by <a href="https://nerdypappy.com" target="_blank" style="color:#fbbf24;">Gundahar Bravin</a> &mdash; <a href="https://github.com/mteedev/lore-opensim-map" target="_blank" style="color:#fbbf24;">GitHub</a></p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('lore_settings'); ?>

                <!-- GRID SETTINGS -->
                <h2>🌐 Grid Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Grid Name</th>
                        <td>
                            <input type="text" name="lore_grid_name" value="<?php echo esc_attr(get_option('lore_grid_name', '')); ?>" class="regular-text" placeholder="My OpenSim Grid">
                            <p class="description">Display name for your grid (shown in map UI).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Grid URL</th>
                        <td>
                            <input type="text" name="lore_grid_url" value="<?php echo esc_attr(get_option('lore_grid_url', '')); ?>" class="regular-text" placeholder="yourgrid.com:8002">
                            <p class="description">Your grid's login URI (e.g. <code>mygrid.com:8002</code>). Used to build teleport links automatically.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Registration URL</th>
                        <td>
                            <input type="text" name="lore_register_url" value="<?php echo esc_attr(get_option('lore_register_url', '')); ?>" class="regular-text" placeholder="https://yourgrid.com/register">
                            <p class="description">Link for the "Join Free Today" button in region popups.</p>
                        </td>
                    </tr>
                </table>

                <!-- DATABASE SETTINGS -->
                <h2>🗄️ OpenSimulator Database</h2>
                <p>Connect to your OpenSimulator <strong>Robust</strong> database so L.O.R.E. can sync region data.</p>
                <table class="form-table">
                    <tr>
                        <th>Database Host</th>
                        <td>
                            <input type="text" name="lore_db_host" value="<?php echo esc_attr(get_option('lore_db_host', '')); ?>" class="regular-text" placeholder="localhost or IP address">
                        </td>
                    </tr>
                    <tr>
                        <th>Database Name</th>
                        <td>
                            <input type="text" name="lore_db_name" value="<?php echo esc_attr(get_option('lore_db_name', '')); ?>" class="regular-text" placeholder="robust">
                        </td>
                    </tr>
                    <tr>
                        <th>Database User</th>
                        <td>
                            <input type="text" name="lore_db_user" value="<?php echo esc_attr(get_option('lore_db_user', '')); ?>" class="regular-text" placeholder="opensim_user">
                        </td>
                    </tr>
                    <tr>
                        <th>Database Password</th>
                        <td>
                            <input type="password" name="lore_db_password" value="<?php echo esc_attr(get_option('lore_db_password', '')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>

                <!-- MAP SETTINGS -->
                <h2>🗺️ Map Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Map Tile URL</th>
                        <td>
                            <input type="text" name="lore_tile_url" value="<?php echo esc_attr(get_option('lore_tile_url', '')); ?>" class="large-text" placeholder="http://yourgrid.com:9000/index.php?method=MapItems&...">
                            <p class="description">Your grid's Warp3D map tile URL. Leave blank to use the Grid URL above.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Default Zoom</th>
                        <td>
                            <input type="number" name="lore_default_zoom" value="<?php echo esc_attr(get_option('lore_default_zoom', '3')); ?>" min="1" max="8" style="width:60px;">
                            <p class="description">Initial zoom level (1-8). Recommended: 3.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Center X Coordinate</th>
                        <td>
                            <input type="number" name="lore_center_x" value="<?php echo esc_attr(get_option('lore_center_x', '1000')); ?>" class="small-text">
                            <p class="description">Map loads centered on this X grid coordinate.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Center Y Coordinate</th>
                        <td>
                            <input type="number" name="lore_center_y" value="<?php echo esc_attr(get_option('lore_center_y', '1000')); ?>" class="small-text">
                            <p class="description">Map loads centered on this Y grid coordinate.</p>
                        </td>
                    </tr>
                </table>

                <!-- APPEARANCE -->
                <h2>🎨 Appearance</h2>
                <table class="form-table">
                    <tr>
                        <th>Accent Color</th>
                        <td>
                            <input type="text" name="lore_accent_color" value="<?php echo esc_attr($accent); ?>" class="lore-color-picker">
                            <p class="description">Used for popup title, search box border, and coordinate text.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Button Color</th>
                        <td>
                            <input type="text" name="lore_button_color" value="<?php echo esc_attr($button); ?>" class="lore-color-picker">
                            <p class="description">Used for the Teleport button gradient.</p>
                        </td>
                    </tr>
                </table>

                <!-- FEATURED REGION -->
                <h2>⭐ Featured Region <span style="font-weight:normal;font-size:13px;color:#666;">(optional)</span></h2>
                <table class="form-table">
                    <tr>
                        <th>Featured Region Name</th>
                        <td>
                            <input type="text" name="lore_featured_region" value="<?php echo esc_attr(get_option('lore_featured_region', '')); ?>" class="regular-text" placeholder="Welcome">
                            <p class="description">Exact name of a region to highlight with a special marker. Leave blank to disable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Marker Style</th>
                        <td>
                            <select name="lore_featured_marker">
                                <?php
                                $marker = get_option('lore_featured_marker', 'pulse');
                                $options = array(
                                    'none'  => 'None',
                                    'pulse' => 'Pulsing Glow',
                                    'star'  => 'Gold Star',
                                    'pin'   => 'Pin Drop',
                                );
                                foreach ($options as $val => $label) {
                                    echo '<option value="' . $val . '"' . selected($marker, $val, false) . '>' . $label . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <!-- REGION SYNC -->
            <hr>
            <h2>🔄 Region Sync</h2>
            <p>Sync region data from your OpenSimulator database into WordPress. Regions are synced in batches of 50 to avoid timeouts.</p>
            
            <table class="form-table" style="margin-bottom:20px;">
                <tr>
                    <th>Automatic Daily Sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lore_auto_sync_enabled" value="1" <?php checked(get_option('lore_auto_sync_enabled'), '1'); ?>>
                            Enable daily automatic region sync at 3:00 AM server time
                        </label>
                        <p class="description">When enabled, L.O.R.E. will automatically sync regions from your database every day. You can still manually sync anytime using the button below.</p>
                        <?php
                        $next_scheduled = wp_next_scheduled('lore_daily_sync');
                        if ($next_scheduled) {
                            echo '<p class="description" style="color:#16a34a;">⏰ Next automatic sync: <strong>' . date('F j, Y g:i A', $next_scheduled) . '</strong></p>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <h3>Manual Sync</h3>
            <p>
                <button type="button" id="lore-sync-btn" class="button button-primary button-large">Sync Regions Now</button>
            </p>
            <div id="lore-sync-wrap" style="display:none;max-width:500px;margin-top:15px;">
                <div style="background:#e5e7eb;border-radius:8px;height:26px;overflow:hidden;margin-bottom:8px;">
                    <div id="lore-progress-bar" style="background:linear-gradient(135deg,#fbbf24,#f59e0b);height:100%;width:0%;border-radius:8px;transition:width 0.3s ease;"></div>
                </div>
                <div id="lore-progress-text" style="font-size:13px;color:#374151;margin-bottom:4px;">Preparing...</div>
                <div id="lore-progress-count" style="font-size:12px;color:#6b7280;"></div>
            </div>
            <div id="lore-sync-status" style="margin-top:12px;font-weight:bold;font-size:14px;"></div>

            <!-- SHORTCODE HELP -->
            <hr>
            <h2>📋 Shortcode Usage</h2>
            <p>Paste this shortcode into any page or post:</p>
            <code style="font-size:14px;padding:10px;display:inline-block;background:#f0f0f0;border-radius:6px;">[lore_map]</code>
            <h3>Optional Parameters:</h3>
            <table class="widefat" style="max-width:700px;">
                <thead><tr><th>Parameter</th><th>Default</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>width</code></td><td>100%</td><td>Map width</td></tr>
                    <tr><td><code>height</code></td><td>600px</td><td>Map height</td></tr>
                    <tr><td><code>zoom</code></td><td>3</td><td>Initial zoom (1-8)</td></tr>
                    <tr><td><code>center_x</code></td><td>1000</td><td>Starting X coordinate</td></tr>
                    <tr><td><code>center_y</code></td><td>1000</td><td>Starting Y coordinate</td></tr>
                    <tr><td><code>grid_url</code></td><td><em>From settings</em></td><td>Override grid URL</td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;">Example: <code>[lore_map height="800px" zoom="4" center_x="10000" center_y="10000"]</code></p>

        </div>

        <script>
        jQuery(document).ready(function($) {
            // Color pickers
            $('.lore-color-picker').wpColorPicker();

            // Sync with progress bar
            var batchSize = 50, offset = 0, totalSynced = 0, totalRegions = 0, syncing = false;

            $('#lore-sync-btn').on('click', function() {
                if (syncing) return;
                syncing = true; offset = 0; totalSynced = 0; totalRegions = 0;
                $(this).prop('disabled', true);
                $('#lore-sync-wrap').show();
                $('#lore-sync-status').text('').css('color','#374151');
                $('#lore-progress-bar').css('width','0%');
                $('#lore-progress-text').text('Clearing old data and starting sync...');
                $('#lore-progress-count').text('');
                doSync(true);
            });

            function doSync(clearFirst) {
                $.ajax({
                    url: ajaxurl, type: 'POST', timeout: 30000,
                    data: {
                        action: 'lore_sync_batch',
                        nonce: '<?php echo wp_create_nonce('lore_sync'); ?>',
                        offset: offset,
                        batch_size: batchSize,
                        clear_first: clearFirst ? 1 : 0
                    },
                    success: function(r) {
                        if (!r.success) { syncFail(r.data); return; }
                        totalSynced += r.data.synced;
                        totalRegions = r.data.total;
                        offset += batchSize;
                        var pct = totalRegions > 0 ? Math.min(100, Math.round((totalSynced / totalRegions) * 100)) : 0;
                        var batch = Math.ceil(offset / batchSize);
                        var total = Math.ceil(totalRegions / batchSize);
                        $('#lore-progress-bar').css('width', pct + '%');
                        $('#lore-progress-text').text('Syncing batch ' + batch + ' of ' + total + '...');
                        $('#lore-progress-count').text(totalSynced + ' of ' + totalRegions + ' regions synced');
                        if (r.data.synced < batchSize || totalSynced >= totalRegions) {
                            syncDone();
                        } else {
                            setTimeout(function() { doSync(false); }, 100);
                        }
                    },
                    error: function() { syncFail('Network error - please try again'); }
                });
            }

            function syncDone() {
                syncing = false;
                $('#lore-sync-btn').prop('disabled', false);
                $('#lore-progress-bar').css('width','100%');
                $('#lore-progress-text').text('Sync complete!');
                $('#lore-sync-status').text('✅ Synced ' + totalSynced + ' regions successfully!').css('color','#16a34a');
            }

            function syncFail(msg) {
                syncing = false;
                $('#lore-sync-btn').prop('disabled', false);
                $('#lore-progress-text').text('Sync failed.');
                $('#lore-sync-status').text('❌ ' + msg).css('color','#dc2626');
            }
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX: GET REGIONS                                                   */
    /* ------------------------------------------------------------------ */

    public function ajax_lore_get_regions() {
        check_ajax_referer('lore_nonce', 'nonce');
        global $wpdb;
        $regions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}lore_regions WHERE status = 'active'"
        );
        wp_send_json_success($regions);
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX: GET REGION INFO (click popup)                                 */
    /* ------------------------------------------------------------------ */

    public function ajax_lore_get_region_info() {
        $x = intval($_GET['x']);
        $y = intval($_GET['y']);
        global $wpdb;
        $table  = $wpdb->prefix . 'lore_regions';
        $region = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE region_x = %d AND region_y = %d", $x, $y
        ));
        if ($region) {
            wp_send_json_success($region);
        } else {
            wp_send_json_error('Region not found');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX: BATCH SYNC                                                    */
    /* ------------------------------------------------------------------ */

    public function ajax_lore_sync_batch() {
        check_ajax_referer('lore_sync', 'nonce');

        $host       = get_option('lore_db_host');
        $dbname     = get_option('lore_db_name');
        $user       = get_option('lore_db_user');
        $pass       = get_option('lore_db_password');
        $offset     = intval($_POST['offset']);
        $batch_size = intval($_POST['batch_size']) ?: 50;
        $clear      = intval($_POST['clear_first']);

        if (empty($host) || empty($dbname) || empty($user)) {
            wp_send_json_error('Database settings not configured. Please fill in the Database section in L.O.R.E. settings.');
            return;
        }

        try {
            $db = new mysqli($host, $user, $pass, $dbname);
            if ($db->connect_error) {
                wp_send_json_error('Database connection failed: ' . $db->connect_error);
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'lore_regions';

            if ($clear) {
                $wpdb->query("TRUNCATE TABLE $table");
            }

            // Total count
            $total = (int) $db->query("SELECT COUNT(*) FROM regions")->fetch_row()[0];

            // Fetch batch
            $result = $db->query(
                "SELECT uuid, regionName, locX, locY, serverURI 
                 FROM regions 
                 LIMIT $batch_size OFFSET $offset"
            );

            if (!$result) {
                wp_send_json_error('Query failed: ' . $db->error);
                return;
            }

            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $rx = (int) $row['locX'];
                $ry = (int) $row['locY'];

                // OpenSim stores coords in meters (>= 100000) or already as region coords
                if ($rx >= 100000) { $rx = (int)($rx / 256); $ry = (int)($ry / 256); }

                $wpdb->insert($table, array(
                    'region_uuid' => $row['uuid'],
                    'region_name' => $row['regionName'],
                    'region_x'    => $rx,
                    'region_y'    => $ry,
                    'server_uri'  => $row['serverURI'],
                    'status'      => 'active',
                ));
                $count++;
            }

            $db->close();
            wp_send_json_success(array('synced' => $count, 'total' => $total, 'offset' => $offset));

        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  CRON: AUTO-SYNC                                                     */
    /* ------------------------------------------------------------------ */

    public function activate_cron() {
        if (get_option('lore_auto_sync_enabled') == '1') {
            $this->schedule_cron();
        }
    }

    public function deactivate_cron() {
        wp_clear_scheduled_hook('lore_daily_sync');
    }

    private function schedule_cron() {
        if (!wp_next_scheduled('lore_daily_sync')) {
            wp_schedule_event(strtotime('tomorrow 3:00 AM'), 'daily', 'lore_daily_sync');
        }
    }

    public function cron_sync_regions() {
        // Only run if auto-sync is enabled
        if (get_option('lore_auto_sync_enabled') != '1') {
            return;
        }

        $host     = get_option('lore_db_host');
        $dbname   = get_option('lore_db_name');
        $user     = get_option('lore_db_user');
        $pass     = get_option('lore_db_password');

        if (empty($host) || empty($dbname) || empty($user)) {
            error_log('L.O.R.E. Auto-Sync: Database credentials not configured');
            return;
        }

        try {
            $db = new mysqli($host, $user, $pass, $dbname);
            if ($db->connect_error) {
                error_log('L.O.R.E. Auto-Sync: Database connection failed - ' . $db->connect_error);
                return;
            }

            global $wpdb;
            $table = $wpdb->prefix . 'lore_regions';

            // Clear existing regions
            $wpdb->query("TRUNCATE TABLE $table");

            // Get total count
            $total_result = $db->query("SELECT COUNT(*) FROM regions");
            $total = (int) $total_result->fetch_row()[0];

            // Sync all regions in batches
            $batch_size = 50;
            $offset = 0;
            $synced = 0;

            while ($offset < $total) {
                $result = $db->query(
                    "SELECT uuid, regionName, locX, locY, serverURI 
                     FROM regions 
                     LIMIT $batch_size OFFSET $offset"
                );

                if (!$result) {
                    error_log('L.O.R.E. Auto-Sync: Query failed at offset ' . $offset);
                    break;
                }

                while ($row = $result->fetch_assoc()) {
                    $rx = (int) $row['locX'];
                    $ry = (int) $row['locY'];

                    if ($rx >= 100000) { 
                        $rx = (int)($rx / 256); 
                        $ry = (int)($ry / 256); 
                    }

                    $wpdb->insert($table, array(
                        'region_uuid' => $row['uuid'],
                        'region_name' => $row['regionName'],
                        'region_x'    => $rx,
                        'region_y'    => $ry,
                        'server_uri'  => $row['serverURI'],
                        'status'      => 'active',
                    ));
                    $synced++;
                }

                $offset += $batch_size;
            }

            $db->close();
            error_log('L.O.R.E. Auto-Sync: Successfully synced ' . $synced . ' regions');

        } catch (Exception $e) {
            error_log('L.O.R.E. Auto-Sync: Error - ' . $e->getMessage());
        }
    }
}

new LORE_OpenSim_Map();

// Handle auto-sync setting changes
add_action('updated_option', function($option) {
    if ($option === 'lore_auto_sync_enabled') {
        $enabled = get_option('lore_auto_sync_enabled');
        
        // Clear existing schedule
        wp_clear_scheduled_hook('lore_daily_sync');
        
        // Schedule if enabled
        if ($enabled == '1') {
            if (!wp_next_scheduled('lore_daily_sync')) {
                wp_schedule_event(strtotime('tomorrow 3:00 AM'), 'daily', 'lore_daily_sync');
            }
        }
    }
});
