<?php
/**
 * Template partial: Load status stat box
 * 
 * @var float $load1 Load average (1 minute)
 * @var float $load5 Load average (5 minutes)
 * @var float $load15 Load average (15 minutes)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$load1  = $load1  ?? 0.0;
$load5  = $load5  ?? 0.0;
$load15 = $load15 ?? 0.0;

$status      = RTSM_UI_Helpers::get_load_status($load1);
// Scale load 0-12 to 0-100% (≥12 is pegged at 100%)
$load_pct    = min(($load1 / 12) * 100, 100);
echo RTSM_UI_Helpers::render_stat_box([
    'id'            => 'rtsm-stat-load',
    'label'         => 'Load Average (1 min)',
    'value'         => number_format($load1, 2),
    'subtitle'      => sprintf('5 min: %s | 15 min: %s', number_format($load5, 2), number_format($load15, 2)),
    'variant'       => $status['variant'],
    'progress'      => $load_pct,
]);
