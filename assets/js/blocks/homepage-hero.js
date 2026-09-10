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
     Hero flourish (GSAP) — headline rise, laser line draw, spark burst.
     Falls back silently if GSAP hasn't loaded or motion is reduced.
     --------------------------------------------------------------------- */
  function initHeroMotion() {
    var hero = document.querySelector(".hero");
    if (!hero) return;

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
      .from(hero.querySelectorAll(".laser-line"), {
        scaleX: 0, duration: 0.9, ease: "power2.inOut", stagger: 0.08
      }, "-=0.5")
      .from(hero.querySelector(".hero__media-frame"), {
        opacity: 0, y: 30, duration: 0.9
      }, "-=0.7")
      .from(hero.querySelector(".hero__badge"), {
        opacity: 0, scale: 0.85, duration: 0.6, ease: "back.out(1.6)"
      }, "-=0.3");

    spawnSparks(hero.querySelector(".spark-field"));
  }

  function spawnSparks(field) {
    if (!field || prefersReducedMotion || typeof gsap === "undefined") return;
    var count = window.innerWidth < 640 ? 6 : 14;
    for (var i = 0; i < count; i++) {
      (function () {
        var s = document.createElement("span");
        s.className = "spark";
        field.appendChild(s);
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
      })();
    }
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initHeroMotion();
  });
})();
