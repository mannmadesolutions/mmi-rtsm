<?php
/**
 * Environment Detector
 * 
 * Detects hosting environment type, provider, and available capabilities
 * This determines which features RTSM can offer to the user
 *
 * @package MMI_RTSM
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Environment_Detector {
    
    /**
     * Cached environment data
     */
    private static $environment_cache = null;
    
    /**
     * Get complete environment information
     */
    public static function get_environment() {
        if (self::$environment_cache !== null) {
            return self::$environment_cache;
        }
        
        $environment = [
            'type' => self::detect_environment_type(),
            'provider' => self::detect_hosting_provider(),
            'capabilities' => self::detect_capabilities(),
            'recommendations' => [],
            'limitations' => []
        ];
        
        // Add provider-specific recommendations
        $environment['recommendations'] = self::get_provider_recommendations($environment['provider']);
        
        // Add limitations based on environment
        $environment['limitations'] = self::get_environment_limitations($environment);
        
        self::$environment_cache = $environment;
        
        return $environment;
    }
    
    /**
     * Detect environment type
     * 
     * @return string shared|vps|dedicated|managed|local
     */
    private static function detect_environment_type() {
        // Local development indicators
        if (self::is_local_environment()) {
            return 'local';
        }
        
        // Managed WordPress hosting (WPEngine, Kinsta, etc.)
        if (self::is_managed_wordpress()) {
            return 'managed';
        }
        
        // VPS/Dedicated indicators
        if (self::has_root_capabilities()) {
            return 'dedicated';
        }
        
        if (self::has_vps_indicators()) {
            return 'vps';
        }
        
        // Default to shared hosting
        return 'shared';
    }
    
    /**
     * Check if running in local development environment
     */
    private static function is_local_environment() {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        $local_indicators = [
            'localhost',
            '127.0.0.1',
            '.local',
            '.test',
            '.dev',
            'vagrant',
            'homestead',
            'vvv.local',
            'wpsandbox'
        ];
        
        foreach ($local_indicators as $indicator) {
            if (stripos($host, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for local IP ranges
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $host)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if running on managed WordPress hosting
     */
    private static function is_managed_wordpress() {
        // Check for managed hosting via headers or constants
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        
        $managed_indicators = [
            defined('WPENGINE_ACCOUNT'),
            defined('KINSTA_CACHE_ZONE'),
            defined('GD_SYSTEM_PLUGIN_DIR'), // GoDaddy Managed
            defined('FLYWHEEL_CONFIG_DIR'),
            defined('WPE_PLUGIN_DIR'),
            isset($headers['X-Kinsta-Cache']),
            isset($headers['X-Pass-Why']),
            isset($_SERVER['IS_WPE']),
            isset($_SERVER['KINSTA_CACHE_ZONE'])
        ];
        
        return in_array(true, $managed_indicators, true);
    }
    
    /**
     * Check for VPS indicators
     */
    private static function has_vps_indicators() {
        // Can execute shell commands
        if (self::can_execute_shell()) {
            return true;
        }
        
        // Can read system files
        if (is_readable('/proc/cpuinfo') || is_readable('/etc/os-release')) {
            return true;
        }
        
        // Check for VPS control panel presence
        $vps_paths = [
            '/usr/local/runcloud',
            '/usr/local/serverpilot',
            '/usr/local/gridpane',
            '/opt/ploi'
        ];
        
        foreach ($vps_paths as $path) {
            if (is_dir($path)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check for root/full server capabilities
     */
    private static function has_root_capabilities() {
        // Try to read root-only files
        $root_files = [
            '/root/.bash_history',
            '/var/log/auth.log',
            '/etc/shadow'
        ];
        
        foreach ($root_files as $file) {
            if (is_readable($file)) {
                return true;
            }
        }
        
        // Check if running as root user
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect hosting provider
     */
    private static function detect_hosting_provider() {
        // Check HTTP headers first
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        
        // Provider detection via headers
        $header_providers = [
            'X-Kinsta-Cache' => 'kinsta',
            'X-Pass-Why' => 'wpengine',
            'X-SiteGround-ID' => 'siteground',
            'X-Powered-By-Plesk' => 'plesk',
            'Server' => [
                'LiteSpeed' => 'litespeed',
                'cloudflare' => 'cloudflare'
            ]
        ];
        
        foreach ($header_providers as $header => $provider) {
            if (isset($headers[$header])) {
                if (is_array($provider)) {
                    foreach ($provider as $value => $name) {
                        if (stripos($headers[$header], $value) !== false) {
                            return $name;
                        }
                    }
                } else {
                    return $provider;
                }
            }
        }
        
        // Check for provider-specific constants
        if (defined('WPENGINE_ACCOUNT')) return 'wpengine';
        if (defined('KINSTA_CACHE_ZONE')) return 'kinsta';
        if (defined('GD_SYSTEM_PLUGIN_DIR')) return 'godaddy';
        if (defined('FLYWHEEL_CONFIG_DIR')) return 'flywheel';
        if (defined('MM_BASE_DIR')) return 'bluehost';
        
        // Check for control panel directories
        if (is_dir('/usr/local/runcloud')) return 'runcloud';
        if (is_dir('/usr/local/serverpilot')) return 'serverpilot';
        if (is_dir('/usr/local/cpanel')) return 'cpanel';
        if (is_dir('/usr/local/gridpane')) return 'gridpane';
        if (is_dir('/opt/ploi')) return 'ploi';
        
        // Check for provider-specific files
        if (file_exists('/etc/siteground-release')) return 'siteground';
        if (file_exists('/usr/local/interworx')) return 'interworx';
        
        // Check server hostname patterns
        $hostname = gethostname();
        $hostname_patterns = [
            'hostgator' => 'hostgator',
            'dreamhost' => 'dreamhost',
            'bluehost' => 'bluehost',
            'inmotionhosting' => 'inmotion',
            'a2hosting' => 'a2hosting',
            'namecheap' => 'namecheap',
            'digitalocean' => 'digitalocean',
            'vultr' => 'vultr',
            'linode' => 'linode',
            'aws' => 'aws'
        ];
        
        foreach ($hostname_patterns as $pattern => $provider) {
            if (stripos($hostname, $pattern) !== false) {
                return $provider;
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Detect available capabilities
     */
    private static function detect_capabilities() {
        return [
            'shell_exec' => self::can_execute_shell(),
            'system_files' => self::can_read_system_files(),
            'process_list' => self::can_list_processes(),
            'process_kill' => self::can_kill_processes(),
            'network_stats' => self::can_read_network_stats(),
            'disk_stats' => self::can_read_disk_stats(),
            'log_files' => self::can_read_log_files(),
            'multi_site_access' => self::can_access_other_sites(),
            'php_functions' => self::get_available_php_functions(),
            'server_software' => self::detect_server_software()
        ];
    }
    
    /**
     * Check if shell execution is available
     */
    private static function can_execute_shell() {
        if (!function_exists('shell_exec')) {
            return false;
        }
        
        $disabled = explode(',', ini_get('disable_functions'));
        return !in_array('shell_exec', $disabled);
    }
    
    /**
     * Check if system files can be read
     */
    private static function can_read_system_files() {
        $test_files = [
            '/proc/loadavg',
            '/proc/meminfo',
            '/proc/cpuinfo'
        ];
        
        foreach ($test_files as $file) {
            if (is_readable($file)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if processes can be listed
     */
    private static function can_list_processes() {
        if (!self::can_execute_shell()) {
            return false;
        }
        
        $test = @shell_exec('ps aux 2>&1');
        return $test !== null && stripos($test, 'not found') === false;
    }
    
    /**
     * Check if processes can be killed
     */
    private static function can_kill_processes() {
        // This is a permission check, not actually killing anything
        return self::can_execute_shell() && function_exists('posix_getuid');
    }
    
    /**
     * Check if network stats can be read
     */
    private static function can_read_network_stats() {
        if (self::can_execute_shell()) {
            $test = @shell_exec('netstat -an 2>&1');
            if ($test !== null && stripos($test, 'not found') === false) {
                return true;
            }
        }
        
        return is_readable('/proc/net/tcp');
    }
    
    /**
     * Check if disk stats can be read
     */
    private static function can_read_disk_stats() {
        return function_exists('disk_free_space') && function_exists('disk_total_space');
    }
    
    /**
     * Check if log files can be read
     */
    private static function can_read_log_files() {
        $log_locations = [
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            ini_get('error_log')
        ];
        
        foreach ($log_locations as $log) {
            if ($log && is_readable($log)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if can access other WordPress sites on server
     */
    private static function can_access_other_sites() {
        // Check if can read parent directory structure
        $doc_root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $parent = dirname($doc_root);
        
        if (is_readable($parent) && is_dir($parent)) {
            // Try to list directory contents
            $contents = @scandir($parent);
            return $contents !== false && count($contents) > 2; // More than . and ..
        }
        
        return false;
    }
    
    /**
     * Get available PHP functions
     */
    private static function get_available_php_functions() {
        $functions = [
            'shell_exec', 'exec', 'system', 'passthru', 'proc_open',
            'posix_getuid', 'posix_kill', 'apache_get_modules'
        ];
        
        $available = [];
        $disabled = explode(',', ini_get('disable_functions'));
        
        foreach ($functions as $func) {
            if (function_exists($func) && !in_array($func, $disabled)) {
                $available[] = $func;
            }
        }
        
        return $available;
    }
    
    /**
     * Detect server software
     */
    private static function detect_server_software() {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
        
        return [
            'raw' => $software,
            'type' => self::parse_server_type($software),
            'version' => self::parse_server_version($software)
        ];
    }
    
    /**
     * Parse server type from SERVER_SOFTWARE
     */
    private static function parse_server_type($software) {
        if (stripos($software, 'nginx') !== false) return 'nginx';
        if (stripos($software, 'apache') !== false) return 'apache';
        if (stripos($software, 'litespeed') !== false) return 'litespeed';
        if (stripos($software, 'iis') !== false) return 'iis';
        
        return 'unknown';
    }
    
    /**
     * Parse server version from SERVER_SOFTWARE
     */
    private static function parse_server_version($software) {
        if (preg_match('/(\d+\.\d+\.\d+)/', $software, $matches)) {
            return $matches[1];
        }
        
        return 'unknown';
    }
    
    /**
     * Get provider-specific recommendations
     */
    private static function get_provider_recommendations($provider) {
        $recommendations = [
            'wpengine' => [
                'Enable WP Engine object cache for better performance',
                'Use built-in CDN (no plugin needed)',
                'Leverage WP Engine staging environments',
                'No caching plugin needed - built-in server caching'
            ],
            'kinsta' => [
                'Enable Kinsta CDN in MyKinsta dashboard',
                'Use Redis object cache (included free)',
                'No caching plugin needed - server-level caching active',
                'Utilize Kinsta APM for deep performance insights'
            ],
            'siteground' => [
                'Install SG Optimizer plugin for best performance',
                'Enable SuperCacher in Site Tools',
                'Use SiteGround CDN (free with hosting)',
                'Consider upgrading to GoGeek for more resources'
            ],
            'godaddy' => [
                'Consider migrating to SiteGround, Kinsta, or Cloudways',
                'Expected improvement: 40-60% faster load times',
                'GoDaddy shared hosting has known performance issues',
                'Better options available at similar price points'
            ],
            'bluehost' => [
                'Consider upgrading to Cloud or VPS plan',
                'Install a caching plugin (WP Rocket or W3 Total Cache)',
                'Shared hosting can be slow - monitor performance',
                'Alternative hosts: SiteGround, A2 Hosting, or Cloudways'
            ],
            'hostgator' => [
                'Install a caching plugin immediately',
                'Consider upgrading to Cloud or VPS hosting',
                'Optimize database regularly (high autoload issues)',
                'Better performance available at similar cost elsewhere'
            ],
            'runcloud' => [
                'Optimize PHP-FPM settings in RunCloud panel',
                'Enable Redis or Memcached for object caching',
                'Serve static files through a CDN',
                'Monitor PHP worker usage - upgrade if hitting limits'
            ],
            'unknown' => [
                'Install a performance monitoring plugin',
                'Enable caching (WP Rocket, W3 Total Cache, or WP Super Cache)',
                'Optimize images with ShortPixel or Imagify',
                'Monitor database performance and clean regularly'
            ]
        ];
        
        return $recommendations[$provider] ?? $recommendations['unknown'];
    }
    
    /**
     * Get environment limitations
     */
    private static function get_environment_limitations($environment) {
        $limitations = [];
        
        if ($environment['type'] === 'shared') {
            $limitations[] = 'Server-wide monitoring not available on shared hosting';
            $limitations[] = 'Process management disabled (hosting restriction)';
            $limitations[] = 'System log access unavailable';
        }
        
        if ($environment['type'] === 'managed') {
            $limitations[] = 'Some server metrics restricted by managed host';
            $limitations[] = 'Process management may be limited';
        }
        
        if (!$environment['capabilities']['shell_exec']) {
            $limitations[] = 'Shell execution disabled - some features unavailable';
            $limitations[] = 'Process monitoring limited to WordPress context';
        }
        
        if (!$environment['capabilities']['log_files']) {
            $limitations[] = 'Cannot access server log files';
            $limitations[] = 'WordPress-level logging only';
        }
        
        if (!$environment['capabilities']['multi_site_access']) {
            $limitations[] = 'Cannot detect other sites on server';
            $limitations[] = 'Multi-site dashboard unavailable';
        }
        
        return $limitations;
    }
    
    /**
     * Get human-readable environment summary
     */
    public static function get_environment_summary() {
        $env = self::get_environment();
        
        $type_labels = [
            'shared' => 'Shared Hosting',
            'vps' => 'VPS Hosting',
            'dedicated' => 'Dedicated Server',
            'managed' => 'Managed WordPress Hosting',
            'local' => 'Local Development'
        ];
        
        $provider_labels = [
            'wpengine' => 'WP Engine',
            'kinsta' => 'Kinsta',
            'siteground' => 'SiteGround',
            'godaddy' => 'GoDaddy',
            'bluehost' => 'Bluehost',
            'hostgator' => 'HostGator',
            'runcloud' => 'RunCloud',
            'unknown' => 'Unknown Provider'
        ];
        
        return [
            'type' => $type_labels[$env['type']] ?? $env['type'],
            'provider' => $provider_labels[$env['provider']] ?? ucfirst($env['provider']),
            'capabilities_count' => count(array_filter($env['capabilities'])),
            'has_limitations' => count($env['limitations']) > 0
        ];
    }
}
