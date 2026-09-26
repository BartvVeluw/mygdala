/**
 * Collapsible admin list items (admin/_admin_collapse.php).
 *
 * The opening and closing itself is the browser's: every item is a <details>
 * with a <summary>, so click, Enter, Space, the tab order and the
 * expanded/collapsed state a screen reader announces all work with no script
 * at all. This file only remembers things:
 *
 *   1. WHICH ITEMS ARE OPEN, per group and per scope, so a save that reloads
 *      the screen leaves the editor looking at what they were looking at.
 *      An item the server marked data-admin-collapse-open (a block that was
 *      just added) wins over what was remembered.
 *
 *   2. WHICH ITEM WAS BEING WORKED ON. Following an item's Bewerken link or
 *      pressing one of its buttons records it as that group's return target;
 *      the next load of the screen opens it, asks admin-tabs.js to open the
 *      tab it lives in, and scrolls it into view. The target is consumed on
 *      arrival, so coming back from a block editor lands on that block and an
 *      ordinary visit later still starts at the top.
 *
 * There is nothing block-specific here, and nothing a new content block has
 * to implement: everything is read from the markup the list already writes.
 */
(function () {
  "use strict";

  var OPEN_PREFIX = "mygdalaAdminOpen:";
  var RETURN_PREFIX = "mygdalaAdminReturn:";

  function read(key) {
    try {
      return window.sessionStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function write(key, value) {
    try {
      window.sessionStorage.setItem(key, value);
    } catch (e) {}
  }

  function drop(key) {
    try {
      window.sessionStorage.removeItem(key);
    } catch (e) {}
  }

  function scopeOf(group) {
    return (
      (group.getAttribute("data-admin-collapse-group") || "") +
      ":" +
      (group.getAttribute("data-admin-collapse-scope") || "")
    );
  }

  function readOpenState(key) {
    var raw = read(key);
    if (!raw) return {};

    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function initGroup(group) {
    var suffix = scopeOf(group);
    var openKey = OPEN_PREFIX + suffix;
    var returnKey = RETURN_PREFIX + suffix;

    var items = Array.prototype.filter.call(
      group.querySelectorAll("details[data-admin-collapse-id]"),
      function (item) {
        return item.closest("[data-admin-collapse-group]") === group;
      }
    );

    if (items.length === 0) return;

    var state = readOpenState(openKey);
    var returnTo = read(returnKey);
    drop(returnKey);

    var focusItem = null;

    items.forEach(function (item) {
      var id = item.getAttribute("data-admin-collapse-id");

      if (item.hasAttribute("data-admin-collapse-open") || id === returnTo) {
        item.open = true;
      } else if (Object.prototype.hasOwnProperty.call(state, id)) {
        item.open = state[id] === true;
      }

      if (id === returnTo) focusItem = item;

      item.addEventListener("toggle", function () {
        var current = readOpenState(openKey);
        current[id] = item.open;
        write(openKey, JSON.stringify(current));
      });
    });

    /**
     * Leaving through one of an item's own controls records it. Not the
     * summary and not the drag handle: opening a row is not "working on it",
     * and a drag never reloads the page.
     *
     * Not in a list marked data-admin-collapse-no-return: its rows are edited
     * in place (the rows of a block editor, admin/_editor_rows.php), so a
     * click inside one (a rich-text button, "Kies afbeelding") is editing,
     * never leaving, and the next load should not jump to that row.
     */
    if (!group.hasAttribute("data-admin-collapse-no-return")) {
      group.addEventListener("click", function (event) {
        var control = event.target.closest ? event.target.closest("a[href], button") : null;
        if (!control || control.tagName === "SUMMARY") return;

        var item = control.closest("details[data-admin-collapse-id]");
        if (!item || item.closest("[data-admin-collapse-group]") !== group) return;

        write(returnKey, item.getAttribute("data-admin-collapse-id"));
      });
    }

    if (!focusItem) return;

    // Its tab first — scrolling to something inside a hidden panel would
    // scroll to nothing at all.
    if (window.AdminTabs && window.AdminTabs.reveal) window.AdminTabs.reveal(focusItem);

    var summary = focusItem.querySelector("summary");
    if (summary) summary.focus({ preventScroll: true });

    focusItem.scrollIntoView({ block: "center" });
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-admin-collapse-group]"), initGroup);
})();
