<?php
// Resume the cart session before reading any saved product quantities.
require_once __DIR__ . '/session.php';

// Load the product database connection for cart item hydration.
require_once __DIR__ . '/config.php';

// Shared formatting and escaping helpers keep cart output consistent with the storefront.
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/auth_helpers.php';

ensureInventoryTable($conn);
ensureCheckoutUsersTable($conn);
ensureCheckoutAdmin($conn);

// Clean the session cart so invalid ids and zero quantities never reach the query.
function normalizeCart($cart)
{
    $normalized = [];

    foreach ($cart as $productId => $quantity) {
        $productId = (int) $productId;
        $quantity = (int) $quantity;

        if ($productId > 0 && $quantity > 0) {
            $normalized[$productId] = $quantity;
        }
    }

    return $normalized;
}

$checkoutAuthMessage = '';
$checkoutAuthTone = '';
$checkoutModalShouldOpen = false;
$checkoutAuthValues = [
    'login_identifier' => '',
    'register_username' => '',
    'register_email' => '',
];
$checkoutAuthRedirect = '';
$checkoutAuthFlash = $_SESSION['checkout_auth_flash'] ?? null;
$checkoutCurrentUser = $_SESSION['checkout_user'] ?? null;
unset($_SESSION['checkout_auth_flash']);

if (is_array($checkoutAuthFlash)) {
    $checkoutAuthMessage = (string) ($checkoutAuthFlash['message'] ?? '');
    $checkoutAuthTone = (string) ($checkoutAuthFlash['tone'] ?? '');
    $checkoutAuthValues = array_merge($checkoutAuthValues, (array) ($checkoutAuthFlash['values'] ?? []));

    if ($checkoutAuthTone === 'error') {
        $checkoutModalShouldOpen = true;
    } else {
        $checkoutAuthMessage = '';
        $checkoutAuthTone = '';
    }
}

if (isset($_GET['checkout']) && $_GET['checkout'] === '1' && !$checkoutCurrentUser) {
    $checkoutModalShouldOpen = true;
}

$checkoutIsAdmin = checkoutUserIsAdmin($checkoutCurrentUser);
$checkoutUnderConstruction = isset($_GET['under_construction']) && $_GET['under_construction'] === '1';

$inventoryModalProducts = [];
$inventoryModalProductsJson = '[]';
$inventoryFormAction = 'inventory.php';
$inventoryReturnUrl = 'cart.php';
$modalEditProductId = 0;

