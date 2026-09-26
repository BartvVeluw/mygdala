<?php

/**
 * Renders the Rich text section (App\Service\RichTextContent) — the
 * long-form reading column. The markup started as the body block
 * informatiepagina.php used to emit for a CMS information page (same
 * `.container--narrow`, same `.rich-content` wrapper); WIDTH and SPACING
 * below say what changed since.
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
 * WIDTH. 'width' (RichTextContent::WIDTHS): 'medium' is the narrow reading
 * column (.container--narrow) the block always had and adds nothing; 'large'
 * drops the modifier, so the text runs as wide as the site's normal content
 * (.container, the width of every other wide block). No width of its own.
 *
 * SPACING. The section keeps the spacing every block has (`section`,
 * --sp-7 above and below, core.css), so a text block gets the same room above
 * it as below it. It used to drop its top padding always: a leftover of the
 * information page, where it sat directly under the page hero. That case is
 * $tightTop now (a hero above says so, BlockDefinition::tightensFollowingBlock()),
 * exactly as partials/section-text-image-split.php does it. Two text blocks
 * directly after each other still read as one column: the second drops its
 * top padding in assets/css/blocks/rich-text.css, so they do not stack two
 * paddings.
 *
 * @param array{state: string, body: string, align?: string, width?: string, button_label?: string, button_href?: string} $section
 */
function render_section_rich_text(array $section, bool $tightTop = false): void
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
    $widthClass = ($section['width'] ?? 'medium') === 'large' ? '' : ' container--narrow';
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="rich-text-section"<?= $tightTop ? ' style="padding-top:0;"' : '' ?>>
      <div class="container<?= $widthClass ?><?= $alignClass ?>">
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
