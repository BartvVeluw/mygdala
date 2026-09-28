/**
 * The choice of items of a gallery block (admin/_gallery_selection.php): the
 * Projecten block and the gallery. Plain vanilla JS, one controller per
 * [data-gallery-selection].
 *
 *   [data-gallery-needs="all category"]  shown while the source (Bron) is one
 *                                        of the listed scopes: the category for
 *                                        "one category", the order for "all"
 *                                        and "one category", the picker for
 *                                        "picked by hand"
 *   [data-gallery-manual-note]           how many are picked and how many the
 *                                        block shows, with the maximum of the
 *                                        same form ([data-gallery-max])
 *
 * The server prints the same `hidden` for what is stored, and every part is
 * sent whether it is hidden or not, so nothing a hidden part holds is lost.
 */
(function () {
  "use strict";

  function fill(text, values) {
    return String(text || "").replace(/:(\w+)/g, function (match, key) {
      return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match;
    });
  }

  function init(root) {
    var scope = root.querySelector("[data-gallery-scope]");
    if (!scope) return;

    var form = root.closest("form");
    var max = form ? form.querySelector("[data-gallery-max]") : null;
    var note = root.querySelector("[data-gallery-manual-note]");
    var random = root.querySelector("input[name='manual_random']");
    var words = {};
    try {
      words = JSON.parse(root.getAttribute("data-gallery-selection-words") || "{}");
    } catch (e) {
      words = {};
    }

    function sync() {
      var current = scope.value;
      Array.prototype.forEach.call(root.querySelectorAll("[data-gallery-needs]"), function (part) {
        var wanted = (part.getAttribute("data-gallery-needs") || "").split(/\s+/);
        part.hidden = wanted.indexOf(current) === -1;
      });

      if (!note) return;
      var picked = root.querySelectorAll("[data-item-picker-checkbox]:checked").length;
      var limit = max && max.value !== "" ? parseInt(max.value, 10) : null;
      var shown = limit === null ? picked : Math.min(picked, limit);
      var text;
      if (picked === 0) {
        text = words.none;
      } else if (shown < picked) {
        text = random && random.checked ? words.random_some : words.some;
      } else {
        text = words.all;
      }
      note.textContent = fill(text, { picked: String(picked), shown: String(shown) });
    }

    root.addEventListener("change", sync);
    if (max) max.addEventListener("change", sync);
    sync();
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-gallery-selection]"), init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})();
