<?php
/**
 * Template partial: Load Cause Analysis card
 *
 * Explains WHY server load is elevated and provides actionable steps.
 * Rendered server-side on initial page load; JS layer refreshes the
 * #rtsm-load-causes-panel element from the load_causes AJAX payload.
 *
 * @var array $load_causes  Result of RTSM_Server_Monitor::get_load_cause_analysis()
 * @var float $load1        Current 1-min load average
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Always set by dashboard.php (its only caller) before including this file
// — guarded here anyway so this file is self-consistent for static analysis.
$load_causes = $load_causes ?? [];
$load1       = $load1       ?? 0.0;

$throttler   = $load_causes['throttler']        ?? [];
$attribution = $load_causes['attribution']      ?? [];
$wpcron      = $load_causes['wpcron']           ?? [];
$mem_pressure= $load_causes['memory_pressure']  ?? [];

/* ── Condition flags ── */
$has_throttler      = ! empty( $throttler['active'] );
$has_dev_load       = ( $attribution['dev_cpu'] ?? 0 ) >= 5;
$has_backup_load    = ( $attribution['backup_cpu'] ?? 0 ) >= 10;
$has_cron_overdue   = ( $wpcron['overdue_count'] ?? 0 ) > 0;
$has_web_spike      = ( $attribution['web_cpu'] ?? 0 ) >= 50;
$has_mem_pressure   = ! empty( $mem_pressure['high'] );
$has_any_cause      = $has_throttler || $has_dev_load
                    || $has_backup_load || $has_cron_overdue || $has_web_spike || $has_mem_pressure;

/* ── Overall severity bar (load 0-12 mapped to 0-100%) ── */
$load_val      = (float) ( $load1 ?? 0 );
$severity_pct  = min( ( $load_val / 12 ) * 100, 100 );
if ( $load_val >= 8.0 )      { $severity_label = 'Critical'; $severity_variant = 'error'; }
elseif ( $load_val >= 6.0 )  { $severity_label = 'High';     $severity_variant = 'warning'; }
elseif ( $load_val >= 4.0 )  { $severity_label = 'Elevated'; $severity_variant = 'warning'; }
else                          { $severity_label = 'Normal';   $severity_variant = 'success'; }

