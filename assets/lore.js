/**
 * L.O.R.E. - Leaflet OpenSimulator Regional Explorer
 * https://nerdypappy.com/lore
 * Author: Gundahar Bravin
 * License: GPL v2
 */

var loreInstances = {};

function initLORE(mapId) {
    var container = document.getElementById(mapId);
    if (!container) return;

    var config = {
        zoom:     parseInt(container.dataset.zoom)    || 3,
        centerX:  parseInt(container.dataset.centerX) || 1000,
        centerY:  parseInt(container.dataset.centerY) || 1000,
        gridUrl:  container.dataset.gridUrl           || '',
    };

    // Pull colors + settings from localized PHP data
    var accentColor  = (typeof lore_settings !== 'undefined') ? lore_settings.accent_color  : '#2563eb';
    var buttonColor  = (typeof lore_settings !== 'undefined') ? lore_settings.button_color  : '#7c3aed';
    var registerUrl  = (typeof lore_settings !== 'undefined') ? lore_settings.register_url  : '';
    var gridName     = (typeof lore_settings !== 'undefined') ? lore_settings.grid_name     : 'OpenSimulator Grid';
    var ajaxUrl      = (typeof lore_settings !== 'undefined') ? lore_settings.ajax_url      : '';
    var nonce        = (typeof lore_settings !== 'undefined') ? lore_settings.nonce         : '';

    // ----------------------------------------------------------------
    // Tile layer
    // ----------------------------------------------------------------
    var LORETileLayer = L.TileLayer.extend({
        getTileUrl: function(coords) {
            var z = this._getZoomForUrl();
            var rpt = Math.pow(2, z - 1);
            var x   = coords.x * rpt;
            var y   = (Math.abs(coords.y) - 1) * rpt;
            var base = config.gridUrl || '';
            return base
                .replace('{z}', z)
                .replace('{x}', x)
                .replace('{y}', y);
        }
    });

    // ----------------------------------------------------------------
    // Map init
    // ----------------------------------------------------------------
    var map = L.map(mapId, {
        crs:              L.CRS.Simple,
        center:           [config.centerY, config.centerX],
        zoom:             config.zoom,
        minZoom:          1,
        maxZoom:          8,
        zoomControl:      true,
        attributionControl: true
    });

    map.attributionControl.addAttribution(
        '<a href="https://nerdypappy.com/lore" target="_blank">L.O.R.E.</a>'
    );

    // Tile layer
    new LORETileLayer(config.gridUrl, {
        attribution: gridName,
        maxZoom: 8, minZoom: 1,
        zoomOffset: 1, zoomReverse: true,
        tileSize: 256, errorTileUrl: ''
    }).addTo(map);

    // Store instance
    loreInstances[mapId] = {
        map:     map,
        config:  config,
        regions: []
    };

    // ----------------------------------------------------------------
    // Load regions
    // ----------------------------------------------------------------
    jQuery.ajax({
        url:  ajaxUrl,
        type: 'GET',
        data: { action: 'lore_get_regions', nonce: nonce },
        success: function(r) {
            if (r.success) {
                loreInstances[mapId].regions = r.data;
                // Inject dynamic CSS colors
                injectColors(accentColor, buttonColor);
            }
        }
    });

    // ----------------------------------------------------------------
    // Click handler — fetch region info from DB
    // ----------------------------------------------------------------
    map.on('click', function(e) {
        var x = Math.round(e.latlng.lng);
        var y = Math.round(e.latlng.lat);

        jQuery.ajax({
            url:  ajaxUrl,
            type: 'GET',
            data: { action: 'lore_get_region_info', nonce: nonce, x: x, y: y },
            success: function(r) {
                if (r.success) {
                    showPopup(map, e.latlng, r.data, config, registerUrl, accentColor, buttonColor);
                } else {
                    showUnknown(map, e.latlng, x, y, accentColor);
                }
            },
            error: function() {
                showUnknown(map, e.latlng, x, y, accentColor);
            }
        });
    });

    // ----------------------------------------------------------------
    // Coordinate display (bottom left)
    // ----------------------------------------------------------------
    var coordControl = L.control({ position: 'bottomleft' });
    coordControl.onAdd = function() {
        var div = L.DomUtil.create('div', 'lore-coords');
        div.innerHTML = '<span id="lore-coord-' + mapId + '">Move cursor over map</span>';
        return div;
    };
    coordControl.addTo(map);

    map.on('mousemove', function(e) {
        var el = document.getElementById('lore-coord-' + mapId);
        if (el) el.textContent = 'X: ' + Math.round(e.latlng.lng) + '  Y: ' + Math.round(e.latlng.lat);
    });

    // ----------------------------------------------------------------
    // Search box (top right)
    // ----------------------------------------------------------------
    addSearch(mapId, ajaxUrl, nonce, accentColor, buttonColor, registerUrl, config);
}

/* ------------------------------------------------------------------ */
/*  POPUP BUILDERS                                                      */
/* ------------------------------------------------------------------ */

