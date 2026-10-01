<?php
/**
 * Template partial: Connections stat box
 * 
 * @var int|array $connections Connection count or array with 'count' key
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$connections = $connections ?? 0;

$count = is_array($connections) ? $connections['count'] : $connections;
echo RTSM_UI_Helpers::render_stat_box([
    'id'       => 'rtsm-stat-connections',
    'label'    => 'Connections',
    'value'    => number_format($count),
    'subtitle' => 'Active connections',
    'color'    => '#45852C',
    'classes'  => 'rtsm-stat-normal',
]);
