/**
 * The CMS side of product personalization, in two independent halves:
 *
 *   1. the visual engraving-area editor in the product editor's
 *      "Personalisatie" card (admin/product-form.php + _personalization_builder.php),
 *      where one preview image can carry SEVERAL zones at once;
 *   2. sizing the text in the reconstructed order previews on the order
 *      detail page (admin/order.php).
 *
 * They share this file because they share exactly one rule — how a zone's
 * height turns into a font size — and duplicating that rule is precisely how
 * the CMS would start showing something other than what the customer saw.
 * Each half no-ops on a page that does not contain it.
 *
 * ## 1. Engraving-area editor
 *
 * Click a zone to select it, drag it, resize it by its corners, or simply
 * type its percentages. The four number inputs of each zone are the SOURCE OF
 * TRUTH and the only thing that is ever submitted — each zone has its own
 * form, and the rectangle is a convenience on top of it. That is what keeps
 * the editor keyboard-accessible, usable without JavaScript at all, and
 * unable to influence what the server stores: every value is validated again
 * in PHP by App\Service\Personalization\PersonalizationRules.
 *
 * Plain native JavaScript with Pointer Events, no library: one code path
 * handles a mouse, a trackpad, a pen and a touch screen.
 */
(function () {
  "use strict";

  /* Mirrors PersonalizationRules::MIN_AREA_SIZE_PERCENT — a zone smaller than
     this is not a zone, it's a misclick. */
  var MIN_SIZE = 2;
  var KEYBOARD_STEP = 1;

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  /* One decimal everywhere: matches the inputs' step="0.1" and the three
     decimals the database column can hold, without ever producing a value the
     browser's own number validation would reject. */
  function round(value) {
    return Math.round(value * 10) / 10;
  }

  /* ------------------------------------------------------------------
     One zone rectangle, bound to the four inputs of its own zone form
     ------------------------------------------------------------------ */

  function createZone(box, stage) {
    var zoneId = box.getAttribute("data-zone-box");

    function input(key) {
      return document.querySelector('[data-zone-input="' + key + '"][data-zone-id="' + zoneId + '"]');
    }

    var inputs = { x: input("x"), y: input("y"), width: input("width"), height: input("height") };

    if (!inputs.x || !inputs.y || !inputs.width || !inputs.height) return null;

    /* True while applyToInputs() is writing the fields itself. The "input"
       listener below re-reads ALL FOUR fields, so without this flag the event
       fired after writing `x` would re-derive the rectangle from an `x` that
       is already new and a `y` that is not yet written — silently reverting
       every field after the first. That is not theoretical: it is exactly why
       dragging only ever moved a zone horizontally until this guard existed. */
    var syncingInputs = false;

    function readInputs() {
      return {
        x: parseFloat(inputs.x.value),
        y: parseFloat(inputs.y.value),
        width: parseFloat(inputs.width.value),
        height: parseFloat(inputs.height.value)
      };
    }

    /* The one place that decides what a valid rectangle is: inside the image,
       never smaller than MIN_SIZE, never past the right/bottom edge. Anything
       unreadable falls back to a sensible centred default rather than NaN. */
    function normalize(candidate) {
      var width = clamp(isFinite(candidate.width) ? candidate.width : 50, MIN_SIZE, 100);
      var height = clamp(isFinite(candidate.height) ? candidate.height : 30, MIN_SIZE, 100);
      var x = clamp(isFinite(candidate.x) ? candidate.x : 25, 0, 100 - width);
      var y = clamp(isFinite(candidate.y) ? candidate.y : 35, 0, 100 - height);

      return { x: round(x), y: round(y), width: round(width), height: round(height) };
    }

    var area = normalize(readInputs());

    function applyToBox() {
      box.style.left = area.x + "%";
      box.style.top = area.y + "%";
      box.style.width = area.width + "%";
      box.style.height = area.height + "%";
    }

    function applyToInputs() {
      syncingInputs = true;

      try {
        ["x", "y", "width", "height"].forEach(function (key) {
          var next = String(area[key]);
          if (inputs[key].value !== next) {
            inputs[key].value = next;
            // So anything else listening to these fields (and the browser's
            // own validity state) sees a real change, not a silent assignment.
            inputs[key].dispatchEvent(new Event("input", { bubbles: true }));
          }
        });
      } finally {
        syncingInputs = false;
      }
    }

    function setArea(candidate, updateInputs) {
      area = normalize(candidate);
      applyToBox();
      if (updateInputs !== false) applyToInputs();
    }

    ["x", "y", "width", "height"].forEach(function (key) {
      inputs[key].addEventListener("input", function () {
        if (syncingInputs) return;
        // While typing, don't rewrite the field the admin is in the middle of
        // — only redraw the rectangle. The value is normalised on blur.
        area = normalize(readInputs());
        applyToBox();
      });

      inputs[key].addEventListener("change", function () {
        if (syncingInputs) return;
        setArea(readInputs());
      });
    });

    setArea(area);

    return {
      id: zoneId,
      box: box,
      stage: stage,
      get area() { return area; },
      setArea: setArea
    };
  }

  /* ------------------------------------------------------------------
     One stage: an image with any number of zones on it
     ------------------------------------------------------------------ */

  function initZoneEditor(root) {
    var stage = root.querySelector("[data-zone-stage]");
    if (!stage) return;

    var zones = [];
    Array.prototype.forEach.call(stage.querySelectorAll("[data-zone-box]"), function (box) {
      var zone = createZone(box, stage);
      if (zone) zones.push(zone);
    });

    if (zones.length === 0) return;

    var drag = null;

    function select(zone) {
      zones.forEach(function (other) {
        other.box.classList.toggle("is-selected", other === zone);
      });
    }

    function stageSize() {
      var rect = stage.getBoundingClientRect();
      return { width: rect.width || 1, height: rect.height || 1 };
    }

    function beginDrag(zone, event, handle) {
      // Only the primary button/contact — a right-click or a second finger
      // must not start a second, conflicting drag.
      if (event.button !== undefined && event.button !== 0) return;

      drag = {
        zone: zone,
        pointerId: event.pointerId,
        handle: handle,
        startX: event.clientX,
        startY: event.clientY,
        start: {
          x: zone.area.x, y: zone.area.y,
          width: zone.area.width, height: zone.area.height
        }
      };

      zone.box.classList.add("is-dragging");

      if (event.target.setPointerCapture) {
        try { event.target.setPointerCapture(event.pointerId); } catch (e) { /* not fatal */ }
      }

      event.preventDefault();
      event.stopPropagation();
    }

    function moveDrag(event) {
      if (!drag || event.pointerId !== drag.pointerId) return;

      var size = stageSize();
      var dx = ((event.clientX - drag.startX) / size.width) * 100;
      var dy = ((event.clientY - drag.startY) / size.height) * 100;
      var start = drag.start;

      if (drag.handle === null) {
        drag.zone.setArea({ x: start.x + dx, y: start.y + dy, width: start.width, height: start.height });
        return;
      }

      // Resizing works on the two edges the grabbed corner owns; the opposite
      // two stay exactly where they are, which is what makes a corner drag
      // feel right.
      var left = start.x;
      var top = start.y;
      var right = start.x + start.width;
      var bottom = start.y + start.height;

      if (drag.handle.indexOf("w") !== -1) left = clamp(start.x + dx, 0, right - MIN_SIZE);
      if (drag.handle.indexOf("e") !== -1) right = clamp(right + dx, left + MIN_SIZE, 100);
      if (drag.handle.indexOf("n") !== -1) top = clamp(start.y + dy, 0, bottom - MIN_SIZE);
      if (drag.handle.indexOf("s") !== -1) bottom = clamp(bottom + dy, top + MIN_SIZE, 100);

      drag.zone.setArea({ x: left, y: top, width: right - left, height: bottom - top });
    }

    function endDrag(event) {
      if (!drag || (event && event.pointerId !== drag.pointerId)) return;
      drag.zone.box.classList.remove("is-dragging");
      drag = null;
    }

    zones.forEach(function (zone) {
      zone.box.addEventListener("pointerdown", function (event) {
        select(zone);
        if (event.target.hasAttribute && event.target.hasAttribute("data-zone-handle")) return;
        zone.box.focus();
        beginDrag(zone, event, null);
      });

      zone.box.addEventListener("focus", function () { select(zone); });

      Array.prototype.forEach.call(zone.box.querySelectorAll("[data-zone-handle]"), function (handle) {
        handle.addEventListener("pointerdown", function (event) {
          select(zone);
          beginDrag(zone, event, handle.getAttribute("data-zone-handle"));
        });
      });

      /* Keyboard: the accessible equivalent of dragging. */
      zone.box.addEventListener("keydown", function (event) {
        var dx = 0;
        var dy = 0;

        if (event.key === "ArrowLeft") dx = -KEYBOARD_STEP;
        else if (event.key === "ArrowRight") dx = KEYBOARD_STEP;
        else if (event.key === "ArrowUp") dy = -KEYBOARD_STEP;
        else if (event.key === "ArrowDown") dy = KEYBOARD_STEP;
        else return;

        event.preventDefault();

        if (event.shiftKey) {
          zone.setArea({
            x: zone.area.x,
            y: zone.area.y,
            width: zone.area.width + dx,
            height: zone.area.height + dy
          });
          return;
        }

        zone.setArea({ x: zone.area.x + dx, y: zone.area.y + dy, width: zone.area.width, height: zone.area.height });
      });
    });

    // Listening on the document (not the box) so a fast drag that outruns the
    // pointer never leaves a rectangle stuck mid-move.
    document.addEventListener("pointermove", moveDrag);
    document.addEventListener("pointerup", endDrag);
    document.addEventListener("pointercancel", endDrag);
  }

  /* ------------------------------------------------------------------
     2. Reconstructed order previews (admin/order.php)
     ------------------------------------------------------------------ */

  /**
   * Sizes the text in a reconstructed personalization preview. Everything
   * else in that preview is a percentage and needs no JavaScript, but a font
   * size cannot be expressed as a fraction of its container's HEIGHT in CSS
   * — so it is computed here from the zone's measured height, using the ratio
   * and scale that were stored WITH THE ORDER (not today's constants). That
   * is the same formula assets/js/personalization.js uses, which is what
   * makes the CMS show the picture the customer actually saw.
   */
  function sizePersonalizationText(preview) {
    var zone = preview.querySelector("[data-personalization-zone]");
    var textLayer = preview.querySelector("[data-personalization-text]");
    if (!zone || !textLayer) return;

    var height = zone.getBoundingClientRect().height;
    if (!height) return;

    var ratio = parseFloat(textLayer.getAttribute("data-text-ratio"));
    var scale = parseFloat(textLayer.getAttribute("data-text-scale"));
    if (!isFinite(ratio) || ratio <= 0) ratio = 0.3;
    if (!isFinite(scale) || scale <= 0) scale = 1;

    textLayer.style.fontSize = (height * ratio * scale) + "px";
  }

  function initPersonalizationPreviews() {
    var previews = document.querySelectorAll("[data-personalization-preview]");
    if (!previews.length) return;

    function sizeAll() {
      Array.prototype.forEach.call(previews, sizePersonalizationText);
    }

    Array.prototype.forEach.call(previews, function (preview) {
      var image = preview.querySelector(".admin-personalization__base");
      if (!image) return;
      if (image.complete) sizePersonalizationText(preview);
      else image.addEventListener("load", function () { sizePersonalizationText(preview); });
    });

    window.addEventListener("resize", sizeAll);
    sizeAll();
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-zone-editor]"), initZoneEditor);
    initPersonalizationPreviews();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
