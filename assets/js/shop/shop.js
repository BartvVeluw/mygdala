/* =========================================================================
   Shop — catalogue, product detail, checkout and order status.

   Asked for by the Shop's own routes (shop.php, collectie.php, product.php,
   cart.php, checkout.php, bestelling-status.php, personaliseren.php) and by
   the two Shop content blocks (product_grid, shop_collections). A page
   without any of those never downloads it.

   Depends on assets/js/shop/cart.js, which the site shell always loads
   first: `S` below is that file's internal Shop API (cart state plus the
   shared product render helpers). It is read inside the DOMContentLoaded
   handler, so the two files carry no load-order requirement between them.
   ========================================================================= */
(function () {
  "use strict";

  /* assets/js/shop/cart.js's internal API — bound at boot, see the header. */
  var S = null;

  /* ---------------------------------------------------------------------
     Cloudflare Turnstile (checkout.php) — the API script tag names this
     global via its "onload" query param so Cloudflare calls it as soon as
     the widget API is ready, regardless of whether that happens before or
     after initCheckoutPage() runs on DOMContentLoaded (the script itself is
     loaded async). initCheckoutPage() registers the real renderer here once
     it exists; if Turnstile is already ready by then, it renders immediately.
     --------------------------------------------------------------------- */
  var turnstileReadyCallback = null;
  var turnstileApiReady = false;
  window.vvlOnTurnstileLoad = function () {
    turnstileApiReady = true;
    if (typeof turnstileReadyCallback === "function") turnstileReadyCallback();
  };

  /* ---------------------------------------------------------------------
     Shop: load products from the backend (GET /api/products.php) and
     render them into the grid. Falls back to an error message, keeping
     the rest of the page intact, if the API/database is unreachable.

     The same grid — and therefore the same product-card markup — also
     serves a collection page (/collecties/<slug>, see collectie.php): when
     the grid carries data-collection-slug, the request is scoped to that
     collection and the API returns its products in the collection's own
     order. A "Related Products" CMS section (partials/section-related-products.php)
     is the third user: it carries data-product-ids with the ids its block
     selected, already ordered, and the API returns exactly those. There is
     deliberately no second renderer: a product card must look and behave
     identically wherever it appears.

     A page can hold MORE THAN ONE such grid (a Related Products block on
     /shop.php sits alongside the shop's own grid), so every
     [data-products-grid] on the page is initialised independently and each
     one finds the [data-products-error] belonging to it — the one inside its
     own container — rather than a shared document-wide element.
     --------------------------------------------------------------------- */
  function initShopProducts() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-products-grid]"), initProductGrid);
  }

  function initProductGrid(grid) {
    if (!grid) return;

    var scope = grid.parentNode;
    var errorEl =
      (scope && scope.querySelector ? scope.querySelector("[data-products-error]") : null) ||
      document.querySelector("[data-products-error]");
    var collectionSlug = grid.getAttribute("data-collection-slug");
    var productIds = grid.getAttribute("data-product-ids");

    /* "Gerelateerde producten" is an OPTIONAL block, unlike the shop and
       collection grids: a product with no related products is a perfectly
       normal product, not an error, and neither an empty result nor a failed
       request may leave anything visible under it — no heading, no wrapper,
       no message. The shop and collection grids keep their own empty state,
       because there "no products" really is what the visitor asked to see and
       has to be told about.

       The server already returns null and renders nothing for the ordinary
       "this product has none" case (App\Service\RelatedProductsContent), so
       what is handled here is the remainder: ids that no longer resolve to a
       visible product, and a request that fails. Both are logged server-side
       where they are real failures; neither is ever printed to the customer. */
    var relatedSection = grid.closest ? grid.closest("[data-related-products]") : null;

    function removeOptionalBlock() {
      if (relatedSection && relatedSection.parentNode) {
        relatedSection.parentNode.removeChild(relatedSection);
      }
    }

    function renderProducts(products) {
      if (!products.length) {
        if (relatedSection) {
          removeOptionalBlock();
          return;
        }

        grid.innerHTML = '<p class="lead">' + S.escapeHtml(S.text("no_products")) + "</p>";
        return;
      }

      grid.innerHTML = products
        .map(function (product) {
          // Shop cards show a short plain-text preview only — formatting is
          // stripped, and CSS (.product-card__desc) clamps it to ~3 lines.
          // The full formatted description is on product.php.
          var descPlain = S.stripHtmlToText(product.description);
          var description = descPlain ?
            '<p class="product-card__desc">' + S.escapeHtml(descPlain) + "</p>" : "";
          var cardName = product.name || "";
          var cardAlt = product.image_alt || cardName;
          var media = product.image_path ?
            '<img src="' + S.escapeAttr(S.rootPath(product.image_path)) + '" alt="' + S.escapeAttr(cardAlt) + '" loading="lazy">' :
            S.genericProductIcon;

          return (
            // Root-relative on purpose: this same card is rendered from
            // /shop.php AND from the nested /collecties/<slug> path, where a
            // relative "product.php?id=" would resolve to
            // /collecties/product.php and 404. A product keeps its one
            // canonical product page no matter which collection it was
            // reached from.
            //
            // And in the language the page is being read in: since
            // Multilingual 2.0 phase 6 a card on /en/shop.php has to link to
            // /en/product.php?id=..., or clicking it drops the customer back
            // into the default language (S.localeUrl, see cart.js).
            '<a class="product-card is-visible" href="' + S.escapeAttr(S.localeUrl("/product.php?id=" + encodeURIComponent(product.id))) + '">' +
            '<div class="product-card__media">' + media + "</div>" +
            '<div class="product-card__body">' +
            "<h3>" + S.escapeHtml(cardName) + "</h3>" +
            description +
            '<div class="product-card__footer">' +
            '<span class="product-card__price">' + S.formatPrice(product.price) + "</span>" +
            "</div>" +
            "</div>" +
            "</a>"
          );
        })
        .join("");
    }

    var endpoint = "/api/products.php";
    if (productIds !== null) {
      endpoint += "?ids=" + encodeURIComponent(productIds);
    } else if (collectionSlug) {
      endpoint += "?collection=" + encodeURIComponent(collectionSlug);
    }

    fetch(S.apiUrl(endpoint))
      .then(function (res) {
        if (!res.ok) throw new Error("Request failed: " + res.status);
        return res.json();
      })
      .then(function (payload) {
        renderProducts(Array.isArray(payload.data) ? payload.data : []);
      })
      .catch(function () {
        if (relatedSection) {
          removeOptionalBlock();
          return;
        }

        grid.style.display = "none";
        if (errorEl) errorEl.hidden = false;
      });
  }

  /* ---------------------------------------------------------------------
     Product detail page (product.php?id=…): loads one product from
     GET /api/product.php and fills in the page. Shows a friendly message
     for a missing/invalid id, a 404, or an API/database failure.
     --------------------------------------------------------------------- */
  function initProductDetail() {
    var root = document.querySelector("[data-product-detail]");
    if (!root) return;

    var loadingEl = document.querySelector("[data-product-loading]");
    var errorEl = document.querySelector("[data-product-error]");
    var contentEl = document.querySelector("[data-product-content]");

    function showError() {
      if (loadingEl) loadingEl.hidden = true;
      if (contentEl) contentEl.hidden = true;
      if (errorEl) errorEl.hidden = false;

      // Personalisatie is its own full-width section BELOW the product, so
      // hiding the product's own content does not hide it. A product that
      // cannot be loaded must not still offer a configurator — and, for a
      // personalization-required product, the page's only add-to-cart button.
      var personalizerSection = document.querySelector("[data-personalizer-section]");
      if (personalizerSection) personalizerSection.hidden = true;
    }

    function renderProduct(product) {
      // document.title is deliberately NOT touched here. product.php renders
      // the product's real <title> server-side, in the language of the page
      // (App\Service\ProductSeo — which honours the SEO title the owner can
      // type in the CMS). Rebuilding a title here from a hardcoded
      // " | Shop — …" suffix would silently overwrite that custom title the
      // moment the API response arrived.

      // The BREADCRUMB is deliberately NOT touched here either. product.php
      // prints the product's real name into it server-side, from the same
      // `products` row this response comes from (App\Service\ProductSeo), so
      // a visitor without JavaScript and a crawler get the trail too. This
      // used to overwrite it because the page only ever shipped the word
      // "Product"; rewriting it now would only put the same words back.

      var nameEl = document.querySelector("[data-product-name]");
      if (nameEl) nameEl.textContent = product.name || "";

      var priceEl = document.querySelector("[data-product-price]");
      if (priceEl) priceEl.innerHTML = S.formatPrice(product.price);

      var descEl = document.querySelector("[data-product-description]");
      if (descEl) {
        if (product.description) {
          // Full formatted description here (unlike the shop card): the
          // value is server-sanitized HTML (sanitized on write AND again by
          // App\Service\ShopLocalization on the way out), so it is the one
          // value this page renders with innerHTML. Every plain-text field —
          // the product name above included — stays textContent.
          descEl.innerHTML = product.description;
          descEl.hidden = false;
        } else {
          descEl.hidden = true;
        }
      }

      var mediaEl = document.querySelector("[data-product-media]");
      var thumbsEl = document.querySelector("[data-product-thumbs]");
      var addBtn = document.querySelector("[data-product-add-to-cart]");
      var qtyInput = document.querySelector("[data-product-qty] input");
      var variantsEl = document.querySelector("[data-product-variants]");

      var options = Array.isArray(product.options) ? product.options : [];
      var variants = Array.isArray(product.variants) ? product.variants : [];
      var hasVariants = variants.length > 0;
      var selectedValues = {}; // option_id -> value_id
      var selectedVariant = null;

      function imageAlt(image, fallbackText) {
        return (image && image.alt_text) ? image.alt_text : fallbackText;
      }

      /* Renders a full gallery (main image + thumbnails) from a list of
         {image_path, alt_text} images — used for both a plain product's own
         photos and a variant's photos. Always replaces whatever gallery was
         showing before; falls back to the generic icon when there are none. */
      function renderGallery(images, fallbackAltText) {
        images = Array.isArray(images) ? images : [];

        function showMain(image) {
          if (!mediaEl) return;
          mediaEl.innerHTML = image ?
            '<img src="' + S.escapeAttr(S.rootPath(image.image_path)) + '" alt="' + S.escapeAttr(imageAlt(image, fallbackAltText)) + '">' :
            S.genericProductIcon;
        }

        if (images.length === 0) {
          showMain(null);
          if (thumbsEl) { thumbsEl.hidden = true; thumbsEl.innerHTML = ""; }
          return;
        }

        showMain(images[0]);

        if (!thumbsEl) return;

        if (images.length > 1) {
          thumbsEl.innerHTML = images
            .map(function (img, index) {
              var isActive = index === 0;
              var altText = imageAlt(img, fallbackAltText);
              return (
                '<button type="button" class="product-detail__thumb' + (isActive ? " is-active" : "") + '" data-image-index="' + index +
                '" aria-pressed="' + (isActive ? "true" : "false") + '" aria-label="' + S.escapeAttr(altText) + '">' +
                '<img src="' + S.escapeAttr(S.rootPath(img.image_path)) + '" alt="" loading="lazy"></button>'
              );
            })
            .join("");
          thumbsEl.hidden = false;

          thumbsEl.querySelectorAll("[data-image-index]").forEach(function (btn) {
            btn.addEventListener("click", function () {
              var index = parseInt(btn.getAttribute("data-image-index"), 10);
              showMain(images[index]);
              thumbsEl.querySelectorAll(".product-detail__thumb").forEach(function (other) {
                other.classList.remove("is-active");
                other.setAttribute("aria-pressed", "false");
              });
              btn.classList.add("is-active");
              btn.setAttribute("aria-pressed", "true");
            });
          });
        } else {
          thumbsEl.hidden = true;
          thumbsEl.innerHTML = "";
        }
      }

      /* A product with variants never shows its own product-level photos —
         only the selected variant's gallery. A product without variants
         keeps using product_images exactly as before. See MAIN.MD. */
      var nonVariantImages = [];
      var nonVariantDefaultImage = null;
      if (!hasVariants) {
        nonVariantImages = Array.isArray(product.images) ? product.images.slice() : [];
        var primaryIndex = nonVariantImages.findIndex(function (img) { return !!img.is_primary; });
        if (primaryIndex > 0) {
          nonVariantImages.unshift(nonVariantImages.splice(primaryIndex, 1)[0]);
        }
        nonVariantDefaultImage = nonVariantImages[0] || (product.image_path ? { image_path: product.image_path, alt_text: null } : null);
        if (nonVariantImages.length === 0 && nonVariantDefaultImage) {
          nonVariantImages = [nonVariantDefaultImage];
        }
        renderGallery(nonVariantImages, titleText);
      }

      /* ---------------------------------------------------------------
         Option/variant selector — only rendered when the product has
         variants. The default variant (first active by sort_order — see
         MAIN.MD) is auto-selected on load; picking another variant swaps
         the price and replaces the entire photo gallery with that
         variant's own photos.
         --------------------------------------------------------------- */
      function variantAltFallback(variant) {
        var label = variant.values.map(function (v) { return v.value; }).join(", ");
        return label ? titleText + " – " + label : titleText;
      }

      function findMatchingVariant() {
        for (var i = 0; i < options.length; i++) {
          if (!selectedValues[options[i].id]) return null;
        }
        for (var v = 0; v < variants.length; v++) {
          var variant = variants[v];
          if (variant.values.length !== options.length) continue;
          var matches = variant.values.every(function (val) {
            return String(selectedValues[val.option_id]) === String(val.value_id);
          });
          if (matches) return variant;
        }
        return null;
      }

      function applySelection() {
        selectedVariant = hasVariants ? findMatchingVariant() : null;

        var effectivePrice = selectedVariant && selectedVariant.price != null ? selectedVariant.price : product.price;
        if (priceEl) priceEl.innerHTML = S.formatPrice(effectivePrice);

        /* Personalization surcharges are shown on top of whatever this
           product/variant currently costs, so the panel is handed that price
           whenever the selection changes. It only ever DISPLAYS it — the
           authoritative line price is resolved again by api/checkout.php,
           from the database. */
        if (window.VVLPersonalization && window.VVLPersonalization.setBasePrice) {
          window.VVLPersonalization.setBasePrice(effectivePrice);
        }

        if (hasVariants) {
          renderGallery(
            selectedVariant ? selectedVariant.images : [],
            selectedVariant ? variantAltFallback(selectedVariant) : titleText
          );
        }

        if (addBtn) addBtn.disabled = hasVariants && !selectedVariant;
      }

      /* ---------------------------------------------------------------
         Generic option-group rendering: an option's `display_type`
         ("color" or "standard", see MAIN.MD/ProductOptionRepository)
         decides how its values render — circular colour swatches or plain
         buttons — but never which option this is; nothing here checks an
         option name like "Kleur"/"KM". Both variants use real <button>s
         (not a <select>) so the selected value's name is always exposed
         via text/aria-label, never colour alone. */
      function renderOptionGroup(option) {
        var optionValues = Array.isArray(option.values) ? option.values : [];
        var selectedValueId = selectedValues[option.id];
        var isColor = option.display_type === "color";
        var selectedValue = optionValues.find(function (v) {
          return selectedValueId != null && String(selectedValueId) === String(v.id);
        });

        var heading = isColor && selectedValue ?
          S.escapeHtml(option.name) + ": <span data-selected-value-label>" + S.escapeHtml(selectedValue.value) + "</span>" :
          S.escapeHtml(option.name);

        var valuesHtml = optionValues.map(function (value) {
          var isSelected = selectedValueId != null && String(selectedValueId) === String(value.id);
          if (isColor) {
            var swatchColor = value.hex_color || "#888888";
            return (
              '<button type="button" class="product-detail__swatch' + (isSelected ? " is-selected" : "") + '"' +
              ' style="background-color:' + S.escapeAttr(swatchColor) + '"' +
              ' data-variant-option="' + option.id + '" data-value-id="' + value.id + '"' +
              ' title="' + S.escapeAttr(value.value) + '" aria-label="' + S.escapeAttr(value.value) + '"' +
              ' aria-pressed="' + (isSelected ? "true" : "false") + '"></button>'
            );
          }
          return (
            '<button type="button" class="product-detail__option-btn' + (isSelected ? " is-selected" : "") + '"' +
            ' data-variant-option="' + option.id + '" data-value-id="' + value.id + '"' +
            ' aria-pressed="' + (isSelected ? "true" : "false") + '">' + S.escapeHtml(value.value) + "</button>"
          );
        }).join("");

        return (
          '<div class="product-detail__variant-group">' +
          '<label id="variant-option-' + option.id + '-label">' + heading + "</label>" +
          '<div class="' + (isColor ? "product-detail__swatches" : "product-detail__option-buttons") + '" role="group" aria-labelledby="variant-option-' + option.id + '-label">' +
          valuesHtml +
          "</div></div>"
        );
      }

      function renderAllOptionGroups() {
        variantsEl.innerHTML = options.map(renderOptionGroup).join("");

        variantsEl.querySelectorAll("[data-variant-option]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var optionId = btn.getAttribute("data-variant-option");
            selectedValues[optionId] = parseInt(btn.getAttribute("data-value-id"), 10);
            applySelection();
            // Full re-render keeps every group's "is-selected" state and
            // (for colour options) the visible selected-value label in sync
            // — simplest correct option given how small this markup is.
            renderAllOptionGroups();
          });
        });
      }

      if (variantsEl) {
        if (!hasVariants || options.length === 0) {
          variantsEl.hidden = true;
          variantsEl.innerHTML = "";
        } else {
          // Auto-select the default variant (variants[0] — already sorted,
          // active-only) so the page never loads without a valid selection.
          variants[0].values.forEach(function (v) { selectedValues[v.option_id] = v.value_id; });

          variantsEl.hidden = false;
          renderAllOptionGroups();
        }
      }

      applySelection();

      if (addBtn) {
        addBtn.onclick = function () {
          if (hasVariants && !selectedVariant) return;

          /* Personalization, when this product has it. The module is only
             loaded on a product whose CMS configuration offers it (see
             product.php), so on every other product this is simply absent
             and the line below adds nothing at all — the add-to-cart path
             for a normal product is unchanged.

             Its own client-side check runs first purely so the customer gets
             an inline message instead of a rejected checkout later; the
             server re-validates everything regardless. */
          var personalizer = window.VVLPersonalization || null;
          if (personalizer) {
            var personalizationError = personalizer.validate();
            if (personalizationError) {
              personalizer.showError(personalizationError);
              return;
            }
          }
          var personalization = personalizer ? personalizer.getState() : null;

          var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
          var effectivePrice = selectedVariant && selectedVariant.price != null ? selectedVariant.price : product.price;
          var effectiveImage = selectedVariant ?
            (selectedVariant.images[0] ? selectedVariant.images[0].image_path : null) :
            (nonVariantDefaultImage ? nonVariantDefaultImage.image_path : null);
          var variantLabel = selectedVariant ?
            selectedVariant.values.map(function (v) { return v.option_name + ': ' + v.value; }).join(", ") :
            null;
          var cartProduct = {
            id: product.id,
            name: product.name,
            price: effectivePrice,
            image_path: effectiveImage,
            variant_id: selectedVariant ? selectedVariant.id : null,
            variant_label: variantLabel,
            personalization: personalization
          };
          // `price` stays the product's own unit price; the surcharge lives
          // inside `personalization` so the cart can show and total both
          // without ever conflating them.
          var lineId = S.cartAdd(cartProduct, qty);
          document.dispatchEvent(new CustomEvent("vvl-add-to-cart", { detail: { product: cartProduct, qty: qty } }));
          S.flashAddButton(addBtn);
          if (qtyInput) qtyInput.value = 1;

          if (personalizer && personalization) {
            /* Compose the picture the customer just approved and post it,
               without making them wait: the line is already in the cart, and
               the tokens are attached to it when they arrive. */
            if (typeof personalizer.composeAndAttach === "function") {
              personalizer.composeAndAttach(lineId);
            }
            // The draft has become a cart line; keeping it would restore the
            // same personalization again on the next visit to this product.
            if (typeof personalizer.clearDraft === "function") personalizer.clearDraft();
            // A fresh panel for the next unit: leaving the previous text in
            // place makes it far too easy to order the same name twice.
            personalizer.reset();
          }
        };
      }

      if (contentEl) contentEl.hidden = false;
      if (loadingEl) loadingEl.hidden = true;
    }

    var params = new URLSearchParams(window.location.search);
    var idParam = params.get("id");
    var id = idParam != null ? parseInt(idParam, 10) : NaN;

    if (idParam == null || isNaN(id) || id < 1 || String(id) !== idParam.trim()) {
      showError();
      return;
    }

    fetch(S.apiUrl("/api/product.php?id=" + encodeURIComponent(id)))
      .then(function (res) {
        if (!res.ok) throw new Error("Request failed: " + res.status);
        return res.json();
      })
      .then(function (payload) {
        if (!payload || !payload.data) throw new Error("No product data");
        renderProduct(payload.data);
      })
      .catch(showError);
  }

  /* ---------------------------------------------------------------------
     Dutch (BAG/PDOK) address lookup, shared between the shipping and
     billing address sections of checkout.php (each is a `[data-address-
     fields]` block — see initCheckoutPage()). Only ever a UX convenience:
     api/checkout.php independently re-runs the exact same lookup
     server-side (App\Service\Address\CheckoutAddressResolver) before an
     order/payment can be created, so this module deciding "valid" can never
     by itself get an unverified address into an order — it only gates the
     submit button for a better experience and avoids a guaranteed-to-fail
     round trip.

     State machine per section: "idle" (nothing looked up yet, or the last
     lookup was invalidated by an edit) -> "loading" -> "valid" | "invalid".
     Editing postcode/house number/addition, or switching country, always
     invalidates a previous "valid" result and clears the auto-filled
     street/city fields (MAIN.MD "Dutch address validation", scenario 4).
     --------------------------------------------------------------------- */
  function initAddressLookup(root) {
    var noOp = { isValidForSubmit: function () { return true; }, focus: function () {} };
    if (!root) return noOp;

    var countrySelect = root.querySelector("[data-address-country]");
    var postcodeInput = root.querySelector("[data-address-postcode]");
    var houseNumberInput = root.querySelector("[data-address-house-number]");
    var additionInput = root.querySelector("[data-address-house-number-addition]");
    var streetInput = root.querySelector("[data-address-street]");
    var cityInput = root.querySelector("[data-address-city]");
    var statusEl = root.querySelector("[data-address-status]");
    var errorEl = root.querySelector("[data-address-error]");

    if (!postcodeInput || !houseNumberInput || !streetInput || !cityInput) return noOp;

    var state = "idle";
    var lookupToken = 0;
    var debounceTimer = null;

    function isDutch() {
      return !countrySelect || countrySelect.value === "NL";
    }

    function clearMessages() {
      if (statusEl) { statusEl.hidden = true; statusEl.textContent = ""; }
      if (errorEl) { errorEl.classList.remove("is-visible"); errorEl.textContent = ""; }
    }

    function showLoading() {
      clearMessages();
      if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = S.text("address_lookup_busy");
      }
    }

    function showInvalid(reason) {
      clearMessages();
      if (!errorEl) return;
      errorEl.textContent = S.text(reason === "unavailable" ? "address_lookup_unavailable" : "address_lookup_not_found");
      errorEl.classList.add("is-visible");
    }

    function setAutoFilled(isAutoFilled) {
      streetInput.readOnly = isAutoFilled;
      cityInput.readOnly = isAutoFilled;
    }

    function invalidate() {
      if (state === "valid") {
        streetInput.value = "";
        cityInput.value = "";
      }
      setAutoFilled(false);
      state = "idle";
      clearMessages();
      lookupToken++; // ignore any in-flight response for the address that's no longer current
    }

    function runLookup() {
      var postcode = postcodeInput.value.trim();
      var huisnummer = houseNumberInput.value.trim();

      if (!/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/.test(postcode) || !/^[0-9]{1,5}$/.test(huisnummer)) {
        state = "idle";
        clearMessages();
        return;
      }

      var token = ++lookupToken;
      state = "loading";
      showLoading();

      fetch("/api/address-lookup-nl.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          postcode: postcode,
          huisnummer: huisnummer,
          huisnummer_toevoeging: additionInput ? additionInput.value.trim() : ""
        })
      })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          if (token !== lookupToken) return; // superseded by a newer edit/lookup
          if (result.ok && result.data && result.data.data) {
            streetInput.value = result.data.data.street;
            cityInput.value = result.data.data.city;
            setAutoFilled(true);
            state = "valid";
            clearMessages();
          } else {
            setAutoFilled(false);
            state = "invalid";
            showInvalid(result.data && result.data.reason);
          }
        })
        .catch(function () {
          if (token !== lookupToken) return;
          setAutoFilled(false);
          state = "invalid";
          showInvalid("unavailable");
        });
    }

    function scheduleLookup() {
      if (debounceTimer) clearTimeout(debounceTimer);
      debounceTimer = setTimeout(runLookup, 500);
    }

    [postcodeInput, houseNumberInput, additionInput].forEach(function (input) {
      if (!input) return;
      input.addEventListener("input", function () {
        invalidate();
        if (isDutch()) scheduleLookup();
      });
      input.addEventListener("blur", function () {
        if (!isDutch()) return;
        if (debounceTimer) clearTimeout(debounceTimer);
        runLookup();
      });
    });

    if (countrySelect) {
      countrySelect.addEventListener("change", function () {
        invalidate();
        if (isDutch()) scheduleLookup();
      });
    }

    return {
      isValidForSubmit: function () {
        return !isDutch() || state === "valid";
      },
      focus: function () { postcodeInput.focus(); }
    };
  }

  /* ---------------------------------------------------------------------
     Checkout page (checkout.php): renders the order summary from the
     cart, and on submit posts the form + cart to POST /api/checkout.php.
     The backend re-loads every price from the database and creates the
     Mollie payment — this page only ever displays what the backend
     confirms, it never sends a total. On success it redirects to Mollie's
     hosted checkout; the cart itself is only cleared later, on the return
     page, once payment is confirmed (see initOrderStatusPage()).
     --------------------------------------------------------------------- */
  function initCheckoutPage() {
    var form = document.querySelector("[data-checkout-form]");
    if (!form) return;

    var emptyEl = document.querySelector("[data-checkout-empty]");
    var itemsEl = form.querySelector("[data-checkout-items]");
    var subtotalEl = form.querySelector("[data-checkout-subtotal]");
    var shippingEl = form.querySelector("[data-checkout-shipping]");
    var shippingMethodEl = form.querySelector("[data-checkout-shipping-method]");
    var shippingOptionPriceEl = form.querySelector("[data-checkout-shipping-option-price]");
    var totalEl = form.querySelector("[data-checkout-total]");
    var errorEl = form.querySelector("[data-checkout-error]");
    var submitBtn = form.querySelector("[data-checkout-submit]");
    var submitLabelEl = form.querySelector("[data-checkout-submit-label]");
    var overlayEl = document.querySelector("[data-checkout-overlay]");
    var landFieldEl = form.querySelector("[data-checkout-land-field]");
    var landSelect = form.querySelector("#land");
    var termsCheckbox = form.querySelector("#terms_accepted");
    var termsErrorEl = form.querySelector("[data-checkout-terms-error]");
    var turnstileContainer = form.querySelector("[data-checkout-turnstile]");
    var turnstileErrorEl = form.querySelector("[data-checkout-turnstile-error]");
    var billingSameCheckbox = form.querySelector("#facturatie_zelfde");
    var billingFieldsEl = form.querySelector("[data-billing-fields]");

    var shippingAddressLookup = initAddressLookup(form.querySelector("[data-address-fields]:not([data-billing-fields])"));
    var billingAddressLookup = initAddressLookup(billingFieldsEl);

    function isBillingSameAsShipping() {
      return !billingSameCheckbox || billingSameCheckbox.checked;
    }

    function updateBillingVisibility() {
      if (billingFieldsEl) billingFieldsEl.hidden = isBillingSameAsShipping();
    }

    // Set only via Turnstile's own callback once a real challenge response
    // comes back — never assumed, never derived client-side. The server
    // re-verifies this token with Cloudflare before creating the order/
    // payment regardless (see api/checkout.php + App\Service\
    // TurnstileVerifier); this is only what lets the frontend show a useful
    // inline error instead of a submit that's certain to be rejected.
    var turnstileToken = null;
    var turnstileWidgetId = null;

    function clearTermsError() {
      if (termsErrorEl) termsErrorEl.classList.remove("is-visible");
      if (termsCheckbox) termsCheckbox.removeAttribute("aria-invalid");
    }

    function showTermsError() {
      if (!termsErrorEl) return;
      termsErrorEl.textContent = S.text("terms_required");
      termsErrorEl.classList.add("is-visible");
      if (termsCheckbox) termsCheckbox.setAttribute("aria-invalid", "true");
    }

    function clearTurnstileError() {
      if (turnstileErrorEl) turnstileErrorEl.classList.remove("is-visible");
    }

    function showTurnstileError(message) {
      if (!turnstileErrorEl) return;
      turnstileErrorEl.textContent = message || S.text("security_check_failed");
      turnstileErrorEl.classList.add("is-visible");
    }

    function renderTurnstile() {
      if (!turnstileContainer || turnstileWidgetId !== null) return;
      if (typeof window.turnstile === "undefined") return;
      var sitekey = turnstileContainer.getAttribute("data-sitekey");
      if (!sitekey) return;

      turnstileWidgetId = window.turnstile.render(turnstileContainer, {
        sitekey: sitekey,
        callback: function (token) {
          turnstileToken = token;
          clearTurnstileError();
        },
        "expired-callback": function () {
          turnstileToken = null;
          showTurnstileError(S.text("security_check_expired"));
        },
        "error-callback": function () {
          turnstileToken = null;
          showTurnstileError(null);
        },
        "timeout-callback": function () {
          turnstileToken = null;
        }
      });
    }

    // Covers both possible load orders: Turnstile's script may finish
    // loading (and call window.vvlOnTurnstileLoad) before or after this
    // function runs on DOMContentLoaded.
    turnstileReadyCallback = renderTurnstile;
    if (turnstileApiReady) renderTurnstile();

    // Server-calculated shipping for the current cart/method/country — never
    // computed client-side. null while loading/unavailable/not applicable
    // yet; checkout can't be submitted until this holds a real quote (pickup
    // sets it synchronously to €0, "verzenden" waits for
    // /api/shipping-quote.php). The server still recalculates everything
    // from scratch on submit — this is a preview only.
    var currentShipping = null;
    var quoteToken = 0;
    var quoteDebounce = null;

    function isPickupSelected() {
      var checked = form.querySelector('input[name="verzendmethode"]:checked');
      return !checked || checked.value === "afhalen";
    }

    function loadShippingCountries() {
      if (!landSelect) return;
      fetch(S.apiUrl("/api/shipping-zones.php"))
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (!data || !Array.isArray(data.countries) || !data.countries.length) return;
          var previous = landSelect.value;
          landSelect.innerHTML = data.countries.map(function (country) {
            return '<option value="' + S.escapeAttr(country.code) + '">' + S.escapeHtml(country.label) + "</option>";
          }).join("");
          var stillExists = data.countries.some(function (c) { return c.code === previous; });
          landSelect.value = stillExists ? previous : data.countries[0].code;
        })
        .catch(function () { /* keep the static NL/BE fallback options */ });
    }

    function updateLandFieldVisibility() {
      var pickup = isPickupSelected();
      if (landFieldEl) landFieldEl.hidden = pickup;
      if (landSelect) landSelect.required = !pickup;
    }

    function showShippingError(message) {
      if (shippingOptionPriceEl) shippingOptionPriceEl.textContent = "—";
      showError(message || S.text("no_shipping_method"));
    }

    function requestShippingQuote() {
      var items = S.readCart();
      if (!items.length) return;

      var pickup = isPickupSelected();
      var land = landSelect ? landSelect.value : "";

      if (pickup) {
        currentShipping = { shipping_cost: 0, shipping_method: "afhalen", shipping_method_label: S.text("pickup") };
        clearError();
        renderSummary();
        return;
      }

      if (!land) {
        currentShipping = null;
        renderSummary();
        return;
      }

      var token = ++quoteToken;
      if (shippingOptionPriceEl) shippingOptionPriceEl.textContent = "…";

      fetch(S.apiUrl("/api/shipping-quote.php"), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          verzendmethode: "verzenden",
          land: land,
          items: items.map(function (item) { return { id: item.id, qty: item.qty }; })
        })
      })
        .then(function (res) {
          return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (result) {
          if (token !== quoteToken) return; // a newer request has already superseded this one
          if (!result.ok || !result.data || typeof result.data.shipping_cost !== "number") {
            currentShipping = null;
            showShippingError(result.data && result.data.error);
            renderSummary();
            return;
          }
          currentShipping = result.data;
          clearError();
          renderSummary();
        })
        .catch(function () {
          if (token !== quoteToken) return;
          currentShipping = null;
          showShippingError(null);
          renderSummary();
        });
    }

    function scheduleShippingQuote() {
      if (quoteDebounce) clearTimeout(quoteDebounce);
      quoteDebounce = setTimeout(requestShippingQuote, 250);
    }

    function renderSummary() {
      var items = S.readCart();

      if (!items.length) {
        form.hidden = true;
        if (emptyEl) emptyEl.hidden = false;
        return;
      }
      form.hidden = false;
      if (emptyEl) emptyEl.hidden = true;

      if (itemsEl) {
        itemsEl.innerHTML = items.map(function (item) {
          var variantLine = item.variant_label ? "<span>" + S.escapeHtml(item.variant_label) + "</span>" : "";
          return (
            '<div class="checkout-summary-item">' +
            '<div class="cart-row__media">' + S.cartItemMedia(item) + "</div>" +
            '<div class="checkout-summary-item__info">' +
            "<strong>" + S.escapeHtml(item.name) + "</strong>" +
            variantLine +
            S.cartPersonalizationHtml(item, "cart-personalization--compact") +
            "<span>" + item.qty + "x</span>" +
            "</div>" +
            '<div class="checkout-summary-item__price">' + S.formatPrice(S.cartLineCents(item) / 100) + "</div>" +
            "</div>"
          );
        }).join("");
      }

      var subtotal = S.cartSubtotal(items);
      var shipping = currentShipping ? currentShipping.shipping_cost : 0;
      var total = subtotal + shipping;

      if (subtotalEl) subtotalEl.innerHTML = S.formatPrice(subtotal);
      if (shippingEl) {
        shippingEl.innerHTML = currentShipping ?
          (shipping > 0 ? S.formatPrice(shipping) : S.escapeHtml(S.text("free"))) :
          "&hellip;";
      }
      if (shippingMethodEl) {
        shippingMethodEl.textContent = currentShipping && currentShipping.shipping_method !== "afhalen" ?
          " (" + currentShipping.shipping_method_label + ")" : "";
      }
      if (shippingOptionPriceEl && !isPickupSelected()) {
        shippingOptionPriceEl.innerHTML = currentShipping ? S.formatPrice(currentShipping.shipping_cost) : "&hellip;";
      }
      if (totalEl) totalEl.innerHTML = S.formatPrice(total);
    }

    function showError(message) {
      if (!errorEl) return;
      errorEl.textContent = message || S.text("order_failed");
      errorEl.classList.add("is-visible");
    }

    function clearError() {
      if (errorEl) errorEl.classList.remove("is-visible");
    }

    function setSubmitting(isSubmitting) {
      if (submitBtn) submitBtn.disabled = isSubmitting;
      if (submitLabelEl) {
        submitLabelEl.textContent = isSubmitting ?
          S.text("processing") :
          S.text("place_order");
      }
      if (overlayEl) overlayEl.hidden = !isSubmitting;
    }

    // Cloudflare tokens are single-use — after any rejected/failed submit
    // attempt the customer needs a fresh challenge response before they can
    // try again, never a reused/stale token.
    function resetTurnstile() {
      turnstileToken = null;
      if (turnstileWidgetId !== null && window.turnstile) {
        window.turnstile.reset(turnstileWidgetId);
      }
    }

    loadShippingCountries();
    updateLandFieldVisibility();
    updateBillingVisibility();
    requestShippingQuote();
    renderSummary();

    document.addEventListener("vvl-cart-change", function () {
      renderSummary();
      scheduleShippingQuote();
    });

    form.addEventListener("change", function (e) {
      if (e.target.name === "verzendmethode") {
        updateLandFieldVisibility();
        requestShippingQuote();
      } else if (e.target === landSelect) {
        scheduleShippingQuote();
      } else if (e.target === termsCheckbox && termsCheckbox.checked) {
        clearTermsError();
      } else if (e.target === billingSameCheckbox) {
        updateBillingVisibility();
      }
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      clearError();

      var items = S.readCart();
      if (!items.length) { renderSummary(); return; }

      // Terms & Conditions and Turnstile are checked explicitly (not just via
      // the checkbox's native "required") so each gets its own clean inline
      // message instead of a generic browser validation bubble — this is a
      // convenience only: the server independently re-checks both and is the
      // authoritative gate (see api/checkout.php), so neither check here can
      // be bypassed by disabling/patching this script.
      var termsAccepted = !!(termsCheckbox && termsCheckbox.checked);
      if (!termsAccepted) {
        showTermsError();
        if (termsCheckbox) termsCheckbox.focus();
        return;
      }
      clearTermsError();

      if (!turnstileToken) {
        showTurnstileError(null);
        return;
      }
      clearTurnstileError();

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      // The Dutch BAG/PDOK lookup gates submission the same way terms/
      // Turnstile do above — a UX convenience only, since api/checkout.php
      // independently re-verifies both addresses server-side regardless (see
      // initAddressLookup() above and MAIN.MD "Dutch address validation").
      if (!shippingAddressLookup.isValidForSubmit()) {
        showError(S.text("shipping_address_invalid"));
        shippingAddressLookup.focus();
        return;
      }

      var billingSame = isBillingSameAsShipping();
      if (!billingSame) {
        var billingRequiredFields = [
          form.facturatie_voornaam, form.facturatie_achternaam, form.facturatie_postcode,
          form.facturatie_huisnummer, form.facturatie_straat, form.facturatie_plaats
        ];
        for (var bi = 0; bi < billingRequiredFields.length; bi++) {
          if (!billingRequiredFields[bi] || !billingRequiredFields[bi].value.trim()) {
            showError(S.text("billing_address_required"));
            billingRequiredFields[bi].focus();
            return;
          }
        }
        if (!billingAddressLookup.isValidForSubmit()) {
          showError(S.text("billing_address_invalid"));
          billingAddressLookup.focus();
          return;
        }
      }

      if (!currentShipping) {
        showShippingError(null);
        return;
      }

      var payload = {
        voornaam: form.voornaam.value.trim(),
        achternaam: form.achternaam.value.trim(),
        email: form.email.value.trim(),
        telefoon: form.telefoon.value.trim(),
        bedrijf: form.bedrijf ? form.bedrijf.value.trim() : "",
        land: landSelect ? landSelect.value : "",
        postcode: form.postcode.value.trim(),
        huisnummer: form.huisnummer.value.trim(),
        huisnummer_toevoeging: form.huisnummer_toevoeging ? form.huisnummer_toevoeging.value.trim() : "",
        straat: form.straat.value.trim(),
        plaats: form.plaats.value.trim(),
        verzendmethode: (form.querySelector('input[name="verzendmethode"]:checked') || {}).value,
        betaalmethode: (form.querySelector('input[name="betaalmethode"]:checked') || {}).value,
        /* Only the identifiers and the personalization travel: never a price,
           never a name, never an image path. api/checkout.php re-reads the
           price from the database and re-validates the personalization
           against the product's live configuration, so this payload can only
           ever say WHAT was ordered, never what it costs or what is allowed.
           `upload_token` is the customer's own upload handle; the file it
           points at is on the server already. Note what is NOT sent: no
           label, no surcharge, no view — the server derives all of that from
           the product's own configuration. */
        items: items.map(function (item) {
          var line = { id: item.id, qty: item.qty, variant_id: item.variant_id || null };
          var zones = S.cartPersonalizationZones(item);
          if (zones.length) {
            line.personalization = {
              zones: zones.map(function (zone) {
                return {
                  zone_key: zone.zone_key,
                  text: zone.text || "",
                  font: zone.font || null,
                  color: zone.color || null,
                  upload_token: zone.upload_token || null,
                  transform: zone.transform || null
                };
              })
            };
            /* The composed previews, keyed by view. Handles only — the files
               are on the server already, and the server re-checks that each
               one was posted for THIS product and this view before claiming
               it. A line without them orders exactly as before. */
            if (item.preview_tokens) line.preview_tokens = item.preview_tokens;
          }
          return line;
        }),
        terms_accepted: termsAccepted,
        turnstile_token: turnstileToken,
        facturatie_zelfde: billingSame,
        /* The language this checkout is happening in. /api/checkout.php has
           no URL of its own to read it from (it is reached by fetch), so it
           travels with the order and the server validates it against the
           website language registry before it decides anything — see
           docs/multilingual/ROUTING.md. It only ever picks which of this
           site's own order pages the customer returns to. */
        language: document.documentElement.lang || ""
      };

      if (!billingSame) {
        payload.facturatie_land = form.facturatie_land.value;
        payload.facturatie_voornaam = form.facturatie_voornaam.value.trim();
        payload.facturatie_achternaam = form.facturatie_achternaam.value.trim();
        payload.facturatie_bedrijf = form.facturatie_bedrijf.value.trim();
        payload.facturatie_postcode = form.facturatie_postcode.value.trim();
        payload.facturatie_huisnummer = form.facturatie_huisnummer.value.trim();
        payload.facturatie_huisnummer_toevoeging = form.facturatie_huisnummer_toevoeging.value.trim();
        payload.facturatie_straat = form.facturatie_straat.value.trim();
        payload.facturatie_plaats = form.facturatie_plaats.value.trim();
      }

      setSubmitting(true);

      fetch("/api/checkout.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (res) {
          return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (result) {
          if (!result.ok || !result.data || !result.data.checkoutUrl) {
            showError(result.data && result.data.error);
            setSubmitting(false);
            resetTurnstile();
            return;
          }
          window.location.href = result.data.checkoutUrl;
        })
        .catch(function () {
          showError(null);
          setSubmitting(false);
          resetTurnstile();
        });
    });
  }

  /* ---------------------------------------------------------------------
     Order status / return page (bestelling-status.php?order=…): after
     Mollie's checkout, shows what the backend confirms for this order.
     The cart is only cleared once the backend reports the order as
     actually paid — never just because the customer landed on this page.
     --------------------------------------------------------------------- */
  function initOrderStatusPage() {
    var root = document.querySelector("[data-order-status]");
    if (!root) return;

    var loadingEl = document.querySelector("[data-order-status-loading]");
    var errorEl = document.querySelector("[data-order-status-error]");
    var contentEl = document.querySelector("[data-order-status-content]");
    var stateEls = {
      paid: document.querySelector("[data-order-status-paid]"),
      pending: document.querySelector("[data-order-status-pending]"),
      failed: document.querySelector("[data-order-status-failed]")
    };

    function showError() {
      if (loadingEl) loadingEl.hidden = true;
      if (contentEl) contentEl.hidden = true;
      if (errorEl) errorEl.hidden = false;
    }

    function renderOrder(order) {
      if (loadingEl) loadingEl.hidden = true;
      if (contentEl) contentEl.hidden = false;

      var orderIdEl = document.querySelector("[data-order-status-id]");
      if (orderIdEl) orderIdEl.textContent = order.order_number || "#" + order.order_id;

      var nameEl = document.querySelector("[data-order-status-name]");
      if (nameEl) nameEl.textContent = order.customer_first_name || "";

      var totalEl = document.querySelector("[data-order-status-total]");
      if (totalEl) totalEl.innerHTML = S.formatPrice(order.total);

      var shippingEl = document.querySelector("[data-order-status-shipping]");
      if (shippingEl) shippingEl.innerHTML = S.formatPrice(order.shipping_cost);

      var confirmationEl = document.querySelector("[data-order-status-confirmation]");
      if (confirmationEl) confirmationEl.hidden = order.status !== "paid" || !order.confirmation_sent;

      var itemsEl = document.querySelector("[data-order-status-items]");
      if (itemsEl) {
        itemsEl.innerHTML = order.items.map(function (item) {
          var variantLine = item.variant_label ? "<span>" + S.escapeHtml(item.variant_label) + "</span>" : "";
          return (
            '<div class="checkout-summary-item">' +
            '<div class="cart-row__media">' + S.cartItemMedia(item) + "</div>" +
            '<div class="checkout-summary-item__info">' +
            "<strong>" + S.escapeHtml(item.name) + "</strong>" +
            variantLine +
            "<span>" + item.quantity + "x</span>" +
            "</div>" +
            '<div class="checkout-summary-item__price">' + S.formatPrice(item.unit_price * item.quantity) + "</div>" +
            "</div>"
          );
        }).join("");
      }

      var group = (order.status === "paid") ? "paid" :
        (order.status === "failed" || order.status === "canceled" || order.status === "expired") ? "failed" : "pending";

      Object.keys(stateEls).forEach(function (key) {
        if (stateEls[key]) stateEls[key].hidden = key !== group;
      });

      // Only ever clear the cart once the backend confirms the order is actually paid.
      if (order.status === "paid") {
        S.writeCart([]);
      }
    }

    var params = new URLSearchParams(window.location.search);
    var idParam = params.get("order");
    var orderId = idParam != null ? parseInt(idParam, 10) : NaN;

    if (idParam == null || isNaN(orderId) || orderId < 1 || String(orderId) !== idParam.trim()) {
      showError();
      return;
    }

    fetch(S.apiUrl("/api/order-status.php?order=" + encodeURIComponent(orderId)))
      .then(function (res) {
        if (!res.ok) throw new Error("Request failed: " + res.status);
        return res.json();
      })
      .then(function (payload) {
        if (!payload || !payload.data) throw new Error("No order data");
        renderOrder(payload.data);
      })
      .catch(showError);
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    S = (window.VVLCart && window.VVLCart.internal) || null;
    if (!S) return; // assets/js/shop/cart.js is missing — nothing here can run

    initShopProducts();
    initProductDetail();
    initCheckoutPage();
    initOrderStatusPage();
  });
})();
