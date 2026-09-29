/**
 * Shop → Producten (admin/products.php): grid or list.
 *
 * A view preference, not data: one card markup, drawn two ways by CSS
 * (data-product-view on the overview), remembered in this browser's
 * localStorage the way the Media Library remembers its view. No request and
 * no server setting. Without this script the grid stays and the switch stays
 * hidden; a browser that refuses storage still switches, it just forgets.
 */
(function () {
  "use strict";

  var STORAGE_KEY = "mygdala.products.view";

  var overview = document.querySelector("[data-product-overview]");
  var toggle = document.querySelector("[data-product-view-toggle]");
  if (!overview || !toggle) return;

  function storedView() {
    try {
      return window.localStorage.getItem(STORAGE_KEY) === "list" ? "list" : "grid";
    } catch (e) {
      return "grid";
    }
  }

  function applyView(view) {
    overview.setAttribute("data-product-view", view);
    Array.prototype.forEach.call(toggle.querySelectorAll("[data-product-view-option]"), function (option) {
      option.setAttribute("aria-pressed", option.getAttribute("data-product-view-option") === view ? "true" : "false");
    });
  }

  toggle.hidden = false;
  applyView(storedView());

  toggle.addEventListener("click", function (event) {
    var option = event.target.closest("[data-product-view-option]");
    if (!option) return;

    var view = option.getAttribute("data-product-view-option") === "list" ? "list" : "grid";
    applyView(view);

    try {
      window.localStorage.setItem(STORAGE_KEY, view);
    } catch (e) {
      // Not remembered for next time, but still shown now.
    }
  });
})();
