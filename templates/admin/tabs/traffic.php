<?php
/**
 * RTSM Traffic Analysis Tab
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ──────────────────────────────────────────────────────────────────────────
 * 1. Live server stats – pull from RTSM_Stats_Collector (all values are
 *    backed by short-lived transient caches so repeated tab loads are fast).
 * ────────────────────────────────────────────────────────────────────────── */
$collector  = RTSM_Stats_Collector::get_instance();

// Load average (cached 10 s) — rounded to 2 dp so initial PHP render matches JS formatting.
$load_data  = $collector->get_load_average();
$load_1min  = round( $load_data['1min']  ?? 0, 2 );
$load_5min  = round( $load_data['5min']  ?? 0, 2 );
$load_15min = round( $load_data['15min'] ?? 0, 2 );

// CPU core count – cached in a 5-minute transient. nproc is the primary method; /proc/cpuinfo is the fallback.
$cpu_cores = get_transient( 'rtsm_cpu_cores' );
if ( $cpu_cores === false ) {
    $cpu_cores = 1;
    if ( function_exists( 'shell_exec' ) ) {
        $nproc = (int) trim( @shell_exec( 'nproc' ) );
        if ( $nproc > 0 ) { $cpu_cores = $nproc; }
    }
    if ( $cpu_cores <= 1 && is_readable( '/proc/cpuinfo' ) ) {
        $cpu_cores = max( 1, substr_count( @file_get_contents( '/proc/cpuinfo' ), 'processor' ) );
    }
    set_transient( 'rtsm_cpu_cores', $cpu_cores, 5 * MINUTE_IN_SECONDS );
}
$load_per_core = round( $load_1min / $cpu_cores, 2 );
$load_pct      = min( 100, round( ( $load_1min / $cpu_cores ) * 100 ) );
// Real CPU utilisation from /proc/stat (matches admin bar and RunCloud) — rounded to 2 dp.
$cpu_pct       = round( $collector->get_cpu_usage(), 2 );

// Memory (cached 10 s, reads /proc/meminfo via collector) — percent rounded to 2 dp.
$mem_info    = $collector->get_memory_info();
$mem_total_mb = $mem_info['total'] ?? 0;
$mem_used_mb  = $mem_info['used']  ?? 0;
$mem_pct      = round( $mem_info['percent'] ?? 0, 2 );

// PHP-FPM worker count – cached 15 s; ps aux is expensive
$php_workers = get_transient( 'rtsm_php_fpm_workers' );
if ( $php_workers === false ) {
    $php_workers = 0;
    if ( function_exists( 'shell_exec' ) ) {
        $ps          = @shell_exec( "ps aux | grep 'php-fpm' | grep -v grep | wc -l" );
        $php_workers = (int) trim( $ps );
    }
    set_transient( 'rtsm_php_fpm_workers', $php_workers, 15 );
}

// Calculate load bar saturation percentage (determines severity)
// Bar represents: 0% = 0 load, 50% = 1x cores, 100% = 2x cores
$load_bar_pct = min( 100, round( ( $load_1min / ( $cpu_cores * 2 ) ) * 100 ) );

// Severity level — now based on load bar percentage for CONSISTENCY
// < 50% bar = healthy, 50-79% = monitored, 80%+ = concerning
$severity = 'NORMAL';
if ( $load_bar_pct >= 100 )     { $severity = 'EMERGENCY'; }
elseif ( $load_bar_pct >= 80 )  { $severity = 'CRITICAL'; }
elseif ( $load_bar_pct >= 50 )  { $severity = 'ELEVATED'; }

$severity_variant = RTSM_UI_Helpers::SEVERITY_VARIANTS[ $severity ]['variant'];

// Auto-remediation setting — drives threshold card copy and conditional links
$auto_maintenance_on = (bool) RTSM_Settings_Manager::get_instance()->get('rtsm_auto_maintenance', 0);

/* ──────────────────────────────────────────────────────────────────────────
 * 3. Log / alert files
 * ────────────────────────────────────────────────────────────────────────── */
$log_file   = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-traffic-analysis.log';
$alert_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-alerts.log';
$log_exists = file_exists( $log_file );

