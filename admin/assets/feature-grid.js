/*
 * The icon choice of a card in the Kenmerken in kaartjes editor
 * (admin/feature-grid.php): the picker for a custom icon is only in view
 * while "Eigen icoon uit Iconen" is the choice, and choosing or uploading an
 * SVG in that picker makes it the choice.
 *
 * Delegated from the document, because row-list.js adds cards after load.
 * Without this script both controls simply stay visible, and the endpoint
 * decides on the select alone (api/admin/update-feature-grid.php).
 */
(function () {
  "use strict";

  var CUSTOM = "custom";

  function customFieldOf(select) {
    var row = select.closest("[data-feature-icon]");
    return row ? row.querySelector("[data-feature-icon-custom]") : null;
  }

  function sync(select) {
    var custom = customFieldOf(select);
    if (custom) {
      custom.hidden = select.value !== CUSTOM;
    }
  }

  function syncAll(root) {
    Array.prototype.forEach.call(root.querySelectorAll("select[data-feature-icon-select]"), sync);
  }

  document.addEventListener("change", function (event) {
    var target = event.target;

    if (target.matches && target.matches("select[data-feature-icon-select]")) {
      sync(target);
      return;
    }

    // The picker's hidden input changes when an icon is chosen or uploaded.
    if (target.matches && target.matches("[data-feature-icon-custom] [data-media-picker-input]") && target.value !== "") {
      var row = target.closest("[data-feature-icon]");
      var select = row ? row.querySelector("select[data-feature-icon-select]") : null;
      if (select && select.value !== CUSTOM) {
        select.value = CUSTOM;
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
    }

    // A card added by row-list.js.
    if (target.matches && target.matches("[data-row-list]")) {
      syncAll(target);
    }
  });

  syncAll(document);
})();
