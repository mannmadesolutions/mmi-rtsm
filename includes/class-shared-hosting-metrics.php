<?php
/**
 * Shared Hosting Metrics
 * 
 * Collects server metrics using ONLY PHP functions (no shell access required)
 * This is the FREE tier - works on any hosting environment
 * 
 * @package MMI_RTSM
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Shared_Hosting_Metrics {
    
    private static $instance = null;
    private $start_time;
    private $request_count_key = 'rtsm_request_count';
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->start_time = microtime(true);
        
        // Track requests per minute
        add_action('init', [$this, 'track_request'], 1);
    }
    
    /**
     * Get all available metrics for shared hosting
     * 
     * @return array
     */
    public function get_all_metrics() {
        return [
            'load' => $this->get_load_average(),
            'memory' => $this->get_memory_info(),
            'disk' => $this->get_disk_info(),
            'php' => $this->get_php_info(),
            'requests' => $this->get_request_stats(),
            'database' => $this->get_database_stats(),
            'timestamp' => current_time('mysql'),
            'tier' => 'FREE' // Hosting tier
        ];
    }
    
    /**
     * Get load average (works on most Linux shared hosting)
     * 
     * @return array
     */
    public function get_load_average() {
        $load = [
            '1min' => 0,
            '5min' => 0,
            '15min' => 0,
            'available' => false
        ];
        
        // Try sys_getloadavg (available on most Linux shared hosting)
        if (function_exists('sys_getloadavg')) {
            $sys_load = @sys_getloadavg();
            if (is_array($sys_load) && count($sys_load) >= 3) {
                $load['1min'] = round($sys_load[0], 2);
                $load['5min'] = round($sys_load[1], 2);
                $load['15min'] = round($sys_load[2], 2);
                $load['available'] = true;
            }
        }
        
        // Fallback: Try reading /proc/loadavg (if readable)
        if (!$load['available'] && is_readable('/proc/loadavg')) {
            $loadavg = @file_get_contents('/proc/loadavg');
            if ($loadavg) {
                $parts = explode(' ', $loadavg);
                if (count($parts) >= 3) {
                    $load['1min'] = round(floatval($parts[0]), 2);
                    $load['5min'] = round(floatval($parts[1]), 2);
                    $load['15min'] = round(floatval($parts[2]), 2);
                    $load['available'] = true;
                }
            }
        }
        
        return $load;
    }
    
    /**
     * Get memory information (PHP process only)
     * 
     * @return array
     */
    public function get_memory_info() {
        $memory = [
            'current_usage' => 0,
            'current_usage_mb' => '0 MB',
            'peak_usage' => 0,
            'peak_usage_mb' => '0 MB',
            'limit' => 0,
            'limit_mb' => '0 MB',
            'percent' => 0,
            'available' => true
        ];
        
        // Current memory usage
        $memory['current_usage'] = memory_get_usage(true);
        $memory['current_usage_mb'] = $this->format_bytes($memory['current_usage']);
        
        // Peak memory usage
        $memory['peak_usage'] = memory_get_peak_usage(true);
        $memory['peak_usage_mb'] = $this->format_bytes($memory['peak_usage']);
        
        // Memory limit
        $limit_str = ini_get('memory_limit');
        $memory['limit'] = $this->parse_size($limit_str);
        $memory['limit_mb'] = $this->format_bytes($memory['limit']);
        
        // Percentage
        if ($memory['limit'] > 0) {
            $memory['percent'] = round(($memory['peak_usage'] / $memory['limit']) * 100, 1);
        }
        
        return $memory;
    }
    
    /**
     * Get disk space information
     * 
     * @return array
     */
    public function get_disk_info() {
        $disk = [
            'total' => 0,
            'total_formatted' => '0 GB',
            'free' => 0,
            'free_formatted' => '0 GB',
            'used' => 0,
            'used_formatted' => '0 GB',
            'percent' => 0,
            'available' => false
        ];
        
        $path = ABSPATH;
        
        if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            
            if ($total !== false && $free !== false) {
                $disk['total'] = $total;
                $disk['total_formatted'] = $this->format_bytes($total);
                $disk['free'] = $free;
                $disk['free_formatted'] = $this->format_bytes($free);
                $disk['used'] = $total - $free;
                $disk['used_formatted'] = $this->format_bytes($disk['used']);
                $disk['percent'] = $total > 0 ? round(($disk['used'] / $total) * 100, 1) : 0;
                $disk['available'] = true;
            }
        }
        
        return $disk;
    }
    
    /**
     * Get PHP process information
     * 
     * @return array
     */
    public function get_php_info() {
        return [
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false,
            'opcache_stats' => $this->get_opcache_stats(),
            'current_script_time' => round(microtime(true) - $this->start_time, 3)
        ];
    }
    
    /**
     * Get OPcache statistics (if available)
     * 
     * @return array|null
     */
    private function get_opcache_stats() {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        
        $status = @opcache_get_status(false);
        if (!$status) {
            return null;
        }
        
        return [
            'enabled' => !empty($status['opcache_enabled']),
            'hits' => $status['opcache_statistics']['hits'] ?? 0,
            'misses' => $status['opcache_statistics']['misses'] ?? 0,
            'memory_used' => $this->format_bytes($status['memory_usage']['used_memory'] ?? 0),
            'memory_free' => $this->format_bytes($status['memory_usage']['free_memory'] ?? 0),
            'num_cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
        ];
    }
    
    /**
     * Track incoming requests
     */
    public function track_request() {
        $current_minute = date('Y-m-d H:i');
        $counts = get_transient($this->request_count_key) ?: [];
        
        if (!isset($counts[$current_minute])) {
            $counts[$current_minute] = 0;
        }
        
        $counts[$current_minute]++;
        
        // Keep only last 5 minutes
        $counts = array_slice($counts, -5, null, true);
        
        set_transient($this->request_count_key, $counts, 300);
    }
    
    /**
     * Get request statistics
     * 
     * @return array
     */
    public function get_request_stats() {
        $counts = get_transient($this->request_count_key) ?: [];
        
        $total = array_sum($counts);
        $minutes = count($counts);
        $avg_per_minute = $minutes > 0 ? round($total / $minutes, 1) : 0;
        
        return [
            'total_last_5min' => $total,
            'avg_per_minute' => $avg_per_minute,
            'current_minute' => $counts[date('Y-m-d H:i')] ?? 0,
            'by_minute' => $counts
        ];
    }
    
    /**
     * Get database statistics
     * 
     * @return array
     */
    public function get_database_stats() {
        global $wpdb;
        
        $stats = [
            'queries' => 0,
            'query_time' => 0,
            'connections' => 0,
            'available' => false
        ];
        
        // Query count (if SAVEQUERIES is enabled)
        if (defined('SAVEQUERIES') && SAVEQUERIES && isset($wpdb->queries)) {
            $stats['queries'] = count($wpdb->queries);
            
            // Calculate total query time
            foreach ($wpdb->queries as $query) {
                if (isset($query[1])) {
                    $stats['query_time'] += floatval($query[1]);
                }
            }
            
            $stats['query_time'] = round($stats['query_time'], 4);
            $stats['available'] = true;
        }
        
        // Try to get connection count from MySQL (might not work on shared hosting)
        $connections = $wpdb->get_var("SHOW STATUS LIKE 'Threads_connected'");
        if ($connections !== null) {
            $stats['connections'] = intval($connections);
        }
        
        return $stats;
    }
    
    /**
     * Format bytes to human-readable format
     * 
     * @param int $bytes
     * @return string
     */
    private function format_bytes($bytes) {
        $bytes = intval($bytes);
        
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $exp = floor(log($bytes) / log(1024));
        $exp = min($exp, count($units) - 1);
        
        return round($bytes / pow(1024, $exp), 2) . ' ' . $units[$exp];
    }
    
    /**
     * Parse size string (like '256M') to bytes
     * 
     * @param string $size
     * @return int
     */
    private function parse_size($size) {
        $size = trim($size);
        $last = strtolower($size[strlen($size)-1]);
        $value = intval($size);
        
        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get simplified status for admin bar
     * 
     * @return array
     */
    public function get_admin_bar_stats() {
        $load = $this->get_load_average();
        $memory = $this->get_memory_info();
        $requests = $this->get_request_stats();
        
        $status = 'normal';
        $color = '#45852C';
        
        if ($load['available']) {
            if ($load['1min'] >= 8.0) {
                $status = 'critical';
                $color = '#dc3232';
            } elseif ($load['1min'] >= 5.0) {
                $status = 'warning';
                $color = '#f0b849';
            }
        }
        
        return [
            'status' => $status,
            'color' => $color,
            'load' => $load['1min'],
            'memory_percent' => $memory['percent'],
            'requests_per_min' => $requests['avg_per_minute']
        ];
    }
}
