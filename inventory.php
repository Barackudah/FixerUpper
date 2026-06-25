<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/inventory_helpers.php';

$inventoryIsEmbed = (($_GET['embed'] ?? '') === '1') || (($_POST['inventory_embed'] ?? '') === '1');
$checkoutCurrentUser = $_SESSION['checkout_user'] ?? null;
$checkoutIsAdmin = checkoutUserIsAdmin($checkoutCurrentUser);

if (!$checkoutIsAdmin) {
    if (($_GET['inventory_action'] ?? '') === 'product_database_json') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'admin access required.']);
        exit;
    }

    header('Location: index.php');
    exit;
}

ensureInventoryTable($conn);

function inventorySafeReturnUrl($value)
{
    $value = trim(str_replace('\\', '/', (string) $value));

    if ($value === '' || str_contains($value, '://') || str_starts_with($value, '/') || str_starts_with($value, '//')) {
        return '';
    }

    return preg_match('/^(index|cart)\.php(?:\?.*)?$/', $value) ? $value : '';
}

function inventoryRedirectUrl($inventoryIsEmbed)
{
    $returnUrl = inventorySafeReturnUrl($_POST['inventory_return'] ?? '');

    if ($returnUrl !== '') {
        return $returnUrl;
    }

    return $inventoryIsEmbed ? 'inventory.php?embed=1' : 'inventory.php';
}

function inventoryCleanOneLine($value)
{
    return preg_replace('/\s+/', ' ', trim((string) $value));
}

function inventorySlugify($value)
{
    $value = function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value);
    $value = strtr($value, [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ]);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim(preg_replace('/-{2,}/', '-', $value), '-');

    return substr($value, 0, 50);
}

function inventoryDuplicateProductName($name)
{
    $source = inventoryCleanOneLine($name) ?: 'Product';
    $base = rtrim(substr($source, 0, 95)) ?: 'Product';

    return $base . ' Copy';
}

function inventoryUniqueDuplicateSlug($conn, $slug)
{
    $root = preg_replace('/-copy(?:-\d+)?$/', '', inventorySlugify($slug));
    $root = trim($root, '-') ?: 'product';
    $stmt = $conn->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');

    for ($copyNumber = 1; $copyNumber < 10000; $copyNumber++) {
        $suffix = $copyNumber === 1 ? '-copy' : '-copy-' . $copyNumber;
        $rootPart = rtrim(substr($root, 0, 50 - strlen($suffix)), '-') ?: 'product';
        $candidate = $rootPart . $suffix;
        $stmt->bind_param('s', $candidate);
        $stmt->execute();

        if (!$stmt->get_result()->fetch_assoc()) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique duplicate slug.');
}

function inventoryPrepareImagePath($slug, $fallbackPath)
{
    $fallbackPath = trim(str_replace('\\', '/', (string) $fallbackPath));

    if ($fallbackPath === '') {
        $fallbackPath = 'assets/images/pc_noimage.png';
    }

    $upload = $_FILES['add_product_image'] ?? null;

    if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return substr($fallbackPath, 0, 255);
    }

    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Choose a PNG, JPG, GIF or WEBP image.');
    }

    $assetsDir = __DIR__ . '/assets/images';

    if (!is_dir($assetsDir)) {
        mkdir($assetsDir, 0775, true);
    }

    $baseName = inventorySlugify($slug ?: pathinfo((string) $upload['name'], PATHINFO_FILENAME)) ?: 'product';
    $targetPath = $assetsDir . '/' . $baseName . '.' . $extension;
    $counter = 2;

    while (file_exists($targetPath)) {
        $targetPath = $assetsDir . '/' . $baseName . '-' . $counter . '.' . $extension;
        $counter++;
    }

    if (!move_uploaded_file((string) $upload['tmp_name'], $targetPath)) {
        throw new RuntimeException('Unable to save uploaded image.');
    }

    return 'assets/images/' . basename($targetPath);
}

