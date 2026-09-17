<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FaqRepository;
use App\Repository\FeatureGridRepository;
use App\Repository\HomepageHeroRepository;
use App\Repository\PageHeroRepository;
use App\Repository\StatStripRepository;
use App\Repository\StepListRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\FaqContent;
use App\Service\FeatureGridContent;
use App\Service\HomepageHeroContent;
use App\Service\PageHeroContent;
use App\Service\SiteSettings;
use App\Service\StatStripContent;
use App\Service\StepListContent;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;

/**
 * The seven oldest block types render stored content, or nothing at all.
 *
 * Each of them used to carry a DEFAULTS constant with the copy of the site
 * this CMS grew out of, keyed on the (page_slug, section_key) that copy once
 * lived at. A missing row, a failed lookup or an emptied field on an active
 * row all fell through to it, so a generic installation could show another
 * business's text, photographs and routes. CONTENT-BLOCKS.md, "Het
 * inhoudscontract", and DECISIONS.md, "Geen hardcoded fallback-copy meer",
 * say what must happen instead; this test holds each type to it at the level a
 * visitor sees: the markup its block definition renders.
 *
 * Every case uses the legacy address of that type, because that is where the
 * old copy would come back, and runs inside a transaction that is rolled back
 * afterwards. Two of the types are page-bound (the Page hero per slug, the
 * Homepage Hero per site), so there is no throwaway row to use instead.
 *
 * The lookup-failure case swaps App\Database's connection for an empty SQLite
 * database, so every content query throws the way a missing table or a lock
 * would. That is test-only: the original connection is restored in a finally
 * block and again in tearDown().
 *
 * A row that is active but has nothing in it borrows no legacy copy and
 * renders nothing at all; Tests\Service\NoEmptyActiveBlockTest asks the rest
 * of that contract — what does count as content, per type.
 */
final class NoFallbackCopyTest extends TestCase
{
    /**
     * Each type and the address its DEFAULTS were keyed on. A null
     * section_key is a page-bound type that has none.
     */
    private const LEGACY_INSTANCES = [
        'faq' => ['diensten', 'faq'],
        'feature_grid' => ['index', 'value-props'],
        'step_list' => ['index', 'werkwijze'],
        'stat_strip' => ['index', 'capability-band'],
        'text_image_split' => ['over-mij', 'idee-naar-product'],
        'page_hero' => ['shop', null],
        'homepage_hero' => ['index', null],
    ];

    /** The content table each type keeps its rows in. */
    private const CONTENT_TABLES = [
        'faq' => 'faq_sections',
        'feature_grid' => 'feature_grids',
        'step_list' => 'step_list_sections',
        'stat_strip' => 'stat_strips',
        'text_image_split' => 'text_image_splits',
        'page_hero' => 'page_heroes',
        'homepage_hero' => 'homepage_hero',
    ];

    /**
     * Fragments every one of the old DEFAULTS contained at least one of, at
     * the legacy addresses above. Matched case-insensitively.
     */
    private const LEGACY_COPY = [
        'Van Veluw',
        'Laserdesign',
        'Nijmegen',
        'hero-collage',
        'contact.php',
        'portfolio.php',
        'gravure',
        'graveer',
        'MOPA',
    ];

    /** Where new instances of the repeatable types are created. */
    private const TEST_SLUG = '__test_no_fallback_copy__';

