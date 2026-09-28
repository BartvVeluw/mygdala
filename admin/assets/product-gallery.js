/**
 * The product editor's pictures (admin/product-form.php, admin/_product_gallery.php):
 * the product's own pool of pictures, and per variant the subset it shows.
 *
 * NOTHING IS SAVED HERE. Every change — a picture chosen from the Media
 * Library, a move with ← or →, a drag, a removal, a variant's tick — only
 * changes what is on screen and the hidden `gallery[]` / `variant_images[..][]`
 * inputs inside the product form. The form's one Opslaan (or the save bar)
 * sends them, and api/admin/update-product.php checks every token again. No
 * request, no reload, and the page stays where it is.
 *
 * A TOKEN names a picture without trusting the browser with anything else:
 * `image:<id>` is a picture the product already has, `media:<id>` one chosen
 * from the library in this visit. The server resolves both
 * (App\Service\ProductGallery) and ignores what it cannot.
 *
 * WITHOUT JAVASCRIPT the server-rendered inputs are posted unchanged, so a
 * save keeps the pictures exactly as they were.
 *
 * A VARIANT'S PICTURES live in its row in the Varianten section
 * (admin/_product_variants.php), and a row can appear after the page loaded:
 * "Variant toevoegen" (admin/assets/row-list.js, `row-list:added`) brings one,
 * and after a save the editor draws the sections again from the server
 * (admin/assets/admin-editor.js, `admin-editor:replaced`). A new row is taken
 * in as it comes; a redrawn page starts the gallery again from what the
 * server drew, and every listener of the one before it is taken off (one
 * AbortController per start), so nothing ever reacts twice. A removed row is
 * simply no longer on the page, and is forgotten.
 *
 * Keyboard and pointer both work: ← and → are real buttons (the model a
 * drag only adds to), drag and drop is for a mouse, and on a touch screen
 * the arrows are the way. A move keeps focus on the moved picture's button
 * and says the new position in a live region.
 *
 * ALSO THE PORTFOLIO'S PROJECT GALLERY (admin/portfolio-item.php, Portfolio
 * 2.0): a pool with no variants, marked [data-picture-gallery] instead of
 * [data-product-gallery], so one script keeps one behaviour for every list of
 * library pictures an editor orders. Two settings on the root tell the two
 * apart: `data-gallery-first-badge` names the first card (or, empty, names
 * none: a project's main picture stands apart), and
 * `data-gallery-exclude-input` names the form field of a picture that may not
 * also be in the list (the item's main picture), which is then refused like a
 * double. The server applies both rules again (App\Service\PortfolioProjectGallery).
 *
 * AND A MEDIA SEQUENCE'S FURTHER ITEMS (admin/_media_sequence_field.php: the
 * Paginakop's and the Mediabanner's): the same pool, where an item may be a
 * video (data-kind="video", or the picked item's kind). A video has no frame
 * to show, so its card shows the Media Library's video icon instead of a
 * picture.
 */
