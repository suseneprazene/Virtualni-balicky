/* global DD, jQuery */
(function ($) {
    'use strict';

    const ajax = (action, data, cb) =>
        $.post(DD.ajax_url, { action, nonce: DD.nonce, ...data }, cb).fail(() => alert(DD.strings.error));

    // ── Nový balíček ──────────────────────────────────────────────────────────
    $('#dd-new-package-btn').on('click', () => openDetail(0, '', '', 15, 0));

    // ── Upravit balíček ───────────────────────────────────────────────────────
    $(document).on('click', '.dd-edit-pkg', function () {
        const btn = $(this);
        openDetail(btn.data('id'), btn.data('name'), btn.data('desc'), btn.data('price'), btn.data('first-free') || 0);
        // Nejprve vyrenderuj strom, pak načti zaškrtnuté hodnoty
        renderCategoryTree(function () {
            loadRules(btn.data('id'));
        });
        loadDocuments(btn.data('id'));
    });

    // ── Zavřít ────────────────────────────────────────────────────────────────
    $('#dd-close-detail').on('click', () => $('#dd-detail-panel').hide());

    // ── Uložit balíček ────────────────────────────────────────────────────────
    $('#dd-save-package-btn').on('click', function () {
        const btn   = $(this);
        const id         = $('#dd-pkg-id').val();
        const name       = $('#dd-pkg-name').val().trim();
        const desc       = $('#dd-pkg-desc').val().trim();
        const price      = $('#dd-pkg-price').val();
        const first_free = $('#dd-pkg-first-free').is(':checked') ? 1 : 0;
        if (!name) { alert('Název je povinný.'); return; }

        btn.text(DD.strings.saving).prop('disabled', true);
        ajax('dd_save_package', { id, name, description: desc, price, first_free }, res => {
            btn.text('Uložit balíček').prop('disabled', false);
            if (!res.success) { alert(res.data || DD.strings.error); return; }
            const pkg = res.data;
            $('#dd-pkg-id').val(pkg.id);
            $('#dd-documents-section, #dd-rules-section').show();
            $('#dd-detail-title').text(pkg.name);

            const existing = $(`.dd-pkg-row[data-id="${pkg.id}"]`);
            const priceText = parseFloat(pkg.price) > 0 ? pkg.price + ' Kč' : 'zdarma';
            if (existing.length) {
                existing.find('.dd-pkg-name').text(pkg.name);
                existing.find('.dd-pkg-meta').html(priceText);
                existing.find('.dd-edit-pkg')
                    .data('name', pkg.name)
                    .data('desc', pkg.description || '')
                    .data('price', pkg.price)
                    .data('first-free', pkg.first_free);
            } else {
                const row = `<div class="dd-pkg-row dd-active" data-id="${pkg.id}">
                    <div class="dd-pkg-info">
                        <strong class="dd-pkg-name">${escHtml(pkg.name)}</strong>
                        <span class="dd-pkg-meta">${escHtml(priceText)} &bull; 0 dokumentů</span>
                    </div>
                    <div class="dd-pkg-actions">
                        <label class="dd-toggle"><input type="checkbox" class="dd-toggle-active" checked data-id="${pkg.id}"><span class="dd-toggle-slider"></span></label>
                        <button class="button dd-edit-pkg" data-id="${pkg.id}" data-name="${escHtml(pkg.name)}" data-desc="${escHtml(pkg.description||'')}" data-price="${pkg.price}" data-first-free="${pkg.first_free}">Upravit</button>
                        <button class="button button-link-delete dd-delete-pkg" data-id="${pkg.id}">🗑</button>
                    </div>
                </div>`;
                $('#dd-package-list .dd-empty').remove();
                $('#dd-package-list').prepend(row);
            }
            showNotice('success', 'Balíček byl uložen.');

            // Pokud je nový, zobraz sekce a vyrenderuj strom
            if (!id || id === '0') {
                renderCategoryTree();
            }
        });
    });

    // ── Smazat balíček ────────────────────────────────────────────────────────
    $(document).on('click', '.dd-delete-pkg', function () {
        if (!confirm(DD.strings.confirm_delete_package)) return;
        const id  = $(this).data('id');
        const row = $(this).closest('.dd-pkg-row');
        ajax('dd_delete_package', { id }, res => {
            if (!res.success) { alert(DD.strings.error); return; }
            row.fadeOut(300, function () { $(this).remove(); });
            if ($('#dd-pkg-id').val() == id) $('#dd-detail-panel').hide();
            showNotice('success', 'Balíček smazán.');
        });
    });

    // ── Toggle aktivní ────────────────────────────────────────────────────────
    $(document).on('change', '.dd-toggle-active', function () {
        const id = $(this).data('id'), active = this.checked ? 1 : 0;
        $(this).closest('.dd-pkg-row').toggleClass('dd-active', !!active).toggleClass('dd-inactive', !active);
        ajax('dd_toggle_package', { id, active }, () => {});
    });

    // ── Nahrání dokumentu ─────────────────────────────────────────────────────
    $('#dd-upload-btn').on('click', function () {
        const file = $('#dd-doc-file')[0].files[0];
        const packageId = parseInt( $('#dd-pkg-id').val(), 10 );
        if (!file) { alert('Vyberte soubor.'); return; }
        if (!packageId || packageId <= 0) {
            // Balíček ještě nebyl uložen – zkus ho uložit automaticky
            const name = $('#dd-pkg-name').val().trim();
            if (!name) { alert('Nejdřív vyplňte a uložte název balíčku.'); return; }
            alert('Balíček ještě nebyl uložen. Klikněte nejdřív na „Uložit balíček".');
            return;
        }

        const progress = $('#dd-upload-progress').text(DD.strings.uploading).show();
        const self = $(this).prop('disabled', true);

        const fd = new FormData();
        fd.append('action', 'dd_upload_document');
        fd.append('nonce', DD.nonce);
        fd.append('package_id', packageId);
        fd.append('doc_name', $('#dd-doc-name').val().trim() || file.name);
        fd.append('file', file);

        $.ajax({ url: DD.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
            success(res) {
                progress.hide(); self.prop('disabled', false);
                if (!res.success) { alert(res.data || DD.strings.error); return; }
                appendDocRow(res.data);
                $('#dd-doc-name').val(''); $('#dd-doc-file').val('');
                updateDocCount(1);
                showNotice('success', `Dokument „${res.data.name}" nahrán.`);
            },
            error() { progress.hide(); self.prop('disabled', false); alert(DD.strings.error); }
        });
    });

    // ── Smazat dokument ───────────────────────────────────────────────────────
    $(document).on('click', '.dd-delete-doc', function () {
        if (!confirm(DD.strings.confirm_delete_document)) return;
        const id = $(this).data('id'), row = $(this).closest('.dd-doc-row');
        ajax('dd_delete_document', { id }, res => {
            if (!res.success) { alert(DD.strings.error); return; }
            row.fadeOut(200, function () { $(this).remove(); });
            updateDocCount(-1);
        });
    });

    // ══════════════════════════════════════════════════════════════════════════
    // ── Pravidla: kategorie + produkty ────────────────────────────────────────
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Vyrenderuje strom kategorií do #dd-category-tree.
     * Po dokončení zavolá volitelný callback (používáme pro loadRules).
     */
    function renderCategoryTree(callback) {
        const cats = DD.categories || [];
        const tree = $('#dd-category-tree').empty();

        if (!cats.length) {
            tree.html('<em>Žádné kategorie produktů.</em>');
            if (callback) callback();
            return;
        }

        // Seskup podle rodiče
        const byParent = {};
        cats.forEach(c => {
            const p = c.parent || 0;
            if (!byParent[p]) byParent[p] = [];
            byParent[p].push(c);
        });

        function buildLevel(parentId, depth) {
            const children = byParent[parentId] || [];
            if (!children.length) return $();
            const ul = $('<ul class="dd-cat-tree">').css('padding-left', depth * 16 + 'px');
            children.forEach(c => {
                const li = $('<li>');
                const label = $('<label>').append(
                    $('<input type="checkbox" class="dd-cat-cb">').val(c.id),
                    $('<span>').text(' ' + c.name)
                );
                li.append(label);
                const sub = buildLevel(c.id, depth + 1);
                if (sub.length) li.append(sub);
                ul.append(li);
            });
            return ul;
        }

        tree.append(buildLevel(0, 0));
        if (callback) callback();
    }

    // Vyhledávání produktů
    let searchTimer;
    $(document).on('input', '#dd-product-search', function () {
        clearTimeout(searchTimer);
        const term = $(this).val().trim();
        const results = $('#dd-product-results').empty();
        if (term.length < 2) return;

        searchTimer = setTimeout(() => {
            ajax('dd_search_products', { term }, res => {
                if (!res.success || !res.data.length) {
                    results.html('<div class="dd-prod-noresult">Nic nenalezeno.</div>');
                    return;
                }
                res.data.forEach(p => {
                    if ($(`#dd-added-products .dd-prod-tag[data-id="${p.id}"]`).length) return;
                    results.append(
                        $('<div class="dd-prod-result">').text(p.name)
                            .data('id', p.id).data('name', p.name)
                    );
                });
            });
        }, 300);
    });

    $(document).on('click', '.dd-prod-result', function () {
        const id   = $(this).data('id');
        const name = $(this).data('name');
        if ($(`#dd-added-products .dd-prod-tag[data-id="${id}"]`).length) return;
        $('#dd-added-products').append(
            $('<span class="dd-prod-tag">').attr('data-id', id).append(
                document.createTextNode(name + ' '),
                $('<button class="dd-remove-prod" type="button">×</button>').data('id', id)
            )
        );
        $('#dd-product-search').val('');
        $('#dd-product-results').empty();
    });

    $(document).on('click', '.dd-remove-prod', function () {
        $(this).closest('.dd-prod-tag').remove();
    });

    // Uložit pravidla
    $('#dd-save-rules-btn').on('click', function () {
        const packageId = $('#dd-pkg-id').val();
        if (!packageId || packageId === '0') { alert('Nejdřív uložte balíček.'); return; }

        const catIds  = $('.dd-cat-cb:checked').map((_, el) => el.value).get();
        const prodIds = $('#dd-added-products .dd-prod-tag').map((_, el) => $(el).data('id')).get();

        ajax('dd_save_rules', { package_id: packageId, category_ids: catIds, product_ids: prodIds }, res => {
            if (!res.success) { alert(DD.strings.error); return; }
            const summary = [];
            if (!catIds.length && !prodIds.length) summary.push(DD.strings.no_restriction);
            if (catIds.length)  summary.push(catIds.length + ' kategorie');
            if (prodIds.length) summary.push(prodIds.length + ' produkty');
            showNotice('success', 'Pravidla uložena: ' + summary.join(', ') + '.');
        });
    });

    /**
     * Načte uložená pravidla pro balíček a zaškrtne/přidá je do UI.
     * Volat až PO renderCategoryTree().
     */
    function loadRules(packageId) {
        // Reset
        $('.dd-cat-cb').prop('checked', false);
        $('#dd-added-products').empty();
        $('#dd-product-search').val('');
        $('#dd-product-results').empty();

        ajax('dd_get_rules', { package_id: packageId }, res => {
            if (!res.success) return;

            // Zaškrtni kategorie – DOM už existuje (renderCategoryTree byl volán dřív)
            (res.data.category_ids || []).forEach(id => {
                $(`.dd-cat-cb[value="${id}"]`).prop('checked', true);
            });

            // Přidej produkty jako tagy
            (res.data.products || []).forEach(p => {
                $('#dd-added-products').append(
                    $('<span class="dd-prod-tag">').attr('data-id', p.id).append(
                        document.createTextNode(p.name + ' '),
                        $('<button class="dd-remove-prod" type="button">×</button>').data('id', p.id)
                    )
                );
            });
        });
    }

    // ── Statistiky ────────────────────────────────────────────────────────────
    $(document).on('click', '.dd-load-stats', function () {
        const btn = $(this), pkgId = btn.data('package');
        const wrap = $(`#dd-stats-table-${pkgId}`), tbody = wrap.find('.dd-stats-tbody');
        if (wrap.is(':visible')) { wrap.hide(); btn.text('Zobrazit historii odeslání'); return; }

        ajax('dd_get_stats', { package_id: pkgId }, res => {
            tbody.empty();
            if (!res.success || !res.data.length) {
                tbody.append('<tr><td colspan="4">Žádná odeslání.</td></tr>');
            } else {
                res.data.forEach(row => {
                    tbody.append(`<tr>
                        <td>${escHtml(row.user_email)}</td>
                        <td>${escHtml(row.doc_name || '—')}</td>
                        <td><a href="post.php?post=${row.order_id}&action=edit" target="_blank">#${row.order_id}</a></td>
                        <td>${escHtml(row.sent_at)}</td>
                    </tr>`);
                });
            }
            wrap.show(); btn.text('Skrýt historii');
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────────

    function openDetail(id, name, desc, price, firstFree) {
        $('#dd-pkg-id').val(id);
        $('#dd-pkg-name').val(name);
        $('#dd-pkg-desc').val(desc);
        $('#dd-pkg-price').val(price);
        $('#dd-pkg-first-free').prop('checked', !!parseInt(firstFree));
        $('#dd-detail-title').text(id ? name : 'Nový balíček');
        const isExisting = id > 0;
        $('#dd-documents-section, #dd-rules-section').toggle(isExisting);
        $('#dd-document-list').empty();
        $('#dd-detail-panel').show();
        $('#dd-pkg-name').focus();
        // Reset pravidel vizuálně
        $('.dd-cat-cb').prop('checked', false);
        $('#dd-added-products, #dd-product-results').empty();
        $('#dd-product-search, #dd-category-tree').val && $('#dd-product-search').val('');
    }

    function loadDocuments(packageId) {
        ajax('dd_get_documents', { package_id: packageId }, res => {
            if (!res || !res.success) return;
            $('#dd-document-list').empty();
            res.data.forEach(doc => appendDocRow(doc));
            $('#dd-doc-count').text(res.data.length);
        });
    }

    function appendDocRow(doc) {
        const icon = mimeIcon(doc.file_type);
        $('#dd-document-list').append(`<div class="dd-doc-row" data-id="${doc.id}">
            <span class="dd-doc-icon">${icon}</span>
            <span class="dd-doc-name">${escHtml(doc.name)}</span>
            <span class="dd-doc-size">${doc.size || ''}</span>
            <button class="button button-link-delete dd-delete-doc" data-id="${doc.id}" title="Smazat">🗑</button>
        </div>`);
    }

    function updateDocCount(delta) {
        const badge = $('#dd-doc-count');
        badge.text(Math.max(0, parseInt(badge.text() || '0') + delta));
    }

    function mimeIcon(mime) {
        if (!mime) return '📄';
        if (mime.includes('pdf'))   return '📕';
        if (mime.includes('image')) return '🖼️';
        if (mime.includes('zip'))   return '📦';
        if (mime.includes('epub'))  return '📖';
        if (mime.includes('word'))  return '📝';
        return '📄';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showNotice(type, msg) {
        const cls = type === 'success' ? 'notice-success' : 'notice-error';
        const el  = $(`<div class="notice ${cls} is-dismissible"><p></p></div>`);
        el.find('p').text(msg);
        $('#dd-notices').html(el);
        setTimeout(() => el.fadeOut(), 4000);
    }

})(jQuery);
