/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

(function(){

    var cfg = window.diskviewerConfig || {};
    var dragStepRows    = +cfg.dragStepRows || 1;
    var refreshEnabled  = cfg.refreshEnabled !== false;
    var refreshInterval = +cfg.refreshInterval || 20000;
    var warningPct      = +cfg.warningPct  || 95;
    var criticalPct     = +cfg.criticalPct || 98;
    var tempWarning     = +cfg.tempWarning  || 45;
    var tempCritical    = +cfg.tempCritical || 55;

    var tempUnit        = (cfg.tempUnit === 'F') ? 'F' : 'C';

    var spaceSeverityEnabled = (cfg.spaceSeverityEnabled !== false);

    // raw temps stay in celsius (disks.ini convention), we only convert at render time
    function fmtTemp(celsius){
        if (tempUnit === 'F') {
            return Math.round(9/5 * celsius + 32) + '°F';
        }
        return celsius + '°C';
    }

    var defaultExpandLevel = +cfg.defaultExpandRows || 0;
    var enableSpinButton  = !!cfg.enableSpinButton;
    var poolHighlightUsed = !!cfg.poolHighlightUsed;
    var showFsBadge       = cfg.showFsBadge !== false;
    var showDiskErrors    = cfg.showDiskErrors !== false;
    var showPower         = !!cfg.showPower;
    var showDecimalPct    = !!cfg.showDecimalPct;
    var showUsedColumn    = !!cfg.showUsedColumn;
    var showIdTooltip     = cfg.showIdTooltip !== false;
    var showSectionIndicators = cfg.showSectionIndicators !== false;
    var fontSize          = (cfg.fontSize === 'small' || cfg.fontSize === 'large') ? cfg.fontSize : 'default';
    var STORAGE_KEY  = 'dv_expand_v3';

    var API_URL      = '/plugins/diskviewer/include/diskviewer_api.php';

    var SPEED_REFRESH_INTERVAL = 2000;
    var pollTimer    = null;
    var speedTimer   = null;
    var lastModel    = null;
    var expandRows   = null;
    var dragState    = null;

    function loadExpand(){
        try {
            var v = sessionStorage.getItem(STORAGE_KEY);
            if (v === null || v === '') return 0;
            var n = parseInt(v, 10);
            if (isNaN(n)) return 0;

            return Math.max(-100, Math.min(100, n));  // allow negative: a value below baseline means the user dragged the widget collapsed
        } catch(e){ return 0; }
    }

    function saveExpand(n){
        try { sessionStorage.setItem(STORAGE_KEY, String(n)); } catch(e){}
    }

    // decimal units (1000) to match unraid's main page and the size printed on the drive
    function formatBytes(bytes, precision, alwaysTwo){
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

    function diskIdent(row){
        if (!row || row.is_summary || !showIdTooltip || row.not_installed) return '';
        var id  = (row.ident_id || '').toString().trim();
        var dev = (row.dev_short || '').toString().trim();
        var s = id;
        if (dev) s += (s ? ' ' : '') + '(' + dev + ')';
        return s;
    }

    function escapeHtml(s){
        s = String(s == null ? '' : s);
        return s.replace(/[&<>"']/g, function(c){
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    var GEAR_SVG   = '<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 01-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 01.872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 012.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 012.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 01.872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 01-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 01-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 110-5.858 2.929 2.929 0 010 5.858z"/></svg>';
    var BOLT_SVG   = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';

    var STACK_SVG  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l-9 4.5l9 4.5l9 -4.5l-9 -4.5"/><path d="M3 13.5l9 4.5l9 -4.5"/></svg>';
    var THUMB_SVG  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73V10z"/></svg>';

    var ARROW_UP   = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 14l5-5 5 5z"/></svg>';
    var ARROW_DOWN = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>';

    var WARN_TRIANGLE_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L1 22h22L12 2zm0 4.45L19.95 20H4.05L12 6.45zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>';

    var THERMO_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 13V5a3 3 0 0 0-6 0v8a5 5 0 1 0 6 0zm-3-9a1 1 0 0 1 1 1v3h-2V5a1 1 0 0 1 1-1z"/></svg>';

    function computeColumnSeverities(model){
        var worstPct = 'ok', worstTemp = 'ok', worstHealth = 'ok';
        var rank = { ok: 0, warning: 1, critical: 2 };
        var sections = model && model.sections || [];

        for (var i = 0; i < sections.length; i++) {
            var tiles = sections[i].tiles || [];
            for (var j = 0; j < tiles.length; j++) {
                var t = tiles[j];
                if (t.is_summary) continue;
                if (t.is_parity)  continue;

                var sev = t.severity || 'ok';
                if ((rank[sev] || 0) > (rank[worstPct] || 0)) worstPct = sev;

                var raw = t.temp;
                if (raw && raw !== '*' && raw !== '-') {
                    var n = parseInt(raw, 10);
                    if (!isNaN(n)) {
                        var tWarn = +t.temp_warning  || tempWarning;
                        var tCrit = +t.temp_critical || tempCritical;
                        var tsev = n >= tCrit ? 'critical' :
                                   n >= tWarn ? 'warning'  : 'ok';
                        if ((rank[tsev] || 0) > (rank[worstTemp] || 0)) worstTemp = tsev;
                    }
                }

                var smart = t.smart || 'unknown';
                var hsev = smart === 'critical' ? 'critical' :
                           smart === 'warning'  ? 'warning'  : 'ok';
                if ((rank[hsev] || 0) > (rank[worstHealth] || 0)) worstHealth = hsev;
            }
        }
        return { pct: worstPct, temp: worstTemp, health: worstHealth };
    }

    function severityModifier(prefix, sev){
        if (sev === 'critical') return ' ' + prefix + '--crit';
        if (sev === 'warning')  return ' ' + prefix + '--warn';
        return '';
    }

    function levelOfSectionId(id){
        if (id === 'array') return 0;
        if (id && id.indexOf('pool_') === 0) return 1;
        if (id === 'pools') return 2;
        if (id === 'unassigned') return 2;
        if (id === 'boot') return 2;
        return 3;
    }

    function computeBaselineRowCount(level){
        var container = $('dv-sections');
        if (!container) return 0;
        var sections = container.querySelectorAll('.dv-section');
        var count = 0;
        for (var i = 0; i < sections.length; i++) {
            var secId = sections[i].getAttribute('data-section') || '';
            if (levelOfSectionId(secId) <= level) {
                count += sections[i].querySelectorAll('.dv-row').length;
            }
        }
        return count;
    }

    function renderColumnHeaders(colSev){
        colSev = colSev || { pct: 'ok', temp: 'ok', health: 'ok' };
        var usedCls   = 'dv-colhd-used'   + severityModifier('dv-colhd-used',   colSev.pct);
        var tempCls   = 'dv-colhd-temp'   + severityModifier('dv-colhd-temp',   colSev.temp);
        var healthCls = 'dv-colhd-health' + severityModifier('dv-colhd-health', colSev.health);
        return '<div class="dv-colhd">' +
            '<span></span>' +
            '<span class="dv-colhd-name">DISK</span>' +
            '<span class="dv-colhd-size">SIZE</span>' +
            '<span class="dv-colhd-free">FREE</span>' +
            '<span class="' + usedCls + '">USED</span>' +
            '<span class="dv-colhd-speed">SPEED R/W</span>' +
            '<span class="' + tempCls + '">TEMP</span>' +
            '<span class="' + healthCls + '">H</span>' +
            '<span class="dv-colhd-settings">S</span>' +
        '</div>';
    }

    function buildSpeedHtml(o){
        var spun      = !!o.spun;
        var speed     = +o.speed || 0;
        var speedDir  = String(o.speedDir || '');

        if (spun && speed > 0) {
            var isRead = (speedDir === 'r');
            var arrow  = isRead ? ARROW_DOWN : ARROW_UP;

            var dirCls = isRead ? 'dv-col-speed--r' : 'dv-col-speed--w';
            return '<span class="dv-col-speed ' + dirCls + '">' + arrow
                 + '<span class="dv-col-speed-num">' + formatBytes(speed) + '/s</span></span>';
        }
        // mirror the tool: awake but no i/o -> idle, spun down -> sleep
        return '<span class="dv-col-speed-na">' + (spun ? 'idle' : 'sleep') + '</span>';
    }

    function updateSpeedCell(cell, o){
        var spun  = !!o.spun;
        var speed = +o.speed || 0;
        if (!(spun && speed > 0)) {
            if (!cell.querySelector('.dv-col-speed-na')) cell.innerHTML = '<span class="dv-col-speed-na">-</span>';
            return;
        }
        var wantCls = (String(o.speedDir || '') === 'r') ? 'dv-col-speed--r' : 'dv-col-speed--w';
        var span = cell.querySelector('.dv-col-speed');
        var numTxt = formatBytes(speed) + '/s';
        if (span && span.className.indexOf(wantCls) !== -1) {
            var numEl = span.querySelector('.dv-col-speed-num');
            if (numEl) { if (numEl.textContent !== numTxt) numEl.textContent = numTxt; }
            else cell.innerHTML = buildSpeedHtml(o);
        } else {
            cell.innerHTML = buildSpeedHtml(o);
        }
    }

    function renderRow(row, isMember){
        var rawName  = row.name || '';

        var displayName = row.display_name || rawName;
        var nameEsc  = escapeHtml(displayName);
        var devName  = encodeURIComponent(row.main_dev || rawName);
        var pct      = Math.max(0, Math.min(100, +row.pct || 0));
        var isSummary = !!row.is_summary;
        var isParity  = !!row.is_parity;
        var spinDisabled = !!row.spin_disabled;
        var notInstalled = !!row.not_installed;
        var severity  = row.severity || 'ok';
        var size      = +row.size || 0;
        var free      = +row.free || 0;
        var temp      = row.temp || '*';
        var spun      = !!row.spun;
        var smart     = row.smart || 'unknown';
        var speed     = +row.speed_bps || 0;
        var speedDir  = row.speed_dir || '';
        var errors    = +row.errors || 0;

        var cls = 'dv-row';

        var stylesAsSummary = !!row.style_as_summary;
        if (isSummary)            cls += ' dv-row--summary';
        if (isMember)             cls += ' dv-row--member';

        if (row.group === 'boot' && !isMember) cls += ' dv-row--boot';
        if (severity === 'warning')  cls += ' dv-row--warn';
        if (severity === 'critical') cls += ' dv-row--crit';

        var boltCls = spun ? 'dv-bolt dv-bolt--on' : 'dv-bolt dv-bolt--off';

        var boltLabel = spun ? 'Click to spin down' : 'Click to spin up';
        var boltEl;
        var canBeButton = !isSummary && !isParity && !spinDisabled && enableSpinButton;
        if (isSummary || row.group === 'boot') {

            boltEl = '<span class="dv-bolt dv-bolt--static" aria-hidden="true">' + STACK_SVG + '</span>';
        } else if (canBeButton) {
            boltEl = '<button type="button" class="' + boltCls + '" aria-label="' + boltLabel + '" data-dv-spin="' + (spun ? 'down' : 'up') + '" data-dv-name="' + escapeHtml(row.name || '') + '">' + BOLT_SVG + '</button>';
        } else {
            boltEl = '<span class="' + boltCls + ' dv-bolt--static" aria-hidden="true">' + BOLT_SVG + '</span>';
        }

        var sizeText, freeText, usedText;
        var isPoolMember = !!row.is_pool_member;
        var collapseCapacity = isParity || isPoolMember || !!row.no_capacity || notInstalled;
        if (collapseCapacity) {
            sizeText = escapeHtml(formatBytes(size));
            freeText = '';
            usedText = '';
        } else if (size > 0) {
            sizeText = escapeHtml(formatBytes(size));
            freeText = escapeHtml(formatBytes(free, 1, true));
            usedText = escapeHtml(formatBytes(size - free, 1, true));
        } else {
            sizeText = '-';
            freeText = '-';
            usedText = '-';
        }

        var pctText;
        if (showDecimalPct) {
            pctText = pct.toFixed(2) + '%';
        } else {
            pctText = Math.round(pct) + '%';
        }

        var usedPctCls = 'dv-col-used-pct' +
                         (severity === 'critical' ? ' dv-col-used-pct--crit' :
                          severity === 'warning'  ? ' dv-col-used-pct--warn' : '');

        var fillCls = severity === 'critical' ? 'dv-bar-fill dv-bar-fill--crit' :
                      severity === 'warning'  ? 'dv-bar-fill dv-bar-fill--warn' : 'dv-bar-fill dv-bar-fill--ok';

        var usedCellHtml;
        if (collapseCapacity) {

            usedCellHtml =
                '<div class="dv-col-used">' +
                    '<div class="dv-col-used-line"><span class="dv-col-used-pct"></span></div>' +
                    '<div class="dv-col-bar dv-col-bar--empty"></div>' +
                '</div>';
        } else {
            var bytesSpan = showUsedColumn ? '<span class="dv-col-used-bytes">' + usedText + '</span>' : '';
            usedCellHtml =
                '<div class="dv-col-used">' +
                    '<div class="dv-col-used-line">' +
                        bytesSpan +
                        '<span class="' + usedPctCls + '">' + pctText + '</span>' +
                    '</div>' +
                    '<div class="dv-col-bar"><div class="' + fillCls + '" style="width:' + pct + '%"></div></div>' +
                '</div>';
        }

        var speedHtml = buildSpeedHtml({
            errors: errors, spun: spun, speed: speed, speedDir: speedDir,
            isSummary: isSummary, isParity: isParity
        });

        var tempText = isSummary ? '' : (spun ? 'n/a' : 'sleep');
        var tempCls  = 'dv-col-temp dv-temp-na';
        if (temp && temp !== '*' && temp !== '-') {
            var n = parseInt(temp, 10);
            if (!isNaN(n)) {

                var rowWarn = +row.temp_warning  || tempWarning;
                var rowCrit = +row.temp_critical || tempCritical;
                tempText = fmtTemp(n);
                tempCls = 'dv-col-temp ' + (
                    n >= rowCrit ? 'dv-temp-crit' :
                    n >= rowWarn ? 'dv-temp-warn' : 'dv-temp-ok'
                );
            }
        }

        var thumbDir = smart === 'critical' ? 'down' : 'up';
        var thumbCol = smart === 'critical' ? 'dv-thumb--crit' :
                       smart === 'warning'  ? 'dv-thumb--warn' :
                       smart === 'healthy'  ? 'dv-thumb--ok'   : 'dv-thumb--na';
        var smartTitle = smart === 'unknown' ? 'no data' : smart;
        var thumbHtml = '<span class="dv-thumb ' + thumbCol + ' dv-thumb--' + thumbDir + '" title="SMART: ' + escapeHtml(smartTitle) + '">' + THUMB_SVG + '</span>';

        if (notInstalled) {
            sizeText     = '';
            freeText     = '';
            usedCellHtml = '<div class="dv-col-used"></div>';
            speedHtml    = '';
            tempText     = '';
            thumbHtml    = '';
        }

        // gear links to a real device page; summaries are synthetic, so no gear there (matches unraid main)
        var gearEl = isSummary
            ? '<span class="dv-col-gear"></span>'
            : '<a class="dv-col-gear" href="/Main/Device?name=' + devName + '" title="Disk settings" aria-label="Open disk settings">' + GEAR_SVG + '</a>';

        var nameTip  = diskIdent(row);
        var nameHtml = '<span class="dv-name' + (notInstalled ? ' dv-name--missing' : '') + '"'
                     + (nameTip ? ' data-dv-ident="' + escapeHtml(nameTip) + '"' : '')
                     + '>' + nameEsc + '</span>'
                     + (notInstalled ? ' <span class="dv-missing-label">NOT INSTALLED or MISSING</span>' : '');
        if ((isSummary || stylesAsSummary) && row.fs && showFsBadge) {
            nameHtml += ' <span class="dv-fs-pill">' + escapeHtml(row.fs) + '</span>';
        }

        var toastHtml = '<div class="dv-row-toast" role="tooltip" aria-hidden="true"></div>';

        // nvme power to the left of temp, like unraid (only when the native toggle is on and a draw is reported)
        var pwrHtml = '';
        if (showPower && !notInstalled) {
            var pw = +row.power || 0;
            if (pw > 0) pwrHtml = '<span class="dv-col-pwr">' + (pw < 10 ? pw.toFixed(2) : pw.toFixed(1)) + ' W / </span>';
        }

        return '<div class="' + cls + '" data-name="' + escapeHtml(row.name || '') + '" data-severity="' + severity + '"' + (isSummary ? ' data-is-summary="1"' : '') + (notInstalled ? ' data-dv-missing="1"' : '') + '>' +
            '<span class="dv-col-bolt">' + boltEl + '</span>' +
            '<div class="dv-col-name' + (notInstalled ? ' dv-col-name--missing' : '') + '">' + nameHtml + '</div>' +
            '<span class="dv-col-size">' + sizeText + '</span>' +
            '<span class="dv-col-free">' + freeText + '</span>' +
            usedCellHtml +
            '<span class="dv-col-speed-wrap">' + speedHtml + '</span>' +
            '<span class="' + tempCls + '">' + pwrHtml + escapeHtml(tempText) + '</span>' +
            '<span class="dv-col-thumb">' + thumbHtml + '</span>' +
            gearEl +
            toastHtml +
        '</div>';
    }

    function renderSection(sec){

        var rawLabel = sec.label || '';
        var count    = +sec.count || 0;
        var raid     = sec.raid || '';
        var secId    = sec.id || '';
        var pieces = [];
        if (rawLabel) pieces.push(rawLabel);

        if (secId !== 'boot' || count > 1) pieces.push((count === 1 ? 'DEVICE' : 'DEVICES') + ' ' + count);
        if (raid) pieces.push(raid);
        var label = escapeHtml(pieces.join(' · '));
        var rows  = sec.tiles || [];

        var hasSummary = false;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].is_summary) { hasSummary = true; break; }
        }

        var rankS = { ok: 0, warning: 1, critical: 2 };
        var errDisks = [], healthDisks = [];
        var tempWarnDisks = [], tempCritDisks = [];
        var tempCritBlink = false;
        var healthWorst = 'warning';

        for (var bi = 0; bi < rows.length; bi++) {
            var bt = rows[bi];
            if (bt.is_summary) continue;
            var dlabel = bt.display_name || bt.name || '?';

            if (showDiskErrors) {
                var errCount = +bt.errors || 0;
                if (errCount > 0) {
                    errDisks.push(dlabel + ' (' + errCount + ' error' + (errCount === 1 ? '' : 's') + ')');
                }
            }

            var raw = bt.temp;
            if (raw && raw !== '*' && raw !== '-') {
                var n = parseInt(raw, 10);
                if (!isNaN(n)) {
                    var tWarn = +bt.temp_warning  || tempWarning;
                    var tCrit = +bt.temp_critical || tempCritical;
                    if (n >= tCrit) {
                        tempCritDisks.push(dlabel + ' (' + n + '\u00b0)');
                        if (n >= tCrit * 1.10) tempCritBlink = true;
                    } else if (n >= tWarn) {
                        tempWarnDisks.push(dlabel + ' (' + n + '\u00b0)');
                    }
                }
            }

            var smart = bt.smart || 'unknown';
            var hsev = smart === 'critical' ? 'critical' : smart === 'warning' ? 'warning' : 'ok';
            if (hsev !== 'ok') {
                healthDisks.push(dlabel);
                if (rankS[hsev] > rankS[healthWorst]) healthWorst = hsev;
            }
        }

        var indHtml = '';

        function toastInner(title, items) {
            var s = '<span class="dv-toast-title">' + escapeHtml(title) + '</span>';
            for (var i = 0; i < items.length; i++) {
                s += '<span class="dv-toast-item">' + escapeHtml(items[i]) + '</span>';
            }
            return s;
        }
        if (showSectionIndicators) {
            if (errDisks.length > 0) {
                indHtml += '<span class="dv-section-ind dv-section-ind--err">'
                         + WARN_TRIANGLE_SVG
                         + '<span class="dv-section-ind-badge">' + errDisks.length + '</span>'
                         + '<span class="dv-row-toast dv-row-toast--warn">' + toastInner('Disk errors', errDisks) + '</span>'
                         + '</span>';
            }
            if (tempWarnDisks.length > 0) {
                indHtml += '<span class="dv-section-ind dv-section-ind--warn">'
                         + THERMO_SVG
                         + '<span class="dv-section-ind-badge">' + tempWarnDisks.length + '</span>'
                         + '<span class="dv-row-toast dv-row-toast--warn">' + toastInner('High temp (warning)', tempWarnDisks) + '</span>'
                         + '</span>';
            }
            if (tempCritDisks.length > 0) {
                var critBlinkCls = tempCritBlink ? ' dv-section-ind--blink' : '';
                indHtml += '<span class="dv-section-ind dv-section-ind--crit' + critBlinkCls + '">'
                         + THERMO_SVG
                         + '<span class="dv-section-ind-badge">' + tempCritDisks.length + '</span>'
                         + '<span class="dv-row-toast dv-row-toast--crit">' + toastInner('High temp (critical)', tempCritDisks) + '</span>'
                         + '</span>';
            }
            if (healthDisks.length > 0) {
                indHtml += '<span class="dv-section-ind dv-section-ind--crit dv-section-ind--health">'
                         + THUMB_SVG
                         + '<span class="dv-section-ind-badge">' + healthDisks.length + '</span>'
                         + '<span class="dv-row-toast dv-row-toast--crit">' + toastInner('Health', healthDisks) + '</span>'
                         + '</span>';
            }
        }
        var warnHtml = indHtml;

        var html = '<div class="dv-section" data-section="' + escapeHtml(secId) + '">';
        html += '<div class="dv-section-hd">';
        html += '<span class="dv-section-lbl">' + label + '</span>';
        html += warnHtml;

        if (secId === 'pools' && enableSpinButton) {
            html += '<span class="dv-section-actions">';
            html +=   '<button type="button" class="dv-bulk-spin" data-dv-bulk="up" '
                  +     'aria-label="Spin up all pool disks">'
                  +     BOLT_SVG + '<span class="dv-bulk-arrow">' + ARROW_UP + '</span>'
                  +   '</button>';
            html +=   '<button type="button" class="dv-bulk-spin" data-dv-bulk="down" '
                  +     'aria-label="Spin down all pool disks">'
                  +     BOLT_SVG + '<span class="dv-bulk-arrow">' + ARROW_DOWN + '</span>'
                  +   '</button>';

            html +=   '<div class="dv-row-toast" data-dv-bulk-toast role="tooltip" aria-hidden="true"></div>';
            html += '</span>';
        }

        html += '</div>';
        html += '<div class="dv-rows">';
        for (var i = 0; i < rows.length; i++) {
            var isMember = hasSummary && !rows[i].is_summary;
            html += renderRow(rows[i], isMember);
        }
        html += '</div>';
        html += '</div>';
        return html;
    }

    function render(model){
        lastModel = model;

        try {
            window.localStorage.setItem(
                'diskviewer.lastModel.v1',
                JSON.stringify({ savedAt: Date.now(), model: model })
            );
        } catch(e) {}

        var container = $('dv-sections');
        if (!container) return;

        var countEl = $('dv-device-count');
        if (countEl) countEl.textContent = model.total_devices;
        var missingEl = $('dv-badge-missing');
        if (missingEl) {
            var dvMissing = model.missing_devices || 0;
            if (dvMissing > 0) {
                missingEl.textContent = '- ' + dvMissing + ' Not installed';
                missingEl.style.display = '';
            } else {
                missingEl.textContent = '';
                missingEl.style.display = 'none';
            }
        }

        var html = '';
        if ((model.sections || []).length > 0) {
            var colSev = computeColumnSeverities(model);
            html += renderColumnHeaders(colSev);
            for (var i = 0; i < model.sections.length; i++) {
                html += renderSection(model.sections[i]);
            }
        }

        if (html === '') {
            html = '<div class="dv-empty-state"><span>No disks to display</span></div>';
        }
        container.innerHTML = html;

        container.classList.toggle('dv-pool-highlight', poolHighlightUsed);

        var hasPower = false;
        if (showPower) {
            var secs = model.sections || [];
            for (var si = 0; si < secs.length && !hasPower; si++) {
                var tl = secs[si].tiles || [];
                for (var ti = 0; ti < tl.length; ti++) {
                    if ((+tl[ti].power || 0) > 0) { hasPower = true; break; }
                }
            }
        }
        container.classList.toggle('dv-show-power', hasPower);

        container.classList.remove('dv-font-small', 'dv-font-default', 'dv-font-large');
        container.classList.add('dv-font-' + fontSize);

        expandRows = clampToExtraRows(expandRows);

        applyContainerHeight();
        updateDragHandleVisibility();
    }

    function applyContainerHeight(){
        var container = $('dv-sections');
        if (!container) return;

        var defaultVisibleRows = computeBaselineRowCount(defaultExpandLevel);

        var totalVisible = defaultVisibleRows + expandRows;
        if (totalVisible < 1) totalVisible = 1;

        var px;
        if (dragState && dragState.rowOffsets) {
            var off = dragState.rowOffsets;
            var n = off.length;
            if (n > 0) {
                var idx = Math.min(totalVisible, n) - 1;
                if (idx < 0) idx = 0;
                px = off[idx][0] + off[idx][1];
            } else {
                px = totalVisible * 26 + 28;
            }
            container.style.setProperty('max-height', px + 'px', 'important');
            return;
        }

        var rows = container.querySelectorAll('.dv-row');
        if (rows.length > 0) {

            var idx2 = Math.min(totalVisible, rows.length) - 1;
            if (idx2 < 0) idx2 = 0;
            var row = rows[idx2];

            var top = 0;
            var node = row;
            while (node && node !== container) {
                top += node.offsetTop;
                node = node.offsetParent;
            }
            px = top + row.offsetHeight;
        } else {

            var rowH = 26;
            px = totalVisible * rowH + 28;
        }

        container.style.setProperty('max-height', px + 'px', 'important');
    }

    function updateDragHandleVisibility(){
        var handle = $('dv-drag-handle');
        if (!handle) return;
        handle.style.display = '';
    }

    function tileRowHeight(){
        var container = document.querySelector('.dv-rows');
        if (!container) return 28;
        var row = container.querySelector('.dv-row');
        if (!row) return 28;
        var rect = row.getBoundingClientRect();
        return Math.max(20, Math.round(rect.height));
    }

    function clampToExtraRows(n){

        var container = $('dv-sections');
        if (!container) return n;
        var rows = container.querySelectorAll('.dv-row');
        if (rows.length === 0) return n;
        var defaultVisibleRows = computeBaselineRowCount(defaultExpandLevel);
        // clamp the drag amount to what the section actually has, both directions
        var maxExtra = Math.max(0, rows.length - defaultVisibleRows);
        var minExtra = -(Math.max(0, defaultVisibleRows - 1));
        return Math.max(minExtra, Math.min(maxExtra, n));
    }

    function fetchState(done){
        var x = new XMLHttpRequest();
        x.open('GET', API_URL + '?action=state&t=' + Date.now());
        x.timeout = 8000;
        x.onload = function(){
            if (x.status === 200) {
                try {
                    var model = JSON.parse(x.responseText);
                    render(model);
                } catch(e){}
            }
            if (typeof done === 'function') done();
        };
        x.onerror = x.ontimeout = function(){
            if (typeof done === 'function') done();
        };
        x.send();
    }

    // speed column polls on its own faster cadence so throughput stays live between full refreshes
    function fetchSpeeds(){
        var x = new XMLHttpRequest();
        x.open('GET', API_URL + '?action=speeds&t=' + Date.now());
        x.timeout = 5000;
        x.onload = function(){
            if (x.status !== 200) return;
            try {
                var arr = JSON.parse(x.responseText);
                if (Array.isArray(arr)) updateSpeeds(arr);
            } catch(e){}
        };
        x.send();
    }

    function updateSpeeds(arr){
        if (!arr || !arr.length) return;

        var byName = {};
        for (var i = 0; i < arr.length; i++) {
            var d = arr[i];
            if (!d || !d.name) continue;
            byName[d.name] = d;
        }

        var rows = document.querySelectorAll('.dv-row');
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            if (row.getAttribute('data-is-summary') === '1') continue;

            if (row.getAttribute('data-dv-missing') === '1') continue;
            var name = row.getAttribute('data-name') || '';
            var d = byName[name];
            if (!d) continue;
            var cell = row.querySelector('.dv-col-speed-wrap');
            if (!cell) continue;
            updateSpeedCell(cell, {
                spun:     d.spun,
                speed:    d.speed_bps,
                speedDir: d.speed_dir
            });
        }

        var sections = document.querySelectorAll('.dv-section');
        for (var si = 0; si < sections.length; si++) {
            var sec = sections[si];
            var summaryRow = sec.querySelector('.dv-row[data-is-summary="1"]');
            if (!summaryRow) continue;
            var summaryCell = summaryRow.querySelector('.dv-col-speed-wrap');
            if (!summaryCell) continue;
            var memberRows = sec.querySelectorAll('.dv-row:not([data-is-summary="1"])');
            var sumR = 0, sumW = 0;
            var anyMemberSpun = false;
            for (var mi = 0; mi < memberRows.length; mi++) {
                var memberName = memberRows[mi].getAttribute('data-name') || '';
                var md = byName[memberName];
                if (!md) continue;
                var bps = +md.speed_bps || 0;
                var mdir = md.speed_dir || '';
                if (mdir === 'r') sumR += bps;
                else if (mdir === 'w') sumW += bps;
                if (md.spun) anyMemberSpun = true;
            }
            var totalSum = sumR + sumW;
            updateSpeedCell(summaryCell, {
                spun: anyMemberSpun,
                speed: totalSum,
                speedDir: totalSum > 0 ? (sumR >= sumW ? 'r' : 'w') : ''
            });
        }

        if (lastModel && Array.isArray(lastModel.sections)) {
            for (var s = 0; s < lastModel.sections.length; s++) {
                var sec = lastModel.sections[s];
                if (!sec || !Array.isArray(sec.tiles)) continue;
                for (var k = 0; k < sec.tiles.length; k++) {
                    var row2 = sec.tiles[k];
                    if (!row2 || !row2.name) continue;
                    var d2 = byName[row2.name];
                    if (!d2) continue;
                    row2.speed_bps = d2.speed_bps;
                    row2.speed_dir = d2.speed_dir;
                    row2.errors    = d2.errors;
                    row2.spun      = d2.spun;
                }
            }
        }
    }

    function startPolling(){
        stopPolling();
        if (!refreshEnabled) return;
        pollTimer  = setInterval(fetchState,  refreshInterval);
        speedTimer = setInterval(fetchSpeeds, SPEED_REFRESH_INTERVAL);
    }

    function stopPolling(){
        if (pollTimer)  { clearInterval(pollTimer);  pollTimer  = null; }
        if (speedTimer) { clearInterval(speedTimer); speedTimer = null; }
    }

    function getCsrfToken(){
        return cfg.csrfToken || window.csrf_token || '';
    }

    function onHandleDown(ev){
        var handle = $('dv-drag-handle');
        if (!handle) return;
        if (ev.button !== undefined && ev.button !== 0) return;
        ev.preventDefault();
        var rowH = tileRowHeight();

        var rowOffsets = [];
        var container  = $('dv-sections');
        if (container) {
            var rows = container.querySelectorAll('.dv-row');
            for (var i = 0; i < rows.length; i++) {
                var n = rows[i], top = 0;
                while (n && n !== container) {
                    top += n.offsetTop;
                    n = n.offsetParent;
                }
                rowOffsets.push([top, rows[i].offsetHeight]);
            }
        }
        dragState = {
            startY: ev.clientY,
            startRows: expandRows,
            rowH: rowH,
            moved: false,
            rowOffsets: rowOffsets,
        };
        handle.classList.add('dv-drag-handle--active');
        document.addEventListener('mousemove', onHandleMove);
        document.addEventListener('mouseup', onHandleUp);
    }

    function onHandleMove(ev){
        if (!dragState) return;

        dragState.lastY = ev.clientY;
        if (dragState.rafPending) return;
        dragState.rafPending = true;
        requestAnimationFrame(function(){
            dragState.rafPending = false;
            if (!dragState) return;
            var dy = dragState.lastY - dragState.startY;
            dragState.moved = true;
            var steps = Math.round(dy / dragState.rowH) * dragStepRows;

            var target = dragState.startRows + steps;
            target = clampToExtraRows(target);
            if (target !== expandRows) {
                expandRows = target;
                applyContainerHeight();
            }
        });
    }

    function onHandleUp(){
        var handle = $('dv-drag-handle');
        if (handle) handle.classList.remove('dv-drag-handle--active');
        document.removeEventListener('mousemove', onHandleMove);
        document.removeEventListener('mouseup', onHandleUp);
        saveExpand(expandRows);
        dragState = null;
    }

    function onHandleKey(ev){
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowRight') {
            ev.preventDefault();
            expandRows = clampToExtraRows(expandRows + dragStepRows);
            applyContainerHeight();
            saveExpand(expandRows);
        } else if (ev.key === 'ArrowUp' || ev.key === 'ArrowLeft') {
            ev.preventDefault();
            expandRows = clampToExtraRows(Math.max(0, expandRows - dragStepRows));
            applyContainerHeight();
            saveExpand(expandRows);
        }
    }

    function wireSpinButtons(){
        var container = $('dv-sections');
        if (!container) return;

        container.addEventListener('mouseover', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bolt');
            if (!btn) return;
            var row = btn.closest('.dv-row');
            if (!row) return;
            var dir  = btn.getAttribute('data-dv-spin');
            var name = btn.getAttribute('data-dv-name') || '';
            var toast = row.querySelector('.dv-row-toast');
            if (!toast) return;
            if (dir === 'down') {
                toast.className = 'dv-row-toast dv-row-toast--crit dv-row-toast--show';
                toast.innerHTML = '\u26A0 Spin down ' + escapeHtml(name.toUpperCase()) + ' now';
            } else {
                toast.className = 'dv-row-toast dv-row-toast--ok dv-row-toast--show';
                toast.innerHTML = 'Spin up ' + escapeHtml(name.toUpperCase());
            }
        });
        container.addEventListener('mouseout', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bolt');
            if (!btn) return;
            var row = btn.closest('.dv-row');
            if (!row) return;
            var toast = row.querySelector('.dv-row-toast');
            if (toast) toast.className = 'dv-row-toast';
        });

        container.addEventListener('click', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bolt');
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            var dir  = btn.getAttribute('data-dv-spin');
            var name = btn.getAttribute('data-dv-name') || '';
            if (!name || !dir) return;
            var row = btn.closest('.dv-row');
            var toast = row ? row.querySelector('.dv-row-toast') : null;
            btn.disabled = true;
            btn.classList.add('dv-bolt--busy');
            var body = 'name=' + encodeURIComponent(name) +
                       '&direction=' + encodeURIComponent(dir) +
                       '&csrf_token=' + encodeURIComponent(getCsrfToken());
            var x = new XMLHttpRequest();
            x.open('POST', API_URL + '?action=spin');
            x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
            x.timeout = 10000;
            x.onload = function(){
                var ok = false, err = '';
                try {
                    var r = JSON.parse(x.responseText);
                    ok  = !!r.ok;
                    err = r.error || '';
                } catch(e) { err = 'invalid response'; }
                if (!ok && toast) {
                    toast.className = 'dv-row-toast dv-row-toast--crit dv-row-toast--show';
                    toast.innerHTML = '\u26A0 ' + escapeHtml('Spin failed: ' + (err || 'unknown'));
                    setTimeout(function(){ toast.className = 'dv-row-toast'; }, 3000);
                }
                setTimeout(function(){
                    btn.classList.remove('dv-bolt--busy');
                    btn.disabled = false;
                    fetchState();
                }, 1200);
            };
            x.onerror = x.ontimeout = function(){
                if (toast) {
                    toast.className = 'dv-row-toast dv-row-toast--crit dv-row-toast--show';
                    toast.innerHTML = '\u26A0 Spin request failed';
                    setTimeout(function(){ toast.className = 'dv-row-toast'; }, 3000);
                }
                btn.classList.remove('dv-bolt--busy');
                btn.disabled = false;
            };
            x.send(body);
        });
    }

    function wireBulkSpin(){
        var container = $('dv-sections');
        if (!container) return;

        container.addEventListener('mouseover', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bulk-spin');
            if (!btn) return;
            var actions = btn.closest('.dv-section-actions');
            if (!actions) return;
            var toast = actions.querySelector('[data-dv-bulk-toast]');
            if (!toast) return;
            var dir = btn.getAttribute('data-dv-bulk');
            if (dir === 'down') {
                toast.className = 'dv-row-toast dv-row-toast--crit dv-row-toast--show';
                toast.innerHTML = '\u26A0 Spin down all pool disks';
            } else {
                toast.className = 'dv-row-toast dv-row-toast--ok dv-row-toast--show';
                toast.innerHTML = 'Spin up all pool disks';
            }
        });
        container.addEventListener('mouseout', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bulk-spin');
            if (!btn) return;
            var actions = btn.closest('.dv-section-actions');
            if (!actions) return;
            var toast = actions.querySelector('[data-dv-bulk-toast]');
            if (toast) toast.className = 'dv-row-toast';
        });

        container.addEventListener('click', function(ev){
            var btn = ev.target.closest && ev.target.closest('button.dv-bulk-spin');
            if (!btn) return;
            ev.preventDefault();
            ev.stopPropagation();
            var dir = btn.getAttribute('data-dv-bulk');
            if (dir !== 'up' && dir !== 'down') return;
            if (!lastModel || !lastModel.sections) return;

            var pools = null;
            for (var i = 0; i < lastModel.sections.length; i++) {
                if (lastModel.sections[i].id === 'pools') { pools = lastModel.sections[i]; break; }
            }
            if (!pools || !pools.tiles || !pools.tiles.length) return;

            var targets = [];
            for (var j = 0; j < pools.tiles.length; j++) {
                var t = pools.tiles[j];
                if (t.is_summary) continue;
                if (t.spin_disabled) continue;
                if (dir === 'up'   && t.spun)  continue;
                if (dir === 'down' && !t.spun) continue;
                if (t.name) targets.push(t.name);
            }

            var allBulk = container.querySelectorAll('button.dv-bulk-spin');
            for (var k = 0; k < allBulk.length; k++) {
                allBulk[k].disabled = true;
                allBulk[k].classList.add('dv-bolt--busy');
            }

            if (targets.length === 0) {

                setTimeout(function(){
                    for (var m = 0; m < allBulk.length; m++) {
                        allBulk[m].disabled = false;
                        allBulk[m].classList.remove('dv-bolt--busy');
                    }
                    fetchState();
                }, 400);
                return;
            }

            var throttleMs = (dir === 'up') ? 350 : 200;

            var idx = 0;
            function next(){
                if (idx >= targets.length) {
                    setTimeout(function(){
                        for (var m = 0; m < allBulk.length; m++) {
                            allBulk[m].disabled = false;
                            allBulk[m].classList.remove('dv-bolt--busy');
                        }
                        fetchState();
                    }, 1000);
                    return;
                }
                var name = targets[idx++];
                var body = 'name=' + encodeURIComponent(name) +
                           '&direction=' + encodeURIComponent(dir) +
                           '&csrf_token=' + encodeURIComponent(getCsrfToken());
                var x = new XMLHttpRequest();
                x.open('POST', API_URL + '?action=spin');
                x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                x.timeout = 10000;

                x.onload  = function(){ setTimeout(next, throttleMs); };  // bulk spin runs one disk at a time, throttled, so emcmd isn't hammered
                x.onerror = x.ontimeout = function(){ setTimeout(next, throttleMs); };
                x.send(body);
            }
            next();
        });
    }

    function wireRefresh(){
        var btn = $('dv-manual-refresh');
        if (!btn) return;
        btn.addEventListener('click', function(ev){
            ev.preventDefault();
            var icon = btn.querySelector('i');
            if (btn.classList.contains('dv-busy')) return;
            btn.classList.add('dv-busy');
            if (icon) {
                icon.classList.remove('fa-refresh');
                icon.classList.add('fa-hourglass-half');
            }

            var t0 = Date.now();
            fetchState(function(){
                var elapsed = Date.now() - t0;
                var hold = Math.max(0, 450 - elapsed);
                setTimeout(function(){
                    if (icon) {
                        icon.classList.remove('fa-hourglass-half');
                        icon.classList.add('fa-refresh');
                    }
                    btn.classList.remove('dv-busy');
                }, hold);
            });
        });
    }

    function wireNameTips(){
        var container = $('dv-sections');
        if (!container || container._dvNameTipWired) return;
        container._dvNameTipWired = true;

        var tip = null;
        function ensureTip(){
            if (tip) return tip;
            tip = document.createElement('div');
            tip.className = 'dv-tip dv-tip--info';
            tip.style.display = 'none';
            document.body.appendChild(tip);
            return tip;
        }
        function showTip(el, text){
            var t = ensureTip();
            t.textContent = text;
            t.style.display = 'block';
            var r  = el.getBoundingClientRect();
            var tr = t.getBoundingClientRect();

            var left = r.left - 9;
            left = Math.max(6, Math.min(left, window.innerWidth - tr.width - 6));
            var top = r.top - tr.height - 6;
            if (top < 4) top = r.bottom + 6;
            t.style.left = Math.round(left) + 'px';
            t.style.top  = Math.round(top) + 'px';
        }
        function hideTip(){ if (tip) tip.style.display = 'none'; }

        container.addEventListener('mouseover', function(ev){
            var el = ev.target.closest && ev.target.closest('.dv-name[data-dv-ident]');
            if (!el) return;
            var text = el.getAttribute('data-dv-ident') || '';
            if (text) showTip(el, text);
        });
        container.addEventListener('mouseout', function(ev){
            if (ev.target.closest && ev.target.closest('.dv-name[data-dv-ident]')) hideTip();
        });
        container.addEventListener('scroll', hideTip, { passive: true });
        container.addEventListener('wheel',  hideTip, { passive: true });
        window.addEventListener('scroll', hideTip, { passive: true });
    }

    function wireScrollHint(){
        var sections = $('dv-sections');
        if (!sections) return;

        var HINT_SVG =
            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                '<path d="M7 14l5-5 5 5z"/>' +
            '</svg>' +
            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                '<path d="M7 10l5 5 5-5z"/>' +
            '</svg>';

        var hint = null;
        var hideTimer = null;
        var hidden = true;

        function ensureHint(){
            if (hint) return hint;
            hint = document.createElement('div');
            hint.className = 'dv-scroll-hint';
            hint.setAttribute('aria-hidden', 'true');
            hint.innerHTML = HINT_SVG;
            document.body.appendChild(hint);
            return hint;
        }

        function show(x, y){
            ensureHint();

            hint.style.left = (x + 14) + 'px';
            hint.style.top  = (y + 4)  + 'px';
            if (hidden) {
                hint.classList.add('dv-scroll-hint--show');
                hidden = false;
            }
        }

        function hide(){
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
            if (hint && !hidden) {
                hint.classList.remove('dv-scroll-hint--show');
                hidden = true;
            }
        }

        function hasOverflow(){
            return sections.scrollHeight > sections.clientHeight + 1;
        }

        sections.addEventListener('mouseenter', function(ev){
            if (!hasOverflow()) return;
            show(ev.clientX, ev.clientY);

            if (hideTimer) clearTimeout(hideTimer);
            hideTimer = setTimeout(hide, 2000);
        });

        sections.addEventListener('mousemove', function(ev){

            if (!hidden) {
                hint.style.left = (ev.clientX + 14) + 'px';
                hint.style.top  = (ev.clientY + 4)  + 'px';
            }
        });

        sections.addEventListener('mouseleave', hide);

        sections.addEventListener('scroll', hide, { passive: true });
        sections.addEventListener('wheel',  hide, { passive: true });
    }

    function paintFromCache(){

        try {
            if (cfg && cfg.initialModel && cfg.initialModel.sections) {
                render(cfg.initialModel);
                return true;
            }
        } catch(e) {}

        try {
            var raw = window.localStorage.getItem('diskviewer.lastModel.v1');
            if (!raw) return false;
            var wrapped = JSON.parse(raw);
            if (!wrapped || !wrapped.model) return false;

            if (typeof wrapped.savedAt === 'number'
                && (Date.now() - wrapped.savedAt) > 3600000) {
                return false;
            }
            render(wrapped.model);
            return true;
        } catch(e) {
            return false;
        }
    }

    function init(){
        expandRows = loadExpand();

        var handle = $('dv-drag-handle');
        if (handle) {
            handle.addEventListener('mousedown', onHandleDown);
            handle.addEventListener('keydown', onHandleKey);
            handle.addEventListener('touchstart', function(ev){
                var t = ev.touches[0];
                if (!t) return;
                onHandleDown({ clientY: t.clientY, button: 0, preventDefault: function(){ ev.preventDefault(); } });
            }, { passive: false });
            handle.addEventListener('touchmove', function(ev){
                var t = ev.touches[0];
                if (!t || !dragState) return;
                onHandleMove({ clientY: t.clientY });
                ev.preventDefault();
            }, { passive: false });
            handle.addEventListener('touchend', function(){ onHandleUp(); });
        }
        wireRefresh();
        wireSpinButtons();
        wireBulkSpin();
        wireScrollHint();
        wireNameTips();

        paintFromCache();
        fetchState();
        startPolling();

        if (!document._dvVisListener) {
            document._dvVisListener = true;
            document.addEventListener('visibilitychange', function(){
                if (document.visibilityState === 'visible') {
                    fetchState();
                }
            });
            window.addEventListener('pageshow', function(){
                fetchState();
            });
        }
    }

    window.diskviewerRepaint = function(){
        try {

            cfg = window.diskviewerConfig || {};
            dragStepRows    = +cfg.dragStepRows || 1;
            refreshEnabled  = cfg.refreshEnabled !== false;
            refreshInterval = +cfg.refreshInterval || 20000;
            warningPct      = +cfg.warningPct  || 95;
            criticalPct     = +cfg.criticalPct || 98;
            tempWarning     = +cfg.tempWarning  || 45;
            tempCritical    = +cfg.tempCritical || 55;
            tempUnit        = (cfg.tempUnit === 'F') ? 'F' : 'C';
            spaceSeverityEnabled = (cfg.spaceSeverityEnabled !== false);
            defaultExpandLevel = +cfg.defaultExpandRows || 0;
            if (defaultExpandLevel < 0) defaultExpandLevel = 0;
            if (defaultExpandLevel > 3) defaultExpandLevel = 3;
            enableSpinButton  = !!cfg.enableSpinButton;
            poolHighlightUsed = !!cfg.poolHighlightUsed;
            showFsBadge       = cfg.showFsBadge !== false;
            showDiskErrors    = cfg.showDiskErrors !== false;
            showPower         = !!cfg.showPower;
            showDecimalPct    = !!cfg.showDecimalPct;
            showUsedColumn    = !!cfg.showUsedColumn;
            showIdTooltip     = cfg.showIdTooltip !== false;
            showSectionIndicators = cfg.showSectionIndicators !== false;
            fontSize          = (cfg.fontSize === 'small' || cfg.fontSize === 'large') ? cfg.fontSize : 'default';

            var handle = $('dv-drag-handle');
            if (handle && !handle._dvBound) {
                handle._dvBound = true;
                handle.addEventListener('mousedown', onHandleDown);
                handle.addEventListener('keydown', onHandleKey);
                handle.addEventListener('touchstart', function(ev){
                    var t = ev.touches[0];
                    if (!t) return;
                    onHandleDown({ clientY: t.clientY, button: 0, preventDefault: function(){ ev.preventDefault(); } });
                }, { passive: false });
                handle.addEventListener('touchmove', function(ev){
                    var t = ev.touches[0];
                    if (!t || !dragState) return;
                    onHandleMove({ clientY: t.clientY });
                    ev.preventDefault();
                }, { passive: false });
                handle.addEventListener('touchend', function(){ onHandleUp(); });
            }
            wireRefresh();
            wireSpinButtons();
            wireBulkSpin();
            wireScrollHint();
            wireNameTips();

            paintFromCache();

            fetchState();

            startPolling();
        } catch(e) {

        }
    };

    'use strict';

    if (defaultExpandLevel < 0) defaultExpandLevel = 0;
    if (defaultExpandLevel > 3) defaultExpandLevel = 3;

    function $(id){ return document.getElementById(id); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
