/*
 * The editor of a picture's presentation (Responsive Media 2.0,
 * admin/_responsive_image_field.php, ADMIN-UI.md "Afbeeldingsweergave").
 *
 * THE SLIDERS ARE THE VALUE. Every frame has two range inputs, horizontal and
 * vertical, and they are what the form posts (the focus point in whole
 * percentages, what CSS object-position means). Everything here only sets
 * them — dragging the picture, one of the nine points — and then fires the
 * same "input" and "change" a person moving the slider would, so the save bar
 * and anything else listening hears one ordinary edit.
 *
 * DRAGGING moves the picture inside its frame, the way it will be cropped:
 * drag it to the right and more of its left side comes into view. Only the
 * direction in which the picture is larger than its frame moves anything.
 * Pointer events, so a mouse, a finger and a pen all work, with the pointer
 * captured while it is down and the frame drawn at most once per animation
 * frame. The frame is aria-hidden: the sliders are the keyboard's and a
 * screen reader's way (arrow keys, Page Up/Down), with their value named.
 *
 * THE PHONE PART shows only what applies: the phone's own picture picker
 * when "Eigen afbeelding" is chosen, else the switch for a point of its own,
 * and the phone's frame whenever it has a point of its own.
 *
 * THE FRAMES FOLLOW THE PICTURES: choosing another desktop picture in the
 * block's own picker (the field's data-rm-picker names it) or another phone
 * picture puts it in the frame at once, and a choice elsewhere in the same
 * row or form that changes the place's shape (data-rm-desktop-ratio,
 * data-rm-mobile-ratio on its input) changes the frame's shape. A picture
 * chosen some other way than a Media picker (a Detailsectie gallery item that
 * shows a product's own picture) reaches the frame through an "rm:picture"
 * event the block's own script sends from inside the row, with the picture's
 * URL in detail.src ('' for none).
 *
 * DELEGATED, so a row admin/assets/row-list.js adds after the page loaded
 * works like one the server printed. This file holds no text of its own
 * (ADMIN-UI.md): the words come from the field's data attributes.
 */
