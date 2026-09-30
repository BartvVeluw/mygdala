<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Detailsectie 2.1, read from the source (no database): the parts a page
 * render cannot show.
 *
 *   - the editor's form carries data-nav-item-form, without which
 *     admin/assets/navigation-item.js stops at once and every source panel
 *     (the library picker with its required picture, and every item list)
 *     is on screen together: the cause of the "second required picture";
 *   - gallery items fold through the shared collapsible row, keyed by the
 *     row's own key, with the list remembered per section;
 *   - the source script keeps a folded item's summary on the source chosen
 *     on screen and explains an item without a main picture;
 *   - an item without a picture is never an <img> on the website.
 */
final class DetailSectionTwoOneContractTest extends TestCase
{
    public function testTheSourceSwitchRunsOnTheDetailSectionForm(): void
    {
        $editor = self::source('admin/detail-section.php');
        $this->assertMatchesRegularExpression('~<form method="post" action="/api/admin/update-detail-section.php"[^>]* data-nav-item-form~', $editor);
        $this->assertStringContainsString('link_target_scripts();', $editor, 'navigation-item.js is loaded');

        $switch = self::source('admin/assets/navigation-item.js');
        $this->assertStringContainsString('document.querySelector("[data-nav-item-form]")', $switch, 'the hook the form must carry');
        $this->assertStringContainsString('form.addEventListener("row-list:added", syncDestination);', $switch, 'an item added on screen follows its source');

        $field = self::source('admin/_gallery_source_field.php');
        $this->assertStringContainsString('data-nav-link-group', $field);
        $this->assertStringContainsString('data-nav-link-field="media"', $field, 'the library picker is the media panel only');
    }

    public function testGalleryItemsFoldThroughTheSharedCollapsibleRow(): void
    {
        $editor = self::source('admin/detail-section.php');
        $imageRow = substr($editor, (int) strpos($editor, '$imageRow = static function'), 4000);

        $this->assertStringContainsString("'title' => detail_section_item_title(", $imageRow);
        $this->assertStringContainsString("'open' => !ctype_digit(\$key) || \$hasMessage || \$count === 1,", $imageRow, 'a new item, one with a message and a lone one start open');
        $this->assertStringContainsString('editor_row_close(true);', $imageRow);
        $this->assertStringContainsString('data-admin-collapse-group="detail-section-images" data-admin-collapse-scope="<?= $sectionId ?>" data-admin-collapse-no-return', $editor);
        $this->assertStringContainsString('admin_collapse_script();', $editor);

        // The key is the row's id, so a move or an addition opens nothing else.
        $rows = self::source('admin/_editor_rows.php');
        $this->assertStringContainsString('data-admin-collapse-id="<?= $h($key) ?>"', $rows);
    }

    public function testTheSourceScriptRetitlesAndExplainsAMissingMainPicture(): void
    {
        $js = self::source('admin/assets/gallery-source.js');
        $this->assertStringContainsString('function retitle(row)', $js);
        $this->assertStringContainsString('[data-row-list-title]', $js);
        $this->assertStringContainsString('[data-linked-image-no-picture]', $js);
        $this->assertStringContainsString('answer.picture === false', $js);
        $this->assertDoesNotMatchRegularExpression('/innerHTML/', $js, 'names are text, never markup');

        $field = self::source('admin/_gallery_source_field.php');
        $this->assertStringContainsString('data-linked-image-no-picture', $field);
        $this->assertStringContainsString("admin_te('gallery_source.no_picture')", $field);

        $preview = self::source('api/admin/linked-image-preview.php');
        $this->assertStringContainsString('LinkedImages::item($kind, (int) $id)', $preview);
        $this->assertStringContainsString("'picture' =>", $preview);
    }

    public function testAnItemWithoutAPictureIsNeverAnImage(): void
    {
        $partial = self::source('partials/section-detail-section.php');
        $this->assertStringContainsString('service-detail__gallery-item--name', $partial);
        $this->assertMatchesRegularExpression('~<\?php if \(\$hasPicture\): \?>\s*<\?php render_responsive_image~', $partial);

        $css = self::source('assets/css/blocks/detail-section.css');
        $this->assertStringContainsString('.service-detail__gallery-blank{ display: block; aspect-ratio: 1;', $css, 'the same square');
        $this->assertMatchesRegularExpression(
            '~\.service-detail__gallery-blank\{[^}]*var\(--color-primary-wash\)\), var\(--color-surface\); \}~',
            $css,
            'a card of its own: the wash alone on a dark palette is hardly lighter than the page'
        );
    }

    private static function source(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path));
    }
}
