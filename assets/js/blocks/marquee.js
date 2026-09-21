/* =========================================================================
   Content block: marquee (partials/section-marquee.php)
   Asked for by App\Service\Blocks\MarqueeBlock::scripts().
   ========================================================================= */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------
     Material / category marquee — seamless infinite scroll.
     Content (the item labels) comes from the CMS via MarqueeContent and is
     rendered server-side into .marquee__track (see index.php) — this
     function only owns behaviour: it reads whatever items PHP already
     rendered, then duplicates them into two identical halves so the CSS
     `marquee` keyframes (translateX 0 → -50%) loop with no visible seam,
     padded with extra copies (and the animation duration scaled to match)
     so that a single half is always wider than the viewport — otherwise
     very wide screens would briefly show blank space past the end of a
     short track.
     --------------------------------------------------------------------- */
  var MARQUEE_PX_PER_SEC = 46;

  function initMarquee() {
    var track = document.querySelector("[data-marquee-track]");
    if (!track) return;

    /* The labels exactly as the server printed them: already in the language
       of the page, so a copy is simply the same words again. */
    var baseItems = Array.prototype.map.call(track.children, function (el) {
      return el.textContent;
    });
    if (!baseItems.length) return;

    function renderCopies(copies) {
      track.textContent = "";
      for (var c = 0; c < copies; c++) {
        baseItems.forEach(function (label) {
          var span = document.createElement("span");
          // Marquee labels are plain-text CMS material/category names, so
          // textContent — never innerHTML.
          span.textContent = label;
          track.appendChild(span);
        });
      }
    }

    var baseCopies = 2; // minimum for the -50% loop to line up
    renderCopies(baseCopies);
    var halfWidth = track.scrollWidth / 2;
    if (halfWidth > 0) {
      var targetHalfWidth = Math.max(window.innerWidth * 1.5, 1600);
      var extra = Math.max(1, Math.ceil(targetHalfWidth / halfWidth));
      if (extra > 1) {
        renderCopies(baseCopies * extra);
        halfWidth = track.scrollWidth / 2;
      }
      track.style.setProperty("--marquee-duration", (halfWidth / MARQUEE_PX_PER_SEC).toFixed(2) + "s");
    }
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initMarquee();
  });
})();
