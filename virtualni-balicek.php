<?php
/**
 * Plugin Name: Virtuální balíček
 * Plugin URI:  https://example.com/virtualni-balicek
 * Description: Mystery box dárky v WooCommerce – zákazník si přikoupí tajný dárek, plugin náhodně vybere dokument a pošle ho e-mailem.
 * Version:     1.1.2
 * Author:      Váš web
 * Text Domain: virtualni-balicek
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'DD_VERSION',     '1.1.2' );
define( 'DD_PLUGIN_FILE', __FILE__ );
define( 'DD_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'DD_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( $class ) {
    $prefix = 'DD_';
    if ( strpos( $class, $prefix ) !== 0 ) return;
    $file = DD_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, 3 ) ) ) . '.php';
    if ( file_exists( $file ) ) require $file;
} );

register_activation_hook( __FILE__, [ 'DD_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DD_Installer', 'deactivate' ] );

// Repair DB – lze spustit i bez deaktivace/aktivace
add_action( 'wp_ajax_dd_repair_db', function() {
    check_ajax_referer( 'dd_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Nedostatečná oprávnění.' );
    DD_Installer::activate();
    wp_send_json_success( 'Databáze opravena / aktualizována.' );
} );

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Virtuální balíček:</strong> Vyžaduje aktivní WooCommerce.</p></div>';
        } );
        return;
    }

    load_plugin_textdomain( 'virtualni-balicek', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    DD_Admin::init();
    DD_Cart::init();
    DD_Order::init();
    DD_Email::init();
    DD_Debug::init();
} );
