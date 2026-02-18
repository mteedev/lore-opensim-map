<?php
/**
 * Plugin Name: L.O.R.E. - Leaflet OpenSimulator Regional Explorer
 * Plugin URI:  https://nerdypappy.com/lore
 * Description: A modern, interactive OpenSimulator grid map plugin powered by Leaflet.js. Features region search, teleport links, batch sync with progress bar, and fully customizable colors.
 * Version:     1.1.0
 * Author:      Gundahar Bravin
 * Author URI:  https://nerdypappy.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lore-opensim-map
 */

if (!defined('ABSPATH')) exit;

define('LORE_VERSION',    '1.1.0');
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
            // Additional databases
            'lore_db2_enabled', 'lore_db2_host', 'lore_db2_name', 'lore_db2_user', 'lore_db2_password',
            'lore_db3_enabled', 'lore_db3_host', 'lore_db3_name', 'lore_db3_user', 'lore_db3_password',
            'lore_db4_enabled', 'lore_db4_host', 'lore_db4_name', 'lore_db4_user', 'lore_db4_password',
            'lore_db5_enabled', 'lore_db5_host', 'lore_db5_name', 'lore_db5_user', 'lore_db5_password',
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
                <h2>🗄️ OpenSimulator Database(s)</h2>
                <p>Connect to your OpenSimulator <strong>Robust</strong> database(s) so L.O.R.E. can sync region data.</p>
                <p style="color:#6b7280;font-size:13px;margin-bottom:20px;">💡 <strong>For load-balanced grids:</strong> If your grid shards regions across multiple databases, enable additional databases below.</p>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1200px;">
                    
                    <!-- PRIMARY DATABASE (LEFT COLUMN) -->
                    <div style="border:2px solid #2563eb;border-radius:8px;padding:20px;background:#f8fafc;">
                        <h3 style="margin-top:0;color:#2563eb;">Primary Database</h3>
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="padding-left:0;">Database Host</th>
                                <td>
                                    <input type="text" name="lore_db_host" value="<?php echo esc_attr(get_option('lore_db_host', '')); ?>" class="regular-text" placeholder="localhost or IP">
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0;">Database Name</th>
                                <td>
                                    <input type="text" name="lore_db_name" value="<?php echo esc_attr(get_option('lore_db_name', '')); ?>" class="regular-text" placeholder="robust">
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0;">User</th>
                                <td>
                                    <input type="text" name="lore_db_user" value="<?php echo esc_attr(get_option('lore_db_user', '')); ?>" class="regular-text" placeholder="opensim_user">
                                </td>
                            </tr>
                            <tr>
                                <th style="padding-left:0;">Password</th>
                                <td>
                                    <input type="password" name="lore_db_password" value="<?php echo esc_attr(get_option('lore_db_password', '')); ?>" class="regular-text">
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- ADDITIONAL DATABASES (RIGHT COLUMN) -->
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        
                        <?php for ($i = 2; $i <= 5; $i++): 
                            $enabled = get_option("lore_db{$i}_enabled") == '1';
                        ?>
                        
                        <!-- Database <?php echo $i; ?> -->
                        <div style="border:1px solid #d1d5db;border-radius:8px;padding:16px;background:#fafafa;">
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer;">
                                <input type="checkbox" 
                                       name="lore_db<?php echo $i; ?>_enabled" 
                                       value="1" 
                                       <?php checked($enabled); ?>
                                       class="lore-db-toggle"
                                       data-db="<?php echo $i; ?>"
                                       style="width:18px;height:18px;">
                                <strong style="font-size:14px;">Database <?php echo $i; ?></strong>
                            </label>
                            
                            <div id="lore-db<?php echo $i?>-fields" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                                <table class="form-table" style="margin:0;">
                                    <tr>
                                        <th style="padding:4px 0;width:80px;font-size:13px;">Host</th>
                                        <td style="padding:4px 0;">
                                            <input type="text" name="lore_db<?php echo $i; ?>_host" value="<?php echo esc_attr(get_option("lore_db{$i}_host", '')); ?>" class="regular-text" placeholder="db<?php echo $i; ?>.yourgrid.com" style="width:100%;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="padding:4px 0;font-size:13px;">Database</th>
                                        <td style="padding:4px 0;">
                                            <input type="text" name="lore_db<?php echo $i; ?>_name" value="<?php echo esc_attr(get_option("lore_db{$i}_name", '')); ?>" class="regular-text" placeholder="robust<?php echo $i; ?>" style="width:100%;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="padding:4px 0;font-size:13px;">User</th>
                                        <td style="padding:4px 0;">
                                            <input type="text" name="lore_db<?php echo $i; ?>_user" value="<?php echo esc_attr(get_option("lore_db{$i}_user", '')); ?>" class="regular-text" placeholder="lore_user" style="width:100%;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th style="padding:4px 0;font-size:13px;">Password</th>
                                        <td style="padding:4px 0;">
                                            <input type="password" name="lore_db<?php echo $i; ?>_password" value="<?php echo esc_attr(get_option("lore_db{$i}_password", '')); ?>" class="regular-text" style="width:100%;">
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        
                        <?php endfor; ?>
                        
                    </div>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('.lore-db-toggle').on('change', function() {
                        var dbNum = $(this).data('db');
                        var fields = $('#lore-db' + dbNum + '-fields');
                        if ($(this).is(':checked')) {
                            fields.slideDown(200);
                        } else {
                            fields.slideUp(200);
                        }
                    });
                });
                </script>

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

        $offset     = intval($_POST['offset']);
        $batch_size = intval($_POST['batch_size']) ?: 50;
        $clear      = intval($_POST['clear_first']);

        global $wpdb;
        $table = $wpdb->prefix . 'lore_regions';

        if ($clear) {
            $wpdb->query("TRUNCATE TABLE $table");
        }

        // Collect all enabled databases
        $databases = array();
        
        // Primary database
        $host1 = get_option('lore_db_host');
        $name1 = get_option('lore_db_name');
        $user1 = get_option('lore_db_user');
        $pass1 = get_option('lore_db_password');
        
        if (!empty($host1) && !empty($name1) && !empty($user1)) {
            $databases[] = array(
                'host' => $host1,
                'name' => $name1,
                'user' => $user1,
                'pass' => $pass1,
                'label' => 'Primary Database'
            );
        }
        
        // Additional databases 2-5
        for ($i = 2; $i <= 5; $i++) {
            if (get_option("lore_db{$i}_enabled") == '1') {
                $host = get_option("lore_db{$i}_host");
                $name = get_option("lore_db{$i}_name");
                $user = get_option("lore_db{$i}_user");
                $pass = get_option("lore_db{$i}_password");
                
                if (!empty($host) && !empty($name) && !empty($user)) {
                    $databases[] = array(
                        'host' => $host,
                        'name' => $name,
                        'user' => $user,
                        'pass' => $pass,
                        'label' => "Database {$i}"
                    );
                }
            }
        }

        if (empty($databases)) {
            wp_send_json_error('No database credentials configured. Please configure at least the Primary Database.');
            return;
        }

        try {
            $total_regions = 0;
            $synced_count = 0;
            $errors = array();
            
            // Loop through all databases
            foreach ($databases as $db_config) {
                $db = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);
                
                if ($db->connect_error) {
                    $errors[] = $db_config['label'] . ': Connection failed';
                    continue;
                }

                // Get total count from this database
                $count_result = $db->query("SELECT COUNT(*) FROM regions");
                if ($count_result) {
                    $db_total = (int) $count_result->fetch_row()[0];
                    $total_regions += $db_total;
                }

                // Fetch batch from this database
                $result = $db->query(
                    "SELECT uuid, regionName, locX, locY, serverURI 
                     FROM regions 
                     LIMIT $batch_size OFFSET $offset"
                );

                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $rx = (int) $row['locX'];
                        $ry = (int) $row['locY'];

                        // OpenSim stores coords in meters (>= 100000) or already as region coords
                        if ($rx >= 100000) { 
                            $rx = (int)($rx / 256); 
                            $ry = (int)($ry / 256); 
                        }

                        // Insert or update (in case of duplicate UUIDs across databases)
                        $wpdb->replace($table, array(
                            'region_uuid' => $row['uuid'],
                            'region_name' => $row['regionName'],
                            'region_x'    => $rx,
                            'region_y'    => $ry,
                            'server_uri'  => $row['serverURI'],
                            'status'      => 'active',
                        ));
                        $synced_count++;
                    }
                }

                $db->close();
            }
            
            $response = array(
                'synced' => $synced_count, 
                'total' => $total_regions, 
                'offset' => $offset,
                'databases' => count($databases)
            );
            
            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }

            wp_send_json_success($response);

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
        if (get_option('lore_auto_sync_enabled') != '1') return;

        global $wpdb;
        $table = $wpdb->prefix . 'lore_regions';
        $wpdb->query("TRUNCATE TABLE $table");

        // Collect all enabled databases
        $databases = array();
        
        // Primary
        $host1 = get_option('lore_db_host');
        $name1 = get_option('lore_db_name');
        $user1 = get_option('lore_db_user');
        $pass1 = get_option('lore_db_password');
        if (!empty($host1) && !empty($name1) && !empty($user1)) {
            $databases[] = array($host1, $user1, $pass1, $name1, 'Primary');
        }
        
        // Additional 2-5
        for ($i = 2; $i <= 5; $i++) {
            if (get_option("lore_db{$i}_enabled") == '1') {
                $h = get_option("lore_db{$i}_host");
                $n = get_option("lore_db{$i}_name");
                $u = get_option("lore_db{$i}_user");
                $p = get_option("lore_db{$i}_password");
                if (!empty($h) && !empty($n) && !empty($u)) {
                    $databases[] = array($h, $u, $p, $n, "DB{$i}");
                }
            }
        }

        if (empty($databases)) {
            error_log('L.O.R.E. Auto-Sync: No databases configured');
            return;
        }

        try {
            $total_synced = 0;
            
            foreach ($databases as list($host, $user, $pass, $dbname, $label)) {
                $db = new mysqli($host, $user, $pass, $dbname);
                if ($db->connect_error) {
                    error_log("L.O.R.E. Auto-Sync {$label}: Connection failed");
                    continue;
                }

                $count_result = $db->query("SELECT COUNT(*) FROM regions");
                $total = $count_result ? (int) $count_result->fetch_row()[0] : 0;
                $synced = 0;

                $batch_size = 50;
                $offset = 0;

                while ($offset < $total) {
                    $result = $db->query("SELECT uuid, regionName, locX, locY, serverURI FROM regions LIMIT $batch_size OFFSET $offset");
                    if (!$result) break;

                    while ($row = $result->fetch_assoc()) {
                        $rx = (int) $row['locX'];
                        $ry = (int) $row['locY'];
                        if ($rx >= 100000) { $rx = (int)($rx / 256); $ry = (int)($ry / 256); }

                        $wpdb->replace($table, array(
                            'region_uuid' => $row['uuid'],
                            'region_name' => $row['regionName'],
                            'region_x' => $rx,
                            'region_y' => $ry,
                            'server_uri' => $row['serverURI'],
                            'status' => 'active',
                        ));
                        $synced++;
                    }
                    $offset += $batch_size;
                }

                $db->close();
                $total_synced += $synced;
                error_log("L.O.R.E. Auto-Sync {$label}: Synced {$synced} regions");
            }

            error_log("L.O.R.E. Auto-Sync: Total synced {$total_synced} regions from " . count($databases) . " database(s)");

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
