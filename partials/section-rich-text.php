<?php

/**
 * Renders the Rich text section (App\Service\RichTextContent) — the narrow
 * long-form reading column. The markup is the body block informatiepagina.php
 * used to emit for a CMS information page, extracted verbatim (same
 * `padding-top:0` section, same `.container--narrow`, same `.rich-content`
 * wrapper) so the three migrated pages render pixel-identically now that
 * they go through the page builder instead.
 *
 * Caller must already have checked $section['state'] !==
 * RichTextContent::STATE_HIDDEN before calling this.
 *
 * $content is sanitized HTML (RichTextSanitizer, both at save time in
 * api/admin/update-rich-text-section.php and again on read in
 * RichTextContent::forSection()) — printed as real markup, never escaped
 * back to plain text.
 *
 * A section that has a separate English body additionally carries the usual
 * data-nl/data-en pair (assets/js/core.js swaps those as innerHTML, which is
 * exactly what this markup needs). A section without one emits no language
 * attributes at all, so every body that predates the optional English column
 * still renders byte-identically.
 *
 * @param array{state: string, content_html: string, content_html_en?: string} $section
 */
function render_section_rich_text(array $section): void
{
    if (trim($section['content_html']) === '') {
        // An empty body renders nothing at all rather than an empty section
        // with vertical padding — a brand-new, not-yet-filled Rich text
        // section must not leave a visible gap on the live page.
        return;
    }

    $englishBody = trim((string) ($section['content_html_en'] ?? ''));
    $langAttributes = '';
    if ($englishBody !== '') {
        $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $langAttributes = ' data-nl="' . $h($section['content_html']) . '" data-en="' . $h($englishBody) . '"';
    }
    ?>
    <section style="padding-top:0;">
      <div class="container container--narrow">
        <div class="rich-content"<?= $langAttributes ?>><?= $section['content_html'] ?></div>
      </div>
    </section>
    <?php
}
