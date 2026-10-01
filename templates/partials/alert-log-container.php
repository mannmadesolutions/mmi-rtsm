<?php
/**
 * Template partial: Alert log container
 * 
 * @var array $recent_alerts Recent alert lines
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again.
$recent_alerts = $recent_alerts ?? [];
?>
<div class="alert-log-container">
    <?php foreach ($recent_alerts as $alert): ?>
        <div class="alert-line <?php echo esc_attr(RTSM_UI_Helpers::get_alert_severity_class($alert)); ?>">
            <?php echo esc_html(rtrim($alert)); ?>
        </div>
    <?php endforeach; ?>
</div>
