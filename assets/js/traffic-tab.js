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

    /* ── Severity → shared state word (matches RTSM_UI_Helpers::SEVERITY_VARIANTS) ── */
    const SEVERITY = {
        EMERGENCY: { variant: 'error',   icon: '🚨' },
        CRITICAL:  { variant: 'error',   icon: '🔴' },
        ELEVATED:  { variant: 'warning', icon: '⚠️' },
        NORMAL:    { variant: 'success', icon: '✅' },
    };

    const VARIANTS = {
        OK:   'success',
        WARN: 'warning',
        CRIT: 'error',
    };
    const ALL_VARIANTS = 'success warning error info';

    const CSS_CLASSES = {
        ACTIVE: 'is-active',
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
        loadBar:            '#rtsm-load-bar',
        loadBarFill:        '.rtsm-progress-fill',
        loadBarLabel:       '#rtsm-load-bar-label',
        severityBadge:      '#rtsm-severity-badge',
        statBox:            '.mmi-stat-box',
        statusTime:         '#rtsm-status-time',
        interpretation:     '#rtsm-interpretation',
        interpBlocks:       '[data-sev]',
        interpLoad:         '[data-interp-load]',
        interpPct:          '[data-interp-pct]',
        mitigationCard:     '#rtsm-mitigation-card',
        mitigationHeading:  '.rtsm-mitigation-heading',
        emergOnlyText:      '.rtsm-emerg-only',
        nonEmergOnlyText:   '.rtsm-non-emerg-only',
        thresholdCells:     '.rtsm-threshold[data-threshold]',
        thresholdBadge:     '.rtsm-threshold-active',
        currentLoad:        '#rtsm-thresh-current-load',
    };

    /* ── Messages & Labels ───────────────────────────────────────────── */
    const MESSAGES = {
        loadSaturation:    'Load saturation — %pct%% of comfortable capacity (%cores%× cores)',
        whatToDo:          'What To Do — %sev% State',
        updated:           'Updated %time% UTC',
        loadPerCore:       '%load% per core (%cores% cores)',
        memoryUsage:       '%used% / %total%',
        loadMinutes:       '%5min% · %15min% (5 / 15 min)',
        missingConfig:     'RTSM Traffic Tab: ajaxUrl or nonce missing — live updates disabled.',
        pollError:         'RTSM Traffic Tab poll error:',
    };

    /* ── AJAX Config ─────────────────────────────────────────────────── */
    const AJAX_ACTION = 'rtsm_get_stats';

    /* ── Severity helpers ────────────────────────────────────────────── */
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

    /** Build the severity badge HTML string (mirrors RTSM_UI_Helpers::severity_badge()). */
    function buildBadgeHtml(sev) {
        const conf = SEVERITY[sev] || SEVERITY.NORMAL;
        return `<span class="mmi-badge ${conf.variant}">${conf.icon} ${sev}</span>`;
    }

    /** Return a shared state word based on a value and two thresholds. */
    function variantFor(val, warnAt, critAt) {
        if (val >= critAt) { return VARIANTS.CRIT; }
        if (val >= warnAt) { return VARIANTS.WARN; }
        return VARIANTS.OK;
    }

    /** Set a value element's text and recolor the stat tile around it. */
    function setStat(selector, text, variant) {
        $(selector).text(text).closest(SELECTORS.statBox).removeClass(ALL_VARIANTS).addClass(variant);
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

        /* ── Load average ── */
        setStat(SELECTORS.statLoad1, load1.toFixed(2), variantFor(load1, cpuCores * (PERF_THRESHOLDS.loadWarnPct / 100), cpuCores));
        const loadSubText = MESSAGES.loadMinutes
            .replace('%5min%', load5.toFixed(2))
            .replace('%15min%', load15.toFixed(2));
        $(SELECTORS.statLoadSub).text(loadSubText);

        /* ── CPU % (actual from /proc/stat — matches admin bar) ── */
        setStat(SELECTORS.statCpuPct, cpuPct.toFixed(2) + '%', variantFor(cpuPct, PERF_THRESHOLDS.cpuWarnPct, PERF_THRESHOLDS.cpuCritPct));
        const loadPerCore = (load1 / cpuCores).toFixed(2);
        const cpuSubText = MESSAGES.loadPerCore
            .replace('%load%', loadPerCore)
            .replace('%cores%', cpuCores);
        $(SELECTORS.statCpuSub).text(cpuSubText);

        /* ── Memory ── */
        setStat(SELECTORS.statMemPct, memPct.toFixed(2) + '%', variantFor(memPct, PERF_THRESHOLDS.memWarnPct, PERF_THRESHOLDS.memCritPct));
        const memSubText = MESSAGES.memoryUsage
            .replace('%used%', memUsed)
            .replace('%total%', memTot);
        $(SELECTORS.statMemSub).text(memSubText);

        /* ── Load bar ── */
        const barPct    = Math.min(BAR_PERCENT_CAP, Math.round((load1 / (cpuCores * CORE_MULTIPLIER)) * 100));
        const barVariant = barPct >= SEVERITY_THRESHOLDS.CRITICAL ? VARIANTS.CRIT
                         : (barPct >= SEVERITY_THRESHOLDS.ELEVATED ? VARIANTS.WARN : VARIANTS.OK);
        const $loadBar = $(SELECTORS.loadBar);
        $loadBar.removeClass('is-success is-warning is-error is-info').addClass(`is-${barVariant}`);
        const fill = $loadBar.find(SELECTORS.loadBarFill)[0];
        if (fill) { fill.style.setProperty('--fill-pct', barPct + '%'); }
        const barLabel = MESSAGES.loadSaturation
            .replace('%pct%', barPct)
            .replace('%cores%', CORE_MULTIPLIER);
        $(SELECTORS.loadBarLabel).text(barLabel);

        /* ── Severity badge ── */
        $(SELECTORS.severityBadge).html(buildBadgeHtml(sev));

        /* ── Interpretation block: swap active class on wrapper ── */
        const interp = $(SELECTORS.interpretation);
        interp.find(SELECTORS.interpBlocks).each(function () {
            this.hidden = this.getAttribute('data-sev') !== sev.toLowerCase();
        });
        // Refresh dynamic values inside each interpretation block
        interp.find(SELECTORS.interpLoad).text(load1.toFixed(2));
        interp.find(SELECTORS.interpPct).text(Math.round((load1 / cpuCores) * 100));

        /* ── Mitigation card: show only when severity !== NORMAL ── */
        const mitCard = $(SELECTORS.mitigationCard);
        mitCard.prop('hidden', sev === 'NORMAL');
        if (sev !== 'NORMAL') {
            const mitigationTitle = MESSAGES.whatToDo.replace('%sev%', sev);
            mitCard.find(SELECTORS.mitigationHeading).text(mitigationTitle);
            // Highlight EMERGENCY-specific line
            mitCard.find(SELECTORS.emergOnlyText).prop('hidden', sev !== 'EMERGENCY');
            mitCard.find(SELECTORS.nonEmergOnlyText).prop('hidden', sev === 'EMERGENCY');
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
            $(this).toggleClass(CSS_CLASSES.ACTIVE, meta.active);
            $(this).find(SELECTORS.thresholdBadge).prop('hidden', !meta.active);
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
        /* The Traffic tab may arrive later via rtsm_load_tab, so wire the
           updates up unconditionally; poll()/updateTrafficTab() no-op while
           the tab isn't in the DOM. */
        if (window.rtsmConfig) {
            /* The admin bar popup dispatches 'rtsm:stats' after every fetch —
               reuse its snapshot so both surfaces show the same numbers. */
            document.addEventListener('rtsm:stats', function (e) {
                updateTrafficTab(e.detail);
            });
        } else {
            setInterval(poll, intervalMs);
        }
        poll();
        $(document).on('rtsm_tab_loaded', function (event, tabName) {
            if (tabName === 'traffic') { poll(); }
        });
    });

}(jQuery));
