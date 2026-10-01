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
<div class="mmi-panel-card card-active-incident">
    <h2 class="status-critical">
        <span class="dashicons dashicons-warning"></span>
        Active Incident in Progress
    </h2>
    <div class="mmi-panel-stat-grid four-columns">
        <div class="mmi-panel-stat-box">
            <div class="stat-label">Started</div>
            <div class="stat-value"><?php echo esc_html($active_incident['started_at']); ?></div>
            <div class="stat-sublabel"><?php echo esc_html(human_time_diff($active_incident['timestamp'])); ?> ago</div>
        </div>
        <div class="mmi-panel-stat-box">
            <div class="stat-label">Severity</div>
            <div class="stat-value status-critical"><?php echo esc_html(strtoupper($active_incident['severity'])); ?></div>
        </div>
        <div class="mmi-panel-stat-box">
            <div class="stat-label">Peak Load</div>
            <div class="stat-value"><?php echo esc_html(number_format($active_incident['load'], 2)); ?></div>
        </div>
        <div class="mmi-panel-stat-box">
            <div class="stat-label">PHP Processes</div>
            <div class="stat-value"><?php echo esc_html($active_incident['php_processes']); ?></div>
        </div>
    </div>
    <p class="m-top-large text-secondary">
        <strong>Client IP:</strong> <?php echo esc_html($active_incident['client_ip']); ?> |
        <strong>URI:</strong> <code><?php echo esc_html($active_incident['request_uri']); ?></code>
    </p>

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
    <div class="rtsm-incident-causes m-top-medium">
        <strong>Identified causes at incident start:</strong>
        <ul class="rtsm-cause-list rtsm-mt-6">
            <?php foreach ($cause_lines as $line): ?>
                <li class="rtsm-cause-item"><?php echo wp_kses($line, ['span' => ['class' => []]]); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <p class="m-top-small">
        <em>This card auto-dismisses when load normalises.</em>&nbsp;
        <button type="button" id="rtsm-clear-incident-card-btn"
            data-nonce="<?php echo esc_attr(wp_create_nonce('rtsm_nonce')); ?>"
            data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            class="button button-small button-secondary">Clear incident now</button>
    </p>
</div>
<script>
(function(){
    var btn = document.getElementById('rtsm-clear-incident-card-btn');
    if (!btn) return;
    btn.addEventListener('click', function(){
        btn.disabled = true;
        btn.textContent = 'Clearing\u2026';
        var fd = new FormData();
        fd.append('action', 'rtsm_resolve_incident');
        fd.append('nonce', btn.dataset.nonce);
        fetch(btn.dataset.ajax, {method:'POST', body:fd, credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(){
                var card = btn.closest('.card-active-incident');
                if (card) card.remove();
                // Also remove the top-of-page admin notice if present.
                var notice = document.getElementById('mmi-incident-notice');
                if (notice) notice.remove();
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = 'Clear incident now';
            });
    });
})();
</script>
