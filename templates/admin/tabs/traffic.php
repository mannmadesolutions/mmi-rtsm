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

/* ──────────────────────────────────────────────────────────────────────────
 * Severity color schemes (keyed by severity level)
 * ────────────────────────────────────────────────────────────────────────── */
$severity_colours = [
    'NORMAL'    => [ 'bg' => '#f0fdf4', 'border' => '#45852C', 'badge'  => '#45852C', 'text' => '#14532d', 'css_class' => 'rtsm-sev-normal-theme' ],
    'ELEVATED'  => [ 'bg' => '#fffbeb', 'border' => '#d97706', 'badge'  => '#d97706', 'text' => '#78350f', 'css_class' => 'rtsm-sev-elevated-theme' ],
    'CRITICAL'  => [ 'bg' => '#fff7ed', 'border' => '#ea580c', 'badge'  => '#ea580c', 'text' => '#7c2d12', 'css_class' => 'rtsm-sev-critical-theme' ],
    'EMERGENCY' => [ 'bg' => '#fef2f2', 'border' => '#dc2626', 'badge'  => '#dc2626', 'text' => '#7f1d1d', 'css_class' => 'rtsm-sev-emergency-theme' ],
];
$sc = $severity_colours[ $severity ];

// Auto-remediation setting — drives threshold card copy and conditional links
$auto_maintenance_on = (bool) RTSM_Settings_Manager::get_instance()->get('rtsm_auto_maintenance', 0);
// CF integration present — drives conditional links/card
$cf_available = class_exists('MMI_CF_API');

/* ──────────────────────────────────────────────────────────────────────────
 * 2. Cloudflare protection status
 * ────────────────────────────────────────────────────────────────────────── */
$cf_configured      = false;
$cf_bot_rules_ok    = false;
$cf_circuit_active  = false;
$cf_circuit_status  = [];
$cf_retry_at        = false;

if ( $cf_available ) {
    $cf_api         = MMI_CF_API::instance();
    $cf_configured  = $cf_api->is_configured();
    if ( $cf_configured ) {
        $cf_circuit_active = $cf_api->is_api_locked();
        $cf_circuit_status = $cf_api->get_circuit_breaker_status() ?: [];
    }
}
$cf_bot_rules_ok = (bool) MMI_Settings::get('mmi_cf_bot_rules_initialized', false );
$cf_retry_ts     = wp_next_scheduled( 'mmi_cf_retry_bot_rules' );

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

/* ──────────────────────────────────────────────────────────────────────────
 * Helper: severity badge HTML
 * ────────────────────────────────────────────────────────────────────────── */
function rtsm_severity_badge( $sev ) {
    // Modifier classes and icons must match traffic-tab.js's own SEVERITY_COLORS/
    // buildBadgeHtml() exactly — this PHP function renders the initial page-load
    // state, JS's own version replaces it on every subsequent poll (both target the
    // same #rtsm-severity-badge element). They used to render two different shapes
    // (this one as .rtsm-mini-badge with inline CSS vars, JS's as .rtsm-severity-badge
    // + a modifier class) — the badge visibly changed shape the instant the first
    // poll completed. Unified onto JS's shape/class scheme.
    $icons = [
        'EMERGENCY' => '🚨',
        'CRITICAL'  => '🔴',
        'ELEVATED'  => '⚠️',
        'NORMAL'    => '✅',
    ];
    $icon     = $icons[ $sev ] ?? $icons['NORMAL'];
    $modifier = 'rtsm-severity-badge-' . strtolower( isset( $icons[ $sev ] ) ? $sev : 'NORMAL' );
    return sprintf(
        '<span class="rtsm-severity-badge %s">%s %s</span>',
        esc_attr( $modifier ), $icon, esc_html( $sev )
    );
}