(function () {
  "use strict";

  /** One gallery on the page: its pool root and everything wired to it. */
  function start(root) {
    var stopping = new AbortController();
    function on(target, type, handler) {
      target.addEventListener(type, handler, { signal: stopping.signal });
    }

    var list = root.querySelector("[data-gallery-list]");
    var emptyNote = root.querySelector("[data-gallery-empty]");
    var status = root.querySelector("[data-gallery-status]");
    var inputName = root.getAttribute("data-gallery-input") || "gallery[]";
    var words = {};
    try {
      words = JSON.parse(root.getAttribute("data-gallery-words") || "{}");
    } catch (e) {
      words = {};
    }

    function word(key, fallback, values) {
      var text = words[key] || fallback;
      Object.keys(values || {}).forEach(function (name) {
        text = text.split(":" + name).join(String(values[name]));
      });
      return text;
    }

    /** The pool: [{token, src, name, kind}], in display order. */
    var pool = Array.prototype.map.call(list.querySelectorAll("[data-gallery-item]"), function (item) {
      return {
        token: item.getAttribute("data-token"),
        src: item.getAttribute("data-src") || "",
        name: item.getAttribute("data-name") || "",
        kind: item.getAttribute("data-kind") === "video" ? "video" : "image"
      };
    });

    /** The same decorative picture media_video_icon() prints (admin/_media_picker.php). */
    function videoIcon() {
      var ns = "http://www.w3.org/2000/svg";
      var wrap = document.createElement("span");
      wrap.className = "admin-media-video-icon";
      wrap.setAttribute("aria-hidden", "true");
      var svg = document.createElementNS(ns, "svg");
      svg.setAttribute("viewBox", "0 0 24 24");
      svg.setAttribute("width", "32");
      svg.setAttribute("height", "32");
      svg.setAttribute("focusable", "false");
      var rect = document.createElementNS(ns, "rect");
      [["x", "2.5"], ["y", "5"], ["width", "19"], ["height", "14"], ["rx", "2.5"], ["fill", "none"], ["stroke", "currentColor"], ["stroke-width", "1.6"]].forEach(function (a) { rect.setAttribute(a[0], a[1]); });
      var path = document.createElementNS(ns, "path");
      path.setAttribute("d", "M10 9.2v5.6l4.8-2.8z");
      path.setAttribute("fill", "currentColor");
      svg.appendChild(rect);
      svg.appendChild(path);
      wrap.appendChild(svg);
      return wrap;
    }

    /** Per variant on the page: {id, label, root, list, tiles, tokens: [...]} */
    var variants = [];

    function announce(text) {
      if (!status) return;
      status.textContent = "";
      window.setTimeout(function () { status.textContent = text; }, 30);
    }

    /** Tells the save bar (and anything else listening) that the form changed. */
    function touched() {
      var marker = root.querySelector("[data-gallery-marker]");
      if (marker) marker.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function byToken(token) {
      for (var i = 0; i < pool.length; i++) {
        if (pool[i].token === token) return pool[i];
      }
      return null;
    }

    /* ------------------------------------------------------------------ */
    /* One sortable strip: the pool, or a variant's own selection.          */
    /* ------------------------------------------------------------------ */

    function button(className, text, label, attrs) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = className;
      b.textContent = text;
      b.setAttribute("aria-label", label);
      Object.keys(attrs || {}).forEach(function (name) { b.setAttribute(name, attrs[name]); });
      return b;
    }

    /**
     * Renders `tokens` into `container` as picture cards with ← → ×, and a
     * hidden input per card named `name`. `firstBadge` labels the first card
     * (Hoofdfoto / Eerste foto).
     */
    function renderStrip(container, tokens, name, firstBadge) {
      container.innerHTML = "";

      tokens.forEach(function (token, index) {
        var picture = byToken(token);
        if (!picture) return;

        var item = document.createElement("li");
        item.className = "admin-gallery__item";
        item.setAttribute("data-gallery-item", "");
        item.setAttribute("data-token", token);
        item.setAttribute("data-kind", picture.kind === "video" ? "video" : "image");
        item.setAttribute("draggable", "true");

        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = token;
        item.appendChild(input);

        var media = document.createElement("span");
        media.className = "admin-gallery__media";
        if (picture.kind === "video") {
          media.appendChild(videoIcon());
        } else {
          var img = document.createElement("img");
          img.src = picture.src;
          img.alt = "";
          img.loading = "lazy";
          img.draggable = false;
          media.appendChild(img);
        }
        item.appendChild(media);

        var position = document.createElement("span");
        position.className = "admin-gallery__position";
        position.textContent = String(index + 1);
        position.setAttribute("aria-hidden", "true");
        item.appendChild(position);

        if (index === 0 && firstBadge) {
          var badge = document.createElement("span");
          badge.className = "admin-gallery__badge";
          badge.textContent = firstBadge;
          item.appendChild(badge);
        }

        var caption = document.createElement("span");
        caption.className = "admin-gallery__name";
        caption.textContent = picture.name;
        item.appendChild(caption);

        var actions = document.createElement("span");
        actions.className = "admin-gallery__actions";
        var left = button("admin-gallery__btn", "\u2190", word("left", ":name naar links", { name: picture.name }), { "data-gallery-move": "-1" });
        var right = button("admin-gallery__btn", "\u2192", word("right", ":name naar rechts", { name: picture.name }), { "data-gallery-move": "1" });
        var remove = button("admin-gallery__btn admin-gallery__btn--remove", "\u00d7", word("remove", ":name verwijderen", { name: picture.name }), { "data-gallery-remove": "" });
        left.disabled = index === 0;
        right.disabled = index === tokens.length - 1;
        actions.appendChild(left);
        actions.appendChild(right);
        actions.appendChild(remove);
        item.appendChild(actions);

        container.appendChild(item);
      });
    }

    /**
     * Wires ← → × and drag-and-drop on a strip. `getTokens`/`setTokens` read
     * and replace the strip's order; `onRemove` handles ×.
     */
    function wireStrip(container, getTokens, setTokens, onRemove, render) {
      on(container, "click", function (event) {
        var target = event.target.closest ? event.target.closest("button") : null;
        if (!target || !container.contains(target)) return;

        var item = target.closest("[data-gallery-item]");
        var token = item ? item.getAttribute("data-token") : null;
        if (!token) return;

        var tokens = getTokens().slice();
        var index = tokens.indexOf(token);

        if (target.hasAttribute("data-gallery-move")) {
          var step = parseInt(target.getAttribute("data-gallery-move"), 10);
          var to = index + step;
          if (index < 0 || to < 0 || to >= tokens.length) return;

          tokens.splice(index, 1);
          tokens.splice(to, 0, token);
          setTokens(tokens);
          render();
          touched();

          // Keep the keyboard where it was: on the same arrow of the moved
          // picture, or on the other one when it has reached an end.
          var moved = container.querySelector('[data-token="' + token + '"]');
          if (moved) {
            var same = moved.querySelector('[data-gallery-move="' + step + '"]');
            var other = moved.querySelector('[data-gallery-move="' + (-step) + '"]');
            (same && !same.disabled ? same : other).focus();
          }
          announce(word("moved", ":name: positie :position van :total", {
            name: (byToken(token) || {}).name || "",
            position: to + 1,
            total: tokens.length
          }));
          return;
        }

        if (target.hasAttribute("data-gallery-remove")) {
          var name = (byToken(token) || {}).name || "";
          onRemove(token);
          touched();
          announce(word("removed", ":name verwijderd", { name: name }));

          var next = container.querySelectorAll("[data-gallery-item]")[Math.min(index, container.querySelectorAll("[data-gallery-item]").length - 1)];
          var focusTarget = next ? next.querySelector("[data-gallery-remove]") : root.querySelector("[data-media-picker-open]");
          if (focusTarget) focusTarget.focus();
        }
      });

      // Drag and drop, for a mouse. The arrows above are the same move for
      // everybody else; both end in setTokens().
      var dragged = null;

      on(container, "dragstart", function (event) {
        var item = event.target.closest ? event.target.closest("[data-gallery-item]") : null;
        if (!item) return;
        dragged = item.getAttribute("data-token");
        item.classList.add("is-dragging");
        if (event.dataTransfer) {
          event.dataTransfer.effectAllowed = "move";
          event.dataTransfer.setData("text/plain", dragged);
        }
      });

      on(container, "dragover", function (event) {
        if (dragged === null) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = "move";

        var over = event.target.closest ? event.target.closest("[data-gallery-item]") : null;
        var draggedEl = container.querySelector(".is-dragging");
        if (!over || !draggedEl || over === draggedEl) return;

        var box = over.getBoundingClientRect();
        var after = event.clientX > box.left + box.width / 2;
        container.insertBefore(draggedEl, after ? over.nextSibling : over);
      });

      on(container, "drop", function (event) {
        if (dragged !== null) event.preventDefault();
      });

      on(container, "dragend", function () {
        if (dragged === null) return;
        var token = dragged;
        dragged = null;

        var order = Array.prototype.map.call(container.querySelectorAll("[data-gallery-item]"), function (item) {
          return item.getAttribute("data-token");
        });
        var changed = order.join("|") !== getTokens().join("|");
        setTokens(order);
        render();

        if (changed) {
          touched();
          announce(word("moved", ":name: positie :position van :total", {
            name: (byToken(token) || {}).name || "",
            position: order.indexOf(token) + 1,
            total: order.length
          }));
        }
      });
    }

    /* ------------------------------------------------------------------ */
    /* The pool                                                           */
    /* ------------------------------------------------------------------ */

    // The label of the first card: the root's own when it names one (an empty
    // one means none), else the product's "Hoofdfoto".
    var firstBadge = root.hasAttribute("data-gallery-first-badge")
      ? root.getAttribute("data-gallery-first-badge")
      : word("primary", "Hoofdfoto");

    // A picture that may not also be in this list: the form field that holds
    // it, when the root names one.
    var excludeName = root.getAttribute("data-gallery-exclude-input");
    function excludedMediaId() {
      if (!excludeName) return "";
      var form = root.closest("form");
      var field = form ? form.querySelector('input[name="' + excludeName + '"]') : null;
      return field ? String(field.value || "") : "";
    }

    function renderPool() {
      renderStrip(list, pool.map(function (p) { return p.token; }), inputName, firstBadge);
      if (emptyNote) emptyNote.hidden = pool.length > 0;
      liveVariants().forEach(renderVariant);

      // The product editor's section line says how many pictures there are.
      var count = root.hasAttribute("data-product-gallery") ? document.querySelector("[data-product-gallery-count]") : null;
      if (count) count.textContent = String(pool.length);
    }

    /** The variants still on the page: a removed row is forgotten here. */
    function liveVariants() {
      variants = variants.filter(function (variant) { return document.contains(variant.root); });
      return variants;
    }

    wireStrip(
      list,
      function () { return pool.map(function (p) { return p.token; }); },
      function (tokens) {
        pool = tokens.map(byToken).filter(Boolean);
      },
      function (token) {
        pool = pool.filter(function (p) { return p.token !== token; });
        // A picture that is no longer the product's cannot stay on a variant.
        liveVariants().forEach(function (variant) {
          variant.tokens = variant.tokens.filter(function (t) { return t !== token; });
        });
        renderPool();
      },
      renderPool
    );

    /**
     * A picture chosen or uploaded in the Media Library modal
     * (admin/assets/media-picker.js, a field with data-media-picker-collect).
     * A picture that is already in the pool is not added twice.
     */
    on(root, "media-picker:choose", function (event) {
      var item = event.detail || {};
      if (!item.id) return;

      var token = "media:" + item.id;
      var known = pool.some(function (p) {
        return p.token === token || (p.mediaId && String(p.mediaId) === String(item.id));
      }) || excludedMediaId() === String(item.id);
      if (known) {
        announce(word("duplicate", ":name staat al bij dit product", { name: item.name || "" }));
        return;
      }

      pool.push({ token: token, src: item.thumbnail || "", name: item.name || "", mediaId: item.id, kind: item.kind === "video" ? "video" : "image" });
      renderPool();
      touched();
      announce(word("added", ":name toegevoegd", { name: item.name || "" }));
    });

    // The media id behind an `image:` token, so a library item that is already
    // one of the product's pictures is recognised when it is chosen again.
    Array.prototype.forEach.call(list.querySelectorAll("[data-gallery-item]"), function (item) {
      var picture = byToken(item.getAttribute("data-token"));
      if (picture && item.getAttribute("data-media-id")) picture.mediaId = item.getAttribute("data-media-id");
    });

    /* ------------------------------------------------------------------ */
    /* Variants: a subset of the pool, in the variant's own order           */
    /* ------------------------------------------------------------------ */

    function renderVariant(variant) {
      variant.tokens = variant.tokens.filter(function (t) { return byToken(t) !== null; });

      renderStrip(variant.list, variant.tokens, "variant_images[" + variant.id + "][]", word("variantFirst", "Eerste foto"));
      if (variant.empty) variant.empty.hidden = variant.tokens.length > 0;

      if (!variant.tiles) return;
      variant.tiles.innerHTML = "";
      pool.forEach(function (picture) {
        var chosen = variant.tokens.indexOf(picture.token) !== -1;
        var tile = document.createElement("button");
        tile.type = "button";
        tile.className = "admin-gallery-tile" + (chosen ? " is-chosen" : "");
        tile.setAttribute("aria-pressed", chosen ? "true" : "false");
        tile.setAttribute("data-token", picture.token);
        tile.setAttribute("aria-label", word("tile", ":name bij :variant", { name: picture.name, variant: variant.label }));

        var img = document.createElement("img");
        img.src = picture.src;
        img.alt = "";
        img.loading = "lazy";
        tile.appendChild(img);

        var mark = document.createElement("span");
        mark.className = "admin-gallery-tile__mark";
        mark.setAttribute("aria-hidden", "true");
        mark.textContent = chosen ? "\u2713" : "";
        tile.appendChild(mark);

        variant.tiles.appendChild(tile);
      });
    }

    /** Takes in one variant's block: on load, or when its row is added. */
    function addVariant(block) {
      if (variants.some(function (known) { return known.root === block; })) return;

      var variant = {
        id: block.getAttribute("data-variant-id"),
        label: block.getAttribute("data-variant-label") || "",
        root: block,
        list: block.querySelector("[data-variant-gallery-list]"),
        tiles: block.querySelector("[data-variant-gallery-tiles]"),
        empty: block.querySelector("[data-variant-gallery-empty]"),
        tokens: Array.prototype.map.call(block.querySelectorAll("[data-variant-gallery-list] [data-gallery-item]"), function (item) {
          return item.getAttribute("data-token");
        })
      };
      if (!variant.list) return;
      variants.push(variant);

      wireStrip(
        variant.list,
        function () { return variant.tokens; },
        function (tokens) { variant.tokens = tokens; },
        function (token) {
          variant.tokens = variant.tokens.filter(function (t) { return t !== token; });
          renderVariant(variant);
        },
        function () { renderVariant(variant); }
      );

      if (variant.tiles) {
        on(variant.tiles, "click", function (event) {
          var tile = event.target.closest ? event.target.closest("[data-token]") : null;
          if (!tile || !variant.tiles.contains(tile)) return;

          var token = tile.getAttribute("data-token");
          var at = variant.tokens.indexOf(token);
          if (at === -1) {
            variant.tokens.push(token);
          } else {
            variant.tokens.splice(at, 1);
          }
          renderVariant(variant);
          touched();

          var again = variant.tiles.querySelector('[data-token="' + token + '"]');
          if (again) again.focus();
        });
      }

      renderVariant(variant);
    }

    Array.prototype.forEach.call(document.querySelectorAll("[data-variant-gallery]"), addVariant);

    renderPool();

    return {
      root: root,
      addVariant: addVariant,
      stop: function () { stopping.abort(); }
    };
  }

  /* ------------------------------------------------------------------ */
  /* "Eigen beschrijving voor deze variant"                              */
  /* ------------------------------------------------------------------ */

  // Off: the variant shows the product's description, and the editor is out
  // of the way. On: the variant's own text. The server stores nothing for a
  // variant that is off, so the product's text keeps reaching it. Delegated,
  // so a variant row added on the screen works the same.
  document.addEventListener("change", function (event) {
    var toggle = event.target && event.target.closest ? event.target.closest("[data-variant-description-toggle]") : null;
    var block = toggle ? toggle.closest("[data-variant-description]") : null;
    if (!block) return;

    var editor = block.querySelector("[data-variant-description-editor]");
    var inherited = block.querySelector("[data-variant-description-inherited]");
    if (editor) editor.hidden = !toggle.checked;
    if (inherited) inherited.hidden = toggle.checked;
  });

  /* ------------------------------------------------------------------ */
  /* Starting, and starting again                                        */
  /* ------------------------------------------------------------------ */

  var current = null;

  function boot() {
    var root = document.querySelector("[data-product-gallery], [data-picture-gallery]");
    if (current) current.stop();
    current = root ? start(root) : null;
  }

  boot();

  // A variant row "Variant toevoegen" just put on the screen.
  document.addEventListener("row-list:added", function (event) {
    if (!current || !event.target || !event.target.querySelectorAll) return;
    Array.prototype.forEach.call(event.target.querySelectorAll("[data-variant-gallery]"), current.addVariant);
  });

  // The editor drew a section again after a save: start from what is there.
  document.addEventListener("admin-editor:replaced", boot);
})();
