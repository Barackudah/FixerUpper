<?php
// Escape dynamic output before it is inserted into HTML attributes or text nodes.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Product cards use whole-pound prices, while the cart page keeps pennies.
function productPrice($price)
{
    return '&pound; ' . number_format((float) $price, 0, '.', '');
}

// Format cart totals with pennies because the cart shows line-item prices.
function cartPrice($price)
{
    return '&pound; ' . number_format((float) $price, 2, ',', ' ');
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

// Use the current session cart by default for JSON endpoints.
function cartCount($cart = null)
{
    // Passing a cart explicitly keeps this helper testable, while the default supports endpoints.
    return cartBadgeCount($cart ?? ($_SESSION['cart'] ?? []));
}
