<?php defined( 'ABSPATH' ) || exit;
/** @var object $pkg */
$doc_count  = DD_Package::document_count( (int) $pkg->id );
$price_text = $pkg->price > 0 ? wc_price( $pkg->price ) : __( 'zdarma', 'dobrovolny-darek' );
?>
<div class="dd-pkg-row <?php echo $pkg->active ? 'dd-active' : 'dd-inactive'; ?>" data-id="<?php echo esc_attr( $pkg->id ); ?>">
    <div class="dd-pkg-info">
        <strong class="dd-pkg-name"><?php echo esc_html( $pkg->name ); ?></strong>
        <span class="dd-pkg-meta">
            <?php echo $price_text; ?> &bull;
            <?php printf( _n( '%d dokument', '%d dokumentů', $doc_count, 'dobrovolny-darek' ), $doc_count ); ?>
        </span>
    </div>
    <div class="dd-pkg-actions">
        <label class="dd-toggle" title="<?php esc_attr_e( 'Aktivní/Neaktivní', 'dobrovolny-darek' ); ?>">
            <input type="checkbox" class="dd-toggle-active" <?php checked( $pkg->active, 1 ); ?> data-id="<?php echo esc_attr( $pkg->id ); ?>">
            <span class="dd-toggle-slider"></span>
        </label>
        <button class="button dd-edit-pkg" data-id="<?php echo esc_attr( $pkg->id ); ?>"
                data-name="<?php echo esc_attr( $pkg->name ); ?>"
                data-desc="<?php echo esc_attr( $pkg->description ); ?>"
                data-price="<?php echo esc_attr( $pkg->price ); ?>"
                data-first-free="<?php echo esc_attr( $pkg->first_free ?? 0 ); ?>">
            <?php _e( 'Upravit', 'dobrovolny-darek' ); ?>
        </button>
        <button class="button button-link-delete dd-delete-pkg" data-id="<?php echo esc_attr( $pkg->id ); ?>">🗑</button>
    </div>
</div>
