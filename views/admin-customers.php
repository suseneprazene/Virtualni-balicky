<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap dd-admin">
    <h1>👤 <?php _e( 'Zákazníci – přehled dárků', 'virtualni-balicek' ); ?></h1>

    <div class="dd-customer-search-wrap">
        <p><?php _e( 'Zadej e-mail zákazníka a uvidíš, které dárky již obdržel a kolik mu zbývá.', 'virtualni-balicek' ); ?></p>
        <div style="display:flex;gap:.5em;align-items:center;flex-wrap:wrap;">
            <input type="email" id="dd-customer-email" class="regular-text"
                   placeholder="zakaznik@email.cz"
                   value="<?php echo esc_attr( $_GET['email'] ?? '' ); ?>">
            <button class="button button-primary" id="dd-customer-search-btn">
                🔍 <?php _e( 'Vyhledat', 'virtualni-balicek' ); ?>
            </button>
        </div>
    </div>

    <div id="dd-customer-result" style="margin-top:1.5em"></div>
</div>

<script>
(function($){
    var nonce = '<?php echo wp_create_nonce('dd_admin_nonce'); ?>';

    // Pokud je email v URL, spusť hned
    var initEmail = $('#dd-customer-email').val();
    if (initEmail) search(initEmail);

    $('#dd-customer-search-btn').on('click', function(){
        search($('#dd-customer-email').val().trim());
    });
    $('#dd-customer-email').on('keydown', function(e){
        if (e.key === 'Enter') search($(this).val().trim());
    });

    function search(email) {
        if (!email) return;
        var out = $('#dd-customer-result').html('<p>Načítám…</p>');

        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'dd_customer_history',
            nonce:  nonce,
            email:  email,
        }, function(res) {
            if (!res.success) { out.html('<div class="notice notice-error"><p>' + escHtml(res.data||'Chyba') + '</p></div>'); return; }

            var d = res.data;
            var html = '<h2>' + escHtml(email) + '</h2>';

            // Souhrnná tabulka balíčků
            html += '<h3>Přehled balíčků</h3>';
            html += '<table class="widefat striped" style="max-width:700px"><thead><tr>'
                  + '<th>Balíček</th><th>Stav</th><th>Celkem dok.</th><th>Odesláno</th><th>Zbývá</th>'
                  + '</tr></thead><tbody>';

            d.summary.forEach(function(s) {
                var statusLabel = s.active ? '<span style="color:green">Aktivní</span>' : '<span style="color:#aaa">Neaktivní</span>';
                var remainColor = s.remaining === 0 ? 'color:#c00' : (s.remaining <= 1 ? 'color:#e67e00' : 'color:green');
                var remainText  = s.remaining === 0
                    ? '✗ vyčerpáno'
                    : s.remaining + ' / ' + s.total_docs;
                html += '<tr>'
                      + '<td><strong>' + escHtml(s.package_name) + '</strong></td>'
                      + '<td>' + statusLabel + '</td>'
                      + '<td>' + s.total_docs + '</td>'
                      + '<td>' + s.sent + '</td>'
                      + '<td style="' + remainColor + ';font-weight:600">' + remainText + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';

            // Manuální odeslání náhodného balíčku podle kategorie
            html += '<h3 style="margin-top:1.5em">Manuální odeslání podle kategorie</h3>';
            if (!d.categories || !d.categories.length) {
                html += '<p style="color:#999"><em>Žádné kategorie s balíčky.</em></p>';
            } else {
                html += '<table class="widefat striped" style="max-width:900px"><thead><tr>'
                      + '<th>Kategorie</th><th>Balíčky v kategorii</th><th>K odeslání</th><th>Akce</th>'
                      + '</tr></thead><tbody>';
                d.categories.forEach(function(c) {
                    var disabled = c.available_count > 0 ? '' : 'disabled';
                    html += '<tr>'
                          + '<td>' + escHtml(c.name) + '</td>'
                          + '<td>' + escHtml(String(c.eligible_count)) + '</td>'
                          + '<td>' + escHtml(String(c.available_count)) + '</td>'
                          + '<td><button class="button button-secondary dd-send-random-cat" data-category-id="' + escHtml(String(c.id)) + '" ' + disabled + '>Odešli náhodný balíček</button></td>'
                          + '</tr>';
                });
                html += '</tbody></table>';
            }

            // Historie odeslaných
            html += '<h3 style="margin-top:1.5em">Historie odeslaných dárků</h3>';
            if (!d.history.length) {
                html += '<p style="color:#999"><em>Žádné odeslané dárky.</em></p>';
            } else {
                html += '<table class="widefat striped"><thead><tr>'
                      + '<th>Balíček</th><th>Dokument</th><th>Objednávka</th><th>Odesláno</th>'
                      + '</tr></thead><tbody>';
                d.history.forEach(function(row) {
                    var icon = mimeIcon(row.file_type);
                    html += '<tr>'
                          + '<td>' + escHtml(row.package_name || '—') + '</td>'
                          + '<td>' + icon + ' ' + escHtml(row.document_name || '—') + '</td>'
                          + '<td><a href="post.php?post=' + row.order_id + '&action=edit" target="_blank">#' + row.order_id + '</a></td>'
                          + '<td>' + escHtml(row.sent_at) + '</td>'
                          + '</tr>';
                });
                html += '</tbody></table>';
            }

            // Sekce pro smazání historie (testování)
            html += '<div class="dd-clear-history-wrap">';
            html += '<h3>🧪 Smazat historii (testování)</h3>';
            html += '<p class="description">Smazáním záznamu se zákazníkovi obnoví nárok na dárek z daného balíčku – včetně <em>první dárek zdarma</em>. Použij jen pro testování.</p>';
            html += '<div style="display:flex;gap:.5em;align-items:center;flex-wrap:wrap;margin-top:.5em">';
            html += '<select id="dd-clear-package-select" style="min-width:200px"><option value="0">— Všechny balíčky —</option>';
            d.summary.forEach(function(s) {
                if (s.sent > 0) {
                    html += '<option value="' + escHtml(String(s.package_id)) + '">' + escHtml(s.package_name) + ' (' + s.sent + ' odeslání)</option>';
                }
            });
            html += '</select>';
            html += '<button class="button button-link-delete" id="dd-clear-history-btn">🗑 Smazat historii</button>';
            html += '</div>';
            html += '</div>';

            out.html(html);
        });
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
        return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Smazání historie – delegovaný handler (elementy jsou dynamicky vytvořeny)
    $(document).on('click', '#dd-clear-history-btn', function() {
        var email  = $('#dd-customer-email').val().trim();
        var pkgId  = $('#dd-clear-package-select').val() || 0;
        var pkgLabel = $('#dd-clear-package-select option:selected').text();
        var msg = pkgId > 0
            ? 'Opravdu smazat historii dárků zákazníka ' + email + ' pro balíček "' + pkgLabel + '"?'
            : 'Opravdu smazat CELOU historii dárků zákazníka ' + email + '?';
        if (!confirm(msg)) return;

        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action:     'dd_clear_customer',
            nonce:      nonce,
            email:      email,
            package_id: pkgId,
        }, function(res) {
            if (!res.success) {
                alert('Chyba: ' + (res.data || 'neznámá'));
                return;
            }
            var deleted = res.data.deleted;
            var notice = $('<div class="notice notice-success is-dismissible"><p>✅ Smazáno ' + deleted + ' záznamů. Historie zákazníka byla vymazána.</p></div>');
            $('#dd-customer-result').prepend(notice);
            // Znovu načti přehled
            search(email);
        });
    });

    // Ruční odeslání náhodného balíčku podle kategorie
    $(document).on('click', '.dd-send-random-cat', function() {
        var btn = $(this);
        var email = $('#dd-customer-email').val().trim();
        var categoryId = btn.data('category-id');
        if (!email || !categoryId) return;
        if (!confirm('Odeslat zákazníkovi ' + email + ' náhodný balíček z této kategorie?')) return;

        btn.prop('disabled', true).text('Odesílám…');
        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
            action: 'dd_send_random_by_category',
            nonce:  nonce,
            email:  email,
            category_id: categoryId
        }, function(res) {
            if (!res.success) {
                alert('Chyba: ' + (res.data || 'neznámá'));
                btn.prop('disabled', false).text('Odešli náhodný balíček');
                return;
            }
            var msg = (res.data && res.data.message) ? res.data.message : 'Balíček byl odeslán.';
            var notice = $('<div class="notice notice-success is-dismissible"><p>✅ ' + escHtml(msg) + '</p></div>');
            $('#dd-customer-result').prepend(notice);
            search(email);
        }).fail(function() {
            alert('Chyba při komunikaci se serverem.');
            btn.prop('disabled', false).text('Odešli náhodný balíček');
        });
    });
})(jQuery);
</script>

<style>
.dd-customer-search-wrap {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 1.2em;
    max-width: 600px;
}
.dd-clear-history-wrap {
    margin-top: 2em;
    padding: 1em 1.2em;
    background: #fff8f0;
    border: 1px solid #f0c070;
    border-left: 4px solid #e67e00;
    border-radius: 6px;
    max-width: 700px;
}
.dd-clear-history-wrap h3 { margin-top: 0; font-size: 1em; }
</style>
