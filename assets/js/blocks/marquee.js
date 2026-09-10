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

    var baseItems = Array.prototype.map.call(track.children, function (el) {
      return { nl: el.dataset.nl, en: el.dataset.en };
    });
    if (!baseItems.length) return;

    /* A copy carries both languages, exactly like the server-rendered item
       it came from, and shows whichever one is current. Reading the language
       here (instead of always printing NL and relying on assets/js/core.js's
       applyLang having not run yet) is what lets this file boot before or
       after Core without a visible difference: before step 4 both lived in
       main.js and the marquee happened to be initialised first. */
    function renderCopies(copies) {
      var isEn = document.documentElement.lang === "en";
      track.innerHTML = "";
      for (var c = 0; c < copies; c++) {
        baseItems.forEach(function (item) {
          var span = document.createElement("span");
          span.setAttribute("data-nl", item.nl);
          span.setAttribute("data-en", item.en);
          span.innerHTML = isEn && item.en != null ? item.en : item.nl;
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
