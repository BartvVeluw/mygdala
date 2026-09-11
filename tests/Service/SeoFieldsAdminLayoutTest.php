<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique as
 * tests/Service/ProductDeletionAdminSecurityTest.php — this project has no
 * browser test harness) for the SEO block in the two page editors,
 * admin/page.php and admin/page-new.php.
 *
 * This was purely a LAYOUT change — two language columns with full-width
 * fields and a real textarea for the meta descriptions — so what these tests
 * mostly protect is what did NOT change: the field names the save endpoints
 * read, the maxlengths, the "leeg = Nederlandse ..." fallback hints, and the
 * fact that nothing about the SEO logic itself moved into the template.
 */
final class SeoFieldsAdminLayoutTest extends TestCase
{
    private const EDITORS = ['admin/page.php', 'admin/page-new.php'];

    /**
     * The names api/admin/update-page.php and api/admin/create-page.php read
     * out of $_POST. Renaming or dropping one silently stops saving that
     * field, with no error anywhere.
     */
    public function testEveryEditorStillSubmitsTheSameFourFieldNames(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            foreach (['meta_title', 'meta_title_en', 'meta_description', 'meta_description_en'] as $field) {
                $this->assertStringContainsString(
                    'name="' . $field . '"',
                    $source,
                    $editor . ' must still submit ' . $field
                );
            }
        }
    }

    /**
     * Both descriptions are multi-line textareas now, both titles are still
     * single-line inputs.
     */
    public function testMetaDescriptionsAreMultiLineTextareas(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            foreach (['meta_description', 'meta_description_en'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/<textarea name="' . $field . '" rows="3"/',
                    $source,
                    $editor . ': ' . $field . ' must be a textarea with room for several lines'
                );
            }

            foreach (['meta_title', 'meta_title_en'] as $field) {
                $this->assertStringContainsString(
                    '<input type="text" name="' . $field . '"',
                    $source,
                    $editor . ': ' . $field . ' must stay a single-line input'
                );
            }
        }
    }

    /**
     * A textarea carries its value as element content, not a value attribute
     * — getting that wrong loses every existing meta description on the
     * first save.
     */
    public function testTextareasCarryTheirExistingValueAsContent(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertMatchesRegularExpression(
                '/<textarea name="meta_description" .*?><\?= \$h\(\$(fieldValue|value)\(\'meta_description\'\)\) \?><\/textarea>/',
                $source,
                $editor . ': the NL meta description must render its stored value inside the textarea'
            );
            $this->assertMatchesRegularExpression(
                '/<textarea name="meta_description_en" .*?><\?= \$h\(\$(fieldValue|value)\(\'meta_description_en\'\)\) \?><\/textarea>/',
                $source,
                $editor . ': the EN meta description must render its stored value inside the textarea'
            );
        }
    }

    public function testEveryFieldKeepsItsServerSideLengthLimit(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertSame(
                2,
                substr_count($source, 'maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>"'),
                $editor . ' must cap both SEO titles at MAX_META_TITLE_LENGTH'
            );
            $this->assertSame(
                2,
                substr_count($source, 'maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"'),
                $editor . ' must cap both meta descriptions at MAX_META_DESCRIPTION_LENGTH'
            );
        }
    }

    /**
     * A translation field still says what leaving it empty does — but the
     * sentence is built from the site's actual primary language now
     * (admin_lang_fallback_placeholder), because "Leeg = Nederlandse titel"
     * is simply untrue on an English-primary website.
     */
    public function testTheEnglishFallbackHintComesFromTheSitesPrimaryLanguage(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringContainsString(
                "admin_lang_placeholder_attr('en')",
                $source,
                $editor . ' must let the language component write the fallback hint'
            );
            $this->assertStringNotContainsString('Leeg = Nederlandse titel', $source);
            $this->assertStringNotContainsString('Leeg = Nederlandse tekst', $source);
        }
    }

    /**
     * The layout this replaced printed a Dutch column beside an English one
     * on every site, including the Dutch-only ones — the defect Multilingual
     * V1 set out to remove and did not finish removing (MULTILINGUAL.md).
     * One pane per language, one on screen at a time.
     */
    public function testTheSeoBlockUsesOneLanguagePanePerLanguage(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringNotContainsString(
                'admin-seo-grid',
                $source,
                $editor . ' must not put two languages side by side'
            );
            $this->assertSame(
                1,
                substr_count($source, "admin_lang_pane_start('nl')"),
                $editor . ' must render exactly one Dutch pane'
            );
            $this->assertSame(
                1,
                substr_count($source, "admin_lang_pane_start('en')"),
                $editor . ' must render exactly one English pane'
            );
            $this->assertStringContainsString('admin_lang_tabs()', $source);
            $this->assertStringContainsString('admin_lang_tabs_script()', $source);
        }
    }

    /**
     * The fields must be full-width inside their column: that needs the
     * existing .admin-product-form field styling (which is what sets
     * width:100% and stacks the label above the control) plus the opt-out
     * from its 720px reading measure.
     */
    public function testTheSeoBlockReusesTheExistingFormStylingAtFullWidth(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringContainsString(
                'class="admin-product-form admin-product-form--wide"',
                $source,
                $editor . ' must reuse the standard form field styling without its 720px cap'
            );
            $this->assertStringNotContainsString(
                'admin-form-row--split',
                $this->seoSection($source),
                $editor . ': the SEO block must no longer put two fields side by side in one row'
            );
        }
    }

    public function testTheStylesheetStillLetsTheFieldsUseTheFullWidth(): void
    {
        $css = $this->fileSource('admin/assets/admin.css');

        $this->assertMatchesRegularExpression(
            '/\.admin-product-form--wide\{\s*max-width:\s*none;\s*\}/',
            $css
        );
    }

    /**
     * Guards the one thing this change must not have touched: how a title or
     * description is chosen and rendered. Neither editor may start doing SEO
     * work of its own. (The card's intro paragraph does read the site name,
     * to spell the automatic "<Titel> — <site>" fallback out for the editor;
     * that is help copy, not a second implementation of the fallback.)
     */
    public function testTheEditorsStillContainNoSeoLogic(): void
    {
        foreach (self::EDITORS as $editor) {
            $seo = $this->seoSection($this->fileSource($editor));

            foreach (['seoTitle(', 'metaDescription('] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $seo,
                    $editor . ': fallback/SEO rendering belongs in PageContent and partials/page-head.php, not in the form'
                );
            }
        }
    }

    /**
     * Everything between the SEO card's <h2> and the end of that <section>.
     */
    private function seoSection(string $source): string
    {
        // The heading is a catalogue key now, so the card is found by the key
        // rather than by the Dutch word that used to be printed there.
        $start = strpos($source, "admin_te('page.seo')");
        if ($start === false) {
            $start = strpos($source, "admin_te('page.seo_optioneel')");
        }
        $this->assertNotFalse($start, 'the editor must still have an SEO card');

        $end = strpos($source, '</section>', $start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    private function fileSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
