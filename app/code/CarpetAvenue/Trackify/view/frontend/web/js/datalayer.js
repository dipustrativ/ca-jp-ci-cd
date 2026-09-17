/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Reads server-rendered GA4 payloads out of the DOM and pushes them into dataLayer.
 *
 * Why JSON blobs and not inline <script> pushes: Productify's layered navigation
 * replaces .column.main with a raw `innerHTML` write (filter-ajax.js), and innerHTML
 * never executes <script> tags — an inline payload would silently stop firing after the
 * first filter. A <script type="application/json"> blob survives that swap as readable
 * DOM, and infinite scroll (IAS) appends whole `div.products.wrapper` nodes, so listing
 * blobs that live inside the wrapper arrive with every page for free.
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 */
define(['Magento_Customer/js/customer-data'], function (customerData) {
    'use strict';

    var BLOB = 'script.trackify-dl:not([data-sent])',
        MAIN = '.column.main',
        TILE = 'li.item.product',
        WRAPPER = '.products.wrapper',
        SEEN_KEY = 'trackify_last_event_id',
        requested = false,
        lastVisitor = null;

    /**
     * @param {Object} payload
     */
    function push(payload) {
        window.dataLayer = window.dataLayer || [];

        // GA4 requires the previous ecommerce object to be cleared, otherwise fields
        // from the last event leak into this one.
        if (payload.ecommerce) {
            window.dataLayer.push({ecommerce: null});
        }
        window.dataLayer.push(payload);
    }

    /**
     * @param {HTMLElement} el
     * @returns {Object|null}
     */
    function parse(el) {
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    /**
     * Storage is unavailable in some privacy modes; never let that break tracking.
     *
     * @param {String} key
     * @param {String} [value]
     * @returns {String|null}
     */
    function store(key, value) {
        try {
            if (value === undefined) {
                return window.sessionStorage.getItem(key);
            }
            window.sessionStorage.setItem(key, value);
        } catch (e) {
            return null;
        }

        return null;
    }

    /**
     * A reloaded success page re-renders the purchase blob. Fire it once per order.
     *
     * @param {Object} payload
     * @returns {Boolean}
     */
    function alreadyPurchased(payload) {
        var id = payload.ecommerce && payload.ecommerce.transaction_id,
            key;

        if (payload.event !== 'purchase' || !id) {
            return false;
        }
        key = 'trackify_purchase_' + id;

        if (store(key) === '1') {
            return true;
        }
        store(key, '1');

        return false;
    }

    /**
     * @param {ParentNode} [root]
     */
    function drain(root) {
        var blobs = (root || document).querySelectorAll(BLOB);

        Array.prototype.forEach.call(blobs, function (el) {
            var payload;

            el.setAttribute('data-sent', '1');
            payload = parse(el);

            if (payload && !alreadyPurchased(payload)) {
                push(payload);
            }
        });
    }

    /**
     * select_item needs no extra markup: the clicked tile's position inside its own
     * wrapper indexes straight into that wrapper's view_item_list payload.
     *
     * @param {Event} event
     */
    function onTileClick(event) {
        var tile = event.target.closest && event.target.closest(TILE),
            wrapper = tile && tile.closest(WRAPPER),
            blob = wrapper && wrapper.querySelector('script.trackify-dl'),
            payload = blob && parse(blob),
            items = payload && payload.ecommerce && payload.ecommerce.items,
            index;

        if (!items) {
            return;
        }
        index = Array.prototype.indexOf.call(wrapper.querySelectorAll(TILE), tile);

        if (index < 0 || !items[index]) {
            return;
        }

        push({
            event: 'select_item',
            ecommerce: {
                item_list_id: payload.ecommerce.item_list_id,
                item_list_name: payload.ecommerce.item_list_name,
                items: [items[index]]
            }
        });
    }

    /**
     * Visitor properties and cart events are the only genuinely per-customer data, so
     * they come through customer-data rather than the cached page. The section is
     * replayed from localStorage on every page load, hence the id high-water mark.
     *
     * @param {Object} data
     */
    function onCustomerData(data) {
        var lastSeen = parseInt(store(SEEN_KEY), 10) || 0,
            highest = lastSeen,
            visitor;

        if (!data) {
            return;
        }

        // Magento only fetches customer sections once something has invalidated them, so
        // a first-time anonymous visitor would otherwise never get visitor_data at all.
        // Ask for it once; from then on it is served from localStorage and only re-fetched
        // when login/logout/a cart action invalidates it (etc/frontend/sections.xml).
        if (!data.visitor) {
            if (!requested) {
                requested = true;
                customerData.reload(['trackify'], false);
            }

            return;
        }

        // customer-data replays the cached section on every page load and fires the
        // subscriber again after each invalidation (a cart action, a login). Push
        // visitor_data only when the values actually changed, so a GTM tag bound to it
        // does not fire twice for one page view.
        visitor = JSON.stringify(data.visitor);

        if (visitor !== lastVisitor) {
            lastVisitor = visitor;
            push(Object.assign({event: 'visitor_data'}, data.visitor));
        }

        (data.events || []).forEach(function (entry) {
            if (entry.id > lastSeen) {
                push(entry.payload);
                highest = Math.max(highest, entry.id);
            }
        });

        if (highest !== lastSeen) {
            store(SEEN_KEY, String(highest));
        }
    }

    return function () {
        var main = document.querySelector(MAIN);

        drain();

        if (main) {
            main.addEventListener('click', onTileClick);

            // Covers layered-nav filtering, sorting and infinite scroll alike, with no
            // coupling to Productify's collectionUpdated event or to IAS internals.
            new MutationObserver(function () {
                drain(main);
            }).observe(main, {childList: true, subtree: true});
        }

        customerData.get('trackify').subscribe(onCustomerData);
        onCustomerData(customerData.get('trackify')());
    };
});
