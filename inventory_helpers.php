<?php
function ensureInventoryTable($conn)
{
    static $isReady = false;

    if ($isReady) {
        return;
    }

    $stmt = $conn->prepare(
        "CREATE TABLE IF NOT EXISTS product_inventory (
            product_id INT UNSIGNED NOT NULL,
            stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            location VARCHAR(80) NOT NULL DEFAULT 'Unassigned',
            supplier VARCHAR(120) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id),
            KEY idx_product_inventory_stock (stock_quantity),
            CONSTRAINT fk_product_inventory_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
         SELECT
            id,
            CASE slug
                WHEN 'product-1' THEN 3
                WHEN 'product-2' THEN 7
                WHEN 'product-3' THEN 5
                WHEN 'product-4' THEN 2
                WHEN 'product-5' THEN 4
                WHEN 'product-6' THEN 1
                ELSE 0
            END,
            'Main workshop',
            'FixerUpper Build Team'
         FROM products
         WHERE is_active = 1
         ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)"
    );
    $stmt->execute();
    $stmt->close();

    $isReady = true;
}

function inventoryStatus($stockQuantity)
{
    $stockQuantity = (int) $stockQuantity;

    if ($stockQuantity <= 0) {
        return 'out';
    }

    if ($stockQuantity <= 3) {
        return 'low';
    }

    if ($stockQuantity <= 9) {
        return 'medium';
    }

    return 'in';
}

function inventoryStatusLabel($stockQuantity)
{
    $status = inventoryStatus($stockQuantity);

    if ($status === 'out') {
        return 'out of stock';
    }

    if ($status === 'low') {
        return 'low stock';
    }

    if ($status === 'medium') {
        return 'medium stock';
    }

    return 'in stock';
}

function inventoryStockText($stockQuantity)
{
    $stockQuantity = (int) $stockQuantity;
    $status = inventoryStatus($stockQuantity);

    if ($status === 'out') {
        return 'out of stock';
    }

    if ($status === 'low') {
        return 'low stock: ' . $stockQuantity . ' left';
    }

    if ($status === 'medium') {
        return 'medium stock: ' . $stockQuantity . ' left';
    }

    return $stockQuantity . ' in stock';
}

function inventoryModalProducts($conn)
{
    $inventoryStmt = executePreparedStatement(
        $conn,
        "SELECT
            p.id,
            p.slug,
            p.name,
            p.short_description,
            p.price,
            p.main_image,
            p.is_active,
            COALESCE(i.stock_quantity, 0) AS stock_quantity,
            COALESCE(NULLIF(i.location, ''), 'Unassigned') AS location,
            COALESCE(i.supplier, '') AS supplier,
            i.updated_at
         FROM products p
         LEFT JOIN product_inventory i ON i.product_id = p.id
         WHERE p.is_active = 1
         ORDER BY p.id"
    );
    $inventoryResult = $inventoryStmt->get_result();

    $inventoryItems = [];

    while ($item = $inventoryResult->fetch_assoc()) {
        $stockQuantity = (int) $item['stock_quantity'];
        $item['stock_quantity'] = $stockQuantity;
        $item['price'] = number_format((float) $item['price'], 2, '.', '');
        $item['is_active'] = (int) $item['is_active'];

        $inventoryItems[] = $item;
    }

    $inventoryStmt->close();
    $productSpecsById = [];
    $inventoryItemIds = array_map(static fn($item) => (int) $item['id'], $inventoryItems);

    if ($inventoryItemIds) {
        $inventoryItemIds = array_map('intval', $inventoryItemIds);
        $specsStmt = executePreparedStatement(
            $conn,
            "SELECT product_id, label, value
             FROM product_specs
             WHERE product_id IN (" . preparedPlaceholders($inventoryItemIds) . ")
             ORDER BY product_id, sort_order, id",
            str_repeat('i', count($inventoryItemIds)),
            $inventoryItemIds
        );
        $specsResult = $specsStmt->get_result();

        while ($spec = $specsResult->fetch_assoc()) {
            $productId = (int) $spec['product_id'];
            $productSpecsById[$productId] ??= [];
            $productSpecsById[$productId][] = [
                'label' => $spec['label'],
                'value' => $spec['value'],
            ];
        }

        $specsStmt->close();
    }

    $modalProducts = [];

    foreach ($inventoryItems as $item) {
        $productId = (int) $item['id'];
        $modalProducts[] = [
            'id' => $productId,
            'slug' => $item['slug'],
            'name' => $item['name'],
            'short_description' => $item['short_description'],
            'price' => $item['price'],
            'main_image' => $item['main_image'],
            'is_active' => (bool) $item['is_active'],
            'stock_quantity' => max(1, min(999, (int) $item['stock_quantity'])),
            'location' => $item['location'],
            'supplier' => $item['supplier'],
            'specs' => $productSpecsById[$productId] ?? [],
        ];
    }

    return $modalProducts;
}
