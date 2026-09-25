/* =========================================================================
   The site's one lightbox (partials/lightbox.php): a picture enlarged over
   the page, with previous/next through the pictures of the same group.

   Shared, and owned by nobody but its callers: the gallery block and the
   Projecten block ask for it (App\Service\Blocks\ItemGalleryBlock::scripts(),
   ProjectCardsBlock::scripts()), and so does a Portfolio project page
   (portfolio-detail.php). Portfolio 2.0 replaced the two lightboxes that
   used to live in assets/js/blocks/item-gallery.js and
   assets/js/portfolio-detail.js with this one.

   THE CONTRACT, all in the markup:

     [data-lightbox]          the overlay, printed once per page
     [data-lightbox-trigger]  a <button> that opens it, with data-src,
                              data-alt and data-caption
     [data-lightbox-group]    the element whose triggers form one sequence:
                              a gallery block, or a project page's pictures

   WHICH PICTURES, AND IN WHAT ORDER, is decided the moment the lightbox
   opens: every trigger of the opener's group that is shown right now, in
   page order. A gallery filtered on a category hides its other cards
   (display: none), so previous/next never reach a picture the visitor
   cannot see, and a new filter gives a new sequence the next time it opens.
   Only the opener's group: a project page never steps into another block's
   pictures, and one gallery never into another's.

   It wraps around at both ends, as the project page's lightbox always did.

   A DIALOG: role="dialog" and aria-modal on the overlay, focus moved into it
   on open and kept there (Tab and Shift+Tab cycle its buttons), Escape and
   the × close it, ← and → step, a click on the backdrop closes, a
   horizontal swipe steps on a touch screen, and focus goes back to the
   picture that opened it. Every word is the page's: the buttons carry their
   own aria-label, and a caption is written with textContent, never as
   markup.
   ========================================================================= */
(function () {
  "use strict";

  var SWIPE_DISTANCE = 50;

  function isShown(element) {
    return element.getClientRects().length > 0;
  }

  function init() {
    var overlay = document.querySelector("[data-lightbox]");
    if (!overlay) return;

    var imgEl = overlay.querySelector("[data-lightbox-image]");
    var captionEl = overlay.querySelector("[data-lightbox-caption]");
    var counterEl = overlay.querySelector("[data-lightbox-counter]");
    var closeBtn = overlay.querySelector("[data-lightbox-close]");
    var prevBtn = overlay.querySelector("[data-lightbox-prev]");
    var nextBtn = overlay.querySelector("[data-lightbox-next]");

    var slides = [];
    var current = 0;
    var opener = null;
    var touchStartX = null;

    function isOpen() {
      return overlay.classList.contains("is-open");
    }

    function render() {
      var slide = slides[current];
      imgEl.src = slide.src;
      imgEl.alt = slide.alt;
      captionEl.textContent = slide.caption;
      captionEl.hidden = slide.caption === "";

      var several = slides.length > 1;
      prevBtn.hidden = !several;
      nextBtn.hidden = !several;
      counterEl.hidden = !several;
      counterEl.textContent = several ? (current + 1) + " / " + slides.length : "";
    }

    function step(delta) {
      if (slides.length < 2) return;
      current = (current + delta + slides.length) % slides.length;
      render();
    }

    function open(trigger) {
      var group = trigger.closest("[data-lightbox-group]") || document;
      var triggers = Array.prototype.filter.call(
        group.querySelectorAll("[data-lightbox-trigger]"),
        isShown
      );
      if (triggers.indexOf(trigger) === -1) triggers = [trigger];

      slides = triggers.map(function (t) {
        return {
          src: t.getAttribute("data-src") || "",
          alt: t.getAttribute("data-alt") || "",
          caption: t.getAttribute("data-caption") || ""
        };
      });
      current = triggers.indexOf(trigger);
      opener = trigger;

      render();
      overlay.classList.add("is-open");
      overlay.setAttribute("aria-hidden", "false");
      document.body.style.overflow = "hidden";
      closeBtn.focus();
    }

    function close() {
      if (!isOpen()) return;
      overlay.classList.remove("is-open");
      overlay.setAttribute("aria-hidden", "true");
      document.body.style.overflow = "";
      imgEl.removeAttribute("src");
      if (opener && document.contains(opener)) opener.focus();
      opener = null;
    }

    /** The overlay's own buttons that can take focus right now, in order. */
    function focusable() {
      return [closeBtn, prevBtn, nextBtn].filter(function (b) { return b && !b.hidden; });
    }

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest ? event.target.closest("[data-lightbox-trigger]") : null;
      if (!trigger || overlay.contains(trigger)) return;
      event.preventDefault();
      open(trigger);
    });

    closeBtn.addEventListener("click", close);
    prevBtn.addEventListener("click", function () { step(-1); });
    nextBtn.addEventListener("click", function () { step(1); });
    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) close();
    });

    document.addEventListener("keydown", function (event) {
      if (!isOpen()) return;

      if (event.key === "Escape") {
        event.preventDefault();
        close();
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        step(1);
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        step(-1);
      } else if (event.key === "Tab") {
        var buttons = focusable();
        var at = buttons.indexOf(document.activeElement);
        event.preventDefault();
        var next = at === -1
          ? 0
          : (at + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length;
        buttons[next].focus();
      }
    });

    overlay.addEventListener("touchstart", function (event) {
      touchStartX = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, { passive: true });
    overlay.addEventListener("touchend", function (event) {
      if (touchStartX === null || event.changedTouches.length !== 1) return;
      var dx = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(dx) >= SWIPE_DISTANCE) step(dx < 0 ? 1 : -1);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
