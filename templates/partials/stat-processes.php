<?php
/**
 * Template partial: Process count stat box
 * 
 * @var int $process_count Total process count
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$process_count = $process_count ?? 0;

echo RTSM_UI_Helpers::render_stat_box([
    'label' => 'Total Processes',
    'value' => esc_html($process_count),
    'subtitle' => 'Running system processes',
    'color' => '#2271b1'
]);
