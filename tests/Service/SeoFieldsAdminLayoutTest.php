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
 * It started as a LAYOUT change — full-width fields and a real textarea for
 * the meta description — and since Multilingual 2.0 phase 2 the fields hold
 * ONE website language at a time (admin/_localized_fields.php). What these
 * tests protect: the field names the save endpoints read, the maxlengths,
 * the fallback hint coming from the component, one language on screen, and
 * the fact that nothing about the SEO logic itself moved into the template.
 */
final class SeoFieldsAdminLayoutTest extends TestCase
{
    private const EDITORS = ['admin/page.php', 'admin/page-new.php'];

    /**
     * The names api/admin/update-page.php and api/admin/create-page.php read
     * out of $_POST. Renaming or dropping one silently stops saving that
     * field, with no error anywhere.
     *
     * Since Multilingual 2.0 phase 2 a save carries ONE website language's
     * text, so each field has one name and no `_en` twin: a leftover twin
     * would be a field no endpoint reads any more.
     */
    public function testEveryEditorSubmitsOneNamePerLocalizedField(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            foreach (['title', 'meta_title', 'meta_description'] as $field) {
                $this->assertStringContainsString('name="' . $field . '"', $source, $editor . ' must still submit ' . $field);
                $this->assertStringNotContainsString('name="' . $field . '_en"', $source, $editor . ' must not submit a fixed English twin of ' . $field);
                $this->assertStringNotContainsString('name="' . $field . '_nl"', $source, $editor . ' must not submit a fixed Dutch twin of ' . $field);
            }
        }
    }

    /**
     * The description is a multi-line textarea, the title a single-line input.
     */
    public function testMetaDescriptionsAreMultiLineTextareas(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertMatchesRegularExpression(
                '/<textarea name="meta_description" rows="3"/',
                $source,
                $editor . ': meta_description must be a textarea with room for several lines'
            );
            $this->assertStringContainsString(
                '<input type="text" name="meta_title"',
                $source,
                $editor . ': meta_title must stay a single-line input'
            );
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
            $this->assertMatchesRegularExpression(
                '/<textarea name="meta_description" .*?><\?= \$h\(\$(textValue|value)\((PageTranslation::META_DESCRIPTION|\'meta_description\')\)\) \?><\/textarea>/',
                $this->fileSource($editor),
                $editor . ': the meta description must render its value inside the textarea'
            );
        }
    }

    public function testEveryFieldKeepsItsServerSideLengthLimit(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertSame(
                1,
                substr_count($source, 'maxlength="<?= PageService::MAX_META_TITLE_LENGTH ?>"'),
                $editor . ' must cap the SEO title at MAX_META_TITLE_LENGTH'
            );
            $this->assertSame(
                1,
                substr_count($source, 'maxlength="<?= PageService::MAX_META_DESCRIPTION_LENGTH ?>"'),
                $editor . ' must cap the meta description at MAX_META_DESCRIPTION_LENGTH'
            );
        }
    }

    /**
     * A translation field still says what leaving it empty does, and the
     * sentence comes from the component, built from the site's actual default
     * language — never "Leeg = Nederlandse titel", which is untrue on a site
     * whose default is another language.
     */
    public function testTheFallbackHintComesFromTheLocalizedFieldsComponent(): void
    {
        $pageEditor = $this->fileSource('admin/page.php');

        $this->assertSame(
            3,
            substr_count($pageEditor, 'admin_localized_placeholder_attr($editLanguage)'),
            'the title, the SEO title and the meta description each carry the fallback hint'
        );

        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringNotContainsString('Leeg = Nederlandse titel', $source);
            $this->assertStringNotContainsString('Leeg = Nederlandse tekst', $source);
        }
    }

    /**
     * One website language on screen and in the request: the localized-fields
     * component (admin/_localized_fields.php), not the V1 panes that rendered
     * a hidden copy of every field per language. The page editor says which
     * language it saves exactly once; a new page is always written in the
     * default language, so its form does not ask.
     */
    public function testTheEditorsShowOneWebsiteLanguageAtATime(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringNotContainsString('admin-seo-grid', $source, $editor . ' must not put two languages side by side');
            $this->assertStringNotContainsString('admin_lang_pane_start', $source, $editor . ' must not render V1 language panes');
            $this->assertStringNotContainsString('_language_fields.php', $source, $editor);
            $this->assertStringContainsString("require_once __DIR__ . '/_localized_fields.php';", $source, $editor);
            $this->assertStringContainsString('admin_localized_bar(', $source, $editor);
            $this->assertDoesNotMatchRegularExpression("/[\x27\"](?:nl|en)[\x27\"]/", $this->seoSection($source), $editor . ': the SEO card names no language');
        }

        $pageEditor = $this->fileSource('admin/page.php');
        $this->assertSame(1, substr_count($pageEditor, 'admin_localized_input($editLanguage)'), 'the settings form says once which language it saves');
        $this->assertStringContainsString('$editLanguage = admin_localized_language();', $pageEditor);

        $newPage = $this->fileSource('admin/page-new.php');
        $this->assertStringNotContainsString('admin_localized_input(', $newPage, 'a new page is written in the default language, decided by the endpoint');
        $this->assertStringContainsString('$newPageLanguage = admin_localized_default();', $newPage);
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
