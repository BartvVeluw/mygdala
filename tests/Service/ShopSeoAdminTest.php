<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard for the SEO card in the product and
 * collection editors and for the endpoints that save it — the same technique
 * tests/Service/SeoFieldsAdminLayoutTest.php uses for the CMS page editors
 * (this project has no browser test harness).
 *
 * What it protects: the field names the save endpoints read (renaming one
 * silently stops saving that field, with no error anywhere), the fact that
 * both editors offer the identical vocabulary, that the SEO fields go
 * through the SAME endpoint as the rest of the form rather than a second
 * product-SEO endpoint, that the uploaded social image goes through an
 * uploader instead of a client-supplied path, and that the endpoints keep
 * their AdminAuth/permission/CSRF guards.
 *
 * ONE LANGUAGE PER FORM since Multilingual 2.0 phase 5 wave C: both editors
 * carry `meta_title` and `meta_description` once, for the language
 * admin/_localized_fields.php names, and both endpoints save exactly that
 * language's words. There is no `_en` twin on screen any more, and a form
 * with five website languages still submits two SEO fields.
 */
final class ShopSeoAdminTest extends TestCase
{
    private const EDITORS = ['admin/product-form.php', 'admin/collection.php'];

    private const SAVE_ENDPOINTS = [
        'api/admin/create-product.php',
        'api/admin/update-product.php',
        'api/admin/create-collection.php',
        'api/admin/update-collection.php',
    ];

    private const SEO_TEXT_FIELDS = ['meta_title', 'meta_description'];

