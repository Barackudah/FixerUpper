(function () {
    const form = document.querySelector("[data-checkout-confirm-form]");
    const button = form ? form.querySelector("[data-checkout-confirm-button]") : null;
    let isSubmitting = false;

    if (!form || !button) {
        return;
    }

    form.addEventListener("submit", (event) => {
        if (isSubmitting) {
            return;
        }

        event.preventDefault();
        isSubmitting = true;
        button.disabled = true;
        button.classList.add("is-submitting");

        window.setTimeout(() => {
            form.submit();
        }, 1420);
    });
})();
