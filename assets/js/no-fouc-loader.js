/**
 * CRITICAL FIX: Prevent CSS Flash (FOUC)
 * 
 * Problem: CSS loaded via document.write() causes flash of unstyled content
 * Solution: Create link elements and append to head synchronously
 * 
 * Usage in HTML files:
 * 1. Include this script BEFORE closing </head>
 * 2. Or copy the inline version into your <head>
 */

(function () {
    'use strict';

    // Auto-detect environment
    var host = window.location.hostname;
    var path = window.location.pathname;
    var isLocal = (host === 'localhost' || host === '127.0.0.1') && path.includes('/autobot/');
    var BASE = isLocal ? '/autobot' : '';

    // Version for cache control (update when CSS changes)
    var VERSION = '1.0.1';

    /**
     * Load CSS synchronously to prevent FOUC
     */
    function loadCSS(href) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        // Insert at top of head for higher priority
        if (document.head.firstChild) {
            document.head.insertBefore(link, document.head.firstChild);
        } else {
            document.head.appendChild(link);
        }
    }

    /**
     * Load JavaScript
     */
    function loadJS(src) {
        var script = document.createElement('script');
        script.src = src;
        document.head.appendChild(script);
    }

    // Load critical CSS files immediately
    loadCSS(BASE + '/assets/css/style.css?v=' + VERSION);
    loadCSS(BASE + '/assets/css/responsive-fixes.css?v=' + VERSION);

    // Load path-config.js for API endpoints
    loadJS(BASE + '/assets/js/path-config.js?v=' + VERSION);

})();
