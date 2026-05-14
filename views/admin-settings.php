<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap dd-admin">
    <h1>⚙️ <?php _e( 'Virtuální balíček – Nastavení', 'virtualni-balicek' ); ?></h1>

    <form method="post">
        <?php wp_nonce_field( 'dd_settings', 'dd_settings_nonce' ); ?>
        <table class="form-table">

            <tr>
                <th scope="row"><label for="dd_checkbox_label"><?php _e( 'Text checkboxu v košíku', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <input type="text" id="dd_checkbox_label" name="dd_checkbox_label" class="large-text"
                           value="<?php echo esc_attr( get_option( 'dd_checkbox_label', '🎁 Přidat tajný dárek' ) ); ?>">
                    <p class="description"><?php _e( 'Zobrazí se u checkboxu nebo jako popis radio volby. Cena se přidá automaticky.', 'virtualni-balicek' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="dd_cart_description"><?php _e( 'Popisek pod výběrem dárku', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <input type="text" id="dd_cart_description" name="dd_cart_description" class="large-text"
                           value="<?php echo esc_attr( get_option( 'dd_cart_description', 'Překvapení čeká – obsah dárku zjistíte až v e-mailu po objednávce.' ) ); ?>">
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="dd_crosssell_label"><?php _e( 'Text cross-sell checkboxu', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <input type="text" id="dd_crosssell_label" name="dd_crosssell_label" class="large-text"
                           value="<?php echo esc_attr( get_option( 'dd_crosssell_label', 'Zajímá tě také tajný dárek ze sekce {package_name}?' ) ); ?>">
                    <p class="description">
                        <?php _e( 'Zobrazí se zákazníkovi, jehož košík neodpovídá balíčku. Použijte proměnnou:', 'virtualni-balicek' ); ?>
                        <code>{package_name}</code>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="dd_exhausted_message"><?php _e( 'Zpráva při vyčerpání dárků', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <input type="text" id="dd_exhausted_message" name="dd_exhausted_message" class="large-text"
                           value="<?php echo esc_attr( get_option( 'dd_exhausted_message', 'Pro Tebe tu nyní žádný dárek navíc nemám. Ale jakmile nějaký vytvořím, dozvíš se to při další návštěvě košíku ;)' ) ); ?>">
                    <p class="description"><?php _e( 'Zobrazí se zákazníkovi v košíku, pokud již vyčerpal všechny dokumenty v balíčku.', 'virtualni-balicek' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="dd_email_subject"><?php _e( 'Předmět e-mailu s dárkem', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <input type="text" id="dd_email_subject" name="dd_email_subject" class="large-text"
                           value="<?php echo esc_attr( get_option( 'dd_email_subject', '🎁 Váš tajný dárek z objednávky #{order_id}' ) ); ?>">
                    <p class="description">
                        <?php _e( 'Proměnné:', 'virtualni-balicek' ); ?>
                        <code>{order_id}</code> <code>{customer_name}</code> <code>{customer_email}</code>
                        <code>{document_name}</code> <code>{site_name}</code> <code>{order_date}</code>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="dd_email_body"><?php _e( 'Tělo e-mailu s dárkem', 'virtualni-balicek' ); ?></label></th>
                <td>
                    <?php wp_editor(
                        get_option( 'dd_email_body', DD_Email::default_body() ),
                        'dd_email_body',
                        [ 'textarea_name' => 'dd_email_body', 'textarea_rows' => 12, 'media_buttons' => false ]
                    ); ?>
                    <p class="description"><?php _e( 'Dokument bude přiložen jako příloha. Stejné proměnné jako u předmětu.', 'virtualni-balicek' ); ?></p>
                </td>
            </tr>

        </table>

        <div class="dd-settings-info">
            <h3><?php _e( 'Logika zobrazení dárků v košíku', 'virtualni-balicek' ); ?></h3>
            <ol>
                <li><?php _e( '<strong>Přímá shoda</strong> – zákazník má v košíku produkt z kategorie/produktu přiřazeného k balíčku → zobrazí se jako hlavní nabídka.', 'virtualni-balicek' ); ?></li>
                <li><?php _e( '<strong>Více shod</strong> – zákazník splňuje podmínky více balíčků → radio buttony s volbou + možnost Náhodný výběr.', 'virtualni-balicek' ); ?></li>
                <li><?php _e( '<strong>Cross-sell</strong> – zákazník nemá produkt z daného balíčku → zobrazí se nenápadný checkbox s textem cross-sell.', 'virtualni-balicek' ); ?></li>
                <li><?php _e( '<strong>Bez pravidel</strong> – balíček bez přiřazených kategorií/produktů se nabídne vždy (univerzální).', 'virtualni-balicek' ); ?></li>
                <li><?php _e( '<strong>Vyčerpáno</strong> – zákazník obdržel všechny dokumenty → nabídka se skryje (nebo čeká na přidání nových).', 'virtualni-balicek' ); ?></li>
            </ol>
        </div>

        <?php submit_button( __( 'Uložit nastavení', 'virtualni-balicek' ) ); ?>
    </form>
</div>
