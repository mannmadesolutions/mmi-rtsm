<?php
/**
 * Template partial: PHP workers stat box
 * 
 * @var int $active Active workers
 * @var int $max Max workers
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$active = $active ?? 0;
$max    = $max    ?? 0;

echo RTSM_UI_Helpers::render_stat_box([
    'label' => 'PHP Workers',
    'value' => esc_html($active) . ' / ' . esc_html($max),
    'subtitle' => 'Active / Maximum',
    'color' => '#2271b1',
    'classes' => 'info'
]);
