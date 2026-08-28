define([], function () {
    'use strict';

    var KEY_PREFIX = 'plp_snapshot:';
    var DEFAULT_MAX_AGE_MS = 10 * 60 * 1000;

    var RESTORED_FLAG = 'data-plp-restored';
    var FROM_BFCACHE_FLAG = 'data-from-bfcache';

    var DID_BIND_ONCE = false;
    var DID_RESTORE_ONCE = false;

    var BASE_URL_FOR_PAGING = null;
    var LAST_FORCED_PAGE_REQUESTED = null;

    // ---- NEW: IAS forcing state ----
    var FORCED_PATHNAME = null;        // pathname of restored PLP (scope)
    var FORCED_NEXT_URL = null;        // absolute url to load next
    var NEXT_PAGE_NUM = null;          // numeric next page
    var LOAD_IN_PROGRESS = false;   // prevent double loads / double clicks
    // --------------------------------

    function now() { return Date.now(); }
    function normalizeUrl(url) { try { return decodeURIComponent(url); } catch (e) { return url; } }
    function keyForUrl(url) { return KEY_PREFIX + normalizeUrl(url); }

    function isPlpPage() {
        if (!document.body || !document.body.classList) return false;
        return document.body.classList.contains('catalog-category-view') ||
            document.body.classList.contains('catalogsearch-result-index');
    }

    function selectMain() {
        return document.querySelector('#maincontent') || document.querySelector('.column.main');
    }

    function safeJsonParse(raw) {
        try { return JSON.parse(raw); } catch (e) { return null; }
    }

    function storageGet(url) {
        try { return sessionStorage.getItem(keyForUrl(url)); } catch (e) { return null; }
    }

    function storageSet(url, value) {
        try { sessionStorage.setItem(keyForUrl(url), value); return true; } catch (e) { return false; }
    }

    function storageRemove(url) {
        try { sessionStorage.removeItem(keyForUrl(url)); return true; } catch (e) { return false; }
    }

    function getNavType() {
        var nav = performance.getEntriesByType && performance.getEntriesByType('navigation');
        if (nav && nav[0] && nav[0].type) return nav[0].type;

        if (performance && performance.navigation) {
            if (performance.navigation.type === 2) return 'back_forward';
            if (performance.navigation.type === 1) return 'reload';
            return 'navigate';
        }
        return 'unknown';
    }

    // ---------- URL helpers ----------
    function parseUrl(urlString) {
        try { return new URL(urlString, location.origin); } catch (e) { return null; }
    }

    function getPageFromUrl(urlString) {
        try {
            var u = new URL(urlString, location.origin);
            var p = parseInt(u.searchParams.get('p') || '1', 10);
            return (isNaN(p) || p < 1) ? 1 : p;
        } catch (e) {
            return 1;
        }
    }

    function buildHrefForPage(urlString, pageNum) {
        try {
            var u = new URL(urlString, location.origin);
            u.searchParams.set('p', String(pageNum));
            u.searchParams.set('ajaxscroll', '1'); // keep if required
            u.searchParams.delete('_');
            return u.toString();
        } catch (e) {
            return null;
        }
    }

    function buildNextHrefFromUrl(urlString) {
        var p = getPageFromUrl(urlString);
        return buildHrefForPage(urlString, p + 1);
    }

    function samePlpPath(urlString) {
        var u = parseUrl(urlString);
        if (!u) return false;
        return !!FORCED_PATHNAME && u.pathname === FORCED_PATHNAME;
    }

    function setNextPageHref(href) {
        if (!href) return;

        var el =
            document.querySelector('.toolbar .pages a.next') ||
            document.querySelector('.toolbar .pages .next a') ||
            document.querySelector('.pages-item-next a') ||
            document.querySelector('a.next');

        if (el) el.setAttribute('href', href);
    }

    // ---------- IAS TriggerExtension patch (fix p=2 + double click + duplicate page) ----------
    function applyForcedNextToIasInstance(iasInstance) {
        if (!iasInstance || !FORCED_NEXT_URL) return;

        try {
            // Patch nextUrl() if present
            if (typeof iasInstance.nextUrl === 'function' && !iasInstance.__plp_nextUrl_patched) {
                iasInstance.__plp_nextUrl_patched = true;
                var _nextUrl = iasInstance.nextUrl;
                iasInstance.nextUrl = function () {
                    var original = _nextUrl.apply(this, arguments);
                    if (original && samePlpPath(original) && FORCED_NEXT_URL) return FORCED_NEXT_URL;
                    return original;
                };
            }

            // Patch string properties if present
            if (typeof iasInstance.nextUrl === 'string' && samePlpPath(iasInstance.nextUrl) && FORCED_NEXT_URL) {
                iasInstance.nextUrl = FORCED_NEXT_URL;
            }

            if (iasInstance.options) {
                if (typeof iasInstance.options.next === 'string' && samePlpPath(iasInstance.options.next) && FORCED_NEXT_URL) {
                    iasInstance.options.next = FORCED_NEXT_URL;
                }
                if (typeof iasInstance.options.nextUrl === 'string' && samePlpPath(iasInstance.options.nextUrl) && FORCED_NEXT_URL) {
                    iasInstance.options.nextUrl = FORCED_NEXT_URL;
                }
            }
        } catch (e) {}
    }

    function patchIASTriggerExtensionOnce() {
        if (window.__plpPatchedIASTriggerExtension) return true;

        var Original = window.IASTriggerExtension;
        if (typeof Original !== 'function') return false;

        window.__plpPatchedIASTriggerExtension = true;

        window.IASTriggerExtension = function (options) {
            var ext = new Original(options);

            // Guard: avoid re-showing trigger while load is happening
            if (ext && typeof ext.showTriggerNext === 'function') {
                var _showTriggerNext = ext.showTriggerNext;
                ext.showTriggerNext = function () {
                    // If we're loading, don't create another trigger
                    if (LOAD_IN_PROGRESS) return false;

                    // Before trigger creation, force IAS next url
                    try {
                        if (this.ias && FORCED_NEXT_URL) {
                            applyForcedNextToIasInstance(this.ias);
                        }
                    } catch (e) {}

                    return _showTriggerNext.apply(this, arguments);
                };
            }

            // Patch createTrigger so click always uses latest forced URL and single-flight
            if (ext && typeof ext.createTrigger === 'function') {
                var _createTrigger = ext.createTrigger;
                ext.createTrigger = function (clickCallback, html) {
                    var $trigger = _createTrigger.apply(this, arguments);
                    try {
                        var self = this;

                        // Replace click handler with our guarded handler
                        $trigger.off('click.__plp');
                        $trigger.on('click.__plp', function (ev) {
                            ev.preventDefault();

                            // Single-flight: ignore double click / repeated binding issues
                            if (LOAD_IN_PROGRESS) return false;
                            LOAD_IN_PROGRESS = true;

                            // Force IAS to the latest next URL at click time
                            try {
                                if (self.ias && FORCED_NEXT_URL) {
                                    applyForcedNextToIasInstance(self.ias);
                                }
                            } catch (e) {}

                            // Call original IAS callback
                            try {
                                LAST_FORCED_PAGE_REQUESTED = NEXT_PAGE_NUM;

                                NEXT_PAGE_NUM = NEXT_PAGE_NUM + 1;
                                FORCED_NEXT_URL = buildHrefForPage(location.href, NEXT_PAGE_NUM); // OR use restoreUrl if you prefer
                                setNextPageHref(FORCED_NEXT_URL);

                                var ret = clickCallback.call(self);

                                setTimeout(function () { LOAD_IN_PROGRESS = false; }, 2500);

                                return ret;

                            } catch (e) {
                                LOAD_IN_PROGRESS = false;
                                return false;
                            }
                        });
                    } catch (e) {}

                    return $trigger;
                };
            }

            return ext;
        };

        // Preserve static props (defensive)
        try {
            for (var k in Original) {
                if (Object.prototype.hasOwnProperty.call(Original, k)) {
                    window.IASTriggerExtension[k] = Original[k];
                }
            }
        } catch (e) {}

        return true;
    }

    function waitAndPatchIASTriggerExtension() {
        var tries = 0;
        (function tick() {
            if (patchIASTriggerExtensionOnce()) return;
            if (++tries > 60) return; // ~6s
            setTimeout(tick, 100);
        })();
    }

    // ---------- IAS reset + init ----------
    function resetIasDomArtifacts($) {
        $('.ias-trigger, .ias-load-more, .ias-spinner, .ias-noneleft, .ias-pagination').remove();
    }

    function rebindAndReinitShowMore(main, restoreUrl) {
        if (!window.require) {
            try { main.dispatchEvent(new CustomEvent('contentUpdated', { bubbles: true })); } catch (e) {}
            return;
        }

        require(['mage/apply/main', 'jquery'], function (mage, $) {
            waitAndPatchIASTriggerExtension();

            try { main.dispatchEvent(new CustomEvent('contentUpdated', { bubbles: true })); } catch (e) {}
            mage.apply();

            // HARD RESET IAS DOM
            resetIasDomArtifacts($);

            // ===== FIX: Ensure next button points to correct page =====
            setNextPageHref(FORCED_NEXT_URL || buildNextHrefFromUrl(restoreUrl || location.href));

            // ===== FIX: Remove any previous button that might appear =====
            $('.ias-trigger-prev').remove();

            $(document).off('contentUpdated.__plpUnlock').on('contentUpdated.__plpUnlock', function () {
                LOAD_IN_PROGRESS = false;
            });

            try { main.dispatchEvent(new CustomEvent('contentUpdated', { bubbles: true })); } catch (e) {}
        });
    }

    // ---------- controller ----------
    function createController(options) {
        var maxAgeMs = DEFAULT_MAX_AGE_MS;

        if (options && typeof options.maxAgeSeconds !== 'undefined') {
            var sec = parseInt(options.maxAgeSeconds, 10);
            if (!isNaN(sec) && sec >= 0) maxAgeMs = sec * 1000;
        }

        function capture() {
            if (!isPlpPage()) return false;

            var main = selectMain();
            if (!main) return false;

            var payload = {
                ts: now(),
                url: location.href,
                scrollY: window.scrollY || 0,
                mainHtml: main.innerHTML
            };

            try {
                return storageSet(location.href, JSON.stringify(payload));
            } catch (e) {
                return false;
            }
        }


        function restore() {
            if (DID_RESTORE_ONCE) return false;
            DID_RESTORE_ONCE = true;

            if (!isPlpPage()) return false;

            var raw = storageGet(location.href);
            if (!raw) return false;

            var payload = safeJsonParse(raw);
            if (!payload || !payload.ts) return false;
            if ((now() - payload.ts) > maxAgeMs) return false;

            var main = selectMain();
            if (!main) return false;

            // Restore HTML snapshot
            main.innerHTML = payload.mainHtml;
            document.documentElement.setAttribute(RESTORED_FLAG, '1');

            // ===== SIMPLE FIX: Remove snapshot immediately =====
            storageRemove(location.href);

            // Verify it's gone
            if (storageGet(location.href)) {
                console.warn('⚠️ Snapshot still exists, removing again');
                storageRemove(location.href);
            }

            // -----------------------------
            //  Establish paging base once
            // -----------------------------
            var restoreUrl = payload.url || location.href;

            // Base URL used for ALL future paging (filters/sort preserved)
            BASE_URL_FOR_PAGING = restoreUrl;

            // Scope IAS forcing to this category only
            var u = parseUrl(restoreUrl);
            FORCED_PATHNAME = u ? u.pathname : null;

            // Compute next page exactly once
            // Example: restored at p=5 → next should be p=6
            NEXT_PAGE_NUM = getPageFromUrl(restoreUrl) + 1;

            // Build forced next URL
            // FORCED_NEXT_URL = buildHrefForPage(BASE_URL_FOR_PAGING, NEXT_PAGE_NUM);
            FORCED_NEXT_URL = buildHrefForPage(BASE_URL_FOR_PAGING || location.href, NEXT_PAGE_NUM);


            // Sync toolbar "next" immediately (some IAS builds read this)
            setNextPageHref(FORCED_NEXT_URL);

            // Ensure IAS patch is ready BEFORE re-init
            waitAndPatchIASTriggerExtension();

            requestAnimationFrame(function () {
                // Restore scroll FIRST (user is near show-more)
                window.scrollTo(0, payload.scrollY || 0);

                // Rebind + re-init show-more / IAS safely
                rebindAndReinitShowMore(main, restoreUrl);

                // Wait a moment for DOM to settle, then reload images
                setTimeout(function() {
                    reloadUnloadedImages();
                }, 500);
            });

            return true;
        }

        function reloadUnloadedImages() {
            // Only run on category/search pages
            if (!isPlpPage()) return;

            var reloadedCount = 0;
            var unloadedImages = [];

            // Find all lazy-loaded images
            var allImages = document.querySelectorAll('img[data-original]');

            // Check each image - ONLY ADD IF ACTUALLY UNLOADED
            allImages.forEach(function(img) {
                var isLoaded = img.complete && img.naturalHeight > 0;
                var currentSrc = img.src;
                var realSrc = img.getAttribute('data-original');

                // Check if image is actually unloaded
                var needsReload = false;

                // Condition 1: Not complete or zero height (failed to load)
                if (!img.complete || img.naturalHeight === 0) {
                    needsReload = true;
                }

                // Condition 2: Still using placeholder
                if (currentSrc.includes('lazyload_thumb') || currentSrc.includes('placeholder')) {
                    needsReload = true;
                }

                // Condition 3: Image dimensions are zero (failed load)
                if (img.naturalWidth === 0 || img.naturalHeight === 0) {
                    needsReload = true;
                }

                // Condition 4: Current src is different from real src (already loading real image)
                /*if (currentSrc === realSrc || currentSrc.includes('?t=')) {
                    // Already has real image or reloaded, don't add again
                    needsReload = false;
                }*/

                if (needsReload) {
                    unloadedImages.push(img);
                }
            });

            if (unloadedImages.length === 0) {
                return;
            }

            // Reload only unloaded images
            unloadedImages.forEach(function(img, index) {
                var realSrc = img.getAttribute('data-original');
                // var newSrc = realSrc + '?t=' + Date.now() + index;
                var newSrc = realSrc;

                // Reload image
                img.src = newSrc;
                reloadedCount++;

            });
        }

        // Helper function to remove borders after testing
        function removeTestBorders() {
            document.querySelectorAll('img[style*="border: 5px solid yellow"]').forEach(function(img) {
                img.style.border = '';
                img.style.boxSizing = '';
            });
        }

        function init() {
            if (DID_BIND_ONCE) return;
            DID_BIND_ONCE = true;

            // Patch early
            waitAndPatchIASTriggerExtension();

            window.addEventListener('pageshow', function (e) {
                if (e.persisted) {
                    document.documentElement.setAttribute(FROM_BFCACHE_FLAG, '1');

                    // When page restored from BFCache, check and reload images
                    setTimeout(function() {
                        reloadUnloadedImages();
                    }, 500);

                    return;
                }
                if (getNavType() === 'back_forward') {
                    restore();
                }
            });

            window.addEventListener('pagehide', function () {
                capture();
            });

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') capture();
            });

            if (getNavType() === 'back_forward') {
                restore();
            }
        }

        return { init: init };
    }

    return function (config) {
        createController(config || {}).init();
    };
});
