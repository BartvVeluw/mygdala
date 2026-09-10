<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\HomepageHeroRepository;
use App\Service\HomepageHeroContent;
use PHPUnit\Framework\TestCase;

/**
 * The "Highlight grootte" setting on the Homepage Hero: the validation/clamp
 * pair around `title_highlight_size`, the fact that it survives a round trip
 * through the repository, and — via static source inspection, the same
 * technique as tests/Service/ProductDeletionAdminSecurityTest.php, since
 * this project has no HTTP or browser test harness — that the frontend
 * renders it as a RESPONSIVE percentage rather than a fixed size or a
 * transform.
 *
 * The behaviour that matters most here is backwards compatibility: a Hero
 * saved before this column existed (NULL) must render exactly as it always
 * did, i.e. at 100%.
 *
 * The round-trip test writes to the single real `homepage_hero` row (the
 * table is a page-bound singleton keyed by page_slug, so there is no
 * throwaway row to create instead — see HomepageHeroContent's docblock); it
 * captures that row in setUp() and restores it verbatim in tearDown().
 */
final class HomepageHeroHighlightSizeTest extends TestCase
{
    /** @var array<string, mixed>|null the real row, restored in tearDown() */
    private ?array $originalRow = null;

    protected function setUp(): void
    {
        HomepageHeroContent::clearCache();
        $this->originalRow = (new HomepageHeroRepository())->findBySlug(HomepageHeroContent::PAGE_SLUG);
    }

