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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $checkoutAction = (string) ($_POST['checkout_action'] ?? '');

    if ($checkoutAction === 'confirm_order') {
        try {
            $order = checkoutCreateOrderFromCart($conn, (int) $checkoutCurrentUser['id'], currentCart());
            setCurrentCart([]);
            header('Location: order_complete.php?order_id=' . (int) $order['id']);
            exit;
        } catch (Throwable $error) {
            $_SESSION['checkout_order_error'] = $error->getMessage();
            header('Location: confirm_order.php');
            exit;
        }
    }
}

$orderError = (string) ($_SESSION['checkout_order_error'] ?? '');
unset($_SESSION['checkout_order_error']);

$cartSummary = checkoutLoadCartSummary($conn, currentCart());
setCurrentCart($cartSummary['cart']);

$cartItems = $cartSummary['items'];
$cartCount = cartBadgeCount($cartSummary['cart']);
$cartTotal = (float) $cartSummary['total'];
$cartQuantityTotal = (int) $cartSummary['quantity_total'];
$cartHasStockIssue = (bool) $cartSummary['has_stock_issue'];
$cartHasVisibleItems = (bool) $cartItems;

$inventoryModalProducts = [];
$inventoryModalProductsJson = '[]';
$inventoryFormAction = 'inventory.php';
$inventoryReturnUrl = 'confirm_order.php';
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
    <title>FIXERUPPER Confirm Order</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="cart-body checkout-review-body<?= $cartHasVisibleItems ? ' cart-body--filled' : ' cart-body--empty'; ?>">
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

    <main class="container cart-container checkout-review-container">
        <section class="cart-page checkout-review-page" aria-label="Confirm order">
            <?php if ($orderError !== ''): ?>
                <p class="cart-message checkout-review-message is-visible" data-tone="error" role="status">
                    <?= e($orderError); ?>
                </p>
            <?php endif; ?>

            <?php if (!$cartItems): ?>
                <div class="checkout-empty-panel">
                    <p class="cart-empty">your cart is empty.</p>
                    <a class="inventory-action-button checkout-return-button" href="index.php">
                        <span>back to store</span>
                    </a>
                </div>
            <?php else: ?>
                <div class="cart-table checkout-review-table">
                    <div class="cart-header" aria-hidden="true">
                        <span class="cart-heading cart-heading--items">ITEMS</span>
                        <span class="cart-heading cart-heading--quantity">QUANTITY</span>
                        <span class="cart-heading cart-heading--total">TOTAL PRICE</span>
                    </div>

                    <?php foreach ($cartItems as $item): ?>
                        <?php
                            $product = $item['product'];
                            $quantity = (int) $item['quantity'];
                            $stockQuantity = (int) $item['stock_quantity'];
                            $stockStatus = inventoryStatus($stockQuantity);
                        ?>
                        <article class="cart-row checkout-review-row<?= $item['has_stock_issue'] ? ' checkout-review-row--warning' : ''; ?>">
                            <a class="cart-item-media" href="index.php#<?= e($product['slug']); ?>" aria-label="<?= e($product['name']); ?>">
                                <img src="<?= e($product['main_image']); ?>" alt="<?= e($product['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <a class="cart-item-title" href="index.php#<?= e($product['slug']); ?>">
                                <?= e($product['name']); ?>
                                <span class="cart-item-stock cart-item-stock--<?= e($stockStatus); ?>">
                                    <?php if ($item['has_stock_issue']): ?>
                                        only <?= (int) $stockQuantity; ?> available
                                    <?php else: ?>
                                        <?= e(inventoryStockText($stockQuantity)); ?>
                                    <?php endif; ?>
                                </span>
                            </a>

                            <div class="cart-quantity-cell checkout-review-quantity">
                                <span><?= (int) $quantity; ?></span>
                            </div>

                            <div class="cart-line-total"><?= cartPrice($item['line_total']); ?></div>
                        </article>
                    <?php endforeach; ?>

                    <section class="checkout-total-panel" aria-label="Order total">
                        <div class="checkout-total-row">
                            <span>Total</span>
                            <strong><?= cartPrice($cartTotal); ?></strong>
                        </div>
                        <p class="checkout-total-note">
                            <?= (int) $cartQuantityTotal; ?> item<?= $cartQuantityTotal === 1 ? '' : 's'; ?> ready for confirmation.
                        </p>

                        <?php if ($cartHasStockIssue): ?>
                            <p class="checkout-stock-warning">update your cart before confirming this order.</p>
                            <a class="inventory-action-button checkout-return-button" href="cart.php">
                                <span>back to cart</span>
                            </a>
                        <?php else: ?>
                            <form class="checkout-confirm-form" method="post" action="confirm_order.php" data-checkout-confirm-form>
                                <input type="hidden" name="checkout_action" value="confirm_order">
                                <button class="cart-checkout-summary checkout-confirm-button" type="submit" data-checkout-confirm-button>
                                    <span class="cart-checkout-button">CONFIRM ORDER</span>
                                </button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </section>

        <div class="products-dots cart-ad-divider checkout-review-ad-divider" aria-hidden="true"></div>
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
    <script src="assets/js/checkout-confirm.js?v=<?= filemtime(__DIR__ . '/assets/js/checkout-confirm.js'); ?>"></script>
</body>
</html>
