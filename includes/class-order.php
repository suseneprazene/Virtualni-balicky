<?php
defined( 'ABSPATH' ) || exit;

class DD_Order {

    public static function init(): void {
        // Klasický checkout hook
        add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_gift_choice' ], 10, 2 );

        // Blokový checkout hooks (WooCommerce Blocks)
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'save_gift_choice_block' ], 10, 1 );
        // Starší verze WC Blocks
        add_action( 'woocommerce_blocks_checkout_order_processed',    [ __CLASS__, 'save_gift_choice_block' ], 10, 1 );

        // Spustit při změně stavu objednávky
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'process_gift' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'process_gift' ], 10, 1 );
        

        // Admin meta box
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ __CLASS__, 'show_order_meta' ], 10, 1 );
    }

    // ── Uložení volby – klasický checkout ─────────────────────────────────────

    public static function save_gift_choice( WC_Order $order, array $data ): void {
        self::do_save_gift_choice( $order );
    }

    // ── Uložení volby – blokový checkout ─────────────────────────────────────

    public static function save_gift_choice_block( WC_Order $order ): void {
        self::do_save_gift_choice( $order );
    }

    // ── Společná logika uložení ───────────────────────────────────────────────

    private static function do_save_gift_choice( WC_Order $order ): void {
        // Načti session
        $session = WC()->session;
        if ( ! $session ) {
            // Pokud session není dostupná (např. REST API), zkusíme customer session
            $session = WC()->customer ? WC()->session : null;
        }

        $selected = $session ? (int) $session->get( DD_Cart::SESSION_KEY )   : 0;
        $xsell    = $session ? (int) $session->get( DD_Cart::SESSION_XSELL ) : 0;

        // Náhodný výběr – vylosuj teď
        if ( $selected === -1 ) {
            $product_ids  = array_map( fn( $i ) => (int) $i['product_id'], WC()->cart ? WC()->cart->get_cart() : [] );
            if ( empty( $product_ids ) ) {
                // Z položek objednávky
                $product_ids = array_map( fn( $i ) => $i->get_product_id(), array_values( $order->get_items() ) );
            }
            $category_ids = DD_Package::get_category_ids_for_products( $product_ids );
            $resolved     = DD_Package::resolve_for_cart( $product_ids, $category_ids );
            if ( ! empty( $resolved['matched'] ) ) {
                shuffle( $resolved['matched'] );
                $selected = (int) $resolved['matched'][0]->id;
            } else {
                $selected = 0;
            }
        }

        if ( $selected > 0 ) {
            $order->update_meta_data( '_dd_package_id',  $selected );
            $order->update_meta_data( '_dd_gift_status', 'pending' );
        }

        if ( $xsell > 0 ) {
            $order->update_meta_data( '_dd_xsell_package_id',  $xsell );
            $order->update_meta_data( '_dd_xsell_gift_status', 'pending' );
        }

        // Ulož meta ihned (důležité pro block checkout – order se ukládá async)
        if ( $selected > 0 || $xsell > 0 ) {
            $order->save();
        }

        // Vyčisti session
        if ( $session ) {
            $session->set( DD_Cart::SESSION_KEY,   0 );
            $session->set( DD_Cart::SESSION_XSELL, 0 );
        }
    }

    // ── Zpracování dárku (po změně stavu) ────────────────────────────────────

    public static function process_gift( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        self::dispatch_gift(
            $order,
            (int) $order->get_meta( '_dd_package_id' ),
            '_dd_gift_status',
            '_dd_document_id',
            '_dd_document_name'
        );

        self::dispatch_gift(
            $order,
            (int) $order->get_meta( '_dd_xsell_package_id' ),
            '_dd_xsell_gift_status',
            '_dd_xsell_document_id',
            '_dd_xsell_document_name'
        );
    }

    private static function dispatch_gift(
        WC_Order $order,
        int $package_id,
        string $status_key,
        string $doc_id_key,
        string $doc_name_key
    ): void {
        if ( ! $package_id ) return;

        // Idempotence
        if ( $order->get_meta( $status_key ) === 'sent' ) return;

        $package = DD_Package::get( $package_id );
        if ( ! $package ) {
            $order->update_meta_data( $status_key, 'error_no_package' );
            $order->save();
            return;
        }

        $email    = $order->get_billing_email();
        $document = DD_Package::pick_random_unsent( $package_id, $email );

        if ( ! $document ) {
            $order->update_meta_data( $status_key, 'exhausted' );
            $order->save();
            $order->add_order_note( sprintf(
                __( 'Tajný dárek [%s]: zákazník vyčerpal všechny dokumenty.', 'virtualni-balicek' ),
                $package->name
            ) );
            return;
        }

        $sent = DD_Email::send_gift( $order, $package, $document );

        if ( $sent ) {
            $user_id = $order->get_user_id() ?: null;
            DD_Package::record_sent( $package_id, (int) $document->id, $email, $order->get_id(), $user_id );

            $order->update_meta_data( $status_key,   'sent' );
            $order->update_meta_data( $doc_id_key,   $document->id );
            $order->update_meta_data( $doc_name_key, $document->name );
            $order->save();
            $order->add_order_note( sprintf(
                __( 'Tajný dárek [%s] odeslán: "%s" → %s', 'virtualni-balicek' ),
                $package->name, $document->name, $email
            ) );
        } else {
            $order->update_meta_data( $status_key, 'error_email' );
            $order->save();
            $order->add_order_note( sprintf(
                __( 'Tajný dárek [%s]: chyba při odesílání e-mailu.', 'virtualni-balicek' ),
                $package->name
            ) );
        }
    }

    // ── Ruční zpracování z Debug panelu ───────────────────────────────────────

    /**
     * Fallback: pokud _dd_package_id chybí (např. starší objednávka nebo session
     * se neuložila), pokusíme se balíček dohledat z produktů objednávky.
     */
    public static function force_process_with_fallback( int $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'status' => 'error', 'message' => 'Objednávka nenalezena.' ];

        // Pokud _dd_package_id chybí, zkus resolve z produktů objednávky
        if ( ! $order->get_meta( '_dd_package_id' ) ) {
            $product_ids  = array_map( fn( $i ) => $i->get_product_id(), array_values( $order->get_items() ) );
            $category_ids = DD_Package::get_category_ids_for_products( $product_ids );
            $resolved     = DD_Package::resolve_for_cart( $product_ids, $category_ids );

            $pkg = null;
            if ( ! empty( $resolved['matched'] ) ) {
                $email = $order->get_billing_email();
                // Vyber první, ze kterého má zákazník nevyčerpané dokumenty
                foreach ( $resolved['matched'] as $candidate ) {
                    if ( DD_Package::has_unsent( (int) $candidate->id, $email ) ) {
                        $pkg = $candidate;
                        break;
                    }
                }
            }

            if ( $pkg ) {
                $order->update_meta_data( '_dd_package_id',  $pkg->id );
                $order->update_meta_data( '_dd_gift_status', 'pending' );
                $order->save();
            } else {
                return [ 'status' => 'no_package', 'message' => '⚠️ Nepodařilo se automaticky přiřadit balíček. Žádný aktivní balíček neodpovídá produktům objednávky nebo zákazník vše vyčerpal.' ];
            }
        }

        // Reset status aby se odeslalo
        $order->update_meta_data( '_dd_gift_status', 'pending' );
        $order->save();

        self::process_gift( $order_id );

        $order    = wc_get_order( $order_id );
        $status   = $order->get_meta( '_dd_gift_status' );
        $doc_name = $order->get_meta( '_dd_document_name' );

        $messages = [
            'sent'             => '✅ Dárek odeslán: ' . $doc_name,
            'exhausted'        => '⚠️ Zákazník vyčerpal všechny dokumenty v balíčku.',
            'error_email'      => '❌ Chyba při odesílání e-mailu. Zkontroluj SMTP nastavení.',
            'error_no_package' => '❌ Balíček nenalezen nebo není aktivní.',
            'pending'          => '⚠️ Stále pending – žádný dokument nebyl vybrán.',
        ];

        return [
            'status'  => $status,
            'message' => $messages[ $status ] ?? 'Status: ' . $status,
        ];
    }

    // ── Admin meta box ────────────────────────────────────────────────────────

    public static function show_order_meta( WC_Order $order ): void {
        $labels = [
            'pending'          => '⏳ ' . __( 'Čeká na odeslání', 'virtualni-balicek' ),
            'sent'             => '✅ ' . __( 'Odesláno', 'virtualni-balicek' ),
            'exhausted'        => '⚠️ ' . __( 'Zákazník vyčerpal všechny dokumenty', 'virtualni-balicek' ),
            'error_no_package' => '❌ ' . __( 'Balíček nenalezen', 'virtualni-balicek' ),
            'error_email'      => '❌ ' . __( 'Chyba e-mailu', 'virtualni-balicek' ),
        ];

        $gifts = [
            [ 'label' => __( 'Tajný dárek', 'virtualni-balicek' ),      'pkg_key' => '_dd_package_id',      'status_key' => '_dd_gift_status',      'doc_key' => '_dd_document_name' ],
            [ 'label' => __( 'Cross-sell dárek', 'virtualni-balicek' ), 'pkg_key' => '_dd_xsell_package_id', 'status_key' => '_dd_xsell_gift_status', 'doc_key' => '_dd_xsell_document_name' ],
        ];

        $doc_id_key_map = [
            '_dd_gift_status'       => '_dd_document_id',
            '_dd_xsell_gift_status' => '_dd_xsell_document_id',
        ];

        foreach ( $gifts as $gift ) {
            $pkg_id = (int) $order->get_meta( $gift['pkg_key'] );
            if ( ! $pkg_id ) continue;
            $pkg    = DD_Package::get( $pkg_id );
            $status = $order->get_meta( $gift['status_key'] );
            $doc    = $order->get_meta( $gift['doc_key'] );
            $doc_id = (int) $order->get_meta( $doc_id_key_map[ $gift['status_key'] ] ?? '' );

            echo '<div style="margin-top:.8em;padding:.7em;background:#f9f0ff;border-left:4px solid #8a2be2;">';
            echo '<strong>🎁 ' . esc_html( $gift['label'] );
            if ( $pkg ) echo ' – ' . esc_html( $pkg->name );
            echo ':</strong> ';
            echo esc_html( $labels[ $status ] ?? $status );
            if ( $doc ) {
                echo ' — <em>' . esc_html( $doc ) . '</em>';
                if ( $doc_id ) {
                    $download_url = wp_nonce_url(
                        admin_url( 'admin-ajax.php?action=dd_download_document&doc_id=' . $doc_id ),
                        'dd_admin_nonce',
                        'nonce'
                    );
                    echo ' <a href="' . esc_url( $download_url ) . '" title="' . esc_attr__( 'Stáhnout dokument', 'virtualni-balicek' ) . '">📥 ' . esc_html__( 'Stáhnout', 'virtualni-balicek' ) . '</a>';
                }
            }

            if ( in_array( $status, [ 'error_email', 'pending', 'error_no_package' ], true ) ) {
                $resend_url = wp_nonce_url(
                    add_query_arg(
                        [ 'dd_resend' => $order->get_id(), 'dd_resend_key' => $gift['status_key'] ],
                        admin_url( 'admin.php?page=dobrovolny-darek-stats' )
                    ),
                    'dd_resend_' . $order->get_id()
                );
                echo ' <a href="' . esc_url( $resend_url ) . '">' . __( 'Znovu odeslat', 'virtualni-balicek' ) . '</a>';
            }
            echo '</div>';
        }
    }
}