// CSS color classes for initial PHP render — JS will update these on each poll.
$load_color_cls    = $load_1min >= $cpu_cores          ? 'rtsm-color-crit' : ( $load_1min >= $cpu_cores * 0.625 ? 'rtsm-color-warn' : 'rtsm-color-ok' );
$cpu_color_cls     = $cpu_pct   >= 90                  ? 'rtsm-color-crit' : ( $cpu_pct   >= 60               ? 'rtsm-color-warn' : 'rtsm-color-ok' );
$mem_color_cls     = $mem_pct   >= 85                  ? 'rtsm-color-crit' : ( $mem_pct   >= 60               ? 'rtsm-color-warn' : 'rtsm-color-ok' );
$workers_color_cls = $php_workers >= 30                ? 'rtsm-color-crit' : ( $php_workers >= 15             ? 'rtsm-color-warn' : 'rtsm-color-ok' );
?>

<div class="rtsm-traffic-wrap" id="rtsm-traffic-wrap">

<?php /* ── 1. CURRENT SERVER STATUS ──────────────────────────────────── */ ?>
<div id="rtsm-status-card" class="rtsm-card rtsm-status-card-theme" data-severity="<?php echo esc_attr( strtolower($severity) ); ?>" style="--border-color:<?php echo esc_attr($sc['border']); ?>;--bg-color:<?php echo esc_attr($sc['bg']); ?>;--text-color:<?php echo esc_attr($sc['text']); ?>;">
    <h2 class="rtsm-status-heading">
        <span class="dashicons dashicons-performance"></span>
        Current Server Status &nbsp;
        <span id="rtsm-severity-badge"><?php echo rtsm_severity_badge( $severity ); ?></span>
        <span id="rtsm-status-time" class="rtsm-status-time">Updated <?php echo esc_html( current_time('H:i:s') ); ?> UTC</span>
    </h2>

    <div class="rtsm-status-grid">
        <div class="rtsm-stat-cell">
            <div id="rtsm-stat-load1" class="val <?php echo esc_attr($load_color_cls); ?>"><?php echo esc_html( $load_1min ); ?></div>
            <div class="lbl">Load (1 min)</div>
            <div id="rtsm-stat-load-sub" class="sub"><?php echo esc_html( $load_5min ); ?> · <?php echo esc_html( $load_15min ); ?> (5 / 15 min)</div>
        </div>
        <div class="rtsm-stat-cell">
            <div id="rtsm-stat-cpu-pct" class="val <?php echo esc_attr($cpu_color_cls); ?>"><?php echo esc_html( $cpu_pct ); ?>%</div>
            <div class="lbl">CPU Usage</div>
            <div id="rtsm-stat-cpu-sub" class="sub"><?php echo esc_html( $load_per_core ); ?> per core&nbsp;(<?php echo esc_html( $cpu_cores ); ?> cores)</div>
        </div>
        <div class="rtsm-stat-cell">
            <div id="rtsm-stat-mem-pct" class="val <?php echo esc_attr($mem_color_cls); ?>"><?php echo esc_html( $mem_pct ); ?>%</div>
            <div class="lbl">Memory Used</div>
            <div id="rtsm-stat-mem-sub" class="sub"><?php echo esc_html( number_format($mem_used_mb) ); ?> MB / <?php echo esc_html( number_format($mem_total_mb) ); ?> MB</div>
        </div>
        <div class="rtsm-stat-cell">
            <div id="rtsm-stat-workers" class="val <?php echo esc_attr($workers_color_cls); ?>"><?php echo esc_html( $php_workers ); ?></div>
            <div class="lbl">PHP-FPM Workers</div>
            <div class="sub">Active processes</div>
        </div>
    </div>

    <!-- Load bar -->
    <div class="rtsm-load-bar-wrap">
        <div id="rtsm-load-bar-label" class="rtsm-load-bar-label">Load saturation &mdash; <span class="rtsm-load-bar-pct"><?php echo esc_html($load_bar_pct); ?></span>% of comfortable capacity (2&times; cores)</div>
        <div class="rtsm-load-bar-bg">
            <div id="rtsm-load-bar-fill" class="rtsm-load-bar-fill" data-load-pct="<?php echo esc_attr($load_bar_pct); ?>" style="--load-pct:<?php echo esc_attr($load_bar_pct); ?>%;"></div>
        </div>
    </div>

    <!-- Interpretation — all four variants always in DOM; CSS + JS show the active one via rtsm-sev-* class -->
    <div id="rtsm-interpretation" class="rtsm-interpretation rtsm-sev-<?php echo esc_attr( strtolower($severity) ); ?>" style="--bg-color:<?php echo esc_attr($sc['bg']); ?>;--border-color:<?php echo esc_attr($sc['border']); ?>;--text-color:<?php echo esc_attr($sc['text']); ?>;">
        <div class="rtsm-interp-block rtsm-interp-normal">
            <strong>✅ All systems nominal</strong>
            Load saturation is below 50%. Your server is comfortably handling the current request volume. Load <span data-interp-load><?php echo esc_html($load_1min); ?></span> on <?php echo esc_html($cpu_cores); ?> core(s) = <span data-interp-pct><?php echo esc_html($load_pct); ?></span>% utilisation. Requests are being processed faster than they arrive. No action required — keep monitoring.
        </div>
        <div class="rtsm-interp-block rtsm-interp-elevated">
            <strong>⚠️ Elevated — monitor closely</strong>
            Load saturation is 50–79%. Your server is busier than comfortable but not yet critical. Load <span data-interp-load><?php echo esc_html($load_1min); ?></span> on <?php echo esc_html($cpu_cores); ?> core(s) indicates requests are queuing slightly. Monitor traffic sources below closely. If this state persists for more than 10 minutes, investigate the spike source (high-traffic URLs, bot activity, or resource-intensive tasks). Automatic traffic logging has activated.
        </div>
        <div class="rtsm-interp-block rtsm-interp-critical">
            <strong>🔴 Critical — take action now</strong>
            Load saturation is 80–99%. Your server is significantly overloaded. Load <span data-interp-load><?php echo esc_html($load_1min); ?></span> on <?php echo esc_html($cpu_cores); ?> core(s) means response times are degraded and requests are backing up. Check the Recent Alerts log immediately to identify the traffic source. Enable bot protection, rate limiting, or manual query caching. If auto-remediation is active, it should activate emergency measures shortly.
        </div>
        <div class="rtsm-interp-block rtsm-interp-emergency">
            <strong>🚨 Emergency — server under severe stress</strong>
            Load saturation exceeds 100%! Your server is in critical overload. Load <span data-interp-load><?php echo esc_html($load_1min); ?></span> on <?php echo esc_html($cpu_cores); ?> core(s) is unsustainable — pages are timing out and requests may return HTTP 500 errors. Emergency lockdown and auto-remediation have been triggered. Immediately investigate and block attack traffic, reduce database queries, enable aggressive caching, and consider temporarily disabling non-critical features.
        </div>
    </div>
