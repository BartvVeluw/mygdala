/* =========================================================================
   Van Veluw Laserdesign — cookie consent engine
   Loaded in <head>, before every other script, on every public page (see
   partials/header.php). Vanilla JS, no dependencies, no build step —
   matches assets/js/core.js's style.

   Responsibilities:
   - Read/write the stored consent decision (localStorage key below).
   - Show/hide + wire up the banner and preferences modal markup that
     partials/cookie-consent.php renders (once, reused site-wide via
     partials/footer.php).
   - Expose window.VVLConsent as the integration point for any *future*
     analytics/marketing script: register it under a category and it will
     run immediately if consent is already granted, or automatically the
     moment the visitor grants it — and never before, never without it.

   Category keys + the consent-version number are NOT hardcoded here: they
   come from window.VVL_CONSENT_CONFIG, a tiny inline JSON blob each page
   prints from App\Service\CookieConsentConfig::jsConfig() right before this
   script tag. That keeps categories/version centralized in one PHP file
   (src/Service/CookieConsentConfig.php) instead of duplicated per language.
   Consent-checking (hasConsent/registerScript) must work the instant this
   file parses — a future tracker script placed right after this one in
   <head> may call registerScript() before the DOM body even exists — so it
   deliberately never touches the DOM until DOMContentLoaded.
   ========================================================================= */
