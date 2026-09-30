/* =========================================================================
   Content block: Reviews (partials/section-reviews.php)
   Asked for by App\Service\Blocks\ReviewsBlock::scripts().

   Only the carousel layout has behaviour, and only a little: the strip is a
   native horizontal scroll with snap points (swipe on a phone, the arrow
   keys once it has focus, a trackpad), so it works without this script.
   This script adds what a strip cannot do by itself, per instance:

     - the arrow buttons: one review further or back, smooth unless the
       visitor asked for less motion;
     - they only show when the reviews do not all fit, and are disabled at
       either end;
     - a short status, "1–3 / 5", that a screen reader hears after a move.

   It never moves by itself. The same flat-strip contract as the
   Kaarten-carrousel's row layout (assets/js/blocks/card-carousel.js), without
   its 3D ring and autoplay. No requestAnimationFrame: a timer is enough to
   wait for the end of a scroll.
   ========================================================================= */
(function () {
  "use strict";

  var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  function setup(root) {
    var track = root.querySelector("[data-reviews-track]");
    var slides = Array.prototype.slice.call(root.querySelectorAll("[data-reviews-slide]"));
    var controls = root.querySelector("[data-reviews-controls]");
    var prev = root.querySelector("[data-reviews-prev]");
    var next = root.querySelector("[data-reviews-next]");
    var status = root.querySelector("[data-reviews-status]");
    if (!track || !controls || !prev || !next || slides.length < 2) return;

    /* The distance from one review to the next: its width plus the gap. */
    function step() {
      var first = slides[0].getBoundingClientRect();
      var second = slides[1].getBoundingClientRect();
      return Math.abs(second.left - first.left) || first.width || 1;
    }

    function update() {
      var max = track.scrollWidth - track.clientWidth;
      var overflows = max > 2;
      controls.hidden = !overflows;
      if (!overflows) return;

      var left = Math.abs(track.scrollLeft);
      prev.disabled = left <= 2;
      next.disabled = left >= max - 2;

      if (status) {
        var size = step();
        var first = Math.min(slides.length - 1, Math.round(left / size));
        var shown = Math.max(1, Math.round((track.clientWidth + 1) / size));
        var last = Math.min(slides.length, first + shown);
        status.textContent = (last - first > 1 ? (first + 1) + "–" + last : String(first + 1)) + " / " + slides.length;
      }
    }

    function go(direction) {
      track.scrollBy({ left: direction * step(), behavior: reducedMotion.matches ? "auto" : "smooth" });
    }

    prev.addEventListener("click", function () { go(-1); });
    next.addEventListener("click", function () { go(1); });

    var timer = null;
    function later() {
      if (timer !== null) clearTimeout(timer);
      timer = setTimeout(function () { timer = null; update(); }, 60);
    }
    track.addEventListener("scroll", later, { passive: true });
    window.addEventListener("resize", later);

    update();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-reviews-carousel]"), setup);
})();
