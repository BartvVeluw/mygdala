/**
 * The admin's shared UI behaviour (admin/_admin_ui.php, ADMIN-UI.md):
 *
 *   - field help: hovering a "?" shows its explanation, a click (Enter, a tap)
 *     pins it, and the cross, Escape or a click elsewhere closes it;
 *   - the global help switch in the shell, remembered per browser;
 *   - the line in a file input that names the chosen file.
 *
 * Loaded by admin/_header.php at the very top of <body>, without `defer`.
 * The first thing it does is put the stored preference on <html>, so a screen
 * with help switched off never paints its help first. Everything after that is
 * one listener per event type on `document`: no listener per icon, no work
 * until something happens, and a component added to the page later works
 * without being initialised.
 *
 * NOT in this file: a single word an editor reads. Explanations, button names
 * and the file-input wording are CMS text the server renders from the catalog;
 * this script only shows, hides and places them. Nothing here decides what a
 * form submits either — every control stays a native element.
 *
 * Without this script the page still works: a "?" opens its explanation
 * through the browser's own popover, a file input is the browser's own, and
 * help simply stays on.
 */
(function () {
  "use strict";

  /** Mygdala's own key, named like the ones in admin-tabs.js and save-bar.js. */
  var STORAGE_KEY = "mygdalaAdminHelp";

  var HOVER_OPEN_DELAY = 120;
  var HOVER_CLOSE_DELAY = 220;
  var VIEWPORT_MARGIN = 8;
  var TRIGGER_GAP = 6;

  var root = document.documentElement;
  var supportsPopover = typeof HTMLElement.prototype.showPopover === "function";

  /** Open explanations, the most recent last: Escape closes from the end. */
  var openStack = [];

  /** The pending hover open or close of each explanation. */
  var timers = new WeakMap();

  // --- The preference ----------------------------------------------------

  function storedPreference() {
    try {
      return window.localStorage.getItem(STORAGE_KEY) === "off" ? "off" : "on";
    } catch (e) {
      // Storage blocked or unavailable: the default, which is help on.
      return "on";
    }
  }

  function remember(state) {
    try {
      window.localStorage.setItem(STORAGE_KEY, state);
    } catch (e) {
      // Not remembered for next time, but still applied to this page.
    }
  }

  function applyPreference(state) {
    root.setAttribute("data-admin-help", state);

    document.querySelectorAll("[data-admin-help-toggle]").forEach(function (toggle) {
      toggle.setAttribute("aria-pressed", state === "on" ? "true" : "false");
    });

    if (state === "off") {
      closeAll(null);
    }
  }

  root.classList.add("admin-ui-enhanced");
  root.setAttribute("data-admin-help", storedPreference());

  // --- One explanation ---------------------------------------------------

  function helpOf(element) {
    return element.closest("[data-admin-help]");
  }

  function popoverOf(help) {
    return help ? help.querySelector("[data-admin-help-popover]") : null;
  }

  function triggerOf(help) {
    return help ? help.querySelector("[data-admin-help-trigger]") : null;
  }

  /** "peek" while it follows the pointer, "pinned" once clicked, null when closed. */
  function stateOf(popover) {
    return popover.getAttribute("data-admin-help-state");
  }

  function cancelTimer(popover) {
    clearTimeout(timers.get(popover));
    timers.delete(popover);
  }

  function later(popover, delay, callback) {
    cancelTimer(popover);
    timers.set(popover, setTimeout(function () {
      timers.delete(popover);
      callback();
    }, delay));
  }

  function open(popover, mode) {
    var trigger = triggerOf(helpOf(popover));
    cancelTimer(popover);

    if (stateOf(popover) === null) {
      if (!supportsPopover) {
        popover.classList.add("is-open");
      } else if (!popover.matches(":popover-open")) {
        popover.showPopover();
      }

      openStack.push(popover);
    }

    popover.setAttribute("data-admin-help-state", mode);

    if (trigger) {
      trigger.setAttribute("aria-expanded", "true");
      place(popover, trigger);
    }
  }

  function close(popover) {
    cancelTimer(popover);

    if (stateOf(popover) === null) {
      return;
    }

    popover.removeAttribute("data-admin-help-state");
    openStack = openStack.filter(function (entry) {
      return entry !== popover;
    });

    if (!supportsPopover) {
      popover.classList.remove("is-open");
    } else if (popover.matches(":popover-open")) {
      popover.hidePopover();
    }

    var trigger = triggerOf(helpOf(popover));
    if (trigger) {
      trigger.setAttribute("aria-expanded", "false");
    }
  }

  function closeAll(except) {
    openStack.slice().forEach(function (popover) {
      if (popover !== except) {
        close(popover);
      }
    });
  }

  /**
   * Beside its icon: below it, or above it when there is no room below, and
   * pulled back inside the viewport on every side. Fixed coordinates, because
   * the top layer is positioned against the viewport. Without the script the
   * browser centres the explanation instead, which is why the defaults it
   * overrides (margin, inset) are only overridden here.
   */
  function place(popover, trigger) {
    var rect = trigger.getBoundingClientRect();
    var viewportWidth = root.clientWidth;
    var viewportHeight = window.innerHeight;

    popover.style.margin = "0";
    popover.style.right = "auto";
    popover.style.bottom = "auto";
    popover.style.left = "0px";
    popover.style.top = "0px";

    var width = popover.offsetWidth;
    var height = popover.offsetHeight;

    var left = Math.min(rect.left - 12, viewportWidth - width - VIEWPORT_MARGIN);
    var top = rect.bottom + TRIGGER_GAP;

    if (top + height > viewportHeight - VIEWPORT_MARGIN) {
      var above = rect.top - TRIGGER_GAP - height;
      top = above >= VIEWPORT_MARGIN ? above : Math.max(VIEWPORT_MARGIN, viewportHeight - height - VIEWPORT_MARGIN);
    }

    popover.style.left = Math.max(VIEWPORT_MARGIN, left) + "px";
    popover.style.top = top + "px";
  }

  var placing = false;

  function placeAll() {
    if (placing || openStack.length === 0) {
      return;
    }

    placing = true;

    window.requestAnimationFrame(function () {
      placing = false;

      openStack.slice().forEach(function (popover) {
        var trigger = triggerOf(helpOf(popover));

        // An icon that is no longer on screen (its tab or language pane was
        // switched away) takes its explanation with it.
        if (!trigger || trigger.getClientRects().length === 0) {
          close(popover);
          return;
        }

        place(popover, trigger);
      });
    });
  }

  document.addEventListener("scroll", placeAll, { capture: true, passive: true });
  window.addEventListener("resize", placeAll);

  // --- Pointer, click and keyboard ----------------------------------------

  // Hover is for a mouse or a pen. A touch screen has no hover: a tap is a
  // click, and a click pins.
  document.addEventListener("pointerover", function (event) {
    if (event.pointerType === "touch" || !(event.target instanceof Element)) {
      return;
    }

    var help = helpOf(event.target);
    var popover = popoverOf(help);
    if (!popover) {
      return;
    }

    // Back on the icon, or inside its explanation: whatever was about to
    // close it, does not.
    cancelTimer(popover);

    if (stateOf(popover) === null && event.target.closest("[data-admin-help-trigger]")) {
      later(popover, HOVER_OPEN_DELAY, function () {
        open(popover, "peek");
      });
    }
  });

  document.addEventListener("pointerout", function (event) {
    if (event.pointerType === "touch" || !(event.target instanceof Element)) {
      return;
    }

    var help = helpOf(event.target);

    // Moving between the icon and its own explanation is staying.
    if (!help || (event.relatedTarget instanceof Node && help.contains(event.relatedTarget))) {
      return;
    }

    var popover = popoverOf(help);
    if (!popover) {
      return;
    }

    if (stateOf(popover) === null) {
      cancelTimer(popover);
    } else if (stateOf(popover) === "peek") {
      later(popover, HOVER_CLOSE_DELAY, function () {
        if (stateOf(popover) === "peek") {
          close(popover);
        }
      });
    }
  });

  document.addEventListener("click", function (event) {
    if (!(event.target instanceof Element)) {
      return;
    }

    if (event.target.closest("[data-admin-help-toggle]")) {
      var next = root.getAttribute("data-admin-help") === "off" ? "on" : "off";
      remember(next);
      applyPreference(next);
      return;
    }

    var help = helpOf(event.target);
    var popover = popoverOf(help);
    var trigger = triggerOf(help);
    if (!popover || !trigger) {
      return;
    }

    // From here on the script owns open and closed. preventDefault() cancels
    // the popovertarget attributes, which are only the no-script fallback.
    if (event.target.closest("[data-admin-help-close]")) {
      event.preventDefault();
      close(popover);
      trigger.focus();
      return;
    }

    if (event.target.closest("[data-admin-help-trigger]")) {
      event.preventDefault();

      if (stateOf(popover) === "pinned") {
        close(popover);
      } else {
        closeAll(popover);
        open(popover, "pinned");
      }
    }
  });

  // A press anywhere outside an explanation and its icon closes it.
  document.addEventListener("pointerdown", function (event) {
    if (openStack.length === 0) {
      return;
    }

    var target = event.target instanceof Node ? event.target : null;

    openStack.slice().forEach(function (popover) {
      var help = helpOf(popover);
      if (!help || !target || !help.contains(target)) {
        close(popover);
      }
    });
  });

  // Capture phase, and only when an explanation was open: Escape closes that
  // explanation and nothing else, so a modal it sits in keeps its own Escape
  // for the next press.
  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape" || openStack.length === 0) {
      return;
    }

    var popover = openStack[openStack.length - 1];
    var trigger = triggerOf(helpOf(popover));
    var focusWasInside = popover.contains(document.activeElement);

    close(popover);

    if (focusWasInside && trigger) {
      trigger.focus();
    }

    event.stopPropagation();
  }, true);

  // --- File input --------------------------------------------------------

  function nameChosenFiles(input) {
    var wrapper = input.closest("[data-admin-file]");
    var output = wrapper ? wrapper.querySelector("[data-admin-file-name]") : null;
    if (!output) {
      return;
    }

    var count = input.files ? input.files.length : 0;

    if (count === 0) {
      output.textContent = output.getAttribute("data-admin-file-none") || "";
    } else if (count === 1) {
      output.textContent = input.files[0].name;
    } else {
      output.textContent = (output.getAttribute("data-admin-file-many") || "").replace(":count", String(count));
    }
  }

  document.addEventListener("change", function (event) {
    var input = event.target;

    if (input instanceof HTMLInputElement && input.type === "file" && input.closest("[data-admin-file]")) {
      nameChosenFiles(input);
    }
  });

  // A form reset empties its file inputs after this event, not before it.
  document.addEventListener("reset", function (event) {
    var form = event.target;

    window.setTimeout(function () {
      form.querySelectorAll('[data-admin-file] input[type="file"]').forEach(nameChosenFiles);
    }, 0);
  });

  // --- Start -------------------------------------------------------------

  function whenReady() {
    // The switch did not exist yet when the preference was first applied.
    applyPreference(root.getAttribute("data-admin-help") === "off" ? "off" : "on");

    // A browser that restores a form after Back may bring a chosen file along.
    document.querySelectorAll('[data-admin-file] input[type="file"]').forEach(nameChosenFiles);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", whenReady);
  } else {
    whenReady();
  }

  // The same choice in another tab of this CMS.
  window.addEventListener("storage", function (event) {
    if (event.key === STORAGE_KEY) {
      applyPreference(storedPreference());
    }
  });
})();
