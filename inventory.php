<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';

ensureInventoryTable($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedItems = $_POST['inventory'] ?? [];
    $stmt = $conn->prepare(
        'INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            stock_quantity = VALUES(stock_quantity),
            location = VALUES(location),
            supplier = VALUES(supplier)'
    );

    foreach ($submittedItems as $productId => $item) {
        $productId = (int) $productId;

        if ($productId < 1) {
            continue;
        }

        $stockQuantity = max(0, (int) ($item['stock_quantity'] ?? 0));
        $location = substr(trim((string) ($item['location'] ?? '')), 0, 80);
        $supplier = substr(trim((string) ($item['supplier'] ?? '')), 0, 120);

        if ($location === '') {
            $location = 'Unassigned';
        }

        $stmt->bind_param('iiss', $productId, $stockQuantity, $location, $supplier);
        $stmt->execute();
    }

    $_SESSION['inventory_notice'] = 'Inventory updated.';
    header('Location: inventory.php');
    exit;
}

$inventoryResult = $conn->query(
    "SELECT
        p.id,
        p.slug,
        p.name,
        p.price,
        p.main_image,
        COALESCE(i.stock_quantity, 0) AS stock_quantity,
        COALESCE(NULLIF(i.location, ''), 'Unassigned') AS location,
        COALESCE(i.supplier, '') AS supplier,
        i.updated_at
     FROM products p
     LEFT JOIN product_inventory i ON i.product_id = p.id
     WHERE p.is_active = 1
     ORDER BY p.id"
);

$inventoryItems = [];

while ($item = $inventoryResult->fetch_assoc()) {
    $stockQuantity = (int) $item['stock_quantity'];
    $status = inventoryStatus($stockQuantity);

    $item['stock_quantity'] = $stockQuantity;
    $item['stock_status'] = $status;
    $item['stock_label'] = inventoryStatusLabel($stockQuantity);

    $inventoryItems[] = $item;
}

$cartCount = cartBadgeCount($_SESSION['cart'] ?? []);
$notice = $_SESSION['inventory_notice'] ?? '';
unset($_SESSION['inventory_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="inventory-body cart-body--filled">
    <nav>
        <div class="nav-logo">
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="index.php#about">ABOUT US</a>
            <a href="inventory.php" aria-current="page">INVENTORY</a>
            <a href="index.php#contacts">CONTACTS</a>
        </div>

        <div class="nav-actions">
            <a href="#login" title="Login">
                <img src="assets/images/login_icon.png" alt="Login">
            </a>
            <a href="#logout" title="Logout">
                <img src="assets/images/logout_icon.png" alt="Logout">
            </a>
            <a href="cart.php" title="Shopping Cart">
                <img src="assets/images/shoppingcard_icon.png" alt="Cart">
                <span class="cart-badge" aria-live="polite"><?= $cartCount > 0 ? (int) $cartCount : ''; ?></span>
            </a>
            <a href="#search" title="Search">
                <img src="assets/images/search_icon.png" alt="Search">
            </a>
        </div>
    </nav>

    <main class="container cart-container inventory-container">
        <section class="cart-page inventory-page" aria-label="Inventory">
            <?php if ($notice): ?>
                <p class="cart-message inventory-notice is-visible" role="status"><?= e($notice); ?></p>
            <?php endif; ?>

            <form id="inventory-form" class="inventory-form" method="post" action="inventory.php">
                <div class="cart-table inventory-table" data-inventory-table>
                    <div class="cart-header inventory-header" aria-hidden="true">
                        <span class="cart-heading cart-heading--items inventory-heading--items">ITEMS</span>
                        <span class="cart-heading inventory-heading inventory-heading--status">STATUS</span>
                        <span class="cart-heading inventory-heading inventory-heading--stock">STOCK</span>
                        <span class="cart-heading inventory-heading inventory-heading--location">LOCATION</span>
                        <span class="cart-heading inventory-heading inventory-heading--supplier">SUPPLIER</span>
                    </div>

                    <?php foreach ($inventoryItems as $item): ?>
                        <article class="cart-row inventory-row">
                            <a class="cart-item-media" href="index.php#<?= e($item['slug']); ?>" aria-label="<?= e($item['name']); ?>">
                                <img src="<?= e($item['main_image']); ?>" alt="<?= e($item['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <a class="cart-item-title inventory-item-title" href="index.php#<?= e($item['slug']); ?>">
                                <?= e($item['name']); ?>
                                <span class="cart-item-stock"><?= e($item['slug']); ?></span>
                            </a>

                            <span class="inventory-status inventory-status--<?= e($item['stock_status']); ?>">
                                <?= e($item['stock_label']); ?>
                            </span>

                            <div class="cart-quantity-cell inventory-quantity-cell inventory-quantity-cell--stock">
                                <span class="visually-hidden">Stock</span>
                                <div class="cart-quantity-control inventory-quantity-control" aria-label="Stock">
                                    <button class="cart-quantity-button" type="button" data-inventory-step="-1" aria-label="Decrease stock">&minus;</button>
                                    <input class="cart-quantity-value inventory-quantity-value" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" name="inventory[<?= (int) $item['id']; ?>][stock_quantity]" value="<?= (int) $item['stock_quantity']; ?>" data-inventory-quantity aria-label="Stock">
                                    <button class="cart-quantity-button" type="button" data-inventory-step="1" aria-label="Increase stock">+</button>
                                </div>
                                <div class="cart-remove-confirmation" data-inventory-remove-confirmation hidden>
                                    <span class="cart-remove-label">REMOVE ITEM?</span>
                                    <span class="cart-remove-options">
                                        <button class="cart-remove-choice" type="button" data-inventory-remove-confirm="yes">yes</button>
                                        <span class="cart-remove-separator" aria-hidden="true">/</span>
                                        <button class="cart-remove-choice" type="button" data-inventory-remove-confirm="no">no</button>
                                    </span>
                                </div>
                            </div>

                            <label class="inventory-text-field inventory-text-field--location">
                                <span class="visually-hidden">Location</span>
                                <input type="text" maxlength="80" name="inventory[<?= (int) $item['id']; ?>][location]" value="<?= e($item['location']); ?>">
                            </label>

                            <label class="inventory-text-field inventory-text-field--supplier">
                                <span class="visually-hidden">Supplier</span>
                                <input type="text" maxlength="120" name="inventory[<?= (int) $item['id']; ?>][supplier]" value="<?= e($item['supplier']); ?>">
                            </label>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="inventory-actions">
                    <button class="inventory-action-button inventory-add" type="button">
                        <span class="inventory-action-icon inventory-add-icon" aria-hidden="true"></span>
                        <span>add item</span>
                    </button>
                    <button class="inventory-action-button inventory-save" type="submit">
                        <span class="inventory-action-icon inventory-save-icon" aria-hidden="true"></span>
                        <span>save inventory</span>
                    </button>
                </div>
            </form>
        </section>

        <footer class="site-footer inventory-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>
    <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js'); ?>"></script>
</body>
</html>
