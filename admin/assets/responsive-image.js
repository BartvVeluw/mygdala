/*
 * The editor of a picture's presentation (Responsive Media 3.0,
 * admin/_responsive_image_field.php, ADMIN-UI.md "Afbeeldingsweergave").
 *
 * THE SLIDERS ARE THE VALUE. Every frame has three range inputs: horizontal
 * and vertical (the focus point in whole percentages, what CSS
 * object-position means) and zoom (100-200%, the CSS `scale` of the picture
 * around that point). They are what the form posts. Everything here only
 * sets them — dragging the picture, one of the nine points, "Afbeelding
 * resetten" — and then fires the same "input" and "change" a person moving
 * the slider would, so the save bar and anything else listening hears one
 * ordinary edit.
 *
 * THE PREVIEW IS THE PAGE. The picture in the frame gets the same
 * object-position, `scale` and transform-origin partials/responsive-image.php
 * prints on the website, inside a frame that clips it the same way, so what
 * the editor shows is the crop a visitor gets. A contained picture ("Hele
 * afbeelding") is never zoomed: its zoom row hides and its value waits.
 *
 * DRAGGING moves the picture inside its frame, the way it will be cropped:
 * drag it to the right and more of its left side comes into view. The
 * room it can move is how much larger the (zoomed) picture is than its
 * frame, so at 150% both directions move even where the picture itself fits
 * the frame exactly. Pointer events, so a mouse, a finger and a pen all
 * work, with the pointer captured while it is down and the frame drawn at
 * most once per animation frame. The frame is aria-hidden: the sliders are
 * the keyboard's and a screen reader's way (arrow keys, Page Up/Down), with
 * their value named.
 *
 * LAZY, SO A LONG SCREEN STAYS LIGHT. A field is woken (its points and reset
 * button shown, its state drawn: data-rm-ready) only when it comes within
 * a screen of the viewport (IntersectionObserver), when a row list adds it,
 * or when someone reaches it first by pointer or keyboard; the server already
 * printed its state, so an unwoken field looks right and posts right. Its
 * preview is loading="lazy" in the markup, so the browser fetches it on the
 * same approach. Without IntersectionObserver every field wakes at once.
 *
 * DELEGATED: one set of listeners on the document for every field on the
 * screen, however many there are, so a row admin/assets/row-list.js adds
 * after the page loaded works like one the server printed, and a row it
 * removes ("row-list:removed") leaves nothing behind but its observation,
 * which is dropped.
 *
 * THE PHONE PART shows only what applies: the phone's own picture picker
 * when "Eigen afbeelding" is chosen, else the switch for a point of its own,
 * and the phone's frame whenever it has a point (and zoom) of its own.
 *
 * THE FRAMES FOLLOW THE PICTURES: choosing another desktop picture in the
 * block's own picker (the field's data-rm-picker names it) or another phone
 * picture puts it in the frame at once, and a choice elsewhere in the same
 * row or form that changes the place's shape (data-rm-desktop-ratio,
 * data-rm-mobile-ratio on its input) changes the frame's shape. A picture
 * chosen some other way than a Media picker (a Detailsectie gallery item that
 * shows a product's own picture) reaches the frame through an "rm:picture"
 * event the block's own script sends from inside the row, with the picture's
 * URL in detail.src ('' for none). Point and zoom stay what they are.
 *
 * DESKTOP, TABLET, MOBIEL (Responsive Media 3.1). The switch above the
 * focus frame (data-rm-view on the field) gives that one frame the shape and
 * size of the picture on that screen: --admin-rm-<view>-ratio and -width,
 * which the server printed and a block with size steps keeps up to date from
 * its own table (data-rm-shapes, worked out by the block's Content class
 * from the lengths of its stylesheet; this file computes no size). On
 * Mobiel the frame shows what a phone shows: the phone's fit, and - when the
 * phone has a point of its own - the phone's picture, point and zoom, as a
 * picture to look at (is-mirror; the phone part's own frame is where that
 * point moves). The same <img> changes, so a view costs no download except
 * a phone picture of its own, which the phone part loads anyway.
 *
 * This file holds no text of its own (ADMIN-UI.md): the words come from the
 * field's data attributes.
 */
