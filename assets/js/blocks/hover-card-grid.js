/*
 * Hover kaarten grid (partials/section-hover-card-grid.php): on a touch
 * screen, a tap turns a card without a link to its second picture and back.
 *
 * Only there, and only for such a card: a mouse gets the second picture from
 * :hover and the keyboard from :focus-within, both in
 * assets/css/blocks/hover-card-grid.css, and a tap on a card WITH a link
 * follows the link. A card without a second picture is left alone. The second
 * picture is an alternative view (alt="", aria-hidden), so nothing a visitor
 * needs depends on this script: without it, the first picture simply stays.
 *
 * Asked for by App\Service\Blocks\HoverCardGridBlock::scripts(); one script
 * however many grids the page has, each grid handled on its own.
 */
(function () {
  "use strict";

  if (!window.matchMedia || !window.matchMedia("(hover: none)").matches) {
    return;
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-hover-card-grid]"), function (grid) {
    grid.addEventListener("click", function (event) {
      var card = event.target.closest ? event.target.closest("[data-hover-card-swap]") : null;
      if (!card || !grid.contains(card) || card.querySelector("a[href]")) {
        return;
      }

      card.classList.toggle("is-flipped");
    });
  });
})();