/* ──────────────────────────────────────────────────────────────────────────
 * 4. Parse recent alerts into structured records
 *    Format: "[YYYY-MM-DD HH:MM:SS] ⚠️  SEVERITY ALERT - Load: X.XX | PHP Processes: N | Memory: X.X% [| ...]"
 *            "    → URL: /path"
 *            "    → IP: 1.2.3.4"
 *            "    → User-Agent: ..."
 * ────────────────────────────────────────────────────────────────────────── */
$parsed_alerts = [];

if ( file_exists( $alert_file ) ) {
    // Read last 200 lines so we can group multi-line entries into 25 records
    $raw_lines = array_slice( file( $alert_file ), -200 );

    $current = null;
    foreach ( $raw_lines as $line ) {
        $line = rtrim( $line );
        if ( empty( $line ) ) continue;

        // New alert header line
        if ( preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+⚠️\s+(\w+)\s+ALERT\s+-\s+(.+)$/', $line, $m ) ) {
            if ( $current ) { $parsed_alerts[] = $current; }
            $meta_str = $m[3];
            $fields   = [];
            foreach ( explode( '|', $meta_str ) as $part ) {
                if ( preg_match( '/^(.+?):\s+(.+)$/', trim( $part ), $kv ) ) {
                    $fields[ trim( $kv[1] ) ] = trim( $kv[2] );
                }
            }
            $current = [
                'ts'            => $m[1],
                'severity'      => $m[2],
                'load'          => isset( $fields['Load'] )          ? rtrim( $fields['Load'],   '%' ) : '—',
                'php_procs'     => $fields['PHP Processes']          ?? '—',
                'memory'        => isset( $fields['Memory'] )        ? rtrim( $fields['Memory'], '%' ) : '—',
                'dev_cpu'       => $fields['Dev Tools CPU']          ?? null,
                'web_cpu'       => $fields['Web CPU']                ?? null,
                'cause'         => $fields['Cause']                  ?? null,
                'suppressed'    => str_contains( $meta_str, 'MAINTENANCE SUPPRESSED' ),
                'url'           => '',
                'ip'            => '',
                'ua'            => '',
            ];
        } elseif ( $current && str_starts_with( $line, '    → URL: ' ) ) {
            $current['url'] = substr( $line, 11 );
        } elseif ( $current && str_starts_with( $line, '    → IP: ' ) ) {
            $current['ip'] = substr( $line, 10 );
        } elseif ( $current && str_starts_with( $line, '    → User-Agent: ' ) ) {
            $current['ua'] = substr( $line, 18 );
        }
    }
    if ( $current ) { $parsed_alerts[] = $current; }

    // newest first, max 25
    $parsed_alerts = array_reverse( array_slice( $parsed_alerts, -25 ) );
}

/* ──────────────────────────────────────────────────────────────────────────
 * 5. Historical stats
 * ────────────────────────────────────────────────────────────────────────── */
$stats = RTSM_Traffic_Logger::get_analysis_summary();

// Stat-tile states for the initial render — traffic-tab.js recomputes them on
// every poll with the same thresholds.
$load_variant    = $load_1min >= $cpu_cores ? 'error' : ( $load_1min >= $cpu_cores * 0.625 ? 'warning' : 'success' );
$cpu_variant     = $cpu_pct   >= 90 ? 'error' : ( $cpu_pct   >= 60 ? 'warning' : 'success' );
$mem_variant     = $mem_pct   >= 85 ? 'error' : ( $mem_pct   >= 60 ? 'warning' : 'success' );
$workers_variant = $php_workers >= 30 ? 'error' : ( $php_workers >= 15 ? 'warning' : 'success' );
$bar_variant     = $load_bar_pct >= 80 ? 'error' : ( $load_bar_pct >= 50 ? 'warning' : 'success' );
?>

<div class="rtsm-traffic" id="rtsm-traffic-wrap">

