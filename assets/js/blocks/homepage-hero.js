/* =========================================================================
   Content block: homepage hero (partials/section-homepage-hero.php)
   Asked for by App\Service\Blocks\HomepageHeroBlock, together with GSAP —
   the ONE thing on this site that uses it. Before step 4 the GSAP tag sat in
   twelve page templates, eleven of which never render a hero.
   ========================================================================= */
(function () {
  "use strict";

  var prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------------------------------------------------------------------
     Hero flourish (GSAP) — headline rise, the decoration layers drawn in,
     particles in the decoration canvas. Only the neutral hooks
     (data-hero-decoration, data-decoration-layer) are used; what they look
     like is the stylesheet's (THEMING.md, "Decoratie van de
     homepage-opening"). Falls back silently if GSAP hasn't loaded or
     motion is reduced.
     --------------------------------------------------------------------- */
  function initHeroMotion(hero) {
    if (prefersReducedMotion || typeof gsap === "undefined") {
      hero.querySelectorAll(".gsap-init-hide").forEach(function (el) {
        el.style.opacity = 1;
        el.style.transform = "none";
      });
      return;
    }

    var tl = gsap.timeline({ defaults: { ease: "power3.out" } });
    tl.from(hero.querySelectorAll(".hero__eyebrow, .hero h1, .hero__lead, .hero__actions, .hero__meta"), {
      opacity: 0, y: 26, duration: 0.8, stagger: 0.12
    })
      .from(hero.querySelectorAll("[data-decoration-layer]"), {
        scaleX: 0, duration: 0.9, ease: "power2.inOut", stagger: 0.08
      }, "-=0.5")
      .from(hero.querySelector(".hero__media-frame"), {
        opacity: 0, y: 30, duration: 0.9
      }, "-=0.7")
      .from(hero.querySelector(".hero__badge"), {
        opacity: 0, scale: 0.85, duration: 0.6, ease: "back.out(1.6)"
      }, "-=0.3");

    spawnParticles(hero.querySelector("[data-hero-decoration]"));
  }

  // A theme that hides the canvas, or the particles, gets none: the first
  // particle is measured and taken out again before any tween starts.
  function spawnParticles(field) {
    if (!field || prefersReducedMotion || typeof gsap === "undefined") return;
    if (window.getComputedStyle(field).display === "none") return;
    var count = window.innerWidth < 640 ? 6 : 14;
    for (var i = 0; i < count; i++) {
      var s = document.createElement("span");
      s.className = "hero-decoration__particle";
      field.appendChild(s);
      if (i === 0 && window.getComputedStyle(s).display === "none") {
        field.removeChild(s);
        return;
      }
      (function (s) {
        function burst() {
          var x = Math.random() * field.clientWidth;
          var y = Math.random() * field.clientHeight;
          gsap.set(s, { x: x, y: y, opacity: 0, scale: 0.5 });
          gsap.to(s, {
            opacity: 1, scale: 1.4, duration: 0.25, ease: "power1.out",
            onComplete: function () {
              gsap.to(s, {
                opacity: 0, y: y + 24 + Math.random() * 30, duration: 0.6 + Math.random() * 0.5,
                ease: "power1.in", onComplete: burst,
                delay: Math.random() * 2.2
              });
            }
          });
        }
        gsap.delayedCall(Math.random() * 2, burst);
      })(s);
    }
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".hero").forEach(initHeroMotion);
  });
})();
