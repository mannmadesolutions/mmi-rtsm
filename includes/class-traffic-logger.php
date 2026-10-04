<?php
/**
 * Traffic Analysis Logger
 * Logs high-load incidents with request details for forensic analysis
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Traffic_Logger {
    
    private static $instance = null;
    
    private $log_file;
    private $incident_file;
    private $load_threshold;      // Computed from CPU core count (≥ bar 50%: cores × 1.0)
    private $critical_threshold;  // Computed from CPU core count (≥ bar 80%: cores × 1.6)
    private $emergency_threshold; // Computed from CPU core count (≥ bar 100%: cores × 2.0)
    private $grace_period_seconds = 120; // Must be high for 120 seconds before activating
    private $minimum_activations_needed = 3; // Require 3 high-load checks before activating
    private $collector;
    private $incident_active = false;
    private $archiver = null;
    private $high_load_checks = 0; // Counter for sustained load checks
    private $last_high_load_timestamp = 0; // Track when high load started
    // Note: Process caching now uses WordPress transients ('rtsm_process_cache')
    // instead of instance variables, providing persistence across requests
    
    /**
     * Get singleton instance of RTSM_Traffic_Logger
     * Prevents multiple instantiations and wasted resource initialization
     * 
     * @return RTSM_Traffic_Logger
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @return float Elevated-load threshold (cores × 1.0, floored at 5.0). */
    public function get_load_threshold(): float      { return $this->load_threshold; }
    /** @return float Critical-load threshold (cores × 1.6, capped by RunCloud floor). */
    public function get_critical_threshold(): float  { return $this->critical_threshold; }
    /** @return float Emergency-load threshold (cores × 2.0). */
    public function get_emergency_threshold(): float { return $this->emergency_threshold; }
    
    /**
     * Constructor - Private to enforce singleton pattern
     */
    private function __construct() {
        // Centralized log directory, resolved the same way regardless of
        // whether mmi-hub is present (ADR-0006).
        $log_dir = rtrim(mmi_shared_lib_log_dir(), '/');
        $this->log_file = $log_dir . '/server-traffic-analysis.log';
        $this->incident_file = $log_dir . '/active-incident.flag';
        // Scale thresholds to actual CPU core count — aligned with the load bar severity system:
        // ELEVATED bar ≥ 50% (cores × 1.0), CRITICAL bar ≥ 80% (cores × 1.6), EMERGENCY bar ≥ 100% (cores × 2.0)
        $cpu_cores = 1;
        if ( function_exists( 'shell_exec' ) ) {
            $nproc = (int) trim( @shell_exec( 'nproc' ) );
            if ( $nproc > 0 ) { $cpu_cores = $nproc; }
        }
        if ( $cpu_cores <= 1 && is_readable( '/proc/cpuinfo' ) ) {
            $cpu_cores = max( 1, substr_count( @file_get_contents( '/proc/cpuinfo' ), 'processor' ) );
        }
        $this->load_threshold      = round( $cpu_cores * 1.0, 1 ); // ELEVATED (bar 50%): start logging
        $this->critical_threshold  = round( $cpu_cores * 1.6, 1 ); // CRITICAL (bar 80%): maintenance after grace period
        $this->emergency_threshold = round( $cpu_cores * 2.0, 1 ); // EMERGENCY (bar 100%): immediate lockdown

        // HARD FLOOR: RunCloud alerts at load 5.0 regardless of core count.
        // If our computed CRITICAL threshold is higher than 5.0 (e.g. on a 4-core box
        // critical = 6.4) we would never fire CF escalation before RunCloud alerts.
        // Cap critical_threshold so it never exceeds 5.0 on this server's RunCloud plan.
        $runcloud_alert_floor = (float) MMI_Settings::get( 'rtsm_runcloud_alert_floor', 5.0 );
        if ( $this->critical_threshold > $runcloud_alert_floor ) {
            $this->critical_threshold = $runcloud_alert_floor;
        }

        $this->collector = RTSM_Stats_Collector::get_instance();
        
        // Initialize event archiver (if available)
        if (class_exists('MMI_Event_Archiver')) {
            $this->archiver = MMI_Event_Archiver::instance();
        }
        
        // Hook into WordPress init to capture request data
        add_action('init', [$this, 'check_and_log'], 1);
        
        // Schedule periodic checks
        if (!wp_next_scheduled('rtsm_periodic_check')) {
            wp_schedule_event(time(), 'every_minute', 'rtsm_periodic_check');
        }
        add_action('rtsm_periodic_check', [$this, 'periodic_check']);
    }
    
    /**
     * Check load on every request and log if high
     * AUTO-REMEDIATION: Activates emergency mode at critical thresholds
     * ENHANCED: Grace period + bot filtering + dev tool attribution
     */
    public function check_and_log() {
        // Prevent double-execution within the same WP bootstrap: both the init
        // hook and periodic_check() can converge on this method within one request.
        if (get_transient('rtsm_check_log_ran')) {
            return;
        }
        set_transient('rtsm_check_log_ran', 1, 30); // 30-second dedup — matches attribution cache TTL
        
        $load = $this->collector->get_load_average();
        $load_1min = $load['1min'];
        $request_data = $this->get_request_data();

        // Auto-resolve any stale incident flag when load has normalised.
        // Runs here (not just in periodic_check) so any web request can clear
        // the flag — WP-Cron is request-triggered and may be infrequent after
        // an attack when CF has reduced incoming traffic.
        if ($this->is_incident_active() && $load_1min < ($this->load_threshold * 0.8)) {
            $this->resolve_incident($load_1min);
        }

        // Use cached attribution only — ps aux is NEVER run on the init hot-path.
        // The periodic_check() WP-Cron event force-refreshes this cache every minute.
        $attribution_cached = get_transient('rtsm_process_cache');
        $attribution = is_array($attribution_cached) ? $attribution_cached : [
            'dev_cpu'             => 0.0,
            'web_cpu'             => 0.0,
            'dev_fraction'        => 0.0,
            'suppress_maintenance'=> false,
            'categories'          => [],
            'top_dev_processes'   => [],
        ];
        $is_bot = $this->is_bot_traffic($request_data);
        
        // ALWAYS generate alert entries for graph data (even normal load)
        $this->generate_alert($load_1min, $this->get_severity_level($load_1min), $attribution);
        
        // Dev-tool attribution suppresses .maintenance file creation, but NEVER suppresses
        // Cloudflare escalation or incident logging — a dev session cannot justify load 5+
        // affecting real visitors.  suppress_maintenance only gates the .maintenance file.
        $suppress_maintenance_only = $attribution['suppress_maintenance'];
        
        // CRITICAL: Emergency auto-remediation (short transient-based grace period)
        if ($load_1min >= $this->emergency_threshold) {
            if ($this->should_activate_on_emergency_load()) {
                // suppress_maintenance skips the .maintenance file but still fires CF hook
                $this->activate_emergency_mode($load_1min, 'EMERGENCY', $suppress_maintenance_only);
            }
            $this->archive_load_event($load_1min, 'EMERGENCY');
            do_action('rtsm_critical_load', $load_1min, $request_data);
        }
        // WARNING: High load auto-remediation (with grace period)
        elseif ($load_1min >= $this->critical_threshold) {
            if ($this->should_activate_on_critical_load($load_1min, $is_bot)) {
                $this->activate_emergency_mode($load_1min, 'CRITICAL', $suppress_maintenance_only);
                $this->archive_load_event($load_1min, 'CRITICAL');
                do_action('rtsm_critical_load', $load_1min, $request_data);
            }
        }
        // MONITORING: Log high load
        elseif ($load_1min >= $this->load_threshold) {
            $this->log_request($load_1min);
            do_action('rtsm_high_load_detected', $load_1min, $request_data);
        } elseif ($suppress_maintenance_only && $load_1min >= $this->load_threshold) {
            // Dev tools are responsible — still log for visibility
            $this->log_request($load_1min);
        }
    }

    /**
     * Analyse which process categories are responsible for current CPU load.
     * Uses WordPress transients for persistent caching across requests.
     *
     * Returns an array:
     *   'dev_cpu'              => float  – total CPU% attributed to dev tools
     *   'web_cpu'             => float  – total CPU% attributed to web/PHP/DB
     *   'dev_fraction'        => float  – 0.0–1.0 share of sampled CPU from dev tools
     *   'suppress_maintenance'=> bool   – true when dev tools explain the load
     *   'categories'          => array  – breakdown by category
     *   'top_dev_processes'   => array  – descriptive names of offending dev processes
     */
    private function get_load_attribution() {
        // Check persistent WordPress transient cache first
        // This survives across requests, solving the instance-based cache limitation
        $cached = get_transient('rtsm_process_cache');
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $result = [
            'dev_cpu'              => 0.0,
            'web_cpu'             => 0.0,
            'backup_cpu'          => 0.0,
            'dev_fraction'        => 0.0,
            'suppress_maintenance'=> false,
            'categories'          => [],
            'top_dev_processes'   => [],
            'top_backup_processes'=> [],
        ];

        if (!function_exists('shell_exec')) {
            // Cache empty result for 30 seconds
            set_transient('rtsm_process_cache', $result, 30);
            return $result;
        }

        // Grab top 40 processes sorted by CPU
        $output = @shell_exec('ps aux --sort=-%cpu 2>/dev/null | head -41 | tail -n +2');
        if (empty($output)) {
            // Cache empty result for 30 seconds
            set_transient('rtsm_process_cache', $result, 30);
            return $result;
        }

        $dev_patterns = [
            // VS Code Remote Server – any process spawned under the vscode-server directory
            'vscode_server'    => '/\.vscode-server\//i',
            // Generic VS Code CLI helper
            'vscode_cli'       => '/vscode.*code-[0-9a-f]+/i',
            // PHP Intelephense (runs as a Node child of the extension host)
            'intelephense'     => '/intelephense/i',
            // TypeScript / JavaScript language servers (tsserver, typescript-language-server)
            'lang_server_ts'   => '/tsserver\.js|typescript-language-server/i',
            // Generic language servers for any editor
            'lang_server'      => '/language-server|langserver|pylsp|pyls |jdtls|clangd/i',
            // SSH daemon child processes (individual connections, not the listener)
            'ssh_session'      => '/sshd:.+(pts|\@notty|priv)/i',
            // Generic development tools invoked over SSH
            'dev_tools'        => '/(rsync|rclone|scp |sftp-server|git (fetch|clone|pull|push)|npm (install|run|ci)|composer (install|update|dump)|yarn (install|add))/i',
            // Monitoring / health-check agents that are benign
            'monitoring'       => '/runcloudagent|runcloud|newrelic|datadog|htop|iostat|vmstat|sar\b/i',
            // Scheduled backup tools — resource-intensive but temporary and expected
            'backup'           => '/mydumper|myloader|mysqldump|mariadb-dump|xtrabackup|innobackupex|percona-xtrabackup|borgbackup|restic|duplicati|rclone sync|rclone copy|rsync.*--backup/i',
        ];

        // Process categories that belong to web traffic (should still trigger alerts)
        $web_patterns = [
            'php_fpm'  => '/php-fpm: pool/i',
            'nginx'    => '/nginx: worker/i',
            'apache'   => '/httpd|apache2/i',
            'mysql'    => '/mysqld|mariadbd/i',
            'redis'    => '/redis-server/i',
        ];

        $lines = array_filter(explode("\n", trim($output)));
        $total_cpu    = 0.0;
        $dev_cpu      = 0.0;
        $web_cpu      = 0.0;
        $backup_cpu   = 0.0;
        $categories   = [];
        $dev_procs    = [];
        $backup_procs = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) < 11) continue;

            $cpu     = floatval($parts[2]);
            $command = $parts[10];
            $total_cpu += $cpu;

            // Check dev patterns first
            $matched_dev = false;
            foreach ($dev_patterns as $category => $pattern) {
                if (preg_match($pattern, $command)) {
                    $dev_cpu += $cpu;
                    $categories[$category] = ($categories[$category] ?? 0.0) + $cpu;
                    $matched_dev = true;

                    // Build a human-readable label for logging
                    $label = $this->describe_dev_process($command, $category);
                    if ($cpu >= 1.0 && !in_array($label, $dev_procs)) {
                        $dev_procs[] = sprintf('%s (%.1f%%)', $label, $cpu);
                    }
                    // Track backup processes separately for cause-panel granularity
                    if ($category === 'backup') {
                        $backup_cpu += $cpu;
                        if (!in_array($label, $backup_procs)) {
                            $backup_procs[] = sprintf('%s (%.1f%%)', $label, $cpu);
                        }
                    }
                    break;
                }
            }

            if (!$matched_dev) {
                foreach ($web_patterns as $category => $pattern) {
                    if (preg_match($pattern, $command)) {
                        $web_cpu += $cpu;
                        break;
                    }
                }
            }
        }

        $result['dev_cpu']            = round($dev_cpu, 1);
        $result['web_cpu']           = round($web_cpu, 1);
        $result['backup_cpu']        = round($backup_cpu, 1);
        $result['categories']        = $categories;
        $result['top_dev_processes'] = $dev_procs;
        $result['top_backup_processes'] = $backup_procs;

        if ($total_cpu > 0) {
            $result['dev_fraction'] = round($dev_cpu / $total_cpu, 2);
        }

        // Suppress maintenance when:
        //   a) dev tools own ≥ 40% of sampled CPU, AND
        //   b) web/PHP processes are not also spiking independently (web_cpu < 80% of total)
        //
        // This means: if VS Code + Intelephense + SSH are burning CPU but PHP-FPM is calm,
        // it's a dev session, not a web traffic emergency.
        $result['suppress_maintenance'] = (
            $result['dev_fraction'] >= 0.40
            && $result['web_cpu'] < ($total_cpu * 0.80)
        );

        // Cache result in WordPress transient for 30 seconds
        // This persists across requests, solving the instance-based cache limitation
        set_transient('rtsm_process_cache', $result, 30);
        return $result;
    }

    /**
     * Return a short human-readable label for a dev-tool process command string.
     */
    private function describe_dev_process($command, $category) {
        if (strpos($command, 'extensionHost') !== false)    return 'VS Code Extension Host';
        if (strpos($command, 'fileWatcher') !== false)      return 'VS Code File Watcher';
        if (strpos($command, 'server-main.js') !== false)   return 'VS Code Server';
        if (strpos($command, 'tsserver.js') !== false)      return 'TypeScript/Intelephense Server';
        if (strpos($command, 'typescript-language') !== false) return 'TypeScript Language Server';
        if (strpos($command, 'intelephense') !== false)     return 'PHP Intelephense';
        if (strpos($command, 'ptyHost') !== false)          return 'VS Code Terminal Host';
        if (strpos($command, 'bootstrap-fork') !== false)   return 'VS Code Extension Process';
        if (strpos($command, '.vscode-server') !== false)   return 'VS Code Server Process';
        if (preg_match('/sshd:.*@/', $command))             return 'SSH Session';
        if (strpos($command, 'rsync') !== false)            return 'rsync (file sync)';
        if (strpos($command, 'rclone') !== false)           return 'rclone (file sync)';
        if (strpos($command, 'git ') !== false)             return 'git operation';
        if (strpos($command, 'npm ') !== false)             return 'npm';
        if (strpos($command, 'composer ') !== false)        return 'Composer';
        if (strpos($command, 'mydumper') !== false)         return 'mydumper (DB backup)';
        if (strpos($command, 'mysqldump') !== false)        return 'mysqldump (DB backup)';
        if (strpos($command, 'myloader') !== false)         return 'myloader (DB restore)';
        if (strpos($command, 'xtrabackup') !== false ||
            strpos($command, 'innobackupex') !== false)     return 'Percona XtraBackup';
        if (strpos($command, 'borgbackup') !== false ||
            strpos($command, 'borg ') !== false)            return 'BorgBackup';
        if (strpos($command, 'restic') !== false)           return 'Restic backup';
        if (strpos($command, 'duplicati') !== false)        return 'Duplicati backup';
        return ucwords(str_replace('_', ' ', $category));
    }
    
    /**
     * Check if emergency load has been sustained long enough to warrant EMERGENCY activation.
     *
     * Uses transients (instead of instance variables) so the counter persists across
     * PHP-FPM worker processes — previously the instance-based counter reset on every
     * request, making the grace period completely ineffective in real traffic.
     *
     * EMERGENCY threshold fires after 2 checks within a 60-second window.
     */
    private function should_activate_on_emergency_load() {
        // If we already latched this incident window, don't re-trigger on every
        // subsequent request.  The latch expires after 5 minutes so a genuinely
        // new emergency (after the incident resolves) can fire again.
        if (get_transient('rtsm_emergency_mode_triggered')) {
            return false;
        }

        $current_time = time();
        $first_ts = get_transient('rtsm_emergency_load_first_ts');
        $checks   = (int) get_transient('rtsm_emergency_load_checks');

        if ( $first_ts === false ) {
            // First observation — start the clock; don't activate yet
            set_transient('rtsm_emergency_load_first_ts', $current_time, 5 * MINUTE_IN_SECONDS);
            set_transient('rtsm_emergency_load_checks', 1, 5 * MINUTE_IN_SECONDS);
            return false;
        }

        $checks++;
        set_transient('rtsm_emergency_load_checks', $checks, 5 * MINUTE_IN_SECONDS);
        $elapsed = $current_time - (int) $first_ts;

        // 2+ checks spanning at least 60 seconds = genuinely sustained emergency load
        if ($checks >= 2 && $elapsed >= 60) {
            // Latch: prevent every subsequent request from re-triggering this window
            set_transient('rtsm_emergency_mode_triggered', true, 5 * MINUTE_IN_SECONDS);
            return true;
        }
        return false;
    }

    /**
     * Check if this should activate emergency mode on critical load.
     *
     * Uses transients (instead of instance variables) so the counter persists across
     * PHP-FPM worker processes — previously the instance-based counter reset on every
     * request, making the grace period completely ineffective in real traffic.
     *
     * CRITICAL threshold fires after $this->minimum_activations_needed checks spanning
     * at least $this->grace_period_seconds (default: 3 checks over 120 s).
     *
     * CHANGED: The counter no longer resets when load briefly dips below critical.
     * Fluctuating load (5.2 → 4.8 → 5.1) is a classic attack signature; resetting
     * on every dip meant three qualifying checks never accumulated.
     * Instead, the counter naturally expires (10-min transient TTL) if load stays low.
     */
    private function should_activate_on_critical_load($load, $is_bot = false) {
        // Bot traffic + critical load is EXACTLY the scenario CF Under Attack Mode is for.
        // Previously this suppressed escalation when is_bot=true — that is backwards.
        // Now we ACCELERATE: bots get a shorter grace period (2 checks / 30 s).
        $min_checks  = $is_bot ? 2 : $this->minimum_activations_needed;
        $min_elapsed = $is_bot ? 30 : $this->grace_period_seconds;
        $bot_label   = $is_bot ? ' [BOT-ACCELERATED]' : '';
        
        $current_time = time();
        
        // Only hard-reset the counter when load drops well below the ELEVATED threshold
        // (i.e. normal load, not just a brief dip below critical).
        // Previous reset at (critical - 2) was too aggressive — a single low reading
        // during a fluctuating real event cleared all accumulated evidence.
        if ($load < ($this->load_threshold * 0.8)) {
            delete_transient('rtsm_critical_load_first_ts');
            delete_transient('rtsm_critical_load_checks');
            return false;
        }

        $first_ts = get_transient('rtsm_critical_load_first_ts');
        $checks   = (int) get_transient('rtsm_critical_load_checks');
        
        if ( $first_ts === false ) {
            // First observation — start the clock; don't activate yet
            set_transient('rtsm_critical_load_first_ts', $current_time, 10 * MINUTE_IN_SECONDS);
            set_transient('rtsm_critical_load_checks', 1, 10 * MINUTE_IN_SECONDS);
            return false;
        }

        $checks++;
        set_transient('rtsm_critical_load_checks', $checks, 10 * MINUTE_IN_SECONDS);
        $elapsed = $current_time - (int) $first_ts;
        
        return $checks >= $min_checks && $elapsed >= $min_elapsed;
    }
    
    /**
     * Detect if traffic is from bots/crawlers
     */
    private function is_bot_traffic($request_data) {
        $user_agent = strtolower($request_data['user_agent'] ?? '');
        $url = strtolower($request_data['url'] ?? '');
        $raw_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Known bot user agents
        $bot_patterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 'python', 'java(?!script)',
            'googlebot', 'bingbot', 'yandexbot', 'slurp', 'baidu', 'ahrefs', 'semrush',
            'majestic', 'pingdom', 'lighthouse', 'headless', 'phantomjs', 'selenium',
            // Commercial product scrapers identified from access log analysis (2026-02-26)
            'geedoshop', 'geedoshopproductfinder',
            // Aggressive SEO crawlers that ignore crawl-delay
            'barkrowler', 'babbar',
            // Other common scrapers
            'mj12bot', 'dotbot', 'rogerbot', 'seznambot', 'petalbot', 'zoominfobot',
        ];
        
        foreach ($bot_patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $user_agent)) {
                return true;
            }
        }
        
        // Fake/spoofed user agents: nobody legitimately runs IE 6/7/8 on Windows 95/NT 4.0/NT 5.0.
        // These are a reliable signal of automated scraper traffic.
        if (preg_match('/MSIE [678]\.\d.*Windows (9[58]|NT [45]\.)/i', $raw_ua)) {
            return true;
        }
        if (preg_match('/Windows (9[58]|NT [45]\.).*Trident/i', $raw_ua)) {
            return true;
        }
        
        // Feed crawling often causes load
        if (strpos($url, '/feed') !== false || strpos($url, 'rss') !== false) {
            return true;
        }
        
        // ?add-to-cart= on a paginated category/archive page is always bot behaviour.
        // Real shoppers add to cart from single product pages or via AJAX.
        if (isset($_GET['add-to-cart']) && preg_match('#/page/\d+/?#', $url)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get severity level based on load
     */
    private function get_severity_level($load) {
        if ($load >= $this->emergency_threshold) {
            return 'EMERGENCY';
        } elseif ($load >= $this->critical_threshold) {
            return 'CRITICAL';
        } elseif ($load >= $this->load_threshold) {
            return 'ELEVATED';
        } else {
            return 'NORMAL';
        }
    }
    
    /**
     * Get current request data for logging and external integrations
     */
    private function get_request_data() {
        return [
            'ip' => $this->get_client_ip(),
            'url' => self::redact_uri($_SERVER['REQUEST_URI'] ?? ''),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'referer' => self::redact_uri($_SERVER['HTTP_REFERER'] ?? '')
        ];
    }
    
    /**
     * Periodic check (runs every minute)
     * Also handles incident recovery detection
     * ENHANCED: Always logs snapshots for continuous graph data
     */
    public function periodic_check() {
        // NOTE: Do NOT throttle this at load 5.0 — the whole point is that this check
        // fires independently of incoming web requests (via WP-Cron or system cron).
        // When background processes drive high load with low web traffic, this is the
        // ONLY code path that can fire the CF escalation hook.
        
        $load = $this->collector->get_load_average();
        $load_1min = $load['1min'];
        
        // Check for incident recovery
        if ($this->is_incident_active() && $load_1min < 3.0) {
            $this->resolve_incident($load_1min);
        }
        
        // ALWAYS log snapshot for graph data (not just high load)
        $this->log_snapshot($load_1min);

        // Visitor IPs/user agents/URLs are personal data — enforce retention.
        $this->maybe_purge_expired_logs();

        // Refresh the attribution cache here — it is safe to run ps aux inside
        // WP-Cron (background context) without risk of blocking a web request.
        // Force a fresh sample by clearing the old cache first so check_and_log()
        // below always gets current data from the transient.
        delete_transient('rtsm_process_cache');
        $this->get_load_attribution();

        // Run the full mitigation pipeline via check_and_log(), which is also
        // registered on init. Use a short transient lock to prevent double-firing
        // when both the init handler and the cron path run in the same WP bootstrap.
        if (!get_transient('rtsm_check_log_ran')) {
            $this->check_and_log();
        }
    }
    
    /**
     * Log detailed request information
     */
    private function log_request($load) {
        global $wp_query;
        
        $data = [
            'timestamp' => current_time('Y-m-d H:i:s'),
            'load' => $load,
            'type' => 'REQUEST',
            'url' => self::redact_uri($_SERVER['REQUEST_URI'] ?? ''),
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 150),
            'ip' => $this->get_client_ip(),
            'referer' => self::redact_uri($_SERVER['HTTP_REFERER'] ?? ''),
            'query_string' => self::redact_query($_SERVER['QUERY_STRING'] ?? ''),
            'request_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'memory_used' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB',
            'php_processes' => $this->count_php_processes(),
            'is_admin' => is_admin() ? 'yes' : 'no',
            'is_ajax' => defined('DOING_AJAX') && DOING_AJAX ? 'yes' : 'no',
            'is_cron' => defined('DOING_CRON') && DOING_CRON ? 'yes' : 'no',
            'is_rest' => defined('REST_REQUEST') && REST_REQUEST ? 'yes' : 'no',
            'user_id' => get_current_user_id(),
        ];
        
        // Detect request type
        if ($data['is_cron'] === 'yes') {
            $data['request_type'] = 'CRON';
        } elseif ($data['is_ajax'] === 'yes') {
            $data['request_type'] = 'AJAX';
        } elseif ($data['is_rest'] === 'yes') {
            $data['request_type'] = 'REST_API';
        } elseif ($data['is_admin'] === 'yes') {
            $data['request_type'] = 'ADMIN';
        } elseif (strpos($data['url'], '/wp-json/') !== false) {
            $data['request_type'] = 'REST_API';
        } elseif (strpos($data['url'], 'wc-api') !== false) {
            $data['request_type'] = 'WC_API';
        } elseif (strpos($data['url'], '/feed') !== false || strpos($data['url'], '.xml') !== false) {
            $data['request_type'] = 'FEED';
        } else {
            $data['request_type'] = 'FRONTEND';
        }
        
        // Check for suspicious patterns
        $suspicious = [];
        if (strpos($data['user_agent'], 'bot') !== false || strpos($data['user_agent'], 'Bot') !== false) {
            $suspicious[] = 'BOT_USER_AGENT';
        }
        if (empty($data['referer']) && !$data['is_admin']) {
            $suspicious[] = 'NO_REFERER';
        }
        if ($data['request_time'] > 2.0) {
            $suspicious[] = 'SLOW_REQUEST';
        }
        if (strpos($data['url'], '..') !== false || strpos($data['url'], 'wp-config') !== false) {
            $suspicious[] = 'SUSPICIOUS_PATH';
        }
        
        $data['flags'] = implode(',', $suspicious);
        
        $this->write_log($data);
    }
    
    /**
     * Log system snapshot during high load
     */
    private function log_snapshot($load) {
        $cpu = $this->collector->get_cpu_usage();
        $memory = $this->collector->get_memory_info();
        $processes = $this->collector->get_processes('high-cpu', 10);
        
        $data = [
            'timestamp' => current_time('Y-m-d H:i:s'),
            'load' => $load,
            'type' => 'SNAPSHOT',
            'cpu_usage' => $cpu . '%',
            'memory_used' => $memory['used'] . 'MB / ' . $memory['total'] . 'MB (' . $memory['percent'] . '%)',
            'php_processes' => $this->count_php_processes(),
            'top_processes' => $this->format_top_processes($processes),
        ];
        
        $this->write_log($data);
    }
    
    /**
     * Write log entry
     */
    private function write_log($data) {
        // Safety check: ensure $data is an array
        if (!is_array($data)) {
            MMI_Logger::warn( 'write_log called with non-array data', [ 'data_type' => gettype( $data ) ], 'general', 'RTSM_Traffic_Logger' );
            return;
        }

        // Ensure required keys exist
        if (!isset($data['timestamp']) || !isset($data['load']) || !isset($data['type'])) {
            MMI_Logger::warn( 'write_log missing required keys in data array', [], 'general', 'RTSM_Traffic_Logger' );
            return;
        }
        
        $log_entry = '[' . $data['timestamp'] . '] ';
        $log_entry .= 'Load: ' . $data['load'] . ' | ';
        $log_entry .= 'Type: ' . $data['type'] . ' | ';
        
        unset($data['timestamp'], $data['type']);
        
        foreach ($data as $key => $value) {
            if (!empty($value) || $value === 0) {
                // Values include client-controlled strings (URL, user agent,
                // referer). Strip the field separator and line breaks so a
                // crafted header can't forge extra fields or log lines.
                $value = str_replace(['|', "\r", "\n"], ['/', ' ', ' '], (string) $value);
                $log_entry .= ucfirst(str_replace('_', ' ', $key)) . ': ' . $value . ' | ';
            }
        }
        
        $log_entry = rtrim($log_entry, ' | ') . "\n";
        
        // Write to log file
        @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Keep log file under 5MB (reduced from 10MB for better performance)
        if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) {
            $this->rotate_log();
        }
    }
    
    /**
     * Rotate log file
     */
    private function rotate_log() {
        if (file_exists($this->log_file)) {
            $backup = $this->log_file . '.' . date('Y-m-d-His');
            @rename($this->log_file, $backup);
            
            // Keep only last 3 backups (reduced from 5 to minimize disk usage)
            $backups = glob($this->log_file . '.*');
            if (count($backups) > 3) {
                usort($backups, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                foreach (array_slice($backups, 0, -3) as $old_backup) {
                    @unlink($old_backup);
                }
            }
        }
    }
    
    /**
     * Get client IP address.
     *
     * Forwarding headers (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) are
     * client-controlled and trivially spoofed, so they are only honoured when
     * the TCP peer (REMOTE_ADDR) is a trusted proxy: a published Cloudflare
     * range, or an address/CIDR added via the 'rtsm_trusted_proxies' filter
     * (e.g. a local reverse proxy). Otherwise REMOTE_ADDR is used as-is.
     * Every value is validated as an IP so log lines can't be injected.
     */
    private function get_client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }

        if (self::is_trusted_proxy($remote)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
                if (empty($_SERVER[$key])) {
                    continue;
                }
                // Left-most X-Forwarded-For entry is the original client.
                $candidate = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $remote;
    }

    /**
     * Whether $ip is a proxy whose forwarding headers may be trusted.
     *
     * @param string $ip Validated IP address.
     * @return bool
     */
    private static function is_trusted_proxy($ip) {
        // Cloudflare published ranges: https://www.cloudflare.com/ips/
        $ranges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];
        $ranges = (array) apply_filters('rtsm_trusted_proxies', $ranges);

        foreach ($ranges as $range) {
            if (self::ip_in_cidr($ip, (string) $range)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4/IPv6 CIDR (or single address) membership test.
     *
     * @param string $ip
     * @param string $cidr e.g. '10.0.0.0/8', '2606:4700::/32', '127.0.0.1'
     * @return bool
     */
    private static function ip_in_cidr($ip, $cidr) {
        $parts  = explode('/', trim($cidr), 2);
        $subnet = @inet_pton($parts[0]);
        $addr   = @inet_pton($ip);
        if ($subnet === false || $addr === false || strlen($subnet) !== strlen($addr)) {
            return false;
        }
        $max  = strlen($addr) * 8;
        $bits = isset($parts[1]) ? (int) $parts[1] : $max;
        if ($bits < 0 || $bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($addr, 0, $bytes) !== substr($subnet, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($addr[$bytes] & $mask) === ($subnet[$bytes] & $mask));
    }

    /**
     * Query-string parameter names whose values are never written to logs
     * (password-reset keys, tokens, nonces, emails, ...). Filterable.
     *
     * @return string[]
     */
    private static function sensitive_query_keys() {
        return (array) apply_filters('rtsm_sensitive_query_keys', [
            'key', 'token', 'access_token', 'auth', 'code', 'nonce', '_wpnonce',
            'pass', 'pwd', 'password', 'secret', 'api_key', 'apikey', 'email',
            'login', 'user_login', 'session', 'sig', 'signature', 'order_key',
        ]);
    }

    /**
     * Replace the values of sensitive parameters in a raw query string.
     *
     * @param string $query
     * @return string
     */
    private static function redact_query($query) {
        $query = (string) $query;
        if ($query === '') {
            return '';
        }
        $sensitive = array_map('strtolower', self::sensitive_query_keys());
        $pairs     = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            $name = preg_replace('/\[.*$/', '', $name);
            if (in_array($name, $sensitive, true)) {
                $pairs[$i] = explode('=', $pair, 2)[0] . '=REDACTED';
            }
        }
        return implode('&', $pairs);
    }

    /**
     * Redact sensitive query values from a URI/URL, keeping the path.
     *
     * @param string $uri
     * @return string
     */
    public static function redact_uri($uri) {
        $uri = (string) $uri;
        $pos = strpos($uri, '?');
        if ($pos === false) {
            return $uri;
        }
        return substr($uri, 0, $pos + 1) . self::redact_query(substr($uri, $pos + 1));
    }

    /**
     * Retention: drop traffic-log lines and rotated backups older than
     * 'rtsm_log_retention_days' (default 30). Runs at most once a day from
     * the periodic cron check.
     */
    private function maybe_purge_expired_logs() {
        if (get_transient('rtsm_log_purge_ran')) {
            return;
        }
        set_transient('rtsm_log_purge_ran', 1, DAY_IN_SECONDS);

        $days = (int) apply_filters('rtsm_log_retention_days', 30);
        if ($days <= 0) {
            return;
        }
        $cutoff_ts = time() - ($days * DAY_IN_SECONDS);

        // Rotated backups: delete whole files past the cutoff.
        $backups = glob($this->log_file . '.*');
        foreach ((array) $backups as $backup) {
            if (is_file($backup) && filemtime($backup) < $cutoff_ts) {
                @unlink($backup);
            }
        }

        // Live log: keep only lines stamped on/after the cutoff. Lines are
        // written with current_time() (site timezone), so compare in the same.
        if (!is_file($this->log_file)) {
            return;
        }
        $cutoff = wp_date('Y-m-d H:i:s', $cutoff_ts);
        $lines  = @file($this->log_file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m) && $m[1] < $cutoff) {
                continue;
            }
            $kept[] = $line;
        }
        if (count($kept) !== count($lines)) {
            @file_put_contents($this->log_file, $kept ? implode("\n", $kept) . "\n" : '', LOCK_EX);
        }
    }
    
    /**
     * Count PHP-FPM processes
     */
    private function count_php_processes() {
        if (function_exists('shell_exec')) {
            $count = @shell_exec("ps aux | grep -c 'php-fpm: pool' 2>/dev/null");
            return $count ? intval($count) - 1 : 0; // Subtract grep itself
        }
        return 0;
    }
    
    /**
     * Format top processes for logging
     */
    private function format_top_processes($processes) {
        if (empty($processes)) {
            return 'none';
        }
        
        $formatted = [];
        foreach (array_slice($processes, 0, 5) as $proc) {
            $formatted[] = sprintf(
                '%s(CPU:%.1f%%,MEM:%.1f%%)',
                substr($proc['command'], 0, 20),
                $proc['cpu'],
                $proc['mem']
            );
        }
        
        return implode('; ', $formatted);
    }
    
    /**
     * Get analysis summary
     */
    /**
     * @param string|null $since Site-local 'Y-m-d H:i:s'. Only log lines stamped at or after it
     *                           are counted; null counts the whole log.
     */
    public static function get_analysis_summary( ?string $since = null ) {
        $log_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-traffic-analysis.log';

        if (!file_exists($log_file)) {
            return [
                'total_incidents' => 0,
                'message' => 'No high-load incidents recorded yet.'
            ];
        }

        // Cache key incorporates the file's last-modified time so a new incident
        // automatically busts the cache without waiting for TTL expiry.
        $mtime     = (int) filemtime( $log_file );
        $cache_key = 'rtsm_analysis_summary_' . $mtime . ( $since ? '_' . md5( substr( $since, 0, 16 ) ) : '' );

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $log_content = file_get_contents($log_file);
        $lines = explode("\n", trim($log_content));
        
        $stats = [
            'total_incidents' => 0,
            'by_type' => [],
            'by_request_type' => [],
            'top_urls' => [],
            'top_ips' => [],
            'suspicious_flags' => [],
            'max_load' => 0,
            'avg_load' => 0,
            'peak_times' => [],
        ];
        
        $total_load = 0;
        
        foreach ($lines as $line) {
            if (empty($line)) continue;

            if ( $since !== null && preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $ts ) && $ts[1] < $since ) {
                continue;
            }

            $stats['total_incidents']++;
            
            // Parse load
            if (preg_match('/Load: ([\d.]+)/', $line, $match)) {
                $load = floatval($match[1]);
                $total_load += $load;
                $stats['max_load'] = max($stats['max_load'], $load);
            }
            
            // Parse type
            if (preg_match('/Type: (\w+)/', $line, $match)) {
                $type = $match[1];
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            }
            
            // Parse request type
            if (preg_match('/Request type: (\w+)/', $line, $match)) {
                $req_type = $match[1];
                $stats['by_request_type'][$req_type] = ($stats['by_request_type'][$req_type] ?? 0) + 1;
            }
            
            // Parse URL
            if (preg_match('/Url: ([^\|]+)/', $line, $match)) {
                $url = trim($match[1]);
                if (!empty($url)) {
                    $stats['top_urls'][$url] = ($stats['top_urls'][$url] ?? 0) + 1;
                }
            }
            
            // Parse IP
            if (preg_match('/Ip: ([^\|]+)/', $line, $match)) {
                $ip = trim($match[1]);
                if (!empty($ip) && $ip !== 'unknown') {
                    $stats['top_ips'][$ip] = ($stats['top_ips'][$ip] ?? 0) + 1;
                }
            }
            
            // Parse flags
            if (preg_match('/Flags: ([^\|]+)/', $line, $match)) {
                $flags = trim($match[1]);
                if (!empty($flags)) {
                    foreach (explode(',', $flags) as $flag) {
                        $stats['suspicious_flags'][$flag] = ($stats['suspicious_flags'][$flag] ?? 0) + 1;
                    }
                }
            }
            
            // Parse timestamp for peak times
            if (preg_match('/\[([\d-]+ (\d+):)/', $line, $match)) {
                $hour = intval($match[2]);
                $stats['peak_times'][$hour] = ($stats['peak_times'][$hour] ?? 0) + 1;
            }
        }
        
        if ($stats['total_incidents'] > 0) {
            $stats['avg_load'] = round($total_load / $stats['total_incidents'], 2);
        }
        
        // Sort arrays
        arsort($stats['top_urls']);
        arsort($stats['top_ips']);
        arsort($stats['suspicious_flags']);
        arsort($stats['peak_times']);
        
        // Limit arrays
        $stats['top_urls'] = array_slice($stats['top_urls'], 0, 10, true);
        $stats['top_ips'] = array_slice($stats['top_ips'], 0, 10, true);

        // Cache until the log file changes (max 5 minutes as safety net)
        set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

        return $stats;
    }
    
    /**
     * AUTO-REMEDIATION: Activate emergency maintenance mode
     *
     * IMPROVED: Now includes admin access whitelist
     * Allows admins to access wp-admin even during maintenance
     *
     * Uses WordPress native .maintenance file to safely put the site in
     * maintenance mode. This approach NEVER modifies wp-config.php.
     *
     * Escalation chain:
     *   1. Creates ABSPATH/.maintenance  (WordPress shows built-in maintenance page)
     *   2. Fires rtsm_emergency_mode_activated hook (for any listener; none in
     *      this suite since mmi-cloudflare-integration was retired 2026-10-04)
     *
     * NOTE: .maintenance creation is gated on the 'rtsm_auto_maintenance' setting
     * (default OFF). Incident logging and hook firing always occur regardless,
     * so Cloudflare escalation still works without taking the site offline.
     */
    /**
     * @param float  $load
     * @param string $severity
     * @param bool   $skip_maintenance_file  When true (dev-tool attribution), skip .maintenance
     *                                        but still fire the CF escalation hook and log the
     *                                        incident — real visitors are still impacted.
     */
    private function activate_emergency_mode($load, $severity, $skip_maintenance_file = false) {
        $auto_maintenance = (bool) RTSM_Settings_Manager::get_instance()->get('rtsm_auto_maintenance', 0);

        // Always record the incident and fire the CF hook regardless of maintenance suppression.
        $this->create_incident_flag($load, $severity);
        $this->generate_alert($load, $severity);
        $this->log_incident_start($load, $severity);

        // Skip .maintenance file when auto_maintenance is off OR dev tools are the source.
        if ( ! $auto_maintenance || $skip_maintenance_file ) {
            // Fire hook for any listener (e.g. a CDN escalation)
            do_action('rtsm_emergency_mode_activated', $load, $severity);
            return;
        }

        $maintenance_file = ABSPATH . '.maintenance';

        // Check if already in maintenance mode
        if (file_exists($maintenance_file)) {
            $this->log_request($load);
            do_action('rtsm_emergency_mode_activated', $load, $severity);
            return;
        }

        // Activate WordPress native maintenance mode (safe, atomic, reversible)
        // IMPORTANT: Admin access is handled by drop-in plugin or theme filter
        // See: emergency-maintenance.php or wp-content/maintenance.php
        $maintenance_content = '<?php $upgrading = ' . time() . '; // RTSM auto-activated: '
            . $severity . ' at ' . date('Y-m-d H:i:s')
            . ' (load: ' . number_format($load, 2) . ') | Admins can access /wp-admin/ | Check Server Monitor plugin';
        @file_put_contents($maintenance_file, $maintenance_content);

        // Log activation
        $alert_msg = sprintf(
            '🚨 AUTO-REMEDIATION ACTIVATED - Load: %.2f | Severity: %s | .maintenance mode ENABLED',
            $load,
            $severity
        );
        MMI_Logger::error( $alert_msg, [ 'load' => $load, 'severity' => $severity ], 'server-alerts', 'MMI_Traffic_Logger' );

        // Fire hook for any listener (e.g. a CDN escalation)
        do_action('rtsm_emergency_mode_activated', $load, $severity);
    }
    
    /**
     * Generate alert for elevated/critical load
     *
     * @param float  $load       1-min load average
     * @param string $severity   NORMAL|ELEVATED|CRITICAL|EMERGENCY
     * @param array  $attribution Optional result from get_load_attribution()
     */
    private function generate_alert($load, $severity, array $attribution = []) {
        $php_processes = $this->count_php_processes();
        $memory = $this->collector->get_memory_info();

        $alert = sprintf(
            '⚠️ %s ALERT - Load: %.2f | PHP Processes: %d | Memory: %s%%',
            $severity,
            $load,
            $php_processes,
            $memory['percent']
        );

        $context = [
            'load'          => $load,
            'severity'      => $severity,
            'php_processes' => $php_processes,
            'memory_pct'    => $memory['percent'],
        ];

        // Append attribution summary when dev tools are a factor
        if (!empty($attribution) && $attribution['dev_cpu'] > 0) {
            $alert .= sprintf(
                ' | Dev Tools CPU: %.1f%% | Web CPU: %.1f%%',
                $attribution['dev_cpu'],
                $attribution['web_cpu']
            );
            if (!empty($attribution['top_dev_processes'])) {
                $alert .= ' | Cause: ' . implode(', ', array_slice($attribution['top_dev_processes'], 0, 3));
            }
            if (!empty($attribution['suppress_maintenance'])) {
                $alert .= ' | [MAINTENANCE SUPPRESSED - dev tool load]';
            }
            $context['dev_cpu'] = $attribution['dev_cpu'];
            $context['web_cpu'] = $attribution['web_cpu'];
        }

        // Add request details if available
        if (isset($_SERVER['REQUEST_URI'])) {
            $context['url'] = self::redact_uri($_SERVER['REQUEST_URI']);
            $context['ip'] = $this->get_client_ip();
            $context['user_agent'] = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 150);
        }

        MMI_Logger::warn( $alert, $context, 'server-alerts', 'MMI_Traffic_Logger' );
    }
    
    /**
     * Create incident flag file for tracking active incidents
     */
    private function create_incident_flag($load, $severity) {
        // Pull cross-plugin cause analysis so the incident record is self-contained.
        $causes = class_exists('RTSM_Server_Monitor')
            ? RTSM_Server_Monitor::get_load_cause_analysis()
            : [];

        $incident_data = [
            'started_at'     => date('Y-m-d H:i:s'),
            'timestamp'      => time(),
            'load'           => $load,
            'severity'       => $severity,
            'php_processes'  => $this->count_php_processes(),
            'memory_percent' => $this->collector->get_memory_info()['percent'],
            'request_uri'    => self::redact_uri($_SERVER['REQUEST_URI'] ?? 'N/A'),
            'client_ip'      => $this->get_client_ip(),
            'user_agent'     => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 150),
            'causes'         => $causes,
        ];
        
        file_put_contents($this->incident_file, json_encode($incident_data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Check if an incident is currently active
     */
    private function is_incident_active() {
        return file_exists($this->incident_file);
    }
    
    /**
     * Resolve incident when load returns to normal
     */
    private function resolve_incident($current_load) {
        if (!file_exists($this->incident_file)) {
            return;
        }
        
        $incident_data = json_decode(file_get_contents($this->incident_file), true);
        $duration = time() - $incident_data['timestamp'];
        
        // Log resolution
        $resolution = sprintf(
            '✅ INCIDENT RESOLVED - Duration: %d minutes | Peak Load: %.2f | Current Load: %.2f',
            round($duration / 60),
            $incident_data['load'],
            $current_load
        );

        MMI_Logger::info( $resolution, [
            'duration_minutes' => round( $duration / 60 ),
            'peak_load'        => $incident_data['load'],
            'current_load'     => $current_load,
        ], 'server-alerts', 'MMI_Traffic_Logger' );
        
        // Archive the incident resolution (permanent record)
        $this->archive_incident_resolution($incident_data, $current_load, $duration);
        
        // Remove incident flag
        unlink($this->incident_file);

        // Deactivate WordPress maintenance mode that was set by activate_emergency_mode()
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            @unlink($maintenance_file);
        }

        // Clear grace period transients so remediation can fire again on a fresh incident
        delete_transient('rtsm_emergency_load_first_ts');
        delete_transient('rtsm_emergency_load_checks');
        delete_transient('rtsm_critical_load_first_ts');
        delete_transient('rtsm_critical_load_checks');

        // Fire hook for any listener (e.g. to undo a CDN escalation)
        do_action('rtsm_emergency_mode_deactivated', $current_load, $incident_data);

        // Log to main traffic log using correct array format
        $this->write_log([
            'timestamp' => date('Y-m-d H:i:s'),
            'load'      => $current_load,
            'type'      => 'INCIDENT_RESOLVED',
            'duration'  => round($duration / 60) . ' min',
            'peak_load' => $incident_data['load'],
        ]);
    }
    
    /**
     * Log comprehensive incident start data
     */
    private function log_incident_start($load, $severity) {
        // Get top processes for logging
        $processes = $this->collector->get_processes('high-cpu', 10);

        // Previously appended a free-form block to wp-content/uploads/
        // server-traffic-analysis.log — a public, guessable URL holding
        // visitor IPs and user agents. Now a structured line in the same
        // (non-public, retention-managed) traffic log as everything else.
        $this->write_log([
            'timestamp'     => current_time('Y-m-d H:i:s'),
            'load'          => round((float) $load, 2),
            'type'          => 'INCIDENT_START',
            'severity'      => $severity,
            'php_processes' => $this->count_php_processes(),
            'memory'        => $this->collector->get_memory_info()['percent'] . '%',
            'url'           => self::redact_uri($_SERVER['REQUEST_URI'] ?? 'N/A'),
            'ip'            => $this->get_client_ip(),
            'user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 150),
            'top_processes' => $this->format_top_processes($processes),
        ]);
    }
    
    /**
     * Archive a high-value load event to permanent storage
     * 
     * SELECTIVE: Only archives CRITICAL and EMERGENCY events, not regular/elevated load.
     * This prevents duplication while preserving crisis-level events for future analysis.
     * 
     * @param float $load Server load
     * @param string $severity Severity level
     */
    private function archive_load_event($load, $severity) {
        if (!$this->archiver) {
            return;
        }
        
        $memory = $this->collector->get_memory_info();
        $processes = $this->collector->get_processes('high-cpu', 10);
        
        $event_data = [
            'server_load' => sprintf('%.2f', $load),
            'php_processes' => $this->count_php_processes(),
            'memory_used' => $memory['used'] . 'MB',
            'memory_total' => $memory['total'] . 'MB',
            'memory_percent' => $memory['percent'] . '%',
            'cpu_usage' => $this->collector->get_cpu_usage() . '%',
            'request_uri' => self::redact_uri($_SERVER['REQUEST_URI'] ?? 'N/A'),
            'client_ip' => $this->get_client_ip(),
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 100),
            'top_processes' => $this->format_top_processes($processes)
        ];
        
        $this->archiver->archive_event('SERVER_LOAD_' . $severity, $severity, $event_data);
    }
    
    /**
     * Archive an incident resolution event
     * 
     * @param array $incident_data Original incident data
     * @param float $current_load Current load
     * @param int $duration Duration in seconds
     */
    private function archive_incident_resolution($incident_data, $current_load, $duration) {
        if (!$this->archiver) {
            return;
        }
        
        $event_data = [
            'incident_started' => $incident_data['started_at'] ?? 'Unknown',
            'duration_minutes' => round($duration / 60),
            'peak_load' => sprintf('%.2f', $incident_data['load'] ?? 0),
            'final_load' => sprintf('%.2f', $current_load),
            'severity' => $incident_data['severity'] ?? 'Unknown',
            'initial_php_processes' => $incident_data['php_processes'] ?? 'N/A',
            'initial_memory' => $incident_data['memory_percent'] ?? 'N/A',
            'triggering_request' => $incident_data['request_uri'] ?? 'N/A',
            'triggering_ip' => $incident_data['client_ip'] ?? 'N/A'
        ];
        
        $this->archiver->archive_event('INCIDENT_RESOLVED', 'INCIDENT', $event_data);
    }
}

// Add custom cron schedule for every minute
add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display' => __('Every Minute')
    ];
    return $schedules;
});

// Initialize traffic logger
add_action('plugins_loaded', function() {
    if (class_exists('RTSM_Stats_Collector')) {
        RTSM_Traffic_Logger::instance();
    }
}, 20);

