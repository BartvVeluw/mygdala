/**
 * The hand-picked, ordered list of admin/_item_picker.php: the projects of a
 * Projecten block or a gallery, and a project's related projects. Plain
 * vanilla JS, one controller per [data-item-picker].
 *
 * The list is ordinary checkboxes. The picked rows stand first, in their
 * picked order, and the browser posts the ticked boxes in the order they
 * stand, so reordering the rows IS reordering the list — the pattern of the
 * collection's product picker (admin/assets/collections-admin.js). This script
 * only moves rows:
 *
 *   - ticking a row puts it at the end of the picked ones, unticking it puts
 *     it right after them;
 *   - ↑ and ↓ on a picked row swap it with its neighbour (the keyboard's way),
 *     and a drag on its handle moves it among the picked ones;
 *   - a search box hides the rows whose title and categories do not match (a
 *     hidden row still posts: it is only out of sight), and is not an edit
 *     itself: its events stop at the box;
 *   - a count says how many are picked, and a status line tells a screen
 *     reader what moved where.
 *
 * A move changes no field, so it sends a `change` through the form itself:
 * the save bar (admin/assets/save-bar.js) marks the form as changed exactly as
 * if a box had been ticked.
 */
(function () {
  "use strict";

  function words(picker) {
    try {
      return JSON.parse(picker.getAttribute("data-item-picker-words") || "{}");
    } catch (e) {
      return {};
    }
  }

  function fill(text, values) {
    return String(text || "").replace(/:(\w+)/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
    });
  }

  function init(picker) {
    var list = picker.querySelector("[data-item-picker-list]");
    if (!list) return;

    var text = words(picker);
    var search = picker.querySelector("[data-item-picker-search]");
    var count = picker.querySelector("[data-item-picker-count]");
    var none = picker.querySelector("[data-item-picker-none]");
    var status = picker.querySelector("[data-item-picker-status]");
    var dragged = null;

    function rows() {
      return Array.prototype.slice.call(list.querySelectorAll("[data-item-picker-row]"));
    }

    function isPicked(row) {
      var box = row.querySelector("[data-item-picker-checkbox]");
      return !!(box && box.checked);
    }

    function picked() {
      return rows().filter(isPicked);
    }

    function titleOf(row) {
      var title = row.querySelector("[data-item-picker-title]");
      return title ? title.textContent.trim() : "";
    }

    function announce(message) {
      if (status) status.textContent = message;
    }

    function changed() {
      list.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function refresh() {
      var chosen = picked();
      rows().forEach(function (row) {
        var isChosen = chosen.indexOf(row) !== -1;
        row.classList.toggle("is-selected", isChosen);
        var moves = row.querySelector("[data-item-picker-moves]");
        if (moves) {
          moves.hidden = false;
          var position = chosen.indexOf(row);
          var up = moves.querySelector('[data-item-picker-move="-1"]');
          var down = moves.querySelector('[data-item-picker-move="1"]');
          if (up) up.disabled = !isChosen || position === 0;
          if (down) down.disabled = !isChosen || position === chosen.length - 1;
        }
      });
      if (count) count.textContent = fill(text.count, { n: String(chosen.length) });
    }

    /** Puts a row right after the last picked one (or first, with none picked). */
    function placeAfterPicked(row) {
      var chosen = picked().filter(function (other) { return other !== row; });
      var anchor = chosen.length ? chosen[chosen.length - 1].nextSibling : list.firstChild;
      list.insertBefore(row, anchor);
    }

    function move(row, step) {
      var chosen = picked();
      var from = chosen.indexOf(row);
      var to = from + step;
      if (from === -1 || to < 0 || to >= chosen.length) return;

      if (step < 0) {
        list.insertBefore(row, chosen[to]);
      } else {
        list.insertBefore(row, chosen[to].nextSibling);
      }

      refresh();
      changed();
      announce(fill(text.moved, { name: titleOf(row), position: String(to + 1), total: String(chosen.length) }));
    }

    list.addEventListener("change", function (event) {
      var box = event.target;
      if (!box || !box.matches || !box.matches("[data-item-picker-checkbox]")) return;
      var row = box.closest("[data-item-picker-row]");
      if (!row) return;

      placeAfterPicked(row);
      refresh();
      announce(fill(box.checked ? text.picked : text.unpicked, { name: titleOf(row) }));
    });

    list.addEventListener("click", function (event) {
      var button = event.target.closest ? event.target.closest("[data-item-picker-move]") : null;
      if (!button || button.disabled) return;
      var row = button.closest("[data-item-picker-row]");
      if (!row) return;

      move(row, parseInt(button.getAttribute("data-item-picker-move"), 10) || 0);
      // The button may now be disabled at the top or the bottom: keep the
      // focus on the row rather than losing it.
      if (button.disabled) {
        var other = row.querySelector("[data-item-picker-move]:not([disabled])");
        if (other) other.focus();
      } else {
        button.focus();
      }
    });

    // A drag starts only on the handle of a picked row, so a click on the
    // row's box or title is never swallowed by a drag.
    rows().forEach(function (row) {
      var handle = row.querySelector("[data-item-picker-handle]");
      if (!handle) return;

      function arm() {
        if (isPicked(row)) row.setAttribute("draggable", "true");
      }
      function disarm() {
        row.setAttribute("draggable", "false");
      }

      handle.addEventListener("mousedown", arm);
      handle.addEventListener("touchstart", arm, { passive: true });
      document.addEventListener("mouseup", disarm);
      document.addEventListener("touchend", disarm);

      row.addEventListener("dragstart", function (event) {
        dragged = row;
        row.classList.add("is-dragging");
        if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
      });
      row.addEventListener("dragend", function () {
        row.classList.remove("is-dragging");
        disarm();
        if (dragged) {
          refresh();
          changed();
          var chosen = picked();
          announce(fill(text.moved, { name: titleOf(row), position: String(chosen.indexOf(row) + 1), total: String(chosen.length) }));
        }
        dragged = null;
      });
      row.addEventListener("dragover", function (event) {
        if (!dragged || dragged === row || !isPicked(row)) return;
        event.preventDefault();
        var all = rows();
        if (all.indexOf(dragged) < all.indexOf(row)) {
          list.insertBefore(dragged, row.nextSibling);
        } else {
          list.insertBefore(dragged, row);
        }
      });
    });

    if (search) {
      // Searching is not an edit: its events stop here, so the save bar never
      // calls a form changed because somebody looked for a project.
      search.addEventListener("change", function (event) {
        event.stopPropagation();
      });
      search.addEventListener("input", function (event) {
        event.stopPropagation();
        var query = search.value.trim().toLowerCase();
        var shown = 0;
        rows().forEach(function (row) {
          var match = query === "" || (row.getAttribute("data-name") || "").indexOf(query) !== -1;
          row.hidden = !match;
          if (match) shown++;
        });
        if (none) none.hidden = shown !== 0;
      });
    }

    refresh();
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-item-picker]"), init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
