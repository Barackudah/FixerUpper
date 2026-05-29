# FixerUpper

Responsive gaming PC storefront page built with PHP, CSS and JavaScript.

![FixerUpper homepage](assets/images/homepage-screenshot.png)

![FixerUpper product details modal](assets/images/more-info-screenshot.png)

![FixerUpper shopping cart](assets/images/shopping-cart-screenshot.png)

## Features

- Responsive product grid for desktop, tablet and mobile layouts.
- Product details modal with image dots and scroll locking.
- Session-backed shopping cart with live item counts.
- Styled quantity controls with remove confirmation for zero quantity.
- Inventory page for stock, product location and supplier notes.
- Inventory save flow with edited-field highlighting and modal-style system messages.
- Cart quantity limits based on available inventory.
- Lowercase status messaging with matching fade-in and fade-out animations.
- Animated advertising logo strip.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Import `database/fixerupper.sql` into MySQL.
3. Open `http://localhost/fixerupper/index.php`.
4. Open `http://localhost/fixerupper/inventory.php` to manage product stock.

The inventory table is also created automatically on first page load if it is missing. It stores stock quantity, location and supplier values for each active product.

## Inventory notes

- Low stock is currently calculated with a fixed threshold: fewer than 3 items.
- `product_inventory` does not use a `reorder_level` column.
- Editing location or supplier text highlights the changed field until the next successful save.
