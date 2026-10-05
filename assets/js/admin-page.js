/**
 * Real-Time Server Monitor - Admin Page JavaScript
 * 
 * Auto-refresh functionality for dashboard stats
 * 
 * @package MMI_RTSM
 * @since 2.1.14
 */

(function($) {
    'use strict';
    
    /* ── Configuration ──────────────────────────────────────────────── */
    const config = window.rtsmAdmin || {};
    const ajaxUrl = config.ajaxUrl || window.ajaxurl;
    const nonce = config.nonce || '';
    // Use configured refresh interval (converted to milliseconds)
    const refreshInterval = (config.refreshInterval || 10) * 1000;

    /* ── DOM Selectors ──────────────────────────────────────────────── */
    const SELECTORS = {
        dashboardStats:  '#rtsm-dashboard-stats',
        statLoad:        '#rtsm-stat-load',
        statCpu:         '#rtsm-stat-cpu',
        statMemory:      '#rtsm-stat-memory',
        statConnections: '#rtsm-stat-connections',
        lastUpdate:      '#rtsm-last-update',
        statValue:       '.mmi-stat-value',
        statSublabel:    '.mmi-stat-meta',
        progress:        '.rtsm-progress',
        progressFill:    '.rtsm-progress-fill',
        loadCausesPanel: '#rtsm-load-causes-panel',
        causesContent:   '.mmi-section-content',
        clearIncident:   '.rtsm-clear-incident',
        incidentSection: '.rtsm-incident-section',
    };

    /* ── Shared state words (.mmi-stat-box / .mmi-badge / .rtsm-progress) ── */
    const VARIANTS = {
        critical: 'error',
        warning:  'warning',
        normal:   'success',
    };
    const ALL_VARIANTS = 'success warning error info';

    /* ── Performance Thresholds ─────────────────────────────────────── */
    // Values sourced from PHP (RTSM_UI_Helpers::get_thresholds) when available.
    const _pt = config.thresholds || {};
    const THRESHOLDS = {
        load:   { critical: (_pt.load   && _pt.load.critical)   || 6.0,
                  warning:  (_pt.load   && _pt.load.elevated)   || 4.0 },
        cpu:    { critical: (_pt.cpu    && _pt.cpu.critical)    || 80,
                  warning:  (_pt.cpu    && _pt.cpu.warning)     || 60 },
        memory: { critical: (_pt.memory && _pt.memory.critical) || 90,
                  warning:  (_pt.memory && _pt.memory.warning)  || 75 },
    };
    
    /**
     * Update dashboard stats
     */
    function updateDashboardStats() {
        // Safety checks
        if (typeof ajaxUrl === 'undefined') {
            console.error('RTSM: ajaxUrl not defined');
            return;
        }
        
        if (!nonce) {
            console.error('RTSM: Security nonce missing');
            return;
        }
        
        $.post(ajaxUrl, { 
            action: 'rtsm_get_stats', 
            nonce: nonce 
        }, function(response) {
            if (response.success) {
                updateStatsDisplay(response.data);
            }
        }).fail(function(xhr, status, error) {
            console.error('RTSM Stats Update Error:', status, error);
        });
    }
    
    /**
     * Update stats display in the grid
     */
    function updateStatsDisplay(data) {
        // Load Average
        const load1 = parseFloat((data.load && data.load['1min']) || 0);
        const load5 = parseFloat((data.load && data.load['5min']) || 0);
        const load15 = parseFloat((data.load && data.load['15min']) || 0);
        const $loadBox = $(SELECTORS.statLoad);
        if ($loadBox.length) {
            setTileState($loadBox, variantFor(load1, THRESHOLDS.load), Math.min((load1 / 12) * 100, 100));
            $loadBox.find(SELECTORS.statValue).text(load1.toFixed(2));
            $loadBox.find(SELECTORS.statSublabel).text(`5 min: ${load5.toFixed(2)} | 15 min: ${load15.toFixed(2)}`);
        }

        // CPU Usage
        const cpu = parseFloat(data.cpu_usage || 0);
        const $cpuBox = $(SELECTORS.statCpu);
        if ($cpuBox.length) {
            setTileState($cpuBox, variantFor(cpu, THRESHOLDS.cpu), Math.min(cpu, 100));
            $cpuBox.find(SELECTORS.statValue).text(`${cpu.toFixed(2)}%`);
        }

        // Memory
        const memPercent = parseFloat((data.memory && data.memory.percent) || 0);
        const memUsed = (data.memory && data.memory.used) || '0';
        const memTotal = (data.memory && data.memory.total) || '0';
        const $memBox = $(SELECTORS.statMemory);
        if ($memBox.length) {
            setTileState($memBox, variantFor(memPercent, THRESHOLDS.memory), memPercent);
            $memBox.find(SELECTORS.statValue).text(`${memPercent.toFixed(2)}%`);
            $memBox.find(SELECTORS.statSublabel).text(`${memUsed} MB / ${memTotal} MB`);
        }

        // Connections
        const connections = typeof data.connections === 'object'
            ? (data.connections.count || 0)
            : (data.connections || 0);
        const $connBox = $(SELECTORS.statConnections);
        if ($connBox.length) {
            $connBox.find(SELECTORS.statValue).text(connections);
        }

        // Last-updated timestamp
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        $(SELECTORS.lastUpdate).text(`Last updated ${hh}:${mm}:${ss}`);

        // Refresh the load-cause analysis panel
        if (data.load_causes !== undefined) {
            updateCausesPanel(load1, data.load_causes);
        }
    }
    
    /**
     * Map a metric onto a shared state word using its {critical, warning} pair.
     *
     * @param {number} value
     * @param {Object} limits  { critical, warning }
     * @returns {string} success|warning|error
     */
    function variantFor(value, limits) {
        if (value >= limits.critical) return VARIANTS.critical;
        if (value >= limits.warning)  return VARIANTS.warning;
        return VARIANTS.normal;
    }

    /**
     * Recolor a stat tile and its progress bar.
     *
     * @param {jQuery} $box
     * @param {string} variant
     * @param {number} pct      0-100 progress fill
     */
    function setTileState($box, variant, pct) {
        $box.removeClass(ALL_VARIANTS).addClass(variant);
        const $bar = $box.find(SELECTORS.progress);
        $bar.removeClass('is-success is-warning is-error is-info').addClass(`is-${variant}`);
        const fill = $bar.find(SELECTORS.progressFill)[0];
        if (fill) fill.style.setProperty('--fill-pct', `${pct.toFixed(1)}%`);
    }

    /* ── Load Cause Analysis Panel ──────────────────────────────── */

    /**
     * Refresh the #rtsm-load-causes-panel element from the AJAX payload.
     *
     * @param {number} load1      Current 1-min load average
     * @param {Object} causes     load_causes from AJAX response
     */
    function updateCausesPanel(load1, causes) {
        const $panel = $(SELECTORS.loadCausesPanel);
        if (!$panel.length) return;

        // Same rule as dashboard.php's $show_causes: elevated load, an
        // active incident, or overdue WP-Cron jobs (the cron health email
        // links here, so it must stay visible at normal load too).
        const ELEVATED_THRESHOLD = 4.0;
        const overdue = (causes && causes.wpcron && causes.wpcron.overdue_count) || 0;
        const show = load1 >= ELEVATED_THRESHOLD || overdue > 0 || $(SELECTORS.incidentSection).length > 0;

        $panel.prop('hidden', !show);
        if (!show) return;

        // Rebuild the whole section: the server may have rendered only the
        // empty placeholder.
        $panel.html(buildPanelHTML(load1, causes)).attr('data-load', load1.toFixed(2));
    }

    /**
     * Section header + severity bar + cause cards — mirrors
     * templates/partials/card-load-causes.php.
     *
     * @param {number} load1
     * @param {Object} causes
     * @returns {string} HTML string
     */
    function buildPanelHTML(load1, causes) {
        const logsUrl = rtsmAdmin.logsUrl || '';
        let label = 'Normal', variant = 'success';
        if (load1 >= 8.0)      { label = 'Critical'; variant = 'error'; }
        else if (load1 >= 6.0) { label = 'High';     variant = 'warning'; }
        else if (load1 >= 4.0) { label = 'Elevated'; variant = 'warning'; }
        const pct = Math.min((load1 / 12) * 100, 100).toFixed(1);
        const logsBtn = logsUrl
            ? `<div class="rtsm-section-actions"><a href="${escHtml(logsUrl)}" class="button button-secondary"><span class="dashicons dashicons-media-text"></span> View Activity Logs</a></div>`
            : '';

        return `<div class="mmi-section-header"><div>` +
                `<h3 class="mmi-process-section-header"><span class="dashicons dashicons-performance"></span> Load Cause Analysis</h3>` +
                `<p class="mmi-process-section-description">Why load is elevated and what to do about it. Refreshes live.</p>` +
            `</div>${logsBtn}</div>` +
            `<div class="mmi-section-content">` +
                `<div class="rtsm-severity">` +
                    `<div class="rtsm-severity-label mmi-text-${variant}">${label} — Load ${load1.toFixed(2)}</div>` +
                    `<div class="rtsm-progress is-${variant}"><span class="rtsm-progress-fill" style="--fill-pct:${pct}%;"></span></div>` +
                `</div>` +
                buildCausesHTML(causes) +
            `</div>`;
    }

    /**
     * Build the cause cards from the cause object.
     *
     * @param {Object} causes
     * @returns {string} HTML string
     */
    function buildCausesHTML(causes) {
        const throttler   = causes.throttler   || {};
        const attribution = causes.attribution || {};
        const wpcron      = causes.wpcron      || {};

        // Page URLs come from PHP localization
        const throttlerUrl  = rtsmAdmin.throttlerUrl  || '';
        const trafficUrl    = rtsmAdmin.trafficUrl    || '';
        const logsUrl       = rtsmAdmin.logsUrl       || '';
        const processesUrl  = rtsmAdmin.processesUrl  || '';
        const nonce         = rtsmAdmin.nonce         || '';
        // Derive WP Tools URL from the admin-ajax.php URL (strip query + filename)
        const admin_url_tools = (rtsmAdmin.ajaxUrl || '').replace(/\/admin-ajax\.php.*$/, '/tools.php');

        const items = [];

        /* ── 2. Background Processes ── */
        if (throttler.active) {
            const running    = throttler.running_count  || 0;
            const throttled  = throttler.throttled_count || 0;
            const stuckCount = throttler.stuck_count || 0;
            const badge      = `${running} running${throttled > 0 ? `, ${throttled} throttled` : ''}`;
            const itemClass  = stuckCount > 0 ? 'error inline' : 'warning';
            const stuckBadge = stuckCount > 0
                ? ` <span class="mmi-badge error">⚠ ${stuckCount} stuck</span>`
                : '';

            const procList = (throttler.processes || []).slice(0, 6).map(function(p) {
                const statusBadge = `<span class="mmi-badge ${p.status === 'throttled' ? 'warning' : 'info'}">${escHtml(p.status)}</span>`;
                let meta = '';
                if (p.elapsed_sec != null) {
                    meta += ` <span class="mmi-text-muted">for ${formatDuration(p.elapsed_sec)}</span>`;
                }
                if (p.is_stuck) {
                    const timeoutMin = Math.floor((p.timeout_sec || 600) / 60);
                    meta += ` <span class="mmi-badge error">⚠ Stuck</span>`
                          + ` <span class="mmi-text-muted">(exceeded ${timeoutMin}m timeout)</span>`;
                }
                if (p.pause_count > 0) {
                    meta += ` <span class="mmi-text-muted">(paused ${p.pause_count}×)</span>`;
                }
                return `<li><code>${escHtml(p.name)}</code> ${statusBadge}${meta}</li>`;
            }).join('');

            const detail  = procList ? `<ul>${procList}</ul>` : '';
            const actions = [
                throttlerUrl ? `<a href="${escHtml(throttlerUrl)}" class="button button-secondary"><span class="dashicons dashicons-controls-pause"></span> View Throttler</a>` : '',
                processesUrl ? `<a href="${escHtml(processesUrl)}" class="button button-secondary"><span class="dashicons dashicons-list-view"></span> View Processes</a>` : '',
            ].filter(Boolean).join('');
            items.push(
                `<div class="mmi-info-card ${itemClass}">` +
                    `<h3><span class="dashicons dashicons-controls-pause"></span> <strong>Background Processes</strong> <span class="mmi-badge warning">${escHtml(badge)}</span>${stuckBadge}</h3>` +
                    (detail ? `<div class="rtsm-cause-detail">${detail}</div>` : '') +
                    (actions ? `<div class="mmi-toolbar">${actions}</div>` : '') +
                `</div>`
            );
        }

        /* ── 3. Overdue WP-Cron Jobs ── */
        if ((wpcron.overdue_count || 0) > 0) {
            const count      = wpcron.overdue_count;
            const isCritical = count >= 100;
            const itemClass  = isCritical ? 'error inline' : 'warning';
            const badgeClass = isCritical ? 'error'      : 'warning';

            let detail = '';
            if (count >= 1000) {
                detail = `<strong>Critical:</strong> ${count.toLocaleString()} overdue jobs will all fire together on the next HTTP request, causing a severe load spike. Investigate immediately for stuck or runaway hooks.`;
            } else if (count >= 100) {
                detail = `${count.toLocaleString()} overdue jobs fire on the next incoming request and will spike PHP load. Check for stuck or runaway scheduled tasks.`;
            } else {
                detail = `Overdue tasks fire on the next incoming request, spiking PHP load.`;
            }

            // Top overdue hooks
            const overduedHooks = wpcron.overdue_hooks || [];
            if (overduedHooks.length > 0) {
                const hookRows = overduedHooks.map(
                    h => `<li><code>${escHtml(h.hook)}</code> <span class="mmi-text-muted">&mdash; ${formatDuration(h.overdue_sec)} overdue</span></li>`
                ).join('');
                detail += `<ul>${hookRows}</ul>`;
            }

            const cronBtn  = nonce
                ? `<button type="button" class="button button-secondary rtsm-trigger-cron" data-nonce="${escHtml(nonce)}"><span class="dashicons dashicons-update"></span> Trigger Cron Now</button>`
                : '';
            const wpTools  = `<a href="${escHtml(admin_url_tools || (rtsmAdmin.ajaxUrl || '').replace('admin-ajax.php', 'tools.php'))}" class="button button-secondary"><span class="dashicons dashicons-admin-tools"></span> WP Tools</a>`;
            const logsBtn  = logsUrl ? `<a href="${escHtml(logsUrl)}" class="button button-secondary"><span class="dashicons dashicons-text-page"></span> View Logs</a>` : '';

            items.push(
                `<div class="mmi-info-card ${itemClass}">` +
                    `<h3><span class="dashicons dashicons-clock"></span> <strong>Overdue WP-Cron Jobs</strong> <span class="mmi-badge ${badgeClass}">${count.toLocaleString()} overdue</span></h3>` +
                    `<div class="rtsm-cause-detail">${detail}</div>` +
                    `<div class="mmi-toolbar">${cronBtn}${wpTools}${logsBtn}</div>` +
                `</div>`
            );
        }

        /* ── 4. High Web Traffic ── */
        if ((attribution.web_cpu || 0) >= 50) {
            const webCpu     = parseFloat(attribution.web_cpu).toFixed(1);
            const isVeryHigh = parseFloat(attribution.web_cpu) >= 150;
            const itemClass  = isVeryHigh ? 'error inline' : 'warning';
            const badgeClass = isVeryHigh ? 'error'      : 'warning';
            const detail     = 'Open Traffic Analysis to see the top URLs and IPs. If it looks like an attack, block it in Cloudflare (WAF rule or Under Attack Mode in the Cloudflare dashboard).';
            const trafficBtn = trafficUrl
                ? `<a href="${escHtml(trafficUrl)}" class="button button-secondary"><span class="dashicons dashicons-chart-line"></span> Traffic Analysis</a>`
                : '';
            items.push(
                `<div class="mmi-info-card ${itemClass}">` +
                    `<h3><span class="dashicons dashicons-chart-area"></span> <strong>High Web Traffic</strong> <span class="mmi-badge ${badgeClass}">${webCpu}% web CPU</span></h3>` +
                    `<div class="rtsm-cause-detail">${detail}</div>` +
                    (trafficBtn ? `<div class="mmi-toolbar">${trafficBtn}</div>` : '') +
                `</div>`
            );
        }

        /* ── Scheduled Backup ── */
        if ((attribution.backup_cpu || 0) >= 10) {
            const backupCpu = parseFloat(attribution.backup_cpu);
            const procs = (attribution.top_backup_processes || []).map(p => escHtml(p)).join(', ');
            const note  = backupCpu >= 100 ? '<br><strong>Note:</strong> CPU usage exceeds one full core — consider rescheduling the backup to an off-peak window in RunCloud.' : '';
            items.push(
                `<div class="mmi-info-card">` +
                    `<h3><span class="dashicons dashicons-backup"></span> <strong>Scheduled Backup Running</strong> <span class="mmi-badge info">${backupCpu.toFixed(1)}% CPU</span></h3>` +
                    `<div class="rtsm-cause-detail">${procs ? `<strong>Processes:</strong> <code>${procs}</code>. ` : ''}Backup processes are CPU- and I/O-intensive but temporary. Server load will drop when the backup completes.${note}</div>` +
                `</div>`
            );
        }

        /* ── Memory Pressure ── */
        const mem = causes.memory_pressure || {};
        if (mem.high) {
            const memPct    = parseFloat(mem.percent || 0);
            const swapUsed  = parseInt(mem.swap_used_mb || 0, 10);
            const swapTotal = parseInt(mem.swap_total_mb || 0, 10);
            const critical  = memPct >= 95;
            let detail;
            if (swapTotal > 0 && swapUsed > 0) {
                detail = `<strong>Swap active:</strong> ${swapUsed.toLocaleString()} MB in use of ${swapTotal.toLocaleString()} MB. Swapping inflates load averages because CPU waits for disk I/O.`;
            } else if (critical) {
                detail = 'Memory is near capacity. PHP-FPM workers may be killed by the OOM killer, causing worker churn and elevated load.';
            } else {
                detail = 'High memory usage can cause PHP-FPM workers to enter swap, degrading response times.';
            }
            items.push(
                `<div class="mmi-info-card ${critical ? 'error inline' : 'warning'}">` +
                    `<h3><span class="dashicons dashicons-dashboard"></span> <strong>Memory Pressure</strong> <span class="mmi-badge ${critical ? 'error' : 'warning'}">${memPct.toFixed(1)}% used</span></h3>` +
                    `<div class="rtsm-cause-detail">${detail} Consider reducing the number of PHP-FPM workers, lowering <code>pm.max_children</code>, or upgrading your server RAM.</div>` +
                `</div>`
            );
        }

        /* ── 5. Developer Tools ── */
        if ((attribution.dev_cpu || 0) >= 5) {
            const procs = (attribution.top_dev_processes || []).slice(0, 3).map(p => `<code>${escHtml(p)}</code>`).join(', ');
            items.push(
                `<div class="mmi-info-card">` +
                    `<h3><span class="dashicons dashicons-editor-code"></span> <strong>Developer Tools</strong> <span class="mmi-badge info">${parseFloat(attribution.dev_cpu).toFixed(1)}% CPU</span></h3>` +
                    `<div class="rtsm-cause-detail">${procs ? `Processes: ${procs}. ` : ''}Maintenance mode is suppressed.</div>` +
                `</div>`
            );
        }

        if (!items.length) {
            return '<p class="rtsm-cause-none"><span class="dashicons dashicons-yes-alt mmi-text-success"></span> No specific cause identified — load may be transient or within normal variance.</p>';
        }
        return `<div class="rtsm-cause-list">${items.join('')}</div>`;
    }

    /**
     * Format a duration in seconds into a human-readable string.
     *
     * @param {number} sec
     * @returns {string}
     */
    function formatDuration(sec) {
        sec = Math.floor(sec);
        if (sec >= 86400) return Math.floor(sec / 86400) + 'd ' + Math.floor((sec % 86400) / 3600) + 'h';
        if (sec >= 3600)  return Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm';
        if (sec >= 60)    return Math.floor(sec / 60) + 'm ' + (sec % 60) + 's';
        return sec + 's';
    }

    /**
     * Trigger WP-Cron via the rtsm_spawn_cron AJAX handler.
     *
     * @param {HTMLElement} btn
     */
    function triggerWPCron(btn) {
        const $btn  = $(btn);
        const nonce = $btn.data('nonce') || rtsmAdmin.nonce;
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update"></span> Triggering…');
        $.post(rtsmAdmin.ajaxUrl, { action: 'rtsm_spawn_cron', nonce })
            .done(function(res) {
                if (res.success) {
                    $btn.html('<span class="dashicons dashicons-yes-alt"></span> Cron triggered!');
                    setTimeout(function() {
                        $btn.prop('disabled', false)
                            .html('<span class="dashicons dashicons-update"></span> Trigger Cron Now');
                    }, 3000);
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Trigger Cron Now');
                }
            })
            .fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Trigger Cron Now');
            });
    }

    /**
     * Minimal HTML-escape helper (avoids XSS in dynamically inserted content).
     *
     * @param {string|number} str
     * @returns {string}
     */
    function escHtml(str) {
        return window.MMIEscapeHtml(str);
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Poll on an interval but only fetch while the Dashboard tab is in
        // the DOM — it may arrive later via rtsm_load_tab, so don't gate the
        // timer on its presence at page load.
        const tick = function() {
            if ($(SELECTORS.dashboardStats).length) updateDashboardStats();
        };
        setInterval(tick, refreshInterval);
        setTimeout(tick, 2000);
        $(document).on('rtsm_tab_loaded', function(event, tabName) {
            if (tabName === 'dashboard') tick();
        });

        // "Clear incident now" (was an inline <script> in card-active-incident.php)
        $(document).on('click', SELECTORS.clearIncident, function() {
            const $btn = $(this);
            $btn.prop('disabled', true).addClass('mmi-is-loading').html('<span class="mmi-loading"></span> Clearing…');
            $.post(ajaxUrl, { action: 'rtsm_resolve_incident', nonce })
                .done(function() {
                    $btn.closest(SELECTORS.incidentSection).remove();
                    $('#mmi-incident-notice').remove();
                })
                .fail(function() {
                    $btn.prop('disabled', false).removeClass('mmi-is-loading')
                        .html('<span class="dashicons dashicons-dismiss"></span> Clear incident now');
                });
        });

        // Delegated handler for "Trigger Cron Now" buttons
        $(document).on('click', '.rtsm-trigger-cron', function() {
            triggerWPCron(this);
        });
    });
    
})(jQuery);
