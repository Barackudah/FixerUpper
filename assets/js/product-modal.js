(function () {
    /*
     * Product modal controller.
     *
     * The whole script is wrapped in an immediately invoked function expression
     * so the variables below stay private and do not leak into the global window
     * scope. This file is responsible for:
     * - storing the product data shown in the modal;
     * - opening and closing the modal from "more info" links;
     * - rendering the product title, price, image, slider dots and details;
     * - locking page scroll while the modal is open;
     * - keeping the custom details scrollbar in sync.
     */

    /*
     * Product data used by the modal.
     *
     * Each key matches the data-product-id value on a product link in the HTML.
     * When a visitor clicks a link, openModal() uses that id to find the matching
     * product object here and then fills the modal with its title, price, image
     * and details.
     */
    const products = window.fixerupperProducts || {
        "product-1": {
            title: "Ultra Threadripper Pro Gaming PC",
            price: "&pound; 3100",
            image: "assets/images/pc_1.png",
            details: [
                ["Operating System", "Windows 11 Pro Workstations (64-bit Edition)"],
                ["Processor", "AMD Ryzen Threadripper PRO 9985WX with workstation-class multi-core performance"],
                ["Graphics", "NVIDIA RTX PRO 5000 Blackwell 48GB for rendering, AI work and high-end gaming"],
                ["Memory", "128GB DDR5 high-speed workstation memory"],
                ["Storage", "4TB NVMe SSD with room for active projects and large game libraries"],
                ["Cooling", "Premium liquid cooling with tuned airflow for sustained workloads"],
                ["Case", "Black workstation chassis with tempered glass and RGB lighting"],
                ["Motherboard", "WRX90 workstation board with multi-GPU spacing and reinforced PCIe slots"],
                ["Networking", "2.5Gb Ethernet, Wi-Fi 7 and Bluetooth for modern peripherals"],
                ["Power Supply", "1200W 80+ Gold modular PSU with overhead for future upgrades"],
                ["Front I/O", "USB-C, high-speed USB-A ports and dedicated audio connections"],
                ["Warranty", "Three-year parts and labour coverage with priority workstation support"],
                ["Build Notes", "Cable-managed interior, tuned fan curves and burn-in testing before dispatch"]
            ]
        },
        "product-2": {
            title: "Intel Ultra 9 Z890 PC Builder",
            price: "&pound; 1900",
            image: "assets/images/pc_2.png",
            details: [
                ["Operating System", "Windows 11 Pro"],
                ["Processor", "Intel Core Ultra 9 285K: 24 cores, 125W TDP, 40MB cache"],
                ["Graphics", "Dedicated high-performance graphics ready for modern gaming"],
                ["Memory", "32GB DDR5 memory"],
                ["Storage", "2TB NVMe SSD"],
                ["Motherboard", "Z890 platform with Wi-Fi and expansion-ready connectivity"],
                ["Cooling", "Performance air cooling with quiet fan profile"]
            ]
        },
        "product-3": {
            title: "AMD 7000-Series Ryzen 9 Custom",
            price: "&pound; 2100",
            image: "assets/images/pc_3.png",
            details: [
                ["Operating System", "Windows 11 Home"],
                ["Processor", "AMD Ryzen 9 7900X: 12 cores, 170W TDP, up to 5.60GHz turbo"],
                ["Graphics", "High-end Radeon or GeForce graphics configuration"],
                ["Memory", "64GB DDR5 memory"],
                ["Storage", "2TB NVMe SSD"],
                ["Cooling", "Liquid cooling with optimized airflow"],
                ["Case", "Compact black showcase case with filtered intake"]
            ]
        },
        "product-4": {
            title: "Ryzen 7 RTX Gaming PC",
            price: "&pound; 1600",
            image: "assets/images/pc_4.png",
            details: [
                ["Operating System", "Windows 11 Home"],
                ["Processor", "AMD Ryzen 7 7800X3D: 8 cores with 3D V-Cache"],
                ["Graphics", "NVIDIA GeForce RTX 4070 SUPER"],
                ["Memory", "32GB DDR5 memory"],
                ["Storage", "2TB NVMe SSD"],
                ["Connectivity", "Wi-Fi ready with modern rear I/O"],
                ["Cooling", "Quiet tower cooling for gaming loads"]
            ]
        },
        "product-5": {
            title: "Intel Core i7 Gaming PC",
            price: "&pound; 1750",
            image: "assets/images/pc_5.png",
            details: [
                ["Operating System", "Windows 11 Home"],
                ["Processor", "Intel Core i7 performance CPU with hybrid core architecture"],
                ["Graphics", "NVIDIA GeForce RTX 4070 Ti SUPER"],
                ["Memory", "32GB DDR5 memory"],
                ["Storage", "2TB NVMe SSD"],
                ["Lighting", "RGB cooling and case accents"],
                ["Cooling", "Balanced airflow setup for long gaming sessions"]
            ]
        },
        "product-6": {
            title: "Ryzen 9 Workstation PC",
            price: "&pound; 2400",
            image: "assets/images/pc_6.png",
            details: [
                ["Operating System", "Windows 11 Pro"],
                ["Processor", "AMD Ryzen 9 9950X: 16 cores for creation and multitasking"],
                ["Graphics", "NVIDIA GeForce RTX 4080 SUPER"],
                ["Memory", "64GB DDR5 memory"],
                ["Storage", "4TB NVMe SSD"],
                ["Cooling", "Liquid cooling with a quiet performance curve"],
                ["Case", "Showcase chassis with panoramic side window"]
            ]
        }
    };

    /*
     * Cached DOM references.
     *
     * These elements are queried once and then reused by the event handlers and
     * render functions. This keeps the code below focused on updating known modal
     * pieces instead of searching the page repeatedly.
     */
    const modal = document.getElementById("product-modal");
    const modalTitle = document.getElementById("modal-product-title");
    const modalImageStage = modal.querySelector(".product-modal__image-stage");
    const modalImage = document.getElementById("modal-product-image");
    const modalBlank = document.getElementById("modal-product-blank");
    const modalDots = document.getElementById("modal-product-dots");
    const modalText = document.getElementById("modal-product-text");
    const modalPrice = document.getElementById("modal-product-price");
    const modalCopy = modal.querySelector(".product-modal__copy");
    const modalScrollbar = modal.querySelector(".product-modal__scrollbar");
    const modalScrollbarThumb = modal.querySelector(".product-modal__scrollbar-thumb");
    const cartButton = modal.querySelector(".product-modal__cart");
    const cartMessage = document.getElementById("modal-cart-message");
    const cartBadge = document.querySelector(".cart-badge");
    const moreInfoLinks = document.querySelectorAll(".product-more-info[data-product-id]");

    /*
     * Runtime state for the currently open modal.
     *
     * activeProduct, activeProductId and activeSlide describe which product and
     * slide are visible, and which database id should be posted to the cart.
     * lastFocusedElement lets closeModal() return keyboard focus to the link that
     * opened the modal, which matters for keyboard and screen-reader users. The
     * remaining values support custom scrollbar dragging and mobile background
     * scroll locking.
     */
    let activeProduct = null;
    let activeProductId = null;
    let activeSlide = 0;
    let lastFocusedElement = null;
    let isDraggingScrollbar = false;
    let dragStartY = 0;
    let dragStartScrollTop = 0;
    let lockedScrollY = 0;
    let lastTouchY = 0;
    let cartMessageTimer = 0;
    let cartMessageHideTimer = 0;
    let cartMessageAnimationFrame = 0;
    let slideAnimationTimer = 0;
    let slideAnimationToken = 0;
    let visibleSlide = 0;
    let isSlideAnimating = false;
    let modalCloseResetTimer = 0;
    let imageSwipeStartX = 0;
    let imageSwipeStartY = 0;
    let isTrackingImageSwipe = false;
    let isImageSwipeIntent = false;
    let cartButtonLabelAnimationTimer = 0;
    let cartButtonLabelSettleTimer = 0;
    const cartButtonLabelSlideDuration = 420;
    const cartButtonLabelColorFadeDuration = 700;
    const modalCloseResetDelay = 320;

    /*
     * Checks whether an element can still scroll in the requested direction.
     *
     * deltaY uses one shared sign convention for wheel and touch events after
     * normalization in preventBackgroundScroll():
     * - positive means the user is moving toward previous content;
     * - negative means the user is moving toward later content.
     */
    function canScroll(element, deltaY) {
        if (!element || element.scrollHeight <= element.clientHeight) {
            return false;
        }

        if (deltaY > 0) {
            return element.scrollTop > 0;
        }

        if (deltaY < 0) {
            return element.scrollTop + element.clientHeight < element.scrollHeight - 1;
        }

        return true;
    }

    /*
     * Walks up from the event target and checks whether the action happened
     * inside a modal area that can handle the scroll. If it can, the browser may
     * scroll the modal content. If it cannot, the scroll is blocked later so the
     * page behind the modal does not move.
     */
    function hasScrollableModalParent(target, deltaY) {
        let element = target;

        while (element && element !== document.body) {
            if (element === modal && canScroll(element, deltaY)) {
                return true;
            }

            if (element.closest && element.closest(".product-modal") && canScroll(element, deltaY)) {
                return true;
            }

            if (element === modal) {
                break;
            }

            element = element.parentElement;
        }

        return false;
    }

    /*
     * Prevents the page behind the modal from scrolling.
     *
     * Mouse wheel events and mobile touch events describe movement differently,
     * so this function first normalizes both into deltaY. It then checks whether
     * the modal itself can scroll. Only scrolls outside the modal, or attempts to
     * scroll past the modal's own edges, are cancelled.
     */
    function preventBackgroundScroll(event) {
        if (!modal.classList.contains("is-open")) {
            return;
        }

        const eventTarget = event.target.closest ? event.target : event.target.parentElement;

        if (!eventTarget || !eventTarget.closest(".product-modal")) {
            event.preventDefault();
            return;
        }

        const deltaY = event.type === "wheel" ? -event.deltaY : event.touches[0].clientY - lastTouchY;

        if (!hasScrollableModalParent(eventTarget, deltaY)) {
            event.preventDefault();
        }

        if (event.type === "touchmove") {
            lastTouchY = event.touches[0].clientY;
        }
    }

    /*
     * Stores the previous touch position so touchmove can calculate the next
     * movement direction. This is needed because touchmove does not provide a
     * ready-made delta value like a wheel event.
     */
    function rememberTouchPosition(event) {
        lastTouchY = event.touches[0].clientY;
    }

    /*
     * Locks the background page while the modal is open.
     *
     * The current scroll position is saved before modal-open classes and event
     * listeners are added. unlockPageScroll() restores that position so closing
     * the modal does not move the visitor to a different part of the page.
     */
    function lockPageScroll() {
        lockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        document.documentElement.classList.add("modal-open");
        document.body.classList.add("modal-open");
        document.addEventListener("touchstart", rememberTouchPosition, { passive: true });
        document.addEventListener("touchmove", preventBackgroundScroll, { passive: false });
        document.addEventListener("wheel", preventBackgroundScroll, { passive: false });
    }

    /*
     * Removes the scroll lock and deletes the listeners added by
     * lockPageScroll(). Keeping the lock and unlock logic paired helps avoid
     * duplicate listeners when the modal is opened more than once.
     */
    function unlockPageScroll() {
        document.documentElement.classList.remove("modal-open");
        document.body.classList.remove("modal-open");
        document.removeEventListener("touchstart", rememberTouchPosition);
        document.removeEventListener("touchmove", preventBackgroundScroll);
        document.removeEventListener("wheel", preventBackgroundScroll);
        window.scrollTo(0, lockedScrollY);
    }

    /*
     * Builds the slide list for the image area.
     *
     * Real product images come from the database when available. Placeholder
     * slides keep every dot animated until more gallery images are added.
     */
    function getSlides(product) {
        const slides = Array.isArray(product.images) && product.images.length
            ? product.images.map((image) => ({
                type: "image",
                src: image.src,
                alt: image.alt || product.title
            }))
            : [{ type: "image", src: product.image, alt: product.title }];

        while (slides.length < 8) {
            slides.push({
                type: "image",
                src: "assets/images/pc_noimage.png",
                alt: `${product.title} image coming soon`
            });
        }

        return slides.slice(0, 8);
    }

    /*
     * Renders the details text for the selected product.
     *
     * The details can now come from the database, so each text node is created
     * directly instead of building HTML strings.
     */
    function renderDetails(product) {
        modalText.textContent = "";

        product.details.forEach(([label, value]) => {
            const detail = document.createElement("p");
            const detailLabel = document.createElement("strong");

            detailLabel.textContent = `${label}:`;
            detail.appendChild(detailLabel);
            detail.append(` ${value}`);
            modalText.appendChild(detail);
        });

        modalText.scrollTop = 0;
    }

    /*
     * Synchronizes the custom scrollbar with the real text scroll position.
     *
     * The modalText panel still uses normal browser scrolling. This function only
     * changes the size and position of the visual thumb:
     * - thumb height shows how much of the content is visible;
     * - thumb position reflects modalText.scrollTop within the full range.
     */
    function updateCustomScrollbar() {
        const scrollRange = modalText.scrollHeight - modalText.clientHeight;
        const hasScroll = scrollRange > 1;

        modalCopy.classList.toggle("has-scroll", hasScroll);

        if (!hasScroll) {
            modalScrollbarThumb.style.height = "";
            modalScrollbarThumb.style.transform = "translateY(0)";
            return;
        }

        const trackHeight = modalScrollbar.clientHeight;
        const thumbHeight = Math.max(54, Math.round(trackHeight * (modalText.clientHeight / modalText.scrollHeight)));
        const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
        const thumbTop = scrollRange > 0 ? (modalText.scrollTop / scrollRange) * maxThumbTop : 0;

        modalScrollbarThumb.style.height = `${thumbHeight}px`;
        modalScrollbarThumb.style.transform = `translateY(${thumbTop}px)`;
    }

    /*
     * Schedules a scrollbar update for the next animation frame. This lets the
     * browser apply recent DOM and class changes before layout values are read.
     * It is especially useful right after opening the modal or resizing the
     * window.
     */
    function requestScrollbarUpdate() {
        window.requestAnimationFrame(updateCustomScrollbar);
    }

    /*
     * Keeps the cart counter in the navigation synced with the server-side
     * session cart after a product is added.
     */
    function updateCartBadge(count) {
        if (!cartBadge) {
            return;
        }

        const cartCount = Number(count) || 0;
        cartBadge.textContent = cartCount > 0 ? cartCount : "";
    }

    function ensureCartButtonLabel() {
        if (!cartButton) {
            return null;
        }

        let viewport = cartButton.querySelector(".product-modal__cart-label-viewport");
        let label = cartButton.querySelector(".product-modal__cart-label--current");

        if (!viewport || !label) {
            const initialText = cartButton.textContent.trim() || "Add to Cart";

            cartButton.textContent = "";
            viewport = document.createElement("span");
            viewport.className = "product-modal__cart-label-viewport";

            label = document.createElement("span");
            label.className = "product-modal__cart-label product-modal__cart-label--current";
            label.textContent = initialText;
            label.dataset.label = initialText;

            viewport.append(label);
            cartButton.append(viewport);
        }

        return { viewport, label };
    }

    function setCartButtonLabel(nextText, shouldAnimate) {
        const labelParts = ensureCartButtonLabel();

        if (!labelParts) {
            return;
        }

        const { viewport, label } = labelParts;
        const normalizedText = nextText || "Add to Cart";

        if (label.textContent === normalizedText) {
            return;
        }

        window.clearTimeout(cartButtonLabelAnimationTimer);
        window.clearTimeout(cartButtonLabelSettleTimer);
        viewport.querySelectorAll(".product-modal__cart-label--leaving").forEach((leavingLabel) => {
            leavingLabel.remove();
        });
        label.classList.remove(
            "product-modal__cart-label--entering",
            "product-modal__cart-label--fresh",
            "product-modal__cart-label--settling"
        );

        if (!shouldAnimate) {
            label.textContent = normalizedText;
            label.dataset.label = normalizedText;
            return;
        }

        const leavingLabel = label.cloneNode(true);
        leavingLabel.className = "product-modal__cart-label product-modal__cart-label--leaving";
        leavingLabel.addEventListener("animationend", () => {
            leavingLabel.remove();
        }, { once: true });
        viewport.append(leavingLabel);

        label.textContent = normalizedText;
        label.dataset.label = normalizedText;
        label.classList.add("product-modal__cart-label--entering", "product-modal__cart-label--fresh");

        let hasSettled = false;
        const settleCartButtonLabel = () => {
            if (hasSettled) {
                return;
            }

            hasSettled = true;
            label.classList.remove("product-modal__cart-label--entering");
            label.classList.add("product-modal__cart-label--settling");
        };
        const handleCartButtonLabelEnterEnd = (event) => {
            if (event.animationName !== "productModalCartLabelEnterRight") {
                return;
            }

            window.clearTimeout(cartButtonLabelSettleTimer);
            settleCartButtonLabel();
            label.removeEventListener("animationend", handleCartButtonLabelEnterEnd);
        };

        label.addEventListener("animationend", handleCartButtonLabelEnterEnd);
        cartButtonLabelSettleTimer = window.setTimeout(settleCartButtonLabel, cartButtonLabelSlideDuration + 60);

        cartButtonLabelAnimationTimer = window.setTimeout(() => {
            label.removeEventListener("animationend", handleCartButtonLabelEnterEnd);
            label.classList.remove(
                "product-modal__cart-label--entering",
                "product-modal__cart-label--fresh",
                "product-modal__cart-label--settling"
            );
            viewport.querySelectorAll(".product-modal__cart-label--leaving").forEach((leavingLabel) => {
                leavingLabel.remove();
            });
        }, cartButtonLabelSlideDuration + cartButtonLabelColorFadeDuration + 120);
    }

    function updateCartAvailability(product, options) {
        if (!product) {
            if (cartButton) {
                cartButton.disabled = false;
                cartButton.dataset.cartState = "default";
                setCartButtonLabel("Add to Cart", false);
            }

            return;
        }

        const hasStockQuantity = Object.prototype.hasOwnProperty.call(product, "stockQuantity");
        const stockQuantity = hasStockQuantity ? Number(product.stockQuantity) || 0 : 1;
        const isOutOfStock = hasStockQuantity && stockQuantity < 1;
        const isInCart = Boolean(product.inCart);

        if (cartButton) {
            cartButton.disabled = isOutOfStock || isInCart;
            cartButton.dataset.cartState = isInCart ? "added" : (isOutOfStock ? "unavailable" : "default");
            setCartButtonLabel(isInCart ? "added to cart" : "Add to Cart", Boolean(options && options.animate));
        }
    }

    function resetModalAfterClose() {
        if (modal.classList.contains("is-open")) {
            return;
        }

        modalCopy.classList.remove("has-scroll");
        activeProduct = null;
        activeProductId = null;
        updateCartAvailability(null);

        if (cartButton) {
            delete cartButton.dataset.productId;
        }
    }

    /*
     * Shows the add-to-cart result without changing the modal layout. Duplicate
     * products use the same live region so screen-reader users hear the message.
     */
    function showCartMessage(message, tone) {
        if (!cartMessage) {
            return;
        }

        window.clearTimeout(cartMessageTimer);
        window.clearTimeout(cartMessageHideTimer);
        window.cancelAnimationFrame(cartMessageAnimationFrame);
        cartMessage.textContent = message ? String(message).toLocaleLowerCase("en-US") : "";
        cartMessage.dataset.tone = tone || "";
        cartMessage.classList.remove("is-visible", "is-hiding");

        if (message) {
            cartMessageAnimationFrame = window.requestAnimationFrame(() => {
                cartMessage.classList.add("is-visible");
            });

            cartMessageHideTimer = window.setTimeout(() => {
                cartMessage.classList.add("is-hiding");
            }, 3000);

            cartMessageTimer = window.setTimeout(() => {
                cartMessage.textContent = "";
                cartMessage.dataset.tone = "";
                cartMessage.classList.remove("is-visible", "is-hiding");
            }, 3600);
        }
    }

    /*
     * Creates the dot buttons for the carousel. Each button closes over its own
     * index, so a click can call setSlide(index) directly.
     */
    function renderDots(slides) {
        modalDots.innerHTML = "";

        slides.forEach((slide, index) => {
            const dot = document.createElement("button");
            dot.type = "button";
            dot.className = "product-modal__dot";
            dot.setAttribute("aria-label", `Image ${index + 1}`);
            dot.addEventListener("click", () => setSlide(index));
            modalDots.appendChild(dot);
        });
    }

    function clearSlideAnimation() {
        slideAnimationToken += 1;
        window.clearTimeout(slideAnimationTimer);
        isSlideAnimating = false;
        modalImage.classList.remove(
            "product-modal__image--entering",
            "product-modal__image--entering-from-left",
            "product-modal__image--entering-from-right"
        );
        modalImageStage.querySelectorAll(".product-modal__image--leaving").forEach((image) => {
            image.remove();
        });
    }

    function finishSlideAnimation(token) {
        if (token !== slideAnimationToken) {
            return;
        }

        window.clearTimeout(slideAnimationTimer);
        modalImage.classList.remove(
            "product-modal__image--entering",
            "product-modal__image--entering-from-left",
            "product-modal__image--entering-from-right"
        );
        modalImageStage.querySelectorAll(".product-modal__image--leaving").forEach((image) => {
            image.remove();
        });

        isSlideAnimating = false;
    }

    function renderImageSlide(slide, shouldAnimate, direction) {
        const canAnimate = shouldAnimate && !modalImage.hidden && modalImage.getAttribute("src");
        const isForward = direction !== "backward";

        clearSlideAnimation();

        if (canAnimate) {
            const leavingImage = modalImage.cloneNode(false);
            leavingImage.removeAttribute("id");
            leavingImage.className = [
                "product-modal__image",
                "product-modal__image--leaving",
                isForward ? "product-modal__image--leaving-to-left" : "product-modal__image--leaving-to-right"
            ].join(" ");
            leavingImage.hidden = false;
            leavingImage.addEventListener("animationend", () => {
                leavingImage.remove();
            }, { once: true });
            modalImageStage.appendChild(leavingImage);
        }

        modalImage.src = slide.src;
        modalImage.alt = slide.alt;
        modalImage.hidden = false;
        modalBlank.hidden = true;

        if (canAnimate) {
            isSlideAnimating = true;
            slideAnimationToken += 1;
            const currentAnimationToken = slideAnimationToken;

            modalImage.classList.add(
                "product-modal__image--entering",
                isForward ? "product-modal__image--entering-from-right" : "product-modal__image--entering-from-left"
            );
            modalImage.addEventListener("animationend", (event) => {
                if (event.animationName === "productModalImageEnterLeft" || event.animationName === "productModalImageEnterRight") {
                    finishSlideAnimation(currentAnimationToken);
                }
            }, { once: true });

            slideAnimationTimer = window.setTimeout(() => {
                finishSlideAnimation(currentAnimationToken);
            }, 650);
        }
    }

    function updateDots() {
        [...modalDots.children].forEach((dot, dotIndex) => {
            const isActive = dotIndex === activeSlide;
            dot.classList.toggle("is-active", isActive);
            dot.setAttribute("aria-current", isActive ? "true" : "false");
        });
    }

    function showSlide(index, options) {
        const slides = getSlides(activeProduct);
        const slide = slides[index] || slides[0];
        const shouldAnimate = !(options && options.animate === false) && index !== visibleSlide;
        const direction = options && options.direction
            ? options.direction
            : (index < visibleSlide ? "backward" : "forward");

        visibleSlide = index;

        if (slide.type === "image") {
            renderImageSlide(slide, shouldAnimate, direction);
        } else {
            clearSlideAnimation();
            modalImage.hidden = true;
            modalBlank.hidden = false;
        }

        if (!(options && options.updateDots === false)) {
            updateDots();
        }
    }

    /*
     * Shows the requested slide and updates dot state.
     *
     * Image slides animate from the side of the selected dot. The previous image
     * exits in the opposite direction while aria-current marks the active dot for
     * assistive technologies.
     */
    function setSlide(index, options) {
        if (!activeProduct) {
            return;
        }

        const shouldForceInstantSlide = options && options.animate === false;

        if (isSlideAnimating && !shouldForceInstantSlide) {
            return;
        }

        const slides = getSlides(activeProduct);
        const normalizedIndex = slides[index] ? index : 0;
        const previousSlide = activeSlide;
        const shouldAnimate = !shouldForceInstantSlide && normalizedIndex !== previousSlide;
        const direction = options && options.direction
            ? options.direction
            : (normalizedIndex < previousSlide ? "backward" : "forward");

        activeSlide = normalizedIndex;
        updateDots();

        showSlide(normalizedIndex, {
            animate: shouldAnimate,
            direction,
            updateDots: false
        });
    }

    function goToRelativeSlide(step) {
        if (!activeProduct || step === 0) {
            return;
        }

        const slides = getSlides(activeProduct);

        if (slides.length < 2) {
            return;
        }

        const nextSlide = (activeSlide + step + slides.length) % slides.length;

        setSlide(nextSlide, {
            direction: step > 0 ? "forward" : "backward"
        });
    }

    function beginImageSwipe(event) {
        if (!activeProduct || event.touches.length !== 1) {
            isTrackingImageSwipe = false;
            return;
        }

        const touch = event.touches[0];
        imageSwipeStartX = touch.clientX;
        imageSwipeStartY = touch.clientY;
        isTrackingImageSwipe = true;
        isImageSwipeIntent = false;
    }

    function trackImageSwipe(event) {
        if (!isTrackingImageSwipe || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];
        const deltaX = touch.clientX - imageSwipeStartX;
        const deltaY = touch.clientY - imageSwipeStartY;
        const absX = Math.abs(deltaX);
        const absY = Math.abs(deltaY);

        if (!isImageSwipeIntent && (absX > 10 || absY > 10)) {
            isImageSwipeIntent = absX > absY * 1.18;
        }

        if (isImageSwipeIntent) {
            event.preventDefault();
        }
    }

    function endImageSwipe(event) {
        if (!isTrackingImageSwipe) {
            return;
        }

        const touch = event.changedTouches[0];
        const deltaX = touch.clientX - imageSwipeStartX;
        const deltaY = touch.clientY - imageSwipeStartY;
        const absX = Math.abs(deltaX);
        const absY = Math.abs(deltaY);
        const shouldChangeSlide = absX >= 46 && absX > absY * 1.18;

        isTrackingImageSwipe = false;
        isImageSwipeIntent = false;

        if (!shouldChangeSlide) {
            return;
        }

        event.preventDefault();
        goToRelativeSlide(deltaX < 0 ? 1 : -1);
    }

    function cancelImageSwipe() {
        isTrackingImageSwipe = false;
        isImageSwipeIntent = false;
    }

    /*
     * Opens the modal for the product id from the clicked "more info" link.
     *
     * The function updates all modal content first, then locks background scroll,
     * makes the window visible, schedules a custom scrollbar update and moves
     * focus to the close button for keyboard users.
     */
    function openModal(productId) {
        const product = products[productId];

        if (!product) {
            return;
        }

        window.clearTimeout(modalCloseResetTimer);
        activeProduct = product;
        activeProductId = product.id || productId;
        activeSlide = 0;
        lastFocusedElement = document.activeElement;
        showCartMessage("", "");

        if (cartButton) {
            cartButton.dataset.productId = activeProductId;
        }

        modalTitle.textContent = product.title;
        modalPrice.innerHTML = product.price;
        updateCartAvailability(product);
        renderDetails(product);
        renderDots(getSlides(product));
        setSlide(0, { animate: false });

        lockPageScroll();
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        requestScrollbarUpdate();

        modal.querySelector(".product-modal__close").focus();
    }

    /*
     * Closes the modal and returns the page to its previous state.
     *
     * Focus returns to the element that opened the window. This lets keyboard
     * users continue from the same product card instead of being sent back to the
     * top of the page.
     */
    function closeModal() {
        unlockPageScroll();
        window.clearTimeout(modalCloseResetTimer);
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        showCartMessage("", "");
        modalCloseResetTimer = window.setTimeout(resetModalAfterClose, modalCloseResetDelay);

        if (lastFocusedElement) {
            lastFocusedElement.focus();
        }
    }

    /*
     * Adds the open product to the PHP session cart and updates the nav counter
     * from the JSON response.
     */
    async function addActiveProductToCart() {
        const productId = cartButton ? cartButton.dataset.productId || activeProductId : activeProductId;

        if (!productId || !cartButton) {
            return;
        }

        if (activeProduct && activeProduct.inCart) {
            updateCartAvailability(activeProduct);
            return;
        }

        if (
            activeProduct
            && Object.prototype.hasOwnProperty.call(activeProduct, "stockQuantity")
            && (Number(activeProduct.stockQuantity) || 0) < 1
        ) {
            showCartMessage("this product is out of stock.", "error");
            return;
        }

        cartButton.dataset.cartState = "pending";
        cartButton.disabled = true;

        try {
            const response = await fetch(window.fixerupperCartEndpoint || "add_to_cart.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams({
                    product_id: productId,
                    quantity: "1"
                })
            });

            const result = await response.json();

            if (result.duplicate) {
                if (activeProduct) {
                    activeProduct.inCart = true;
                }

                updateCartBadge(result.cart_count);
                updateCartAvailability(activeProduct, { animate: true });
                showCartMessage("", "");
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || "unable to add product to cart.");
            }

            updateCartBadge(result.cart_count);
            if (activeProduct) {
                activeProduct.inCart = true;
            }

            updateCartAvailability(activeProduct, { animate: true });
            showCartMessage("", "");
        } catch (error) {
            console.error(error);
            showCartMessage(error.message || "unable to add product to cart.", "error");
        } finally {
            updateCartAvailability(activeProduct);
        }
    }

    /*
     * Entry points from product cards.
     *
     * The link keeps its normal href as an HTML fallback, but JavaScript
     * intercepts the click and opens the modal instead of navigating away.
     */
    moreInfoLinks.forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            openModal(link.dataset.productId);
        });
    });

    if (cartButton) {
        cartButton.addEventListener("click", addActiveProductToCart);
    }

    modalImageStage.addEventListener("touchstart", beginImageSwipe, { passive: true });
    modalImageStage.addEventListener("touchmove", trackImageSwipe, { passive: false });
    modalImageStage.addEventListener("touchend", endImageSwipe, { passive: false });
    modalImageStage.addEventListener("touchcancel", cancelImageSwipe);

    /*
     * Any element inside the modal with the data-modal-close attribute closes the
     * window. This lets the close button and any other close controls share the
     * same behavior.
     */
    modal.addEventListener("click", (event) => {
        if (event.target.closest("[data-modal-close]")) {
            closeModal();
        }
    });

    /*
     * Updates the visual scrollbar whenever the details panel scrolls for real:
     * by mouse wheel, touch gesture, keyboard or another native scrolling method.
     */
    modalText.addEventListener("scroll", updateCustomScrollbar);

    /*
     * Custom scrollbar pointer handling.
     *
     * Clicking the track moves the details panel closer to the selected position.
     * Dragging the thumb converts pointer movement into modalText.scrollTop
     * changes.
     */
    modalScrollbar.addEventListener("pointerdown", (event) => {
        if (!modalCopy.classList.contains("has-scroll")) {
            return;
        }

        const clickedThumb = event.target === modalScrollbarThumb;

        if (!clickedThumb) {
            const trackRect = modalScrollbar.getBoundingClientRect();
            const thumbHeight = modalScrollbarThumb.offsetHeight;
            const maxThumbTop = Math.max(0, trackRect.height - thumbHeight);
            const nextThumbTop = Math.min(
                maxThumbTop,
                Math.max(0, event.clientY - trackRect.top - thumbHeight / 2)
            );
            const scrollRange = modalText.scrollHeight - modalText.clientHeight;
            modalText.scrollTop = maxThumbTop > 0 ? (nextThumbTop / maxThumbTop) * scrollRange : 0;
        }

        isDraggingScrollbar = true;
        dragStartY = event.clientY;
        dragStartScrollTop = modalText.scrollTop;
        modalScrollbar.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    /*
     * While dragging, converts pointer movement along the track into the matching
     * scroll distance inside the text panel.
     */
    modalScrollbar.addEventListener("pointermove", (event) => {
        if (!isDraggingScrollbar) {
            return;
        }

        const trackHeight = modalScrollbar.clientHeight;
        const thumbHeight = modalScrollbarThumb.offsetHeight;
        const maxThumbTop = Math.max(1, trackHeight - thumbHeight);
        const scrollRange = modalText.scrollHeight - modalText.clientHeight;
        const scrollPerPixel = scrollRange / maxThumbTop;

        modalText.scrollTop = dragStartScrollTop + (event.clientY - dragStartY) * scrollPerPixel;
    });

    /*
     * pointerup and pointercancel both end dragging and release pointer capture.
     * pointercancel handles cases such as browser gestures or interrupted input.
     */
    modalScrollbar.addEventListener("pointerup", (event) => {
        isDraggingScrollbar = false;
        modalScrollbar.releasePointerCapture(event.pointerId);
    });

    modalScrollbar.addEventListener("pointercancel", (event) => {
        isDraggingScrollbar = false;
        modalScrollbar.releasePointerCapture(event.pointerId);
    });

    /*
     * Escape is the standard key for closing modal windows.
     */
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("is-open")) {
            closeModal();
        }
    });

    /*
     * Resizing the window can change the text panel height, so an open modal must
     * recalculate the thumb size and position.
     */
    window.addEventListener("resize", () => {
        if (modal.classList.contains("is-open")) {
            requestScrollbarUpdate();
        }
    });
})();
