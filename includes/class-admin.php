<?php
defined( 'ABSPATH' ) || exit;

class DD_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_dd_save_package',    [ __CLASS__, 'ajax_save_package' ] );
        add_action( 'wp_ajax_dd_delete_package',  [ __CLASS__, 'ajax_delete_package' ] );
        add_action( 'wp_ajax_dd_toggle_package',  [ __CLASS__, 'ajax_toggle_package' ] );
        add_action( 'wp_ajax_dd_upload_document', [ __CLASS__, 'ajax_upload_document' ] );
        add_action( 'wp_ajax_dd_delete_document', [ __CLASS__, 'ajax_delete_document' ] );
        add_action( 'wp_ajax_dd_get_documents',   [ __CLASS__, 'ajax_get_documents' ] );
        add_action( 'wp_ajax_dd_get_stats',       [ __CLASS__, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_dd_search_products', [ __CLASS__, 'ajax_search_products' ] );
        add_action( 'wp_ajax_dd_save_rules',      [ __CLASS__, 'ajax_save_rules' ] );
        add_action( 'wp_ajax_dd_get_rules',       [ __CLASS__, 'ajax_get_rules' ] );
        add_action( 'wp_ajax_dd_customer_history',  [ __CLASS__, 'ajax_customer_history' ] );
    }

    public static function register_menu(): void {
        add_menu_page( __( 'Dobrovolný dárek', 'dobrovolny-darek' ), __( 'Tajné dárky', 'dobrovolny-darek' ), 'manage_woocommerce', 'dobrovolny-darek', [ __CLASS__, 'page_packages' ], 'dashicons-gift', 56 );
        add_submenu_page( 'dobrovolny-darek', __( 'Balíčky', 'dobrovolny-darek' ),    __( 'Balíčky', 'dobrovolny-darek' ),    'manage_woocommerce', 'dobrovolny-darek',          [ __CLASS__, 'page_packages' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Statistiky', 'dobrovolny-darek' ), __( 'Statistiky', 'dobrovolny-darek' ), 'manage_woocommerce', 'dobrovolny-darek-stats',    [ __CLASS__, 'page_stats' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Zákazníci', 'dobrovolny-darek' ), __( 'Zákazníci', 'dobrovolny-darek' ), 'manage_woocommerce', 'dobrovolny-darek-customers', [ __CLASS__, 'page_customers' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Diagnostika', 'dobrovolny-darek' ),  __( 'Diagnostika', 'dobrovolny-darek' ),  'manage_woocommerce', 'dobrovolny-darek-debug',    [ __CLASS__, 'page_debug' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Nastavení', 'dobrovolny-darek' ),  __( 'Nastavení', 'dobrovolny-darek' ),  'manage_woocommerce', 'dobrovolny-darek-settings', [ __CLASS__, 'page_settings' ] );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'dobrovolny-darek' ) === false ) return;
        wp_enqueue_style( 'dd-admin', DD_PLUGIN_URL . 'assets/admin.css', [], DD_VERSION );
        wp_enqueue_script( 'dd-admin', DD_PLUGIN_URL . 'assets/admin.js', [ 'jquery', 'wp-util' ], DD_VERSION, true );
        wp_localize_script( 'dd-admin', 'DD', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'dd_admin_nonce' ),
            'categories' => self::get_all_categories(),
            'strings'    => [
                'confirm_delete_package'  => __( 'Opravdu smazat celý balíček i s dokumenty?', 'dobrovolny-darek' ),
                'confirm_delete_document' => __( 'Opravdu smazat tento dokument?', 'dobrovolny-darek' ),
                'uploading'               => __( 'Nahrávám…', 'dobrovolny-darek' ),
                'saving'                  => __( 'Ukládám…', 'dobrovolny-darek' ),
                'error'                   => __( 'Nastala chyba. Zkuste to znovu.', 'dobrovolny-darek' ),
                'no_restriction'          => __( '(žádné omezení – platí pro všechny)', 'dobrovolny-darek' ),
                'search_placeholder'      => __( 'Hledat produkt…', 'dobrovolny-darek' ),
            ],
        ] );
        wp_enqueue_media();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function get_all_categories(): array {
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
        if ( is_wp_error( $terms ) ) return [];
        return array_map( fn($t) => [ 'id' => $t->term_id, 'name' => $t->name, 'parent' => $t->parent ], $terms );
    }

    // ── Stránky ───────────────────────────────────────────────────────────────

    public static function page_packages(): void {
        $packages = DD_Package::get_all();
        include DD_PLUGIN_DIR . 'views/admin-packages.php';
    }

    public static function page_stats(): void {
        // Ruční opětovné odeslání
        if ( ! empty( $_GET['dd_resend'] ) ) {
            $order_id   = absint( $_GET['dd_resend'] );
            $status_key = sanitize_key( $_GET['dd_resend_key'] ?? '_dd_gift_status' );
            if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'dd_resend_' . $order_id ) && current_user_can( 'manage_woocommerce' ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order->update_meta_data( $status_key, 'pending' );
                    $order->save();
                    DD_Order::process_gift( $order_id );
                }
                wp_redirect( add_query_arg( [ 'dd_resend_done' => 1, 'order_id' => $order_id ], admin_url( 'admin.php?page=dobrovolny-darek-stats' ) ) );
                exit;
            }
        }
        $packages = DD_Package::get_all();
        include DD_PLUGIN_DIR . 'views/admin-stats.php';
    }

    public static function page_settings(): void {
        if ( isset( $_POST['dd_settings_nonce'] ) && wp_verify_nonce( $_POST['dd_settings_nonce'], 'dd_settings' ) ) {
            update_option( 'dd_email_subject',    sanitize_text_field( $_POST['dd_email_subject'] ?? '' ) );
            update_option( 'dd_email_body',       wp_kses_post( $_POST['dd_email_body'] ?? '' ) );
            update_option( 'dd_checkbox_label',   sanitize_text_field( $_POST['dd_checkbox_label'] ?? '' ) );
            update_option( 'dd_cart_description', sanitize_text_field( $_POST['dd_cart_description'] ?? '' ) );
            update_option( 'dd_crosssell_label',  sanitize_text_field( $_POST['dd_crosssell_label'] ?? '' ) );
            update_option( 'dd_exhausted_message', sanitize_text_field( $_POST['dd_exhausted_message'] ?? '' ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . __( 'Nastavení uloženo.', 'dobrovolny-darek' ) . '</p></div>';
        }
        include DD_PLUGIN_DIR . 'views/admin-settings.php';
    }

    // ── AJAX: balíčky ─────────────────────────────────────────────────────────

    public static function ajax_save_package(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $id    = absint( $_POST['id'] ?? 0 );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $desc  = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price = (float) str_replace( ',', '.', $_POST['price'] ?? '0' );

        if ( empty( $name ) ) wp_send_json_error( 'Název balíčku je povinný.' );

        global $wpdb;
        $table = $wpdb->prefix . 'dd_packages';

        if ( $id > 0 ) {
            $wpdb->update( $table, [ 'name' => $name, 'description' => $desc, 'price' => $price ], [ 'id' => $id ], [ '%s', '%s', '%f' ], [ '%d' ] );
        } else {
            $wpdb->insert( $table, [ 'name' => $name, 'description' => $desc, 'price' => $price, 'active' => 1 ], [ '%s', '%s', '%f', '%d' ] );
            $id = $wpdb->insert_id;
        }

        wp_send_json_success( [ 'id' => $id, 'name' => $name, 'description' => $desc, 'price' => $price ] );
    }

    public static function ajax_delete_package(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Chybí ID.' );

        global $wpdb;
        $docs = $wpdb->get_results( $wpdb->prepare( "SELECT file_path FROM {$wpdb->prefix}dd_documents WHERE package_id = %d", $id ) );
        foreach ( $docs as $doc ) { if ( file_exists( $doc->file_path ) ) unlink( $doc->file_path ); }
        $wpdb->delete( $wpdb->prefix . 'dd_documents',     [ 'package_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'dd_package_rules', [ 'package_id' => $id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'dd_packages',      [ 'id' => $id ],         [ '%d' ] );
        wp_send_json_success();
    }

    public static function ajax_toggle_package(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
        $id     = absint( $_POST['id'] ?? 0 );
        $active = absint( $_POST['active'] ?? 0 );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'dd_packages', [ 'active' => $active ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        wp_send_json_success( [ 'active' => $active ] );
    }

    // ── AJAX: dokumenty ───────────────────────────────────────────────────────

    public static function ajax_upload_document(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $package_id = absint( $_POST['package_id'] ?? 0 );
        if ( ! $package_id ) wp_send_json_error( 'Chybí ID balíčku.' );
        if ( empty( $_FILES['file'] ) ) wp_send_json_error( 'Žádný soubor.' );

        $allowed = [ 'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                     'application/zip', 'application/epub+zip', 'text/plain',
                     'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ];

        $file  = $_FILES['file'];
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed, true ) ) wp_send_json_error( 'Nepodporovaný typ: ' . esc_html( $mime ) );
        if ( $file['size'] > 20 * 1024 * 1024 ) wp_send_json_error( 'Max 20 MB.' );

        DD_Installer::create_upload_dir();
        $dir      = DD_Installer::get_upload_dir();
        $ext      = pathinfo( $file['name'], PATHINFO_EXTENSION );
        $filename = 'pkg' . $package_id . '_' . uniqid() . '.' . strtolower( $ext );
        $dest     = $dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) wp_send_json_error( 'Nelze uložit soubor.' );

        $doc_name = sanitize_text_field( $_POST['doc_name'] ?? pathinfo( $file['name'], PATHINFO_FILENAME ) );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'dd_documents',
            [ 'package_id' => $package_id, 'name' => $doc_name, 'file_path' => $dest, 'file_type' => $mime ],
            [ '%d', '%s', '%s', '%s' ] );

        wp_send_json_success( [ 'id' => $wpdb->insert_id, 'name' => $doc_name, 'file_type' => $mime, 'size' => size_format( $file['size'] ) ] );
    }

    public static function ajax_delete_document(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
        $id = absint( $_POST['id'] ?? 0 );
        global $wpdb;
        $doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dd_documents WHERE id = %d", $id ) );
        if ( ! $doc ) wp_send_json_error( 'Nenalezeno.' );
        if ( file_exists( $doc->file_path ) ) unlink( $doc->file_path );
        $wpdb->delete( $wpdb->prefix . 'dd_documents', [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    public static function ajax_get_documents(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
        $package_id = absint( $_POST['package_id'] ?? 0 );
        $docs = DD_Package::get_documents( $package_id );
        $out  = array_map( fn($d) => [
            'id'        => $d->id,
            'name'      => $d->name,
            'file_type' => $d->file_type,
            'size'      => file_exists( $d->file_path ) ? size_format( filesize( $d->file_path ) ) : '?',
        ], $docs );
        wp_send_json_success( $out );
    }

    public static function ajax_get_stats(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
        $package_id = absint( $_POST['package_id'] ?? 0 );
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_email, s.document_id, d.name AS doc_name, s.order_id, s.sent_at
             FROM {$wpdb->prefix}dd_sent s
             LEFT JOIN {$wpdb->prefix}dd_documents d ON d.id = s.document_id
             WHERE s.package_id = %d ORDER BY s.sent_at DESC LIMIT 200",
            $package_id
        ) );
        wp_send_json_success( $rows );
    }

    // ── AJAX: pravidla (kategorie + produkty) ─────────────────────────────────

    public static function ajax_save_rules(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

        $package_id   = absint( $_POST['package_id'] ?? 0 );
        $category_ids = array_map( 'absint', (array) ( $_POST['category_ids'] ?? [] ) );
        $product_ids  = array_map( 'absint', (array) ( $_POST['product_ids'] ?? [] ) );

        if ( ! $package_id ) wp_send_json_error( 'Chybí ID balíčku.' );

        DD_Package::save_rules( $package_id, $category_ids, $product_ids );
        wp_send_json_success();
    }

    public static function ajax_get_rules(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

        $package_id = absint( $_POST['package_id'] ?? 0 );
        $rules      = DD_Package::get_rules( $package_id );

        $cats  = [];
        $prods = [];
        foreach ( $rules as $r ) {
            if ( $r->rule_type === 'category' ) $cats[]  = (int) $r->object_id;
            if ( $r->rule_type === 'product'  ) {
                $p = wc_get_product( (int) $r->object_id );
                $prods[] = [ 'id' => (int) $r->object_id, 'name' => $p ? $p->get_name() : '#' . $r->object_id ];
            }
        }
        wp_send_json_success( [ 'category_ids' => $cats, 'products' => $prods ] );
    }

    public static function ajax_search_products(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

        $term = sanitize_text_field( $_POST['term'] ?? '' );
        if ( strlen( $term ) < 2 ) wp_send_json_success( [] );

        $query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $term,
            'posts_per_page' => 20,
            'fields'         => 'ids',
        ] );

        $results = array_map( fn($id) => [ 'id' => $id, 'name' => get_the_title( $id ) ], $query->posts );
        wp_send_json_success( $results );
    }

    public static function page_debug(): void {
        echo '<div class="wrap">';
        DD_Debug::render_panel();
        echo '</div>';
    }

    public static function page_customers(): void {
        include DD_PLUGIN_DIR . 'views/admin-customers.php';
    }

    public static function ajax_customer_history(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! $email ) wp_send_json_error( 'Chybí e-mail.' );

        global $wpdb;

        // Všechny odeslané dárky pro zákazníka
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                s.id,
                s.package_id,
                p.name  AS package_name,
                s.document_id,
                d.name  AS document_name,
                d.file_type,
                s.order_id,
                s.sent_at
             FROM {$wpdb->prefix}dd_sent s
             LEFT JOIN {$wpdb->prefix}dd_packages  p ON p.id = s.package_id
             LEFT JOIN {$wpdb->prefix}dd_documents d ON d.id = s.document_id
             WHERE s.user_email = %s
             ORDER BY s.sent_at DESC",
            $email
        ) );

        // Přidej info kolik dokumentů zbývá v každém balíčku
        $packages = DD_Package::get_all();
        $summary  = [];
        foreach ( $packages as $pkg ) {
            $total      = DD_Package::document_count( (int) $pkg->id );
            $sent_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dd_sent WHERE package_id = %d AND user_email = %s",
                $pkg->id, $email
            ) );
            $unsent = max( 0, $total - $sent_count );
            $summary[] = [
                'package_id'   => $pkg->id,
                'package_name' => $pkg->name,
                'active'       => (bool) $pkg->active,
                'total_docs'   => $total,
                'sent'         => $sent_count,
                'remaining'    => $unsent,
            ];
        }

        wp_send_json_success( [
            'email'   => $email,
            'history' => $rows,
            'summary' => $summary,
        ] );
    }
}
