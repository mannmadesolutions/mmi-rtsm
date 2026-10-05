<?php
/**
 * RTSM Admin Page Template
 * 
 * Unified admin interface with tabbed navigation
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get license information
$license_manager = RTSM_License_Manager::get_instance();
$is_licensed     = $license_manager->is_licensed();
$license_tier    = $is_licensed ? 'licensed' : 'free'; // for backward-compat helpers
$is_premium      = $is_licensed;
$has_pro         = $is_licensed;

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

// Check current server load for emergency notice using unified metrics if available
if (class_exists('MMI_Unified_Metrics')) {
    $metrics = MMI_Unified_Metrics::instance()->get_metrics();
    $current_load = $metrics['load']['1min'];
} else {
    $current_load = sys_getloadavg()[0];
}
$show_emergency_notice = $current_load >= 10.0;
?>

<div class="wrap mmi-page mmi-rtsm-page">
    <div class="mmi-header">
        <h1><span class="dashicons dashicons-performance"></span> Real-Time Server Monitor</h1>
        <div class="mmi-header-actions">
            <?php echo RTSM_Tier_Branding::render_badge( $license_tier ); ?>
            <?php if ( ! $is_licensed ) : ?>
                <a href="<?php echo esc_url( $license_manager->get_upgrade_url() ); ?>" class="button button-primary" target="_blank">
                    <span class="dashicons dashicons-unlock"></span> Get MMI Suite
                </a>
            <?php endif; ?>
        </div>
        <p class="mmi-header-description">Comprehensive server performance monitoring and analysis</p>
    </div>
    <div class="wp-header-end"></div>

    <?php if ($show_emergency_notice): ?>
        <div class="notice notice-error">
            <p>
                <strong>⚠️ High Server Load Detected (<?php echo esc_html(number_format($current_load, 2)); ?>)</strong><br>
                The server is under heavy load. Check the Process Monitor and Diagnostics tabs for details.
            </p>
        </div>
    <?php endif; ?>

        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'dashboard' ) ); ?>" class="nav-tab <?php echo $active_tab === 'dashboard' ? 'nav-tab-active' : ''; ?>" data-tab="dashboard">
                <span class="dashicons dashicons-dashboard"></span>
                Dashboard
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'traffic' ) ); ?>" class="nav-tab <?php echo $active_tab === 'traffic' ? 'nav-tab-active' : ''; ?>" data-tab="traffic">
                <span class="dashicons dashicons-chart-line"></span>
                Traffic Analysis
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'processes' ) ); ?>" class="nav-tab <?php echo $active_tab === 'processes' ? 'nav-tab-active' : ''; ?>" data-tab="processes">
                <span class="dashicons dashicons-editor-ul"></span>
                Process Monitor
                <?php if ( ! $has_pro ) : ?>
                    <?php echo RTSM_Tier_Branding::render_tab_badge(); ?>
                <?php endif; ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'diagnostics' ) ); ?>" class="nav-tab <?php echo $active_tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>" data-tab="diagnostics">
                <span class="dashicons dashicons-admin-generic"></span>
                Diagnostics
                <?php if ( ! $is_premium ) : ?>
                    <?php echo RTSM_Tier_Branding::render_tab_badge(); ?>
                <?php endif; ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings' ) ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>" data-tab="settings">
                <span class="dashicons dashicons-admin-settings"></span>
                Settings
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'tab', 'logs' ) ); ?>" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>" data-tab="logs">
                <span class="dashicons dashicons-media-text"></span>
                Activity Logs
            </a>
        </nav>

        <!-- Tab panes (inactive ones load via rtsm_load_tab) -->
            <!-- Only load the active tab on page load -->
            <?php
            $tabs = ['dashboard', 'traffic', 'processes', 'diagnostics', 'settings', 'logs'];
            foreach ($tabs as $tab) {
                $is_active = ($active_tab === $tab);
                echo '<div id="tab-' . $tab . '" class="mmi-tab-pane' . ($is_active ? ' active' : '') . '" data-tab="' . $tab . '" data-loaded="' . ($is_active ? 'true' : 'false') . '">';
                
                if ($is_active) {
                    // Load active tab content immediately
                    include RTSM_PLUGIN_DIR . 'templates/admin/tabs/' . $tab . '.php';
                } else {
                    // Show loading placeholder for inactive tabs
                    include RTSM_PLUGIN_DIR . 'templates/partials/tab-loading-placeholder.php';
                }
                
                echo '</div>';
            }
            ?>
</div>

