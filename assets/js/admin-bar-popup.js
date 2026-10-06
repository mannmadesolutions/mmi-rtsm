/**
 * Real-Time Server Monitor - Admin Bar Popup JavaScript
 * 
 * Extracted from inline script for proper caching
 * 
 * FIX (2026-02-12): Use getNonce() function instead of const nonce to get fresh
 * nonce values that are automatically refreshed by the heartbeat system. This prevents
 * AJAX errors after nonces expire.
 * 
 * @package MMI_RTSM
 * @since 2.1.1
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


    /* ── State ─────────────────────────────────────────────────────────── */
    let popupInterval, isOpen = false, currentFilter = 'all', popupBuilt = false, isPremium = false, hasPro = false;
    let prevBarStats         = {};
    let prevPopupStats       = {};
    let countdownSecondsLeft = 0;
    let countdownTickTimer   = null;
    /* ── Config ────────────────────────────────────────────────────────── */
    const config          = window.rtsmConfig || {};
    // Getter always reads the live config so heartbeat-refreshed nonces are picked up
    const getNonce        = () => (window.rtsmConfig && window.rtsmConfig.nonce) || config.nonce || '';
    const upgradeUrl      = config.upgradeUrl  || '';
    const settingsUrl     = config.settingsUrl || '';
    const refreshInterval = parseInt(config.refreshInterval || 10) * 1000;
    const i18n            = config.i18n || {};

    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        adminBarItem:      '#wp-admin-bar-rtsm-server-monitor',
        adminBarLink:      '#wp-admin-bar-rtsm-server-monitor .ab-item',
        popup:             '#rtsm-popup',
        popupContent:      '#rtsm-popup-content',
        closeBtn:          '#rtsm-close',
        processTable:      '#rtsm-process-table',
        timestamp:         '#rtsm-timestamp',
        popupGrid:         '.rtsm-popup-grid',
        serverIpHeading:   '.rtsm-server-ip-heading',
        connectionDetails: '.rtsm-connection-details',
        filterButton:      '.rtsm-filter button',
    };

    /* ── Messages ──────────────────────────────────────────────────────── */
    const MESSAGES = {
        securityFailed:     'Security verification failed. Please refresh the page.',
        connectionFailed:   'Connection failed. Please check your network.',
        errorLoading:       'Error loading processes',
        serverMonitorError: '⚠️ Server Monitor Error',
        connectionError:    '⚠️ Connection Error',
        serverIpLabel:      'Server IP: %ip%',
        lastUpdated:        'Last: %time% • %interval%s',
        topIpsLabel:        'Top IPs:\n',
        topConnSubtitle:    'Top: %ip% (%count%)',
        activeConnTitle:    '🌐 Active Connection IPs',
    };

    /* ── Thresholds ────────────────────────────────────────────────────── */
    // Values sourced from PHP (RTSM_UI_Helpers::get_thresholds) so all display
    // layers stay in sync. Hardcoded numbers are fallbacks only.
    const _t = config.thresholds || {};
    const THRESHOLDS = {
        loadCrit:       (_t.load   && _t.load.critical)   || 8.0,
        loadWarn:       (_t.load   && _t.load.elevated)   || 4.0,
        cpuCrit:        (_t.cpu    && _t.cpu.critical)    || 80,
        cpuWarn:        (_t.cpu    && _t.cpu.warning)     || 60,
        maxConnDisplay: 10,
        tooltipConns:   5,
    };

    /* ── Table config ──────────────────────────────────────────────────── */
    const TABLE_CONFIG = {
        columns:    7,
        sampleRows: 8,
    };

    /* ── Filter config ─────────────────────────────────────────────────── */
    const FILTER_TYPES = ['all', 'high-cpu', 'php', 'python', 'node', 'mysql'];

    const FILTER_LABELS = {
        'all':      'All',
        'high-cpu': 'High CPU',
        'php':      'PHP',
        'python':   'Python',
        'node':     'Node',
        'mysql':    'MySQL',
    };

    /* ── Status classes ────────────────────────────────────────────────── */
    const STATUS_CLASSES = { DANGER: 'danger', WARNING: 'warning', SUCCESS: 'success' };
    
    /**
     * Kill a process.
     * @param {number}  pid   - Process ID
     * @param {boolean} force - Whether to force-kill
     */
    function killProcess(pid, force) {
        if (!hasPro) {
            showUpgradePrompt();
            return;
        }

        const forceLabel = force ? ' (FORCE)' : '';
        const confirmMsg = `${i18n.killProcess || 'Kill process'} ${pid}?${forceLabel}`;
        if (!confirm(confirmMsg)) return;

        $.post((config.ajaxUrl || window.ajaxurl), {
            action: 'rtsm_kill_process',
            nonce:  getNonce(),
            pid,
            force
        }, function(r) {
            if (r.success) {
                alert(r.data.message);
                loadProcesses(currentFilter);
            } else {
                const tryForceMsg = `${r.data}\n\n${i18n.tryForceKill || 'Try force kill?'}`;
                if (confirm(tryForceMsg)) { killProcess(pid, true); }
            }
        });
    }
    
    /**
     * Helper function to safely get connection count
     */
    function getConnectionCount(connections) {
        if (typeof connections === 'object' && connections !== null) {
            return connections.count !== undefined ? connections.count : 0;
        }
        return parseInt(connections) || 0;
    }
    
    /**
     * Helper function to get connection details
     */
    function getConnectionDetails(connections) {
        if (typeof connections === 'object' && connections !== null && Array.isArray(connections.details)) {
            return connections.details;
        }
        return [];
    }
    
    /**
     * Load processes for the given filter type.
     * @param {string} filter
     */
    function loadProcesses(filter) {
        if (!hasPro) {
            showUpgradePrompt();
            return;
        }

        currentFilter = filter;

        /* Update active filter button */
        $(SELECTORS.filterButton).removeClass('active');
        $(`${SELECTORS.filterButton}[data-filter="${filter}"]`).addClass('active');

        /* Show loading */
        $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-table-loading">${i18n.loading || 'Loading...'}</td></tr>`);

        if (!getNonce()) {
            $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-table-error">${MESSAGES.securityFailed}</td></tr>`);
            return;
        }

        $.post((config.ajaxUrl || window.ajaxurl), {
            action: 'rtsm_get_processes',
            nonce:  getNonce(),
            filter
        }, function(r) {
            if (r.success) {
                renderProcesses(r.data.processes);
            } else {
                if (r.data && r.data.code === 'nonce_verification_failed') {
                    $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-table-error">${MESSAGES.securityFailed}</td></tr>`);
                } else {
                    showUpgradePrompt();
                }
            }
        }).fail(function(xhr) {
            const errorMessage = xhr.status === 403 ? MESSAGES.securityFailed
                               : xhr.status === 0   ? MESSAGES.connectionFailed
                               : MESSAGES.errorLoading;
            $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-table-error">${errorMessage}</td></tr>`);
        });
    }
    
    /**
     * Render the process table rows.
     * @param {Array} procs
     */
    function renderProcesses(procs) {
        if (!procs || !procs.length) {
            $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-table-empty">${i18n.noProcesses || 'No processes'}</td></tr>`);
            return;
        }

        const rows = procs.map(function(p) {
            const cpuClass = p.cpu >= THRESHOLDS.cpuCrit ? 'rtsm-cpu-critical'
                           : p.cpu >= THRESHOLDS.cpuWarn ? 'rtsm-cpu-warning'
                           : '';
            const killBtn  = p.killable
                ? `<button class="rtsm-kill-btn" data-pid="${esc(p.pid)}">${i18n.kill || 'Kill'}</button>`
                : '<span class="rtsm-process-system-lock" title="System">🔒</span>';

            return `<tr>
                <td>${esc(p.pid)}</td>
                <td>${esc(p.user)}</td>
                <td class="${cpuClass}">${p.cpu}%</td>
                <td>${p.mem}%</td>
                <td>${esc(p.elapsed || '-')}</td>
                <td title="${esc(p.command)}">${esc(p.command)}</td>
                <td>${killBtn}</td>
            </tr>`;
        }).join('');

        $(SELECTORS.processTable).html(rows);
    }
    
    /**
     * Show upgrade prompt with obfuscated sample data.
     */
    function showUpgradePrompt() {
        let sampleRows = '';
        for (let i = 0; i < TABLE_CONFIG.sampleRows; i++) {
            sampleRows += `<tr>
                <td>████</td>
                <td>████████</td>
                <td>██.█%</td>
                <td>█.█%</td>
                <td>██:██</td>
                <td>████████████████████████████████████████</td>
                <td>🔒</td>
            </tr>`;
        }

        const html = `
            <div class="rtsm-preview-wrapper">
                <table class="rtsm-table rtsm-preview-table">
                    <thead><tr><th>PID</th><th>User</th><th>CPU</th><th>Mem</th><th>Time</th><th>Command</th><th>Kill</th></tr></thead>
                    <tbody>${sampleRows}</tbody>
                </table>
                <div class="rtsm-popup-overlay">
                    <div class="rtsm-popup-overlay-content">
                        <div class="rtsm-lock-icon">🔓</div>
                        <h3 class="rtsm-upgrade-heading">${i18n.unlockPro || 'Unlock Pro Features'}</h3>
                        <p class="rtsm-upgrade-description">${i18n.proDescription || 'Get real-time process monitoring with kill capabilities, advanced filters, and detailed metrics'}</p>
                        <div class="rtsm-upgrade-features">
                            <span class="rtsm-feature-pill">⚡ Real-time</span>
                            <span class="rtsm-feature-pill">🎯 Kill Processes</span>
                            <span class="rtsm-feature-pill">📊 CPU Track</span>
                            <span class="rtsm-feature-pill">💾 Memory</span>
                        </div>
                        <div class="rtsm-upgrade-buttons">
                            <a href="${upgradeUrl}" target="_blank" class="rtsm-upgrade-btn rtsm-upgrade-btn-primary">
                                ${i18n.upgradeNow || 'Upgrade Now'}
                            </a>
                            <a href="${settingsUrl}" class="rtsm-upgrade-btn rtsm-upgrade-btn-secondary">
                                <span class="rtsm-license-icon">🔑</span> ${i18n.activateLicense || 'Activate License'}
                            </a>
                        </div>
                    </div>
                </div>
            </div>`;

        $(SELECTORS.processTable).html(`<tr><td colspan="${TABLE_CONFIG.columns}" class="rtsm-upgrade-table-cell">${html}</td></tr>`);
    }
    
    /**
     * Build the popup HTML skeleton (runs once; guarded by popupBuilt flag).
     */
    function buildPopup() {
        if (popupBuilt) return;

        /* Build filter buttons using top-level FILTER_TYPES / FILTER_LABELS constants */
        let filterButtons = '';
        if (hasPro) {
            const buttons = FILTER_TYPES.map(function(filter) {
                const active = currentFilter === filter ? ' active' : '';
                const label  = FILTER_LABELS[filter] || filter.charAt(0).toUpperCase() + filter.slice(1);
                return `<button data-filter="${filter}" class="${active}">${label}</button>`;
            }).join('');
            filterButtons = `<div class="rtsm-filter">${buttons}</div>`;
        }

        const html = `<div class="rtsm-popup-grid"></div>
            <h4>${i18n.processMonitor || 'Process Monitor'}</h4>
            ${filterButtons}
            <div class="rtsm-table-wrapper">
                <table class="rtsm-table">
                    <thead><tr><th>PID</th><th>User</th><th>CPU</th><th>Mem</th><th>Time</th><th>Command</th><th>Kill</th></tr></thead>
                    <tbody id="rtsm-process-table"></tbody>
                </table>
            </div>
            <div class="rtsm-popup-footer">
                <p id="rtsm-timestamp"></p>
                <div class="rtsm-countdown-bar">
                    <div class="rtsm-countdown-fill" id="rtsm-countdown-fill"></div>
                </div>
            </div>`;

        $(SELECTORS.popupContent).html(html);

        if (hasPro) {
            $(SELECTORS.filterButton).on('click', function() {
                loadProcesses($(this).data('filter'));
            });
        }

        /* Event delegation for dynamically-added kill buttons */
        $(document).on('click', '.rtsm-kill-btn', function(e) {
            e.preventDefault();
            const pid = $(this).data('pid');
            if (pid) { killProcess(pid, false); }
        });

        popupBuilt = true;
    }
    
    /**
     * Fetch fresh stats from the server and update all surfaces.
     */
    function update() {
        if (!config.ajaxUrl && typeof ajaxurl === 'undefined') { return; }
        if (!getNonce()) { return; }

        $.post((config.ajaxUrl || window.ajaxurl), { action: 'rtsm_get_stats', nonce: getNonce() }, function(r) {
            if (r.success) {
                const wasPro = hasPro;
                isPremium    = r.data.is_premium || false;
                hasPro       = r.data.has_pro     || false;

                updateAdminBar(r.data);
                /* Broadcast so any co-loaded tab (e.g. traffic) can share this
                   same response instead of firing an independent AJAX poll. */
                document.dispatchEvent(new CustomEvent('rtsm:stats', { detail: r.data }));
                if (isOpen) {
                    updatePopup(r.data);

                    /* Pro status just became true mid-session: rebuild popup to remove overlay */
                    if (hasPro && !wasPro && popupBuilt) {
                        popupBuilt = false;
                        buildPopup();
                        loadProcesses(currentFilter);
                    }
                }
                resetCountdown();
            } else {
                $(SELECTORS.adminBarLink).html(`<span class="rtsm-error-message">${MESSAGES.serverMonitorError}</span>`);
            }
        }).fail(function() {
            $(SELECTORS.adminBarLink).html(`<span class="rtsm-error-message">${MESSAGES.connectionError}</span>`);
        });
    }

    /**
     * Reset the popup countdown to the full refresh interval.
     * Starts the progress-bar depletion animation and "Next in Xs" tick.
     */
    function resetCountdown() {
        countdownSecondsLeft = Math.ceil(refreshInterval / 1000);
        clearInterval(countdownTickTimer);

        /* Snap bar back to full width, then animate depletion over the interval */
        const $fill = $('#rtsm-countdown-fill');
        if ($fill.length) {
            $fill.css({ transition: 'none', width: '100%' });
            void $fill[0].offsetWidth; /* force reflow so transition resets cleanly */
            $fill.css({ transition: `width ${refreshInterval}ms linear`, width: '0%' });
        }

        /* Admin bar SVG ring: animate stroke-dashoffset from 0 (full) → 31.42 (empty) */
        const ringFill = document.getElementById('rtsm-bar-ring-fill');
        if (ringFill) {
            ringFill.style.transition = 'none';
            ringFill.style.strokeDashoffset = '0';
            void ringFill.getBoundingClientRect(); /* force reflow for SVG */
            ringFill.style.transition = `stroke-dashoffset ${refreshInterval}ms linear`;
            ringFill.style.strokeDashoffset = '31.42';
        }

        /* Update the inline text counter every second */
        $('#rtsm-next-in').text(countdownSecondsLeft + 's');
        countdownTickTimer = setInterval(function() {
            countdownSecondsLeft--;
            if (countdownSecondsLeft <= 0) {
                countdownSecondsLeft = 0;
                clearInterval(countdownTickTimer);
            }
            $('#rtsm-next-in').text(countdownSecondsLeft + 's');
        }, 1000);
    }
    
    /**
     * Flash a jQuery element to signal a value change.
     * @param {jQuery} $el
     */
    function flashValue($el) {
        $el.addClass('rtsm-value-updated').one('animationend', function() {
            $(this).removeClass('rtsm-value-updated');
        });
    }

    /**
     * Update the admin bar status strip.
     * @param {object} d - Stats payload
     */
    function updateAdminBar(d) {
        if (!d || !d.load || !d.cpu_usage || !d.memory) { return; }

        const l          = d.load['1min'];
        const icon       = l >= THRESHOLDS.loadCrit ? '🔴' : (l >= THRESHOLDS.loadWarn ? '🟡' : '📊');
        const colorClass = l >= THRESHOLDS.loadCrit ? STATUS_CLASSES.DANGER
                         : l >= THRESHOLDS.loadWarn ? STATUS_CLASSES.WARNING
                         : STATUS_CLASSES.SUCCESS;
        const connCount  = getConnectionCount(d.connections);

        const loadStr = l.toFixed(2);
        const cpuStr  = parseFloat(d.cpu_usage).toFixed(2) + '%';
        const memStr  = parseFloat(d.memory.percent).toFixed(2) + '%';
        const connStr = String(connCount);

        /* SVG circle: r=5 → circumference = 2π×5 ≈ 31.42 */
        const newHtml = `<span class="rtsm-admin-bar-content">
            <span>${icon}</span>
            <span class="load-value ${colorClass}">${loadStr}</span>
            <span class="separator">|</span>
            <span class="cpu-value">CPU: ${cpuStr}</span>
            <span class="separator">|</span>
            <span class="mem-value">Mem: ${memStr}</span>
            <span class="separator">|</span>
            <span class="conn-value">Conn: ${connStr}</span>
            <span class="rtsm-ring-wrap" aria-hidden="true">
                <svg class="rtsm-ring" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                    <circle class="rtsm-ring-track" cx="7" cy="7" r="5"/>
                    <circle class="rtsm-ring-fill" id="rtsm-bar-ring-fill" cx="7" cy="7" r="5"/>
                </svg>
            </span>
        </span>`;

        $(SELECTORS.adminBarLink).html(newHtml);

        /* Animate spans whose values changed since the last update */
        if (prevBarStats.load !== undefined) {
            const $link = $(SELECTORS.adminBarLink);
            if (prevBarStats.load !== loadStr) flashValue($link.find('.load-value'));
            if (prevBarStats.cpu  !== cpuStr)  flashValue($link.find('.cpu-value'));
            if (prevBarStats.mem  !== memStr)  flashValue($link.find('.mem-value'));
            if (prevBarStats.conn !== connStr) flashValue($link.find('.conn-value'));
        }
        prevBarStats = { load: loadStr, cpu: cpuStr, mem: memStr, conn: connStr };
    }
    
    /**
     * Update the open popup with fresh stats.
     * @param {object} d - Stats payload
     */
    function updatePopup(d) {
        const lc          = d.load['1min'] >= THRESHOLDS.loadCrit ? STATUS_CLASSES.DANGER
                          : d.load['1min'] >= THRESHOLDS.loadWarn ? STATUS_CLASSES.WARNING
                          : STATUS_CLASSES.SUCCESS;
        const connCount   = getConnectionCount(d.connections);
        const connDetails = getConnectionDetails(d.connections);
        const serverIp    = d.server_ip || 'Unknown';

        /* Build tooltip for connections card */
        let connTooltip = '';
        if (connDetails.length > 0) {
            connTooltip = MESSAGES.topIpsLabel;
            connDetails.slice(0, THRESHOLDS.tooltipConns).forEach(function(conn) {
                connTooltip += `${conn.ip}: ${conn.count} conn\n`;
            });
        }

        const firstConn   = connDetails[0] || null;
        const connSubHtml = firstConn
            ? `<div class="rtsm-popup-subtitle">Top: ${esc(firstConn.ip)} (${esc(firstConn.count)})</div>`
            : '';

        const newLoad = parseFloat(d.load['1min']).toFixed(2);
        const newCpu  = parseFloat(d.cpu_usage).toFixed(2) + '%';
        const newMem  = d.memory.used + 'MB';
        const newConn = String(connCount);

        const statsHtml = `
            <div class="rtsm-popup-card ${lc}">
                <h4>${i18n.load1m || 'Load (1m)'}</h4>
                <div class="rtsm-popup-value ${lc}" data-metric="load">${newLoad}</div>
                <div class="rtsm-popup-subtitle">5m: ${parseFloat(d.load['5min']).toFixed(2)} | 15m: ${parseFloat(d.load['15min']).toFixed(2)}</div>
            </div>
            <div class="rtsm-popup-card">
                <h4>${i18n.cpu || 'CPU'}</h4>
                <div class="rtsm-popup-value" data-metric="cpu">${newCpu}</div>
            </div>
            <div class="rtsm-popup-card">
                <h4>${i18n.memory || 'Memory'}</h4>
                <div class="rtsm-popup-value" data-metric="mem">${newMem}</div>
                <div class="rtsm-popup-subtitle">${parseFloat(d.memory.percent).toFixed(2)}% of ${d.memory.total}MB</div>
            </div>
            <div class="rtsm-popup-card" title="${esc(connTooltip)}">
                <h4>${i18n.connections || 'Connections'}</h4>
                <div class="rtsm-popup-value" data-metric="conn">${newConn}</div>
                ${connSubHtml}
            </div>`;

        $(SELECTORS.popupGrid).html(statsHtml);

        /* Animate card values that changed since the last update */
        if (prevPopupStats.load !== undefined) {
            const $grid = $(SELECTORS.popupGrid);
            if (prevPopupStats.load !== newLoad) flashValue($grid.find('[data-metric="load"]'));
            if (prevPopupStats.cpu  !== newCpu)  flashValue($grid.find('[data-metric="cpu"]'));
            if (prevPopupStats.mem  !== newMem)  flashValue($grid.find('[data-metric="mem"]'));
            if (prevPopupStats.conn !== newConn) flashValue($grid.find('[data-metric="conn"]'));
        }
        prevPopupStats = { load: newLoad, cpu: newCpu, mem: newMem, conn: newConn };

        /* Server IP heading */
        const ipLabel = MESSAGES.serverIpLabel.replace('%ip%', serverIp);
        if ($(SELECTORS.serverIpHeading).length) {
            $(SELECTORS.serverIpHeading).html(ipLabel);
        } else {
            $(SELECTORS.popupGrid).after(`<h3 class="rtsm-server-ip-heading">${ipLabel}</h3>`);
        }

        /* Connection details section */
        let connectionDetailsHtml = '';
        if (connDetails.length > 0) {
            const ipRows = connDetails.slice(0, THRESHOLDS.maxConnDisplay).map(function(conn) {
                const barWidth = Math.min((conn.count / connDetails[0].count) * 100, 100);
                return `<div class="rtsm-connection-ip-row">
                    <code class="rtsm-connection-ip-code">${esc(conn.ip)}</code>
                    <div class="rtsm-connection-ip-bar">
                        <div class="rtsm-connection-ip-bar-fill" style="--bar-width:${barWidth}%"></div>
                    </div>
                </div>
                <div class="rtsm-connection-ip-count">${conn.count}</div>`;
            }).join('');

            connectionDetailsHtml = `<div class="rtsm-connection-details-box">
                <h4 class="rtsm-connection-details-title">${MESSAGES.activeConnTitle}</h4>
                <div class="rtsm-connection-details-grid">${ipRows}</div>
            </div>`;
        }

        const $connDetails = $(SELECTORS.connectionDetails);
        if ($connDetails.length) {
            $connDetails.html(connectionDetailsHtml);
        } else {
            $(SELECTORS.serverIpHeading).after(`<div class="rtsm-connection-details">${connectionDetailsHtml}</div>`);
        }

        const interval  = refreshInterval / 1000;
        const timeLabel = MESSAGES.lastUpdated
            .replace('%time%', d.timestamp)
            .replace('%interval%', interval);
        $(SELECTORS.timestamp).html(
            timeLabel + ' &bull; <span class="rtsm-next-in-label">Next in <span id="rtsm-next-in">' +
            Math.ceil(refreshInterval / 1000) + 's</span></span>'
        );

        if (!hasPro && popupBuilt) {
            showUpgradePrompt();
        } else if (hasPro && popupBuilt) {
            const $processTd = $(SELECTORS.processTable + ' td');
            if (!$processTd.length || $processTd.text().includes('Loading')) {
                loadProcesses(currentFilter);
            }
        }
    }
    
    /* ── Init ──────────────────────────────────────────────────────────── */
    $(document).ready(function() {

        /* Toggle popup */
        $(SELECTORS.adminBarItem).on('click', function(e) {
            e.preventDefault();
            isOpen = !isOpen;
            $(SELECTORS.popup).toggleClass('active');

            if (isOpen) {
                $.post((config.ajaxUrl || window.ajaxurl), { action: 'rtsm_get_stats', nonce: getNonce() }, function(r) {
                    if (r.success) {
                        isPremium = r.data.is_premium || false;
                        hasPro    = r.data.has_pro    || false;

                        buildPopup();
                        updateAdminBar(r.data);
                        updatePopup(r.data);
                        resetCountdown();

                        if (hasPro) {
                            loadProcesses(currentFilter);
                        } else {
                            showUpgradePrompt();
                        }
                    }
                });

                popupInterval = setInterval(update, refreshInterval);
            } else {
                if (popupInterval) {
                    clearInterval(popupInterval);
                    popupInterval = null;
                }
            }
        });

        /* Close button */
        $(SELECTORS.closeBtn).on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            isOpen = false;
            $(SELECTORS.popup).removeClass('active');
            if (popupInterval) {
                clearInterval(popupInterval);
                popupInterval = null;
            }
        });

        /* Click outside to close */
        $(document).on('click', function(e) {
            if (isOpen &&
                !$(e.target).closest(SELECTORS.popup).length &&
                !$(e.target).closest(SELECTORS.adminBarItem).length) {
                isOpen = false;
                $(SELECTORS.popup).removeClass('active');
                if (popupInterval) {
                    clearInterval(popupInterval);
                    popupInterval = null;
                }
            }
        });

        /* Background updates when popup is closed */
        setInterval(function() {
            if (!isOpen) { update(); }
        }, refreshInterval);

        /* Initial update */
        update();
    });

})(jQuery);
