/**
 * The "Gerelateerde projecten" section of a project's editor
 * (admin/portfolio-item.php, App\Service\PortfolioRelatedProjects). Plain
 * vanilla JS for one [data-related-projects] section:
 *
 *   [data-related-needs="on"]                shown while the switch is on
 *   [data-related-needs="automatic hybrid"]  the order and what to do with too
 *                                            few, for the automatic way
 *   [data-related-needs="manual hybrid"]     the picker, for the ones picked
 *                                            by hand
 *   [data-related-state]                     "aan" / "uit" next to the heading,
 *                                            so the folded section says it
 *
 * The server prints the same `hidden` for what is stored. Every field is
 * posted whether it is hidden or not, so switching back and forth loses
 * nothing; the save itself is the form's one Opslaan.
 */
(function () {
  "use strict";

  function init(section) {
    var toggle = section.querySelector("[data-related-switch]");
    var state = section.querySelector("[data-related-state]");
    if (!toggle) return;

    function mode() {
      var checked = section.querySelector("[data-related-mode]:checked");
      return checked ? checked.value : "automatic";
    }

    function sync() {
      var on = toggle.checked;
      var current = mode();

      Array.prototype.forEach.call(section.querySelectorAll("[data-related-needs]"), function (part) {
        var wanted = (part.getAttribute("data-related-needs") || "").split(/\s+/);
        part.hidden = wanted.indexOf("on") !== -1 ? !on : wanted.indexOf(current) === -1;
      });

      if (state) {
        state.textContent = on ? state.getAttribute("data-state-on") : state.getAttribute("data-state-off");
        state.classList.toggle("admin-badge--paid", on);
        state.classList.toggle("admin-badge--draft", !on);
      }
    }

    section.addEventListener("change", function (event) {
      if (event.target === toggle || (event.target.matches && event.target.matches("[data-related-mode]"))) {
        sync();
      }
    });

    sync();
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-related-projects]"), init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