    public function testBothEditorsSubmitTheSameSeoFieldNames(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            foreach (self::SEO_TEXT_FIELDS as $field) {
                $this->assertStringContainsString(
                    'name="' . $field . '"',
                    $source,
                    $editor . ' must submit ' . $field
                );
            }

            // Media Library 2.0: the share image is chosen (or uploaded into
            // the library) with the shared picker in social-image mode, never
            // with a file input of the editor's own.
            $this->assertStringContainsString("media_picker_field('og_media_id', \$shareMedia,", $source, $editor . ' must offer the share image through the library');
            $this->assertStringContainsString('MediaType::SOCIAL_IMAGE', $source, $editor . ' must ask for a raster share image');
            $this->assertStringNotContainsString("'name' => 'og_image'", $source, $editor . ' must not upload a share image itself');
            $this->assertStringContainsString('name="remove_og_image"', $source, $editor . ' must offer removing an old one again');
        }
    }

    public function testTheFieldNamesMatchTheCmsPageEditorsSoTheVocabularyIsShared(): void
    {
        $pageEditor = $this->fileSource('admin/page.php');

        // Every editor in this CMS stores its text per website language and
        // submits one language at a time (Multilingual 2.0), so they all
        // share these two names and none of them has an `_en` twin.
        foreach (self::SEO_TEXT_FIELDS as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $pageEditor);
        }
    }

    public function testTheDescriptionsAreMultiLineTextareasCarryingTheirValueAsContent(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertMatchesRegularExpression(
                '/<textarea name="meta_description" rows="3"/',
                $source,
                $editor . ': meta_description must be a textarea with room for several lines'
            );
            // A textarea carries its value as element content, not a value
            // attribute — getting that wrong loses every stored meta
            // description on the first save.
            $this->assertMatchesRegularExpression(
                '#<textarea name="meta_description" rows="3".*?(productWord|collectionWord)\(.*?</textarea>#s',
                $source,
                $editor . ': meta_description must render its stored value inside the textarea'
            );

            $this->assertStringContainsString(
                '<input type="text" name="meta_title"',
                $source,
                $editor . ': meta_title must stay a single-line input'
            );
        }
    }

    public function testEveryFieldIsCappedAtTheSharedServerSideLimit(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            // ONE of each: the form carries the language on screen, so a
            // second copy would be a second language's field sneaking back in.
            $this->assertSame(
                1,
                substr_count($source, 'maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>"'),
                $editor . ' must cap its one SEO title at Seo::MAX_META_TITLE_LENGTH'
            );
            $this->assertSame(
                1,
                substr_count($source, 'maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>"'),
                $editor . ' must cap its one meta description at Seo::MAX_META_DESCRIPTION_LENGTH'
            );
        }
    }

    /**
     * The whole point of the feature is that empty means "use the real
     * content". If the editor stops saying so, the owner has no way to know.
     */
    public function testBothEditorsExplainTheAutomaticFallback(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);
            $seo = $this->seoSection($source);

            $this->assertStringContainsString('optioneel', mb_strtolower($seo), $editor . ' must say the fields are optional');
            $this->assertStringContainsString('automatisch', $seo, $editor . ' must say what happens when a field is left empty');

            // Which language the fields are in, and what an empty translation
            // falls back to, is said once per screen, above the SEO card
            // (admin/_localized_fields.php), not again in every card.
            $bar = strpos($source, 'admin_localized_bar($editingLanguage)');
            self::assertNotFalse($bar, $editor . ' must say which language its fields are in');
            self::assertSame(1, substr_count($source, 'admin_localized_bar('), $editor . ' says it once');
            self::assertLessThan(strpos($source, $seo), $bar, $editor . ': before the SEO card');
        }
    }

    /**
     * Neither editor may hold a language list, a second language's fields or a
     * pane switcher of its own: the languages come from `site_languages`
     * through admin/_localized_fields.php, and adding German is a row there
     * rather than a line of PHP here (Multilingual 2.0 phase 5 wave C).
     */
    public function testBothEditorsUseTheSharedLocalizedFieldsPrimitive(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringContainsString("require_once __DIR__ . '/_localized_fields.php';", $source);
            $this->assertStringContainsString('$editingLanguage = admin_localized_language();', $source);
            $this->assertStringContainsString('admin_localized_input($editingLanguage)', $source, $editor . ' must tell its endpoint which language it carries');
            $this->assertStringContainsString('admin_localized_bar($editingLanguage)', $source);

            foreach ([
                'admin-seo-grid',
                'admin_lang_pane_start',
                'admin_lang_bar(',
                'admin_lang_placeholder_attr',
                "'nl' =>",
                "'en' =>",
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    $editor . ' must not know a fixed pair of languages'
                );
            }
        }
    }

    /**
     * The character counter is a usability aid only. If it ever gained the
     * power to shorten a value, the administrator's own copy would be
     * silently rewritten — which is exactly what this feature must not do.
     */
    public function testTheCharacterCounterOnlyCountsAndNeverRewrites(): void
    {
        $js = $this->fileSource('admin/assets/admin.js');

        $start = strpos($js, 'function initSeoCharCounters');
        $this->assertNotFalse($start, 'admin.js must still define the SEO character counter');
        $body = substr($js, $start, 900);

        foreach (['field.value =', '.substring(', '.slice(', 'preventDefault'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                'the character counter must never modify or block the field'
            );
        }
    }

    /**
     * Neither editor may start deciding what a title or description IS —
     * that belongs in App\Service\ProductSeo / App\Service\CollectionContent,
     * so the page head, the Open Graph tags, the JSON-LD and the sitemap all
     * read the same answer. (The help copy does print the site name to spell
     * the fallback out; that is copy, not a second implementation.)
     */
    public function testTheEditorsContainNoSeoResolutionLogic(): void
    {
        foreach (self::EDITORS as $editor) {
            $seo = $this->seoSection($this->fileSource($editor));

            foreach (['seoTitle(', 'metaDescription(', 'socialImagePath(', 'canonicalUrl('] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $seo,
                    $editor . ': SEO resolution belongs in the service layer, not in the form'
                );
            }
        }
    }

    // ------------------------------------------------------------ endpoints

    public function testTheSeoFieldsAreSavedByTheExistingProductAndCollectionEndpoints(): void
    {
        foreach (self::SAVE_ENDPOINTS as $endpoint) {
            $source = $this->fileSource($endpoint);

            foreach (self::SEO_TEXT_FIELDS as $field) {
                // Written as words of the ONE language the form named, through
                // the class that owns the Shop's storage — never as a column
                // on the `products`/`collections` row.
                $this->assertStringContainsString(
                    'ShopLocalization::' . strtoupper($field) . " => \$fields['" . $field . "']",
                    $source,
                    $endpoint . ' must persist ' . $field
                );
            }

            // The language argument is the one the FORM carried, never a
            // literal. Two spellings are allowed and no more: the submitted
            // field itself, or a $language the same file assigned from it —
            // which api/admin/update-collection.php does because the address
            // rules need it several times (Multilingual 2.0 phase 6).
            $this->assertMatchesRegularExpression(
                "/ShopLocalization::save(Product|Collection)\\(\\\$\\w+, (\\\$fields\\['language_code'\\]|\\\$language),/",
                $source,
                $endpoint . ' must save the words of the language the form carried, and no other'
            );

            if (preg_match("/ShopLocalization::save(?:Product|Collection)\\(\\\$\\w+, \\\$language,/", $source) === 1) {
                $this->assertMatchesRegularExpression(
                    "/\\\$language = \\(string\\) \\\$fields\\['language_code'\\];/",
                    $source,
                    $endpoint . ": \$language must come from the form's own field"
                );
            }
        }
    }

    public function testThereIsNoSeparateProductOrCollectionSeoEndpoint(): void
    {
        foreach (['update-product-seo.php', 'update-collection-seo.php', 'update-seo.php'] as $unwanted) {
            $this->assertFileDoesNotExist(
                dirname(__DIR__, 2) . '/api/admin/' . $unwanted,
                'the SEO fields save through the existing editors, not a second endpoint'
            );
        }
    }

    /**
     * Media Library 2.0: the share image is a library item. The endpoint
     * resolves the posted id against the library (shop_share_image_choice(),
     * MediaService::findSocialImage()) and never stores a file or trusts a
     * path from the form.
     */
    public function testTheSocialImageIsAlwaysALibraryItem(): void
    {
        foreach (self::SAVE_ENDPOINTS as $endpoint) {
            $source = $this->fileSource($endpoint);

            $this->assertStringContainsString('shop_share_image_choice($_POST,', $source, $endpoint . ' must resolve the share image against the library');
            $this->assertStringNotContainsString("\$_FILES['og_image']", $source, $endpoint . ' must not store an upload of its own');
            $this->assertStringNotContainsString(
                "\$_POST['og_image_path']",
                $source,
                $endpoint . ' must never accept an image path straight from the form'
            );
        }
    }

    public function testTheSaveEndpointsKeepTheirAuthAndCsrfGuards(): void
    {
        foreach (self::SAVE_ENDPOINTS as $endpoint) {
            $source = $this->fileSource($endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi();', $source, $endpoint);
            $this->assertStringContainsString('AdminAuth::requirePermissionForApi(', $source, $endpoint);
            $this->assertStringContainsString('Csrf::validate(', $source, $endpoint);
        }
    }

    /**
     * Server-side validation must reject an over-long value rather than cut
     * it down — see api/admin/_seo_validation.php.
     */
    public function testTheSharedValidatorRejectsRatherThanTruncates(): void
    {
        $source = $this->fileSource('api/admin/_seo_validation.php');

        $this->assertStringContainsString('mb_strlen($value) > $maxLength', $source);
        $this->assertStringContainsString('mag maximaal', $source);
        $this->assertStringNotContainsString('mb_substr(', $source, 'the validator must never silently shorten admin input');
    }

    /**
     * Everything between the SEO card's heading and the end of that section.
     */
    private function seoSection(string $source): string
    {
        // The heading is a catalogue key now (admin/_translate.php).
        $start = strpos($source, "admin_te('shop.seo')");
        if ($start === false) {
            $start = strpos($source, '<h3>SEO</h3>');
        }
        $this->assertNotFalse($start, 'the editor must have an SEO card');

        $end = strpos($source, 'name="remove_og_image"', $start);
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
