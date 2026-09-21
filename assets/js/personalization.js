/* =========================================================================
   Product personalization — the customer's live engraving configurator.

   Loaded only by /product.php, and only for a product whose CMS
   configuration actually offers personalization (see
   partials/product-personalization.php). Every other page, and every normal
   product, never downloads this file.

   It owns exactly one thing: the personalization panel and the state behind
   it — which may now be several ZONES spread over several VIEWS (front,
   back, ...). The cart, the price resolution and the add-to-cart button stay
   entirely assets/js/shop/shop.js's job; this module simply publishes its current
   state on window.VVLPersonalization, which main.js reads when the customer
   adds the product to the cart. That keeps the two independent: main.js works
   unchanged when this file is absent, and this file needs to know nothing
   about the cart's storage format.

   Nothing here is a security boundary, and nothing here decides a price. The
   server re-validates every value against the product's live configuration at
   checkout (App\Service\Personalization\PersonalizationValidator) and re-reads
   every surcharge from the product's own zones. What this module guarantees is
   that a customer sees, and can only produce, a personalization that fits
   inside the areas the administrator defined.

   Coordinate system (identical to the PHP side, see PersonalizationRules):
     - a ZONE is positioned in percentages of its view's preview image;
     - a layer's transform x/y are fractions 0..1 of the ZONE box and mark the
       layer's CENTRE — clamped to 0..1 so the centre can never leave the
       zone, while the zone itself clips any overflow;
     - scale 1.0 means text at 30% of the zone's height, an image at 60% of
       its width, so the same numbers mean the same picture at any size.
   ========================================================================= */
