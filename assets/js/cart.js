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

        row.querySelectorAll("[data-cart-step]").forEach((button) => {
            button.disabled = isBusy;
        });
    }

    function refreshDecreaseState(row, quantity) {
        const decreaseButton = row.querySelector("[data-cart-step='-1']");

        if (decreaseButton) {
            decreaseButton.disabled = quantity <= 1;
        }
    }

    function renderRowTotals(row, result) {
        const quantity = Number(result.quantity) || 1;
        const quantityTarget = row.querySelector("[data-cart-quantity]");
        const unitPriceTarget = row.querySelector("[data-cart-unit-price]");
        const multiplierTarget = row.querySelector("[data-cart-multiplier]");
        const lineTotalTarget = row.querySelector("[data-cart-line-total]");

        if (quantityTarget) {
            quantityTarget.textContent = quantity;
        }

        if (unitPriceTarget && result.formatted_unit_price) {
            unitPriceTarget.hidden = quantity <= 1;
            unitPriceTarget.innerHTML = result.formatted_unit_price;
        }

        if (multiplierTarget) {
            multiplierTarget.hidden = quantity <= 1;
            multiplierTarget.textContent = quantity > 1 ? `x ${quantity}` : "";
        }

        if (lineTotalTarget && result.formatted_line_total) {
            lineTotalTarget.innerHTML = result.formatted_line_total;
        }

        refreshDecreaseState(row, quantity);
    }

    table.addEventListener("click", async (event) => {
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
        const nextQuantity = Math.max(1, currentQuantity + step);

        if (nextQuantity === currentQuantity) {
            refreshDecreaseState(row, currentQuantity);
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