<?php /* ── 1. CURRENT SERVER STATUS ──────────────────────────────────── */ ?>
<div id="rtsm-status-card" class="mmi-process-section" data-severity="<?php echo esc_attr( strtolower( $severity ) ); ?>">
    <?php echo RTSM_UI_Helpers::section_header(
        'performance',
        'Current Server Status',
        'Live load against this server\'s ' . $cpu_cores . ' CPU cores.',
        ' <span id="rtsm-severity-badge">' . RTSM_UI_Helpers::severity_badge( $severity ) . '</span>',
        '<span id="rtsm-status-time" class="mmi-text-muted">Updated ' . esc_html( current_time( 'H:i:s' ) ) . ' UTC</span>'
    ); ?>
    <div class="mmi-section-content">
        <div class="mmi-stats-grid">
            <div class="mmi-stat-box inline <?php echo esc_attr( $load_variant ); ?>">
                <div class="mmi-stat-label">Load (1 min)</div>
                <div id="rtsm-stat-load1" class="mmi-stat-value"><?php echo esc_html( $load_1min ); ?></div>
                <div id="rtsm-stat-load-sub" class="mmi-stat-meta"><?php echo esc_html( $load_5min ); ?> · <?php echo esc_html( $load_15min ); ?> (5 / 15 min)</div>
            </div>
            <div class="mmi-stat-box inline <?php echo esc_attr( $cpu_variant ); ?>">
                <div class="mmi-stat-label">CPU Usage</div>
                <div id="rtsm-stat-cpu-pct" class="mmi-stat-value"><?php echo esc_html( $cpu_pct ); ?>%</div>
                <div id="rtsm-stat-cpu-sub" class="mmi-stat-meta"><?php echo esc_html( $load_per_core ); ?> per core (<?php echo esc_html( $cpu_cores ); ?> cores)</div>
            </div>
            <div class="mmi-stat-box inline <?php echo esc_attr( $mem_variant ); ?>">
                <div class="mmi-stat-label">Memory Used</div>
                <div id="rtsm-stat-mem-pct" class="mmi-stat-value"><?php echo esc_html( $mem_pct ); ?>%</div>
                <div id="rtsm-stat-mem-sub" class="mmi-stat-meta"><?php echo esc_html( number_format( $mem_used_mb ) ); ?> MB / <?php echo esc_html( number_format( $mem_total_mb ) ); ?> MB</div>
            </div>
            <div class="mmi-stat-box inline <?php echo esc_attr( $workers_variant ); ?>">
                <div class="mmi-stat-label">PHP-FPM Workers</div>
                <div id="rtsm-stat-workers" class="mmi-stat-value"><?php echo esc_html( $php_workers ); ?></div>
                <div class="mmi-stat-meta">Active processes</div>
            </div>
        </div>

        <div class="rtsm-severity">
            <div id="rtsm-load-bar-label" class="rtsm-severity-label">Load saturation — <?php echo esc_html( $load_bar_pct ); ?>% of comfortable capacity (2× cores)</div>
            <?php echo RTSM_UI_Helpers::progress_bar( $load_bar_pct, $bar_variant, 'rtsm-load-bar' ); ?>
        </div>

        <?php /* All four always in the DOM; traffic-tab.js un-hides the one matching the live severity. */ ?>
        <div id="rtsm-interpretation">
            <div class="mmi-info-card success" data-sev="normal"<?php echo $severity === 'NORMAL' ? '' : ' hidden'; ?>>
                <h3>✅ All systems nominal</h3>
                <p>Load saturation is below 50%. Your server is comfortably handling the current request volume. Load <span data-interp-load><?php echo esc_html( $load_1min ); ?></span> on <?php echo esc_html( $cpu_cores ); ?> core(s) = <span data-interp-pct><?php echo esc_html( $load_pct ); ?></span>% utilisation. Requests are being processed faster than they arrive. No action required — keep monitoring.</p>
            </div>
            <div class="mmi-info-card warning" data-sev="elevated"<?php echo $severity === 'ELEVATED' ? '' : ' hidden'; ?>>
                <h3>⚠️ Elevated — monitor closely</h3>
                <p>Load saturation is 50–79%. Your server is busier than comfortable but not yet critical. Load <span data-interp-load><?php echo esc_html( $load_1min ); ?></span> on <?php echo esc_html( $cpu_cores ); ?> core(s) indicates requests are queuing slightly. Monitor traffic sources below closely. If this state persists for more than 10 minutes, investigate the spike source (high-traffic URLs, bot activity, or resource-intensive tasks). Automatic traffic logging has activated.</p>
            </div>
            <div class="mmi-info-card error inline" data-sev="critical"<?php echo $severity === 'CRITICAL' ? '' : ' hidden'; ?>>
                <h3>🔴 Critical — take action now</h3>
                <p>Load saturation is 80–99%. Your server is significantly overloaded. Load <span data-interp-load><?php echo esc_html( $load_1min ); ?></span> on <?php echo esc_html( $cpu_cores ); ?> core(s) means response times are degraded and requests are backing up. Check the Recent Alerts log immediately to identify the traffic source. Enable bot protection, rate limiting, or manual query caching. If auto-remediation is active, it should activate emergency measures shortly.</p>
            </div>
            <div class="mmi-info-card error inline" data-sev="emergency"<?php echo $severity === 'EMERGENCY' ? '' : ' hidden'; ?>>
                <h3>🚨 Emergency — server under severe stress</h3>
                <p>Load saturation exceeds 100%! Your server is in critical overload. Load <span data-interp-load><?php echo esc_html( $load_1min ); ?></span> on <?php echo esc_html( $cpu_cores ); ?> core(s) is unsustainable — pages are timing out and requests may return HTTP 500 errors. Emergency lockdown and auto-remediation have been triggered. Immediately investigate and block attack traffic, reduce database queries, enable aggressive caching, and consider temporarily disabling non-critical features.</p>
            </div>
        </div>
    </div>
