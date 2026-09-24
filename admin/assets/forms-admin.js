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
 *
 * MOVING A ROW moves the element, nothing else. The browser sends the rows in
 * the order they sit in, the endpoint stores them in that order, and the
 * index in their names goes along, so both languages and the "Standaard"
 * mark stay with the option. The first row cannot go up and the last cannot
 * go down; focus stays on the row that moved, and a status line says where
 * it went, in the catalogue's words the server put on it.
 *
 * Every add, remove and move ends with a bubbling "change" from the list:
 * none of them types anything, and the save bar (admin/assets/save-bar.js)
 * learns about edits from input and change events alone.
 */
(function () {
  "use strict";

  Array.prototype.forEach.call(document.querySelectorAll("[data-form-options]"), function (group) {
    var list = group.querySelector("[data-form-option-list]");
    var add = group.querySelector("[data-form-option-add]");
    if (!list || !add) return;

    var max = parseInt(group.getAttribute("data-form-options-max") || "50", 10);
    var noDefault = group.querySelector('input[name="default_option"][value=""]');
    var status = group.querySelector("[data-form-option-status]");

    function rows() {
      return list.querySelectorAll("[data-form-option-row]");
    }

    function renumber() {
      var all = rows();

      Array.prototype.forEach.call(all, function (row, position) {
        var number = String(position + 1);
        var badge = row.querySelector("[data-form-option-number]");
        if (badge) badge.textContent = number;

        Array.prototype.forEach.call(row.querySelectorAll("[aria-label]"), function (element) {
          element.setAttribute("aria-label", element.getAttribute("aria-label").replace(/\d+/, number));
        });

        var up = row.querySelector('[data-form-option-move="up"]');
        var down = row.querySelector('[data-form-option-move="down"]');
        if (up) up.disabled = position === 0;
        if (down) down.disabled = position === all.length - 1;
      });

      add.disabled = all.length >= max;
    }

    /** Tells the save bar the options changed; see the note above. */
    function changed() {
      list.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function nextIndex() {
      var highest = -1;

      Array.prototype.forEach.call(list.querySelectorAll('input[name^="option_label["]'), function (input) {
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
        changed();

        var target = next ? visibleInput(next) : null;
        (target || add).focus();
      });
    }

    function enableMove(row) {
      var buttons = row.querySelector("[data-form-option-move-group]");
      if (!buttons) return;

      buttons.hidden = false;

      Array.prototype.forEach.call(buttons.querySelectorAll("[data-form-option-move]"), function (button) {
        button.addEventListener("click", function () {
          var up = button.getAttribute("data-form-option-move") === "up";
          var sibling = up ? row.previousElementSibling : row.nextElementSibling;
          if (!sibling) return;

          // The NEIGHBOUR moves past this row rather than this row past its
          // neighbour: the row with the focused button stays in the document,
          // so the focus stays where the keyboard left it.
          list.insertBefore(sibling, up ? row.nextElementSibling : row);
          renumber();
          changed();

          // A button that just became disabled cannot keep the focus; its
          // partner in the same row can.
          if (button.disabled) {
            var other = buttons.querySelector('[data-form-option-move="' + (up ? "down" : "up") + '"]');
            if (other && !other.disabled) other.focus();
          } else {
            button.focus();
          }

          if (status) {
            var position = Array.prototype.indexOf.call(rows(), row) + 1;
            status.textContent = (status.getAttribute("data-form-option-moved") || "").replace(":n", String(position));
          }
        });
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
          // Empty, and with no option id: a copied row is a NEW option, and
          // it may not carry the one it was copied from. Its placeholder, the
          // name of that other option in the default language, goes too.
          input.value = "";
          input.defaultValue = "";
          input.removeAttribute("placeholder");
        }
      });

      list.appendChild(copy);
      enableRemove(copy);
      enableMove(copy);
      renumber();
      changed();

      var target = visibleInput(copy);
      if (target) target.focus();
    });

    Array.prototype.forEach.call(rows(), function (row) {
      enableRemove(row);
      enableMove(row);
    });
    add.hidden = false;
    renumber();
  });
})();

