/* global DD_Cart, jQuery */
(function ($) {
    'use strict';

    var selectionInProgress = false;

    function init() {
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

            if (DD_Cart.is_block_cart === '1') {
                // Aktualizuj naši sekci v DOM
                if (res.data && res.data.html) {
                    replaceGiftSection(res.data.html);
                }
                // Triggeruj přepočet blokového košíku
                triggerBlockCartRefresh();
            } else {
                $(document.body).trigger('wc_update_cart');
            }
        }).fail(function () {
            selectionInProgress = false;
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

    function triggerBlockCartRefresh() {
        var refreshed = false;

        // WooCommerce blocks používá vlastní store (@woocommerce/block-data)
        if (window.wp && window.wp.data) {
            try {
                var cartStore = window.wp.data.dispatch('wc/store/cart');
                if (cartStore && typeof cartStore.invalidateResolutionForStore === 'function') {
                    cartStore.invalidateResolutionForStore();
                    refreshed = true;
                }
            } catch (e) {}
        }

        // Fallback pro případy, kdy Store API invalidate není dostupné
        if (!refreshed) {
            console.warn('DD block cart refresh: Store API invalidation unavailable, reloading page as fallback');
            window.setTimeout(function () {
                window.location.reload();
            }, 250);
        }
    }

    window.DD_Cart_init = init;
    $(function () { init(); });

})(jQuery);