</div>

<?php /* ── 2. MITIGATION GUIDANCE ────────────────────────────────────── */ ?>
<div id="rtsm-mitigation-card" class="rtsm-card<?php echo $severity === 'NORMAL' ? ' rtsm-hidden' : ''; ?>">
    <h2><span class="dashicons dashicons-sos"></span> <span class="rtsm-mitigation-heading">What To Do &mdash; <?php echo esc_html($severity); ?> State</span></h2>
    <div class="rtsm-mitigation-grid">

        <div class="rtsm-mitigation-item">
            <h4>🔍 Diagnose the load source</h4>
            <ul>
                <li>Check <strong>Recent Alerts</strong> below — identify which IP / URL is generating volume.</li>
                <li>Look at <strong>Top High-Load URLs</strong> in Historical Analysis — repeated hits to a single path suggest a scraper or bot.</li>
                <li>High <em>PHP worker</em> count + low memory use → CPU-bound PHP (bad query, missing cache).</li>
                <li>High PHP workers + high memory → memory pressure; consider increasing PHP-FPM pool limits.</li>
            </ul>
        </div>

        <div class="rtsm-mitigation-item">
            <h4>🛡️ Block bad actors at Cloudflare (fastest relief)</h4>
            <ul>
                <li>Go to Cloudflare → Security → WAF — add a block rule for the offending IP or user-agent.</li>
                <?php if ($cf_available): ?>
                <li>If the Cloudflare bot rules below are pending, wait for the API lock to clear or clear it manually.</li>
                <li>Enabling <strong>Under Attack Mode</strong> via the Cloudflare tab gives instant 5-second JS challenge to all visitors — without taking your site offline.</li>
                <?php else: ?>
                <li>Enabling Cloudflare Under Attack Mode in your Cloudflare dashboard gives instant 5-second JS challenge to all visitors — without taking your site offline.</li>
                <?php endif; ?>
            </ul>
            <?php if ($cf_available): ?>
            <a class="rtsm-action-link" href="<?php echo esc_url( admin_url('admin.php?page=mmi-rtsm&tab=cloudflare') ); ?>">→ Open Cloudflare Tab</a>
            <?php endif; ?>
        </div>

        <div class="rtsm-mitigation-item">
            <h4>⚙️ WooCommerce / WordPress quick wins</h4>
            <ul>
                <li>Verify a page cache is active (WP Rocket, LiteSpeed, Cloudflare cache rules).</li>
                <li>Ensure WooCommerce <code>?add-to-cart=</code> requests from bots are redirected before PHP runs (see Cloudflare rules).</li>
                <li>Disable WooCommerce cart fragment AJAX for unauthenticated visitors to reduce DB writes under pressure.</li>
                <li>Check for runaway WP-Cron jobs — cron firing every second = each cron spawns a PHP worker.</li>
            </ul>
        </div>

        <div class="rtsm-mitigation-item">
            <h4>🗄️ Database &amp; PHP-FPM checks</h4>
            <ul>
                <li>A <code>SHOW PROCESSLIST;</code> in MySQL will reveal locked or long-running queries driving load.</li>
                <li>PHP-FPM max_children default = 5 on shared plans — increase to 20–30 if workers are exhausted.</li>
                <li>Enable OPcache if not already active — reduces per-request PHP compile time dramatically.</li>
                <li class="rtsm-emerg-only<?php echo $severity !== 'EMERGENCY' ? ' rtsm-hidden' : ''; ?>"><strong>Consider enabling maintenance mode</strong> via WP Settings to stop all non-admin traffic while you diagnose.</li>
                <li class="rtsm-non-emerg-only<?php echo $severity === 'EMERGENCY' ? ' rtsm-hidden' : ''; ?>">Monitor via RunCloud &rarr; PHP &rarr; PHP-FPM status.</li>
            </ul>
            <a class="rtsm-action-link" href="<?php echo esc_url( admin_url('admin.php?page=mmi-rtsm&tab=processes') ); ?>">→ View Process Monitor</a>
        </div>

    </div>
