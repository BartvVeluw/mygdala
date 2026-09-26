<?php

declare(strict_types=1);

use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockPreview;

/**
 * The little schematic drawing on a content-block card, shared by the block
 * picker (admin/page.php) and the Contentblokken catalogue
 * (admin/content-blocks.php) so the two can never show a different picture of
 * the same block.
 *
 * It draws whatever shapes the block's own definition asked for
 * (BlockDefinition::preview(), validated against BlockPreview's closed
 * vocabulary), as plain boxes in the admin's own colours. It is NOT a preview
 * of the real frontend and must not grow into one — see BlockPreview's
 * docblock for why a screenshot would be the wrong answer here. The real
 * block is shown by the library's preview dialog (admin/_block_library.php);
 * that dialog also shows this drawing, larger, for a block without a sample.
 *
 * PURELY DECORATIVE. The whole figure is aria-hidden: everything it hints at
 * is written out in the block's label and description right next to it, so a
 * screen reader, a printed page and a browser that never loaded admin.css all
 * lose nothing. That is also why a block with no preview simply draws
 * nothing rather than falling back to some placeholder.
 */

/**
 * How many <i> children each shape needs. The shapes themselves are drawn
 * entirely in admin.css (.admin-bp--<part>); these are the boxes it has to
 * work with, kept here so one file decides both halves.
 *
 * @var array<string, int>
 */
const ADMIN_BLOCK_PREVIEW_CHILDREN = [
    BlockPreview::HERO => 3,
    BlockPreview::PAGE_TITLE => 3,
    BlockPreview::HEADING => 2,
    BlockPreview::TEXT => 4,
    BlockPreview::IMAGE_LEFT => 2,
    BlockPreview::COLUMNS => 3,
    BlockPreview::ROWS => 3,
    BlockPreview::NUMBERS => 3,
    BlockPreview::FIGURES => 3,
    BlockPreview::BAND => 3,
    BlockPreview::TICKER => 5,
    BlockPreview::CAROUSEL => 3,
    BlockPreview::GALLERY => 6,
    BlockPreview::TILES => 3,
    BlockPreview::PRODUCTS => 6,
    BlockPreview::FIELDS => 4,
    BlockPreview::TWO_CARDS => 2,
    BlockPreview::CHIPS => 4,
    BlockPreview::SPACE => 0,
];

/**
 * The block's icon in the same 24x24 stroke <svg> the sidebar and the
 * page-template cards use.
 *
 * The inner markup comes from the definition class itself — trusted,
 * hardcoded, first-party constant markup, never request or database data —
 * so it is echoed unescaped, exactly like admin/page-new.php does with
 * App\Service\PageTemplates\PageTemplateDefinition::icon().
 */
function block_icon_svg(BlockDefinition $definition, string $class = 'admin-block-icon'): void
{
    echo '<svg class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $definition->icon()
        . '</svg>';
}

/**
 * The schematic figure, or nothing at all when the block declares no preview.
 */
function block_visual(BlockDefinition $definition, string $class = 'admin-block-visual'): void
{
    $parts = BlockPreview::filter($definition->preview());
    if ($parts === []) {
        return;
    }

    echo '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">';
    foreach ($parts as $part) {
        echo '<span class="admin-bp admin-bp--' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '">';
        echo str_repeat('<i></i>', ADMIN_BLOCK_PREVIEW_CHILDREN[$part] ?? 0);
        echo '</span>';
    }
    echo '</span>';
}
