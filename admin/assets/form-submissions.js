/**
 * Selecting stored submissions in Beheer → Inzendingen
 * (admin/form-submissions.php, FORMS.md "Inzendingen in bulk").
 *
 * What it does, and all it does:
 *   - the header checkbox selects every row ON THIS PAGE, and shows "some"
 *     (indeterminate) when only part of them is chosen;
 *   - the bar says how many are chosen, in a status region a screen reader
 *     announces, and shows the three actions only once there is a choice;
 *   - the delete button's question names that number, for the CMS's own
 *     dialog (admin/assets/admin-ui.js asks it).
 *
 * It decides nothing. Which ids may be acted on, and whether the action is
 * one there is, is api/admin/bulk-form-submissions.php's call. Nothing is
 * remembered either: a new page, a filter or a reload starts empty, and no
 * selection is written to any storage. Nothing a submission says is read or
 * logged here; the script only sees ids and checkboxes.
 *
 * Without it the checkboxes and buttons still post the form; the header
 * checkbox stays hidden because it would do nothing.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-submission-bulk]");

  if (!form) {
    return;
  }

  var all = form.querySelector("[data-submission-select-all]");
  var count = form.querySelector("[data-submission-selected-count]");
  var actions = form.querySelector("[data-submission-bulk-actions]");
  var bar = form.querySelector("[data-submission-bulk-bar]");
  var remove = form.querySelector("[data-submission-bulk-delete]");

  function boxes() {
    return Array.prototype.slice.call(form.querySelectorAll("[data-submission-select]"));
  }

  function counted(element, number) {
    var text = number === 1
      ? element.getAttribute("data-text-one")
      : element.getAttribute("data-text-many");

    return (text || "").replace(":count", String(number));
  }

  function update() {
    var list = boxes();
    var chosen = list.filter(function (box) {
      return box.checked;
    });

    if (all) {
      all.checked = list.length > 0 && chosen.length === list.length;
      all.indeterminate = chosen.length > 0 && chosen.length < list.length;
    }

    if (count) {
      count.textContent = chosen.length === 0
        ? count.getAttribute("data-text-none") || ""
        : counted(count, chosen.length);
    }

    if (actions) {
      actions.hidden = chosen.length === 0;
    }

    if (bar) {
      bar.classList.toggle("has-selection", chosen.length > 0);
    }

    if (remove && chosen.length > 0) {
      remove.setAttribute("data-admin-confirm", counted(remove, chosen.length));
    }

    list.forEach(function (box) {
      var row = box.closest("[data-submission-row]");

      if (row) {
        row.classList.toggle("is-selected", box.checked);
      }
    });
  }

  form.addEventListener("change", function (event) {
    var target = event.target;

    if (target === all) {
      boxes().forEach(function (box) {
        box.checked = all.checked;
      });
    }

    update();
  });

  if (all) {
    all.hidden = false;
  }

  // A browser that restores the form after Back may bring ticks along; the
  // bar starts from what is really ticked.
  update();
  window.addEventListener("pageshow", update);
})();
