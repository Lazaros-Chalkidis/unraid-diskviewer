/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

// unraid calls this from the navbar button's onclick
function DiskViewerButton(){
    var action = (window.diskviewerHeaderAction) || 'main';
    if (action === 'settings') {
        location.href = '/Settings/DiskViewerSettings';
    } else if (action === 'widget') {
        location.href = '/Dashboard';
    } else if (action === 'tool') {
        location.href = '/Tools/DiskViewerTool';
    } else {
        location.href = '/Main';
    }
}

(function(){
    "use strict";

    var navItem     = null;
    var thermo      = null;
    var health      = null;
    var pctMark     = null;
    var diskIcon    = null;
    var thermoBlink = null;
    var diskTooltip   = null;
    var tooltipText   = '#ddd';

    // matches --dv-ok / --dv-warn / --dv-crit from the stylesheet
    var SEV_COLOR = {
        ok:       '#4caf50',
        warning:  '#ef9f27',
        critical: '#e24b4a'
    };

    var THERMO_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>'
      + '</svg>';

    var THUMB_UP_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M7 10v12"/>'
      + '<path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>'
      + '</svg>';

    var THUMB_DOWN_SVG =
        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" '
      + 'stroke="currentColor" stroke-width="5" stroke-linecap="round" '
      + 'stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M17 14V2"/>'
      + '<path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/>'
      + '</svg>';

    var MARKER_FILTER_DARK  = 'drop-shadow(0 0 1px rgba(0,0,0,.95)) drop-shadow(0 0 .5px rgba(0,0,0,.95))';
    var MARKER_FILTER_LIGHT = 'drop-shadow(0 0 1px rgba(255,255,255,.95)) drop-shadow(0 0 .5px rgba(255,255,255,.95))';

    // inject the disk icon + the three severity markers into the navbar once
    function setup(){
        navItem = document.querySelector('.nav-item.DiskViewerButton');
        if(!navItem){ setTimeout(setup, 500); return; }

        navItem.style.display = 'none';

        var link = navItem.querySelector('a');
        if(link){

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

                var bay = document.createElementNS('http://www.w3.org/2000/svg','rect');
                bay.setAttribute('x','2');
                bay.setAttribute('y','13');
                bay.setAttribute('width','20');
                bay.setAttribute('height','7');
                bay.setAttribute('rx','1.5');
                svg.appendChild(bay);

                var dot1 = document.createElementNS('http://www.w3.org/2000/svg','line');
                dot1.setAttribute('x1','6'); dot1.setAttribute('y1','16.5');
                dot1.setAttribute('x2','6.01'); dot1.setAttribute('y2','16.5');
                svg.appendChild(dot1);

                var dot2 = document.createElementNS('http://www.w3.org/2000/svg','line');
                dot2.setAttribute('x1','10'); dot2.setAttribute('y1','16.5');
                dot2.setAttribute('x2','10.01'); dot2.setAttribute('y2','16.5');
                svg.appendChild(dot2);

                var wrap = document.createElement('span');
                wrap.style.position = 'relative';
                wrap.style.display = 'inline-block';
                wrap.style.width = '16px';
                wrap.style.height = '16px';
                wrap.style.lineHeight = '0';
                wrap.style.verticalAlign = 'middle';

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

            var anchor = link.querySelector('span') || link;

            var navColor = getComputedStyle(link).color || '#cccccc';
            var rgbMatch = navColor.match(/\d+/g) || [200,200,200];
            var luminance = (parseInt(rgbMatch[0],10) + parseInt(rgbMatch[1],10) + parseInt(rgbMatch[2],10)) / 3;
            var markerFilter = luminance >= 128 ? MARKER_FILTER_DARK : MARKER_FILTER_LIGHT;

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

        var themePalette = {
            black: { bg: '#1a1a1a', border: '#555',    text: '#dddddd' },
            gray:  { bg: '#2a2a2a', border: '#555',    text: '#e8e8e8' },
            white: { bg: '#ffffff', border: '#cdcdcd', text: '#222222' },
            azure: { bg: '#fbfdff', border: '#bcd2e8', text: '#1a3149' }
        };

        var unraidTheme = 'black';
        if (window.diskviewerTheme && themePalette[window.diskviewerTheme]) {
            unraidTheme = window.diskviewerTheme;
        } else {
            var bodyClasses = (document.body && document.body.className || '').split(/\s+/);
            for (var ti = 0; ti < bodyClasses.length; ti++) {
                if (themePalette[bodyClasses[ti]]) { unraidTheme = bodyClasses[ti]; break; }
            }
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
            'max-width:360px;';
        document.body.appendChild(diskTooltip);

        function showHeaderTooltip(){
            if (!diskTooltip || !navItem) return;
            var rect = navItem.getBoundingClientRect();

            diskTooltip.style.display = 'block';
            var tipW = diskTooltip.offsetWidth || 220;

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

        navItem.addEventListener('mouseenter', showHeaderTooltip);
        navItem.addEventListener('mouseleave', hideHeaderTooltip);
        navItem.addEventListener('mouseover',  showHeaderTooltip);
        navItem.addEventListener('mouseout',   function(ev){

            if (!navItem.contains(ev.relatedTarget)) hideHeaderTooltip();
        });
        if (link) {
            link.addEventListener('mouseenter', showHeaderTooltip);
            link.addEventListener('mouseover',  showHeaderTooltip);

            link.removeAttribute('title');
        }

        window.diskviewerShowTooltip = showHeaderTooltip;
        window.diskviewerHideTooltip = hideHeaderTooltip;
    }

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

        var cells = '';
        for (var i = 0; i < issues.length; i++) {
            var it    = issues[i] || {};
            var color = sevColor[it.severity] || tooltipText;
            var name  = String(it.name  || '?');

            var ax    = (it.axis === 'errors') ? '' : (axisLabel[it.axis] || String(it.axis || '').toUpperCase());
            var label = String(it.label || '');
            cells +=
                '<span style="font-weight:600;text-align:left;color:' + color + ';">'                                  + escapeHtml(name)  + '</span>' +
                '<span style="opacity:0.9;font-size:10px;font-weight:700;letter-spacing:0.04em;color:' + color + ';">' + escapeHtml(ax)    + '</span>' +
                '<span style="font-weight:700;text-align:right;color:' + color + ';">'                                 + escapeHtml(label) + '</span>';
        }
        diskTooltip.innerHTML =
            '<div style="display:grid;grid-template-columns:auto auto auto;gap:3px 14px;align-items:center;">' + cells + '</div>';
    }

    function escapeHtml(s){
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function applyState(temp, healthSev, util, errorsSev, issues, tempBlink){

        // badge disabled in settings, hide the whole nav item
        if (temp === 'off' || healthSev === 'off' || util === 'off' || errorsSev === 'off') {
            navItem.style.display = 'none';
            return;
        }

        navItem.style.display = '';

        if (thermo) {
            thermo.style.color = SEV_COLOR[temp] || SEV_COLOR.ok;

            // honour the user's reduced-motion setting before starting the pulse
            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var wantBlink = (tempBlink === true) && !reduceMotion;
            if (wantBlink && !thermoBlink && thermo.animate) {
                thermoBlink = thermo.animate(
                    [{ opacity: 1 }, { opacity: 0.2 }, { opacity: 1 }],
                    { duration: 2000, iterations: Infinity, easing: 'ease-in-out' }
                );
            } else if (!wantBlink && thermoBlink) {
                thermoBlink.cancel();
                thermoBlink = null;
                thermo.style.opacity = '1';
            }
        }
        if (pctMark) pctMark.style.color = SEV_COLOR[util] || SEV_COLOR.ok;
        if (health) {

            health.style.color = SEV_COLOR[healthSev] || SEV_COLOR.ok;
            health.innerHTML = (healthSev === 'critical') ? THUMB_DOWN_SVG : THUMB_UP_SVG;
        }

        if (diskIcon) {
            if (errorsSev === 'warning' || errorsSev === 'critical') {
                diskIcon.setAttribute('stroke', SEV_COLOR[errorsSev]);
            } else {
                var iconColor = getComputedStyle(navItem.querySelector('a') || navItem).color || '#ccc';
                diskIcon.setAttribute('stroke', iconColor);
            }
        }

        renderTooltip(issues);
    }

    // 30s poll, repaints the markers from the cached severities
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
                var tb = (r.temp_blink === true);

                var ca = (typeof r.click_action === 'string') ? r.click_action : 'main';
                if (ca !== 'main' && ca !== 'widget' && ca !== 'tool' && ca !== 'settings') ca = 'main';
                window.diskviewerHeaderAction = ca;
                var issues = Array.isArray(r.disk_issues) ? r.disk_issues : [];
                applyState(t, h, u, e, issues, tb);
            } catch(e2){
                navItem.style.display = 'none';
            }
        };
        x.onerror = x.ontimeout = function(){};
        x.send();
    }

    setTimeout(setup, 2000);
})();
