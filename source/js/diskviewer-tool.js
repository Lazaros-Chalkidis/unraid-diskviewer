/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

(function () {
    'use strict';

    var _cfg   = window.diskviewerToolConfig || {};
    var _token = _cfg.dvToken || '';

    // prefer the token the page injected, fall back to the global unraid sets
    function csrfToken() { return _cfg.csrfToken || window.csrf_token || ''; }

    var API = '/plugins/diskviewer/include/diskviewer_api.php';

    function apiUrl(action, extra) {
        var url = API + '?action=' + encodeURIComponent(action)
                + '&_dvt=' + encodeURIComponent(_token);
        if (extra) url += '&' + extra;
        return url;
    }

    function fetchJson(action, extra) {
        return fetch(apiUrl(action, extra), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    var TAB_MAP = {
        dvtTabOverview: 'dvtPanelOverview',
        dvtTabDisks:    'dvtPanelDisks'
    };
    var _activeTab     = 'dvtTabOverview';
    var _overviewLoaded = false;
    var _disksLoaded    = false;
    var _lastUnit      = 'C';
    var _enableSpin    = false;
    var _showUsedBytes = false;
    var _showDecimal   = false;
    var _showIdTooltip = true;
    var _showPower     = false;
    var _refreshTimer  = null;
    var _lastSections  = [];

    var BOLT_SVG   = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
    var STACK_SVG  = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l-9 4.5l9 4.5l9 -4.5l-9 -4.5"/><path d="M3 13.5l9 4.5l9 -4.5"/></svg>';
    var ARROW_UP   = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 14l5-5 5 5z"/></svg>';
    var ARROW_DOWN = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>';

    var THUMB_SVG  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73V10z"/></svg>';
    var GEAR_SVG   = '<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 01-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 01.872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 012.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 012.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 01.872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 01-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 01-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 110-5.858 2.929 2.929 0 010 5.858z"/></svg>';

    function switchTab(tabId) {
        if (_activeTab === tabId) return;
        _activeTab = tabId;

        var tabs = document.querySelectorAll('.dvt-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].id === tabId);
        }

        var panelIds = Object.keys(TAB_MAP);
        for (var j = 0; j < panelIds.length; j++) {
            var panel = document.getElementById(TAB_MAP[panelIds[j]]);
            if (panel) panel.style.display = (panelIds[j] === tabId) ? 'block' : 'none';
        }

        if (tabId === 'dvtTabDisks' && !_disksLoaded) {
            _disksLoaded = true;
            loadDisksTab();
        }

        syncFixedThead();
    }

    function wireTabs() {
        var tabs = document.querySelectorAll('.dvt-tab');
        for (var i = 0; i < tabs.length; i++) {
            (function (tab) {
                tab.addEventListener('click', function () { switchTab(tab.id); });
                tab.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        switchTab(tab.id);
                    }
                });
            })(tabs[i]);
        }
    }

    // decimal units, kept identical to the widget's formatBytes
    function formatBytes(bytes, precision, alwaysTwo) {
        if (!bytes || bytes <= 0) return '0 B';
        if (precision === undefined) precision = 1;
        var units = ['B','KB','MB','GB','TB','PB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1000));
        i = Math.min(i, units.length - 1);
        var val = bytes / Math.pow(1000, i);

        var str;
        if (alwaysTwo && i >= 3) {
            str = val.toFixed(2);
        } else if (i >= 4) {
            var dp = Math.max(precision, 2);
            str = val.toFixed(dp).replace(/\.?0+$/, '');
        } else {

            str = String(Math.round(val));
        }
        return str + ' ' + units[i];
    }

    function diskIdent(tile){
        if (!tile || tile.is_summary || !_showIdTooltip || tile.not_installed) return '';
        var id  = (tile.ident_id || '').toString().trim();
        var dev = (tile.dev_short || '').toString().trim();
        var s = id;
        if (dev) s += (s ? ' ' : '') + '(' + dev + ')';
        return s;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
    }
    function setHtml(id, html) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = html;
    }

    function buildLoading() {
        return '<div class="dvt-loading">'
             + '<div class="dvt-loading__bars">'
             + '<div class="dvt-loading__bar"></div>'
             + '<div class="dvt-loading__bar"></div>'
             + '<div class="dvt-loading__bar"></div>'
             + '<div class="dvt-loading__bar"></div>'
             + '<div class="dvt-loading__bar"></div>'
             + '</div>'
             + '<span class="dvt-loading__text">Loading disks...</span>'
             + '</div>';
    }

    function loadOverview() {
        if (_overviewLoaded) return;
        _overviewLoaded = true;
        setHtml('dvt-overview-disks', buildLoading());
        var sub0 = document.getElementById('dvt-subtitle');
        if (sub0) sub0.textContent = '';
        fetchJson('tool_overview').then(function (model) {
            var ov = model.overview || {};
            var cfg = model.cfg || {};
            _lastUnit = ov.temp_unit === 'F' ? 'F' : 'C';
            _enableSpin = !!cfg.enable_spin_button;
            _showUsedBytes = !!cfg.show_used_column;
            _showDecimal   = !!cfg.show_decimal_pct;
            _showIdTooltip = cfg.show_id_tooltip !== false;
            _showPower     = !!cfg.show_power;
            renderOverviewDisks(model.sections || []);
            wireSpin();
            wireBulkSpin();
            wireTooltips();
            applyFontSize(cfg);
            setupRefresh(cfg);
            ensureFixedThead();
            startSpeedPolling();
            var sub = document.getElementById('dvt-subtitle');
            if (sub) {
                var totalDev   = ov.device_count || 0;
                var missingDev = ov.missing_count || 0;
                if (missingDev > 0) {
                    sub.innerHTML = 'Total ' + totalDev + ' Devices '
                                  + '<span class="dvt-sub-missing">- ' + missingDev + ' Not installed</span>';
                } else {
                    sub.textContent = 'Total ' + totalDev + ' Devices';
                }
            }
        }).catch(function (err) {
            var sub = document.getElementById('dvt-subtitle');
            if (sub) sub.textContent = 'Failed to load';
            setHtml('dvt-overview-disks', '<div class="dvt-empty">Could not load disk data.</div>');
        });
    }

    function applyFontSize(cfg) {
        var host = document.getElementById('dvt-overview-disks');
        if (!host) return;
        var fs = (cfg.font_size === 'small' || cfg.font_size === 'large') ? cfg.font_size : 'default';
        host.classList.remove('dvt-font-small', 'dvt-font-default', 'dvt-font-large');
        host.classList.add('dvt-font-' + fs);
    }

    function setupRefresh(cfg) {
        if (_refreshTimer) { clearInterval(_refreshTimer); _refreshTimer = null; }
        if (!cfg.refresh_enabled) return;

        var ms = Math.max(5000, parseInt(cfg.refresh_interval, 10) || 10000);
        _refreshTimer = setInterval(function () {

            if (_activeTab !== 'dvtTabOverview') return;
            fetchJson('tool_overview').then(function (model) {
                var ov = model.overview || {};
                _lastUnit = ov.temp_unit === 'F' ? 'F' : 'C';
                renderOverviewDisks(model.sections || []);
                wireSpin();
                ensureFixedThead();
            }).catch(function () {});
        }, ms);
    }

    var COLS = [
        { key: 'bolt',    label: '',            cls: 'dvt-tbl__ctr',  width: 38  },
        { key: 'disk',    label: 'Disk',        cls: 'dvt-tbl__name', width: 110 },
        { key: 'fs',      label: 'FS',          cls: 'dvt-tbl__ctr',  width: 60  },
        { key: 'size',    label: 'Size',        cls: 'dvt-tbl__num',  width: 62  },
        { key: 'free',    label: 'Free',        cls: 'dvt-tbl__num',  width: 86  },
        { key: 'used',    label: 'Used',        cls: 'dvt-tbl__num',  hdCls: 'dvt-tbl__ctr', width: 150 },
        { key: 'speed',   label: 'Speed r/w',   cls: 'dvt-tbl__num',  width: 96  },
        { key: 'temp',    label: 'Temp',        cls: 'dvt-tbl__num',  width: 64  },
        { key: 'health',  label: 'Health',      cls: 'dvt-tbl__ctr',  width: 78  },
        { key: 'errors',  label: 'Errors',      cls: 'dvt-tbl__num',  width: 64  },
        { key: 'age',     label: 'Age/Hours',   cls: 'dvt-tbl__num',  width: 120, ph: true, fsw: true },
        { key: 'realloc', label: 'Realloc',     cls: 'dvt-tbl__num',  width: 72,  ph: true },
        { key: 'pending', label: 'Pending',     cls: 'dvt-tbl__num',  width: 72,  ph: true },
        { key: 'crc',     label: 'CRC',         cls: 'dvt-tbl__num',  width: 66,  ph: true },
        { key: 'verdict', label: 'Verdict',     cls: 'dvt-tbl__ctr',  width: 108, ph: true },
        { key: 'scrub',   label: 'Last scrub',  cls: 'dvt-tbl__ctr',  width: 94,  ph: true },
        { key: 'nscrub',  label: 'Next scrub',  cls: 'dvt-tbl__ctr',  width: 94,  ph: true },
        { key: 'frag',    label: 'Frag',        cls: 'dvt-tbl__num',  width: 64,  ph: true },
        { key: 's',       label: 'Settings',    cls: 'dvt-tbl__ctr',  width: 84  }
    ];

    function renderOverviewDisks(sections) {
        var anyTiles = false;
        for (var i = 0; i < sections.length; i++) {
            if ((sections[i].tiles || []).length) { anyTiles = true; break; }
        }
        if (!anyTiles) {
            setHtml('dvt-overview-disks', '<div class="dvt-empty">No disks found.</div>');
            return;
        }

        _lastSections = sections;

        var colSev = worstColSeverity(sections);

        // if any nvme reports a draw (and the native toggle is on), the temp column needs more room for "9.00 W / 42 C"
        var hasPower = false;
        if (_showPower) {
            for (var hs = 0; hs < sections.length && !hasPower; hs++) {
                var ht = sections[hs].tiles || [];
                for (var hi = 0; hi < ht.length; hi++) {
                    if ((+ht[hi].power || 0) > 0) { hasPower = true; break; }
                }
            }
        }

        var colgroup = '<colgroup>';
        var thead = '<thead><tr>';
        for (var c = 0; c < COLS.length; c++) {

            var colBaseW = COLS[c].width;
            var colFsw   = COLS[c].fsw;
            if (COLS[c].key === 'temp' && hasPower) { colBaseW = 100; colFsw = true; }
            var colW = colFsw
                     ? 'calc(' + colBaseW + 'px * var(--dvt-fs, 1))'
                     : (colBaseW + 'px');
            colgroup += '<col style="width:' + colW + '">';
            var hdCls = COLS[c].hdCls || COLS[c].cls;
            var sevForCol = colSev[COLS[c].key];
            if (sevForCol && sevForCol !== 'ok') hdCls += ' dvt-hd--' + sevForCol;
            thead += '<th class="' + hdCls + '">' + esc(COLS[c].label) + '</th>';
        }
        colgroup += '</colgroup>';
        thead += '</tr></thead>';

        var nCols = COLS.length;
        var html = '<div class="dvt-tbl-scroll"><table class="dvt-tbl dvt-tbl--wide">'
                 + colgroup + thead + '<tbody>';
        for (var s = 0; s < sections.length; s++) {
            var sec = sections[s];
            var tiles = sec.tiles || [];
            if (!tiles.length) continue;
            var secId = sec.id || '';

            var zebra = (secId === 'cache' || secId === 'pools');

            var actions = '';
            if (_enableSpin && secId === 'pools') {
                actions = '<span class="dvt-bulk-actions">'
                    + '<button type="button" class="dvt-bulk-spin" data-dvt-bulk="up" data-dvt-secid="' + esc(secId) + '" aria-label="Spin up all pool disks">' + BOLT_SVG + '<span class="dvt-bulk-arrow">' + ARROW_UP + '</span></button>'
                    + '<button type="button" class="dvt-bulk-spin" data-dvt-bulk="down" data-dvt-secid="' + esc(secId) + '" aria-label="Spin down all pool disks">' + BOLT_SVG + '<span class="dvt-bulk-arrow">' + ARROW_DOWN + '</span></button>'
                    + '</span>';
            }
            html += '<tr class="dvt-sec-row"><td colspan="' + nCols + '">'
                  + '<div class="dvt-sec-row__inner"><span class="dvt-sec-lbl">' + esc(sec.label || sec.id || '') + '</span>' + actions + '</div></td></tr>';

            var secHasSummary = false;
            for (var hs = 0; hs < tiles.length; hs++) {
                if (tiles[hs].is_summary) { secHasSummary = true; break; }
            }
            var memberIdx = 0;
            for (var t = 0; t < tiles.length; t++) {
                var tile = tiles[t];
                var zCls = '';
                if (zebra && !tile.is_summary) {
                    zCls = (memberIdx % 2 === 0) ? ' dvt-zebra-odd' : ' dvt-zebra-even';
                    memberIdx++;
                }
                html += renderDiskRow(tile, secId, zCls, secHasSummary);
            }
        }
        html += '</tbody></table></div>';
        setHtml('dvt-overview-disks', html);
    }

    function worstColSeverity(sections) {
        var rank = { ok: 0, warn: 1, crit: 2 };
        var w = { temp: 'ok', health: 'ok', errors: 'ok' };
        function bump(col, sev) { if (rank[sev] > rank[w[col]]) w[col] = sev; }
        for (var s = 0; s < sections.length; s++) {
            var tiles = sections[s].tiles || [];
            for (var t = 0; t < tiles.length; t++) {
                var d = tiles[t];
                var rawT = d.temp;
                if (rawT && rawT !== '*' && rawT !== '-') {
                    var n = parseInt(rawT, 10);
                    if (!isNaN(n)) {
                        var tc = +d.temp_critical || 0, tw = +d.temp_warning || 0;
                        if (tc && n >= tc) bump('temp', 'crit');
                        else if (tw && n >= tw) bump('temp', 'warn');
                    }
                }
                var sm = d.smart || '';
                if (sm === 'critical') bump('health', 'crit');
                else if (sm === 'warning') bump('health', 'warn');
                if ((+d.errors || 0) > 0) bump('errors', 'crit');
            }
        }
        return w;
    }

    var _fixedWrap  = null;
    var _fixedTable = null;
    var _fixedBound = false;

    var _hdrOffset = null;
    // find the height of whatever unraid is using as a fixed/sticky header so the cloned thead sits right under it
    function unraidHeaderOffset() {
        if (_hdrOffset !== null) return _hdrOffset;
        var sels = ['#header', '.header', 'header', '#menu'];
        for (var i = 0; i < sels.length; i++) {
            var el = document.querySelector(sels[i]);
            if (!el) continue;
            var cs = window.getComputedStyle(el);
            if (cs.position === 'fixed' || cs.position === 'sticky') {
                var h = el.getBoundingClientRect().height;
                if (h > 20 && h < 200) { _hdrOffset = Math.round(h); return _hdrOffset; }
            }
        }
        return 52;  // sensible default if no fixed header was found
    }

    function ensureFixedThead() {
        var realTable = document.querySelector('#dvt-overview-disks .dvt-tbl--wide');
        if (!realTable) {
            if (_fixedWrap) _fixedWrap.style.display = 'none';
            return;
        }
        if (!_fixedWrap) {
            _fixedWrap = document.createElement('div');
            _fixedWrap.style.display = 'none';
            document.body.appendChild(_fixedWrap);
        }

        var realThead = realTable.querySelector('thead');
        var realCols  = realTable.querySelector('colgroup');
        var dvtWrap = document.querySelector('.dvt-wrapper');
        var light   = (dvtWrap && dvtWrap.classList.contains('dvt-light')) ? ' dvt-light' : '';
        var themeM  = dvtWrap ? (dvtWrap.className.match(/\bdvt-theme-[a-z]+\b/) || [''])[0] : '';
        var theme   = themeM ? ' ' + themeM : '';
        var fontCls = (function () {
            var host = document.getElementById('dvt-overview-disks');
            if (!host) return '';
            if (host.classList.contains('dvt-font-small'))   return ' dvt-font-small';
            if (host.classList.contains('dvt-font-large'))   return ' dvt-font-large';
            if (host.classList.contains('dvt-font-default')) return ' dvt-font-default';
            return '';
        })();

        _fixedWrap.className = 'dvt-fixed-thead-wrap' + theme + light + fontCls;
        _fixedWrap.innerHTML = '';
        var clone = document.createElement('table');
        clone.className = 'dvt-tbl dvt-tbl--wide dvt-fixed-thead';
        if (realCols)  clone.appendChild(realCols.cloneNode(true));
        if (realThead) clone.appendChild(realThead.cloneNode(true));
        _fixedWrap.appendChild(clone);
        _fixedTable = clone;

        bindFixedTheadListeners();
        syncFixedThead();
    }

    // floats a clone of the table header once the real one scrolls under the unraid bar
    function syncFixedThead() {
        if (!_fixedWrap || !_fixedTable) return;
        var realTable = document.querySelector('#dvt-overview-disks .dvt-tbl--wide');
        var scroller  = document.querySelector('#dvt-overview-disks .dvt-tbl-scroll');
        var realThead = realTable && realTable.querySelector('thead');
        if (!realTable || !realThead || !scroller || _activeTab !== 'dvtTabOverview') {
            _fixedWrap.style.display = 'none';
            return;
        }
        var off    = unraidHeaderOffset();
        var sRect  = scroller.getBoundingClientRect();
        var hRect  = realThead.getBoundingClientRect();

        var show = (hRect.top < off) && (sRect.bottom > off + 4);
        if (!show) { _fixedWrap.style.display = 'none'; return; }

        _fixedWrap.style.position = 'fixed';
        _fixedWrap.style.top      = off + 'px';
        _fixedWrap.style.left     = Math.round(sRect.left) + 'px';
        _fixedWrap.style.width    = Math.round(sRect.width) + 'px';
        _fixedWrap.style.zIndex   = '90';
        _fixedTable.style.width = realTable.offsetWidth + 'px';
        _fixedTable.style.transform = 'translateX(' + (-scroller.scrollLeft) + 'px)';  // keep the clone in sync with horizontal scroll
        _fixedWrap.style.display = 'block';
    }

    function bindFixedTheadListeners() {
        if (_fixedBound) return;
        _fixedBound = true;
        window.addEventListener('scroll', syncFixedThead, { passive: true });
        window.addEventListener('resize', syncFixedThead, { passive: true });

        var host = document.getElementById('dvt-overview-disks');
        if (host) host.addEventListener('scroll', syncFixedThead, { passive: true, capture: true });
    }

    var SPEED_INTERVAL = 2000;  // speeds poll faster than the rest of the page
    var _speedTimer = null;

    function startSpeedPolling() {
        if (_speedTimer) return;
        _speedTimer = setInterval(pollSpeeds, SPEED_INTERVAL);
    }

    function pollSpeeds() {
        if (_activeTab !== 'dvtTabOverview') return;
        var host = document.getElementById('dvt-overview-disks');
        if (!host || !host.querySelector('.dvt-tbl__row')) return;
        fetchJson('speeds').then(function (arr) {
            if (Array.isArray(arr)) applySpeeds(arr, host);
        }).catch(function () {});
    }

    function applySpeeds(arr, host) {
        var byName = {};
        for (var i = 0; i < arr.length; i++) {
            if (arr[i] && arr[i].name) byName[arr[i].name] = arr[i];
        }
        var rows = host.querySelectorAll('.dvt-tbl__row');

        var agg = {};
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            var secId = row.getAttribute('data-dvt-section') || '';
            if (!agg[secId]) agg[secId] = { sumR: 0, sumW: 0, anySpun: false, summary: null };

            if (row.getAttribute('data-dvt-summary') === '1') {
                agg[secId].summary = row;
                continue;
            }
            var name = row.getAttribute('data-dvt-row') || '';
            var d = byName[name];
            if (!d) continue;

            var cell = row.querySelector('.dvt-speed-cell');
            if (cell) updateSpeedCell(cell, !!d.spun, +d.speed_bps || 0, d.speed_dir || '');
            var bps = +d.speed_bps || 0, dir = d.speed_dir || '';
            if (dir === 'r') agg[secId].sumR += bps;
            else if (dir === 'w') agg[secId].sumW += bps;
            if (d.spun) agg[secId].anySpun = true;
        }

        for (var sid in agg) {
            if (!agg.hasOwnProperty(sid)) continue;
            var a = agg[sid];
            if (!a.summary) continue;
            var sCell = a.summary.querySelector('.dvt-speed-cell');
            if (!sCell) continue;
            var total = a.sumR + a.sumW;
            updateSpeedCell(sCell, a.anySpun, total, total > 0 ? (a.sumR >= a.sumW ? 'r' : 'w') : '');
        }
    }

    function muted(txt) { return '<span class="dvt-na">' + txt + '</span>'; }

    var SLEEP_HTML = '<i class="fa fa-hdd-o dvt-sleep-ico" aria-hidden="true"></i>sleep';

    function buildSpeed(spun, bps, dir) {
        bps = +bps || 0;
        if (!spun)     return muted(SLEEP_HTML);
        if (bps <= 0)  return muted('idle');
        var isRead = (dir || '') === 'r';
        var arrow = isRead ? ARROW_DOWN : ARROW_UP;
        var cls = isRead ? 'dvt-speed--r' : 'dvt-speed--w';
        return '<span class="dvt-speed ' + cls + '">' + arrow
             + '<span class="dvt-speed-num">' + formatBytes(bps) + '/s</span></span>';
    }

    function speedCell(tile) {
        return buildSpeed(!!tile.spun, +tile.speed_bps || 0, tile.speed_dir || '');
    }

    function updateSpeedCell(cell, spun, bps, dir) {
        bps = +bps || 0;
        var active = spun && bps > 0;
        if (!active) {
            var emptyHtml = spun ? muted('idle') : muted(SLEEP_HTML);
            if (cell.innerHTML !== emptyHtml) cell.innerHTML = emptyHtml;
            return;
        }
        var wantCls = ((dir || '') === 'r') ? 'dvt-speed--r' : 'dvt-speed--w';
        var span = cell.querySelector('.dvt-speed');
        var numTxt = formatBytes(bps) + '/s';
        if (span && span.classList.contains(wantCls)) {
            var numEl = span.querySelector('.dvt-speed-num');
            if (numEl) { if (numEl.textContent !== numTxt) numEl.textContent = numTxt; }
            else cell.innerHTML = buildSpeed(true, bps, dir);
        } else {
            cell.innerHTML = buildSpeed(true, bps, dir);
        }
    }

    function boltCell(tile) {
        var isSummary = !!tile.is_summary;
        var isParity  = !!tile.is_parity;
        var spun      = !!tile.spun;
        var spinDisabled = !!tile.spin_disabled;
        var boltCls = spun ? 'dvt-bolt dvt-bolt--on' : 'dvt-bolt dvt-bolt--off';

        if (isSummary || tile.group === 'boot') {
            return '<span class="dvt-bolt dvt-bolt--static" aria-hidden="true">' + STACK_SVG + '</span>';
        }
        var canButton = !isParity && !spinDisabled && _enableSpin;
        if (canButton) {
            var dir = spun ? 'down' : 'up';
            var label = spun ? 'Click to spin down' : 'Click to spin up';
            return '<button type="button" class="' + boltCls + '" aria-label="' + label + '" '
                 + 'data-dvt-spin="' + dir + '" data-dvt-name="' + esc(tile.name || '') + '">' + BOLT_SVG + '</button>';
        }
        return '<span class="' + boltCls + ' dvt-bolt--static" aria-hidden="true">' + BOLT_SVG + '</span>';
    }

    function renderDiskRow(tile, secId, zCls, secHasSummary) {
        var isParity  = !!tile.is_parity;
        var isMember  = !!tile.is_pool_member;
        var isSummary = !!tile.is_summary;

        var isMemberRow = !!secHasSummary && !isSummary;
        var notInstalled = !!tile.not_installed;
        var collapse  = isParity || isMember || !!tile.no_capacity || notInstalled;
        var spun      = !!tile.spun;
        var sev = tile.severity || 'ok';

        var NA   = muted('n/a');
        var SPUN = muted(SLEEP_HTML);

        function metricEmpty() { return isSummary ? '' : (!spun ? SPUN : NA); }

        var name = esc((tile.display_name || tile.name || '').toUpperCase());

        var nameTip  = diskIdent(tile);
        var nameCell = '<span class="dvt-name' + (notInstalled ? ' dvt-name--missing' : '') + '"'
                     + (nameTip ? ' data-dvt-ident="' + esc(nameTip) + '"' : '')
                     + '>' + name + '</span>'
                     + (notInstalled ? ' <span class="dvt-missing-label">NOT INSTALLED or MISSING</span>' : '');
        var size = (tile.size > 0) ? formatBytes(tile.size) : NA;
        var free = collapse ? '' : (tile.size > 0 ? formatBytes(tile.free, 1, true) : NA);

        var usedCell;
        if (collapse) {
            usedCell = '';
        } else if (tile.size > 0) {
            var pct = (tile.pct != null) ? +tile.pct
                    : ((tile.size - (tile.free || 0)) / tile.size * 100);
            pct = Math.max(0, Math.min(100, pct));
            var pctText = _showDecimal ? pct.toFixed(2) + '%' : Math.round(pct) + '%';
            var usedSev = tile.severity || 'ok';
            var pctCls = 'dvt-col-used-pct'
                       + (usedSev === 'critical' ? ' dvt-col-used-pct--crit'
                        : usedSev === 'warning'  ? ' dvt-col-used-pct--warn' : '');
            var fillCls = 'dvt-bar-fill '
                        + (usedSev === 'critical' ? 'dvt-bar-fill--crit'
                         : usedSev === 'warning'  ? 'dvt-bar-fill--warn' : 'dvt-bar-fill--ok');
            var bytesSpan = _showUsedBytes
                ? '<span class="dvt-col-used-bytes">' + formatBytes((tile.size || 0) - (tile.free || 0), 1, true) + '</span>'
                : '';
            usedCell = '<div class="dvt-col-used"><div class="dvt-col-used-line">'
                     + bytesSpan + '<span class="' + pctCls + '">' + pctText + '</span></div>'
                     + '<div class="dvt-col-bar"><div class="' + fillCls + '" style="width:' + pct + '%"></div></div></div>';
        } else {
            usedCell = NA;
        }

        var fsRaw = (tile.fs || '').toString().trim();
        var fs = fsRaw.toLowerCase();
        var showFs = (isSummary || !!tile.style_as_summary || secId === 'pools' || secId === 'boot') && fsRaw;
        var fsHtml = showFs ? '<span class="dvt-fs-pill">' + esc(fsRaw) + '</span>' : '';

        var rawT = tile.temp, tempCell, tempCls = '';
        var hasTemp = false;
        if (rawT && rawT !== '*' && rawT !== '-') {
            var n = parseInt(rawT, 10);
            if (!isNaN(n)) {
                hasTemp = true;
                var tWarn = +tile.temp_warning || 0, tCrit = +tile.temp_critical || 0;
                if (tCrit && n >= tCrit) tempCls = ' dvt-crit';
                else if (tWarn && n >= tWarn) tempCls = ' dvt-warn';
                tempCell = '<span class="' + tempCls.trim() + '">' + n + (_lastUnit === 'F' ? '\u00b0F' : '\u00b0C') + '</span>';
            }
        }
        if (!hasTemp) tempCell = metricEmpty();

        // nvme power to the left of temp, like unraid (native toggle on and a draw reported)
        if (_showPower && !isSummary) {
            var pw = +tile.power || 0;
            if (pw > 0) tempCell = '<span class="dvt-pwr">' + (pw < 10 ? pw.toFixed(2) : pw.toFixed(1)) + ' W / </span>' + tempCell;
        }

        var smart = tile.smart || 'unknown';
        var thumbDir = smart === 'critical' ? 'down' : 'up';
        var thumbCol = smart === 'critical' ? 'dvt-thumb--crit' :
                       smart === 'warning'  ? 'dvt-thumb--warn' :
                       smart === 'healthy'  ? 'dvt-thumb--ok'   : 'dvt-thumb--na';
        var healthHtml = '<span class="dvt-thumb ' + thumbCol + ' dvt-thumb--' + thumbDir
                       + '" title="SMART: ' + esc(smart === 'unknown' ? 'no data' : smart) + '">' + THUMB_SVG + '</span>';

        var devName = encodeURIComponent(tile.main_dev || tile.name || '');
        // gear links to a real device page; summaries are synthetic, so no gear there (matches unraid main)
        var gearHtml = isSummary ? '' : '<a class="dvt-gear" href="/Main/Device?name=' + devName
                     + '" title="Disk settings" aria-label="Open disk settings">' + GEAR_SVG + '</a>';

        var errCount = +tile.errors || 0;
        var errText = isSummary ? '' : (errCount > 0 ? '<span class="dvt-crit">' + errCount + '</span>' : '0');

        var sa = tile.smart_attrs || null;

        if (sa && sa.wear_pct !== null && sa.wear_pct !== undefined) {
            var wcls = sa.wear_pct >= 90 ? 'dvt-crit' : sa.wear_pct >= 75 ? 'dvt-warn' : 'dvt-ok';

            healthHtml = '<span class="dvt-health">' + healthHtml
                       + '<span class="dvt-health-wear ' + wcls + '">' + sa.wear_pct + '%</span></span>';
        }
        function smartNum(v, warnGt, critGt) {
            if (v === null || v === undefined) return metricEmpty();

            var cls;
            if (critGt !== undefined && v > critGt) cls = 'dvt-crit';
            else if (warnGt !== undefined && v > warnGt) cls = 'dvt-warn';
            else cls = 'dvt-ok';
            return '<span class="' + cls + '">' + v + '</span>';
        }
        var ageCell;
        if (sa && sa.age_hours !== null && sa.age_hours !== undefined) {

            var yrTxt = (sa.age_hours / 8760).toFixed(1) + ' y';
            var hrTxt = String(sa.age_hours).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ' h';
            ageCell = '<span class="dvt-col-age">' + yrTxt + ' / ' + hrTxt + '</span>';
        } else {
            ageCell = metricEmpty();
        }
        var verdictCell;
        if (tile.verdict) {
            var vsev = tile.verdict_sev || 'ok';
            var vcls = vsev === 'critical' ? 'dvt-crit' : vsev === 'warning' ? 'dvt-warn' : vsev === 'ok' ? 'dvt-ok' : '';
            verdictCell = '<span class="' + vcls + '">' + esc(tile.verdict) + '</span>';
        } else {
            verdictCell = metricEmpty();
        }

        function relAge(ts, fmt) {
            var d = new Date(ts * 1000), now = new Date();
            if (d.getFullYear() === now.getFullYear()
                && d.getMonth() === now.getMonth()
                && d.getDate() === now.getDate()) {
                return '<span class="dvt-scrub-today">today</span>';
            }

            var label = fmt;
            if (!label) {
                var mm = ('0' + (d.getMonth() + 1)).slice(-2);
                var dd = ('0' + d.getDate()).slice(-2);
                label = d.getFullYear() + '/' + mm + '/' + dd;
            }
            return '<span class="dvt-scrub-date">' + esc(label) + '</span>';
        }
        // next-scrub label, rough on purpose (days, then months)
        function relFuture(ts) {
            var days = Math.ceil((ts - Date.now() / 1000) / 86400);
            if (days <= 0)  return 'soon';
            if (days === 1) return 'tomorrow';
            if (days < 60)  return 'in ' + days + ' days';
            return 'in ' + Math.round(days / 30) + ' mo';
        }
        var scrubCapable = (fs === 'btrfs' || fs === 'zfs') && (isSummary || (!isParity && !isMember));

        var scrubCell  = isMemberRow ? '' : (scrubCapable ? (tile.scrub_last_ts ? relAge(tile.scrub_last_ts, tile.scrub_last_fmt) : muted('no scrub')) : NA);
        var fragCell   = isMemberRow ? '' : ((fs === 'zfs' && scrubCapable && tile.scrub_frag != null && tile.scrub_frag !== '')
                       ? esc(tile.scrub_frag) : NA);
        var nscrubCell = isMemberRow ? '' : (scrubCapable ? (tile.scrub_next_ts ? relFuture(tile.scrub_next_ts) : muted('no schedule')) : NA);

        var speedCellHtml = '<span class="dvt-speed-cell">' + speedCell(tile) + '</span>';

        var cells = {
            bolt:    boltCell(tile),
            disk:    nameCell,
            fs:      fsHtml,
            size:    size,
            free:    free,
            used:    usedCell,
            speed:   speedCellHtml,
            temp:    tempCell,
            health:  healthHtml,
            s:       gearHtml,
            age:     ageCell,

            realloc: smartNum(sa ? sa.realloc : null, 0, 10),
            pending: smartNum(sa ? sa.pending : null, 0, 5),
            crc:     smartNum(sa ? sa.crc : null, 100, undefined),
            verdict: verdictCell,
            scrub:   scrubCell,
            errors:  errText,
            frag:    fragCell,
            nscrub:  nscrubCell
        };

        if (notInstalled) {
            cells.fs = cells.size = cells.free = cells.used = '';
            cells.speed = cells.temp = cells.health = '';
            cells.age = cells.realloc = cells.pending = cells.crc = '';
            cells.verdict = cells.scrub = cells.errors = cells.frag = cells.nscrub = '';
        }

        var rowCls = 'dvt-tbl__row';
        if (isSummary) rowCls += ' dvt-tbl__row--summary';
        else if (isMemberRow) rowCls += ' dvt-tbl__row--member';
        if (notInstalled) rowCls += ' dvt-tbl__row--missing';

        if (secId === 'boot' && !isMemberRow) rowCls += ' dvt-tbl__row--boot';
        rowCls += (zCls || '');

        var tds = '';
        for (var c = 0; c < COLS.length; c++) {
            var col = COLS[c];
            tds += '<td class="' + col.cls + '">' + cells[col.key] + '</td>';
        }
        return '<tr class="' + rowCls + '" data-dvt-row="' + esc(tile.name || '')
             + '" data-dvt-section="' + esc(secId || '') + '"'
             + (isSummary ? ' data-dvt-summary="1"' : '') + '>' + tds + '</tr>';
    }

    function wireSpin() {
        var host = document.getElementById('dvt-overview-disks');
        if (!host || host._dvtSpinWired) return;
        host._dvtSpinWired = true;
        host.addEventListener('click', function (ev) {
            var btn = ev.target.closest && ev.target.closest('button.dvt-bolt');
            if (!btn) return;
            ev.preventDefault();
            var dir  = btn.getAttribute('data-dvt-spin');
            var name = btn.getAttribute('data-dvt-name') || '';
            if (!name || !dir) return;
            btn.disabled = true;
            btn.classList.add('dvt-bolt--busy');
            var body = 'name=' + encodeURIComponent(name) + '&direction=' + encodeURIComponent(dir)
                     + '&csrf_token=' + encodeURIComponent(csrfToken());
            fetch(API + '?action=spin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: body
            }).then(function (r) { return r.json(); }).then(function (r) {

                _overviewLoaded = false;
                loadOverview();
            }).catch(function () {
                btn.classList.remove('dvt-bolt--busy');
                btn.disabled = false;
            });
        });
    }

    function wireBulkSpin() {
        var host = document.getElementById('dvt-overview-disks');
        if (!host || host._dvtBulkWired) return;
        host._dvtBulkWired = true;
        host.addEventListener('click', function (ev) {
            var btn = ev.target.closest && ev.target.closest('button.dvt-bulk-spin');
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            var dir   = btn.getAttribute('data-dvt-bulk');
            var secId = btn.getAttribute('data-dvt-secid') || '';
            if (dir !== 'up' && dir !== 'down') return;

            var sec = null;
            for (var i = 0; i < _lastSections.length; i++) {
                if (_lastSections[i].id === secId) { sec = _lastSections[i]; break; }
            }
            if (!sec || !sec.tiles || !sec.tiles.length) return;

            var targets = [];
            for (var j = 0; j < sec.tiles.length; j++) {
                var t = sec.tiles[j];
                if (t.is_summary || t.spin_disabled) continue;
                if (dir === 'up' && t.spun) continue;
                if (dir === 'down' && !t.spun) continue;
                if (t.name) targets.push(t.name);
            }

            var allBulk = host.querySelectorAll('button.dvt-bulk-spin');
            function release(reload) {
                for (var m = 0; m < allBulk.length; m++) {
                    allBulk[m].disabled = false;
                    allBulk[m].classList.remove('dvt-bolt--busy');
                }
                if (reload) { _overviewLoaded = false; loadOverview(); }
            }
            for (var k = 0; k < allBulk.length; k++) {
                allBulk[k].disabled = true;
                allBulk[k].classList.add('dvt-bolt--busy');
            }
            if (targets.length === 0) { setTimeout(function () { release(true); }, 400); return; }

            var throttleMs = (dir === 'up') ? 350 : 200;
            var idx = 0;
            function next() {
                if (idx >= targets.length) { setTimeout(function () { release(true); }, 1000); return; }
                var name = targets[idx++];
                var body = 'name=' + encodeURIComponent(name) + '&direction=' + encodeURIComponent(dir)
                         + '&csrf_token=' + encodeURIComponent(csrfToken());
                fetch(API + '?action=spin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: body
                }).then(function () { setTimeout(next, throttleMs); })
                  .catch(function () { setTimeout(next, throttleMs); });
            }
            next();
        });
    }

    var _tip = null;
    function ensureTip() {
        if (_tip) return _tip;
        _tip = document.createElement('div');
        _tip.className = 'dvt-tip';
        _tip.style.display = 'none';
        document.body.appendChild(_tip);
        return _tip;
    }
    function showTip(btn, variant, text, align) {
        var tip = ensureTip();
        tip.className = 'dvt-tip dvt-tip--' + variant;
        tip.innerHTML = text;
        tip.style.display = 'block';
        var r = btn.getBoundingClientRect();
        var tr = tip.getBoundingClientRect();

        var left = (align === 'left')  ? (r.left - 9)
                 : (align === 'right') ? (r.right + 9 - tr.width)
                 : (r.left + (r.width / 2) - (tr.width / 2));
        left = Math.max(6, Math.min(left, window.innerWidth - tr.width - 6));
        var top = r.top - tr.height - 6;
        if (top < 4) top = r.bottom + 6;
        tip.style.left = Math.round(left) + 'px';
        tip.style.top  = Math.round(top) + 'px';
    }
    function hideTip() { if (_tip) _tip.style.display = 'none'; }

    function wireTooltips() {
        var host = document.getElementById('dvt-overview-disks');
        if (!host || host._dvtTipWired) return;
        host._dvtTipWired = true;
        host.addEventListener('mouseover', function (ev) {
            var nameEl = ev.target.closest && ev.target.closest('.dvt-name[data-dvt-ident]');
            if (nameEl) {
                var ident = nameEl.getAttribute('data-dvt-ident') || '';
                if (ident) showTip(nameEl, 'info', esc(ident), 'left');
                return;
            }
            var bulk = ev.target.closest && ev.target.closest('button.dvt-bulk-spin');
            if (bulk) {
                if (bulk.getAttribute('data-dvt-bulk') === 'down') showTip(bulk, 'crit', '\u26A0 Spin down all pool disks', 'right');
                else showTip(bulk, 'ok', 'Spin up all pool disks', 'right');
                return;
            }
            var bolt = ev.target.closest && ev.target.closest('button.dvt-bolt');
            if (bolt) {
                var name = (bolt.getAttribute('data-dvt-name') || '').toUpperCase();
                if (bolt.getAttribute('data-dvt-spin') === 'down') showTip(bolt, 'crit', '\u26A0 Spin down ' + esc(name) + ' now', 'left');
                else showTip(bolt, 'ok', 'Spin up ' + esc(name), 'left');
            }
        });
        host.addEventListener('mouseout', function (ev) {
            if (ev.target.closest && (ev.target.closest('button.dvt-bulk-spin') || ev.target.closest('button.dvt-bolt') || ev.target.closest('.dvt-name[data-dvt-ident]'))) hideTip();
        });

        window.addEventListener('scroll', hideTip, { passive: true });
    }

    function loadDisksTab() {
        var sb = document.getElementById('dvt-disks-sidebar');
        if (sb) sb.innerHTML = '<div style="padding:1rem;color:var(--dvt-text-dim);">Disk list pending</div>';
    }

    function boot() {
        wireTabs();
        loadOverview();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.diskviewerTool = { switchTab: switchTab, fetchJson: fetchJson };
})();
