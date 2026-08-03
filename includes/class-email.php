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
        return self::send_gifts_combined( $order, [
            [
                'package'  => $package,
                'document' => $document,
            ],
        ] );
    }

    /**
     * Odešle jeden e-mail s více dárky jako přílohami.
     *
     * @param array<int,array{package:object,document:object}> $gifts
     * @return bool True při úspěchu.
     */
    public static function send_gifts_combined( WC_Order $order, array $gifts ): bool {
        if ( empty( $gifts ) ) {
            return false;
        }

        $first_gift = $gifts[0];
        $document   = $first_gift['document'];
        $to      = $order->get_billing_email();
        $name    = $order->get_billing_first_name();
        $subject = self::parse_template(
            get_option( 'dd_email_subject', __( '🎁 Váš tajný dárek z objednávky #{order_id}', 'virtualni-balicek' ) ),
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

        // Příloha – sestavíme seznam {cesta, zobrazovaný název} pro každý dárek.
        // Přílohy přidáváme přímo přes $phpmailer->addAttachment() v hooku
        // phpmailer_init, protože předáváním cest přes wp_mail() PHPMailer jako
        // display name vždy použije basename (= náhodný interní název souboru).
        $attachmentDefs = [];
        foreach ( $gifts as $gift ) {
            $gift_document = $gift['document'] ?? null;
            if ( ! $gift_document || empty( $gift_document->file_path ) || ! file_exists( $gift_document->file_path ) ) {
                continue;
            }

            $ext      = strtolower( (string) pathinfo( (string) $gift_document->file_path, PATHINFO_EXTENSION ) );
            $mime     = ! empty( $gift_document->file_type ) ? $gift_document->file_type : 'application/octet-stream';
            // Název souboru: primárně admin název dokumentu, zachováváme diakritiku
            // (není třeba sanitize_file_name – PHPMailer název jen zakóduje do hlavičky).
            $basename = trim( (string) $gift_document->name );
            if ( $basename === '' ) {
                $basename = pathinfo( (string) $gift_document->file_path, PATHINFO_FILENAME );
            }
            if ( $ext !== '' ) {
                $basename .= '.' . $ext;
            }

            $attachmentDefs[] = [
                'path'     => (string) $gift_document->file_path,
                'filename' => $basename,
                'mime'     => $mime,
            ];
        }

        $attachCallback = static function ( $phpmailer ) use ( $attachmentDefs ): void {
            foreach ( $attachmentDefs as $def ) {
                try {
                    $phpmailer->addAttachment( $def['path'], $def['filename'], 'base64', $def['mime'] );
                } catch ( \Exception $e ) {
                    // Soubor nelze přiložit – zaloguj a pokračuj.
                    error_log( '[DD_Email] addAttachment failed for ' . $def['path'] . ': ' . $e->getMessage() );
                }
            }
        };
        add_action( 'phpmailer_init', $attachCallback );
        // Žádné přílohy nepředáváme přes wp_mail – přidá je attachCallback výše.
        $result = wp_mail( $to, $subject, $message, $headers, [] );
        remove_action( 'phpmailer_init', $attachCallback );

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
        return '<p>' . __( 'Dobrý den, {customer_name}!', 'virtualni-balicek' ) . '</p>
<p>' . __( 'Děkujeme za Vaši objednávku č. {order_id}.', 'virtualni-balicek' ) . '</p>
<p>' . __( 'Jak jste si přál(a), přikládáme Váš <strong>tajný dárek</strong> – najdete ho v příloze tohoto e-mailu.', 'virtualni-balicek' ) . '</p>
<p>' . __( 'Doufáme, že Vás potěší!', 'virtualni-balicek' ) . '</p>
<p>' . __( 'S přáním hezkého dne,<br>{site_name}', 'virtualni-balicek' ) . '</p>';
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