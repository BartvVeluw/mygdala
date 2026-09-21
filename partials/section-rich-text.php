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
 * WHICH LANGUAGE. Neither this file nor its caller decides: the body arrives
 * already in the language of the request, with the fallback applied
 * (App\Service\Blocks\BlockLocalization).
 *
 * @param array{state: string, body: string} $section
 */
function render_section_rich_text(array $section): void
{
    $visible = $section['body'];

    if (trim($visible) === '') {
        // An empty body renders nothing at all rather than an empty section
        // with vertical padding — a brand-new, not-yet-filled Rich text
        // section must not leave a visible gap on the live page.
        return;
    }
    ?>
    <section style="padding-top:0;">
      <div class="container container--narrow">
        <div class="rich-content"><?= $visible ?></div>
      </div>
    </section>
    <?php
}
