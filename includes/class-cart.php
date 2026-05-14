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
        echo '<h3 class="dd-gift-heading">🎁 ' . esc_html__( 'Náhodný balíček', 'dobrovolny-darek' )
            . ' <button type="button" class="dd-info-btn" aria-label="' . esc_attr__( 'Co je náhodný balíček?', 'dobrovolny-darek' ) . '">?</button></h3>';

        // Info popup
        echo '<div class="dd-info-popup" role="tooltip" hidden>';
        echo '<button type="button" class="dd-info-close" aria-label="Zavřít">×</button>';
        echo '<strong>' . esc_html__( 'Co je náhodný balíček?', 'dobrovolny-darek' ) . '</strong>';
        echo '<p>' . esc_html__(
            'Náhodný balíček je digitální balíček překvapení, který si můžeš přidat ke své objednávce. '
            . 'Systém ti nabídne balíčky vztahující se ke kategoriím produktů, které sis právě koupil – takže překvapení bude sedět.',
            'dobrovolny-darek'
        ) . '</p>';
        echo '<ul>';
        echo '<li>' . esc_html__( '🎲 Obsah je tajný – zjistíš ho až z e-mailu po dokončení objednávky.', 'dobrovolny-darek' ) . '</li>';
        echo '<li>' . esc_html__( '🔁 Každý balíček si koupíš maximálně jednou – nikdy nedostaneš stejný obsah dvakrát.', 'dobrovolny-darek' ) . '</li>';
        echo '<li>' . esc_html__( '🛒 Můžeš si přidat i balíček z jiné kategorie jako bonus navíc.', 'dobrovolny-darek' ) . '</li>';
        echo '<li>' . esc_html__( '📩 Dárek obdržíš jako přílohu e-mailu ihned po zpracování a zaplacení objednávky.', 'dobrovolny-darek' ) . '</li>';
        echo '</ul>';
        echo '</div>';

        // Přímé balíčky – každý jako samostatný checkbox, vzájemně se vylučující
        if ( ! empty( $available ) ) {
            self::render_direct_packages( $available, $selected_id, $email, $product_ids, $category_ids );
        }

        // Cross-sell
        foreach ( $crosssell_pkgs as $pkg ) {
            self::render_crosssell_checkbox( $pkg, $xsell_id === (int) $pkg->id );
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
        array $category_ids
    ): void {
        $description = get_option( 'dd_cart_description', 'Překvapení čeká – obsah balíčku zjistíš až v e-mailu po objednávce.' );
        $multiple    = count( $packages ) > 1;

        if ( $multiple ) {
            echo '<p class="dd-gift-intro">' . esc_html__( 'Vyber si náhodný balíček z kategorie:', 'dobrovolny-darek' ) . '</p>';
        }

        foreach ( $packages as $pkg ) {
            $ff         = $email ? DD_Package::is_first_free_eligible( (int) $pkg->id, $email ) : false;
            $cat_label  = self::category_label_for_package( $pkg, $product_ids );
            $line_label = $multiple
                ? sprintf( __( 'Náhodný balíček z kategorie %s', 'dobrovolny-darek' ), $cat_label )
                : get_option( 'dd_checkbox_label', __( '🎁 Přidat náhodný balíček', 'dobrovolny-darek' ) )
                  . ( $cat_label ? ' – ' . $cat_label : '' );

            echo '<div class="dd-gift-row dd-gift-direct">';
            echo '<label class="dd-gift-label">';
            echo '<input type="checkbox"'
                . ' class="dd-pkg-checkbox dd-pkg-direct"'
                . ' data-package="' . esc_attr( $pkg->id ) . '"'
                . ' data-type="direct"'
                . ( $selected_id === (int) $pkg->id ? ' checked' : '' ) . '>';
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
     * Vrátí HTML s cenou – při first_free zobrazí přeškrtnutou původní cenu.
     * Smí obsahovat HTML tagy (<s>), neescapovat při výpisu.
     */
    private static function price_text_html( object $pkg, bool $first_free = false ): string {
        if ( (float) $pkg->price <= 0 ) {
            return ' <span class="dd-price-free">(' . esc_html__( 'zdarma', 'dobrovolny-darek' ) . ')</span>';
        }
        if ( $first_free ) {
            $original = wp_strip_all_tags( wc_price( $pkg->price ) );
            return ' <span class="dd-price-firstfree">(<s>' . $original . '</s>&nbsp;'
                . esc_html__( 'první zdarma', 'dobrovolny-darek' ) . ')</span>';
        }
        return ' <span class="dd-price">(+' . wp_strip_all_tags( wc_price( $pkg->price ) ) . ')</span>';
    }

    private static function render_crosssell_checkbox( object $pkg, bool $checked ): void {
        $email    = self::get_customer_email();
        $ff       = $email ? DD_Package::is_first_free_eligible( (int) $pkg->id, $email ) : false;
        $template = get_option( 'dd_crosssell_label', 'Zajímá tě také náhodný balíček ze sekce {package_name}?' );
        $label    = str_replace( '{package_name}', $pkg->name, $template );
        ?>
        <div class="dd-gift-row dd-gift-crosssell">
            <label class="dd-gift-label">
                <input type="checkbox"
                       class="dd-pkg-checkbox"
                       data-package="<?php echo esc_attr( $pkg->id ); ?>"
                       data-type="crosssell"
                       <?php checked( $checked ); ?>>
                <span><?php echo esc_html( $label ); ?><?php echo self::price_text_html( $pkg, $ff ); // phpcs:ignore ?></span>
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

        $email = self::get_customer_email();

        $add_fee = function ( int $pkg_id, string $suffix = '' ) use ( $cart, $email ) {
            if ( $pkg_id <= 0 ) return;
            $pkg = DD_Package::get( $pkg_id );
            if ( ! $pkg || ! $pkg->active || (float) $pkg->price <= 0 ) return;

            // První dárek zdarma – přeskoč fee
            if ( DD_Package::is_first_free_eligible( $pkg_id, $email ) ) return;

            $label = get_option( 'dd_checkbox_label', __( 'náhodný balíček', 'dobrovolny-darek' ) );
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
        .dd-gift-heading{margin:0 0 .7em;color:#7a5500;}
        .dd-gift-row{margin-bottom:.6em;}
        .dd-gift-row:last-child{margin-bottom:0;}
        .dd-gift-label{display:flex;align-items:flex-start;gap:.5em;cursor:pointer;font-weight:600;}
        .dd-gift-label input{margin-top:.2em;width:1.1em;height:1.1em;flex-shrink:0;}
        .dd-gift-desc{margin:.4em 0 0 0;color:#555;}
        .dd-gift-intro{margin:0 0 .5em;font-weight:600;}
        .dd-gift-crosssell{border-top:1px dashed #ccc;padding-top:.6em;margin-top:.6em;}
        .dd-gift-crosssell .dd-gift-label{color:#555;font-weight:normal;}
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
            position:absolute;top:.55em;right:.65em;
            background:none;border:none;cursor:pointer;
            font-size:.85em;color:#aaa;line-height:1;padding:2px 4px;
            border-radius:3px;
        }
        .dd-info-close:hover{color:#333;background:#f0f0f0;}
        ';
    }
}
