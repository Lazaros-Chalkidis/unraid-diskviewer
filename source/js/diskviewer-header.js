/* ============================================================================
   DISK VIEWER  -  Header Bar Indicator Script
   /plugins/diskviewer/js/diskviewer-header.js

   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3

   Adds a custom disk icon to the Unraid navbar with three coloured marker
   glyphs (thermometer, thumbs, percent) that summarise temperature, SMART
   health, and utilisation severity at a glance. Polls /diskviewer_header.php
   every 30 seconds for fresh severity values and repaints the markers.
   ========================================================================= */

// DiskViewer header indicator
// Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3

// ============================================================================
// 0. Global click handler (called by Unraid's button onclick attribute)
// ============================================================================
function DiskViewerButton(){
    var action = (window.diskviewerHeaderAction) || 'main';
    if (action === 'settings') {
        location.href = '/Settings/DiskViewerSettings';
    } else if (action === 'widget') {
        location.href = '/Dashboard';
    } else {
        location.href = '/Main';
    }
}

(function(){
    "use strict";

    // ============================================================================
    // 1. Module state
    // ============================================================================

    var navItem     = null;
    var thermo      = null;  // top-left:   temperature severity
    var health      = null;  // top-centre: SMART health severity
    var pctMark     = null;  // top-right:  utilization severity
    var diskIcon    = null;  // the main hdd silhouette - tinted by errors_severity
    var diskTooltip   = null;  // custom hover tooltip showing per-disk issue rows
    var tooltipText   = '#ddd'; // fallback text colour, set from theme palette in setup()


    // ============================================================================
    // 2. Constants (severity colours, marker SVGs, halo filter)
    // ============================================================================

    // Severity to colour. Mirrors --dv-ok / --dv-warn / --dv-crit from widget.css.
    var SEV_COLOR = {
        ok:       '#4caf50',
        warning:  '#ef9f27',
        critical: '#e24b4a'
    };

    // Lucide thermometer (temp marker). Sized to 10x10 with a thicker
    // stroke and a drop-shadow outline so it stays legible against the
    // navbar regardless of theme. Earlier 8x8 / stroke-width:4 was too
    // thin and washed into the navbar background especially in light mode.
    var THERMO_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>'
      + '</svg>';

    // Lucide thumbs-up (health marker, ok/warning). 10x10 cell to match thermo.
    var THUMB_UP_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M7 10v12"/>'
      + '<path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>'
      + '</svg>';

    // Lucide thumbs-down (health marker, critical). 10x10 cell.
    var THUMB_DOWN_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M17 14V2"/>'
      + '<path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/>'
      + '</svg>';

    // Drop-shadow outline applied to all three markers. 1px black halo on
    // dark themes flips to a 1px white halo via prefers-color-scheme so the
    // markers stay separated from the navbar background and from each other.
    // filter() uses drop-shadow which respects the SVG/text shape, unlike
    // text-shadow which only works on rasterized glyphs.
    var MARKER_FILTER_DARK  = 'drop-shadow(0 0 1px rgba(0,0,0,.95)) drop-shadow(0 0 .5px rgba(0,0,0,.95))';
    var MARKER_FILTER_LIGHT = 'drop-shadow(0 0 1px rgba(255,255,255,.95)) drop-shadow(0 0 .5px rgba(255,255,255,.95))';


    // ============================================================================
    // 3. setup() — DOM scaffold (one-shot at startup)
    //    Replaces the navbar icon with a custom front-face disk silhouette and
    //    anchors three coloured markers across the top of it.
    // ============================================================================

    function setup(){
        navItem = document.querySelector('.nav-item.DiskViewerButton');
        if(!navItem){ setTimeout(setup, 500); return; }

        // Hidden until first poll resolves. HEADER_SHOW_BADGE=0 keeps it hidden.
        navItem.style.display = 'none';

        var link = navItem.querySelector('a');
        if(link){
            // Replace product image with inline SVG. Front-face-only disk
            // silhouette: just the bottom drive-bay rectangle with the two
            // status LED dots. The full Lucide hard-drive icon (with the slab
            // line at y=12 and the angled top body) takes the entire 16px
            // height, leaving no room inside the icon footprint for any
            // overlay marker - anything we tried to place around it had to
            // sit outside the bounds and got clipped by the Unraid navbar
            // strip. Stripping to just the front face confines the disk to
            // the bottom ~5px and frees the top ~9px as marker real estate
            // INSIDE the same 16x16 footprint, so no negative offsets, no
            // overflow tricks, no clipping anywhere.
            var img = link.querySelector('img, b.system, i.system, b.fa, span');
            if(img){
                var svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                svg.setAttribute('width','16');
                svg.setAttribute('height','16');
                svg.setAttribute('viewBox','0 0 24 24');
                svg.setAttribute('fill','none');
                svg.setAttribute('stroke','currentColor');
                svg.setAttribute('stroke-width','2');
                svg.setAttribute('stroke-linecap','round');
                svg.setAttribute('stroke-linejoin','round');
                svg.setAttribute('class','system');

                // Drive-bay rectangle (the disk's front face). y=13 to y=20
                // anchors it at the bottom of the 24x24 viewBox - i.e. the
                // bottom ~5px of the 16x16 rendered icon.
                var bay = document.createElementNS('http://www.w3.org/2000/svg','rect');
                bay.setAttribute('x','2');
                bay.setAttribute('y','13');
                bay.setAttribute('width','20');
                bay.setAttribute('height','7');
                bay.setAttribute('rx','1.5');
                svg.appendChild(bay);

                // Two status LED dots, same coordinates as the original Lucide
                // icon but nudged half a pixel down to centre on the bay.
                var dot1 = document.createElementNS('http://www.w3.org/2000/svg','line');
                dot1.setAttribute('x1','6'); dot1.setAttribute('y1','16.5');
                dot1.setAttribute('x2','6.01'); dot1.setAttribute('y2','16.5');
                svg.appendChild(dot1);

                var dot2 = document.createElementNS('http://www.w3.org/2000/svg','line');
                dot2.setAttribute('x1','10'); dot2.setAttribute('y1','16.5');
                dot2.setAttribute('x2','10.01'); dot2.setAttribute('y2','16.5');
                svg.appendChild(dot2);

                // 16x16 wrapper hosts both the disk silhouette (positioned
                // top:0 left:0) and the three markers (positioned across the
                // top half). Everything is inside the icon footprint so no
                // ancestor clipping can cut anything off.
                var wrap = document.createElement('span');
                wrap.style.position = 'relative';
                wrap.style.display = 'inline-block';
                wrap.style.width = '16px';
                wrap.style.height = '16px';
                wrap.style.lineHeight = '0';
                wrap.style.verticalAlign = 'middle';

                // Pin the disk silhouette absolutely inside the wrapper so it
                // doesn't push the markers around with its own line height.
                svg.style.position = 'absolute';
                svg.style.top = '0';
                svg.style.left = '0';
                wrap.appendChild(svg);
                img.parentNode.replaceChild(wrap, img);

                var iconColor = getComputedStyle(link).color || '#ccc';
                svg.setAttribute('stroke', iconColor);
                diskIcon = svg;

                link.style.position = 'relative';
            } else {
                link.style.position = 'relative';
            }

            // Anchor markers inside the icon wrapper if it exists, else fall
            // back to the link. Wrapper is the new 16x16 inline-block span we
            // just created.
            var anchor = link.querySelector('span') || link;

            // Three peer markers across the top of the icon footprint, now
            // 10x10 each (previously 8x8). The bigger size + thicker stroke
            // (5 vs 4) + drop-shadow outline filter dramatically improves
            // legibility against the navbar background, fixing the previous
            // "too thin to notice" complaint. Horizontal placement is shifted
            // to fit the larger glyphs without overlapping each other or
            // running off the right edge of the wrapper - thermo sits to the
            // left of the wrapper, health centred over the disk silhouette,
            // pctMark to the right.
            //
            // pointer-events:none on all three so they never intercept the
            // click that opens the disk page.

            // Pick black or white halo based on the navbar's text colour.
            // Bright text (>= ~50% luminance) => navbar is dark => use black
            // halo to separate marker colours from any dark surrounding.
            // Dark text => navbar is light => use white halo. We sample the
            // computed colour on the link element since that's what the icon
            // inherits.
            var navColor = getComputedStyle(link).color || '#cccccc';
            var rgbMatch = navColor.match(/\d+/g) || [200,200,200];
            var luminance = (parseInt(rgbMatch[0],10) + parseInt(rgbMatch[1],10) + parseInt(rgbMatch[2],10)) / 3;
            var markerFilter = luminance >= 128 ? MARKER_FILTER_DARK : MARKER_FILTER_LIGHT;

            // Temperature marker
            thermo = document.createElement('span');
            thermo.style.position = 'absolute';
            thermo.style.top = '-2px';
            thermo.style.left = '-9px';
            thermo.style.width = '10px';
            thermo.style.height = '10px';
            thermo.style.display = 'inline-flex';
            thermo.style.alignItems = 'center';
            thermo.style.justifyContent = 'center';
            thermo.style.lineHeight = '1';
            thermo.style.pointerEvents = 'none';
            thermo.style.color = SEV_COLOR.ok;
            thermo.style.filter = markerFilter;
            thermo.innerHTML = THERMO_SVG;
            anchor.appendChild(thermo);

            // Health marker. Sat at left:4 originally for geometric centering
            // between thermo (-9) and pctMark (18), but the thumb-up Lucide
            // path has heavier visual mass on its right side (the palm and
            // fingers), so its optical centre lands a pixel right of the box
            // centre. Result: it looked crowded against the percent glyph.
            // Shifted to left:3 for visual balance - geometric distances are
            // now 12px (thermo→thumb) vs 15px (thumb→percent), but the optical
            // distances read as roughly equal because of the thumb glyph's
            // right-side mass.
            health = document.createElement('span');
            health.style.position = 'absolute';
            health.style.top = '-5px';
            health.style.left = '3px';
            health.style.width = '10px';
            health.style.height = '10px';
            health.style.display = 'inline-flex';
            health.style.alignItems = 'center';
            health.style.justifyContent = 'center';
            health.style.lineHeight = '1';
            health.style.pointerEvents = 'none';
            health.style.color = SEV_COLOR.ok;
            health.style.filter = markerFilter;
            health.innerHTML = THUMB_UP_SVG;
            anchor.appendChild(health);

            // Utilization marker - text glyph "%" sized to match the 10px
            // SVGs visually. font-weight:900 alone gave a noticeably thinner
            // stroke than the 5-weight SVG paths next to it because most
            // system fonts top out around weight 700/800 even when 900 is
            // requested. -webkit-text-stroke adds a half-pixel outline in
            // the same colour as the fill, which thickens the glyph the
            // same way the SVG strokes thicken the thermo and thumb. Drop
            // shadow halo applies on top for the same legibility reason as
            // the other two markers. Explicit width/height + inline-flex
            // required because the 16x16 wrapper has line-height:0 (so the
            // absolute SVG inside doesn't push siblings); a bare text node
            // would inherit the collapsed line-height and disappear.
            pctMark = document.createElement('span');
            pctMark.style.position = 'absolute';
            pctMark.style.top = '-2px';
            pctMark.style.left = '18px';
            pctMark.style.width = '10px';
            pctMark.style.height = '10px';
            pctMark.style.display = 'inline-flex';
            pctMark.style.alignItems = 'center';
            pctMark.style.justifyContent = 'center';
            pctMark.style.fontSize = '11px';
            pctMark.style.fontWeight = '900';
            pctMark.style.lineHeight = '1';
            pctMark.style.pointerEvents = 'none';
            pctMark.style.color = SEV_COLOR.ok;
            pctMark.style.filter = markerFilter;
            pctMark.style.fontFamily = 'Arial Black, Helvetica Neue, system-ui, -apple-system, sans-serif';
            pctMark.style.webkitTextStroke = '0.6px currentColor';
            pctMark.textContent = '%';
            anchor.appendChild(pctMark);
        }

        poll();
        setInterval(poll, 30000);

        // Custom hover tooltip - structured per-disk issue panel that
        // replaces the browser-native title-attribute popup.
        //
        // Two design choices that matter for reliability:
        //   1. Tooltip is appended to document.body (NOT to navItem).
        //      Some Unraid themes wrap the navbar in containers with
        //      overflow:hidden, transform, or contain:layout which would
        //      either clip a child-of-navItem tooltip or break its
        //      coordinate system. Living at body level under
        //      position:fixed sidesteps all of that.
        //   2. Hover is caught with both mouseenter and mouseover, and
        //      released with mouseleave + mouseout. Some Unraid themes
        //      block hover on the inner anchor and the SVG inside it
        //      eats pointer events - so the more event types we listen
        //      for, the harder it is for the popup to silently never
        //      fire. The dedup is implicit: showTooltip() is idempotent
        //      (sets display:block over and over, harmless).
        //
        // Theme adaptation: Unraid attaches a class on <body> matching
        // the active theme name (black/white/azure/gray). We read it
        // here and pick a matching palette so the panel doesn't look
        // alien on light pages. Falls back to dark if no recognised
        // theme class is found, since the legacy default is dark.
        var themePalette = {
            black: { bg: '#1a1a1a', border: '#555',    text: '#dddddd' },
            gray:  { bg: '#2a2a2a', border: '#555',    text: '#e8e8e8' },
            white: { bg: '#ffffff', border: '#cdcdcd', text: '#222222' },
            azure: { bg: '#fbfdff', border: '#bcd2e8', text: '#1a3149' }
        };
        var bodyClasses = (document.body && document.body.className || '').split(/\s+/);
        var unraidTheme = 'black';
        for (var ti = 0; ti < bodyClasses.length; ti++) {
            if (themePalette[bodyClasses[ti]]) { unraidTheme = bodyClasses[ti]; break; }
        }
        var pal = themePalette[unraidTheme];
        tooltipText = pal.text;

        diskTooltip = document.createElement('div');
        diskTooltip.style.cssText =
            'position:fixed;' +
            'display:none;' +
            'z-index:2147483647;' +
            'background:' + pal.bg + ';' +
            'border:1px solid ' + pal.border + ';' +
            'border-radius:5px;' +
            'padding:8px 12px;' +
            'box-shadow:0 4px 14px rgba(0,0,0,0.5);' +
            'color:' + pal.text + ';' +
            'font-size:11px;' +
            'font-family:system-ui,-apple-system,"Segoe UI",sans-serif;' +
            'line-height:1.5;' +
            'white-space:nowrap;' +
            'pointer-events:none;' +
            'min-width:220px;' +
            'max-width:360px;';
        document.body.appendChild(diskTooltip);

        // Show/hide helpers. show() positions the panel relative to the
        // navItem's current viewport rect every time, so the tooltip
        // stays correctly anchored even if the navbar shifts on scroll
        // or the page resizes.
        function showHeaderTooltip(){
            if (!diskTooltip || !navItem) return;
            var rect = navItem.getBoundingClientRect();
            // Display before measuring so getBoundingClientRect on the
            // tooltip returns its real width.
            diskTooltip.style.display = 'block';
            var tipW = diskTooltip.offsetWidth || 220;
            // Right-align under the icon, then clamp so we never spill
            // past the right edge (or the left, on extremely narrow
            // viewports). Vertical anchor: 8px below the icon.
            var leftPx = rect.right - tipW;
            var maxLeft = window.innerWidth - tipW - 8;
            if (leftPx > maxLeft) leftPx = maxLeft;
            if (leftPx < 8)      leftPx = 8;
            diskTooltip.style.left = leftPx + 'px';
            diskTooltip.style.top  = (rect.bottom + 8) + 'px';
        }
        function hideHeaderTooltip(){
            if (diskTooltip) diskTooltip.style.display = 'none';
        }

        // Belt-and-suspenders event hookup. We listen on the navItem
        // (the <li>) AND the link inside it. mouseenter/leave handle
        // the simple cases; mouseover/out plus a relatedTarget check
        // catch themes that block enter/leave or themes whose child SVG
        // breaks bubbling. Either path eventually fires the same
        // show/hide pair, so duplication is harmless.
        navItem.addEventListener('mouseenter', showHeaderTooltip);
        navItem.addEventListener('mouseleave', hideHeaderTooltip);
        navItem.addEventListener('mouseover',  showHeaderTooltip);
        navItem.addEventListener('mouseout',   function(ev){
            // Only hide when the pointer truly leaves the nav item -
            // moving from the link to a child SVG fires mouseout on
            // the link with relatedTarget inside the navItem, which
            // we want to keep showing.
            if (!navItem.contains(ev.relatedTarget)) hideHeaderTooltip();
        });
        if (link) {
            link.addEventListener('mouseenter', showHeaderTooltip);
            link.addEventListener('mouseover',  showHeaderTooltip);
            // Strip any pre-existing browser-title text so it doesn't
            // compete with the custom panel.
            link.removeAttribute('title');
        }

        // Convenience hook for manual verification from devtools:
        // run `diskviewerShowTooltip()` in the console; if the panel
        // appears, the DOM/CSS path is fine and the issue is purely
        // event delivery (a theme stripping hover events on the navbar
        // somehow). If it doesn't, the panel itself isn't getting
        // built correctly.
        window.diskviewerShowTooltip = showHeaderTooltip;
        window.diskviewerHideTooltip = hideHeaderTooltip;
    }

    // ============================================================================
    // 3b. renderTooltip() — paint the custom hover panel from disk_issues
    // ============================================================================

    // Builds the panel content from a list of {name, axis, severity, label}
    // entries. Each row is name | axis | value, colour-tinted by severity.
    // Empty list renders a single green "All disks OK" message. The list is
    // already sorted server-side (axis priority then severity) so iteration
    // order is final.
    function renderTooltip(issues){
        if (!diskTooltip) return;
        if (!issues || issues.length === 0) {
            diskTooltip.innerHTML =
                '<div style="color:#4caf50;font-weight:600;text-align:center;padding:2px 0;">' +
                'All disks are OK</div>';
            return;
        }
        var axisLabel = { health: 'HEALTH', errors: 'ERRORS', temp: 'TEMP', used: 'USED' };
        var sevColor  = { critical: '#f44336', warning: '#ffb300' };
        var rows = '';
        for (var i = 0; i < issues.length; i++) {
            var it    = issues[i] || {};
            var color = sevColor[it.severity] || tooltipText;
            var name  = String(it.name  || '?');
            var ax    = axisLabel[it.axis] || String(it.axis || '').toUpperCase();
            var label = String(it.label || '');
            rows +=
                '<div style="display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:2px 0;color:' + color + ';">' +
                  '<span style="font-weight:600;text-align:left;">'                                 + escapeHtml(name)  + '</span>' +
                  '<span style="opacity:0.9;font-size:10px;font-weight:700;letter-spacing:0.04em;">' + escapeHtml(ax)    + '</span>' +
                  '<span style="font-weight:700;text-align:right;">'                                + escapeHtml(label) + '</span>' +
                '</div>';
        }
        diskTooltip.innerHTML = rows;
    }

    // Cheap HTML escape - disk names from disks.ini are user-controlled
    // (the user names their pools) and end up as innerHTML, so an unsafe
    // pool name like </span><script>...</script> would otherwise execute.
    function escapeHtml(s){
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ============================================================================
    // 4. applyState() — paint markers from polled severity values
    // ============================================================================

    function applyState(temp, healthSev, util, errorsSev, issues){
        // 'off' from any axis (i.e. HEADER_SHOW_BADGE=0) means hide entirely.
        if (temp === 'off' || healthSev === 'off' || util === 'off' || errorsSev === 'off') {
            navItem.style.display = 'none';
            return;
        }

        // Always visible whenever the badge is enabled, so a green status
        // is just as readable as a red one.
        navItem.style.display = '';

        if (thermo)  thermo.style.color  = SEV_COLOR[temp] || SEV_COLOR.ok;
        if (pctMark) pctMark.style.color = SEV_COLOR[util] || SEV_COLOR.ok;
        if (health) {
            // Health element reflects ONLY the SMART health axis. Disk
            // errors don't bleed into here - a SMART-healthy disk with
            // BTRFS errors keeps the thumb green; the errors signal
            // lives on the disk silhouette below instead.
            health.style.color = SEV_COLOR[healthSev] || SEV_COLOR.ok;
            health.innerHTML = (healthSev === 'critical') ? THUMB_DOWN_SVG : THUMB_UP_SVG;
        }

        // Disk silhouette - tinted by the errors axis. Falls back to the
        // ambient nav link colour when errors are clean, so the icon
        // matches whatever the user's Unraid theme paints the rest of
        // the header bar in. When errors_severity raises to warning,
        // the silhouette goes amber; reserves red for a future
        // critical state (currently errors top out at warning).
        if (diskIcon) {
            if (errorsSev === 'warning' || errorsSev === 'critical') {
                diskIcon.setAttribute('stroke', SEV_COLOR[errorsSev]);
            } else {
                var iconColor = getComputedStyle(navItem.querySelector('a') || navItem).color || '#ccc';
                diskIcon.setAttribute('stroke', iconColor);
            }
        }

        // Repaint the hover tooltip from the latest disk_issues. The
        // custom panel replaces the browser-native title attribute and
        // shows one row per problem.
        renderTooltip(issues);
    }

    // ============================================================================
    // 6. poll() — XHR fetch from header.php every 30s
    // ============================================================================

    function poll(){
        var x = new XMLHttpRequest();
        x.open('GET', '/plugins/diskviewer/include/diskviewer_header.php?t=' + Date.now());
        x.timeout = 5000;
        x.onload = function(){
            try {
                var r = JSON.parse(x.responseText) || {};
                var t = typeof r.temp_severity    === 'string' ? r.temp_severity    : 'ok';
                var h = typeof r.health_severity  === 'string' ? r.health_severity  : 'ok';
                var u = typeof r.util_severity    === 'string' ? r.util_severity    : 'ok';
                var e = typeof r.errors_severity  === 'string' ? r.errors_severity  : 'ok';
                // Header click action - publish to window so the click
                // handler (lines 20-28 of this file) can route the user
                // to their chosen page. Read on every poll so a setting
                // change takes effect within one poll cycle without a
                // browser refresh.
                var ca = (typeof r.click_action === 'string') ? r.click_action : 'main';
                if (ca !== 'main' && ca !== 'widget' && ca !== 'settings') ca = 'main';
                window.diskviewerHeaderAction = ca;
                var issues = Array.isArray(r.disk_issues) ? r.disk_issues : [];
                applyState(t, h, u, e, issues);
            } catch(e2){
                navItem.style.display = 'none';
            }
        };
        x.onerror = x.ontimeout = function(){};
        x.send();
    }

    // ============================================================================
    // 7. Bootstrap — defer setup() so the navbar DOM is settled before we patch it
    // ============================================================================

    setTimeout(setup, 2000);
})();
