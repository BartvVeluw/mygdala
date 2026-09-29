/**
 * The product editor's Bestelvelden section (admin/_product_order_fields.php,
 * Shop Product & Ordering 2.0). Adding, moving and removing questions and
 * their choices is admin/assets/row-list.js; this file only keeps the screen
 * in step with two choices:
 *
 *   - "Bestelgegevens vragen" off hides the list of questions (it is still
 *     sent, and kept, so switching it on again brings them back);
 *   - a question's type decides what it shows: a maximum length for a text
 *     question, the list of choices for keuzerondjes and a dropdown, a
 *     maximum file size for an image question, none of these for a tick box.
 *     What a type does not show is still sent, and the server keeps only
 *     what belongs to the saved type.
 *
 * Owned by admin/product-form.php. Nothing here decides what is valid: the
 * server (App\Service\OrderFields\ProductOrderFieldEditor) checks every
 * question again. It runs again after a save redraws the region
 * (admin-editor:replaced) and when a row is added (row-list:added).
 */
(function () {
  "use strict";

  var TEXT_TYPES = ["text", "textarea"];
  var OPTION_TYPES = ["radio", "select"];
  var IMAGE_TYPES = ["image"];

  function applyRow(row) {
    var select = row.querySelector("[data-order-field-type]");
    var type = select ? select.value : "text";

    Array.prototype.forEach.call(row.querySelectorAll('[data-order-field-when="text"]'), function (el) {
      el.hidden = TEXT_TYPES.indexOf(type) === -1;
    });
    Array.prototype.forEach.call(row.querySelectorAll('[data-order-field-when="options"]'), function (el) {
      el.hidden = OPTION_TYPES.indexOf(type) === -1;
    });
    Array.prototype.forEach.call(row.querySelectorAll('[data-order-field-when="image"]'), function (el) {
      el.hidden = IMAGE_TYPES.indexOf(type) === -1;
    });
  }

  function applyAll() {
    var section = document.querySelector("[data-product-order-fields]");
    if (!section) return;

    var toggle = section.querySelector("[data-order-fields-toggle]");
    var body = section.querySelector("[data-order-fields-body]");
    if (toggle && body) body.hidden = !toggle.checked;

    var rows = section.querySelectorAll("[data-order-field-key]");
    Array.prototype.forEach.call(rows, applyRow);

    var empty = section.querySelector("[data-order-fields-empty]");
    if (empty) empty.hidden = rows.length > 0;
  }

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!target || !target.matches) return;
    if (target.matches("[data-order-fields-toggle]")) {
      applyAll();
    } else if (target.matches("[data-order-field-type]")) {
      var row = target.closest("[data-order-field-key]");
      if (row) applyRow(row);
    } else if (target.closest && target.closest("[data-product-order-fields]")) {
      // A row moved or removed (row-list.js dispatches a change on the list).
      applyAll();
    }
  });
  document.addEventListener("row-list:added", applyAll);
  document.addEventListener("admin-editor:replaced", applyAll);
  document.addEventListener("DOMContentLoaded", applyAll);
})();
