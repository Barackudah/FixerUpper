<?php
// Escape dynamic output before it is inserted into HTML attributes or text nodes.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function preparedPlaceholders($values)
{
    return implode(',', array_fill(0, count($values), '?'));
}

function executePreparedStatement($conn, $sql, $types = '', array $params = [])
{
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $refs = [];

        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        unset($value);
        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();

    return $stmt;
}

// Product cards use whole-pound prices, while the cart page keeps pennies.
function productPrice($price)
{
    return '&pound; ' . number_format((float) $price, 0, '.', '');
}

function productPriceText($price)
{
    return html_entity_decode(productPrice($price), ENT_QUOTES, 'UTF-8');
}

// Format cart totals with pennies because the cart shows line-item prices.
function cartPrice($price)
{
    return '&pound; ' . number_format((float) $price, 2, ',', ' ');
}

function cartPriceText($price)
{
    return html_entity_decode(cartPrice($price), ENT_QUOTES, 'UTF-8');
}

// Count unique products with positive quantities for the navigation badge.
function cartBadgeCount($cart)
{
    // The badge represents unique products, not the sum of all item quantities.
    $count = 0;

    foreach ($cart as $quantity) {
        // Ignore zero or invalid quantities so stale session data does not show a badge.
        if ((int) $quantity > 0) {
            $count++;
        }
    }

    return $count;
}

function checkoutUserIsAdmin($user = null)
{
    $user = $user ?? ($_SESSION['checkout_user'] ?? null);

    return is_array($user) && strtolower((string) ($user['role'] ?? '')) === 'admin';
}

function migrateLegacySessionCart()
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return;
    }

    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        $_SESSION['carts'] = [];
    }

    if (!isset($_SESSION['carts']['guest'])) {
        $_SESSION['carts']['guest'] = $_SESSION['cart'];
    }

    unset($_SESSION['cart']);
}

function currentCartKey()
{
    $userId = (int) ($_SESSION['checkout_user']['id'] ?? 0);

    return $userId > 0 ? 'user:' . $userId : 'guest';
}

function &currentCart()
{
    migrateLegacySessionCart();

    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        $_SESSION['carts'] = [];
    }

    $cartKey = currentCartKey();

    if (!isset($_SESSION['carts'][$cartKey]) || !is_array($_SESSION['carts'][$cartKey])) {
        $_SESSION['carts'][$cartKey] = [];
    }

    return $_SESSION['carts'][$cartKey];
}

function setCurrentCart($cart)
{
    $currentCart =& currentCart();
    $currentCart = is_array($cart) ? $cart : [];
}

function normalizeSessionCartItems($cart)
{
    $normalized = [];

    if (!is_array($cart)) {
        return $normalized;
    }

    foreach ($cart as $productId => $quantity) {
        $productId = (int) $productId;
        $quantity = min(99, (int) $quantity);

        if ($productId > 0 && $quantity > 0) {
            $normalized[$productId] = $quantity;
        }
    }

    return $normalized;
}

function clearGuestCart()
{
    migrateLegacySessionCart();

    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        $_SESSION['carts'] = [];
    }

    $_SESSION['carts']['guest'] = [];
}

function mergeGuestCartIntoUserCart($conn, $userId)
{
    migrateLegacySessionCart();

    $userId = (int) $userId;

    if ($userId < 1) {
        return;
    }

    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        $_SESSION['carts'] = [];
    }

    $guestCart = normalizeSessionCartItems($_SESSION['carts']['guest'] ?? []);
    $userCartKey = 'user:' . $userId;
    $userCart = normalizeSessionCartItems($_SESSION['carts'][$userCartKey] ?? []);

    if (!$guestCart) {
        $_SESSION['carts']['guest'] = [];
        $_SESSION['carts'][$userCartKey] = $userCart;
        return;
    }

    $productIds = array_unique(array_merge(array_keys($guestCart), array_keys($userCart)));
    $stockByProduct = [];

    if ($productIds) {
        $productIds = array_map('intval', $productIds);
        $productStmt = executePreparedStatement(
            $conn,
            "SELECT
                p.id,
                COALESCE(i.stock_quantity, 0) AS stock_quantity
             FROM products p
             LEFT JOIN product_inventory i ON i.product_id = p.id
             WHERE p.is_active = 1 AND p.id IN (" . preparedPlaceholders($productIds) . ")",
            str_repeat('i', count($productIds)),
            $productIds
        );
        $productResult = $productStmt->get_result();

        if (!$productResult) {
            $productStmt->close();
            $_SESSION['carts'][$userCartKey] = $userCart;
            return;
        }

        while ($product = $productResult->fetch_assoc()) {
            $stockByProduct[(int) $product['id']] = (int) $product['stock_quantity'];
        }

        $productStmt->close();
    }

    foreach ($guestCart as $productId => $quantity) {
        $availableStock = (int) ($stockByProduct[$productId] ?? 0);

        if ($availableStock < 1) {
            continue;
        }

        $existingQuantity = (int) ($userCart[$productId] ?? 0);
        $mergedQuantity = min(99, $availableStock, $existingQuantity + $quantity);

        if ($mergedQuantity > 0) {
            $userCart[$productId] = $mergedQuantity;
        }
    }

    foreach ($userCart as $productId => $quantity) {
        $availableStock = (int) ($stockByProduct[$productId] ?? 0);
        $quantity = min(99, $availableStock, (int) $quantity);

        if ($quantity < 1) {
            unset($userCart[$productId]);
            continue;
        }

        $userCart[$productId] = $quantity;
    }

    $_SESSION['carts'][$userCartKey] = $userCart;
    $_SESSION['carts']['guest'] = [];
}

function removeProductFromAllSessionCarts($productId)
{
    migrateLegacySessionCart();

    if (!isset($_SESSION['carts']) || !is_array($_SESSION['carts'])) {
        return;
    }

    $productId = (int) $productId;

    foreach ($_SESSION['carts'] as &$cart) {
        if (is_array($cart)) {
            unset($cart[$productId]);
        }
    }

    unset($cart);
}

// Use the current session cart by default for JSON endpoints.
function cartCount($cart = null)
{
    // Passing a cart explicitly keeps this helper testable, while the default supports endpoints.
    return cartBadgeCount($cart ?? currentCart());
}
