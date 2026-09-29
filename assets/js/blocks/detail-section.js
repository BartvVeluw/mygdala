/* =========================================================================
   Content block: Detailsectie (partials/section-detail-section.php) — its
   gallery on a phone. Asked for by
   App\Service\Blocks\DetailSectionBlock::scripts().

   ON A PHONE THE GALLERY IS ONE ITEM AT A TIME: a native horizontal strip
   with scroll-snap (assets/css/blocks/detail-section.css), exactly the
   pattern the Kaarten-carrousel falls back to below its breakpoint
   (assets/js/blocks/card-carousel.js). A swipe is the browser's own scroll,
   so a tap on a linked item (a product, a project, a blog post) still
   follows its link and a sideways swipe never does — there is no gesture
   code here to get that wrong. Above the breakpoint it is the grid it always
   was and this file does nothing visible.

   THE ARROWS step one item and stop at either end, disabled there, as the
   carousel's flat strip does (no wrap-around): the strip shows where it is.
   They are real buttons (Tab, Enter, Space), with the words the partial
   printed, and the strip itself is a focusable region the arrow keys scroll.
   A visitor who asked for less motion gets the jump without the glide.

   SCOPED PER INSTANCE: every [data-detail-gallery] is its own strip.
   ========================================================================= */
(function () {
  "use strict";

  var reducedMotion = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);

  function setup(root) {
    var strip = root.querySelector("[data-detail-gallery-strip]");
    var items = Array.prototype.slice.call(root.querySelectorAll("[data-detail-gallery-item]"));
    var prev = root.querySelector("[data-detail-gallery-prev]");
    var next = root.querySelector("[data-detail-gallery-next]");
    var controls = root.querySelector("[data-detail-gallery-controls]");
    if (!strip || items.length < 2 || !prev || !next) return;

    if (controls) controls.hidden = false;

    /** The item whose left edge is closest to the strip's own. */
    function current() {
      var left = strip.scrollLeft;
      var best = 0;
      var distance = Infinity;
      items.forEach(function (item, index) {
        var d = Math.abs(item.offsetLeft - strip.offsetLeft - left);
        if (d < distance) {
          distance = d;
          best = index;
        }
      });
      return best;
    }

    function go(index) {
      var target = items[Math.max(0, Math.min(items.length - 1, index))];
      strip.scrollTo({ left: target.offsetLeft - strip.offsetLeft, behavior: reducedMotion ? "auto" : "smooth" });
    }

    function sync() {
      var index = current();
      prev.disabled = index === 0;
      next.disabled = index === items.length - 1;
    }

    prev.addEventListener("click", function () { go(current() - 1); });
    next.addEventListener("click", function () { go(current() + 1); });

    var ticking = false;
    strip.addEventListener("scroll", function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        ticking = false;
        sync();
      });
    }, { passive: true });

    strip.addEventListener("keydown", function (event) {
      if (event.key === "ArrowRight") {
        event.preventDefault();
        go(current() + 1);
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        go(current() - 1);
      }
    });

    window.addEventListener("resize", sync);
    sync();
  }

  function start() {
    document.querySelectorAll("[data-detail-gallery]").forEach(setup);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
