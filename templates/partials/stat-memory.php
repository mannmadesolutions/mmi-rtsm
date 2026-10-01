<?php
/**
 * Template partial: Memory usage stat box
 * 
 * @var float $memory_percent Memory usage percentage
 * @var string $memory_used Memory used (formatted)
 * @var string $memory_total Memory total (formatted)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$memory_percent = $memory_percent ?? 0.0;
$memory_used    = $memory_used    ?? '';
$memory_total   = $memory_total   ?? '';

$status = RTSM_UI_Helpers::get_memory_status($memory_percent);
echo RTSM_UI_Helpers::render_stat_box([
    'id'            => 'rtsm-stat-memory',
    'label'         => 'Memory',
    'value'         => number_format($memory_percent, 2) . '%',
    'subtitle'      => sprintf('%s MB / %s MB', number_format($memory_used, 0), number_format($memory_total, 0)),
    'color'         => $status['color'],
    'icon'          => $status['icon'] . ' ',
    'classes'       => $status['class'],
    'progress'      => $memory_percent,
    'progress_color'=> $status['color'],
]);