</div>

<?php /* ── 2. MITIGATION GUIDANCE ────────────────────────────────────── */ ?>
<div id="rtsm-mitigation-card" class="mmi-process-section"<?php echo $severity === 'NORMAL' ? ' hidden' : ''; ?>>
    <div class="mmi-section-header">
        <div>
            <h3 class="mmi-process-section-header"><span class="dashicons dashicons-sos"></span> <span class="rtsm-mitigation-heading">What To Do — <?php echo esc_html( $severity ); ?> State</span></h3>
            <p class="mmi-process-section-description">Shown only while load is above normal.</p>
        </div>
    </div>
    <div class="mmi-section-content">
        <div class="mmi-grid-2">
            <div class="mmi-widget">
                <h3>🔍 Diagnose the load source</h3>
                <ul>
                    <li>Check <strong>Recent Alerts</strong> below — identify which IP / URL is generating volume.</li>
                    <li>Look at <strong>URLs at Incident Start</strong> in Historical Analysis — the same path starting several incidents suggests a scraper or bot.</li>
                    <li>High <em>PHP worker</em> count + low memory use → CPU-bound PHP (bad query, missing cache).</li>
                    <li>High PHP workers + high memory → memory pressure; consider increasing PHP-FPM pool limits.</li>
                </ul>
            </div>
            <div class="mmi-widget">
                <h3>🛡️ Block bad actors at Cloudflare (fastest relief)</h3>
                <ul>
                    <li>Go to Cloudflare → Security → WAF — add a block rule for the offending IP or user-agent.</li>
                    <li>Enabling Cloudflare Under Attack Mode in your Cloudflare dashboard gives instant 5-second JS challenge to all visitors — without taking your site offline.</li>
                </ul>
            </div>
            <div class="mmi-widget">
                <h3>⚙️ WooCommerce / WordPress quick wins</h3>
                <ul>
                    <li>Verify a page cache is active (WP Rocket, LiteSpeed, Cloudflare cache rules).</li>
                    <li>Ensure WooCommerce <code>?add-to-cart=</code> requests from bots are redirected before PHP runs (see Cloudflare rules).</li>
                    <li>Disable WooCommerce cart fragment AJAX for unauthenticated visitors to reduce DB writes under pressure.</li>
                    <li>Check for runaway WP-Cron jobs — cron firing every second = each cron spawns a PHP worker.</li>
                </ul>
            </div>
            <div class="mmi-widget">
                <h3>🗄️ Database &amp; PHP-FPM checks</h3>
                <ul>
                    <li>A <code>SHOW PROCESSLIST;</code> in MySQL will reveal locked or long-running queries driving load.</li>
                    <li>PHP-FPM max_children default = 5 on shared plans — increase to 20–30 if workers are exhausted.</li>
                    <li>Enable OPcache if not already active — reduces per-request PHP compile time dramatically.</li>
                    <li class="rtsm-emerg-only"<?php echo $severity !== 'EMERGENCY' ? ' hidden' : ''; ?>><strong>Consider enabling maintenance mode</strong> via WP Settings to stop all non-admin traffic while you diagnose.</li>
                    <li class="rtsm-non-emerg-only"<?php echo $severity === 'EMERGENCY' ? ' hidden' : ''; ?>>Monitor via RunCloud → PHP → PHP-FPM status.</li>
                </ul>
                <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=mmi-rtsm&tab=processes' ) ); ?>"><span class="dashicons dashicons-editor-ul"></span> View Process Monitor</a>
            </div>
        </div>
    </div>
