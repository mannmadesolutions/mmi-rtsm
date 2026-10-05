<?php
/**
 * Template partial: Active incident card
 * 
 * @var array $active_incident Incident data
 */

if (!defined('ABSPATH')) {
    exit;
}

// Always set by dashboard.php (its only caller, only when $has_active_incident
// is true) before including this file — guarded here anyway so this file is
// self-consistent for static analysis.
$active_incident = $active_incident ?? [];
?>
<div class="mmi-process-section rtsm-incident-section">
    <?php echo RTSM_UI_Helpers::section_header(
        'warning',
        'Active Incident in Progress',
        'Recorded when load crossed the incident threshold. Clears itself when load normalises.',
        ' <span class="mmi-badge error">' . esc_html(strtoupper($active_incident['severity'] ?? '')) . '</span>',
        '<button type="button" class="button button-secondary rtsm-clear-incident"><span class="dashicons dashicons-dismiss"></span> Clear incident now</button>'
    ); ?>
    <div class="mmi-section-content">
    <div class="mmi-stats-grid rtsm-text-tiles">
        <?php
        echo RTSM_UI_Helpers::render_stat_box([
            'label'    => 'Started',
            'value'    => esc_html($active_incident['started_at'] ?? ''),
            'subtitle' => esc_html(human_time_diff($active_incident['timestamp'] ?? time())) . ' ago',
        ]);
        echo RTSM_UI_Helpers::render_stat_box([
            'label'   => 'Peak Load',
            'value'   => esc_html(number_format((float) ($active_incident['load'] ?? 0), 2)),
            'variant' => 'error',
        ]);
        echo RTSM_UI_Helpers::render_stat_box([
            'label' => 'PHP Processes',
            'value' => esc_html($active_incident['php_processes'] ?? ''),
        ]);
        echo RTSM_UI_Helpers::render_stat_box([
            'label' => 'Client IP',
            'value' => '<code>' . esc_html($active_incident['client_ip'] ?? '') . '</code>',
            'subtitle' => '<code>' . esc_html($active_incident['request_uri'] ?? '') . '</code>',
        ]);
        ?>
    </div>

    <?php
    /* ── Cause summary captured at incident start ───────────── */
    $inc_causes = $active_incident['causes'] ?? [];
    $inc_cf     = $inc_causes['cf']          ?? [];
    $inc_thr    = $inc_causes['throttler']   ?? [];
    $inc_attr   = $inc_causes['attribution'] ?? [];
    $inc_cron   = $inc_causes['wpcron']      ?? [];

    $cause_lines = [];
    if (!empty($inc_cf['active'])) {
        $label = $inc_cf['auto_escalated'] ? 'CF Under Attack (auto-escalated by RTSM)' : 'CF Under Attack (manual)';
        $cause_lines[] = '<span class="dashicons dashicons-shield-alt"></span> ' . esc_html($label);
    }
    if (!empty($inc_thr['active'])) {
        $cause_lines[] = '<span class="dashicons dashicons-controls-pause"></span> '
            . esc_html($inc_thr['running_count']) . ' background process(es) running, '
            . esc_html($inc_thr['throttled_count']) . ' throttled';
    }
    if (($inc_cron['overdue_count'] ?? 0) > 0) {
        $cause_lines[] = '<span class="dashicons dashicons-clock"></span> '
            . esc_html($inc_cron['overdue_count']) . ' overdue WP-Cron task(s)';
    }
    if (($inc_attr['web_cpu'] ?? 0) >= 50) {
        $cause_lines[] = '<span class="dashicons dashicons-chart-area"></span> High web traffic — '
            . esc_html(number_format($inc_attr['web_cpu'], 1)) . '% web CPU';
    }
    if (($inc_attr['dev_cpu'] ?? 0) >= 5) {
        $cause_lines[] = '<span class="dashicons dashicons-editor-code"></span> Dev tools — '
            . esc_html(number_format($inc_attr['dev_cpu'], 1)) . '% CPU';
    }

    if (!empty($cause_lines)):
    ?>
    <div class="mmi-info-card warning">
        <h3>Identified causes at incident start</h3>
        <ul class="rtsm-icon-list">
            <?php foreach ($cause_lines as $line): ?>
                <li><?php echo wp_kses($line, ['span' => ['class' => []]]); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    </div>
</div>
