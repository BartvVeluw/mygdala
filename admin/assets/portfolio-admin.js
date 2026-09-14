/**
 * admin/portfolio.php — the compact Portfolio overview.
 *
 * Three independent pieces, all plain vanilla JS (no library, no build
 * step), scoped to this one admin page:
 *
 * 1. Client-side search/filter over the already-rendered cards. With ~100
 *    lightweight cards loaded at once, a server round trip per keystroke
 *    would be wasted work — see MAIN.MD. Filter state is mirrored into the
 *    URL (history.replaceState, no reload) so it survives a reload and can
 *    be carried into each card's "back to portfolio" link.
 * 2. Drag-and-drop reordering via a dedicated handle (not the whole card),
 *    so dragging never fights with the card's own "click to edit" link —
 *    see initDragReorder()'s mousedown/mouseup dance below.
 * 3. Scroll-position memory: before following a card link away from this
 *    page, the current scroll offset is stashed in sessionStorage and
 *    restored on the next load of this page, so returning from "item 60 of
 *    100" doesn't dump the admin back at the top of the grid.
 */
(function () {
  "use strict";

  var SCROLL_KEY = "vvl-portfolio-admin-scroll";

  function initFilters(grid) {
    var toolbar = document.querySelector("[data-portfolio-toolbar]");
    if (!toolbar) return;

    var searchInput = toolbar.querySelector("[data-portfolio-search]");
    var categorySelect = toolbar.querySelector('[data-portfolio-filter="category"]');
    var visibilitySelect = toolbar.querySelector('[data-portfolio-filter="visibility"]');
    var detailSelect = toolbar.querySelector('[data-portfolio-filter="detail"]');
    var homeSelect = toolbar.querySelector('[data-portfolio-filter="home"]');
    var countEl = document.querySelector("[data-portfolio-count]");
    var emptyEl = document.querySelector("[data-portfolio-empty]");
    var cards = Array.prototype.slice.call(grid.querySelectorAll("[data-portfolio-card]"));

    function currentState() {
      return {
        q: (searchInput.value || "").trim().toLowerCase(),
        cat: categorySelect.value,
        vis: visibilitySelect.value,
        detail: detailSelect.value,
        home: homeSelect.value,
      };
    }

    function matches(card, state) {
      if (state.q !== "" && card.getAttribute("data-title").indexOf(state.q) === -1) return false;
      if (state.cat === "_none") {
        // "Zonder categorie": only the cards whose item has no category at all.
        if ((card.getAttribute("data-categories") || "").trim() !== "") return false;
      } else if (state.cat !== "all") {
        var cats = (card.getAttribute("data-categories") || "").split(/\s+/);
        if (cats.indexOf(state.cat) === -1) return false;
      }
      if (state.vis === "visible" && card.getAttribute("data-active") !== "1") return false;
      if (state.vis === "hidden" && card.getAttribute("data-active") !== "0") return false;
      if (state.detail === "yes" && card.getAttribute("data-detail") !== "1") return false;
      if (state.detail === "no" && card.getAttribute("data-detail") !== "0") return false;
      if (state.home === "yes" && card.getAttribute("data-home") !== "1") return false;
      if (state.home === "no" && card.getAttribute("data-home") !== "0") return false;
      return true;
    }

    function updateBackLinks(backQuery) {
      cards.forEach(function (card) {
        var link = card.querySelector(".admin-portfolio-card__link");
        if (!link) return;
        var id = card.getAttribute("data-id");
        link.href = "/admin/portfolio-item.php?id=" + id + (backQuery ? "&back=" + encodeURIComponent(backQuery) : "");
      });
    }

    function apply() {
      var state = currentState();
      var visibleCount = 0;

      cards.forEach(function (card) {
        var show = matches(card, state);
        card.hidden = !show;
        if (show) visibleCount++;
      });

      if (countEl) {
        countEl.textContent = visibleCount + " van " + cards.length + " items";
      }
      if (emptyEl) {
        emptyEl.hidden = visibleCount !== 0;
      }

      var params = [];
      if (state.q) params.push("q=" + encodeURIComponent(searchInput.value.trim()));
      if (state.cat !== "all") params.push("cat=" + encodeURIComponent(state.cat));
      if (state.vis !== "all") params.push("vis=" + encodeURIComponent(state.vis));
      if (state.detail !== "all") params.push("detail=" + encodeURIComponent(state.detail));
      if (state.home !== "all") params.push("home=" + encodeURIComponent(state.home));
      var queryString = params.join("&");

      var newUrl = window.location.pathname + (queryString ? "?" + queryString : "");
      window.history.replaceState(null, "", newUrl);

      updateBackLinks(queryString);
    }

    [searchInput, categorySelect, visibilitySelect, detailSelect, homeSelect].forEach(function (el) {
      if (!el) return;
      el.addEventListener("input", apply);
      el.addEventListener("change", apply);
    });

    apply();
  }

  /**
   * Drag-and-drop reordering of the overview cards. The card itself only
   * becomes draggable="true" for the duration of a press-and-drag that
   * started on its handle ([data-portfolio-drag-handle]) — starting a drag
   * anywhere else on the card is impossible, so a normal click on the
   * card's own link always opens the editor and never gets swallowed by a
   * drag gesture.
   */
  function initDragReorder(grid) {
    var dragged = null;

    function cardNodes() {
      return Array.prototype.slice.call(grid.querySelectorAll("[data-portfolio-card]"));
    }

    cardNodes().forEach(function (card) {
      var handle = card.querySelector("[data-portfolio-drag-handle]");
      if (!handle) return;

      handle.addEventListener("mousedown", function () {
        card.setAttribute("draggable", "true");
      });
      handle.addEventListener("touchstart", function () {
        card.setAttribute("draggable", "true");
      }, { passive: true });

      function disarm() {
        card.setAttribute("draggable", "false");
      }
      document.addEventListener("mouseup", disarm);
      document.addEventListener("touchend", disarm);

      card.addEventListener("dragstart", function () {
        dragged = card;
        card.classList.add("is-dragging");
      });

      card.addEventListener("dragend", function () {
        card.classList.remove("is-dragging");
        disarm();
        if (dragged) persistOrder(grid);
        dragged = null;
      });

      card.addEventListener("dragover", function (event) {
        event.preventDefault();
        if (!dragged || dragged === card || card.hidden) return;

        var nodes = cardNodes();
        var draggedIndex = nodes.indexOf(dragged);
        var targetIndex = nodes.indexOf(card);

        if (draggedIndex < targetIndex) {
          grid.insertBefore(dragged, card.nextSibling);
        } else {
          grid.insertBefore(dragged, card);
        }
      });
    });
  }

  function persistOrder(grid) {
    var url = grid.getAttribute("data-reorder-url");
    var csrfToken = grid.getAttribute("data-csrf-token");
    var itemIds = Array.prototype.map
      .call(grid.querySelectorAll("[data-portfolio-card]"), function (card) {
        return card.getAttribute("data-id");
      })
      .join(",");

    var body = new URLSearchParams();
    body.set("csrf_token", csrfToken);
    body.set("item_ids", itemIds);

    fetch(url, { method: "POST", credentials: "same-origin", body: body }).catch(function () {});
  }

  function initScrollMemory(grid) {
    grid.querySelectorAll(".admin-portfolio-card__link").forEach(function (link) {
      link.addEventListener("click", function () {
        try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)); } catch (e) {}
      });
    });

    try {
      var saved = sessionStorage.getItem(SCROLL_KEY);
      if (saved !== null) {
        window.scrollTo(0, parseInt(saved, 10) || 0);
        sessionStorage.removeItem(SCROLL_KEY);
      }
    } catch (e) {}
  }

  function init() {
    var grid = document.querySelector("[data-portfolio-grid]");
    if (!grid) return;

    initFilters(grid);
    initDragReorder(grid);
    initScrollMemory(grid);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
