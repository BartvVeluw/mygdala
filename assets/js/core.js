/* =========================================================================
   Mygdala — CORE site behaviour
   Loaded on every public page (App\Service\PageAssets), and only what every
   page genuinely uses: the header/navigation and the generic scroll-reveal
   every block opts into with data-reveal.

   No language code. The server renders every page in the language of its
   URL, and the language switch is a row of ordinary links to the other
   URLs (App\Service\Routing\LanguageSwitch), so there is nothing to swap
   in the browser: no data-nl/data-en, no data-lang-html, no stored language
   (docs/multilingual/ARCHITECTURE.md, "Eén taal per antwoord").

   What does NOT belong here: anything a single content block, the shared
   lightbox or the Shop owns. Those live next to their own markup —
   assets/js/blocks/*.js, assets/js/lightbox.js, assets/js/shop/*.js — and
   are asked for by the block definition or route that needs them.
   Adding a block-specific initialiser back into this file is exactly the
   regression Tests\Service\FrontendAssetOwnershipTest fails on.

   Vanilla JS, no build step.
   ========================================================================= */
(function () {
  "use strict";

  var docEl = document.documentElement;
  var prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------------------------------------------------------------------
     Header: compact-on-scroll + mobile nav
     --------------------------------------------------------------------- */
  function initHeader() {
    var header = document.querySelector(".site-header");
    if (!header) return;

    var onScroll = function () {
      header.classList.toggle("is-scrolled", window.scrollY > 24);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });

    var toggle = document.querySelector(".nav-toggle");
    var nav = document.querySelector(".main-nav");
    if (!toggle || !nav) return;

    function closeNav() {
      nav.classList.remove("is-open");
      toggle.classList.remove("is-open");
      toggle.setAttribute("aria-expanded", "false");
      document.body.style.overflow = "";
    }
    function openNav() {
      nav.classList.add("is-open");
      toggle.classList.add("is-open");
      toggle.setAttribute("aria-expanded", "true");
      document.body.style.overflow = "hidden";
    }
    toggle.addEventListener("click", function () {
      var isOpen = nav.classList.contains("is-open");
      isOpen ? closeNav() : openNav();
    });
    nav.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", closeNav);
    });
    // Full-screen mobile nav has no separate "outside" area — clicking the
    // overlay's own empty space (not a link/button inside it) closes it,
    // same intent as clicking outside a modal.
    nav.addEventListener("click", function (e) {
      if (e.target === nav) closeNav();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key !== "Escape") return;
      if (!nav.classList.contains("is-open")) return;
      closeNav();
      toggle.focus();
    });
  }

  /**
   * CMS-managed submenus (App\Service\NavigationService, markup in
   * partials/main-nav-list.php): an item with a submenu is its own link plus
   * a separate .main-nav__toggle button, up to three levels deep. The link is
   * never touched here, so clicking or tapping it always navigates.
   *
   * ONE STATE PER ITEM: .is-open on the item and aria-expanded on its toggle,
   * always set together by setOpen(). Everything that opens or closes a
   * submenu goes through it — hover, click, keyboard, Escape, focus leaving
   * — and the CSS panel and chevron read nothing else. Adding .is-enhanced
   * to .main-nav switches off the CSS-only :hover fallback, so there are
   * never two mechanisms that could disagree.
   *
   *   - Desktop, mouse: entering an item opens its submenu; leaving the whole
   *     branch (item, row and panel) closes it after a short grace period,
   *     unless the keyboard focus is still inside or it was opened on
   *     purpose with the toggle. Touch and pen pointers are ignored here: a
   *     tap never opens anything by hover, so a first tap on a link goes
   *     straight through.
   *   - Toggle: opens or closes. Opening closes the item's open siblings (one
   *     branch per level, as before); closing also closes everything below.
   *     A click on a submenu the hover already opened pins it open instead.
   *   - Escape closes the innermost open submenu that holds the focus and
   *     puts the focus back on its toggle; without focus inside, it closes
   *     every open submenu. It stops there, so the mobile menu itself only
   *     closes on the next Escape.
   *   - Focus moving out of a branch, or a click anywhere outside it,
   *     closes it.
   *
   * PLACEMENT (desktop only). A level-3 flyout opens to the right of its
   * panel, or to the left when it would not fit there and the left has more
   * room; a top-level panel that would run off the right edge lines up with
   * its item's right edge instead. Both get .opens-left. Measured from the
   * real boxes on load, when a submenu opens and once a resize has settled
   * (RESIZE_DELAY), reading every box of a level first and writing its
   * classes after, top level first because level 3 hangs off level 2.
   */
  function initNavDropdowns() {
    var nav = document.querySelector(".main-nav");
    if (!nav) return;
    var items = Array.prototype.slice.call(nav.querySelectorAll(".main-nav__item--has-children"));
    if (!items.length) return;

    nav.classList.add("is-enhanced");
    var desktop = window.matchMedia("(min-width: 901px)");
    var CLOSE_DELAY = 180;
    var RESIZE_DELAY = 100;
    var EDGE = 8;

    function toggleOf(item) { return item.querySelector(":scope > .main-nav__row > .main-nav__toggle"); }
    function panelOf(item) { return item.querySelector(":scope > .main-nav__submenu"); }
    function isOpen(item) { return item.classList.contains("is-open"); }
    function isTopLevel(item) { return item.parentElement.classList.contains("main-nav__list"); }
    function siblingsOf(item) {
      return Array.prototype.filter.call(item.parentElement.children, function (el) {
        return el !== item && el.classList.contains("main-nav__item--has-children");
      });
    }

    function setOpen(item, open, how) {
      window.clearTimeout(item.navCloseTimer);
      if (open) {
        siblingsOf(item).forEach(function (sibling) {
          if (isOpen(sibling)) setOpen(sibling, false);
        });
        // A hover never downgrades a submenu that was opened on purpose.
        if (!isOpen(item) || how !== "hover") item.navOpenedBy = how;
        item.classList.add("is-open");
        place();
      } else {
        item.classList.remove("is-open");
        item.navOpenedBy = null;
        items.forEach(function (inner) {
          if (inner !== item && item.contains(inner) && isOpen(inner)) setOpen(inner, false);
        });
      }
      var toggle = toggleOf(item);
      if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }

    function closeAll() {
      items.forEach(function (item) {
        if (isOpen(item)) setOpen(item, false);
      });
    }

    function place() {
      if (!desktop.matches) return;
      var viewport = document.documentElement.clientWidth;
      var topLevel = items.filter(isTopLevel);
      var nested = items.filter(function (item) { return !isTopLevel(item); });

      topLevel.map(function (item) {
        var panel = panelOf(item);
        return [item, !!panel && item.getBoundingClientRect().left + panel.offsetWidth > viewport - EDGE];
      }).forEach(function (decision) {
        decision[0].classList.toggle("opens-left", decision[1]);
      });

      nested.map(function (item) {
        var panel = panelOf(item);
        if (!panel) return [item, false];
        var parentPanel = item.parentElement.getBoundingClientRect();
        var roomRight = viewport - EDGE - parentPanel.right;
        var roomLeft = parentPanel.left - EDGE;
        return [item, panel.offsetWidth > roomRight && roomLeft > roomRight];
      }).forEach(function (decision) {
        decision[0].classList.toggle("opens-left", decision[1]);
      });
    }

    var placeTimer = null;
    window.addEventListener("resize", function () {
      window.clearTimeout(placeTimer);
      placeTimer = window.setTimeout(place, RESIZE_DELAY);
    });
    var onBreakpoint = function () {
      closeAll();
      place();
    };
    if (desktop.addEventListener) desktop.addEventListener("change", onBreakpoint);
    else if (desktop.addListener) desktop.addListener(onBreakpoint);
    place();

    items.forEach(function (item) {
      var toggle = toggleOf(item);
      if (!toggle) return;

      toggle.addEventListener("click", function () {
        // A mouse reaches the toggle through the item, so the hover has
        // already opened the submenu: that click means "keep it open", not
        // "close it". It pins the submenu; the next click closes it.
        if (isOpen(item) && item.navOpenedBy === "hover") {
          setOpen(item, true, "click");
          return;
        }
        setOpen(item, !isOpen(item), "click");
      });

      item.addEventListener("pointerenter", function (e) {
        if (e.pointerType !== "mouse" || !desktop.matches) return;
        window.clearTimeout(item.navCloseTimer);
        if (!isOpen(item)) setOpen(item, true, "hover");
      });
      item.addEventListener("pointerleave", function (e) {
        if (e.pointerType !== "mouse" || !desktop.matches) return;
        if (item.navOpenedBy !== "hover") return;
        window.clearTimeout(item.navCloseTimer);
        item.navCloseTimer = window.setTimeout(function () {
          if (item.navOpenedBy === "hover" && !item.contains(document.activeElement)) setOpen(item, false);
        }, CLOSE_DELAY);
      });

      item.addEventListener("focusout", function (e) {
        var next = e.relatedTarget;
        if (next && !item.contains(next) && isOpen(item)) setOpen(item, false);
      });
    });

    document.addEventListener("click", function (e) {
      items.forEach(function (item) {
        if (isOpen(item) && !item.contains(e.target)) setOpen(item, false);
      });
    });

    function onEscape(e) {
      if (e.key !== "Escape") return;
      var focused = document.activeElement;
      var innermost = null;
      items.forEach(function (item) {
        if (isOpen(item) && item.contains(focused) && (!innermost || innermost.contains(item))) innermost = item;
      });
      if (innermost) {
        setOpen(innermost, false);
        var toggle = toggleOf(innermost);
        if (toggle) toggle.focus();
        e.stopPropagation();
        return;
      }
      if (items.some(isOpen)) closeAll();
    }
    // Inside the menu first, so closing a submenu can stop the Escape before
    // it reaches the mobile menu's own handler on document.
    nav.addEventListener("keydown", onEscape);
    document.addEventListener("keydown", function (e) {
      if (!nav.contains(document.activeElement)) onEscape(e);
    });
  }

  /* ---------------------------------------------------------------------
     Scroll reveal (IntersectionObserver) — staggers siblings sharing a
     data-reveal-group by their DOM order.
     --------------------------------------------------------------------- */
  function initReveal() {
    var items = document.querySelectorAll("[data-reveal]");
    if (!items.length) return;

    if (prefersReducedMotion || !("IntersectionObserver" in window)) {
      items.forEach(function (el) { el.classList.add("is-visible"); });
      return;
    }

    var groups = {};
    items.forEach(function (el) {
      var g = el.dataset.revealGroup || "_default_" + Math.random();
      groups[g] = groups[g] || [];
      groups[g].push(el);
    });
    Object.keys(groups).forEach(function (g) {
      groups[g].forEach(function (el, i) {
        el.style.transitionDelay = Math.min(i * 90, 450) + "ms";
      });
    });

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.16, rootMargin: "0px 0px -40px 0px" });

    items.forEach(function (el) { io.observe(el); });
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initHeader();
    initNavDropdowns();
    initReveal();
  });
})();
