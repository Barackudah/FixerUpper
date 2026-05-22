(function () {
    const table = document.querySelector("[data-cart-table]");
    const cartBadge = document.querySelector(".cart-badge");
    const endpoint = window.fixerupperUpdateCartEndpoint || "update_cart.php";

    if (!table) {
        return;
    }

    function updateCartBadge(count) {
        if (!cartBadge) {
            return;
        }

        const cartCount = Number(count) || 0;
        cartBadge.textContent = cartCount > 0 ? cartCount : "";
    }

    function setRowBusy(row, isBusy) {
        row.classList.toggle("is-busy", isBusy);

        row.querySelectorAll("[data-cart-step], [data-cart-remove-confirm]").forEach((button) => {
            button.disabled = isBusy;
        });
    }

    function refreshDecreaseState(row, quantity) {
        const decreaseButton = row.querySelector("[data-cart-step='-1']");

        if (decreaseButton) {
            decreaseButton.disabled = quantity < 1;
        }
    }

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

    table.addEventListener("click", async (event) => {
        const removeChoice = event.target.closest("[data-cart-remove-confirm]");

        if (removeChoice) {
            const row = removeChoice.closest("[data-cart-row]");
            const quantityTarget = row ? row.querySelector("[data-cart-quantity]") : null;

            if (!row) {
                return;
            }

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

                if (!table.querySelector("[data-cart-row]")) {
                    showEmptyCart();
                }
            } catch (error) {
                console.error(error);
                setRowBusy(row, false);
            }

            return;
        }

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

        if (nextQuantity === currentQuantity) {
            refreshDecreaseState(row, currentQuantity);
            return;
        }

        if (nextQuantity === 0) {
            setPendingRemove(row, true);
            return;
        }

        setRowBusy(row, true);

        try {
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
            console.error(error);
            refreshDecreaseState(row, currentQuantity);
        } finally {
            setRowBusy(row, false);
            refreshDecreaseState(row, Number(quantityTarget.textContent) || 1);
        }
    });
})();
