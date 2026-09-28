/* =========================================================================
   The media sequence (partials/media-sequence.php): more than one picture or
   video in one frame, one after the other. One controller per
   [data-media-sequence] root on the page, each on its own.

   Shared, and owned by nobody but its callers, like assets/js/lightbox.js:
   the Paginakop and the Mediabanner ask for it
   (App\Service\Blocks\PageHeroBlock::scripts(), MediaBannerBlock::scripts()).

   THE CONTRACT, all in the markup:

     [data-media-sequence]            the root, with its options:
       -transition="fade|slide|none"  how one item gives way to the next
       -duration="5"                  seconds a picture stays
       -autoplay                      it moves on by itself
       -loop                          after the last item, the first again
       -hover-pause                   a mouse over it holds the current item
       -swipe                         a horizontal swipe steps (touch)
     [data-media-sequence-slide]      one item, data-kind="image|video"
     [data-media-sequence-controls]   the buttons, hidden until this runs
     [data-media-sequence-prev|next|dot|pause]

   PLAYING BY ITSELF: a picture stays `duration` seconds; a video plays to its
   end, muted, and then the next item comes. A video that the browser will
   not start (a power-saving mode) gets its controls and is treated like a
   picture, so the sequence never stalls on it. No video ever loops inside a
   sequence: the sequence itself starts again when it loops.

   STOPPING: the pause button stops and resumes it (WCAG 2.2.2), and pausing
   also pauses a playing video. It also holds while the tab is not visible,
   while a mouse rests on a root with -hover-pause, and while the keyboard is
   on one of its arrows or dots. For a visitor who asked for less motion it
   starts paused: nothing moves until they press "afspelen", and nothing
   slides or fades then either (assets/css/media-sequence.css).

   A11Y: the item in view is the only one assistive technology reaches; the
   others are aria-hidden and inert (a decorative track is hidden as a
   whole). A dot says which item it is, and aria-current marks the one in
   view. Every word is the page's own; this file holds none.
   ========================================================================= */
