/**
 * MMI CloudFlare Protection - Dashboard Widget JavaScript
 * 
 * FIX (2026-02-12): Use dynamic nonce from rtsmWidgetConfig to get fresh
 * nonce values that are automatically refreshed by the heartbeat system.
 */

(function($) {
    'use strict';

    /**
     * HTML-escape server data (log URLs, IPs, user agents, process command
     * lines) before it goes into .html()/template strings. Delegates to the
     * shared MMIEscapeHtml when loaded; this script doesn't declare that
     * handle as a dependency, so a local fallback is kept.
     *
     * @param {*} str
     * @returns {string}
     */
    function esc(str) {
        if (typeof window.MMIEscapeHtml === 'function') {
            return window.MMIEscapeHtml(str);
        }
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        widgetContent:   '#rtsm-dashboard-widget-content',
        processTable:    '.rtsm-process-table',
        serverIpHeading: '.rtsm-server-ip-heading',
    };

    /* ── Thresholds ────────────────────────────────────────────────────── */
    // Values sourced from PHP (RTSM_UI_Helpers::get_thresholds) so all UI layers stay in sync.
    const _wt = (typeof rtsmWidgetConfig !== 'undefined' && rtsmWidgetConfig.thresholds) || {};
    const THRESHOLDS = {
        loadCrit:      (_wt.load   && _wt.load.critical)   || 6.0,
        loadWarn:      (_wt.load   && _wt.load.elevated)   || 4.0,
        cpuCrit:       (_wt.cpu    && _wt.cpu.critical)    || 80,
        cpuWarn:       (_wt.cpu    && _wt.cpu.warning)     || 60,
        memCrit:       (_wt.memory && _wt.memory.critical) || 90,
        memWarn:       (_wt.memory && _wt.memory.warning)  || 75,
        procCpuCrit:   50,
        procCpuWarn:   25,
        narrowWidget:  400,
        resizeDebounce: 250,
    };

    /* ── Status classes ────────────────────────────────────────────────── */
    const STATUS_CLASSES = { DANGER: 'danger', WARNING: 'warning', SUCCESS: 'success' };

    /* ── Messages ──────────────────────────────────────────────────────── */
    const MESSAGES = {
        configError:    'Configuration error. Please refresh the page.',
        nonceError:     'Security verification missing. Please refresh the page.',
        securityFailed: 'Security verification failed. Please refresh the page.',
        connectionFail: 'Connection failed. Please check your network.',
        statsError:     'Failed to load server statistics. Please check your server configuration.',
    };

    let isPremium = false;
    let prevWidgetStats         = {};
    let widgetCountdownSecsLeft = 0;
    let widgetCountdownTimer    = null;
    /* Matches the admin bar popup interval when that script is also loaded */
    const widgetInterval = (window.rtsmConfig
        ? parseInt(window.rtsmConfig.refreshInterval || 10)
        : parseInt((typeof rtsmWidgetConfig !== 'undefined' && rtsmWidgetConfig.refreshInterval) || 10)
    ) * 1000;
    
    // Helper function to get fresh nonce
    const getNonce = () => {
        if (typeof rtsmWidgetConfig !== 'undefined' && rtsmWidgetConfig.nonce) {
            return rtsmWidgetConfig.nonce;
        }
        return '';
    };
    
    $(document).ready(function() {
        if (typeof rtsmWidgetConfig === 'undefined' || !rtsmWidgetConfig.nonce) {
            console.error('RTSM Dashboard Widget: Configuration or nonce missing. Config:', rtsmWidgetConfig);
            $(SELECTORS.widgetContent).html(`<div class="rtsm-dashboard-error"><p>${MESSAGES.configError}</p></div>`);
            return;
        }
        
        /* When the admin bar popup script is present it dispatches 'rtsm:stats'
           after every successful fetch.  Subscribe to that shared event so all
           three surfaces (admin bar, dashboard widget, traffic tab) always show
           the same snapshot — no extra AJAX calls needed. */
        if (window.rtsmConfig) {
            document.addEventListener('rtsm:stats', function (e) {
                isPremium = e.detail.is_premium || false;
                renderDashboardWidget(e.detail);
            });
            /* One immediate fetch in case admin bar hasn't fired yet on load. */
            updateDashboardWidget();
        } else {
            /* Admin bar not on this page; poll independently. */
            updateDashboardWidget();
            const refreshInterval = (rtsmWidgetConfig.refreshInterval || 10) * 1000;
            setInterval(updateDashboardWidget, refreshInterval);
        }
    });
    
    function updateDashboardWidget() {
        const nonce = getNonce();
        if (!nonce) {
            $(SELECTORS.widgetContent).html(`<div class="rtsm-dashboard-error"><p>${MESSAGES.nonceError}</p></div>`);
            console.error('RTSM Dashboard Widget: Nonce not available');
            return;
        }

        $.ajax({
            url: (typeof rtsmWidgetConfig !== 'undefined' && rtsmWidgetConfig.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'rtsm_get_stats',
                nonce
            },
            success: function(response) {
                if (response.success) {
                    isPremium = response.data.is_premium || false;
                    renderDashboardWidget(response.data);
                } else {
                    let errorMessage = 'Error loading stats';
                    if (response.data && response.data.code === 'nonce_verification_failed') {
                        errorMessage = MESSAGES.securityFailed;
                    } else if (response.data && response.data.message) {
                        errorMessage = response.data.message;
                    } else if (typeof response.data === 'string') {
                        errorMessage = response.data;
                    }
                    $(SELECTORS.widgetContent).html(`<div class="rtsm-dashboard-error"><p>${errorMessage}</p></div>`);
                }
            },
            error: function(xhr) {
                const errorMessage = xhr.status === 403 ? MESSAGES.securityFailed
                                   : xhr.status === 0   ? MESSAGES.connectionFail
                                   : MESSAGES.statsError;
                $(SELECTORS.widgetContent).html(`<div class="rtsm-dashboard-error"><p>${errorMessage}</p></div>`);
            }
        });
    }
    
    function renderDashboardWidget(data) {
        const load1 = parseFloat(data.load['1min']);
        const cpuValue = data.cpu && data.cpu.percent ? parseFloat(data.cpu.percent) : parseFloat(data.cpu_usage || 0);
        const memValue = parseFloat(data.memory.percent || 0);
        
        // Handle connections - could be a number or an object with count property
        let connections = 0;
        if (typeof data.connections === 'object' && data.connections !== null) {
            connections = parseInt(data.connections.count || 0);
        } else {
            connections = parseInt(data.connections || 0);
        }
        
        const load1Class = load1 >= THRESHOLDS.loadCrit ? STATUS_CLASSES.DANGER : (load1 >= THRESHOLDS.loadWarn ? STATUS_CLASSES.WARNING : STATUS_CLASSES.SUCCESS);
        const cpuClass   = cpuValue >= THRESHOLDS.cpuCrit ? STATUS_CLASSES.DANGER : (cpuValue >= THRESHOLDS.cpuWarn ? STATUS_CLASSES.WARNING : STATUS_CLASSES.SUCCESS);
        const memClass   = memValue >= THRESHOLDS.memCrit ? STATUS_CLASSES.DANGER : (memValue >= THRESHOLDS.memWarn ? STATUS_CLASSES.WARNING : STATUS_CLASSES.SUCCESS);
        
        let processSection = '';
        
        if (isPremium) {
            // Premium: Show actual process data (same structure as popup)
            let processRows = '';
            if (data.processes && Array.isArray(data.processes) && data.processes.length > 0) {
                data.processes.forEach(function(proc, index) {
                    // Match the popup's field access pattern exactly
                    // Popup uses: proc.user, proc.cpu, proc.mem, proc.command
                    const user = proc.user || 'unknown';
                    const command = proc.command || proc.cmd || proc.name || proc.process || 'Unknown Process';
                    const cpu = parseFloat(proc.cpu) || 0;
                    const mem = parseFloat(proc.mem || proc.memory) || 0;
                    
                    // Display format: "user: command" (matching popup style)
                    const displayName = `${user}: ${command}`;

                    const cpuClass = cpu >= THRESHOLDS.procCpuCrit ? 'rtsm-cpu-cell-danger' : (cpu >= THRESHOLDS.procCpuWarn ? 'rtsm-cpu-cell-warning' : '');
                    
                    processRows += `<tr>
                        <td title="${esc(displayName)}">${esc(displayName)}</td>
                        <td class="${cpuClass}">${cpu}%</td>
                        <td>${mem}%</td>
                    </tr>`;
                });
            }
            
            processSection = `
                <h3 class="rtsm-dashboard-widget-heading">Top Processes</h3>
                <div class="rtsm-table-wrapper">
                    <table class="rtsm-process-table">
                        <thead>
                            <tr>
                                <th>Process</th>
                                <th>CPU</th>
                                <th>Memory</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${processRows}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            // Free: Show real process data (read-only — no kill button)
            let processRows = '';
            if (data.processes && Array.isArray(data.processes) && data.processes.length > 0) {
                data.processes.forEach(function(proc) {
                    const user    = proc.user || 'unknown';
                    const command = proc.command || proc.cmd || proc.name || proc.process || 'Unknown';
                    const cpu     = parseFloat(proc.cpu) || 0;
                    const mem     = parseFloat(proc.mem || proc.memory) || 0;
                    const cpuCls  = cpu >= THRESHOLDS.procCpuCrit ? 'rtsm-cpu-cell-danger'
                                  : cpu >= THRESHOLDS.procCpuWarn ? 'rtsm-cpu-cell-warning' : '';
                    processRows += `<tr>
                        <td title="${esc(user)}: ${esc(command)}">${esc(user)}: ${esc(command)}</td>
                        <td class="${cpuCls}">${cpu}%</td>
                        <td>${mem}%</td>
                    </tr>`;
                });
            } else {
                processRows = `<tr><td colspan="3" class="rtsm-no-process-td">No process data</td></tr>`;
            }

            processSection = `
                <h3 class="rtsm-dashboard-widget-heading">Top Processes <small class="rtsm-process-upgrade-note">(read-only &mdash; <a href="${rtsmWidgetConfig.upgradeUrl}" target="_blank">upgrade</a> for kill)</small></h3>
                <div class="rtsm-table-wrapper">
                    <table class="rtsm-process-table">
                        <thead>
                            <tr>
                                <th>Process</th>
                                <th>CPU</th>
                                <th>Memory</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${processRows}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        const serverIp = data.server_ip || 'Unknown';

        // Build cause analysis mini-summary (only when load is elevated and causes available)
        let causeSection = '';
        if (load1 >= THRESHOLDS.loadWarn && data.load_causes) {
            const causes = data.load_causes;
            const items = [];
            if (causes.cloudflare && causes.cloudflare.under_attack) {
                const since = causes.cloudflare.since ? ` since ${causes.cloudflare.since}` : '';
                items.push(`🛡️ Cloudflare Under Attack Mode active${since}`);
            }
            if (causes.throttler && causes.throttler.active) {
                items.push(`⚙️ Throttler: ${causes.throttler.running_count} running / ${causes.throttler.throttled_count} throttled`);
            }
            if (causes.wpcron && causes.wpcron.overdue_count > 0) {
                items.push(`🕐 WP-Cron: ${causes.wpcron.overdue_count} overdue job(s)`);
            }
            if (items.length > 0) {
                const listItems = items.map(i => `<li>${i}</li>`).join('');
                causeSection = `<div class="rtsm-dashboard-section rtsm-cause-summary">
                    <h3 class="rtsm-dashboard-widget-heading">Load Causes</h3>
                    <ul class="rtsm-cause-list">${listItems}</ul>
                </div>`;
            }
        }

        const newLoadStr = load1.toFixed(2);
        const newCpuStr  = cpuValue.toFixed(2) + '%';
        const newMemStr  = data.memory.used + 'MB';
        const newConnStr = String(connections);

        const html = `
            <div class="rtsm-grid">
                <div class="rtsm-card ${load1Class}">
                    <h4>Load (1m)</h4>
                    <div class="rtsm-value ${load1Class}" data-metric="load">${newLoadStr}</div>
                    <div class="rtsm-subtitle">5m: ${parseFloat(data.load['5min']).toFixed(2)} | 15m: ${parseFloat(data.load['15min']).toFixed(2)}</div>
                </div>
                <div class="rtsm-card">
                    <h4>CPU Usage</h4>
                    <div class="rtsm-value ${cpuClass}" data-metric="cpu">${newCpuStr}</div>
                    <div class="rtsm-subtitle">${data.cpu && data.cpu.cores ? data.cpu.cores + ' cores' : ''}</div>
                </div>
                <div class="rtsm-card">
                    <h4>Memory</h4>
                    <div class="rtsm-value ${memClass}" data-metric="mem">${newMemStr}</div>
                    <div class="rtsm-subtitle">${memValue.toFixed(2)}% of ${data.memory.total}MB</div>
                </div>
                <div class="rtsm-card">
                    <h4>Connections</h4>
                    <div class="rtsm-value" data-metric="conn">${newConnStr}</div>
                    <div class="rtsm-subtitle">Active</div>
                </div>
            </div>
            <h3 class="rtsm-server-ip-heading">
                Server IP: ${serverIp}
            </h3>
            ${processSection}
            ${causeSection}
            <div class="rtsm-dashboard-links">
                <a href="${rtsmWidgetConfig.settingsUrl}" class="button button-small">View Full Dashboard →</a>
            </div>
            <div class="rtsm-widget-footer">
                <span>Next in <span id="rtsm-widget-next-in">--</span></span>
                <div class="rtsm-widget-countdown-bar">
                    <div class="rtsm-widget-countdown-fill" id="rtsm-widget-countdown-fill"></div>
                </div>
            </div>
        `;
        
        $(SELECTORS.widgetContent).html(html);

        /* Animate card values that changed since the last update */
        if (prevWidgetStats.load !== undefined) {
            const $wc = $(SELECTORS.widgetContent);
            if (prevWidgetStats.load !== newLoadStr) flashWidgetValue($wc.find('[data-metric="load"]'));
            if (prevWidgetStats.cpu  !== newCpuStr)  flashWidgetValue($wc.find('[data-metric="cpu"]'));
            if (prevWidgetStats.mem  !== newMemStr)  flashWidgetValue($wc.find('[data-metric="mem"]'));
            if (prevWidgetStats.conn !== newConnStr) flashWidgetValue($wc.find('[data-metric="conn"]'));
        }
        prevWidgetStats = { load: newLoadStr, cpu: newCpuStr, mem: newMemStr, conn: newConnStr };

        resetWidgetCountdown();
    }
    
    // Handle viewport resize (dev tools opening/closing)
    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(adjustTableLayout, THRESHOLDS.resizeDebounce);
    });

    /**
     * Flash a jQuery element to signal a value change.
     * @param {jQuery} $el
     */
    function flashWidgetValue($el) {
        $el.addClass('rtsm-value-updated').one('animationend', function() {
            $(this).removeClass('rtsm-value-updated');
        });
    }

    /**
     * Reset the widget countdown to the full refresh interval.
     * Restarts the progress-bar depletion animation and "Next in Xs" tick.
     */
    function resetWidgetCountdown() {
        widgetCountdownSecsLeft = Math.ceil(widgetInterval / 1000);
        clearInterval(widgetCountdownTimer);

        /* Snap bar back to full, then animate depletion */
        const $fill = $('#rtsm-widget-countdown-fill');
        if ($fill.length) {
            $fill.css({ transition: 'none', width: '100%' });
            void $fill[0].offsetWidth; /* force reflow */
            $fill.css({ transition: `width ${widgetInterval}ms linear`, width: '0%' });
        }

        $('#rtsm-widget-next-in').text(widgetCountdownSecsLeft + 's');
        widgetCountdownTimer = setInterval(function() {
            widgetCountdownSecsLeft--;
            if (widgetCountdownSecsLeft <= 0) {
                widgetCountdownSecsLeft = 0;
                clearInterval(widgetCountdownTimer);
            }
            $('#rtsm-widget-next-in').text(widgetCountdownSecsLeft + 's');
        }, 1000);
}

    /**
     * Adjust table layout for narrow container widths.
     */
    function adjustTableLayout() {
        const $widget = $(SELECTORS.widgetContent);
        const $tables = $widget.find(SELECTORS.processTable);

        if ($tables.length > 0) {
            const containerWidth = $widget.width();

            if (containerWidth < THRESHOLDS.narrowWidget) {
                $tables.addClass('rtsm-table-scroll');
            } else {
                $tables.removeClass('rtsm-table-scroll');
            }
        }
    }

})(jQuery);
