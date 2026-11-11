/**
 * RTSM Dashboard → Visitors table.
 *
 * Fills #rtsm-visitors from rtsm_get_visitors (RTSM_Visitor_Profiles::ajax_table):
 * every address seen in the chosen time range, its label (Likely bad, Watch,
 * Verified crawler) and why. Rows are all on the page, so the shared
 * mmi-table-sort.js sorts every heading in the browser.
 *
 * Copy addresses / Copy Cloudflare rule use the ticked rows, or every
 * "Likely bad" row shown when none are ticked. Nothing is blocked from here.
 *
 * @package MMI_RTSM
 */
(function ($) {
    'use strict';

    const config = window.rtsmAdmin || {};
    const ajaxUrl = config.ajaxUrl || window.ajaxurl;
    const NONCE_ACTION = 'rtsm_nonce';
    const post = (data) => window.MMINonces.post(NONCE_ACTION, ajaxUrl, data);
    const AUTO_REFRESH_MS = 60000;

    const SEL = {
        section: '#rtsm-visitors',
        windowPills: '[data-rtsm-visitors="window"] .mmi-pill',
        levelPills: '[data-rtsm-visitors="level"] .mmi-pill',
        count: '.mmi-pill-count',
        note: '#rtsm-visitors-note',
        notice: '#rtsm-visitors-notice',
        table: '#rtsm-visitors-table',
        body: '#rtsm-visitors-table tbody',
        all: '#rtsm-visitors-all',
        row: '.rtsm-visitor-check',
        networkCol: '.rtsm-visitors-network',
        copyIps: '#rtsm-visitors-copy-ips',
        copyRule: '#rtsm-visitors-copy-rule',
    };

    const LEVEL_BADGE = { bad: 'error', watch: 'warning', verified: 'success' };

    const state = { window: 3600, level: 'all', rows: [], seq: 0, timer: null };

    function esc(str) {
        if (typeof window.MMIEscapeHtml === 'function') {
            return window.MMIEscapeHtml(str);
        }
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function ago(seconds) {
        if (seconds < 60) return `${seconds} s ago`;
        if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
        return `${(seconds / 3600).toFixed(1)} h ago`;
    }

    function windowLabel(seconds) {
        return { 900: 'the last 15 minutes', 3600: 'the last hour', 21600: 'the last 6 hours', 86400: 'the last 24 hours' }[seconds] || 'this range';
    }

    /* ── Loading ─────────────────────────────────────────────────────── */

    /** Load the current window and label. The newest call wins: replies to older ones are dropped. */
    function load() {
        if (!$(SEL.section).length) return;
        const seq = ++state.seq;
        $(SEL.section).attr('aria-busy', 'true');
        post({ action: 'rtsm_get_visitors', window: state.window, level: state.level })
            .done(function (res) {
                if (seq !== state.seq) return;
                if (!res || !res.success) {
                    showNotice((res && res.data && res.data.message) || 'Could not load visitors.', 'error');
                    return;
                }
                render(res.data);
            })
            .fail(function () {
                if (seq === state.seq) showNotice('Could not load visitors. Check your connection and try again.', 'error');
            })
            .always(function () {
                if (seq === state.seq) $(SEL.section).removeAttr('aria-busy');
            });
    }

    function render(data) {
        state.rows = data.rows || [];
        const counts = data.counts || {};
        $(SEL.section).find(SEL.count).each(function () {
            const key = $(this).data('count');
            $(this).text(counts[key] !== undefined ? counts[key] : '');
        });

        const shown = state.rows.length;
        const capped = data.matched > shown ? ` Showing the ${shown.toLocaleString()} most active of ${Number(data.matched).toLocaleString()}.` : '';
        $(SEL.note).text(`${Number(counts.all || 0).toLocaleString()} addresses in ${windowLabel(data.window)}. ${data.explain || ''}${capped}`);

        $(SEL.networkCol).prop('hidden', !data.networks);
        const cols = data.networks ? 11 : 10;

        if (!shown) {
            $(SEL.body).html(`<tr><td colspan="${cols}" class="mmi-text-muted">No visitors ${state.level === 'all' ? '' : 'with this label '}in ${windowLabel(data.window)}.</td></tr>`);
        } else {
            $(SEL.body).html(state.rows.map((r) => rowHtml(r, data.networks)).join(''));
        }
        $(SEL.all).prop('checked', false);
        updateButtons();
    }

    function rowHtml(r, networks) {
        const badge = r.level
            ? `<span class="mmi-badge ${LEVEL_BADGE[r.level] || 'info'}">${esc(r.label)}</span>`
            : '<span class="mmi-text-muted">—</span>';
        const why = (r.reasons || []).map((t) => `<li>${esc(t)}</li>`).join('');
        let net = '';
        if (networks) {
            const n = r.network;
            net = `<td data-sort-value="${esc(n ? n.name : '')}">${n
                ? `${esc(n.name)} <span class="mmi-text-muted">${esc(n.cc)}</span>${n.hosting ? ' <span class="mmi-badge info" title="A hosting or cloud provider: servers, not people">Hosting</span>' : ''}`
                : '<span class="mmi-text-muted">—</span>'}</td>`;
        }
        const rank = { bad: 0, watch: 1, '': 2, verified: 3 }[r.level || ''];
        return `<tr data-ip="${esc(r.ip)}" data-level="${esc(r.level)}">
            <td class="check-column"><input type="checkbox" class="rtsm-visitor-check" value="${esc(r.ip)}" aria-label="Select ${esc(r.ip)}"></td>
            <td><code class="rtsm-visitor-ip">${esc(r.ip)}</code></td>
            <td data-sort-value="${rank}">${badge}</td>
            <td class="rtsm-visitor-why">${why ? `<ul>${why}</ul>` : ''}</td>
            <td data-sort-value="${r.req}">${Number(r.req).toLocaleString()}</td>
            <td data-sort-value="${r.pages}">${Number(r.pages).toLocaleString()}</td>
            <td data-sort-value="${r.errors}">${Number(r.errors).toLocaleString()}</td>
            <td data-sort-value="${r.ago}">${ago(r.ago)}</td>
            ${net}
            <td class="rtsm-visitor-ua" title="${esc(r.ua)}">${r.ua ? esc(r.ua) : '<span class="mmi-text-muted">none</span>'}</td>
            <td class="column-actions"><a href="https://www.abuseipdb.com/check/${encodeURIComponent(r.ip)}" target="_blank" rel="noopener noreferrer" title="Open this address's abuse reports on AbuseIPDB">Reports ↗</a></td>
        </tr>`;
    }

    /* ── Selection and copying ───────────────────────────────────────── */

    /** Ticked addresses, or every "Likely bad" one shown when none are ticked. */
    function targets() {
        const ticked = $(SEL.row + ':checked').map(function () { return this.value; }).get();
        if (ticked.length) return { ips: ticked, which: 'selected' };
        return { ips: state.rows.filter((r) => r.level === 'bad').map((r) => r.ip), which: 'likely bad' };
    }

    function updateButtons() {
        const t = targets();
        const n = t.ips.length;
        const suffix = n ? ` (${n} ${t.which})` : '';
        $(SEL.copyIps).prop('disabled', !n).attr('title', n ? `Copy ${n} ${t.which} addresses, one per line` : 'Tick rows to copy their addresses');
        $(SEL.copyRule).prop('disabled', !n).attr('title', n
            ? `Copy a Cloudflare custom-rule expression matching ${n} ${t.which} addresses`
            : 'Tick rows, or show some Likely bad visitors, to build a rule');
        $(SEL.copyRule).find('.rtsm-copy-count').remove();
        if (suffix) $(SEL.copyRule).append(`<span class="rtsm-copy-count">${esc(suffix)}</span>`);
    }

    function copy(text, message) {
        const done = () => showNotice(message, 'success');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, () => fallbackCopy(text, done));
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        ta.remove();
        if (ok) done(); else showNotice('Copying was blocked by the browser. Select the text and copy it yourself:\n' + text, 'warning');
    }

    function showNotice(message, type) {
        $(SEL.notice).html(`<div class="mmi-info-card ${type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success')} rtsm-visitors-notice"><p>${esc(message).replace(/\n/g, '<br>')}</p></div>`);
    }

    /* ── Events ──────────────────────────────────────────────────────── */

    function bind() {
        $(document)
            .on('click', SEL.windowPills, function () {
                $(SEL.windowPills).removeClass('active');
                $(this).addClass('active');
                state.window = parseInt($(this).data('value'), 10) || 3600;
                load();
            })
            .on('click', SEL.levelPills, function () {
                $(SEL.levelPills).removeClass('active');
                $(this).addClass('active');
                state.level = String($(this).data('value'));
                load();
            })
            .on('change', SEL.all, function () {
                $(SEL.row).prop('checked', this.checked);
                updateButtons();
            })
            .on('change', SEL.row, updateButtons)
            .on('click', SEL.copyIps, function () {
                const t = targets();
                copy(t.ips.join('\n'), `Copied ${t.ips.length} ${t.which} addresses.`);
            })
            .on('click', SEL.copyRule, function () {
                const t = targets();
                copy(`(ip.src in {${t.ips.join(' ')}})`,
                    `Copied a rule for ${t.ips.length} ${t.which} addresses. In Cloudflare, open Security → WAF → Custom rules, add or edit a rule, choose "Edit expression", paste it and pick Block or Managed Challenge. Addresses change often, so prefer rules on paths or networks for lasting protection.`);
            })
            .on('rtsm_tab_loaded', function (e, tab) {
                if (tab === 'dashboard') start();
            });
    }

    function start() {
        if (!$(SEL.section).length) return;
        load();
        clearInterval(state.timer);
        state.timer = setInterval(function () {
            // Keep a selection the owner is working with; refresh otherwise.
            if (document.hidden || !$(SEL.section).length || $(SEL.row + ':checked').length) return;
            load();
        }, AUTO_REFRESH_MS);
    }

    $(function () {
        bind();
        start();
    });
})(jQuery);
