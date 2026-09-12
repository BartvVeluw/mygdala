<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ContactCardRepository;
use App\Repository\CtaBandRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\ContactCardContent;
use App\Service\ContactFormContent;
use App\Service\CtaBandContent;
use App\Service\MarqueeContent;
use App\Service\PageContent;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 of the content-block refactor (docs/content-blocks/PHASE-2.md):
 * the simple blocks become genuinely reusable, per-instance blocks.
 *
 * What this file owns, and what it deliberately leaves to its neighbours:
 * Tests\Service\ContentBlockArchitectureTest owns the phase 1 architecture
 * rules (one list per page, fixed blocks, page protection),
 * Tests\Service\SectionRegistryTest owns create/delete against the real
 * content tables in general. Here we assert the phase 2 promises: every
 * migrated type is addable/repeatable according to its own registry rules,
 * two instances on one page keep independent content, and no hardcoded
 * per-page path is left behind. That one particular site's contact page and
 * shop note survived the migration is that site's history, not this CMS's
 * contract (TESTING.md, the `migration-backfill` group).
 *
 * Blocks are created on a throwaway page of this test's own, so nothing here
 * can touch real site content; tearDown deletes each created block through
 * SectionRegistry::delete() — the same path the CMS uses — and then the page.
 */
final class ReusableBlocksPhase2Test extends TestCase
{
    private const TEST_KEY = '__test_phase2__';

    private PageRepository $pages;
    private PageSectionRepository $sections;
    private int $pageId;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->removeTestPage();

