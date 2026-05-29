<?php
// Resume the visitor session so this endpoint can read and change the cart array.
require_once __DIR__ . '/session.php';

// Load the database connection used to verify product ids and prices.
require_once __DIR__ . '/config.php';

// Shared cart-count and price-format helpers keep JSON responses consistent.
require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';

ensureInventoryTable($conn);

// Every response from this endpoint is JSON because it is called by cart.js.
header('Content-Type: application/json; charset=utf-8');

// Reject direct browser visits and keep cart mutations limited to POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'only post requests are allowed.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// All cart rows post a numeric database product id.
$productId = (int) ($_POST['product_id'] ?? 0);

// The action decides whether the endpoint updates quantity or removes a row.
$action = (string) ($_POST['action'] ?? 'update');

// Quantity is required for updates and intentionally ignored for remove actions.
$quantity = (int) ($_POST['quantity'] ?? 0);

// Remove actions do not need a positive quantity, but quantity updates do.
if ($productId < 1 || ($action !== 'remove' && $quantity < 1)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'invalid cart item.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Removing only touches the session cart; the products table remains unchanged.
if ($action === 'remove') {
    unset($_SESSION['cart'][$productId]);

    echo json_encode([
        'success' => true,
        'removed' => true,
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Keep the quantity within a reasonable display range before recalculating totals.
$quantity = min($quantity, 99);

// Fetch the active product price and stock so totals and availability stay server-side.
$stmt = $conn->prepare(
    'SELECT
        p.id,
        p.price,
        COALESCE(i.stock_quantity, 0) AS stock_quantity
     FROM products p
     LEFT JOIN product_inventory i ON i.product_id = p.id
     WHERE p.is_active = 1 AND p.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

// A missing product means the cart row is stale or the product was disabled.
if (!$product) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'product not found.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Do not create new cart rows here; adding products is handled by add_to_cart.php.
if (!isset($_SESSION['cart'][$productId]) || (int) $_SESSION['cart'][$productId] < 1) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'cart item not found.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

$availableStock = (int) $product['stock_quantity'];

if ($quantity > $availableStock) {
    $currentQuantity = (int) $_SESSION['cart'][$productId];
    $lineTotal = (float) $product['price'] * $currentQuantity;

    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $availableStock > 0 ? 'only ' . $availableStock . ' available.' : 'this product is out of stock.',
        'quantity' => $currentQuantity,
        'cart_count' => cartCount(),
        'stock_quantity' => $availableStock,
        'formatted_line_total' => cartPrice($lineTotal),
    ]);
    exit;
}

// Persist the new quantity in the session and return the updated line total.
$_SESSION['cart'][$productId] = $quantity;

$unitPrice = (float) $product['price'];
$lineTotal = $unitPrice * $quantity;

// Send back only the pieces of state the existing cart row needs to refresh.
echo json_encode([
    'success' => true,
    'quantity' => $quantity,
    'cart_count' => cartCount(),
    'stock_quantity' => $availableStock,
    'formatted_line_total' => cartPrice($lineTotal),
]);
