/* =========================================================================
   Content block: item gallery (partials/section-item-gallery.php)
   Filter bar + lightbox, both scoped per rendered block instance.
   Asked for by App\Service\Blocks\ItemGalleryBlock::scripts().

   The .lightbox markup and CSS are shared with portfolio-detail.php, which
   drives its own copy with prev/next (assets/js/portfolio-detail.js); the
   two never touch each other's overlay — this one only ever looks for
   .lightbox[data-item-lightbox].
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
     Lightbox
     --------------------------------------------------------------------- */
  function initLightbox() {
    // The shared overlay a gallery block prints when its lightbox setting is
    // on — never portfolio-detail.php's own [data-project-lightbox], which
    // assets/js/portfolio-detail.js drives with its own navigation.
    var lightbox = document.querySelector(".lightbox[data-item-lightbox]");
    if (!lightbox) return;
    var imgEl = lightbox.querySelector("img");
    var captionEl = lightbox.querySelector(".lightbox__caption");
    var closeBtn = lightbox.querySelector(".lightbox__close");
    var lastFocused = null;

    function open(src, alt, caption) {
      lastFocused = document.activeElement;
      imgEl.src = src;
      imgEl.alt = alt || "";
      captionEl.textContent = caption || "";
      lightbox.classList.add("is-open");
      closeBtn.focus();
      document.body.style.overflow = "hidden";
    }
    function close() {
      lightbox.classList.remove("is-open");
      document.body.style.overflow = "";
      if (lastFocused) lastFocused.focus();
    }

    // Only plain, non-linked cards zoom: a card that is a real <a href>
    // (its own project page, a product page, or the block's fallback link)
    // navigates instead — see MAIN.MD ("clickable Portfolio cards"). The
    // partial marks the zoomable ones, and only inside a block whose
    // lightbox setting is on, so two blocks on one page can differ.
    document.querySelectorAll("[data-gallery-lightbox] [data-lightbox-item]").forEach(function (item) {
      item.addEventListener("click", function () {
        var img = item.querySelector("img");
        var label = item.querySelector(".gallery-item__overlay p");
        open(img.src, img.alt, label ? label.textContent : "");
      });
      item.setAttribute("tabindex", "0");
      item.setAttribute("role", "button");
      item.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); item.click(); }
      });
    });
    closeBtn.addEventListener("click", close);
    lightbox.addEventListener("click", function (e) { if (e.target === lightbox) close(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
  }

  /* ---------------------------------------------------------------------
     Boot
     --------------------------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", function () {
    initFilters();
    initLightbox();
  });
})();