</div>

<?php /* ── 3. AUTO-REMEDIATION THRESHOLDS ──────────────────────────────── */ ?>
<?php
// Thresholds scale with CPU core count — aligned with the load bar severity system:
// Elevated ≥ bar 50% (cores × 1.0), Critical ≥ bar 80% (cores × 1.6), Emergency ≥ bar 100% (cores × 2.0), Recovery < bar 25% (cores × 0.5)
$thresh_elevated  = round( $cpu_cores * 1.0, 1 );
$thresh_critical  = round( $cpu_cores * 1.6, 1 );
$thresh_emergency = round( $cpu_cores * 2.0, 1 );
$thresh_recovery  = round( $cpu_cores * 0.5, 1 );
$thresholds = [
    [ 'key' => 'elevated',  'label' => 'Elevated',  'load' => '≥ ' . $thresh_elevated,  'icon' => '📝', 'variant' => 'warning',
      'action' => 'Starts logging every high-load request — URL, IP, user-agent, memory — for forensic analysis. No site impact.',
      'active' => $load_1min >= $thresh_elevated && $load_1min < $thresh_critical ],
    [ 'key' => 'critical',  'label' => 'Critical',  'load' => '≥ ' . $thresh_critical,  'icon' => '🛡️', 'variant' => 'error inline',
      'action' => $auto_maintenance_on
        ? 'Auto-activates WordPress maintenance mode after 3 checks spanning 120 seconds of sustained load.'
        : 'Incident flag and detailed logs written. Alerts and hooks fire — enable Auto-Remediation in Settings to also put the site into maintenance mode.',
      'active' => $load_1min >= $thresh_critical && $load_1min < $thresh_emergency ],
    [ 'key' => 'emergency', 'label' => 'Emergency', 'load' => '≥ ' . $thresh_emergency, 'icon' => '🚨', 'variant' => 'error inline',
      'action' => $auto_maintenance_on
        ? 'Immediate maintenance mode after 2 checks spanning 60 seconds. No extended grace period. All non-admin traffic blocked.'
        : 'Incident flag and detailed logs written. Alerts and hooks fire — enable Auto-Remediation in Settings to also put the site into maintenance mode.',
      'active' => $load_1min >= $thresh_emergency ],
    [ 'key' => 'recovery',  'label' => 'Recovery',  'load' => '< ' . $thresh_recovery,  'icon' => '✅', 'variant' => 'success',
      'action' => $auto_maintenance_on
        ? 'Auto-resolve: maintenance mode lifted, incident flag cleared.'
        : 'Incident flag cleared. Logging resumes normal cadence.',
      'active' => $load_1min < $thresh_recovery ],
];
?>
<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header(
        'shield',
        'Auto-Remediation Thresholds',
        'What RTSM does at each load level. The level the server is in now is marked.',
        '',
        '<span class="mmi-text-muted">Current load: <strong id="rtsm-thresh-current-load">' . esc_html( $load_1min ) . '</strong></span>'
    ); ?>
    <div class="mmi-section-content">
        <div class="mmi-grid-4">
            <?php foreach ( $thresholds as $t ) : ?>
                <div class="mmi-info-card rtsm-threshold <?php echo esc_attr( $t['variant'] ); ?><?php echo $t['active'] ? ' is-active' : ''; ?>" data-threshold="<?php echo esc_attr( $t['key'] ); ?>">
                    <h3><?php echo $t['icon']; ?> <?php echo esc_html( $t['label'] ); ?> (<?php echo esc_html( $t['load'] ); ?>)</h3>
                    <p><?php echo esc_html( $t['action'] ); ?></p>
                    <span class="mmi-badge rtsm-threshold-active"<?php echo $t['active'] ? '' : ' hidden'; ?>>▲ Currently active</span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="mmi-hint-text">
            <strong>What is load average?</strong> It represents the average number of processes <em>waiting</em> for CPU time over 1, 5, and 15-minute windows. On this <?php echo esc_html( $cpu_cores ); ?>-core server, a load of <strong><?php echo esc_html( $cpu_cores ); ?>.0</strong> means 100% utilisation — every core is busy with no queue. A load of <strong><?php echo esc_html( $cpu_cores * 2 ); ?>.0</strong> means cores are 200% subscribed and requests are visibly queuing.
        </p>
    </div>
