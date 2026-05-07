<?php
defined( 'ABSPATH' ) || exit;

class DD_Cart {

    const SESSION_KEY   = 'dd_selected_package';
    const SESSION_XSELL = 'dd_crosssell_package';

    public static function init(): void {
        // Klasický shortcode košík
        add_action( 'woocommerce_before_cart_totals', [ __CLASS__, 'render_gift_ui' ] );

        // Skripty
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

        // Poplatek
        add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'apply_fee' ] );

        // Checkout refresh
        add_action( 'woocommerce_checkout_update_order_review', [ __CLASS__, 'update_session_from_post' ] );

        // AJAX
        add_action( 'wp_ajax_dd_select_package',        [ __CLASS__, 'ajax_select' ] );
        add_action( 'wp_ajax_nopriv_dd_select_package', [ __CLASS__, 'ajax_select' ] );
        add_action( 'wp_ajax_dd_get_cart_html',         [ __CLASS__, 'ajax_get_cart_html' ] );
        add_action( 'wp_ajax_nopriv_dd_get_cart_html',  [ __CLASS__, 'ajax_get_cart_html' ] );

        // Blokový košík – jednorázový inject přes footer
        add_action( 'wp_footer', [ __CLASS__, 'inject_block_cart_init' ] );
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
        ] );

        wp_add_inline_style( 'woocommerce-general', self::cart_css() );
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
            $ids[] = (int) $item['product_id'];
        }
        return array_unique( $ids );
    }

    public static function get_customer_email(): string {
        if ( is_user_logged_in() ) return wp_get_current_user()->user_email;
        $email = WC()->session ? WC()->session->get( 'billing_email' ) : '';
        return sanitize_email( $email ?: '' );
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

        // Balíčky kde zákazník vyčerpal vše (jen pro přihlášené nebo known email)
        $exhausted_pkgs = [];
        if ( $email ) {
            $exhausted_pkgs = array_values( array_filter( $resolved['matched'], function( $pkg ) use ( $email ) {
                return ! DD_Package::has_unsent( (int) $pkg->id, $email )
                    && DD_Package::document_count( (int) $pkg->id ) > 0;
            } ) );
        }

        if ( empty( $available ) && empty( $crosssell_pkgs ) && empty( $exhausted_pkgs ) ) return '';

        $selected_id = (int) ( WC()->session ? WC()->session->get( self::SESSION_KEY ) : 0 );
        $xsell_id    = (int) ( WC()->session ? WC()->session->get( self::SESSION_XSELL ) : 0 );

        ob_start();
        echo '<div class="dd-gift-section" id="dd-gift-section">';
        echo '<h3 class="dd-gift-heading">🎁 ' . esc_html__( 'Tajný dárek', 'dobrovolny-darek' ) . '</h3>';

        if ( ! empty( $available ) ) {
            if ( count( $available ) === 1 ) {
                self::render_single_checkbox( $available[0], $selected_id === (int) $available[0]->id );
            } else {
                self::render_radio_group( $available, $selected_id );
            }
        }

        foreach ( $crosssell_pkgs as $pkg ) {
            self::render_crosssell_checkbox( $pkg, $xsell_id === (int) $pkg->id );
        }

        // Zpráva pro vyčerpané balíčky (bez zbývajících dokumentů)
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

    private static function price_text( object $pkg ): string {
        return (float) $pkg->price > 0
            ? ' (+' . wp_strip_all_tags( wc_price( $pkg->price ) ) . ')'
            : ' (' . __( 'zdarma', 'dobrovolny-darek' ) . ')';
    }

    private static function render_single_checkbox( object $pkg, bool $checked ): void {
        $label       = get_option( 'dd_checkbox_label', '🎁 Přidat tajný dárek' );
        $description = get_option( 'dd_cart_description', 'Překvapení čeká – obsah dárku zjistíte až v e-mailu po objednávce.' );
        ?>
        <div class="dd-gift-row dd-gift-direct">
            <label class="dd-gift-label">
                <input type="checkbox"
                       class="dd-pkg-checkbox"
                       data-package="<?php echo esc_attr( $pkg->id ); ?>"
                       data-type="direct"
                       <?php checked( $checked ); ?>>
                <span><?php echo esc_html( $label . self::price_text( $pkg ) ); ?></span>
            </label>
            <?php if ( $description ) : ?>
                <p class="dd-gift-desc"><?php echo esc_html( $description ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_radio_group( array $packages, int $selected_id ): void {
        $description = get_option( 'dd_cart_description', 'Překvapení čeká – obsah dárku zjistíte až v e-mailu po objednávce.' );
        echo '<div class="dd-gift-row dd-gift-multi">';
        echo '<p class="dd-gift-intro">' . esc_html__( 'Vyberte tajný dárek:', 'dobrovolny-darek' ) . '</p>';
        echo '<label class="dd-radio-label"><input type="radio" name="dd_package_radio" class="dd-pkg-radio" value="0" data-type="direct" '
            . checked( $selected_id, 0, false ) . '> ' . esc_html__( 'Nechci dárek', 'dobrovolny-darek' ) . '</label>';

        foreach ( $packages as $pkg ) {
            echo '<label class="dd-radio-label"><input type="radio" name="dd_package_radio" class="dd-pkg-radio" value="'
                . esc_attr( $pkg->id ) . '" data-type="direct" ' . checked( $selected_id, $pkg->id, false ) . '> '
                . esc_html( $pkg->name . self::price_text( $pkg ) ) . '</label>';
        }

        // Cena pro náhodný výběr = průměr nebo min cena balíčků
        $prices = array_map( fn($p) => (float) $p->price, $packages );
        $has_paid = array_filter( $prices, fn($p) => $p > 0 );
        if ( count( $has_paid ) > 0 ) {
            $random_price_text = ' (+' . wp_strip_all_tags( wc_price( min( $has_paid ) ) ) . '–' . wp_strip_all_tags( wc_price( max( $has_paid ) ) ) . ')';
        } else {
            $random_price_text = ' (' . __( 'zdarma', 'dobrovolny-darek' ) . ')';
        }
        echo '<label class="dd-radio-label"><input type="radio" name="dd_package_radio" class="dd-pkg-radio" value="-1" data-type="direct" '
            . checked( $selected_id, -1, false ) . '> ' . esc_html( '🎲 ' . __( 'Náhodný výběr', 'dobrovolny-darek' ) . $random_price_text ) . '</label>';

        if ( $description ) echo '<p class="dd-gift-desc">' . esc_html( $description ) . '</p>';
        echo '</div>';
    }

    private static function render_crosssell_checkbox( object $pkg, bool $checked ): void {
        $template = get_option( 'dd_crosssell_label', 'Zajímá tě také tajný dárek ze sekce {package_name}?' );
        $label    = str_replace( '{package_name}', $pkg->name, $template );
        ?>
        <div class="dd-gift-row dd-gift-crosssell">
            <label class="dd-gift-label">
                <input type="checkbox"
                       class="dd-pkg-checkbox"
                       data-package="<?php echo esc_attr( $pkg->id ); ?>"
                       data-type="crosssell"
                       <?php checked( $checked ); ?>>
                <span><?php echo esc_html( $label . self::price_text( $pkg ) ); ?></span>
            </label>
        </div>
        <?php
    }

    // ── AJAX: zákazník vybral ─────────────────────────────────────────────────

    public static function ajax_select(): void {
        check_ajax_referer( 'dd_cart_nonce', 'nonce' );

        $package_id = (int) ( $_POST['package_id'] ?? 0 );
        $type       = sanitize_key( $_POST['type'] ?? 'direct' );
        $checked    = (bool) absint( $_POST['checked'] ?? 1 );

        if ( $type === 'crosssell' ) {
            WC()->session->set( self::SESSION_XSELL, $checked ? $package_id : 0 );
        } else {
            WC()->session->set( self::SESSION_KEY, $checked ? $package_id : 0 );
        }

        WC()->cart->calculate_totals();
        wp_send_json_success( [ 'html' => self::build_gift_html() ] );
    }

    // ── Update session z checkout ─────────────────────────────────────────────

    public static function update_session_from_post( string $post_data ): void {
        parse_str( $post_data, $fields );
        if ( isset( $fields['dd_package_radio'] ) ) {
            WC()->session->set( self::SESSION_KEY, (int) $fields['dd_package_radio'] );
        }
        WC()->session->set(
            self::SESSION_XSELL,
            ! empty( $fields['dd_crosssell_pkg'] ) ? (int) $fields['dd_crosssell_pkg'] : 0
        );
    }

    // ── Fee ───────────────────────────────────────────────────────────────────

    public static function apply_fee( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! WC()->session ) return;

        $add_fee = function ( int $pkg_id, string $suffix = '' ) use ( $cart ) {
            if ( $pkg_id <= 0 ) return;
            $pkg = DD_Package::get( $pkg_id );
            if ( ! $pkg || ! $pkg->active || (float) $pkg->price <= 0 ) return;
            $label = get_option( 'dd_checkbox_label', __( 'Tajný dárek', 'dobrovolny-darek' ) );
            $cart->add_fee( $label . $suffix, (float) $pkg->price, true );
        };

        $selected = (int) WC()->session->get( self::SESSION_KEY );
        $xsell    = (int) WC()->session->get( self::SESSION_XSELL );

        if ( $selected === -1 ) {
            // Náhodný výběr – spočítej fee z prvního dostupného balíčku
            $product_ids  = self::get_cart_product_ids();
            $category_ids = DD_Package::get_category_ids_for_products( $product_ids );
            $resolved     = DD_Package::resolve_for_cart( $product_ids, $category_ids );
            if ( ! empty( $resolved['matched'] ) ) {
                $add_fee( (int) $resolved['matched'][0]->id, ' 🎲' );
            }
        } else {
            $add_fee( $selected );
        }

        if ( $xsell > 0 ) $add_fee( $xsell );
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    private static function cart_css(): string {
        return '
        .dd-gift-section{margin:1.2em 0;padding:1em 1.2em;border:2px dashed #f0a500;border-radius:8px;background:#fffdf0;}
        .dd-gift-heading{margin:0 0 .7em;font-size:1em;color:#7a5500;}
        .dd-gift-row{margin-bottom:.6em;}
        .dd-gift-row:last-child{margin-bottom:0;}
        .dd-gift-label{display:flex;align-items:flex-start;gap:.5em;cursor:pointer;font-weight:600;}
        .dd-gift-label input{margin-top:.15em;width:1.1em;height:1.1em;flex-shrink:0;}
        .dd-gift-desc{margin:.3em 0 0 1.6em;font-size:.88em;color:#666;}
        .dd-gift-intro{margin:0 0 .4em;font-weight:600;font-size:.95em;}
        .dd-radio-label{display:flex;align-items:center;gap:.5em;margin:.3em 0;cursor:pointer;}
        .dd-radio-label input{width:1.1em;height:1.1em;}
        .dd-gift-crosssell{border-top:1px dashed #ccc;padding-top:.6em;margin-top:.6em;}
        .dd-gift-crosssell .dd-gift-label{color:#555;font-weight:normal;font-size:.92em;}
        .dd-gift-exhausted{border-top:1px dashed #ccc;padding-top:.6em;margin-top:.4em;}
        .dd-gift-exhausted-msg{margin:0;font-size:.92em;color:#777;font-style:italic;}
        ';
    }
}
