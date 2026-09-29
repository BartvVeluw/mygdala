/* =========================================================================
   The product page's gallery: one big picture on a stage and a row of
   thumbnail buttons (product.php, [data-product-gallery]).

   Asked for by product.php and by the Featured Product block
   (partials/section-featured-product.php), each before
   assets/js/shop/shop.js, which hands it the pictures once the product has
   loaded (window.VVLProductGallery). It knows nothing about variants,
   prices or the cart: shop.js decides WHICH pictures are shown, this file
   decides HOW.

   ONE WAY TO CHANGE PICTURE. A thumbnail click, a swipe, an arrow key and a
   new variant all end in show(target, direction, animate), so the picture,
   the active thumbnail and the index can never disagree.

   THE POSITION IS AN INDEX, 0-based, into the pictures on show. A new list
   (another variant) keeps the index when it has it and falls back to the
   first picture otherwise — nextGalleryIndex(). Stepping past either end
   wraps around, as the site's lightbox does (assets/js/lightbox.js).

   THE TRANSITION is a word from a closed list, written by the server into
   data-gallery-transition (App\Service\ProductGalleryTransition) and checked
   here against the same list again; anything else is the default. It only
   ever selects one of three CSS rules in assets/css/shop/shop.css:

     none   the next picture is simply there
     fade   the two pictures cross-fade
     slide  the next picture slides in from the side it comes from: from
            the right when it lies further on, from the left when it lies
            before (for a thumbnail, by the difference in index)

   Every change waits until the next picture is decoded (at most LOAD_WAIT),
   so the stage never shows an empty frame between two pictures, and it
   keeps its square throughout: both pictures sit inside it, on top of each
   other. prefers-reduced-motion makes every change "none".

   SWIPE is a way of ASKING for the next picture, not a transition, and works
   whatever the transition is. Touch and pen only, through Pointer Events:
   the stage says touch-action: pan-y pinch-zoom, so a vertical drag still
   scrolls the page (the browser takes it over and the pointer is
   cancelled), and nothing here ever calls preventDefault(). A swipe counts
   when it is at least SWIPE_DISTANCE long and clearly more sideways than up
   or down. The thumbnails always work too: swiping is never the only way.
   ========================================================================= */
