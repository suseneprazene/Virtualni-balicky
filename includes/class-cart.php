<?php
defined( 'ABSPATH' ) || exit;

class DD_Virtual_Product extends WC_Product {
    public function exists(): bool {
        return true;
    }

    public function is_purchasable(): bool {
        return true;
    }

    public function get_type(): string {
        return 'simple';
    }
}

class DD_Cart {

    const CART_ITEM_KEY = 'dd_package_id';
    const SESSION_KEY   = 'dd_selected_package';
    const SESSION_XSELL = 'dd_crosssell_package';

    public static function init(): void {
        // Klasický shortcode košík
        add_action( 'woocommerce_before_cart_totals', [ __CLASS__, 'render_gift_ui' ] );

        // Skripty
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

        add_filter( 'woocommerce_get_cart_item_from_session', [ __CLASS__, 'restore_cart_item_from_session' ], 10, 3 );
        add_action( 'woocommerce_before_calculate_totals',    [ __CLASS__, 'set_virtual_item_prices' ], 20 );
        add_filter( 'woocommerce_cart_item_name',             [ __CLASS__, 'cart_item_name' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_class',            [ __CLASS__, 'cart_item_class' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_product',          [ __CLASS__, 'cart_item_product' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_thumbnail',        [ __CLASS__, 'cart_item_thumbnail' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_price',            [ __CLASS__, 'cart_item_price' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_quantity',         [ __CLASS__, 'cart_item_quantity' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_subtotal',         [ __CLASS__, 'cart_item_subtotal' ], 10, 3 );

        // AJAX
        add_action( 'wp_ajax_dd_select_package',        [ __CLASS__, 'ajax_select' ] );
        add_action( 'wp_ajax_nopriv_dd_select_package', [ __CLASS__, 'ajax_select' ] );
        add_action( 'wp_ajax_dd_get_cart_html',         [ __CLASS__, 'ajax_get_cart_html' ] );
        add_action( 'wp_ajax_nopriv_dd_get_cart_html',  [ __CLASS__, 'ajax_get_cart_html' ] );

        // Blokový košík – jednorázový inject přes footer
        add_action( 'wp_footer', [ __CLASS__, 'inject_block_cart_init' ] );

        // Skryj obrázek placeholder produktu v potvrzovacím e-mailu WooCommerce
        add_filter( 'woocommerce_order_item_thumbnail', [ __CLASS__, 'hide_email_thumbnail' ], 10, 2 );
    }

    // ── Skripty ───────────────────────────────────────────────────────────────

    public static function enqueue_scripts(): void {
        if ( ! is_cart() && ! is_checkout() ) return;

        wp_enqueue_script(
            'dd-cart',
            DD_PLUGIN_URL . 'assets/cart.js',
            [ 'jquery' ],
            DD_VERSION,
            true
        );
        wp_localize_script( 'dd-cart', 'DD_Cart', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'dd_cart_nonce' ),
            'is_block_cart' => self::is_block_cart() ? '1' : '0',
            'package_label_needle' => (string) __( 'Virtuální balíček', 'virtualni-balicek' ),
        ] );

        wp_add_inline_style( 'woocommerce-general', self::cart_css() );

        // Toggle info popupu
        wp_add_inline_script( 'dd-cart', "
(function(){
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.dd-info-btn');
        var close = e.target.closest('.dd-info-close');
        if (btn) {
            var popup = btn.closest('.dd-gift-heading').nextElementSibling;
            if (popup && popup.classList.contains('dd-info-popup')) {
                popup.hidden = !popup.hidden;
            }
            return;
        }
        if (close) {
            close.closest('.dd-info-popup').hidden = true;
            return;
        }
        // Klik mimo popup ho zavře
        var open = document.querySelector('.dd-info-popup:not([hidden])');
        if (open && !open.contains(e.target)) open.hidden = true;
    });
})();
        " );
    }

    public static function is_block_cart(): bool {
        if ( ! is_cart() ) return false;
        global $post;
        if ( $post && has_blocks( $post->post_content ) ) {
            return has_block( 'woocommerce/cart', $post->post_content );
        }
        return false;
    }

    // ── Detekce obsahu košíku ─────────────────────────────────────────────────

