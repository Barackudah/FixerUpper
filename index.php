<?php
// Start the session before rendering so the cart badge can reflect the current cart.
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';

ensureInventoryTable($conn);

// Decide which decorative divider variants are visible at each responsive breakpoint.
function productDividerClasses($position, $totalProducts)
{
    $classes = ['products-dots', 'products-dots--inline'];
    $isLastProduct = $position === $totalProducts;

    if ($position % 3 === 0 || $isLastProduct) {
        $classes[] = 'products-dots--desktop-row';
    }

    if ($position % 2 === 0 || $isLastProduct) {
        $classes[] = 'products-dots--compact-row';
    }

    if ($position % 2 !== 0 || $isLastProduct) {
        $classes[] = 'products-dots--single-column';
    }

    return implode(' ', $classes);
}

$productsById = [];

// Load the main product records first; related images and specs are attached below.
$productStmt = executePreparedStatement(
    $conn,
    "SELECT
        p.id,
        p.slug,
        p.name,
        p.short_description,
        p.price,
        p.main_image,
        COALESCE(i.stock_quantity, 0) AS stock_quantity
     FROM products p
     LEFT JOIN product_inventory i ON i.product_id = p.id
     WHERE p.is_active = 1
     ORDER BY p.id"
);
$productResult = $productStmt->get_result();

while ($product = $productResult->fetch_assoc()) {
    $product['specs'] = [];
    $product['images'] = [];
    $productsById[(int) $product['id']] = $product;
}

$productStmt->close();
$productIds = array_keys($productsById);

// Fetch related product media and specification rows in two batch queries.
if ($productIds) {
    $productIds = array_map('intval', $productIds);
    $idPlaceholders = preparedPlaceholders($productIds);

    $imageStmt = executePreparedStatement(
        $conn,
        "SELECT product_id, image_path, alt_text
         FROM product_images
         WHERE product_id IN ($idPlaceholders)
         ORDER BY product_id, sort_order, id",
        str_repeat('i', count($productIds)),
        $productIds
    );
    $imageResult = $imageStmt->get_result();

    while ($image = $imageResult->fetch_assoc()) {
        $productId = (int) $image['product_id'];
        $productsById[$productId]['images'][] = $image;
    }

    $imageStmt->close();

    $specStmt = executePreparedStatement(
        $conn,
        "SELECT product_id, label, value
         FROM product_specs
         WHERE product_id IN ($idPlaceholders)
         ORDER BY product_id, sort_order, id",
        str_repeat('i', count($productIds)),
        $productIds
    );
    $specResult = $specStmt->get_result();

    while ($spec = $specResult->fetch_assoc()) {
        $productId = (int) $spec['product_id'];
        $productsById[$productId]['specs'][] = $spec;
    }

    $specStmt->close();
}

// Re-index products for rendering while keeping a slug-keyed map for the modal script.
$products = array_values($productsById);
$productCount = count($products);
$modalProducts = [];
$sessionCart =& currentCart();

foreach ($sessionCart as $productId => $quantity) {
    $productId = (int) $productId;

    if ($productId < 1 || (int) $quantity < 1 || !isset($productsById[$productId])) {
        unset($sessionCart[$productId]);
    }
}

// The navigation badge shows unique products and stays empty when the cart has no items.
$cartCount = cartBadgeCount($sessionCart);
$cartProductIds = [];
$checkoutCurrentUser = $_SESSION['checkout_user'] ?? null;
$checkoutIsAdmin = checkoutUserIsAdmin($checkoutCurrentUser);
$inventoryModalProducts = [];
$inventoryModalProductsJson = '[]';
$inventoryFormAction = 'inventory.php';
$inventoryReturnUrl = 'index.php';
$modalEditProductId = 0;

if ($checkoutIsAdmin) {
    $inventoryModalProducts = inventoryModalProducts($conn);
    $inventoryModalProductsJson = json_encode(
        $inventoryModalProducts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?: '[]';
}

foreach ($sessionCart as $productId => $quantity) {
    if ((int) $quantity > 0) {
        $cartProductIds[(int) $productId] = true;
    }
}

// Build a site-relative endpoint so AJAX still works from a subdirectory install.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$cartEndpoint = ($basePath === '' ? '' : $basePath) . '/add_to_cart.php';

foreach ($products as $product) {
    $images = [];
    $productId = (int) $product['id'];

    // Prefer gallery images when they exist, otherwise fall back to the main product image.
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
    $stockQuantity = (int) $product['stock_quantity'];

    // Convert database spec rows into the compact [label, value] shape used by JS.
    foreach ($product['specs'] as $spec) {
        $details[] = [$spec['label'], $spec['value']];
    }

    // Expose only the fields the modal needs instead of sending raw database rows.
    $modalProducts[$product['slug']] = [
        'id' => $productId,
        'slug' => $product['slug'],
        'title' => $product['name'],
        'price' => productPriceText($product['price']),
        'image' => $product['main_image'],
        'images' => $images,
        'details' => $details,
        'stockQuantity' => $stockQuantity,
        'inCart' => isset($cartProductIds[$productId]),
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
            <?php if ($checkoutCurrentUser): ?>
                <p class="nav-user-status">hi, <?= e($checkoutCurrentUser['username']); ?></p>
            <?php endif; ?>
            <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
        </div>

        <!-- The main menu uses anchor links; currently they point to future page sections. -->
        <div class="nav-menu">
            <a href="#home">HOME</a>
            <a href="cart.php?under_construction=1">ABOUT US</a>
            <a href="cart.php?under_construction=1">CONTACTS</a>
            <?php if ($checkoutIsAdmin): ?>
                <a href="#manage" data-inventory-add-open>MANAGE</a>
            <?php endif; ?>
        </div>

        <!--
            Action icons are links, so real login, cart and search pages can be connected later.
            The title attribute provides a hover hint, while alt text improves accessibility and
            helps when an image fails to load.
        -->
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
                    <?php
                        $stockQuantity = (int) $product['stock_quantity'];
                        $stockStatus = inventoryStatus($stockQuantity);
                    ?>
                    <div class="product-stock product-stock--<?= e($stockStatus); ?>">
                        <?= e(inventoryStockText($stockQuantity)); ?>
                    </div>
                    <div class="product-footer">
                        <span class="product-price"><?= productPrice($product['price']); ?></span>
                        <a class="product-more-info" href="#<?= e($product['slug']); ?>" data-product-id="<?= e($product['slug']); ?>" data-label="More Info">More Info</a>
                    </div>
                </article>

                <div class="<?= e(productDividerClasses($index + 1, $productCount)); ?>" aria-hidden="true"></div>
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

    <?php if ($checkoutIsAdmin): ?>
        <?php include __DIR__ . '/partials/inventory_add_modal.php'; ?>
    <?php endif; ?>

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
    <?php if ($checkoutIsAdmin): ?>
        <script>
            window.fixerUpperInventoryProducts = <?= $inventoryModalProductsJson; ?>;
            window.fixerUpperInventoryEditProductId = <?= (int) $modalEditProductId; ?>;
        </script>
        <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js'); ?>"></script>
    <?php endif; ?>
    <script src="assets/js/product-modal.js?v=<?= filemtime(__DIR__ . '/assets/js/product-modal.js'); ?>"></script>
</body>
</html>
