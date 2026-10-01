<?php
/**
 * RTSM Admin Interface
 * Real-Time Server Monitor administration page (server monitoring only, no Cloudflare content)
 */

if (!defined('ABSPATH')) exit;

class RTSM_Admin {
    
    /**
     * Initialize admin interface
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);

        // Enqueue assets when on RTSM page
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Register AJAX handlers for tab loading
        add_action('wp_ajax_rtsm_load_tab', [__CLASS__, 'ajax_load_tab']);
    }

    /**
     * Submenu under the shared 'mmi-dashboard' ("MannMade") parent menu
     * (see mmi-shared/bootstrap.php).
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'mmi-dashboard',
            __('Real-Time Server Monitor', 'mmi-rtsm'),
            __('Server Monitor', 'mmi-rtsm'),
            rtsm_required_capability(),
            'mmi-rtsm',
            [__CLASS__, 'render_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        // Only load on RTSM page - check multiple possible hook values
        $valid_hooks = ['mmi-dashboard_page_mmi-rtsm', 'toplevel_page_mmi-rtsm', 'admin_page_mmi-rtsm'];
        $is_rtsm_page = in_array($hook, $valid_hooks) || (isset($_GET['page']) && $_GET['page'] === 'mmi-rtsm');
        
        if (!$is_rtsm_page) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('chartjs', RTSM_PLUGIN_URL . 'assets/vendor/chart.umd.min.js', [], '4.4.1', true);
        
        // Enqueue tab navigation script first — rtsmAdmin data is localized to this handle,
        // so it must be registered before rtsm-admin-page (which depends on it).
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/js/admin-tabs-navigation.js')) {
            wp_enqueue_script('rtsm-admin-tabs', RTSM_PLUGIN_URL . 'assets/js/admin-tabs-navigation.js', ['jquery'], RTSM_VERSION, true);
            
            // Localize script with AJAX data - attached to the tab navigation script
            $settings = RTSM_Settings_Manager::get_instance();
            // Determine CPU core count for load-percentage calculations in JS.
            // nproc is the most reliable method; /proc/cpuinfo counting is the fallback.
            $rtsm_cpu_cores = 1;
            if ( function_exists( 'shell_exec' ) ) {
                $nproc = (int) trim( @shell_exec( 'nproc' ) );
                if ( $nproc > 0 ) { $rtsm_cpu_cores = $nproc; }
            }
            if ( $rtsm_cpu_cores <= 1 && is_readable( '/proc/cpuinfo' ) ) {
                $rtsm_cpu_cores = max( 1, substr_count( file_get_contents( '/proc/cpuinfo' ), 'processor' ) );
            }
            wp_localize_script('rtsm-admin-tabs', 'rtsmAdmin', [
                'ajaxUrl'         => admin_url('admin-ajax.php'),
                'nonce'           => wp_create_nonce('rtsm_nonce'), // Must match ajax_get_stats() validation
                'refreshInterval' => $settings->get_refresh_interval(),
                'cpuCores'        => $rtsm_cpu_cores,
                'throttlerUrl'    => admin_url('tools.php?page=wp-throttle'),
                'cloudflareUrl'   => admin_url('admin.php?page=mmi-cloudflare'),
                'logsUrl'         => admin_url('admin.php?page=mmi-rtsm&tab=logs'),
                'processesUrl'    => admin_url('admin.php?page=mmi-rtsm&tab=processes'),
                'thresholds'      => RTSM_UI_Helpers::get_thresholds(),
            ]);
        }
        
        // Enqueue admin page refresh script — depends on rtsm-admin-tabs so rtsmAdmin is
        // guaranteed to be defined (wp_localize_script outputs inline data before the
        // dependency script, which runs before the dependent).
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/js/admin-page.js')) {
            wp_enqueue_script('rtsm-admin-page', RTSM_PLUGIN_URL . 'assets/js/admin-page.js', ['jquery', 'rtsm-admin-tabs', 'mmi-escape-html'], RTSM_VERSION, true);
        }

        // Enqueue refactored inline event handlers (consolidated from inline HTML onclick handlers)
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/js/refactored-inline.js')) {
            wp_enqueue_script('rtsm-refactored-inline', RTSM_PLUGIN_URL . 'assets/js/refactored-inline.js', ['jquery', 'mmi-escape-html'], RTSM_VERSION, true);
        }
        
        // NOTE: mmi-suite-common is loaded globally by MMI Hub Asset Manager
        // Only load RTSM-specific CSS that doesn't conflict with unified system
        
        // Enqueue refactored inline styles (consolidated from inline HTML)
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/refactored-inline.css')) {
            wp_enqueue_style('rtsm-refactored-inline', RTSM_PLUGIN_URL . 'assets/css/refactored-inline.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        // Enqueue premium overlay styles (unified premium feature system)
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/premium-overlays.css')) {
            wp_enqueue_style('rtsm-premium-overlays', RTSM_PLUGIN_URL . 'assets/css/premium-overlays.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        // Enqueue tab navigation styles
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/admin-tabs-navigation.css')) {
            wp_enqueue_style('rtsm-admin-tabs', RTSM_PLUGIN_URL . 'assets/css/admin-tabs-navigation.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        // Enqueue admin page styles
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/admin-page.css')) {
            wp_enqueue_style('rtsm-admin-page', RTSM_PLUGIN_URL . 'assets/css/admin-page.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        // Enqueue dashboard tab styles (for stat boxes and charts)
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/dashboard-tab.css')) {
            wp_enqueue_style('rtsm-dashboard-tab', RTSM_PLUGIN_URL . 'assets/css/dashboard-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        // Enqueue tab-specific styles
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/settings-tab.css')) {
            wp_enqueue_style('rtsm-settings-tab', RTSM_PLUGIN_URL . 'assets/css/settings-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/traffic-tab.css')) {
            wp_enqueue_style('rtsm-traffic-tab', RTSM_PLUGIN_URL . 'assets/css/traffic-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }

        if (file_exists(RTSM_PLUGIN_DIR . 'assets/js/traffic-tab.js')) {
            wp_enqueue_script('rtsm-traffic-tab', RTSM_PLUGIN_URL . 'assets/js/traffic-tab.js', ['jquery', 'rtsm-admin-tabs'], RTSM_VERSION, true);
        }
        
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/processes-tab.css')) {
            wp_enqueue_style('rtsm-processes-tab', RTSM_PLUGIN_URL . 'assets/css/processes-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }

        // Was never enqueued despite existing and being the only stylesheet
        // for templates/admin/tabs/logs.php's .log-viewer/.no-logs-notice/
        // .log-actions/.mmi-panel-log-* classes — found while investigating
        // the .mmi-badge collision below; the Activity Logs tab has been
        // rendering with none of its intended styling (dark monospace log
        // viewer, severity coloring, panels) since whenever this enqueue
        // call was dropped or never added.
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/logs-tab.css')) {
            wp_enqueue_style('rtsm-logs-tab', RTSM_PLUGIN_URL . 'assets/css/logs-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }
        
        if (file_exists(RTSM_PLUGIN_DIR . 'assets/css/diagnostics-tab.css')) {
            wp_enqueue_style('rtsm-diagnostics-tab', RTSM_PLUGIN_URL . 'assets/css/diagnostics-tab.css', ['mmi-suite-common'], RTSM_VERSION);
        }
    }
    
    /**
     * Render main admin page (server monitoring only) - Unified single page
     */
    public static function render_page() {
        if (!rtsm_user_can()) {
            return;
        }

        // License-or-trial gate (MMI_License_Gate, shared library) — the
        // menu itself always registers (see rtsm_init()'s own comment), but
        // the real dashboard only renders once licensed or trialing.
        if ( ! class_exists( 'MMI_License_Gate' ) || ! MMI_License_Gate::is_open( 'mmi-rtsm' ) ) {
            if ( class_exists( 'MMI_License_Gate' ) ) {
                MMI_License_Gate::render_locked_page( 'mmi-rtsm', 'Real-Time Server Monitor' );
            }
            return;
        }

        // Check premium status
        $license_manager = RTSM_License_Manager::get_instance();
        $is_premium = $license_manager && method_exists($license_manager, 'is_premium') ? $license_manager->is_premium() : false;
        
        // Load the tabbed admin interface template
        include RTSM_PLUGIN_DIR . 'templates/admin/admin-page.php';
    }
    
