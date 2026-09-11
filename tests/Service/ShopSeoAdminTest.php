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

    private const SEO_TEXT_FIELDS = ['meta_title', 'meta_title_en', 'meta_description', 'meta_description_en'];

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

            $this->assertStringContainsString('name="og_image"', $source, $editor . ' must offer a social image upload');
            $this->assertStringContainsString('name="remove_og_image"', $source, $editor . ' must offer removing it again');
        }
    }

    public function testTheFieldNamesMatchTheCmsPageEditorsSoTheVocabularyIsShared(): void
    {
        $pageEditor = $this->fileSource('admin/page.php');

        foreach (self::SEO_TEXT_FIELDS as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $pageEditor);
        }
    }

    public function testTheDescriptionsAreMultiLineTextareasCarryingTheirValueAsContent(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            foreach (['meta_description', 'meta_description_en'] as $field) {
                $this->assertMatchesRegularExpression(
                    '/<textarea name="' . $field . '" rows="3"/',
                    $source,
                    $editor . ': ' . $field . ' must be a textarea with room for several lines'
                );
                // A textarea carries its value as element content, not a
                // value attribute — getting that wrong loses every stored
                // meta description on the first save.
                $this->assertMatchesRegularExpression(
                    '#<textarea name="' . $field . '" rows="3".*?(fieldValue|collectionFieldValue)\(.*?</textarea>#s',
                    $source,
                    $editor . ': ' . $field . ' must render its stored value inside the textarea'
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

    public function testEveryFieldIsCappedAtTheSharedServerSideLimit(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertSame(
                2,
                substr_count($source, 'maxlength="<?= Seo::MAX_META_TITLE_LENGTH ?>"'),
                $editor . ' must cap both SEO titles at Seo::MAX_META_TITLE_LENGTH'
            );
            $this->assertSame(
                2,
                substr_count($source, 'maxlength="<?= Seo::MAX_META_DESCRIPTION_LENGTH ?>"'),
                $editor . ' must cap both meta descriptions at Seo::MAX_META_DESCRIPTION_LENGTH'
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
            $seo = $this->seoSection($this->fileSource($editor));

            $this->assertStringContainsString('optioneel', mb_strtolower($seo), $editor . ' must say the fields are optional');
            $this->assertStringContainsString('automatisch', $seo, $editor . ' must say what happens when a field is left empty');
            $this->assertStringContainsString("admin_lang_placeholder_attr('en')", $seo);
        }
    }

    public function testBothEditorsUseTheSharedLanguagePanes(): void
    {
        foreach (self::EDITORS as $editor) {
            $source = $this->fileSource($editor);

            $this->assertStringNotContainsString(
                'admin-seo-grid',
                $source,
                $editor . ' must not put two languages side by side'
            );
            $this->assertStringContainsString("admin_lang_pane_start('nl')", $source);
            $this->assertStringContainsString("admin_lang_pane_start('en')", $source);
            $this->assertStringContainsString('admin_lang_tabs()', $source);
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
                $this->assertStringContainsString(
                    "'" . $field . "' => \$fields['" . $field . "']",
                    $source,
                    $endpoint . ' must persist ' . $field
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

    public function testTheSocialImageIsAlwaysStoredThroughAnUploader(): void
    {
        foreach (self::SAVE_ENDPOINTS as $endpoint) {
            $source = $this->fileSource($endpoint);

            $this->assertMatchesRegularExpression(
                '/\$uploader->store\(\$_FILES\[.og_image.\]\)/',
                $source,
                $endpoint . ' must validate/rename the upload instead of trusting a client path'
            );
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
