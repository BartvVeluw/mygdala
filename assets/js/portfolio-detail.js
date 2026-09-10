/* =========================================================================
   Portfolio detail page (portfolio-detail.php) — the project lightbox, with
   prev/next/counter over every [data-project-lightbox-trigger] on the page.
   Asked for by that route, not by a block: the page is not built out of
   content blocks.
   ========================================================================= */
(function () {
  "use strict";

  var docEl = document.documentElement;

  /* ---------------------------------------------------------------------
     Project detail lightbox (portfolio-detail.php) — a separate instance
     from the item gallery block's (assets/js/blocks/item-gallery.js): that
     one shows a single non-linked Portfolio
     card image with no navigation, this one chains every
     [data-project-lightbox-trigger] on the page (main project image, then
     every gallery image, in DOM order) into one next/prev/counter sequence.
     Both use the shared .lightbox/.lightbox__inner/.lightbox__close markup
     and CSS; this only adds prev/next + a counter on top.
     --------------------------------------------------------------------- */
  function initProjectLightbox() {
    var triggers = Array.prototype.slice.call(document.querySelectorAll("[data-project-lightbox-trigger]"));
    var lightbox = document.querySelector("[data-project-lightbox]");
    if (!triggers.length || !lightbox) return;

    var imgEl = lightbox.querySelector(".lightbox__inner img");
    var captionEl = lightbox.querySelector(".lightbox__caption");
    var counterEl = lightbox.querySelector("[data-lightbox-counter]");
    var closeBtn = lightbox.querySelector("[data-lightbox-close]");
    var prevBtn = lightbox.querySelector("[data-lightbox-prev]");
    var nextBtn = lightbox.querySelector("[data-lightbox-next]");
    var lastFocused = null;
    var currentIndex = 0;

    function altFor(trigger) {
      var lang = docEl.lang === "en" ? "en" : "nl";
      var alt = lang === "en" ? trigger.dataset.altEn : trigger.dataset.altNl;
      return alt || trigger.dataset.altNl || "";
    }

    function render(index) {
      currentIndex = (index + triggers.length) % triggers.length;
      var trigger = triggers[currentIndex];
      var alt = altFor(trigger);
      imgEl.src = trigger.dataset.src;
      imgEl.alt = alt;
      captionEl.textContent = alt;
      if (counterEl) counterEl.textContent = (currentIndex + 1) + " / " + triggers.length;
    }

    function open(index) {
      lastFocused = document.activeElement;
      render(index);
      lightbox.classList.add("is-open");
      lightbox.setAttribute("aria-hidden", "false");
      document.body.style.overflow = "hidden";
      closeBtn.focus();
    }
    function close() {
      lightbox.classList.remove("is-open");
      lightbox.setAttribute("aria-hidden", "true");
      document.body.style.overflow = "";
      if (lastFocused) lastFocused.focus();
    }
    function next() { render(currentIndex + 1); }
    function prev() { render(currentIndex - 1); }

    triggers.forEach(function (trigger, i) {
      trigger.addEventListener("click", function () { open(i); });
    });

    if (triggers.length > 1 && prevBtn && nextBtn) {
      prevBtn.addEventListener("click", prev);
      nextBtn.addEventListener("click", next);
    } else if (prevBtn && nextBtn) {
      prevBtn.hidden = true;
      nextBtn.hidden = true;
    }

    closeBtn.addEventListener("click", close);
    lightbox.addEventListener("click", function (e) { if (e.target === lightbox) close(); });
    document.addEventListener("keydown", function (e) {
      if (!lightbox.classList.contains("is-open")) return;
      if (e.key === "Escape") close();
      else if (e.key === "ArrowRight") next();
      else if (e.key === "ArrowLeft") prev();
    });
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initProjectLightbox();
  });
})();
