<?php
/**
 * RTSM Dashboard Tab
 * Real-time server monitoring
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current stats – use singleton so cached transients are hit if another
// tab was loaded in the same AJAX request sequence.
$collector = RTSM_Stats_Collector::get_instance();
$load = $collector->get_load_average();
$cpu = $collector->get_cpu_usage();
$memory = $collector->get_memory_info();
$connections = $collector->get_connections();

// Get platform info
$platform_info = RTSM_Platform_Detector::get_instance()->get_platform_info();

// Check for active incidents — same resolver the traffic logger writes to
// (and mmi-cloudflare-integration also reads), regardless of mmi-hub's presence.
$incident_file = rtrim(mmi_shared_lib_log_dir(), '/') . '/active-incident.flag';
$has_active_incident = file_exists($incident_file);
$active_incident = null;
if ($has_active_incident) {
    $active_incident = json_decode(file_get_contents($incident_file), true);
}

// Load cause analysis — always fetch so the panel can render even below threshold.
$load_causes = RTSM_Server_Monitor::get_load_cause_analysis();
// Show the cause panel whenever load is "elevated" (≥ 4.0), an incident is active,
// or there are overdue WP-Cron jobs — the cron health alert email's CTA button
// links straight here, so it must render even when current load looks normal.
$show_causes = $load['1min'] >= 4.0 || $has_active_incident || ( $load_causes['wpcron']['overdue_count'] ?? 0 ) > 0;
?>

<div class="mmi-dashboard-content">
    <!-- Active Incident Card -->
    <?php if ($has_active_incident): ?>
        <?php include RTSM_PLUGIN_DIR . 'templates/partials/card-active-incident.php'; ?>
    <?php endif; ?>

    <!-- Load Cause Analysis (shown when load is elevated) -->
    <?php if ($show_causes): ?>
        <?php
        $load1 = $load['1min'];
        include RTSM_PLUGIN_DIR . 'templates/partials/card-load-causes.php';
        ?>
    <?php else: ?>
        <!-- Placeholder keeps JS refresh target in the DOM at all times -->
        <div id="rtsm-load-causes-panel" class="mmi-hidden"
             data-load="<?php echo esc_attr(number_format($load['1min'], 2)); ?>"></div>
    <?php endif; ?>

    <!-- Current Server Status -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-dashboard"></span>
            Current Server Status
        </h2>
        
        <div id="rtsm-dashboard-stats" class="mmi-panel-stat-grid four-columns">
            <?php
            $load1 = $load['1min'];
            $load5 = $load['5min'];
            $load15 = $load['15min'];
            include RTSM_PLUGIN_DIR . 'templates/partials/stat-load.php';
            
            include RTSM_PLUGIN_DIR . 'templates/partials/stat-cpu.php';
            
            $memory_percent = $memory['percent'];
            $memory_used = $memory['used'];
            $memory_total = $memory['total'];
            include RTSM_PLUGIN_DIR . 'templates/partials/stat-memory.php';
            
            include RTSM_PLUGIN_DIR . 'templates/partials/stat-connections.php';
            ?>
        </div>
    </div>

    <!-- Platform Information -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-admin-site-alt3"></span>
            Platform Information
        </h2>
        
        <div class="rtsm-grid-3col">
            <div>
                <strong>Hosting Platform:</strong><br>
                <span class="platform-highlight">
                    <?php echo esc_html($platform_info['platform_name']); ?>
                </span>
            </div>
            <div>
                <strong>Web Server:</strong><br>
                <?php echo esc_html(!empty($platform_info['server_software']) ? $platform_info['server_software'] : 'Not detected'); ?>
                <?php if (!empty($platform_info['os_name'])): ?>
                    <br><span class="mmi-rtsm-meta-sub"><?php echo esc_html($platform_info['os_name']); ?>&nbsp;<?php echo esc_html($platform_info['kernel'] ?? ''); ?></span>
                <?php endif; ?>
            </div>
            <div>
                <strong>PHP Version:</strong><br>
                <?php echo esc_html($platform_info['php_version']); ?>
                <?php if (!empty($platform_info['php_sapi'])): ?>
                    <br><span class="mmi-rtsm-meta-sub">SAPI: <?php echo esc_html($platform_info['php_sapi']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($platform_info['uptime']) || !empty($platform_info['hostname'])): ?>
        <div class="rtsm-grid-2col rtsm-mt-16">
            <?php if (!empty($platform_info['hostname'])): ?>
            <div>
                <strong>Server Hostname:</strong><br>
                <span class="mmi-rtsm-mono-value"><?php echo esc_html($platform_info['hostname']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($platform_info['uptime'])): ?>
            <div>
                <strong>Server Uptime:</strong><br>
                <?php echo esc_html($platform_info['uptime']); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="capabilities-box">
            <h3>Available Capabilities</h3>
            <div class="capabilities-badges">
                <?php foreach ($platform_info['capabilities'] as $capability => $available): ?>
                    <span class="mmi-badge <?php echo $available ? 'success' : 'error'; ?>">
                        <?php echo $available ? '✓' : '✗'; ?> 
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $capability))); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Cloudflare Integration promo (only shown when plugin is not active) -->
    <?php if ( ! class_exists('MMI_CF_API') ): ?>
    <div class="rtsm-info-box m-top-large">
        <p class="margin-none">
            <strong>💡 Extend your monitoring:</strong> The <strong>MMI Cloudflare Integration</strong> plugin adds bot attack detection, Under Attack Mode automation, and traffic diagnostics to this dashboard.
            <a href="<?php echo esc_url( admin_url('admin.php?page=mmi-dashboard') ); ?>">Learn more →</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Auto-refresh indicator -->
    <div class="rtsm-info-box m-top-xlarge text-center">
        Auto-refreshing every <strong>30 seconds</strong>
        <span id="rtsm-last-update" class="m-left-medium">(Last updated: <?php echo current_time('H:i:s'); ?>)</span>
    </div>
</div>

