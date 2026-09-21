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

   What does NOT belong here: anything a single content block, the portfolio
   detail page or the Shop owns. Those live next to their own markup —
   assets/js/blocks/*.js, assets/js/portfolio-detail.js, assets/js/shop/*.js
   — and are asked for by the block definition or route that needs them.
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
   * CMS-managed nav dropdowns (App\Service\NavigationService — a top-level
   * item with children renders a .main-nav__toggle button + .main-nav__submenu
   * instead of a plain link; see partials/header.php). Click/Enter/Space
   * toggles aria-expanded + the .is-open class both functions and CSS key
   * off; desktop CSS also reveals the panel on plain :hover as a mouse-only
   * convenience, independent of this JS state. Works identically for the
   * mobile in-place expand/collapse (only the CSS differs, see style.css).
   */
  function initNavDropdowns() {
    var items = document.querySelectorAll(".main-nav__item--has-children");
    if (!items.length) return;

    function closeItem(item) {
      item.classList.remove("is-open");
      var btn = item.querySelector(".main-nav__toggle");
      if (btn) btn.setAttribute("aria-expanded", "false");
    }
    function closeAllExcept(except) {
      items.forEach(function (item) {
        if (item !== except) closeItem(item);
      });
    }

    items.forEach(function (item) {
      var toggle = item.querySelector(".main-nav__toggle");
      if (!toggle) return;

      toggle.addEventListener("click", function () {
        var isOpen = item.classList.contains("is-open");
        closeAllExcept(item);
        if (isOpen) {
          closeItem(item);
        } else {
          item.classList.add("is-open");
          toggle.setAttribute("aria-expanded", "true");
        }
      });
    });

    document.addEventListener("click", function (e) {
      items.forEach(function (item) {
        if (!item.contains(e.target)) closeItem(item);
      });
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") items.forEach(closeItem);
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
