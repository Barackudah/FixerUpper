<?php
function ensureInventoryTable($conn)
{
    static $isReady = false;

    if ($isReady) {
        return;
    }

    $conn->query(
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

    $conn->query(
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

    $isReady = true;
}

function inventoryStatus($stockQuantity)
{
    $stockQuantity = (int) $stockQuantity;

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

    if ($status === 'low') {
        return 'low stock: ' . $stockQuantity . ' left';
    }

    if ($status === 'medium') {
        return 'medium stock: ' . $stockQuantity . ' left';
    }

    return $stockQuantity . ' in stock';
}
