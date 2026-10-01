<?php
/**
 * Platform Detector
 * 
 * Detects hosting platform and available system functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Platform_Detector {
    
    private static $instance = null;
    private $platform = null;
    private $capabilities = [];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->detect_platform();
        $this->detect_capabilities();
    }
    
    private function detect_platform() {
        // Check for common hosting platforms
        if (defined('IS_WPE') && IS_WPE) {
            $this->platform = 'wpengine';
        } elseif (defined('GD_SYSTEM_PLUGIN_DIR')) {
            $this->platform = 'godaddy';
        } elseif (defined('FLYWHEEL_CONFIG_DIR')) {
            $this->platform = 'flywheel';
        } elseif (isset($_SERVER['KINSTA_CACHE_ZONE'])) {
            $this->platform = 'kinsta';
        } elseif (@file_exists('/opt/sp/mu-plugins')) {
            $this->platform = 'siteground';
        } elseif (getenv('PANTHEON_ENVIRONMENT')) {
            $this->platform = 'pantheon';
        } elseif (
            strpos(gethostname(), 'runcloud') !== false ||
            @file_exists('/RunCloud') ||
            @is_dir('/etc/runcloud-agent') ||
            @is_dir('/opt/runcloud') ||
            @is_dir('/etc/runcloud')
        ) {
            $this->platform = 'runcloud';
        } elseif (@file_exists('/usr/local/cpanel')) {
            $this->platform = 'cpanel';
        } elseif (@file_exists('/usr/local/psa')) {
            $this->platform = 'plesk';
        } else {
            $this->platform = 'generic';
        }
    }
    
    private function detect_capabilities() {
        // Check if shell_exec is available
        $this->capabilities['shell_exec'] = $this->is_function_available('shell_exec');
        
        // Check if exec is available
        $this->capabilities['exec'] = $this->is_function_available('exec');
        
        // Check if sys_getloadavg is available
        $this->capabilities['sys_getloadavg'] = function_exists('sys_getloadavg');
        
        // Check if proc_open is available
        $this->capabilities['proc_open'] = $this->is_function_available('proc_open');
        
        // Check if we can read /proc files (Linux) - suppress open_basedir warnings
        $this->capabilities['proc_files'] = @is_readable('/proc/loadavg');
        
        // Check if we can use PHP's getrusage
        $this->capabilities['getrusage'] = function_exists('getrusage');
        
        // Check for Windows
        $this->capabilities['windows'] = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    private function is_function_available($function) {
        if (!function_exists($function)) {
            return false;
        }
        
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);
        
        return !in_array($function, $disabled);
    }
    
    public function get_platform() {
        return $this->platform;
    }
    
    public function get_capabilities() {
        return $this->capabilities;
    }
    
    public function has_capability($capability) {
        return isset($this->capabilities[$capability]) && $this->capabilities[$capability];
    }
    
    public function get_platform_info() {
        // Compute server uptime from /proc/uptime (Linux only)
        $uptime = '';
        if ( @is_readable('/proc/uptime') ) {
            $raw = @file_get_contents('/proc/uptime');
            if ( $raw ) {
                $secs    = (int) $raw;
                $days    = floor( $secs / 86400 );
                $hours   = floor( ( $secs % 86400 ) / 3600 );
                $minutes = floor( ( $secs % 3600 ) / 60 );
                $uptime  = $days > 0 ? "{$days}d {$hours}h {$minutes}m" : "{$hours}h {$minutes}m";
            }
        }

        return [
            'platform'        => $this->platform,
            'platform_name'   => $this->get_platform_name(),
            'php_version'     => PHP_VERSION,
            'php_sapi'        => php_sapi_name(),
            'os'              => PHP_OS,
            'os_name'         => php_uname('s'),
            'kernel'          => php_uname('r'),
            'hostname'        => gethostname(),
            'uptime'          => $uptime,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'capabilities'    => $this->capabilities,
        ];
    }
    
    private function get_platform_name() {
        $names = [
            'wpengine' => 'WP Engine',
            'godaddy' => 'GoDaddy',
            'flywheel' => 'Flywheel',
            'kinsta' => 'Kinsta',
            'siteground' => 'SiteGround',
            'pantheon' => 'Pantheon',
            'runcloud' => 'RunCloud',
            'cpanel' => 'cPanel',
            'plesk' => 'Plesk',
            'generic' => 'Generic Linux/Unix'
        ];
        
        return $names[$this->platform] ?? 'Unknown';
    }
}
