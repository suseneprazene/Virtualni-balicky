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
        add_action( 'wp_ajax_dd_download_document', [ __CLASS__, 'ajax_download_document' ] );
        add_action( 'wp_ajax_dd_clear_customer',    [ __CLASS__, 'ajax_clear_customer' ] );
        add_action( 'wp_ajax_dd_delete_sent_record', [ __CLASS__, 'ajax_delete_sent_record' ] );
        add_action( 'wp_ajax_dd_send_random_by_category', [ __CLASS__, 'ajax_send_random_by_category' ] );
    }

    public static function register_menu(): void {
        add_menu_page( __( 'Virtuální balíček', 'virtualni-balicek' ), __( 'Virtuální balíček', 'virtualni-balicek' ), 'manage_woocommerce', 'dobrovolny-darek', [ __CLASS__, 'page_packages' ], 'dashicons-gift', 56 );
        add_submenu_page( 'dobrovolny-darek', __( 'Balíčky', 'virtualni-balicek' ),    __( 'Balíčky', 'virtualni-balicek' ),    'manage_woocommerce', 'dobrovolny-darek',          [ __CLASS__, 'page_packages' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Statistiky', 'virtualni-balicek' ), __( 'Statistiky', 'virtualni-balicek' ), 'manage_woocommerce', 'dobrovolny-darek-stats',    [ __CLASS__, 'page_stats' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Zákazníci', 'virtualni-balicek' ), __( 'Zákazníci', 'virtualni-balicek' ), 'manage_woocommerce', 'dobrovolny-darek-customers', [ __CLASS__, 'page_customers' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Diagnostika', 'virtualni-balicek' ),  __( 'Diagnostika', 'virtualni-balicek' ),  'manage_woocommerce', 'dobrovolny-darek-debug',    [ __CLASS__, 'page_debug' ] );
        add_submenu_page( 'dobrovolny-darek', __( 'Nastavení', 'virtualni-balicek' ),  __( 'Nastavení', 'virtualni-balicek' ),  'manage_woocommerce', 'dobrovolny-darek-settings', [ __CLASS__, 'page_settings' ] );

        // Automaticky vytvoř/aktualizuj tabulky pokud chybí (např. po přejmenování pluginu)
        self::maybe_install();
    }

    /**
     * Zkontroluje DB verzi a spustí install pokud je plugin aktualizován nebo
     * tabulky ještě neexistují (stav po přejmenování bez deaktivace/aktivace).
     */
    private static function maybe_install(): void {
        global $wpdb;

        // Zkontroluj jestli sloupec first_free existuje (přidán v 1.1.0)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$wpdb->prefix}dd_packages` LIKE 'first_free'" );

        if ( get_option( 'dd_db_version' ) !== DD_VERSION
            || ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}dd_packages'" )
            || empty( $col )
        ) {
            DD_Installer::activate();
        }
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

        $id         = absint( $_POST['id'] ?? 0 );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $desc       = sanitize_textarea_field( $_POST['description'] ?? '' );
        $price      = (float) str_replace( ',', '.', $_POST['price'] ?? '0' );
        $first_free = absint( $_POST['first_free'] ?? 0 ) ? 1 : 0;

        if ( empty( $name ) ) wp_send_json_error( 'Název balíčku je povinný.' );

        global $wpdb;
        $table = $wpdb->prefix . 'dd_packages';

        // Ochrana: pokud tabulka neexistuje, automaticky ji vytvoř
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
        if ( ! $exists ) {
            DD_Installer::activate();
        }

        if ( $id > 0 ) {
            $result = $wpdb->update(
                $table,
                [ 'name' => $name, 'description' => $desc, 'price' => $price, 'first_free' => $first_free ],
                [ 'id' => $id ],
                [ '%s', '%s', '%f', '%d' ],
                [ '%d' ]
            );
            if ( $result === false ) {
                wp_send_json_error( 'Chyba při ukládání: ' . $wpdb->last_error );
            }
        } else {
            $result = $wpdb->insert(
                $table,
                [ 'name' => $name, 'description' => $desc, 'price' => $price, 'active' => 1, 'first_free' => $first_free ],
                [ '%s', '%s', '%f', '%d', '%d' ]
            );
            if ( $result === false || ! $wpdb->insert_id ) {
                wp_send_json_error( 'Chyba při vytváření balíčku: ' . $wpdb->last_error );
            }
            $id = $wpdb->insert_id;
        }

        wp_send_json_success( [ 'id' => $id, 'name' => $name, 'description' => $desc, 'price' => $price, 'first_free' => $first_free ] );
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
        $files = self::normalize_uploaded_files();
        if ( empty( $files ) ) wp_send_json_error( 'Žádný soubor.' );

        $doc_name_input = sanitize_text_field( $_POST['doc_name'] ?? '' );
        $documents      = [];
        $errors         = [];

        foreach ( $files as $index => $file ) {
            $doc_name = '';
            if ( count( $files ) === 1 && $doc_name_input !== '' ) {
                $doc_name = $doc_name_input;
            } elseif ( $doc_name_input !== '' ) {
                $doc_name = $doc_name_input . ' #' . ( $index + 1 );
            }

            $result = self::store_uploaded_document( $package_id, $file, $doc_name );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }
            $documents[] = $result;
        }

        if ( empty( $documents ) ) {
            wp_send_json_error( implode( ' ', $errors ) ?: 'Nahrání souboru selhalo.' );
        }

        $response = [
            'documents' => $documents,
            'errors'    => $errors,
        ];

        if ( count( $documents ) === 1 ) {
            $response = array_merge( $response, $documents[0] );
        }

        wp_send_json_success( $response );
    }

    /**
     * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    private static function normalize_uploaded_files(): array {
        $files = [];

        if (
            ! empty( $_FILES['files'] )
            && is_array( $_FILES['files'] )
            && isset( $_FILES['files']['name'], $_FILES['files']['tmp_name'], $_FILES['files']['error'], $_FILES['files']['size'] )
            && is_array( $_FILES['files']['name'] )
        ) {
            $count = count( $_FILES['files']['name'] );
            for ( $i = 0; $i < $count; $i++ ) {
                $files[] = [
                    'name'     => (string) ( $_FILES['files']['name'][ $i ] ?? '' ),
                    'type'     => (string) ( $_FILES['files']['type'][ $i ] ?? '' ),
                    'tmp_name' => (string) ( $_FILES['files']['tmp_name'][ $i ] ?? '' ),
                    'error'    => (int) ( $_FILES['files']['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ),
                    'size'     => (int) ( $_FILES['files']['size'][ $i ] ?? 0 ),
                ];
            }
        } elseif ( ! empty( $_FILES['file'] ) && is_array( $_FILES['file'] ) ) {
            $files[] = [
                'name'     => (string) ( $_FILES['file']['name'] ?? '' ),
                'type'     => (string) ( $_FILES['file']['type'] ?? '' ),
                'tmp_name' => (string) ( $_FILES['file']['tmp_name'] ?? '' ),
                'error'    => (int) ( $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE ),
                'size'     => (int) ( $_FILES['file']['size'] ?? 0 ),
            ];
        }

        return array_values( array_filter( $files, static function( array $f ): bool {
            return ! empty( $f['name'] )
                && ! empty( $f['tmp_name'] )
                && (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK;
        } ) );
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{id:int,name:string,file_type:string,size:string}|WP_Error
     */
    private static function store_uploaded_document( int $package_id, array $file, string $doc_name = '' ) {
        if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', __( 'Chyba nahrání souboru.', 'virtualni-balicek' ) );
        }

        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/zip',
            'application/epub+zip',
            'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $allowed_exts = [ 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'epub', 'txt', 'docx' ];

        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        $size = (int) $file['size'];
        if ( $size <= 0 ) {
            return new WP_Error( 'file_empty', 'Soubor je prázdný.' );
        }
        if ( $size > 20 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'Max 20 MB.' );
        }

        $ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext === '' || ! in_array( $ext, $allowed_exts, true ) ) {
            return new WP_Error( 'extension_not_allowed', 'Nepodporovaná přípona souboru.' );
        }
        if ( ! in_array( $mime, $allowed, true ) ) {
            return new WP_Error( 'mime_not_allowed', 'Nepodporovaný typ: ' . (string) $mime );
        }

        DD_Installer::create_upload_dir();
        $dir      = DD_Installer::get_upload_dir();
        $filename = 'pkg' . $package_id . '_' . uniqid() . '.' . $ext;
        $dest     = $dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'file_save_failed', 'Nelze uložit soubor.' );
        }

        $final_name = $doc_name !== ''
            ? sanitize_text_field( $doc_name )
            : sanitize_text_field( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) );

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'dd_documents',
            [ 'package_id' => $package_id, 'name' => $final_name, 'file_path' => $dest, 'file_type' => $mime ],
            [ '%d', '%s', '%s', '%s' ]
        );
        if ( $inserted === false ) {
            if ( file_exists( $dest ) ) {
                unlink( $dest );
            }
            return new WP_Error( 'db_insert_failed', 'Chyba při ukládání dokumentu.' );
        }

        return [
            'id'        => (int) $wpdb->insert_id,
            'name'      => $final_name,
            'file_type' => (string) $mime,
            'size'      => size_format( (int) $file['size'] ),
        ];
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
            'open_url'  => admin_url( 'admin-ajax.php?action=dd_download_document&doc_id=' . absint( $d->id ) . '&inline=1' ),
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

        wp_send_json_success( self::build_customer_history_payload( $email ) );
    }

    // ── AJAX: smazání historie zákazníka ─────────────────────────────────────

    public static function ajax_clear_customer(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $email      = sanitize_email( $_POST['email'] ?? '' );
        $package_id = absint( $_POST['package_id'] ?? 0 );

        if ( ! $email ) wp_send_json_error( 'Chybí e-mail.' );

        global $wpdb;
        if ( $package_id > 0 ) {
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'dd_sent',
                [ 'user_email' => $email, 'package_id' => $package_id ],
                [ '%s', '%d' ]
            );
        } else {
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'dd_sent',
                [ 'user_email' => $email ],
                [ '%s' ]
            );
        }

        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // ── AJAX: smazání jednotlivého záznamu z historie ─────────────────────────

    public static function ajax_delete_sent_record(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );

        $email   = sanitize_email( $_POST['email'] ?? '' );
        $sent_id = absint( $_POST['sent_id'] ?? 0 );

        if ( ! $email ) wp_send_json_error( 'Chybí e-mail.' );
        if ( ! $sent_id ) wp_send_json_error( 'Chybí ID záznamu.' );

        global $wpdb;
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'dd_sent',
            [ 'id' => $sent_id, 'user_email' => $email ],
            [ '%d', '%s' ]
        );

        if ( ! $deleted ) {
            wp_send_json_error( 'Záznam nenalezen nebo nebyl smazán.' );
        }

        wp_send_json_success( [ 'deleted' => 1 ] );
    }

    public static function ajax_send_random_by_category(): void {
        check_ajax_referer( 'dd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Nedostatečná oprávnění.', 'virtualni-balicek' ) );
        }

        $email       = sanitize_email( $_POST['email'] ?? '' );
        $category_id = absint( $_POST['category_id'] ?? 0 );

        if ( ! $email ) {
            wp_send_json_error( __( 'Neplatný zákazník (e-mail).', 'virtualni-balicek' ) );
        }
        if ( ! $category_id ) {
            wp_send_json_error( __( 'Neplatná kategorie.', 'virtualni-balicek' ) );
        }

        $category = get_term( $category_id, 'product_cat' );
        if ( ! $category || is_wp_error( $category ) ) {
            wp_send_json_error( __( 'Kategorie neexistuje.', 'virtualni-balicek' ) );
        }

        $eligible_packages = DD_Package::get_active_by_category( $category_id );
        if ( empty( $eligible_packages ) ) {
            wp_send_json_error( __( 'V této kategorii nejsou žádné dostupné balíčky.', 'virtualni-balicek' ) );
        }

        $sendable_packages = array_values( array_filter(
            $eligible_packages,
            static fn( $pkg ) => DD_Package::has_unsent( (int) $pkg->id, $email )
        ) );

        if ( empty( $sendable_packages ) ) {
            wp_send_json_error( __( 'Pro zákazníka už nejsou v této kategorii žádné neodeslané dokumenty.', 'virtualni-balicek' ) );
        }

        shuffle( $sendable_packages );
        $picked = $sendable_packages[0];

        $result = DD_Order::send_manual_random_package_for_customer( $email, (int) $picked->id );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result['message'] ?? __( 'Nepodařilo se odeslat balíček.', 'virtualni-balicek' ) );
        }

        wp_send_json_success( [
            'message'      => $result['message'],
            'category_id'  => $category_id,
            'category'     => $category->name,
            'package_id'   => (int) $picked->id,
            'package_name' => $picked->name,
            'customer_data' => self::build_customer_history_payload( $email ),
        ] );
    }

    // ── AJAX: stažení dokumentu (admin – proklik z objednávky) ────────────────

    public static function ajax_download_document(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Nedostatečná oprávnění.', 403 );

        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
        if ( $nonce && ! wp_verify_nonce( $nonce, 'dd_admin_nonce' ) ) {
            wp_die( 'Neplatný bezpečnostní token.', 403 );
        }

        $doc_id = absint( $_GET['doc_id'] ?? 0 );
        if ( ! $doc_id ) wp_die( 'Chybí ID dokumentu.' );

        global $wpdb;
        $doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dd_documents WHERE id = %d", $doc_id ) );
        if ( ! $doc || ! file_exists( $doc->file_path ) ) wp_die( 'Dokument nenalezen.' );

        $filename = basename( $doc->file_path );
        $mime     = $doc->file_type ?: mime_content_type( $doc->file_path ) ?: 'application/octet-stream';
        $inline   = ! empty( $_GET['inline'] ) && stripos( (string) $mime, 'pdf' ) !== false;
        $dispo    = $inline ? 'inline' : 'attachment';

        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: ' . $dispo . '; filename="' . sanitize_file_name( $doc->name . '_' . $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $doc->file_path ) );
        header( 'Cache-Control: no-cache' );
        readfile( $doc->file_path );
        exit;
    }

    private static function build_customer_history_payload( string $email ): array {
        global $wpdb;

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

        $summary  = [];
        $packages = DD_Package::get_all();
        foreach ( $packages as $pkg ) {
            $total      = DD_Package::document_count( (int) $pkg->id );
            $sent_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dd_sent WHERE package_id = %d AND user_email = %s",
                $pkg->id,
                $email
            ) );

            $summary[] = [
                'package_id'   => $pkg->id,
                'package_name' => $pkg->name,
                'active'       => (bool) $pkg->active,
                'total_docs'   => $total,
                'sent'         => $sent_count,
                'remaining'    => max( 0, $total - $sent_count ),
            ];
        }

        $categories = [];
        $terms      = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $category_id = (int) $term->term_id;

                $eligible_packages = DD_Package::get_active_by_category( $category_id, false );
                if ( empty( $eligible_packages ) ) {
                    continue;
                }

                $available_count = 0;
                foreach ( $eligible_packages as $pkg ) {
                    if ( DD_Package::has_unsent( (int) $pkg->id, $email ) ) {
                        $available_count++;
                    }
                }

                $categories[] = [
                    'id'              => $category_id,
                    'name'            => $term->name,
                    'eligible_count'  => count( $eligible_packages ),
                    'available_count' => $available_count,
                ];
            }
        }

        return [
            'email'      => $email,
            'history'    => $rows,
            'summary'    => $summary,
            'categories' => $categories,
        ];
    }
}