/**
 * THE PREVIEW (admin/form.php). The frame holds admin/form-preview.php: the
 * stored form, drawn by the public renderer. The server gives it a fixed
 * height and the width of its column, which works as it is. This makes it
 * fit and lets the editor pick a width:
 *
 *   - Desktop draws the frame at least 680 pixels wide, just past the 640 at
 *     which the site puts every field on its own row, and scales it down
 *     into the column when the column is narrower. What is shown is the
 *     grid every wider screen shows, with the shares the editor chose, only
 *     smaller; wider than that changes no share, only the pixels.
 *   - Mobiel draws it 375 pixels wide, a phone's width, where every field is
 *     a full row.
 *
 * The site's media query does the rest, because the frame is a viewport of
 * its own. The frame's height is the height of the form inside it, read
 * through the frame's document: its sandbox allows the same origin and
 * nothing else, so no script runs in it while this one may measure it. The
 * frame is never navigated, posted to or written into. On a phone-sized
 * screen the preview starts on Mobiel. The choice of width holds while the
 * screen is open and is not remembered.
 */
(function () {
  "use strict";

  var preview = document.querySelector("[data-form-preview]");
  if (!preview) return;

  var stage = preview.querySelector("[data-form-preview-stage]");
  var frame = preview.querySelector("[data-form-preview-frame]");
  var group = preview.querySelector("[data-form-preview-viewports]");
  if (!stage || !frame) return;

  var WIDTHS = { desktop: 680, mobile: 375 };

  function contentHeight() {
    try {
      var doc = frame.contentDocument;
      var main = doc && doc.getElementById("main");
      if (!main) return 0;

      return Math.ceil(main.getBoundingClientRect().bottom);
    } catch (e) {
      return 0;
    }
  }

  function fit() {
    var available = stage.clientWidth;
    if (available <= 0) return;

    var viewport = stage.getAttribute("data-viewport") === "mobile" ? "mobile" : "desktop";
    var width = viewport === "mobile" ? WIDTHS.mobile : Math.max(available, WIDTHS.desktop);
    var scale = Math.min(1, available / width);

    frame.style.width = width + "px";

    var height = contentHeight();
    if (height <= 0) return;

    frame.style.height = height + "px";
    frame.style.transform = scale < 1 ? "scale(" + scale + ")" : "";
    frame.style.marginLeft = Math.max(0, Math.floor((available - width * scale) / 2)) + "px";
    stage.style.height = Math.ceil(height * scale) + "px";
    stage.classList.add("is-fitted");
  }

  function show(viewport) {
    stage.setAttribute("data-viewport", viewport);
    Array.prototype.forEach.call(group ? group.querySelectorAll("[data-form-preview-viewport]") : [], function (button) {
      button.setAttribute("aria-pressed", button.getAttribute("data-form-preview-viewport") === viewport ? "true" : "false");
    });
    fit();
  }

  if (group) {
    Array.prototype.forEach.call(group.querySelectorAll("[data-form-preview-viewport]"), function (button) {
      button.addEventListener("click", function () {
        show(button.getAttribute("data-form-preview-viewport"));
      });
    });
    group.hidden = false;
  }

  frame.addEventListener("load", function () {
    fit();

    // A web font that arrives after the load event changes the height.
    try {
      var fonts = frame.contentDocument && frame.contentDocument.fonts;
      if (fonts && fonts.ready) fonts.ready.then(fit);
    } catch (e) {}
  });

  if (typeof ResizeObserver === "function") {
    new ResizeObserver(fit).observe(stage);
  } else {
    window.addEventListener("resize", fit);
  }

  // On a screen as narrow as a phone the editor is looking at a phone, so
  // the preview starts there too (the admin's own 640px).
  if (group && window.matchMedia && window.matchMedia("(max-width: 640px)").matches) {
    show("mobile");
  }

  // The frame may have finished loading before this file ran.
  fit();
})();
