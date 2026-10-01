<?php
/**
 * RTSM Activity Logs Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

$log_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-traffic-analysis.log';
$alert_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-alerts.log';

// Get recent log entries
$traffic_logs = [];
$alert_logs = [];

if (file_exists($log_file)) {
    $traffic_logs = array_reverse(array_slice(file($log_file), -50));
}

if (file_exists($alert_file)) {
    $alert_logs = array_reverse(array_slice(file($alert_file), -50));
}
?>

<div class="mmi-logs-content">
    <!-- Alert Logs -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-megaphone"></span>
            System Alerts
            <?php if (!empty($alert_logs)): ?>
                <span class="mmi-badge warning"><?php echo esc_html(count($alert_logs)); ?> recent</span>
            <?php endif; ?>
        </h2>
        
        <?php if (empty($alert_logs)): ?>
            <div class="no-logs-notice">
                <span class="dashicons dashicons-yes-alt"></span>
                <p>No alerts recorded. Your server is running smoothly!</p>
            </div>
        <?php else: ?>
            <div class="mmi-toolbar">
                <button type="button" class="button button-secondary mmi-action-btn" id="download-alert-log-page">
                    <span class="dashicons dashicons-download"></span> Download Full Log
                </button>
                <button type="button" class="button button-secondary mmi-action-btn" id="clear-alert-log-page">
                    <span class="dashicons dashicons-trash"></span> Clear All Alerts
                </button>
                <span class="log-count">Showing last 50 entries</span>
            </div>
            
            <div class="log-viewer">
                <?php foreach ($alert_logs as $log): ?>
                    <div class="log-entry <?php echo esc_attr(RTSM_UI_Helpers::get_alert_severity_class($log)); ?>">
                        <?php echo esc_html(rtrim($log)); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Traffic Logs -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-networking"></span>
            Traffic Logs
            <?php if (!empty($traffic_logs)): ?>
                <span class="mmi-badge"><?php echo esc_html(count($traffic_logs)); ?> recent</span>
            <?php endif; ?>
        </h2>
        
        <?php if (empty($traffic_logs)): ?>
            <div class="no-logs-notice">
                <span class="dashicons dashicons-visibility"></span>
                <p>No traffic logged yet. Logs will appear when server load exceeds 5.0</p>
            </div>
        <?php else: ?>
            <div class="mmi-toolbar">
                <button type="button" class="button button-secondary mmi-action-btn" id="download-traffic-log-page">
                    <span class="dashicons dashicons-download"></span> Download Full Log
                </button>
                <button type="button" class="button button-secondary mmi-action-btn" id="clear-traffic-log-page">
                    <span class="dashicons dashicons-trash"></span> Clear Traffic Logs
                </button>
                <span class="log-count">Showing last 50 entries</span>
            </div>
            
            <div class="log-viewer">
                <?php foreach ($traffic_logs as $log): 
                    $severity_class = '';
                    $load_match = [];
                    if (preg_match('/Load:\s*([\d\.]+)/', $log, $load_match)) {
                        $load_value = floatval($load_match[1]);
                        if ($load_value >= 10.0) {
                            $severity_class = 'log-entry-critical';
                        } elseif ($load_value >= 7.0) {
                            $severity_class = 'log-entry-warning';
                        }
                    }
                ?>
                    <div class="log-entry <?php echo esc_attr($severity_class); ?>">
                        <?php echo esc_html(rtrim($log)); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Log File Information -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-media-document"></span>
            Log File Information
        </h2>
        
        <table class="mmi-uniform-table mmi-uniform-table--hoverable">
            <thead>
                <tr>
                    <th>Log Type</th>
                    <th>File Path</th>
                    <th>Size</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Traffic Analysis</strong></td>
                    <td><code><?php echo esc_html($log_file); ?></code></td>
                    <td><?php echo file_exists($log_file) ? size_format(filesize($log_file)) : '—'; ?></td>
                    <td>
                        <?php if (file_exists($log_file)): ?>
                            <span class="mmi-badge success">✓ Active</span>
                        <?php else: ?>
                            <span class="mmi-badge">No data</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>System Alerts</strong></td>
                    <td><code><?php echo esc_html($alert_file); ?></code></td>
                    <td><?php echo file_exists($alert_file) ? size_format(filesize($alert_file)) : '—'; ?></td>
                    <td>
                        <?php if (file_exists($alert_file)): ?>
                            <span class="mmi-badge success">✓ Active</span>
                        <?php else: ?>
                            <span class="mmi-badge">No data</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

