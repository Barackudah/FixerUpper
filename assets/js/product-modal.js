(function () {
    const products = {
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

    const modal = document.getElementById("product-modal");
    const modalTitle = document.getElementById("modal-product-title");
    const modalImage = document.getElementById("modal-product-image");
    const modalBlank = document.getElementById("modal-product-blank");
    const modalDots = document.getElementById("modal-product-dots");
    const modalText = document.getElementById("modal-product-text");
    const modalPrice = document.getElementById("modal-product-price");
    const modalCopy = modal.querySelector(".product-modal__copy");
    const modalScrollbar = modal.querySelector(".product-modal__scrollbar");
    const modalScrollbarThumb = modal.querySelector(".product-modal__scrollbar-thumb");
    const moreInfoLinks = document.querySelectorAll(".product-more-info[data-product-id]");

    let activeProduct = null;
    let activeSlide = 0;
    let lastFocusedElement = null;
    let isDraggingScrollbar = false;
    let dragStartY = 0;
    let dragStartScrollTop = 0;
    let lockedScrollY = 0;
    let lastTouchY = 0;

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

    function rememberTouchPosition(event) {
        lastTouchY = event.touches[0].clientY;
    }

    function lockPageScroll() {
        lockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        document.documentElement.classList.add("modal-open");
        document.body.classList.add("modal-open");
        document.addEventListener("touchstart", rememberTouchPosition, { passive: true });
        document.addEventListener("touchmove", preventBackgroundScroll, { passive: false });
        document.addEventListener("wheel", preventBackgroundScroll, { passive: false });
    }

    function unlockPageScroll() {
        document.documentElement.classList.remove("modal-open");
        document.body.classList.remove("modal-open");
        document.removeEventListener("touchstart", rememberTouchPosition);
        document.removeEventListener("touchmove", preventBackgroundScroll);
        document.removeEventListener("wheel", preventBackgroundScroll);
        window.scrollTo(0, lockedScrollY);
    }

    function getSlides(product) {
        return [
            { type: "image", src: product.image, alt: product.title },
            { type: "blank" },
            { type: "blank" },
            { type: "blank" },
            { type: "blank" },
            { type: "blank" },
            { type: "blank" },
            { type: "blank" }
        ];
    }

    function renderDetails(product) {
        modalText.innerHTML = product.details
            .map(([label, value]) => `<p><strong>${label}:</strong> ${value}</p>`)
            .join("");
        modalText.scrollTop = 0;
    }

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

    function requestScrollbarUpdate() {
        window.requestAnimationFrame(updateCustomScrollbar);
    }

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

    function setSlide(index) {
        if (!activeProduct) {
            return;
        }

        const slides = getSlides(activeProduct);
        const slide = slides[index] || slides[0];
        activeSlide = index;

        if (slide.type === "image") {
            modalImage.src = slide.src;
            modalImage.alt = slide.alt;
            modalImage.hidden = false;
            modalBlank.hidden = true;
        } else {
            modalImage.hidden = true;
            modalBlank.hidden = false;
        }

        [...modalDots.children].forEach((dot, dotIndex) => {
            const isActive = dotIndex === activeSlide;
            dot.classList.toggle("is-active", isActive);
            dot.setAttribute("aria-current", isActive ? "true" : "false");
        });
    }

    function openModal(productId) {
        const product = products[productId];

        if (!product) {
            return;
        }

        activeProduct = product;
        activeSlide = 0;
        lastFocusedElement = document.activeElement;

        modalTitle.textContent = product.title;
        modalPrice.innerHTML = product.price;
        renderDetails(product);
        renderDots(getSlides(product));
        setSlide(0);

        lockPageScroll();
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        requestScrollbarUpdate();

        modal.querySelector(".product-modal__close").focus();
    }

    function closeModal() {
        unlockPageScroll();
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        modalCopy.classList.remove("has-scroll");
        activeProduct = null;

        if (lastFocusedElement) {
            lastFocusedElement.focus();
        }
    }

    moreInfoLinks.forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            openModal(link.dataset.productId);
        });
    });

    modal.addEventListener("click", (event) => {
        if (event.target.closest("[data-modal-close]")) {
            closeModal();
        }
    });

    modalText.addEventListener("scroll", updateCustomScrollbar);

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

    modalScrollbar.addEventListener("pointerup", (event) => {
        isDraggingScrollbar = false;
        modalScrollbar.releasePointerCapture(event.pointerId);
    });

    modalScrollbar.addEventListener("pointercancel", (event) => {
        isDraggingScrollbar = false;
        modalScrollbar.releasePointerCapture(event.pointerId);
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("is-open")) {
            closeModal();
        }
    });

    window.addEventListener("resize", () => {
        if (modal.classList.contains("is-open")) {
            requestScrollbarUpdate();
        }
    });
})();
