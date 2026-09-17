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
 * $section['body'] is sanitized HTML in every language (RichTextSanitizer at
 * save time, and again on read in App\Service\Blocks\BlockLocalization) —
 * printed as real markup, never escaped back to plain text.
 *
 * WHICH LANGUAGE. Neither this file nor its caller decides: SiteText prints
 * the body a visitor sees first (the website's default language, with the
 * fallback already applied) and, only when another language shows different
 * markup, the data-nl/data-en pair for the V1 switch. That pair carries
 * data-lang-html, the marker that lets assets/js/core.js's applyLang()
 * re-render it with innerHTML; without it applyLang writes textContent, the
 * site-wide default that keeps a plain-text field's markup from ever
 * executing. A body the same in every language emits no language attributes
 * at all, exactly as before the English body existed.
 *
 * @param array{state: string, body: \App\Service\Language\LocalizedValue} $section
 */
function render_section_rich_text(array $section): void
{
    $visible = \App\Service\Language\SiteText::visibleOf($section['body']);

    if (trim($visible) === '') {
        // An empty body renders nothing at all rather than an empty section
        // with vertical padding — a brand-new, not-yet-filled Rich text
        // section must not leave a visible gap on the live page.
        return;
    }
    ?>
    <section style="padding-top:0;">
      <div class="container container--narrow">
        <div class="rich-content"<?= \App\Service\Language\SiteText::htmlAttrsOf($section['body']) ?>><?= $visible ?></div>
      </div>
    </section>
    <?php
}
