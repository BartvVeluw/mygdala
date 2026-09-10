/**
 * Shop collections admin — plain vanilla JS (no library, no build step),
 * same conventions as admin/assets/portfolio-admin.js.
 *
 * Two independent pieces, each a no-op on a page that doesn't contain it:
 *
 * 1. admin/collections.php — drag-and-drop ordering of the collection cards
 *    via a dedicated handle, persisted to reorder-collections.php. Same
 *    handle-armed-draggable dance as the Portfolio overview, so a normal
 *    click on the card still opens the editor and never gets swallowed by a
 *    drag gesture.
 *
 * 2. admin/collection.php — the product picker: search over the rendered
 *    rows, "checked rows float to the top", and drag-and-drop ordering
 *    within that checked block.
 *
 * The picker deliberately has NO hidden field and no serialization step.
 * The rows are ordinary `product_ids[]` checkboxes, so the browser submits
 * exactly the checked ones in DOM order — meaning "reorder the rows" IS
 * "reorder the collection", with nothing to keep in sync and nothing that
 * breaks when JavaScript doesn't run (PHP already renders the selected rows
 * first, in the stored order).
 */
(function () {
  "use strict";

  /**
   * Arms `card` to be draggable only while its handle is held down, and
   * calls onDrop() once a drag finishes. Shared by both grids/lists below.
   */
  function makeDraggable(container, item, handle, itemSelector, onDrop, state) {
    handle.addEventListener("mousedown", function () {
      item.setAttribute("draggable", "true");
    });
    handle.addEventListener(
      "touchstart",
      function () {
        item.setAttribute("draggable", "true");
      },
      { passive: true }
    );

    function disarm() {
      item.setAttribute("draggable", "false");
    }
    document.addEventListener("mouseup", disarm);
    document.addEventListener("touchend", disarm);

    item.addEventListener("dragstart", function () {
      state.dragged = item;
      item.classList.add("is-dragging");
    });

    item.addEventListener("dragend", function () {
      item.classList.remove("is-dragging");
      disarm();
      if (state.dragged) onDrop();
      state.dragged = null;
    });

    item.addEventListener("dragover", function (event) {
      event.preventDefault();
      var dragged = state.dragged;
      if (!dragged || dragged === item || item.hidden) return;
      if (state.canDropOn && !state.canDropOn(item)) return;

      var nodes = Array.prototype.slice.call(container.querySelectorAll(itemSelector));
      var draggedIndex = nodes.indexOf(dragged);
      var targetIndex = nodes.indexOf(item);

      if (draggedIndex < targetIndex) {
        container.insertBefore(dragged, item.nextSibling);
      } else {
        container.insertBefore(dragged, item);
      }
    });
  }

  /* -------------------------------------------------------------------
     1. admin/collections.php — reorder the collections themselves.
     ------------------------------------------------------------------- */
  function initCollectionReorder() {
    var grid = document.querySelector("[data-collections-grid]");
    if (!grid) return;

    var state = { dragged: null };

    function persistOrder() {
      var url = grid.getAttribute("data-reorder-url");
      var csrfToken = grid.getAttribute("data-csrf-token");
      var ids = Array.prototype.map
        .call(grid.querySelectorAll("[data-collection-card]"), function (card) {
          return card.getAttribute("data-id");
        })
        .join(",");

      var body = new URLSearchParams();
      body.set("csrf_token", csrfToken);
      body.set("collection_ids", ids);

      fetch(url, { method: "POST", credentials: "same-origin", body: body }).catch(function () {});
    }

    Array.prototype.forEach.call(grid.querySelectorAll("[data-collection-card]"), function (card) {
      var handle = card.querySelector("[data-collection-drag-handle]");
      if (!handle) return;

      makeDraggable(grid, card, handle, "[data-collection-card]", persistOrder, state);
    });
  }

  /* -------------------------------------------------------------------
     2. admin/collection.php — the product picker.
     ------------------------------------------------------------------- */
  function initProductPicker() {
    var list = document.querySelector("[data-collection-product-list]");
    if (!list) return;

    var rows = Array.prototype.slice.call(list.querySelectorAll("[data-collection-product-row]"));
    var searchInput = document.querySelector("[data-collection-product-search]");
    var countEl = document.querySelector("[data-collection-product-count]");
    var emptyEl = document.querySelector("[data-collection-product-empty]");

    function isSelected(row) {
      var checkbox = row.querySelector("[data-collection-product-checkbox]");
      return !!checkbox && checkbox.checked;
    }

    function updateCount() {
      if (!countEl) return;
      var selected = rows.filter(isSelected).length;
      countEl.textContent = selected + " van " + rows.length + " producten geselecteerd";
    }

    /**
     * The last checked row in DOM order — new selections are appended after
     * it so a freshly ticked product lands at the end of the collection
     * rather than jumping to the front.
     */
    function lastSelectedRow() {
      var last = null;
      Array.prototype.forEach.call(list.querySelectorAll("[data-collection-product-row]"), function (row) {
        if (isSelected(row)) last = row;
      });
      return last;
    }

    function firstUnselectedRow() {
      var found = null;
      Array.prototype.forEach.call(list.querySelectorAll("[data-collection-product-row]"), function (row) {
        if (!found && !isSelected(row)) found = row;
      });
      return found;
    }

    rows.forEach(function (row) {
      var checkbox = row.querySelector("[data-collection-product-checkbox]");
      if (!checkbox) return;

      checkbox.addEventListener("change", function () {
        if (checkbox.checked) {
          row.classList.add("is-selected");
          var last = lastSelectedRow();
          // lastSelectedRow() can return this very row (it is already
          // checked by now); moving it after itself is a harmless no-op.
          if (last && last !== row) {
            list.insertBefore(row, last.nextSibling);
          }
        } else {
          row.classList.remove("is-selected");
          var firstUnselected = firstUnselectedRow();
          if (firstUnselected && firstUnselected !== row) {
            list.insertBefore(row, firstUnselected);
          }
        }
        updateCount();
      });
    });

    // Dragging is only meaningful between two selected rows: the order of
    // unchecked rows is never submitted, so allowing them to be dropped in
    // the middle of the selection would just be confusing.
    var state = {
      dragged: null,
      canDropOn: function (row) {
        return isSelected(row) && isSelected(state.dragged);
      },
    };

    rows.forEach(function (row) {
      var handle = row.querySelector("[data-collection-product-handle]");
      if (!handle) return;

      makeDraggable(list, row, handle, "[data-collection-product-row]", updateCount, state);
    });

    if (searchInput) {
      searchInput.addEventListener("input", function () {
        var query = (searchInput.value || "").trim().toLowerCase();
        var visible = 0;

        rows.forEach(function (row) {
          // A selected product always stays visible: hiding it would make
          // it look removed while it is still very much in the collection.
          var show = query === "" || isSelected(row) || row.getAttribute("data-name").indexOf(query) !== -1;
          row.hidden = !show;
          if (show) visible++;
        });

        if (emptyEl) emptyEl.hidden = visible !== 0;
      });
    }

    updateCount();
  }

  function init() {
    initCollectionReorder();
    initProductPicker();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
