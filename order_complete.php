<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/checkout_helpers.php';

ensureInventoryTable($conn);
ensureCheckoutUsersTable($conn);
ensureCheckoutAdmin($conn);
ensureCheckoutOrderTables($conn);

$checkoutCurrentUser = $_SESSION['checkout_user'] ?? null;

if (!$checkoutCurrentUser) {
    header('Location: cart.php?checkout=1');
    exit;
}

$checkoutIsAdmin = checkoutUserIsAdmin($checkoutCurrentUser);
$orderId = (int) ($_GET['order_id'] ?? 0);
$order = checkoutFindOrderForUser($conn, $orderId, (int) $checkoutCurrentUser['id']);
$cartCount = cartBadgeCount(currentCart());

$inventoryModalProducts = [];
$inventoryModalProductsJson = '[]';
$inventoryFormAction = 'inventory.php';
$inventoryReturnUrl = 'order_complete.php?order_id=' . $orderId;
$modalEditProductId = 0;

if ($checkoutIsAdmin) {
    $inventoryModalProducts = inventoryModalProducts($conn);
    $inventoryModalProductsJson = json_encode(
        $inventoryModalProducts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?: '[]';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER Order Complete</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="cart-body checkout-complete-body">
    <nav>
        <div class="nav-logo">
            <p class="nav-user-status">hi, <?= e($checkoutCurrentUser['username']); ?></p>
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="cart.php?under_construction=1">ABOUT US</a>
            <a href="cart.php?under_construction=1">CONTACTS</a>
            <?php if ($checkoutIsAdmin): ?>
                <a href="#manage" data-inventory-add-open>MANAGE</a>
            <?php endif; ?>
        </div>

        <div class="nav-actions">
            <a href="logout.php" title="Logout" aria-label="Logout">
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

    <main class="container cart-container checkout-complete-container">
        <section class="cart-page checkout-complete-page" aria-label="Order completed">
            <?php if (!$order): ?>
                <div class="checkout-empty-panel">
                    <p class="cart-empty">order not found.</p>
                    <a class="inventory-action-button checkout-return-button" href="cart.php">
                        <span>back to cart</span>
                    </a>
                </div>
            <?php else: ?>
                <section class="checkout-complete-panel">
                    <p class="checkout-complete-kicker">order <?= e(checkoutOrderNumber($order['id'])); ?></p>
                    <h1>order completed.</h1>
                    <p class="checkout-complete-copy">your details have been submitted.</p>

                </section>
            <?php endif; ?>
        </section>

        <div class="products-dots cart-ad-divider checkout-complete-ad-divider" aria-hidden="true"></div>
        <?php include __DIR__ . '/partials/advertising_carousel.php'; ?>

        <footer class="site-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>

    <?php if ($checkoutIsAdmin): ?>
        <?php include __DIR__ . '/partials/inventory_add_modal.php'; ?>
        <script>
            window.fixerUpperInventoryProducts = <?= $inventoryModalProductsJson; ?>;
            window.fixerUpperInventoryEditProductId = <?= (int) $modalEditProductId; ?>;
        </script>
        <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js'); ?>"></script>
    <?php endif; ?>
</body>
</html>
