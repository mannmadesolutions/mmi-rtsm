<?php
/**
 * Stats Collector
 * 
 * Collects server statistics using multiple methods based on platform capabilities
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Stats_Collector {

    /** @var self|null Singleton instance for get_instance() callers */
    private static $instance = null;

    /**
     * Cache TTL in seconds for transient-based metric caching.
     * Short-lived so stats remain near-realtime.
     */
    const CACHE_TTL = 10;

    private $detector;

    /**
     * Constructor – kept public for backward compatibility with `new RTSM_Stats_Collector()`.
     * Prefer RTSM_Stats_Collector::get_instance() for new code.
     */
    public function __construct() {
        $this->detector = RTSM_Platform_Detector::get_instance();
    }

    /**
     * Singleton accessor – reuse a single instance across the current request.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get load average using the best available method
     * NOW DELEGATES TO MMI_Unified_Metrics for single source of truth
     */
    public function get_load_average() {
        $cached = get_transient( 'rtsm_stat_load' );
        if ( $cached !== false ) {
            return $cached;
        }

        // Use unified metrics if available (Phase 1 implementation)
        if (class_exists('MMI_Unified_Metrics')) {
            try {
                $metrics = MMI_Unified_Metrics::instance()->get_metrics();
                if (isset($metrics['load']) && is_array($metrics['load'])) {
                    set_transient( 'rtsm_stat_load', $metrics['load'], self::CACHE_TTL );
                    return $metrics['load'];
                }
            } catch (Exception $e) {
                MMI_Logger::warn( 'MMI_Unified_Metrics failed, using fallback', [ 'error' => $e->getMessage() ], 'general', 'RTSM_Stats_Collector' );
                // Fall through to legacy methods
            }
        }
        
        // Legacy fallback for backwards compatibility
        // Method 1: sys_getloadavg (most reliable)
        if ($this->detector->has_capability('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && count($load) >= 3) {
                $result = [
                    '1min' => round($load[0], 2),
                    '5min' => round($load[1], 2),
                    '15min' => round($load[2], 2)
                ];
                set_transient( 'rtsm_stat_load', $result, self::CACHE_TTL );
                return $result;
            }
        }
        
        // Method 2: Read /proc/loadavg (Linux)
        if ($this->detector->has_capability('proc_files')) {
            $loadavg = @file_get_contents('/proc/loadavg');
            if ($loadavg) {
                $load = explode(' ', $loadavg);
                if (count($load) >= 3) {
                    $result = [
                        '1min' => round(floatval($load[0]), 2),
                        '5min' => round(floatval($load[1]), 2),
                        '15min' => round(floatval($load[2]), 2)
                    ];
                    set_transient( 'rtsm_stat_load', $result, self::CACHE_TTL );
                    return $result;
                }
            }
        }
        
        // Method 3: shell_exec uptime
        if ($this->detector->has_capability('shell_exec')) {
            $uptime = @shell_exec('uptime');
            if ($uptime && preg_match('/load average: ([0-9.]+),?\s+([0-9.]+),?\s+([0-9.]+)/', $uptime, $matches)) {
                $result = [
                    '1min' => round(floatval($matches[1]), 2),
                    '5min' => round(floatval($matches[2]), 2),
                    '15min' => round(floatval($matches[3]), 2)
                ];
                set_transient( 'rtsm_stat_load', $result, self::CACHE_TTL );
                return $result;
            }
        }
        
        // Fallback: Return zeros if no method available
        return ['1min' => 0, '5min' => 0, '15min' => 0];
    }
    
    /**
     * Get CPU usage percentage
     * NOW DELEGATES TO MMI_Unified_Metrics for single source of truth
     */
    public function get_cpu_usage() {
        $cached = get_transient( 'rtsm_stat_cpu' );
        if ( $cached !== false ) {
            return $cached;
        }

        // Use unified metrics if available (Phase 1 implementation)
        if (class_exists('MMI_Unified_Metrics')) {
            try {
                $metrics = MMI_Unified_Metrics::instance()->get_metrics();
                if (isset($metrics['cpu']['percent'])) {
                    set_transient( 'rtsm_stat_cpu', $metrics['cpu']['percent'], self::CACHE_TTL );
                    return $metrics['cpu']['percent'];
                }
            } catch (Exception $e) {
                MMI_Logger::warn( 'MMI_Unified_Metrics CPU failed, using fallback', [ 'error' => $e->getMessage() ], 'general', 'RTSM_Stats_Collector' );
                // Fall through to legacy methods
            }
        }
        
        // Legacy fallback for backwards compatibility
        // Method 1: Read /proc/stat (Linux) – use transient to persist prev_stats across requests
        if ($this->detector->has_capability('proc_files') && is_readable('/proc/stat')) {
            $stats = @file_get_contents('/proc/stat');
            if ($stats && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/m', $stats, $matches)) {
                $current = [
                    'user' => $matches[1],
                    'nice' => $matches[2],
                    'system' => $matches[3],
                    'idle' => $matches[4]
                ];

                $prev_stats = get_transient( 'rtsm_cpu_prev_stat' );
                // Store this reading for the next call
                set_transient( 'rtsm_cpu_prev_stat', $current, 60 );

                if ( $prev_stats ) {
                    $diff_idle  = $current['idle'] - $prev_stats['idle'];
                    $diff_total = ( $current['user'] + $current['nice'] + $current['system'] + $current['idle'] )
                                - ( $prev_stats['user'] + $prev_stats['nice'] + $prev_stats['system'] + $prev_stats['idle'] );
                    
                    if ($diff_total > 0) {
                        $result = round( 100 * (1 - $diff_idle / $diff_total), 1 );
                        set_transient( 'rtsm_stat_cpu', $result, self::CACHE_TTL );
                        return $result;
                    }
                }
            }
        }
        
        // Method 2: shell_exec top
        if ($this->detector->has_capability('shell_exec')) {
            $cpu = @shell_exec("top -bn1 | grep 'Cpu(s)' | sed 's/.*, *\\([0-9.]*\\)%* id.*/\\1/' | awk '{print $1}'");
            if ($cpu !== null && $cpu !== false && $cpu !== '') {
                $result = round(100 - floatval($cpu), 1);
                set_transient( 'rtsm_stat_cpu', $result, self::CACHE_TTL );
                return $result;
            }
        }
        
        // Fallback: Use load average as approximation
        $load = $this->get_load_average();
        return round($load['1min'] * 25, 1); // Rough approximation
    }
    
    /**
     * Get memory information
     * NOW DELEGATES TO MMI_Unified_Metrics for single source of truth
     */
    public function get_memory_info() {
        $cached = get_transient( 'rtsm_stat_memory' );
        if ( $cached !== false ) {
            return $cached;
        }

        // Use unified metrics if available (Phase 1 implementation)
        if (class_exists('MMI_Unified_Metrics')) {
            try {
                $metrics = MMI_Unified_Metrics::instance()->get_metrics();
                if (isset($metrics['memory']) && is_array($metrics['memory'])) {
                    // Convert bytes to MB for backwards compatibility
                    $result = [
                        'total' => round($metrics['memory']['total'] / 1024 / 1024, 0),
                        'used' => round($metrics['memory']['used'] / 1024 / 1024, 0),
                        'percent' => $metrics['memory']['percent']
                    ];
                    set_transient( 'rtsm_stat_memory', $result, self::CACHE_TTL );
                    return $result;
                }
            } catch (Exception $e) {
                MMI_Logger::warn( 'MMI_Unified_Metrics memory failed, using fallback', [ 'error' => $e->getMessage() ], 'general', 'RTSM_Stats_Collector' );
                // Fall through to legacy methods
            }
        }
        
        // Legacy fallback for backwards compatibility
        // Method 1: Read /proc/meminfo (Linux)
        if ($this->detector->has_capability('proc_files') && is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available);
                
                if (isset($total[1])) {
                    $total_mb = round($total[1] / 1024, 0);
                    $available_mb = isset($available[1]) ? round($available[1] / 1024, 0) : 0;
                    $used_mb = $total_mb - $available_mb;
                    
                    $result = [
                        'total' => $total_mb,
                        'used' => $used_mb,
                        'percent' => round(($used_mb / $total_mb) * 100, 1)
                    ];
                    set_transient( 'rtsm_stat_memory', $result, self::CACHE_TTL );
                    return $result;
                }
            }
        }
        
        // Method 2: shell_exec free
        if ($this->detector->has_capability('shell_exec')) {
            $mem = @shell_exec("free -m | awk 'NR==2{print $2,$3}'");
            if ($mem) {
                $parts = explode(' ', trim($mem));
                if (count($parts) >= 2) {
                    $total = intval($parts[0]);
                    $used = intval($parts[1]);
                    $result = [
                        'total' => $total,
                        'used' => $used,
                        'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
                    ];
                    set_transient( 'rtsm_stat_memory', $result, self::CACHE_TTL );
                    return $result;
                }
            }
        }
        
        // Method 3: PHP memory_get_usage (less accurate but universal)
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit) {
            $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
            $current_usage = memory_get_usage(true);
            
            return [
                'total' => round($memory_limit_bytes / 1024 / 1024, 0),
                'used' => round($current_usage / 1024 / 1024, 0),
                'percent' => round(($current_usage / $memory_limit_bytes) * 100, 1)
            ];
        }
        
        return ['total' => 0, 'used' => 0, 'percent' => 0];
    }
    
    /**
     * Get process list
     */
    public function get_processes($filter = 'all', $limit = 20) {
        $cache_key = 'rtsm_stat_processes_' . $filter . '_' . $limit;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $processes = [];

        // Method 1: shell_exec ps (Unix/Linux)
        if ($this->detector->has_capability('shell_exec') && !$this->detector->has_capability('windows')) {
            $grep = '';
            switch ($filter) {
                case 'php':
                    $grep = " | grep -E 'php-fpm|php'";
                    break;
                case 'python':
                    $grep = " | grep python";
                    break;
                case 'node':
                    $grep = " | grep node";
                    break;
                case 'mysql':
                    $grep = " | grep -E 'mysql|mariadb'";
                    break;
            }

            // -eo with an explicit etime column avoids a per-process shell_exec
            // (previously: one extra "ps -p $pid -o etime" call per row, every poll).
            $output = @shell_exec("ps -eo user:20,pid,pcpu,pmem,vsz,rss,etime,args --no-headers --sort=-pcpu | head -" . $limit . $grep);

            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', trim($line), 8);
                    if (count($parts) >= 8) {
                        $cpu = floatval($parts[2]);
                        $mem = floatval($parts[3]);

                        if ($filter === 'high-cpu' && $cpu < 10.0) {
                            continue;
                        }

                        if ($filter === 'all' && $cpu < 0.5 && $mem < 0.5) {
                            continue;
                        }

                        $processes[] = [
                            'pid' => $parts[1],
                            'user' => substr($parts[0], 0, 12),
                            'cpu' => $cpu,
                            'mem' => $mem,
                            'vsz' => $parts[4],
                            'rss' => $parts[5],
                            'elapsed' => $parts[6],
                            'command' => substr($parts[7], 0, 60),
                            'killable' => $this->is_killable_process($parts[0], $parts[7])
                        ];
                    }
                }
            }
        }

        // Method 2: Windows tasklist
        if ($this->detector->has_capability('windows') && $this->detector->has_capability('exec')) {
            @exec('tasklist /FO CSV /NH', $output);
            foreach ($output as $line) {
                $parts = str_getcsv($line);
                if (count($parts) >= 5) {
                    $processes[] = [
                        'pid' => $parts[1],
                        'user' => 'N/A',
                        'cpu' => 0,
                        'mem' => round(intval(str_replace(',', '', $parts[4])) / 1024, 1),
                        'elapsed' => 'N/A',
                        'command' => substr($parts[0], 0, 60),
                        'killable' => false
                    ];
                }
            }
        }

        $result = array_slice($processes, 0, $limit);
        set_transient($cache_key, $result, self::CACHE_TTL);
        return $result;
    }
    
    /**
     * Kill a process
     */
    public function kill_process($pid, $force = false) {
        if (!$this->detector->has_capability('shell_exec')) {
            return ['success' => false, 'message' => 'Process killing not available on this platform'];
        }
        
        $pid = intval($pid);
        if ($pid <= 0) {
            return ['success' => false, 'message' => 'Invalid PID'];
        }
        
        // Verify process exists
        $process_info = @shell_exec("ps -p $pid -o user,comm --no-headers 2>/dev/null");
        if (!$process_info) {
            return ['success' => false, 'message' => 'Process not found'];
        }
        
        $info_parts = preg_split('/\s+/', trim($process_info), 2);
        if (!$this->is_killable_process($info_parts[0], $info_parts[1])) {
            return ['success' => false, 'message' => 'Cannot kill system or critical processes'];
        }
        
        // Kill the process
        $signal = $force ? '-9' : '-15';
        @shell_exec("kill $signal $pid 2>&1");
        
        usleep(500000);
        $still_running = @shell_exec("ps -p $pid --no-headers 2>/dev/null");
        
        if ($still_running) {
            return ['success' => false, 'message' => 'Process still running'];
        }
        
        return ['success' => true, 'message' => "Process $pid killed successfully"];
    }
    
    /**
     * Check if process is safe to kill
     */
    private function is_killable_process($user, $command) {
        $system_users = ['root', 'mysql', 'redis', 'www-data', 'SYSTEM', 'postgres'];
        if (in_array($user, $system_users)) {
            return false;
        }
        
        $critical_keywords = ['systemd', 'sshd', 'init', 'kernel', 'System'];
        foreach ($critical_keywords as $keyword) {
            if (stripos($command, $keyword) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get active connections
     */
    public function get_connections() {
        $cached = get_transient( 'rtsm_stat_connections' );
        if ( $cached !== false ) {
            return $cached;
        }

        if ($this->detector->has_capability('shell_exec')) {
            // Get detailed connection information with IPs.
            // ss is 10-100x faster than netstat under high connection counts and
            // handles D-state process contention far better under server load.
            $connections_output = @shell_exec("timeout 5 ss -tn state established 'sport = :80' 2>/dev/null | tail -n +2 | awk '{print \$5}' | cut -d: -f1 | sort | uniq -c | sort -rn | head -20");
            
            $connection_details = [];
            $real_count = 0;
            
            if ($connections_output) {
                $lines = array_filter(explode("\n", trim($connections_output)));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
                        $ip = trim($matches[2]);
                        $count = intval($matches[1]);
                        
                        // Filter out internal/local IPs and Cloudflare proxies
                        if ($this->should_include_ip($ip)) {
                            $connection_details[] = [
                                'ip' => $ip,
                                'count' => $count
                            ];
                            $real_count += $count;
                        }
                    }
                }
            }
            
            $result = [
                'count' => $real_count,
                'details' => $connection_details
            ];
            set_transient( 'rtsm_stat_connections', $result, self::CACHE_TTL );
            return $result;
        }
        
        return [
            'count' => 0,
            'details' => []
        ];
    }

    /**
     * Get disk usage for the WordPress root partition.
     * Uses PHP's native disk_free_space() / disk_total_space() — no shell_exec required.
     * Cached for 60 s (disk usage changes slowly; no need to hit the OS every 10 s).
     *
     * @return array{total_gb: float, used_gb: float, free_gb: float, percent: float}
     */
    public function get_disk_info(): array {
        $cached = get_transient('rtsm_stat_disk');
        if ($cached !== false) {
            return $cached;
        }

        $path  = defined('ABSPATH') ? ABSPATH : '/';
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);

        if ($total && $free && $total > 0) {
            $used    = $total - $free;
            $percent = round(($used / $total) * 100, 2);
            $result  = [
                'total_gb' => round($total / 1073741824, 2),
                'used_gb'  => round($used  / 1073741824, 2),
                'free_gb'  => round($free  / 1073741824, 2),
                'percent'  => $percent,
            ];
        } else {
            $result = ['total_gb' => 0, 'used_gb' => 0, 'free_gb' => 0, 'percent' => 0];
        }

        set_transient('rtsm_stat_disk', $result, 60);
        return $result;
    }

    /**
     * Get all core metrics as a single atomic snapshot.
     *
     * All metrics are collected in the same request window, eliminating timestamp
     * skew caused by per-metric transients that may expire at different times.
     * Used by ajax_get_stats() to guarantee response coherence.
     *
     * @return array{_meta: array, load: array, cpu: float, memory: array, connections: array}
     */
    public function get_snapshot(): array {
        $cached = get_transient('rtsm_snapshot');
        if ($cached !== false) {
            return $cached;
        }

        // Invalidate per-metric transients so all four measurements happen in the
        // same request at the same point in time — no stale values in the snapshot.
        delete_transient('rtsm_stat_load');
        delete_transient('rtsm_stat_cpu');
        delete_transient('rtsm_stat_memory');
        delete_transient('rtsm_stat_connections');

        $snapshot = [
            '_meta'       => ['timestamp' => time()],
            'load'        => $this->get_load_average(),
            'cpu'         => $this->get_cpu_usage(),
            'memory'      => $this->get_memory_info(),
            'connections' => $this->get_connections(),
            'disk'        => $this->get_disk_info(),
        ];

        // Cache for 1 second less than the configured refresh interval so each JS
        // poll sees fresh data. Falls back to CACHE_TTL when Settings_Manager is
        // unavailable. Minimum TTL is 1 second.
        $refresh_interval = class_exists( 'RTSM_Settings_Manager' )
            ? (int) RTSM_Settings_Manager::get_instance()->get_refresh_interval()
            : self::CACHE_TTL;
        $ttl = max( 1, $refresh_interval - 1 );

        set_transient('rtsm_snapshot', $snapshot, $ttl);
        return $snapshot;
    }

    /**
     * Determine if an IP should be included in connection tracking
     * Filters out localhost, private networks, and Cloudflare IPs
     */
    private function should_include_ip($ip) {
        // Filter localhost and loopback
        if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '127.') === 0) {
            return false;
        }
        
        // Filter private network ranges (RFC 1918)
        if (preg_match('/^10\./', $ip) ||                    // 10.0.0.0/8
            preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip) || // 172.16.0.0/12
            preg_match('/^192\.168\./', $ip)) {              // 192.168.0.0/16
            return false;
        }
        
        // Filter Cloudflare IP ranges (common ones)
        // Full list at: https://www.cloudflare.com/ips/
        $cloudflare_ranges = [
            '173.245.48.', '103.21.244.', '103.22.200.', '103.31.4.', '141.101.64.',
            '108.162.192.', '190.93.240.', '188.114.96.', '197.234.240.', '198.41.128.',
            '162.158.', '104.16.', '104.17.', '104.18.', '104.19.', '104.20.', '104.21.',
            '104.22.', '104.23.', '104.24.', '104.25.', '104.26.', '104.27.', '104.28.'
        ];
        
        foreach ($cloudflare_ranges as $range) {
            if (strpos($ip, $range) === 0) {
                return false;
            }
        }
        
        // Check if it's the server's own external IP
        $server_ip = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
        if ($server_ip && trim($server_ip) === $ip) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parse_memory_limit($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit)-1]);
        $limit = intval($limit);
        
        switch($last) {
            case 'g':
                $limit *= 1024;
            case 'm':
                $limit *= 1024;
            case 'k':
                $limit *= 1024;
        }
        
        return $limit;
    }
}
