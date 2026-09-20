/* =========================================================================
   Shop — cart state and the header mini-cart.

   Loaded on EVERY public page, because partials/header.php renders the
   mini-cart on every public page. That is the one Core->Shop asset seam
   left after step 4; App\Service\PageAssets documents it and step 5 removes
   it by turning the mini-cart into a module contribution. The rest of the
   Shop's frontend (catalogue, product detail, checkout, order status) is in
   assets/js/shop/shop.js and loads only on Shop routes.

   The shared render helpers live here rather than in Core: they format
   product prices and product copy, which is Shop knowledge.
   ========================================================================= */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------
     THE LANGUAGE PREFIX of the page this script is running on.

     Every language has its own URLs since Multilingual 2.0 phase 6
     (docs/multilingual/ROUTING.md), and a link this file builds in the
     browser has to carry the same prefix the server would have put on it —
     otherwise a customer on /en/... is dropped back into the default
     language the moment they click a cart line.

     PHP stamps it on <html data-url-prefix>: "" for the default language and
     "/en" for any other. It is read once, it is never parsed out of the
     current path (a two-letter first segment could just as well be a page
     slug), and a value that is not a plain /xx is ignored — a rewritten
     attribute must not be able to point links at another origin.
     --------------------------------------------------------------------- */
  var URL_PREFIX = (function () {
    var raw = document.documentElement.getAttribute("data-url-prefix") || "";

    return /^\/[a-z]{2}$/.test(raw) ? raw : "";
  })();

  /** A root-relative site path in the language this page is being read in. */
  function localeUrl(path) {
    return URL_PREFIX + path;
  }

  var docEl = document.documentElement;

  /* ---------------------------------------------------------------------
     Shared helpers for rendering product data (used by the shop grid and
     the product detail page): HTML escaping, price formatting, and the
     same NL/EN fallback rule the rest of the site uses (data-nl/data-en,
     EN value if present, otherwise NL).
     --------------------------------------------------------------------- */
  var genericProductIcon =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="8" cy="6" r="3.2"/><path d="M10.3 8.3L20 18l-2.5 2.5L8 10.7"/><path d="M14 14l3 3"/></svg>';

  function escapeHtml(value) {
    var div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/"/g, "&quot;");
  }

  /**
   * Makes a stored, site-relative path root-relative.
   *
   * Image paths come out of the database as "assets/images/x.webp" and the
   * product URL is built as "product.php?id=…", both written back when every
   * public page still lived at the site root. Two routes are nested —
   * /portfolio/<slug> and /collecties/<slug> — and there a relative path
   * resolves against the directory ("/collecties/assets/images/x.webp") and
   * 404s. Anything rendered by JS therefore goes through here.
   *
   * A no-op for a value that is already root-relative or absolute, so this
   * changes nothing on the root-level pages.
   */
  function rootPath(path) {
    var value = String(path == null ? "" : path);
    if (value === "" || value.charAt(0) === "/" || /^[a-z][a-z0-9+.-]*:|^\/\//i.test(value)) {
      return value;
    }
    return "/" + value;
  }

  function formatPrice(price) {
    var num = parseFloat(price);
    if (isNaN(num)) return "";
    return "&euro;" + num.toFixed(2).replace(".", ",");
  }

  function bilingualAttrs(nl, en) {
    var nlVal = nl || "";
    var enVal = en || nlVal;
    return 'data-nl="' + escapeAttr(nlVal) + '" data-en="' + escapeAttr(enVal) + '"';
  }

  function currentLangText(nl, en) {
    var lang = docEl.lang === "en" ? "en" : "nl";
    var nlVal = nl || "";
    var enVal = en || nlVal;
    return escapeHtml(lang === "en" ? enVal : nlVal);
  }

  /**
   * Product descriptions are stored server-side as sanitized HTML (see
   * DescriptionSanitizer — only p/br/strong/em/a survive) or, for older
   * rows, plain text. currentLangHtml() returns that value as-is (no
   * escaping) for the product detail page, which renders it with
   * innerHTML/data-nl-data-en so formatting shows up. Never use this for
   * content that hasn't gone through the server-side sanitizer.
   */
  function currentLangHtml(nl, en) {
    var lang = docEl.lang === "en" ? "en" : "nl";
    var nlVal = nl || "";
    var enVal = en || nlVal;
    return lang === "en" ? enVal : nlVal;
  }

  /**
   * Plain-text preview of a (possibly HTML) description, for the shop card
   * — formatting is dropped entirely there, only the clamped text remains.
   */
  function stripHtmlToText(html) {
    if (!html) return "";
    var tmp = document.createElement("div");
    tmp.innerHTML = html;
    return (tmp.textContent || "").replace(/\s+/g, " ").trim();
  }

  /* ---------------------------------------------------------------------
     Cart state — shared by every page that includes the header cart
     trigger, the standalone cart page (cart.php) and the product detail
     page's "Add to cart" button. Persisted to localStorage so it survives
     navigation between pages. Items: {id, name, name_en, price,
     image_path, qty, variant_id, variant_label}. variant_id/variant_label
     are null for a product without variants. Two lines are the "same" line
     (quantity just adds up) only when both id AND variant_id match — two
     different variants of the same product are always separate lines.

     A PERSONALIZED item carries two extra fields: `personalization` (what
     the customer typed/uploaded and where they put it — see
     assets/js/personalization.js) and `line_id`, a random handle generated
     when the line is created. Personalized lines are matched by comparing
     the whole personalization, so "Bart" and "Inge" of the same keychain are
     always two lines while adding the identical personalization twice still
     just adds up. `line_id` then identifies the line for the UI's
     data-cart-* attributes, because a product+variant pair no longer
     identifies one line on its own. An item WITHOUT personalization keeps
     exactly the key it has always had, so a cart saved before this feature
     existed keeps working unchanged.

     None of this is trusted at checkout: api/checkout.php re-reads every
     price from the database and re-validates every personalization against
     the product's live configuration.
     --------------------------------------------------------------------- */
  var CART_KEY = "vvl-cart";

  /**
   * Gives every personalized line the line_id it needs.
   *
   * A personalized line is identified by its line_id and by nothing else: it
   * is how the composed preview finds the line it belongs to, and how the
   * cart's edit link reopens that exact personalization. A line saved before
   * line ids existed has none — and a customer's cart lives in their browser,
   * so it outlives the deploy that introduced them. Without this such a line
   * is permanently anonymous: adding the same personalization again merges
   * into it and returns no id, so its preview is composed, posted, and then
   * attached to nothing, and the cart links back to a bare product page
   * instead of restoring what the customer made.
   *
   * Written straight to storage rather than through writeCart(), because
   * nothing the customer can see has changed and no consumer needs waking.
   */
  function backfillCartLineIds(items) {
    var changed = false;

    items.forEach(function (item) {
      if (item && item.personalization && !item.line_id) {
        item.line_id = newCartLineId();
        changed = true;
      }
    });

    if (changed) {
      try { localStorage.setItem(CART_KEY, JSON.stringify(items)); } catch (e) {}
    }

    return items;
  }

  function readCart() {
    try {
      var items = JSON.parse(localStorage.getItem(CART_KEY) || "[]");
      return Array.isArray(items) ? backfillCartLineIds(items) : [];
    } catch (e) { return []; }
  }

  function writeCart(items) {
    try { localStorage.setItem(CART_KEY, JSON.stringify(items)); } catch (e) {}
    document.dispatchEvent(new CustomEvent("vvl-cart-change"));
  }

  /* Identifies a cart line for the data-cart-* attributes the UI uses to
     target it: a personalized line by its own random line_id, any other line
     by product id + variant id (empty string when the product has no
     variant) — byte-for-byte the key this function has always returned, so
     nothing about an unpersonalized cart changes. */
  function cartLineKey(item) {
    if (item.line_id) return "L:" + String(item.line_id);
    return String(item.id) + "::" + (item.variant_id != null ? String(item.variant_id) : "");
  }

  function newCartLineId() {
    return String(Date.now().toString(36)) + "-" + Math.random().toString(36).slice(2, 10);
  }

  /* Whether two cart lines are the same purchase. Product and variant must
     match, and so must the personalization IN FULL — the text, the uploaded
     file and where both were placed. Comparing the serialized personalization
     (rather than a hash, or the line key) is what makes "do not accidentally
     merge differently personalized items" a property of the data instead of
     a lucky collision-free hash. */
  function samePersonalization(a, b) {
    var left = a && a.personalization ? a.personalization : null;
    var right = b && b.personalization ? b.personalization : null;
    if (!left && !right) return true;
    if (!left || !right) return false;
    return JSON.stringify(left) === JSON.stringify(right);
  }

  function cartAdd(product, qty) {
    qty = parseInt(qty, 10) || 1;
    var variantId = product.variant_id != null ? product.variant_id : null;
    var personalization = product.personalization || null;
    var items = readCart();
    var existing = null;
    for (var i = 0; i < items.length; i++) {
      var candidate = items[i];
      var sameVariant = (candidate.variant_id != null ? String(candidate.variant_id) : "") ===
        (variantId != null ? String(variantId) : "");
      if (String(candidate.id) === String(product.id) && sameVariant &&
          samePersonalization(candidate, { personalization: personalization })) {
        existing = candidate;
        break;
      }
    }
    var touched;
    if (existing) {
      existing.qty += qty;
      touched = existing;
    } else {
      var line = {
        id: product.id,
        name: product.name || "",
        name_en: product.name_en || "",
        price: parseFloat(product.price) || 0,
        image_path: product.image_path || null,
        variant_id: variantId,
        variant_label: product.variant_label || null,
        qty: qty
      };
      if (personalization) {
        line.personalization = personalization;
        line.line_id = newCartLineId();
      }
      items.push(line);
      touched = line;
    }
    writeCart(items);

    // The line_id of a personalized line, so the caller can attach its
    // composed preview once the browser has finished rendering it.
    return touched.line_id || null;
  }

  /**
   * Attaches the COMPOSED preview tokens (one per personalization view) to a
   * cart line, after the fact.
   *
   * Deliberately out-of-band: the customer's line goes into the cart the
   * instant they click, and the pictures follow when the browser has
   * rasterised and posted them. A line that never receives its tokens is
   * still a complete, orderable line — the snapshot is supplementary, and
   * api/checkout.php simply has nothing to claim.
   */
  function cartAttachPreviewTokens(lineId, tokens) {
    if (!lineId || !tokens || !Object.keys(tokens).length) return;

    var items = readCart();
    var changed = false;

    items.forEach(function (item) {
      if (String(item.line_id) !== String(lineId)) return;
      item.preview_tokens = tokens;
      changed = true;
    });

    if (changed) writeCart(items);
  }

  /* The small surface assets/js/personalization.js talks to. Kept to one
     function on purpose: the personalization module knows nothing about how
     the cart is stored, and the cart knows nothing about how a preview is
     composed. */

  /**
   * Where a cart line links to. A personalized line carries its own line_id,
   * so returning to the product restores THAT line's personalization for
   * editing instead of dropping the customer into an empty editor — see
   * restore() in assets/js/personalization.js. An ordinary line keeps the
   * plain product URL it always had.
   */
  function cartItemEditUrl(item) {
    var url = localeUrl("/product.php?id=" + encodeURIComponent(item.id));
    if (item.line_id) url += "&line=" + encodeURIComponent(item.line_id);
    return url;
  }

  function cartRemove(key) {
    writeCart(readCart().filter(function (item) { return cartLineKey(item) !== key; }));
  }

  function cartSetQty(key, qty) {
    qty = parseInt(qty, 10) || 0;
    var items = readCart()
      .map(function (item) {
        if (cartLineKey(item) === key) item.qty = qty;
        return item;
      })
      .filter(function (item) { return item.qty > 0; });
    writeCart(items);
  }

  function cartCount(items) {
    return items.reduce(function (sum, item) { return sum + (parseInt(item.qty, 10) || 0); }, 0);
  }

  /* Money is summed in whole CENTS, never in floats — the same rule
     App\Service\Personalization\Money enforces on the server. A personalization
     surcharge is added to a product price and then across lines, and that
     repeated addition is exactly what drifts a float total a cent away from
     the invoice. `item.price` stays the product's own unit price; the
     surcharge sits beside it so the cart can show both separately. */
  function toCents(value) {
    if (typeof value === "number" && isFinite(value)) return Math.round(value * 100);
    if (typeof value !== "string") return 0;
    var match = /^(-)?(\d*)(?:[.,](\d*))?$/.exec(value.trim());
    if (!match) return 0;
    var fraction = (match[3] || "").slice(0, 2);
    while (fraction.length < 2) fraction += "0";
    var cents = parseInt(match[2] || "0", 10) * 100 + parseInt(fraction, 10);
    return match[1] === "-" ? -cents : cents;
  }

  /* Display only, and never authoritative: api/checkout.php re-reads every
     surcharge from the product's own zone configuration, so a tampered value
     here changes a label in one browser and nothing else. */
  function cartSurchargeCents(item) {
    var personalization = item && item.personalization;
    if (!personalization || typeof personalization.surcharge_cents !== "number") return 0;
    return Math.max(0, Math.round(personalization.surcharge_cents));
  }

  /** What one unit of this line costs: the product's price plus its surcharge. */
  function cartUnitCents(item) {
    return toCents(item.price) + cartSurchargeCents(item);
  }

  function cartLineCents(item) {
    return cartUnitCents(item) * (parseInt(item.qty, 10) || 0);
  }

  function cartSubtotalCents(items) {
    return items.reduce(function (sum, item) { return sum + cartLineCents(item); }, 0);
  }

  function cartSubtotal(items) {
    return cartSubtotalCents(items) / 100;
  }

  function cartItemMedia(item) {
    return item.image_path ?
      '<img src="' + escapeAttr(rootPath(item.image_path)) + '" alt="" loading="lazy">' :
      genericProductIcon;
  }

  /* The one renderer for "this line is personalized", reused by the header
     dropdown, the cart page and the checkout summary so the customer sees
     the same confirmation everywhere. Its job is verification — "did I add
     the right thing?" — not a second editor, so it stays a single line plus
     an optional thumbnail.

     The uploaded image is shown through its own token URL
     (/api/personalization-image.php), never a storage path: that endpoint
     serves only the downscaled re-encoded copy, and the original the owner
     receives is not reachable from the shop at all. */
  /* A cart line's personalized zones, normalised.

     A line saved before multiple zones existed stored ONE flat zone
     ({text, upload_token, transform}); a Phase 2 line stores {zones: [...]}.
     Both are read here, so a cart still sitting in a customer's browser from
     before this feature grew keeps rendering — and keeps checking out, since
     the server accepts both shapes too. */
  function cartPersonalizationZones(item) {
    var personalization = item && item.personalization;
    if (!personalization) return [];

    if (Array.isArray(personalization.zones)) return personalization.zones;

    if (personalization.text || personalization.upload_token) {
      return [{
        zone_key: personalization.zone_key || "default",
        label: null,
        label_en: null,
        text: personalization.text || "",
        upload_token: personalization.upload_token || null,
        upload_name: personalization.upload_name || null,
        upload_preview_url: personalization.upload_preview_url || null,
        surcharge_cents: 0
      }];
    }

    return [];
  }

  function zoneDisplayLabel(zone) {
    var nl = zone.label || null;
    var en = zone.label_en || nl;
    return nl ? currentLangText(nl, en) : null;
  }

  /* The one renderer for "this line is personalized", reused by the header
     dropdown, the cart page and the checkout summary so the customer sees the
     same confirmation everywhere. Its job is verification — "did I add the
     right thing?" — not a second editor, so each zone stays one short line.

     Only customer-facing labels are shown; a zone key, a view key or anything
     else internal never reaches the markup. The uploaded image is shown
     through its own token URL (/api/personalization-image.php), never a
     storage path: that endpoint serves only the downscaled re-encoded copy,
     and the original the owner receives is not reachable from the shop. */
  function cartPersonalizationHtml(item, modifier) {
    var zones = cartPersonalizationZones(item);
    if (!zones.length) return "";

    var rows = zones.map(function (zone) {
      var parts = [];
      if (zone.text) parts.push("“" + escapeHtml(zone.text) + "”");
      if (zone.font_label) parts.push(escapeHtml(zone.font_label));
      if (zone.upload_token) {
        parts.push(
          currentLangText("eigen afbeelding", "own image") +
          (zone.upload_name ? " (" + escapeHtml(zone.upload_name) + ")" : "")
        );
      }
      if (!parts.length) return "";

      var label = zoneDisplayLabel(zone);
      var surcharge = zone.surcharge_cents > 0 ?
        ' <span class="cart-personalization__surcharge">+ ' + formatPrice(zone.surcharge_cents / 100) + "</span>" : "";

      // The thumbnail's class is fixed, never derived from the block's own
      // class list: a modifier like "cart-personalization--compact" would
      // otherwise turn into "cart-personalization--compact__thumb" (a class
      // that does not exist) and hand the image the block's flex-row styling.
      var thumb = zone.upload_preview_url ?
        '<img class="cart-personalization__thumb" src="' + escapeAttr(zone.upload_preview_url) + '" alt="" loading="lazy">' : "";

      return '<span class="cart-personalization__zone">' + thumb + "<span>" +
        (label ? "<b>" + label + ":</b> " : "") + parts.join(", ") + surcharge +
        "</span></span>";
    }).filter(Boolean);

    if (!rows.length) return "";

    return '<p class="cart-personalization' + (modifier ? " " + modifier : "") + '">' +
      '<span class="cart-personalization__heading">' +
      currentLangText("Personalisatie", "Personalisation") + "</span>" +
      rows.join("") + "</p>";
  }

  /* Header cart badge + dropdown — reads current cart state and rewrites
     the existing markup on every page that has [data-cart-trigger]. */
  function renderCartHeader() {
    var items = readCart();
    var count = cartCount(items);
    var subtotal = cartSubtotal(items);
    var isEn = docEl.lang === "en";

    document.querySelectorAll("[data-cart-trigger]").forEach(function (trigger) {
      var badge = trigger.querySelector("[data-cart-count]");
      if (badge) badge.textContent = String(count);

      var countLabel = trigger.querySelector(".cart-dropdown__count");
      if (countLabel) {
        var nl = count + (count === 1 ? " product" : " producten");
        var en = count + (count === 1 ? " item" : " items");
        countLabel.setAttribute("data-nl", nl);
        countLabel.setAttribute("data-en", en);
        countLabel.textContent = isEn ? en : nl;
      }

      var itemsList = trigger.querySelector(".cart-dropdown__items");
      var footer = trigger.querySelector(".cart-dropdown__subtotal");

      if (!items.length) {
        if (itemsList) {
          itemsList.innerHTML = '<li class="cart-dropdown__empty" ' +
            bilingualAttrs("Je winkelwagen is leeg.", "Your cart is empty.") + ">" +
            currentLangText("Je winkelwagen is leeg.", "Your cart is empty.") + "</li>";
        }
        if (footer) footer.hidden = true;
        return;
      }

      if (footer) {
        footer.hidden = false;
        var subtotalEl = footer.querySelector("strong");
        if (subtotalEl) subtotalEl.innerHTML = formatPrice(subtotal);
      }
      if (itemsList) {
        itemsList.innerHTML = items.map(function (item) {
          var variantLine = item.variant_label ?
            "<span class=\"cart-dropdown__item-variant\">" + escapeHtml(item.variant_label) + "</span>" : "";
          return (
            '<li class="cart-dropdown__item">' +
            '<a class="cart-dropdown__item-link" href="' + escapeAttr(cartItemEditUrl(item)) + '">' +
            '<div class="cart-dropdown__item-media">' + cartItemMedia(item) + "</div>" +
            '<div class="cart-dropdown__item-info">' +
            "<p " + bilingualAttrs(item.name, item.name_en) + ">" + currentLangText(item.name, item.name_en) + "</p>" +
            variantLine +
            cartPersonalizationHtml(item, "cart-personalization--compact") +
            "<span>" + item.qty + "x</span>" +
            "</div>" +
            "</a>" +
            '<div class="cart-dropdown__item-price">' + formatPrice(cartLineCents(item) / 100) + "</div>" +
            '<button type="button" class="cart-dropdown__item-remove" data-cart-remove="' + escapeAttr(cartLineKey(item)) + '" aria-label="Verwijderen" data-nl-aria="Verwijderen" data-en-aria="Remove">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
            "</li>"
          );
        }).join("");
      }
    });
  }

  /* Full cart page (cart.php): renders the real cart into .cart-list /
     .cart-empty / the order summary, and wires quantity + remove
     controls. No-op on pages that don't have [data-cart-list]. */
  function renderCartPage() {
    var list = document.querySelector("[data-cart-list]");
    if (!list) return;
    var emptyEl = document.querySelector("[data-cart-empty]");
    var items = readCart();
    var subtotal = cartSubtotal(items);

    if (!items.length) {
      list.innerHTML = "";
      list.hidden = true;
      if (emptyEl) emptyEl.hidden = false;
    } else {
      list.hidden = false;
      if (emptyEl) emptyEl.hidden = true;
      list.innerHTML = items.map(function (item) {
        var key = cartLineKey(item);
        var variantLine = item.variant_label ?
          "<p class=\"cart-row__variant\">" + escapeHtml(item.variant_label) + "</p>" : "";
        return (
          '<div class="cart-row">' +
          '<a class="cart-row__link" href="' + escapeAttr(cartItemEditUrl(item)) + '">' +
          '<div class="cart-row__media">' + cartItemMedia(item) + "</div>" +
          '<div class="cart-row__info">' +
          "<h3 " + bilingualAttrs(item.name, item.name_en) + ">" + currentLangText(item.name, item.name_en) + "</h3>" +
          variantLine +
          cartPersonalizationHtml(item, "") +
          "</div>" +
          "</a>" +
          '<div class="qty-stepper qty-stepper--sm">' +
          '<button type="button" data-cart-qty-dec="' + escapeAttr(key) + '" aria-label="Aantal verlagen" data-nl-aria="Aantal verlagen" data-en-aria="Decrease quantity">&minus;</button>' +
          '<input type="number" value="' + item.qty + '" min="1" max="20" inputmode="numeric" data-cart-qty-input="' + escapeAttr(key) + '" aria-label="Aantal" data-nl-aria="Aantal" data-en-aria="Quantity">' +
          '<button type="button" data-cart-qty-inc="' + escapeAttr(key) + '" aria-label="Aantal verhogen" data-nl-aria="Aantal verhogen" data-en-aria="Increase quantity">+</button>' +
          "</div>" +
          '<div class="cart-row__price">' + formatPrice(cartLineCents(item) / 100) + "</div>" +
          '<button type="button" class="cart-row__remove" data-cart-remove="' + escapeAttr(key) + '" aria-label="Verwijderen" data-nl-aria="Verwijderen" data-en-aria="Remove">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
          "</div>"
        );
      }).join("");
    }

    document.querySelectorAll("[data-cart-subtotal]").forEach(function (el) { el.innerHTML = formatPrice(subtotal); });
    document.querySelectorAll("[data-cart-total]").forEach(function (el) { el.innerHTML = formatPrice(subtotal); });
  }

  function renderCartUI() {
    renderCartHeader();
    renderCartPage();
  }

  /* Delegated handlers for remove / quantity controls rendered by
     renderCartHeader() and renderCartPage() above. */
  function initCartControls() {
    document.addEventListener("click", function (e) {
      var removeBtn = e.target.closest("[data-cart-remove]");
      if (removeBtn) { cartRemove(removeBtn.getAttribute("data-cart-remove")); return; }

      var decBtn = e.target.closest("[data-cart-qty-dec]");
      if (decBtn) {
        var decKey = decBtn.getAttribute("data-cart-qty-dec");
        var decItem = readCart().filter(function (i) { return cartLineKey(i) === decKey; })[0];
        if (decItem) cartSetQty(decKey, decItem.qty - 1);
        return;
      }

      var incBtn = e.target.closest("[data-cart-qty-inc]");
      if (incBtn) {
        var incKey = incBtn.getAttribute("data-cart-qty-inc");
        var incItem = readCart().filter(function (i) { return cartLineKey(i) === incKey; })[0];
        if (incItem) cartSetQty(incKey, incItem.qty + 1);
        return;
      }
    });

    document.addEventListener("change", function (e) {
      var input = e.target.closest("[data-cart-qty-input]");
      if (!input) return;
      cartSetQty(input.getAttribute("data-cart-qty-input"), parseInt(input.value, 10) || 1);
    });

    document.addEventListener("vvl-cart-change", renderCartUI);
    window.addEventListener("storage", function (e) {
      if (e.key === CART_KEY) renderCartUI();
    });
  }

  /* Briefly swaps an "Add to cart" button's icon for a checkmark as
     immediate feedback, independent of the toast/bump below. */
  function flashAddButton(btn) {
    clearTimeout(btn._addFlashTimer);
    btn.classList.remove("is-added");
    void btn.offsetWidth; // restart the CSS animation if clicked again quickly
    btn.classList.add("is-added");
    btn._addFlashTimer = setTimeout(function () {
      btn.classList.remove("is-added");
    }, 1200);
  }

  /* Add-to-cart confirmation — cart icon bump + a small, non-blocking
     toast near the header cart icon. Auto-dismisses, needs no
     interaction, and reuses/updates a single toast element so repeated
     adds don't stack up notifications.
     The toast is appended to <body> (not nested inside the header/nav):
     on narrow viewports .main-nav carries a CSS transform for its
     slide-in panel, and a transformed ancestor becomes the containing
     block for position:fixed descendants — nesting the toast there
     silently broke its fixed positioning on mobile.
     It's a real <a href="cart.php">, so it's clickable throughout its
     visible life, including while fading out (.is-hiding only lowers
     opacity — pointer-events stay on via .is-visible until that fade
     finishes). Hovering during the fade cancels it and restores full
     opacity; leaving restarts the auto-hide timer from scratch. */
  var CART_TOAST_DELAY = 2600;
  var cartToastEl = null;

  function ensureCartToast() {
    if (cartToastEl) return cartToastEl;
    cartToastEl = document.createElement("a");
    cartToastEl.className = "cart-toast";
    cartToastEl.href = localeUrl("/cart.php");
    cartToastEl.setAttribute("data-cart-toast", "");
    cartToastEl.setAttribute("aria-live", "polite");
    cartToastEl.innerHTML =
      '<div class="cart-toast__media" data-cart-toast-media></div>' +
      '<div class="cart-toast__body">' +
      "<p data-cart-toast-title></p>" +
      "<span data-cart-toast-detail></span>" +
      "</div>";
    document.body.appendChild(cartToastEl);

    function scheduleHide() {
      clearTimeout(cartToastEl._hideTimer);
      cartToastEl._hideTimer = setTimeout(function () {
        cartToastEl.classList.add("is-hiding");
      }, CART_TOAST_DELAY);
    }
    cartToastEl._scheduleHide = scheduleHide;

    /* Hover pauses the countdown and, if a fade is already underway,
       cancels it (opacity transitions back to 1 via the same CSS rule
       that started the fade). Leaving restarts the full countdown. */
    cartToastEl.addEventListener("mouseenter", function () {
      clearTimeout(cartToastEl._hideTimer);
      cartToastEl.classList.remove("is-hiding");
    });
    cartToastEl.addEventListener("mouseleave", function () {
      if (cartToastEl.classList.contains("is-visible")) scheduleHide();
    });

    /* Only once the fade-out transition actually completes do we drop
       .is-visible (which is what turns pointer-events back off). */
    cartToastEl.addEventListener("transitionend", function (e) {
      if (e.propertyName !== "opacity") return;
      if (cartToastEl.classList.contains("is-hiding")) {
        cartToastEl.classList.remove("is-visible", "is-hiding");
      }
    });

    return cartToastEl;
  }

  function initCartFeedback() {
    document.addEventListener("vvl-add-to-cart", function (e) {
      var detail = e.detail || {};
      var product = detail.product || {};
      var qty = detail.qty || 1;

      var iconBtn = document.querySelector("[data-cart-trigger] .cart-trigger__btn");
      if (iconBtn) {
        iconBtn.classList.remove("is-bumped");
        void iconBtn.offsetWidth; // restart the CSS animation if triggered again quickly
        iconBtn.classList.add("is-bumped");
      }

      var toast = ensureCartToast();

      /* Above the mobile breakpoint, anchor the toast under the actual
         cart icon; below it, the stylesheet positions it as a fixed
         bottom-center banner instead (clearing any inline position keeps
         that CSS in control). */
      if (iconBtn && window.innerWidth > 900) {
        var rect = iconBtn.getBoundingClientRect();
        toast.style.top = (rect.bottom + 14) + "px";
        toast.style.right = (document.documentElement.clientWidth - rect.right) + "px";
      } else {
        toast.style.top = "";
        toast.style.right = "";
      }

      var titleEl = toast.querySelector("[data-cart-toast-title]");
      if (titleEl) titleEl.textContent = currentLangText("Toegevoegd aan je winkelwagen", "Added to your cart");

      var mediaEl = toast.querySelector("[data-cart-toast-media]");
      if (mediaEl) mediaEl.innerHTML = cartItemMedia(product);

      var detailEl = toast.querySelector("[data-cart-toast-detail]");
      if (detailEl) detailEl.textContent = qty + "× " + currentLangText(product.name, product.name_en);

      toast.classList.remove("is-hiding");
      toast.classList.add("is-visible");
      toast._scheduleHide();
    });
  }

  /* ---------------------------------------------------------------------
     Cart dropdown (header) — opens on hover (fine-pointer devices) or on
     click/tap, closes on outside click, Escape, or after leaving the
     trigger.
     --------------------------------------------------------------------- */
  function initCartDropdown() {
    var trigger = document.querySelector("[data-cart-trigger]");
    if (!trigger) return;
    var btn = trigger.querySelector(".cart-trigger__btn");
    var dropdown = trigger.querySelector("[data-cart-dropdown]");
    if (!btn || !dropdown) return;

    var hoverCapable = window.matchMedia("(hover: hover) and (pointer: fine)").matches;
    var closeTimer = null;

    function open() {
      clearTimeout(closeTimer);
      trigger.classList.add("is-open");
      btn.setAttribute("aria-expanded", "true");
    }
    function close() {
      trigger.classList.remove("is-open");
      btn.setAttribute("aria-expanded", "false");
    }
    function scheduleClose() {
      clearTimeout(closeTimer);
      closeTimer = setTimeout(close, 220);
    }

    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      trigger.classList.contains("is-open") ? close() : open();
    });

    if (hoverCapable) {
      trigger.addEventListener("mouseenter", open);
      trigger.addEventListener("mouseleave", scheduleClose);
    }

    document.addEventListener("click", function (e) {
      if (!trigger.contains(e.target)) close();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") close();
    });

    /* Checkout form: no backend yet, just stop the page from reloading. */
    var checkoutForm = document.querySelector("[data-checkout-form]");
    if (checkoutForm) {
      checkoutForm.addEventListener("submit", function (e) { e.preventDefault(); });
    }
  }

  /* ---------------------------------------------------------------------
     Quantity steppers (product page, cart page) — UI only, clamps to
     the input's min/max and fires a change event so listeners (e.g. the
     product total) can react.
     --------------------------------------------------------------------- */
  function initQtySteppers() {
    document.querySelectorAll(".qty-stepper").forEach(function (stepper) {
      var input = stepper.querySelector("input");
      if (!input) return;
      var min = parseInt(input.min, 10) || 1;
      var max = parseInt(input.max, 10) || 99;

      function clamp() {
        var val = parseInt(input.value, 10);
        if (isNaN(val)) val = min;
        input.value = Math.max(min, Math.min(max, val));
      }

      stepper.querySelectorAll("button").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var val = (parseInt(input.value, 10) || min) + (btn.dataset.step === "down" ? -1 : 1);
          input.value = Math.max(min, Math.min(max, val));
          input.dispatchEvent(new Event("change", { bubbles: true }));
        });
      });
      input.addEventListener("change", clamp);
    });
  }

  /* ---------------------------------------------------------------------
     Product page: live total (unit price x quantity) — display only.
     --------------------------------------------------------------------- */
  function initProductTotal() {
    var wrap = document.querySelector("[data-product-total]");
    if (!wrap) return;
    var input = wrap.querySelector(".qty-stepper input");
    var out = wrap.querySelector("[data-total-value]");
    var unit = parseFloat(wrap.dataset.unitPrice || "0");
    if (!input || !out) return;

    function update() {
      var qty = parseInt(input.value, 10) || 1;
      out.textContent = "€" + (unit * qty).toFixed(2).replace(".", ",");
    }
    input.addEventListener("change", update);
    input.addEventListener("input", update);
    update();
  }

  /* ---------------------------------------------------------------------
     The Shop's cart API.

     attachPreviewTokens is the one PUBLIC entry point, called by
     assets/js/personalization.js after it has composed a preview — it
     predates step 4 and its shape is unchanged.

     Everything under `internal` is exactly that: the cart state and the
     shared render helpers that assets/js/shop/shop.js (product grid,
     product detail, checkout, order status) needs and that used to sit in
     the same file as its callers. It is an INTERNAL contract between two
     Shop files, not a site-wide API — nothing outside assets/js/shop/ may
     read it.
     --------------------------------------------------------------------- */
  window.VVLCart = {
    attachPreviewTokens: cartAttachPreviewTokens,
    internal: {
      readCart: readCart,
      writeCart: writeCart,
      cartAdd: cartAdd,
      cartItemMedia: cartItemMedia,
      cartPersonalizationHtml: cartPersonalizationHtml,
      cartPersonalizationZones: cartPersonalizationZones,
      cartLineCents: cartLineCents,
      cartSubtotal: cartSubtotal,
      flashAddButton: flashAddButton,
      genericProductIcon: genericProductIcon,
      escapeHtml: escapeHtml,
      escapeAttr: escapeAttr,
      rootPath: rootPath,
      localeUrl: localeUrl,
      formatPrice: formatPrice,
      bilingualAttrs: bilingualAttrs,
      currentLangText: currentLangText,
      currentLangHtml: currentLangHtml,
      stripHtmlToText: stripHtmlToText
    }
  };

  /* ---------------------------------------------------------------------
     Boot
     Same order as before the split: renderCartUI() writes the cart rows,
     initQtySteppers() then binds the steppers inside them.
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initCartDropdown();
    initCartControls();
    initCartFeedback();
    renderCartUI();
    initQtySteppers();
    initProductTotal();
  });
})();