</div>

<?php /* ── 4. RECENT ALERTS ──────────────────────────────────────────────── */ ?>
<?php if ( ! empty( $parsed_alerts ) || file_exists( $alert_file ) ) : ?>
<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header(
        'bell',
        'Recent Alerts',
        ! empty( $parsed_alerts ) ? 'Last ' . count( $parsed_alerts ) . ' structured alert events, newest first.' : 'Structured alert events from the alert log.'
    ); ?>
    <div class="mmi-section-content">
    <?php if ( ! empty( $parsed_alerts ) ) : ?>
        <div class="mmi-table-scroll-wrapper">
            <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                <thead>
                    <tr>
                        <th>Time (UTC)</th>
                        <th>Status</th>
                        <th>Load</th>
                        <th>PHP Workers</th>
                        <th>Memory</th>
                        <th>URL</th>
                        <th>IP</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $parsed_alerts as $a ) :
                    $load_f     = (float) $a['load'];
                    $load_class = $load_f >= 20 ? 'mmi-text-error' : ( $load_f >= 5 ? 'mmi-text-warning' : '' );
                ?>
                    <tr>
                        <td class="rtsm-nowrap"><?php echo esc_html( $a['ts'] ); ?></td>
                        <td><?php echo RTSM_UI_Helpers::severity_badge( $a['severity'] ); ?></td>
                        <td class="<?php echo esc_attr( $load_class ); ?>"><?php echo esc_html( $a['load'] ); ?></td>
                        <td><?php echo esc_html( $a['php_procs'] ); ?></td>
                        <td><?php
                            $mp = (float) preg_replace( '/[^0-9.]/', '', $a['memory'] );
                            echo esc_html( number_format( $mp, 1 ) ) . '%';
                        ?></td>
                        <td class="rtsm-truncate" title="<?php echo esc_attr( $a['url'] ); ?>"><?php echo esc_html( $a['url'] ?: '—' ); ?></td>
                        <td><code><?php echo esc_html( $a['ip'] ?: '—' ); ?></code></td>
                        <td class="mmi-text-muted">
                            <?php if ( $a['suppressed'] ) : ?>
                                <span class="mmi-badge info">Dev tools (maintenance suppressed)</span>
                            <?php elseif ( $a['cause'] ) : ?>
                                <?php echo esc_html( preg_replace( '/\s*\([\d.]+%\)/', '', $a['cause'] ) ); // Trim "(X.X%)" suffix from process names ?>
                            <?php elseif ( $a['dev_cpu'] ) : ?>
                                Dev <?php echo esc_html( $a['dev_cpu'] ); ?> / Web <?php echo esc_html( $a['web_cpu'] ); ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mmi-hint-text">
            <strong>Tip:</strong> A cluster of the same IP across multiple log entries with no referer and a scraper user-agent = bot attack. Block it at Cloudflare. Lines marked "Dev tools" are caused by VS Code running locally and are automatically excluded from auto-remediation triggers.
        </p>
    <?php else : ?>
        <p class="mmi-text-muted">Alert log exists but contains no parseable structured entries yet. New alerts are written on every request when load is being monitored.</p>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* ── 5. HISTORICAL INCIDENT ANALYSIS ─────────────────────────────── */ ?>
