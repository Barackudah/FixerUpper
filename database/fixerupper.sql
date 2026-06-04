-- Create the local project database when it does not exist yet.
CREATE DATABASE IF NOT EXISTS fixerupper
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- All following tables and seed data are written into the FixerUpper database.
USE fixerupper;

-- Users table is prepared for future login/checkout functionality.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the desktop admin account used by the local inventory application.
INSERT INTO users (username, email, password_hash, role) VALUES
    ('admin', 'quazarmovies@gmail.com', 'sha256$8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918', 'admin')
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    role = VALUES(role);

-- Products are the main storefront items shown on index.php and cart.php.
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    short_description TEXT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    main_image VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product inventory tracks saleable stock for each storefront product.
CREATE TABLE IF NOT EXISTS product_inventory (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product images support modal galleries and keep image ordering outside the PHP code.
CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    alt_text VARCHAR(150) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_images_order (product_id, sort_order),
    KEY idx_product_images_product (product_id),
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product specifications are rendered as labelled details inside the More Info modal.
CREATE TABLE IF NOT EXISTS product_specs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    value TEXT NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_specs_order (product_id, sort_order),
    KEY idx_product_specs_product (product_id),
    CONSTRAINT fk_product_specs_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders are not wired to checkout yet, but the structure is ready for future payment flow.
CREATE TABLE IF NOT EXISTS orders (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items preserve the product, quantity and unit price for each checkout line.
CREATE TABLE IF NOT EXISTS order_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the six active storefront products used by the current design and screenshots.
INSERT INTO products (slug, name, short_description, price, main_image, is_active) VALUES
    ('product-1', 'Ultra Threadripper Pro Gaming PC', 'Windows 11 Pro Workstations (64-bit Edition) AMD Ryzen Threadripper PRO 9985WX NVIDIA RTX PRO 5000 Blackwell 48GB 128GB DDR5 ...', 3100.00, 'assets/images/pc_1.png', 1),
    ('product-2', 'Intel Ultra 9 Z890 PC Builder', 'Intel Core Ultra 9 285K: 24 Cores [8P Up to 5.70GHz / 16E Up to 4.60GHz], 125W TDP, 40MB Cache, Intel Graphics No Overclocking...', 1900.00, 'assets/images/pc_2.png', 1),
    ('product-3', 'AMD 7000-Series Ryzen 9 Custom', 'AMD Ryzen 9 7900X: 12 Cores, 170W TDP, 4.70GHz, 5.60GHz Turbo, 64MB L3 Cache, Pro OC Compatible, Radeon Graphics...', 2100.00, 'assets/images/pc_3.png', 1),
    ('product-4', 'Ryzen 7 RTX Gaming PC', 'AMD Ryzen 7 7800X3D: 8 Cores, NVIDIA GeForce RTX 4070 SUPER, 32GB DDR5, 2TB NVMe SSD, Wi-Fi Ready...', 1600.00, 'assets/images/pc_4.png', 1),
    ('product-5', 'Intel Core i7 Gaming PC', 'Intel Core i7: 20 Cores, NVIDIA GeForce RTX 4070 Ti SUPER, 32GB DDR5, 2TB NVMe SSD, RGB Cooling...', 1750.00, 'assets/images/pc_5.png', 1),
    ('product-6', 'Ryzen 9 Workstation PC', 'AMD Ryzen 9 9950X: 16 Cores, NVIDIA GeForce RTX 4080 SUPER, 64GB DDR5, 4TB NVMe SSD, Liquid Cooling...', 2400.00, 'assets/images/pc_6.png', 1)
ON DUPLICATE KEY UPDATE
    -- Keep seed reruns idempotent by refreshing editable product fields.
    name = VALUES(name),
    short_description = VALUES(short_description),
    price = VALUES(price),
    main_image = VALUES(main_image),
    is_active = VALUES(is_active);

-- Seed starting inventory without overwriting later manual stock edits.
INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
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
WHERE slug IN ('product-1', 'product-2', 'product-3', 'product-4', 'product-5', 'product-6')
ON DUPLICATE KEY UPDATE
    product_id = VALUES(product_id);

-- Add one gallery image per product using the product's main image as the first slide.
INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
SELECT id, main_image, name, 1
FROM products
WHERE slug IN ('product-1', 'product-2', 'product-3', 'product-4', 'product-5', 'product-6')
ON DUPLICATE KEY UPDATE
    -- Re-running the seed updates image metadata without creating duplicate gallery rows.
    image_path = VALUES(image_path),
    alt_text = VALUES(alt_text);

-- Seed modal specifications for every active product.
INSERT INTO product_specs (product_id, label, value, sort_order)
SELECT id, 'Operating System', 'Windows 11 Pro Workstations (64-bit Edition)', 1 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Processor', 'AMD Ryzen Threadripper PRO 9985WX with workstation-class multi-core performance', 2 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Graphics', 'NVIDIA RTX PRO 5000 Blackwell 48GB for rendering, AI work and high-end gaming', 3 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Memory', '128GB DDR5 high-speed workstation memory', 4 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Storage', '4TB NVMe SSD with room for active projects and large game libraries', 5 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Cooling', 'Premium liquid cooling with tuned airflow for sustained workloads', 6 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Case', 'Black workstation chassis with tempered glass and RGB lighting', 7 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Motherboard', 'WRX90 workstation board with multi-GPU spacing and reinforced PCIe slots', 8 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Networking', '2.5Gb Ethernet, Wi-Fi 7 and Bluetooth for modern peripherals', 9 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Power Supply', '1200W 80+ Gold modular PSU with overhead for future upgrades', 10 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Front I/O', 'USB-C, high-speed USB-A ports and dedicated audio connections', 11 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Warranty', 'Three-year parts and labour coverage with priority workstation support', 12 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Build Notes', 'Cable-managed interior, tuned fan curves and burn-in testing before dispatch', 13 FROM products WHERE slug = 'product-1'
UNION ALL SELECT id, 'Operating System', 'Windows 11 Pro', 1 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Processor', 'Intel Core Ultra 9 285K: 24 cores, 125W TDP, 40MB cache', 2 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Graphics', 'Dedicated high-performance graphics ready for modern gaming', 3 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Memory', '32GB DDR5 memory', 4 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Storage', '2TB NVMe SSD', 5 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Motherboard', 'Z890 platform with Wi-Fi and expansion-ready connectivity', 6 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Cooling', 'Performance air cooling with quiet fan profile', 7 FROM products WHERE slug = 'product-2'
UNION ALL SELECT id, 'Operating System', 'Windows 11 Home', 1 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Processor', 'AMD Ryzen 9 7900X: 12 cores, 170W TDP, up to 5.60GHz turbo', 2 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Graphics', 'High-end Radeon or GeForce graphics configuration', 3 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Memory', '64GB DDR5 memory', 4 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Storage', '2TB NVMe SSD', 5 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Cooling', 'Liquid cooling with optimized airflow', 6 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Case', 'Compact black showcase case with filtered intake', 7 FROM products WHERE slug = 'product-3'
UNION ALL SELECT id, 'Operating System', 'Windows 11 Home', 1 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Processor', 'AMD Ryzen 7 7800X3D: 8 cores with 3D V-Cache', 2 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Graphics', 'NVIDIA GeForce RTX 4070 SUPER', 3 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Memory', '32GB DDR5 memory', 4 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Storage', '2TB NVMe SSD', 5 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Connectivity', 'Wi-Fi ready with modern rear I/O', 6 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Cooling', 'Quiet tower cooling for gaming loads', 7 FROM products WHERE slug = 'product-4'
UNION ALL SELECT id, 'Operating System', 'Windows 11 Home', 1 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Processor', 'Intel Core i7 performance CPU with hybrid core architecture', 2 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Graphics', 'NVIDIA GeForce RTX 4070 Ti SUPER', 3 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Memory', '32GB DDR5 memory', 4 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Storage', '2TB NVMe SSD', 5 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Lighting', 'RGB cooling and case accents', 6 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Cooling', 'Balanced airflow setup for long gaming sessions', 7 FROM products WHERE slug = 'product-5'
UNION ALL SELECT id, 'Operating System', 'Windows 11 Pro', 1 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Processor', 'AMD Ryzen 9 9950X: 16 cores for creation and multitasking', 2 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Graphics', 'NVIDIA GeForce RTX 4080 SUPER', 3 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Memory', '64GB DDR5 memory', 4 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Storage', '4TB NVMe SSD', 5 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Cooling', 'Liquid cooling with a quiet performance curve', 6 FROM products WHERE slug = 'product-6'
UNION ALL SELECT id, 'Case', 'Showcase chassis with panoramic side window', 7 FROM products WHERE slug = 'product-6'
ON DUPLICATE KEY UPDATE
    -- Specification rows are keyed by product and sort order, so reruns update copy in place.
    label = VALUES(label),
    value = VALUES(value);
