/**
 * The block picker's behaviour (admin/_block_picker.php): open the panel,
 * filter the cards, lay them out as cards or as a list, close it again.
 *
 * IT DOES NOT ADD ANYTHING. Every card is a real submit button inside a real
 * POST form to api/admin/add-page-section.php, so adding a block is the
 * browser submitting that form — this script never posts, never builds a
 * request and is not the security boundary. Turn it off and the panel simply
 * never opens; nothing here can add a block that the server would not have
 * accepted from the old dropdown.
 *
 * Everything works without a mouse and without hover: the cards are buttons,
 * the filters and the view toggle are buttons with aria-pressed, Escape
 * closes the panel, focus moves into the search box on open and back to the
 * opener on close, and Tab stays inside the panel while it is open. Nothing
 * is revealed by hovering — a touch device sees exactly what a desktop does.
 *
 * A PREVIEW ON TOP. The Voorbeeld button beside a card opens the block's
 * preview in the Contentblokken library's own modal <dialog>
 * (admin/assets/block-library.js). While that dialog is open it owns the
 * keyboard: Escape closes the preview and not this panel, and Tab stays in
 * the dialog. When it closes, the focus is back on that Voorbeeld button.
 *
 * THE VIEW IS A DISPLAY PREFERENCE. Cards or list is remembered per browser,
 * in localStorage under one Mygdala key, and applied before the panel is ever
 * opened. Storage that is blocked or empty simply means cards. Nothing about
 * it reaches the server.
 */
(function () {
  "use strict";

  var panel = document.querySelector("[data-block-picker]");
  if (!panel) return;

  var openers = document.querySelectorAll("[data-block-picker-open]");
  var searchInput = panel.querySelector("[data-block-picker-search]");
  var statusEl = panel.querySelector("[data-block-picker-status]");
  var filterButtons = panel.querySelectorAll("[data-block-picker-filter]");
  var viewButtons = panel.querySelectorAll("[data-block-picker-view]");
  var groups = panel.querySelectorAll("[data-block-picker-group]");
  var cards = panel.querySelectorAll("[data-block-card]");

  var lastFocused = null;
  var activeCategory = "";

  /** Mygdala's own key, named like mygdalaAdminHelp and mygdalaAdminTab:. */
  var VIEW_STORAGE_KEY = "mygdalaAdminBlockPickerView";
  var VIEWS = ["cards", "list"];
  var DEFAULT_VIEW = "cards";

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

  // --- Cards or a list ------------------------------------------------------

  function storedView() {
    try {
      var stored = window.localStorage.getItem(VIEW_STORAGE_KEY);
      return VIEWS.indexOf(stored) !== -1 ? stored : DEFAULT_VIEW;
    } catch (e) {
      // Storage blocked or unavailable: the default, which is the cards.
      return DEFAULT_VIEW;
    }
  }

  function rememberView(view) {
    try {
      window.localStorage.setItem(VIEW_STORAGE_KEY, view);
    } catch (e) {
      // Not remembered for next time, but still shown now.
    }
  }

  /**
   * ONE set of buttons, laid out twice by admin.css: nothing is rendered
   * again and nothing moves, so the search, the filters, the tab order and
   * the one-click add are the very same in both views.
   */
  function setView(view) {
    if (VIEWS.indexOf(view) === -1) view = DEFAULT_VIEW;

    panel.setAttribute("data-block-picker-layout", view);
    viewButtons.forEach(function (button) {
      var isActive = button.getAttribute("data-block-picker-view") === view;
      button.setAttribute("aria-pressed", isActive ? "true" : "false");
    });
  }

  // --- Search and filter ----------------------------------------------------

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
      // The slot holds the card and its preview button: both go together.
      var slot = card.closest("[data-block-slot]");
      if (slot) slot.hidden = !show;
      if (show) visible++;

      showMatchingUses(card, show ? term : "");
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
   * A card found through one of its example uses says which one: that line
   * appears while the search is on and goes again with it. Only the uses the
   * term matched, so a card grows by a line exactly when that line explains
   * why it is among the results.
   */
  function showMatchingUses(card, term) {
    var container = card.querySelector("[data-block-uses]");
    if (!container) return;

    var any = false;
    container.querySelectorAll("[data-block-use]").forEach(function (use) {
      var matches = term !== "" && (use.getAttribute("data-block-use") || "").indexOf(term) !== -1;
      use.hidden = !matches;
      if (matches) any = true;
    });

    container.hidden = !any;
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

  viewButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      var view = button.getAttribute("data-block-picker-view") || DEFAULT_VIEW;
      rememberView(view);
      setView(view);
    });
  });

  // The same choice, made in another tab of this CMS.
  window.addEventListener("storage", function (event) {
    if (event.key === VIEW_STORAGE_KEY) setView(storedView());
  });

  document.addEventListener("keydown", function (event) {
    if (panel.hidden) return;
    // A modal dialog over the panel (the block preview) handles its own keys.
    if (document.querySelector("dialog[open]")) return;

    if (event.key === "Escape") {
      event.preventDefault();
      closePanel();
    } else if (event.key === "Tab") {
      trapTab(event);
    }
  });

  setView(storedView());
})();