(function () {
  "use strict";

  var root = document.querySelector("[data-personalizer]");
  if (!root) return;

  var configEl = root.querySelector("[data-personalizer-config]");
  var config = null;

  try {
    config = JSON.parse(configEl ? configEl.textContent : "null");
  } catch (e) {
    config = null;
  }

  if (!config || !config.views || !config.views.length) {
    // A panel we cannot configure is worse than no panel.
    root.hidden = true;
    return;
  }

  /* The panel's own sentences, in the language of this page: the server
     resolved them for this request and put them in the configuration
     (App\Service\Personalization\PersonalizationScriptText). This script
     never picks a language and never holds a Dutch/English pair; a missing
     key is an empty string. {label}/{max} are filled in here, and the result
     is plain text, written with textContent. */
  var TEXT = config.text && typeof config.text === "object" ? config.text : {};

  function text(key, values) {
    var sentence = Object.prototype.hasOwnProperty.call(TEXT, key) && typeof TEXT[key] === "string" ? TEXT[key] : "";
    if (!values) return sentence;

    return sentence.replace(/\{(\w+)\}/g, function (match, name) {
      return Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match;
    });
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  var MIN_SCALE = (config.limits && config.limits.min_scale) || 0.2;
  var MAX_SCALE = (config.limits && config.limits.max_scale) || 3;
  var MAX_ROTATION = (config.limits && config.limits.max_rotation) || 180;
  var TEXT_RATIO = (config.render && config.render.text_base_height_ratio) || 0.3;
  var IMAGE_RATIO = (config.render && config.render.image_base_width_ratio) || 0.6;

  /* ---------------------------------------------------------------------
     Money — whole cents, never floats, mirroring App\Service\Personalization\Money.
     --------------------------------------------------------------------- */

  function centsFrom(value) {
    if (typeof value === "number" && isFinite(value)) return Math.round(value * 100);
    if (typeof value !== "string") return 0;
    var match = /^(-)?(\d*)(?:[.,](\d*))?$/.exec(value.trim());
    if (!match) return 0;
    var whole = parseInt(match[2] || "0", 10);
    var fraction = (match[3] || "").slice(0, 2);
    while (fraction.length < 2) fraction += "0";
    var cents = whole * 100 + parseInt(fraction, 10);
    return match[1] === "-" ? -cents : cents;
  }

  function formatEuro(cents) {
    var sign = cents < 0 ? "-" : "";
    var abs = Math.abs(cents);
    return sign + "€ " + Math.floor(abs / 100) + "," + String(abs % 100).padStart(2, "0");
  }

  /* ---------------------------------------------------------------------
     Configuration lookups
     --------------------------------------------------------------------- */

  /* The shop-wide font library, as of Phase 3. It is a property of the SHOP,
     not of a zone: every text zone offers exactly the fonts the CMS has
     active, and the customer picks. `fonts` is already ordered and already
     filtered to active fonts server-side. */
  var FONTS = Array.isArray(config.fonts) ? config.fonts : [];
  var DEFAULT_FONT = config.default_font || (FONTS[0] && FONTS[0].key) || null;

  function fontByKey(key) {
    var found = null;
    FONTS.forEach(function (font) {
      if (font.key === key) found = font;
    });
    return found;
  }

  /* The fixed text-colour palette. Sent whole by the server so the editor
     never invents a colour of its own — every hex that reaches a style
     attribute here came out of App\Service\Personalization\PersonalizationColors. */
  var COLORS = Array.isArray(config.colors) ? config.colors : [];
  var DEFAULT_COLOR = config.default_color || (COLORS[0] && COLORS[0].key) || null;

  function colorByKey(key) {
    var found = null;
    COLORS.forEach(function (color) {
      if (color.key === key) found = color;
    });
    return found;
  }

  function colorHex(key) {
    var color = colorByKey(key) || colorByKey(DEFAULT_COLOR);
    return color ? color.hex : "";
  }

  var zonesByKey = {};
  var viewOfZone = {};
  /* Where each zone sits in the product's OWN configured order — the order
     the administrator arranged the views and zones in. Used to emit zones in
     that same order everywhere, so the cart, the checkout payload and the CMS
     all read top-to-bottom the same way (and so two identical
     personalizations always serialise identically). */
  var zoneOrder = {};

  config.views.forEach(function (view) {
    view.zones.forEach(function (zone) {
      zonesByKey[zone.zone_key] = zone;
      viewOfZone[zone.zone_key] = view;
      zoneOrder[zone.zone_key] = Object.keys(zoneOrder).length;
    });
  });

  /* The zone's name in the language of this page, or its key when the
     administrator never named it. */
  function zoneLabel(zone) {
    return zone.label || zone.zone_key;
  }

  /* ---------------------------------------------------------------------
     State — one entry per zone, published as-is to main.js
     --------------------------------------------------------------------- */

  function neutralTransform() {
    return { x: 0.5, y: 0.5, scale: 1, rotation: 0 };
  }

  /* No zone argument any more: as of Phase 3 the starting font is a
     property of the SHOP's library, not of the zone. */
  function neutralZoneState() {
    return {
      text: "",
      font: DEFAULT_FONT,
      color: DEFAULT_COLOR,
      upload_token: null,
      upload_name: null,
      upload_preview_url: null,
      transform: { text: neutralTransform(), image: neutralTransform() }
    };
  }

  var state = { zones: {} };
  var basePriceCents = 0;
  var activeView = config.views[0].view_key;

  Object.keys(zonesByKey).forEach(function (key) {
    state.zones[key] = neutralZoneState();
  });

  /* ---------------------------------------------------------------------
     Elements
     --------------------------------------------------------------------- */

  function one(selector) { return root.querySelector(selector); }
  function all(selector) { return Array.prototype.slice.call(root.querySelectorAll(selector)); }
  function attr(name, value) { return '[' + name + '="' + value.replace(/"/g, '\\"') + '"]'; }

  var statusEl = one("[data-personalizer-status]");
  var errorEl = one("[data-personalizer-error]");
  var priceEl = one("[data-personalizer-price]");

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message || "";
    errorEl.hidden = !message;
  }

  function showStatus(message) {
    if (!statusEl) return;
    statusEl.textContent = message || "";
    statusEl.hidden = !message;
  }

  function zoneBox(zoneKey) { return one(attr("data-personalizer-zone", zoneKey)); }
  function layerEl(zoneKey, kind) {
    return one('[data-personalizer-layer="' + kind + '"]' + attr("data-personalizer-layer-zone", zoneKey));
  }

  /* ---------------------------------------------------------------------
     Rendering
     --------------------------------------------------------------------- */

  /* Text size is the one thing that cannot be a percentage in CSS (font-size
     percentages resolve against the parent's font size, not its height), so
     it is recomputed from the zone's measured height — on load, on resize, on
     a view switch, and whenever the scale changes. A hidden view measures 0
     and is simply skipped; switching to it recomputes. */
  function applyTextSize(zoneKey) {
    var layer = layerEl(zoneKey, "text");
    var box = zoneBox(zoneKey);
    if (!layer || !box) return;

    var height = box.getBoundingClientRect().height;
    if (!height) return;

    layer.style.fontSize = (height * TEXT_RATIO * state.zones[zoneKey].transform.text.scale) + "px";
  }

  function applyLayer(zoneKey, kind) {
    var layer = layerEl(zoneKey, kind);
    if (!layer) return;

    var transform = state.zones[zoneKey].transform[kind];
    layer.style.left = (transform.x * 100) + "%";
    layer.style.top = (transform.y * 100) + "%";
    layer.style.transform = "translate(-50%, -50%) rotate(" + transform.rotation + "deg)";

    if (kind === "image") {
      layer.style.width = (IMAGE_RATIO * transform.scale * 100) + "%";
    } else {
      applyTextSize(zoneKey);
    }
  }

  function renderZoneText(zoneKey) {
    var layer = layerEl(zoneKey, "text");
    var zoneState = state.zones[zoneKey];
    if (!layer) return;

    layer.textContent = zoneState.text;
    layer.hidden = zoneState.text === "";

    if (zoneState.font) {
      var font = fontByKey(zoneState.font);
      layer.style.fontFamily = font ? font.stack : "";
    }

    // Only ever a hex the server sent; an unknown key falls back rather than
    // reaching the style attribute.
    layer.style.color = colorHex(zoneState.color);

    applyLayer(zoneKey, "text");

    var counter = one(attr("data-personalizer-text-count", zoneKey));
    if (counter) counter.textContent = String(zoneState.text.length);
  }

  function renderZoneImage(zoneKey) {
    var layer = layerEl(zoneKey, "image");
    var zoneState = state.zones[zoneKey];
    if (!layer) return;

    if (zoneState.upload_preview_url) {
      layer.src = zoneState.upload_preview_url;
      layer.hidden = false;
    } else {
      layer.removeAttribute("src");
      layer.hidden = true;
    }

    var nameEl = one(attr("data-personalizer-file-name", zoneKey));
    if (nameEl) {
      nameEl.textContent = zoneState.upload_name || "";
      nameEl.hidden = !zoneState.upload_name;
    }

    var removeBtn = one(attr("data-personalizer-remove-image", zoneKey));
    if (removeBtn) removeBtn.hidden = !zoneState.upload_token;

    var controls = one(attr("data-personalizer-image-controls", zoneKey));
    if (controls) controls.hidden = !zoneState.upload_token;

    applyLayer(zoneKey, "image");
  }

  function renderZone(zoneKey) {
    renderZoneText(zoneKey);
    renderZoneImage(zoneKey);
  }

  function renderAll() {
    Object.keys(state.zones).forEach(renderZone);
    renderPrice();
  }

  /* ---------------------------------------------------------------------
     Price breakdown — display only; checkout recalculates from the database
     --------------------------------------------------------------------- */

  function usedZoneKeys() {
    return Object.keys(state.zones).filter(function (key) {
      var zoneState = state.zones[key];
      return zoneState.text !== "" || !!zoneState.upload_token;
    });
  }

  function surchargeCents() {
    return usedZoneKeys().reduce(function (sum, key) {
      return sum + (zonesByKey[key].surcharge_cents || 0);
    }, 0);
  }

  function renderPrice() {
    if (!priceEl) return;

    var used = usedZoneKeys().filter(function (key) {
      return (zonesByKey[key].surcharge_cents || 0) > 0;
    });

    // Nothing to explain until we know the product's own price.
    if (!basePriceCents && used.length === 0) {
      priceEl.hidden = true;
      return;
    }

    priceEl.hidden = false;

    var baseEl = priceEl.querySelector("[data-personalizer-price-base]");
    if (baseEl) baseEl.textContent = formatEuro(basePriceCents);

    var linesEl = priceEl.querySelector("[data-personalizer-price-lines]");
    if (linesEl) {
      linesEl.textContent = "";
      used.forEach(function (key) {
        var row = document.createElement("div");
        row.className = "personalizer__price-row";
        var name = document.createElement("span");
        name.textContent = zoneLabel(zonesByKey[key]);
        var amount = document.createElement("strong");
        amount.textContent = "+ " + formatEuro(zonesByKey[key].surcharge_cents);
        row.appendChild(name);
        row.appendChild(amount);
        linesEl.appendChild(row);
      });
    }

    var totalEl = priceEl.querySelector("[data-personalizer-price-total]");
    if (totalEl) totalEl.textContent = formatEuro(basePriceCents + surchargeCents());
  }

  /* ---------------------------------------------------------------------
     View switching — state per view is never touched by switching
     --------------------------------------------------------------------- */

  function switchView(viewKey) {
    activeView = viewKey;

    all("[data-personalizer-stage]").forEach(function (stage) {
      stage.hidden = stage.getAttribute("data-personalizer-stage") !== viewKey;
    });
    all("[data-personalizer-panel]").forEach(function (panel) {
      panel.hidden = panel.getAttribute("data-personalizer-panel") !== viewKey;
    });
    all("[data-personalizer-tab]").forEach(function (tab) {
      var isActive = tab.getAttribute("data-personalizer-tab") === viewKey;
      tab.classList.toggle("is-active", isActive);
      tab.setAttribute("aria-selected", isActive ? "true" : "false");
    });

    // The newly visible zones can only now be measured, so their text needs
    // its size recomputed — nothing about their VALUES changes.
    (config.views.filter(function (v) { return v.view_key === viewKey; })[0] || { zones: [] })
      .zones.forEach(function (zone) { applyTextSize(zone.zone_key); });
  }

  all("[data-personalizer-tab]").forEach(function (tab) {
    tab.addEventListener("click", function () {
      switchView(tab.getAttribute("data-personalizer-tab"));
      saveDraft();
    });
  });

  /* ---------------------------------------------------------------------
     Dragging — one Pointer Events code path for mouse, pen and touch
     --------------------------------------------------------------------- */

  function makeDraggable(zoneKey, kind) {
    var layer = layerEl(zoneKey, kind);
    var box = zoneBox(zoneKey);
    if (!layer || !box) return;

    var drag = null;

    layer.addEventListener("pointerdown", function (event) {
      if (event.button !== undefined && event.button !== 0) return;
      if (layer.hidden) return;

      var rect = box.getBoundingClientRect();
      drag = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        start: { x: state.zones[zoneKey].transform[kind].x, y: state.zones[zoneKey].transform[kind].y },
        zoneWidth: rect.width || 1,
        zoneHeight: rect.height || 1
      };

      layer.classList.add("is-dragging");
      layer.focus();

      if (layer.setPointerCapture) {
        try { layer.setPointerCapture(event.pointerId); } catch (e) { /* not fatal */ }
      }

      // Stops the page from scrolling (touch) or the image from being dragged
      // as a link/file (mouse) while positioning.
      event.preventDefault();
    });

    function move(event) {
      if (!drag || event.pointerId !== drag.pointerId) return;

      var dx = (event.clientX - drag.startX) / drag.zoneWidth;
      var dy = (event.clientY - drag.startY) / drag.zoneHeight;

      state.zones[zoneKey].transform[kind].x = clamp(drag.start.x + dx, 0, 1);
      state.zones[zoneKey].transform[kind].y = clamp(drag.start.y + dy, 0, 1);
      applyLayer(zoneKey, kind);
      event.preventDefault();
    }

    function end(event) {
      if (!drag || (event && event.pointerId !== drag.pointerId)) return;
      drag = null;
      layer.classList.remove("is-dragging");
      // Once per gesture, not once per pointermove.
      saveDraft();
    }

    document.addEventListener("pointermove", move);
    document.addEventListener("pointerup", end);
    document.addEventListener("pointercancel", end);

    /* Keyboard equivalent of the drag — the whole reason each layer is
       focusable. One press moves 2% of the zone, fine-grained enough to place
       something precisely without needing 50 presses. */
    layer.addEventListener("keydown", function (event) {
      var step = 0.02;
      var dx = 0;
      var dy = 0;

      if (event.key === "ArrowLeft") dx = -step;
      else if (event.key === "ArrowRight") dx = step;
      else if (event.key === "ArrowUp") dy = -step;
      else if (event.key === "ArrowDown") dy = step;
      else return;

      event.preventDefault();
      state.zones[zoneKey].transform[kind].x = clamp(state.zones[zoneKey].transform[kind].x + dx, 0, 1);
      state.zones[zoneKey].transform[kind].y = clamp(state.zones[zoneKey].transform[kind].y + dy, 0, 1);
      applyLayer(zoneKey, kind);
      saveDraft();
    });
  }

  Object.keys(zonesByKey).forEach(function (zoneKey) {
    makeDraggable(zoneKey, "text");
    makeDraggable(zoneKey, "image");
  });

  /* ---------------------------------------------------------------------
     Controls
     --------------------------------------------------------------------- */

  all("[data-personalizer-text-input]").forEach(function (input) {
    var zoneKey = input.getAttribute("data-personalizer-text-input");
    input.addEventListener("input", function () {
      // maxlength already stops typing past the limit; slicing also covers a
      // paste on the browsers that don't enforce it there.
      var limit = zonesByKey[zoneKey].max_text_length;
      state.zones[zoneKey].text = input.value.slice(0, limit);
      if (input.value !== state.zones[zoneKey].text) input.value = state.zones[zoneKey].text;
      showError(null);
      renderZoneText(zoneKey);
      renderPrice();
      saveDraft();
    });
  });

  /* The closed selector renders in the face it currently names, so the
     customer is looking at the shape they are about to engrave rather than at
     a word. The option list already previews itself (inline styles from the
     server); only the closed control has to follow the selection. */
  function applyFontSelectFace(select) {
    var font = fontByKey(select.value);
    select.style.fontFamily = font ? font.stack : "";
  }

  all("[data-personalizer-font]").forEach(function (select) {
    var zoneKey = select.getAttribute("data-personalizer-font");
    applyFontSelectFace(select);
    select.addEventListener("change", function () {
      state.zones[zoneKey].font = select.value;
      applyFontSelectFace(select);
      renderZoneText(zoneKey);
      saveDraft();
    });
  });

  all("[data-personalizer-color]").forEach(function (input) {
    var zoneKey = input.getAttribute("data-personalizer-color");
    input.addEventListener("change", function () {
      if (!input.checked) return;
      state.zones[zoneKey].color = input.value;
      renderZoneText(zoneKey);
      saveDraft();
    });
  });

  all("[data-personalizer-scale]").forEach(function (slider) {
    var kind = slider.getAttribute("data-personalizer-scale");
    var zoneKey = slider.getAttribute("data-personalizer-scale-zone");
    slider.addEventListener("input", function () {
      var value = parseFloat(slider.value);
      state.zones[zoneKey].transform[kind].scale = clamp(isFinite(value) ? value : 1, MIN_SCALE, MAX_SCALE);
      applyLayer(zoneKey, kind);
      saveDraft();
    });
  });

  all("[data-personalizer-rotation]").forEach(function (slider) {
    var kind = slider.getAttribute("data-personalizer-rotation");
    var zoneKey = slider.getAttribute("data-personalizer-rotation-zone");
    slider.addEventListener("input", function () {
      var value = parseFloat(slider.value);
      state.zones[zoneKey].transform[kind].rotation =
        clamp(isFinite(value) ? value : 0, -MAX_ROTATION, MAX_ROTATION);
      applyLayer(zoneKey, kind);
      saveDraft();
    });
  });

  function resetSlider(zoneKey, kind, name, value) {
    var slider = one('[data-personalizer-' + name + '="' + kind + '"]' + attr("data-personalizer-" + name + "-zone", zoneKey));
    if (slider) slider.value = String(value);
  }

  function clearImage(zoneKey) {
    var zoneState = state.zones[zoneKey];
    zoneState.upload_token = null;
    zoneState.upload_name = null;
    zoneState.upload_preview_url = null;
    zoneState.transform.image = neutralTransform();

    var fileInput = one(attr("data-personalizer-file-input", zoneKey));
    if (fileInput) fileInput.value = "";
    resetSlider(zoneKey, "image", "scale", 1);
    resetSlider(zoneKey, "image", "rotation", 0);

    renderZoneImage(zoneKey);
    renderPrice();
    saveDraft();
  }

  function resetZone(zoneKey) {
    state.zones[zoneKey] = neutralZoneState();

    var input = one(attr("data-personalizer-text-input", zoneKey));
    if (input) input.value = "";
    var fontSelect = one(attr("data-personalizer-font", zoneKey));
    if (fontSelect && DEFAULT_FONT) {
      fontSelect.value = DEFAULT_FONT;
      applyFontSelectFace(fontSelect);
    }
    var colorInput = one('[data-personalizer-color="' + zoneKey.replace(/"/g, '\\"') + '"][value="' + String(DEFAULT_COLOR).replace(/"/g, '\\"') + '"]');
    if (colorInput) colorInput.checked = true;
    resetSlider(zoneKey, "text", "scale", 1);
    resetSlider(zoneKey, "text", "rotation", 0);

    clearImage(zoneKey);
    renderZone(zoneKey);
    renderPrice();
    saveDraft();
  }

  all("[data-personalizer-remove-image]").forEach(function (button) {
    var zoneKey = button.getAttribute("data-personalizer-remove-image");
    button.addEventListener("click", function () {
      clearImage(zoneKey);
      showError(null);
      showStatus(null);
    });
  });

  all("[data-personalizer-reset-zone]").forEach(function (button) {
    var zoneKey = button.getAttribute("data-personalizer-reset-zone");
    button.addEventListener("click", function () {
      resetZone(zoneKey);
      showError(null);
      showStatus(null);
    });
  });

  /* ---------------------------------------------------------------------
     Uploading — one at a time, and nothing else moves while it runs

     The old handler was fire-and-forget, which produced a real race: pick
     image A, keep editing, pick image B, and whichever RESPONSE came back
     last won — often A, silently overwriting the newer state.

     Two independent guards fix it, and both are needed:

       1. The editor is LOCKED while an upload is in flight. Every control is
          disabled, add-to-cart with it, and the section carries aria-busy so
          the wait is announced rather than looking like a dead UI. A second
          upload therefore cannot be started at all.
       2. Every request still carries a sequence number, and a response whose
          number is not the current one is DROPPED. That is belt and braces:
          the lock already prevents a second upload, but a response arriving
          after the customer cleared the zone (or after a reset) must not
          resurrect it either.
     --------------------------------------------------------------------- */

  var uploadSequence = 0;
  var activeUpload = 0;

  function isUploading() {
    return activeUpload !== 0;
  }

  /**
   * Locks or unlocks the whole editor. Deliberately blunt — every input,
   * button and the purchase action — because "which controls are safe to
   * touch mid-upload" is a question with no good answer and a bad failure
   * mode.
   */
  function setBusy(busy, message) {
    root.setAttribute("aria-busy", busy ? "true" : "false");
    root.classList.toggle("is-busy", !!busy);

    all("input, select, button, textarea").forEach(function (el) {
      if (busy) {
        // Remember only what WE disabled, so unlocking never enables a
        // control that was disabled for its own reasons.
        if (!el.disabled) {
          el.setAttribute("data-personalizer-relock", "1");
          el.disabled = true;
        }
      } else if (el.getAttribute("data-personalizer-relock") === "1") {
        el.removeAttribute("data-personalizer-relock");
        el.disabled = false;
      }
    });

    // The purchase action lives inside this section, so the loop above
    // already covers it — but a page that ever moves it out must still not
    // be orderable mid-upload.
    var addButton = document.querySelector("[data-product-add-to-cart]");
    if (addButton && !root.contains(addButton)) {
      if (busy) {
        addButton.disabled = true;
        addButton.setAttribute("data-personalizer-relock", "1");
      } else if (addButton.getAttribute("data-personalizer-relock") === "1") {
        addButton.removeAttribute("data-personalizer-relock");
        addButton.disabled = false;
      }
    }

    showStatus(busy ? message : null);
  }

  all("[data-personalizer-file-input]").forEach(function (fileInput) {
    var zoneKey = fileInput.getAttribute("data-personalizer-file-input");

    fileInput.addEventListener("change", function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;

      // Belt and braces: the editor is locked during an upload, so this can
      // only be reached if a browser fires change on a disabled input.
      if (isUploading()) {
        fileInput.value = "";
        return;
      }

      var sequence = ++uploadSequence;
      activeUpload = sequence;

      showError(null);
      setBusy(true, text("upload_busy"));

      var body = new FormData();
      body.append("product_id", String(config.product_id));
      body.append("zone_key", zoneKey);
      body.append("file", file);

      function finish() {
        if (activeUpload === sequence) {
          activeUpload = 0;
          setBusy(false);
        }
      }

      fetch(config.upload_endpoint, { method: "POST", body: body })
        .then(function (res) {
          return res.json()
            .catch(function () { return {}; })
            .then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (result) {
          // A stale or out-of-order response changes nothing.
          if (sequence !== uploadSequence) return;
          finish();

          if (!result.ok || !result.data || !result.data.token) {
            // The server's message is already customer-facing and never
            // contains a path, an id or a technical detail.
            showError((result.data && result.data.error) || text("upload_failed"));
            clearImage(zoneKey);
            return;
          }

          var zoneState = state.zones[zoneKey];
          zoneState.upload_token = result.data.token;
          zoneState.upload_name = result.data.original_filename || file.name;
          zoneState.upload_preview_url = result.data.preview_url;
          zoneState.transform.image = neutralTransform();
          renderZoneImage(zoneKey);
          renderPrice();
          saveDraft();
        })
        .catch(function () {
          if (sequence !== uploadSequence) return;
          finish();

          showError(text("upload_connection_failed"));
          clearImage(zoneKey);
        });
    });
  });

  function resetAll() {
    Object.keys(state.zones).forEach(resetZone);
    showError(null);
    showStatus(null);
    switchView(config.views[0].view_key);
    renderAll();
    // Nothing is left to restore, so the draft goes with it — otherwise a
    // refresh would bring back what the customer just cleared.
    clearDraft();
  }

  var resetBtn = one("[data-personalizer-reset]");
  if (resetBtn) resetBtn.addEventListener("click", resetAll);

  /* Keep text sizes correct when the layout changes (responsive image,
     orientation change, the gallery finishing loading). */
  function resizeAll() {
    Object.keys(state.zones).forEach(applyTextSize);
  }

  window.addEventListener("resize", resizeAll);
  if (typeof ResizeObserver !== "undefined") {
    all("[data-personalizer-stage]").forEach(function (stage) {
      new ResizeObserver(resizeAll).observe(stage);
    });
  }

  all(".personalizer__base").forEach(function (image) {
    if (image.complete) resizeAll();
    else image.addEventListener("load", resizeAll);
  });

  /* ---------------------------------------------------------------------
     Draft persistence — a refresh must not throw the customer's work away

     Everything the customer produced lives in `state.zones`, so persisting a
     draft is persisting that object plus which view they were looking at.
     localStorage, keyed per product, for the same reason the cart uses it:
     there is no account and no server-side session for an anonymous visitor,
     and a draft is per-browser by nature.

     What is deliberately NOT stored: anything about price. The surcharge and
     the product price are re-read from the server on every render and
     recalculated at checkout; a stale localStorage number must never be able
     to influence what something costs.

     The uploaded image travels as its TOKEN, never as image data. The file
     itself is already on the server, and an unclaimed upload survives long
     enough (PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS) for a draft to
     be worth restoring. If the token has since been swept, the image simply
     fails to load and the customer picks a new file — the rest of their work
     is untouched.
     --------------------------------------------------------------------- */

  var DRAFT_PREFIX = "vvl-personalization-draft:";
  var DRAFT_KEY = DRAFT_PREFIX + config.product_id;
  var DRAFT_VERSION = 1;

  function readJson(key) {
    try {
      var raw = window.localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function writeJson(key, value) {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      // A full or disabled localStorage costs the draft, never the editor.
    }
  }

  function clearDraft() {
    try {
      window.localStorage.removeItem(DRAFT_KEY);
    } catch (e) { /* nothing to do */ }
  }

  /** True when the customer has actually produced something worth keeping. */
  function hasContent() {
    return usedZoneKeys().length > 0;
  }

  function saveDraft() {
    if (!hasContent()) {
      clearDraft();
      return;
    }

    writeJson(DRAFT_KEY, {
      v: DRAFT_VERSION,
      product_id: config.product_id,
      active_view: activeView,
      saved_at: Date.now(),
      zones: state.zones
    });
  }

  /**
   * Copies one persisted zone onto live state, field by field. Never a blind
   * assign: a stored draft is untrusted input like any other, and a font,
   * colour or zone that no longer exists must not be able to reappear.
   */
  function applyZoneState(zoneKey, stored) {
    if (!zonesByKey[zoneKey] || !stored || typeof stored !== "object") return;

    var zoneState = state.zones[zoneKey];
    var zone = zonesByKey[zoneKey];

    if (typeof stored.text === "string" && zone.allow_text) {
      zoneState.text = stored.text.slice(0, zone.max_text_length);
    }
    if (fontByKey(stored.font)) zoneState.font = stored.font;
    if (colorByKey(stored.color)) zoneState.color = stored.color;

    if (zone.allow_image && typeof stored.upload_token === "string" && /^[0-9a-f]{32}$/.test(stored.upload_token)) {
      zoneState.upload_token = stored.upload_token;
      zoneState.upload_name = typeof stored.upload_name === "string" ? stored.upload_name : null;
      // Rebuilt from the token rather than trusted from storage, so a
      // tampered draft cannot point an <img> anywhere it likes.
      zoneState.upload_preview_url = "/api/personalization-image.php?token=" + encodeURIComponent(stored.upload_token);
    }

    ["text", "image"].forEach(function (kind) {
      var stored_ = stored.transform && stored.transform[kind];
      if (!stored_) return;
      var target = zoneState.transform[kind];
      target.x = clamp(numberOr(stored_.x, 0.5), 0, 1);
      target.y = clamp(numberOr(stored_.y, 0.5), 0, 1);
      target.scale = clamp(numberOr(stored_.scale, 1), MIN_SCALE, MAX_SCALE);
      target.rotation = zone.allow_rotation
        ? clamp(numberOr(stored_.rotation, 0), -MAX_ROTATION, MAX_ROTATION)
        : 0;
    });
  }

  function numberOr(value, fallback) {
    var number = typeof value === "number" ? value : parseFloat(value);
    return isFinite(number) ? number : fallback;
  }

  /** Pushes restored state back into the form controls. */
  function syncControls() {
    Object.keys(state.zones).forEach(function (zoneKey) {
      var zoneState = state.zones[zoneKey];

      var textInput = one(attr("data-personalizer-text-input", zoneKey));
      if (textInput) textInput.value = zoneState.text;

      var fontSelect = one(attr("data-personalizer-font", zoneKey));
      if (fontSelect && zoneState.font) {
        fontSelect.value = zoneState.font;
        applyFontSelectFace(fontSelect);
      }

      all('[data-personalizer-color="' + zoneKey.replace(/"/g, '\\"') + '"]').forEach(function (input) {
        input.checked = input.value === zoneState.color;
      });

      ["text", "image"].forEach(function (kind) {
        resetSlider(zoneKey, kind, "scale", zoneState.transform[kind].scale);
        resetSlider(zoneKey, kind, "rotation", zoneState.transform[kind].rotation);
      });
    });
  }

  /**
   * Restores a draft: the cart line named in the URL when the customer came
   * back from the cart to edit it, otherwise this product's own draft.
   *
   * The cart line wins on purpose. "Edit this line" has to show that line,
   * even when the customer has since started something else on the same
   * product — otherwise they would edit one thing and change another.
   */
  function restore() {
    var restored = null;

    var lineId = new URLSearchParams(window.location.search).get("line");
    if (lineId) {
      var line = findCartLine(lineId);
      if (line) restored = zonesFromCartLine(line);
    }

    if (!restored) {
      var draft = readJson(DRAFT_KEY);
      if (draft && draft.v === DRAFT_VERSION && draft.product_id === config.product_id && draft.zones) {
        restored = { zones: draft.zones, active_view: draft.active_view };
      }
    }

    if (!restored) return;

    Object.keys(restored.zones || {}).forEach(function (zoneKey) {
      applyZoneState(zoneKey, restored.zones[zoneKey]);
    });

    syncControls();

    var view = restored.active_view;
    if (view && config.views.some(function (v) { return v.view_key === view; })) {
      activeView = view;
    }
  }

  /** One cart line by its line_id, read straight out of the cart's storage. */
  function findCartLine(lineId) {
    var cart = readJson("vvl-cart");
    if (!Array.isArray(cart)) return null;

    var found = null;
    cart.forEach(function (item) {
      if (item && String(item.line_id) === String(lineId) && Number(item.id) === Number(config.product_id)) {
        found = item;
      }
    });

    return found;
  }

  /**
   * A cart line's personalization, in the shape applyZoneState() wants. The
   * cart stores a LIST of used zones; the editor keeps a map of every zone.
   */
  function zonesFromCartLine(line) {
    var personalization = line && line.personalization;
    var list = personalization && Array.isArray(personalization.zones) ? personalization.zones : [];
    if (!list.length) return null;

    var zones = {};
    var firstView = null;

    list.forEach(function (zone) {
      if (!zone || !zone.zone_key) return;
      zones[zone.zone_key] = {
        text: zone.text,
        font: zone.font,
        color: zone.color,
        upload_token: zone.upload_token,
        upload_name: zone.upload_name,
        transform: zone.transform
      };
      if (!firstView && zone.view_key) firstView = zone.view_key;
    });

    return { zones: zones, active_view: firstView };
  }

  /* ---------------------------------------------------------------------
     Composed preview — the picture the customer actually approved

     At add-to-cart the browser rasterises each USED view exactly as it is on
     screen and posts it. The server validates and re-encodes it (see
     api/personalization-preview.php), and the CMS order screen offers it as
     "Download preview".

     Why the browser and not the server: the text is set in a webfont the
     browser downloaded, and GD can only draw with a local TTF/OTF, so a
     server-drawn version would differ from what the customer approved in
     exactly the cases that matter. Everything here is SUPPLEMENTARY — a
     failure is swallowed, the line is still added, and the order is still
     complete without it.
     --------------------------------------------------------------------- */

  function loadImage(src) {
    return new Promise(function (resolve, reject) {
      var image = new Image();
      // Same-origin in every case (the product's own preview image, and the
      // customer's upload through its token URL), so the canvas never taints.
      image.onload = function () { resolve(image); };
      image.onerror = function () { reject(new Error("image")); };
      image.src = src;
    });
  }

  /** Draws one view onto a canvas and returns it as a PNG blob. */
  function composeView(view, zoneStates) {
    return loadImage(view.preview_image).then(function (base) {
      var width = Math.min(config.preview_width || 1400, base.naturalWidth || 1400);
      var scale = width / (base.naturalWidth || width);
      var height = Math.round((base.naturalHeight || width) * scale);

      var canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      var ctx = canvas.getContext("2d");
      ctx.drawImage(base, 0, 0, width, height);

      // Each zone is a rectangle in percentages of this image; each layer is
      // positioned in fractions of that rectangle. Identical arithmetic to
      // the on-screen preview and to PersonalizationRules, so the picture is
      // the same picture.
      var pending = view.zones.map(function (zone) {
        var zoneState = zoneStates[zone.zone_key];
        if (!zoneState) return Promise.resolve();

        var box = {
          x: (zone.area.x / 100) * width,
          y: (zone.area.y / 100) * height,
          w: (zone.area.width / 100) * width,
          h: (zone.area.height / 100) * height
        };

        var drawImageLayer = Promise.resolve();

        if (zoneState.upload_token && zoneState.upload_preview_url) {
          drawImageLayer = loadImage(zoneState.upload_preview_url).then(function (layer) {
            var transform = zoneState.transform.image;
            var layerWidth = IMAGE_RATIO * transform.scale * box.w;
            var ratio = (layer.naturalHeight || 1) / (layer.naturalWidth || 1);
            var layerHeight = layerWidth * ratio;

            ctx.save();
            ctx.beginPath();
            ctx.rect(box.x, box.y, box.w, box.h);
            ctx.clip();
            ctx.translate(box.x + transform.x * box.w, box.y + transform.y * box.h);
            ctx.rotate((transform.rotation * Math.PI) / 180);
            ctx.drawImage(layer, -layerWidth / 2, -layerHeight / 2, layerWidth, layerHeight);
            ctx.restore();
          }).catch(function () { /* a missing layer must not lose the rest */ });
        }

        return drawImageLayer.then(function () {
          if (!zoneState.text) return;

          var transform = zoneState.transform.text;
          var font = fontByKey(zoneState.font);
          var fontSize = box.h * TEXT_RATIO * transform.scale;

          ctx.save();
          ctx.beginPath();
          ctx.rect(box.x, box.y, box.w, box.h);
          ctx.clip();
          ctx.translate(box.x + transform.x * box.w, box.y + transform.y * box.h);
          ctx.rotate((transform.rotation * Math.PI) / 180);
          ctx.font = fontSize + "px " + (font ? font.stack : "sans-serif");
          ctx.textAlign = "center";
          ctx.textBaseline = "middle";
          ctx.fillStyle = colorHex(zoneState.color);
          ctx.fillText(zoneState.text, 0, 0);
          ctx.restore();
        });
      });

      return Promise.all(pending).then(function () {
        return new Promise(function (resolve) {
          canvas.toBlob(function (blob) { resolve(blob); }, "image/png");
        });
      });
    });
  }

  /** Which views the given zone states actually put something on. */
  function usedViewsFor(zoneStates) {
    var keys = {};
    Object.keys(zoneStates).forEach(function (zoneKey) {
      if (viewOfZone[zoneKey]) keys[viewOfZone[zoneKey].view_key] = true;
    });

    return config.views.filter(function (view) { return keys[view.view_key]; });
  }

  /**
   * A deep-enough copy of the zones that currently carry something.
   *
   * Taken SYNCHRONOUSLY at the moment add-to-cart is clicked, because
   * everything after it is asynchronous (fonts, image decoding, the POST) and
   * the editor is reset for the next unit the instant the line is added.
   * Composing from live state would therefore rasterise an empty editor.
   */
  function snapshotZoneStates() {
    var copy = {};

    usedZoneKeys().forEach(function (zoneKey) {
      var zoneState = state.zones[zoneKey];
      copy[zoneKey] = {
        text: zoneState.text,
        font: zoneState.font,
        color: zoneState.color,
        upload_token: zoneState.upload_token,
        upload_preview_url: zoneState.upload_preview_url,
        transform: {
          text: cloneTransform(zoneState.transform.text),
          image: cloneTransform(zoneState.transform.image)
        }
      };
    });

    return copy;
  }

  /**
   * Composes and posts every used view, then hands the tokens to the cart.
   * Fire-and-forget by design: the customer's line is already in the cart, so
   * nothing here may block them, and a failure costs a convenience file.
   */
  function composeAndAttach(lineId) {
    if (!config.preview_endpoint || typeof document.createElement("canvas").toBlob !== "function") {
      return Promise.resolve({});
    }

    // Synchronously, before anything can reset the editor.
    var zoneStates = snapshotZoneStates();
    var views = usedViewsFor(zoneStates);

    if (!views.length) return Promise.resolve({});

    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();

    return ready.then(function () {
      return Promise.all(views.map(function (view) {
        return composeView(view, zoneStates).then(function (blob) {
          if (!blob) return null;

          var body = new FormData();
          body.append("product_id", String(config.product_id));
          body.append("view_key", view.view_key);
          body.append("file", blob, "preview.png");

          return fetch(config.preview_endpoint, { method: "POST", body: body })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
              return data && data.token ? { view_key: view.view_key, token: data.token } : null;
            });
        }).catch(function () { return null; });
      }));
    }).then(function (results) {
      var tokens = {};
      results.forEach(function (result) {
        if (result) tokens[result.view_key] = result.token;
      });

      if (lineId && window.VVLCart && typeof window.VVLCart.attachPreviewTokens === "function") {
        window.VVLCart.attachPreviewTokens(lineId, tokens);
      }

      return tokens;
    }).catch(function () { return {}; });
  }

  /* ---------------------------------------------------------------------
     Start

     Deliberately the LAST thing in this module. restore() reads DRAFT_KEY,
     which is a `var` assigned in the persistence block above: running the
     init any earlier would read it while it is still undefined, and the
     customer's draft would silently never come back.
     --------------------------------------------------------------------- */

  // Before the first paint: a refresh, or a return from the cart to edit a
  // line, must land on the customer's own work rather than an empty editor.
  restore();

  switchView(activeView);
  renderAll();

  /* ---------------------------------------------------------------------
     Public interface — the only thing assets/js/shop/shop.js touches.
     --------------------------------------------------------------------- */

  window.VVLPersonalization = {
    /**
     * The personalization to attach to a cart line, or null when the customer
     * left every zone empty — a personalizable product may also simply be
     * bought plain, and the server treats it the same way.
     *
     * `surcharge_cents` travels for DISPLAY only; api/checkout.php re-reads
     * every surcharge from the product's own zone configuration and ignores
     * whatever is here.
     */
    getState: function () {
      var used = usedZoneKeys();
      if (used.length === 0) return null;

      // Ordered by the product's own configuration, so two identical
      // personalizations serialise identically (which is what lets the cart
      // recognise them as one line) AND the customer reads their zones in the
      // order the administrator arranged them.
      used.sort(function (a, b) { return zoneOrder[a] - zoneOrder[b]; });

      return {
        zones: used.map(function (key) {
          var zoneState = state.zones[key];
          var zone = zonesByKey[key];

          return {
            zone_key: key,
            view_key: viewOfZone[key].view_key,
            label: zone.label || key,
            text: zoneState.text,
            font: zoneState.text ? zoneState.font : null,
            font_label: zoneState.text ? fontLabel(zoneState.font) : null,
            color: zoneState.text ? zoneState.color : null,
            upload_token: zoneState.upload_token,
            upload_name: zoneState.upload_name,
            upload_preview_url: zoneState.upload_preview_url,
            surcharge_cents: zone.surcharge_cents || 0,
            transform: {
              text: cloneTransform(zoneState.transform.text),
              image: cloneTransform(zoneState.transform.image)
            }
          };
        }),
        surcharge_cents: surchargeCents()
      };
    },

    /**
     * A customer-facing reason not to add this to the cart yet, or null when
     * it is fine. Purely a better experience: the server checks all of this
     * again and is the only thing that decides what an order may contain.
     */
    validate: function () {
      var problem = null;

      // An upload in flight means the personalization is not finished being
      // described yet. The button is disabled while this is true; this is the
      // check behind it.
      if (isUploading()) {
        return text("wait_for_upload");
      }

      /* A personalization-REQUIRED product cannot be bought plain. This is
         only the courteous half of the rule — App\Service\Personalization\PersonalizationValidator
         rejects such a line server-side whatever the browser sends — but it
         is the half that tells the customer WHAT is missing instead of just
         refusing. */
      if (config.is_required && usedZoneKeys().length === 0) {
        return text("fill_in_first");
      }

      Object.keys(zonesByKey).some(function (key) {
        var zone = zonesByKey[key];
        var zoneState = state.zones[key];

        if (zoneState.text.length > zone.max_text_length) {
          problem = text("text_too_long", { label: zoneLabel(zone), max: zone.max_text_length });
          return true;
        }

        if (!zone.is_required) return false;

        var hasText = zoneState.text !== "";
        var hasImage = !!zoneState.upload_token;
        var filled = zone.mode === "text" ? hasText : (zone.mode === "image" ? hasImage : (hasText || hasImage));

        if (!filled) {
          problem = text("zone_required", { label: zoneLabel(zone) });
          // Bring the customer to the zone they still have to fill in.
          switchView(viewOfZone[key].view_key);
          return true;
        }

        return false;
      });

      return problem;
    },

    /**
     * The product's own current unit price, so the panel can show what the
     * personalization adds. Called by main.js whenever the selected variant
     * (and therefore the price) changes — this module never resolves a price
     * itself.
     */
    setBasePrice: function (price) {
      basePriceCents = centsFrom(price);
      renderPrice();
    },

    showError: showError,

    /**
     * True while an image is uploading. main.js asks before adding to the
     * cart, so a half-finished upload can never be ordered even if the
     * disabled button were somehow activated.
     */
    isUploading: isUploading,

    /**
     * Composes the picture of every used view and posts it, then hands the
     * tokens to the cart line. Fire-and-forget: the line is already in the
     * cart, and a failed snapshot costs a convenience file, never an order.
     */
    composeAndAttach: composeAndAttach,

    /** Drops this product's saved draft — called after a successful add. */
    clearDraft: clearDraft,

    /** Called by main.js after a successful add, so the next unit starts clean. */
    reset: resetAll
  };

  function cloneTransform(transform) {
    return { x: transform.x, y: transform.y, scale: transform.scale, rotation: transform.rotation };
  }

  function fontLabel(fontKey) {
    var font = fontByKey(fontKey);
    return font ? font.label : null;
  }
})();