        $this->pageId = $this->pages->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'title' => 'Fase 2 blokkentest',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $row = $this->sections->findById($id);
            if ($row === null) {
                continue;
            }
            SectionRegistry::delete($row, $this->sections);
        }
        $this->created = [];

        $this->removeTestPage();
        $this->clearContentCaches();
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_KEY]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $del = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $del->execute(['id' => (int) $row['id']]);

            $del = $db->prepare('DELETE FROM pages WHERE id = :id');
            $del->execute(['id' => (int) $row['id']]);
        }

        // Only ever this test's own throwaway page_slug.
        foreach (['cta_bands', 'contact_form_sections', 'contact_cards', 'marquee_sections', 'rich_text_sections'] as $table) {
            $del = $db->prepare("DELETE FROM {$table} WHERE page_slug = :key");
            $del->execute(['key' => self::TEST_KEY]);
        }

        PageContent::clearCache();
    }

    private function clearContentCaches(): void
    {
        CtaBandContent::clearCache();
        ContactFormContent::clearCache();
        ContactCardContent::clearCache();
        MarqueeContent::clearCache();
        RichTextContent::clearCache();
    }

    /**
     * @return array{0: int, 1: ?string} [page_sections id, section_key]
     */
    private function addBlock(string $type): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_KEY);
        $id = $this->sections->create($this->pageId, self::TEST_KEY, $type, $sectionKey, $sectionId);
        $this->created[] = $id;

        return [$id, $sectionKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function testPage(): array
    {
        $page = $this->pages->findById($this->pageId);
        $this->assertNotNull($page);

        return $page;
    }

    // -------------------------------------------------------- capabilities

    public function testEveryPhaseTwoBlockIsAddableToAnyOrdinaryPage(): void
    {
        $available = SectionRegistry::availableForPage($this->testPage(), $this->sections);

        foreach (['marquee', 'cta_band', 'contact_form', 'contact_card', 'rich_text'] as $type) {
            $this->assertArrayHasKey(
                $type,
                $available,
                "\"{$type}\" must be addable on an ordinary page — that is what \"reusable\" means"
            );
            $this->assertTrue(SectionRegistry::isManuallyAddable($type));
            $this->assertTrue(SectionRegistry::isDeletable($type));
            $this->assertFalse(SectionRegistry::isFixed($type), "\"{$type}\" is no longer a fixed block");
            $this->assertNull(SectionRegistry::types()[$type]['allowed_pages'], "\"{$type}\" must not be tied to one page");
        }
    }

    public function testRepeatableBlocksRepeatAndTheQuoteFormDoesNot(): void
    {
        foreach (['marquee', 'cta_band', 'contact_card'] as $type) {
            $this->assertTrue(SectionRegistry::allowMultiple($type), "\"{$type}\" must be repeatable");
            $this->assertNull(SectionRegistry::maxInstances($type), "\"{$type}\" must have no instance cap");
        }

        // The one deliberate exception, and the reason is technical: the
        // quote form's field ids are fixed markup, so a second form on the
        // same page would emit duplicate DOM ids.
        $this->assertFalse(SectionRegistry::allowMultiple('contact_form'));
        $this->assertSame(1, SectionRegistry::maxInstances('contact_form'));
    }

    public function testTwoCtaBandsOnOnePageKeepSeparateContent(): void
    {
        [, $firstKey] = $this->addBlock('cta_band');
        [, $secondKey] = $this->addBlock('cta_band');

        $this->assertNotSame($firstKey, $secondKey, 'each instance needs its own section_key');

        $repository = new CtaBandRepository();
        $repository->upsertSection(self::TEST_KEY, (string) $firstKey, $this->ctaValues('Eerste CTA'));
        $repository->upsertSection(self::TEST_KEY, (string) $secondKey, $this->ctaValues('Tweede CTA'));
        CtaBandContent::clearCache();

        $this->assertSame('Eerste CTA', CtaBandContent::forSection(self::TEST_KEY, (string) $firstKey)['title_nl']);
        $this->assertSame('Tweede CTA', CtaBandContent::forSection(self::TEST_KEY, (string) $secondKey)['title_nl']);
    }

    public function testTwoContactCardsOnOnePageKeepSeparateContent(): void
    {
        [, $firstKey] = $this->addBlock('contact_card');
        [, $secondKey] = $this->addBlock('contact_card');

        $repository = new ContactCardRepository();
        $repository->upsertSection(self::TEST_KEY, (string) $firstKey, ['title_nl' => 'Kaart A', 'button_label_nl' => 'A']);
        $repository->upsertSection(self::TEST_KEY, (string) $secondKey, ['title_nl' => 'Kaart B', 'button_label_nl' => 'B']);
        ContactCardContent::clearCache();

        $this->assertSame('Kaart A', ContactCardContent::forSection(self::TEST_KEY, (string) $firstKey)['title_nl']);
        $this->assertSame('Kaart B', ContactCardContent::forSection(self::TEST_KEY, (string) $secondKey)['title_nl']);
    }

    public function testDeletingOneCtaBandLeavesTheOtherIntact(): void
    {
        [$firstId, $firstKey] = $this->addBlock('cta_band');
        [, $secondKey] = $this->addBlock('cta_band');

        $pageSection = $this->sections->findById($firstId);
        $this->assertNotNull($pageSection);

        SectionRegistry::delete($pageSection, $this->sections);
        CtaBandContent::clearCache();

        $repository = new CtaBandRepository();
        $this->assertNull($repository->findBySlugAndKey(self::TEST_KEY, (string) $firstKey));
        $this->assertNotNull(
            $repository->findBySlugAndKey(self::TEST_KEY, (string) $secondKey),
            'deleting one instance must never take another instance\'s content with it'
        );
    }

    public function testAContactCardWithoutAUrlMailsTheAddressFromSiteSettings(): void
    {
        [, $key] = $this->addBlock('contact_card');

        (new ContactCardRepository())->upsertSection(self::TEST_KEY, (string) $key, [
            'title_nl' => 'Mail me',
            'button_label_nl' => 'Mail direct',
            'button_url' => '',
        ]);
        ContactCardContent::clearCache();

        $expected = 'mailto:' . SiteSettings::get('email');
        $this->assertSame($expected, ContactCardContent::forSection(self::TEST_KEY, (string) $key)['button_url']);
    }

    // ------------------------------------------------- no hardcoded paths

    public function testNoBlockTypeCarriesAHardcodedPageOrInstancePathAnyMore(): void
    {
        $this->assertFalse(
            defined(MarqueeContent::class . '::SECTIONS'),
            'the Marquee had a hardcoded index:materialenband path — it must be an ordinary instance now'
        );
        $this->assertFalse(
            defined(CtaBandContent::class . '::PAGES'),
            'the CTA band had a hardcoded list of pages that may carry one'
        );

        // ... and the editors no longer address an instance by page alone.
        $registry = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/SectionRegistry.php');
        $this->assertStringNotContainsString('cta-band.php?slug=', $registry);
    }

    /**
     * @return array<string, string|bool>
     */
    private function ctaValues(string $title): array
    {
        return [
            'eyebrow_nl' => 'Test',
            'eyebrow_en' => '',
            'title_nl' => $title,
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'primary_label_nl' => 'Knop',
            'primary_label_en' => '',
            'primary_url' => '/contact.php',
            'secondary_label_nl' => '',
            'secondary_label_en' => '',
            'secondary_url' => '',
            'is_active' => true,
        ];
    }
}
