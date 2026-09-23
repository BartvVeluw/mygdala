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
 * ALIGNMENT AND BUTTON. 'align' (RichTextContent::ALIGNMENTS) aligns the text
 * and everything inline in it, and the button with it; left is the default
 * and adds no class, so a left-aligned block without a button is exactly the
 * markup it always was. The button is there only when it has both a label
 * and an address (RichTextContent::forSection()).
 *
 * @param array{state: string, body: string, align?: string, button_label?: string, button_href?: string} $section
 */
function render_section_rich_text(array $section): void
{
    $visible = $section['body'];
    $label = (string) ($section['button_label'] ?? '');
    $href = (string) ($section['button_href'] ?? '');
    $hasButton = $label !== '' && $href !== '';

    if (trim($visible) === '' && !$hasButton) {
        // An empty body renders nothing at all rather than an empty section
        // with vertical padding — a brand-new, not-yet-filled Rich text
        // section must not leave a visible gap on the live page.
        return;
    }

    $align = (string) ($section['align'] ?? 'left');
    $alignClass = in_array($align, ['center', 'right'], true) ? ' rich-text--' . $align : '';
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section style="padding-top:0;">
      <div class="container container--narrow<?= $alignClass ?>">
        <?php if (trim($visible) !== ''): ?>
        <div class="rich-content"><?= $visible ?></div>
        <?php endif; ?>
        <?php if ($hasButton): ?>
        <p class="rich-text__actions"><a href="<?= $h($href) ?>" class="btn"><?= $h($label) ?></a></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