(function () {
  "use strict";

  var STORAGE_KEY = "vvl_cookie_consent";
  var CATEGORY_NECESSARY = "necessary";

  var CONFIG = window.VVL_CONSENT_CONFIG || { version: 1, categories: [CATEGORY_NECESSARY] };

  /* ---------------------------------------------------------------------
     Stored consent — read/write, independent of any DOM markup.
     Shape: { version, categories: { necessary:true, analytics:false, ... }, decidedAt }
     --------------------------------------------------------------------- */
  function readStored() {
    var raw;
    try { raw = localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
    if (!raw) return null;
    try {
      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== "object" || !parsed.categories) return null;
      return parsed;
    } catch (e) {
      return null;
    }
  }

  function writeStored(categories) {
    var state = {
      version: CONFIG.version,
      categories: categories,
      decidedAt: new Date().toISOString()
    };
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    return state;
  }

  /**
   * null = no decision yet for the current CONFIG.version (first visit, or
   * the policy/categories changed since the visitor last decided).
   */
  function currentCategories() {
    var stored = readStored();
    if (!stored || stored.version !== CONFIG.version) return null;
    return stored.categories;
  }

  function isDecided() {
    return currentCategories() !== null;
  }

  function hasConsent(category) {
    if (category === CATEGORY_NECESSARY) return true;
    var cats = currentCategories();
    return !!(cats && cats[category]);
  }

  /* ---------------------------------------------------------------------
     Registry for future analytics/marketing scripts. A script registers
     once; it is run at most once, exactly when consent for its category is
     (or becomes) granted — never before, and never again after that.
     --------------------------------------------------------------------- */
  var registered = []; // { category, key, loader }
  var alreadyRun = {};

  function runIfConsented(item) {
    if (alreadyRun[item.key]) return;
    if (!hasConsent(item.category)) return;
    alreadyRun[item.key] = true;
    try { item.loader(); } catch (e) { if (window.console) console.error("[cookie-consent] script '" + item.key + "' failed:", e); }
  }

  function registerScript(category, key, loader) {
    if (CONFIG.categories.indexOf(category) === -1) {
      if (window.console) console.error("[cookie-consent] unknown category '" + category + "' for script '" + key + "' — add it to CookieConsentConfig::CATEGORIES first.");
      return;
    }
    var item = { category: category, key: key, loader: loader };
    registered.push(item);
    runIfConsented(item);
  }

  function runAllPending() {
    registered.forEach(runIfConsented);
  }

  /* ---------------------------------------------------------------------
     Banner + preferences modal — wired up once the DOM (footer partial) is
     available. Everything below only runs after DOMContentLoaded.
     --------------------------------------------------------------------- */
  var root, banner, modal, dialog, backdrop, toggles = [];
  var lastFocused = null;

  function categoryKeysInDom() {
    return toggles.map(function (t) { return t.getAttribute("data-consent-category"); });
  }

  function buildCategories(mode) {
    var result = {};
    result[CATEGORY_NECESSARY] = true;
    categoryKeysInDom().forEach(function (key) {
      if (key === CATEGORY_NECESSARY) return;
      if (mode === "all") result[key] = true;
      else if (mode === "reject") result[key] = false;
      else result[key] = undefined; // filled from toggle state by caller
    });
    return result;
  }

  function buildCategoriesFromToggles() {
    var result = buildCategories("reject");
    toggles.forEach(function (t) {
      var key = t.getAttribute("data-consent-category");
      if (key === CATEGORY_NECESSARY) return;
      result[key] = t.checked;
    });
    return result;
  }

  function syncTogglesFromState() {
    var cats = currentCategories() || {};
    toggles.forEach(function (t) {
      var key = t.getAttribute("data-consent-category");
      if (t.disabled) { t.checked = true; return; }
      t.checked = !!cats[key];
    });
  }

  function updateRootVisibility() {
    if (root) root.hidden = banner.hidden && modal.hidden;
  }

  function showBanner() {
    if (root) root.hidden = false;
    banner.hidden = false;
  }

  function hideBanner() {
    banner.hidden = true;
    updateRootVisibility();
  }

  function decide(categories) {
    var state = writeStored(categories);
    hideBanner();
    closeModal();
    runAllPending();
    document.dispatchEvent(new CustomEvent("vvl-consent-change", { detail: state }));
  }

  function trapFocus(e) {
    var focusables = dialog.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!focusables.length) return;
    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function onModalKeydown(e) {
    if (e.key === "Escape") { closeModal(); return; }
    if (e.key === "Tab") trapFocus(e);
  }

  function openModal() {
    lastFocused = document.activeElement;
    syncTogglesFromState();
    if (root) root.hidden = false;
    modal.hidden = false;
    document.body.style.overflow = "hidden";
    document.addEventListener("keydown", onModalKeydown);
    dialog.focus();
  }

  function closeModal() {
    if (modal.hidden) return;
    modal.hidden = true;
    document.body.style.overflow = "";
    document.removeEventListener("keydown", onModalKeydown);
    updateRootVisibility();
    if (lastFocused && typeof lastFocused.focus === "function") lastFocused.focus();
    /* No decision was made by simply closing — if the visitor still hasn't
       decided (first visit), the banner keeps asking. Rejecting is exactly
       as available as accepting; closing the panel is neither. */
    if (!isDecided()) showBanner();
  }

  function initConsentUi() {
    root = document.querySelector("[data-cookie-consent]");
    if (!root) return;
    banner = root.querySelector("[data-cookie-banner]");
    modal = root.querySelector("[data-cookie-modal]");
    dialog = root.querySelector(".cookie-modal__dialog");
    backdrop = root.querySelector("[data-cookie-modal-backdrop]");
    toggles = Array.prototype.slice.call(root.querySelectorAll("[data-consent-category]"));

    root.addEventListener("click", function (e) {
      var actionEl = e.target.closest("[data-cookie-action]");
      if (!actionEl) return;
      var action = actionEl.getAttribute("data-cookie-action");
      if (action === "accept-all" || action === "accept-all-modal") {
        decide(buildCategories("all"));
      } else if (action === "reject") {
        decide(buildCategories("reject"));
      } else if (action === "manage") {
        openModal();
      } else if (action === "save") {
        decide(buildCategoriesFromToggles());
      } else if (action === "close-modal") {
        closeModal();
      }
    });

    if (backdrop) backdrop.addEventListener("click", closeModal);

    document.querySelectorAll("[data-cookie-settings-open]").forEach(function (el) {
      el.addEventListener("click", function (e) {
        e.preventDefault();
        openModal();
      });
    });

    if (!isDecided()) {
      showBanner();
    } else {
      updateRootVisibility();
    }
  }

  document.addEventListener("DOMContentLoaded", initConsentUi);

  /* Run once at parse time too: consent already granted from a previous
     visit means a registered script should not wait for DOMContentLoaded. */
  runAllPending();

  window.VVLConsent = {
    hasConsent: hasConsent,
    isDecided: isDecided,
    registerScript: registerScript,
    openPreferences: function () { if (root) openModal(); },
    getCategories: function () { return currentCategories() || {}; }
  };
})();
