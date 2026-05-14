<?php
defined( 'ABSPATH' ) || exit;

class DD_Installer {

    public static function activate(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_packages = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dd_packages (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(200)    NOT NULL,
            description  TEXT,
            price        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            active       TINYINT(1)      NOT NULL DEFAULT 1,
            first_free   TINYINT(1)      NOT NULL DEFAULT 0,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        $sql_documents = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dd_documents (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id   BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(200)    NOT NULL,
            file_path    VARCHAR(500)    NOT NULL,
            file_type    VARCHAR(50)     NOT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY package_id (package_id)
        ) $charset;";

        // Pravidla: kategorie nebo produkty přiřazené k balíčku
        $sql_rules = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dd_package_rules (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            package_id   BIGINT UNSIGNED NOT NULL,
            rule_type    VARCHAR(20)     NOT NULL,
            object_id    BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY package_id (package_id),
            KEY rule_lookup (rule_type(20), object_id)
        ) $charset;";

        $sql_sent = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}dd_sent (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED,
            user_email   VARCHAR(200)    NOT NULL,
            package_id   BIGINT UNSIGNED NOT NULL,
            document_id  BIGINT UNSIGNED NOT NULL,
            order_id     BIGINT UNSIGNED NOT NULL,
            sent_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_email_package (user_email, package_id),
            KEY order_id (order_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_packages );
        dbDelta( $sql_documents );
        dbDelta( $sql_rules );
        dbDelta( $sql_sent );

        // Pojistka: přidej first_free pokud ho dbDelta nepřidal (existující starší tabulka)
        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$wpdb->prefix}dd_packages` LIKE 'first_free'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$wpdb->prefix}dd_packages` ADD COLUMN `first_free` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active`" );
        }

        update_option( 'dd_db_version', DD_VERSION );
        self::create_upload_dir();
    }

    /**
     * Returns the ID of a hidden, virtual WooCommerce product that serves as
     * a placeholder for DD cart items. Creates it on first call.
     *
     * A real product_id is required so that the WooCommerce Store API (used by
     * the block cart) does not discard the item.
     */
    public static function get_or_create_placeholder_product(): int {
        $stored = (int) get_option( 'dd_placeholder_product_id', 0 );
        if ( $stored > 0 && wc_get_product( $stored ) ) {
            return $stored;
        }

        // Recover from situations where the option was lost.
        if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
            $by_sku = wc_get_product_id_by_sku( 'dd-bundle-placeholder' );
            if ( $by_sku > 0 ) {
                update_option( 'dd_placeholder_product_id', $by_sku );
                return $by_sku;
            }
        }

        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return 0;
        }

        $product = new WC_Product_Simple();
        $product->set_name( __( 'Virtuální balíček', 'virtualni-balicek' ) );
        $product->set_virtual( true );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_status( 'publish' );
        $product->set_price( '0' );
        $product->set_regular_price( '0' );
        $product->set_sold_individually( true );
        $product->set_sku( 'dd-bundle-placeholder' );

        $product_id = $product->save();
        if ( $product_id > 0 ) {
            update_option( 'dd_placeholder_product_id', $product_id );
            self::set_placeholder_thumbnail( $product_id );
            return $product_id;
        }

        return 0;
    }

    /**
     * Uploads the plugin's package-icon.svg to the WP media library (once) and
     * sets it as the thumbnail of the placeholder product.
     */
    private static function set_placeholder_thumbnail( int $product_id ): void {
        // Already assigned?
        if ( get_post_thumbnail_id( $product_id ) ) {
            return;
        }

        // Check whether the icon was already uploaded in a previous run.
        $existing = get_option( 'dd_placeholder_thumbnail_id', 0 );
        if ( $existing > 0 && get_post( $existing ) ) {
            set_post_thumbnail( $product_id, $existing );
            return;
        }

        $icon_path = DD_PLUGIN_DIR . 'assets/package-icon.svg';
        if ( ! file_exists( $icon_path ) ) {
            return;
        }

        // Need WP media helpers.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload    = wp_upload_dir();
        $dest_dir  = $upload['basedir'] . '/virtualni-balicek';
        $dest_file = $dest_dir . '/package-icon.svg';

        if ( ! is_dir( $dest_dir ) ) {
            wp_mkdir_p( $dest_dir );
        }

        if ( ! copy( $icon_path, $dest_file ) ) {
            return;
        }

        $wp_filetype  = wp_check_filetype( 'package-icon.svg', [ 'svg' => 'image/svg+xml' ] );
        $attachment   = [
            'guid'           => $upload['baseurl'] . '/virtualni-balicek/package-icon.svg',
            'post_mime_type' => $wp_filetype['type'] ?: 'image/svg+xml',
            'post_title'     => __( 'Virtuální balíček – ikona', 'virtualni-balicek' ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment( $attachment, $dest_file, $product_id );
        if ( ! $attach_id || is_wp_error( $attach_id ) ) {
            return;
        }

        wp_generate_attachment_metadata( $attach_id, $dest_file );
        set_post_thumbnail( $product_id, $attach_id );
        update_option( 'dd_placeholder_thumbnail_id', $attach_id );
    }

    public static function deactivate(): void {}

    public static function create_upload_dir(): void {
        $upload = wp_upload_dir();
        $dir    = $upload['basedir'] . '/dobrovolny-darek';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "deny from all\n" );
            file_put_contents( $dir . '/index.php', "<?php // silence\n" );
        }
    }

    public static function get_upload_dir(): string {
        $upload = wp_upload_dir();
        return $upload['basedir'] . '/dobrovolny-darek';
    }
}
