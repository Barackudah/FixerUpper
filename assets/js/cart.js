(function () {
    // Cart page controller: keeps quantity buttons, remove confirmation and totals in sync.
    const table = document.querySelector("[data-cart-table]");
    const cartBadge = document.querySelector(".cart-badge");
    const cartMessage = document.querySelector("[data-cart-message]");
    const checkoutTrigger = document.querySelector("[data-checkout-trigger]");
    const checkoutBadge = checkoutTrigger ? checkoutTrigger.querySelector(".cart-checkout-badge") : null;
    const checkoutRedirect = checkoutTrigger ? checkoutTrigger.dataset.checkoutRedirect || "" : "";
    const isCheckoutAuthenticated = checkoutTrigger ? checkoutTrigger.dataset.checkoutAuthenticated === "1" : false;
    const checkoutModal = document.getElementById("checkout-modal");
    const checkoutModalClose = checkoutModal ? checkoutModal.querySelector("[data-checkout-modal-close]") : null;
    const checkoutInitialField = checkoutModal ? checkoutModal.querySelector('input[name="login_identifier"]') : null;
    const checkoutAuthMessage = checkoutModal ? checkoutModal.querySelector("[data-checkout-auth-message]") : null;
    const endpoint = window.fixerupperUpdateCartEndpoint || "update_cart.php";
    let cartMessageTimer = 0;
    let cartMessageHideTimer = 0;
    let cartMessageAnimationFrame = 0;
    let checkoutAnimationTimer = 0;
    let checkoutLockedScrollY = 0;
    let checkoutLastTouchY = 0;
    let isCheckoutAnimating = false;
    let isCheckoutScrollLocked = false;

    function getQuantityValue(quantityTarget) {
        const rawValue = "value" in quantityTarget ? quantityTarget.value : quantityTarget.textContent;
        const quantity = Number.parseInt(rawValue, 10);

        return Number.isFinite(quantity) ? quantity : 1;
    }

    function getStoredQuantity(quantityTarget) {
        const quantity = Number.parseInt(quantityTarget.dataset.previousQuantity, 10);

        return Number.isFinite(quantity) && quantity > 0 ? quantity : getQuantityValue(quantityTarget);
    }

    function setQuantityValue(quantityTarget, quantity) {
        const normalizedQuantity = Math.min(Math.max(Number.parseInt(quantity, 10) || 1, 1), 99);

        if ("value" in quantityTarget) {
            quantityTarget.value = String(normalizedQuantity);
        } else {
            quantityTarget.textContent = String(normalizedQuantity);
        }

        quantityTarget.dataset.previousQuantity = String(normalizedQuantity);
    }

    function getAvailableStock(row) {
        const availableStock = Number.parseInt(row.dataset.stockAvailable, 10);

        return Number.isFinite(availableStock) ? availableStock : 99;
    }

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

    function shouldRestoreCheckoutFocus() {
        return !window.matchMedia || window.matchMedia("(hover: hover) and (pointer: fine)").matches;
    }

    function resetCheckoutTriggerState(shouldBlur) {
        if (!checkoutTrigger) {
            return;
        }

        window.clearTimeout(checkoutAnimationTimer);
        checkoutTrigger.classList.remove("is-confirming");
        checkoutTrigger.disabled = false;
        isCheckoutAnimating = false;

        if (shouldBlur) {
            checkoutTrigger.blur();
        }
    }

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

    function hasScrollableCheckoutParent(target, deltaY) {
        let element = target;

        while (element && element !== document.body) {
            if (element === checkoutModal && canScroll(element, deltaY)) {
                return true;
            }

            if (element.closest && element.closest(".checkout-modal") && canScroll(element, deltaY)) {
                return true;
            }

            if (element === checkoutModal) {
                break;
            }

            element = element.parentElement;
        }

        return false;
    }

    function rememberCheckoutTouchPosition(event) {
        checkoutLastTouchY = event.touches[0].clientY;
    }

    function preventCheckoutBackgroundScroll(event) {
        if (!checkoutModal || !checkoutModal.classList.contains("is-open")) {
            return;
        }

        const eventTarget = event.target.closest ? event.target : event.target.parentElement;

        if (!eventTarget || !eventTarget.closest(".checkout-modal")) {
            event.preventDefault();
            return;
        }

        const deltaY = event.type === "wheel" ? -event.deltaY : event.touches[0].clientY - checkoutLastTouchY;

        if (!hasScrollableCheckoutParent(eventTarget, deltaY)) {
            event.preventDefault();
        }

        if (event.type === "touchmove") {
            checkoutLastTouchY = event.touches[0].clientY;
        }
    }

    function lockCheckoutPageScroll() {
        if (isCheckoutScrollLocked) {
            return;
        }

        isCheckoutScrollLocked = true;
        checkoutLockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        document.documentElement.classList.add("modal-open");
        document.body.classList.add("modal-open");
        document.addEventListener("touchstart", rememberCheckoutTouchPosition, { passive: true });
        document.addEventListener("touchmove", preventCheckoutBackgroundScroll, { passive: false });
        document.addEventListener("wheel", preventCheckoutBackgroundScroll, { passive: false });
    }

    function unlockCheckoutPageScroll() {
        isCheckoutScrollLocked = false;
        document.documentElement.classList.remove("modal-open");
        document.body.classList.remove("modal-open");
        document.removeEventListener("touchstart", rememberCheckoutTouchPosition);
        document.removeEventListener("touchmove", preventCheckoutBackgroundScroll);
        document.removeEventListener("wheel", preventCheckoutBackgroundScroll);
        window.scrollTo(0, checkoutLockedScrollY);
    }

    function openCheckoutModal() {
        if (!checkoutModal) {
            return;
        }

        lockCheckoutPageScroll();
        checkoutModal.classList.add("is-open");
        checkoutModal.setAttribute("aria-hidden", "false");

        if (shouldRestoreCheckoutFocus() && checkoutInitialField) {
            checkoutInitialField.focus();
        } else if (checkoutModalClose) {
            checkoutModalClose.focus();
        }
    }

    function closeCheckoutModal() {
        if (!checkoutModal || !checkoutModal.classList.contains("is-open")) {
            return;
        }

        checkoutModal.classList.remove("is-open");
        checkoutModal.setAttribute("aria-hidden", "true");
        unlockCheckoutPageScroll();
        resetCheckoutTriggerState(!shouldRestoreCheckoutFocus());

        if (checkoutTrigger && shouldRestoreCheckoutFocus()) {
            checkoutTrigger.focus();
        }
    }

    function runCheckoutAnimation() {
        return new Promise((resolve) => {
            if (!checkoutTrigger || !checkoutBadge) {
                resolve();
                return;
            }

            let isDone = false;
            const finish = () => {
                if (isDone) {
                    return;
                }

                isDone = true;
                window.clearTimeout(checkoutAnimationTimer);
                checkoutTrigger.removeEventListener("animationend", handleAnimationEnd);
                checkoutTrigger.classList.remove("is-confirming");
                checkoutTrigger.disabled = false;
                resolve();
            };
            const handleAnimationEnd = (event) => {
                if (event.animationName === "checkoutBadgeConfirm" || event.animationName === "checkoutBadgeConfirmTouch") {
                    finish();
                }
            };

            checkoutTrigger.disabled = true;
            checkoutTrigger.classList.remove("is-confirming");
            void checkoutTrigger.offsetWidth;
            checkoutTrigger.addEventListener("animationend", handleAnimationEnd);
            checkoutTrigger.classList.add("is-confirming");
            checkoutAnimationTimer = window.setTimeout(finish, 1750);
        });
    }

    if (checkoutTrigger) {
        checkoutTrigger.addEventListener("click", async () => {
            if (isCheckoutAnimating || (checkoutModal && checkoutModal.classList.contains("is-open"))) {
                return;
            }

            isCheckoutAnimating = true;
            await runCheckoutAnimation();
            isCheckoutAnimating = false;

            if (isCheckoutAuthenticated && checkoutRedirect) {
                window.location.href = checkoutRedirect;
                return;
            }

            openCheckoutModal();
        });
    }

    if (checkoutModal) {
        checkoutModal.addEventListener("click", (event) => {
            if (event.target.closest("[data-checkout-modal-close]")) {
                closeCheckoutModal();
            }
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeCheckoutModal();
            }
        });
    }

    if (checkoutModal && checkoutModal.classList.contains("is-open")) {
        lockCheckoutPageScroll();

        if (shouldRestoreCheckoutFocus() && checkoutInitialField) {
            checkoutInitialField.focus();
        } else if (checkoutModalClose) {
            checkoutModalClose.focus();
        }
    }

    if (checkoutAuthMessage) {
        const authMessageShouldRedirect = checkoutAuthMessage.dataset.tone === "success" && checkoutAuthMessage.dataset.checkoutAuthRedirect;

        checkoutAuthMessage.classList.remove("is-visible", "is-hiding");
        void checkoutAuthMessage.offsetWidth;

        window.requestAnimationFrame(() => {
            checkoutAuthMessage.classList.add("is-visible");
        });

        window.setTimeout(() => {
            checkoutAuthMessage.classList.add("is-hiding");
        }, 3000);

        window.setTimeout(() => {
            if (authMessageShouldRedirect) {
                closeCheckoutModal();
                window.location.href = checkoutAuthMessage.dataset.checkoutAuthRedirect;
                return;
            }

            checkoutAuthMessage.textContent = "";
            checkoutAuthMessage.classList.remove("is-visible", "is-hiding");
            checkoutAuthMessage.hidden = true;
        }, 3600);
    }

    window.addEventListener("pageshow", () => {
        resetCheckoutTriggerState(!shouldRestoreCheckoutFocus());
    });

    if (!table) {
        return;
    }

    table.querySelectorAll("[data-cart-quantity]").forEach((quantityTarget) => {
        setQuantityValue(quantityTarget, getQuantityValue(quantityTarget));
    });

    // Keep the header cart badge aligned with the server response after every update.
    function updateCartBadge(count) {
        if (!cartBadge) {
            return;
        }

        const cartCount = Number(count) || 0;
        cartBadge.textContent = cartCount > 0 ? cartCount : "";
    }

    // Busy rows are visually dimmed and their controls are disabled until AJAX finishes.
    function setRowBusy(row, isBusy) {
        row.classList.toggle("is-busy", isBusy);

        row.querySelectorAll("[data-cart-step], [data-cart-remove-confirm], [data-cart-quantity]").forEach((control) => {
            control.disabled = isBusy;
        });
    }

    // The minus button becomes unavailable only after the user reaches the remove prompt.
    function refreshDecreaseState(row, quantity) {
        const decreaseButton = row.querySelector("[data-cart-step='-1']");

        if (decreaseButton) {
            decreaseButton.disabled = quantity < 1;
        }
    }

    // Swap the quantity capsule with the YES / NO remove confirmation for zero quantity.
    function setPendingRemove(row, isPending, restoreQuantity) {
        const quantityControl = row.querySelector(".cart-quantity-control");
        const removeConfirmation = row.querySelector("[data-cart-remove-confirmation]");

        row.classList.toggle("is-remove-pending", isPending);

        if (isPending) {
            row.dataset.pendingRemoveQuantity = String(restoreQuantity || 1);
        } else {
            delete row.dataset.pendingRemoveQuantity;
        }

        if (quantityControl) {
            quantityControl.hidden = isPending;
        }

        if (removeConfirmation) {
            removeConfirmation.hidden = !isPending;
            removeConfirmation.querySelectorAll("[data-cart-remove-confirm]").forEach((button) => {
                button.disabled = false;
            });
        }
    }

    // Replace the cart table with the empty-state message after the last row is removed.
    function showEmptyCart() {
        const cartPage = document.querySelector(".cart-page");

        table.remove();

        if (!cartPage || cartPage.querySelector(".cart-empty")) {
            return;
        }

        const emptyMessage = document.createElement("p");
        emptyMessage.className = "cart-empty";
        emptyMessage.textContent = "your cart is empty.";
        cartPage.append(emptyMessage);
        document.body.classList.remove("cart-body--filled");
        document.body.classList.add("cart-body--empty");
    }

    // Update the visible quantity and line total from the JSON payload returned by PHP.
    function renderRowTotals(row, result) {
        const quantity = Number(result.quantity) || 1;
        const quantityTarget = row.querySelector("[data-cart-quantity]");
        const lineTotalTarget = row.querySelector("[data-cart-line-total]");

        if (quantityTarget) {
            setQuantityValue(quantityTarget, quantity);
        }

        if (lineTotalTarget && result.formatted_line_total) {
            lineTotalTarget.textContent = result.formatted_line_total;
        }

        if (Number.isFinite(Number(result.stock_quantity))) {
            row.dataset.stockAvailable = String(result.stock_quantity);
        }

        setPendingRemove(row, false);
        refreshDecreaseState(row, quantity);
    }

    async function updateQuantity(row, quantity) {
        const response = await fetch(endpoint, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: new URLSearchParams({
                product_id: row.dataset.productId,
                quantity: String(quantity)
            })
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || "unable to update cart.");
        }

        return result;
    }

    async function commitQuantityInput(quantityTarget) {
        const row = quantityTarget.closest("[data-cart-row]");

        if (!row || row.classList.contains("is-busy")) {
            return;
        }

        const previousQuantity = getStoredQuantity(quantityTarget);
        const rawQuantity = quantityTarget.value.trim();

        if (rawQuantity === "") {
            setQuantityValue(quantityTarget, previousQuantity);
            refreshDecreaseState(row, previousQuantity);
            return;
        }

        const nextQuantity = Math.min(Number.parseInt(rawQuantity, 10) || 0, 99);

        if (nextQuantity === 0) {
            setQuantityValue(quantityTarget, previousQuantity);
            setPendingRemove(row, true, previousQuantity);
            return;
        }

        if (nextQuantity === previousQuantity) {
            setQuantityValue(quantityTarget, previousQuantity);
            refreshDecreaseState(row, previousQuantity);
            return;
        }

        if (nextQuantity > getAvailableStock(row)) {
            setQuantityValue(quantityTarget, previousQuantity);
            refreshDecreaseState(row, previousQuantity);
            showCartMessage(`only ${getAvailableStock(row)} available.`, "error");
            return;
        }

        setRowBusy(row, true);

        try {
            const result = await updateQuantity(row, nextQuantity);

            renderRowTotals(row, result);
            updateCartBadge(result.cart_count);
            showCartMessage("", "");
        } catch (error) {
            console.error(error);
            showCartMessage(error.message || "unable to update cart.", "error");
            setQuantityValue(quantityTarget, previousQuantity);
            refreshDecreaseState(row, previousQuantity);
        } finally {
            setRowBusy(row, false);
            refreshDecreaseState(row, getQuantityValue(quantityTarget));
        }
    }

    table.addEventListener("focusin", (event) => {
        const quantityTarget = event.target.closest("[data-cart-quantity]");

        if (!quantityTarget) {
            return;
        }

        quantityTarget.dataset.previousQuantity = String(getQuantityValue(quantityTarget));
        quantityTarget.select();
    });

    table.addEventListener("input", (event) => {
        const quantityTarget = event.target.closest("[data-cart-quantity]");

        if (!quantityTarget) {
            return;
        }

        const digits = quantityTarget.value.replace(/\D/g, "");
        let nextValue = digits;

        if (digits.length > 2) {
            nextValue = String(Math.min(Number.parseInt(digits, 10) || 0, 99));
        }

        if (quantityTarget.value !== nextValue) {
            quantityTarget.value = nextValue;
        }
    });

    table.addEventListener("keydown", (event) => {
        const quantityTarget = event.target.closest("[data-cart-quantity]");

        if (!quantityTarget) {
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            quantityTarget.blur();
        }

        if (event.key === "Escape") {
            event.preventDefault();
            setQuantityValue(quantityTarget, getStoredQuantity(quantityTarget));
            quantityTarget.blur();
        }
    });

    table.addEventListener("change", (event) => {
        const quantityTarget = event.target.closest("[data-cart-quantity]");

        if (!quantityTarget) {
            return;
        }

        commitQuantityInput(quantityTarget);
    });

    // One delegated click handler covers all quantity buttons and confirmation buttons.
    table.addEventListener("click", async (event) => {
        const removeChoice = event.target.closest("[data-cart-remove-confirm]");

        // The remove confirmation is shown only after the visitor clicks minus at quantity 1.
        if (removeChoice) {
            const row = removeChoice.closest("[data-cart-row]");
            const quantityTarget = row ? row.querySelector("[data-cart-quantity]") : null;

            if (!row) {
                return;
            }

            // Choosing "no" cancels removal and restores the previous quantity without contacting PHP.
            if (removeChoice.dataset.cartRemoveConfirm === "no") {
                if (quantityTarget) {
                    setQuantityValue(quantityTarget, Number(row.dataset.pendingRemoveQuantity) || 1);
                }

                setPendingRemove(row, false);
                refreshDecreaseState(row, quantityTarget ? getQuantityValue(quantityTarget) : 1);
                return;
            }

            setRowBusy(row, true);

            try {
                // Confirmed removal is persisted server-side, then reflected in the DOM.
                const response = await fetch(endpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: new URLSearchParams({
                        action: "remove",
                        product_id: row.dataset.productId
                    })
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || "unable to remove cart item.");
                }

                row.remove();
                updateCartBadge(result.cart_count);

                // When the last row disappears, switch to the same empty state as PHP renders.
                if (!table.querySelector("[data-cart-row]")) {
                    showEmptyCart();
                }
            } catch (error) {
                console.error(error);
                showCartMessage(error.message || "unable to remove cart item.", "error");
                setRowBusy(row, false);
            }

            return;
        }

        // Quantity buttons are marked with data-cart-step values of -1 and 1.
        const button = event.target.closest("[data-cart-step]");

        if (!button) {
            return;
        }

        const row = button.closest("[data-cart-row]");
        const quantityTarget = row ? row.querySelector("[data-cart-quantity]") : null;

        if (!row || !quantityTarget || row.classList.contains("is-busy")) {
            return;
        }

        const currentQuantity = getQuantityValue(quantityTarget);
        const step = Number(button.dataset.cartStep) || 0;
        const nextQuantity = Math.max(0, currentQuantity + step);

        // Ignore no-op clicks, but still refresh the disabled state for consistency.
        if (nextQuantity === currentQuantity) {
            refreshDecreaseState(row, currentQuantity);
            return;
        }

        // Quantity zero is a soft state first; removal only happens after YES is clicked.
        if (nextQuantity === 0) {
            setPendingRemove(row, true, currentQuantity);
            return;
        }

        if (nextQuantity > getAvailableStock(row)) {
            refreshDecreaseState(row, currentQuantity);
            showCartMessage(`only ${getAvailableStock(row)} available.`, "error");
            return;
        }

        setRowBusy(row, true);

        try {
            // Send the new quantity to PHP so the session cart and price total stay canonical.
            const result = await updateQuantity(row, nextQuantity);

            renderRowTotals(row, result);
            updateCartBadge(result.cart_count);
            showCartMessage("", "");
        } catch (error) {
            // Keep the previous quantity visible if the server rejects or the request fails.
            console.error(error);
            showCartMessage(error.message || "unable to update cart.", "error");
            setQuantityValue(quantityTarget, currentQuantity);
            refreshDecreaseState(row, currentQuantity);
        } finally {
            // Always unlock the row and recalculate the minus button after the request settles.
            setRowBusy(row, false);
            refreshDecreaseState(row, getQuantityValue(quantityTarget));
        }
    });
})();
