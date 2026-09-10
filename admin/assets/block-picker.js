/**
 * The block picker's behaviour (admin/_block_picker.php): open the panel,
 * filter the cards, close it again.
 *
 * IT DOES NOT ADD ANYTHING. Every card is a real submit button inside a real
 * POST form to api/admin/add-page-section.php, so adding a block is the
 * browser submitting that form — this script never posts, never builds a
 * request and is not the security boundary. Turn it off and the panel simply
 * never opens; nothing here can add a block that the server would not have
 * accepted from the old dropdown.
 *
 * Everything works without a mouse and without hover: the cards are buttons,
 * the filters are buttons with aria-pressed, Escape closes the panel, focus
 * moves into the search box on open and back to the opener on close, and Tab
 * stays inside the panel while it is open. Nothing is revealed by hovering —
 * a touch device sees exactly what a desktop does.
 */
(function () {
  "use strict";

  var panel = document.querySelector("[data-block-picker]");
  if (!panel) return;

  var openers = document.querySelectorAll("[data-block-picker-open]");
  var searchInput = panel.querySelector("[data-block-picker-search]");
  var statusEl = panel.querySelector("[data-block-picker-status]");
  var filterButtons = panel.querySelectorAll("[data-block-picker-filter]");
  var groups = panel.querySelectorAll("[data-block-picker-group]");
  var cards = panel.querySelectorAll("[data-block-card]");

  var lastFocused = null;
  var activeCategory = "";

  var FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function openPanel(opener) {
    lastFocused = opener || document.activeElement;

    panel.hidden = false;
    panel.setAttribute("aria-hidden", "false");
    document.body.classList.add("admin-block-picker-open");
    openers.forEach(function (button) {
      button.setAttribute("aria-expanded", "true");
    });

    if (searchInput) {
      searchInput.value = "";
      searchInput.focus();
    }
    setCategory("");
    applyFilter();
  }

  function closePanel() {
    panel.hidden = true;
    panel.setAttribute("aria-hidden", "true");
    document.body.classList.remove("admin-block-picker-open");
    openers.forEach(function (button) {
      button.setAttribute("aria-expanded", "false");
    });

    if (lastFocused && typeof lastFocused.focus === "function") {
      lastFocused.focus();
    }
  }

  function setCategory(category) {
    activeCategory = category;
    filterButtons.forEach(function (button) {
      var isActive = button.getAttribute("data-block-picker-filter") === category;
      button.classList.toggle("is-active", isActive);
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  /**
   * Plain substring matching over the label, the description, the category
   * name and the example uses — deliberately not fuzzy and deliberately not
   * over the registry key, which an editor never sees. With this many blocks
   * anything cleverer would be harder to predict, not easier to use.
   */
  function applyFilter() {
    var term = searchInput ? searchInput.value.trim().toLowerCase() : "";
    var visible = 0;

    cards.forEach(function (card) {
      var matchesTerm = term === "" || (card.getAttribute("data-block-terms") || "").indexOf(term) !== -1;
      var matchesCategory =
        activeCategory === "" || card.getAttribute("data-block-category") === activeCategory;
      var show = matchesTerm && matchesCategory;

      card.hidden = !show;
      if (show) visible++;
    });

    groups.forEach(function (group) {
      var shown = group.querySelectorAll("[data-block-card]:not([hidden])").length;
      group.hidden = shown === 0;
    });

    if (!statusEl) return;

    if (visible === 0) {
      statusEl.textContent = term === ""
        ? "Geen contentblok in deze categorie."
        : "Geen contentblok gevonden voor “" + searchInput.value.trim() + "”.";
      statusEl.hidden = false;
    } else if (term === "" && activeCategory === "") {
      statusEl.textContent = "";
      statusEl.hidden = true;
    } else {
      statusEl.textContent = visible === 1 ? "1 contentblok gevonden." : visible + " contentblokken gevonden.";
      statusEl.hidden = false;
    }
  }

  /**
   * Tab must not walk out of an open dialog into the page behind it. Kept as
   * a plain wrap instead of an inert/focus-trap library: the panel's
   * focusable elements are all in one subtree and none of them move.
   */
  function trapTab(event) {
    var focusable = Array.prototype.filter.call(
      panel.querySelectorAll(FOCUSABLE),
      function (el) {
        return el.offsetParent !== null || el === document.activeElement;
      }
    );
    if (focusable.length === 0) return;

    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  openers.forEach(function (button) {
    button.addEventListener("click", function () {
      openPanel(button);
    });
  });

  panel.querySelectorAll("[data-block-picker-close]").forEach(function (button) {
    button.addEventListener("click", closePanel);
  });

  if (searchInput) {
    searchInput.addEventListener("input", applyFilter);
    // A search field's own clear button fires "search", not "input", in
    // WebKit — without this the cleared box would keep the old filter.
    searchInput.addEventListener("search", applyFilter);
  }

  filterButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      setCategory(button.getAttribute("data-block-picker-filter") || "");
      applyFilter();
    });
  });

  document.addEventListener("keydown", function (event) {
    if (panel.hidden) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closePanel();
    } else if (event.key === "Tab") {
      trapTab(event);
    }
  });
})();