(function () {
  "use strict";

  var TRANSITIONS = ["none", "fade", "slide"];
  // What the gallery did before the setting existed, and the server's own
  // default: a page without a valid attribute looks as it always did.
  var FALLBACK_TRANSITION = "fade";

  var SWIPE_DISTANCE = 50; // px, the lightbox's own threshold
  var SWIPE_RATIO = 1.5;   // sideways must beat up/down by this much
  var LOAD_WAIT = 400;     // ms: never wait longer for a slow picture
  var CLEANUP_AFTER = { fade: 360, slide: 440 }; // ms: if transitionend never comes

  function transitionOf(value) {
    return TRANSITIONS.indexOf(value) >= 0 ? value : FALLBACK_TRANSITION;
  }

  /* The index to show in a list of `length` pictures when the gallery was
     at `index`: the same position when the list has it, the first picture
     otherwise. Both 0-based. */
  function nextGalleryIndex(index, length) {
    return index >= 0 && index < length ? index : 0;
  }

  /* One step past either end comes back at the other one. */
  function wrapIndex(index, length) {
    return ((index % length) + length) % length;
  }

  var reduceMotion = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;

  function prefersReducedMotion() {
    return !!(reduceMotion && reduceMotion.matches);
  }

  /**
   * @param {Object} options
   *   root         the [data-product-gallery] element (carries the transition)
   *   stage        the big picture's frame ([data-product-media])
   *   thumbs       the thumbnail row ([data-product-thumbs]), may be null
   *   rootPath     function(path) -> URL (assets/js/shop/cart.js)
   *   placeholder  trusted markup for a product without any picture
   */
  function create(options) {
    var root = options.root;
    var stage = options.stage;
    var thumbs = options.thumbs || null;
    var rootPath = options.rootPath || function (path) { return path; };

    // Normalised once, so the CSS only ever sees one of the three words.
    var transition = transitionOf(root ? root.getAttribute("data-gallery-transition") : null);
    if (root) root.setAttribute("data-gallery-transition", transition);

    var images = [];
    var index = 0;
    var altFallback = "";
    var swapToken = 0;
    var running = null; // the transition on screen: { leaving, incoming, timer }

    function altOf(image) {
      return image && image.alt_text ? image.alt_text : altFallback;
    }

    function srcOf(image) {
      return rootPath(image.image_path);
    }

    /* The big picture as an element: built, never parsed from a string, so
       no value of the picture can become markup. */
    function pictureElement(image) {
      var img = document.createElement("img");
      img.className = "product-detail__main-img";
      img.decoding = "async";
      img.alt = altOf(image);
      var width = parseInt(image.width, 10);
      var height = parseInt(image.height, 10);
      if (width > 0 && height > 0) {
        img.width = width;
        img.height = height;
      }
      img.src = srcOf(image);
      return img;
    }

    function shownPicture() {
      var pictures = stage.querySelectorAll(".product-detail__main-img");
      return pictures.length ? pictures[pictures.length - 1] : null;
    }

    /* Ends whatever transition is on screen at once: the outgoing picture
       goes, the incoming one stands where it was heading. */
    function settle() {
      if (!running) return;
      var done = running;
      running = null;
      window.clearTimeout(done.timer);
      if (done.leaving && done.leaving.parentNode === stage) stage.removeChild(done.leaving);
      done.incoming.classList.remove("is-entering");
      done.incoming.removeAttribute("data-direction");
      stage.classList.remove("is-transitioning");
      // A transition still under way keeps running after its rule is gone
      // (its end value has not changed), so it is finished explicitly: the
      // next change must start from a picture that is fully in place.
      if (typeof done.incoming.getAnimations === "function") {
        done.incoming.getAnimations().forEach(function (animation) { animation.finish(); });
      }
    }

    /* Calls `ready` once the picture can be painted, or after LOAD_WAIT at
       the latest — never twice. A cached picture is ready almost at once. */
    function whenReady(img, ready) {
      var called = false;
      function go() {
        if (called) return;
        called = true;
        ready();
      }
      if (typeof img.decode === "function") {
        img.decode().then(go, go);
      } else if (img.complete) {
        go();
        return;
      } else {
        img.addEventListener("load", go);
        img.addEventListener("error", go);
      }
      window.setTimeout(go, LOAD_WAIT);
    }

    function commit(incoming, outgoing, mode, direction) {
      if (mode === "none" || !outgoing || outgoing.parentNode !== stage) {
        stage.textContent = "";
        stage.appendChild(incoming);
        return;
      }

      var side = direction < 0 ? "prev" : "next";
      incoming.classList.add("is-entering");
      incoming.setAttribute("data-direction", side);
      outgoing.setAttribute("data-direction", side);
      stage.appendChild(incoming);

      // The starting position has to be laid out before the class that
      // moves it goes, or the browser skips straight to the end. Reading a
      // layout value does that without waiting for a frame.
      void incoming.offsetWidth;
      stage.classList.add("is-transitioning");
      incoming.classList.remove("is-entering");
      outgoing.classList.add("is-leaving");

      running = { leaving: outgoing, incoming: incoming, timer: 0 };
      var mine = running;
      function finish(event) {
        if (event && event.target !== incoming) return;
        if (running === mine) settle();
      }
      incoming.addEventListener("transitionend", finish);
      mine.timer = window.setTimeout(finish, CLEANUP_AFTER[mode] || 400);
    }

    /* Marks the thumbnail of the picture on show: a lasting selected state
       (aria-current), separate from hover and focus. The row wraps instead
       of scrolling (shop.css, .product-detail__thumbs), so every thumbnail
       is always in view and nothing needs scrolling here. */
    function markThumb(active) {
      if (!thumbs) return;
      Array.prototype.forEach.call(thumbs.querySelectorAll("[data-image-index]"), function (btn) {
        var on = parseInt(btn.getAttribute("data-image-index"), 10) === active;
        btn.classList.toggle("is-active", on);
        if (on) {
          btn.setAttribute("aria-current", "true");
        } else {
          btn.removeAttribute("aria-current");
        }
      });
    }

    /**
     * THE one way to change picture. `direction` is where the new picture
     * comes from: > 0 further on (it enters from the right), < 0 before.
     */
    function show(target, direction, animate) {
      if (images.length === 0) {
        settle();
        swapToken++;
        stage.innerHTML = options.placeholder || "";
        index = 0;
        return;
      }

      index = wrapIndex(target, images.length);
      var image = images[index];
      markThumb(index);

      settle();
      var outgoing = shownPicture();

      if (outgoing && outgoing.getAttribute("src") === srcOf(image)) {
        swapToken++;
        outgoing.alt = altOf(image);
        return;
      }

      var mode = animate && outgoing && !prefersReducedMotion() ? transition : "none";
      var incoming = pictureElement(image);
      var token = ++swapToken;

      // Nothing on the stage yet (the page loading): show it straight away,
      // the browser paints it as it arrives.
      if (!outgoing) {
        commit(incoming, null, "none", direction);
        return;
      }

      whenReady(incoming, function () {
        if (token !== swapToken) return; // a later change won
        commit(incoming, outgoing, mode, direction);
      });
    }

    function step(delta) {
      if (images.length < 2) return;
      show(index + delta, delta, true);
    }

    /* The pictures on both sides, fetched ahead the moment a finger lands
       on the stage, so a swipe finds its picture ready. */
    function preloadNeighbours() {
      if (images.length < 2) return;
      [index + 1, index - 1].forEach(function (at) {
        var img = new Image();
        img.src = srcOf(images[wrapIndex(at, images.length)]);
      });
    }

    function renderThumbs() {
      if (!thumbs) return;
      thumbs.textContent = "";

      if (images.length < 2) {
        thumbs.hidden = true;
        return;
      }

      images.forEach(function (image, i) {
        var btn = document.createElement("button");
        btn.type = "button";
        btn.className = "product-detail__thumb";
        btn.setAttribute("data-image-index", String(i));
        btn.setAttribute("aria-label", altOf(image));
        var img = document.createElement("img");
        img.src = srcOf(image);
        img.alt = "";
        img.loading = "lazy";
        btn.appendChild(img);
        thumbs.appendChild(btn);
      });
      thumbs.hidden = false;
    }

    /**
     * A new list of pictures (the page loading, or another variant): keeps
     * the index when the list has it (nextGalleryIndex()).
     */
    function setImages(list, fallbackAlt, animate) {
      var previous = index;
      images = Array.isArray(list) ? list.filter(function (image) { return image && image.image_path; }) : [];
      altFallback = fallbackAlt || "";
      renderThumbs();
      var target = nextGalleryIndex(previous, images.length);
      show(target, target < previous ? -1 : 1, animate);
    }

    if (thumbs) {
      thumbs.addEventListener("click", function (event) {
        var btn = event.target.closest ? event.target.closest("[data-image-index]") : null;
        if (!btn || !thumbs.contains(btn)) return;
        var target = parseInt(btn.getAttribute("data-image-index"), 10);
        if (isNaN(target)) return;
        show(target, target - index, true);
      });

      // ← and → on a focused thumbnail step through the pictures and take
      // the focus along, so the keyboard never loses its place. Tab still
      // moves through the buttons as it always did.
      thumbs.addEventListener("keydown", function (event) {
        if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
        if (!event.target.closest || !event.target.closest("[data-image-index]")) return;
        if (images.length < 2) return;
        event.preventDefault();
        step(event.key === "ArrowRight" ? 1 : -1);
        var next = thumbs.querySelector('[data-image-index="' + index + '"]');
        if (next) next.focus();
      });
    }

    if (window.PointerEvent) {
      var start = null;

      stage.addEventListener("pointerdown", function (event) {
        if (event.pointerType === "mouse" || !event.isPrimary || images.length < 2) {
          start = null;
          return;
        }
        start = { id: event.pointerId, x: event.clientX, y: event.clientY };
        preloadNeighbours();
      });

      stage.addEventListener("pointerup", function (event) {
        if (!start || event.pointerId !== start.id) return;
        var dx = event.clientX - start.x;
        var dy = event.clientY - start.y;
        start = null;
        if (Math.abs(dx) >= SWIPE_DISTANCE && Math.abs(dx) > Math.abs(dy) * SWIPE_RATIO) {
          // A finger moving left pulls the next picture in from the right.
          step(dx < 0 ? 1 : -1);
        }
      });

      // The browser took the gesture over (a vertical scroll, a pinch).
      stage.addEventListener("pointercancel", function () { start = null; });
    }

    return {
      setImages: setImages,
      show: show,
      step: step,
      index: function () { return index; },
      transition: function () { return transition; }
    };
  }

  window.VVLProductGallery = {
    create: create,
    transitionOf: transitionOf,
    nextGalleryIndex: nextGalleryIndex,
    TRANSITIONS: TRANSITIONS.slice()
  };
})();
