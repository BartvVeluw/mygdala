/*
 * Mediabanner (partials/section-media-banner.php): a video that plays by
 * itself stands still for a visitor who asked their system for less motion.
 *
 * For every banner video with data-media-banner-autoplay on the page, and only
 * when prefers-reduced-motion is "reduce": pause it, drop its autoplay, and
 * give it its controls (and its place for assistive technology back), so the
 * visitor can still start it. Nothing else: without this script, or without
 * that preference, the video behaves exactly as its attributes say.
 *
 * Asked for by App\Service\Blocks\MediaBannerBlock::scripts(); one script
 * however many banners the page has, and each video handled on its own.
 */
(function () {
  "use strict";

  if (!window.matchMedia || !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    return;
  }

  Array.prototype.forEach.call(document.querySelectorAll("video[data-media-banner-autoplay]"), function (video) {
    video.autoplay = false;
    video.removeAttribute("autoplay");
    video.pause();
    video.controls = true;
    video.removeAttribute("aria-hidden");
  });
})();
