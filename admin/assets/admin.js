/**
 * Drag-and-drop reordering for small thumbnail photo grids — variant photos
 * (admin/product-form.php, [data-variant-image-grid], POST field
 * "variant_id") and Portfolio-item "Projectafbeeldingen"
 * (admin/portfolio-item.php, [data-portfolio-image-grid], POST field
 * "portfolio_item_id"). Plain HTML5 drag/drop — no library. On drop,
 * persists the new order server-side via fetch(), then reloads the page so
 * every other bit of server-rendered state (e.g. the "Standaard" badge on
 * the first image) stays authoritative. Every other admin interaction stays
 * a plain form POST, except the rich-text description editor further below.
 */
(function () {
  "use strict";

  function initDragReorderGrids(gridSelector, cardSelector, idField) {
    var grids = document.querySelectorAll(gridSelector);

    grids.forEach(function (grid) {
      var dragged = null;

      grid.querySelectorAll(cardSelector).forEach(function (card) {
        card.addEventListener("dragstart", function () {
          dragged = card;
          card.classList.add("is-dragging");
        });

        card.addEventListener("dragend", function () {
          card.classList.remove("is-dragging");
          dragged = null;
        });

        card.addEventListener("dragover", function (event) {
          event.preventDefault();
          if (!dragged || dragged === card) return;

          var rect = card.getBoundingClientRect();
          var isAfter = event.clientX - rect.left > rect.width / 2;
          grid.insertBefore(dragged, isAfter ? card.nextSibling : card);
        });

        card.addEventListener("drop", function (event) {
          event.preventDefault();
          persistOrder(grid, cardSelector, idField);
        });
      });
    });
  }

  function persistOrder(grid, cardSelector, idField) {
    var entityId = grid.getAttribute("data-entity-id");
    var url = grid.getAttribute("data-reorder-url");
    var csrfToken = grid.getAttribute("data-csrf-token");
    var imageIds = Array.prototype.map
      .call(grid.querySelectorAll(cardSelector), function (card) {
        return card.getAttribute("data-image-id");
      })
      .join(",");

    var body = new URLSearchParams();
    body.set("csrf_token", csrfToken);
    body.set(idField, entityId);
    body.set("image_ids", imageIds);

    fetch(url, { method: "POST", credentials: "same-origin", body: body })
      .then(function () { window.location.reload(); })
      .catch(function () { window.location.reload(); });
  }

  function initVariantImageGrids() {
    initDragReorderGrids("[data-variant-image-grid]", ".admin-variant-image-card", "variant_id");
    initDragReorderGrids("[data-portfolio-image-grid]", ".admin-portfolio-image-card", "portfolio_item_id");
  }

  /**
   * Page builder (admin/page.php, [data-page-section-zone]):
   * drag-and-drop reordering of the one ordered block list of a page.
   * Unlike initDragReorderGrids() above, a row also contains Edit/Hide/
   * Delete buttons that must stay normally clickable — so only the small
   * "&#8801;" handle (`.admin-drag-handle`) is draggable, never the row
   * itself, and dragging is initiated from the handle but moves its
   * containing `.admin-page-section-row`. On drop, persists the new order
   * via fetch() to reorder-page-sections.php (JSON response, same
   * conventions as reorder-portfolio-items.php); on any failure, reloads so
   * the visible order never silently drifts from what's actually saved.
   */
  function initPageSectionZones() {
    document.querySelectorAll("[data-page-section-zone]").forEach(function (zone) {
      var dragged = null;

      zone.querySelectorAll(".admin-drag-handle").forEach(function (handle) {
        var row = handle.closest(".admin-page-section-row");
        if (!row) return;

        handle.addEventListener("dragstart", function () {
          dragged = row;
          row.classList.add("is-dragging");
        });

        handle.addEventListener("dragend", function () {
          row.classList.remove("is-dragging");
          dragged = null;
        });
      });

      zone.querySelectorAll(".admin-page-section-row").forEach(function (row) {
        row.addEventListener("dragover", function (event) {
          event.preventDefault();
          if (!dragged || dragged === row) return;

          var rect = row.getBoundingClientRect();
          var isAfter = event.clientY - rect.top > rect.height / 2;
          zone.insertBefore(dragged, isAfter ? row.nextSibling : row);
        });

        row.addEventListener("drop", function (event) {
          event.preventDefault();
          persistPageSectionOrder(zone);
        });
      });
    });
  }

  function persistPageSectionOrder(zone) {
    var url = zone.getAttribute("data-reorder-url");
    var csrfToken = zone.getAttribute("data-csrf-token");
    var pageId = zone.getAttribute("data-page-id");
    var sectionIds = Array.prototype.map
      .call(zone.querySelectorAll(".admin-page-section-row"), function (row) {
        return row.getAttribute("data-page-section-id");
      })
      .join(",");

    var body = new URLSearchParams();
    body.set("csrf_token", csrfToken);
    body.set("page_id", pageId);
    body.set("section_ids", sectionIds);

    fetch(url, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
      .then(function (data) { if (!data || !data.ok) window.location.reload(); })
      .catch(function () { window.location.reload(); });
  }

  /**
   * Global Navigation admin (admin/navigation.php, [data-nav-zone]): drag
   * reordering of nav_items. Same handle-only-draggable pattern as
   * initPageSectionZones() above, generalized to run once per zone —
   * there is one zone for the top-level items and one more per parent item
   * that currently has children, each independently draggable and each
   * persisted to its own parent_id scope (reorder-nav-items.php never lets
   * a drop move an item into a different zone's parent — the drop handler
   * only ever reorders rows already inside the same zone element).
   */
  function initNavItemZones() {
    document.querySelectorAll("[data-nav-zone]").forEach(function (zone) {
      var dragged = null;

      zone.querySelectorAll(".admin-drag-handle").forEach(function (handle) {
        var row = handle.closest(".admin-nav-item-row");
        if (!row) return;

        handle.addEventListener("dragstart", function () {
          dragged = row;
          row.classList.add("is-dragging");
        });
        handle.addEventListener("dragend", function () {
          row.classList.remove("is-dragging");
          dragged = null;
        });
      });

      zone.querySelectorAll(":scope > .admin-nav-item-row").forEach(function (row) {
        row.addEventListener("dragover", function (event) {
          event.preventDefault();
          if (!dragged || dragged === row || dragged.parentNode !== zone) return;

          var rect = row.getBoundingClientRect();
          var isAfter = event.clientY - rect.top > rect.height / 2;
          zone.insertBefore(dragged, isAfter ? row.nextSibling : row);
        });

        row.addEventListener("drop", function (event) {
          event.preventDefault();
          persistNavItemOrder(zone);
        });
      });
    });
  }

  function persistNavItemOrder(zone) {
    var url = zone.getAttribute("data-reorder-url");
    var csrfToken = zone.getAttribute("data-csrf-token");
    var parentId = zone.getAttribute("data-parent-id") || "";
    var itemIds = Array.prototype.map
      .call(zone.querySelectorAll(":scope > .admin-nav-item-row"), function (row) {
        return row.getAttribute("data-nav-item-id");
      })
      .join(",");

    var body = new URLSearchParams();
    body.set("csrf_token", csrfToken);
    body.set("parent_id", parentId);
    body.set("item_ids", itemIds);

    fetch(url, { method: "POST", credentials: "same-origin", body: body })
      .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
      .then(function (data) { if (!data || !data.ok) window.location.reload(); })
      .catch(function () { window.location.reload(); });
  }

  /**
   * Footer admin (admin/footer.php, [data-footer-column-zone] +
   * [data-footer-link-zone]): drag reordering of footer_columns (one zone,
   * top-level) and footer_links (one zone per column) — same pattern as
   * initNavItemZones() above.
   */
  function initFooterZones() {
    document.querySelectorAll("[data-footer-column-zone]").forEach(function (zone) {
      initFooterDragZone(zone, ".admin-footer-column-row", "data-footer-column-id", function () {
        var url = zone.getAttribute("data-reorder-url");
        var csrfToken = zone.getAttribute("data-csrf-token");
        var columnIds = Array.prototype.map
          .call(zone.querySelectorAll(":scope > .admin-footer-column-row"), function (row) {
            return row.getAttribute("data-footer-column-id");
          })
          .join(",");

        var body = new URLSearchParams();
        body.set("csrf_token", csrfToken);
        body.set("column_ids", columnIds);
        fetch(url, { method: "POST", credentials: "same-origin", body: body })
          .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
          .then(function (data) { if (!data || !data.ok) window.location.reload(); })
          .catch(function () { window.location.reload(); });
      });
    });

    document.querySelectorAll("[data-footer-link-zone]").forEach(function (zone) {
      initFooterDragZone(zone, ".admin-footer-link-row", "data-footer-link-id", function () {
        var url = zone.getAttribute("data-reorder-url");
        var csrfToken = zone.getAttribute("data-csrf-token");
        var columnId = zone.getAttribute("data-column-id");
        var linkIds = Array.prototype.map
          .call(zone.querySelectorAll(":scope > .admin-footer-link-row"), function (row) {
            return row.getAttribute("data-footer-link-id");
          })
          .join(",");

        var body = new URLSearchParams();
        body.set("csrf_token", csrfToken);
        body.set("column_id", columnId);
        body.set("link_ids", linkIds);
        fetch(url, { method: "POST", credentials: "same-origin", body: body })
          .then(function (response) { return response.json().catch(function () { return { ok: false }; }); })
          .then(function (data) { if (!data || !data.ok) window.location.reload(); })
          .catch(function () { window.location.reload(); });
      });
    });
  }

  function initFooterDragZone(zone, rowSelector, idAttr, persist) {
    var dragged = null;

    zone.querySelectorAll(".admin-drag-handle").forEach(function (handle) {
      var row = handle.closest(rowSelector);
      if (!row) return;

      handle.addEventListener("dragstart", function () {
        dragged = row;
        row.classList.add("is-dragging");
      });
      handle.addEventListener("dragend", function () {
        row.classList.remove("is-dragging");
        dragged = null;
      });
    });

    zone.querySelectorAll(":scope > " + rowSelector).forEach(function (row) {
      row.addEventListener("dragover", function (event) {
        event.preventDefault();
        if (!dragged || dragged === row || dragged.parentNode !== zone) return;

        var rect = row.getBoundingClientRect();
        var isAfter = event.clientY - rect.top > rect.height / 2;
        zone.insertBefore(dragged, isAfter ? row.nextSibling : row);
      });

      row.addEventListener("drop", function (event) {
        event.preventDefault();
        persist();
      });
    });
  }

  /**
   * Rich-text editor for admin long-form fields (product descriptions,
   * Portfolio Introtekst/Projectbeschrijving — admin/_richtext_field.php,
   * [data-richtext-field]), built on Quill (self-hosted, see
   * admin/assets/vendor/quill/ — loaded by the pages that need it, before
   * this script) instead of the deprecated contenteditable +
   * document.execCommand approach this used to use.
   *
   * Progressive enhancement: _richtext_field.php always renders a real,
   * visible <textarea data-richtext-source> — the field the form actually
   * submits. This function only runs when Quill loaded successfully; it
   * then hides that textarea and mounts a Quill editor in its place,
   * keeping the textarea's value in sync on every edit (and once more on
   * submit, as a final flush). If Quill's script fails to load for any
   * reason, this whole function no-ops and the plain textarea remains the
   * fully working field — editing is never blocked on this script.
   *
   * Nothing here is the security boundary — RichTextSanitizer /
   * DescriptionSanitizer on the server is (see
   * api/admin/update-portfolio-item.php and api/admin/_product_validation.php);
   * the `formats` restriction below only shapes what a well-behaved editor
   * produces and normalizes pasted content to, same spirit as the old
   * toolbar's limited command set.
   */
  var RICHTEXT_TOOLBAR_PRESETS = {
    simple: ["bold", "italic", "link", "undo", "redo"],
    full: ["header2", "header3", "paragraph", "bold", "italic", "listBullet", "listOrdered", "link", "undo", "redo"],
  };

  var RICHTEXT_FORMATS = {
    simple: ["bold", "italic", "link"],
    full: ["header", "bold", "italic", "list", "link"],
  };

  var RICHTEXT_BUTTONS = {
    header2: { cls: "ql-header", value: "2", title: "Kop 2", html: "H2" },
    header3: { cls: "ql-header", value: "3", title: "Kop 3", html: "H3" },
    paragraph: { cls: "ql-header", value: "", title: "Paragraaf", html: "&para;" },
    bold: { cls: "ql-bold", title: "Vet", html: "<strong>B</strong>" },
    italic: { cls: "ql-italic", title: "Cursief", html: "<em>I</em>" },
    listBullet: { cls: "ql-list", value: "bullet", title: "Opsomming", html: "&bull; Lijst" },
    listOrdered: { cls: "ql-list", value: "ordered", title: "Genummerde lijst", html: "1. Lijst" },
    link: { cls: "ql-link", title: "Link invoegen/verwijderen", html: "Link" },
    undo: { cls: "ql-undo", title: "Ongedaan maken", html: "&#8630;" },
    redo: { cls: "ql-redo", title: "Opnieuw", html: "&#8631;" },
  };

  function buildRichTextToolbar(preset) {
    var keys = RICHTEXT_TOOLBAR_PRESETS[preset] || RICHTEXT_TOOLBAR_PRESETS.simple;
    var toolbar = document.createElement("div");
    toolbar.className = "admin-richtext-toolbar";

    keys.forEach(function (key) {
      var spec = RICHTEXT_BUTTONS[key];
      if (!spec) return;

      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = spec.cls;
      if (spec.value !== undefined) btn.setAttribute("value", spec.value);
      btn.title = spec.title;
      btn.innerHTML = spec.html;
      toolbar.appendChild(btn);
    });

    return toolbar;
  }

  function initRichTextEditors() {
    var fields = document.querySelectorAll("[data-richtext-field]");
    if (!fields.length) return;

    if (typeof Quill === "undefined") {
      // Quill's script didn't load (network hiccup, blocked, etc.) — every
      // field's plain <textarea data-richtext-source> is already visible
      // and fully usable as-is, so there is nothing to fix here.
      return;
    }

    fields.forEach(function (field) {
      var textarea = field.querySelector("[data-richtext-source]");
      if (!textarea) return;

      var preset = field.getAttribute("data-richtext-toolbar") || "simple";
      var sizeClass = field.getAttribute("data-richtext-size") || "";

      var toolbarEl = buildRichTextToolbar(preset);
      var editorEl = document.createElement("div");
      editorEl.className = "admin-richtext-editor" + (sizeClass ? " " + sizeClass : "");

      field.insertBefore(toolbarEl, textarea);
      field.insertBefore(editorEl, textarea);
      textarea.hidden = true;

      var quill = new Quill(editorEl, {
        theme: "snow",
        formats: RICHTEXT_FORMATS[preset] || RICHTEXT_FORMATS.simple,
        modules: {
          toolbar: {
            container: toolbarEl,
            handlers: {
              // Custom link handler (plain prompt(), same UX as the old
              // editor) instead of Quill's default theme-tooltip UI — one
              // less moving part to get right across desktop/mobile.
              // `value` is the boolean Quill computes from current
              // selection state: true = apply/open a link, false = remove
              // one (clicking the same button again on already-linked text).
              link: function (value) {
                if (!value) {
                  this.quill.format("link", false, "user");
                  return;
                }

                var range = this.quill.getSelection(true);
                if (!range) return;

                var existing = this.quill.getFormat(range).link;
                var url = window.prompt("Link-URL (bv. https://example.com):", existing || "https://");
                if (url) {
                  this.quill.format("link", url, "user");
                }
              },
              undo: function () {
                this.quill.history.undo();
              },
              redo: function () {
                this.quill.history.redo();
              },
            },
          },
          history: { userOnly: true },
        },
      });

      if (textarea.value) {
        quill.clipboard.dangerouslyPasteHTML(textarea.value);
        quill.history.clear();
      }

      function sync() {
        textarea.value = quill.getText().trim() === "" ? "" : quill.root.innerHTML;
      }

      quill.on("text-change", function (delta, oldDelta, source) {
        sync();

        // Let the rest of the admin see a rich-text edit as an ordinary field
        // change — the save bar (admin/assets/save-bar.js) listens for this
        // to know the form is dirty. Only for `source === "user"`: Quill
        // rewrites the field once with its own normalised HTML while it
        // loads, and reporting THAT as an edit would mark every freshly
        // opened editor as having unsaved changes.
        if (source === "user") {
          textarea.dispatchEvent(new Event("input", { bubbles: true }));
        }
      });

      var form = field.closest("form");
      if (form) {
        form.addEventListener("submit", sync);
      }
    });
  }

  /**
   * Keeps a colour <input type="color"> and its editable hex text field
   * ([data-color-sync-form] wrapping [data-color-picker] + [data-color-hex])
   * in sync in both directions, for a "Color" display-type option's values
   * (admin/product-form.php). Server-side hex validation in
   * api/admin/create-product-option-value.php /
   * update-product-option-value.php is the real enforcement point — this is
   * only for a pleasant editing experience.
   */
  function initColorSync() {
    document.querySelectorAll("[data-color-sync-form]").forEach(function (form) {
      var picker = form.querySelector("[data-color-picker]");
      var hexInput = form.querySelector("[data-color-hex]");
      if (!picker || !hexInput) return;

      picker.addEventListener("input", function () {
        hexInput.value = picker.value.toUpperCase();
      });

      hexInput.addEventListener("input", function () {
        if (/^#[0-9A-Fa-f]{6}$/.test(hexInput.value)) {
          picker.value = hexInput.value;
        }
      });
    });
  }

  /**
   * Keeps the percentage readout next to a range slider in sync while it is
   * dragged ([data-range-field] wrapping [data-range-input] +
   * [data-range-output]) — currently the Homepage Hero's "Highlight
   * grootte". Purely a display convenience, same convention as
   * initColorSync(): the <output> is already rendered server-side with the
   * saved value (so the control reads correctly without JavaScript), and
   * api/admin/update-homepage-hero.php is the real validation point.
   */
  function initRangeOutputs() {
    document.querySelectorAll("[data-range-field]").forEach(function (field) {
      var input = field.querySelector("[data-range-input]");
      var output = field.querySelector("[data-range-output]");
      if (!input || !output) return;

      input.addEventListener("input", function () {
        output.textContent = input.value + "%";
      });
    });
  }

  /**
   * Homepage Hero admin (admin/homepage-hero.php): shows only the Image or
   * only the Video card, matching the currently-selected "Media" radio —
   * hiding the irrelevant upload controls instead of showing both at once.
   * Purely a display convenience: the server still renders both cards (with
   * `hidden` on whichever doesn't match the saved media_type, so this works
   * the same on first load without JS), and each card's own form still
   * submits/validates independently of this toggle.
   */
  function initHomepageHeroMediaToggle() {
    var group = document.querySelector("[data-media-type-group]");
    if (!group) return;

    var radios = group.querySelectorAll('input[name="media_type"]');
    var panels = document.querySelectorAll("[data-media-panel]");

    function sync() {
      var selected = group.querySelector('input[name="media_type"]:checked');
      if (!selected) return;

      panels.forEach(function (panel) {
        panel.hidden = panel.getAttribute("data-media-panel") !== selected.value;
      });
    }

    radios.forEach(function (radio) {
      radio.addEventListener("change", sync);
    });
  }

  /**
   * Portfolio-item admin (admin/portfolio-item.php): shows/hides the
   * detail-page-only fields (slug, intro, description, Projectafbeeldingen)
   * to match the live state of "Projectpagina inschakelen", without a
   * reload. Same convention as initHomepageHeroMediaToggle(): the server
   * still renders every panel (with `hidden` matching the saved
   * has_detail_page value), so this works the same on first load without
   * JS, and nothing here is the validation boundary — that's still
   * api/admin/update-portfolio-item.php.
   */
  function initPortfolioDetailToggle() {
    var checkbox = document.querySelector("[data-detail-toggle]");
    if (!checkbox) return;

    var panels = document.querySelectorAll("[data-detail-panel]");

    function sync() {
      panels.forEach(function (panel) {
        panel.hidden = !checkbox.checked;
      });
    }

    checkbox.addEventListener("change", sync);
  }

  /**
   * Generic "auto-fill a slug field from a title field, until the admin
   * edits the slug themselves" helper — currently used by
   * admin/information-page.php's "+ Nieuwe pagina" form
   * ([data-slug-source] on the title input, [data-slug-target] on the slug
   * input). Purely a convenience: the server (see
   * api/admin/create-information-page.php) always re-derives/validates the
   * slug itself when the field is left empty, and never trusts this preview.
   *
   * A slug field that already has a value when the page loads (editing an
   * existing page) is treated as already "touched", so retyping the title
   * on an edit screen can never silently overwrite a saved slug — only a
   * genuinely empty slug field (the "+ Nieuwe pagina" form) auto-fills.
   */
  function slugify(value) {
    var ascii = value.normalize ? value.normalize("NFD").replace(/[\u0300-\u036f]/g, "") : value;

    return ascii
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 170);
  }

  /**
   * Live character counter for the SEO fields (admin/product-form.php,
   * admin/collection.php): every <input>/<textarea> carrying
   * data-char-count gets a "<used> / <maxlength>" readout under it.
   *
   * Purely informational. It never truncates, never blocks a save and never
   * rewrites what the administrator typed — the field's own maxlength (which
   * mirrors the database column, see App\Service\Seo) is the only limit, and
   * the server re-checks it anyway. There is deliberately no "ideal length"
   * warning: Google truncates a snippet by pixel width, not by a character
   * count, so a hard number here would be advice dressed up as a rule.
   */
  function initSeoCharCounters() {
    document.querySelectorAll("[data-char-count]").forEach(function (field) {
      var max = parseInt(field.getAttribute("maxlength") || "0", 10);
      if (!max) return;

      var output = document.createElement("span");
      output.className = "admin-char-count";

      function render() {
        output.textContent = field.value.length + " / " + max;
      }

      field.addEventListener("input", render);
      render();

      field.insertAdjacentElement("afterend", output);
    });
  }

  function initSlugAutoFill() {
    document.querySelectorAll("[data-slug-target]").forEach(function (target) {
      var source = document.querySelector("[data-slug-source]");
      if (!source) return;

      var touched = target.value.trim() !== "";

      target.addEventListener("input", function () {
        touched = true;
      });

      source.addEventListener("input", function () {
        if (touched) return;
        target.value = slugify(source.value);
      });
    });
  }

  /**
   * Portfolio-item "Projectafbeeldingen toevoegen"
   * (admin/portfolio-item.php, [data-portfolio-add-images-form]).
   *
   * Progressive enhancement over the plain <input type="file" multiple>
   * form: without this script, selecting several photos still submits them
   * all in one multipart POST, same as before. With it, each selected file
   * is instead uploaded in its own sequential request (one at a time) to
   * api/admin/add-portfolio-item-images.php with `ajax=1`, so the combined
   * request size never scales with the number of photos chosen.
   *
   * Fixes the bug where selecting several large photos at once produced one
   * multipart POST big enough to exceed PHP's post_max_size — see MAIN.MD,
   * "Portfolio: upload van meerdere projectafbeeldingen faalt bij een grote
   * gecombineerde POST". The CSRF token is reused across requests (it's one
   * per admin session, not single-use — see src/Service/Csrf.php).
   *
   * Any per-file errors are stashed in sessionStorage and rendered once,
   * just above this form, after the page reload that follows the batch —
   * simplest way to surface them given the endpoint's normal (non-ajax)
   * success/error path is a full-page redirect with a session-flash
   * message, which a background fetch() never navigates to.
   */
  var PORTFOLIO_IMAGE_ERRORS_KEY = "vvl-portfolio-item-image-errors";

  function initPortfolioItemImageUpload() {
    var form = document.querySelector("[data-portfolio-add-images-form]");
    if (!form || !window.fetch || !window.FormData) return;

    var fileInput = form.querySelector('input[type="file"]');
    var submitBtn = form.querySelector('button[type="submit"]');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var itemIdInput = form.querySelector('input[name="portfolio_item_id"]');
    if (!fileInput || !submitBtn || !csrfInput || !itemIdInput) return;

    var statusEl = document.createElement("p");
    statusEl.className = "admin-text-muted";
    statusEl.hidden = true;
    form.appendChild(statusEl);

    form.addEventListener("submit", function (event) {
      var files = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
      if (files.length === 0) return; // nothing selected — let the server show its usual message

      event.preventDefault();

      submitBtn.disabled = true;
      fileInput.disabled = true;
      statusEl.hidden = false;

      var errors = [];

      function uploadNext(index) {
        if (index >= files.length) {
          finish();
          return;
        }

        statusEl.textContent = "Foto " + (index + 1) + " van " + files.length + " uploaden…";

        var body = new FormData();
        body.append("csrf_token", csrfInput.value);
        body.append("portfolio_item_id", itemIdInput.value);
        body.append("ajax", "1");
        body.append("images[]", files[index]);

        fetch(form.getAttribute("action"), { method: "POST", credentials: "same-origin", body: body })
          .then(function (response) {
            return response.json().catch(function () { return { ok: false }; });
          })
          .then(function (data) {
            if (!data || !data.ok) {
              errors.push(files[index].name + ": " + ((data && data.error) || "upload mislukt."));
            }
            uploadNext(index + 1);
          })
          .catch(function () {
            errors.push(files[index].name + ": upload mislukt.");
            uploadNext(index + 1);
          });
      }

      function finish() {
        try {
          if (errors.length > 0) {
            sessionStorage.setItem(PORTFOLIO_IMAGE_ERRORS_KEY, JSON.stringify(errors));
          }
        } catch (e) {}
        window.location.reload();
      }

      uploadNext(0);
    });
  }

  function renderStoredPortfolioImageErrors() {
    var raw;
    try {
      raw = sessionStorage.getItem(PORTFOLIO_IMAGE_ERRORS_KEY);
    } catch (e) {
      raw = null;
    }
    if (!raw) return;

    try {
      sessionStorage.removeItem(PORTFOLIO_IMAGE_ERRORS_KEY);
    } catch (e) {}

    var errors;
    try {
      errors = JSON.parse(raw);
    } catch (e) {
      return;
    }
    if (!Array.isArray(errors) || errors.length === 0) return;

    var form = document.querySelector("[data-portfolio-add-images-form]");
    if (!form || !form.parentNode) return;

    var box = document.createElement("div");
    box.className = "admin-alert admin-alert--error";
    var list = document.createElement("ul");
    list.className = "admin-error-list";
    errors.forEach(function (message) {
      var li = document.createElement("li");
      li.textContent = message;
      list.appendChild(li);
    });
    box.appendChild(list);
    form.parentNode.insertBefore(box, form);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initVariantImageGrids();
      initPageSectionZones();
      initNavItemZones();
      initFooterZones();
      initRichTextEditors();
      initColorSync();
      initRangeOutputs();
      initHomepageHeroMediaToggle();
      initPortfolioDetailToggle();
      initPortfolioItemImageUpload();
      renderStoredPortfolioImageErrors();
      initSlugAutoFill();
      initSeoCharCounters();
    });
  } else {
    initVariantImageGrids();
    initPageSectionZones();
    initRichTextEditors();
    initColorSync();
    initRangeOutputs();
    initHomepageHeroMediaToggle();
    initPortfolioDetailToggle();
    initPortfolioItemImageUpload();
    renderStoredPortfolioImageErrors();
    initSlugAutoFill();
    initSeoCharCounters();
  }
})();
