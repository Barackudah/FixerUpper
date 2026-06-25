<?php
// The cart is stored in the visitor's PHP session until checkout is implemented.
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

// Shared cart-count helper keeps JSON badge values consistent with the rendered pages.
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';

ensureInventoryTable($conn);

header('Content-Type: application/json; charset=utf-8');

// This endpoint only changes cart state through AJAX POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'only post requests are allowed.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Product ids arrive from the modal as either a database id or a legacy slug.
$productId = trim($_POST['product_id'] ?? '');

// The modal currently adds one item, but the endpoint accepts a quantity for future reuse.
$quantity = (int) ($_POST['quantity'] ?? 1);

// Reject empty product ids and non-positive quantities before touching the database.
if ($productId === '' || $quantity < 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'invalid cart item.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Accept both the numeric database id and the old product slug used by the modal links.
$numericProductId = ctype_digit($productId) ? (int) $productId : 0;
$stmt = $conn->prepare(
    'SELECT
        p.id,
        p.slug,
        COALESCE(i.stock_quantity, 0) AS stock_quantity
     FROM products p
     LEFT JOIN product_inventory i ON i.product_id = p.id
     WHERE p.is_active = 1 AND (p.id = ? OR p.slug = ?)
     LIMIT 1'
);
$stmt->bind_param('is', $numericProductId, $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

// Stop early if the posted id no longer matches an active product.
if (!$product) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'product not found.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

$cart =& currentCart();

// Store cart quantities by real product id so checkout can join to products directly.
$cartProductId = (int) $product['id'];
$availableStock = (int) $product['stock_quantity'];

if (isset($cart[$cartProductId]) && (int) $cart[$cartProductId] > 0) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'duplicate' => true,
        'message' => 'this product is already in the cart.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

if ($availableStock < $quantity) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $availableStock > 0 ? 'only ' . $availableStock . ' left in stock.' : 'this product is out of stock.',
        'cart_count' => cartCount(),
        'stock_quantity' => $availableStock,
    ]);
    exit;
}

// The session cart stores product ids as keys and quantities as values.
$cart[$cartProductId] = $quantity;

// Return the updated unique-product count so the navigation badge can refresh instantly.
echo json_encode([
    'success' => true,
    'message' => 'added to cart.',
    'cart_count' => cartCount(),
]);
