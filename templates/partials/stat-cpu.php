<?php
/**
 * Template partial: CPU usage stat box
 * 
 * @var float $cpu CPU usage percentage
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$cpu = $cpu ?? 0.0;

$status   = RTSM_UI_Helpers::get_cpu_status($cpu);
// Check whether reading came from /proc/stat or was estimated from load
$proc_available = file_exists('/proc/stat');
$subtitle       = $proc_available
    ? 'Current processor utilization'
    : 'Current processor utilization (~est. from load)';
echo RTSM_UI_Helpers::render_stat_box([
    'id'            => 'rtsm-stat-cpu',
    'label'         => 'CPU Usage',
    'value'         => number_format($cpu, 2) . '%',
    'subtitle'      => $subtitle,
    'variant'       => $status['variant'],
    'progress'      => min($cpu, 100),
]);