    /**
     * Render Dashboard Tab (legacy - kept for backwards compatibility)
     */
    public static function render_dashboard_inline() {
        if (!rtsm_user_can()) {
            return;
        }
        
        // Get server stats
        $stats = self::get_server_stats();
        
        // Check premium status
        $license_manager = RTSM_License_Manager::get_instance();
        $is_premium = $license_manager && method_exists($license_manager, 'is_premium') ? $license_manager->is_premium() : false;
        
        ?>
        <div class="wrap">
            <div class="mmi-header">
                <h1><span class="dashicons dashicons-performance"></span> Real-Time Server Monitor</h1>
                <p class="mmi-header-description">Comprehensive server performance monitoring and analysis</p>
            </div>
            <div class="wp-header-end"></div>
            
            <div class="rtsm-dashboard">
                <!-- Main Server Stats Grid -->
                <h2>Server Status Overview</h2>
                <div class="dashboard-stats-row" id="rtsm-dashboard-stats">
                    
                    <!-- Load Average (matches admin bar) -->
                    <div class="mmi-stat-box <?php 
                        $load1 = $stats['load']['1min'];
                        echo $load1 >= 6.0 ? 'error' : ($load1 >= 4.0 ? 'warning' : 'success'); 
                    ?>">
                        <div class="mmi-stat-label">Load Average (1m)</div>
                        <div class="mmi-stat-value">
                            <?php echo number_format($load1, 2); ?>
                        </div>
                        <div class="mmi-stat-meta">
                            5m: <?php echo number_format($stats['load']['5min'], 2); ?> | 
                            15m: <?php echo number_format($stats['load']['15min'], 2); ?>
                        </div>
                    </div>
                    
                    <!-- CPU Usage (matches admin bar) -->
                    <div class="mmi-stat-box info">
                        <div class="mmi-stat-label">CPU Usage</div>
                        <div class="mmi-stat-value">
                            <?php echo number_format($stats['cpu_usage'], 2); ?>%
                        </div>
                        <div class="mmi-stat-meta">
                            Current processor utilization
                        </div>
                    </div>
                    
                    <!-- Memory Usage (matches admin bar) -->
                    <div class="mmi-stat-box info">
                        <div class="mmi-stat-label">Memory Usage</div>
                        <div class="mmi-stat-value">
                            <?php echo number_format($stats['memory']['percent'], 2); ?>%
                        </div>
                        <div class="mmi-stat-meta">
                            <?php echo esc_html($stats['memory']['used']); ?> / <?php echo esc_html($stats['memory']['total']); ?>
                        </div>
                    </div>
                    
                    <!-- Connections (matches admin bar) -->
                    <div class="mmi-stat-box">
                        <div class="mmi-stat-label">Active Connections</div>
                        <div class="mmi-stat-value">
                            <?php echo is_array($stats['connections']) ? esc_html($stats['connections']['count']) : esc_html($stats['connections']); ?>
                        </div>
                        <div class="mmi-stat-meta">
                            Current network connections
                        </div>
                    </div>
                </div>
                
                <!-- Additional Stats Row -->
                <div class="dashboard-stats-row m-top-xlarge">
                    
                    <div class="mmi-stat-box warning">
                        <div class="mmi-stat-label">Disk Usage</div>
                        <div class="mmi-stat-value">
                            <?php echo esc_html($stats['disk_usage']); ?>%
                        </div>
                        <div class="mmi-stat-meta">
                            <?php echo esc_html($stats['disk_free']); ?> available
                        </div>
                    </div>
                    
                    <div class="mmi-stat-box success">
                        <div class="mmi-stat-label">Server Uptime</div>
                        <div class="mmi-stat-value">
                            <?php echo esc_html($stats['uptime']); ?>
                        </div>
                        <div class="mmi-stat-meta">
                            <?php if ($stats['last_boot'] !== 'N/A'): ?>
                                Last boot: <?php echo esc_html($stats['last_boot']); ?>
                            <?php else: ?>
                                Uptime data requires /proc access
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mmi-stat-box info">
                        <div class="mmi-stat-label">PHP Workers</div>
                        <div class="mmi-stat-value">
                            <?php echo esc_html($stats['php_workers']['active']); ?> / <?php echo esc_html($stats['php_workers']['max']); ?>
                        </div>
                        <div class="mmi-stat-meta">
                            Active / Maximum
                        </div>
                    </div>
                    
                    <div class="mmi-stat-box">
                        <div class="mmi-stat-label">Total Processes</div>
                        <div class="mmi-stat-value">
                            <?php echo esc_html($stats['process_count']); ?>
                        </div>
                        <div class="mmi-stat-meta">
                            Running system processes
                        </div>
                    </div>
                </div>
                
                <!-- Performance Thresholds -->
                <div class="mmi-panel-card m-top-xlarge">
                    <h3>Performance Thresholds & Current Status</h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Normal</th>
                                <th>Warning</th>
                                <th>Critical</th>
                                <th>Current Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Load Average (1m)</strong></td>
                                <td>&lt; 4.0</td>
                                <td>4.0 - 6.0</td>
                                <td>&gt; 6.0</td>
                                <td>
                                    <?php 
                                    if ($load1 >= 6.0) echo '<span class="status-critical">🔴 Critical (' . number_format($load1, 2) . ')</span>';
                                    elseif ($load1 >= 4.0) echo '<span class="status-warning">🟡 Warning (' . number_format($load1, 2) . ')</span>';
                                    else echo '<span class="status-success">✅ Normal (' . number_format($load1, 2) . ')</span>';
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>CPU Usage</strong></td>
                                <td>&lt; 60%</td>
                                <td>60% - 80%</td>
                                <td>&gt; 80%</td>
                                <td>
                                    <?php 
                                    $cpu = $stats['cpu_usage'];
                                    if ($cpu >= 80) echo '<span class="status-critical">🔴 Critical (' . number_format($cpu, 2) . '%)</span>';
                                    elseif ($cpu >= 60) echo '<span class="status-warning">🟡 Warning (' . number_format($cpu, 2) . '%)</span>';
                                    else echo '<span class="status-success">✅ Normal (' . number_format($cpu, 2) . '%)</span>';
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Memory Usage</strong></td>
                                <td>&lt; 75%</td>
                                <td>75% - 90%</td>
                                <td>&gt; 90%</td>
                                <td>
                                    <?php 
                                    $mem = $stats['memory']['percent'];
                                    if ($mem >= 90) echo '<span class="status-critical">🔴 Critical (' . number_format($mem, 2) . '%)</span>';
                                    elseif ($mem >= 75) echo '<span class="status-warning">🟡 Warning (' . number_format($mem, 2) . '%)</span>';
                                    else echo '<span class="status-success">✅ Normal (' . number_format($mem, 2) . '%)</span>';
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Process Management Section -->
                <div class="mmi-panel-card m-top-xlarge">
                    <div class="rtsm-flex-header">
                        <div>
                            <h3 class="margin-none">Process Management</h3>
                            <p class="m-vertical-small text-secondary">Monitor server processes. <?php if (!$is_premium): ?><em>(Process termination requires premium license)</em><?php endif; ?></p>
                        </div>
                        <button type="button" class="button mmi-action-btn" data-action="reload">
                            <span class="dashicons dashicons-update"></span> Refresh
                        </button>
                    </div>
                    
                    <?php if (!$is_premium): ?>
                        <div class="notice notice-info inline m-vertical-large">
                            <p><strong>💡 Tip:</strong> For full process management including filtering and termination, use the <strong>Real-Time Server Monitor popup</strong> from the admin bar.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php 
                    $processes = self::get_top_processes();
                    if (!empty($processes)): 
                    ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th class="width-80">PID</th>
                                    <th class="width-120">User</th>
                                    <th class="width-80">CPU %</th>
                                    <th class="width-80">Memory %</th>
                                    <th>Command</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($processes as $proc): ?>
                                <tr>
                                    <td><code><?php echo esc_html($proc['pid']); ?></code></td>
                                    <td><?php echo esc_html($proc['user']); ?></td>
                                    <td>
                                        <span class="<?php echo $proc['cpu'] > 50 ? 'status-critical' : ($proc['cpu'] > 25 ? 'status-warning' : 'text-secondary'); ?>">
                                            <?php echo esc_html($proc['cpu']); ?>%
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($proc['mem']); ?>%</td>
                                    <td><code class="command-code"><?php echo esc_html($proc['command']); ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="notice notice-warning inline margin-none">
                            <p><strong>⚠️ No process data available.</strong> This feature requires shell_exec() to be enabled on your server.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- System Information -->
                <div class="system-info m-top-xlarge">
                    <h3>System Information</h3>
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <td class="width-200"><strong>Operating System:</strong></td>
                                <td><?php echo esc_html($stats['os']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>PHP Version:</strong></td>
                                <td><?php echo esc_html($stats['php_version']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Web Server:</strong></td>
                                <td><?php echo esc_html($stats['web_server']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Database:</strong></td>
                                <td><?php echo esc_html($stats['database']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Dashboard tab - Server overview
     */
    private static function render_dashboard_tab() {
        // Get server stats using same methods as admin bar for consistency
        $stats = self::get_server_stats();
        
        // Determine load status colors (matching admin bar logic)
        $load1 = $stats['load']['1min'];
        $load_status = 'success';
        $load_color = '#45852C';
        if ($load1 >= 6.0) {
            $load_status = 'danger';
            $load_color = '#dc3232';
        } elseif ($load1 >= 4.0) {
            $load_status = 'warning';
            $load_color = '#f0b849';
        }
        
        ?>
        <div class="rtsm-dashboard">
            <h2>Server Status Overview</h2>
            <p class="description">Real-time metrics synchronized with admin bar display</p>
            
            <div class="rtsm-stats-grid" id="rtsm-dashboard-stats">
                
                <!-- Load Average (matches admin bar) -->
                <div class="rtsm-stat-card border-<?php echo $load_status; ?>">
                    <h3>Load Average (1m)</h3>
                    <div class="stat-value status-<?php echo $load_status; ?>">
                        <?php echo number_format($load1, 2); ?>
                    </div>
                    <div class="stat-subtitle">
                        5m: <?php echo number_format($stats['load']['5min'], 2); ?> | 
                        15m: <?php echo number_format($stats['load']['15min'], 2); ?>
                    </div>
                </div>
                
                <!-- CPU Usage (matches admin bar) -->
                <div class="rtsm-stat-card">
                    <h3>CPU Usage</h3>
                    <div class="stat-value cpu">
                        <?php echo number_format($stats['cpu_usage'], 2); ?>%
                    </div>
                    <div class="stat-subtitle">
                        Current processor utilization
                    </div>
                </div>
                
                <!-- Memory Usage (matches admin bar) -->
                <div class="rtsm-stat-card">
                    <h3>Memory Usage</h3>
                    <div class="stat-value memory">
                        <?php echo number_format($stats['memory']['percent'], 2); ?>%
                    </div>
                    <div class="stat-subtitle">
                        <?php echo esc_html($stats['memory']['used']); ?> / <?php echo esc_html($stats['memory']['total']); ?>
                    </div>
                </div>
                
                <!-- Connections (matches admin bar) -->
                <div class="rtsm-stat-card">
                    <h3>Active Connections</h3>
                    <div class="stat-value connections">
                        <?php echo is_array($stats['connections']) ? esc_html($stats['connections']['count']) : esc_html($stats['connections']); ?>
                    </div>
                    <div class="stat-subtitle">
                        Current network connections
                    </div>
                </div>
            </div>
            
            <!-- Additional Stats -->
            <div class="rtsm-stats-grid m-top-xlarge">
                
                <!-- Disk Usage -->
                <div class="rtsm-stat-card">
                    <h3>Disk Usage</h3>
                    <div class="stat-value disk">
                        <?php echo esc_html($stats['disk_usage']); ?>%
                    </div>
                    <div class="stat-subtitle">
                        <?php echo esc_html($stats['disk_free']); ?> available
                    </div>
                </div>
                
                <!-- Server Uptime -->
                <div class="rtsm-stat-card">
                    <h3>Server Uptime</h3>
                    <div class="stat-value uptime">
                        <?php echo esc_html($stats['uptime']); ?>
                    </div>
                    <div class="stat-subtitle">
                        <?php if ($stats['last_boot'] !== 'N/A'): ?>
                            Last boot: <?php echo esc_html($stats['last_boot']); ?>
                        <?php else: ?>
                            Uptime data requires /proc access
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- PHP Workers -->
                <div class="rtsm-stat-card">
                    <h3>PHP Workers</h3>
                    <div class="stat-value">
                        <?php echo esc_html($stats['php_workers']['active']); ?> / <?php echo esc_html($stats['php_workers']['max']); ?>
                    </div>
                    <div class="stat-subtitle">
                        Active / Maximum
                    </div>
                </div>
                
                <!-- Process Count -->
                <div class="rtsm-stat-card">
                    <h3>Total Processes</h3>
                    <div class="stat-value">
                        <?php echo esc_html($stats['process_count']); ?>
                    </div>
                    <div class="stat-subtitle">
                        Running system processes
                    </div>
                </div>
            </div>
            
            <div class="system-info">
                <h3>System Information</h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Operating System:</strong></td>
                            <td><?php echo esc_html($stats['os']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>PHP Version:</strong></td>
                            <td><?php echo esc_html($stats['php_version']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Web Server:</strong></td>
                            <td><?php echo esc_html($stats['web_server']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Database:</strong></td>
                            <td><?php echo esc_html($stats['database']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Metrics tab - Performance charts
     */
    private static function render_metrics_tab() {
        // Get current stats for display
        $stats = self::get_server_stats();
        ?>
        <div class="rtsm-metrics">
            <h2>Performance Metrics</h2>
            <p class="description">Real-time server performance monitoring synchronized with admin bar</p>
            
            <!-- Current Snapshot -->
            <div class="mmi-panel-card m-bottom-xlarge">
                <h3 class="margin-none m-bottom-medium">Current Performance Snapshot</h3>
                <div class="mmi-panel-stat-grid five-columns">
                    <div class="mmi-panel-stat-box">
                        <div class="stat-label">Load (1m)</div>
                        <div class="stat-value"><?php echo number_format($stats['load']['1min'], 2); ?></div>
                    </div>
                    <div class="mmi-panel-stat-box">
                        <div class="stat-label">CPU Usage</div>
                        <div class="stat-value"><?php echo number_format($stats['cpu_usage'], 2); ?>%</div>
                    </div>
                    <div class="mmi-panel-stat-box">
                        <div class="stat-label">Memory</div>
                        <div class="stat-value"><?php echo number_format($stats['memory']['percent'], 2); ?>%</div>
                    </div>
                    <div class="mmi-panel-stat-box">
                        <div class="stat-label">Connections</div>
                        <div class="stat-value"><?php echo is_array($stats['connections']) ? esc_html($stats['connections']['count']) : esc_html($stats['connections']); ?></div>
                    </div>
                    <div class="mmi-panel-stat-box">
                        <div class="stat-label">PHP Workers</div>
                        <div class="stat-value"><?php echo $stats['php_workers']['active']; ?> / <?php echo $stats['php_workers']['max']; ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Performance Thresholds -->
            <div class="mmi-panel-card">
                <h3>Performance Thresholds & Current Status</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Normal</th>
                            <th>Warning</th>
                            <th>Critical</th>
                            <th>Current Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Load Average (1m)</strong></td>
                            <td>&lt; 4.0</td>
                            <td>4.0 - 6.0</td>
                            <td>&gt; 6.0</td>
                            <td>
                                <?php 
                                $load1 = $stats['load']['1min'];
                                if ($load1 >= 6.0) echo '<span class="rtsm-status-critical">🔴 Critical (' . number_format($load1, 2) . ')</span>';
                                elseif ($load1 >= 4.0) echo '<span class="rtsm-status-warning">🟡 Warning (' . number_format($load1, 2) . ')</span>';
                                else echo '<span class="rtsm-status-success">✅ Normal (' . number_format($load1, 2) . ')</span>';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>CPU Usage</strong></td>
                            <td>&lt; 60%</td>
                            <td>60% - 80%</td>
                            <td>&gt; 80%</td>
                            <td>
                                <?php 
                                $cpu = $stats['cpu_usage'];
                                if ($cpu >= 80) echo '<span class="rtsm-status-critical">🔴 Critical (' . number_format($cpu, 2) . '%)</span>';
                                elseif ($cpu >= 60) echo '<span class="rtsm-status-warning">🟡 Warning (' . number_format($cpu, 2) . '%)</span>';
                                else echo '<span class="rtsm-status-success">✅ Normal (' . number_format($cpu, 2) . '%)</span>';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Memory Usage</strong></td>
                            <td>&lt; 75%</td>
                            <td>75% - 90%</td>
                            <td>&gt; 90%</td>
                            <td>
                                <?php 
                                $mem = $stats['memory']['percent'];
                                if ($mem >= 90) echo '<span class="rtsm-status-critical">🔴 Critical (' . number_format($mem, 2) . '%)</span>';
                                elseif ($mem >= 75) echo '<span class="rtsm-status-warning">🟡 Warning (' . number_format($mem, 2) . '%)</span>';
                                else echo '<span class="rtsm-status-success">✅ Normal (' . number_format($mem, 2) . '%)</span>';
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <p class="m-top-xlarge text-secondary">
                <strong>Note:</strong> Metrics are synchronized with the admin bar display and refresh on page load.
            </p>
        </div>
        <?php
    }
    
    /**
     * Render Processes tab - Process management
     */
    private static function render_processes_tab() {
        // Check if user has premium features
        $license_manager = RTSM_License_Manager::get_instance();
        $is_premium = $license_manager && method_exists($license_manager, 'is_premium') ? $license_manager->is_premium() : false;
        
        ?>
        <div class="rtsm-processes">
            <h2>Process Management</h2>
            <p>Monitor and manage server processes in real-time. <?php if (!$is_premium): ?><em>(Process termination requires premium license)</em><?php endif; ?></p>
            
            <?php if (!$is_premium): ?>
                <div class="notice notice-info">
                    <p><strong>💡 Note:</strong> Process viewing is available in free version. Upgrade to Premium to unlock process termination capabilities through the admin bar popup.</p>
                </div>
            <?php endif; ?>
            
            <div class="mmi-panel-card">
                <div class="rtsm-flex-header m-bottom-medium">
                    <h3 class="margin-none">Top Processes by CPU Usage</h3>
                    <button type="button" class="button mmi-action-btn" data-action="reload">
                        <span class="dashicons dashicons-update"></span> Refresh
                    </button>
                </div>
                
                <?php 
                $processes = self::get_top_processes();
                if (!empty($processes)): 
                ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th class="width-80">PID</th>
                                <th class="width-120">User</th>
                                <th class="width-80">CPU %</th>
                                <th class="width-80">Memory %</th>
                                <th>Command</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processes as $proc): ?>
                            <tr>
                                <td><code><?php echo esc_html($proc['pid']); ?></code></td>
                                <td><?php echo esc_html($proc['user']); ?></td>
                                <td>
                                    <span class="<?php echo $proc['cpu'] > 50 ? 'rtsm-cpu-high' : ($proc['cpu'] > 25 ? 'rtsm-cpu-medium' : 'rtsm-cpu-normal'); ?>">
                                        <?php echo esc_html($proc['cpu']); ?>%
                                    </span>
                                </td>
                                <td><?php echo esc_html($proc['mem']); ?>%</td>
                                <td><code class="small-code"><?php echo esc_html($proc['command']); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="notice notice-warning inline margin-none">
                        <p><strong>⚠️ No process data available.</strong> This feature requires shell_exec() to be enabled on your server.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="recommendations-section m-top-xlarge">
                <h3 class="margin-none m-bottom-medium">Process Management via Admin Bar</h3>
                <p class="margin-none">
                    <strong>💡 Tip:</strong> For full process management capabilities including filtering (PHP, MySQL, Node.js, Python) and 
                    <?php echo $is_premium ? 'process termination' : '<em>process termination (premium)</em>'; ?>, 
                    use the <strong>Real-Time Server Monitor popup</strong> accessible from the admin bar at the top of any admin page. 
                    Click the server stats in the admin bar to open the interactive process manager.
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get server statistics (matching admin bar data structure)
     */
    private static function get_server_stats() {
        $stats = [];
        
        // Use Stats Collector for consistency with admin bar
        if (class_exists('RTSM_Stats_Collector')) {
            $collector = new RTSM_Stats_Collector();
            
            // Load (as array, matching admin bar)
            $stats['load'] = $collector->get_load_average();
            
            // CPU
            $stats['cpu_usage'] = $collector->get_cpu_usage();
            
            // Memory (as array, matching admin bar)
            $memory_info = $collector->get_memory_info();
            $stats['memory'] = [
                'percent' => $memory_info['percent'],
                'used' => $memory_info['used'] . 'MB',
                'total' => $memory_info['total'] . 'MB'
            ];
            
            // Connections (matching admin bar)
            $stats['connections'] = $collector->get_connections();
            
            // PHP Workers
            $stats['php_workers'] = self::get_php_worker_info();
            
            // Process count
            $stats['process_count'] = self::get_process_count();
        } else {
            // Fallback
            $load = @sys_getloadavg();
            $stats['load'] = [
                '1min' => $load[0] ?? 0,
                '5min' => $load[1] ?? 0,
                '15min' => $load[2] ?? 0
            ];
            $stats['cpu_usage'] = 0;
            $stats['memory'] = ['percent' => 0, 'used' => 'N/A', 'total' => 'N/A'];
            $stats['connections'] = 0;
            $stats['php_workers'] = ['active' => 0, 'max' => 0];
            $stats['process_count'] = 0;
        }
        
        // Disk
        $disk = self::get_disk_info();
        $stats['disk_usage'] = $disk['percent'];
        $stats['disk_free'] = $disk['free'];
        
        // Uptime (improved to actually work)
        $uptime = self::get_uptime();
        $stats['uptime'] = $uptime['formatted'];
        $stats['last_boot'] = $uptime['boot_time'];
        
        // System info
        $stats['os'] = php_uname('s') . ' ' . php_uname('r');
        $stats['php_version'] = phpversion();
        $stats['web_server'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        
        global $wpdb;
        $stats['database'] = 'MySQL ' . $wpdb->db_version();
        
        return $stats;
    }
    
    /**
     * Get memory information
     */
    private static function get_memory_info() {
        // Try to read /proc/meminfo (may be blocked by open_basedir)
        $meminfo = @file_get_contents('/proc/meminfo');
        if (!$meminfo) {
            // Fallback: use memory_get_usage() for PHP memory only
            return ['percent' => 0, 'used' => 'N/A', 'total' => 'Restricted'];
        }
        
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
        
        $total_kb = isset($total[1]) ? intval($total[1]) : 0;
        $available_kb = isset($available[1]) ? intval($available[1]) : 0;
        $used_kb = $total_kb - $available_kb;
        
        $percent = $total_kb > 0 ? round(($used_kb / $total_kb) * 100, 1) : 0;
        
        return [
            'percent' => $percent,
            'used' => self::format_bytes($used_kb * 1024),
            'total' => self::format_bytes($total_kb * 1024)
        ];
    }
    
    /**
     * Get disk information
     */
    private static function get_disk_info() {
        $disk_total = @disk_total_space(ABSPATH);
        $disk_free = @disk_free_space(ABSPATH);
        
        if (!$disk_total || !$disk_free) {
            return ['percent' => 0, 'free' => '0 GB'];
        }
        
        $disk_used = $disk_total - $disk_free;
        $percent = round(($disk_used / $disk_total) * 100, 1);
        
        return [
            'percent' => $percent,
            'free' => self::format_bytes($disk_free)
        ];
    }
    
    /**
     * Get uptime information
     */
    private static function get_uptime() {
        // Method 1: Try /proc/uptime
        $uptime_data = @file_get_contents('/proc/uptime');
        if ($uptime_data) {
            $uptime_seconds = intval(explode(' ', $uptime_data)[0]);
            
            $days = floor($uptime_seconds / 86400);
            $hours = floor(($uptime_seconds % 86400) / 3600);
            $minutes = floor(($uptime_seconds % 3600) / 60);
            
            $formatted = '';
            if ($days > 0) $formatted .= $days . 'd ';
            if ($hours > 0) $formatted .= $hours . 'h ';
            $formatted .= $minutes . 'm';
            
            $boot_time = date('M j, H:i', time() - $uptime_seconds);
            
            return [
                'formatted' => trim($formatted),
                'boot_time' => $boot_time
            ];
        }
        
        // Method 2: Try shell uptime command
        if (function_exists('shell_exec')) {
            $uptime_output = @shell_exec('uptime -s 2>&1');
            if ($uptime_output && stripos($uptime_output, 'command not found') === false) {
                $boot_timestamp = strtotime(trim($uptime_output));
                if ($boot_timestamp) {
                    $uptime_seconds = time() - $boot_timestamp;
                    $days = floor($uptime_seconds / 86400);
                    $hours = floor(($uptime_seconds % 86400) / 3600);
                    
                    return [
                        'formatted' => $days . 'd ' . $hours . 'h',
                        'boot_time' => date('M j, H:i', $boot_timestamp)
                    ];
                }
            }
        }
        
        // Fallback: Calculate based on oldest PHP-FPM process (approximation)
        if (function_exists('shell_exec')) {
            $oldest_php = @shell_exec("ps -eo pid,etime,comm | grep php-fpm | awk '{print $2}' | head -1 2>&1");
            if ($oldest_php && !empty(trim($oldest_php))) {
                return [
                    'formatted' => trim($oldest_php),
                    'boot_time' => 'Approximate'
                ];
            }
        }
        
        // Last resort: Show restriction notice
        return ['formatted' => 'Unavailable', 'boot_time' => 'N/A'];
    }
    
    /**
     * Get top processes
     */
    private static function get_top_processes() {
        // shell_exec may be disabled for security
        if (!function_exists('shell_exec')) {
            return [];
        }
        
        try {
            $output = @shell_exec('ps aux --sort=-%cpu | head -11 2>&1');
            if (!$output || stripos($output, 'command not found') !== false) {
                return [];
            }
            
            $lines = explode("\n", trim($output));
            array_shift($lines); // Remove header
            
            $processes = [];
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                
                $parts = preg_split('/\s+/', $line, 11);
                if (count($parts) < 11) continue;
                
                $processes[] = [
                    'user' => $parts[0],
                    'pid' => $parts[1],
                    'cpu' => $parts[2],
                    'mem' => $parts[3],
                    'command' => substr($parts[10], 0, 80)
                ];
            }
            
            return $processes;
        } catch (Throwable $e) {
            return [];
        }
    }
    
    /**
     * AJAX handler for loading tab content
     */
    public static function ajax_load_tab() {
        // Graceful nonce verification (don't die on failure)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'rtsm_nonce')) {
            wp_send_json_error('Security verification failed. Please refresh the page.');
        }
        
        // Check permissions
        if (!rtsm_user_can()) {
            wp_send_json_error('Permission denied');
        }
        
        // Get tab name
        $tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : '';
        
        // Validate tab
        $valid_tabs = ['dashboard', 'traffic', 'processes', 'diagnostics', 'settings', 'logs'];
        if (!in_array($tab, $valid_tabs)) {
            wp_send_json_error('Invalid tab');
        }
        
        // Check if tab file exists
        $tab_file = RTSM_PLUGIN_DIR . 'templates/admin/tabs/' . $tab . '.php';
        if (!file_exists($tab_file)) {
            wp_send_json_error('Tab template not found');
        }
        
        // Capture tab output
        ob_start();
        include $tab_file;
        $content = ob_get_clean();
        
        // Return content
        wp_send_json_success($content);
    }
    
    /**
     * Format bytes to human-readable size
     */
    private static function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get PHP worker information
     */
    private static function get_php_worker_info() {
        $cached = get_transient( 'rtsm_php_worker_info' );
        if ( $cached !== false ) {
            return $cached;
        }

        if (!function_exists('shell_exec')) {
            return ['active' => 0, 'max' => 0];
        }
        
        try {
            // Get PHP-FPM process count
            $output = @shell_exec("ps aux | grep -E 'php-fpm|php' | grep -v grep | wc -l 2>&1");
            $active = $output ? intval(trim($output)) : 0;
            
            // Try to get max workers from pool config
            $max = 20; // Default fallback
            $pool_config = @shell_exec("grep -r 'pm.max_children' /etc/php* 2>&1 | head -1");
            if ($pool_config && preg_match('/pm\\.max_children\\s*=\\s*(\\d+)/', $pool_config, $matches)) {
                $max = intval($matches[1]);
            }
            
            $result = ['active' => $active, 'max' => $max];
            set_transient( 'rtsm_php_worker_info', $result, 15 );
            return $result;
        } catch (Throwable $e) {
            return ['active' => 0, 'max' => 0];
        }
    }
    
    /**
     * Get total process count
     */
    private static function get_process_count() {
        $cached = get_transient( 'rtsm_process_count' );
        if ( $cached !== false ) {
            return $cached;
        }

        if (!function_exists('shell_exec')) {
            return 0;
        }
        
        try {
            $output = @shell_exec('ps aux | wc -l 2>&1');
            $result = $output ? max(0, intval(trim($output)) - 1) : 0; // -1 for header line
            set_transient( 'rtsm_process_count', $result, 15 );
            return $result;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
