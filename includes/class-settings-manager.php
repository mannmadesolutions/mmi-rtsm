<?php
/**
 * RTSM Settings Manager
 * 
 * Centralized settings management using custom database table
 * for better performance and unified settings across the plugin suite
 * 
 * @package MMI_RTSM
 * @since 2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Settings_Manager {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Database table name
     */
    private $table_name;
    
    /**
     * Settings cache
     */
    private $cache = [];
    
    /**
     * Default settings
     */
    private $defaults = [
        'rtsm_refresh_interval' => 10,
        'rtsm_show_admin_bar' => 1,
        'rtsm_show_dashboard_widget' => 1,
        // Auto-maintenance: must be explicitly opted in to (default OFF)
        // When disabled, RTSM still logs incidents and fires hooks (so Cloudflare escalation
        // still works) but will NOT write ABSPATH/.maintenance to take the site offline.
        'rtsm_auto_maintenance' => 0,
    ];
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mmi_settings';
        
        // Create table on initialization if needed
        $this->maybe_create_table();
        
        // Migrate existing wp_options to custom table on first run
        $this->maybe_migrate_settings();
    }
    
    /**
     * Create settings table if it doesn't exist
     */
    private function maybe_create_table() {
        global $wpdb;
        
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        
        if (!$table_exists) {
            $this->create_table();
        }
    }
    
    /**
     * Create the settings table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin_slug varchar(100) NOT NULL,
            setting_key varchar(191) NOT NULL,
            setting_value longtext,
            autoload varchar(20) NOT NULL DEFAULT 'yes',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY plugin_setting (plugin_slug, setting_key),
            KEY plugin_slug (plugin_slug),
            KEY autoload (autoload)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        MMI_Logger::info( 'Created mmi_settings table', [], 'database', 'RTSM_Settings_Manager' );
    }
    
    /**
     * Migrate existing settings from wp_options to custom table
     */
    private function maybe_migrate_settings() {
        // Check if migration has already been done
        if (MMI_Settings::get('rtsm_settings_migrated', false)) {
            return;
        }
        
        $migrated = 0;
        foreach ($this->defaults as $key => $default_value) {
            // Get existing value from wp_options
            $value = get_option($key, null);
            
            if ($value !== null) {
                // Save to custom table
                $this->set($key, $value);
                $migrated++;
            }
        }
        
        // Mark migration as complete
        MMI_Settings::set('rtsm_settings_migrated', true, false);
        
        if ($migrated > 0) {
            MMI_Logger::info( "Migrated {$migrated} settings to custom table", [], 'database', 'RTSM_Settings_Manager' );
        }
    }
    
    /**
     * Get a setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public function get($key, $default = null) {
        // Check cache first
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        
        // Use default from class if no custom default provided
        if ($default === null && isset($this->defaults[$key])) {
            $default = $this->defaults[$key];
        }
        
        global $wpdb;
        
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT setting_value FROM {$this->table_name}
             WHERE plugin_slug = %s AND setting_key = %s",
            'mmi-rtsm',
            $key
        ));
        
        // If not found, return default
        if ($value === null) {
            return $default;
        }
        
        // Attempt to unserialize if serialized
        $unserialized = @maybe_unserialize($value);
        $value = ($unserialized !== false) ? $unserialized : $value;
        
        // Cache the value
        $this->cache[$key] = $value;
        
        return $value;
    }
    
    /**
     * Set a setting value
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @param string $autoload Whether to autoload (yes/no)
     * @return bool Success
     */
    public function set($key, $value, $autoload = 'yes') {
        global $wpdb;
        
        // Serialize if needed
        $value = maybe_serialize($value);
        
        $result = $wpdb->replace(
            $this->table_name,
            [
                'plugin_slug' => 'mmi-rtsm',
                'setting_key' => $key,
                'setting_value' => $value,
                'autoload' => $autoload
            ],
            ['%s', '%s', '%s', '%s']
        );
        
        // Update cache
        if ($result !== false) {
            $this->cache[$key] = maybe_unserialize($value);
        }
        
        return $result !== false;
    }
    
    /**
     * Delete a setting
     * 
     * @param string $key Setting key
     * @return bool Success
     */
    public function delete($key) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            [
                'plugin_slug' => 'mmi-rtsm',
                'setting_key' => $key
            ],
            ['%s', '%s']
        );
        
        // Clear from cache
        unset($this->cache[$key]);
        
        return $result !== false;
    }
    
    /**
     * Get all settings for this plugin
     * 
     * @return array Associative array of settings
     */
    public function get_all() {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT setting_key, setting_value FROM {$this->table_name}
             WHERE plugin_slug = %s",
            'mmi-rtsm'
        ), ARRAY_A);
        
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['setting_key']] = maybe_unserialize($row['setting_value']);
        }
        
        return $settings;
    }
    
    /**
     * Get refresh interval (most commonly used setting)
     * 
     * @return int Refresh interval in seconds
     */
    public function get_refresh_interval() {
        return (int) $this->get('rtsm_refresh_interval', 10);
    }
    
    /**
     * Set refresh interval
     * 
     * @param int $seconds Seconds (5-60)
     * @return bool Success
     */
    public function set_refresh_interval($seconds) {
        $seconds = max(5, min(60, (int) $seconds));
        return $this->set('rtsm_refresh_interval', $seconds);
    }
    
    /**
     * Clear all cached settings
     */
    public function clear_cache() {
        $this->cache = [];
    }
    
    /**
     * Export settings for debugging
     * 
     * @return array Settings with metadata
     */
    public function export_settings() {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE plugin_slug = %s",
            'mmi-rtsm'
        ), ARRAY_A);
    }
}
