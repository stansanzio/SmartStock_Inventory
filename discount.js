const Discount = (() => {
  const percent = 20;
  const typeSelect = document.getElementById("discount-type");
  const applyButton = document.getElementById("discount-button");
  let type = "";

  function getType() {
    return type;
  }

  function reset() {
    type = "";
    typeSelect.value = "";
  }

  function label(discountType = type, discountPercent = percent) {
    if (!discountType) return "Discount";
    const customerType = discountType === "pwd" ? "PWD" : "Senior Citizen";
    return `${customerType} discount (${discountPercent}%)`;
  }

  function calculate(subtotalCents, taxRatePercent) {
    const discountCents = type
      ? Math.round(subtotalCents * percent / 100)
      : 0;
    const taxCents = type ? 0 : Math.round(subtotalCents * taxRatePercent / 100);
    return { discountCents, taxCents };
  }

  function render({ formattedDiscount, itemCount, checkoutInProgress }) {
    document.getElementById("discount-total-row").hidden = !type;
    document.getElementById("discount-label").textContent = label();
    document.getElementById("discount-amount").textContent = `-${formattedDiscount}`;
    document.getElementById("tax-label").textContent = type ? "VAT exempt" : "Tax";
    document.getElementById("tax-rate-wrap").hidden = Boolean(type);
    typeSelect.disabled = checkoutInProgress || itemCount === 0 || Boolean(type);
    applyButton.disabled = checkoutInProgress || itemCount === 0 || (!type && !typeSelect.value);
    applyButton.textContent = type ? "Remove Discount" : "Apply Discount";
  }

  function initialize({ getCartSize, isCheckoutInProgress, setStatus, renderCart }) {
    typeSelect.addEventListener("change", () => {
      applyButton.disabled = isCheckoutInProgress() || getCartSize() === 0 || !typeSelect.value;
    });
    applyButton.addEventListener("click", () => {
      if (type) {
        reset();
        setStatus("Discount removed.");
      } else if (typeSelect.value) {
        type = typeSelect.value;
        setStatus(`${label()} applied. VAT is exempt.`);
      } else {
        return;
      }
      renderCart();
    });
  }

  return { calculate, getType, initialize, label, render, reset };
})();
