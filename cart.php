<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cartPrice($price)
{
    return '&pound; ' . number_format((float) $price, 2, ',', ' ');
}

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

if ($cartQuantities) {
    $idList = implode(',', array_keys($cartQuantities));
    $productResult = $conn->query(
        "SELECT id, slug, name, price, main_image
         FROM products
         WHERE is_active = 1 AND id IN ($idList)"
    );

    while ($product = $productResult->fetch_assoc()) {
        $cartProducts[(int) $product['id']] = $product;
    }

    foreach ($cartQuantities as $productId => $quantity) {
        if (!isset($cartProducts[$productId])) {
            continue;
        }

        $product = $cartProducts[$productId];
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
<body class="cart-body">
    <nav>
        <div class="nav-logo">
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="index.php#about">ABOUT US</a>
            <a href="index.php#contacts">CONTACTS</a>
        </div>

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
        <section class="cart-page" aria-label="Shopping cart">
            <?php if (!$cartItems): ?>
                <p class="cart-empty">Your cart is empty.</p>
            <?php else: ?>
                <div class="cart-table" data-cart-table>
                    <div class="cart-header" aria-hidden="true">
                        <span class="cart-heading cart-heading--items">ITEMS</span>
                        <span class="cart-heading cart-heading--quantity">QUANTITY</span>
                        <span class="cart-heading cart-heading--price">PRICE</span>
                        <span class="cart-heading cart-heading--total">TOTAL</span>
                    </div>

                    <?php foreach ($cartItems as $item): ?>
                        <?php
                            $product = $item['product'];
                            $quantity = (int) $item['quantity'];
                        ?>
                        <article
                            class="cart-row"
                            data-cart-row
                            data-product-id="<?= (int) $product['id']; ?>"
                            data-unit-price="<?= e($product['price']); ?>"
                        >
                            <a class="cart-item-media" href="index.php#<?= e($product['slug']); ?>" aria-label="<?= e($product['name']); ?>">
                                <img src="<?= e($product['main_image']); ?>" alt="<?= e($product['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <a class="cart-item-title" href="index.php#<?= e($product['slug']); ?>">
                                <?= e($product['name']); ?>
                            </a>

                            <div class="cart-quantity-control" aria-label="Quantity">
                                <button class="cart-quantity-button" type="button" data-cart-step="-1" aria-label="Decrease quantity"<?= $quantity <= 1 ? ' disabled' : ''; ?>>&minus;</button>
                                <span class="cart-quantity-value" data-cart-quantity><?= $quantity; ?></span>
                                <button class="cart-quantity-button" type="button" data-cart-step="1" aria-label="Increase quantity">+</button>
                            </div>

                            <div class="cart-price-breakdown">
                                <span data-cart-unit-price<?= $quantity <= 1 ? ' hidden' : ''; ?>><?= cartPrice($product['price']); ?></span>
                                <span class="cart-price-multiplier" data-cart-multiplier<?= $quantity <= 1 ? ' hidden' : ''; ?>><?= $quantity > 1 ? 'x ' . $quantity : ''; ?></span>
                            </div>

                            <div class="cart-line-total" data-cart-line-total><?= cartPrice($item['line_total']); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="products-dots cart-ad-divider" aria-hidden="true"></div>

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

        <footer class="site-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>

    <script>
        window.fixerupperUpdateCartEndpoint = "<?= e($updateCartEndpoint); ?>";
    </script>
    <script src="assets/js/cart.js?v=<?= filemtime(__DIR__ . '/assets/js/cart.js'); ?>"></script>
</body>
</html>
