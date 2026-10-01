<?php
/**
 * Main Server Monitor Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Server_Monitor {
    
    private $detector;
    private $collector;
    private $license_manager;
    private $traffic_logger;
    private $settings;
    
    public function __construct() {
        $this->detector = RTSM_Platform_Detector::get_instance();
        $this->collector = new RTSM_Stats_Collector();
        $this->license_manager = RTSM_License_Manager::get_instance();
        $this->settings = RTSM_Settings_Manager::get_instance();
        
        // Initialize traffic logger using singleton pattern
        // This ensures only one instance exists across the entire request lifecycle
        $this->traffic_logger = RTSM_Traffic_Logger::instance();
        
        // Register hooks
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_admin_bar_assets']);
        add_action('admin_head', [$this, 'add_admin_bar_popup']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        
        // AJAX handlers
        add_action('wp_ajax_rtsm_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_rtsm_get_processes', [$this, 'ajax_get_processes']);
        add_action('wp_ajax_rtsm_kill_process', [$this, 'ajax_kill_process']);
        add_action('wp_ajax_rtsm_get_platform_info', [$this, 'ajax_get_platform_info']);
        add_action('wp_ajax_rtsm_resolve_incident', [$this, 'ajax_resolve_incident']);
        add_action('wp_ajax_rtsm_enable_cf_under_attack', [$this, 'ajax_enable_cf_under_attack']);
        add_action('wp_ajax_rtsm_disable_cf_under_attack', [$this, 'ajax_disable_cf_under_attack']);
        add_action('wp_ajax_rtsm_spawn_cron', [$this, 'ajax_spawn_cron']);
        add_action('wp_ajax_mmi_cf_get_diagnostics', [$this, 'ajax_get_diagnostics']);
    }
    
    public function register_settings() {
        register_setting('rtsm_settings', 'rtsm_refresh_interval');
        register_setting('rtsm_settings', 'rtsm_show_admin_bar');
        register_setting('rtsm_settings', 'rtsm_show_dashboard_widget');
    }

    /**
     * AJAX: Admin-initiated incident resolution.
     *
     * Fires the full resolve_incident() pipeline so alert logs, CF hook, and
     * grace-period transients are all cleaned up identically to auto-resolution.
     */
    public function ajax_resolve_incident() {
        if (!rtsm_user_can() || !check_ajax_referer('rtsm_nonce', 'nonce', false)) {
            rtsm_audit('incident.resolve', ['outcome' => 'denied']);
            wp_send_json_error('Unauthorised', 403);
        }

        $logger = RTSM_Traffic_Logger::instance();

        // Use reflection to call the private resolve_incident() method.
        // This keeps all resolution logic in one place and avoids duplication.
        $ref = new ReflectionMethod($logger, 'resolve_incident');
        $ref->setAccessible(true);

        $flag_file = rtrim(mmi_shared_lib_log_dir(), '/') . '/active-incident.flag';

        if (!file_exists($flag_file)) {
            wp_send_json_success(['message' => 'No active incident to clear.']);
        }

        $collector = RTSM_Stats_Collector::get_instance();
        $current_load = $collector->get_load_average()['1min'];
        $ref->invoke($logger, $current_load);

        rtsm_audit('incident.resolve', [
            'object_type' => 'incident',
            'outcome'     => 'success',
            'details'     => ['load' => $current_load],
        ]);

        wp_send_json_success(['message' => 'Incident cleared.', 'load' => $current_load]);
    }

    /**
     * AJAX: Enable Cloudflare Under Attack Mode via the existing rtsm_critical_load hook.
     * Fires the same pipeline the auto-escalation uses, so the CF plugin handles it consistently.
     */
    public function ajax_enable_cf_under_attack() {
        if (!rtsm_user_can() || !check_ajax_referer('rtsm_nonce', 'nonce', false)) {
            rtsm_audit('cloudflare.under_attack.enable', ['outcome' => 'denied']);
            wp_send_json_error('Unauthorised', 403);
        }

        $collector    = RTSM_Stats_Collector::get_instance();
        $current_load = $collector->get_load_average()['1min'];

        // Trigger the same hook that auto-escalation fires — CF plugin handles the rest
        do_action('rtsm_critical_load', $current_load, []);

        // Confirm the CF plugin actually set the flag
        $confirmed = class_exists('MMI_Settings') && (bool) MMI_Settings::get('mmi_cf_under_attack_active', false);
        // Invalidate the cause analysis cache so the next poll reflects the new state immediately
        delete_transient('rtsm_load_cause_analysis');

        rtsm_audit('cloudflare.under_attack.enable', [
            'object_type' => 'cloudflare_zone',
            'outcome'     => 'success',
            'details'     => ['confirmed' => $confirmed, 'load' => $current_load],
        ]);

        wp_send_json_success([
            'message'   => $confirmed
                ? 'Cloudflare Under Attack Mode is now active.'
                : 'Request sent — Cloudflare plugin will activate Under Attack Mode shortly.',
            'confirmed' => $confirmed,
            'load'      => $current_load,
        ]);
    }

    /**
     * AJAX: Disable Cloudflare Under Attack Mode and clear RTSM escalation flags.
     */
    public function ajax_disable_cf_under_attack() {
        if (!rtsm_user_can() || !check_ajax_referer('rtsm_nonce', 'nonce', false)) {
            rtsm_audit('cloudflare.under_attack.disable', ['outcome' => 'denied']);
            wp_send_json_error('Unauthorised', 403);
        }

        // Fire the deactivation hook — CF plugin listens and disables UAM
        do_action('rtsm_emergency_mode_deactivated', 0, []);

        // Clear RTSM's own escalation flags so the cause panel updates
        if (class_exists('MMI_Settings')) {
            MMI_Settings::set('mmi_cf_rtsm_under_attack_active', false);
            MMI_Settings::set('mmi_cf_rtsm_under_attack_since', 0);
        }
        delete_transient('rtsm_load_cause_analysis');

        $confirmed = class_exists('MMI_Settings') && ! (bool) MMI_Settings::get('mmi_cf_under_attack_active', false);

        rtsm_audit('cloudflare.under_attack.disable', [
            'object_type' => 'cloudflare_zone',
            'outcome'     => 'success',
            'details'     => ['confirmed' => $confirmed],
        ]);

        wp_send_json_success([
            'message'   => $confirmed
                ? 'Cloudflare Under Attack Mode has been disabled.'
                : 'Disable request sent — Cloudflare plugin will deactivate Under Attack Mode shortly.',
            'confirmed' => $confirmed,
        ]);
    }

    /**
     * AJAX: Generate intelligent diagnostics from RTSM's own traffic log.
     *
     * Reads the server-traffic-analysis.log, analyses URL hit patterns, IP
     * concentration, and request classifications, then returns a verdict with
     * confidence score, recommendation, url_analysis array, and user_agent_analysis
     * map — all shaped to match what displayDiagnostics() in the JS expects.
     *
     * Nonce: mmi-panel-admin-nonce (issued in diagnostics.php via wp_localize_script)
     */
    public function ajax_get_diagnostics() {
        if ( ! rtsm_user_can() ) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        if ( ! check_ajax_referer('mmi-panel-admin-nonce', 'nonce', false) ) {
            wp_send_json_error('Security check failed', 403);
        }

        $interval = sanitize_text_field( $_POST['interval'] ?? '1hour' );

        // Map the UI interval to a minutes cutoff.
        $interval_map = [
            '30min'   => 30,
            '1hour'   => 60,
            '6hours'  => 360,
            '24hours' => 1440,
        ];
        $minutes = $interval_map[ $interval ] ?? 60;
        $cutoff  = time() - ( $minutes * 60 );

        // Pull raw summary from the traffic logger.
        $summary = RTSM_Traffic_Logger::get_analysis_summary();

        if ( empty( $summary['total_incidents'] ) || $summary['total_incidents'] === 0 ) {
            wp_send_json_success( [
                'threat_type'        => 'normal',
                'verdict'            => '✅ No incidents recorded',
                'confidence'         => 100,
                'recommendation'     => 'No high-load incidents have been logged in the selected period. The server appears healthy.',
                'url_analysis'       => [],
                'user_agent_analysis'=> [],
            ] );
        }

        // ── URL analysis ─────────────────────────────────────────────────
        $total_hits = array_sum( $summary['top_urls'] );
        $url_analysis = [];

        $url_categories = [
            'xmlrpc.php'    => [ 'cat' => 'XML-RPC Attack Vector',   'concern' => 'critical' ],
            'wp-login.php'  => [ 'cat' => 'Auth Endpoint',           'concern' => 'high'     ],
            'admin-ajax.php'=> [ 'cat' => 'Heavy AJAX Handler',      'concern' => 'high'     ],
            'wp-cron.php'   => [ 'cat' => 'WP-Cron Pile-up',         'concern' => 'medium'   ],
            'wc-ajax'       => [ 'cat' => 'WooCommerce AJAX',         'concern' => 'medium'   ],
            '?s='           => [ 'cat' => 'Search Query',             'concern' => 'medium'   ],
        ];

        foreach ( $summary['top_urls'] as $url => $hits ) {
            $pct     = $total_hits > 0 ? round( ( $hits / $total_hits ) * 100, 1 ) : 0;
            $concern = 'normal';
            $cat     = 'General Request';
            $desc    = 'Standard page request.';

            foreach ( $url_categories as $pattern => $cfg ) {
                if ( strpos( $url, $pattern ) !== false ) {
                    $concern = $cfg['concern'];
                    $cat     = $cfg['cat'];
                    $desc    = "Matched pattern: {$pattern}";
                    break;
                }
            }

            $url_analysis[] = [
                'url'           => $url,
                'hits'          => $hits,
                'percentage'    => $pct,
                'category'      => $cat,
                'concern_level' => $concern,
                'description'   => $desc,
            ];
        }

        // ── User-agent / request-type analysis ────────────────────────
        $user_agent_analysis = [];
        foreach ( $summary['by_request_type'] as $type => $count ) {
            $label = ucfirst( $type );
            $user_agent_analysis[ $label ] = $count;
        }

        // ── Verdict logic ─────────────────────────────────────────────
        $xmlrpc_hits   = $summary['top_urls']['xmlrpc.php'] ?? 0;
        $login_hits    = $summary['top_urls']['wp-login.php'] ?? 0;
        $ajax_hits     = $summary['top_urls']['admin-ajax.php'] ?? 0;
        $top_ip_count  = ! empty( $summary['top_ips'] ) ? max( $summary['top_ips'] ) : 0;
        $total_ips     = array_sum( $summary['top_ips'] );
        $ip_pct        = $total_ips > 0 ? round( ( $top_ip_count / $total_ips ) * 100 ) : 0;

        $threat_type    = 'normal';
        $verdict        = '✅ Normal Operations';
        $confidence     = 75;
        $recommendation = 'Traffic patterns appear within normal parameters. Continue regular monitoring.';

        if ( $xmlrpc_hits > 5 || $login_hits > 10 ) {
            $threat_type    = 'bot_attack';
            $verdict        = '🚨 Bot Attack Detected';
            $confidence     = 85;
            $recommendation = 'XML-RPC or wp-login.php is being hammered. Consider blocking xmlrpc.php, enabling Cloudflare Under Attack Mode, and adding rate limiting to wp-login.php.';
        } elseif ( $ip_pct > 50 && $top_ip_count > 5 ) {
            $threat_type    = 'single_ip_attack';
            $verdict        = '🚨 Single-IP Attack';
            $confidence     = 80;
            $recommendation = 'A single IP is responsible for over 50% of high-load requests. Block this IP at the server or Cloudflare level immediately.';
        } elseif ( $ajax_hits > 20 ) {
            $threat_type    = 'code_issue';
            $verdict        = '🔧 Code Issue — Heavy AJAX';
            $confidence     = 70;
            $recommendation = 'admin-ajax.php is generating excessive load. Profile AJAX handlers with Query Monitor, enable object caching, and audit any polling scripts.';
        } elseif ( $summary['avg_load'] > 4.0 ) {
            $threat_type    = 'code_issue';
            $verdict        = '🔧 Elevated Load — Possible Code Issue';
            $confidence     = 60;
            $recommendation = 'Average server load during recorded incidents is high. Review active plugins, scheduled tasks, and database queries.';
        }

        wp_send_json_success( [
            'threat_type'        => $threat_type,
            'verdict'            => $verdict,
            'confidence'         => $confidence,
            'recommendation'     => $recommendation,
            'url_analysis'       => $url_analysis,
            'user_agent_analysis'=> $user_agent_analysis,
        ] );
    }

    /**
     * Enqueue admin bar assets on the public frontend for logged-in admins.
     *
     * The admin_enqueue_scripts hook only fires in wp-admin, so the wpadminbar
     * shown on the frontend gets no CSS without this counterpart on wp_enqueue_scripts.
     */
    public function enqueue_frontend_admin_bar_assets() {
        if ( ! is_admin_bar_showing() || ! rtsm_user_can() ) {
            return;
        }

        if ( ! $this->settings->get('rtsm_show_admin_bar', 1) ) {
            return;
        }

        wp_enqueue_style( 'rtsm-admin-bar', RTSM_PLUGIN_URL . 'assets/css/admin-bar.css', [], RTSM_VERSION );
        wp_enqueue_style( 'rtsm-admin-bar-popup', RTSM_PLUGIN_URL . 'assets/css/admin-bar-popup.css', [], RTSM_VERSION );
        wp_enqueue_script( 'rtsm-admin-bar-popup', RTSM_PLUGIN_URL . 'assets/js/admin-bar-popup.js', [ 'jquery' ], RTSM_VERSION, true );

        wp_localize_script( 'rtsm-admin-bar-popup', 'rtsmConfig', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('rtsm_nonce'),
            'upgradeUrl'      => esc_url( $this->license_manager->get_upgrade_url() ),
            'settingsUrl'     => admin_url('admin.php?page=mmi-rtsm'),
            'refreshInterval' => $this->settings->get_refresh_interval(),
            'i18n'            => [
                'loading'            => __('Loading...', 'mmi-rtsm'),
                'noProcesses'        => __('No processes', 'mmi-rtsm'),
                'killProcess'        => __('Kill process', 'mmi-rtsm'),
                'tryForceKill'       => __('Try force kill?', 'mmi-rtsm'),
                'processMonitor'     => __('Process Monitor Filters', 'mmi-rtsm'),
                'load1m'             => __('Load (1m)', 'mmi-rtsm'),
                'cpu'                => __('CPU', 'mmi-rtsm'),
                'memory'             => __('Memory', 'mmi-rtsm'),
                'connections'        => __('Connections', 'mmi-rtsm'),
                'unlockPremium'      => __('Unlock Premium Features', 'mmi-rtsm'),
                'premiumDescription' => __('Get real-time process monitoring with kill capabilities, advanced filters, and detailed metrics', 'mmi-rtsm'),
                'upgradeNow'         => __('Upgrade Now', 'mmi-rtsm'),
                'activateLicense'    => __('Activate License', 'mmi-rtsm'),
            ],
            'thresholds'      => RTSM_UI_Helpers::get_thresholds(),
        ] );
    }

    public function add_settings_page() {
        // Settings pages have been moved to centralized MannMade menu
        // See MMI Hub → Server Monitor for the unified interface
        
        // Keep this method for backwards compatibility but don't add menu items
        // Admin interface is now handled by RTSM_Admin class
    }
    
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!rtsm_user_can() || !$this->settings->get('rtsm_show_admin_bar', 1)) {
            return;
        }
        
        $load = $this->collector->get_load_average();
        $load1 = $load['1min'];
        $cpu = $this->collector->get_cpu_usage();
        $memory = $this->collector->get_memory_info();
        $connections = $this->collector->get_connections();
        
        $icon = '📊';
        $colorClass = 'success';
        if ($load1 >= 6.0) {
            $icon = '🔴';
            $colorClass = 'danger';
        } elseif ($load1 >= 4.0) {
            $icon = '🟡';
            $colorClass = 'warning';
        }
        
        $wp_admin_bar->add_node([
            'id' => 'rtsm-server-monitor',
            'title' => sprintf(
                '<span class="rtsm-admin-bar-content"><span>%s</span><span class="load-value %s">%.2f</span><span class="separator">|</span><span class="cpu-value">CPU: %s%%</span><span class="separator">|</span><span class="mem-value">Mem: %s%%</span><span class="separator">|</span><span class="conn-value">Conn: %s</span></span>',
                $icon,
                $colorClass,
                $load1,
                $cpu,
                $memory['percent'],
                is_array($connections) ? $connections['count'] : $connections
            ),
            'href' => '#',
            'meta' => [
                'class' => 'rtsm-admin-bar',
                'title' => __('Server Monitor - Click for details', 'mmi-rtsm')
            ]
        ]);
    }
    
    public function enqueue_admin_bar_assets($hook) {
        if (!rtsm_user_can()) {
            return;
        }
        
        // Enqueue admin bar popup assets globally in admin
        if ($this->settings->get('rtsm_show_admin_bar', 1)) {
            wp_enqueue_style('rtsm-admin-bar', RTSM_PLUGIN_URL . 'assets/css/admin-bar.css', [], RTSM_VERSION);
            wp_enqueue_style('rtsm-admin-bar-popup', RTSM_PLUGIN_URL . 'assets/css/admin-bar-popup.css', [], RTSM_VERSION);
            wp_enqueue_script('rtsm-admin-bar-popup', RTSM_PLUGIN_URL . 'assets/js/admin-bar-popup.js', ['jquery'], RTSM_VERSION, true);
            
            // Localize script for admin bar popup
            wp_localize_script('rtsm-admin-bar-popup', 'rtsmConfig', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rtsm_nonce'),
                'upgradeUrl' => esc_url($this->license_manager->get_upgrade_url()),
                'settingsUrl' => admin_url('admin.php?page=mmi-rtsm'),
                'refreshInterval' => $this->settings->get_refresh_interval(),
                'i18n' => [
                    'loading' => __('Loading...', 'mmi-rtsm'),
                    'noProcesses' => __('No processes', 'mmi-rtsm'),
                    'killProcess' => __('Kill process', 'mmi-rtsm'),
                    'tryForceKill' => __('Try force kill?', 'mmi-rtsm'),
                    'processMonitor' => __('Process Monitor Filters', 'mmi-rtsm'),
                    'load1m' => __('Load (1m)', 'mmi-rtsm'),
                    'cpu' => __('CPU', 'mmi-rtsm'),
                    'memory' => __('Memory', 'mmi-rtsm'),
                    'connections' => __('Connections', 'mmi-rtsm'),
                    'unlockPremium' => __('Unlock Premium Features', 'mmi-rtsm'),
                    'premiumDescription' => __('Get real-time process monitoring with kill capabilities, advanced filters, and detailed metrics', 'mmi-rtsm'),
                    'upgradeNow' => __('Upgrade Now', 'mmi-rtsm'),
                    'activateLicense' => __('Activate License', 'mmi-rtsm')
                ],
                'thresholds' => RTSM_UI_Helpers::get_thresholds(),
            ]);
        }
        
        // Enqueue dashboard widget assets on dashboard page
        if ($hook === 'index.php' && $this->settings->get('rtsm_show_dashboard_widget', 1)) {
            wp_enqueue_style('rtsm-dashboard-widget', RTSM_PLUGIN_URL . 'assets/css/dashboard-widget.css', [], RTSM_VERSION);
            wp_enqueue_script('rtsm-dashboard-widget', RTSM_PLUGIN_URL . 'assets/js/dashboard-widget.js', ['jquery'], RTSM_VERSION, true);
            
            // Localize script for dashboard widget
            wp_localize_script('rtsm-dashboard-widget', 'rtsmWidgetConfig', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rtsm_nonce'),
                'refreshInterval' => $this->settings->get_refresh_interval(),
                'settingsUrl' => admin_url('admin.php?page=mmi-rtsm'),
                'upgradeUrl' => esc_url($this->license_manager->get_upgrade_url()),
                'thresholds' => RTSM_UI_Helpers::get_thresholds(),
            ]);
        }
    }
    
    public function add_admin_bar_popup() {
        if (!is_admin_bar_showing() || !rtsm_user_can() || !$this->settings->get('rtsm_show_admin_bar', 1)) {
            return;
        }
        
        require_once RTSM_PLUGIN_DIR . 'templates/admin-bar-popup.php';
    }
    
    public function add_dashboard_widget() {
        if (!rtsm_user_can() || !$this->settings->get('rtsm_show_dashboard_widget', 1)) {
            return;
        }
        
        wp_add_dashboard_widget(
            'rtsm_dashboard_widget',
            '📊 ' . __('Server Monitor', 'mmi-rtsm'),
            [$this, 'render_dashboard_widget']
        );
    }
    
    public function render_dashboard_widget() {
        require_once RTSM_PLUGIN_DIR . 'templates/dashboard-widget.php';
    }
    
    public function ajax_get_stats() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rtsm_nonce' ) ) {
            wp_send_json_error( array(
                'message' => 'Security verification failed. Please refresh the page.',
                'code' => 'nonce_verification_failed'
            ), 403 );
        }
        
        if (!rtsm_user_can()) {
            wp_send_json_error('Insufficient permissions');
        }
        
        try {
            // Validate collector exists
            if (!$this->collector) {
                throw new Exception('Stats collector not initialized');
            }
            
            $snap = $this->collector->get_snapshot();
            $load        = $snap['load'];
            $cpu         = $snap['cpu'];
            $memory      = $snap['memory'];
            $connections = $snap['connections'];
            $disk        = $snap['disk'] ?? ['percent' => 0, 'total_gb' => 0, 'used_gb' => 0, 'free_gb' => 0];
            $processes = $this->collector->get_processes('all', 5);
            
            // Determine license status for frontend feature gating.
            $is_licensed = $this->license_manager ? $this->license_manager->is_licensed() : false;
            
            // Get server IP address
            $server_ip = $this->get_server_ip();
            
            wp_send_json_success([
                'load' => [
                    '1min' => round($load['1min'], 2),
                    '5min' => round($load['5min'], 2),
                    '15min' => round($load['15min'], 2)
                ],
                'cpu_usage' => number_format($cpu, 2, '.', ''),
                'cpu' => [
                    'percent' => number_format($cpu, 2, '.', '')
                ],
                'memory' => [
                    'percent' => number_format($memory['percent'], 2, '.', ''),
                    'used' => $memory['used'],
                    'total' => $memory['total']
                ],
                'disk' => [
                    'percent'  => $disk['percent'],
                    'total_gb' => $disk['total_gb'] ?? 0,
                    'used_gb'  => $disk['used_gb']  ?? 0,
                    'free_gb'  => $disk['free_gb']  ?? 0,
                ],
                'connections' => $connections,
                'processes' => $processes,
                'timestamp' => current_time('H:i:s'),
                'server_ip' => $server_ip,
                'is_licensed' => $is_licensed,
                'is_premium'  => $is_licensed,
                'has_pro'     => $is_licensed,
                'load_causes' => self::get_load_cause_analysis(),
            ]);
        } catch (Exception $e) {
            MMI_Logger::error( 'ajax_get_stats error', [ 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine() ], 'general', 'RTSM_Server_Monitor' );
            
            // Send user-friendly error with details
            wp_send_json_error([
                'message' => 'Error fetching server stats',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Gather cross-plugin load cause analysis.
     *
     * Queries the WP Background-Process Throttler, Cloudflare Integration,
     * and RTSM's own process attribution to explain WHY load is elevated.
     * Results are cached for 30 seconds (same TTL as the attribution transient).
     *
     * @return array {
     *   cloudflare: { active, under_attack, auto_escalated, manual_override, since },
     *   throttler:  { active, running_count, throttled_count, processes[] },
     *   attribution:{ dev_cpu, web_cpu, dev_fraction, suppress_maintenance, top_dev_processes[] },
     *   wpcron:     { overdue_count, due_soon_count },
     * }
     */
    public static function get_load_cause_analysis(): array {
        $cached = get_transient('rtsm_load_cause_analysis');
        if ($cached !== false) {
            return $cached;
        }

        /* ── Cloudflare status ─────────────────────────────────────── */
        $cf = [
            'active'          => false,
            'under_attack'    => false,
            'auto_escalated'  => false,  // RTSM triggered escalation
            'manual_override' => false,
            'since'           => null,
        ];
        if (class_exists('MMI_Settings')) {
            $cf['auto_escalated']  = (bool) MMI_Settings::get('mmi_cf_rtsm_under_attack_active', false);
            $cf['manual_override'] = (bool) MMI_Settings::get('mmi_cf_manual_override', false)
                                     && MMI_Settings::get('mmi_cf_manual_override_level', '') === 'under_attack';
            $cf['under_attack']    = $cf['auto_escalated'] || $cf['manual_override']
                                     || (bool) MMI_Settings::get('mmi_cf_under_attack_active', false);
            $cf['active']          = $cf['under_attack'];
            if ($cf['auto_escalated']) {
                $since_ts = (int) MMI_Settings::get('mmi_cf_rtsm_under_attack_since', 0);
                if ($since_ts > 0) {
                    $cf['since'] = wp_date('H:i:s', $since_ts);
                }
            }
        }

        /* ── WP Background-Process Throttler ─────────────────────── */
        $throttler = [
            'active'          => false,
            'running_count'   => 0,
            'throttled_count' => 0,
            'processes'       => [],
        ];
        if (class_exists('WP_Throttle_Manager')) {
            $manager   = WP_Throttle_Manager::instance();
            $running   = $manager->get_all_processes('running');
            $throttled = $manager->get_all_processes('throttled');
            $throttler['running_count']   = count($running);
            $throttler['throttled_count'] = count($throttled);
            $throttler['active']          = ($throttler['running_count'] + $throttler['throttled_count']) > 0;
            $stuck_count = 0;
            foreach (array_merge($running, $throttled) as $p) {
                $started_ts  = ! empty( $p['started_at'] ) ? strtotime( $p['started_at'] ) : null;
                $timeout_sec = max( 1, (int)( $p['timeout'] ?? 600 ) );
                $elapsed_sec = $started_ts ? ( time() - $started_ts ) : null;
                $is_stuck    = $started_ts && $elapsed_sec !== null && $elapsed_sec > $timeout_sec;
                if ($is_stuck) {
                    $stuck_count++;
                }
                $throttler['processes'][] = [
                    'name'        => $p['name']       ?? $p['process_id'],
                    'process_id'  => $p['process_id'] ?? '',
                    'status'      => $p['status']     ?? 'unknown',
                    'elapsed_sec' => $elapsed_sec,
                    'timeout_sec' => $timeout_sec,
                    'pause_count' => (int)( $p['pause_count'] ?? 0 ),
                    'is_stuck'    => $is_stuck,
                ];
            }
            $throttler['stuck_count'] = $stuck_count;
        }

        /* ── RTSM load attribution (dev vs web traffic) ───────────── */
        $attribution = [
            'dev_cpu'              => 0.0,
            'web_cpu'             => 0.0,
            'backup_cpu'          => 0.0,
            'dev_fraction'        => 0.0,
            'suppress_maintenance'=> false,
            'top_dev_processes'   => [],
            'top_backup_processes'=> [],
        ];
        // Re-use the transient cached by Traffic Logger if it exists.
        $cached_attr = get_transient('rtsm_process_cache');
        if ($cached_attr !== false && is_array($cached_attr)) {
            $attribution = array_merge($attribution, $cached_attr);
        }

        /* ── Memory pressure ─────────────────────────────────────────── */
        $memory_pressure = ['high' => false, 'percent' => 0.0, 'swap_used_mb' => 0, 'swap_total_mb' => 0];
        if (is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $st);
                preg_match('/SwapFree:\s+(\d+)/',  $meminfo, $sf);
                $swap_total_kb = isset($st[1]) ? (int)$st[1] : 0;
                $swap_free_kb  = isset($sf[1]) ? (int)$sf[1] : 0;
                $swap_used_kb  = max(0, $swap_total_kb - $swap_free_kb);
                $memory_pressure['swap_used_mb']  = (int) round($swap_used_kb  / 1024);
                $memory_pressure['swap_total_mb'] = (int) round($swap_total_kb / 1024);
            }
        }
        // Use cached memory stats from the attribution transient if available,
        // otherwise fall back to a fresh collector call.
        $mem_info_raw = get_transient('rtsm_stat_memory');
        $mem_pct = is_array($mem_info_raw) ? (float)($mem_info_raw['percent'] ?? 0) : 0.0;
        if ($mem_pct === 0.0) {
            $collector_instance = RTSM_Stats_Collector::get_instance();
            $mem_pct = (float)($collector_instance->get_memory_info()['percent'] ?? 0);
        }
        $memory_pressure['percent'] = round($mem_pct, 2);
        $memory_pressure['high']    = $mem_pct >= 85.0;

        /* ── WP-Cron overdue events ───────────────────────────────── */
        $wpcron = ['overdue_count' => 0, 'due_soon_count' => 0, 'overdue_hooks' => []];
        $crons  = _get_cron_array();
        if (is_array($crons)) {
            $now              = time();
            $hook_max_overdue = []; // hook_name => max overdue seconds
            foreach ($crons as $timestamp => $hooks) {
                if (!is_int($timestamp) || empty($hooks)) {
                    continue;
                }
                $hook_count = array_sum(array_map('count', array_values($hooks)));
                if ($timestamp < $now - 60) {
                    $wpcron['overdue_count'] += $hook_count;
                    foreach ($hooks as $hook => $instances) {
                        $overdue_sec = $now - $timestamp;
                        if (!isset($hook_max_overdue[$hook]) || $overdue_sec > $hook_max_overdue[$hook]) {
                            $hook_max_overdue[$hook] = $overdue_sec;
                        }
                    }
                } elseif ($timestamp <= $now + 120) {
                    $wpcron['due_soon_count'] += $hook_count;
                }
            }
            // Top 5 most-overdue hooks
            arsort($hook_max_overdue);
            foreach (array_slice($hook_max_overdue, 0, 5, true) as $hook => $sec) {
                $wpcron['overdue_hooks'][] = ['hook' => $hook, 'overdue_sec' => (int)$sec];
            }
        }

        /* ── CF-layer attack detection events ────────────────────────────────────── */
        // When the CF plugin detects a distributed botnet (site-wide rate counter), it deploys
        // a WAF rule and stores the event type in MMI_Settings. We surface that here so the
        // cause card can explain WHY CF escalated — separate from whether UAM is active.
        $cf_detection = ['active' => false, 'type' => '', 'rate' => 0, 'since_human' => ''];
        if (class_exists('MMI_Settings')) {
            $attack_type  = (string) MMI_Settings::get('mmi_cf_active_attack_type', '');
            $attack_since = (int)    MMI_Settings::get('mmi_cf_active_attack_since', 0);
            // Only surface if detected within the last 30 minutes — stale state is noise.
            if ($attack_type && $attack_since > 0 && (time() - $attack_since) < 1800) {
                $cf_detection = [
                    'active'      => true,
                    'type'        => $attack_type,
                    'rate'        => (int) MMI_Settings::get('mmi_cf_active_attack_rate', 0),
                    'since_human' => wp_date('H:i:s', $attack_since),
                ];
            }
        }

        $result = compact('cf', 'throttler', 'attribution', 'wpcron', 'memory_pressure', 'cf_detection');
        set_transient('rtsm_load_cause_analysis', $result, 30);
        return $result;
    }

    public function ajax_get_processes() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rtsm_nonce' ) ) {
            wp_send_json_error( array(
                'message' => 'Security verification failed. Please refresh the page.',
                'code' => 'nonce_verification_failed'
            ), 403 );
        }
        
        if (!rtsm_user_can()) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // License check: process monitor requires an active MMI Suite license.
        if (!$this->license_manager || !$this->license_manager->is_licensed()) {
            wp_send_json_error(__('Process monitoring requires an active MMI Suite license.', 'mmi-rtsm'));
        }

        $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';
        // Normalize underscore variant (high_cpu → high-cpu) sent by older button templates
        $filter = str_replace('_', '-', $filter);
        $processes = $this->collector->get_processes($filter, 20);
        
        wp_send_json_success(['processes' => $processes]);
    }
    
    public function ajax_kill_process() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rtsm_nonce' ) ) {
            rtsm_audit( 'process.kill', [ 'outcome' => 'denied', 'details' => [ 'reason' => 'nonce' ] ] );
            wp_send_json_error( array(
                'message' => 'Security verification failed. Please refresh the page.',
                'code' => 'nonce_verification_failed'
            ), 403 );
        }
        
        if (!rtsm_user_can()) {
            rtsm_audit('process.kill', ['outcome' => 'denied', 'details' => ['reason' => 'capability']]);
            wp_send_json_error('Insufficient permissions');
        }
        
        // License check: process management requires an active MMI Suite license.
        if (!$this->license_manager || !$this->license_manager->is_licensed()) {
            wp_send_json_error(__('Process management requires an active MMI Suite license.', 'mmi-rtsm'));
        }
        
        $pid = intval($_POST['pid']);
        $force = isset($_POST['force']) && $_POST['force'] === 'true';
        
        $result = $this->collector->kill_process($pid, $force);

        rtsm_audit('process.kill', [
            'object_type' => 'process',
            'object_id'   => $pid,
            'outcome'     => $result['success'] ? 'success' : 'failure',
            'details'     => ['signal' => $force ? 'SIGKILL' : 'SIGTERM', 'message' => $result['message']],
        ]);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function ajax_get_platform_info() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rtsm_nonce' ) ) {
            wp_send_json_error( array(
                'message' => 'Security verification failed. Please refresh the page.',
                'code' => 'nonce_verification_failed'
            ), 403 );
        }
        
        if (!rtsm_user_can()) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $info = $this->detector->get_platform_info();
        wp_send_json_success($info);
    }
    
    /**
     * AJAX: Trigger WP-Cron runner non-blocking via spawn_cron().
     * Safe to call multiple times — WP cron locking prevents pile-up.
     */
    public function ajax_spawn_cron() {
        if (!rtsm_user_can() || !check_ajax_referer('rtsm_nonce', 'nonce', false)) {
            rtsm_audit('cron.spawn', ['outcome' => 'denied']);
            wp_send_json_error('Unauthorised', 403);
        }
        spawn_cron();
        rtsm_audit('cron.spawn', ['outcome' => 'success']);
        wp_send_json_success(['message' => 'WP-Cron spawn triggered.']);
    }

    /**
     * Get server IP address
     * 
     * @return string Server IP address or 'Unknown' if cannot be determined
     */
    private function get_server_ip() {
        // Try multiple methods to get the server IP
        
        // Method 1: hostname -I command (works on most Linux systems)
        $ip = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }
        
        // Method 2: hostname -i command (alternative)
        $ip = @shell_exec("hostname -i 2>/dev/null | awk '{print $1}'");
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }
        
        // Method 3: SERVER_ADDR from PHP
        if (isset($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP)) {
            return $_SERVER['SERVER_ADDR'];
        }
        
        // Method 4: SERVER_NAME lookup (last resort)
        if (isset($_SERVER['SERVER_NAME'])) {
            $ip = gethostbyname($_SERVER['SERVER_NAME']);
            if ($ip && $ip !== $_SERVER['SERVER_NAME'] && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // If all methods fail
        return 'Unknown';
    }
}
