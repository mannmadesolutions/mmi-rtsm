/**
 * Real-Time Server Monitor - Traffic Tab JavaScript
 *
 * Polls rtsm_get_stats at the configured interval and keeps every live-data
 * element on the Traffic Analysis tab in sync with the admin-bar widget so
 * both surfaces always show the same numbers.
 *
 * Depends on: window.rtsmAdmin (set by wp_localize_script on 'rtsm-admin-tabs')
 *   - rtsmAdmin.ajaxUrl
 *   - rtsmAdmin.nonce       (rtsm_nonce — matches ajax_get_stats() validator)
 *   - rtsmAdmin.refreshInterval   (seconds)
 *   - rtsmAdmin.cpuCores          (logical CPU count for load-% calculation)
 *
 * @package MMI_RTSM
 */

(function ($) {
    'use strict';

    /* ── Config & Constants ──────────────────────────────────────────── */
    const cfg          = window.rtsmAdmin || {};
    const ajaxUrl      = cfg.ajaxUrl || window.ajaxurl || '';
    const nonce        = cfg.nonce || '';
    const MIN_INTERVAL_MS = 5 * 1000;
    const DEFAULT_INTERVAL_S = 10;
    const DEFAULT_CPU_CORES = 1;
    const intervalMs   = Math.max(MIN_INTERVAL_MS, parseInt(cfg.refreshInterval || DEFAULT_INTERVAL_S, 10) * 1000);
    const cpuCores     = Math.max(1, parseInt(cfg.cpuCores || DEFAULT_CPU_CORES, 10));

    /* ── Severity Thresholds ─────────────────────────────────────────── */
    const SEVERITY_THRESHOLDS = {
        EMERGENCY: 100,
        CRITICAL:  80,
        ELEVATED:  50,
    };

    /* ── Color Schemes ───────────────────────────────────────────────── */
    const SEVERITY_COLORS = {
        EMERGENCY: { color: '#7f1d1d', bg: '#fca5a5', icon: '🚨', borderColor: '#dc2626', cardText: '#7f1d1d', badgeClass: 'rtsm-severity-badge-emergency' },
        CRITICAL:  { color: '#7c2d12', bg: '#fdba74', icon: '🔴', borderColor: '#ea580c', cardText: '#7c2d12', badgeClass: 'rtsm-severity-badge-critical'  },
        ELEVATED:  { color: '#78350f', bg: '#fde68a', icon: '⚠️',  borderColor: '#d97706', cardText: '#78350f', badgeClass: 'rtsm-severity-badge-elevated'  },
        NORMAL:    { color: '#14532d', bg: '#fff', icon: '✅', borderColor: '#45852C', cardText: '#14532d', badgeClass: 'rtsm-severity-badge-normal'    },
    };

    const COLOR_CLASSES = {
        OK:   'rtsm-color-ok',
        WARN: 'rtsm-color-warn',
        CRIT: 'rtsm-color-crit',
    };

    const CSS_CLASSES = {
        SEV_NORMAL:    'rtsm-sev-normal',
        SEV_ELEVATED:  'rtsm-sev-elevated',
        SEV_CRITICAL:  'rtsm-sev-critical',
        SEV_EMERGENCY: 'rtsm-sev-emergency',
        HIDDEN:        'rtsm-hidden',
        ACTIVE:        'active',
        SEVERITY:      'data-threshold',
        THRESH_TRIGGER: 'thresh-trigger',
    };

    /* ── Performance Thresholds (as % of comfortable capacity) ────────── */
    const PERF_THRESHOLDS = {
        loadWarnPct: 62.5,
        cpuWarnPct: 60,
        cpuCritPct: 90,
        memWarnPct: 60,
        memCritPct: 85,
        recoveryThresholdPct: 25,
    };

    const CORE_MULTIPLIER = 2; // For load saturation calculation
    const BAR_PERCENT_CAP = 100;

    /* ── DOM Selectors ───────────────────────────────────────────────── */
    const SELECTORS = {
        trafficWrap:        '#rtsm-traffic-wrap',
        statLoad1:          '#rtsm-stat-load1',
        statLoadSub:        '#rtsm-stat-load-sub',
        statCpuPct:         '#rtsm-stat-cpu-pct',
        statCpuSub:         '#rtsm-stat-cpu-sub',
        statMemPct:         '#rtsm-stat-mem-pct',
        statMemSub:         '#rtsm-stat-mem-sub',
        loadBarFill:        '#rtsm-load-bar-fill',
        loadBarLabel:       '#rtsm-load-bar-label',
        loadBarPct:         '.rtsm-load-bar-pct',
        severityBadge:      '#rtsm-severity-badge',
        statusCard:         '#rtsm-status-card',
        statusHeading:      '.rtsm-status-heading',
        statusTime:         '#rtsm-status-time',
        interpretation:     '#rtsm-interpretation',
        interpLoad:         '[data-interp-load]',
        interpPct:          '[data-interp-pct]',
        mitigationCard:     '#rtsm-mitigation-card',
        mitigationHeading:  '.rtsm-mitigation-heading',
        emergOnlyText:      '.rtsm-emerg-only',
        nonEmergOnlyText:   '.rtsm-non-emerg-only',
        thresholdCells:     '.rtsm-thresh-cell[data-threshold]',
        thresholdTrigger:   '.thresh-trigger',
        currentLoad:        '#rtsm-thresh-current-load',
    };

    /* ── Messages & Labels ───────────────────────────────────────────── */
    const MESSAGES = {
        loadSaturation:    'Load saturation — %pct%% of comfortable capacity (%cores%× cores)',
        whatToDo:          'What To Do — %sev% State',
        updated:           'Updated %time% UTC',
        currentlyActive:   '▲ CURRENTLY ACTIVE',
        loadPerCore:       '%load% per core (%cores% cores)',
        memoryUsage:       '%used% / %total%',
        loadMinutes:       '%5min% · %15min% (5 / 15 min)',
        missingConfig:     'RTSM Traffic Tab: ajaxUrl or nonce missing — live updates disabled.',
        pollError:         'RTSM Traffic Tab poll error:',
    };

    const BADGE_STYLES = {
        display:       'inline-block',
        padding:       '1px 7px',
        borderRadius:  '12px',
        fontSize:      '11px',
        fontWeight:    '600',
        marginRight:   '10px',
    };

    /* ── AJAX Config ─────────────────────────────────────────────────── */
    const AJAX_ACTION = 'rtsm_get_stats';

    /* ── Severity helpers ────────────────────────────────────────────── */
    const SEV_CLASSES = [
        CSS_CLASSES.SEV_NORMAL,
        CSS_CLASSES.SEV_ELEVATED,
        CSS_CLASSES.SEV_CRITICAL,
        CSS_CLASSES.SEV_EMERGENCY,
    ];

    /**
     * Derive severity string from load and core count using load bar percentage.
     * This ensures the load bar color and severity badge are always in sync.
     * Bar %: < 50% = NORMAL, 50-79% = ELEVATED, 80-99% = CRITICAL, >= 100% = EMERGENCY
     */
    function getSeverity(load1, cores) {
        const barPct = Math.min(BAR_PERCENT_CAP, Math.round((load1 / (cores * CORE_MULTIPLIER)) * 100));
        if (barPct >= SEVERITY_THRESHOLDS.EMERGENCY) { return 'EMERGENCY'; }
        if (barPct >= SEVERITY_THRESHOLDS.CRITICAL)  { return 'CRITICAL';  }
        if (barPct >= SEVERITY_THRESHOLDS.ELEVATED)  { return 'ELEVATED';  }
        return 'NORMAL';
    }

    /** Build the severity badge HTML string (mirrors rtsm_severity_badge() in PHP). */
    function buildBadgeHtml(sev) {
        const colorSet = SEVERITY_COLORS[sev] || SEVERITY_COLORS.NORMAL;
        return `<span class="rtsm-severity-badge ${colorSet.badgeClass}">${colorSet.icon} ${sev}</span>`;
    }

    /** Return the border/text colours for the status card per severity. */
    function statusCardColors(sev) {
        const colorSet = SEVERITY_COLORS[sev] || SEVERITY_COLORS.NORMAL;
        return {
            border: colorSet.borderColor,
            text:   colorSet.cardText,
        };
    }

    /** Return a CSS colour class based on a value and two thresholds. */
    function colorClass(val, warnAt, critAt) {
        if (val >= critAt) { return COLOR_CLASSES.CRIT; }
        if (val >= warnAt) { return COLOR_CLASSES.WARN; }
        return COLOR_CLASSES.OK;
    }

    /* ── DOM updater ─────────────────────────────────────────────────── */
    function updateTrafficTab(data) {
        const wrap = $(SELECTORS.trafficWrap);
        if (!wrap.length) { return; } // Not on the traffic tab

        const load1   = parseFloat(data.load['1min'])  || 0;
        const load5   = parseFloat(data.load['5min'])  || 0;
        const load15  = parseFloat(data.load['15min']) || 0;
        const cpuPct  = parseFloat(data.cpu_usage)     || 0;
        const memPct  = parseFloat(data.memory && data.memory.percent ? data.memory.percent : 0);
        const memUsed = (data.memory && data.memory.used)  ? data.memory.used  : '—';
        const memTot  = (data.memory && data.memory.total) ? data.memory.total : '—';

        const sev    = getSeverity(load1, cpuCores);
        const colors = statusCardColors(sev);

        /* ── Load average ── */
        const loadCls = colorClass(load1, cpuCores * (PERF_THRESHOLDS.loadWarnPct / 100), cpuCores);
        $(SELECTORS.statLoad1)
            .text(load1.toFixed(2))
            .removeClass(COLOR_CLASSES.OK + ' ' + COLOR_CLASSES.WARN + ' ' + COLOR_CLASSES.CRIT)
            .addClass(loadCls);
        const loadSubText = MESSAGES.loadMinutes
            .replace('%5min%', load5.toFixed(2))
            .replace('%15min%', load15.toFixed(2));
        $(SELECTORS.statLoadSub).text(loadSubText);

        /* ── CPU % (actual from /proc/stat — matches admin bar) ── */
        const cpuCls = colorClass(cpuPct, PERF_THRESHOLDS.cpuWarnPct, PERF_THRESHOLDS.cpuCritPct);
        $(SELECTORS.statCpuPct)
            .text(cpuPct.toFixed(2) + '%')
            .removeClass(COLOR_CLASSES.OK + ' ' + COLOR_CLASSES.WARN + ' ' + COLOR_CLASSES.CRIT)
            .addClass(cpuCls);
        const loadPerCore = (load1 / cpuCores).toFixed(2);
        const cpuSubText = MESSAGES.loadPerCore
            .replace('%load%', loadPerCore)
            .replace('%cores%', cpuCores);
        $(SELECTORS.statCpuSub).text(cpuSubText);

        /* ── Memory ── */
        const memCls = colorClass(memPct, PERF_THRESHOLDS.memWarnPct, PERF_THRESHOLDS.memCritPct);
        $(SELECTORS.statMemPct)
            .text(memPct.toFixed(2) + '%')
            .removeClass(COLOR_CLASSES.OK + ' ' + COLOR_CLASSES.WARN + ' ' + COLOR_CLASSES.CRIT)
            .addClass(memCls);
        const memSubText = MESSAGES.memoryUsage
            .replace('%used%', memUsed)
            .replace('%total%', memTot);
        $(SELECTORS.statMemSub).text(memSubText);

        /* ── Load bar ── */
        const barPct    = Math.min(BAR_PERCENT_CAP, Math.round((load1 / (cpuCores * CORE_MULTIPLIER)) * 100));
        const barColor  = barPct >= SEVERITY_THRESHOLDS.CRITICAL ? SEVERITY_COLORS.CRITICAL.borderColor
                        : (barPct >= SEVERITY_THRESHOLDS.ELEVATED ? SEVERITY_COLORS.ELEVATED.borderColor
                        : SEVERITY_COLORS.NORMAL.borderColor);
        const $loadBar = $(SELECTORS.loadBarFill);
        $loadBar[0].style.setProperty('--load-pct', barPct + '%');
        $loadBar[0].style.setProperty('--bar-color', barColor);
        $(SELECTORS.loadBarPct).text(barPct);
        const barLabel = MESSAGES.loadSaturation
            .replace('%pct%', barPct)
            .replace('%cores%', CORE_MULTIPLIER);
        $(SELECTORS.loadBarLabel).text(barLabel);

        /* ── Severity badge + status card colours ── */
        $(SELECTORS.severityBadge).html(buildBadgeHtml(sev));
        const card = $(SELECTORS.statusCard);
        card[0].style.setProperty('--border-color', colors.border);
        card[0].style.setProperty('--text-color', colors.text);

        /* ── Interpretation block: swap active class on wrapper ── */
        const interp = $(SELECTORS.interpretation);
        interp.removeClass(SEV_CLASSES.join(' '));
        interp.addClass('rtsm-sev-' + sev.toLowerCase());
        // Refresh dynamic values inside each interpretation block
        interp.find(SELECTORS.interpLoad).text(load1.toFixed(2));
        interp.find(SELECTORS.interpPct).text(Math.round((load1 / cpuCores) * 100));

        /* ── Mitigation card: show only when severity !== NORMAL ── */
        const mitCard = $(SELECTORS.mitigationCard);
        if (sev === 'NORMAL') {
            mitCard.addClass(CSS_CLASSES.HIDDEN);
        } else {
            mitCard.removeClass(CSS_CLASSES.HIDDEN);
            const mitigationTitle = MESSAGES.whatToDo.replace('%sev%', sev);
            mitCard.find(SELECTORS.mitigationHeading).text(mitigationTitle);
            // Highlight EMERGENCY-specific line
            mitCard.find(SELECTORS.emergOnlyText).toggle(sev === 'EMERGENCY');
            mitCard.find(SELECTORS.nonEmergOnlyText).toggle(sev !== 'EMERGENCY');
        }

        /* ── Threshold cells: toggle 'active' class (aligned with load bar severity %) ── */
        const thresholdMeta = {
            elevated:  { active: barPct >= SEVERITY_THRESHOLDS.ELEVATED && barPct < SEVERITY_THRESHOLDS.CRITICAL },
            critical:  { active: barPct >= SEVERITY_THRESHOLDS.CRITICAL && barPct < BAR_PERCENT_CAP },
            emergency: { active: barPct >= BAR_PERCENT_CAP },
            recovery:  { active: barPct < PERF_THRESHOLDS.recoveryThresholdPct },
        };
        $(SELECTORS.thresholdCells).each(function () {
            const key  = $(this).data('threshold');
            const meta = thresholdMeta[key];
            if (!meta) { return; }
            if (meta.active) {
                $(this).addClass(CSS_CLASSES.ACTIVE);
                if (!$(this).find(SELECTORS.thresholdTrigger).length) {
                    $(this).append(`<div class="${CSS_CLASSES.THRESH_TRIGGER}">${MESSAGES.currentlyActive}</div>`);
                }
            } else {
                $(this).removeClass(CSS_CLASSES.ACTIVE);
                $(this).find(SELECTORS.thresholdTrigger).remove();
            }
        });
        $(SELECTORS.currentLoad).text(load1.toFixed(2));

        /* ── Timestamp ── */
        const timeMsg = MESSAGES.updated.replace('%time%', data.timestamp || '--:--:--');
        $(SELECTORS.statusTime).text(timeMsg);
    }

    /* ── Polling loop ────────────────────────────────────────────────── */
    function poll() {
        if (!ajaxUrl || !nonce) {
            console.warn(MESSAGES.missingConfig);
            return;
        }
        /* Only run when the traffic tab is visible (saves requests when on other tabs) */
        if (!$(SELECTORS.trafficWrap).length) { return; }

        $.post(ajaxUrl, { action: AJAX_ACTION, nonce: nonce })
            .done(function (response) {
                if (response && response.success) {
                    updateTrafficTab(response.data);
                }
            })
            .fail(function (xhr, status, err) {
                console.error(MESSAGES.pollError, status, err);
            });
    }

    /* ── Init ────────────────────────────────────────────────────────── */
    $(function () {
        if (!$(SELECTORS.trafficWrap).length) { return; }

        /* When the admin bar popup script is present it dispatches 'rtsm:stats'
           after every successful fetch.  Subscribe to that shared event so both
           surfaces always show the same snapshot — no extra AJAX calls needed. */
        if (window.rtsmConfig) {
            document.addEventListener('rtsm:stats', function (e) {
                updateTrafficTab(e.detail);
            });
            /* One immediate fetch in case admin bar hasn't fired yet on load. */
            poll();
        } else {
            /* Admin bar not on this page; poll independently. */
            poll();
            setInterval(poll, intervalMs);
        }
    });

}(jQuery));
