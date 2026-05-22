<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function cartCount()
{
    $count = 0;

    foreach ($_SESSION['cart'] ?? [] as $quantity) {
        if ((int) $quantity > 0) {
            $count++;
        }
    }

    return $count;
}

function cartPrice($price)
{
    return '&pound; ' . number_format((float) $price, 2, ',', ' ');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'update');
$quantity = (int) ($_POST['quantity'] ?? 0);

if ($productId < 1 || ($action !== 'remove' && $quantity < 1)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid cart item.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

if ($action === 'remove') {
    unset($_SESSION['cart'][$productId]);

    echo json_encode([
        'success' => true,
        'removed' => true,
        'cart_count' => cartCount(),
    ]);
    exit;
}

$quantity = min($quantity, 99);

$stmt = $conn->prepare('SELECT id, price FROM products WHERE is_active = 1 AND id = ? LIMIT 1');
$stmt->bind_param('i', $productId);
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

if (!isset($_SESSION['cart'][$productId]) || (int) $_SESSION['cart'][$productId] < 1) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Cart item not found.',
        'cart_count' => cartCount(),
    ]);
    exit;
}

$_SESSION['cart'][$productId] = $quantity;

$unitPrice = (float) $product['price'];
$lineTotal = $unitPrice * $quantity;

echo json_encode([
    'success' => true,
    'quantity' => $quantity,
    'cart_count' => cartCount(),
    'formatted_line_total' => cartPrice($lineTotal),
]);
