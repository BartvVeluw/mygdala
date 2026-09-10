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
     snap-scroll strip below 700px, where swipe/drag comes for free.
     Reusable: wire up any container via data-orbit / data-orbit-track /
     data-orbit-card / data-orbit-prev / data-orbit-next / data-orbit-dots.
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

    var angleStep = 360 / n;
    var speedDegPerSec = parseFloat(root.dataset.orbitSpeed || "9");
    var manualDuration = prefersReducedMotion ? 1 : 550;

    var angle = 0;
    var tweenFrom = null, tweenTo = null, tweenStart = null;
    var hoverPaused = false, focusPaused = false, isPaused = false;
    var isFlat = window.matchMedia("(max-width: 699px)").matches;
    var activeIndex = -1;
    var rafId = null;
    var lastFrame = null;
    var hoveredIndex = -1;
    var hoverScale = []; // per-card, eases toward 1 (hovered) / 0 (not) each frame
    var speedFactor = 1; // eases toward 0 while paused so autoplay glides to a stop instead of freezing

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
    function easeInOutCubic(t) {
      return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }
    function updatePaused() { isPaused = hoverPaused || focusPaused; }

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
        // Wider range than a neutral 0–1 scale so the centered card visibly
        // grows past its resting size (pulls toward the viewer) while back
        // cards shrink and dim further away — a physical stack, not a flat swap.
        var scale = 0.5 + depth * 0.58;
        var opacity = 0.28 + depth * 0.72;
        var brightness = 0.5 + depth * 0.55;

        // Small in-place hover bump — eased toward its target each frame
        // (never snapped) so it grows/settles smoothly, and never moves the
        // card itself: only the existing depth-based transform does that.
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

    function startTween(from, to, duration) {
      tweenFrom = from;
      tweenTo = to;
      tweenStart = null;
      tweenDuration = duration;
    }
    var tweenDuration = manualDuration;
    // The resting angle we're commanding the ring toward — kept separate
    // from the live, currently-animating `angle` so that a next()/prev()/
    // goTo() fired before a prior tween finishes still advances by exactly
    // one full step (chained from the last command), instead of re-deriving
    // its step from a live angle that's still mid-flight.
    var targetAngle = 0;

    function syncTargetToLive() {
      if (tweenTo === null) targetAngle = angle; // idle or autoplay-drifted: resync first
    }
    function ringNext() {
      syncTargetToLive();
      targetAngle -= angleStep;
      startTween(angle, targetAngle, manualDuration);
    }
    function ringPrev() {
      syncTargetToLive();
      targetAngle += angleStep;
      startTween(angle, targetAngle, manualDuration);
    }
    function ringGoTo(index) {
      syncTargetToLive();
      var raw = -index * angleStep;
      var delta = raw - targetAngle;
      delta = ((delta % 360) + 540) % 360 - 180;
      targetAngle += delta;
      startTween(angle, targetAngle, manualDuration);
    }

    function cardStep() {
      if (!cards[0]) return 0;
      var gap = parseFloat(getComputedStyle(track).gap) || 0;
      return cards[0].getBoundingClientRect().width + gap;
    }
    function scrollFlatBy(dir) { stage.scrollBy({ left: dir * cardStep(), behavior: prefersReducedMotion ? "auto" : "smooth" }); }
    function scrollFlatTo(index) { stage.scrollTo({ left: index * cardStep(), behavior: prefersReducedMotion ? "auto" : "smooth" }); }

    function next() { isFlat ? scrollFlatBy(1) : ringNext(); }
    function prev() { isFlat ? scrollFlatBy(-1) : ringPrev(); }
    function goTo(index) { isFlat ? scrollFlatTo(index) : ringGoTo(index); }

    var scrollTicking = false;
    stage.addEventListener("scroll", function () {
      if (!isFlat || scrollTicking) return;
      scrollTicking = true;
      requestAnimationFrame(function () {
        var w = cardStep();
        var idx = w ? Math.round(stage.scrollLeft / w) : 0;
        idx = Math.max(0, Math.min(n - 1, idx));
        activeIndex = -1; // force dot refresh via setActive
        setActive(idx);
        scrollTicking = false;
      });
    }, { passive: true });

    function resetCardStyles() {
      cards.forEach(function (card) {
        card.style.transform = "";
        card.style.zIndex = "";
        card.removeAttribute("aria-hidden");
        var link = card.querySelector("a, button");
        if (link) link.tabIndex = 0;
        var inner = card.querySelector(".orbit-card__inner");
        if (inner) { inner.style.transform = ""; inner.style.opacity = ""; inner.style.filter = ""; }
      });
    }

    function enterFlatMode() {
      isFlat = true;
      if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      resetCardStyles();
      activeIndex = -1;
      setActive(0);
    }
    function enterOrbitMode() {
      isFlat = false;
      activeIndex = -1;
      lastFrame = null;
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
      } else if (!prefersReducedMotion && n > 1) {
        // speedFactor eases toward 0 (paused) or 1 (running) instead of the
        // autoplay increment switching on/off instantly — the ring glides to
        // a stop on hover/focus rather than freezing mid-turn.
        var speedTarget = isPaused ? 0 : 1;
        var speedEase = dt > 0 ? Math.min(1, dt / 260) : 1;
        speedFactor += (speedTarget - speedFactor) * speedEase;
        if (Math.abs(speedFactor) > 0.0008) {
          angle -= speedDegPerSec * speedFactor * (dt / 1000);
        }
      }

      applyOrbitFrame(dt);
      rafId = requestAnimationFrame(frame);
    }

    if (prevBtn) prevBtn.addEventListener("click", prev);
    if (nextBtn) nextBtn.addEventListener("click", next);
    // Hovering any card (not just the active one) gives it a small in-place
    // scale bump — no rotation, the ring itself doesn't move. hoverScale
    // eases toward its target each frame in applyOrbitFrame() rather than
    // snapping, so the bump grows/settles smoothly.
    cards.forEach(function (card, i) {
      card.addEventListener("mouseenter", function () { hoveredIndex = i; });
      card.addEventListener("mouseleave", function () { if (hoveredIndex === i) hoveredIndex = -1; });
    });
    root.addEventListener("mouseenter", function () { hoverPaused = true; updatePaused(); });
    root.addEventListener("mouseleave", function () {
      hoverPaused = false;
      // A prior click leaves its button focused; once the pointer has
      // actually left the component that stale focus shouldn't keep
      // autoplay paused forever — only a still-focused keyboard user
      // (who never fires mouseleave) should keep it paused.
      focusPaused = false;
      updatePaused();
    });
    root.addEventListener("focusin", function () { focusPaused = true; updatePaused(); });
    root.addEventListener("focusout", function () { focusPaused = false; updatePaused(); });
    root.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft") { e.preventDefault(); prev(); }
      else if (e.key === "ArrowRight") { e.preventDefault(); next(); }
    });

    var mq = window.matchMedia("(max-width: 699px)");
    function handleModeChange(e) {
      if (e.matches && !isFlat) enterFlatMode();
      else if (!e.matches && isFlat) enterOrbitMode();
    }
    if (mq.addEventListener) mq.addEventListener("change", handleModeChange);
    else if (mq.addListener) mq.addListener(handleModeChange);

    if (isFlat) {
      resetCardStyles();
      setActive(0);
    } else {
      applyOrbitFrame();
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