/* ── Admin page URLs ── */
$throttler_url  = admin_url( 'tools.php?page=wp-throttle' );
$traffic_url    = admin_url( 'admin.php?page=mmi-rtsm&tab=traffic' );
$logs_url       = admin_url( 'admin.php?page=mmi-rtsm&tab=logs' );
$processes_url  = admin_url( 'admin.php?page=mmi-rtsm&tab=processes' );
?>
<div id="rtsm-load-causes-panel" class="mmi-process-section"
     data-load="<?php echo esc_attr( number_format( $load_val, 2 ) ); ?>">
    <?php echo RTSM_UI_Helpers::section_header(
        'performance',
        'Load Cause Analysis',
        'Why load is elevated and what to do about it. Refreshes live.',
        '',
        '<a href="' . esc_url( $logs_url ) . '" class="button button-secondary"><span class="dashicons dashicons-media-text"></span> View Activity Logs</a>'
    ); ?>
    <div class="mmi-section-content">
    <div class="rtsm-severity">
        <div class="rtsm-severity-label mmi-text-<?php echo esc_attr( $severity_variant ); ?>">
            <?php echo esc_html( $severity_label ); ?> — Load <?php echo esc_html( number_format( $load_val, 2 ) ); ?>
        </div>
        <?php echo RTSM_UI_Helpers::progress_bar( $severity_pct, $severity_variant ); ?>
    </div>

    <?php if ( ! $has_any_cause ) : ?>
        <p class="rtsm-cause-none">
            <span class="dashicons dashicons-yes-alt mmi-text-success"></span>
            No specific cause identified — load may be transient or within normal variance.
        </p>

    <?php else : ?>
        <div class="rtsm-cause-list">

            <?php /* ── 2. Background Processes (Warning — running processes drive load) ── */ ?>
            <?php if ( $has_throttler ) : ?>
                <?php
                    $stuck_count = (int)( $throttler['stuck_count'] ?? 0 );
                    $item_class  = $stuck_count > 0 ? 'error inline' : 'warning';
                ?>
                <div class="mmi-info-card <?php echo esc_attr( $item_class ); ?>">
                    <h3>
                        <span class="dashicons dashicons-controls-pause"></span>
                        <strong>Background Processes</strong>
                        <span class="mmi-badge warning">
                            <?php echo esc_html( $throttler['running_count'] ?? 0 ); ?> running
                            <?php if ( ( $throttler['throttled_count'] ?? 0 ) > 0 ) : ?>
                                , <?php echo esc_html( $throttler['throttled_count'] ); ?> throttled
                            <?php endif; ?>
                        </span>
                        <?php if ( $stuck_count > 0 ) : ?>
                            <span class="mmi-badge error">⚠ <?php echo esc_html( $stuck_count ); ?> stuck</span>
                        <?php endif; ?>
                    </h3>
                    <?php if ( ! empty( $throttler['processes'] ) ) : ?>
                        <div class="rtsm-cause-detail">
                            <ul>
                                <?php foreach ( array_slice( $throttler['processes'], 0, 6 ) as $proc ) :
                                    $elapsed     = $proc['elapsed_sec'] ?? null;
                                    $is_stuck    = ! empty( $proc['is_stuck'] );
                                    $pause_count = (int)( $proc['pause_count'] ?? 0 );
                                    // Format elapsed duration
                                    $elapsed_str = '';
                                    if ( $elapsed !== null ) {
                                        if ( $elapsed >= 3600 ) {
                                            $elapsed_str = floor( $elapsed / 3600 ) . 'h ' . floor( ( $elapsed % 3600 ) / 60 ) . 'm';
                                        } elseif ( $elapsed >= 60 ) {
                                            $elapsed_str = floor( $elapsed / 60 ) . 'm ' . ( $elapsed % 60 ) . 's';
                                        } else {
                                            $elapsed_str = $elapsed . 's';
                                        }
                                    }
                                ?>
                                    <li>
                                        <code><?php echo esc_html( $proc['name'] ); ?></code>
                                        <span class="mmi-badge <?php echo $proc['status'] === 'throttled' ? 'warning' : 'info'; ?>">
                                            <?php echo esc_html( $proc['status'] ); ?>
                                        </span>
                                        <?php if ( $elapsed_str ) : ?>
                                            <span class="mmi-text-muted">for <?php echo esc_html( $elapsed_str ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $is_stuck ) : ?>
                                            <span class="mmi-badge error">⚠ Stuck</span>
                                            <span class="mmi-text-muted">(exceeded <?php echo esc_html( floor( ( $proc['timeout_sec'] ?? 600 ) / 60 ) ); ?>m timeout)</span>
                                        <?php endif; ?>
                                        <?php if ( $pause_count > 0 ) : ?>
                                            <span class="mmi-text-muted">(paused <?php echo esc_html( $pause_count ); ?>×)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="mmi-toolbar">
                        <a href="<?php echo esc_url( $throttler_url ); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-controls-pause"></span> View Throttler
                        </a>
                        <a href="<?php echo esc_url( $processes_url ); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-list-view"></span> View Processes
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── 3. Overdue WP-Cron Jobs ── */ ?>
            <?php if ( $has_cron_overdue ) : ?>
                <?php
                    $overdue = (int)( $wpcron['overdue_count'] ?? 0 );
                    if ( $overdue >= 100 )       { $cron_level = 'error inline'; $badge_class = 'error'; }
                    elseif ( $overdue >= 10 )    { $cron_level = 'warning';  $badge_class = 'warning';  }
                    else                         { $cron_level = 'warning';  $badge_class = 'warning';  }
                ?>
                <div class="mmi-info-card <?php echo esc_attr( $cron_level ); ?>">
                    <h3>
                        <span class="dashicons dashicons-clock"></span>
                        <strong>Overdue WP-Cron Jobs</strong>
                        <span class="mmi-badge <?php echo $badge_class; ?>">
                            <?php echo esc_html( number_format( $overdue ) ); ?> overdue
                        </span>
                    </h3>
                    <div class="rtsm-cause-detail">
                        <?php if ( $overdue >= 1000 ) : ?>
                            <strong>Critical:</strong> <?php echo esc_html( number_format( $overdue ) ); ?> overdue jobs will all fire together on the next HTTP request, causing a severe load spike. Investigate immediately for stuck or runaway hooks.
                        <?php elseif ( $overdue >= 100 ) : ?>
                            <?php echo esc_html( number_format( $overdue ) ); ?> overdue jobs fire on the next incoming request and will spike PHP load. Check for stuck or runaway scheduled tasks.
                        <?php else : ?>
                            Overdue tasks fire on the next incoming request, spiking PHP load.
                        <?php endif; ?>
                        <?php if ( ! empty( $wpcron['overdue_hooks'] ) ) : ?>
                            <ul>
                                <?php foreach ( $wpcron['overdue_hooks'] as $hook_info ) :
                                    $sec = (int)$hook_info['overdue_sec'];
                                    if ( $sec >= 86400 )     $od = floor( $sec / 86400 ) . 'd ' . floor( ( $sec % 86400 ) / 3600 ) . 'h overdue';
                                    elseif ( $sec >= 3600 )  $od = floor( $sec / 3600 ) . 'h ' . floor( ( $sec % 3600 ) / 60 ) . 'm overdue';
                                    elseif ( $sec >= 60 )    $od = floor( $sec / 60 ) . 'm ' . ( $sec % 60 ) . 's overdue';
                                    else                     $od = $sec . 's overdue';
                                ?>
                                    <li>
                                        <code><?php echo esc_html( $hook_info['hook'] ); ?></code>
                                        <span class="mmi-text-muted">&mdash; <?php echo esc_html( $od ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                    <div class="mmi-toolbar">
                        <button type="button"
                                class="button button-secondary rtsm-trigger-cron"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'rtsm_nonce' ) ); ?>">
                            <span class="dashicons dashicons-update"></span> Trigger Cron Now
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>"
                           class="button button-secondary">
                            <span class="dashicons dashicons-admin-tools"></span> WP Tools
                        </a>
                        <a href="<?php echo esc_url( $logs_url ); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-text-page"></span> View Logs
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── 4. High Web Traffic (Warning / Critical) ── */ ?>
            <?php if ( $has_web_spike ) : ?>
                <div class="mmi-info-card <?php echo ( $attribution['web_cpu'] ?? 0 ) >= 150 ? 'error inline' : 'warning'; ?>">
                    <h3>
                        <span class="dashicons dashicons-chart-area"></span>
                        <strong>High Web Traffic</strong>
                        <span class="mmi-badge <?php echo ( $attribution['web_cpu'] ?? 0 ) >= 150 ? 'error' : 'warning'; ?>">
                            <?php echo esc_html( number_format( $attribution['web_cpu'] ?? 0, 1 ) ); ?>% web CPU
                        </span>
                    </h3>
                    <div class="rtsm-cause-detail">
                        PHP-FPM / web processes are consuming a high share of CPU.
                        Open Traffic Analysis to see the top URLs and IPs. If it looks like an attack,
                        block it in Cloudflare (WAF rule or Under Attack Mode in the Cloudflare dashboard).
                    </div>
                    <div class="mmi-toolbar">
                        <a href="<?php echo esc_url( $traffic_url ); ?>"
                           class="button button-secondary">
                            <span class="dashicons dashicons-chart-line"></span> Traffic Analysis
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── 5. Scheduled Backup (Info — expected, temporary) ── */ ?>
            <?php if ( $has_backup_load ) : ?>
                <?php $backup_cpu = (float)( $attribution['backup_cpu'] ?? 0 ); ?>
                <div class="mmi-info-card">
                    <h3>
                        <span class="dashicons dashicons-backup"></span>
                        <strong>Scheduled Backup Running</strong>
                        <span class="mmi-badge info">
                            <?php echo esc_html( number_format( $backup_cpu, 1 ) ); ?>% CPU
                        </span>
                    </h3>
                    <div class="rtsm-cause-detail">
                        <?php if ( ! empty( $attribution['top_backup_processes'] ) ) : ?>
                            <strong>Process<?php echo count( $attribution['top_backup_processes'] ) > 1 ? 'es' : ''; ?>:</strong>
                            <code><?php echo esc_html( implode( ', ', $attribution['top_backup_processes'] ) ); ?></code>.
                        <?php endif; ?>
                        Backup processes are CPU- and I/O-intensive but temporary. Server load will drop when the backup completes.
                        <?php if ( $backup_cpu >= 100.0 ) : ?>
                            <br><strong>Note:</strong> CPU usage exceeds one full core — consider rescheduling the backup to an off-peak window in RunCloud.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── 6. Memory Pressure ── */ ?>
            <?php if ( $has_mem_pressure ) : ?>
                <?php
                    $mem_pct       = (float)( $mem_pressure['percent'] ?? 0 );
                    $swap_used     = (int)( $mem_pressure['swap_used_mb']  ?? 0 );
                    $swap_total    = (int)( $mem_pressure['swap_total_mb'] ?? 0 );
                    $mem_level     = $mem_pct >= 95 ? 'error inline' : 'warning';
                    $mem_badge_cls = $mem_pct >= 95 ? 'error' : 'warning';
                ?>
                <div class="mmi-info-card <?php echo esc_attr( $mem_level ); ?>">
                    <h3>
                        <span class="dashicons dashicons-dashboard"></span>
                        <strong>Memory Pressure</strong>
                        <span class="mmi-badge <?php echo $mem_badge_cls; ?>">
                            <?php echo esc_html( number_format( $mem_pct, 1 ) ); ?>% used
                        </span>
                    </h3>
                    <div class="rtsm-cause-detail">
                        <?php if ( $swap_total > 0 && $swap_used > 0 ) : ?>
                            <strong>Swap active:</strong> <?php echo esc_html( number_format( $swap_used ) ); ?> MB in use
                            of <?php echo esc_html( number_format( $swap_total ) ); ?> MB.
                            Swapping inflates load averages because CPU waits for disk I/O.
                        <?php elseif ( $mem_pct >= 95 ) : ?>
                            Memory is near capacity. PHP-FPM workers may be killed by the OOM killer,
                            causing worker churn and elevated load.
                        <?php else : ?>
                            High memory usage can cause PHP-FPM workers to enter swap, degrading response times.
                        <?php endif; ?>
                        Consider reducing the number of PHP-FPM workers, lowering
                        <code>pm.max_children</code>, or upgrading your server RAM.
                    </div>
                </div>
            <?php endif; ?>

            <?php /* ── 8. Developer Tools (Info) ── */ ?>
            <?php if ( $has_dev_load ) : ?>
                <div class="mmi-info-card">
                    <h3>
                        <span class="dashicons dashicons-editor-code"></span>
                        <strong>Developer Tools</strong>
                        <span class="mmi-badge info">
                            <?php echo esc_html( number_format( $attribution['dev_cpu'] ?? 0, 1 ) ); ?>% CPU
                        </span>
                    </h3>
                    <div class="rtsm-cause-detail">
                        <?php if ( ! empty( $attribution['top_dev_processes'] ) ) : ?>
                            Processes: <code><?php echo esc_html( implode( ', ', array_slice( $attribution['top_dev_processes'], 0, 3 ) ) ); ?></code>.
                        <?php endif; ?>
                        WP maintenance mode is suppressed while developer tools are active.
                    </div>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>
    </div>
</div>
