<?php
/**
 * Template partial: Server uptime stat box
 * 
 * @var string $uptime Formatted uptime
 * @var string $last_boot Last boot time
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$uptime    = $uptime    ?? '';
$last_boot = $last_boot ?? 'N/A';

echo RTSM_UI_Helpers::render_stat_box([
    'label' => 'Server Uptime',
    'value' => esc_html($uptime),
    'subtitle' => ($last_boot !== 'N/A') ? 'Last boot: ' . esc_html($last_boot) : 'Uptime data requires /proc access',
    'color' => '#45852C',
    'classes' => 'success'
]);
