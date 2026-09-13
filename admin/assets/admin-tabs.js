/**
 * Tabs for a long admin screen (admin/_admin_tabs.php).
 *
 * WHAT IT DOES. Shows one group of panels at a time and remembers which one.
 * It owns no content, submits nothing and knows nothing about any particular
 * screen: everything it needs is in the markup the PHP helper printed —
 * [data-admin-tabs] around the group, [data-admin-tab] on a tab button,
 * [data-admin-tab-panel] on each panel.
 *
 * WHY IT NEVER MARKS A FORM DIRTY. Switching a tab toggles the `hidden`
 * attribute and some aria state. It never touches a field's value, so no
 * "input" or "change" event is fired and the save bar
 * (admin/assets/save-bar.js) sees nothing at all. A form may span two panels;
 * hidden fields are still submitted with it, exactly as the browser always
 * submits a field that is out of view.
 *
 * REVEALING SOMETHING IN A CLOSED TAB. Three ways in, all of which end at
 * reveal():
 *
 *   - the address bar. #some-id on load opens whichever tab contains that
 *     element, so every existing anchor on these screens keeps working;
 *   - constraint validation. A required field the browser rejects fires
 *     "invalid" even when it is inside a hidden panel — where the browser
 *     could not focus it and the submit would fail with nothing on screen to
 *     explain why. Opening its tab from that event happens before the browser
 *     tries to focus it, so the editor lands on the field;
 *   - window.AdminTabs.reveal(element), used by admin-collapse.js to bring
 *     the block an editor was working on back into view after a reload.
 */
(function () {
  "use strict";

  var STORE_PREFIX = "mygdalaAdminTab:";

  function readStore(key) {
    try {
      return window.sessionStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function writeStore(key, value) {
    try {
      window.sessionStorage.setItem(key, value);
    } catch (e) {}
  }

  /** The remembered-tab key: per group AND per scope, so one page's editor never restores another's tab. */
  function storageKey(root) {
    return (
      STORE_PREFIX +
      (root.getAttribute("data-admin-tabs") || "") +
      ":" +
      (root.getAttribute("data-admin-tabs-scope") || "")
    );
  }

  function ownedBy(root, selector) {
    return Array.prototype.filter.call(root.querySelectorAll(selector), function (element) {
      return element.closest("[data-admin-tabs]") === root;
    });
  }

  function tabsOf(root) {
    return ownedBy(root, "[data-admin-tab]");
  }

  function panelsOf(root) {
    return ownedBy(root, "[data-admin-tab-panel]");
  }

  function activate(root, key, options) {
    var settings = options || {};
    var found = false;

    tabsOf(root).forEach(function (tab) {
      var isActive = tab.getAttribute("data-admin-tab") === key;
      if (isActive) found = true;

      tab.setAttribute("aria-selected", isActive ? "true" : "false");
      tab.setAttribute("tabindex", isActive ? "0" : "-1");
      tab.classList.toggle("is-active", isActive);

      if (isActive && settings.focus) tab.focus();
    });

    if (!found) return false;

    panelsOf(root).forEach(function (panel) {
      panel.hidden = panel.getAttribute("data-admin-tab-panel") !== key;
    });

    if (settings.store !== false) writeStore(storageKey(root), key);

    return true;
  }

  function activeKey(root) {
    var selected = tabsOf(root).filter(function (tab) {
      return tab.getAttribute("aria-selected") === "true";
    })[0];

    return selected ? selected.getAttribute("data-admin-tab") : null;
  }

  /**
   * Open whichever tab contains this element, in every tab group it sits
   * inside — a panel nested in another group's panel is opened too.
   */
  function reveal(element) {
    if (!element || !element.closest) return false;

    var opened = false;
    var panel = element.closest("[data-admin-tab-panel]");

    while (panel) {
      var root = panel.closest("[data-admin-tabs]");
      if (!root) break;

      var key = panel.getAttribute("data-admin-tab-panel");
      if (key !== activeKey(root)) opened = activate(root, key) || opened;

      panel = root.parentElement ? root.parentElement.closest("[data-admin-tab-panel]") : null;
    }

    return opened;
  }

  var KEYS = {
    ArrowLeft: -1,
    ArrowRight: 1,
    Left: -1,
    Right: 1,
  };

  function initRoot(root) {
    var tabs = tabsOf(root);
    if (tabs.length === 0) return;

    root.classList.add("is-enhanced");

    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        activate(root, tab.getAttribute("data-admin-tab"));
      });

      /**
       * The keyboard part of the tabs pattern: arrows move between tabs,
       * Home/End jump to the ends, and moving the selection opens that tab —
       * so a keyboard user needs no second key to see the panel.
       */
      tab.addEventListener("keydown", function (event) {
        var step = KEYS[event.key];
        var index = tabs.indexOf(tab);
        var next = null;

        if (step) {
          next = tabs[(index + step + tabs.length) % tabs.length];
        } else if (event.key === "Home") {
          next = tabs[0];
        } else if (event.key === "End") {
          next = tabs[tabs.length - 1];
        }

        if (!next) return;

        event.preventDefault();
        activate(root, next.getAttribute("data-admin-tab"), { focus: true });
      });
    });

    /**
     * Which tab opens now, most specific first:
     *
     *   1. the screen insisted (a failed save whose errors live on one tab);
     *   2. the address names something inside a panel;
     *   3. what this editor last had open on THIS screen;
     *   4. the screen's own default.
     */
    var forced = root.getAttribute("data-admin-tabs-force");
    if (forced && activate(root, forced, { store: false })) return;

    var hash = window.location.hash ? window.location.hash.slice(1) : "";
    var target = hash ? document.getElementById(hash) : null;
    if (target && root.contains(target) && reveal(target)) return;

    var remembered = readStore(storageKey(root));
    if (remembered && activate(root, remembered, { store: false })) return;

    activate(root, root.getAttribute("data-admin-tabs-default") || tabs[0].getAttribute("data-admin-tab"), {
      store: false,
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-admin-tabs]"), initRoot);

  // A field the browser refuses inside a closed tab: open the tab first, so
  // the browser can focus it and the editor can see what is wrong.
  document.addEventListener(
    "invalid",
    function (event) {
      reveal(event.target);
    },
    true
  );

  window.AdminTabs = { reveal: reveal, activate: activate };
})();