    protected function tearDown(): void
    {
        if ($this->originalRow !== null) {
            $stmt = Database::connection()->prepare(
                'UPDATE homepage_hero SET title_highlight_size = :size WHERE page_slug = :page_slug'
            );
            $stmt->execute([
                'size' => $this->originalRow['title_highlight_size'],
                'page_slug' => HomepageHeroContent::PAGE_SLUG,
            ]);
        }

        HomepageHeroContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Validation (save time — rejects)                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The four sizes named in the feature request, plus both bounds.
     */
    public function testAcceptsWholePercentagesInsideTheBounds(): void
    {
        foreach (['60', '70', '85', '100', '120'] as $value) {
            $this->assertTrue(
                HomepageHeroContent::isHighlightSizeValid($value),
                $value . '% should be a valid highlight size'
            );
        }

        $this->assertTrue(HomepageHeroContent::isHighlightSizeValid((string) HomepageHeroContent::HIGHLIGHT_SIZE_MIN));
        $this->assertTrue(HomepageHeroContent::isHighlightSizeValid((string) HomepageHeroContent::HIGHLIGHT_SIZE_MAX));
        $this->assertTrue(HomepageHeroContent::isHighlightSizeValid(HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT));
    }

    public function testRejectsValuesOutsideTheBounds(): void
    {
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid((string) (HomepageHeroContent::HIGHLIGHT_SIZE_MIN - 1)));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid((string) (HomepageHeroContent::HIGHLIGHT_SIZE_MAX + 1)));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid('0'));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid('400'));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid('-100'));
    }

    /**
     * A tampered POST is the only way these reach the endpoint (the slider
     * cannot produce them), so they must be refused rather than coerced.
     */
    public function testRejectsNonIntegerAndNonScalarValues(): void
    {
        foreach (['', ' ', 'abc', '100%', '85.5', '1e2', '0x64', '090'] as $value) {
            $this->assertFalse(
                HomepageHeroContent::isHighlightSizeValid($value),
                var_export($value, true) . ' should not be a valid highlight size'
            );
        }

        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid(null));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid([]));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid(85.5));
    }

    /**
     * Surrounding whitespace is tolerated rather than rejected, matching the
     * trim() the endpoint already applies to every other POST field.
     */
    public function testIgnoresSurroundingWhitespace(): void
    {
        $this->assertTrue(HomepageHeroContent::isHighlightSizeValid('  90  '));
        $this->assertFalse(HomepageHeroContent::isHighlightSizeValid('  900  '));
    }

    /* ------------------------------------------------------------------ */
    /* Clamping (render time — coerces)                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The backwards-compatibility guarantee: a Hero row written before this
     * column existed has no size, and must render at exactly 100%.
     */
    public function testClampsAMissingValueToTheDefault(): void
    {
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT, HomepageHeroContent::clampHighlightSize(null));
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT, HomepageHeroContent::clampHighlightSize(''));
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT, HomepageHeroContent::clampHighlightSize('abc'));
        $this->assertSame(100, HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT);
    }

    public function testClampsOutOfRangeValuesBackIntoTheBounds(): void
    {
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_MIN, HomepageHeroContent::clampHighlightSize(10));
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_MIN, HomepageHeroContent::clampHighlightSize('-50'));
        $this->assertSame(HomepageHeroContent::HIGHLIGHT_SIZE_MAX, HomepageHeroContent::clampHighlightSize(500));
    }

    public function testKeepsValidValuesUnchanged(): void
    {
        foreach ([60, 70, 85, 100, 120] as $size) {
            $this->assertSame($size, HomepageHeroContent::clampHighlightSize($size));
            $this->assertSame($size, HomepageHeroContent::clampHighlightSize((string) $size));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Round trip through the repository / content layer                   */
    /* ------------------------------------------------------------------ */

    public function testASavedSizeIsReadBackByTheContentLayer(): void
    {
        if ($this->originalRow === null) {
            $this->markTestSkipped('No homepage_hero row in this database.');
        }

        $repository = new HomepageHeroRepository();

        foreach ([70, 85, 120, 100] as $size) {
            $repository->upsert(
                HomepageHeroContent::PAGE_SLUG,
                $this->rowAsUpsertValues($this->originalRow) + ['title_highlight_size' => $size]
            );
            HomepageHeroContent::clearCache();

            $this->assertSame($size, HomepageHeroContent::current()['title_highlight_size']);
        }
    }

    /**
     * Saving anything else about the Hero (an image, a video, the media type
     * or layout) must not silently reset the size — those endpoints carry
     * every other column forward through the same complete-row upsert.
     */
    public function testTheSizeSurvivesAnUpsertThatCarriesTheRowForward(): void
    {
        if ($this->originalRow === null) {
            $this->markTestSkipped('No homepage_hero row in this database.');
        }

        $repository = new HomepageHeroRepository();

        $repository->upsert(
            HomepageHeroContent::PAGE_SLUG,
            $this->rowAsUpsertValues($this->originalRow) + ['title_highlight_size' => 70]
        );

        $carried = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);
        $this->assertNotNull($carried);

        // Exactly what update-homepage-hero-media.php does: re-read the row,
        // clamp the size out of it, change only media_type/layout.
        $repository->upsert(
            HomepageHeroContent::PAGE_SLUG,
            $this->rowAsUpsertValues($carried)
            + ['title_highlight_size' => HomepageHeroContent::clampHighlightSize($carried['title_highlight_size'] ?? null)]
        );

        HomepageHeroContent::clearCache();
        $this->assertSame(70, HomepageHeroContent::current()['title_highlight_size']);
    }

    /**
     * The three carry-forward endpoints each rebuild the complete row before
     * calling upsert(); if one of them forgets the new column, saving an
     * image would quietly reset a Hero's highlight size to the default.
     */
    public function testEveryCarryForwardEndpointPreservesTheSize(): void
    {
        $endpoints = [
            'update-homepage-hero-image.php',
            'update-homepage-hero-video.php',
            'update-homepage-hero-media.php',
        ];

        foreach ($endpoints as $endpoint) {
            $source = $this->fileSource('api/admin/' . $endpoint);

            $this->assertStringContainsString(
                "'title_highlight_size' => HomepageHeroContent::clampHighlightSize(",
                $source,
                $endpoint . ' must carry title_highlight_size forward, or it resets the size on every save'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Save endpoint + admin UI                                            */
    /* ------------------------------------------------------------------ */

    public function testTheSaveEndpointValidatesTheSizeBeforeWritingIt(): void
    {
        $source = $this->fileSource('api/admin/update-homepage-hero.php');

        $this->assertStringContainsString("\$_POST['title_highlight_size']", $source);
        $this->assertStringContainsString('HomepageHeroContent::isHighlightSizeValid(', $source);

        $validationPos = strpos($source, 'isHighlightSizeValid(');
        $upsertPos = strpos($source, '->upsert(');

        $this->assertNotFalse($validationPos);
        $this->assertNotFalse($upsertPos);
        $this->assertLessThan($upsertPos, $validationPos, 'the size must be validated before the row is written');
    }

    public function testTheAdminEditorExposesABoundedSliderWithAVisibleValue(): void
    {
        $source = $this->fileSource('admin/homepage-hero.php');

        $this->assertStringContainsString('Highlight grootte', $source);
        $this->assertStringContainsString('type="range"', $source);
        $this->assertStringContainsString('name="title_highlight_size"', $source);
        $this->assertStringContainsString('HomepageHeroContent::HIGHLIGHT_SIZE_MIN', $source);
        $this->assertStringContainsString('HomepageHeroContent::HIGHLIGHT_SIZE_MAX', $source);
        $this->assertStringContainsString('data-range-output', $source);
    }

    /* ------------------------------------------------------------------ */
    /* Frontend: responsive percentage, not a fixed size or a transform    */
    /* ------------------------------------------------------------------ */

    public function testTheHeroTemplateRendersTheSizeAsACssCustomPropertyPercentage(): void
    {
        $source = $this->fileSource('partials/section-homepage-hero.php');

        $this->assertStringContainsString('--hero-highlight-size: <?= $heroHighlightSize ?>%', $source);
        $this->assertStringContainsString('clampHighlightSize(', $source);

        // The highlight must stay a plain inline <em> inside the H1 — no
        // wrapper element, no separate block, so it keeps wrapping with the
        // rest of the sentence.
        $this->assertStringContainsString('<h1 style="--hero-highlight-size:', $source);
    }

    public function testTheHighlightInheritsTheResponsiveHeadlineScale(): void
    {
        // Step 4 split style.css by owner: the H1 scale is Core's, the
        // hero's own rules belong to the homepage_hero block.
        $css = $this->fileSource('assets/css/core.css')
            . $this->fileSource('assets/css/blocks/homepage-hero.css');

        $this->assertMatchesRegularExpression(
            '/\.hero h1 em\{[^}]*font-size:\s*var\(--hero-highlight-size,\s*100%\)/',
            $css,
            'the highlight must resolve to a percentage of the H1, with a 100% fallback for Heroes that have no size'
        );

        // The H1 itself must still be the clamp()-based responsive size —
        // that is what the percentage above scales against.
        $this->assertMatchesRegularExpression('/--fs-h1:\s*clamp\(/', $css);
        $this->assertMatchesRegularExpression('/\bh1\{[^}]*font-size:\s*var\(--fs-h1\)/', $css);

        // And it must not have been re-implemented with a transform or a
        // fixed pixel size, which would break wrapping and spacing.
        $rule = [];
        preg_match('/\.hero h1 em\{[^}]*\}/', $css, $rule);
        $this->assertNotEmpty($rule);
        $this->assertStringNotContainsString('transform', $rule[0]);
        $this->assertStringNotContainsString('px', $rule[0]);
    }

    /**
     * The italic/gold styling of the highlight is deliberately untouched by
     * this feature.
     */
    public function testTheExistingHighlightStylingIsUnchanged(): void
    {
        // Step 4 split style.css by owner: the H1 scale is Core's, the
        // hero's own rules belong to the homepage_hero block.
        $css = $this->fileSource('assets/css/core.css')
            . $this->fileSource('assets/css/blocks/homepage-hero.css');

        $rule = [];
        preg_match('/\.hero h1 em\{[^}]*\}/', $css, $rule);

        $this->assertNotEmpty($rule);
        $this->assertStringContainsString('font-style: italic', $rule[0]);
        // The accent is now named by its ROLE rather than by the brand colour
        // it happens to be; the rendered colour is unchanged. See THEMING.md.
        $this->assertStringContainsString('color: var(--color-primary)', $rule[0]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The subset of a `homepage_hero` row that upsert() needs, minus the
     * size — mirrors what the admin endpoints build.
     *
     * @param array<string, mixed> $row
     * @return array<string, string|bool>
     */
    private function rowAsUpsertValues(array $row): array
    {
        $defaults = HomepageHeroContent::defaults();

        return [
            'eyebrow_nl' => (string) $row['eyebrow_nl'],
            'eyebrow_en' => (string) ($row['eyebrow_en'] ?? ''),
            'title_nl' => (string) $row['title_nl'],
            'title_en' => (string) ($row['title_en'] ?? ''),
            'title_highlight_nl' => (string) ($row['title_highlight_nl'] ?? ''),
            'title_highlight_en' => (string) ($row['title_highlight_en'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'lead_en' => (string) ($row['lead_en'] ?? ''),
            'primary_label_nl' => (string) $row['primary_label_nl'],
            'primary_label_en' => (string) ($row['primary_label_en'] ?? ''),
            'primary_url' => (string) $row['primary_url'],
            'secondary_label_nl' => (string) ($row['secondary_label_nl'] ?? ''),
            'secondary_label_en' => (string) ($row['secondary_label_en'] ?? ''),
            'secondary_url' => (string) ($row['secondary_url'] ?? ''),
            'image_path' => (string) $row['image_path'],
            'image_alt_nl' => (string) $row['image_alt_nl'],
            'image_alt_en' => (string) ($row['image_alt_en'] ?? ''),
            'badge_title_nl' => (string) ($row['badge_title_nl'] ?? ''),
            'badge_title_en' => (string) ($row['badge_title_en'] ?? ''),
            'badge_text_nl' => (string) ($row['badge_text_nl'] ?? ''),
            'badge_text_en' => (string) ($row['badge_text_en'] ?? ''),
            'media_type' => (string) ($row['media_type'] ?? $defaults['media_type']),
            'video_path' => (string) ($row['video_path'] ?? ''),
            'layout' => (string) ($row['layout'] ?? $defaults['layout']),
            'is_active' => (bool) $row['is_active'],
        ];
    }

    private function fileSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