<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header( 'chart-line', 'Historical Incident Analysis', 'Totals from the traffic log: what triggered entries, and who and what was hit hardest.' ); ?>
    <div class="mmi-section-content">
    <?php if ( empty( $stats['event_entries'] ) ) : ?>
        <p class="mmi-text-muted"><strong>No high-load incidents recorded yet.</strong> Data appears here once the load exceeds 5.0 for the first time.</p>
    <?php else : ?>
        <div class="mmi-stats-grid">
            <?php
            echo RTSM_UI_Helpers::render_stat_box( [ 'label' => 'Logged Incidents', 'value' => esc_html( number_format( $stats['total_incidents'] ) ), 'variant' => 'error' ] );
            echo RTSM_UI_Helpers::render_stat_box( [ 'label' => 'Peak Load Recorded', 'value' => esc_html( number_format( $stats['max_load'], 2 ) ), 'variant' => 'warning' ] );
            echo RTSM_UI_Helpers::render_stat_box( [ 'label' => 'Average Load During Incidents', 'value' => esc_html( $stats['total_incidents'] > 0 ? number_format( $stats['avg_load'], 2 ) : '—' ), 'variant' => 'info' ] );
            ?>
        </div>

        <div class="mmi-grid-2">
            <div>
                <?php if ( ! empty( $stats['by_type'] ) ) : ?>
                <h4>📋 Incident Types <span class="mmi-hint-text rtsm-inline-hint">What triggered each log entry</span></h4>
                <div class="mmi-table-scroll-wrapper">
                <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                    <thead><tr><th>Type</th><th>Count</th><th>%</th><th>What it means</th></tr></thead>
                    <tbody>
                    <?php
                    $type_hints = [
                        'REQUEST'  => 'A real HTTP request arrived during high load',
                        'SNAPSHOT' => 'Periodic 1-minute background health snapshot',
                        'CRITICAL' => 'Load hit the emergency auto-remediation threshold',
                        'INCIDENT_START'    => 'Load crossed the incident threshold; the request being served then is recorded',
                        'INCIDENT_RESOLVED' => 'Load dropped back below the threshold',
                    ];
                    foreach ( $stats['by_type'] as $type => $count ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $type ); ?></strong></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            <td><?php echo esc_html( round( ( $count / $stats['total_entries'] ) * 100, 1 ) ); ?>%</td>
                            <td class="mmi-text-muted"><?php echo esc_html( $type_hints[ $type ] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $stats['by_request_type'] ) ) : ?>
                <h4>🌐 Request Types <span class="mmi-hint-text rtsm-inline-hint">during incidents</span></h4>
                <div class="mmi-table-scroll-wrapper">
                <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                    <thead><tr><th>Type</th><th>Count</th><th>%</th></tr></thead>
                    <tbody>
                    <?php
                    $request_total = array_sum( $stats['by_request_type'] );
                    foreach ( $stats['by_request_type'] as $rtype => $count ) : ?>
                        <tr>
                            <td><?php echo esc_html( $rtype ); ?></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            <td><?php echo esc_html( round( ( $count / $request_total ) * 100, 1 ) ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <h4>🚫 IPs at Incident Start <span class="mmi-hint-text rtsm-inline-hint">The visitor being served when each incident began. One sample per incident, not a count of their traffic — check the access log before blocking.</span></h4>
                <?php if ( ! empty( $stats['top_ips'] ) ) : ?>
                <div class="mmi-table-scroll-wrapper">
                <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                    <thead><tr><th>IP Address</th><th>Incidents</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php
                    $cf_block_url = 'https://dash.cloudflare.com/?to=/:account/:zone/security/waf/tools/ip-access-rules';
                    $ip_crawlers  = $stats['ip_crawlers'] ?? [];
                    foreach ( array_slice( $stats['top_ips'], 0, 10 ) as $ip => $count ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $ip ); ?></code></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            <?php if ( isset( $ip_crawlers[ $ip ] ) ) : ?>
                                <td class="mmi-text-muted"><?php echo esc_html( sprintf( 'Says it is the %s crawler. Blocking it stops indexing or link previews; check before blocking.', $ip_crawlers[ $ip ] ) ); ?></td>
                            <?php else : ?>
                                <td class="rtsm-nowrap"><a href="<?php echo esc_url( $cf_block_url ); ?>" target="_blank" rel="noopener">Block in Cloudflare →</a></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else : ?>
                    <p class="mmi-text-muted">No IP data in traffic log yet.</p>
                <?php endif; ?>

                <?php if ( ! empty( $stats['top_urls'] ) ) : ?>
                <h4>🔗 URLs at Incident Start <span class="mmi-hint-text rtsm-inline-hint">The URL requested when each incident began. It may not exist: bots often ask for guessed file names.</span></h4>
                <div class="mmi-table-scroll-wrapper">
                <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                    <thead><tr><th>URL</th><th>Incidents</th></tr></thead>
                    <tbody>
                    <?php foreach ( array_slice( $stats['top_urls'], 0, 10 ) as $url => $count ) : ?>
                        <tr>
                            <td class="rtsm-truncate" title="<?php echo esc_attr( $url ); ?>"><?php echo esc_html( $url ); ?></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $stats['suspicious_flags'] ) ) : ?>
                <h4>🏴 Suspicious Flags <span class="mmi-hint-text rtsm-inline-hint">Patterns detected on requests logged during incidents</span></h4>
                <div class="mmi-table-scroll-wrapper">
                <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                    <thead><tr><th>Flag</th><th>Count</th><th>What it means</th></tr></thead>
                    <tbody>
                    <?php
                    $flag_hints = [
                        'BOT_USER_AGENT'  => 'User-agent string contained "bot" — likely a crawler',
                        'NO_REFERER'      => 'No HTTP referer — common for direct bot requests',
                        'SLOW_REQUEST'    => 'Request took > 2 s to process — heavy DB query or blocking I/O',
                        'SUSPICIOUS_PATH' => 'URL contained ".." or "wp-config" — potential exploit scan',
                    ];
                    foreach ( $stats['suspicious_flags'] as $flag => $count ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $flag ); ?></code></td>
                            <td><?php echo esc_html( number_format( $count ) ); ?></td>
                            <td class="mmi-text-muted"><?php echo esc_html( $flag_hints[ $flag ] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    </div>
</div>

<?php /* ── 6. LOG FILE MANAGEMENT ───────────────────────────────────────── */ ?>
<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header( 'media-document', 'Log File Management', 'Download or clear the two RTSM log files.' ); ?>
    <div class="mmi-section-content">
        <div class="mmi-grid-2">
            <div class="mmi-widget">
                <h3>Traffic Log <?php echo $log_exists ? '<span class="mmi-badge success">Active</span>' : '<span class="mmi-badge">No data yet</span>'; ?></h3>
                <p><code class="rtsm-break-all"><?php echo esc_html( $log_file ); ?></code></p>
                <?php if ( $log_exists ) : ?>
                    <p><strong>Size:</strong> <?php echo esc_html( size_format( filesize( $log_file ) ) ); ?></p>
                    <div class="mmi-toolbar">
                        <button type="button" class="button button-secondary" id="download-traffic-log" data-rtsm-log="traffic" data-rtsm-log-action="download"><span class="dashicons dashicons-download"></span> Download</button>
                        <button type="button" class="button button-secondary" id="clear-traffic-log" data-rtsm-log="traffic" data-rtsm-log-action="clear"><span class="dashicons dashicons-trash"></span> Clear</button>
                    </div>
                <?php endif; ?>
                <p class="mmi-hint-text">Records every request during elevated load: URL, IP, user-agent, response time, memory, suspicious flags.</p>
            </div>
            <div class="mmi-widget">
                <h3>Alert Log <?php echo file_exists( $alert_file ) ? '<span class="mmi-badge success">Active</span>' : '<span class="mmi-badge">No data yet</span>'; ?></h3>
                <p><code class="rtsm-break-all"><?php echo esc_html( $alert_file ); ?></code></p>
                <?php if ( file_exists( $alert_file ) ) : ?>
                    <p><strong>Size:</strong> <?php echo esc_html( size_format( filesize( $alert_file ) ) ); ?></p>
                    <div class="mmi-toolbar">
                        <button type="button" class="button button-secondary" id="download-alert-log" data-rtsm-log="alert" data-rtsm-log-action="download"><span class="dashicons dashicons-download"></span> Download</button>
                        <button type="button" class="button button-secondary" id="clear-alert-log" data-rtsm-log="alert" data-rtsm-log-action="clear"><span class="dashicons dashicons-trash"></span> Clear</button>
                    </div>
                <?php endif; ?>
                <p class="mmi-hint-text">Written on every request (not just high load) — one line per request with load, memory, PHP workers. Rotated at 5 MB.</p>
            </div>
        </div>
    </div>
</div>

</div><!-- #rtsm-traffic-wrap -->