(function () {
  "use strict";

  function fieldOf(element) {
    return element.closest("[data-rm]");
  }

  function scopeOf(element) {
    return element.closest("[data-row-list-row]") || element.closest("form") || document;
  }

  function axes(focus) {
    return {
      x: focus.querySelector('[data-rm-axis="x"]'),
      y: focus.querySelector('[data-rm-axis="y"]')
    };
  }

  function clamp(value) {
    return Math.max(0, Math.min(100, Math.round(value)));
  }

  function announce(input) {
    input.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function commit(input) {
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  /** Draws one focus editor from its sliders: the picture, the points, the words. */
  function draw(focus) {
    var inputs = axes(focus);
    if (!inputs.x || !inputs.y) return;

    var x = clamp(Number(inputs.x.value));
    var y = clamp(Number(inputs.y.value));
    var preview = focus.querySelector("[data-rm-preview]");
    if (preview) preview.style.objectPosition = x + "% " + y + "%";

    inputs.x.setAttribute("aria-valuetext", x + "%");
    inputs.y.setAttribute("aria-valuetext", y + "%");

    focus.querySelectorAll("[data-rm-preset]").forEach(function (button) {
      var pressed = Number(button.getAttribute("data-x")) === x && Number(button.getAttribute("data-y")) === y;
      button.setAttribute("aria-pressed", pressed ? "true" : "false");
    });

    var field = fieldOf(focus);
    var value = focus.querySelector("[data-rm-value]");
    if (value && field) {
      value.textContent = (field.getAttribute("data-rm-value-template") || "")
        .replace(":x", String(x))
        .replace(":y", String(y));
    }
  }

  function setPoint(focus, x, y, final) {
    var inputs = axes(focus);
    if (!inputs.x || !inputs.y) return;

    inputs.x.value = String(clamp(x));
    inputs.y.value = String(clamp(y));
    draw(focus);
    announce(inputs.x);
    announce(inputs.y);
    if (final) {
      commit(inputs.x);
      commit(inputs.y);
    }
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
      var fit = fitFor(field, focus.getAttribute("data-rm-focus"));
      var preview = focus.querySelector("[data-rm-preview]");
      if (preview) preview.style.objectFit = fit;
      focus.classList.toggle("is-contained", fit === "contain");
    });
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
      src = desktop && !desktop.closest("[data-rm-frame]").hidden ? desktop.getAttribute("src") || "" : "";
    }

    var image = frame.querySelector("[data-rm-preview]");
    if (image && src !== "") image.setAttribute("src", src);
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
    field.querySelectorAll("[data-rm-focus]").forEach(draw);
    field.querySelectorAll("[data-rm-presets]").forEach(function (presets) { presets.hidden = false; });
    drawFit(field);
    drawMobile(field);
  }

  // ------------------------------------------------------------- dragging

  var drag = null;

  function startDrag(event) {
    var frame = event.target.closest("[data-rm-frame]");
    if (!frame || event.button > 0) return;

    var focus = frame.closest("[data-rm-focus]");
    var field = fieldOf(frame);
    var image = frame.querySelector("[data-rm-preview]");
    if (!focus || !field || !image || !image.naturalWidth || !image.naturalHeight) return;
    if (fitFor(field, focus.getAttribute("data-rm-focus")) === "contain") return;

    var box = frame.getBoundingClientRect();
    var scale = Math.max(box.width / image.naturalWidth, box.height / image.naturalHeight);
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
    // view when it goes right, so the point moves the other way.
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
    var preset = event.target instanceof Element ? event.target.closest("[data-rm-preset]") : null;
    if (!preset) return;

    var focus = preset.closest("[data-rm-focus]");
    if (!focus) return;

    setPoint(focus, Number(preset.getAttribute("data-x")), Number(preset.getAttribute("data-y")), true);
  });

  document.addEventListener("input", function (event) {
    var target = event.target;
    if (!(target instanceof Element) || !target.matches("[data-rm-axis]")) return;

    var focus = target.closest("[data-rm-focus]");
    if (focus) draw(focus);
  });

  document.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof Element)) return;

    var field = fieldOf(target);

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
      return;
    }

    // The block's own picker: every field in the same row or form that
    // follows this picker by name.
    var name = target.getAttribute("name") || "";
    scopeOf(target).querySelectorAll("[data-rm]").forEach(function (rm) {
      if (rm.getAttribute("data-rm-picker") !== name) return;

      var src = chosenPicture(target.closest("[data-media-picker]"));
      var frame = rm.querySelector('[data-rm-focus="desktop"] [data-rm-frame]');
      var image = frame ? frame.querySelector("[data-rm-preview]") : null;
      if (frame && image) {
        if (src !== "") image.setAttribute("src", src);
        frame.hidden = src === "";
      }
      drawMobilePicture(rm);
    });
  });

  // A picture chosen some other way than a Media picker (see above).
  document.addEventListener("rm:picture", function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    var src = event.detail && typeof event.detail.src === "string" ? event.detail.src : "";
    scopeOf(target).querySelectorAll("[data-rm]").forEach(function (rm) {
      var frame = rm.querySelector('[data-rm-focus="desktop"] [data-rm-frame]');
      var image = frame ? frame.querySelector("[data-rm-preview]") : null;
      if (frame && image) {
        if (src !== "") image.setAttribute("src", src);
        frame.hidden = src === "";
      }
      drawMobilePicture(rm);
    });
  });

  // A row a row list adds later.
  document.addEventListener("row-list:added", function (event) {
    var row = event.target instanceof Element ? event.target : null;
    if (row) row.querySelectorAll("[data-rm]").forEach(drawAll);
  });

  document.querySelectorAll("[data-rm]").forEach(drawAll);
})();
