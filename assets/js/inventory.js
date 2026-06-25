(function () {
    const table = document.querySelector("[data-inventory-table]");
    const form = document.getElementById("inventory-form");
    const addButtons = [...document.querySelectorAll("[data-inventory-add-open]")];
    const addModal = document.getElementById("inventory-add-modal");
    const addForm = document.querySelector("[data-inventory-add-form]");
    const addNameInput = document.querySelector("[data-add-product-name]");
    const addSlugInput = document.querySelector("[data-add-product-slug]");
    const addPriceInput = document.querySelector("[data-add-product-price]");
    const addImagePathInput = document.querySelector("[data-add-product-image-path]");
    const addImageFileInput = document.querySelector("[data-add-product-image-file]");
    const addStockInput = document.querySelector("[data-add-product-stock]");
    const addSpecsContainer = document.querySelector("[data-add-product-specs]");
    const addProductIdInput = document.querySelector("[data-add-product-id]");
    const productFormActionInput = document.querySelector("[data-product-form-action]");
    const productCommandIdInput = document.querySelector("[data-product-command-id]");
    const addScrollPanel = document.querySelector("[data-inventory-add-scroll]");
    const addScrollbar = document.querySelector("[data-inventory-add-scrollbar]");
    const addScrollbarThumb = document.querySelector("[data-inventory-add-scrollbar-thumb]");
    const productDatabaseList = document.querySelector("[data-product-database-list]");
    const productDatabaseFrame = productDatabaseList ? productDatabaseList.closest(".inventory-database-frame") : null;
    const productDatabaseScrollbar = document.querySelector("[data-product-database-scrollbar]");
    const productDatabaseScrollbarThumb = document.querySelector("[data-product-database-scrollbar-thumb]");
    const productDatabaseRefresh = document.querySelector("[data-product-database-refresh]");
    const productDatabaseCount = document.querySelector("[data-product-database-count]");
    const productDatabaseSearch = document.querySelector("[data-product-database-search]");
    const systemMessage = document.querySelector("[data-system-message]");
    const textInputs = [...document.querySelectorAll(".inventory-text-field input")];
    const savedTextFieldsKey = "fixerupper.inventory.savedTextFields";
    const defaultSpecLabels = ["Operating System", "Processor", "Graphics", "Memory", "Storage", "Cooling", "Case"];
    let modalProducts = Array.isArray(window.fixerUpperInventoryProducts) ? window.fixerUpperInventoryProducts : [];
    let modalProductsById = new Map(modalProducts.map((product) => [String(product.id), product]));
    const minModalQuantity = 1;
    const maxModalQuantity = 999;
    const minAddScrollbarThumbHeight = 54;
    const minProductDatabaseThumbHeight = 54;
    let productDatabaseSort = { key: "id", direction: "asc" };
    let isDraggingAddScrollbar = false;
    let addScrollbarDragStartY = 0;
    let addScrollbarDragStartScrollTop = 0;
    let isDraggingProductDatabaseScrollbar = false;
    let productDatabaseDragStartY = 0;
    let productDatabaseDragStartScrollTop = 0;
    let lockedScrollY = 0;
    let lastFocusedElement = null;

    function slugify(value) {
        const cyrillicMap = {
            а: "a", б: "b", в: "v", г: "g", д: "d", е: "e", ё: "e",
            ж: "zh", з: "z", и: "i", й: "y", к: "k", л: "l", м: "m",
            н: "n", о: "o", п: "p", р: "r", с: "s", т: "t", у: "u",
            ф: "f", х: "h", ц: "ts", ч: "ch", ш: "sh", щ: "sch",
            ъ: "", ы: "y", ь: "", э: "e", ю: "yu", я: "ya"
        };

        return String(value)
            .trim()
            .toLowerCase()
            .replace(/[а-яё]/g, (letter) => cyrillicMap[letter] || "")
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/-{2,}/g, "-")
            .replace(/^-|-$/g, "")
            .slice(0, 50);
    }

    function normalizeModalQuantity(value) {
        const quantity = Number.parseInt(String(value).replace(/\D/g, ""), 10);

        return Math.min(Math.max(Number.isFinite(quantity) ? quantity : minModalQuantity, minModalQuantity), maxModalQuantity);
    }

    function updateAddStockButtons() {
        if (!addForm || !addStockInput) {
            return;
        }

        const quantity = normalizeModalQuantity(addStockInput.value);

        addForm.querySelectorAll('[data-add-product-stock-step="-1"]').forEach((button) => {
            button.disabled = quantity <= minModalQuantity;
        });
    }

    function setAddStock(quantity) {
        if (addStockInput) {
            addStockInput.value = String(normalizeModalQuantity(quantity));
            updateAddStockButtons();
        }
    }

    function updateAddModalScrollbar() {
        if (!addForm || !addScrollPanel || !addScrollbar || !addScrollbarThumb) {
            return;
        }

        const scrollRange = addScrollPanel.scrollHeight - addScrollPanel.clientHeight;
        const hasScroll = scrollRange > 1;

        addForm.classList.toggle("has-scroll", hasScroll);

        if (!hasScroll) {
            addScrollbarThumb.style.height = "";
            addScrollbarThumb.style.transform = "translateY(0)";
            return;
        }

        const trackHeight = addScrollbar.clientHeight;
        const thumbHeight = Math.max(
            minAddScrollbarThumbHeight,
            Math.round(trackHeight * (addScrollPanel.clientHeight / addScrollPanel.scrollHeight))
        );
        const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
        const thumbTop = scrollRange > 0 ? (addScrollPanel.scrollTop / scrollRange) * maxThumbTop : 0;

        addScrollbarThumb.style.height = `${thumbHeight}px`;
        addScrollbarThumb.style.transform = `translateY(${thumbTop}px)`;
    }

    function requestAddModalScrollbarUpdate() {
        window.requestAnimationFrame(updateAddModalScrollbar);
    }

    function updateProductDatabaseScrollbar() {
        if (!productDatabaseFrame || !productDatabaseList || !productDatabaseScrollbar || !productDatabaseScrollbarThumb) {
            return;
        }

        const scrollRange = productDatabaseList.scrollHeight - productDatabaseList.clientHeight;
        const hasScroll = scrollRange > 1;

        productDatabaseFrame.classList.toggle("has-scroll", hasScroll);

        if (!hasScroll) {
            productDatabaseScrollbarThumb.style.height = "";
            productDatabaseScrollbarThumb.style.transform = "translateY(0)";
            return;
        }

        const trackHeight = productDatabaseScrollbar.clientHeight;
        const thumbHeight = Math.max(
            minProductDatabaseThumbHeight,
            Math.round(trackHeight * (productDatabaseList.clientHeight / productDatabaseList.scrollHeight))
        );
        const maxThumbTop = Math.max(0, trackHeight - thumbHeight);
        const thumbTop = scrollRange > 0 ? (productDatabaseList.scrollTop / scrollRange) * maxThumbTop : 0;

        productDatabaseScrollbarThumb.style.height = `${thumbHeight}px`;
        productDatabaseScrollbarThumb.style.transform = `translateY(${thumbTop}px)`;
    }

    function requestProductDatabaseScrollbarUpdate() {
        window.requestAnimationFrame(updateProductDatabaseScrollbar);
    }

    function setProductFormMode(productId = "") {
        if (productFormActionInput) {
            productFormActionInput.value = "save_product";
        }

        if (productCommandIdInput) {
            productCommandIdInput.value = "";
        }

        if (addProductIdInput) {
            addProductIdInput.value = productId ? String(productId) : "";
        }

        const saveLabel = addForm ? addForm.querySelector(".inventory-add-modal__actions .inventory-save span:last-child") : null;

        if (saveLabel) {
            saveLabel.textContent = productId ? "update product" : "save product";
        }

        if (addForm) {
            addForm.classList.toggle("is-editing-product", Boolean(productId));
        }
    }

    function setActiveDatabaseRow(productId = "") {
        if (!productDatabaseList) {
            return;
        }

        productDatabaseList.querySelectorAll("[data-product-row]").forEach((row) => {
            row.classList.toggle("is-selected", productId !== "" && row.dataset.productId === String(productId));
        });
    }

    function formControl(name) {
        return addForm && addForm.elements ? addForm.elements[name] : null;
    }

    function fillProductForm(product) {
        if (!addForm || !product) {
            return;
        }

        addForm.reset();
        setProductFormMode(product.id);
        setActiveDatabaseRow(product.id);

        const fields = {
            "add_product[name]": product.name || "",
            "add_product[slug]": product.slug || "",
            "add_product[price]": product.price || "",
            "add_product[short_description]": product.short_description || "",
            "add_product[main_image]": product.main_image || "assets/images/pc_noimage.png",
            "add_product[location]": product.location || "Unassigned",
            "add_product[supplier]": product.supplier || ""
        };

        Object.entries(fields).forEach(([name, value]) => {
            const control = formControl(name);

            if (control) {
                control.value = value;
            }
        });

        const activeControl = formControl("add_product[is_active]");

        if (activeControl) {
            activeControl.checked = Boolean(product.is_active);
        }

        if (addImageFileInput) {
            addImageFileInput.value = "";
        }

        setAddStock(product.stock_quantity || 1);

        if (addSpecsContainer) {
            const specs = Array.isArray(product.specs) ? product.specs : [];
            const rows = specs.length > 0
                ? specs.map((spec) => createSpecRow(spec.label || "", spec.value || ""))
                : defaultSpecLabels.map((label) => createSpecRow(label, ""));

            addSpecsContainer.replaceChildren(...rows);
        }

        if (addScrollPanel) {
            addScrollPanel.scrollTop = 0;
        }

        requestAddModalScrollbarUpdate();

        if (addNameInput) {
            addNameInput.focus();
        }
    }

    function appendProductActionContent(button, iconSrc, labelText) {
        const icon = document.createElement("img");
        const label = document.createElement("span");

        icon.className = "inventory-database-action-icon";
        icon.src = iconSrc;
        icon.alt = "";
        icon.setAttribute("aria-hidden", "true");
        label.textContent = labelText;
        button.append(icon, label);
    }

    function createProductDatabaseRow(product) {
        const productId = String(product.id || "");
        const row = document.createElement("article");
        const mainButton = document.createElement("button");
        const id = document.createElement("span");
        const copy = document.createElement("span");
        const media = document.createElement("span");
        const image = document.createElement("img");
        const name = document.createElement("span");
        const slug = document.createElement("span");
        const stock = document.createElement("span");
        const actions = document.createElement("span");
        const editButton = document.createElement("button");
        const duplicateButton = document.createElement("button");
        const deleteButton = document.createElement("button");

        row.className = "inventory-database-row";
        row.dataset.productRow = "";
        row.dataset.productId = productId;
        row.dataset.id = productId;
        row.dataset.name = String(product.name || "").toLowerCase();
        row.dataset.stock = String(product.stock_quantity || 0);
        row.dataset.search = [
            productId,
            product.name || "",
            product.slug || "",
            product.stock_quantity || 0
        ].join(" ").toLowerCase();

        mainButton.className = "inventory-database-main";
        mainButton.type = "button";
        mainButton.dataset.productSelect = productId;

        id.className = "inventory-database-id";
        id.textContent = productId;

        copy.className = "inventory-database-copy";
        media.className = "inventory-database-media";
        media.setAttribute("aria-hidden", "true");
        image.src = product.main_image || "assets/images/pc_noimage.png";
        image.alt = "";
        image.onerror = function () {
            this.onerror = null;
            this.src = "assets/images/pc_1.png";
        };
        name.className = "inventory-database-name";
        name.textContent = product.name || "";
        slug.className = "inventory-database-meta";
        slug.textContent = product.slug || "";

        stock.className = "inventory-database-stock";
        stock.textContent = String(product.stock_quantity || 0);

        actions.className = "inventory-database-actions";
        editButton.className = "inventory-modal-mini-button";
        editButton.type = "button";
        editButton.dataset.productEdit = productId;
        appendProductActionContent(editButton, "assets/images/pencil.png", "Edit");

        duplicateButton.className = "inventory-modal-mini-button";
        duplicateButton.type = "submit";
        duplicateButton.formNoValidate = true;
        duplicateButton.dataset.productCommandAction = "duplicate_product";
        duplicateButton.dataset.productCommandProduct = productId;
        appendProductActionContent(duplicateButton, "assets/images/clone.png", "Duplicate");

        deleteButton.className = "inventory-modal-mini-button inventory-modal-mini-button--danger";
        deleteButton.type = "submit";
        deleteButton.formNoValidate = true;
        deleteButton.dataset.productCommandAction = "delete_product";
        deleteButton.dataset.productCommandProduct = productId;
        appendProductActionContent(deleteButton, "assets/images/trash.png", "Delete");

        media.append(image);
        copy.append(media, name, slug);
        mainButton.append(id, copy, stock);
        actions.append(editButton, duplicateButton, deleteButton);
        row.append(mainButton, actions);

        return row;
    }

    function renderProductDatabaseRows(products, selectedProductId = "") {
        if (!productDatabaseList) {
            return;
        }

        productDatabaseList.replaceChildren(...products.map((product) => createProductDatabaseRow(product)));
        setActiveDatabaseRow(modalProductsById.has(String(selectedProductId)) ? selectedProductId : "");
        applyProductDatabaseSearch();
        requestProductDatabaseScrollbarUpdate();
    }

    function applyProductDatabaseSearch({ resetScroll = false } = {}) {
        if (!productDatabaseList) {
            return;
        }

        const queryParts = productDatabaseSearch
            ? productDatabaseSearch.value.trim().toLowerCase().split(/\s+/).filter(Boolean)
            : [];

        productDatabaseList.querySelectorAll("[data-product-row]").forEach((row) => {
            const searchText = row.dataset.search || "";
            const isMatch = queryParts.length === 0 || queryParts.every((part) => searchText.includes(part));
            row.style.display = isMatch ? "" : "none";

            if (!isMatch) {
                row.classList.remove("is-selected");
            }
        });

        if (resetScroll) {
            productDatabaseList.scrollTop = 0;
        }

        requestProductDatabaseScrollbarUpdate();
    }

    async function refreshProductDatabase() {
        if (!productDatabaseList) {
            return;
        }

        const selectedRow = productDatabaseList.querySelector(".is-selected[data-product-row]");
        const selectedProductId = selectedRow ? selectedRow.dataset.productId : "";

        if (productDatabaseRefresh) {
            productDatabaseRefresh.disabled = true;
        }

        try {
            const response = await window.fetch("inventory.php?inventory_action=product_database_json", {
                headers: { Accept: "application/json" },
                cache: "no-store"
            });

            if (!response.ok) {
                throw new Error("Unable to refresh product database.");
            }

            const data = await response.json();
            modalProducts = Array.isArray(data.products) ? data.products : [];
            modalProductsById = new Map(modalProducts.map((product) => [String(product.id), product]));
            productDatabaseSort = { key: "id", direction: "asc" };

            if (productDatabaseCount) {
                productDatabaseCount.textContent = `loaded ${modalProducts.length} active products`;
            }

            renderProductDatabaseRows(modalProducts, selectedProductId);
        } catch (error) {
            window.location.href = "inventory.php";
        } finally {
            if (productDatabaseRefresh) {
                productDatabaseRefresh.disabled = false;
            }
        }
    }

    function sortProductDatabaseRows(key) {
        if (!productDatabaseList) {
            return;
        }

        const direction = productDatabaseSort.key === key && productDatabaseSort.direction === "asc" ? "desc" : "asc";
        const multiplier = direction === "asc" ? 1 : -1;
        const rows = [...productDatabaseList.querySelectorAll("[data-product-row]")];

        rows.sort((first, second) => {
            const firstValue = key === "name" ? first.dataset.name || "" : Number(first.dataset[key] || 0);
            const secondValue = key === "name" ? second.dataset.name || "" : Number(second.dataset[key] || 0);

            if (firstValue < secondValue) {
                return -1 * multiplier;
            }

            if (firstValue > secondValue) {
                return 1 * multiplier;
            }

            return 0;
        });

        productDatabaseSort = { key, direction };
        rows.forEach((row) => productDatabaseList.appendChild(row));
        requestProductDatabaseScrollbarUpdate();
    }

    function createSpecRow(label = "", value = "") {
        const row = document.createElement("div");
        const labelInput = document.createElement("input");
        const valueInput = document.createElement("input");
        const removeButton = document.createElement("button");

        row.className = "inventory-modal-spec-row";
        labelInput.type = "text";
        labelInput.name = "add_product[spec_label][]";
        labelInput.maxLength = 80;
        labelInput.value = label;
        labelInput.setAttribute("aria-label", "Spec label");
        valueInput.type = "text";
        valueInput.name = "add_product[spec_value][]";
        valueInput.value = value;
        valueInput.setAttribute("aria-label", "Spec value");
        removeButton.className = "inventory-modal-remove-spec";
        removeButton.type = "button";
        removeButton.dataset.addProductRemoveSpec = "";
        removeButton.setAttribute("aria-label", "Remove spec row");
        removeButton.textContent = "Remove";

        row.append(labelInput, valueInput, removeButton);

        return row;
    }

    function resetSpecRows() {
        if (!addSpecsContainer) {
            return;
        }

        addSpecsContainer.replaceChildren(...defaultSpecLabels.map((label) => createSpecRow(label, "")));
    }

    function resetAddForm() {
        if (!addForm) {
            return;
        }

        addForm.reset();
        setProductFormMode("");
        setActiveDatabaseRow("");
        resetSpecRows();
        setAddStock(1);

        if (addImagePathInput) {
            addImagePathInput.value = "assets/images/pc_noimage.png";
        }

        if (addImageFileInput) {
            addImageFileInput.value = "";
        }
    }

    function fillSlugFromName() {
        if (!addSlugInput || !addNameInput) {
            return;
        }

        addSlugInput.value = slugify(addNameInput.value);
    }

    function cleanPriceInput(input) {
        const cleaned = input.value.replace(/[^0-9.]/g, "");
        const parts = cleaned.split(".");
        input.value = parts.length > 1 ? `${parts.shift()}.${parts.join("").slice(0, 2)}` : cleaned;
    }

    function lockPageScroll() {
        lockedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
        document.documentElement.classList.add("modal-open");
        document.body.classList.add("modal-open");
    }

    function unlockPageScroll() {
        document.documentElement.classList.remove("modal-open");
        document.body.classList.remove("modal-open");
        window.scrollTo(0, lockedScrollY);
    }

    function openAddModal() {
        if (!addModal || addModal.classList.contains("is-open")) {
            return;
        }

        lastFocusedElement = document.activeElement;
        addModal.classList.add("is-open");
        addModal.setAttribute("aria-hidden", "false");
        lockPageScroll();
        requestAddModalScrollbarUpdate();
        requestProductDatabaseScrollbarUpdate();

        const focusTarget = addModal.querySelector("[data-add-product-name]") || addModal.querySelector(".inventory-add-modal__dialog");

        if (focusTarget) {
            focusTarget.focus();
        }
    }

    function closeAddModal() {
        if (!addModal || !addModal.classList.contains("is-open")) {
            return;
        }

        addModal.classList.remove("is-open");
        addModal.setAttribute("aria-hidden", "true");
        addForm && addForm.classList.remove("has-scroll");
        productDatabaseFrame && productDatabaseFrame.classList.remove("has-scroll");
        unlockPageScroll();

        if (lastFocusedElement && typeof lastFocusedElement.focus === "function") {
            lastFocusedElement.focus();
        }

        lastFocusedElement = null;
    }

    if (addButtons.length > 0 && addModal) {
        addButtons.forEach((addButton) => {
            addButton.addEventListener("click", (event) => {
                event.preventDefault();
                openAddModal();
            });
        });

        addModal.addEventListener("click", (event) => {
            if (event.target.closest("[data-inventory-add-close]")) {
                closeAddModal();
            }
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeAddModal();
            }
        });
    }

    if (addForm) {
        const slugButton = addForm.querySelector("[data-add-product-generate-slug]");
        const placeholderButton = addForm.querySelector("[data-add-product-placeholder]");
        const addSpecButton = addForm.querySelector("[data-add-product-add-spec]");
        const clearButton = addForm.querySelector("[data-add-product-clear]");

        if (slugButton) {
            slugButton.addEventListener("click", fillSlugFromName);
        }

        if (addNameInput) {
            addNameInput.addEventListener("input", () => {
                if (addSlugInput && !addSlugInput.value.trim()) {
                    fillSlugFromName();
                }
            });
        }

        if (addSlugInput) {
            addSlugInput.addEventListener("input", () => {
                addSlugInput.value = slugify(addSlugInput.value);
            });
        }

        if (addPriceInput) {
            addPriceInput.addEventListener("input", () => cleanPriceInput(addPriceInput));
        }

        if (addImageFileInput && addImagePathInput) {
            addImageFileInput.addEventListener("change", () => {
                const file = addImageFileInput.files && addImageFileInput.files[0];

                if (!file) {
                    return;
                }

                const extension = file.name.includes(".") ? file.name.split(".").pop().toLowerCase() : "png";
                const baseSlug = slugify((addSlugInput && addSlugInput.value) || (addNameInput && addNameInput.value) || file.name) || "product";
                addImagePathInput.value = `assets/images/${baseSlug}.${extension}`;
            });
        }

        if (placeholderButton && addImagePathInput) {
            placeholderButton.addEventListener("click", () => {
                addImagePathInput.value = "assets/images/pc_noimage.png";

                if (addImageFileInput) {
                    addImageFileInput.value = "";
                }
            });
        }

        if (addStockInput) {
            setAddStock(addStockInput.value);
            addStockInput.addEventListener("input", () => {
                const digits = addStockInput.value.replace(/\D/g, "");
                if (digits === "") {
                    addStockInput.value = "";
                    updateAddStockButtons();
                    return;
                }

                setAddStock(digits);
            });
            addStockInput.addEventListener("change", () => setAddStock(addStockInput.value));
        }

        addForm.addEventListener("click", (event) => {
            const selectButton = event.target.closest("[data-product-select]");
            const editButton = event.target.closest("[data-product-edit]");
            const commandButton = event.target.closest("[data-product-command-action]");
            const sortButton = event.target.closest("[data-product-sort]");
            const saveProductButton = event.target.closest(".inventory-add-modal__actions .inventory-save");
            const stockButton = event.target.closest("[data-add-product-stock-step]");
            const removeSpecButton = event.target.closest("[data-add-product-remove-spec]");

            if (saveProductButton) {
                if (productFormActionInput) {
                    productFormActionInput.value = "save_product";
                }

                if (productCommandIdInput) {
                    productCommandIdInput.value = "";
                }
            }

            if (selectButton) {
                event.preventDefault();
                const productId = selectButton.dataset.productSelect || "";
                const row = selectButton.closest("[data-product-row]");
                const shouldCollapse = row && row.classList.contains("is-selected");
                setActiveDatabaseRow(shouldCollapse ? "" : productId);
                requestProductDatabaseScrollbarUpdate();
                return;
            }

            if (editButton) {
                event.preventDefault();
                const product = modalProductsById.get(String(editButton.dataset.productEdit));
                fillProductForm(product);
                return;
            }

            if (commandButton) {
                const productId = commandButton.dataset.productCommandProduct || "";
                const action = commandButton.dataset.productCommandAction || "";
                const product = modalProductsById.get(String(productId));
                const productName = product && product.name ? product.name : `product ${productId}`;
                const prompt = action === "delete_product"
                    ? `Delete "${productName}" from the storefront?`
                    : `Create a copy of "${productName}"?`;

                if (!window.confirm(prompt)) {
                    event.preventDefault();
                    return;
                }

                if (productFormActionInput) {
                    productFormActionInput.value = action;
                }

                if (productCommandIdInput) {
                    productCommandIdInput.value = productId;
                }

                return;
            }

            if (sortButton) {
                event.preventDefault();
                sortProductDatabaseRows(sortButton.dataset.productSort || "id");
                return;
            }

            if (stockButton && addStockInput) {
                const step = Number(stockButton.dataset.addProductStockStep) || 0;
                setAddStock(normalizeModalQuantity(addStockInput.value) + step);
                return;
            }

            if (removeSpecButton) {
                const row = removeSpecButton.closest(".inventory-modal-spec-row");

                if (row) {
                    row.remove();
                    requestAddModalScrollbarUpdate();
                }
            }
        });

        if (addSpecButton && addSpecsContainer) {
            addSpecButton.addEventListener("click", () => {
                addSpecsContainer.appendChild(createSpecRow());
                requestAddModalScrollbarUpdate();
            });
        }

        if (clearButton) {
            clearButton.addEventListener("click", () => {
                resetAddForm();
                requestAddModalScrollbarUpdate();
            });
        }

        if (productDatabaseRefresh) {
            productDatabaseRefresh.addEventListener("click", () => {
                refreshProductDatabase();
            });
        }

        if (productDatabaseSearch) {
            const updateProductDatabaseSearch = () => {
                applyProductDatabaseSearch({ resetScroll: true });
            };

            productDatabaseSearch.addEventListener("input", updateProductDatabaseSearch);
            productDatabaseSearch.addEventListener("search", updateProductDatabaseSearch);
        }

        if (productDatabaseList) {
            productDatabaseList.addEventListener("scroll", updateProductDatabaseScrollbar);
        }

        if (productDatabaseScrollbar && productDatabaseScrollbarThumb && productDatabaseList && productDatabaseFrame) {
            productDatabaseScrollbar.addEventListener("pointerdown", (event) => {
                if (!productDatabaseFrame.classList.contains("has-scroll")) {
                    return;
                }

                const clickedThumb = event.target === productDatabaseScrollbarThumb;

                if (!clickedThumb) {
                    const trackRect = productDatabaseScrollbar.getBoundingClientRect();
                    const thumbHeight = productDatabaseScrollbarThumb.offsetHeight;
                    const maxThumbTop = Math.max(0, trackRect.height - thumbHeight);
                    const nextThumbTop = Math.min(
                        maxThumbTop,
                        Math.max(0, event.clientY - trackRect.top - thumbHeight / 2)
                    );
                    const scrollRange = productDatabaseList.scrollHeight - productDatabaseList.clientHeight;
                    productDatabaseList.scrollTop = maxThumbTop > 0 ? (nextThumbTop / maxThumbTop) * scrollRange : 0;
                }

                isDraggingProductDatabaseScrollbar = true;
                productDatabaseDragStartY = event.clientY;
                productDatabaseDragStartScrollTop = productDatabaseList.scrollTop;
                productDatabaseScrollbar.setPointerCapture(event.pointerId);
                event.preventDefault();
            });

            productDatabaseScrollbar.addEventListener("pointermove", (event) => {
                if (!isDraggingProductDatabaseScrollbar) {
                    return;
                }

                const trackHeight = productDatabaseScrollbar.clientHeight;
                const thumbHeight = productDatabaseScrollbarThumb.offsetHeight;
                const maxThumbTop = Math.max(1, trackHeight - thumbHeight);
                const scrollRange = productDatabaseList.scrollHeight - productDatabaseList.clientHeight;
                const scrollPerPixel = scrollRange / maxThumbTop;

                productDatabaseList.scrollTop = productDatabaseDragStartScrollTop + (event.clientY - productDatabaseDragStartY) * scrollPerPixel;
            });

            productDatabaseScrollbar.addEventListener("pointerup", (event) => {
                isDraggingProductDatabaseScrollbar = false;
                productDatabaseScrollbar.releasePointerCapture(event.pointerId);
            });

            productDatabaseScrollbar.addEventListener("pointercancel", (event) => {
                isDraggingProductDatabaseScrollbar = false;
                productDatabaseScrollbar.releasePointerCapture(event.pointerId);
            });
        }

        if (addScrollPanel) {
            addScrollPanel.addEventListener("scroll", updateAddModalScrollbar);
        }

        if (addScrollbar && addScrollbarThumb && addScrollPanel) {
            addScrollbar.addEventListener("pointerdown", (event) => {
                if (!addForm.classList.contains("has-scroll")) {
                    return;
                }

                const clickedThumb = event.target === addScrollbarThumb;

                if (!clickedThumb) {
                    const trackRect = addScrollbar.getBoundingClientRect();
                    const thumbHeight = addScrollbarThumb.offsetHeight;
                    const maxThumbTop = Math.max(0, trackRect.height - thumbHeight);
                    const nextThumbTop = Math.min(
                        maxThumbTop,
                        Math.max(0, event.clientY - trackRect.top - thumbHeight / 2)
                    );
                    const scrollRange = addScrollPanel.scrollHeight - addScrollPanel.clientHeight;
                    addScrollPanel.scrollTop = maxThumbTop > 0 ? (nextThumbTop / maxThumbTop) * scrollRange : 0;
                }

                isDraggingAddScrollbar = true;
                addScrollbarDragStartY = event.clientY;
                addScrollbarDragStartScrollTop = addScrollPanel.scrollTop;
                addScrollbar.setPointerCapture(event.pointerId);
                event.preventDefault();
            });

            addScrollbar.addEventListener("pointermove", (event) => {
                if (!isDraggingAddScrollbar) {
                    return;
                }

                const trackHeight = addScrollbar.clientHeight;
                const thumbHeight = addScrollbarThumb.offsetHeight;
                const maxThumbTop = Math.max(1, trackHeight - thumbHeight);
                const scrollRange = addScrollPanel.scrollHeight - addScrollPanel.clientHeight;
                const scrollPerPixel = scrollRange / maxThumbTop;

                addScrollPanel.scrollTop = addScrollbarDragStartScrollTop + (event.clientY - addScrollbarDragStartY) * scrollPerPixel;
            });

            addScrollbar.addEventListener("pointerup", (event) => {
                isDraggingAddScrollbar = false;
                addScrollbar.releasePointerCapture(event.pointerId);
            });

            addScrollbar.addEventListener("pointercancel", (event) => {
                isDraggingAddScrollbar = false;
                addScrollbar.releasePointerCapture(event.pointerId);
            });
        }

        addForm.addEventListener("submit", () => {
            if (productFormActionInput && productFormActionInput.value !== "save_product") {
                return;
            }

            if (addSlugInput && !addSlugInput.value.trim()) {
                fillSlugFromName();
            }

            if (addPriceInput) {
                cleanPriceInput(addPriceInput);
            }

            setAddStock(addStockInput ? addStockInput.value : 1);
        });

        const pendingEditProductId = window.fixerUpperInventoryEditProductId ? String(window.fixerUpperInventoryEditProductId) : "";

        if (pendingEditProductId && modalProductsById.has(pendingEditProductId)) {
            window.requestAnimationFrame(() => {
                openAddModal();
                fillProductForm(modalProductsById.get(pendingEditProductId));
            });
        }
    }

    window.addEventListener("resize", () => {
        if (addModal && addModal.classList.contains("is-open")) {
            requestAddModalScrollbarUpdate();
            requestProductDatabaseScrollbarUpdate();
        }
    });

    function readSavedTextFieldNames() {
        try {
            return JSON.parse(window.sessionStorage.getItem(savedTextFieldsKey) || "[]");
        } catch (error) {
            return [];
        }
    }

    function clearSavedTextFieldNames() {
        try {
            window.sessionStorage.removeItem(savedTextFieldsKey);
        } catch (error) {
            // Ignore storage errors; the visual state still works while editing.
        }
    }

    function storeChangedTextFieldNames() {
        const changedFieldNames = textInputs
            .filter((input) => input.value !== input.dataset.initialValue)
            .map((input) => input.name);

        try {
            if (changedFieldNames.length > 0) {
                window.sessionStorage.setItem(savedTextFieldsKey, JSON.stringify(changedFieldNames));
            } else {
                window.sessionStorage.removeItem(savedTextFieldsKey);
            }
        } catch (error) {
            // Ignore storage errors; saving inventory should not depend on browser storage.
        }
    }

    function refreshTextInputState(input) {
        input.classList.toggle("is-edited", input.value !== input.dataset.initialValue);
    }

    textInputs.forEach((input) => {
        input.dataset.initialValue = input.value;
        input.addEventListener("input", () => refreshTextInputState(input));
    });

    if (form) {
        form.addEventListener("submit", storeChangedTextFieldNames);
    }

    if (systemMessage) {
        const savedTextFieldNames = readSavedTextFieldNames();
        const settlingTextInputs = textInputs.filter((input) => savedTextFieldNames.includes(input.name));

        clearSavedTextFieldNames();

        systemMessage.textContent = systemMessage.textContent.toLocaleLowerCase("en-US");
        settlingTextInputs.forEach((input) => {
            input.classList.add("is-save-settling");
        });

        window.setTimeout(() => {
            systemMessage.classList.add("is-hiding");
            settlingTextInputs.forEach((input) => {
                input.classList.remove("is-save-settling", "is-edited");
            });
        }, 3000);

        window.setTimeout(() => {
            systemMessage.textContent = "";
            systemMessage.classList.remove("is-visible", "is-hiding");
            systemMessage.hidden = true;
        }, 3600);
    }

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

    function setQuantity(input, quantity) {
        const normalizedQuantity = normalizeQuantity(quantity);

        input.value = String(normalizedQuantity);
        input.dataset.previousQuantity = String(normalizedQuantity);
    }

    function setRemoveFlag(cell, shouldRemove) {
        const row = cell.closest(".inventory-row");
        const removeFlag = row ? row.querySelector("[data-inventory-remove-flag]") : null;

        if (!row || !removeFlag) {
            return;
        }

        row.classList.toggle("is-remove-marked", shouldRemove);
        removeFlag.value = shouldRemove ? "1" : "0";
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
            setRemoveFlag(cell, false);
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
                setQuantity(input, Number(cell.dataset.pendingRemoveQuantity) || 0);
                setRemoveFlag(cell, false);
                setPendingRemove(cell, false);
                return;
            }

            setQuantity(input, 0);
            setRemoveFlag(cell, true);
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

        if (currentQuantity === 0 && step < 0) {
            setPendingRemove(cell, true, currentQuantity);
            return;
        }

        if (nextQuantity === currentQuantity) {
            return;
        }

        if (nextQuantity === 0 && currentQuantity > 0) {
            setPendingRemove(cell, true, currentQuantity);
            return;
        }

        setRemoveFlag(cell, false);
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
