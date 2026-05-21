/* global DD_Cart, jQuery */
(function ($) {
    'use strict';

    var selectionInProgress = false;
    var selectionTimeoutMs = 12000;
    var packageLabelNeedle = ((DD_Cart && DD_Cart.package_label_needle) || '').toLowerCase();
    var cartRowSelectors = 'tr.cart_item, li.wc-block-cart-items__row, .wc-block-cart-items__row, .wc-block-components-order-summary-item';
    var cartRootSelectors = ['form.woocommerce-cart-form', '.wc-block-cart-items', '.wc-block-cart'];
    var giftRowDebounceMs = 60;
    var blockCartRefreshDebounceMs = 350;
    var giftRowObserver = null;
    var giftRowTimer = null;
    var blockCartSubscribed = false;
    var ddRefreshing = false;
    var blockCartRefreshTimer = null;

    function init() {
        markGiftRows();
        setupGiftRowObserver();

        // Subscribe to WC Blocks data store once so we can refresh the DD section
        // when items are added or removed via the block cart.
        if (DD_Cart.is_block_cart === '1' && !blockCartSubscribed) {
            blockCartSubscribed = true;
            subscribeBlockCartChanges();
        }

        // Classic cart: re-mark gift rows after WC replaces fragments.
        $(document.body).off('wc_fragments_refreshed.dd wc_fragments_loaded.dd wc_cart_emptied.dd')
            .on('wc_fragments_refreshed.dd wc_fragments_loaded.dd wc_cart_emptied.dd', function () {
                markGiftRows();
                setupGiftRowObserver();
            });

        // Přímé balíčky – vzájemné vylučování: zaškrtnutím jednoho se ostatní odškrtnou
        $(document).off('change.dd', '.dd-pkg-checkbox').on('change.dd', '.dd-pkg-checkbox', function () {
            var $this = $(this);
            var type  = $this.data('type') || 'direct';

            if ( type === 'direct' && this.checked ) {
                // Odškrtni všechny ostatní přímé checkboxy kromě tohoto
                $('.dd-pkg-direct').not(this).each(function () {
                    if ( this.checked ) {
                        $(this).prop('checked', false);
                    }
                });
            }

            sendSelection(
                $this.data('package'),
                type,
                this.checked ? 1 : 0
            );
        });

        $(document).off('change.dd', '.dd-pkg-radio').on('change.dd', '.dd-pkg-radio', function () {
            sendSelection($(this).val(), $(this).data('type') || 'direct', 1);
        });
    }

    /**
     * Subscribes to the WooCommerce Blocks data store and refreshes the DD gift
     * section whenever the cart items change (e.g. user removes the DD item via
     * the block cart "×" button).
     */
    function subscribeBlockCartChanges() {
        if (!window.wp || !window.wp.data) return;
        var prevItemKeys = null;
        window.wp.data.subscribe(function () {
            // Guard: only act when the cart store is available.
            if (!window.wp.data.select) return;
            var store = window.wp.data.select('wc/store/cart');
            if (!store || typeof store.getCartData !== 'function') return;
            var items;
            try {
                var data = store.getCartData();
                items = data && data.items ? data.items : null;
            } catch (e) { return; }
            if (!items) return;
            var keys = items.map(function (i) { return i.key || i.id || ''; }).sort().join(',');
            if (prevItemKeys === null) {
                prevItemKeys = keys;
                return;
            }
            if (keys !== prevItemKeys) {
                prevItemKeys = keys;
                if (blockCartRefreshTimer) clearTimeout(blockCartRefreshTimer);
                blockCartRefreshTimer = setTimeout(refreshDDSection, blockCartRefreshDebounceMs);
            }
        });
    }

    /**
     * Fetches fresh DD gift section HTML via AJAX and replaces the current
     * #dd-gift-section element.  Used after the block cart updates.
     */
    function refreshDDSection() {
        if (ddRefreshing) return;
        var existing = document.getElementById('dd-gift-section');
        if (!existing) return;
        ddRefreshing = true;
        var url = DD_Cart.ajax_url + '?action=dd_get_cart_html&nonce=' + encodeURIComponent(DD_Cart.nonce);
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                ddRefreshing = false;
                if (!data.success) return;
                var html = (data.data && data.data.html) ? data.data.html : '';
                var el = document.getElementById('dd-gift-section');
                if (!el) return;
                if (!html) {
                    el.remove();
                    return;
                }
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                var newSection = tmp.querySelector('#dd-gift-section');
                if (newSection) {
                    el.replaceWith(newSection);
                    init();
                }
            })
            .catch(function () {
                ddRefreshing = false;
                console.warn('DD: failed to refresh gift section after cart update.');
            });
    }

    function markGiftRows() {
        var cartRows = document.querySelectorAll(cartRowSelectors);

        cartRows.forEach(function (row) {
            if (row.querySelector('[data-dd-gift-item="1"]')) {
                row.classList.add('dd-cart-item');
                return;
            }

            if (packageLabelNeedle) {
                var rowText = (row.textContent || '').toLowerCase();
                if (rowText.indexOf(packageLabelNeedle) !== -1) {
                    row.classList.add('dd-cart-item');
                }
            }
        });
    }

    function setupGiftRowObserver() {
        var root = findFirstCartRoot();
        if (!root) {
            return;
        }
        if (giftRowObserver) {
            giftRowObserver.disconnect();
        }
        giftRowObserver = new MutationObserver(function () {
            if (giftRowTimer) {
                window.clearTimeout(giftRowTimer);
            }
            giftRowTimer = window.setTimeout(function () {
                markGiftRows();
                giftRowTimer = null;
            }, giftRowDebounceMs);
        });
        giftRowObserver.observe(root, {
            childList: true,
            subtree: true
        });
    }

    function findFirstCartRoot() {
        for (var i = 0; i < cartRootSelectors.length; i += 1) {
            var root = document.querySelector(cartRootSelectors[i]);
            if (root) {
                return root;
            }
        }
        return null;
    }

    function sendSelection(packageId, type, checked) {
        if (selectionInProgress) return;
        selectionInProgress = true;

        $.ajax({
            url: DD_Cart.ajax_url,
            method: 'POST',
            timeout: selectionTimeoutMs,
            data: {
                action:     'dd_select_package',
                nonce:      DD_Cart.nonce,
                package_id: packageId,
                type:       type,
                checked:    checked
            }
        }).done(function (res) {
            if (!res || !res.success) {
                console.error('DD select failed:', {
                    endpoint: DD_Cart.ajax_url + '?action=dd_select_package',
                    packageId: packageId,
                    type: type,
                    checked: checked,
                    message: res && res.data && res.data.message ? res.data.message : null,
                    wcNotices: res && res.data && res.data.wc_notices ? res.data.wc_notices : [],
                    response: res && res.data ? res.data : res
                });
                return;
            }

            console.info('DD select success:', {
                packageId: packageId,
                type: type,
                checked: checked,
                ddCount: res.data && typeof res.data.dd_count !== 'undefined' ? res.data.dd_count : null,
                ddItems: res.data && res.data.dd_items ? res.data.dd_items : [],
                cartSize: res.data && typeof res.data.cart_size !== 'undefined' ? res.data.cart_size : null,
                session: res.data && res.data.session ? res.data.session : null
            });

            if (DD_Cart.is_block_cart === '1') {
                // Update our gift section in the DOM.
                if (res.data && res.data.html) {
                    replaceGiftSection(res.data.html);
                    init(); // Re-bind listeners on fresh HTML.
                }
                // Ask WooCommerce Blocks to re-fetch cart data (totals + items) without
                // reloading the whole page.
                refreshBlockCart();
            } else {
                // Classic shortcode cart: reload the cart table and totals in-place.
                reloadCartSections();
            }
        }).fail(function (xhr, textStatus, errorThrown) {
            console.error('DD select transport failed:', {
                endpoint: DD_Cart.ajax_url + '?action=dd_select_package',
                packageId: packageId,
                type: type,
                checked: checked,
                textStatus: textStatus,
                error: errorThrown || null,
                httpStatus: xhr && typeof xhr.status !== 'undefined' ? xhr.status : null,
                responseText: xhr && typeof xhr.responseText === 'string' ? xhr.responseText : null
            });
        }).always(function () {
            selectionInProgress = false;
        });
    }

    /**
     * Tells WooCommerce Blocks to invalidate its cart data cache so it re-fetches
     * from the Store API.  Falls back to a WC fragment refresh on older setups.
     */
    function refreshBlockCart() {
        if (window.wp && window.wp.data) {
            try {
                window.wp.data
                    .dispatch('wc/store/cart')
                    .invalidateResolutionForStoreSelector('getCartData');
                return;
            } catch (e) {
                console.warn('DD: block cart invalidation failed', e);
            }
        }
        // Fallback: refresh WooCommerce mini-cart fragments.
        $(document.body).trigger('wc_fragment_refresh');
    }

    /**
     * Reloads only the classic cart's item table and totals section via a GET
     * request to the current page, then swaps the relevant DOM nodes.
     */
    function reloadCartSections() {
        $.get(window.location.href, function (html) {
            var parser  = new window.DOMParser();
            var doc     = parser.parseFromString(html, 'text/html');

            var newForm = doc.querySelector('form.woocommerce-cart-form');
            var curForm = document.querySelector('form.woocommerce-cart-form');
            if (newForm && curForm) {
                curForm.replaceWith(newForm);
            }

            var newTotals = doc.querySelector('.cart_totals');
            var curTotals = document.querySelector('.cart_totals');
            if (newTotals && curTotals) {
                curTotals.replaceWith(newTotals);
            }

            // Re-bind DD event listeners on the replaced DOM.
            init();
            $(document.body).trigger('wc_fragment_refresh');
        }).fail(function () {
            // Fallback to the standard WC event.
            $(document.body).trigger('wc_update_cart');
        });
    }

    function replaceGiftSection(html) {
        var existing = document.getElementById('dd-gift-section');
        if (!existing) return;
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var newSection = tmp.querySelector('#dd-gift-section');
        if (newSection) existing.replaceWith(newSection);
    }

    window.DD_Cart_init = init;
    $(function () {
        init();
    });

})(jQuery);
