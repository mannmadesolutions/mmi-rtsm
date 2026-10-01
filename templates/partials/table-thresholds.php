<?php
/**
 * Template partial: Performance thresholds table
 * 
 * @var array $stats Server statistics
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$stats = $stats ?? [];
$load1 = $stats['load']['1min'] ?? 0.0;
$cpu   = $stats['cpu_usage']    ?? 0.0;
$mem   = $stats['memory']['percent'] ?? 0.0;
?>
<table class="widefat">
    <thead>
        <tr>
            <th>Metric</th>
            <th>Normal</th>
            <th>Warning</th>
            <th>Critical</th>
            <th>Current Status</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Load Average (1m)</strong></td>
            <td>&lt; 4.0</td>
            <td>4.0 - 6.0</td>
            <td>&gt; 6.0</td>
            <td>
                <?php echo wp_kses_post(
                    RTSM_UI_Helpers::get_threshold_status($load1, 4.0, 6.0, '%.2f')
                ); ?>
            </td>
        </tr>
        <tr>
            <td><strong>CPU Usage</strong></td>
            <td>&lt; 60%</td>
            <td>60% - 80%</td>
            <td>&gt; 80%</td>
            <td>
                <?php echo wp_kses_post(
                    RTSM_UI_Helpers::get_threshold_status($cpu, 60.0, 80.0, '%.2f', '%')
                ); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Memory Usage</strong></td>
            <td>&lt; 75%</td>
            <td>75% - 90%</td>
            <td>&gt; 90%</td>
            <td>
                <?php echo wp_kses_post(
                    RTSM_UI_Helpers::get_threshold_status($mem, 75.0, 90.0, '%.2f', '%')
                ); ?>
            </td>
        </tr>
    </tbody>
</table>
