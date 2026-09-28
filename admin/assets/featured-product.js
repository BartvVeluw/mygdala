/*
 * The Uitgelicht product editor (admin/featured-product.php): the product
 * list's search, and the "chosen right now" line above it.
 *
 *   [data-featured-product-search]   filters the rows by name as you type,
 *                                    like the collection editor's product
 *                                    picker; the checked row always stays
 *   [data-featured-product-current]  name, price and picture of the checked
 *                                    row, copied from that row; the notes
 *                                    "no product yet" and "not available"
 *                                    follow the choice
 *
 * Purely a convenience: the rows are ordinary radio buttons in the one form,
 * the server prints the same state for what is stored, and the endpoint
 * checks the id. No word of its own — every sentence is in the markup.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-featured-product-form]");
  if (!form) return;

  var list = form.querySelector("[data-featured-product-list]");
  if (!list) return;

  var rows = Array.prototype.slice.call(list.querySelectorAll("[data-featured-product-row]"));
  var search = form.querySelector("[data-featured-product-search]");
  var empty = form.querySelector("[data-featured-product-empty]");
  var current = form.querySelector("[data-featured-product-current]");
  var noneNote = form.querySelector("[data-featured-product-none-note]");
  var unavailableNote = form.querySelector("[data-featured-product-unavailable]");

  function radioOf(row) {
    return row.querySelector('input[type="radio"]');
  }

  function checkedRow() {
    for (var i = 0; i < rows.length; i++) {
      var radio = radioOf(rows[i]);
      if (radio && radio.checked) return rows[i];
    }
    return null;
  }

  function showCurrent() {
    var row = checkedRow();
    var radio = row ? radioOf(row) : null;
    var chosen = !!(radio && radio.value !== "");

    rows.forEach(function (candidate) {
      candidate.classList.toggle("is-selected", candidate === row);
    });

    if (noneNote) noneNote.hidden = chosen;
    if (unavailableNote) unavailableNote.hidden = !(chosen && row.hasAttribute("data-inactive"));
    if (!current) return;

    var name = current.querySelector("[data-featured-product-current-name]");
    var price = current.querySelector("[data-featured-product-current-price]");
    var thumb = current.querySelector("[data-featured-product-current-thumb]");
    var edit = current.querySelector("[data-featured-product-current-edit]");
    var rowName = chosen ? row.querySelector("[data-featured-product-row-name]") : null;
    var rowPrice = chosen ? row.querySelector("[data-featured-product-row-price]") : null;
    var rowPicture = chosen ? row.querySelector(".admin-collection-product-row__thumb img") : null;

    if (name) name.textContent = rowName ? rowName.textContent : (current.getAttribute("data-none-label") || "");
    if (price) price.textContent = rowPrice ? rowPrice.textContent : "";
    if (thumb) {
      thumb.textContent = "";
      if (rowPicture) {
        var picture = rowPicture.cloneNode(false);
        picture.removeAttribute("loading");
        thumb.appendChild(picture);
      }
    }
    if (edit) {
      edit.hidden = !chosen;
      if (chosen) edit.setAttribute("href", "/admin/product-form.php?id=" + encodeURIComponent(radio.value));
    }
  }

  function filter() {
    var term = search ? search.value.trim().toLowerCase() : "";
    var shown = 0;

    rows.forEach(function (row) {
      var radio = radioOf(row);
      var name = row.getAttribute("data-name") || "";
      // The empty choice and the checked row never disappear: what is
      // chosen stays visible, and "no product" stays one click away.
      var keep = term === "" || name === "" || (radio && radio.checked) || name.indexOf(term) !== -1;
      row.hidden = !keep;
      if (keep && name !== "") shown++;
    });

    if (empty) empty.hidden = term === "" || shown > 0;
  }

  list.addEventListener("change", function (event) {
    if (event.target && event.target.type === "radio") showCurrent();
  });

  if (search) {
    // Searching changes nothing that is saved, so the save bar
    // (admin/assets/save-bar.js, listening on the document) never hears it.
    ["input", "change"].forEach(function (type) {
      search.addEventListener(type, function (event) {
        event.stopPropagation();
        if (type === "input") filter();
      });
    });
    // Enter in the search field filters; it never submits the whole form.
    search.addEventListener("keydown", function (event) {
      if (event.key === "Enter") event.preventDefault();
    });
  }

  showCurrent();
})();
