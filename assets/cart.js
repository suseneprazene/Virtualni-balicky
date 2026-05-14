/* global DD_Cart, jQuery */
(function ($) {
    'use strict';

    var selectionInProgress = false;
    var packageIconFilename = 'package-icon.svg';
    var packageLabelNeedle = ((DD_Cart && DD_Cart.package_label_needle) || '').toLowerCase();
    var cartRowSelectors = 'tr.cart_item, li.wc-block-cart-items__row, .wc-block-cart-items__row, .wc-block-components-order-summary-item';
    var cartRootSelectors = ['form.woocommerce-cart-form', '.wc-block-cart-items', '.wc-block-cart'];
    var giftRowDebounceMs = 60;
    var giftRowObserver = null;
    var giftRowTimer = null;

    function init() {
        markGiftRows();
        setupGiftRowObserver();

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

    function markGiftRows() {
        var cartRows = document.querySelectorAll(cartRowSelectors);

        cartRows.forEach(function (row) {
            if (row.querySelector('[data-dd-gift-item="1"]')) {
                row.classList.add('dd-cart-item');
                return;
            }

            var image = row.querySelector('img');
            var src = image ? (image.getAttribute('src') || '') : '';
            var path = '';
            if (src) {
                try {
                    path = new URL(src, window.location.origin).pathname || '';
                } catch (e) {
                    path = src;
                }
            }
            if (path.endsWith('/' + packageIconFilename) || path === packageIconFilename) {
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

        $.post(DD_Cart.ajax_url, {
            action:     'dd_select_package',
            nonce:      DD_Cart.nonce,
            package_id: packageId,
            type:       type,
            checked:    checked,
        }, function (res) {
            selectionInProgress = false;

            if (!res || !res.success) {
                console.error('DD select failed:', {
                    endpoint: DD_Cart.ajax_url + '?action=dd_select_package',
                    packageId: packageId,
                    type: type,
                    checked: checked,
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
        }).fail(function () {
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