    private ?\PDO $realConnection = null;

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        // The partials resolve the primary language through SiteSettings;
        // reading it now keeps the lookup-failure case about the block alone.
        SiteSettings::all();
        self::clearContentCaches();
    }

    protected function tearDown(): void
    {
        $this->restoreConnection();

        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        self::clearContentCaches();
    }

    /** @return array<string, array{string}> */
    public static function legacyTypes(): array
    {
        $types = [];
        foreach (array_keys(self::LEGACY_INSTANCES) as $type) {
            $types[$type] = [$type];
        }

        return $types;
    }

    /** @return array<string, array{string}> */
    public static function repeatableTypes(): array
    {
        return array_diff_key(self::legacyTypes(), ['page_hero' => true, 'homepage_hero' => true]);
    }

    /** @return array<string, array{string}> */
    public static function pageBoundTypes(): array
    {
        return array_intersect_key(self::legacyTypes(), ['page_hero' => true, 'homepage_hero' => true]);
    }

    /**
     * @dataProvider legacyTypes
     */
    public function testNoStoredRowRendersNothing(string $type): void
    {
        $this->beginTransaction();
        $this->deleteLegacyRow($type);

        $html = $this->renderLegacyInstance($type);

        $this->assertSame('', trim($html), "A {$type} without a stored row must render nothing, not a fallback.");
    }

    /**
     * @dataProvider legacyTypes
     */
    public function testALookupFailureRendersNothing(string $type): void
    {
        $this->breakConnection();

        try {
            $html = $this->renderLegacyInstance($type);
        } finally {
            $this->restoreConnection();
        }

        $this->assertNoLegacyCopy($html, $type);
        $this->assertSame('', trim($html), "A {$type} whose lookup failed must render nothing.");
    }

    /**
     * @dataProvider legacyTypes
     */
    public function testAHiddenRowRendersNothing(string $type): void
    {
        $this->beginTransaction();
        $this->deleteLegacyRow($type);
        $this->storeLegacyRow($type, false);

        $this->assertSame('', trim($this->renderLegacyInstance($type)), "A hidden {$type} must render nothing.");
    }

    /**
     * @dataProvider repeatableTypes
     */
    public function testAnActiveRowWithoutContentBorrowsNoLegacyCopy(string $type): void
    {
        $this->beginTransaction();
        $this->deleteLegacyRow($type);
        $this->storeLegacyRow($type, true);

        $html = $this->renderLegacyInstance($type);

        $this->assertNoLegacyCopy($html, $type);
        $this->assertSame('', $html, "An active {$type} without content must render nothing at all.");
    }

    /**
     * The field-by-field fallback: an active row with its required text
     * fields emptied (only possible outside the editor, which requires them)
     * used to fill each empty field from the legacy copy of its slug.
     *
     * @dataProvider pageBoundTypes
     */
    public function testEmptyStoredFieldsBorrowNoLegacyCopy(string $type): void
    {
        $this->beginTransaction();
        $this->deleteLegacyRow($type);
        $this->storeLegacyRow($type, true);

        $this->assertNoLegacyCopy($this->renderLegacyInstance($type), $type);
    }

    /**
     * @dataProvider legacyTypes
     */
    public function testStoredContentRendersItsOwnWords(string $type): void
    {
        $this->beginTransaction();

        [$pageSection, $ownWords] = $this->storeOwnContent($type);

        $html = $this->render($pageSection);

        $this->assertStringContainsString($ownWords, $html, "A {$type} with stored content must render it.");
        $this->assertNoLegacyCopy($html, $type);
    }

    /* ------------------------------------------------------------------ */

    private function renderLegacyInstance(string $type): string
    {
        [$pageSlug, $sectionKey] = self::LEGACY_INSTANCES[$type];

        return $this->render([
            'id' => 0,
            'section_type' => $type,
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'section_id' => 0,
        ]);
    }

    /** @param array<string, mixed> $pageSection */
    private function render(array $pageSection): string
    {
        $definition = BlockDefinitions::get((string) $pageSection['section_type']);
        $this->assertNotNull($definition, 'Unknown block type ' . $pageSection['section_type']);

        self::clearContentCaches();

        ob_start();
        try {
            $definition->render($pageSection, false, $pageSection['section_type'] . '-' . $pageSection['id']);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function deleteLegacyRow(string $type): void
    {
        [$pageSlug, $sectionKey] = self::LEGACY_INSTANCES[$type];
        $table = self::CONTENT_TABLES[$type];

        // Items and stats go with their row: every child table cascades.
        if ($sectionKey === null) {
            Database::connection()
                ->prepare("DELETE FROM {$table} WHERE page_slug = :slug")
                ->execute(['slug' => $pageSlug]);

            return;
        }

        Database::connection()
            ->prepare("DELETE FROM {$table} WHERE page_slug = :slug AND section_key = :section_key")
            ->execute(['slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * A row at the legacy address with every text field empty and no items.
     */
    private function storeLegacyRow(string $type, bool $active): void
    {
        [$pageSlug, $sectionKey] = self::LEGACY_INSTANCES[$type];
        $heading = ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'is_active' => $active];

        match ($type) {
            // The words of the converted blocks are per language
            // (block_translations): a row without any has none stored.
            'faq' => (new FaqRepository())->upsertSection($pageSlug, $sectionKey, ['is_active' => $active]),
            'feature_grid' => (new FeatureGridRepository())->upsertGrid($pageSlug, $sectionKey, ['is_active' => $active]),
            'step_list' => (new StepListRepository())->upsertSection($pageSlug, $sectionKey, ['is_active' => $active]),
            'stat_strip' => (new StatStripRepository())->upsertStrip($pageSlug, $sectionKey, ['is_active' => $active]),
            'text_image_split' => (new TextImageSplitRepository())->upsertSection($pageSlug, $sectionKey, $heading + [
                'layout' => 'image_right',
                'button_label_nl' => '',
                'button_label_en' => '',
                'button_url' => '',
            ]),
            'page_hero' => (new PageHeroRepository())->upsert($pageSlug, $heading + [
                'media_id' => null,
                'content_position' => PageHeroContent::POSITION_LEFT,
                'title_size' => PageHeroContent::SIZE_NORMAL,
                'text_size' => PageHeroContent::SIZE_NORMAL,
                'lead_nl' => '',
                'lead_en' => '',
            ]),
            'homepage_hero' => (new HomepageHeroRepository())->upsert($pageSlug, self::homepageHeroValues(['is_active' => $active])),
        };
    }

    /**
     * Creates an instance with content of its own and returns the
     * page_sections row that points at it, plus a phrase that must appear.
     *
     * @return array{array<string, mixed>, string}
     */
    private function storeOwnContent(string $type): array
    {
        if ($type === 'homepage_hero') {
            $this->deleteLegacyRow($type);
            $heroes = new HomepageHeroRepository();
            $heroes->upsert(HomepageHeroContent::PAGE_SLUG, self::homepageHeroValues(['primary_url' => '/']));
            BlockLocalization::save('homepage_hero', (int) $heroes->findBySlug(HomepageHeroContent::PAGE_SLUG)['id'], 'nl', [
                'eyebrow' => 'Eigen eyebrow',
                'title' => 'Eigen homepagetitel',
                'primary_label' => 'Eigen knop',
            ]);

            return [self::pageSection($type, HomepageHeroContent::PAGE_SLUG, null, 0), 'Eigen homepagetitel'];
        }

        $definition = BlockDefinitions::get($type);
        $this->assertNotNull($definition);

        [$sectionId, $sectionKey] = $definition->create(self::TEST_SLUG);
        $pageSection = self::pageSection($type, self::TEST_SLUG, $sectionKey, $sectionId);

        switch ($type) {
            case 'faq':
                BlockLocalization::save('faq_items', (new FaqRepository())->createItem($sectionId), 'nl', [
                    'question' => 'Eigen vraag',
                    'answer' => 'Eigen antwoord',
                ]);

                return [$pageSection, 'Eigen vraag'];

            case 'feature_grid':
                BlockLocalization::save('feature_grid_items', (new FeatureGridRepository())->createItem($sectionId, ['icon_key' => 'heart']), 'nl', [
                    'title' => 'Eigen kaart',
                    'body' => 'Eigen kaarttekst',
                ]);

                return [$pageSection, 'Eigen kaart'];

            case 'step_list':
                BlockLocalization::save('step_list_items', (new StepListRepository())->createItem($sectionId), 'nl', [
                    'title' => 'Eigen stap',
                    'body' => 'Eigen staptekst',
                ]);

                return [$pageSection, 'Eigen stap'];

            case 'stat_strip':
                BlockLocalization::save('stat_strip_items', (new StatStripRepository())->createItem($sectionId), 'nl', [
                    'primary_text' => 'Eigen cijfer',
                    'secondary_text' => 'eigen uitleg',
                ]);

                return [$pageSection, 'Eigen cijfer'];

            case 'text_image_split':
                (new TextImageSplitRepository())->createParagraph($sectionId, [
                    'content_nl' => 'Eigen alinea',
                    'content_en' => '',
                ]);

                return [$pageSection, 'Eigen alinea'];

            case 'page_hero':
                // create() writes the editable starting copy of a new hero.
                return [$pageSection, 'pas deze titel aan'];
        }

        $this->fail("No stored-content fixture for {$type}.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageSection(string $type, string $pageSlug, ?string $sectionKey, int $sectionId): array
    {
        return [
            'id' => $sectionId,
            'section_type' => $type,
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'section_id' => $sectionId,
        ];
    }

    /**
     * Every column HomepageHeroRepository::upsert() writes, empty unless
     * overridden, with the structural values a valid row needs. Its words
     * are per language and stored separately (BlockLocalization).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function homepageHeroValues(array $overrides): array
    {
        return array_merge([
            'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT,
            'primary_url' => '',
            'secondary_url' => '',
            'image_path' => '',
            'media_type' => HomepageHeroContent::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => HomepageHeroContent::LAYOUT_MEDIA_RIGHT,
            'is_active' => true,
        ], $overrides);
    }

    private function assertNoLegacyCopy(string $html, string $type): void
    {
        foreach (self::LEGACY_COPY as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase(
                $fragment,
                $html,
                "A {$type} rendered copy of the site this CMS grew out of ({$fragment})."
            );
        }
    }

    private function beginTransaction(): void
    {
        Database::connection()->beginTransaction();
        $this->inTransaction = true;
    }

    /**
     * Points App\Database at an empty in-memory database, so every content
     * query throws. Test-only; see restoreConnection().
     */
    private function breakConnection(): void
    {
        $property = new \ReflectionProperty(Database::class, 'connection');
        $this->realConnection = Database::connection();

        $property->setValue(null, new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
    }

    private function restoreConnection(): void
    {
        if ($this->realConnection === null) {
            return;
        }

        (new \ReflectionProperty(Database::class, 'connection'))->setValue(null, $this->realConnection);
        $this->realConnection = null;
    }

    private static function clearContentCaches(): void
    {
        FaqContent::clearCache();
        FeatureGridContent::clearCache();
        HomepageHeroContent::clearCache();
        PageHeroContent::clearCache();
        StatStripContent::clearCache();
        StepListContent::clearCache();
        TextImageSplitContent::clearCache();
    }
}
