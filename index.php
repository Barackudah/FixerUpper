<?php
// Start the session before rendering so the cart badge can reflect the current cart.
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function productPrice($price)
{
    return '&pound; ' . number_format((float) $price, 0, '.', '');
}

function cartBadgeCount($cart)
{
    $count = 0;

    foreach ($cart as $quantity) {
        if ((int) $quantity > 0) {
            $count++;
        }
    }

    return $count;
}

function productDividerClasses($position)
{
    $classes = ['products-dots', 'products-dots--inline'];

    if ($position % 3 === 0) {
        $classes[] = 'products-dots--desktop-row';
    }

    $classes[] = $position % 2 === 0 ? 'products-dots--compact-row' : 'products-dots--single-column';

    return implode(' ', $classes);
}

$productsById = [];
$productResult = $conn->query(
    'SELECT id, slug, name, short_description, price, main_image
     FROM products
     WHERE is_active = 1
     ORDER BY id'
);

while ($product = $productResult->fetch_assoc()) {
    $product['specs'] = [];
    $product['images'] = [];
    $productsById[(int) $product['id']] = $product;
}

$productIds = array_keys($productsById);

if ($productIds) {
    $idList = implode(',', $productIds);

    $imageResult = $conn->query(
        "SELECT product_id, image_path, alt_text
         FROM product_images
         WHERE product_id IN ($idList)
         ORDER BY product_id, sort_order, id"
    );

    while ($image = $imageResult->fetch_assoc()) {
        $productId = (int) $image['product_id'];
        $productsById[$productId]['images'][] = $image;
    }

    $specResult = $conn->query(
        "SELECT product_id, label, value
         FROM product_specs
         WHERE product_id IN ($idList)
         ORDER BY product_id, sort_order, id"
    );

    while ($spec = $specResult->fetch_assoc()) {
        $productId = (int) $spec['product_id'];
        $productsById[$productId]['specs'][] = $spec;
    }
}

$products = array_values($productsById);
$modalProducts = [];

// The navigation badge shows unique products and stays empty when the cart has no items.
$cartCount = cartBadgeCount($_SESSION['cart'] ?? []);

// Build a site-relative endpoint so AJAX still works from a subdirectory install.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$cartEndpoint = ($basePath === '' ? '' : $basePath) . '/add_to_cart.php';