function showPopup(map, latlng, region, config, registerUrl, accentColor, buttonColor) {
    var gridUrl    = config.gridUrl || '';
    var regionName = region.region_name;
    var serverUri  = region.server_uri || '';
    var rx         = region.region_x;
    var ry         = region.region_y;

    // Build hop:// teleport URL dynamically from grid URL + region name
    var hopUrl = 'hop://' + gridUrl + '/' + encodeURIComponent(regionName) + '/128/128/25';
    if (serverUri) {
        // Use server URI if available for more accurate teleport
        var host = serverUri.replace('http://', '').replace('https://', '').replace(/\/$/, '');
        hopUrl = 'hop://' + host + '/' + encodeURIComponent(regionName) + '/128/128/25';
    }

    var joinBtn = registerUrl
        ? '<a href="' + registerUrl + '" target="_blank" class="lore-btn lore-btn-join">Join Free Today</a>'
        : '';

    var html = '<div class="lore-popup">'
        + '<h3 class="lore-popup-title">' + escHtml(regionName) + '</h3>'
        + '<hr class="lore-popup-hr">'
        + '<a href="' + hopUrl + '" class="lore-btn lore-btn-teleport">Teleport &#x1F50A;</a>'
        + joinBtn
        + '<p class="lore-popup-coords">(' + rx + ', ' + ry + ')</p>'
        + '</div>';

    L.popup({ className: 'lore-popup-wrap', maxWidth: 320 })
        .setLatLng(latlng)
        .setContent(html)
        .openOn(map);
}

function showUnknown(map, latlng, x, y, accentColor) {
    var html = '<div class="lore-popup">'
        + '<h3 class="lore-popup-title lore-unknown">Unknown Region</h3>'
        + '<hr class="lore-popup-hr">'
        + '<p class="lore-popup-note">No region found at these coordinates</p>'
        + '<p class="lore-popup-coords">(' + x + ', ' + y + ')</p>'
        + '</div>';

    L.popup({ className: 'lore-popup-wrap', maxWidth: 300 })
        .setLatLng(latlng)
        .setContent(html)
        .openOn(map);
}

/* ------------------------------------------------------------------ */
/*  SEARCH                                                              */
/* ------------------------------------------------------------------ */

function addSearch(mapId, ajaxUrl, nonce, accentColor, buttonColor, registerUrl, config) {
    var instance = loreInstances[mapId];
    if (!instance) return;

    var searchControl = L.control({ position: 'topright' });
    searchControl.onAdd = function() {
        var div = L.DomUtil.create('div', 'lore-search-control');
        div.innerHTML = '<div class="lore-search-inner">'
            + '<span class="lore-search-icon">&#128269;</span>'
            + '<input type="text" id="lore-search-' + mapId + '" placeholder="Search regions..." autocomplete="off">'
            + '</div>'
            + '<div class="lore-search-results" id="lore-results-' + mapId + '"></div>';
        L.DomEvent.disableClickPropagation(div);
        return div;
    };
    searchControl.addTo(instance.map);

    setTimeout(function() {
        var input    = document.getElementById('lore-search-' + mapId);
        var results  = document.getElementById('lore-results-' + mapId);
        if (!input || !results) return;

        input.addEventListener('input', function() {
            var q = input.value.toLowerCase().trim();
            results.innerHTML = '';
            if (q.length < 2) { results.style.display = 'none'; return; }

            var matches = instance.regions.filter(function(r) {
                return r.region_name.toLowerCase().indexOf(q) !== -1;
            }).slice(0, 10);

            if (!matches.length) { results.style.display = 'none'; return; }

            results.style.display = 'block';
            matches.forEach(function(region) {
                var item = document.createElement('div');
                item.className = 'lore-result-item';
                item.textContent = region.region_name;
                item.onclick = function() {
                    results.style.display = 'none';
                    input.value = '';
                    zoomTo(mapId, region, ajaxUrl, nonce, registerUrl, config, accentColor, buttonColor);
                };
                results.appendChild(item);
            });
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { results.style.display = 'none'; input.value = ''; }
        });

        document.addEventListener('click', function(e) {
            if (!div.contains(e.target)) results.style.display = 'none';
        });
    }, 200);
}

function zoomTo(mapId, region, ajaxUrl, nonce, registerUrl, config, accentColor, buttonColor) {
    var instance = loreInstances[mapId];
    if (!instance) return;

    var rx = parseInt(region.region_x);
    var ry = parseInt(region.region_y);
    if (rx >= 100000) { rx = Math.round(rx / 256); ry = Math.round(ry / 256); }

    instance.map.setView([ry, rx], 6);

    setTimeout(function() {
        var latlng = L.latLng(ry, rx);
        showPopup(instance.map, latlng, region, config, registerUrl, accentColor, buttonColor);
    }, 400);
}

/* ------------------------------------------------------------------ */
/*  DYNAMIC COLOR INJECTION                                             */
/* ------------------------------------------------------------------ */

function injectColors(accent, button) {
    var id = 'lore-dynamic-css';
    if (document.getElementById(id)) return;
    var style = document.createElement('style');
    style.id  = id;
    style.textContent = [
        '.lore-popup-title { color: ' + accent + ' !important; }',
        '.lore-popup-hr    { border-color: ' + accent + '44 !important; }',
        '.lore-popup-coords { color: ' + accent + '99 !important; }',
        '.lore-btn-teleport { background: linear-gradient(135deg, ' + button + ', ' + shadeColor(button, -20) + ') !important; }',
        '.lore-btn-join     { background: linear-gradient(135deg, ' + accent + '22, ' + accent + '44) !important; color: ' + accent + ' !important; border-color: ' + accent + ' !important; }',
        '.lore-search-control { border-color: ' + accent + ' !important; }',
        '.lore-result-item:hover { color: ' + accent + ' !important; }',
        '.lore-coords { color: ' + accent + ' !important; }',
    ].join('\n');
    document.head.appendChild(style);
}

function shadeColor(hex, pct) {
    var n = parseInt(hex.slice(1), 16);
    var r = Math.min(255, Math.max(0, (n >> 16) + pct));
    var g = Math.min(255, Math.max(0, ((n >> 8) & 0xff) + pct));
    var b = Math.min(255, Math.max(0, (n & 0xff) + pct));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

window.initLORE = initLORE;
