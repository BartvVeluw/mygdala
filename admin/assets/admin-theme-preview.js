/*
 * Dashboard uiterlijk: see a dashboard theme while choosing it.
 *
 * OWNER. admin/settings.php, the only screen that loads it.
 *
 * THE PREVIEW IS THIS PAGE, AND ONLY THIS PAGE. Checking a theme sets the
 * data-admin-theme attribute on <body>, the attribute App\Service\AdminTheme
 * prints there, and admin.css restyles the whole CMS at once. A colour of
 * Eigen kleuren sets one --admin-custom-* property on <body> and on that
 * card's sketch; admin.css derives the rest of the palette from those five,
 * exactly as it does on a saved page. Nothing is sent and nothing is kept:
 * no request, no reload, no browser storage. The stored theme is what the
 * server prints, so a reload, or leaving without saving, shows it again by
 * itself.
 *
 * SAVING is the form's own "Uiterlijk opslaan" or the save bar
 * (admin/assets/save-bar.js). The save bar already hears every input and
 * change event in this form and says the page has unsaved changes; this file
 * keeps no such state of its own.
 *
 * THE COLOUR FIELDS are the colour control of the website's theme screen:
 * admin/assets/theme-admin.js keeps each swatch and its hex field in step,
 * and the hex field is what is posted and what this file reads. A colour is
 * painted only in the shapes the server accepts
 * (App\Service\AdminTheme::normaliseColor()) and only once it matches the
 * pattern the server wrote on the field, so nothing else reaches a CSS
 * property. The endpoint checks the same shape again.
 *
 * WHAT IS NOT HERE. No palette, no theme key and no sentence an editor
 * reads: those are admin.css, the registry and the catalog.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-admin-theme-form]");
  if (!form) return;

  var body = document.body;
  var sketch = form.querySelector("[data-admin-theme-custom-sketch]");
  var choices = form.querySelectorAll("[data-admin-theme-choice]");
  var rows = form.querySelectorAll("[data-admin-theme-color]");

  function each(list, callback) {
    Array.prototype.forEach.call(list, callback);
  }

  function hexField(row) {
    return row.querySelector("[data-default-color]");
  }

  /* The colour in a hex field as "#rrggbb", or null: with or without "#",
     and three digits for six, as on the server. */
  function colourOf(field) {
    var digits = field.value.trim().replace(/^#+/, "");

    if (/^[0-9a-f]{3}$/i.test(digits)) {
      digits = digits[0] + digits[0] + digits[1] + digits[1] + digits[2] + digits[2];
    }

    var pattern = new RegExp("^(?:" + field.getAttribute("pattern") + ")$");
    return pattern.test("#" + digits) ? ("#" + digits).toLowerCase() : null;
  }

  function paint(name, colour) {
    body.style.setProperty("--admin-custom-" + name, colour);
    if (sketch) sketch.style.setProperty("--admin-custom-" + name, colour);
  }

  function paintRow(row) {
    var field = hexField(row);
    var colour = field ? colourOf(field) : null;
    if (colour) paint(row.getAttribute("data-admin-theme-color"), colour);
  }

  /* Changes a field the way typing would: theme-admin.js moves the swatch
     along, this file repaints, and the save bar marks the form unsaved. */
  function setField(field, value) {
    field.value = value;
    field.dispatchEvent(new Event("input", { bubbles: true }));
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }

  /*
   * A browser may put back what was picked before a reload. After a reload
   * the page has to show what is STORED, so every field starts from the value
   * the server wrote. The stored colours go onto <body> as well, where they
   * do nothing until Eigen kleuren is checked.
   */
  each(choices, function (choice) {
    choice.checked = choice.defaultChecked;
  });

  each(rows, function (row) {
    each(row.querySelectorAll("input"), function (field) {
      field.value = field.defaultValue;
    });
    paintRow(row);
  });

  form.addEventListener("change", function (event) {
    var choice = event.target;
    if (choice.hasAttribute("data-admin-theme-choice") && choice.checked) {
      body.setAttribute("data-admin-theme", choice.value);
    }
  });

  /* On the form, so this runs after theme-admin.js, which listens on the
     swatch itself, has copied a picked colour into the hex field. */
  form.addEventListener("input", function (event) {
    var row = event.target.closest("[data-admin-theme-color]");
    if (row) paintRow(row);
  });

  /* Herstel: back to the colour that is stored now. */
  each(rows, function (row) {
    var field = hexField(row);
    var reset = row.querySelector("[data-admin-theme-color-reset]");
    if (!field || !reset) return;

    reset.hidden = false;
    reset.addEventListener("click", function () {
      setField(field, field.defaultValue);
    });
  });

  /* Standaardkleuren: all five back to the Default theme's colours. */
  var defaults = form.querySelector("[data-admin-theme-colors-default]");
  if (defaults) {
    defaults.hidden = false;
    defaults.addEventListener("click", function () {
      each(rows, function (row) {
        var field = hexField(row);
        if (field) setField(field, field.getAttribute("data-default-color"));
      });
    });
  }

  var note = document.querySelector("[data-admin-theme-preview-note]");
  if (note) note.hidden = false;
})();
