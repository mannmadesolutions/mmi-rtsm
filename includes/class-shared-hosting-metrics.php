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
    private $request_count_key = 'rtsm_request_count';
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Track requests per minute
        add_action('init', [$this, 'track_request'], 1);
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
}
