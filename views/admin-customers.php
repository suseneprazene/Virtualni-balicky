<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap dd-admin">
    <h1>👤 <?php _e( 'Zákazníci – přehled dárků', 'dobrovolny-darek' ); ?></h1>

    <div class="dd-customer-search-wrap">
        <p><?php _e( 'Zadej e-mail zákazníka a uvidíš, které dárky již obdržel a kolik mu zbývá.', 'dobrovolny-darek' ); ?></p>
        <div style="display:flex;gap:.5em;align-items:center;flex-wrap:wrap;">
            <input type="email" id="dd-customer-email" class="regular-text"
                   placeholder="zakaznik@email.cz"
                   value="<?php echo esc_attr( $_GET['email'] ?? '' ); ?>">
            <button class="button button-primary" id="dd-customer-search-btn">
                🔍 <?php _e( 'Vyhledat', 'dobrovolny-darek' ); ?>
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
</style>