</div>

<?php /* ── 3. CLOUDFLARE PROTECTION STATUS ────────────────────────────── */ ?>
<?php if ( $cf_available ): ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-cloud"></span> Cloudflare Bot Protection Status</h2>

    <?php if ( ! $cf_configured ): ?>
        <div class="rtsm-cf-row rtsm-cf-error">
            <span class="icon">❌</span>
            <div><strong>API not configured.</strong> Add your Cloudflare Zone ID and API token under <a href="<?php echo esc_url( admin_url('admin.php?page=mmi-rtsm&tab=cloudflare') ); ?>">Cloudflare settings</a>.</div>
        </div>
    <?php elseif ( $cf_circuit_active ): ?>
        <?php
            $lock_reason   = $cf_circuit_status['reason']     ?? 'Unknown reason';
            $lock_expires  = $cf_circuit_status['expires_at'] ?? 0;
            $expires_in_m  = $lock_expires ? round( ($lock_expires - time()) / 60 ) : '?';
        ?>
        <div class="rtsm-cf-row rtsm-cf-error">
            <span class="icon">🔒</span>
            <div>
                <strong>API circuit breaker active — all Cloudflare API calls are paused.</strong><br>
                <span class="rtsm-small-text">Reason: <?php echo esc_html($lock_reason); ?></span><br>
                <span class="rtsm-small-text">Auto-resets: <strong><?php echo $lock_expires ? esc_html( date('H:i', $lock_expires) . ' UTC (in ~' . $expires_in_m . ' min)' ) : 'unknown'; ?></strong></span>
                <?php if ($cf_retry_ts): ?>
                    <br><span class="rtsm-small-text rtsm-warning-text">Bot rule deployment retry scheduled: <strong><?php echo esc_html( date('H:i', $cf_retry_ts) . ' UTC' ); ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="rtsm-cf-row rtsm-cf-warn rtsm-small-text">
            <span class="icon">ℹ️</span>
            <div>The circuit breaker was introduced to prevent the Cloudflare auth lock from escalating. Bot protection rules will auto-deploy once it clears. <strong>Do not manually retry until the breaker expires</strong> — it will re-lock the API.</div>
        </div>
    <?php elseif ( $cf_bot_rules_ok ): ?>
        <div class="rtsm-cf-row rtsm-cf-ok">
            <span class="icon">✅</span>
            <div><strong>Bot rules deployed.</strong> Cloudflare is blocking/redirecting known scrapers and ?add-to-cart= injection attacks at the edge. Origin server is protected.</div>
        </div>
    <?php else: ?>
        <div class="rtsm-cf-row rtsm-cf-warn">
            <span class="icon">⏳</span>
            <div>
                <strong>Bot rules not yet deployed.</strong>
                <?php if ($cf_retry_ts): ?>
                    Scheduled retry at <strong><?php echo esc_html( date('H:i', $cf_retry_ts) . ' UTC' ); ?></strong>.
                <?php else: ?>
                    No retry scheduled. <a href="<?php echo esc_url( admin_url('admin.php?page=mmi-rtsm&tab=cloudflare') ); ?>">Deploy manually →</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* ── 4. AUTO-REMEDIATION THRESHOLDS ──────────────────────────────── */ ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-shield"></span> Auto-Remediation Thresholds
        <span class="rtsm-hint-sm">Current load: <strong id="rtsm-thresh-current-load"><?php echo esc_html($load_1min); ?></strong></span>
    </h2>

    <div class="rtsm-thresh-grid">
        <?php
        // Thresholds scale with CPU core count — aligned with the load bar severity system:
        // Elevated ≥ bar 50% (cores × 1.0), Critical ≥ bar 80% (cores × 1.6), Emergency ≥ bar 100% (cores × 2.0), Recovery < bar 25% (cores × 0.5)
        $thresh_elevated  = round( $cpu_cores * 1.0, 1 );
        $thresh_critical  = round( $cpu_cores * 1.6, 1 );
        $thresh_emergency = round( $cpu_cores * 2.0, 1 );
        $thresh_recovery  = round( $cpu_cores * 0.5, 1 );
        $thresholds = [
            [ 'key'=>'elevated',  'label'=>'Elevated',  'load'=>'≥ ' . $thresh_elevated,  'icon'=>'📝', 'bg'=>'#fffbeb', 'border'=>'#fde68a', 'txt'=>'#78350f', 'action'=>'Starts logging every high-load request — URL, IP, user-agent, memory — for forensic analysis. No site impact.', 'active'=> $load_1min >= $thresh_elevated && $load_1min < $thresh_critical ],
            [ 'key'=>'critical',  'label'=>'Critical',  'load'=>'≥ ' . $thresh_critical,  'icon'=>'🛡️', 'bg'=>'#fff7ed', 'border'=>'#fdba74', 'txt'=>'#7c2d12',
              'action' => $auto_maintenance_on
                ? 'Auto-activates WordPress maintenance mode after 3 checks spanning 120 seconds of sustained load.' . ($cf_available ? ' Cloudflare Under Attack Mode fires via the integration.' : '')
                : 'Incident flag and detailed logs written. Alerts and hooks fire — enable Auto-Remediation in Settings to also put the site into maintenance mode.',
              'active'=> $load_1min >= $thresh_critical && $load_1min < $thresh_emergency ],
            [ 'key'=>'emergency', 'label'=>'Emergency', 'load'=>'≥ ' . $thresh_emergency, 'icon'=>'🚨', 'bg'=>'#fef2f2', 'border'=>'#fca5a5', 'txt'=>'#7f1d1d',
              'action' => $auto_maintenance_on
                ? 'Immediate maintenance mode after 2 checks spanning 60 seconds. No extended grace period. All non-admin traffic blocked.' . ($cf_available ? ' Cloudflare emergency lockdown fires simultaneously.' : '')
                : 'Incident flag and detailed logs written. Alerts and hooks fire — enable Auto-Remediation in Settings to also put the site into maintenance mode.',
              'active'=> $load_1min >= $thresh_emergency ],
            [ 'key'=>'recovery',  'label'=>'Recovery',  'load'=>'< ' . $thresh_recovery,  'icon'=>'✅', 'bg'=>'#f0fdf4', 'border'=>'#fff', 'txt'=>'#14532d',
              'action' => $auto_maintenance_on
                ? 'Auto-resolve: maintenance mode lifted, incident flag cleared.' . ($cf_available ? ' Cloudflare returned to normal security level.' : '')
                : 'Incident flag cleared. Logging resumes normal cadence.',
              'active' => $load_1min < $thresh_recovery ],
        ];
        foreach ($thresholds as $t):
        ?>
        <div class="rtsm-thresh-cell <?php echo $t['active'] ? 'active' : ''; ?>" data-threshold="<?php echo esc_attr($t['key']); ?>"
             style="--thresh-bg:<?php echo esc_attr($t['bg']); ?>;--thresh-border:<?php echo esc_attr($t['border']); ?>;">
            <div class="thresh-icon"><?php echo $t['icon']; ?></div>
            <div class="thresh-label" style="--thresh-txt:<?php echo esc_attr($t['txt']); ?>;"><?php echo esc_html($t['label']); ?> (<?php echo esc_html($t['load']); ?>)</div>
            <div class="thresh-action" style="--thresh-txt:<?php echo esc_attr($t['txt']); ?>;"><?php echo esc_html($t['action']); ?></div>
            <?php if ($t['active']): ?>
                <div class="thresh-trigger" style="--thresh-txt:<?php echo esc_attr($t['txt']); ?>;">▲ CURRENTLY ACTIVE</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="rtsm-hint-top-lg">
        <strong>What is load average?</strong> It represents the average number of processes <em>waiting</em> for CPU time over 1, 5, and 15-minute windows. On this <?php echo esc_html($cpu_cores); ?>-core server, a load of <strong><?php echo esc_html($cpu_cores); ?>.0</strong> means 100% utilisation — every core is busy with no queue. A load of <strong><?php echo esc_html($cpu_cores * 2); ?>.0</strong> means cores are 200% subscribed and requests are visibly queuing.
    </p>
