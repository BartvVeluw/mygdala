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

/**
 * OPTION ROWS (admin/form-field.php). The server renders every stored option
 * as a row plus three empty rows, which is how options are added without
 * this file. With it, "Optie toevoegen" appends another empty row and each
 * row gets a remove button. A new row is a copy of the last one with its
 * values cleared and a fresh index in its names; the index is what ties a
 * row's two languages and its "Standaard" radio together
 * (api/admin/update-form-field.php), so rows are never renumbered on the
 * server. Only the visible numbers and the numbers in the accessible names
 * are counted again, from words the server already wrote.
 *
 * Removing the row that was the default puts the choice back on "no
 * default", so the form never sends a default for a row that is gone.
 */
(function () {
  "use strict";

  Array.prototype.forEach.call(document.querySelectorAll("[data-form-options]"), function (group) {
    var list = group.querySelector("[data-form-option-list]");
    var add = group.querySelector("[data-form-option-add]");
    if (!list || !add) return;

    var max = parseInt(group.getAttribute("data-form-options-max") || "50", 10);
    var noDefault = group.querySelector('input[name="default_option"][value=""]');

    function rows() {
      return list.querySelectorAll("[data-form-option-row]");
    }

    function renumber() {
      Array.prototype.forEach.call(rows(), function (row, position) {
        var number = String(position + 1);
        var badge = row.querySelector("[data-form-option-number]");
        if (badge) badge.textContent = number;

        Array.prototype.forEach.call(row.querySelectorAll("[aria-label]"), function (element) {
          element.setAttribute("aria-label", element.getAttribute("aria-label").replace(/\d+/, number));
        });
      });

      add.disabled = rows().length >= max;
    }

    function nextIndex() {
      var highest = -1;

      Array.prototype.forEach.call(list.querySelectorAll('input[name^="option_nl["]'), function (input) {
        var match = /\[(\d+)\]/.exec(input.name);
        if (match) highest = Math.max(highest, parseInt(match[1], 10));
      });

      return String(highest + 1);
    }

    /** The first text box of a row that is on screen: the language being edited. */
    function visibleInput(row) {
      return Array.prototype.filter.call(row.querySelectorAll('input[type="text"]'), function (input) {
        return input.closest("[hidden]") === null;
      })[0] || null;
    }

    function enableRemove(row) {
      var button = row.querySelector("[data-form-option-remove]");
      if (!button) return;

      button.hidden = false;
      button.addEventListener("click", function () {
        var radio = row.querySelector('input[type="radio"]');
        if (radio && radio.checked && noDefault) noDefault.checked = true;

        var next = row.nextElementSibling || row.previousElementSibling;

        if (rows().length > 1) {
          row.parentNode.removeChild(row);
        } else {
          Array.prototype.forEach.call(row.querySelectorAll('input[type="text"]'), function (input) {
            input.value = "";
          });
          next = row;
        }

        renumber();

        var target = next ? visibleInput(next) : null;
        (target || add).focus();
      });
    }

    add.addEventListener("click", function () {
      var all = rows();
      if (all.length === 0 || all.length >= max) return;

      var copy = all[all.length - 1].cloneNode(true);
      var index = nextIndex();

      Array.prototype.forEach.call(copy.querySelectorAll("input"), function (input) {
        input.name = input.name.replace(/\[\d+\]/, "[" + index + "]");

        if (input.type === "radio") {
          input.value = index;
          input.checked = false;
          input.defaultChecked = false;
        } else {
          input.value = "";
          input.defaultValue = "";
        }
      });

      list.appendChild(copy);
      enableRemove(copy);
      renumber();

      var target = visibleInput(copy);
      if (target) target.focus();
    });

    Array.prototype.forEach.call(rows(), enableRemove);
    add.hidden = false;
    renumber();
  });
})();
