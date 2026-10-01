/* CloudGuard Dashboard Tab JavaScript */
jQuery(document).ready(function($) {

    /* ── Config ────────────────────────────────────────────────────────── */
    const cfg = (typeof mmiCfAdmin !== 'undefined') ? mmiCfAdmin : null;

    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        connectionStatus:   '#cf-connection-status',
        currentLevel:       '#cf-current-level',
        actionResult:       '#cf-action-result',
        testConnection:     '#cf-test-connection',
        refreshStatus:      '#cf-refresh-status',
        testAutoProtect:    '#cf-test-auto-protect',
        refreshDiagnostics: '#cf-refresh-diagnostics',
        diagnosticResults:  '#diagnostic-results',
        diagnosticDetails:  '#diagnostic-details',
        timeRange:          '#traffic-time-range',
        trafficGraph:       '.traffic-graph-container',
        trafficChart:       '#trafficChart',
        attackerChart:      '#attackerChart',
        topAttackingIps:    '#top-attacking-ips',
        spikeCount:         '.spike-count',
        cfActions:          '.mmi-panel-actions, .mmi-action-buttons',
        cardLoad:           '.mmi-metric-card[data-metric="load"]',
        cardCpu:            '.mmi-metric-card[data-metric="cpu"]',
        cardMemory:         '.mmi-metric-card[data-metric="memory"]',
    };

    /* ── Thresholds ────────────────────────────────────────────────────── */
    const THRESHOLDS = {
        loadCrit: 3.0,
        loadWarn: 1.5,
        cpuCrit:  80,
        cpuWarn:  60,
        memCrit:  90,
        memWarn:  75,
    };

    /* ── Status classes ────────────────────────────────────────────────── */
    const STATUS_CLASSES = {
        HEALTHY:        'status-healthy',
        WARNING:        'status-warning',
        CRITICAL:       'status-critical',
        NOTICE_SUCCESS: 'notice-success',
        NOTICE_ERROR:   'notice-error',
    };

    /* ── AJAX actions ──────────────────────────────────────────────────── */
    const AJAX_ACTIONS = {
        testConnection:     'mmi_cf_test_connection',
        getCurrentLevel:    'mmi_cf_get_current_level',
        setSecurityLevel:   'mmi_cf_set_security_level',
        testAutoProtect:    'mmi_cf_test_auto_protect',
        getTrafficData:     'mmi_cf_get_traffic_data',
        analyzeDiagnostics: 'mmi_cf_analyze_diagnostics',
        serverMetrics:      'mmi_get_server_metrics',
    };

    /* ── Chart colors ──────────────────────────────────────────────────── */
    const CHART_COLORS = {
        primary:      '#d63638',
        primaryAlpha: 'rgba(214, 54, 56, 0.1)',
        criticalLine: '#dc3232',
        elevatedLine: '#f0b849',
        labelColor:   '#2c3338',
        palette:      ['#d63638', '#f0b849', '#45852C', '#2271b1', '#50575e'],
    };

    /* ── Chart annotation thresholds ───────────────────────────────────── */
    const CHART_THRESHOLDS = {
        criticalLoad: 15,
        elevatedLoad:  5,
    };

    /* ── Intervals ─────────────────────────────────────────────────────── */
    const INTERVALS = {
        metricsRefresh: 5000,
        initialDelay:   1000,
        noticeDuration: 5000,
    };

    /* ── Messages ──────────────────────────────────────────────────────── */
    const MESSAGES = {
        scriptError:       '✗ Script Error',
        connected:         '✓ Connected',
        failed:            '✗ Failed',
        notConfigured:     'Not Configured',
        notAvailable:      'Not Available',
        loadDetails:       '1m: %1min% | 5m: %5min% | 15m: %15min%',
        loadingInterval:   'Loading %interval% data...',
        noAttackData:      'No attack data available for this time range.',
        failedTrafficData: 'Failed to load traffic data.',
        failedRequest:     'Request failed. Please try again.',
        failedDiagnostics: 'Failed to generate diagnostics.',
        attackerEntry:     '%count% requests, max load %maxLoad%',
        scriptOutput:      'Script executed. Output:<br><pre class="rtsm-script-output">%output%</pre>',
    };

    /* ── Helper functions ──────────────────────────────────────────────── */

    /**
     * Show a dismissible inline notice in the action result container.
     * @param {string} type    - 'success' or 'error'
     * @param {string} message - HTML message content
     */
    function showNotice(type, message) {
        const noticeClass = type === 'success' ? STATUS_CLASSES.NOTICE_SUCCESS : STATUS_CLASSES.NOTICE_ERROR;
        const $notice     = $(`<div class="notice ${noticeClass} is-dismissible"><p>${message}</p></div>`);
        $(SELECTORS.actionResult).html($notice);
        setTimeout(function() {
            $notice.fadeOut(() => $notice.remove());
        }, INTERVALS.noticeDuration);
    }

    /**
     * Map a metric value to a status class.
     * @param {number} value
     * @param {number} warnThreshold
     * @param {number} critThreshold
     * @returns {string}
     */
    function getStatusClass(value, warnThreshold, critThreshold) {
        if (value > critThreshold) { return STATUS_CLASSES.CRITICAL; }
        if (value > warnThreshold) { return STATUS_CLASSES.WARNING;  }
        return STATUS_CLASSES.HEALTHY;
    }

    /* ── CloudFlare connection functions ───────────────────────────────── */

    /**
     * Test the Cloudflare API connection.
     * @param {boolean} showSuccess - Whether to surface success notices
     */
    function testConnection(showSuccess) {
        if (!cfg) {
            $(SELECTORS.connectionStatus).html(MESSAGES.scriptError);
            return;
        }

        $(SELECTORS.connectionStatus).html('<span class="spinner is-active rtsm-inline-spinner"></span>');

        $.post(cfg.ajaxUrl, { action: AJAX_ACTIONS.testConnection, nonce: cfg.nonce }, function(response) {
            if (response.success) {
                $(SELECTORS.connectionStatus).html(MESSAGES.connected);
                if (showSuccess) {
                    const msg = `${response.data.message}<br>Zone: ${response.data.zone_name}<br>Plan: ${response.data.plan}`;
                    showNotice('success', msg);
                }
            } else {
                $(SELECTORS.connectionStatus).html(MESSAGES.failed);
                if (showSuccess) { showNotice('error', response.data.message); }
            }
        }).fail(function() {
            $(SELECTORS.connectionStatus).html(MESSAGES.notConfigured);
        });
    }

    /**
     * Retrieve and display the current Cloudflare security level.
     */
    function getCurrentLevel() {
        if (!cfg) {
            $(SELECTORS.currentLevel).html(MESSAGES.notAvailable);
            return;
        }

        $(SELECTORS.currentLevel).html('<span class="spinner is-active rtsm-inline-spinner"></span>');

        $.post(cfg.ajaxUrl, { action: AJAX_ACTIONS.getCurrentLevel, nonce: cfg.nonce }, function(response) {
            if (response.success) {
                const level      = response.data.level;
                const levelClass = `level-${level}`;
                const levelText  = level.replace('_', ' ').toUpperCase();
                $(SELECTORS.currentLevel).html(`<span class="mmi-panel-security-level ${levelClass}">${levelText}</span>`);
            } else {
                $(SELECTORS.currentLevel).html(MESSAGES.notConfigured);
            }
        }).fail(function() {
            $(SELECTORS.currentLevel).html(MESSAGES.notConfigured);
        });
    }

    /* ── Live metrics ──────────────────────────────────────────────────── */

    /**
     * Fetch live server metrics and update metric cards.
     */
    function updateServerMetrics() {
        const ajaxUrl = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;
        const nonce   = window.mmiMetricsNonce || '';
        const statusClasses = `${STATUS_CLASSES.HEALTHY} ${STATUS_CLASSES.WARNING} ${STATUS_CLASSES.CRITICAL}`;

        $.post(ajaxUrl, { action: AJAX_ACTIONS.serverMetrics, _ajax_nonce: nonce }, function(response) {
            if (!response.success || !response.data) { return; }

            const metrics = response.data;

            /* Load card */
            const $loadCard = $(SELECTORS.cardLoad);
            if ($loadCard.length && metrics.load) {
                $loadCard.find('.metric-value').text(metrics.formatted.load);
                const loadDetails = MESSAGES.loadDetails
                    .replace('%1min%', metrics.formatted.load)
                    .replace('%5min%', Number(metrics.load['5min']).toFixed(2))
                    .replace('%15min%', Number(metrics.load['15min']).toFixed(2));
                $loadCard.find('[data-load-details]').html(loadDetails);
                $loadCard.removeClass(statusClasses)
                         .addClass(getStatusClass(metrics.load['1min'], THRESHOLDS.loadWarn, THRESHOLDS.loadCrit));
            }

            /* CPU card */
            const $cpuCard = $(SELECTORS.cardCpu);
            if ($cpuCard.length && metrics.cpu) {
                $cpuCard.find('.metric-value').text(metrics.formatted.cpu);
                $cpuCard.removeClass(statusClasses)
                        .addClass(getStatusClass(metrics.cpu.percent, THRESHOLDS.cpuWarn, THRESHOLDS.cpuCrit));
            }

            /* Memory card */
            const $memCard = $(SELECTORS.cardMemory);
            if ($memCard.length && metrics.memory) {
                $memCard.find('.metric-value').text(metrics.formatted.memory_percent);
                $memCard.find('[data-memory-details]').text(metrics.formatted.memory);
                $memCard.removeClass(statusClasses)
                        .addClass(getStatusClass(metrics.memory.percent, THRESHOLDS.memWarn, THRESHOLDS.memCrit));
            }
        });
    }

    /* ── Chart helper ──────────────────────────────────────────────────── */

    /**
     * Build a traffic line chart using Chart.js.
     * @param {HTMLCanvasElement} ctx
     * @param {object}            trafficData
     * @param {boolean}           withAnnotations - Include horizontal threshold lines
     * @returns {Chart}
     */
    function createTrafficChart(ctx, trafficData, withAnnotations) {
        const chartConfig = {
            type: 'line',
            data: {
                labels: trafficData.timestamps,
                datasets: [{
                    label:           `Server Load (${trafficData.intervalLabel})`,
                    data:            trafficData.loads,
                    borderColor:     CHART_COLORS.primary,
                    backgroundColor: CHART_COLORS.primaryAlpha,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { font: { weight: 'bold' }, color: CHART_COLORS.labelColor }
                    }
                },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Server Load' } },
                    x: { title: { display: true, text: `Time (${trafficData.timezone})` } }
                }
            }
        };

        if (withAnnotations) {
            chartConfig.options.plugins.annotation = {
                annotations: {
                    criticalLine: {
                        type: 'line',
                        yMin: CHART_THRESHOLDS.criticalLoad,
                        yMax: CHART_THRESHOLDS.criticalLoad,
                        borderColor: CHART_COLORS.criticalLine,
                        borderWidth: 2,
                        borderDash: [5, 5],
                        label: {
                            content: `Critical (${CHART_THRESHOLDS.criticalLoad}.0)`,
                            enabled: true,
                            position: 'end'
                        }
                    },
                    elevatedLine: {
                        type: 'line',
                        yMin: CHART_THRESHOLDS.elevatedLoad,
                        yMax: CHART_THRESHOLDS.elevatedLoad,
                        borderColor: CHART_COLORS.elevatedLine,
                        borderWidth: 1,
                        borderDash: [3, 3],
                        label: {
                            content: `Elevated (${CHART_THRESHOLDS.elevatedLoad}.0)`,
                            enabled: true,
                            position: 'end'
                        }
                    }
                }
            };
        }

        return new Chart(ctx, chartConfig);
    }

    /* ── Init ──────────────────────────────────────────────────────────── */

    testConnection(false);
    getCurrentLevel();
    setInterval(updateServerMetrics, INTERVALS.metricsRefresh);
    setTimeout(updateServerMetrics, INTERVALS.initialDelay);

    /* ── Event handlers ────────────────────────────────────────────────── */

    $(SELECTORS.testConnection).on('click', function() {
        testConnection(true);
    });

    $(SELECTORS.refreshStatus).on('click', function() {
        testConnection(false);
        getCurrentLevel();
    });

    $(SELECTORS.cfActions).on('click', '#cf-set-level', function() {
        const $btn         = $(this);
        const level        = $btn.data('level');
        const originalHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="spinner is-active rtsm-inline-spinner"></span> Setting...');

        $.post(cfg.ajaxUrl, {
            action: AJAX_ACTIONS.setSecurityLevel,
            nonce:  cfg.nonce,
            level
        }, function(response) {
            $btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                showNotice('success', response.data.message);
                getCurrentLevel();
            } else {
                showNotice('error', response.data.message);
            }
        });
    });

    $(SELECTORS.testAutoProtect).on('click', function() {
        const $btn         = $(this);
        const originalHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="spinner is-active rtsm-inline-spinner"></span> Running...');

        $.post(cfg.ajaxUrl, { action: AJAX_ACTIONS.testAutoProtect, nonce: cfg.nonce }, function(response) {
            $btn.prop('disabled', false).html(originalHtml);
            if (response.success) {
                const output = MESSAGES.scriptOutput.replace('%output%', response.data.output);
                showNotice('success', output);
            } else {
                showNotice('error', response.data.message);
            }
        });
    });

    /* ── Traffic charts ────────────────────────────────────────────────── */

    if (typeof Chart !== 'undefined' && typeof window.mmiTrafficData !== 'undefined') {
        const trafficData  = window.mmiTrafficData;
        const trafficCtx   = document.querySelector(SELECTORS.trafficChart);
        const attackerCtx  = document.querySelector(SELECTORS.attackerChart);

        if (trafficCtx && trafficData.timestamps && trafficData.timestamps.length > 0) {
            createTrafficChart(trafficCtx, trafficData, true);
        }

        if (attackerCtx && trafficData.topAttackers && trafficData.topAttackers.ips.length > 0) {
            new Chart(attackerCtx, {
                type: 'doughnut',
                data: {
                    labels: trafficData.topAttackers.ips,
                    datasets: [{ data: trafficData.topAttackers.counts, backgroundColor: CHART_COLORS.palette }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
                    }
                }
            });
        }
    }

    /* ── Time range selector ───────────────────────────────────────────── */

    $(SELECTORS.timeRange).on('change', function() {
        const interval     = $(this).val();
        const $container   = $(SELECTORS.trafficGraph);
        const $chartParent = $(SELECTORS.trafficChart).parent();
        const loadingMsg   = MESSAGES.loadingInterval.replace('%interval%', interval);

        $container.addClass('rtsm-loading-dimmed');
        $chartParent.html(`<div class="rtsm-chart-loading"><span class="spinner is-active rtsm-inline-spinner"></span><p>${loadingMsg}</p></div>`);

        $.ajax({
            url:    (cfg && cfg.ajaxUrl) || window.ajaxurl,
            method: 'GET',
            data: {
                action:      AJAX_ACTIONS.getTrafficData,
                interval,
                _ajax_nonce: cfg ? cfg.nonce : ''
            },
            success: function(response) {
                if (response.success && response.data) {
                    $chartParent.html('<canvas id="trafficChart" class="rtsm-chart-canvas"></canvas>');
                    const newData = response.data;
                    window.mmiTrafficData = newData;

                    const freshCtx = document.querySelector(SELECTORS.trafficChart);
                    if (freshCtx && newData.timestamps && newData.timestamps.length > 0) {
                        createTrafficChart(freshCtx, newData, false);
                    }

                    $(SELECTORS.spikeCount).text(newData.spikeCount || 0);

                    if (newData.topAttackers && newData.topAttackers.length > 0) {
                        const attackerRows = newData.topAttackers.map(function(attacker) {
                            const entry = MESSAGES.attackerEntry
                                .replace('%count%', attacker.count)
                                .replace('%maxLoad%', attacker.maxLoad.toFixed(2));
                            return `<div class="rtsm-attacker-row">
                                <code class="rtsm-attacker-ip" style="--chart-primary:${CHART_COLORS.primary}">${attacker.ip}</code><br>
                                <small>${entry}</small>
                            </div>`;
                        }).join('');
                        $(SELECTORS.topAttackingIps).html(`<div class="rtsm-chart-details">${attackerRows}</div>`);
                    } else {
                        $(SELECTORS.topAttackingIps).html(`<p class="rtsm-no-data-msg">${MESSAGES.noAttackData}</p>`);
                    }

                    $container.removeClass('rtsm-loading-dimmed');
                } else {
                    $chartParent.html(`<div class="notice notice-error inline"><p>${MESSAGES.failedTrafficData}</p></div>`);
                    $container.removeClass('rtsm-loading-dimmed');
                }
            },
            error: function() {
                $chartParent.html(`<div class="notice notice-error inline"><p>${MESSAGES.failedRequest}</p></div>`);
                $container.removeClass('rtsm-loading-dimmed');
            }
        });
    });

    /* ── Diagnostics ───────────────────────────────────────────────────── */

    $(SELECTORS.refreshDiagnostics).on('click', function() {
        const $btn         = $(this);
        const originalHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="spinner is-active rtsm-spinner-inline"></span> Analyzing...');

        $.post(cfg.ajaxUrl, { action: AJAX_ACTIONS.analyzeDiagnostics, nonce: cfg.nonce }, function(response) {
            $btn.prop('disabled', false).html(originalHtml);
            if (response.success && response.data) {
                $(SELECTORS.diagnosticResults).html(response.data.html);
                if (response.data.details) { $(SELECTORS.diagnosticDetails).show(); }
            } else {
                $(SELECTORS.diagnosticResults).html(`<div class="notice notice-error inline"><p>${MESSAGES.failedDiagnostics}</p></div>`);
            }
        }).fail(function() {
            $btn.prop('disabled', false).html(originalHtml);
            $(SELECTORS.diagnosticResults).html(`<div class="notice notice-error inline"><p>${MESSAGES.failedRequest}</p></div>`);
        });
    });

});
