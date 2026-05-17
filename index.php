<!DOCTYPE html>
<html lang="en">
<head>
    <!--
        Basic page settings:
        - UTF-8 ensures that text and special characters are displayed correctly.
        - viewport keeps page scaling predictable across different screens.
        - Google Fonts loads Montserrat and Teko, which are used throughout the interface.
        - style.css contains all visual styling: grids, cards, navigation and advertising animation.
    -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIXERUPPER</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500&family=Teko:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!--
        Top navigation bar.
        It is divided into three logical areas:
        1. The FIXERUPPER brand logo.
        2. The main menu with anchor links.
        3. Action icons for login, logout, cart and search.
    -->
    <nav>
        <!-- The logo is placed in its own block so it can be aligned independently from the menu. -->
        <div class="nav-logo">
            <img src="assets/images/logo.png" alt="FIXERUPPER Logo">
        </div>

        <!-- The main menu uses anchor links; currently they point to future page sections. -->
        <div class="nav-menu">
            <a href="#home">HOME</a>
            <a href="#about">ABOUT US</a>
            <a href="#contacts">CONTACTS</a>
        </div>

        <!--
            Action icons are links, so real login, cart and search pages can be connected later.
            The title attribute provides a hover hint, while alt text improves accessibility and
            helps when an image fails to load.
        -->
        <div class="nav-actions">
            <a href="#login" title="Login">
                <img src="assets/images/login_icon.png" alt="Login">
            </a>
            <a href="#logout" title="Logout">
                <img src="assets/images/logout_icon.png" alt="Logout">
            </a>
            <a href="#cart" title="Shopping Cart">
                <img src="assets/images/shoppingcard_icon.png" alt="Cart">
                <!-- The cart counter is placed over the icon using absolute positioning in CSS. -->
                <span class="cart-badge">69</span>
            </a>
            <a href="#search" title="Search">
                <img src="assets/images/search_icon.png" alt="Search">
            </a>
        </div>
    </nav>

    <!--
        Main page container.
        It defines the fixed layout width, shared spacing and decorative background gradient.
    -->
    <main class="container">
        <!--
            Product cards.
            The divider elements switch at breakpoints: after 3 cards on desktop,
            after 2 cards on tablet widths, and after every card on mobile.
        -->
        <section class="products-grid" aria-label="Featured products">
            <!--
                Each product card follows the same template:
                image, title, short description, price and a link to more details.
            -->
            <article class="product-card">
                <div class="product-media">
                    <img src="assets/images/pc_1.png" alt="Ultra Threadripper Pro Gaming PC">
                </div>
                <h2 class="product-title">Ultra Threadripper Pro Gaming PC</h2>
                <p class="product-description">
                    Windows 11 Pro Workstations (64-bit Edition) AMD Ryzen&trade; Threadripper&trade; PRO 9985WX NVIDIA&reg; RTX&trade; PRO 5000 Blackwell 48GB 128GB DDR5 ...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 3100</span>
                    <a class="product-more-info" href="#product-1" data-product-id="product-1">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--single-column" aria-hidden="true"></div>

            <article class="product-card">
                <div class="product-media">
                    <!-- onerror loads a fallback image if the specific product image cannot be found. -->
                    <img src="assets/images/pc_2.png" alt="Intel Ultra 9 Z890 PC Builder" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                </div>
                <h2 class="product-title">Intel Ultra 9 Z890 PC Builder</h2>
                <p class="product-description">
                    Intel&reg; Core&trade; Ultra 9 285K: 24 Cores [8P Up to 5.70GHz / 16E Up to 4.60GHz], 125W TDP, 40MB Cache, Intel Graphics No Overclocking...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 1900</span>
                    <a class="product-more-info" href="#product-2" data-product-id="product-2">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--compact-row" aria-hidden="true"></div>

            <article class="product-card">
                <div class="product-media">
                    <!-- The same fallback keeps the card from appearing visually empty. -->
                    <img src="assets/images/pc_3.png" alt="AMD 7000-Series Ryzen 9 Custom" onerror="this.onerror=null; this.src='assets/images/pc_1.png';">
                </div>
                <h2 class="product-title">AMD 7000-Series Ryzen 9 Custom</h2>
                <p class="product-description">
                    AMD Ryzen&trade; 9 7900X: 12 Cores, 170W TDP, 4.70GHz, 5.60GHz Turbo, 64MB L3 Cache, Pro OC Compatible, Radeon Graphics...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 2100</span>
                    <a class="product-more-info" href="#product-3" data-product-id="product-3">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--desktop-row products-dots--single-column" aria-hidden="true"></div>

            <article class="product-card">
                <div class="product-media">
                    <img src="assets/images/pc_4.png" alt="Ryzen 7 RTX Gaming PC">
                </div>
                <h2 class="product-title">Ryzen 7 RTX Gaming PC</h2>
                <p class="product-description">
                    AMD Ryzen&trade; 7 7800X3D: 8 Cores, NVIDIA&reg; GeForce RTX&trade; 4070 SUPER, 32GB DDR5, 2TB NVMe SSD, Wi-Fi Ready...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 1600</span>
                    <a class="product-more-info" href="#product-4" data-product-id="product-4">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--compact-row" aria-hidden="true"></div>

            <article class="product-card">
                <div class="product-media">
                    <img src="assets/images/pc_5.png" alt="Intel Core i7 Gaming PC">
                </div>
                <h2 class="product-title">Intel Core i7 Gaming PC</h2>
                <p class="product-description">
                    Intel&reg; Core&trade; i7: 20 Cores, NVIDIA&reg; GeForce RTX&trade; 4070 Ti SUPER, 32GB DDR5, 2TB NVMe SSD, RGB Cooling...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 1750</span>
                    <a class="product-more-info" href="#product-5" data-product-id="product-5">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--single-column" aria-hidden="true"></div>

            <article class="product-card">
                <div class="product-media">
                    <img src="assets/images/pc_6.png" alt="Ryzen 9 Workstation PC">
                </div>
                <h2 class="product-title">Ryzen 9 Workstation PC</h2>
                <p class="product-description">
                    AMD Ryzen&trade; 9 9950X: 16 Cores, NVIDIA&reg; GeForce RTX&trade; 4080 SUPER, 64GB DDR5, 4TB NVMe SSD, Liquid Cooling...
                </p>
                <div class="product-footer">
                    <span class="product-price">&pound; 2400</span>
                    <a class="product-more-info" href="#product-6" data-product-id="product-6">More Info</a>
                </div>
            </article>

            <div class="products-dots products-dots--inline products-dots--desktop-row products-dots--compact-row" aria-hidden="true"></div>
        </section>

        <!--
            Advertising carousel with partner logos.
            It contains a viewport with edge fading and a moving ad-track inside.
        -->
        <section class="advertising-block" aria-label="Advertising banners">
            <div class="ad-viewport">
                <div class="ad-track">
                    <!--
                        First set of logos.
                        All external links open in a new tab, and rel protects this page from
                        access by the newly opened browser tab.
                    -->
                    <div class="ad-set">
                        <div class="ad-banner">
                            <a href="https://www.amd.com/" target="_blank" rel="noopener noreferrer" aria-label="AMD official website">
                                <img class="ad-logo-amd" src="assets/images/amd_logo.png" alt="AMD">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.apple.com/" target="_blank" rel="noopener noreferrer" aria-label="Apple official website">
                                <img class="ad-logo-compact ad-logo-apple" src="assets/images/apple_logo.png" alt="Apple">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.nvidia.com/" target="_blank" rel="noopener noreferrer" aria-label="NVIDIA official website">
                                <img src="assets/images/nvidia_logo.png" alt="NVIDIA">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://store.steampowered.com/" target="_blank" rel="noopener noreferrer" aria-label="Steam official website">
                                <img class="ad-logo-compact" src="assets/images/steam_logo.png" alt="Steam">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.intel.com/" target="_blank" rel="noopener noreferrer" aria-label="Intel official website">
                                <img src="assets/images/intel_logo.png" alt="Intel">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.ea.com/ea-app" target="_blank" rel="noopener noreferrer" aria-label="Origin EA app official website">
                                <img src="assets/images/origin_logo.png" alt="Origin">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.xbox.com/" target="_blank" rel="noopener noreferrer" aria-label="Xbox official website">
                                <img class="ad-logo-compact" src="assets/images/xbox_logo.png" alt="Xbox">
                            </a>
                        </div>
                    </div>
                    <!--
                        The second set fully duplicates the first one.
                        This is required for seamless CSS animation: the track moves by 50%,
                        and the repeated set continues the movement without a sudden jump.
                    -->
                    <div class="ad-set">
                        <div class="ad-banner">
                            <a href="https://www.amd.com/" target="_blank" rel="noopener noreferrer" aria-label="AMD official website">
                                <img class="ad-logo-amd" src="assets/images/amd_logo.png" alt="AMD">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.apple.com/" target="_blank" rel="noopener noreferrer" aria-label="Apple official website">
                                <img class="ad-logo-compact ad-logo-apple" src="assets/images/apple_logo.png" alt="Apple">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.nvidia.com/" target="_blank" rel="noopener noreferrer" aria-label="NVIDIA official website">
                                <img src="assets/images/nvidia_logo.png" alt="NVIDIA">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://store.steampowered.com/" target="_blank" rel="noopener noreferrer" aria-label="Steam official website">
                                <img class="ad-logo-compact" src="assets/images/steam_logo.png" alt="Steam">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.intel.com/" target="_blank" rel="noopener noreferrer" aria-label="Intel official website">
                                <img src="assets/images/intel_logo.png" alt="Intel">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.ea.com/ea-app" target="_blank" rel="noopener noreferrer" aria-label="Origin EA app official website">
                                <img src="assets/images/origin_logo.png" alt="Origin">
                            </a>
                        </div>
                        <div class="ad-banner">
                            <a href="https://www.xbox.com/" target="_blank" rel="noopener noreferrer" aria-label="Xbox official website">
                                <img class="ad-logo-compact" src="assets/images/xbox_logo.png" alt="Xbox">
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bottom part of the page with the author credit and year. -->
        <footer class="site-footer">
            <p>Jurijs Petkevics &copy; 2026</p>
        </footer>
    </main>

    <div class="product-modal" id="product-modal" aria-hidden="true">
        <div class="product-modal__backdrop" data-modal-close></div>
        <section class="product-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-product-title">
            <button class="product-modal__close" type="button" aria-label="Close product details" data-modal-close>x</button>
            <div class="product-modal__content">
                <div class="product-modal__visual">
                    <div class="product-modal__image-stage">
                        <img id="modal-product-image" class="product-modal__image" src="" alt="">
                        <div id="modal-product-blank" class="product-modal__blank" hidden></div>
                    </div>
                    <div id="modal-product-dots" class="product-modal__dots" aria-label="Product images"></div>
                </div>

                <div class="product-modal__copy">
                    <h2 id="modal-product-title" class="visually-hidden"></h2>
                    <div id="modal-product-text" class="product-modal__text"></div>
                    <div class="product-modal__scrollbar" aria-hidden="true">
                        <div class="product-modal__scrollbar-thumb"></div>
                    </div>
                </div>
            </div>

            <div class="product-modal__actions">
                <div class="product-modal__actions-inner">
                    <span id="modal-product-price" class="product-modal__price"></span>
                    <button class="product-modal__cart" type="button">Add to Cart</button>
                </div>
            </div>
        </section>
    </div>

    <script src="assets/js/product-modal.js"></script>
</body>
</html>
