<?php

function ensureCheckoutOrderTables($conn)
{
    static $isReady = false;

    if ($isReady) {
        return;
    }

    $stmt = $conn->prepare(
        "CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            status ENUM('pending', 'paid', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
            total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_orders_user (user_id),
            KEY idx_orders_status (status),
            CONSTRAINT fk_orders_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "CREATE TABLE IF NOT EXISTS order_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(10, 2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_order_items_product (order_id, product_id),
            KEY idx_order_items_order (order_id),
            KEY idx_order_items_product (product_id),
            CONSTRAINT fk_order_items_order
                FOREIGN KEY (order_id) REFERENCES orders(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,
            CONSTRAINT fk_order_items_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt->execute();
    $stmt->close();

    $isReady = true;
}

function checkoutNormalizeCart($cart)
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

function checkoutLoadCartSummary($conn, $cart, $forUpdate = false)
{
    $quantities = checkoutNormalizeCart($cart);
    $productsById = [];
    $items = [];
    $total = 0.0;
    $quantityTotal = 0;
    $hasStockIssue = false;

    if ($quantities) {
        $productIds = array_map('intval', array_keys($quantities));
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = executePreparedStatement(
            $conn,
            "SELECT
                p.id,
                p.slug,
                p.name,
                p.price,
                p.main_image,
                COALESCE(i.stock_quantity, 0) AS stock_quantity
             FROM products p
             LEFT JOIN product_inventory i ON i.product_id = p.id
             WHERE p.is_active = 1 AND p.id IN (" . preparedPlaceholders($productIds) . ")" . $lockClause,
            str_repeat('i', count($productIds)),
            $productIds
        );
        $result = $stmt->get_result();

        while ($product = $result->fetch_assoc()) {
            $productsById[(int) $product['id']] = $product;
        }

        $stmt->close();
    }

    foreach ($quantities as $productId => $quantity) {
        if (!isset($productsById[$productId])) {
            unset($quantities[$productId]);
            continue;
        }

        $product = $productsById[$productId];
        $stockQuantity = (int) $product['stock_quantity'];
        $lineTotal = (float) $product['price'] * $quantity;
        $hasStockIssue = $hasStockIssue || $stockQuantity < $quantity;
        $total += $lineTotal;
        $quantityTotal += $quantity;

        $items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'stock_quantity' => $stockQuantity,
            'has_stock_issue' => $stockQuantity < $quantity,
        ];
    }

    return [
        'cart' => $quantities,
        'items' => $items,
        'total' => $total,
        'quantity_total' => $quantityTotal,
        'has_stock_issue' => $hasStockIssue,
    ];
}

function checkoutCreateOrderFromCart($conn, $userId, $cart)
{
    $userId = (int) $userId;

    if ($userId < 1) {
        throw new RuntimeException('login required before checkout.');
    }

    $conn->begin_transaction();

    try {
        $summary = checkoutLoadCartSummary($conn, $cart, true);

        if (!$summary['items']) {
            throw new RuntimeException('your cart is empty.');
        }

        foreach ($summary['items'] as $item) {
            if ($item['has_stock_issue']) {
                throw new RuntimeException('one or more items are no longer available in the requested quantity.');
            }
        }

        $status = 'completed';
        $total = (float) $summary['total'];
        $stmt = $conn->prepare('INSERT INTO orders (user_id, status, total_amount) VALUES (?, ?, ?)');
        $stmt->bind_param('isd', $userId, $status, $total);
        $stmt->execute();
        $orderId = (int) $stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
        );
        $stockStmt = $conn->prepare(
            'UPDATE product_inventory
             SET stock_quantity = stock_quantity - ?
             WHERE product_id = ? AND stock_quantity >= ?'
        );

        foreach ($summary['items'] as $item) {
            $productId = (int) $item['product']['id'];
            $quantity = (int) $item['quantity'];
            $unitPrice = (float) $item['product']['price'];
            $itemStmt->bind_param('iiid', $orderId, $productId, $quantity, $unitPrice);
            $itemStmt->execute();

            $stockStmt->bind_param('iii', $quantity, $productId, $quantity);
            $stockStmt->execute();

            if ($stockStmt->affected_rows !== 1) {
                throw new RuntimeException('stock changed before the order could be completed.');
            }
        }

        $itemStmt->close();
        $stockStmt->close();
        $conn->commit();

        return [
            'id' => $orderId,
            'total' => $total,
            'items' => $summary['items'],
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function checkoutOrderNumber($orderId)
{
    return '#' . str_pad((string) (int) $orderId, 6, '0', STR_PAD_LEFT);
}

function checkoutFindOrderForUser($conn, $orderId, $userId)
{
    $orderId = (int) $orderId;
    $userId = (int) $userId;

    if ($orderId < 1 || $userId < 1) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, user_id, status, total_amount, created_at
         FROM orders
         WHERE id = ? AND user_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return null;
    }

    $items = [];
    $stmt = $conn->prepare(
        'SELECT
            oi.product_id,
            oi.quantity,
            oi.unit_price,
            p.slug,
            p.name,
            p.main_image
         FROM order_items oi
         INNER JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.id'
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $item['line_total'] = (float) $item['unit_price'] * (int) $item['quantity'];
        $items[] = $item;
    }

    $stmt->close();
    $order['items'] = $items;

    return $order;
}
