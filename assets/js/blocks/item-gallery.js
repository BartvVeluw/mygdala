/* =========================================================================
   Content block: item gallery (partials/section-item-gallery.php)
   The filter bar, scoped per rendered block instance.
   Asked for by App\Service\Blocks\ItemGalleryBlock::scripts().

   The lightbox is not here: a zoomable card's picture is a
   [data-lightbox-trigger] button that the site's one lightbox,
   assets/js/lightbox.js, opens (Portfolio 2.0). It reads which cards are
   shown when it opens, so a filter chosen here is followed there without
   the two scripts talking to each other.
   ========================================================================= */
(function () {
  "use strict";

  /* ---------------------------------------------------------------------
     Gallery-block filter bar

     Scoped PER BLOCK ([data-gallery-block], see
     partials/section-item-gallery.php): a page may carry several galleries,
     each with its own filter bar, and one bar must only ever filter its own
     grid. A block without a bar (its "filterbalk tonen" setting is off, or
     its source has no categories) is simply skipped.
     --------------------------------------------------------------------- */
  function initFilters() {
    document.querySelectorAll("[data-gallery-block]").forEach(function (block) {
      var bar = block.querySelector(".filter-bar");
      var grid = block.querySelector(".gallery-grid");
      if (!bar || !grid) return;
      var items = grid.querySelectorAll(".gallery-item");

      bar.addEventListener("click", function (e) {
        var btn = e.target.closest("button[data-filter]");
        if (!btn) return;
        bar.querySelectorAll("button").forEach(function (b) { b.setAttribute("aria-pressed", "false"); });
        btn.setAttribute("aria-pressed", "true");
        var filter = btn.dataset.filter;
        items.forEach(function (item) {
          var cats = (item.dataset.category || "").split(/\s+/);
          var show = filter === "all" || cats.indexOf(filter) !== -1;
          item.style.display = show ? "" : "none";
        });
      });
    });
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initFilters();
  });
})();
