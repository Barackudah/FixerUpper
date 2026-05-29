(function () {
    const table = document.querySelector("[data-inventory-table]");

    if (!table) {
        return;
    }

    function normalizeQuantity(value) {
        const quantity = Number.parseInt(String(value).replace(/\D/g, ""), 10);

        return Math.min(Math.max(Number.isFinite(quantity) ? quantity : 0, 0), 999);
    }

    function getStoredQuantity(input) {
        const quantity = Number.parseInt(input.dataset.previousQuantity, 10);

        return Number.isFinite(quantity) && quantity >= 0 ? quantity : normalizeQuantity(input.value);
    }

    function getStockStatus(quantity) {
        if (quantity < 1) {
            return "out";
        }

        return quantity < 3 ? "low" : "in";
    }

    function updateStatus(input, quantity) {
        const row = input.closest(".inventory-row");
        const statusTarget = row ? row.querySelector(".inventory-status") : null;

        if (!statusTarget) {
            return;
        }

        const status = getStockStatus(quantity);
        const labels = {
            in: "in stock",
            low: "low stock",
            out: "out of stock"
        };

        statusTarget.textContent = labels[status];
        statusTarget.classList.remove("inventory-status--in", "inventory-status--low", "inventory-status--out");
        statusTarget.classList.add(`inventory-status--${status}`);
    }

    function setQuantity(input, quantity) {
        const normalizedQuantity = normalizeQuantity(quantity);

        input.value = String(normalizedQuantity);
        input.dataset.previousQuantity = String(normalizedQuantity);
        updateStatus(input, normalizedQuantity);
    }

    function setPendingRemove(cell, isPending, restoreQuantity) {
        const quantityControl = cell.querySelector(".inventory-quantity-control");
        const removeConfirmation = cell.querySelector("[data-inventory-remove-confirmation]");

        cell.classList.toggle("is-remove-pending", isPending);

        if (isPending) {
            cell.dataset.pendingRemoveQuantity = String(restoreQuantity);
        } else {
            delete cell.dataset.pendingRemoveQuantity;
        }

        if (quantityControl) {
            quantityControl.hidden = isPending;
        }

        if (removeConfirmation) {
            removeConfirmation.hidden = !isPending;
            removeConfirmation.querySelectorAll("[data-inventory-remove-confirm]").forEach((button) => {
                button.disabled = false;
            });
        }
    }

    function commitQuantityInput(input) {
        const cell = input.closest(".inventory-quantity-cell");
        const previousQuantity = getStoredQuantity(input);
        const rawQuantity = input.value.trim();

        if (rawQuantity === "") {
            setQuantity(input, previousQuantity);
            return;
        }

        const nextQuantity = normalizeQuantity(rawQuantity);

        if (nextQuantity === 0 && previousQuantity > 0) {
            setQuantity(input, previousQuantity);

            if (cell) {
                setPendingRemove(cell, true, previousQuantity);
            }

            return;
        }

        if (cell) {
            setPendingRemove(cell, false);
        }

        setQuantity(input, nextQuantity);
    }

    table.querySelectorAll("[data-inventory-quantity]").forEach((input) => {
        setQuantity(input, normalizeQuantity(input.value));
    });

    table.addEventListener("focusin", (event) => {
        const input = event.target.closest("[data-inventory-quantity]");

        if (!input) {
            return;
        }

        input.dataset.previousQuantity = String(normalizeQuantity(input.value));
        input.select();
    });

    table.addEventListener("click", (event) => {
        const removeChoice = event.target.closest("[data-inventory-remove-confirm]");

        if (removeChoice) {
            const cell = removeChoice.closest(".inventory-quantity-cell");
            const input = cell ? cell.querySelector("[data-inventory-quantity]") : null;

            if (!cell || !input) {
                return;
            }

            if (removeChoice.dataset.inventoryRemoveConfirm === "no") {
                setQuantity(input, Number(cell.dataset.pendingRemoveQuantity) || 1);
                setPendingRemove(cell, false);
                return;
            }

            setQuantity(input, 0);
            setPendingRemove(cell, false);
            return;
        }

        const button = event.target.closest("[data-inventory-step]");

        if (!button) {
            return;
        }

        const cell = button.closest(".inventory-quantity-cell");
        const control = button.closest(".inventory-quantity-control");
        const input = control ? control.querySelector("[data-inventory-quantity]") : null;

        if (!cell || !input) {
            return;
        }

        const currentQuantity = normalizeQuantity(input.value);
        const step = Number(button.dataset.inventoryStep) || 0;
        const nextQuantity = normalizeQuantity(currentQuantity + step);

        if (nextQuantity === currentQuantity) {
            return;
        }

        if (nextQuantity === 0 && currentQuantity > 0) {
            setPendingRemove(cell, true, currentQuantity);
            return;
        }

        setPendingRemove(cell, false);
        setQuantity(input, nextQuantity);
    });

    table.addEventListener("input", (event) => {
        const input = event.target.closest("[data-inventory-quantity]");

        if (!input) {
            return;
        }

        const digits = input.value.replace(/\D/g, "");
        input.value = digits.length > 3 ? String(normalizeQuantity(digits)) : digits;
    });

    table.addEventListener("keydown", (event) => {
        const input = event.target.closest("[data-inventory-quantity]");

        if (!input) {
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            input.blur();
        }

        if (event.key === "Escape") {
            event.preventDefault();
            setQuantity(input, getStoredQuantity(input));
            input.blur();
        }
    });

    table.addEventListener("change", (event) => {
        const input = event.target.closest("[data-inventory-quantity]");

        if (!input) {
            return;
        }

        commitQuantityInput(input);
    });
})();
