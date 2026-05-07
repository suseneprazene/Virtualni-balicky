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

        update_option( 'dd_db_version', DD_VERSION );
        self::create_upload_dir();
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