foreach ($products as $product) {
    $images = [];

    foreach ($product['images'] as $image) {
        $images[] = [
            'src' => $image['image_path'],
            'alt' => $image['alt_text'],
        ];
    }

    if (!$images) {
        $images[] = [
            'src' => $product['main_image'],
            'alt' => $product['name'],
        ];
    }

    $details = [];

    foreach ($product['specs'] as $spec) {
        $details[] = [$spec['label'], $spec['value']];
    }

    $modalProducts[$product['slug']] = [
        'id' => (int) $product['id'],
        'slug' => $product['slug'],
        'title' => $product['name'],
        'price' => productPrice($product['price']),
        'image' => $product['main_image'],
        'images' => $images,
        'details' => $details,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!--
        Basic page settings:
        - UTF-8 ensures that text and special characters are displayed correctly.
        - viewport keeps page scaling predictable across different screens.
        - Google Fonts loads Montserrat and Teko, which are used throughout the interface.
        - style.css contains all visual styling: grids, cards, navigation and advertising animation.
    -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>
    <!--
        Top navigation bar.
        It is divided into three logical areas:
        1. The FIXERUPPER brand logo.
        2. The main menu with anchor links.
        3. Action icons for login, logout, cart and search.
    -->
    <nav>
        <!-- The logo is placed in its own block so it can be aligned independently from the menu. -->
        <div class="nav-logo">
            <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
        </div>

        <!-- The main menu uses anchor links; currently they point to future page sections. -->
        <div class="nav-menu">
            <a href="#home">HOME</a>
            <a href="#about">ABOUT US</a>
            <a href="#contacts">CONTACTS</a>
        </div>

        <!--
            Action icons are links, so real login, cart and search pages can be connected later.
            The title attribute provides a hover hint, while alt text improves accessibility and
            helps when an image fails to load.
        -->
        <div class="nav-actions">
            <a href="#login" title="Login">
                <img src="assets/images/login_icon.png" alt="Login">
            </a>
            <a href="#logout" title="Logout">
                <img src="assets/images/logout_icon.png" alt="Logout">
            </a>
            <a href="cart.php" title="Shopping Cart">
                <img src="assets/images/shoppingcard_icon.png" alt="Cart">
                <!-- The cart counter is placed over the icon using absolute positioning in CSS. -->
                <span class="cart-badge" aria-live="polite"><?= $cartCount > 0 ? (int) $cartCount : ''; ?></span>
            </a>
            <a href="#search" title="Search">
                <img src="assets/images/search_icon.png" alt="Search">
            </a>
        </div>
    </nav>

    <!--
        Main page container.
        It defines the fixed layout width, shared spacing and decorative background gradient.
    -->
    <main class="container">
        <!--
            Product cards.
            The divider elements switch at breakpoints: after 3 cards on desktop,
            after 2 cards on tablet widths, and after every card on mobile.
        -->
        <section class="products-grid" aria-label="Featured products">
            <!--
                Each product card follows the same template:
                image, title, short description, price and a link to more details.
            -->
            <?php foreach ($products as $index => $product): ?>
                <article class="product-card">
                    <div class="product-media">
                        <img src="<?= e($product['main_image']); ?>" alt="<?= e($product['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                    </div>
                    <h2 class="product-title"><?= e($product['name']); ?></h2>
                    <p class="product-description">
                        <?= e($product['short_description']); ?>
                    </p>
                    <div class="product-footer">
                        <span class="product-price"><?= productPrice($product['price']); ?></span>
                        <a class="product-more-info" href="#<?= e($product['slug']); ?>" data-product-id="<?= e($product['slug']); ?>">More Info</a>
                    </div>
                </article>

                <div class="<?= e(productDividerClasses($index + 1)); ?>" aria-hidden="true"></div>
            <?php endforeach; ?>
        </section>

        <!--
            Advertising carousel with partner logos.
            It contains a viewport with edge fading and a moving ad-track inside.
        -->
        <section class="advertising-block" aria-label="Advertising banners">
            <div class="ad-viewport">
                <div class="ad-track">
                    <!--
                        First set of logos.
                        All external links open in a new tab, and rel protects this page from
                        access by the newly opened browser tab.
                    -->
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
                    <!--
                        The second set fully duplicates the first one.
                        This is required for seamless CSS animation: the track moves by 50%,
                        and the repeated set continues the movement without a sudden jump.
                    -->
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

        <!-- Bottom part of the page with the author credit and year. -->
        <footer class="site-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>

    <div class="product-modal" id="product-modal" aria-hidden="true">
        <div class="product-modal__backdrop" data-modal-close></div>
        <section class="product-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-product-title">
            <button class="product-modal__close" type="button" aria-label="Close product details" data-modal-close>x</button>
            <div class="product-modal__content">
                <div class="product-modal__visual">
                    <div class="product-modal__image-stage">
                        <img id="modal-product-image" class="product-modal__image" src="" alt="">
                        <div id="modal-product-blank" class="product-modal__blank" hidden></div>
                    </div>
                    <div id="modal-product-dots" class="product-modal__dots" aria-label="Product images"></div>
                </div>

                <div class="product-modal__copy">
                    <h2 id="modal-product-title" class="visually-hidden"></h2>
                    <div id="modal-product-text" class="product-modal__text"></div>
                    <div class="product-modal__scrollbar" aria-hidden="true">
                        <div class="product-modal__scrollbar-thumb"></div>
                    </div>
                </div>
            </div>

            <div class="product-modal__actions">
                <div class="product-modal__actions-inner">
                    <span id="modal-product-price" class="product-modal__price"></span>
                    <button class="product-modal__cart" type="button">Add to Cart</button>
                </div>
                <span id="modal-cart-message" class="product-modal__message" role="status" aria-live="polite"></span>
            </div>
        </section>
    </div>

    <!-- Product and cart data are exposed before the modal controller initializes. -->
    <script>
        window.fixerupperCartEndpoint = "<?= e($cartEndpoint); ?>";
        window.fixerupperProducts = <?= json_encode($modalProducts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script src="assets/js/product-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/product-modal.js'); ?>"></script>
</body>
</html>
