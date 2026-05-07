<?php
defined( 'ABSPATH' ) || exit;

class DD_Email {

    public static function init(): void {
        // Zpracování ručního opětovného odeslání
        add_action( 'admin_init', [ __CLASS__, 'handle_resend' ] );
    }

    /**
     * Odešle e-mail s dárkem jako přílohou.
     *
     * @return bool True při úspěchu.
     */
    public static function send_gift( WC_Order $order, object $package, object $document ): bool {
        $to      = $order->get_billing_email();
        $name    = $order->get_billing_first_name();
        $subject = self::parse_template(
            get_option( 'dd_email_subject', __( '🎁 Váš tajný dárek z objednávky #{order_id}', 'dobrovolny-darek' ) ),
            $order, $document
        );
        $body    = self::parse_template(
            get_option( 'dd_email_body', self::default_body() ),
            $order, $document
        );

        // WooCommerce e-mail styly
        $mailer  = WC()->mailer();
        $message = $mailer->wrap_message( $subject, $body );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // Příloha
        $attachments = [];
        if ( file_exists( $document->file_path ) ) {
            $attachments[] = $document->file_path;
        }

        $result = wp_mail( $to, $subject, $message, $headers, $attachments );

        return (bool) $result;
    }

    /**
     * Nahrazuje placeholdery v šabloně.
     */
    private static function parse_template( string $template, WC_Order $order, object $document ): string {
        $replacements = [
            '{order_id}'        => $order->get_id(),
            '{customer_name}'   => $order->get_billing_first_name(),
            '{customer_email}'  => $order->get_billing_email(),
            '{document_name}'   => $document->name,
            '{site_name}'       => get_bloginfo( 'name' ),
            '{order_date}'      => wc_format_datetime( $order->get_date_created() ),
        ];
        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Výchozí tělo e-mailu.
     */
    public static function default_body(): string {
        return '<p>' . __( 'Dobrý den, {customer_name}!', 'dobrovolny-darek' ) . '</p>
<p>' . __( 'Děkujeme za Vaši objednávku č. {order_id}.', 'dobrovolny-darek' ) . '</p>
<p>' . __( 'Jak jste si přál(a), přikládáme Váš <strong>tajný dárek</strong> – najdete ho v příloze tohoto e-mailu.', 'dobrovolny-darek' ) . '</p>
<p>' . __( 'Doufáme, že Vás potěší!', 'dobrovolny-darek' ) . '</p>
<p>' . __( 'S přáním hezkého dne,<br>{site_name}', 'dobrovolny-darek' ) . '</p>';
    }

    /**
     * Ruční opětovné odeslání z admin.
     */
    public static function handle_resend(): void {
        if ( empty( $_GET['dd_resend'] ) ) return;

        $order_id = absint( $_GET['dd_resend'] );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'dd_resend_' . $order_id ) ) {
            wp_die( 'Neplatný bezpečnostní token.' );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Nedostatečná oprávnění.' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Objednávka nenalezena.' );

        // Resetuj status aby se mohlo odeslat znovu
        $order->update_meta_data( '_dd_gift_status', 'pending' );
        $order->save();

        DD_Order::process_gift( $order_id );

        $redirect = add_query_arg(
            [ 'dd_resend_done' => 1, 'order_id' => $order_id ],
            admin_url( 'admin.php?page=dobrovolny-darek-stats' )
        );
        wp_redirect( $redirect );
        exit;
    }
}