    public static function get_cart_product_ids(): array {
        if ( ! WC()->cart ) return [];
        $ids = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( isset( $item[ self::CART_ITEM_KEY ] ) ) {
                continue;
            }
            $ids[] = (int) $item['product_id'];
        }
        return array_values( array_filter( array_unique( $ids ) ) );
    }

    public static function get_customer_email(): string {
        if ( is_user_logged_in() ) return wp_get_current_user()->user_email;
        $email = WC()->session ? WC()->session->get( 'billing_email' ) : '';
        return sanitize_email( $email ?: '' );
    }

    public static function make_virtual_product( object $pkg ): DD_Virtual_Product {
        $product = new DD_Virtual_Product( 0 );
        $name    = self::virtual_item_label( $pkg );

        $product->set_name( $name );
        $product->set_price( (float) $pkg->price );
        $product->set_virtual( true );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'instock' );
        $product->set_catalog_visibility( 'hidden' );

        // Assign a real product ID so the WooCommerce Store API (block cart) can handle this item.
        $placeholder_id = self::get_placeholder_product_id();
        if ( $placeholder_id > 0 ) {
            $product->set_id( $placeholder_id );
            // Copy the featured image so WC Blocks / Store API renders the correct thumbnail.
            $image_id = (int) get_post_thumbnail_id( $placeholder_id );
            if ( $image_id > 0 ) {
                $product->set_image_id( $image_id );
            }
        }

        return $product;
    }

    /**
     * Returns the ID of a hidden placeholder WooCommerce product used to give
     * DD cart items a valid product_id (required by the Store API / block cart).
     */
    private static function get_placeholder_product_id(): int {
        static $pid = null;
        if ( $pid === null ) {
            $pid = DD_Installer::get_or_create_placeholder_product();
        }
        return (int) $pid;
    }

    /**
     * Vrátí pole textových WooCommerce chybových oznámení (HTML stripped).
     * Používá se pro diagnostický výstup v AJAX error response.
     *
     * @return string[]
     */
    private static function get_wc_error_notice_texts(): array {
        return array_values( array_map(
            static fn( array $n ): string => wp_strip_all_tags( $n['notice'] ?? '' ),
            wc_get_notices( 'error' )
        ) );
    }

    public static function get_dd_cart_items(): array {
        if ( ! WC()->cart ) return [];
        $items = [];
        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( isset( $item[ self::CART_ITEM_KEY ] ) ) {
                $items[ $key ] = $item;
            }
        }
        return $items;
    }

    /**
     * Vrátí z košíku aktuálně zvolené DD balíčky podle typu.
     *
     * @return array{selected:int,crosssell:int}
     */
    private static function selected_ids_from_cart(): array {
        $selected_id = 0;
        $xsell_id    = 0;

        foreach ( self::get_dd_cart_items() as $item ) {
            $dd_type = $item['dd_type'] ?? 'direct';
            if ( $dd_type === 'direct' ) {
                $selected_id = (int) ( $item[ self::CART_ITEM_KEY ] ?? 0 );
            } elseif ( $dd_type === 'crosssell' ) {
                $xsell_id = (int) ( $item[ self::CART_ITEM_KEY ] ?? 0 );
            }
        }

        return [
            'selected'  => $selected_id,
            'crosssell' => $xsell_id,
        ];
    }

    /**
     * Synchronizuje session výběr DD balíčků podle aktuálního obsahu košíku.
     *
     * @return array{selected:int,crosssell:int}
     */
    private static function sync_selection_session_from_cart(): array {
        $ids = self::selected_ids_from_cart();

        if ( WC()->session ) {
            WC()->session->set( self::SESSION_KEY, $ids['selected'] );
            WC()->session->set( self::SESSION_XSELL, $ids['crosssell'] );
        }

        return $ids;
    }

    public static function add_package_to_cart( int $pkg_id, string $type = 'direct' ): ?string {
        if ( ! WC()->cart || $pkg_id <= 0 ) {
            error_log( '[DD_Cart] add_package_to_cart: cart not available or invalid pkg_id=' . $pkg_id );
            return null;
        }

        $pkg = DD_Package::get( $pkg_id );
        if ( ! $pkg || ! $pkg->active ) {
            error_log( '[DD_Cart] add_package_to_cart: package not found or inactive pkg_id=' . $pkg_id );
            return null;
        }

        $type = $type === 'crosssell' ? 'crosssell' : 'direct';

        if ( $type === 'direct' ) {
            foreach ( self::get_dd_cart_items() as $key => $item ) {
                if ( ( $item['dd_type'] ?? 'direct' ) === 'direct' ) {
                    unset( WC()->cart->cart_contents[ $key ] );
                }
            }
        }

        foreach ( self::get_dd_cart_items() as $key => $item ) {
            if ( (int) ( $item[ self::CART_ITEM_KEY ] ?? 0 ) === $pkg_id && ( $item['dd_type'] ?? 'direct' ) === $type ) {
                return $key;
            }
        }

        $email   = self::get_customer_email();
        $price   = (float) $pkg->price;
        $is_free = $email
            && DD_Package::is_first_free_eligible( $pkg_id, $email )
            && self::get_cart_first_free_pkg_id( $email ) === null;
        if ( $is_free ) {
            $price = 0.0;
        }

        $product  = self::make_virtual_product( $pkg );
        $product->set_price( $price );
        $prod_id  = $product->get_id(); // set by make_virtual_product via placeholder
        if ( $prod_id <= 0 ) {
            error_log( sprintf(
                '[DD_Cart] add_package_to_cart: placeholder product not found (prod_id=0) for pkg_id=%d. Deactivate and reactivate the plugin to recreate it.',
                $pkg_id
            ) );
            return null;
        }

        $cart_key = WC()->cart->add_to_cart(
            $prod_id,
            1,
            0,
            [],
            [
                self::CART_ITEM_KEY => $pkg_id,
                'dd_type'           => $type,
            ]
        );
        if ( ! $cart_key ) {
            $wc_errors = self::get_wc_error_notice_texts();
            error_log( sprintf(
                '[DD_Cart] add_package_to_cart: WC add_to_cart returned false for prod_id=%d pkg_id=%d. WC notices: %s',
                $prod_id,
                $pkg_id,
                wp_json_encode( $wc_errors )
            ) );
            return null;
        }
        $added_item = WC()->cart->get_cart_item( $cart_key );
        if ( empty( $added_item[ self::CART_ITEM_KEY ] ) ) {
            error_log( '[DD_Cart] add_package_to_cart: cart item missing dd_package_id after add_to_cart. cart_key=' . $cart_key );
            return null;
        }

        WC()->cart->set_session();
        return $cart_key;
    }

    public static function remove_package_from_cart( int $pkg_id, string $type = 'direct' ): void {
        if ( ! WC()->cart || $pkg_id <= 0 ) return;
        $changed = false;
        $type    = $type === 'crosssell' ? 'crosssell' : 'direct';
        foreach ( self::get_dd_cart_items() as $key => $item ) {
            if ( (int) ( $item[ self::CART_ITEM_KEY ] ?? 0 ) === $pkg_id && ( $item['dd_type'] ?? 'direct' ) === $type ) {
                unset( WC()->cart->cart_contents[ $key ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            WC()->cart->set_session();
        }
    }

    public static function restore_cart_item_from_session( array $cart_item, array $values, string $key ): array {
        if ( ! isset( $values[ self::CART_ITEM_KEY ] ) ) {
            return $cart_item;
        }

        $pkg_id = (int) $values[ self::CART_ITEM_KEY ];
        $pkg    = DD_Package::get( $pkg_id );
        if ( ! $pkg || ! $pkg->active ) {
            $cart_item['data'] = false;
            return $cart_item;
        }

        $cart_item['data']              = self::make_virtual_product( $pkg );
        $cart_item[ self::CART_ITEM_KEY ] = $pkg_id;
        $cart_item['dd_type']           = $values['dd_type'] ?? 'direct';

        // Ensure product_id is the placeholder (fixes carts stored before this update).
        $placeholder_id = self::get_placeholder_product_id();
        if ( $placeholder_id > 0 ) {
            $cart_item['product_id'] = $placeholder_id;
        }

        return $cart_item;
    }

    /**
     * Vrátí ID balíčku z košíku, který jako první splňuje podmínky pro "první zdarma".
     * Napříč celým košíkem existuje maximálně jeden takový slot – první nalezený vyhraje.
     * Balíčky bez first_free = 1 nebo zákazníci, kteří benefit již čerpali, sem nespadají.
     *
     * @param string $email E-mail zákazníka.
     * @return int|null ID balíčku, nebo null, pokud žádný nenaplnil podmínky.
     */
    private static function get_cart_first_free_pkg_id( string $email ): ?int {
        if ( ! $email || ! WC()->cart ) return null;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! isset( $item[ self::CART_ITEM_KEY ] ) ) continue;
            $pkg_id = (int) $item[ self::CART_ITEM_KEY ];
            if ( DD_Package::is_first_free_eligible( $pkg_id, $email ) ) {
                return $pkg_id;
            }
        }
        return null;
    }

    public static function set_virtual_item_prices( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        $email       = self::get_customer_email();
        $free_pkg_id = $email ? self::get_cart_first_free_pkg_id( $email ) : null;
        foreach ( $cart->get_cart() as $key => $item ) {
            if ( ! isset( $item[ self::CART_ITEM_KEY ] ) || ! isset( $item['data'] ) || $item['data'] === false ) {
                continue;
            }
            $pkg_id = (int) $item[ self::CART_ITEM_KEY ];
            $pkg    = DD_Package::get( $pkg_id );
            if ( ! $pkg || ! $pkg->active ) {
                continue;
            }
            if ( ! isset( $item['quantity'] ) || (int) $item['quantity'] !== 1 ) {
                $cart->cart_contents[ $key ]['quantity'] = 1;
            }
            $price   = (float) $pkg->price;
            $is_free = ( $free_pkg_id !== null && $pkg_id === $free_pkg_id );
            if ( $is_free ) {
                $price = 0.0;
            }
            $item['data']->set_price( $price );
            $cart->cart_contents[ $key ]['line_subtotal']     = $price;
            $cart->cart_contents[ $key ]['line_subtotal_tax'] = 0;
            $cart->cart_contents[ $key ]['line_total']        = $price;
            $cart->cart_contents[ $key ]['line_tax']          = 0;
        }
    }

public static function cart_item_name( string $name, array $cart_item, string $cart_item_key ): string {
    if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) return $name;
    $pkg = DD_Package::get( (int) $cart_item[ self::CART_ITEM_KEY ] );
    if ( ! $pkg ) return $name;

    $label_text = self::virtual_item_label( $pkg );

    // Zobraz obrázek jen pokud má placeholder produkt nastavenou featured image
    $icon_html = '';
    $placeholder_id = self::get_placeholder_product_id();
    if ( $placeholder_id > 0 ) {
        $image_id = (int) get_post_thumbnail_id( $placeholder_id );
        if ( $image_id > 0 ) {
            $icon_html = wp_get_attachment_image( $image_id, 'woocommerce_gallery_thumbnail', false, [
                'class'    => 'dd-cart-item-icon',
                'alt'      => '',
                'loading'  => 'lazy',
                'decoding' => 'async',
            ] );
        }
    }

    return wp_kses_post(
        '<span class="dd-cart-item-label" data-dd-gift-item="1">'
        . $icon_html
        . '<span class="dd-cart-item-text">' . esc_html( $label_text ) . '</span>'
        . '</span>'
    );
}

    public static function cart_item_class( string $class, array $cart_item, string $cart_item_key ): string {
        if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) {
            return $class;
        }

        return trim( $class . ' dd-cart-item' );
    }

    /**
     * Zajistí produktový objekt pro DD virtuální položky v košíku.
     *
     * Rekonstruuje DD_Virtual_Product ve chvíli, kdy WooCommerce předá
     * chybějící nebo neplatný produktový objekt pro naši custom položku.
     *
     * @param mixed  $product       Produkt předaný WooCommerce.
     * @param array  $cart_item     Data řádku košíku.
     * @param string $cart_item_key Klíč řádku košíku.
     * @return mixed
     */
    public static function cart_item_product( $product, array $cart_item, string $cart_item_key ) {
        if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) {
            return $product;
        }
        if ( $product instanceof WC_Product && $product->exists() ) {
            return $product;
        }
        $pkg = DD_Package::get( (int) $cart_item[ self::CART_ITEM_KEY ] );
        if ( ! $pkg || ! $pkg->active ) {
            return $product;
        }
        return self::make_virtual_product( $pkg );
    }

