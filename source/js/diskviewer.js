/* ============================================================================
   DISK VIEWER  -  Widget Frontend Script
   /plugins/diskviewer/js/diskviewer.js

   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3

   Section ordering follows the data flow: config and helpers first, then
   the render pipeline, then layout/visibility, then polling, then user
   interaction wiring (drag, bolt, bulk spin, refresh, scroll hint),
   finally the cache painter and init at the end so init() can call
   anything declared above it.
   ========================================================================= */

// DiskViewer widget
// Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
(function(){

    // ============================================================================
    // 1. Config & State (constants, cfg-derived flags, mutable state holders)
    // ============================================================================

    // ── Config and state ───────────────────────────────────────────────
    var cfg = window.diskviewerConfig || {};
    var dragStepRows    = +cfg.dragStepRows || 1;
    var refreshEnabled  = cfg.refreshEnabled !== false;
    var refreshInterval = +cfg.refreshInterval || 20000;
    var warningPct      = +cfg.warningPct  || 95;
    var criticalPct     = +cfg.criticalPct || 98;
    var tempWarning     = +cfg.tempWarning  || 45;
    var tempCritical    = +cfg.tempCritical || 55;
    // Temperature unit follows Unraid's global Display Settings (C or F).
    // Stored as a single letter; the raw temp value on each tile stays in
    // Celsius (Unraid storage convention) and we convert at render time
    // via fmtTemp(). Thresholds (tempWarning / tempCritical) are likewise
    // in Celsius, so comparisons stay correct in both units without needing
    // to convert the thresholds themselves.
    var tempUnit        = (cfg.tempUnit === 'F') ? 'F' : 'C';
    // Space severity highlighting toggle. When false, the used % column
    // and fill bar stay neutral grey regardless of the threshold values.
    // Default true preserves the original behaviour for users on older
    // settings files who don't have the dropdown set yet.
    var spaceSeverityEnabled = (cfg.spaceSeverityEnabled !== false);

    // Helper: render a Celsius temperature reading in the user's chosen
    // unit. Input is the raw integer from disks.ini; output is a display
    // string like "33°C" or "91°F". Conversion uses the same formula as
    // Limetech's monitor script (round(9/5*C + 32) for Fahrenheit) so
    // values match what the rest of the Unraid WebGUI shows.
    function fmtTemp(celsius){
        if (tempUnit === 'F') {
            return Math.round(9/5 * celsius + 32) + '°F';
        }
        return celsius + '°C';
    }

    // defaultExpandRows is a section-visibility level (kept under the legacy
    // variable name for storage continuity), not a row count:
    //   0 = ARRAY only
    //   1 = ARRAY + cache (multi-disk pools)
    //   2 = ARRAY + cache + pools (single-disk pools)
    //   3 = all (incl. UNASSIGNED)
    // The drag handle reveals additional rows on top of this baseline.
    var defaultExpandLevel = +cfg.defaultExpandRows || 0;
    var enableSpinButton  = !!cfg.enableSpinButton;
    var poolHighlightUsed = !!cfg.poolHighlightUsed;
    var showFsBadge       = cfg.showFsBadge !== false;
    var showDiskErrors    = cfg.showDiskErrors !== false;
    var showDecimalPct    = !!cfg.showDecimalPct;
    var showUsedColumn    = !!cfg.showUsedColumn;
    var showIdTooltip     = cfg.showIdTooltip !== false;  // disk-name identification tooltip
    var showSectionIndicators = cfg.showSectionIndicators !== false;
    var fontSize          = (cfg.fontSize === 'small' || cfg.fontSize === 'large') ? cfg.fontSize : 'default';
    var STORAGE_KEY  = 'dv_expand_v3';   // v1: row counts under different semantics
                                          // v2: extras above a fixed 8-row baseline
                                          // v3: extras above a section-level baseline (DEFAULT_EXPAND_ROWS as 0..3)
    var API_URL      = '/plugins/diskviewer/include/diskviewer_api.php';

    // Speed column polls on its own faster cadence so the user sees live
    // throughput regardless of how rare the global refresh is. 2 seconds
    // gives smooth-but-responsive readings (the backend reports the average
    // bytes/sec over the delta interval, so longer intervals smear spikes).
    var SPEED_REFRESH_INTERVAL = 2000;
    var pollTimer    = null;
    var speedTimer   = null;
    var lastModel    = null;
    var expandRows   = null;
    var dragState    = null;


    // ============================================================================
    // 2. Persistent state (sessionStorage for drag handle position)
    // ============================================================================

    // ── Persistent state for the drag handle ───────────────────────────
    // `expandRows` is the user's drag-revealed extras ON TOP of the baseline
    // sections selected by defaultExpandLevel. A fresh session starts at 0
    // (no extras), so the visible disks come purely from the configured
    // baseline level until the user drags the footer handle.
    function loadExpand(){
        try {
            var v = sessionStorage.getItem(STORAGE_KEY);
            if (v === null || v === '') return 0;
            var n = parseInt(v, 10);
            if (isNaN(n)) return 0;
            // Allow negative (user dragged below baseline) AND positive
            // (extras above baseline). Only clamp the absolute magnitude
            // to a sane band so an absurd stored value doesn't break
            // layout. Previously this clamped to >= 0 which silently
            // discarded the user's collapsed state on every page reload,
            // making the widget snap back open to the full level baseline
            // on each refresh.
            return Math.max(-100, Math.min(100, n));
        } catch(e){ return 0; }
    }

    function saveExpand(n){
        try { sessionStorage.setItem(STORAGE_KEY, String(n)); } catch(e){}
    }


    // ============================================================================
    // 3. Utility Helpers (formatBytes, escapeHtml, $)
    // ============================================================================

    // ── Helpers ────────────────────────────────────────────────────────
    // formatBytes uses decimal (1000-based) units, not binary (1024-based).
    // This matches the convention Unraid's Main page uses and the marketing
    // size printed on the drive itself: a 2TB drive reads as 2 TB, a 16TB
    // drive reads as 16 TB - not 1.8 TB / 14.6 TB which is the same physical
    // capacity expressed in binary GiB/TiB. Keeping the GB/TB labels (rather
    // than the technically-correct GiB/TiB) since that's the convention
    // every consumer-facing Unraid surface uses too.
    function formatBytes(bytes, precision, alwaysTwo){
        if (!bytes || bytes <= 0) return '0 B';
        if (precision === undefined) precision = 1;
        var units = ['B','KB','MB','GB','TB','PB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1000));
        i = Math.min(i, units.length - 1);
        var val = bytes / Math.pow(1000, i);
        var str;
        if (alwaysTwo && i >= 3) {
            // USED / FREE columns: always two decimals from GB up, with no
            // trailing-zero trim, so close values stay distinct and the
            // decimal count is consistent (e.g. 1.49 TB, 8.20 TB, 868.60 GB).
            str = val.toFixed(2);
        } else if (i >= 4) {
            // SIZE column, TB and above: a second decimal so close sizes stay
            // distinct (1.49 vs 1.53 TB). Trailing zeros are trimmed, so a
            // clean 2 TB still reads as 2 TB.
            var dp = Math.max(precision, 2);
            str = val.toFixed(dp).replace(/\.?0+$/, '');
        } else {
            // SIZE column, GB and below: whole numbers, matching what Unraid's
            // Main page shows (a 500 GB drive reads 500 GB, not 500.1 GB).
            str = String(Math.round(val));
        }
        return str + ' ' + units[i];
    }

    // Build the identification string for the hover tooltip on the disk name:
    // "MODEL_SERIAL (sdX)" - the drive model+serial and its kernel device node.
    // Each piece is omitted gracefully when missing, and summary / aggregate
    // rows return an empty string so they get no tooltip.
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


    // ============================================================================
    // 4. SVG Icon Constants
    // ============================================================================

    // ── SVG icons ───────────────────────────────────────────────────────
    var GEAR_SVG   = '<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 01-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 01.872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 012.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 012.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 01.872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 01-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 01-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 110-5.858 2.929 2.929 0 010 5.858z"/></svg>';
    var BOLT_SVG   = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
    // Stack icon for aggregate (summary) rows - two-layer stack glyph.
    // Stroke-based so it visually differs from the filled bolt icon and
    // reads as "synthesis of multiple things" at a glance. Sized to match
    // the bolt so the column alignment stays identical.
    var STACK_SVG  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l-9 4.5l9 4.5l9 -4.5l-9 -4.5"/><path d="M3 13.5l9 4.5l9 -4.5"/></svg>';
    var THUMB_SVG  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73V10z"/></svg>';
    // Speed direction arrow glyphs. Sized at 12px - was 8px originally,
    // bumped to 10px in 2026.05.05t, now to 12px per user feedback that
    // even the 10px size still read as tiny next to the bytes/sec
    // figure. 12px sits visually closer to the column's 13px text size
    // so the arrow and the number look like equals rather than the
    // arrow looking like a footnote. Whole-pixel boundary keeps the
    // SVG sharp without anti-aliasing softness.
    var ARROW_UP   = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 14l5-5 5 5z"/></svg>';
    var ARROW_DOWN = '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>';

    // Warning triangle with centred exclamation mark - shown next to a
    // section header when one or more disks in that section have a
    // non-zero error count (ZFS/BTRFS reported errors via numErrors).
    // The same colour token (--dv-warn) is reused so the indicator
    // tracks the rest of the warning palette across themes.
    var WARN_TRIANGLE_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2L1 22h22L12 2zm0 4.45L19.95 20H4.05L12 6.45zM11 10v5h2v-5h-2zm0 6v2h2v-2h-2z"/></svg>';

    // Thermometer glyph for the section-header high-temperature indicator.
    var THERMO_SVG = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 13V5a3 3 0 0 0-6 0v8a5 5 0 1 0 6 0zm-3-9a1 1 0 0 1 1 1v3h-2V5a1 1 0 0 1 1-1z"/></svg>';


    // ============================================================================
    // 5. Severity & Section-level Computation
    // ============================================================================

    // ── Compute the worst-severity for the three "stateful" columns ───
    // The column headers %, TEMP, and H reflect the worst observed
    // severity of any non-summary tile under each column. This gives the
    // user a one-glance "is anything wrong in this column" cue without
    // forcing them to scan every row. We compute this once per render
    // pass over the whole model and pass the result to renderColumnHeaders.
    //
    // Severity ranking (worst wins): critical > warning > ok.
    // Temperature is computed client-side using tempWarning/tempCritical
    // because the backend does not pre-classify it (it's just a number).
    // SMART (health) and utilization (%) are pre-classified by the backend
    // so we read those fields directly.
    function computeColumnSeverities(model){
        var worstPct = 'ok', worstTemp = 'ok', worstHealth = 'ok';
        var rank = { ok: 0, warning: 1, critical: 2 };
        var sections = model && model.sections || [];

        for (var i = 0; i < sections.length; i++) {
            var tiles = sections[i].tiles || [];
            for (var j = 0; j < tiles.length; j++) {
                var t = tiles[j];
                if (t.is_summary) continue;          // aggregates, not real disks
                if (t.is_parity)  continue;          // parity has no % column

                // Utilization %
                var sev = t.severity || 'ok';
                if ((rank[sev] || 0) > (rank[worstPct] || 0)) worstPct = sev;

                // Temperature - classify from raw number using per-disk
                // thresholds when the server emitted them, fall back to
                // global. Per-disk values come from disks.ini hotTemp/
                // maxTemp (set by the user on Main page per disk) or from
                // NVMe-aware defaults the server picks when no override
                // exists; either way the JS just trusts what arrives.
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

                // SMART health
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

    // ── Section-level helpers ────────────────────────────────────────────
    // Each rendered section gets a level matching DEFAULT_EXPAND_ROWS:
    //   array            → 0
    //   pool_<name>      → 1   (multi-disk pools, the "cache" tier)
    //   pools            → 2   (single-disk pools aggregated)
    //   unassigned       → 2   (same baseline as pools so UD shows by default
    //                           at default_expand_rows=2 - matches Unraid
    //                           Main page where UD is always visible)
    // Anything else (defensive default) maps to the highest level so it
    // doesn't sneak into the baseline crop unintentionally.
    function levelOfSectionId(id){
        if (id === 'array') return 0;
        if (id && id.indexOf('pool_') === 0) return 1;
        if (id === 'pools') return 2;
        if (id === 'unassigned') return 2;
        if (id === 'boot') return 2;   // flash drive: visible by default, like UD
        return 3;
    }

    // Count .dv-row elements that belong to sections at level <= the
    // requested baseline level. This is the dynamic equivalent of the old
    // hardcoded `defaultVisibleRows = 8` - it scales with the user's actual
    // section composition (number of array disks, presence/absence of cache,
    // multiple multi-disk pools, etc.) instead of guessing a magic number
    // that happened to fit a typical layout.
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


    // ============================================================================
    // 6. Render Pipeline (column header, row, section, full model)
    // ============================================================================

    // ── Render column header row (shown once at top) ──────────────────────
    // Three of the headers - %, TEMP, H - take a severity-derived class so
    // the header itself glows when any tile beneath it is in warning or
    // critical state. All three are neutral grey on the ok state - showing
    // a "positive affirmation" green for healthy disks turned out to draw
    // the eye away from real warnings, and the user's expectation is that
    // colour means "look here, something is off".
    function renderColumnHeaders(colSev){
        colSev = colSev || { pct: 'ok', temp: 'ok', health: 'ok' };
        var usedCls   = 'dv-colhd-used'   + severityModifier('dv-colhd-used',   colSev.pct);
        var tempCls   = 'dv-colhd-temp'   + severityModifier('dv-colhd-temp',   colSev.temp);
        var healthCls = 'dv-colhd-health' + severityModifier('dv-colhd-health', colSev.health);
        return '<div class="dv-colhd">' +
            '<span></span>' +                                   // bolt
            '<span class="dv-colhd-name">DISK</span>' +
            '<span class="dv-colhd-size">SIZE</span>' +
            '<span class="dv-colhd-free">FREE</span>' +
            '<span class="' + usedCls + '">USED</span>' +
            '<span class="dv-colhd-speed">SPEED R/W</span>' +
            '<span class="' + tempCls + '">TEMP</span>' +
            '<span class="' + healthCls + '">H</span>' +
            '<span class="dv-colhd-settings">S</span>' +        // gear column
        '</div>';
    }

    // Build the inner HTML for the speed column. Centralised so the live
    // speed poller (updateSpeeds) and the full renderer (renderRow) both
    // produce byte-identical markup. Called per data row including
    // parities (they have write activity during array writes) and summary
    // aggregates (they sum all member tile speeds).
    //
    // Note: error counts used to hijack this cell ("1573 err" instead of
    // a speed reading) when a disk had non-zero errors. Removed because
    // the error information lives in the section-header warning triangle
    // tooltip; the speed column should always show speed and never get
    // overwritten by a different metric.
    function buildSpeedHtml(o){
        var spun      = !!o.spun;
        var speed     = +o.speed || 0;
        var speedDir  = String(o.speedDir || '');

        if (spun && speed > 0) {
            var isRead = (speedDir === 'r');
            var arrow  = isRead ? ARROW_DOWN : ARROW_UP;
            // Direction-specific class so the arrow can be coloured: green
            // for reads, red for writes. The number itself stays in the
            // default text colour - only the arrow takes the accent.
            var dirCls = isRead ? 'dv-col-speed--r' : 'dv-col-speed--w';
            return '<span class="dv-col-speed ' + dirCls + '">' + arrow
                 + '<span class="dv-col-speed-num">' + formatBytes(speed) + '/s</span></span>';
        }
        return '<span class="dv-col-speed-na">-</span>';
    }

    // Update a speed cell in place. When the disk keeps reading/writing in the
    // same direction we only rewrite the number text, leaving the arrow element
    // alone, so steady throughput reads as a smooth flow rather than the whole
    // cell flashing on every poll. Start/stop or a direction flip rebuilds.
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

    // ── Render a single row ────────────────────────────────────────────
    function renderRow(row, isMember){
        var rawName  = row.name || '';
        // Cosmetic label (set on multi-disk pool members as "Device 1",
        // "Device 2", ...). Falls back to the real name for tiles that
        // don't override it. The data-name attribute further down still
        // uses rawName so the speed poller, spin handlers, and severity
        // references continue to key off the real device name.
        var displayName = row.display_name || rawName;
        var nameEsc  = escapeHtml(displayName);  // keep original case in DOM text
        var devName  = encodeURIComponent(row.main_dev || rawName);
        var pct      = Math.max(0, Math.min(100, +row.pct || 0));
        var isSummary = !!row.is_summary;
        var isParity  = !!row.is_parity;
        var spinDisabled = !!row.spin_disabled;
        var notInstalled = !!row.not_installed;  // configured disk, no device
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
        // style_as_summary is a cosmetic-only flag on single-disk cache
        // pool tiles. It used to drive the .dv-row--summary class (uniform
        // grey); now it only drives the FS pill in the name cell - the
        // grey background comes from the zebra nth-child CSS for the
        // combined cache/pools sections, so we don't add .dv-row--summary
        // here.
        var stylesAsSummary = !!row.style_as_summary;
        if (isSummary)            cls += ' dv-row--summary';
        if (isMember)             cls += ' dv-row--member';
        // The boot device is the sole item of its section; give its row the
        // summary grey so it reads as distinct from the (transparent) section
        // header instead of blending into it.
        if (row.group === 'boot') cls += ' dv-row--boot';
        if (severity === 'warning')  cls += ' dv-row--warn';
        if (severity === 'critical') cls += ' dv-row--crit';

        // Spin bolt - three forms:
        //
        //   1. Clickable button: only on spin-eligible, non-summary disks
        //      with the spin button setting enabled (DISK1, DISK2, single-disk
        //      pools, unassigned). The user can toggle these.
        //
        //   2. Static visual icon: on every other tile that has a meaningful
        //      "spun" state to communicate. This includes parity disks, members
        //      of multi-disk pools (cache RAID etc.), and the section summary
        //      tiles. The icon shows green when spun up and dim when spun down,
        //      but cannot be clicked because the action is meaningless on those
        //      tiles (parity must stay locked to the array; multi-disk pools
        //      have constant background I/O; summaries are aggregates, not
        //      real devices). The dv-bolt--static CSS class disables cursor
        //      and pointer events so it looks the same but doesn't react.
        //
        //   3. Static (when spin button setting is disabled globally): every
        //      tile gets the static icon, including DISK1, since the user has
        //      asked for a read-only widget.
        //
        // The .dv-col-bolt slot is always present in the grid so column widths
        // line up across rows even when the bolt is static or hidden.
        var boltCls = spun ? 'dv-bolt dv-bolt--on' : 'dv-bolt dv-bolt--off';
        // aria-label only - no `title`, because the colored hover toast is the
        // primary affordance and a native browser tooltip would double up on it.
        var boltLabel = spun ? 'Click to spin down' : 'Click to spin up';
        var boltEl;
        var canBeButton = !isSummary && !isParity && !spinDisabled && enableSpinButton;
        if (isSummary || row.group === 'boot') {
            // Summary tiles (and the boot device) render the aggregate icon
            // (stack-2 style) instead of a bolt. Bolt only makes sense on real
            // spinnable devices; aggregate rows and the boot flash have no spin
            // state. The icon marks the row as a section-level entry, not a disk.
            boltEl = '<span class="dv-bolt dv-bolt--static" aria-hidden="true">' + STACK_SVG + '</span>';
        } else if (canBeButton) {
            boltEl = '<button type="button" class="' + boltCls + '" aria-label="' + boltLabel + '" data-dv-spin="' + (spun ? 'down' : 'up') + '" data-dv-name="' + escapeHtml(row.name || '') + '">' + BOLT_SVG + '</button>';
        } else {
            boltEl = '<span class="' + boltCls + ' dv-bolt--static" aria-hidden="true">' + BOLT_SVG + '</span>';
        }

        // Size / Free / Pct columns. Capacity collapses to dashes on:
        //   - Parity disks: no filesystem, no usable capacity
        //   - Multi-disk pool members: data is replicated/striped across
        //     disks so per-member usage isn't meaningful; the pool summary
        //     row at the top of the section carries the real aggregate.
        // Array data disks (DISK1, DISK2, ...) keep their capacity stats
        // because each one has its own independent filesystem.
        var sizeText, freeText, usedText;
        var isPoolMember = !!row.is_pool_member;
        var collapseCapacity = isParity || isPoolMember || !!row.no_capacity || notInstalled;
        if (collapseCapacity) {
            sizeText = escapeHtml(formatBytes(size));
            freeText = '-';
            usedText = '-';
        } else if (size > 0) {
            sizeText = escapeHtml(formatBytes(size));
            freeText = escapeHtml(formatBytes(free, 1, true));
            usedText = escapeHtml(formatBytes(size - free, 1, true));
        } else {
            sizeText = '-';
            freeText = '-';
            usedText = '-';
        }

        // USED composite cell. Combines the percent (always) and bytes
        // (when the Show used column toggle is on) into a single inline
        // line, with the mini-bar stacked directly below. The bytes value
        // is the primary number (default text colour, monospace 13px);
        // the percent is a smaller subtext on the right of the same line
        // and carries the severity colour so the alert function survives
        // the visual demotion.
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
            // Parity / pool member rows have no meaningful per-disk usage
            // (parity protects, pool members share storage). Render a single
            // dash with the empty bar slot, matching how SIZE/FREE collapse.
            usedCellHtml =
                '<div class="dv-col-used">' +
                    '<div class="dv-col-used-line"><span class="dv-col-used-pct">-</span></div>' +
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

        // Speed / errors column
        var speedHtml = buildSpeedHtml({
            errors: errors, spun: spun, speed: speed, speedDir: speedDir,
            isSummary: isSummary, isParity: isParity
        });

        // Temperature column
        var tempText = '*';
        var tempCls  = 'dv-col-temp dv-temp-na';
        if (temp && temp !== '*' && temp !== '-') {
            var n = parseInt(temp, 10);
            if (!isNaN(n)) {
                // Per-disk warning/critical when emitted by the server
                // (set by user on Main page, or NVMe-aware defaults for
                // drives without an override). Fall back to the global
                // for safety when fields are missing - keeps older
                // cached models from before this fix still rendering.
                var rowWarn = +row.temp_warning  || tempWarning;
                var rowCrit = +row.temp_critical || tempCritical;
                tempText = fmtTemp(n);
                tempCls = 'dv-col-temp ' + (
                    n >= rowCrit ? 'dv-temp-crit' :
                    n >= rowWarn ? 'dv-temp-warn' : 'dv-temp-ok'
                );
            }
        }

        // Thumb (SMART)
        var thumbDir = smart === 'critical' ? 'down' : 'up';
        var thumbCol = smart === 'critical' ? 'dv-thumb--crit' :
                       smart === 'warning'  ? 'dv-thumb--warn' :
                       smart === 'healthy'  ? 'dv-thumb--ok'   : 'dv-thumb--na';
        var smartTitle = smart === 'unknown' ? 'no data' : smart;
        var thumbHtml = '<span class="dv-thumb ' + thumbCol + ' dv-thumb--' + thumbDir + '" title="SMART: ' + escapeHtml(smartTitle) + '">' + THUMB_SVG + '</span>';

        // Configured but missing disk: every metric column is left blank (no
        // "0 B", no dashes) so the row reads as absent and the "NOT INSTALLED or MISSING"
        // label below is free to span the empty space. The bolt is static
        // (spin disabled server side) so it shows no spin tooltip. The wrapping
        // spans/divs stay in place so the grid columns do not shift.
        if (notInstalled) {
            sizeText     = '';
            freeText     = '';
            usedCellHtml = '<div class="dv-col-used"></div>';
            speedHtml    = '';
            tempText     = '';
            thumbHtml    = '';
        }

        // Gear
        var settingsHref = '/Main/Device?name=' + devName;
        var gearEl = '<a class="dv-col-gear" href="' + settingsHref + '" title="Disk settings" aria-label="Open disk settings">' + GEAR_SVG + '</a>';

        // Toast placeholder (populated on bolt hover)
        // Filesystem pill - on rows that visually read as summaries
        // (synthetic aggregates with is_summary, or single-disk cache
        // pool tiles with style_as_summary). Shown inline next to the
        // name as a small blue chip ("xfs", "btrfs", "zfs", "mixed").
        // Empty / unknown FS skips rendering the pill entirely.
        // Disk name, wrapped so the Main-style identification tooltip has a
        // precise hover target (the name text itself, not the whole cell).
        var nameTip  = diskIdent(row);
        var nameHtml = '<span class="dv-name' + (notInstalled ? ' dv-name--missing' : '') + '"'
                     + (nameTip ? ' data-dv-ident="' + escapeHtml(nameTip) + '"' : '')
                     + '>' + nameEsc + '</span>'
                     + (notInstalled ? ' <span class="dv-missing-label">NOT INSTALLED or MISSING</span>' : '');
        if ((isSummary || stylesAsSummary) && row.fs && showFsBadge) {
            nameHtml += ' <span class="dv-fs-pill">' + escapeHtml(row.fs) + '</span>';
        }

        var toastHtml = '<div class="dv-row-toast" role="tooltip" aria-hidden="true"></div>';

        return '<div class="' + cls + '" data-name="' + escapeHtml(row.name || '') + '" data-severity="' + severity + '"' + (isSummary ? ' data-is-summary="1"' : '') + (notInstalled ? ' data-dv-missing="1"' : '') + '>' +
            '<span class="dv-col-bolt">' + boltEl + '</span>' +
            '<div class="dv-col-name' + (notInstalled ? ' dv-col-name--missing' : '') + '">' + nameHtml + '</div>' +
            '<span class="dv-col-size">' + sizeText + '</span>' +
            '<span class="dv-col-free">' + freeText + '</span>' +
            usedCellHtml +
            '<span class="dv-col-speed-wrap">' + speedHtml + '</span>' +
            '<span class="' + tempCls + '">' + escapeHtml(tempText) + '</span>' +
            '<span class="dv-col-thumb">' + thumbHtml + '</span>' +
            gearEl +
            toastHtml +
        '</div>';
    }

    // ── Render a section ────────────────────────────────────────────────
    function renderSection(sec){
        // Section header format: <LABEL> · DEVICES <N> · <RAID>
        // (the "· RAID X" suffix is omitted when there's no RAID layer).
        // Server stores singular root labels (ARRAY, CACHE, POOL, etc),
        // JS appends "DEVICE" or "DEVICES" inline based on count, then
        // joins everything with ' · ' separators. The CSS uppercases the
        // resulting string so capitalisation here doesn't matter.
        var rawLabel = sec.label || '';
        var count    = +sec.count || 0;
        var raid     = sec.raid || '';
        var secId    = sec.id || '';
        var pieces = [];
        if (rawLabel) pieces.push(rawLabel);
        // The boot section is always a single flash device, so a "DEVICE 1"
        // count adds nothing; show just the label, matching the tool.
        if (secId !== 'boot') pieces.push((count === 1 ? 'DEVICE' : 'DEVICES') + ' ' + count);
        if (raid) pieces.push(raid);
        var label = escapeHtml(pieces.join(' · '));
        var rows  = sec.tiles || [];

        var hasSummary = false;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].is_summary) { hasSummary = true; break; }
        }

        // Build the per-section indicator lists for the three axes shown
        // next to the section label: errors, high temperature, bad health.
        // Each entry records a human label so the hover toast can name the
        // affected disks. Summary tiles are skipped (their fields already
        // roll up members, so counting them would double-count).
        var rankS = { ok: 0, warning: 1, critical: 2 };
        var errDisks = [], healthDisks = [];
        var tempWarnDisks = [], tempCritDisks = [];
        var tempCritBlink = false;
        var healthWorst = 'warning';

        for (var bi = 0; bi < rows.length; bi++) {
            var bt = rows[bi];
            if (bt.is_summary) continue;
            var dlabel = bt.display_name || bt.name || '?';

            // Errors
            if (showDiskErrors) {
                var errCount = +bt.errors || 0;
                if (errCount > 0) {
                    errDisks.push(dlabel + ' (' + errCount + ' error' + (errCount === 1 ? '' : 's') + ')');
                }
            }

            // Temperature - classify from the raw value with per-disk
            // thresholds, same logic as the column-header severity. Warning
            // and critical are tracked separately so each gets its own
            // indicator. A disk more than 10% over its critical threshold
            // flags the critical indicator to blink.
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

            // SMART health
            var smart = bt.smart || 'unknown';
            var hsev = smart === 'critical' ? 'critical' : smart === 'warning' ? 'warning' : 'ok';
            if (hsev !== 'ok') {
                healthDisks.push(dlabel);
                if (rankS[hsev] > rankS[healthWorst]) healthWorst = hsev;
            }
        }

        // Assemble the indicator cluster. Each indicator is an icon plus a
        // small count badge pinned to its top-right, and a hover toast that
        // names the affected disks. The whole cluster is gated behind the
        // Show section indicators toggle.
        var indHtml = '';
        // Build a toast body as a title line followed by one disk per line, so
        // the affected disks stack vertically (same shape as the header badge
        // tooltip) instead of running together on one comma-separated line.
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

        // Bulk spin actions: visible only on the POOLS section header,
        // and only when the spin-button setting is ON. The buttons act
        // on every pool member that is NOT spin-disabled (parity and
        // multi-disk pool members are skipped silently by the click
        // handler, with a final backend guard as defence in depth).
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
            // Shared hover toast (one per actions group, content swapped on hover)
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

    // ── Render full model ────────────────────────────────────────────────
    function render(model){
        lastModel = model;
        // Persist a compact copy of the latest model so the very next page
        // load (e.g. a Dashboard auto-refresh that re-mounts the widget HTML)
        // can paint disks immediately, before fetchState() returns. Without
        // this the widget shows "Loading disks..." for 1-3 seconds on every
        // dashboard refresh while buildModel scans disks.ini, smartctl cache,
        // and unassigned-devices state on the server. localStorage survives
        // tab navigation and is per-origin so it's the right vessel here.
        // Wrapped in try/catch because Safari private mode and quota-exceeded
        // both throw on setItem, and a failed cache write must never break
        // the live render.
        try {
            window.localStorage.setItem(
                'diskviewer.lastModel.v1',
                JSON.stringify({ savedAt: Date.now(), model: model })
            );
        } catch(e) {}

        var container = $('dv-sections');
        if (!container) return;

        // Header: device count
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

        // Body: render ALL sections - the user scrolls inside the container
        // or drags the footer handle to grow the visible area.
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

        // Pool severity highlight class - when on, CSS rules with higher
        // specificity than the zebra paint warn/crit colours over the
        // dv-row--warn / --crit rows in the cache and pool sections.
        container.classList.toggle('dv-pool-highlight', poolHighlightUsed);
        // Disk-row font size: one of three tiers (small / default / large),
        // each scaling the data cells by ~5% via a CSS variable on the wrapper.
        container.classList.remove('dv-font-small', 'dv-font-default', 'dv-font-large');
        container.classList.add('dv-font-' + fontSize);

        // Clamp saved expandRows to the current model (disk count may
        // have changed since the value was stored). Using scrollHeight
        // here is safe because the new HTML has just been written.
        expandRows = clampToExtraRows(expandRows);

        applyContainerHeight();
        updateDragHandleVisibility();
    }


    // ============================================================================
    // 7. Layout & Visibility (container height, row counts, clamps)
    // ============================================================================

    // ── Apply max-height to the scrollable container ───────────────────
    // The container's max-height must always end exactly at the bottom of
    // a row, never partway through one. The previous version used a
    // theoretical formula (8 + expandRows) * 26px + 28px which assumed a
    // fixed row height and a fixed amount of header chrome. In practice
    // section headers (ARRAY/CACHE/POOLS), the sticky column header, and
    // section padding all shift the actual row offsets by a few pixels,
    // so the formula's stop point would land mid-row and clip a disk in
    // half - visible in the screenshot as a faint horizontal slice of the
    // next disk peeking below the last fully-visible one.
    //
    // Now we compute the height from real DOM positions: read each
    // .dv-row's bottom edge relative to the scroll container, then pick
    // the bottom edge of the (defaultVisibleRows + expandRows)-th row.
    // That guarantees the cut is always exactly at a row boundary,
    // regardless of how many sections or how much header padding is
    // above. Fallback to the legacy formula only if the DOM hasn't
    // painted yet (no rows present).
    //
    // The baseline (defaultVisibleRows) is now derived from the configured
    // section level (defaultExpandLevel ∈ 0..3) rather than a fixed 8.
    // See computeBaselineRowCount() and levelOfSectionId() for the mapping.
    function applyContainerHeight(){
        var container = $('dv-sections');
        if (!container) return;

        var defaultVisibleRows = computeBaselineRowCount(defaultExpandLevel);
        // expandRows can now be negative (user dragged up below baseline);
        // see clampToExtraRows for the semantics. totalVisible is floored
        // at 1 here as a final safety net - clampToExtraRows already keeps
        // expandRows at -(baseline-1) so we never go below 1, but a stray
        // out-of-range value should still not collapse the container.
        var totalVisible = defaultVisibleRows + expandRows;
        if (totalVisible < 1) totalVisible = 1;

        // Fast path during an active drag: dragState.rowOffsets is a
        // precomputed array of [offsetFromContainerTop, height] tuples that
        // onHandleDown captured at drag start. The DOM doesn't change shape
        // mid-drag (only the container's max-height does, and that doesn't
        // affect child layout), so reusing the cache avoids 60+ querySelectorAll
        // calls per second plus 60+ offsetTop walk-up chains, both of which
        // force layout flushes and cost real CPU during a drag.
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

        // Slow path: full DOM measurement. Used for initial render, after
        // a state poll repaints rows, and when no drag is in progress.
        // Measure: collect the bottom offset (relative to the container's
        // scrollable content) of every .dv-row. Section headers and the
        // sticky column header are NOT counted as rows - they are chrome
        // that always sits above the rows we're snapping to.
        var rows = container.querySelectorAll('.dv-row');
        if (rows.length > 0) {
            // Pick the row at index totalVisible-1 (zero-based), or the
            // last row if we want to show more than exist.
            var idx2 = Math.min(totalVisible, rows.length) - 1;
            if (idx2 < 0) idx2 = 0;
            var row = rows[idx2];
            // offsetTop is relative to the offsetParent. For nested
            // elements (.dv-row inside .dv-rows inside .dv-section inside
            // .dv-sections) we need to walk up until we hit the container,
            // so we don't pick up a wrong offsetParent.
            var top = 0;
            var node = row;
            while (node && node !== container) {
                top += node.offsetTop;
                node = node.offsetParent;
            }
            px = top + row.offsetHeight;
        } else {
            // Fallback: rows haven't rendered yet. Use the old estimate
            // so the container has a reasonable height before measurement
            // becomes possible. Subsequent renders will correct this.
            var rowH = 26;
            px = totalVisible * rowH + 28;
        }

        // Use setProperty with 'important' so Unraid tile CSS can't override.
        container.style.setProperty('max-height', px + 'px', 'important');
    }

    // ── Drag handle always visible (controls container height) ─────────
    function updateDragHandleVisibility(){
        var handle = $('dv-drag-handle');
        if (!handle) return;
        handle.style.display = '';
    }

    // ── Drag handle ──────────────────────────────────────────────────────
    // Hold left mouse on the handle and drag down to reveal rows, up to collapse.
    // Step = one tile row height. Snap to boundaries on mouseup.
    function tileRowHeight(){
        var container = document.querySelector('.dv-rows');
        if (!container) return 28;
        var row = container.querySelector('.dv-row');
        if (!row) return 28;
        var rect = row.getBoundingClientRect();
        return Math.max(20, Math.round(rect.height));
    }

    function clampToExtraRows(n){
        // expandRows is the user's drag-revealed extras relative to the
        // baseline. It can be:
        //   positive = show MORE rows than baseline (drag down to expand)
        //   zero     = show baseline exactly
        //   negative = show FEWER rows than baseline (drag up to shrink
        //              below baseline; needed when SHOW_* toggles are all
        //              on and the array section alone fills the tile, so
        //              the user has no other way to make it shorter)
        // Maximum positive value is (totalRows - defaultVisibleRows) so we
        // never reveal more rows than exist. Maximum negative is
        // -(defaultVisibleRows - 1) so at least one row stays visible -
        // shrinking to zero rows would leave the user with just chrome
        // and no way to discover that the widget can be re-expanded.
        var container = $('dv-sections');
        if (!container) return n;
        var rows = container.querySelectorAll('.dv-row');
        if (rows.length === 0) return n;
        var defaultVisibleRows = computeBaselineRowCount(defaultExpandLevel);
        var maxExtra = Math.max(0, rows.length - defaultVisibleRows);
        var minExtra = -(Math.max(0, defaultVisibleRows - 1));
        return Math.max(minExtra, Math.min(maxExtra, n));
    }


    // ============================================================================
    // 8. Polling (state fetch, speed fetch, timers)
    // ============================================================================

    // ── Fetch and poll ──────────────────────────────────────────────────
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

    // Lightweight poll: fetches only the speed column data and updates the
    // matching cells in place. Runs on its own (faster) cadence so the user
    // sees live throughput regardless of the global refresh interval.
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

    // Replace just the inner HTML of each row's speed cell. Avoids a full
    // re-render so the rest of the widget (drag state, hover toasts,
    // section headers) is untouched. Also patches lastModel so subsequent
    // full renders carry the latest speed values.
    function updateSpeeds(arr){
        if (!arr || !arr.length) return;
        // Build lookup: row's data-name attribute → speed cell payload.
        var byName = {};
        for (var i = 0; i < arr.length; i++) {
            var d = arr[i];
            if (!d || !d.name) continue;
            byName[d.name] = d;
        }

        // Update only data rows. Summary tiles are skipped explicitly
        // via the data-is-summary attribute - their speed is the PHP
        // aggregate of all member speeds, not any one member's value.
        // Without this skip the poller's name-based lookup collides with
        // the first pool member (both share data-name="cache") and
        // overwrites the aggregate, dropping it down to a single member's
        // speed. Performance: skip the innerHTML write when the rendered
        // HTML hasn't changed since the last poll - typical of disks
        // that are spun down (speed=0) or reading at a steady rate.
        var rows = document.querySelectorAll('.dv-row');
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            if (row.getAttribute('data-is-summary') === '1') continue;
            // Missing (not-installed) disks have no device and no speed; leave
            // their speed cell blank rather than letting the poll write a "-".
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

        // Recompute summary row speed cells locally - sum of member tile
        // speeds per section, dominant direction wins. Without this the
        // summary value would freeze at whatever the last full render
        // captured and look stale once members start showing live activity.
        // We never call the backend for this - the data we need is right
        // there in `byName` from the poll response.
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

        // Keep lastModel speed fields in sync so any future full render
        // (e.g. after a drag) starts from the latest data, not stale state.
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


    // ============================================================================
    // 9. CSRF Helper
    // ============================================================================

    // ── CSRF token helper (Unraid blocks POSTs without it) ─────────────
    function getCsrfToken(){
        return cfg.csrfToken || window.csrf_token || '';
    }


    // ============================================================================
    // 10. Drag Handle Interaction
    // ============================================================================

    function onHandleDown(ev){
        var handle = $('dv-drag-handle');
        if (!handle) return;
        if (ev.button !== undefined && ev.button !== 0) return;
        ev.preventDefault();
        var rowH = tileRowHeight();
        // Precompute the offset/height of every row relative to the
        // scrollable container, ONCE, at drag start. The DOM doesn't change
        // shape during a drag, so reusing this table saves a querySelectorAll
        // and an offsetTop walk-up per frame inside applyContainerHeight().
        // On a system with 30 disks at 60Hz drag throttle that's ~1800
        // skipped DOM measurements per second of active drag.
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
        // Throttle to display refresh rate via rAF. mousemove/touchmove can
        // fire 200+ times per second on high-DPI mice and 120Hz touch panels;
        // applyContainerHeight() rewrites the container max-height which is
        // a layout-dirty operation, so unthrottled drags can pin a CPU core
        // and cause visible jank. rAF coalesces queued frames so each tick
        // executes at most once between paints. Stash the latest event Y so
        // the rAF callback always uses the freshest value, not a stale one.
        dragState.lastY = ev.clientY;
        if (dragState.rafPending) return;
        dragState.rafPending = true;
        requestAnimationFrame(function(){
            dragState.rafPending = false;
            if (!dragState) return;
            var dy = dragState.lastY - dragState.startY;
            dragState.moved = true;
            var steps = Math.round(dy / dragState.rowH) * dragStepRows;
            // No Math.max(0, ...) here - clampToExtraRows() handles both
            // lower and upper bounds. Allowing target to go negative lets
            // the user drag up to shrink the widget below baseline, which
            // is the expected behaviour when all sections are visible and
            // the array section alone is taller than the user wants.
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

    // Keyboard accessibility: ArrowDown/ArrowUp when focused
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


    // ============================================================================
    // 11. Bolt Button (per-row spin)
    // ============================================================================

    // ── Spin button: hover toast + click dispatch (event delegation) ─
    function wireSpinButtons(){
        var container = $('dv-sections');
        if (!container) return;

        // Show toast on mouseenter, hide on mouseleave
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

        // Click handler
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


    // ============================================================================
    // 12. Bulk Spin (POOLS section header)
    // ============================================================================

    // ── Bulk spin (POOLS section header) ────────────────────────────────
    // Sequentially fires individual /spin requests for every pool disk that
    // is NOT spin-disabled (parity & multi-disk pool members are skipped).
    // Sequential dispatch is intentional: it keeps server load low and the
    // backend guard remains the final authority on what actually executes.
    function wireBulkSpin(){
        var container = $('dv-sections');
        if (!container) return;

        // Hover toast: show colored prompt above the bulk buttons on
        // mouseenter, hide on mouseleave. Mirrors the per-disk spin
        // toast so the bulk action gets the same visual feedback as
        // a single-disk action.
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

            // Find the POOLS section in the last model
            var pools = null;
            for (var i = 0; i < lastModel.sections.length; i++) {
                if (lastModel.sections[i].id === 'pools') { pools = lastModel.sections[i]; break; }
            }
            if (!pools || !pools.tiles || !pools.tiles.length) return;

            // Build target list: skip summaries and spin-disabled disks.
            // For 'up', only spin disks that are currently down. For 'down',
            // only those currently up. This avoids redundant API hits.
            var targets = [];
            for (var j = 0; j < pools.tiles.length; j++) {
                var t = pools.tiles[j];
                if (t.is_summary) continue;
                if (t.spin_disabled) continue;
                if (dir === 'up'   && t.spun)  continue;
                if (dir === 'down' && !t.spun) continue;
                if (t.name) targets.push(t.name);
            }

            // Disable both bulk buttons while running
            var allBulk = container.querySelectorAll('button.dv-bulk-spin');
            for (var k = 0; k < allBulk.length; k++) {
                allBulk[k].disabled = true;
                allBulk[k].classList.add('dv-bolt--busy');
            }

            if (targets.length === 0) {
                // Nothing to do; release UI shortly and refresh
                setTimeout(function(){
                    for (var m = 0; m < allBulk.length; m++) {
                        allBulk[m].disabled = false;
                        allBulk[m].classList.remove('dv-bolt--busy');
                    }
                    fetchState();
                }, 400);
                return;
            }

            // Throttling between consecutive bulk spin commands. Without
            // this, 24 pool drives get fired at the SATA controller back to
            // back inside ~2 seconds. Many controllers (especially Marvell
            // and ASMedia) cannot service that many simultaneous wake-ups,
            // and the symptom is NCQ command timeouts on UNRELATED disks
            // sharing the same lane - including array members like DISK1
            // that the widget never touched directly. Logged on Raptor1
            // 2026-04-29: bulk spin-up of 24 pool drives produced a
            // hard-reset on ata10 (DISK1) and 5 pool drives didn't wake at
            // all. Spacing the requests gives each disk time to settle
            // before the next one starts spinning up.
            //
            // Different cadences for the two directions:
            //   up   - hdparm read takes ~150-300ms to actually wake an HDD,
            //          so we wait 350ms before the next request. With 24
            //          drives this turns a ~2s burst into ~8s, well under
            //          any reasonable user-perceptible threshold.
            //   down - hdparm -y is near-instant (issue + return), but the
            //          ATA STANDBY IMMEDIATE command still needs a moment to
            //          propagate before the next one. 200ms is enough.
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
                // Wait throttleMs AFTER the previous request finished before
                // dispatching the next. Using onload+timer (rather than
                // setInterval) means we always serialise: the next disk
                // can't start until the previous server response has come
                // back AND the throttle window has elapsed.
                x.onload  = function(){ setTimeout(next, throttleMs); };
                x.onerror = x.ontimeout = function(){ setTimeout(next, throttleMs); };
                x.send(body);
            }
            next();
        });
    }


    // ============================================================================
    // 13. Refresh Button
    // ============================================================================

    // ── Refresh button ──────────────────────────────────────────────────
    function wireRefresh(){
        var btn = $('dv-manual-refresh');
        if (!btn) return;
        btn.addEventListener('click', function(ev){
            ev.preventDefault();
            var icon = btn.querySelector('i');
            if (btn.classList.contains('dv-busy')) return; // prevent double-click
            btn.classList.add('dv-busy');
            if (icon) {
                icon.classList.remove('fa-refresh');
                icon.classList.add('fa-hourglass-half');
            }
            // Guarantee a minimum visible duration (450ms) so the hourglass
            // is perceivable even when the API responds in <100ms.
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


    // ============================================================================
    // 14. Scroll Hint Tooltip
    // ============================================================================

    // ── Disk-name identification tooltip ────────────────────────────────
    // Plugin-styled hover tooltip (not the browser's native title) showing the
    // Main-page identification string for the disk under the cursor. One shared
    // element is appended to <body> so it escapes the widget's scroll overflow,
    // positioned over the hovered name and flipped below when there is no room
    // above. Mirrors the Tool page's .dvt-tip mechanism for a consistent look.
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
            // Left-align under the disk name's first letter (not centred).
            // Subtract the tip's 9px horizontal padding so the visible text,
            // not the box edge, lines up with the first character.
            var left = r.left - 9;
            left = Math.max(6, Math.min(left, window.innerWidth - tr.width - 6));
            var top = r.top - tr.height - 6;
            if (top < 4) top = r.bottom + 6;   // flip below if no room above
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

    // ── Scroll hint badge ───────────────────────────────────────────────
    // Tiny badge that appears next to the cursor for 2 seconds when the
    // user enters the .dv-sections panel and content is scrollable. The
    // native scrollbar is hidden, so without this hint there would be no
    // visual cue that more content exists. Suppressed when content fits
    // in the visible area (no overflow → nothing to scroll, no hint).
    function wireScrollHint(){
        var sections = $('dv-sections');
        if (!sections) return;

        // SVG with two stacked chevrons (up + down). Sized to match the
        // 9px box from CSS.
        var HINT_SVG =
            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                '<path d="M7 14l5-5 5 5z"/>' +
            '</svg>' +
            '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
                '<path d="M7 10l5 5 5-5z"/>' +
            '</svg>';

        var hint = null;          // lazy-created element
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
            // Offset 14px right and 4px down from the cursor so the badge
            // sits next to the pointer without being underneath it.
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
            // Auto-hide after 2 seconds even if cursor is still inside.
            if (hideTimer) clearTimeout(hideTimer);
            hideTimer = setTimeout(hide, 2000);
        });

        sections.addEventListener('mousemove', function(ev){
            // Reposition only while the hint is visible - don't re-trigger
            // the timer, otherwise it would never auto-dismiss.
            if (!hidden) {
                hint.style.left = (ev.clientX + 14) + 'px';
                hint.style.top  = (ev.clientY + 4)  + 'px';
            }
        });

        sections.addEventListener('mouseleave', hide);
        // User started interacting → they know they can scroll, hide hint.
        sections.addEventListener('scroll', hide, { passive: true });
        sections.addEventListener('wheel',  hide, { passive: true });
    }


    // ============================================================================
    // 15. Cache Painter (instant first paint from server-embedded model
    //     or localStorage fallback)
    // ============================================================================

    // Paint the widget immediately so the user never sees an empty body
    // during a page load or Dashboard auto-refresh. Two paint sources are
    // tried in order:
    //
    //   1. window.diskviewerConfig.initialModel - the server PHP embedded
    //      a fresh buildModel() snapshot directly in the page HTML at
    //      render time. This is the most common path: every page render
    //      and every dashboard tile re-mount carries a current model, so
    //      the widget appears fully populated the moment the HTML reaches
    //      the browser. Zero latency.
    //
    //   2. localStorage 'diskviewer.lastModel.v1' - the model from the
    //      last successful fetchState in any tab on this origin. Used as
    //      a fallback when the server-side embed is unavailable (older
    //      cached page, embed disabled), and may be a few seconds (or
    //      minutes) stale, but stale data on screen for one polling
    //      interval is dramatically better UX than an empty placeholder.
    //      Capped at 1 hour to avoid showing data from an offline server.
    //
    // The first real fetchState() will overwrite whichever painted first.
    // Returns true if a paint happened, false if both sources were empty.
    function paintFromCache(){
        // 1. Server-embedded initial model (preferred path)
        try {
            if (cfg && cfg.initialModel && cfg.initialModel.sections) {
                render(cfg.initialModel);
                return true;
            }
        } catch(e) {}

        // 2. localStorage fallback
        try {
            var raw = window.localStorage.getItem('diskviewer.lastModel.v1');
            if (!raw) return false;
            var wrapped = JSON.parse(raw);
            if (!wrapped || !wrapped.model) return false;
            // Drop very old caches so we don't show data from a server that
            // was offline overnight. 1 hour is generous: the live fetch will
            // correct anything more recent than that within seconds anyway.
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


    // ============================================================================
    // 16. Init (kicks everything off)
    // ============================================================================

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
        // Paint cached data first (instant), then trigger the real fetch.
        // Order matters: paintFromCache before fetchState so the user sees
        // disks immediately even if the AJAX call takes a couple of seconds.
        paintFromCache();
        fetchState();
        startPolling();

        // Re-fetch state whenever the user returns to this tab. Catches the
        // case where the user navigates away (Settings, another plugin
        // page, another browser tab) and then comes back - in that flow,
        // Unraid may swap the tile content via AJAX without re-executing
        // our JS module, so the polling timer set up at init() time is
        // working off the original DOM and the new tile body sits empty
        // until the next polling tick (up to refresh interval seconds in
        // the worst case). visibilitychange fires reliably on every tab
        // focus change in every modern browser, and pageshow fires when
        // the page is restored from the back-forward cache. Together they
        // cover every "return to dashboard" scenario.
        //
        // Wired ONCE on document at init time. Even if init() were to run
        // a second time on a tile re-mount, document is the same node, so
        // we'd just be adding duplicate listeners - guard with a flag on
        // the document so we only attach once per page lifetime.
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


    // ============================================================================
    // 17. Global repaint hook (for dashboard tile re-renders)
    // ============================================================================

    // The Unraid Dashboard auto-refresh and the navigate-away-and-back flow
    // both swap the tile HTML via AJAX without re-executing this JS module.
    // The inline <script>window.diskviewerConfig = {...};</script> emitted
    // alongside the new tile HTML DOES re-execute (overriding the global with
    // the fresh server-built model), and that script also calls
    // window.diskviewerRepaint() if it's defined. This function is that
    // hook: re-bind interactive handlers (drag handle, refresh, spin, etc.
    // because the DOM nodes are brand new after the HTML swap), refresh
    // our local cfg reference, and paint immediately from the embedded
    // initialModel so the user never sees an empty body. The polling
    // interval may also have changed if the user updated settings in
    // between, so restart polling with the new value.
    window.diskviewerRepaint = function(){
        try {
            // Pick up the freshly-injected config blob.
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
            showDecimalPct    = !!cfg.showDecimalPct;
            showUsedColumn    = !!cfg.showUsedColumn;
            showIdTooltip     = cfg.showIdTooltip !== false;
            showSectionIndicators = cfg.showSectionIndicators !== false;
            fontSize          = (cfg.fontSize === 'small' || cfg.fontSize === 'large') ? cfg.fontSize : 'default';

            // Re-bind handlers on the new DOM nodes. Without this, the drag
            // handle in the new tile fragment has no listeners attached.
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

            // Paint instantly from the embedded model.
            paintFromCache();

            // Fire an immediate fetchState() too, so even if the embedded
            // initialModel was missing or stale, fresh data arrives within
            // one AJAX round-trip (~100ms typical) instead of waiting up
            // to the full polling interval (30 sec default). This is the
            // critical guard against "blank widget for 30 seconds" - the
            // paintFromCache path is best-effort, the fetchState path is
            // the floor for how long the user can possibly wait.
            fetchState();

            // Restart polling in case the interval setting changed.
            startPolling();
        } catch(e) {
            // Silent: a repaint failure must not break subsequent ticks.
        }
    };


    'use strict';
    // ════════════════════════════════════════════════════════════════════════
    // TABLE OF CONTENTS
    // ────────────────────────────────────────────────────────────────────────
    //    1. Config, state, persistence
    //        - Config and state
    //        - DOM refs
    //        - Persistent state for the drag handle
    //        - Compute the worst-severity for the three "stateful" columns
    //    2. Utilities and constants
    //        - Helpers
    //        - SVG icons
    //        - Section-level helpers
    //        - CSRF token helper (Unraid blocks POSTs without it)
    //    3. Rendering layer
    //        - Render column header row (shown once at top)
    //        - Render a single row
    //        - Render a section
    //        - Render full model
    //    4. Layout sizing
    //        - Apply max-height to the scrollable container
    //        - Drag handle always visible (controls container height)
    //    5. Network — fetch and poll
    //        - Fetch and poll
    //    6. Drag handle interaction
    //        - Drag handle
    //    7. Spin actions (single + bulk)
    //        - Bulk spin (POOLS section header)
    //    8. Refresh button
    //        - Refresh button
    //    9. Scroll hint badge
    //        - Scroll hint badge
    //   10. Init & lifecycle
    //        - Init
    // ════════════════════════════════════════════════════════════════════════

    if (defaultExpandLevel < 0) defaultExpandLevel = 0;
    if (defaultExpandLevel > 3) defaultExpandLevel = 3;
    // ── DOM refs ────────────────────────────────────────────────────────
    function $(id){ return document.getElementById(id); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
