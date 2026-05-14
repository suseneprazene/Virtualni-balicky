<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap dd-admin">
    <h1>🎁 <?php _e( 'Tajné dárky – Balíčky', 'dobrovolny-darek' ); ?></h1>
    <div id="dd-notices"></div>

    <div class="dd-layout">

        <!-- Seznam balíčků -->
        <div class="dd-panel dd-packages-panel">
            <div class="dd-panel-header">
                <h2><?php _e( 'Balíčky', 'dobrovolny-darek' ); ?></h2>
                <button class="button button-primary" id="dd-new-package-btn">+ <?php _e( 'Nový balíček', 'dobrovolny-darek' ); ?></button>
            </div>
            <div id="dd-package-list">
                <?php if ( empty( $packages ) ) : ?>
                    <p class="dd-empty"><?php _e( 'Zatím žádný balíček.', 'dobrovolny-darek' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $packages as $pkg ) : include __DIR__ . '/partials/package-row.php'; endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detail balíčku -->
        <div class="dd-panel dd-detail-panel" id="dd-detail-panel" style="display:none;">
            <div class="dd-panel-header">
                <h2 id="dd-detail-title"><?php _e( 'Detail balíčku', 'dobrovolny-darek' ); ?></h2>
                <button class="button" id="dd-close-detail">✕</button>
            </div>

            <!-- Základní informace -->
            <div class="dd-section">
                <h3><?php _e( 'Základní informace', 'dobrovolny-darek' ); ?></h3>
                <input type="hidden" id="dd-pkg-id" value="0">
                <table class="form-table dd-form-table">
                    <tr>
                        <th><label for="dd-pkg-name"><?php _e( 'Název *', 'dobrovolny-darek' ); ?></label></th>
                        <td><input type="text" id="dd-pkg-name" class="regular-text" placeholder="např. Recepty na sušené maso"></td>
                    </tr>
                    <tr>
                        <th><label for="dd-pkg-desc"><?php _e( 'Popis', 'dobrovolny-darek' ); ?></label></th>
                        <td><textarea id="dd-pkg-desc" rows="2" class="large-text"></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="dd-pkg-price"><?php _e( 'Cena (Kč)', 'dobrovolny-darek' ); ?></label></th>
                        <td>
                            <input type="number" id="dd-pkg-price" min="0" step="1" value="15" style="width:100px;">
                            <span class="description"><?php _e( '0 = zdarma', 'dobrovolny-darek' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="dd-pkg-first-free"><?php _e( 'První dárek zdarma', 'dobrovolny-darek' ); ?></label></th>
                        <td>
                            <label style="display:inline-flex;align-items:center;gap:.4em;cursor:pointer;">
                                <input type="checkbox" id="dd-pkg-first-free" value="1">
                                <span><?php _e( 'Zákazník dostane první dárek zdarma', 'dobrovolny-darek' ); ?></span>
                            </label>
                            <p class="description"><?php _e( 'Pokud zákazník ještě nikdy neobdržel dárek z tohoto balíčku, poplatek se neúčtuje. V košíku se zobrazí přeškrtnutá cena s textem „první zdarma".', 'dobrovolny-darek' ); ?></p>
                        </td>
                    </tr>
                </table>
                <button class="button button-primary" id="dd-save-package-btn"><?php _e( 'Uložit balíček', 'dobrovolny-darek' ); ?></button>
            </div>

            <!-- Pravidla – kategorie & produkty -->
            <div class="dd-section" id="dd-rules-section" style="display:none;">
                <h3><?php _e( 'Pravidla zobrazení', 'dobrovolny-darek' ); ?></h3>
                <p class="description">
                    <?php _e( 'Balíček se zobrazí v košíku, jen pokud zákazník má aspoň jeden produkt z vybraných kategorií nebo produktů. Nevyberete-li nic, balíček se zobrazí vždy (univerzální).', 'dobrovolny-darek' ); ?>
                </p>

                <div class="dd-rules-grid">
                    <!-- Kategorie -->
                    <div class="dd-rules-col">
                        <h4><?php _e( '📂 Kategorie produktů', 'dobrovolny-darek' ); ?></h4>
                        <div id="dd-category-tree" class="dd-cat-scroll"></div>
                    </div>

                    <!-- Konkrétní produkty -->
                    <div class="dd-rules-col">
                        <h4><?php _e( '📦 Konkrétní produkty', 'dobrovolny-darek' ); ?></h4>
                        <input type="text" id="dd-product-search" class="regular-text"
                               placeholder="<?php esc_attr_e( 'Hledat produkt…', 'dobrovolny-darek' ); ?>" autocomplete="off">
                        <div id="dd-product-results" class="dd-prod-results"></div>
                        <div id="dd-added-products" class="dd-prod-tags"></div>
                    </div>
                </div>

                <button class="button button-secondary" id="dd-save-rules-btn" style="margin-top:.8em;">
                    💾 <?php _e( 'Uložit pravidla', 'dobrovolny-darek' ); ?>
                </button>
            </div>

            <!-- Dokumenty -->
            <div class="dd-section" id="dd-documents-section" style="display:none;">
                <h3><?php _e( 'Dokumenty v balíčku', 'dobrovolny-darek' ); ?> <span id="dd-doc-count" class="dd-badge">0</span></h3>
                <div id="dd-document-list" class="dd-doc-list"></div>

                <div class="dd-upload-area">
                    <h4><?php _e( 'Přidat dokument', 'dobrovolny-darek' ); ?></h4>
                    <table class="form-table dd-form-table">
                        <tr>
                            <th><label for="dd-doc-name"><?php _e( 'Název (admin)', 'dobrovolny-darek' ); ?></label></th>
                            <td><input type="text" id="dd-doc-name" class="regular-text" placeholder="Viditelné jen pro vás"></td>
                        </tr>
                        <tr>
                            <th><label for="dd-doc-file"><?php _e( 'Soubor', 'dobrovolny-darek' ); ?></label></th>
                            <td>
                                <input type="file" id="dd-doc-file" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.zip,.epub,.txt,.docx">
                                <span class="description"><?php _e( 'Max 20 MB. PDF, obrázky, ZIP, EPUB, DOCX, TXT', 'dobrovolny-darek' ); ?></span>
                            </td>
                        </tr>
                    </table>
                    <button class="button button-secondary" id="dd-upload-btn">⬆ <?php _e( 'Nahrát dokument', 'dobrovolny-darek' ); ?></button>
                    <span id="dd-upload-progress" style="display:none; margin-left:1em; color:#555;"></span>
                </div>
            </div>

        </div><!-- .dd-detail-panel -->
    </div><!-- .dd-layout -->
</div>
