/* =========================================================================
   Content block: card carousel (partials/section-card-carousel.php)
   The 3D orbit showcase, scoped per rendered block instance.
   Asked for by App\Service\Blocks\CardCarouselBlock::scripts().
   ========================================================================= */
(function () {
  "use strict";

  var prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------------------------------------------------------------------
     Orbit carousel — reusable 3D rotating showcase.
     Cards never spin on their own axis: they sit at a fixed point on an
     invisible circle (translateX/Z + scale/opacity/brightness by depth)
     and the whole ring turns together. Falls back to a native horizontal
     snap-scroll strip below 700px, where swipe/drag comes for free, and
     on every width when the block's layout is "row"
     (data-orbit-layout="row").
     Reusable: wire up any container via data-orbit / data-orbit-track /
     data-orbit-card / data-orbit-prev / data-orbit-next / data-orbit-dots.

     THE ACTIVE CARD IS ALWAYS CENTERED. Every move — a button, a dot, the
     keyboard, autoplay — goes to a card's own resting angle, never "one
     step from wherever the ring happens to be". The ring used to drift
     continuously and a click stepped from that drifted angle, so the card
     it stopped on could sit far off-center (with two cards: all the way
     to the side). Autoplay now rests on each card and then turns to the
     next one.
     --------------------------------------------------------------------- */
  function initOrbitCarousels() {
    document.querySelectorAll("[data-orbit]").forEach(setupOrbitCarousel);
  }

  function setupOrbitCarousel(root) {
    var stage = root.querySelector(".orbit-carousel__stage");
    var track = root.querySelector("[data-orbit-track]");
    var cards = Array.prototype.slice.call(root.querySelectorAll("[data-orbit-card]"));
    var prevBtn = root.querySelector("[data-orbit-prev]");
    var nextBtn = root.querySelector("[data-orbit-next]");
    var dotsWrap = root.querySelector("[data-orbit-dots]");
    var n = cards.length;
    if (!stage || !track || n < 1) return;

    var isRowLayout = root.getAttribute("data-orbit-layout") === "row";
    var angleStep = 360 / n;
    var manualDuration = prefersReducedMotion ? 1 : 550;
    var autoplayDuration = 1400;
    var autoplayRestMs = 5000;

    var angle = 0;
    var tweenFrom = null, tweenTo = null, tweenStart = null;
    var tweenDuration = manualDuration;
    var hoverPaused = false, focusPaused = false, isPaused = false;
    var mq = window.matchMedia("(max-width: 699px)");
    var isFlat = isRowLayout || mq.matches;
    var activeIndex = -1;
    var rafId = null;
    var lastFrame = null;
    var restedMs = 0;
    var hoveredIndex = -1;
    var hoverScale = []; // per-card, eases toward 1 (hovered) / 0 (not) each frame

    // The resting angle the ring is commanded toward, kept separate from the
    // live, animating `angle`, so a second click during a turn still goes
    // one card further than the first.
    var targetAngle = 0;

    var dotButtons = [];
    if (dotsWrap && n > 1) {
      cards.forEach(function (card, i) {
        var b = document.createElement("button");
        b.type = "button";
        b.setAttribute("role", "tab");
        b.setAttribute("aria-current", "false");
        b.setAttribute("aria-label", String(i + 1) + " / " + String(n));
        b.addEventListener("click", function () { goTo(i); });
        dotsWrap.appendChild(b);
        dotButtons.push(b);
      });
    }
    if (n < 2) {
      if (prevBtn) prevBtn.hidden = true;
      if (nextBtn) nextBtn.hidden = true;
    }

    function normalize(a) {
      a = a % 360;
      if (a < 0) a += 360;
      return a;
    }
    function mod(i) { return ((i % n) + n) % n; }
    function easeInOutCubic(t) {
      return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }
    function updatePaused() {
      isPaused = hoverPaused || focusPaused;
      if (isPaused) restedMs = 0;
    }

    function setActive(i) {
      if (i === activeIndex) return;
      activeIndex = i;
      if (!isFlat) {
        cards.forEach(function (card, idx) {
          var active = idx === i;
          card.setAttribute("data-orbit-active", active ? "true" : "false");
          card.setAttribute("aria-hidden", active ? "false" : "true");
          var link = card.querySelector("a, button");
          if (link) link.tabIndex = active ? 0 : -1;
        });
      }
      dotButtons.forEach(function (b, idx) {
        b.setAttribute("aria-current", idx === i ? "true" : "false");
      });
    }

    function applyOrbitFrame(dt) {
      var rootStyle = getComputedStyle(root);
      var rx = parseFloat(rootStyle.getPropertyValue("--orbit-rx")) || 340;
      var rz = parseFloat(rootStyle.getPropertyValue("--orbit-rz")) || 260;
      var nearestIndex = 0, nearestDepth = -Infinity;
      var hoverEase = dt > 0 ? Math.min(1, dt / 200) : 1;

      cards.forEach(function (card, i) {
        var world = normalize(i * angleStep + angle);
        var rad = (world * Math.PI) / 180;
        var depth = (Math.cos(rad) + 1) / 2; // 0 = back, 1 = front
        var x = Math.sin(rad) * rx;
        var z = Math.cos(rad) * rz;
        // The centered card grows past its resting size while back cards
        // shrink and dim — a physical stack, not a flat swap. The largest
        // value here (front, hovered) is --orbit-front-scale in the CSS,
        // which sizes the stage so the card never covers the controls.
        var scale = 0.5 + depth * 0.58;
        var opacity = 0.28 + depth * 0.72;
        var brightness = 0.5 + depth * 0.55;

        // Small in-place hover bump, eased toward its target each frame.
        var hoverTarget = i === hoveredIndex ? 1 : 0;
        var hoverCur = hoverScale[i] || 0;
        hoverCur += (hoverTarget - hoverCur) * hoverEase;
        hoverScale[i] = hoverCur;
        scale *= 1 + hoverCur * 0.06;

        card.style.transform = "translate3d(" + x.toFixed(1) + "px, 0, " + z.toFixed(1) + "px)";
        card.style.zIndex = String(Math.round(depth * 1000));
        var inner = card.querySelector(".orbit-card__inner");
        if (inner) {
          inner.style.transform = "scale(" + scale.toFixed(3) + ")";
          inner.style.opacity = opacity.toFixed(3);
          inner.style.filter = "brightness(" + brightness.toFixed(3) + ")";
        }
        if (depth > nearestDepth) { nearestDepth = depth; nearestIndex = i; }
      });

      setActive(nearestIndex);
    }

    /** The card the ring is resting on, or turning to. */
    function commandedIndex() {
      var a = tweenTo !== null ? targetAngle : angle;
      return mod(Math.round(-a / angleStep));
    }

    function ringGoTo(index, opts) {
      if (tweenTo === null) targetAngle = angle;
      // The shortest way round to the card's own resting angle.
      var delta = -index * angleStep - targetAngle;
      delta = ((delta % 360) + 540) % 360 - 180;
      // Two cards are half a turn apart either way: keep the direction the
      // caller asked for rather than whatever the rounding picks.
      if (n === 2 && Math.abs(Math.abs(delta) - 180) < 0.001 && opts && opts.direction) {
        delta = opts.direction > 0 ? -180 : 180;
      }
      targetAngle += delta;
      tweenFrom = angle;
      tweenTo = targetAngle;
      tweenStart = null;
      tweenDuration = opts && opts.ms && !prefersReducedMotion ? opts.ms : manualDuration;
      restedMs = 0;
    }

    /* Flat strip (phone, or the "row" layout): a card's resting place is
       the scroll position its own scroll-snap-align puts it at. */
    function flatScrollFor(card) {
      var align = getComputedStyle(card).scrollSnapAlign || "";
      if (align.indexOf("center") !== -1) {
        return card.offsetLeft - (stage.clientWidth - card.offsetWidth) / 2;
      }
      var padding = parseFloat(getComputedStyle(stage).scrollPaddingLeft) || 0;
      return card.offsetLeft - padding;
    }
    function flatNearestIndex() {
      var best = 0, bestDistance = Infinity;
      cards.forEach(function (card, i) {
        var d = Math.abs(flatScrollFor(card) - stage.scrollLeft);
        if (d < bestDistance) { bestDistance = d; best = i; }
      });
      return best;
    }
    function scrollFlatTo(index) {
      var card = cards[Math.max(0, Math.min(n - 1, index))];
      stage.scrollTo({ left: flatScrollFor(card), behavior: prefersReducedMotion ? "auto" : "smooth" });
    }

    function next() {
      if (isFlat) { scrollFlatTo(Math.min(n - 1, flatNearestIndex() + 1)); return; }
      ringGoTo(mod(commandedIndex() + 1), { direction: 1 });
    }
    function prev() {
      if (isFlat) { scrollFlatTo(Math.max(0, flatNearestIndex() - 1)); return; }
      ringGoTo(mod(commandedIndex() - 1), { direction: -1 });
    }
    function goTo(index) { isFlat ? scrollFlatTo(index) : ringGoTo(index); }

    var scrollTicking = false;
    stage.addEventListener("scroll", function () {
      if (!isFlat || scrollTicking) return;
      scrollTicking = true;
      requestAnimationFrame(function () {
        activeIndex = -1; // force dot refresh via setActive
        setActive(flatNearestIndex());
        scrollTicking = false;
      });
    }, { passive: true });

    /* The "row" layout shows its arrows and dots only when the cards do
       not all fit next to each other. */
    function updateOverflow() {
      if (!isRowLayout) return;
      root.setAttribute("data-orbit-overflow", track.scrollWidth > stage.clientWidth + 1 ? "true" : "false");
    }

    function resetCardStyles() {
      cards.forEach(function (card) {
        card.style.transform = "";
        card.style.zIndex = "";
        card.removeAttribute("aria-hidden");
        card.removeAttribute("data-orbit-active");
        var link = card.querySelector("a, button");
        if (link) link.tabIndex = 0;
        var inner = card.querySelector(".orbit-card__inner");
        if (inner) { inner.style.transform = ""; inner.style.opacity = ""; inner.style.filter = ""; }
      });
    }

    function enterFlatMode() {
      isFlat = true;
      if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      tweenFrom = tweenTo = tweenStart = null;
      resetCardStyles();
      activeIndex = -1;
      setActive(flatNearestIndex());
    }
    function enterOrbitMode() {
      isFlat = false;
      activeIndex = -1;
      lastFrame = null;
      // Back on a card's resting angle, never on a half-turned ring.
      angle = targetAngle = -commandedIndex() * angleStep;
      if (!rafId) rafId = requestAnimationFrame(frame);
    }

    function frame(ts) {
      if (isFlat) { rafId = null; return; }
      if (lastFrame == null) lastFrame = ts;
      var dt = ts - lastFrame;
      lastFrame = ts;

      if (tweenTo !== null) {
        if (tweenStart === null) tweenStart = ts;
        var t = tweenDuration > 1 ? Math.min(1, (ts - tweenStart) / tweenDuration) : 1;
        angle = tweenFrom + (tweenTo - tweenFrom) * easeInOutCubic(t);
        if (t >= 1) { angle = tweenTo; tweenFrom = null; tweenTo = null; tweenStart = null; }
      } else if (!prefersReducedMotion && n > 1 && !isPaused) {
        // Autoplay: rest on the centered card, then turn to the next one.
        restedMs += dt;
        if (restedMs >= autoplayRestMs) {
          ringGoTo(mod(commandedIndex() + 1), { direction: 1, ms: autoplayDuration });
        }
      }

      applyOrbitFrame(dt);
      rafId = requestAnimationFrame(frame);
    }

    if (prevBtn) prevBtn.addEventListener("click", prev);
    if (nextBtn) nextBtn.addEventListener("click", next);
    cards.forEach(function (card, i) {
      card.addEventListener("mouseenter", function () { hoveredIndex = i; });
      card.addEventListener("mouseleave", function () { if (hoveredIndex === i) hoveredIndex = -1; });
    });
    root.addEventListener("mouseenter", function () { hoverPaused = true; updatePaused(); });
    root.addEventListener("mouseleave", function () {
      hoverPaused = false;
      // A prior click leaves its button focused; once the pointer has left
      // the component that stale focus should not keep autoplay paused —
      // only a still-focused keyboard user (no mouseleave) should.
      focusPaused = false;
      updatePaused();
    });
    root.addEventListener("focusin", function () { focusPaused = true; updatePaused(); });
    root.addEventListener("focusout", function () { focusPaused = false; updatePaused(); });
    root.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft") { e.preventDefault(); prev(); }
      else if (e.key === "ArrowRight") { e.preventDefault(); next(); }
    });

    function handleModeChange(e) {
      if (isRowLayout) return;
      if (e.matches && !isFlat) enterFlatMode();
      else if (!e.matches && isFlat) enterOrbitMode();
    }
    if (mq.addEventListener) mq.addEventListener("change", handleModeChange);
    else if (mq.addListener) mq.addListener(handleModeChange);

    var resizeTicking = false;
    window.addEventListener("resize", function () {
      if (resizeTicking) return;
      resizeTicking = true;
      requestAnimationFrame(function () { updateOverflow(); resizeTicking = false; });
    });

    updateOverflow();
    if (isFlat) {
      resetCardStyles();
      setActive(0);
    } else {
      applyOrbitFrame(0);
      rafId = requestAnimationFrame(frame);
    }
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initOrbitCarousels();
  });
})();