</div>

<?php /* ── 5. RECENT ALERTS ──────────────────────────────────────────────── */ ?>
<?php if ( ! empty($parsed_alerts) ): ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-bell"></span> Recent Alerts
        <span class="rtsm-hint-sm">Last <?php echo esc_html(count($parsed_alerts)); ?> events · newest first</span>
    </h2>

    <div class="rtsm-overflow-x">
    <table class="rtsm-alerts-table">
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
        <?php foreach ($parsed_alerts as $a):
            $load_f = (float) $a['load'];
            $load_class = $load_f >= 20 ? 'load-high' : ($load_f >= 5 ? 'load-mid' : '');
        ?>
            <tr>
                <td class="ts"><?php echo esc_html($a['ts']); ?></td>
                <td><?php echo rtsm_severity_badge($a['severity']); ?></td>
                <td class="<?php echo esc_attr($load_class); ?>"><?php echo esc_html($a['load']); ?></td>
                <td><?php echo esc_html($a['php_procs']); ?></td>
                <td><?php
                    $mp = (float) preg_replace('/[^0-9.]/', '', $a['memory']);
                    echo esc_html(number_format($mp, 1)) . '%';
                ?></td>
                <td class="url-cell" title="<?php echo esc_attr($a['url']); ?>"><?php echo esc_html($a['url'] ?: '—'); ?></td>
                <td><code class="rtsm-code-xs"><?php echo esc_html($a['ip'] ?: '—'); ?></code></td>
                <td>
                    <?php if ($a['suppressed']): ?>
                        <span class="rtsm-dev-suppressed">Dev tools (maintenance suppressed)</span>
                    <?php elseif ($a['cause']): ?>
                        <span class="rtsm-text-xs-mid"><?php
                            $cause = $a['cause'];
                            // Trim "(X.X%)" suffix from process names
                            $cause = preg_replace('/\s*\([\d.]+%\)/', '', $cause);
                            echo esc_html($cause);
                        ?></span>
                    <?php elseif ($a['dev_cpu']): ?>
                        <span class="rtsm-text-xs-hint">Dev <?php echo esc_html($a['dev_cpu']); ?> / Web <?php echo esc_html($a['web_cpu']); ?></span>
                    <?php else: ?>
                        <span class="rtsm-text-disabled">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <p class="rtsm-hint-top">
        <strong>Tip:</strong> A cluster of the same IP across multiple log entries with no referer and a scraper user-agent = bot attack. Block it at Cloudflare above. Lines marked "Dev tools" are caused by VS Code running locally and are automatically excluded from auto-remediation triggers.
    </p>