if ($checkoutIsAdmin) {
    $inventoryModalProducts = inventoryModalProducts($conn);
    $inventoryModalProductsJson = json_encode(
        $inventoryModalProducts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?: '[]';
}

$checkoutAuthStatusMessage = $checkoutAuthMessage;
$checkoutAuthStatusTone = $checkoutAuthTone;

$sessionCart =& currentCart();
$cartQuantities = normalizeCart($sessionCart);
setCurrentCart($cartQuantities);
$sessionCart =& currentCart();
$cartProducts = [];
$cartItems = [];

// Load only the active products that are currently present in the visitor cart.
if ($cartQuantities) {
    $idList = implode(',', array_keys($cartQuantities));
    $productResult = $conn->query(
        "SELECT
            p.id,
            p.slug,
            p.name,
            p.price,
            p.main_image,
            COALESCE(i.stock_quantity, 0) AS stock_quantity
         FROM products p
         LEFT JOIN product_inventory i ON i.product_id = p.id
         WHERE p.is_active = 1 AND p.id IN ($idList)"
    );

    while ($product = $productResult->fetch_assoc()) {
        // Index products by id so the next loop can attach them to session quantities quickly.
        $cartProducts[(int) $product['id']] = $product;
    }

    // Preserve the session cart order while attaching product data and line totals.
    foreach ($cartQuantities as $productId => $quantity) {
        if (!isset($cartProducts[$productId])) {
            // Skip stale session rows that point to products no longer available.
            unset($sessionCart[$productId]);
            continue;
        }

        $product = $cartProducts[$productId];
        // Calculate line totals on the server so the browser never controls pricing.
        $lineTotal = (float) $product['price'] * $quantity;

        $cartItems[] = [
            'product' => $product,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
        ];
    }
}

// The badge counts unique cart products, so adding the same product twice does not move it.
$cartCount = count($cartItems);
$cartHasVisibleItems = $cartItems && !$checkoutUnderConstruction;

// Build a path-safe AJAX endpoint so the cart still works from /fixerupper.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$updateCartEndpoint = ($basePath === '' ? '' : $basePath) . '/update_cart.php';
$checkoutUnderConstructionEndpoint = ($basePath === '' ? '' : $basePath) . '/cart.php?under_construction=1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER Cart</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="cart-body<?= $cartHasVisibleItems ? ' cart-body--filled' : ' cart-body--empty'; ?><?= $checkoutModalShouldOpen ? ' modal-open' : ''; ?>">
    <!--
        Cart navigation mirrors the homepage header, but marks the cart link as current
        and shows the live cart badge from the server-rendered cart count.
    -->
    <nav>
        <!-- Brand logo links back to the storefront. -->
        <div class="nav-logo">
            <?php if ($checkoutCurrentUser): ?>
                <p class="nav-user-status">hi, <?= e($checkoutCurrentUser['username']); ?></p>
            <?php endif; ?>
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <!-- Main navigation keeps the same destinations as the homepage. -->
        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="cart.php?under_construction=1">ABOUT US</a>
            <a href="cart.php?under_construction=1">CONTACTS</a>
            <?php if ($checkoutIsAdmin): ?>
                <a href="#manage" data-inventory-add-open>MANAGE</a>
            <?php endif; ?>
        </div>

        <!-- Action icons remain available on the cart page for consistent navigation. -->
        <div class="nav-actions">
            <?php if ($checkoutCurrentUser): ?>
                <a href="logout.php" title="Logout" aria-label="Logout">
                    <img src="assets/images/logout_icon.png" alt="Logout">
                </a>
            <?php else: ?>
                <a href="cart.php?checkout=1" title="Login" aria-label="Login">
                    <img src="assets/images/login_icon.png" alt="Login">
                </a>
            <?php endif; ?>
            <a href="cart.php" title="Shopping Cart" aria-current="page">
                <img src="assets/images/shoppingcard_icon.png" alt="Cart">
                <span class="cart-badge" aria-live="polite"><?= $cartCount > 0 ? (int) $cartCount : ''; ?></span>
            </a>
            <a href="#search" title="Search">
                <img src="assets/images/search_icon.png" alt="Search">
            </a>
        </div>
    </nav>

    <main class="container cart-container">
        <!-- The cart table is rendered only when the visitor has active cart items. -->
        <section class="cart-page" aria-label="Shopping cart">
            <p class="cart-message" data-cart-message role="status" aria-live="polite"></p>
            <?php if ($checkoutUnderConstruction): ?>
                <p class="cart-empty">page is under construction.</p>
            <?php elseif (!$cartItems): ?>
                <!-- Empty-state fallback shown by PHP and recreated by JavaScript after final removal. -->
                <p class="cart-empty">your cart is empty.</p>
            <?php else: ?>
                <!-- JavaScript listens on this table wrapper for delegated quantity and remove events. -->
                <div class="cart-table" data-cart-table>
                    <!-- Visual column labels; aria-hidden keeps screen readers focused on actual row content. -->
                    <div class="cart-header" aria-hidden="true">
                        <span class="cart-heading cart-heading--items">ITEMS</span>
                        <span class="cart-heading cart-heading--quantity">QUANTITY</span>
                        <span class="cart-heading cart-heading--total">TOTAL PRICE</span>
                    </div>

                    <?php foreach ($cartItems as $item): ?>
                        <?php
                            // Keep the template below short by unpacking the current row values first.
                            $product = $item['product'];
                            $quantity = (int) $item['quantity'];
                            $stockQuantity = (int) $product['stock_quantity'];
                            $stockStatus = inventoryStatus($stockQuantity);
                        ?>
                        <!-- Each row carries the product id used by cart.js when posting updates. -->
                        <article
                            class="cart-row"
                            data-cart-row
                            data-product-id="<?= (int) $product['id']; ?>"
                            data-stock-available="<?= (int) $product['stock_quantity']; ?>"
                        >
                            <!-- Product image links back to the matching storefront modal anchor. -->
                            <a class="cart-item-media" href="index.php#<?= e($product['slug']); ?>" aria-label="<?= e($product['name']); ?>">
                                <img src="<?= e($product['main_image']); ?>" alt="<?= e($product['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <!-- Product name also links back to the storefront for quick inspection. -->
                            <a class="cart-item-title" href="index.php#<?= e($product['slug']); ?>">
                                <?= e($product['name']); ?>
                                <span class="cart-item-stock cart-item-stock--<?= e($stockStatus); ?>">
                                    <?= e(inventoryStockText($stockQuantity)); ?>
                                </span>
                            </a>

                            <!-- Quantity cell contains both normal controls and the hidden remove prompt. -->
                            <div class="cart-quantity-cell" data-cart-quantity-cell>
                                <!-- The visible capsule is hidden when the visitor reaches the remove prompt. -->
                                <div class="cart-quantity-control" aria-label="Quantity">
                                    <button class="cart-quantity-button" type="button" data-cart-step="-1" aria-label="Decrease quantity">&minus;</button>
                                    <input class="cart-quantity-value" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="2" autocomplete="off" value="<?= $quantity; ?>" data-cart-quantity aria-label="Quantity">
                                    <button class="cart-quantity-button" type="button" data-cart-step="1" aria-label="Increase quantity">+</button>
                                </div>
                                <!-- Confirmation prevents accidental deletion when quantity is reduced to zero. -->
                                <div class="cart-remove-confirmation" data-cart-remove-confirmation hidden>
                                    <span class="cart-remove-label">REMOVE ITEM?</span>
                                    <span class="cart-remove-options">
                                        <button class="cart-remove-choice" type="button" data-cart-remove-confirm="yes">yes</button>
                                        <span class="cart-remove-separator" aria-hidden="true">/</span>
                                        <button class="cart-remove-choice" type="button" data-cart-remove-confirm="no">no</button>
                                    </span>
                                </div>
                            </div>

                            <!-- Line total is refreshed by JavaScript after each successful quantity update. -->
                            <div class="cart-line-total" data-cart-line-total><?= cartPrice($item['line_total']); ?></div>
                        </article>
                    <?php endforeach; ?>

                    <button
                        class="cart-checkout-summary"
                        type="button"
                        data-checkout-trigger
                        data-checkout-authenticated="<?= $checkoutCurrentUser ? '1' : '0'; ?>"
                        data-checkout-redirect="<?= e($checkoutUnderConstructionEndpoint); ?>"
                    >
                        <img class="cart-checkout-badge" src="assets/images/checkout.svg" alt="" aria-hidden="true">
                        <span class="cart-checkout-button">PROCEED TO CHECKOUT</span>
                    </button>
                </div>
            <?php endif; ?>
        </section>

        <div class="products-dots cart-ad-divider" aria-hidden="true"></div>

        <!-- Advertising carousel mirrors the homepage logo strip. -->
        <section class="advertising-block" aria-label="Advertising banners">
            <div class="ad-viewport">
                <div class="ad-track">
                    <div class="ad-set">
                        <div class="ad-banner">
                            <a href="https://www.amd.com/" target="_blank" rel="noopener noreferrer" aria-label="AMD official website">
                                <img class="ad-logo-amd" src="assets/images/amd_logo.png" alt="AMD">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.apple.com/" target="_blank" rel="noopener noreferrer" aria-label="Apple official website">
                                <img class="ad-logo-compact ad-logo-apple" src="assets/images/apple_logo.png" alt="Apple">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.nvidia.com/" target="_blank" rel="noopener noreferrer" aria-label="NVIDIA official website">
                                <img src="assets/images/nvidia_logo.png" alt="NVIDIA">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://store.steampowered.com/" target="_blank" rel="noopener noreferrer" aria-label="Steam official website">
                                <img class="ad-logo-compact" src="assets/images/steam_logo.png" alt="Steam">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.intel.com/" target="_blank" rel="noopener noreferrer" aria-label="Intel official website">
                                <img src="assets/images/intel_logo.png" alt="Intel">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.ea.com/ea-app" target="_blank" rel="noopener noreferrer" aria-label="Origin EA app official website">
                                <img src="assets/images/origin_logo.png" alt="Origin">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.xbox.com/" target="_blank" rel="noopener noreferrer" aria-label="Xbox official website">
                                <img class="ad-logo-compact" src="assets/images/xbox_logo.png" alt="Xbox">
                            </a>
                        </div>
                    </div>
                    <div class="ad-set">
                        <div class="ad-banner">
                            <a href="https://www.amd.com/" target="_blank" rel="noopener noreferrer" aria-label="AMD official website">
                                <img class="ad-logo-amd" src="assets/images/amd_logo.png" alt="AMD">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.apple.com/" target="_blank" rel="noopener noreferrer" aria-label="Apple official website">
                                <img class="ad-logo-compact ad-logo-apple" src="assets/images/apple_logo.png" alt="Apple">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.nvidia.com/" target="_blank" rel="noopener noreferrer" aria-label="NVIDIA official website">
                                <img src="assets/images/nvidia_logo.png" alt="NVIDIA">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://store.steampowered.com/" target="_blank" rel="noopener noreferrer" aria-label="Steam official website">
                                <img class="ad-logo-compact" src="assets/images/steam_logo.png" alt="Steam">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.intel.com/" target="_blank" rel="noopener noreferrer" aria-label="Intel official website">
                                <img src="assets/images/intel_logo.png" alt="Intel">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.ea.com/ea-app" target="_blank" rel="noopener noreferrer" aria-label="Origin EA app official website">
                                <img src="assets/images/origin_logo.png" alt="Origin">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.xbox.com/" target="_blank" rel="noopener noreferrer" aria-label="Xbox official website">
                                <img class="ad-logo-compact" src="assets/images/xbox_logo.png" alt="Xbox">
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Shared site footer with author credit. -->
        <footer class="site-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>

    <?php if (!$checkoutCurrentUser): ?>
        <div class="checkout-modal<?= $checkoutModalShouldOpen ? ' is-open' : ''; ?>" id="checkout-modal" aria-hidden="<?= $checkoutModalShouldOpen ? 'false' : 'true'; ?>">
            <div class="checkout-modal__backdrop" data-checkout-modal-close></div>
            <section class="checkout-modal__dialog" role="dialog" aria-modal="true" aria-label="Checkout">
                <button class="checkout-modal__close" type="button" aria-label="Close checkout" data-checkout-modal-close>x</button>
                <div class="checkout-modal__panel">
                    <?php if ($checkoutAuthStatusMessage !== ''): ?>
                        <p
                            class="checkout-auth-message is-visible"
                            data-checkout-auth-message
                            data-tone="<?= e($checkoutAuthStatusTone); ?>"
                            data-checkout-auth-redirect="<?= e($checkoutAuthRedirect); ?>"
                            role="status"
                            aria-live="polite"
                        >
                            <?= e($checkoutAuthStatusMessage); ?>
                        </p>
                    <?php endif; ?>

                    <form class="checkout-auth-card" method="post" action="login.php" data-checkout-auth-form="login">
                        <input type="hidden" name="checkout_auth_action" value="login">
                        <section class="inventory-modal-section checkout-auth-section">
                            <h2 class="checkout-auth-title">sign in</h2>
                            <label class="inventory-modal-field">
                                <span>Email or username</span>
                                <input type="text" name="login_identifier" maxlength="100" autocomplete="username" value="<?= e($checkoutAuthValues['login_identifier']); ?>" required>
                            </label>
                            <label class="inventory-modal-field">
                                <span>Password</span>
                                <input type="password" name="login_password" autocomplete="current-password" required>
                            </label>
                        </section>
                        <div class="checkout-auth-actions">
                            <button class="inventory-action-button" type="submit">
                                <span>sign in</span>
                            </button>
                        </div>
                    </form>

                    <div class="checkout-auth-divider" aria-hidden="true">
                        <span>or</span>
                    </div>

                    <form class="checkout-auth-card" method="post" action="login.php" data-checkout-auth-form="register">
                        <input type="hidden" name="checkout_auth_action" value="register">
                        <section class="inventory-modal-section checkout-auth-section">
                            <h2 class="checkout-auth-title">create account</h2>
                            <label class="inventory-modal-field">
                                <span>Username</span>
                                <input type="text" name="register_username" maxlength="50" autocomplete="username" value="<?= e($checkoutAuthValues['register_username']); ?>" required>
                            </label>
                            <label class="inventory-modal-field">
                                <span>Email</span>
                                <input type="email" name="register_email" maxlength="100" autocomplete="email" value="<?= e($checkoutAuthValues['register_email']); ?>" required>
                            </label>
                            <label class="inventory-modal-field">
                                <span>Password</span>
                                <input type="password" name="register_password" autocomplete="new-password" required>
                            </label>
                            <label class="inventory-modal-field">
                                <span>Confirm password</span>
                                <input type="password" name="register_password_confirm" autocomplete="new-password" required>
                            </label>
                        </section>
                        <div class="checkout-auth-actions">
                            <button class="inventory-action-button" type="submit">
                                <span>create account</span>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($checkoutIsAdmin): ?>
        <?php include __DIR__ . '/partials/inventory_add_modal.php'; ?>
    <?php endif; ?>

    <!-- Expose the update endpoint before cart.js initializes. -->
    <script>
        window.fixerupperUpdateCartEndpoint = "<?= e($updateCartEndpoint); ?>";
    </script>
    <?php if ($checkoutIsAdmin): ?>
        <script>
            window.fixerUpperInventoryProducts = <?= $inventoryModalProductsJson; ?>;
            window.fixerUpperInventoryEditProductId = <?= (int) $modalEditProductId; ?>;
        </script>
        <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js'); ?>"></script>
    <?php endif; ?>
    <script src="assets/js/cart.js?v=<?= filemtime(__DIR__ . '/assets/js/cart.js'); ?>"></script>
</body>
</html>
