/*
 * Shop-instellingen: "Herstel standaardtekst" on the order confirmation e-mail.
 *
 * OWNER. admin/shop-settings.php, the only screen that loads it.
 *
 * WHAT IT DOES. A click asks for confirmation, then puts each field's
 * standard text into the field: the data-default-value the server wrote from
 * App\Mail\OrderConfirmationBuilder::defaultCopy(), the one copy of that text
 * in the code. Nothing is saved. The owner reads the text in the fields and
 * presses Opslaan, or leaves the page and keeps what was stored.
 *
 * WHAT IS NOT HERE. No request, no form submit, no copy of the standard text,
 * and no sentence an editor reads: the question and the confirmation come
 * from data attributes the server filled from the catalog. Without this
 * script the button stays hidden, and an emptied field that is saved still
 * falls back on its standard text (App\Service\SiteSettings).
 */
(function () {
  "use strict";

  function restore(button) {
    var form = button.closest("form");
    if (!form) return;

    var question = button.getAttribute("data-restore-defaults-confirm") || "";
    if (question !== "" && !window.confirm(question)) return;

    var fields = form.querySelectorAll("[data-default-value]");

    Array.prototype.forEach.call(fields, function (field) {
      var standard = field.getAttribute("data-default-value") || "";
      if (field.value === standard) return;

      field.value = standard;
      // The save bar (admin/assets/save-bar.js) hears these and marks the
      // form as holding unsaved changes until Opslaan.
      field.dispatchEvent(new Event("input", { bubbles: true }));
      field.dispatchEvent(new Event("change", { bubbles: true }));
    });

    var status = form.querySelector("[data-restore-defaults-status]");
    if (status) status.textContent = button.getAttribute("data-restore-defaults-done") || "";

    if (fields.length > 0) fields[0].focus();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-restore-defaults]"), function (button) {
    button.hidden = false;
    button.addEventListener("click", function () {
      restore(button);
    });
  });
})();