</div>
<?php elseif ( file_exists($alert_file) ): ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-bell"></span> Recent Alerts</h2>
    <p class="rtsm-text-muted">Alert log exists but contains no parseable structured entries yet. New alerts are written on every request when load is being monitored.</p>
</div>
<?php endif; ?>

<?php /* ── 6. HISTORICAL INCIDENT ANALYSIS ─────────────────────────────── */ ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-chart-line"></span> Historical Incident Analysis</h2>

    <?php if ( $stats['total_incidents'] === 0 ): ?>
        <p class="rtsm-text-muted"><strong>No high-load incidents recorded yet.</strong> Data appears here once the load exceeds 5.0 for the first time.</p>
    <?php else: ?>
        <div class="rtsm-hist-grid">
            <div class="rtsm-hist-cell">
                <div class="big" class="rtsm-text-red"><?php echo esc_html(number_format($stats['total_incidents'])); ?></div>
                <div class="lbl">Logged Incidents</div>
            </div>
            <div class="rtsm-hist-cell">
                <div class="big" class="rtsm-text-amber"><?php echo esc_html(number_format($stats['max_load'], 2)); ?></div>
                <div class="lbl">Peak Load Recorded</div>
            </div>
            <div class="rtsm-hist-cell">
                <div class="big" class="rtsm-text-blue"><?php echo esc_html(number_format($stats['avg_load'], 2)); ?></div>
                <div class="lbl">Average Load During Incidents</div>
            </div>
        </div>

        <div class="rtsm-panels">
            <div>
                <h3>📋 Incident Types
                    <span class="rtsm-hint-xs">What triggered each log entry</span>
                </h3>
                <?php if (!empty($stats['by_type'])): ?>
                <table class="rtsm-mini-table">
                    <thead><tr><th>Type</th><th>Count</th><th>%</th><th>What it means</th></tr></thead>
                    <tbody>
                    <?php
                    $type_hints = [
                        'REQUEST'  => 'A real HTTP request arrived during high load',
                        'SNAPSHOT' => 'Periodic 1-minute background health snapshot',
                        'CRITICAL' => 'Load hit the emergency auto-remediation threshold',
                    ];
                    foreach ($stats['by_type'] as $type => $count): ?>
                        <tr>
                            <td><strong><?php echo esc_html($type); ?></strong></td>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                            <td><?php echo esc_html(round(($count / $stats['total_incidents']) * 100, 1)); ?>%</td>
                            <td class="rtsm-text-xs-hint"><?php echo esc_html($type_hints[$type] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($stats['by_request_type'])): ?>
                <h3 class="rtsm-mt-16">🌐 Request Types <span class="rtsm-hint-xs">during incidents</span></h3>
                <table class="rtsm-mini-table">
                    <thead><tr><th>Type</th><th>Count</th><th>%</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['by_request_type'] as $rtype => $count): ?>
                        <tr>
                            <td><?php echo esc_html($rtype); ?></td>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                            <td><?php echo esc_html(round(($count / $stats['total_incidents']) * 100, 1)); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <div>
                <h3>🚫 Top High-Load IPs
                    <span class="rtsm-hint-xs">IPs seen most during incidents</span>
                </h3>
                <?php if (!empty($stats['top_ips'])): ?>
                <table class="rtsm-mini-table">
                    <thead><tr><th>IP Address</th><th>Hits</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($stats['top_ips'], 0, 10) as $ip => $count):
                        $cf_block_url = 'https://dash.cloudflare.com/?to=/:account/:zone/security/waf/tools/ip-access-rules';
                    ?>
                        <tr>
                            <td><code class="rtsm-code-xs"><?php echo esc_html($ip); ?></code></td>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                            <td><a href="<?php echo esc_url($cf_block_url); ?>" target="_blank" class="rtsm-cell-xs-nowrap">Block in CF →</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="rtsm-hint-sm-bare">No IP data in traffic log yet.</p>
                <?php endif; ?>

                <?php if (!empty($stats['top_urls'])): ?>
                <h3 class="rtsm-mt-16">🔗 Top High-Load URLs</h3>
                <table class="rtsm-mini-table">
                    <thead><tr><th>URL</th><th>Hits</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($stats['top_urls'], 0, 10) as $url => $count): ?>
                        <tr>
                            <td class="url-cell" class="rtsm-url-cell" title="<?php echo esc_attr($url); ?>"><?php echo esc_html($url); ?></td>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($stats['suspicious_flags'])): ?>
                <h3 class="rtsm-mt-16">🏴 Suspicious Flags</h3>
                <p class="rtsm-label-sm">Patterns detected on requests logged during incidents:</p>
                <table class="rtsm-mini-table">
                    <thead><tr><th>Flag</th><th>Count</th><th>What it means</th></tr></thead>
                    <tbody>
                    <?php
                    $flag_hints = [
                        'BOT_USER_AGENT'   => 'User-agent string contained "bot" — likely a crawler',
                        'NO_REFERER'       => 'No HTTP referer — common for direct bot requests',
                        'SLOW_REQUEST'     => 'Request took > 2 s to process — heavy DB query or blocking I/O',
                        'SUSPICIOUS_PATH'  => 'URL contained ".." or "wp-config" — potential exploit scan',
                    ];
                    foreach ($stats['suspicious_flags'] as $flag => $count): ?>
                        <tr>
                            <td><code class="rtsm-code-xs"><?php echo esc_html($flag); ?></code></td>
                            <td><?php echo esc_html(number_format($count)); ?></td>
                            <td class="rtsm-text-xs-hint"><?php echo esc_html($flag_hints[$flag] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php /* ── 7. LOG FILE MANAGEMENT ───────────────────────────────────────── */ ?>
<div class="rtsm-card">
    <h2><span class="dashicons dashicons-media-document"></span> Log File Management</h2>
    <div class="rtsm-log-grid">
        <div class="rtsm-log-section">
            <p><strong>Traffic Log</strong></p>
            <p class="rtsm-break-all"><code class="rtsm-code-xs"><?php echo esc_html($log_file); ?></code></p>
            <p><strong>Status:</strong> <?php echo $log_exists ? '✅ Active' : '❌ No data yet'; ?></p>
            <?php if ($log_exists): ?>
                <p><strong>Size:</strong> <?php echo esc_html(size_format(filesize($log_file))); ?></p>
                <div class="rtsm-action-row">
                    <button type="button" class="button button-secondary mmi-action-btn" id="download-traffic-log"><span class="dashicons dashicons-download"></span> Download</button>
                    <button type="button" class="button button-secondary mmi-action-btn" id="clear-traffic-log"><span class="dashicons dashicons-trash"></span> Clear</button>
                </div>
            <?php endif; ?>
            <p class="rtsm-file-hint">Records every request during elevated load: URL, IP, user-agent, response time, memory, suspicious flags.</p>
        </div>
        <div class="rtsm-log-section">
            <p><strong>Alert Log</strong></p>
            <p class="rtsm-break-all"><code class="rtsm-code-xs"><?php echo esc_html($alert_file); ?></code></p>
            <p><strong>Status:</strong> <?php echo file_exists($alert_file) ? '✅ Active' : '❌ No data yet'; ?></p>
            <?php if (file_exists($alert_file)): ?>
                <p><strong>Size:</strong> <?php echo esc_html(size_format(filesize($alert_file))); ?></p>
                <div class="rtsm-action-row">
                    <button type="button" class="button button-secondary mmi-action-btn" id="download-alert-log"><span class="dashicons dashicons-download"></span> Download</button>
                    <button type="button" class="button button-secondary mmi-action-btn" id="clear-alert-log"><span class="dashicons dashicons-trash"></span> Clear</button>
                </div>
            <?php endif; ?>
            <p class="rtsm-file-hint">Written on every request (not just high load) — one line per request with load, memory, PHP workers. Rotated at 5 MB.</p>
        </div>
    </div>
</div>

</div><!-- .rtsm-traffic-wrap -->

