<?php
defined( 'ABSPATH' ) || exit;

class DD_Debug {

    public static function init(): void {
        add_action( 'wp_ajax_dd_debug_order',        [ __CLASS__, 'ajax_debug_order' ] );
        add_action( 'wp_ajax_dd_force_process',      [ __CLASS__, 'ajax_force_process' ] );
        add_action( 'wp_ajax_dd_test_email',         [ __CLASS__, 'ajax_test_email' ] );
        add_action( 'wp_ajax_dd_debug_cart',         [ __CLASS__, 'ajax_debug_cart' ] );
        add_action( 'wp_ajax_nopriv_dd_debug_cart',  [ __CLASS__, 'ajax_debug_cart' ] );
    }

    public static function render_panel(): void {
        global $wpdb;
        $orders = wc_get_orders( [ 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC' ] );
        $tables = [ 'dd_packages', 'dd_documents', 'dd_package_rules', 'dd_sent' ];
        $existing = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}dd_%'" );
        $missing  = array_diff( array_map( fn($t) => $wpdb->prefix . $t, $tables ), $existing );
        ?>
        <div class="dd-debug-panel">
            <h2>🔧 Diagnostika</h2>

            <h3>Databázové tabulky</h3>
            <table class="widefat" style="max-width:500px">
                <thead><tr><th>Tabulka</th><th>Existuje</th><th>Záznamy</th></tr></thead>
                <tbody>
                <?php foreach ( $tables as $t ) :
                    $full   = $wpdb->prefix . $t;
                    $exists = in_array( $full, $existing, true );
                    $count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$full`" ) : '–';
                ?>
                <tr>
                    <td><code><?php echo esc_html( $full ); ?></code></td>
                    <td><?php echo $exists ? '✅' : '❌ CHYBÍ'; ?></td>
                    <td><?php echo esc_html( $count ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $missing ) : ?>
            <p>
                <strong style="color:red">❌ Chybí tabulky!</strong>
                <button class="button button-primary" id="dd-repair-db">🔨 Opravit databázi</button>
            </p>
            <?php endif; ?>

            <h3 style="margin-top:1.5em">Test e-mailu</h3>
            <p>
                <input type="email" id="dd-test-email-addr" placeholder="vas@email.cz" style="width:250px">
                <button class="button" id="dd-test-email-btn">📧 Odeslat testovací e-mail</button>
                <span id="dd-test-email-result" style="margin-left:.5em"></span>
            </p>

            <h3 style="margin-top:1.5em">Posledních 10 objednávek</h3>
            <p class="description">
                Tlačítko <strong>▶ Zpracovat</strong> nyní automaticky dohledá správný balíček z produktů objednávky, i pokud session nebyla uložena.
            </p>
            <table class="widefat striped">
                <thead>
                    <tr><th>Objednávka</th><th>Stav</th><th>E-mail</th><th>Dárek meta</th><th>Akce</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $orders as $order ) :
                    $pkg_id   = $order->get_meta( '_dd_package_id' );
                    $status   = $order->get_meta( '_dd_gift_status' );
                    $doc_name = $order->get_meta( '_dd_document_name' );
                    $meta_txt = $pkg_id
                        ? '✅ pkg=' . $pkg_id . ' | ' . ( $status ?: '?' ) . ( $doc_name ? ' | ' . $doc_name : '' )
                        : '— (bez dárku)';
                ?>
                <tr id="dd-order-row-<?php echo $order->get_id(); ?>">
                    <td><a href="<?php echo get_edit_post_link( $order->get_id() ); ?>" target="_blank">#<?php echo $order->get_id(); ?></a></td>
                    <td><?php echo esc_html( $order->get_status() ); ?></td>
                    <td><?php echo esc_html( $order->get_billing_email() ); ?></td>
                    <td><?php echo esc_html( $meta_txt ); ?></td>
                    <td style="white-space:nowrap">
                        <button class="button dd-force-process" data-id="<?php echo $order->get_id(); ?>">▶ Zpracovat</button>
                        <button class="button dd-debug-order"   data-id="<?php echo $order->get_id(); ?>">🔍 Detail</button>
                    </td>
                </tr>
                <tr class="dd-debug-detail" id="dd-detail-<?php echo $order->get_id(); ?>" style="display:none">
                    <td colspan="5"><pre class="dd-debug-pre" style="overflow:auto;max-height:300px;background:#f6f6f6;padding:.8em">Načítám…</pre></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="dd-debug-output" style="margin-top:1em"></div>
        </div>

        <script>
        (function($){
            var nonce = '<?php echo wp_create_nonce('dd_admin_nonce'); ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

            $('#dd-repair-db').on('click', function(){
                $.post(ajaxUrl,{action:'dd_repair_db',nonce:nonce},function(r){
                    alert(r.data||'Hotovo'); location.reload();
                });
            });

            $('#dd-test-email-btn').on('click', function(){
                var addr = $('#dd-test-email-addr').val();
                if(!addr){alert('Zadej e-mail.');return;}
                $('#dd-test-email-result').text('Odesílám…');
                $.post(ajaxUrl,{action:'dd_test_email',nonce:nonce,email:addr},function(r){
                    $('#dd-test-email-result').text(r.success ? '✅ Odesláno!' : '❌ '+(r.data||'Chyba'));
                });
            });

            $(document).on('click','.dd-debug-order',function(){
                var id  = $(this).data('id');
                var row = $('#dd-detail-'+id);
                var pre = row.find('pre');
                row.toggle();
                if(!row.is(':visible')) return;
                pre.text('Načítám…');
                $.post(ajaxUrl,{action:'dd_debug_order',nonce:nonce,order_id:id},function(r){
                    pre.text(r.success ? JSON.stringify(r.data,null,2) : 'Chyba: '+r.data);
                });
            });

            $(document).on('click','.dd-force-process',function(){
                var id  = $(this).data('id');
                var btn = $(this).text('Zpracovávám…').prop('disabled',true);
                $.post(ajaxUrl,{action:'dd_force_process',nonce:nonce,order_id:id},function(r){
                    btn.text('▶ Zpracovat').prop('disabled',false);
                    var cls = r.success && r.data.status==='sent' ? 'notice-success' : 'notice-warning';
                    $('#dd-debug-output').html('<div class="notice '+cls+'"><p>'+(r.success?r.data.message:r.data)+'</p></div>');
                    if(r.success) setTimeout(function(){ location.reload(); }, 2000);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_debug_order(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) { wp_send_json_error( 'Objednávka nenalezena.' ); }

        global $wpdb;
        $email = $order->get_billing_email();

        $gift_meta = [];
        foreach ( $order->get_meta_data() as $meta ) {
            if ( strpos( $meta->key, '_dd_' ) === 0 ) {
                $gift_meta[ $meta->key ] = $meta->value;
            }
        }

        $packages = DD_Package::get_active_all();
        $pkg_info = [];
        foreach ( $packages as $pkg ) {
            $unsent_ids = DD_Package::get_unsent_document_ids( (int) $pkg->id, $email );
            $rules      = DD_Package::get_rules( (int) $pkg->id );
            $pkg_info[] = [
                'id'           => $pkg->id,
                'name'         => $pkg->name,
                'doc_count'    => DD_Package::document_count( (int) $pkg->id ),
                'unsent_count' => count( $unsent_ids ),
                'unsent_ids'   => $unsent_ids,
                'rules_count'  => count( $rules ),
            ];
        }

        $order_product_ids = array_map( fn( $i ) => $i->get_product_id(), array_values( $order->get_items() ) );
        $order_cat_ids     = DD_Package::get_category_ids_for_products( $order_product_ids );
        $resolved          = DD_Package::resolve_for_cart( $order_product_ids, $order_cat_ids );

        $sent_history = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, d.name as doc_name FROM {$wpdb->prefix}dd_sent s
             LEFT JOIN {$wpdb->prefix}dd_documents d ON d.id = s.document_id
             WHERE s.user_email = %s ORDER BY s.sent_at DESC LIMIT 20",
            $email
        ) );

        wp_send_json_success( [
            'order_id'           => $order_id,
            'order_status'       => $order->get_status(),
            'billing_email'      => $email,
            'gift_meta'          => $gift_meta,
            'active_packages'    => $pkg_info,
            'order_product_ids'  => $order_product_ids,
            'order_cat_ids'      => $order_cat_ids,
            'resolved_matched'   => array_map( fn($p) => $p->id . ':' . $p->name, $resolved['matched'] ),
            'resolved_crosssell' => array_map( fn($p) => $p->id . ':' . $p->name, $resolved['crosssell'] ),
            'sent_history'       => $sent_history,
        ] );
    }

    public static function ajax_force_process(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $result   = DD_Order::force_process_with_fallback( $order_id );

        if ( $result['status'] === 'error' ) {
            wp_send_json_error( $result['message'] );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_test_email(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $to      = sanitize_email( $_POST['email'] ?? '' );
        $subject = '🎁 Test e-mailu – Dobrovolný dárek';
        $body    = '<p>Testovací e-mail z pluginu <strong>Dobrovolný dárek</strong> funguje správně.</p>';
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $result  = wp_mail( $to, $subject, $body, $headers );

        if ( $result ) {
            wp_send_json_success( 'E-mail odeslán na ' . $to );
        } else {
            global $phpmailer;
            $error = isset( $phpmailer ) ? $phpmailer->ErrorInfo : 'wp_mail() vrátilo false';
            wp_send_json_error( $error );
        }
    }

    /**
     * Frontend debug endpoint – vrátí snapshot košíku (DD položky + session).
     * Zabezpečeno stejným nonce jako ostatní DD frontend AJAX akce.
     */
    public static function ajax_debug_cart(): void {
        check_ajax_referer( 'dd_cart_nonce', 'nonce' );

        $cart_items = [];
        foreach ( WC()->cart->get_cart() as $key => $item ) {
            $product = $item['data'] ?? null;

            $cart_items[ $key ] = [
                'product_id'       => $item['product_id'] ?? null,
                'dd_package_id'    => $item[ DD_Cart::CART_ITEM_KEY ] ?? null,
                'dd_type'          => $item['dd_type'] ?? null,
                'quantity'         => $item['quantity'] ?? null,
                'line_total'       => $item['line_total'] ?? null,
                'data_class'       => $product ? get_class( $product ) : 'null/false',
                'data_exists'      => $product instanceof WC_Product ? $product->exists() : false,
                'data_purchasable' => $product instanceof WC_Product ? $product->is_purchasable() : false,
                'data_name'        => $product instanceof WC_Product ? $product->get_name() : null,
                'data_price'       => $product instanceof WC_Product ? $product->get_price() : null,
            ];
        }

        $session    = WC()->session;
        $dd_session = [
            'SESSION_KEY'   => $session ? $session->get( DD_Cart::SESSION_KEY ) : null,
            'SESSION_XSELL' => $session ? $session->get( DD_Cart::SESSION_XSELL ) : null,
        ];

        $dd_items = array_filter( $cart_items, static fn( $i ) => $i['dd_package_id'] !== null );

        wp_send_json_success( [
            'cart_item_count' => count( $cart_items ),
            'dd_items_count'  => count( $dd_items ),
            'cart_items'      => $cart_items,
            'dd_items'        => $dd_items,
            'session_dd'      => $dd_session,
        ] );
    }
}
