<?php

declare(strict_types=1);

/**
 * Shared rich-text field renderer for admin forms. Progressive enhancement:
 * the canonical, always-submitted form field is a plain, visible
 * <textarea> — a no-JS (or Quill-failed-to-load) admin can still read and
 * edit the raw sanitized HTML there directly, nothing is ever "impossible
 * to edit". When assets/admin.js's initRichTextEditors() runs, it hides
 * this textarea and mounts a Quill editor (see admin/assets/vendor/quill/)
 * in its place, keeping the textarea's value in sync on every edit and
 * again right before submit — so the exact same field name/shape reaches
 * the server either way. Server-side sanitization
 * (DescriptionSanitizer / RichTextSanitizer) is the actual security
 * boundary in both cases, never this editor.
 *
 * $toolbar selects which preset assets/admin.js builds: 'simple'
 * (bold/italic/link/undo/redo — product descriptions, admin/product-form.php)
 * or 'full' (adds paragraph/H2/H3/lists — Portfolio's Introtekst/
 * Projectbeschrijving, admin/portfolio-item.php, which need more structure
 * for longer project write-ups).
 *
 * $sizeClass: an extra class added to the mounted editor (e.g. one of
 * admin-richtext-editor--md/--lg from admin.css) so different fields can be
 * given a comfortable height without a one-off inline style each time —
 * see admin.js, which reads it from data-richtext-size.
 *
 * Pages using this must self-host and load Quill (quill.min.js +
 * quill.snow.css from admin/assets/vendor/quill/) before assets/admin.js —
 * see admin/product-form.php / admin/portfolio-item.php's <head>.
 */
function renderRichTextField(
    string $fieldName,
    string $label,
    string $value,
    string $toolbar = 'simple',
    string $sizeClass = ''
): void {
    $fieldId = 'field-' . $fieldName;
    ?>
    <div class="admin-richtext-field" data-richtext-field data-richtext-toolbar="<?= htmlspecialchars($toolbar, ENT_QUOTES, 'UTF-8') ?>" data-richtext-size="<?= htmlspecialchars($sizeClass, ENT_QUOTES, 'UTF-8') ?>">
      <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
      <textarea id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>" class="admin-richtext-fallback" data-richtext-source rows="6"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>
    <?php
}
