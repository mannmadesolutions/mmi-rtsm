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

// Check for active incidents — same resolver the traffic logger writes to.
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

<div class="rtsm-dashboard">
    <?php if ($has_active_incident): ?>
        <?php include RTSM_PLUGIN_DIR . 'templates/partials/card-active-incident.php'; ?>
    <?php endif; ?>

    <?php if ($show_causes): ?>
        <?php
        $load1 = $load['1min'];
        include RTSM_PLUGIN_DIR . 'templates/partials/card-load-causes.php';
        ?>
    <?php else: ?>
        <!-- Placeholder keeps the JS refresh target in the DOM at all times -->
        <div id="rtsm-load-causes-panel" class="mmi-process-section" hidden
             data-load="<?php echo esc_attr(number_format($load['1min'], 2)); ?>"></div>
    <?php endif; ?>

    <div class="mmi-process-section">
        <?php echo RTSM_UI_Helpers::section_header(
            'dashboard',
            'Current Server Status',
            'Load, CPU, memory and visitors, refreshed live.',
            '',
            '<span id="rtsm-last-update" class="mmi-text-muted">Last updated ' . esc_html(current_time('H:i:s')) . '</span>'
        ); ?>
        <div class="mmi-section-content">
            <div id="rtsm-dashboard-stats" class="mmi-stats-grid">
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
    </div>

    <?php
    // Visitors table (assets/js/visitors-table.js fills it from rtsm_get_visitors).
    $rtsm_visitor_actions = '<button type="button" class="button" id="rtsm-visitors-copy-ips" disabled>'
        . '<span class="dashicons dashicons-admin-page"></span> Copy addresses</button> '
        . '<button type="button" class="button button-primary" id="rtsm-visitors-copy-rule" disabled>'
        . '<span class="dashicons dashicons-shield"></span> Copy Cloudflare rule</button>';
    ?>
    <div class="mmi-process-section" id="rtsm-visitors">
        <?php echo RTSM_UI_Helpers::section_header(
            'groups',
            'Visitors',
            'Who reached the site and what they did. Each address is labelled from its last 6 hours.',
            '',
            $rtsm_visitor_actions
        ); ?>
        <div class="mmi-section-content">
            <div class="rtsm-visitors-toolbar">
                <div class="mmi-filter-pills" data-rtsm-visitors="window" role="group" aria-label="Time range">
                    <button type="button" class="mmi-pill" data-value="900">Last 15 min</button>
                    <button type="button" class="mmi-pill active" data-value="3600">Last hour</button>
                    <button type="button" class="mmi-pill" data-value="21600">Last 6 hours</button>
                    <button type="button" class="mmi-pill" data-value="86400">Last 24 hours</button>
                </div>
                <div class="mmi-filter-pills" data-rtsm-visitors="level" role="group" aria-label="Label">
                    <button type="button" class="mmi-pill active" data-value="all">All <span class="mmi-pill-count" data-count="all"></span></button>
                    <button type="button" class="mmi-pill" data-value="bad" title="Asked for paths no visitor asks for, made five or more login attempts, or claims to be a search engine and isn't">Likely bad <span class="mmi-pill-count" data-count="bad"></span></button>
                    <button type="button" class="mmi-pill" data-value="watch" title="Scripts, crawlers that name themselves, and heavy page rates">Watch <span class="mmi-pill-count" data-count="watch"></span></button>
                    <button type="button" class="mmi-pill" data-value="verified" title="Google, Bing, Apple, Yandex or Baidu, confirmed by reverse DNS">Verified crawlers <span class="mmi-pill-count" data-count="verified"></span></button>
                    <button type="button" class="mmi-pill" data-value="none" title="Nothing unusual">No label <span class="mmi-pill-count" data-count="none"></span></button>
                </div>
            </div>
            <p class="mmi-hint-text" id="rtsm-visitors-note">Loading visitors…</p>
            <div id="rtsm-visitors-notice" aria-live="polite"></div>
            <div class="mmi-table-scroll-wrapper rtsm-visitors-scroll">
                <table class="mmi-uniform-table" id="rtsm-visitors-table">
                    <thead>
                        <tr>
                            <th class="check-column"><input type="checkbox" id="rtsm-visitors-all" aria-label="Select all shown"></th>
                            <th>Address</th>
                            <th>Label</th>
                            <th>Why</th>
                            <th>Requests</th>
                            <th>Pages</th>
                            <th>Errors</th>
                            <th>Last seen</th>
                            <th class="rtsm-visitors-network" hidden>Network</th>
                            <th>User agent</th>
                            <th class="column-actions">Look up</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mmi-process-section">
        <?php echo RTSM_UI_Helpers::section_header('admin-site-alt3', 'Platform Information', 'What this server is running and which system calls RTSM can use.'); ?>
        <div class="mmi-section-content">
            <div class="mmi-stats-grid rtsm-text-tiles">
                <?php
                echo RTSM_UI_Helpers::render_stat_box([
                    'label' => 'Hosting Platform',
                    'value' => esc_html($platform_info['platform_name']),
                ]);
                echo RTSM_UI_Helpers::render_stat_box([
                    'label'    => 'Web Server',
                    'value'    => esc_html(!empty($platform_info['server_software']) ? $platform_info['server_software'] : 'Not detected'),
                    'subtitle' => !empty($platform_info['os_name']) ? esc_html(trim($platform_info['os_name'] . ' ' . ($platform_info['kernel'] ?? ''))) : '',
                ]);
                echo RTSM_UI_Helpers::render_stat_box([
                    'label'    => 'PHP Version',
                    'value'    => esc_html($platform_info['php_version']),
                    'subtitle' => !empty($platform_info['php_sapi']) ? 'SAPI: ' . esc_html($platform_info['php_sapi']) : '',
                ]);
                if (!empty($platform_info['hostname'])) {
                    echo RTSM_UI_Helpers::render_stat_box([
                        'label'    => 'Server Hostname',
                        'value'    => '<code>' . esc_html($platform_info['hostname']) . '</code>',
                        'subtitle' => !empty($platform_info['uptime']) ? 'Up ' . esc_html($platform_info['uptime']) : '',
                    ]);
                }
                ?>
            </div>

            <h4>Available Capabilities</h4>
            <div class="rtsm-badge-row">
                <?php foreach ($platform_info['capabilities'] as $capability => $available): ?>
                    <span class="mmi-badge <?php echo $available ? 'success' : 'error'; ?>">
                        <?php echo $available ? '✓' : '✗'; ?>
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $capability))); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
