(function () {
    // Cart page controller: keeps quantity buttons, remove confirmation and totals in sync.
    const table = document.querySelector("[data-cart-table]");
    const cartBadge = document.querySelector(".cart-badge");
    const endpoint = window.fixerupperUpdateCartEndpoint || "update_cart.php";

    // If the cart is empty, the table is not rendered and there is nothing to bind.
    if (!table) {
        return;
    }

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

        row.querySelectorAll("[data-cart-step], [data-cart-remove-confirm]").forEach((button) => {
            button.disabled = isBusy;
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
    function setPendingRemove(row, isPending) {
        const quantityControl = row.querySelector(".cart-quantity-control");
        const removeConfirmation = row.querySelector("[data-cart-remove-confirmation]");

        row.classList.toggle("is-remove-pending", isPending);

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
        emptyMessage.textContent = "Your cart is empty.";
        cartPage.append(emptyMessage);
    }

    // Update the visible quantity and line total from the JSON payload returned by PHP.
    function renderRowTotals(row, result) {
        const quantity = Number(result.quantity) || 1;
        const quantityTarget = row.querySelector("[data-cart-quantity]");
        const lineTotalTarget = row.querySelector("[data-cart-line-total]");

        if (quantityTarget) {
            quantityTarget.textContent = quantity;
        }

        if (lineTotalTarget && result.formatted_line_total) {
            lineTotalTarget.innerHTML = result.formatted_line_total;
        }

        setPendingRemove(row, false);
        refreshDecreaseState(row, quantity);
    }

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

            // Choosing "no" cancels removal and restores quantity 1 without contacting PHP.
            if (removeChoice.dataset.cartRemoveConfirm === "no") {
                if (quantityTarget) {
                    quantityTarget.textContent = "1";
                }

                setPendingRemove(row, false);
                refreshDecreaseState(row, 1);
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
                    throw new Error(result.message || "Unable to remove cart item.");
                }

                row.remove();
                updateCartBadge(result.cart_count);

                // When the last row disappears, switch to the same empty state as PHP renders.
                if (!table.querySelector("[data-cart-row]")) {
                    showEmptyCart();
                }
            } catch (error) {
                console.error(error);
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

        if (!row || !quantityTarget) {
            return;
        }

        const currentQuantity = Number(quantityTarget.textContent) || 1;
        const step = Number(button.dataset.cartStep) || 0;
        const nextQuantity = Math.max(0, currentQuantity + step);

        // Ignore no-op clicks, but still refresh the disabled state for consistency.
        if (nextQuantity === currentQuantity) {
            refreshDecreaseState(row, currentQuantity);
            return;
        }

        // Quantity zero is a soft state first; removal only happens after YES is clicked.
        if (nextQuantity === 0) {
            setPendingRemove(row, true);
            return;
        }

        setRowBusy(row, true);

        try {
            // Send the new quantity to PHP so the session cart and price total stay canonical.
            const response = await fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: new URLSearchParams({
                    product_id: row.dataset.productId,
                    quantity: String(nextQuantity)
                })
            });
            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || "Unable to update cart.");
            }

            renderRowTotals(row, result);
            updateCartBadge(result.cart_count);
        } catch (error) {
            // Keep the previous quantity visible if the server rejects or the request fails.
            console.error(error);
            refreshDecreaseState(row, currentQuantity);
        } finally {
            // Always unlock the row and recalculate the minus button after the request settles.
            setRowBusy(row, false);
            refreshDecreaseState(row, Number(quantityTarget.textContent) || 1);
        }
    });
})();
