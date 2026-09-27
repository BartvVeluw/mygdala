/**
 * The product editor's Voorraad switch (admin/_product_inventory.php, Shop
 * Product & Ordering 2.0): with "Voorraad bijhouden" on, every stock field
 * shows — the product's own, or one in each variant's row under Varianten —
 * and with it off they hide and the "unlimited" note shows instead.
 *
 * Owned by admin/product-form.php, which asks for it. Showing and hiding is
 * all it does: a hidden field is still sent as it was, and the server
 * (App\Service\Inventory\InventoryEditor, App\Service\ProductVariantEditor)
 * decides what a value means. Toggling the switch is an ordinary change, so
 * the editor's dirty state sees it through the form's own change event.
 *
 * It runs again after a save redraws a region (admin-editor:replaced) and
 * when a new variant row is added (row-list:added), so a row drawn by the
 * script follows the switch too.
 */
(function () {
  "use strict";

  function apply() {
    var toggle = document.querySelector("[data-stock-track]");
    if (!toggle) return;

    var tracked = toggle.checked;
    Array.prototype.forEach.call(document.querySelectorAll("[data-stock-tracked-only]"), function (el) {
      el.hidden = !tracked;
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-stock-untracked-only]"), function (el) {
      el.hidden = tracked;
    });
  }

  document.addEventListener("change", function (event) {
    if (event.target && event.target.matches && event.target.matches("[data-stock-track]")) apply();
  });
  document.addEventListener("admin-editor:replaced", apply);
  document.addEventListener("row-list:added", apply);
  document.addEventListener("DOMContentLoaded", apply);
})();
