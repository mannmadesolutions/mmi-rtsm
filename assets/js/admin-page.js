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
        thresholdsTable: '.mmi-panel-card table tbody',
        statValue:       '.stat-value',
        statSublabel:    '.stat-sublabel',
        loadCausesPanel: '#rtsm-load-causes-panel',
    };

    /* ── Severity Color Palette ─────────────────────────────────────── */
    const SEVERITY_COLORS = {
        critical: '#dc3232',
        warning:  '#f0b849',
        normal:   '#46b450',
        info:     '#2271b1',
    };

    /* ── Severity CSS Classes (map to .rtsm-stat-card.severity-* rules) */
    const SEVERITY_CLASSES = {
        critical: 'severity-critical',
        warning:  'severity-high',
        normal:   'severity-medium',
        info:     'severity-info',
    };
    const ALL_SEVERITY_CLASSES = Object.values(SEVERITY_CLASSES).join(' ');

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
            const loadClass = load1 >= THRESHOLDS.load.critical ? SEVERITY_CLASSES.critical
                            : (load1 >= THRESHOLDS.load.warning  ? SEVERITY_CLASSES.warning
                            : SEVERITY_CLASSES.normal);
            $loadBox.removeClass(ALL_SEVERITY_CLASSES).addClass(loadClass);
            $loadBox.find(SELECTORS.statValue).text(load1.toFixed(2));
            $loadBox.find(SELECTORS.statSublabel).text(`5 min: ${load5.toFixed(2)} | 15 min: ${load15.toFixed(2)}`);
        }

        // CPU Usage
        const cpu = parseFloat(data.cpu_usage || 0);
        const $cpuBox = $(SELECTORS.statCpu);
        if ($cpuBox.length) {
            const cpuClass = cpu >= THRESHOLDS.cpu.critical ? SEVERITY_CLASSES.critical
                           : (cpu >= THRESHOLDS.cpu.warning  ? SEVERITY_CLASSES.warning
                           : SEVERITY_CLASSES.info);
            $cpuBox.removeClass(ALL_SEVERITY_CLASSES).addClass(cpuClass);
            $cpuBox.find(SELECTORS.statValue).text(`${cpu.toFixed(2)}%`);
        }

        // Memory
        const memPercent = parseFloat((data.memory && data.memory.percent) || 0);
        const memUsed = (data.memory && data.memory.used) || '0';
        const memTotal = (data.memory && data.memory.total) || '0';
        const $memBox = $(SELECTORS.statMemory);
        if ($memBox.length) {
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
        $(SELECTORS.lastUpdate).text(`(Last updated: ${hh}:${mm}:${ss})`);

        // Update Performance Thresholds table if it exists
        updateThresholdsTable(data);

        // Refresh the load-cause analysis panel
        if (data.load_causes !== undefined) {
            updateCausesPanel(load1, data.load_causes);
        }
    }
    
    /**
     * Build a severity-coloured status span for the thresholds table.
     *
     * @param {string} icon    Emoji icon
     * @param {string} label   Text label (e.g. 'Critical')
     * @param {string} color   Hex color from SEVERITY_COLORS
     * @param {string} value   Formatted metric value
     * @returns {string} HTML string
     */
    function buildStatusSpan(icon, label, color, value) {
        return `<span class="rtsm-status-span" style="--status-color:${color};">${icon} ${label} (${value})</span>`;
    }

    /**
     * Update Performance Thresholds current status
     */
    function updateThresholdsTable(data) {
        const $thresholdsTable = $(SELECTORS.thresholdsTable);
        if (!$thresholdsTable.length) return;

        // Update Load Average status
        const load1 = data.load['1min'] || 0;
        let loadStatus;
        if (load1 >= THRESHOLDS.load.critical) {
            loadStatus = buildStatusSpan('🔴', 'Critical', SEVERITY_COLORS.critical, load1.toFixed(2));
        } else if (load1 >= THRESHOLDS.load.warning) {
            loadStatus = buildStatusSpan('🟡', 'Warning', SEVERITY_COLORS.warning, load1.toFixed(2));
        } else {
            loadStatus = buildStatusSpan('✅', 'Normal', SEVERITY_COLORS.normal, load1.toFixed(2));
        }
        $thresholdsTable.find('tr').eq(0).find('td').last().html(loadStatus);

        // Update CPU status
        const cpu = data.cpu_usage || 0;
        let cpuStatus;
        if (cpu >= THRESHOLDS.cpu.critical) {
            cpuStatus = buildStatusSpan('🔴', 'Critical', SEVERITY_COLORS.critical, `${cpu.toFixed(2)}%`);
        } else if (cpu >= THRESHOLDS.cpu.warning) {
            cpuStatus = buildStatusSpan('🟡', 'Warning', SEVERITY_COLORS.warning, `${cpu.toFixed(2)}%`);
        } else {
            cpuStatus = buildStatusSpan('✅', 'Normal', SEVERITY_COLORS.normal, `${cpu.toFixed(2)}%`);
        }
        $thresholdsTable.find('tr').eq(1).find('td').last().html(cpuStatus);

        // Update Memory status
        const mem = data.memory.percent || 0;
        let memStatus;
        if (mem >= THRESHOLDS.memory.critical) {
            memStatus = buildStatusSpan('🔴', 'Critical', SEVERITY_COLORS.critical, `${mem.toFixed(2)}%`);
        } else if (mem >= THRESHOLDS.memory.warning) {
            memStatus = buildStatusSpan('🟡', 'Warning', SEVERITY_COLORS.warning, `${mem.toFixed(2)}%`);
        } else {
            memStatus = buildStatusSpan('✅', 'Normal', SEVERITY_COLORS.normal, `${mem.toFixed(2)}%`);
        }
        $thresholdsTable.find('tr').eq(2).find('td').last().html(memStatus);
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

        const ELEVATED_THRESHOLD = 4.0;
        const isElevated = load1 >= ELEVATED_THRESHOLD;

        if (!isElevated) {
            $panel.hide();
            return;
        }

        $panel.show();
        const html = buildCausesHTML(causes);
        // Replace the inner content but preserve the outer card wrapper
        $panel.find('ul.rtsm-cause-list, p.rtsm-cause-none').remove();
        $panel.find('h2').after(html);
        $panel.attr('data-load', load1.toFixed(2));
    }

    /**
     * Build the inner HTML for the causes panel from the cause object.
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
            const itemClass  = stuckCount > 0 ? 'rtsm-cause-critical' : 'rtsm-cause-warning';
            const stuckBadge = stuckCount > 0
                ? ` <span class="mmi-badge error">⚠ ${stuckCount} stuck</span>`
                : '';

            const procList = (throttler.processes || []).slice(0, 6).map(function(p) {
                const statusBadge = `<span class="mmi-badge ${p.status === 'throttled' ? 'warning' : 'info'}">${escHtml(p.status)}</span>`;
                let meta = '';
                if (p.elapsed_sec != null) {
                    meta += ` <span class="rtsm-process-meta">for ${formatDuration(p.elapsed_sec)}</span>`;
                }
                if (p.is_stuck) {
                    const timeoutMin = Math.floor((p.timeout_sec || 600) / 60);
                    meta += ` <span class="mmi-badge error">⚠ Stuck</span>`
                          + ` <span class="rtsm-process-meta">(exceeded ${timeoutMin}m timeout)</span>`;
                }
                if (p.pause_count > 0) {
                    meta += ` <span class="rtsm-process-meta">(paused ${p.pause_count}×)</span>`;
                }
                return `<li><code>${escHtml(p.name)}</code> ${statusBadge}${meta}</li>`;
            }).join('');

            const detail  = procList ? `<ul class="rtsm-cause-sublist">${procList}</ul>` : '';
            const actions = [
                throttlerUrl ? `<a href="${escHtml(throttlerUrl)}" class="button button-secondary rtsm-action-btn"><span class="dashicons dashicons-controls-pause"></span> View Throttler</a>` : '',
                processesUrl ? `<a href="${escHtml(processesUrl)}" class="button button-secondary rtsm-action-btn"><span class="dashicons dashicons-list-view"></span> View Processes</a>` : '',
            ].filter(Boolean).join('');
            items.push(
                `<li class="rtsm-cause-item ${itemClass}">` +
                    `<div class="rtsm-cause-header"><span class="dashicons dashicons-controls-pause"></span> <strong>Background Processes</strong> <span class="mmi-badge warning">${escHtml(badge)}</span>${stuckBadge}</div>` +
                    (detail ? `<div class="rtsm-cause-detail">${detail}</div>` : '') +
                    (actions ? `<div class="rtsm-cause-actions">${actions}</div>` : '') +
                `</li>`
            );
        }

        /* ── 3. Overdue WP-Cron Jobs ── */
        if ((wpcron.overdue_count || 0) > 0) {
            const count      = wpcron.overdue_count;
            const isCritical = count >= 100;
            const itemClass  = isCritical ? 'rtsm-cause-critical' : 'rtsm-cause-warning';
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
                    h => `<li><code>${escHtml(h.hook)}</code> <span class="rtsm-process-meta">&mdash; ${formatDuration(h.overdue_sec)} overdue</span></li>`
                ).join('');
                detail += `<ul class="rtsm-cause-sublist">${hookRows}</ul>`;
            }

            const cronBtn  = nonce
                ? `<button type="button" class="button button-secondary rtsm-action-btn rtsm-trigger-cron" data-nonce="${escHtml(nonce)}"><span class="dashicons dashicons-update"></span> Trigger Cron Now</button>`
                : '';
            const wpTools  = `<a href="${escHtml(admin_url_tools || (rtsmAdmin.ajaxUrl || '').replace('admin-ajax.php', 'tools.php'))}" class="button button-secondary rtsm-action-btn"><span class="dashicons dashicons-admin-tools"></span> WP Tools</a>`;
            const logsBtn  = logsUrl ? `<a href="${escHtml(logsUrl)}" class="button button-secondary rtsm-action-btn"><span class="dashicons dashicons-text-page"></span> View Logs</a>` : '';

            items.push(
                `<li class="rtsm-cause-item ${itemClass}">` +
                    `<div class="rtsm-cause-header"><span class="dashicons dashicons-clock"></span> <strong>Overdue WP-Cron Jobs</strong> <span class="mmi-badge ${badgeClass}">${count.toLocaleString()} overdue</span></div>` +
                    `<div class="rtsm-cause-detail">${detail}</div>` +
                    `<div class="rtsm-cause-actions">${cronBtn}${wpTools}${logsBtn}</div>` +
                `</li>`
            );
        }

        /* ── 4. High Web Traffic ── */
        if ((attribution.web_cpu || 0) >= 50) {
            const webCpu     = parseFloat(attribution.web_cpu).toFixed(1);
            const isVeryHigh = parseFloat(attribution.web_cpu) >= 150;
            const itemClass  = isVeryHigh ? 'rtsm-cause-critical' : 'rtsm-cause-warning';
            const badgeClass = isVeryHigh ? 'error'      : 'warning';
            const detail     = 'Open Traffic Analysis to see the top URLs and IPs. If it looks like an attack, block it in Cloudflare (WAF rule or Under Attack Mode in the Cloudflare dashboard).';
            const trafficBtn = trafficUrl
                ? `<a href="${escHtml(trafficUrl)}" class="button button-secondary rtsm-action-btn"><span class="dashicons dashicons-chart-line"></span> Traffic Analysis</a>`
                : '';
            items.push(
                `<li class="rtsm-cause-item ${itemClass}">` +
                    `<div class="rtsm-cause-header"><span class="dashicons dashicons-chart-area"></span> <strong>High Web Traffic</strong> <span class="mmi-badge ${badgeClass}">${webCpu}% web CPU</span></div>` +
                    `<div class="rtsm-cause-detail">${detail}</div>` +
                    (trafficBtn ? `<div class="rtsm-cause-actions">${trafficBtn}</div>` : '') +
                `</li>`
            );
        }

        /* ── 5. Developer Tools ── */
        if ((attribution.dev_cpu || 0) >= 5) {
            const procs = (attribution.top_dev_processes || []).slice(0, 3).map(p => `<code>${escHtml(p)}</code>`).join(', ');
            items.push(
                `<li class="rtsm-cause-item rtsm-cause-info">` +
                    `<div class="rtsm-cause-header"><span class="dashicons dashicons-editor-code"></span> <strong>Developer Tools</strong> <span class="mmi-badge info">${parseFloat(attribution.dev_cpu).toFixed(1)}% CPU</span></div>` +
                    `<div class="rtsm-cause-detail">${procs ? `Processes: ${procs}. ` : ''}Maintenance mode is suppressed.</div>` +
                `</li>`
            );
        }

        if (!items.length) {
            return '<p class="rtsm-cause-none"><span class="dashicons dashicons-yes-alt rtsm-icon-success"></span> No specific cause identified — load may be transient.</p>';
        }
        return `<ul class="rtsm-cause-list">${items.join('')}</ul>`;
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
                    $btn.html('<span class="dashicons dashicons-yes-alt"></span> Cron triggered!').addClass('rtsm-btn-success');
                    setTimeout(function() {
                        $btn.prop('disabled', false)
                            .html('<span class="dashicons dashicons-update"></span> Trigger Cron Now')
                            .removeClass('rtsm-btn-success');
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
        // Only run on RTSM admin pages
        if ($(SELECTORS.dashboardStats).length) {
            // Start auto-refresh
            setInterval(updateDashboardStats, refreshInterval);

            // Initial update after 2 seconds (let page load first)
            setTimeout(updateDashboardStats, 2000);
        }

        // Delegated handler for "Trigger Cron Now" buttons
        $(document).on('click', '.rtsm-trigger-cron', function() {
            triggerWPCron(this);
        });
    });
    
})(jQuery);