(function () {
  "use strict";

  var ZOOM_MIN = 100;
  var ZOOM_MAX = 200;

  function fieldOf(element) {
    return element instanceof Element ? element.closest("[data-rm]") : null;
  }

  function scopeOf(element) {
    return element.closest("[data-row-list-row]") || element.closest("form") || document;
  }

  function axes(focus) {
    return {
      x: focus.querySelector('[data-rm-axis="x"]'),
      y: focus.querySelector('[data-rm-axis="y"]'),
      zoom: focus.querySelector('[data-rm-axis="zoom"]')
    };
  }

  function clamp(value) {
    return Math.max(0, Math.min(100, Math.round(value)));
  }

  function clampZoom(value) {
    var zoom = Math.round(Number(value));
    return isFinite(zoom) ? Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom)) : ZOOM_MIN;
  }

  function zoomOf(focus) {
    var input = axes(focus).zoom;
    return input ? clampZoom(input.value) : ZOOM_MIN;
  }

  function announce(input) {
    input.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function commit(input) {
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function viewOf(field) {
    return field.getAttribute("data-rm-view") || "desktop";
  }

  /** Whether a phone shows a point (and picture) of its own rather than the desktop's. */
  function phoneOwnsPoint(field) {
    var source = field.querySelector("[data-rm-source]:checked");
    var ownFocus = field.querySelector("[data-rm-own-focus]");
    return (source ? source.value === "own" : false) || (ownFocus ? ownFocus.checked : false);
  }

  /** The desktop picture of a field, also while its frame shows the phone's. */
  function desktopSource(field) {
    var image = field.querySelector('[data-rm-focus="desktop"] [data-rm-preview]');
    if (!image) return "";
    return image.hasAttribute("data-rm-src") ? image.getAttribute("data-rm-src") || "" : image.getAttribute("src") || "";
  }

  /**
   * What the desktop focus frame shows in the field's view: the desktop
   * picture and point (a tablet follows the large screen), or on Mobiel the
   * phone's fit and, when the phone has its own, its picture, point and zoom.
   */
  function shownIn(field, x, y, zoom) {
    var view = viewOf(field);
    var shown = { x: x, y: y, zoom: zoom, fit: fitFor(field, view === "mobile" ? "mobile" : "desktop"), src: desktopSource(field), mirror: false };
    var phone = field.querySelector('[data-rm-focus="mobile"]');
    if (view === "mobile" && phone && phoneOwnsPoint(field)) {
      var own = axes(phone);
      var image = phone.querySelector("[data-rm-preview]");
      shown.x = clamp(Number(own.x.value));
      shown.y = clamp(Number(own.y.value));
      shown.zoom = zoomOf(phone);
      shown.src = image ? image.getAttribute("src") || shown.src : shown.src;
      shown.mirror = true;
    }
    return shown;
  }

  /** Draws one focus editor from its sliders: the picture, the points, the words. */
  function draw(focus) {
    var inputs = axes(focus);
    if (!inputs.x || !inputs.y) return;

    var x = clamp(Number(inputs.x.value));
    var y = clamp(Number(inputs.y.value));
    var zoom = zoomOf(focus);
    var field = fieldOf(focus);
    var part = focus.getAttribute("data-rm-focus");
    var shown = field && part === "desktop"
      ? shownIn(field, x, y, zoom)
      : { x: x, y: y, zoom: zoom, fit: field ? fitFor(field, part) : "cover", src: null, mirror: false };
    var contained = shown.fit === "contain";
    var preview = focus.querySelector("[data-rm-preview]");
    if (preview) {
      if (shown.src !== null && shown.src !== "" && preview.getAttribute("src") !== shown.src) {
        if (!preview.hasAttribute("data-rm-src")) preview.setAttribute("data-rm-src", preview.getAttribute("src") || "");
        preview.setAttribute("src", shown.src);
      }
      preview.style.objectFit = shown.fit;
      preview.style.objectPosition = shown.x + "% " + shown.y + "%";
      // The page's own rendering (partials/responsive-image.php): scaled
      // around the point, nothing at 100% or when contained.
      preview.style.scale = !contained && shown.zoom !== ZOOM_MIN ? String(shown.zoom / 100) : "";
      preview.style.transformOrigin = !contained && shown.zoom !== ZOOM_MIN ? shown.x + "% " + shown.y + "%" : "";
      var frame = preview.closest("[data-rm-frame]");
      if (frame) frame.classList.toggle("is-mirror", shown.mirror);
    }

    inputs.x.setAttribute("aria-valuetext", x + "%");
    inputs.y.setAttribute("aria-valuetext", y + "%");

    focus.querySelectorAll("[data-rm-preset]").forEach(function (button) {
      var pressed = Number(button.getAttribute("data-x")) === x && Number(button.getAttribute("data-y")) === y;
      button.setAttribute("aria-pressed", pressed ? "true" : "false");
    });

    var value = focus.querySelector("[data-rm-value]");
    if (value && field) {
      value.textContent = (field.getAttribute("data-rm-value-template") || "")
        .replace(":x", String(x))
        .replace(":y", String(y));
    }

    if (inputs.zoom && field) {
      var words = (field.getAttribute("data-rm-zoom-template") || ":zoom").replace(":zoom", String(zoom));
      inputs.zoom.setAttribute("aria-valuetext", words);
      var shown = focus.querySelector("[data-rm-zoom-value]");
      if (shown) shown.textContent = words;
    }
  }

  /** Sets the sliders (a zoom of undefined keeps the zoom) and tells the form. */
  function setPoint(focus, x, y, final, zoom) {
    var inputs = axes(focus);
    if (!inputs.x || !inputs.y) return;

    inputs.x.value = String(clamp(x));
    inputs.y.value = String(clamp(y));
    if (inputs.zoom && zoom !== undefined) inputs.zoom.value = String(clampZoom(zoom));
    draw(focus);

    var touched = [inputs.x, inputs.y];
    if (inputs.zoom && zoom !== undefined) touched.push(inputs.zoom);
    touched.forEach(announce);
    if (final) touched.forEach(commit);
  }

  /** The fit that applies to one frame: its own radio, the phone's falling back to the desktop's. */
  function fitFor(field, part) {
    var desktop = field.querySelector('[data-rm-fit="desktop"]:checked');
    var desktopFit = desktop ? desktop.value : "cover";
    if (part !== "mobile") return desktopFit;

    var mobile = field.querySelector('[data-rm-fit="mobile"]:checked');
    return mobile && mobile.value !== "" ? mobile.value : desktopFit;
  }

  function drawFit(field) {
    field.querySelectorAll("[data-rm-focus]").forEach(function (focus) {
      // The controls follow their own part's fit; the picture the view's (draw()).
      focus.classList.toggle("is-contained", fitFor(field, focus.getAttribute("data-rm-focus")) === "contain");
      draw(focus);
    });
  }

  /** The switch and its words, and the frames, for the field's view. */
  function drawView(field) {
    var view = viewOf(field);
    field.querySelectorAll("[data-rm-view-button]").forEach(function (button) {
      button.setAttribute("aria-pressed", button.getAttribute("data-rm-view-button") === view ? "true" : "false");
    });
    var note = field.querySelector("[data-rm-view-note]");
    if (note) {
      var key = view === "mobile" && phoneOwnsPoint(field) ? "data-mobile-own" : "data-" + view;
      note.textContent = note.getAttribute(key) || "";
    }
    field.querySelectorAll("[data-rm-focus]").forEach(draw);
  }

  /**
   * A block with size steps: the frames of the choices on screen now, from
   * the block's own table (data-rm-shapes), keyed by its controls' values.
   */
  function drawShape(field) {
    var table = field.getAttribute("data-rm-shapes");
    if (!table) return;
    var shapes;
    var controls;
    try {
      shapes = JSON.parse(table);
      controls = JSON.parse(field.getAttribute("data-rm-shape-controls") || "[]");
    } catch (error) {
      return;
    }
    var scope = scopeOf(field);
    var key = controls.map(function (selector) {
      var value = "";
      scope.querySelectorAll(selector).forEach(function (control) {
        if (control.matches("select") || (control.checked && control.matches("input"))) value = control.value;
      });
      return value;
    }).join("|");
    var properties = shapes[key];
    if (!properties) return;
    Object.keys(properties).forEach(function (name) {
      if (/^--admin-rm-[a-z]+-(ratio|width)$/.test(name)) field.style.setProperty(name, String(properties[name]));
    });
  }

  /** Whether a control decides the shape of a field's frames. */
  function shapesFollow(field, control) {
    try {
      return JSON.parse(field.getAttribute("data-rm-shape-controls") || "[]").some(function (selector) {
        return control.matches(selector);
      });
    } catch (error) {
      return false;
    }
  }

  /** Which parts of the phone section apply, from its choices. */
  function drawMobile(field) {
    var mobile = field.querySelector("[data-rm-mobile]");
    if (!mobile) return;

    var source = mobile.querySelector("[data-rm-source]:checked");
    var own = source ? source.value === "own" : false;
    var ownFocus = mobile.querySelector("[data-rm-own-focus]");
    var hasOwnPoint = own || (ownFocus ? ownFocus.checked : false);

    mobile.querySelectorAll('[data-rm-when="own"]').forEach(function (part) { part.hidden = !own; });
    mobile.querySelectorAll('[data-rm-when="desktop"]').forEach(function (part) { part.hidden = own; });
    mobile.querySelectorAll('[data-rm-when="mobile-focus"]').forEach(function (part) { part.hidden = !hasOwnPoint; });

    var fit = mobile.querySelector('[data-rm-fit="mobile"]:checked');
    var height = mobile.querySelector("[data-rm-mobile-height]:checked");
    var customised = own || hasOwnPoint || (fit && fit.value !== "") || (height && height.value !== "");
    var state = mobile.querySelector("[data-rm-mobile-state]");
    if (state) {
      state.textContent = state.getAttribute(customised ? "data-own" : "data-same") || "";
    }

    drawMobilePicture(field);
    drawView(field);
  }

  /** The phone's frame shows the phone's own picture, else the desktop one. */
  function drawMobilePicture(field) {
    var frame = field.querySelector('[data-rm-focus="mobile"] [data-rm-frame]');
    if (!frame) return;

    var source = field.querySelector("[data-rm-source]:checked");
    var src = "";
    if (source && source.value === "own") {
      src = chosenPicture(field.querySelector('[data-rm-when="own"] [data-media-picker]'));
    }
    if (src === "") {
      var desktop = field.querySelector('[data-rm-focus="desktop"] [data-rm-preview]');
      src = desktop && !desktop.closest("[data-rm-frame]").hidden ? desktopSource(field) : "";
    }

    var image = frame.querySelector("[data-rm-preview]");
    if (image && src !== "" && image.getAttribute("src") !== src) image.setAttribute("src", src);
    frame.hidden = src === "";
  }

  /** The preview a Media picker shows of its choice, '' for none. */
  function chosenPicture(picker) {
    if (!picker) return "";
    var input = picker.querySelector("[data-media-picker-input]");
    var image = picker.querySelector("[data-media-picker-preview] img");
    return input && input.value !== "" && image ? image.getAttribute("src") || "" : "";
  }

  function drawAll(field) {
    field.querySelectorAll("[data-rm-presets], [data-rm-reset-row], [data-rm-views]").forEach(function (part) { part.hidden = false; });
    drawShape(field);
    drawFit(field);
    drawMobile(field);
  }

  // ---------------------------------------------------------- waking up

  var observer = typeof window.IntersectionObserver === "function"
    ? new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) wake(entry.target);
      });
    }, { rootMargin: "100% 0px" })
    : null;

  /** Makes one field fully interactive, once. */
  function wake(field) {
    if (!field || field.hasAttribute("data-rm-ready")) return;
    field.setAttribute("data-rm-ready", "");
    if (observer) observer.unobserve(field);
    drawAll(field);
  }

  function watch(field) {
    if (field.hasAttribute("data-rm-ready")) return;
    if (observer) {
      observer.observe(field);
    } else {
      wake(field);
    }
  }

  // Reached before it scrolled into view: by Tab, or by a pointer.
  document.addEventListener("focusin", function (event) {
    wake(fieldOf(event.target));
  });

  // ------------------------------------------------------------- dragging

  var drag = null;

  function startDrag(event) {
    var frame = event.target instanceof Element ? event.target.closest("[data-rm-frame]") : null;
    if (!frame || event.button > 0) return;

    var focus = frame.closest("[data-rm-focus]");
    var field = fieldOf(frame);
    wake(field);
    var image = frame.querySelector("[data-rm-preview]");
    if (!focus || !field || !image || !image.naturalWidth || !image.naturalHeight) return;
    // A contained picture has nothing to move, and the phone's own point
    // shown on Mobiel moves in the phone part's frame.
    if (image.style.objectFit === "contain" || frame.classList.contains("is-mirror")) return;

    // The picture as drawn: covering the frame (object-fit: cover), then
    // enlarged by the zoom. Measured on the frame, whose box the scale does
    // not change.
    var box = frame.getBoundingClientRect();
    var zoom = zoomOf(focus) / 100;
    var scale = Math.max(box.width / image.naturalWidth, box.height / image.naturalHeight) * zoom;
    var inputs = axes(focus);

    drag = {
      frame: frame,
      focus: focus,
      pointer: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      fromX: Number(inputs.x.value),
      fromY: Number(inputs.y.value),
      overflowX: image.naturalWidth * scale - box.width,
      overflowY: image.naturalHeight * scale - box.height,
      pending: null,
      frameRequest: 0
    };

    frame.setPointerCapture(event.pointerId);
    frame.classList.add("is-dragging");
    event.preventDefault();
  }

  function moveDrag(event) {
    if (!drag || event.pointerId !== drag.pointer) return;

    // The picture moves with the pointer: more of its left side comes into
    // view when it goes right, so the point moves the other way. Zoomed
    // around the point, a point's move of p% shifts the picture by p% of
    // its overflow (the zoomed picture's size less the frame's), so one
    // pixel of pointer is one pixel of picture at every zoom.
    var dx = event.clientX - drag.startX;
    var dy = event.clientY - drag.startY;
    var x = drag.overflowX > 0.5 ? drag.fromX - (dx / drag.overflowX) * 100 : drag.fromX;
    var y = drag.overflowY > 0.5 ? drag.fromY - (dy / drag.overflowY) * 100 : drag.fromY;

    drag.pending = [x, y];
    if (!drag.frameRequest) {
      drag.frameRequest = window.requestAnimationFrame(function () {
        if (!drag) return;
        drag.frameRequest = 0;
        if (drag.pending) setPoint(drag.focus, drag.pending[0], drag.pending[1], false);
      });
    }
  }

  function endDrag(event) {
    if (!drag || event.pointerId !== drag.pointer) return;

    var finished = drag;
    drag = null;
    if (finished.frameRequest) window.cancelAnimationFrame(finished.frameRequest);
    if (finished.pending) {
      setPoint(finished.focus, finished.pending[0], finished.pending[1], true);
    }
    finished.frame.classList.remove("is-dragging");
    if (finished.frame.hasPointerCapture(finished.pointer)) {
      finished.frame.releasePointerCapture(finished.pointer);
    }
  }

  document.addEventListener("pointerdown", startDrag);
  document.addEventListener("pointermove", moveDrag);
  document.addEventListener("pointerup", endDrag);
  document.addEventListener("pointercancel", endDrag);

  // ------------------------------------------------------------ the rest

  document.addEventListener("click", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    var viewButton = target.closest("[data-rm-view-button]");
    if (viewButton) {
      var viewed = fieldOf(viewButton);
      if (viewed) {
        wake(viewed);
        viewed.setAttribute("data-rm-view", viewButton.getAttribute("data-rm-view-button") || "desktop");
        drawView(viewed);
      }
      return;
    }

    var preset = target.closest("[data-rm-preset]");
    var reset = preset ? null : target.closest("[data-rm-reset]");
    var focus = (preset || reset) ? (preset || reset).closest("[data-rm-focus]") : null;
    if (!focus) return;

    if (preset) {
      setPoint(focus, Number(preset.getAttribute("data-x")), Number(preset.getAttribute("data-y")), true);
    } else {
      // Back to the middle, unzoomed.
      setPoint(focus, 50, 50, true, ZOOM_MIN);
    }
  });

  document.addEventListener("input", function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.matches("[data-rm-axis]")) return;

    // Both frames: the focus frame shows the phone's point on Mobiel.
    var field = fieldOf(target);
    if (field) field.querySelectorAll("[data-rm-focus]").forEach(draw);
  });

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    var field = fieldOf(target);

    // A choice that shapes the frames of a block with size steps.
    scopeOf(target).querySelectorAll("[data-rm-shapes]").forEach(function (rm) {
      if (shapesFollow(rm, target)) drawShape(rm);
    });

    if (field && target.matches("[data-rm-fit]")) {
      drawFit(field);
      drawMobile(field);
      return;
    }

    if (field && (target.matches("[data-rm-source]") || target.matches("[data-rm-own-focus]") || target.matches("[data-rm-mobile-height]"))) {
      drawMobile(field);
      return;
    }

    // A shape choice elsewhere in the same row or form.
    if (target.matches("[data-rm-desktop-ratio]") || target.matches("[data-rm-mobile-ratio]")) {
      scopeOf(target).querySelectorAll("[data-rm]").forEach(function (rm) {
        if (target.hasAttribute("data-rm-desktop-ratio")) {
          rm.style.setProperty("--admin-rm-desktop-ratio", target.getAttribute("data-rm-desktop-ratio"));
        }
        if (target.hasAttribute("data-rm-mobile-ratio")) {
          rm.style.setProperty("--admin-rm-mobile-ratio", target.getAttribute("data-rm-mobile-ratio"));
        }
      });
      return;
    }

    if (!target.matches("[data-media-picker-input]")) return;

    // The phone's own picker, inside a field.
    if (field) {
      drawMobilePicture(field);
      drawView(field);
      return;
    }

    // The block's own picker: every field in the same row or form that
    // follows this picker by name.
    var name = target.getAttribute("name") || "";
    scopeOf(target).querySelectorAll("[data-rm]").forEach(function (rm) {
      if (rm.getAttribute("data-rm-picker") !== name) return;
      showPicture(rm, chosenPicture(target.closest("[data-media-picker]")));
    });
  });

  /** Another desktop picture in a field's frames; point and zoom stay. */
  function showPicture(rm, src) {
    var frame = rm.querySelector('[data-rm-focus="desktop"] [data-rm-frame]');
    var image = frame ? frame.querySelector("[data-rm-preview]") : null;
    if (frame && image) {
      if (src !== "") {
        image.setAttribute("data-rm-src", src);
        image.setAttribute("src", src);
      }
      frame.hidden = src === "";
    }
    drawMobilePicture(rm);
    drawView(rm);
  }

  // A picture chosen some other way than a Media picker (see above).
  document.addEventListener("rm:picture", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    var src = event.detail && typeof event.detail.src === "string" ? event.detail.src : "";
    scopeOf(target).querySelectorAll("[data-rm]").forEach(function (rm) {
      showPicture(rm, src);
    });
  });

  // A row a row list adds later: it is where the editor is looking.
  document.addEventListener("row-list:added", function (event) {
    var row = event.target instanceof Element ? event.target : null;
    if (row) row.querySelectorAll("[data-rm]").forEach(wake);
  });

  // A row a row list removes: forget its fields.
  document.addEventListener("row-list:removed", function (event) {
    var row = event.target instanceof Element ? event.target : null;
    if (row && observer) row.querySelectorAll("[data-rm]").forEach(function (rm) { observer.unobserve(rm); });
  });

  document.querySelectorAll("[data-rm]").forEach(watch);
})();
