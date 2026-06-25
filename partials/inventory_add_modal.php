<?php
$inventoryAddModalProducts = $inventoryModalProducts ?? $modalProducts ?? [];
$inventoryAddModalFormAction = $inventoryFormAction ?? 'inventory.php';
$inventoryAddModalIsEmbed = (bool) ($inventoryIsEmbed ?? false);
$inventoryAddModalReturnUrl = (string) ($inventoryReturnUrl ?? '');
?>
<div class="product-modal inventory-add-modal" id="inventory-add-modal" aria-hidden="true">
    <div class="product-modal__backdrop" data-inventory-add-close></div>
    <section class="product-modal__dialog inventory-add-modal__dialog" role="dialog" aria-modal="true" aria-label="Add inventory item" tabindex="-1">
        <button class="product-modal__close" type="button" aria-label="Close add item" data-inventory-add-close>x</button>
        <form class="inventory-add-modal__box" method="post" action="<?= e($inventoryAddModalFormAction); ?>" enctype="multipart/form-data" data-inventory-add-form>
            <?php if ($inventoryAddModalIsEmbed): ?>
                <input type="hidden" name="inventory_embed" value="1">
            <?php endif; ?>
            <?php if ($inventoryAddModalReturnUrl !== ''): ?>
                <input type="hidden" name="inventory_return" value="<?= e($inventoryAddModalReturnUrl); ?>">
            <?php endif; ?>
            <input type="hidden" name="inventory_action" value="save_product" data-product-form-action>
            <input type="hidden" name="add_product[id]" value="" data-add-product-id>
            <input type="hidden" name="product_command_id" value="" data-product-command-id>

            <div class="inventory-add-modal__layout">
                <aside class="inventory-database-panel" aria-label="Product database">
                    <div class="inventory-database-frame">
                        <div class="inventory-database-topline">
                            <span data-product-database-count>loaded <?= count($inventoryAddModalProducts); ?> active products</span>
                        </div>

                        <div class="inventory-database-toolbar">
                            <button class="inventory-modal-mini-button" type="button" data-product-database-refresh>Refresh Database</button>
                            <label class="inventory-database-search">
                                <input type="search" placeholder="Search" autocomplete="off" data-product-database-search aria-label="Search products">
                            </label>
                        </div>

                        <div class="inventory-database-table-shell">
                            <div class="cart-header inventory-database-heading">
                                <button class="cart-heading inventory-database-heading-tab inventory-database-heading--id" type="button" data-product-sort="id">ID</button>
                                <button class="cart-heading inventory-database-heading-tab inventory-database-heading--name" type="button" data-product-sort="name">Name</button>
                                <button class="cart-heading inventory-database-heading-tab inventory-database-heading--stock" type="button" data-product-sort="stock">Stock</button>
                            </div>

                            <div class="inventory-database-list" data-product-database-list>
                                <?php foreach ($inventoryAddModalProducts as $product): ?>
                                    <article
                                        class="inventory-database-row"
                                        data-product-row
                                        data-product-id="<?= (int) $product['id']; ?>"
                                        data-id="<?= (int) $product['id']; ?>"
                                        data-name="<?= e(strtolower($product['name'])); ?>"
                                        data-stock="<?= (int) $product['stock_quantity']; ?>"
                                        data-search="<?= e(strtolower((int) $product['id'] . ' ' . $product['name'] . ' ' . $product['slug'] . ' ' . (int) $product['stock_quantity'])); ?>"
                                    >
                                        <button class="inventory-database-main" type="button" data-product-select="<?= (int) $product['id']; ?>">
                                            <span class="inventory-database-id"><?= (int) $product['id']; ?></span>
                                            <span class="inventory-database-copy">
                                                <span class="inventory-database-media" aria-hidden="true">
                                                    <img src="<?= e($product['main_image']); ?>" alt="" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                                                </span>
                                                <span class="inventory-database-name"><?= e($product['name']); ?></span>
                                                <span class="inventory-database-meta"><?= e($product['slug']); ?></span>
                                            </span>
                                            <span class="inventory-database-stock"><?= (int) $product['stock_quantity']; ?></span>
                                        </button>
                                        <span class="inventory-database-actions">
                                            <button class="inventory-modal-mini-button" type="button" data-product-edit="<?= (int) $product['id']; ?>">
                                                <img class="inventory-database-action-icon" src="assets/images/pencil.png" alt="" aria-hidden="true">
                                                <span>Edit</span>
                                            </button>
                                            <button class="inventory-modal-mini-button" type="submit" formnovalidate data-product-command-action="duplicate_product" data-product-command-product="<?= (int) $product['id']; ?>">
                                                <img class="inventory-database-action-icon" src="assets/images/clone.png" alt="" aria-hidden="true">
                                                <span>Duplicate</span>
                                            </button>
                                            <button class="inventory-modal-mini-button inventory-modal-mini-button--danger" type="submit" formnovalidate data-product-command-action="delete_product" data-product-command-product="<?= (int) $product['id']; ?>">
                                                <img class="inventory-database-action-icon" src="assets/images/trash.png" alt="" aria-hidden="true">
                                                <span>Delete</span>
                                            </button>
                                        </span>
                                    </article>
                                <?php endforeach; ?>
                            </div>

                            <div class="product-modal__scrollbar inventory-database-scrollbar" aria-hidden="true" data-product-database-scrollbar>
                                <div class="product-modal__scrollbar-thumb inventory-database-scrollbar-thumb" data-product-database-scrollbar-thumb></div>
                            </div>
                        </div>
                    </div>
                </aside>

                <div class="inventory-add-modal__editor">
                    <div class="inventory-add-modal__scroll-frame">
                        <div class="inventory-add-modal__scroll" data-inventory-add-scroll>
                            <section class="inventory-modal-section">
                                <label class="inventory-modal-field">
                                    <span>Name</span>
                                    <input type="text" name="add_product[name]" maxlength="100" autocomplete="off" data-add-product-name required>
                                </label>
                                <div class="inventory-modal-field inventory-modal-field--with-action">
                                    <label>
                                        <span>Slug</span>
                                        <input type="text" name="add_product[slug]" maxlength="50" autocomplete="off" data-add-product-slug required>
                                    </label>
                                    <button class="inventory-modal-mini-button" type="button" data-add-product-generate-slug>Generate</button>
                                </div>
                                <label class="inventory-modal-field">
                                    <span>Price</span>
                                    <input type="text" name="add_product[price]" inputmode="decimal" autocomplete="off" data-add-product-price required>
                                </label>
                                <label class="inventory-modal-field">
                                    <span>Short description</span>
                                    <textarea name="add_product[short_description]" rows="4" required></textarea>
                                </label>
                                <label class="inventory-modal-field">
                                    <span>Main image</span>
                                    <input type="text" name="add_product[main_image]" value="assets/images/pc_noimage.png" autocomplete="off" data-add-product-image-path>
                                </label>
                                <div class="inventory-modal-inline-actions inventory-modal-image-actions">
                                    <label class="inventory-modal-mini-button inventory-modal-file-button">
                                        Browse
                                        <input type="file" name="add_product_image" accept=".png,.jpg,.jpeg,.gif,.webp" data-add-product-image-file>
                                    </label>
                                    <button class="inventory-modal-mini-button" type="button" data-add-product-placeholder>Use placeholder</button>
                                </div>
                                <div class="inventory-modal-inline-actions inventory-modal-active-row">
                                    <label class="inventory-modal-check">
                                        <input type="checkbox" name="add_product[is_active]" value="1" checked>
                                        <span>active storefront item</span>
                                    </label>
                                </div>
                            </section>

                            <section class="inventory-modal-section">
                                <div class="inventory-modal-stock-row">
                                    <span>Stock quantity</span>
                                    <div class="cart-quantity-control inventory-quantity-control inventory-modal-stock-control" aria-label="Stock quantity">
                                        <button class="cart-quantity-button" type="button" data-add-product-stock-step="-1" aria-label="Decrease stock">&minus;</button>
                                        <input class="cart-quantity-value inventory-quantity-value" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" name="add_product[stock_quantity]" value="1" data-add-product-stock aria-label="Stock quantity">
                                        <button class="cart-quantity-button" type="button" data-add-product-stock-step="1" aria-label="Increase stock">+</button>
                                    </div>
                                </div>
                                <label class="inventory-modal-field">
                                    <span>Location</span>
                                    <input type="text" name="add_product[location]" value="Main workshop" maxlength="80" autocomplete="off">
                                </label>
                                <label class="inventory-modal-field">
                                    <span>Supplier</span>
                                    <input type="text" name="add_product[supplier]" value="FixerUpper Build Team" maxlength="120" autocomplete="off">
                                </label>
                                <h3 class="inventory-modal-subheading">Specs</h3>
                                <div class="inventory-modal-specs" data-add-product-specs>
                                    <?php foreach (['Operating System', 'Processor', 'Graphics', 'Memory', 'Storage', 'Cooling', 'Case'] as $specLabel): ?>
                                        <div class="inventory-modal-spec-row">
                                            <input type="text" name="add_product[spec_label][]" value="<?= e($specLabel); ?>" maxlength="80" aria-label="Spec label">
                                            <input type="text" name="add_product[spec_value][]" aria-label="Spec value">
                                            <button class="inventory-modal-remove-spec" type="button" data-add-product-remove-spec aria-label="Remove spec row">Remove</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="inventory-modal-mini-button" type="button" data-add-product-add-spec>Add spec row</button>
                            </section>
                        </div>

                        <div class="product-modal__scrollbar inventory-add-modal__scrollbar" aria-hidden="true" data-inventory-add-scrollbar>
                            <div class="product-modal__scrollbar-thumb inventory-add-modal__scrollbar-thumb" data-inventory-add-scrollbar-thumb></div>
                        </div>
                    </div>

                    <div class="inventory-add-modal__actions">
                        <button class="inventory-action-button" type="button" data-add-product-clear>
                            <span>clear form</span>
                        </button>
                        <button class="inventory-action-button inventory-save" type="submit">
                            <span class="inventory-action-icon inventory-save-icon" aria-hidden="true"></span>
                            <span>save product</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>
