<?php
defined( 'ABSPATH' ) || exit;

class DD_Package {

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dd_packages ORDER BY active DESC, id DESC" ) ?: [];
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dd_packages WHERE id = %d", $id ) );
    }

    public static function get_active_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dd_packages WHERE active = 1 ORDER BY id DESC" ) ?: [];
    }

    public static function get_active_by_category( int $category_id, bool $include_children = true ): array {
        $category_id = absint( $category_id );
        if ( ! $category_id ) {
            return [];
        }

        $category_ids = [ $category_id ];
        if ( $include_children ) {
            $children = get_term_children( $category_id, 'product_cat' );
            if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
                $category_ids = array_values( array_unique( array_map( 'absint', array_merge( $category_ids, $children ) ) ) );
            }
        }

        $placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );

        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.*
             FROM {$wpdb->prefix}dd_packages p
             INNER JOIN {$wpdb->prefix}dd_package_rules r ON r.package_id = p.id
             WHERE p.active = 1 AND r.rule_type = 'category' AND r.object_id IN ($placeholders)
             ORDER BY p.id DESC",
            $category_ids
        );

        return $wpdb->get_results( $sql ) ?: [];
    }

    // ── Pravidla přiřazení ────────────────────────────────────────────────────

    public static function get_rules( int $package_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT rule_type, object_id FROM {$wpdb->prefix}dd_package_rules WHERE package_id = %d",
            $package_id
        ) ) ?: [];
    }

    public static function save_rules( int $package_id, array $category_ids, array $product_ids ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'dd_package_rules';
        $wpdb->delete( $table, [ 'package_id' => $package_id ], [ '%d' ] );

        foreach ( $category_ids as $cat_id ) {
            $cat_id = absint( $cat_id );
            if ( $cat_id ) {
                $wpdb->insert( $table, [ 'package_id' => $package_id, 'rule_type' => 'category', 'object_id' => $cat_id ], [ '%d', '%s', '%d' ] );
            }
        }
        foreach ( $product_ids as $prod_id ) {
            $prod_id = absint( $prod_id );
            if ( $prod_id ) {
                $wpdb->insert( $table, [ 'package_id' => $package_id, 'rule_type' => 'product', 'object_id' => $prod_id ], [ '%d', '%s', '%d' ] );
            }
        }
    }

    /**
     * Vrátí balíčky pasující na obsah košíku (kategorie + produkty).
     * Balíčky bez pravidel platí pro všechny (universal).
     *
     * @param int[] $cart_product_ids   IDs produktů v košíku
     * @param int[] $cart_category_ids  Všechny term IDs kategorií produktů v košíku
     * @return object[]  Pole balíčků rozdělených do 'matched' a 'crosssell'
     */
    public static function resolve_for_cart( array $cart_product_ids, array $cart_category_ids ): array {
        $all_active = self::get_active_all();
        if ( empty( $all_active ) ) return [ 'matched' => [], 'crosssell' => [] ];

        $matched   = [];
        $crosssell = [];

        foreach ( $all_active as $pkg ) {
            $rules = self::get_rules( (int) $pkg->id );

            // Balíček bez pravidel = univerzální, vždy pasuje
            if ( empty( $rules ) ) {
                $pkg->match_reason = 'universal';
                $matched[] = $pkg;
                continue;
            }

            $rule_cats  = array_map( 'intval', array_column( array_filter( (array) $rules, fn($r) => $r->rule_type === 'category' ), 'object_id' ) );
            $rule_prods = array_map( 'intval', array_column( array_filter( (array) $rules, fn($r) => $r->rule_type === 'product'  ), 'object_id' ) );

            $cat_match  = ! empty( $rule_cats )  && ! empty( array_intersect( $rule_cats,  $cart_category_ids ) );
            $prod_match = ! empty( $rule_prods ) && ! empty( array_intersect( $rule_prods, $cart_product_ids  ) );

            if ( $cat_match || $prod_match ) {
                $pkg->match_reason = 'direct';
                $matched[] = $pkg;
            } else {
                // Neodpovídá košíku → cross-sell kandidát
                $pkg->match_reason = 'crosssell';
                $crosssell[] = $pkg;
            }
        }

        return [ 'matched' => $matched, 'crosssell' => $crosssell ];
    }

    // ── Dokumenty ─────────────────────────────────────────────────────────────

    public static function get_documents( int $package_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dd_documents WHERE package_id = %d ORDER BY created_at ASC",
            $package_id
        ) ) ?: [];
    }

    public static function get_unsent_document_ids( int $package_id, string $email ): array {
        global $wpdb;
        $all_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dd_documents WHERE package_id = %d", $package_id
        ) );
        if ( empty( $all_ids ) ) return [];

        $sent_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT document_id FROM {$wpdb->prefix}dd_sent WHERE package_id = %d AND user_email = %s",
            $package_id, $email
        ) );
        return array_values( array_diff( $all_ids, $sent_ids ) );
    }

    public static function pick_random_unsent( int $package_id, string $email ): ?object {
        $unsent_ids = self::get_unsent_document_ids( $package_id, $email );
        if ( empty( $unsent_ids ) ) return null;
        $pick_id = $unsent_ids[ array_rand( $unsent_ids ) ];
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dd_documents WHERE id = %d", $pick_id ) );
    }

    public static function record_sent( int $package_id, int $document_id, string $email, int $order_id, ?int $user_id = null ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'dd_sent',
            [ 'user_id' => $user_id, 'user_email' => $email, 'package_id' => $package_id, 'document_id' => $document_id, 'order_id' => $order_id ],
            [ '%d', '%s', '%d', '%d', '%d' ]
        );
    }

    public static function has_unsent( int $package_id, string $email ): bool {
        return ! empty( self::get_unsent_document_ids( $package_id, $email ) );
    }

    public static function document_count( int $package_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dd_documents WHERE package_id = %d", $package_id
        ) );
    }

    // ── První dárek zdarma ────────────────────────────────────────────────────

    /**
     * Vrátí true, pokud má zákazník nárok na první dárek zdarma z daného balíčku.
     *
     * Globální logika: zákazník dostane "první zdarma" pouze jednou napříč
     * VŠEMI balíčky s first_free = 1. Jakmile ho jednou čerpal (u jakéhokoli
     * first_free balíčku), nárok zaniká i pro ostatní first_free balíčky.
     *
     * Podmínky:
     *   1. Daný balíček má first_free = 1.
     *   2. Zákazník dosud neobdržel dárek z žádného balíčku s first_free = 1.
     */
    public static function is_first_free_eligible( int $package_id, string $email ): bool {
        global $wpdb;

        $pkg = self::get( $package_id );
        if ( ! $pkg || ! (int) $pkg->first_free ) return false;
        if ( ! $email ) return true; // neznámý zákazník → optimisticky zdarma

        // Zjisti IDs všech aktivních first_free balíčků
        $first_free_ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}dd_packages WHERE first_free = 1 AND active = 1"
        );
        if ( empty( $first_free_ids ) ) return true;

        $placeholders = implode( ',', array_fill( 0, count( $first_free_ids ), '%d' ) );
        $args         = array_merge( [ $email ], array_map( 'intval', $first_free_ids ) );

        $already_used = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dd_sent
                 WHERE user_email = %s AND package_id IN ($placeholders)",
                $args
            )
        );

        return $already_used === 0;
    }

    // ── Helpers pro košík ─────────────────────────────────────────────────────

    /**
     * Vrátí všechna term_id kategorií (včetně rodičovských) pro seznam produktů.
     */
    public static function get_category_ids_for_products( array $product_ids ): array {
        $cat_ids = [];
        foreach ( $product_ids as $prod_id ) {
            $terms = get_the_terms( $prod_id, 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) continue;
            foreach ( $terms as $term ) {
                $cat_ids[] = $term->term_id;
                // Přidej i rodičovské kategorie
                $ancestors = get_ancestors( $term->term_id, 'product_cat' );
                foreach ( $ancestors as $anc ) $cat_ids[] = $anc;
            }
        }
        return array_unique( array_map( 'intval', $cat_ids ) );
    }
}
