<?php
// The cart is stored in the visitor's PHP session until checkout is implemented.
session_start();

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// Keep JSON responses consistent after validation errors and successful adds.
function cartCount()
{
    return array_sum($_SESSION['cart'] ?? []);
}

// This endpoint only changes cart state through AJAX POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

$productId = trim($_POST['product_id'] ?? '');
$quantity = (int) ($_POST['quantity'] ?? 1);

// Reject empty product ids and non-positive quantities before touching the database.
if ($productId === '' || $quantity < 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid cart item.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

// Accept both the numeric database id and the old product slug used by the modal links.
$numericProductId = ctype_digit($productId) ? (int) $productId : 0;
$stmt = $conn->prepare('SELECT id, slug FROM products WHERE is_active = 1 AND (id = ? OR slug = ?) LIMIT 1');
$stmt->bind_param('is', $numericProductId, $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Product not found.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Store cart quantities by real product id so checkout can join to products directly.
$cartProductId = (int) $product['id'];
$_SESSION['cart'][$cartProductId] = ($_SESSION['cart'][$cartProductId] ?? 0) + $quantity;

echo json_encode([
    'success' => true,
    'cart_count' => cartCount(),
]);
