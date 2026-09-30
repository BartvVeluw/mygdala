/*
 * The Oproep met knop editor (admin/cta-band.php): shows the choices that
 * only mean something with a background picture, a text panel or an own
 * height, at once.
 *
 *   [data-cta-needs-image]   hidden while no picture is chosen in the Media
 *                            picker (the focus point and the overlay)
 *   [data-cta-needs-panel]   hidden while "Tekstvlak tonen" is off (its opacity)
 *   [data-cta-needs-custom]  an own height's pixels, hidden unless its
 *                            height (the name without _px) is "Eigen hoogte"
 *
 * It also gives the focus frames of the background (admin/_responsive_image_field.php)
 * the band's shape for the chosen minimum height, the same arithmetic the
 * editor prints on first load: a band about 1152 x 400 with its words alone,
 * 343 x 480 on a phone, and a minimum only ever makes it taller. The phone
 * follows the large screen as assets/css/blocks/cta-band.css does.
 *
 * Purely a display convenience, like admin/assets/page-hero.js. The server
 * prints the same `hidden` and the same frames for what is stored, so the
 * form looks the same on first load and without this script, and the one
 * form always posts every field; the endpoint decides what a value means.
 * The buttons' fields follow their destination through
 * admin/assets/navigation-item.js, not through this file.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-cta-band-form]");
  if (!form) return;

  var picker = form.querySelector('[data-media-picker-input][name="background_media_id"]');
  var panel = form.querySelector("[data-cta-panel-toggle]");
  var height = form.querySelector("[data-cta-height]");
  var presets = { desktop: {}, phone: {} };
  try {
    presets = JSON.parse(height ? height.getAttribute("data-cta-height-px") : "") || presets;
  } catch (error) {
    // No presets: the frames keep what the server printed.
  }

  function chosen(name) {
    var radio = form.querySelector('input[type="radio"][name="' + name + '"]:checked');
    return radio ? radio.value : "auto";
  }

  function pixels(name) {
    var field = form.querySelector('input[name="' + name + '"]');
    var value = field && /^\d{1,4}$/.test(field.value.trim()) ? parseInt(field.value, 10) : 0;
    var min = field ? parseInt(field.min, 10) : 0;
    var max = field ? parseInt(field.max, 10) : 0;
    return value >= min && value <= max ? value : 0;
  }

  function frames() {
    var frame = form.querySelector('[data-rm][data-rm-picker="background_media_id"]');
    if (!frame) return;

    var desktopWord = chosen("min_height");
    var desktop = desktopWord === "custom" ? pixels("min_height_px") : presets.desktop[desktopWord] || 0;
    if (desktopWord === "custom" && desktop === 0) desktopWord = "auto";

    var phoneWord = chosen("mobile_min_height");
    var phone = 0;
    if (phoneWord === "custom") {
      phone = pixels("mobile_min_height_px");
    } else if (phoneWord !== "auto" && phoneWord !== "text") {
      phone = presets.phone[phoneWord] || 0;
    } else if (phoneWord === "auto") {
      phone = desktopWord === "custom" ? Math.min(desktop, presets.phone.tall || desktop) : presets.phone[desktopWord] || 0;
    }

    frame.style.setProperty("--admin-rm-desktop-ratio", "1152 / " + Math.max(400, desktop));
    frame.style.setProperty("--admin-rm-mobile-ratio", "343 / " + Math.max(480, phone));
  }

  function sync() {
    var hasImage = picker ? picker.value !== "" : false;
    var hasPanel = panel ? panel.checked : false;

    Array.prototype.forEach.call(form.querySelectorAll("[data-cta-needs-image]"), function (part) {
      part.hidden = !hasImage;
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-cta-needs-panel]"), function (part) {
      part.hidden = !hasPanel;
    });
    Array.prototype.forEach.call(form.querySelectorAll("[data-cta-needs-custom]"), function (part) {
      part.hidden = chosen(part.getAttribute("data-cta-needs-custom").replace(/_px$/, "")) !== "custom";
    });
    frames();
  }

  form.addEventListener("change", function (event) {
    var name = event.target.name || "";
    if (event.target === picker || event.target === panel || /min_height/.test(name)) {
      sync();
    }
  });
  form.addEventListener("input", function (event) {
    if (/min_height_px$/.test(event.target.name || "")) {
      frames();
    }
  });
  sync();
})();
