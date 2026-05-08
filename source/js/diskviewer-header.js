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

    var navItem = null;
    var thermo  = null;  // top-left:   temperature severity
    var health  = null;  // top-centre: SMART health severity
    var pctMark = null;  // top-right:  utilization severity


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
    }

    // ============================================================================
    // 4. buildTooltip() — hover-tooltip text picker
    // ============================================================================

    // Tooltip phrasing is intentionally vague: tell the user something is
    // off, point them at the disks, but don't reveal which axis or which
    // disk - that's what the widget is for. When everything is green we say
    // so explicitly so the user knows the indicator is live and not stale.
    function buildTooltip(t, h, u){
        var bad = [];
        if (t !== 'ok') bad.push('temperature');
        if (h !== 'ok') bad.push('health');
        if (u !== 'ok') bad.push('utilization');
        if (bad.length === 0) return 'Disk Viewer: everything is ok';
        // Single axis - mention it once
        if (bad.length === 1) return 'Disk Viewer: check your disks (' + bad[0] + ')';
        // Multiple axes - generic catch-all
        return 'Disk Viewer: check your disks';
    }

    // ============================================================================
    // 5. applyState() — paint markers from polled severity values
    // ============================================================================

    function applyState(temp, healthSev, util){
        // 'off' from any axis (i.e. HEADER_SHOW_BADGE=0) means hide entirely.
        if (temp === 'off' || healthSev === 'off' || util === 'off') {
            navItem.style.display = 'none';
            return;
        }

        // Always visible whenever the badge is enabled, so a green status
        // is just as readable as a red one.
        navItem.style.display = '';

        if (thermo)  thermo.style.color  = SEV_COLOR[temp]      || SEV_COLOR.ok;
        if (pctMark) pctMark.style.color = SEV_COLOR[util]      || SEV_COLOR.ok;
        if (health) {
            health.style.color = SEV_COLOR[healthSev] || SEV_COLOR.ok;
            // Thumb flips to "down" only on critical; warning keeps the up
            // glyph because it's still salvageable, just amber.
            health.innerHTML = (healthSev === 'critical') ? THUMB_DOWN_SVG : THUMB_UP_SVG;
        }

        var link = navItem.querySelector('a');
        if (link) link.setAttribute('title', buildTooltip(temp, healthSev, util));
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
                var t = typeof r.temp_severity   === 'string' ? r.temp_severity   : 'ok';
                var h = typeof r.health_severity === 'string' ? r.health_severity : 'ok';
                var u = typeof r.util_severity   === 'string' ? r.util_severity   : 'ok';
                applyState(t, h, u);
            } catch(e){
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