(function () {
  "use strict";

  var reducedMotion = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);

  /** How long a slide's own transition runs, so an item that left can be put back in place afterwards. */
  var SLIDE_MS = 800;

  function start(root) {
    var slides = Array.prototype.slice.call(root.querySelectorAll("[data-media-sequence-slide]"));
    if (slides.length < 2) return;

    var track = root.querySelector("[data-media-sequence-track]");
    var transition = root.getAttribute("data-media-sequence-transition") || "fade";
    var seconds = parseInt(root.getAttribute("data-media-sequence-duration"), 10) || 5;
    var autoplay = root.hasAttribute("data-media-sequence-autoplay");
    var loop = root.hasAttribute("data-media-sequence-loop");
    var hoverPause = root.hasAttribute("data-media-sequence-hover-pause");
    var decorative = !!track && track.getAttribute("aria-hidden") === "true";

    var controls = root.querySelector("[data-media-sequence-controls]");
    var prevButton = root.querySelector("[data-media-sequence-prev]");
    var nextButton = root.querySelector("[data-media-sequence-next]");
    var dots = Array.prototype.slice.call(root.querySelectorAll("[data-media-sequence-dot]"));
    var pauseButton = root.querySelector("[data-media-sequence-pause]");

    var current = 0;
    var playing = autoplay && !reducedMotion;
    var hovered = false;
    var focusHeld = false;
    var timer = null;

    root.classList.add("is-sequencing");
    if (controls) controls.hidden = false;

    function videoOf(slide) {
      return slide.getAttribute("data-kind") === "video" ? slide.querySelector("video") : null;
    }

    /** A video that should have moved on by itself gets a way to be started instead. */
    function giveControls(video) {
      video.controls = true;
      video.removeAttribute("aria-hidden");
    }

    function show(index) {
      slides.forEach(function (slide, i) {
        var active = i === index;
        slide.classList.toggle("is-active", active);
        if (!decorative) {
          if (active) {
            slide.removeAttribute("aria-hidden");
          } else {
            slide.setAttribute("aria-hidden", "true");
          }
          slide.inert = !active;
        }
      });
      dots.forEach(function (dot, i) {
        dot.setAttribute("aria-current", i === index ? "true" : "false");
      });
    }

    function updatePauseButton() {
      if (!pauseButton) return;
      pauseButton.setAttribute("data-state", playing ? "playing" : "paused");
      pauseButton.setAttribute("aria-label", pauseButton.getAttribute(playing ? "data-label-pause" : "data-label-play") || "");
    }

    function stopVideo(slide) {
      var video = videoOf(slide);
      if (!video) return;
      video.pause();
      try {
        video.currentTime = 0;
      } catch (e) {
        /* not seekable yet: it starts at the beginning anyway */
      }
    }

    function wait() {
      window.clearTimeout(timer);
      timer = window.setTimeout(advance, seconds * 1000);
    }

    /** Whether anything holds the sequence where it is right now. */
    function held() {
      return !playing || hovered || focusHeld || document.hidden;
    }

    /** Starts the clock of the item in view, or its video. */
    function schedule() {
      window.clearTimeout(timer);
      timer = null;
      if (held()) return;

      var video = videoOf(slides[current]);
      if (!video) {
        wait();
        return;
      }

      if (video.ended) {
        advance();
        return;
      }

      video.muted = true;
      var attempt = video.play();
      if (attempt && typeof attempt.catch === "function") {
        attempt.catch(function () {
          // The browser would not start it: controls, and a picture's time.
          giveControls(video);
          if (!held()) wait();
        });
      }
      // The next item comes when this video has ended (see below).
    }

    function advance() {
      if (!loop && current === slides.length - 1) {
        playing = false;
        updatePauseButton();
        return;
      }
      go((current + 1) % slides.length, 1);
    }

    function go(to, direction) {
      if (to === current) return;

      var from = slides[current];
      var into = slides[to];
      stopVideo(from);

      if (transition === "slide" && !reducedMotion) {
        // The incoming item starts on the side it comes from, with no
        // transition, and then slides in; the outgoing one slides out the
        // other way and is put back once it is out of sight.
        into.classList.add("is-entering");
        into.style.transform = "translateX(" + (direction * 100) + "%)";
        void into.offsetWidth;
        into.classList.remove("is-entering");
        into.style.transform = "";
        from.style.transform = "translateX(" + (-direction * 100) + "%)";
        window.setTimeout(function () {
          if (!from.classList.contains("is-active")) from.style.transform = "";
        }, SLIDE_MS);
      }

      current = to;
      show(to);
      schedule();
    }

    function step(direction) {
      go((current + direction + slides.length) % slides.length, direction);
    }

    // A video that ends while it is in view makes way, if the sequence plays.
    slides.forEach(function (slide, index) {
      var video = videoOf(slide);
      if (!video) return;
      video.loop = false;
      video.addEventListener("ended", function () {
        if (index === current && playing) {
          if (held()) return;
          advance();
        }
      });
    });

    if (prevButton) prevButton.addEventListener("click", function () { step(-1); });
    if (nextButton) nextButton.addEventListener("click", function () { step(1); });
    dots.forEach(function (dot) {
      dot.addEventListener("click", function () {
        var to = parseInt(dot.getAttribute("data-media-sequence-dot"), 10) || 0;
        go(to, to > current ? 1 : -1);
      });
    });

    if (pauseButton) {
      pauseButton.addEventListener("click", function () {
        playing = !playing;
        if (!playing) {
          window.clearTimeout(timer);
          var video = videoOf(slides[current]);
          if (video) video.pause();
        }
        updatePauseButton();
        schedule();
      });
    }

    // The keyboard on an arrow or a dot holds the item and steps with ← →.
    root.addEventListener("focusin", function (event) {
      if (event.target !== pauseButton && event.target && event.target.closest && event.target.closest("[data-media-sequence-controls]")) {
        focusHeld = true;
        window.clearTimeout(timer);
      }
    });
    root.addEventListener("focusout", function (event) {
      var next = event.relatedTarget;
      if (!next || !root.contains(next) || next === pauseButton) {
        focusHeld = false;
        schedule();
      }
    });
    root.addEventListener("keydown", function (event) {
      var onControl = event.target && event.target.closest && event.target.closest("[data-media-sequence-prev], [data-media-sequence-next], [data-media-sequence-dot]");
      if (!onControl) return;
      if (event.key === "ArrowLeft") {
        step(-1);
        event.preventDefault();
      } else if (event.key === "ArrowRight") {
        step(1);
        event.preventDefault();
      }
    });

    if (hoverPause) {
      root.addEventListener("pointerenter", function (event) {
        if (event.pointerType !== "mouse") return;
        hovered = true;
        window.clearTimeout(timer);
      });
      root.addEventListener("pointerleave", function (event) {
        if (event.pointerType !== "mouse") return;
        hovered = false;
        schedule();
      });
    }

    if (root.hasAttribute("data-media-sequence-swipe")) {
      var startX = null;
      var startY = 0;
      root.addEventListener("pointerdown", function (event) {
        if (event.pointerType === "mouse") return;
        startX = event.clientX;
        startY = event.clientY;
      });
      root.addEventListener("pointerup", function (event) {
        if (startX === null) return;
        var dx = event.clientX - startX;
        var dy = event.clientY - startY;
        startX = null;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) step(dx < 0 ? 1 : -1);
      });
      root.addEventListener("pointercancel", function () { startX = null; });
    }

    document.addEventListener("visibilitychange", function () {
      if (document.hidden) {
        window.clearTimeout(timer);
        var video = videoOf(slides[current]);
        if (video && playing) video.pause();
      } else {
        schedule();
      }
    });

    // Less motion: the first video that would start by itself stands still,
    // with its controls, until the visitor asks for more.
    if (reducedMotion) {
      slides.forEach(function (slide) {
        var video = videoOf(slide);
        if (video && video.autoplay) {
          video.autoplay = false;
          video.removeAttribute("autoplay");
          video.pause();
          giveControls(video);
        }
      });
    }

    show(0);
    updatePauseButton();
    schedule();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-media-sequence]"), start);
})();
