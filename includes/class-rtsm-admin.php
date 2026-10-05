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
                'trafficUrl'      => admin_url('admin.php?page=mmi-rtsm&tab=traffic'),
                'logsUrl'         => admin_url('admin.php?page=mmi-rtsm&tab=logs'),
                'processesUrl'    => admin_url('admin.php?page=mmi-rtsm&tab=processes'),
                'thresholds'      => RTSM_UI_Helpers::get_thresholds(),
            ]);
        }
        
        // admin-page.js depends on rtsm-admin-tabs so rtsmAdmin is defined first.
        wp_enqueue_script('rtsm-admin-page', RTSM_PLUGIN_URL . 'assets/js/admin-page.js', ['jquery', 'rtsm-admin-tabs', 'mmi-escape-html'], RTSM_VERSION, true);
        wp_enqueue_script('rtsm-refactored-inline', RTSM_PLUGIN_URL . 'assets/js/refactored-inline.js', ['jquery', 'mmi-escape-html'], RTSM_VERSION, true);
        wp_enqueue_script('rtsm-traffic-tab', RTSM_PLUGIN_URL . 'assets/js/traffic-tab.js', ['jquery', 'rtsm-admin-tabs'], RTSM_VERSION, true);

        // Diagnostics tab script. Enqueued here, not from the tab template:
        // the tab usually arrives via rtsm_load_tab (AJAX), where enqueueing
        // does nothing.
        wp_enqueue_script('rtsm-diagnostics', RTSM_PLUGIN_URL . 'assets/js/diagnostics.js', ['jquery', 'chartjs'], RTSM_VERSION, true);
        wp_localize_script('rtsm-diagnostics', 'rtsmDiag', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mmi-panel-admin-nonce'),
        ]);

        // Shared license panel behavior (Settings tab renders MMI_License_UI).
        // The shared library only registers this handle.
        wp_enqueue_script('mmi-license-panel');

        // Styling: the shared design system (mmi-suite-common, loaded on every
        // MMI page) plus RTSM's few local rules. admin.css replaced eleven
        // plugin-local stylesheets in 2.10.0.
        wp_enqueue_style('rtsm-admin', RTSM_PLUGIN_URL . 'assets/css/admin.css', ['mmi-suite-common'], RTSM_VERSION);
        // Unlicensed-only feature overlays — tracked separately with the
        // shared .mmi-gated-feature / MMI_Feature_Gate work (style-system.md).
        wp_enqueue_style('rtsm-premium-overlays', RTSM_PLUGIN_URL . 'assets/css/premium-overlays.css', ['mmi-suite-common'], RTSM_VERSION);
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

        // Load the tabbed admin interface template
        include RTSM_PLUGIN_DIR . 'templates/admin/admin-page.php';
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
}
