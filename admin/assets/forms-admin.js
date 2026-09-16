/**
 * The small conveniences of the Forms screens. Everything here makes a
 * server-rendered screen quicker to use; nothing here is needed to use it.
 *
 * "VELD TOEVOEGEN" (admin/form.php). The opener is a link that renders the
 * form editor with the dialog already open, so without this file the dialog
 * simply appears in the page. With it, the same link opens the same <dialog>
 * as a modal: showModal() makes the page behind it inert, keeps Tab inside
 * and closes on Escape, like the confirmation dialog of the CMS
 * (ADMIN-UI.md). Annuleren, Escape and a click on the dimmed page around it
 * close it and put the focus back on the opener. Choosing a type and adding
 * the field is the dialog's own form, sent by the browser: this file never
 * posts, never picks anything and holds no text of its own.
 */
(function () {
  "use strict";

  var dialog = document.querySelector("[data-form-field-add]");
  if (!dialog || typeof dialog.showModal !== "function") return;

  var lastFocused = null;

  /**
   * The address without `add_field`, so a reload after closing does not
   * open the dialog again. Nothing else about the address changes.
   */
  function forgetOpenAddress() {
    try {
      var url = new URL(window.location.href);
      if (!url.searchParams.has("add_field")) return;

      url.searchParams.delete("add_field");
      url.hash = "";
      window.history.replaceState(null, "", url.toString());
    } catch (e) {
      // An address that cannot be rewritten only means a reload reopens it.
    }
  }

  /**
   * Called right away by every way of closing this file knows about, and by
   * the close event for any other: a browser may deliver that event late,
   * after the dialog was opened again. Doing it twice changes nothing.
   */
  function finish() {
    forgetOpenAddress();

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }

    lastFocused = null;
  }

  function open(opener) {
    lastFocused = opener;
    if (!dialog.open) dialog.showModal();
  }

  function close() {
    if (dialog.open) dialog.close();
    finish();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-form-field-add-open]"), function (opener) {
    opener.addEventListener("click", function (event) {
      event.preventDefault();
      open(opener);
    });
  });

  Array.prototype.forEach.call(dialog.querySelectorAll("[data-form-field-add-close]"), function (closer) {
    closer.addEventListener("click", function (event) {
      event.preventDefault();
      close();
    });
  });

  // The panel fills the dialog, so a click that lands on the dialog element
  // itself landed on the dimmed page around it.
  dialog.addEventListener("click", function (event) {
    if (event.target === dialog) close();
  });

  // Escape: the browser closes the dialog itself, right after this.
  dialog.addEventListener("cancel", finish);

  dialog.addEventListener("close", function () {
    if (!dialog.open) finish();
  });

  // Rendered open by the server (the link was followed before this file ran,
  // or the endpoint sent a refused add back): the same dialog, now modal.
  if (dialog.open) {
    lastFocused = document.querySelector("[data-form-field-add-open]");
    dialog.close();
    dialog.showModal();
  }
})();
