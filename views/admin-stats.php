<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap dd-admin">
    <h1>📊 <?php _e( 'Tajné dárky – Statistiky', 'dobrovolny-darek' ); ?></h1>

    <?php
    // Zpracuj mazání statistik
    if ( ! empty( $_POST['dd_clear_stats_nonce'] ) && wp_verify_nonce( $_POST['dd_clear_stats_nonce'], 'dd_clear_stats' ) && current_user_can( 'manage_woocommerce' ) ) {
        $clear_pkg = absint( $_POST['dd_clear_package_id'] ?? 0 );
        global $wpdb;
        if ( $clear_pkg ) {
            $deleted = $wpdb->delete( $wpdb->prefix . 'dd_sent', [ 'package_id' => $clear_pkg ], [ '%d' ] );
            echo '<div class="notice notice-success is-dismissible"><p>Statistiky balíčku vymazány (' . intval($deleted) . ' záznamů).</p></div>';
        }
    }
    ?>

    <?php if ( ! empty( $_GET['dd_resend_done'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf( __( 'Dárek pro objednávku #%d byl znovu odeslán.', 'dobrovolny-darek' ), absint( $_GET['order_id'] ?? 0 ) ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $packages ) ) : ?>
        <p><?php _e( 'Zatím žádné balíčky.', 'dobrovolny-darek' ); ?></p>
    <?php else : ?>

    <div class="dd-stats-grid">
        <?php foreach ( $packages as $pkg ) :
            global $wpdb;
            $total_docs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dd_documents WHERE package_id = %d", $pkg->id ) );
            $total_sent  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dd_sent WHERE package_id = %d", $pkg->id ) );
            $uniq_users  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_email) FROM {$wpdb->prefix}dd_sent WHERE package_id = %d", $pkg->id ) );
            $revenue     = (float) $pkg->price * $total_sent;
        ?>
        <div class="dd-stat-card">
            <div class="dd-stat-card-header">
                <h2><?php echo esc_html( $pkg->name ); ?></h2>
                <span class="dd-badge <?php echo $pkg->active ? 'dd-badge-green' : 'dd-badge-gray'; ?>">
                    <?php echo $pkg->active ? __( 'Aktivní', 'dobrovolny-darek' ) : __( 'Neaktivní', 'dobrovolny-darek' ); ?>
                </span>
            </div>
            <div class="dd-stat-numbers">
                <div class="dd-stat-box">
                    <span class="dd-stat-num"><?php echo $total_docs; ?></span>
                    <span class="dd-stat-label"><?php _e( 'dokumentů', 'dobrovolny-darek' ); ?></span>
                </div>
                <div class="dd-stat-box">
                    <span class="dd-stat-num"><?php echo $total_sent; ?></span>
                    <span class="dd-stat-label"><?php _e( 'odeslaných', 'dobrovolny-darek' ); ?></span>
                </div>
                <div class="dd-stat-box">
                    <span class="dd-stat-num"><?php echo $uniq_users; ?></span>
                    <span class="dd-stat-label"><?php _e( 'zákazníků', 'dobrovolny-darek' ); ?></span>
                </div>
                <div class="dd-stat-box">
                    <span class="dd-stat-num"><?php echo wc_price( $revenue ); ?></span>
                    <span class="dd-stat-label"><?php _e( 'příjmy', 'dobrovolny-darek' ); ?></span>
                </div>
            </div>
            <div style="display:flex;gap:.5em;align-items:center;padding:.5em 1em 1em;">
                <button class="button dd-load-stats" data-package="<?php echo esc_attr( $pkg->id ); ?>">
                    <?php _e( 'Zobrazit historii odeslání', 'dobrovolny-darek' ); ?>
                </button>
                <?php if ( $total_sent > 0 ) : ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Opravdu vymazat všechny statistiky odeslání pro tento balíček? Zákazníci pak mohou dostat stejné dokumenty znovu.');">
                    <?php wp_nonce_field( 'dd_clear_stats', 'dd_clear_stats_nonce' ); ?>
                    <input type="hidden" name="dd_clear_package_id" value="<?php echo esc_attr( $pkg->id ); ?>">
                    <button type="submit" class="button button-link-delete">🗑 Vymazat statistiky</button>
                </form>
                <?php endif; ?>
            </div>
            <div class="dd-stats-table-wrap" id="dd-stats-table-<?php echo $pkg->id; ?>" style="display:none;">
                <table class="widefat striped dd-stats-table">
                    <thead>
                        <tr>
                            <th><?php _e( 'E-mail', 'dobrovolny-darek' ); ?></th>
                            <th><?php _e( 'Dokument', 'dobrovolny-darek' ); ?></th>
                            <th><?php _e( 'Objednávka', 'dobrovolny-darek' ); ?></th>
                            <th><?php _e( 'Odesláno', 'dobrovolny-darek' ); ?></th>
                        </tr>
                    </thead>
                    <tbody class="dd-stats-tbody"></tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>
