<?php
/**
 * Template partial: Visitors stat box (RTSM_Active_Visitors via get_connections())
 *
 * @var int|array $connections Visitor count, or the array get_connections() returns
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$connections = $connections ?? 0;

$count = is_array($connections) ? (int) ($connections['count'] ?? 0) : (int) $connections;
$note  = is_array($connections) ? (string) ($connections['note'] ?? '') : '';
$box   = RTSM_UI_Helpers::render_stat_box([
    'id'       => 'rtsm-stat-connections',
    'label'    => __('Visitors', 'mmi-rtsm'),
    'value'    => number_format($count),
    'subtitle' => esc_html($note !== '' ? $note : __('Last minute', 'mmi-rtsm')),
]);
// The tile's tooltip says where the number comes from.
$explain = is_array($connections) ? (string) ($connections['explain'] ?? '') : '';
if ($explain !== '') {
    $box = preg_replace('/^<div /', '<div title="' . esc_attr($explain) . '" ', ltrim($box), 1);
}
echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
