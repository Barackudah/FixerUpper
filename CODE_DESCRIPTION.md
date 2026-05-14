# Detailed Code Description for FIXERUPPER

## Project Purpose

This project is a static storefront page for the FIXERUPPER gaming and workstation PC shop. Although the main file uses the `.php` extension, the current version contains regular HTML markup without any PHP logic. All visual styling is stored in `assets/css/style.css`, while product images, icons and partner logos are stored in `assets/images/`.

## File Structure

- `index.php` - the main HTML page of the website.
- `assets/css/style.css` - the stylesheet responsible for layout, colors, product cards, navigation, animations and the footer.
- `assets/images/` - the folder containing the site logo, icons, product images and advertising partner logos.

## Description of `index.php`

### The `<head>` Block

The `<head>` block defines the technical settings of the page:

- `charset="UTF-8"` ensures correct display of text and special characters.
- `viewport` helps the browser scale the page correctly on different screens.
- `<title>` sets the browser tab title.
- Google Fonts loads the Montserrat font.
- `assets/css/style.css` connects the external stylesheet with all visual rules.

### Navigation

The navigation bar is placed inside the `<nav>` element and consists of three parts:

- `.nav-logo` - the FIXERUPPER logo.
- `.nav-menu` - the main menu with `HOME`, `ABOUT US` and `CONTACTS` links.
- `.nav-actions` - icons for login, logout, cart and search.

The cart counter is placed inside `.cart-badge`. It is positioned over the cart icon with CSS absolute positioning.

### Main Container

`<main class="container">` groups all main page content. In CSS it has a fixed width, internal spacing and a decorative background gradient created with the `.container::before` pseudo-element.

### Product Cards

Each product card is built with `<article class="product-card">`. Inside each card there are:

- `.product-media` - the product image area.
- `.product-title` - the product name.
- `.product-description` - a short hardware description.
- `.product-footer` - the bottom row with the price and `More Info` link.

Cards are grouped into `.products-grid` sections. Each section displays three products in one row. Decorative `.products-dots` dividers are placed between the rows.

Some images use the `onerror` attribute. It loads a fallback image if the original product image fails to load.

### Advertising Strip

The `.advertising-block` section contains a scrolling strip of company logos. Its main elements are:

- `.ad-viewport` - the visible area of the advertising strip.
- `.ad-track` - the moving track with logos.
- `.ad-set` - one full set of logos.
- `.ad-banner` - the container for a single logo.

There are two identical `.ad-set` blocks in the HTML. This is required for seamless animation: when the first set moves left, the second set continues the motion without a visible gap.

External links open in a new tab with `target="_blank"`. The `rel="noopener noreferrer"` attribute is added for security.

### Footer

`<footer class="site-footer">` contains the author credit and year. In CSS, the footer text uses a muted gradient so it matches the dark theme of the page.

## Description of `style.css`

### Style Reset

At the top of the file, the universal selector `*` resets the default browser spacing and enables `box-sizing: border-box`. This makes element sizing more predictable because padding and borders are included in the declared width and height.

### CSS Variables

The `:root` block stores the main project dimensions:

- page width;
- card width and height;
- spacing between cards;
- dotted divider settings;
- logo sizes in the advertising strip.

This approach makes maintenance easier. For example, card width can be changed in one place instead of throughout the whole stylesheet.

### Navigation

The `nav` block uses CSS Grid with three columns. This keeps the logo on the left, the menu in the center and the action icons on the right.

The `nav::after` pseudo-element draws a thin decorative line below the navigation.

### Product Grid

`.products-grid` uses `display: grid` and `grid-template-columns: repeat(3, var(--card-width))`, so products are arranged in three equal columns.

`.product-card` uses `display: flex` and `flex-direction: column`. Because of this, the bottom price/link block can be pushed to the bottom of the card with `margin-top: auto`.

### Card Effects

Product cards include:

- a dark gradient background;
- a lower shadow;
- the `.product-card::after` darkening layer;
- a hover effect that lifts the card upward.

These techniques add depth and make the interface feel more interactive.

### Advertising Animation

The `@keyframes ad-scroll` animation moves `.ad-track` by `-50%`. Since the track contains two identical logo sets, the movement appears continuous.

When the user hovers over the advertising area, the animation is paused with `animation-play-state: paused`.

### Footer

The footer is placed below the advertising block. Its text has a gradient fill and a light shadow, so it remains visible without distracting from the main content.

## How to Add a New Product

To add a new product, copy this block:

```html
<article class="product-card">
    <div class="product-media">
        <img src="assets/images/pc_1.png" alt="Product name">
    </div>
    <h2 class="product-title">Product name</h2>
    <p class="product-description">
        Short product hardware description...
    </p>
    <div class="product-footer">
        <span class="product-price">&pound; 1000</span>
        <a class="product-more-info" href="#product-id">More Info</a>
    </div>
</article>
```

Then replace the image path, name, description, price and link.

## How to Add a New Logo to the Advertising Strip

Add the same block to both `.ad-set` sections so the animation remains seamless:

```html
<div class="ad-banner">
    <a href="https://example.com/" target="_blank" rel="noopener noreferrer" aria-label="Example official website">
        <img src="assets/images/example_logo.png" alt="Example">
    </a>
</div>
```

If a logo appears too large or too small, create a separate CSS class for it, following the existing examples: `.ad-logo-compact`, `.ad-logo-amd` or `.ad-logo-apple`.
