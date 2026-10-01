<?php
/**
 * RTSM Process Monitor Tab
 * Pro feature - requires Pro license or higher
 */

if (!defined('ABSPATH')) {
    exit;
}

$license_manager = RTSM_License_Manager::get_instance();
$has_pro_access = $license_manager->is_licensed();

if (!$has_pro_access) {
    // Generate obfuscated preview content matching real process monitor layout
    $preview_content = '
        <div class="mmi-panel-card">
            <h2>
                <span class="dashicons dashicons-yes-alt"></span>
                Process Monitor
            </h2>
            <div class="process-filters" class="rtsm-mb-16">
                <button type="button" class="button button-secondary">All Processes</button>
                <button type="button" class="button button-secondary">PHP</button>
                <button type="button" class="button button-secondary">MySQL</button>
                <button type="button" class="button button-secondary">Node.js</button>
                <button type="button" class="button button-secondary">Python</button>
                <button type="button" class="button button-secondary">High CPU</button>
                <button type="button" class="button button-primary mmi-action-btn"><span class="dashicons dashicons-update"></span> Refresh</button>
            </div>
            <table class="mmi-uniform-table mmi-uniform-table--hoverable rtsm-mt-12">
                <thead>
                    <tr>
                        <th>PID</th>
                        <th>User</th>
                        <th>CPU</th>
                        <th>Mem</th>
                        <th>Time</th>
                        <th>Command</th>
                        <th>Kill</th>
                    </tr>
                </thead>
                <tbody>'
                    . str_repeat('<tr>
                        <td>████</td>
                        <td>████████</td>
                        <td>██.█%</td>
                        <td>█.█%</td>
                        <td>██:██</td>
                        <td>████████████████████████████████</td>
                        <td>🔒</td>
                    </tr>', 10) . '
                </tbody>
            </table>
        </div>
    ';

    // Render unified premium overlay (same pattern as Diagnostics tab)
    echo RTSM_UI_Helpers::render_premium_overlay([
        'feature_name'    => 'Process Monitor',
        'description'     => 'Monitor and manage server processes in real-time with advanced filtering and kill capabilities. Requires an active MMI Suite license.',
        'features'        => [
            '<strong>Real-time Process List</strong> — View all running processes with CPU, memory, and runtime details',
            '<strong>Process Filtering</strong> — Filter by PHP, MySQL, Node.js, Python, or high CPU usage',
            '<strong>Process Termination</strong> — Graceful and force kill capabilities for runaway processes',
            '<strong>Auto-remediation</strong> — Configure automatic responses to high-resource processes',
        ],
        'preview_content' => $preview_content,
    ]);

    return;
}
?>

<div class="mmi-processes-content">
    <!-- Pro Features Unlocked -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-yes-alt"></span>
            Process Monitor
        </h2>

        <div class="process-filters">
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="all">All Processes</button>
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="php">PHP</button>
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="mysql">MySQL</button>
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="node">Node.js</button>
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="python">Python</button>
            <button type="button" class="button button-secondary rtsm-process-filter" data-filter="high_cpu">High CPU</button>
            <button type="button" class="button button-primary mmi-action-btn" id="refresh-processes">
                <span class="dashicons dashicons-update"></span> Refresh
            </button>
        </div>

        <div id="processes-container" class="processes-loading">
            <span class="mmi-spinner" class="rtsm-spinner-xl"></span>
            <p>Loading processes...</p>
        </div>
    </div>
</div>
