<?php
/**
 * Template partial: Disk usage stat box
 * 
 * @var string $disk_usage Disk usage percentage
 * @var string $disk_free Free disk space (formatted)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$disk_usage = $disk_usage ?? '';
$disk_free  = $disk_free  ?? '';

echo RTSM_UI_Helpers::render_stat_box([
    'label' => 'Disk Usage',
    'value' => esc_html($disk_usage) . '%',
    'subtitle' => esc_html($disk_free) . ' available',
    'color' => '#f0b849',
    'classes' => 'warning'
]);
