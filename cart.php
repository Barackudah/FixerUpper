<?php
// Resume the cart session before reading any saved product quantities.
require_once __DIR__ . '/session.php';

// Load the product database connection for cart item hydration.
require_once __DIR__ . '/config.php';

// Shared formatting and escaping helpers keep cart output consistent with the storefront.
require_once __DIR__ . '/helpers.php';

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

$cartQuantities = normalizeCart($_SESSION['cart'] ?? []);
$cartProducts = [];
$cartItems = [];

// Load only the active products that are currently present in the visitor cart.
if ($cartQuantities) {
    $idList = implode(',', array_keys($cartQuantities));
    $productResult = $conn->query(
        "SELECT id, slug, name, price, main_image
         FROM products
         WHERE is_active = 1 AND id IN ($idList)"
    );

    while ($product = $productResult->fetch_assoc()) {
        // Index products by id so the next loop can attach them to session quantities quickly.
        $cartProducts[(int) $product['id']] = $product;
    }

    // Preserve the session cart order while attaching product data and line totals.
    foreach ($cartQuantities as $productId => $quantity) {
        if (!isset($cartProducts[$productId])) {
            // Skip stale session rows that point to products no longer available.
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

// Build a path-safe AJAX endpoint so the cart still works from /fixerupper.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$updateCartEndpoint = ($basePath === '' ? '' : $basePath) . '/update_cart.php';
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
<body class="cart-body<?= $cartItems ? ' cart-body--filled' : ' cart-body--empty'; ?>">
    <!--
        Cart navigation mirrors the homepage header, but marks the cart link as current
        and shows the live cart badge from the server-rendered cart count.
    -->
    <nav>
        <!-- Brand logo links back to the storefront. -->
        <div class="nav-logo">
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <!-- Main navigation keeps the same destinations as the homepage. -->
        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="index.php#about">ABOUT US</a>
            <a href="index.php#contacts">CONTACTS</a>
        </div>

        <!-- Action icons remain available on the cart page for consistent navigation. -->
        <div class="nav-actions">
            <a href="#login" title="Login">
                <img src="assets/images/login_icon.png" alt="Login">
            </a>
            <a href="#logout" title="Logout">
                <img src="assets/images/logout_icon.png" alt="Logout">
            </a>
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
            <?php if (!$cartItems): ?>
                <!-- Empty-state fallback shown by PHP and recreated by JavaScript after final removal. -->
                <p class="cart-empty">Your cart is empty.</p>
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
                        ?>
                        <!-- Each row carries the product id used by cart.js when posting updates. -->
                        <article
                            class="cart-row"
                            data-cart-row
                            data-product-id="<?= (int) $product['id']; ?>"
                        >
                            <!-- Product image links back to the matching storefront modal anchor. -->
                            <a class="cart-item-media" href="index.php#<?= e($product['slug']); ?>" aria-label="<?= e($product['name']); ?>">
                                <img src="<?= e($product['main_image']); ?>" alt="<?= e($product['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <!-- Product name also links back to the storefront for quick inspection. -->
                            <a class="cart-item-title" href="index.php#<?= e($product['slug']); ?>">
                                <?= e($product['name']); ?>
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
                </div>
            <?php endif; ?>
        </section>

        <div class="products-dots cart-ad-divider" aria-hidden="true"></div>

        <!-- Advertising carousel duplicated exactly like the homepage for continuous logo scrolling. -->
        <section class="advertising-block" aria-label="Advertising banners">
            <div class="ad-viewport">
                <div class="ad-track">
                    <!-- First logo set. -->
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
                    <!-- Duplicate logo set; CSS moves the track by half its width for a seamless loop. -->
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

    <!-- Expose the update endpoint before cart.js initializes. -->
    <script>
        window.fixerupperUpdateCartEndpoint = "<?= e($updateCartEndpoint); ?>";
    </script>
    <script src="assets/js/cart.js?v=<?= filemtime(__DIR__ . '/assets/js/cart.js'); ?>"></script>
</body>
</html>