function inventoryCollectProductPayload($conn, $currentProductId = null)
{
    $input = $_POST['add_product'] ?? [];
    $currentProductId = (int) ($currentProductId ?? 0);
    $name = inventoryCleanOneLine($input['name'] ?? '');
    $slug = inventorySlugify($input['slug'] ?? $name);
    $shortDescription = trim((string) ($input['short_description'] ?? ''));
    $priceText = preg_replace('/[^0-9.]/', '', (string) ($input['price'] ?? ''));
    $location = inventoryCleanOneLine($input['location'] ?? '') ?: 'Unassigned';
    $supplier = inventoryCleanOneLine($input['supplier'] ?? '');
    $stockQuantity = max(1, min(999, (int) ($input['stock_quantity'] ?? 1)));
    $isActive = isset($input['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new RuntimeException('Product name is required.');
    }

    if (strlen($name) > 100) {
        throw new RuntimeException('Product name must be 100 characters or fewer.');
    }

    if ($slug === '') {
        throw new RuntimeException('Slug is required.');
    }

    if ($shortDescription === '') {
        throw new RuntimeException('Short description is required.');
    }

    if ($priceText === '' || !is_numeric($priceText) || (float) $priceText <= 0) {
        throw new RuntimeException('Price must be greater than zero.');
    }

    if (strlen($location) > 80) {
        throw new RuntimeException('Location must be 80 characters or fewer.');
    }

    if (strlen($supplier) > 120) {
        throw new RuntimeException('Supplier must be 120 characters or fewer.');
    }

    if ($currentProductId > 0) {
        $slugStmt = $conn->prepare('SELECT id FROM products WHERE slug = ? AND id <> ? LIMIT 1');
        $slugStmt->bind_param('si', $slug, $currentProductId);
    } else {
        $slugStmt = $conn->prepare('SELECT id FROM products WHERE slug = ? LIMIT 1');
        $slugStmt->bind_param('s', $slug);
    }

    $slugStmt->execute();

    if ($slugStmt->get_result()->fetch_assoc()) {
        throw new RuntimeException("The slug '$slug' already exists.");
    }

    $imagePath = inventoryPrepareImagePath($slug, $input['main_image'] ?? 'assets/images/pc_noimage.png');

    if (strlen($imagePath) > 255) {
        throw new RuntimeException('Image path must be 255 characters or fewer.');
    }

    $specLabels = $input['spec_label'] ?? [];
    $specValues = $input['spec_value'] ?? [];
    $specs = [];
    $specCount = max(count($specLabels), count($specValues));

    for ($index = 0; $index < $specCount; $index++) {
        $label = inventoryCleanOneLine($specLabels[$index] ?? '');
        $value = trim((string) ($specValues[$index] ?? ''));

        if ($label === '' && $value === '') {
            continue;
        }

        if ($label === '' || $value === '') {
            throw new RuntimeException('Every spec row must have both a label and a value.');
        }

        if (strlen($label) > 80) {
            throw new RuntimeException('Spec labels must be 80 characters or fewer.');
        }

        $specs[] = [$label, $value];
    }

    return [
        'slug' => $slug,
        'name' => $name,
        'short_description' => $shortDescription,
        'price' => number_format((float) $priceText, 2, '.', ''),
        'main_image' => $imagePath,
        'is_active' => $isActive,
        'stock_quantity' => $stockQuantity,
        'location' => $location,
        'supplier' => $supplier,
        'specs' => $specs,
    ];
}

function inventoryCreateProduct($conn, $payload)
{
    $conn->begin_transaction();

    try {
        $productStmt = $conn->prepare(
            'INSERT INTO products (slug, name, short_description, price, main_image, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $productStmt->bind_param(
            'sssssi',
            $payload['slug'],
            $payload['name'],
            $payload['short_description'],
            $payload['price'],
            $payload['main_image'],
            $payload['is_active']
        );
        $productStmt->execute();
        $productId = $conn->insert_id;

        $inventoryStmt = $conn->prepare(
            'INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
             VALUES (?, ?, ?, ?)'
        );
        $inventoryStmt->bind_param('iiss', $productId, $payload['stock_quantity'], $payload['location'], $payload['supplier']);
        $inventoryStmt->execute();

        $sortOrder = 1;
        $imageStmt = $conn->prepare(
            'INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
             VALUES (?, ?, ?, ?)'
        );
        $imageStmt->bind_param('issi', $productId, $payload['main_image'], $payload['name'], $sortOrder);
        $imageStmt->execute();

        if ($payload['specs']) {
            $specStmt = $conn->prepare(
                'INSERT INTO product_specs (product_id, label, value, sort_order)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($payload['specs'] as $index => $spec) {
                $sortOrder = $index + 1;
                [$label, $value] = $spec;
                $specStmt->bind_param('issi', $productId, $label, $value, $sortOrder);
                $specStmt->execute();
            }
        }

        $conn->commit();

        return $productId;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function inventoryLoadProductDetails($conn, $productId)
{
    $productId = (int) $productId;

    if ($productId < 1) {
        throw new RuntimeException('Select a product first.');
    }

    $stmt = $conn->prepare(
        "SELECT
            p.id,
            p.slug,
            p.name,
            p.short_description,
            p.price,
            p.main_image,
            p.is_active,
            COALESCE(i.stock_quantity, 1) AS stock_quantity,
            COALESCE(NULLIF(i.location, ''), 'Unassigned') AS location,
            COALESCE(i.supplier, '') AS supplier
         FROM products p
         LEFT JOIN product_inventory i ON i.product_id = p.id
         WHERE p.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) {
        throw new RuntimeException('Product was not found.');
    }

    $specStmt = $conn->prepare(
        'SELECT label, value
         FROM product_specs
         WHERE product_id = ?
         ORDER BY sort_order, id'
    );
    $specStmt->bind_param('i', $productId);
    $specStmt->execute();
    $specResult = $specStmt->get_result();
    $specs = [];

    while ($spec = $specResult->fetch_assoc()) {
        $specs[] = [$spec['label'], $spec['value']];
    }

    return [
        'id' => (int) $product['id'],
        'slug' => $product['slug'],
        'name' => $product['name'],
        'short_description' => $product['short_description'],
        'price' => number_format((float) $product['price'], 2, '.', ''),
        'main_image' => $product['main_image'],
        'is_active' => (int) $product['is_active'],
        'stock_quantity' => max(1, min(999, (int) $product['stock_quantity'])),
        'location' => $product['location'],
        'supplier' => $product['supplier'],
        'specs' => $specs,
    ];
}

function inventoryUpdateProduct($conn, $productId, $payload)
{
    $productId = (int) $productId;

    if ($productId < 1) {
        throw new RuntimeException('Select a product to update.');
    }

    $conn->begin_transaction();

    try {
        $productStmt = $conn->prepare(
            'UPDATE products
             SET slug = ?, name = ?, short_description = ?, price = ?, main_image = ?, is_active = ?
             WHERE id = ?'
        );
        $productStmt->bind_param(
            'sssssii',
            $payload['slug'],
            $payload['name'],
            $payload['short_description'],
            $payload['price'],
            $payload['main_image'],
            $payload['is_active'],
            $productId
        );
        $productStmt->execute();

        if ($productStmt->affected_rows === 0) {
            $checkStmt = $conn->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
            $checkStmt->bind_param('i', $productId);
            $checkStmt->execute();

            if (!$checkStmt->get_result()->fetch_assoc()) {
                throw new RuntimeException('Product was not found.');
            }
        }

        $inventoryStmt = $conn->prepare(
            'INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                stock_quantity = VALUES(stock_quantity),
                location = VALUES(location),
                supplier = VALUES(supplier)'
        );
        $inventoryStmt->bind_param('iiss', $productId, $payload['stock_quantity'], $payload['location'], $payload['supplier']);
        $inventoryStmt->execute();

        $sortOrder = 1;
        $imageStmt = $conn->prepare(
            'INSERT INTO product_images (product_id, image_path, alt_text, sort_order)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                image_path = VALUES(image_path),
                alt_text = VALUES(alt_text)'
        );
        $imageStmt->bind_param('issi', $productId, $payload['main_image'], $payload['name'], $sortOrder);
        $imageStmt->execute();

        $deleteSpecsStmt = $conn->prepare('DELETE FROM product_specs WHERE product_id = ?');
        $deleteSpecsStmt->bind_param('i', $productId);
        $deleteSpecsStmt->execute();

        if ($payload['specs']) {
            $specStmt = $conn->prepare(
                'INSERT INTO product_specs (product_id, label, value, sort_order)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($payload['specs'] as $index => $spec) {
                $sortOrder = $index + 1;
                [$label, $value] = $spec;
                $specStmt->bind_param('issi', $productId, $label, $value, $sortOrder);
                $specStmt->execute();
            }
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function inventorySoftDeleteProduct($conn, $productId)
{
    $productId = (int) $productId;

    if ($productId < 1) {
        throw new RuntimeException('Select a product to delete.');
    }

    $conn->begin_transaction();

    try {
        $productStmt = $conn->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
        $productStmt->bind_param('i', $productId);
        $productStmt->execute();

        $inventoryStmt = $conn->prepare('UPDATE product_inventory SET stock_quantity = 0 WHERE product_id = ?');
        $inventoryStmt->bind_param('i', $productId);
        $inventoryStmt->execute();

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function inventoryDuplicateProduct($conn, $productId)
{
    $product = inventoryLoadProductDetails($conn, $productId);
    $product['name'] = inventoryDuplicateProductName($product['name']);
    $product['slug'] = inventoryUniqueDuplicateSlug($conn, $product['slug']);
    $product['is_active'] = 1;

    return inventoryCreateProduct($conn, $product);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $inventoryAction = $_POST['inventory_action'] ?? '';

    if ($inventoryAction === 'save_product' || $inventoryAction === 'add_product') {
        $editingProductId = max(0, (int) ($_POST['add_product']['id'] ?? 0));

        try {
            $payload = inventoryCollectProductPayload($conn, $editingProductId);

            if ($editingProductId > 0) {
                inventoryUpdateProduct($conn, $editingProductId, $payload);
                $_SESSION['inventory_notice'] = 'product #' . $editingProductId . ' updated.';
            } else {
                $productId = inventoryCreateProduct($conn, $payload);
                $_SESSION['inventory_notice'] = 'product #' . $productId . ' added.';
            }

            $_SESSION['inventory_notice_tone'] = 'success';
        } catch (Throwable $error) {
            $_SESSION['inventory_notice'] = 'product was not saved. ' . $error->getMessage();
            $_SESSION['inventory_notice_tone'] = 'error';
        }

        header('Location: ' . inventoryRedirectUrl($inventoryIsEmbed));
        exit;
    }

    if ($inventoryAction === 'duplicate_product') {
        $productId = (int) ($_POST['product_command_id'] ?? 0);

        try {
            $newProductId = inventoryDuplicateProduct($conn, $productId);
            $_SESSION['inventory_notice'] = 'product #' . $productId . ' duplicated as #' . $newProductId . '.';
            $_SESSION['inventory_notice_tone'] = 'success';
            $_SESSION['inventory_edit_product_id'] = $newProductId;
        } catch (Throwable $error) {
            $_SESSION['inventory_notice'] = 'product was not duplicated. ' . $error->getMessage();
            $_SESSION['inventory_notice_tone'] = 'error';
        }

        header('Location: ' . inventoryRedirectUrl($inventoryIsEmbed));
        exit;
    }

    if ($inventoryAction === 'delete_product') {
        $productId = (int) ($_POST['product_command_id'] ?? 0);

        try {
            inventorySoftDeleteProduct($conn, $productId);
            removeProductFromAllSessionCarts($productId);
            $_SESSION['inventory_notice'] = 'product #' . $productId . ' deleted.';
            $_SESSION['inventory_notice_tone'] = 'success';
        } catch (Throwable $error) {
            $_SESSION['inventory_notice'] = 'product was not deleted. ' . $error->getMessage();
            $_SESSION['inventory_notice_tone'] = 'error';
        }

        header('Location: ' . inventoryRedirectUrl($inventoryIsEmbed));
        exit;
    }

    $submittedItems = $_POST['inventory'] ?? [];
    $inventoryStmt = $conn->prepare(
        'INSERT INTO product_inventory (product_id, stock_quantity, location, supplier)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            stock_quantity = VALUES(stock_quantity),
            location = VALUES(location),
            supplier = VALUES(supplier)'
    );
    $removeStmt = $conn->prepare(
        'UPDATE products
         SET is_active = 0
         WHERE id = ?'
    );
    $removedCount = 0;

    foreach ($submittedItems as $productId => $item) {
        $productId = (int) $productId;

        if ($productId < 1) {
            continue;
        }

        $shouldRemove = (string) ($item['remove'] ?? '0') === '1';

        if ($shouldRemove) {
            $removeStmt->bind_param('i', $productId);
            $removeStmt->execute();
            removeProductFromAllSessionCarts($productId);
            $removedCount++;
            continue;
        }

        $stockQuantity = max(0, (int) ($item['stock_quantity'] ?? 0));
        $location = substr(trim((string) ($item['location'] ?? '')), 0, 80);
        $supplier = substr(trim((string) ($item['supplier'] ?? '')), 0, 120);

        if ($location === '') {
            $location = 'Unassigned';
        }

        $inventoryStmt->bind_param('iiss', $productId, $stockQuantity, $location, $supplier);
        $inventoryStmt->execute();
    }

    $_SESSION['inventory_notice'] = $removedCount > 0 ? 'inventory updated. item removed.' : 'inventory updated.';
    header('Location: ' . inventoryRedirectUrl($inventoryIsEmbed));
    exit;
}

$inventoryResult = $conn->query(
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

$inventoryItems = [];

while ($item = $inventoryResult->fetch_assoc()) {
    $stockQuantity = (int) $item['stock_quantity'];
    $item['stock_quantity'] = $stockQuantity;
    $item['price'] = number_format((float) $item['price'], 2, '.', '');
    $item['is_active'] = (int) $item['is_active'];

    $inventoryItems[] = $item;
}

$productSpecsById = [];
$inventoryItemIds = array_map(static fn($item) => (int) $item['id'], $inventoryItems);

if ($inventoryItemIds) {
    $idList = implode(',', array_map('intval', $inventoryItemIds));
    $specsResult = $conn->query(
        "SELECT product_id, label, value
         FROM product_specs
         WHERE product_id IN ($idList)
         ORDER BY product_id, sort_order, id"
    );

    while ($spec = $specsResult->fetch_assoc()) {
        $productId = (int) $spec['product_id'];
        $productSpecsById[$productId] ??= [];
        $productSpecsById[$productId][] = [
            'label' => $spec['label'],
            'value' => $spec['value'],
        ];
    }
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

$modalProductsJson = json_encode(
    $modalProducts,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?: '[]';

if (($_GET['inventory_action'] ?? '') === 'product_database_json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        [
            'count' => count($modalProducts),
            'products' => $modalProducts,
        ],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    exit;
}

$cartCount = cartCount();
$inventoryFormAction = inventoryRedirectUrl($inventoryIsEmbed);
$notice = $_SESSION['inventory_notice'] ?? '';
$noticeTone = $_SESSION['inventory_notice_tone'] ?? 'success';
$modalEditProductId = (int) ($_SESSION['inventory_edit_product_id'] ?? 0);
unset($_SESSION['inventory_notice']);
unset($_SESSION['inventory_notice_tone']);
unset($_SESSION['inventory_edit_product_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="inventory-body cart-body--filled<?= $inventoryIsEmbed ? ' inventory-body--embed' : ''; ?>">
    <?php if (!$inventoryIsEmbed): ?>
    <nav>
        <div class="nav-logo">
            <?php if ($checkoutCurrentUser): ?>
                <p class="nav-user-status">hi, <?= e($checkoutCurrentUser['username']); ?></p>
            <?php endif; ?>
            <a href="index.php" aria-label="FIXERUPPER home">
                <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
            </a>
        </div>

        <div class="nav-menu">
            <a href="index.php">HOME</a>
            <a href="cart.php?under_construction=1">ABOUT US</a>
            <a href="cart.php?under_construction=1">CONTACTS</a>
            <a href="#manage" data-inventory-add-open>MANAGE</a>
        </div>

        <div class="nav-actions">
            <?php if ($checkoutCurrentUser): ?>
                <a href="logout.php" title="Logout" aria-label="Logout">
                    <img src="assets/images/logout_icon.png" alt="Logout">
                </a>
            <?php else: ?>
                <a href="cart.php?checkout=1" title="Login" aria-label="Login">
                    <img src="assets/images/login_icon.png" alt="Login">
                </a>
            <?php endif; ?>
            <a href="cart.php" title="Shopping Cart">
                <img src="assets/images/shoppingcard_icon.png" alt="Cart">
                <span class="cart-badge" aria-live="polite"><?= $cartCount > 0 ? (int) $cartCount : ''; ?></span>
            </a>
            <a href="#search" title="Search">
                <img src="assets/images/search_icon.png" alt="Search">
            </a>
        </div>
    </nav>
    <?php endif; ?>

    <main class="container cart-container inventory-container">
        <section class="cart-page inventory-page" aria-label="Inventory">
            <?php if ($notice): ?>
                <p class="cart-message inventory-notice inventory-notice--<?= e($noticeTone); ?> is-visible" data-system-message role="status"><?= e($notice); ?></p>
            <?php endif; ?>

            <form id="inventory-form" class="inventory-form" method="post" action="<?= e($inventoryFormAction); ?>">
                <?php if ($inventoryIsEmbed): ?>
                    <input type="hidden" name="inventory_embed" value="1">
                <?php endif; ?>
                <div class="cart-table inventory-table" data-inventory-table>
                    <div class="cart-header inventory-header" aria-hidden="true">
                        <span class="cart-heading cart-heading--items inventory-heading--items">ITEMS</span>
                        <span class="cart-heading inventory-heading inventory-heading--stock">STOCK</span>
                        <span class="cart-heading inventory-heading inventory-heading--location">LOCATION</span>
                        <span class="cart-heading inventory-heading inventory-heading--supplier">SUPPLIER</span>
                    </div>

                    <?php foreach ($inventoryItems as $item): ?>
                        <article class="cart-row inventory-row">
                            <input type="hidden" name="inventory[<?= (int) $item['id']; ?>][remove]" value="0" data-inventory-remove-flag>

                            <a class="cart-item-media" href="index.php#<?= e($item['slug']); ?>" aria-label="<?= e($item['name']); ?>">
                                <img src="<?= e($item['main_image']); ?>" alt="<?= e($item['name']); ?>" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                            </a>

                            <a class="cart-item-title inventory-item-title" href="index.php#<?= e($item['slug']); ?>">
                                <span class="inventory-item-name"><?= e($item['name']); ?></span>
                                <span class="cart-item-stock"><?= e($item['slug']); ?></span>
                            </a>

                            <div class="cart-quantity-cell inventory-quantity-cell inventory-quantity-cell--stock">
                                <span class="visually-hidden">Stock</span>
                                <div class="cart-quantity-control inventory-quantity-control" aria-label="Stock">
                                    <button class="cart-quantity-button" type="button" data-inventory-step="-1" aria-label="Decrease stock">&minus;</button>
                                    <input class="cart-quantity-value inventory-quantity-value" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" name="inventory[<?= (int) $item['id']; ?>][stock_quantity]" value="<?= (int) $item['stock_quantity']; ?>" data-inventory-quantity aria-label="Stock">
                                    <button class="cart-quantity-button" type="button" data-inventory-step="1" aria-label="Increase stock">+</button>
                                </div>
                                <div class="cart-remove-confirmation" data-inventory-remove-confirmation hidden>
                                    <span class="cart-remove-label">REMOVE ITEM?</span>
                                    <span class="cart-remove-options">
                                        <button class="cart-remove-choice" type="button" data-inventory-remove-confirm="yes">yes</button>
                                        <span class="cart-remove-separator" aria-hidden="true">/</span>
                                        <button class="cart-remove-choice" type="button" data-inventory-remove-confirm="no">no</button>
                                    </span>
                                </div>
                            </div>

                            <label class="inventory-text-field inventory-text-field--location">
                                <span class="visually-hidden">Location</span>
                                <input type="text" maxlength="80" name="inventory[<?= (int) $item['id']; ?>][location]" value="<?= e($item['location']); ?>">
                            </label>

                            <label class="inventory-text-field inventory-text-field--supplier">
                                <span class="visually-hidden">Supplier</span>
                                <input type="text" maxlength="120" name="inventory[<?= (int) $item['id']; ?>][supplier]" value="<?= e($item['supplier']); ?>">
                            </label>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="inventory-actions">
                    <button class="inventory-action-button inventory-add" type="button" data-inventory-add-open>
                        <span class="inventory-action-icon inventory-add-icon" aria-hidden="true"></span>
                        <span>add item</span>
                    </button>
                    <button class="inventory-action-button inventory-save" type="submit">
                        <span class="inventory-action-icon inventory-save-icon" aria-hidden="true"></span>
                        <span>save inventory</span>
                    </button>
                </div>
            </form>
        </section>

        <?php if (!$inventoryIsEmbed): ?>
            <footer class="site-footer inventory-footer">
                <p>Jurijs Petkevics &copy; 2026</p>
            </footer>
        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/partials/inventory_add_modal.php'; ?>

    <script>
        window.fixerUpperInventoryProducts = <?= $modalProductsJson; ?>;
        window.fixerUpperInventoryEditProductId = <?= (int) $modalEditProductId; ?>;
    </script>
    <script src="assets/js/inventory.js?v=<?= filemtime(__DIR__ . '/assets/js/inventory.js'); ?>"></script>
</body>
</html>