public static function cart_item_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string {
    if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) {
        return $thumbnail;
    }
    $placeholder_id = self::get_placeholder_product_id();
    if ( $placeholder_id > 0 ) {
        $image_id = get_post_thumbnail_id( $placeholder_id );
        if ( $image_id > 0 ) {
            return wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, [
                'class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
            ] );
        }
    }

}
    /**
     * Skryje obrázek DD placeholder produktu v potvrzovacím e-mailu WooCommerce.
     * Hook: woocommerce_order_item_thumbnail
     *
     * @param string        $image     HTML obrázku generované WooCommerce.
     * @param WC_Order_Item $item      Položka objednávky.
     * @return string  Prázdný řetězec pokud jde o DD položku, jinak původní HTML.
     */
    public static function hide_email_thumbnail( string $image, $item ): string {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return $image;
        }
        $placeholder_id = (int) get_option( 'dd_placeholder_product_id', 0 );
        if ( $placeholder_id <= 0 ) {
            return $image;
        }
        if ( (int) $item->get_product_id() === $placeholder_id
            || (int) $item->get_variation_id() === $placeholder_id ) {
            return '';
        }
        return $image;
    }

    public static function cart_item_price( string $price_html, array $cart_item, string $cart_item_key ): string {
        if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) return $price_html;
        $pkg = DD_Package::get( (int) $cart_item[ self::CART_ITEM_KEY ] );
        if ( ! $pkg ) return $price_html;
        $email       = self::get_customer_email();
        $free_pkg_id = $email ? self::get_cart_first_free_pkg_id( $email ) : null;
        $ff          = ( $free_pkg_id !== null && (int) $pkg->id === $free_pkg_id );
        return self::price_text_html( $pkg, $ff );
    }

    public static function cart_item_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
        return self::cart_item_price( $subtotal, $cart_item, $cart_item_key );
    }

    public static function cart_item_quantity( string $quantity, array $cart_item, string $cart_item_key ): string {
        if ( ! isset( $cart_item[ self::CART_ITEM_KEY ] ) ) return $quantity;
        return '';
    }

    // ── Sestavení HTML dárkové sekce ─────────────────────────────────────────

    public static function build_gift_html(): string {
        if ( ! WC()->cart || WC()->cart->is_empty() ) return '';

        $product_ids  = self::get_cart_product_ids();
        $category_ids = DD_Package::get_category_ids_for_products( $product_ids );
        $resolved     = DD_Package::resolve_for_cart( $product_ids, $category_ids );
        $email        = self::get_customer_email();
        $available = array_values( array_filter( $resolved['matched'], function ( $pkg ) use ( $email ) {
            if ( ! $email ) return true;
            return DD_Package::has_unsent( (int) $pkg->id, $email );
        } ) );

        $crosssell_pkgs = array_values( array_filter( $resolved['crosssell'], function ( $pkg ) use ( $email ) {
            if ( ! $email ) return DD_Package::document_count( (int) $pkg->id ) > 0;
            return DD_Package::has_unsent( (int) $pkg->id, $email );
        } ) );

        $exhausted_pkgs = [];
        if ( $email ) {
            $exhausted_pkgs = array_values( array_filter( $resolved['matched'], function( $pkg ) use ( $email ) {
                return ! DD_Package::has_unsent( (int) $pkg->id, $email )
                    && DD_Package::document_count( (int) $pkg->id ) > 0;
            } ) );
        }

        if ( empty( $available ) && empty( $crosssell_pkgs ) && empty( $exhausted_pkgs ) ) return '';

        $selected = self::selected_ids_from_cart();
        $selected_id = $selected['selected'];
        $xsell_id    = $selected['crosssell'];

        $available_ids = array_map( 'intval', wp_list_pluck( $available, 'id' ) );
        $crosssell_ids = array_map( 'intval', wp_list_pluck( $crosssell_pkgs, 'id' ) );

        // Checked state is authoritative from the cart only – do not fall back to
        // session here.  Reading session would cause a checkbox to appear checked
        // when the item is not in the cart (stale session from a previous visit).

        if ( $selected_id > 0 && ! in_array( $selected_id, $available_ids, true ) ) {
            $selected_id = 0;
        }
        if ( $xsell_id > 0 && ! in_array( $xsell_id, $crosssell_ids, true ) ) {
            $xsell_id = 0;
        }

        if ( WC()->session ) {
            // Keep session in sync with the actual cart so the lock logic works
            // correctly on every request.
            WC()->session->set( self::SESSION_KEY,   $selected_id );
            WC()->session->set( self::SESSION_XSELL, $xsell_id );
        }

        // Lock state is tracked per slot so that adding a direct package does NOT
        // prevent the user from also choosing a cross-sell package (and vice versa).
        $direct_locked = $selected['selected'] > 0;
        $xsell_locked  = $selected['crosssell'] > 0;
        $has_exact_match  = self::has_direct_match( $resolved['matched'] );
        $fallback_mode    = self::is_fallback_mode( $has_exact_match, $available, $crosssell_pkgs );
        $fallback_categories = self::available_fallback_categories( array_merge( $available, $crosssell_pkgs ) );

        ob_start();
        echo '<div class="dd-gift-section' . ( $direct_locked ? ' dd-gift-locked' : '' ) . '" id="dd-gift-section">';
        echo '<h3 class="dd-gift-heading">🎁 ' . esc_html__( 'Náhodný balíček', 'virtualni-balicek' )
            . ' <button type="button" class="dd-info-btn" aria-label="' . esc_attr__( 'Co je náhodný balíček?', 'virtualni-balicek' ) . '">?</button></h3>';

        // Info popup
        echo '<div class="dd-info-popup" role="tooltip" hidden>';
        echo '<button type="button" class="dd-info-close" aria-label="Zavřít">×</button>';
        echo '<strong>' . esc_html__( 'Co je náhodný balíček?', 'virtualni-balicek' ) . '</strong>';
        echo '<p>' . esc_html__(
            'Náhodný balíček je digitální balíček překvapení, který si můžeš přidat ke své objednávce. '
            . 'Systém ti nabídne balíčky vztahující se ke kategoriím produktů, které sis právě koupil – takže překvapení bude sedět.',
            'virtualni-balicek'
        ) . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html__( '🎲 Obsah je tajný – zjistíš ho až z e-mailu po dokončení objednávky. Očekávej kulinářský tip či babské rady.', 'virtualni-balicek' ) . '</li>';
        echo '<li>' . esc_html__( '🔁 Každý balíček si koupíš maximálně jednou – nikdy nedostaneš stejný obsah dvakrát.', 'virtualni-balicek' ) . '</li>';
        echo '<li>' . esc_html__( '🛒 Můžeš si přidat i balíček z jiné kategorie jako bonus navíc.', 'virtualni-balicek' ) . '</li>';
        echo '<li>' . esc_html__( '📩 Dárek obdržíš jako přílohu e-mailu ihned po zpracování a zaplacení objednávky. Budu rád, pokud mi dáš na Virtuální balíček zpětnou vazbu - líbil se Ti, koupil by sis jej znovu, nebo je to blbost?', 'virtualni-balicek' ) . '</li>';
		  
        echo '</ul>';
        echo '</div>';

        if ( $fallback_mode ) {
            echo '<div class="dd-gift-row dd-gift-fallback">';
            echo '<p class="dd-gift-fallback-msg">' . esc_html__(
                'Pro daný produkt v kategorii pro Tebe nemám Náhodný balíček k dispozici, ale můžeš si vybrat Náhodný balíček z jiných dostupných kategorií:',
                'virtualni-balicek'
            ) . '</p>';
            if ( ! empty( $fallback_categories ) ) {
                echo '<ul class="dd-gift-fallback-cats">';
                foreach ( $fallback_categories as $cat_name ) {
                    echo '<li>' . esc_html( $cat_name ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        if ( $direct_locked ) {
            echo '<p class="dd-gift-lock-msg">' . esc_html__(
                'Balíček je už v košíku. Pro změnu výběru nejdřív odeber balíček z košíku.',
                'virtualni-balicek'
            ) . '</p>';
        }

        // Přímé balíčky – každý jako samostatný checkbox, vzájemně se vylučující
        if ( ! empty( $available ) ) {
            self::render_direct_packages( $available, $selected_id, $email, $product_ids, $direct_locked );
        }

        // Cross-sell
        foreach ( $crosssell_pkgs as $pkg ) {
            self::render_crosssell_checkbox( $pkg, $xsell_id === (int) $pkg->id, $xsell_locked );
        }

        // Vyčerpané
        if ( ! empty( $exhausted_pkgs ) && empty( $available ) ) {
            $exhausted_msg = get_option(
                'dd_exhausted_message',
                'Pro Tebe tu nyní žádný dárek navíc nemám. Ale jakmile nějaký vytvořím, dozvíš se to při další návštěvě košíku ;)'
            );
            echo '<div class="dd-gift-row dd-gift-exhausted">';
            echo '<p class="dd-gift-exhausted-msg">🎁 ' . esc_html( $exhausted_msg ) . '</p>';
            echo '</div>';
        }

        echo '</div>';
        return ob_get_clean();
    }

    // ── Hook pro klasický košík ───────────────────────────────────────────────

    public static function render_gift_ui(): void {
        // Zobraz jen v klasickém (shortcode) košíku
        if ( self::is_block_cart() ) return;
        echo self::build_gift_html(); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    // ── Blokový košík: jednorázový inject ─────────────────────────────────────

    public static function inject_block_cart_init(): void {
        if ( ! is_cart() || ! self::is_block_cart() ) return;
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'dd_cart_nonce' );
        ?>
        <script>
        (function() {
            var injected  = false;
            var attempts  = 0;
            var maxTries  = 20;

            function inject() {
                // Pokud už sekce existuje, zastav
                if (document.getElementById('dd-gift-section')) {
                    injected = true;
                    return;
                }

                // Hledáme vhodné místo v blokovém košíku (sidebar / totals)
                var selectors = [
                    '.wp-block-woocommerce-cart-order-summary-block',
                    '.wc-block-cart__sidebar',
                    '.wc-block-components-totals-wrapper',
                    '.wp-block-woocommerce-cart-totals-block'
                ];
                var container = null;
                for (var i = 0; i < selectors.length; i++) {
                    container = document.querySelector(selectors[i]);
                    if (container) break;
                }
                if (!container) {
                    // Sidebar ještě není v DOM, zkusíme znovu
                    if (++attempts < maxTries) setTimeout(inject, 400);
                    return;
                }

                // Načti HTML jednou přes AJAX
                fetch('<?php echo esc_url( $ajax_url ); ?>?action=dd_get_cart_html&nonce=<?php echo esc_js( $nonce ); ?>')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data.html) return;
                        if (document.getElementById('dd-gift-section')) return; // double-check
                        var div = document.createElement('div');
                        div.innerHTML = data.data.html;
                        var section = div.firstElementChild;
                        if (!section) return;
                        container.insertBefore(section, container.firstChild);
                        injected = true;
                        if (window.DD_Cart_init) window.DD_Cart_init();
                    });
            }

            // Spustíme po DOMContentLoaded + s malým zpožděním pro React
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { setTimeout(inject, 500); });
            } else {
                setTimeout(inject, 500);
            }
        })();
        </script>
        <?php
    }

    // ── AJAX: HTML pro blokový košík ─────────────────────────────────────────

    public static function ajax_get_cart_html(): void {
        check_ajax_referer( 'dd_cart_nonce', 'nonce' );
        wp_send_json_success( [ 'html' => self::build_gift_html() ] );
    }

    // ── Renderovací helpery ───────────────────────────────────────────────────

    /**
     * Každý přímý balíček = vlastní checkbox pojmenovaný podle kategorie z košíku.
     * Vzájemně se vylučují – výběrem jednoho se ostatní odškrtnou (řeší JS).
     * Pokud je jen jeden, zobrazí se jako prostý checkbox.
     */
    private static function render_direct_packages(
        array $packages,
        int $selected_id,
        string $email,
        array $product_ids,
        bool $selection_locked
    ): void {
        $description = get_option( 'dd_cart_description', 'Překvapení čeká – obsah balíčku zjistíš až v e-mailu po objednávce.' );
        $multiple    = count( $packages ) > 1;

        if ( $multiple ) {
            echo '<p class="dd-gift-intro">' . esc_html__( 'Vyber si náhodný balíček z kategorie:', 'virtualni-balicek' ) . '</p>';
        }

        $free_pkg_id = $email ? self::get_cart_first_free_pkg_id( $email ) : null;

        foreach ( $packages as $pkg ) {
            $pkg_int   = (int) $pkg->id;
            $ff        = $email
                && DD_Package::is_first_free_eligible( $pkg_int, $email )
                && ( $free_pkg_id === null || $free_pkg_id === $pkg_int );
            $cat_label  = self::category_label_for_package( $pkg, $product_ids );
            $line_label = $multiple
                ? sprintf( __( 'Náhodný balíček z kategorie %s', 'virtualni-balicek' ), $cat_label )
                : get_option( 'dd_checkbox_label', __( '🎁 Přidat náhodný balíček', 'virtualni-balicek' ) )
                  . ( $cat_label ? ' – ' . $cat_label : '' );

            echo '<div class="dd-gift-row dd-gift-direct">';
            echo '<label class="dd-gift-label">';
            echo '<input type="checkbox"'
                . ' class="dd-pkg-checkbox dd-pkg-direct"'
                . ' data-package="' . esc_attr( $pkg->id ) . '"'
                . ' data-type="direct"'
                . ( $selected_id === (int) $pkg->id ? ' checked' : '' )
                . ( $selection_locked ? ' disabled' : '' ) . '>';
            echo '<span>' . esc_html( $line_label ) . self::price_text_html( $pkg, $ff ) . '</span>'; // phpcs:ignore
            echo '</label>';
            echo '</div>';
        }

        if ( $description ) {
            echo '<p class="dd-gift-desc">' . esc_html( $description ) . '</p>';
        }
    }

    /**
     * Vrátí název kategorie relevantní pro daný balíček a košík.
     * Hledá průnik kategorií pravidel balíčku a kategorií produktů v košíku.
     * Fallback: název balíčku.
     */
    private static function category_label_for_package( object $pkg, array $product_ids ): string {
        $rules = DD_Package::get_rules( (int) $pkg->id );
        if ( empty( $rules ) ) {
            // Univerzální balíček – vrátíme název
            return $pkg->name;
        }

        $rule_cat_ids = array_map(
            'intval',
            array_column(
                array_filter( (array) $rules, fn( $r ) => $r->rule_type === 'category' ),
                'object_id'
            )
        );

        // Najdi kategorie produktů v košíku a vyber průnik s pravidly balíčku
        foreach ( $product_ids as $prod_id ) {
            $terms = get_the_terms( $prod_id, 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) continue;
            foreach ( $terms as $term ) {
                if ( in_array( $term->term_id, $rule_cat_ids, true ) ) {
                    return $term->name;
                }
            }
        }

        // Fallback: název první kategorie v pravidle
        if ( ! empty( $rule_cat_ids ) ) {
            $term = get_term( $rule_cat_ids[0], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) return $term->name;
        }

        return $pkg->name;
    }

    /**
     * @param object[] $packages
     * @return string[]
     */
    private static function available_fallback_categories( array $packages ): array {
        $names = [];
        foreach ( $packages as $pkg ) {
            foreach ( self::package_category_names( $pkg ) as $name ) {
                $names[] = $name;
            }
        }
        $names = array_values( array_unique( array_filter( $names ) ) );
        sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
        return $names;
    }

    /**
     * @param object[] $matched_packages
     */
    private static function has_direct_match( array $matched_packages ): bool {
        foreach ( $matched_packages as $pkg ) {
            if ( ( $pkg->match_reason ?? '' ) === 'direct' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param object[] $available
     * @param object[] $crosssell_pkgs
     */
    private static function is_fallback_mode( bool $has_exact_match, array $available, array $crosssell_pkgs ): bool {
        if ( $has_exact_match ) {
            return false;
        }
        return ! empty( $available ) || ! empty( $crosssell_pkgs );
    }

    /**
     * @return string[]
     */
    private static function package_category_names( object $pkg ): array {
        $names = [];
        $rules = DD_Package::get_rules( (int) $pkg->id );
        foreach ( $rules as $rule ) {
            if ( ( $rule->rule_type ?? '' ) !== 'category' ) {
                continue;
            }
            $term = get_term( (int) $rule->object_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $names[] = $term->name;
            }
        }
        return $names;
    }

    /**
     * Vytvoří popisek DD položky do řádku košíku včetně kategorie.
     *
     * @param object $pkg Balíček DD.
     * @return string
     */
    private static function virtual_item_label( object $pkg ): string {
        $cart_product_ids = self::get_cart_product_ids();
        $category         = self::category_label_for_package( $pkg, $cart_product_ids );
        $base             = __( 'Virtuální balíček', 'virtualni-balicek' );

        if ( $category ) {
            return sprintf(
                /* translators: %s: category label */
                __( '%1$s z kategorie %2$s', 'virtualni-balicek' ),
                $base,
                $category
            );
        }

        return $base . ' – ' . (string) $pkg->name;
    }

    /**
     * Vrátí HTML s cenou – při first_free zobrazí přeškrtnutou původní cenu.
     * Smí obsahovat HTML tagy (<s>), neescapovat při výpisu.
     */
    private static function price_text_html( object $pkg, bool $first_free = false ): string {
        if ( (float) $pkg->price <= 0 ) {
            return ' <span class="dd-price-free">(' . esc_html__( 'zdarma', 'virtualni-balicek' ) . ')</span>';
        }
        if ( $first_free ) {
            $original = wp_strip_all_tags( wc_price( $pkg->price ) );
            return ' <span class="dd-price-firstfree">(<s>' . $original . '</s>&nbsp;'
                . esc_html__( 'první zdarma', 'virtualni-balicek' ) . ')</span>';
        }
        return ' <span class="dd-price">(+' . wp_strip_all_tags( wc_price( $pkg->price ) ) . ')</span>';
    }

    private static function render_crosssell_checkbox( object $pkg, bool $checked, bool $selection_locked ): void {
        $email       = self::get_customer_email();
        $free_pkg_id = $email ? self::get_cart_first_free_pkg_id( $email ) : null;
        $pkg_int     = (int) $pkg->id;
        $ff          = $email
            && DD_Package::is_first_free_eligible( $pkg_int, $email )
            && ( $free_pkg_id === null || $free_pkg_id === $pkg_int );
        $template = get_option( 'dd_crosssell_label', 'Zajímá tě také náhodný balíček ze sekce {package_name}?' );
        $label    = str_replace( '{package_name}', $pkg->name, $template );
        ?>
        <div class="dd-gift-row dd-gift-crosssell<?php echo $selection_locked ? ' dd-gift-crosssell-locked' : ''; ?>">
            <label class="dd-gift-label">
                <input type="checkbox"
                       class="dd-pkg-checkbox"
                       data-package="<?php echo esc_attr( $pkg->id ); ?>"
                       data-type="crosssell"
                       <?php disabled( $selection_locked ); ?>
                       <?php checked( $checked ); ?>>
                <span><?php echo esc_html( $label ); ?><?php echo self::price_text_html( $pkg, $ff ); // phpcs:ignore ?></span>
            </label>
        </div>
        <?php
    }

    // ── AJAX: zákazník vybral ─────────────────────────────────────────────────

    public static function ajax_select(): void {
        check_ajax_referer( 'dd_cart_nonce', 'nonce' );

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        if ( ! WC()->cart ) {
            wp_send_json_error( [ 'message' => __( 'Košík není dostupný.', 'virtualni-balicek' ) ] );
        }

        $package_id    = (int) ( $_POST['package_id'] ?? 0 );
        $type_raw      = sanitize_key( $_POST['type'] ?? 'direct' );
        $allowed_types = [ 'direct', 'crosssell' ];
        if ( ! in_array( $type_raw, $allowed_types, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Neplatný typ balíčku.', 'virtualni-balicek' ) ] );
        }
        $type    = $type_raw;
        $checked = (bool) absint( $_POST['checked'] ?? 1 );

        if ( $package_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Neplatné ID balíčku.', 'virtualni-balicek' ) ] );
        }

        if ( $checked ) {
            wc_clear_notices();
            $cart_key = self::add_package_to_cart( $package_id, $type );
            if ( ! $cart_key ) {
                wp_send_json_error( [
                    'message'    => __( 'Balíček se nepodařilo přidat do košíku.', 'virtualni-balicek' ),
                    'wc_notices' => self::get_wc_error_notice_texts(),
                ] );
            }
        } else {
            self::remove_package_from_cart( $package_id, $type );
        }

        $session_selected = self::sync_selection_session_from_cart();

        WC()->cart->calculate_totals();
        WC()->cart->set_session();
        if ( method_exists( WC()->cart, 'maybe_set_cart_cookies' ) ) {
            WC()->cart->maybe_set_cart_cookies();
        }

        $dd_items = array_values( array_map(
            static function ( array $item ): array {
                return [
                    'package_id' => (int) ( $item[ self::CART_ITEM_KEY ] ?? 0 ),
                    'type'       => (string) ( $item['dd_type'] ?? 'direct' ),
                ];
            },
            self::get_dd_cart_items()
        ) );

        wp_send_json_success( [
            'html'      => self::build_gift_html(),
            'dd_items'  => $dd_items,
            'dd_count'  => count( $dd_items ),
            'cart_size' => count( WC()->cart->get_cart() ),
            'session'   => [
                'selected'  => $session_selected['selected'],
                'crosssell' => $session_selected['crosssell'],
            ],
        ] );
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    private static function cart_css(): string {
        return '
        .dd-gift-section{margin:1.2em 0;padding:1em 1.2em;border:2px dashed #f0a500;border-radius:8px;background:#fffdf0;}
        .dd-gift-heading{margin:0 0 .7em;color:#7a5500;}
        .dd-gift-row{margin-bottom:.6em;}
        .dd-gift-row:last-child{margin-bottom:0;}
        .dd-gift-label{display:flex;align-items:flex-start;gap:.5em;cursor:pointer;font-weight:600;}
        .dd-gift-label input{margin-top:.2em;width:1.1em;height:1.1em;flex-shrink:0;}
        .dd-gift-label input:disabled{cursor:not-allowed;}
        .dd-gift-locked .dd-gift-label{opacity:.6;cursor:not-allowed;}
        .dd-gift-lock-msg{margin:.3em 0 .8em;color:#8a6d3b;font-size:.95em;}
        .dd-gift-fallback{margin:.3em 0 .9em;padding:.65em .8em;border:1px solid #f0d8a8;border-radius:6px;background:#fff7e8;}
        .dd-gift-fallback-msg{margin:0 0 .45em;font-weight:600;color:#7a5500;}
        .dd-gift-fallback-cats{margin:0;padding-left:1.2em;color:#6d5b3d;}
        .dd-gift-fallback-cats li{margin:.1em 0;}
        .dd-gift-desc{margin:.4em 0 0 0;color:#555;}
        .dd-gift-intro{margin:0 0 .5em;font-weight:600;}
        .dd-gift-crosssell{border-top:1px dashed #ccc;padding-top:.6em;margin-top:.6em;}
        .dd-gift-crosssell .dd-gift-label{color:#555;font-weight:normal;}
        /* When the container is locked (direct package chosen), crosssell row stays active
           unless the crosssell slot itself is also locked (dd-gift-crosssell-locked). */
        .dd-gift-locked .dd-gift-crosssell:not(.dd-gift-crosssell-locked) .dd-gift-label{opacity:1;cursor:pointer;}
        .dd-gift-exhausted{border-top:1px dashed #ccc;padding-top:.6em;margin-top:.4em;}
        .dd-gift-exhausted-msg{margin:0;color:#777;font-style:italic;}
        .dd-price-firstfree{color:#2ecc71;font-weight:700;}
        .dd-price-firstfree s{color:#999;font-weight:normal;text-decoration:line-through;}

        /* Info tlačítko */
        .dd-info-btn{
            display:inline-flex;align-items:center;justify-content:center;
            width:18px;height:18px;border-radius:50%;
            background:#f0a500;color:#fff;font-size:.72em;font-weight:700;
            border:none;cursor:pointer;line-height:1;padding:0;
            vertical-align:middle;margin-left:.35em;flex-shrink:0;
        }
        .dd-info-btn:hover{background:#c07800;}

        /* Info popup – křížek vpravo nahoře, ale mimo tok textu díky paddingu */
        .dd-info-popup{
            position:relative;
            background:#fff;border:1px solid #ddd;border-radius:8px;
            padding:.9em 2.4em .9em 1.1em;
            margin:.5em 0 .4em;
            line-height:1.6;
            box-shadow:0 2px 8px rgba(0,0,0,.1);
        }
        .dd-info-popup[hidden]{display:none;}
        .dd-info-popup strong{display:block;margin-bottom:.35em;}
        .dd-info-popup p{margin:.3em 0 .5em;}
        .dd-info-popup ul{margin:.2em 0 0;padding-left:1.3em;}
        .dd-info-popup li{margin-bottom:.3em;}
        .dd-info-close{
            position:absolute !important;top:.45em !important;right:.5em !important;
            background:none !important;border:none !important;cursor:pointer !important;
            font-size:1.1em !important;color:#999 !important;line-height:1 !important;
            padding:0 !important;margin:0 !important;
            width:auto !important;height:auto !important;
            min-width:0 !important;min-height:0 !important;
            box-shadow:none !important;border-radius:0 !important;
            display:inline !important;
        }
        .dd-info-close:hover{color:#333 !important;background:none !important;box-shadow:none !important;}
        .dd-cart-item .product-quantity,
        .dd-cart-item td.product-quantity,
        .dd-cart-item .wc-block-components-quantity-selector,
        .dd-cart-item .wc-block-cart-item__quantity,
        .dd-cart-item .quantity,
        .dd-cart-item .qty,
        .dd-cart-item .plus,
        .dd-cart-item .minus,
        .dd-cart-item [class*="quantity"] button,
        tr:has([data-dd-gift-item="1"]) .product-quantity,
        tr:has([data-dd-gift-item="1"]) td.product-quantity,
        tr:has([data-dd-gift-item="1"]) .quantity,
        tr:has([data-dd-gift-item="1"]) .qty,
        tr:has([data-dd-gift-item="1"]) .plus,
        tr:has([data-dd-gift-item="1"]) .minus,
        li:has([data-dd-gift-item="1"]) .wc-block-components-quantity-selector,
        li:has([data-dd-gift-item="1"]) .wc-block-cart-item__quantity,
        li:has([data-dd-gift-item="1"]) [class*="quantity"] button{
            display:none !important;
        }
        .dd-cart-item-label{display:inline-flex;align-items:center;gap:.35em;}
        .dd-cart-item-icon{width:1em;height:1em;display:inline-block;flex:0 0 1em;vertical-align:middle;}
        ';
    }
}