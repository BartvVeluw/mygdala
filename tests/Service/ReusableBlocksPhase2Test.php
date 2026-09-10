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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Phase 2 of the content-block refactor (docs/content-blocks/PHASE-2.md):
 * the simple blocks become genuinely reusable, per-instance blocks.
 *
 * What this file owns, and what it deliberately leaves to its neighbours:
 * Tests\Service\ContentBlockArchitectureTest owns the phase 1 architecture
 * rules (one list per page, fixed blocks, page protection),
 * Tests\Service\SectionRegistryTest owns create/delete against the real
 * content tables in general. Here we assert the four phase 2 promises:
 * every migrated type is addable/repeatable according to its own registry
 * rules, two instances on one page keep independent content, the content
 * that existed before the migration is still visible on the public site, and
 * no hardcoded per-page path is left behind.
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

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
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

    // ----------------------------------------- the migrated content itself

    #[Group('migration-backfill')]
    public function testTheContactPageStillCarriesEverythingItRendered(): void
    {
        $contact = $this->pages->findByContentKey('contact');
        $this->assertNotNull($contact, 'expected the contact page — run phinx migrate');

        $types = array_map(
            static fn (array $row): string => (string) $row['section_type'],
            $this->sections->findForPage((int) $contact['id'])
        );

        $this->assertContains('contact_form', $types);
        $this->assertContains('contact_card', $types, 'the mail card must have become a block of its own');

        $form = ContactFormContent::forSection('contact', ContactFormContent::MIGRATED_SECTION_KEY);
        $this->assertSame(ContactFormContent::STATE_ACTIVE, $form['state']);
        $this->assertSame('Offerte aanvragen', $form['title_nl']);
        $this->assertSame('Request a quote', $form['title_en']);

        $card = ContactCardContent::forSection('contact', ContactCardContent::MIGRATED_SECTION_KEY);
        $this->assertSame(ContactCardContent::STATE_ACTIVE, $card['state']);
        $this->assertSame('Liever direct mailen?', $card['title_nl']);
        $this->assertSame('Prefer to email directly?', $card['title_en']);
        $this->assertStringContainsString('korte omschrijving', $card['body_nl']);
        $this->assertStringContainsString('short description', $card['body_en']);
        $this->assertSame('mailto:' . SiteSettings::get('email'), $card['button_url']);
    }

    #[Group('migration-backfill')]
    public function testTheShopNoteSurvivedAsAnOrdinaryTextBlock(): void
    {
        $shop = $this->pages->findByContentKey('shop');
        $this->assertNotNull($shop);

        $note = RichTextContent::forSection('shop', 'shop-note');
        $this->assertSame(RichTextContent::STATE_ACTIVE, $note['state'], 'the Shop note must exist as a Rich text block');
        $this->assertStringContainsString('Zoek je iets specifieks', $note['content_html']);
        $this->assertStringContainsString('Looking for something specific', $note['content_html_en']);

        // The docblock still explains where the paragraph went, so look for
        // the MARKUP that used to render it, not the words themselves.
        $this->assertStringNotContainsString(
            'data-nl="Zoek je iets specifieks',
            (string) file_get_contents(dirname(__DIR__, 2) . '/partials/section-product-grid.php'),
            'the paragraph must no longer be hardcoded in the product grid'
        );
    }

    #[Group('migration-backfill')]
    public function testThePublicPagesStillShowTheMigratedContent(): void
    {
        if ($this->request('/') === null) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $contact = $this->request('/contact.php');
        $this->assertNotNull($contact);
        $this->assertSame(200, $contact['status']);
        // `data-form-block` is what `data-quote-form` became when the
        // quote form moved onto the shared form renderer (FORMS.md); the
        // promise this test makes — the Contact page still shows the form,
        // its heading, the details card and the mail card — is unchanged.
        foreach (['data-form-block', 'contact-grid', 'Offerte aanvragen', 'Liever direct mailen?', 'Mail direct', 'mailto:' . SiteSettings::get('email')] as $marker) {
            $this->assertStringContainsString($marker, $contact['body'], "\"{$marker}\" disappeared from /contact.php");
        }

        $shop = $this->request('/shop.php');
        $this->assertNotNull($shop);
        $this->assertSame(200, $shop['status']);
        $this->assertStringContainsString('Zoek je iets specifieks', $shop['body']);
        $this->assertStringContainsString('Looking for something specific', $shop['body'], 'the English copy must survive too');

        // The homepage marquee is now an ordinary instance with no hardcoded
        // fallback copy left — so its items must really come from the
        // database and still be rendered server-side.
        $home = $this->request('/');
        $this->assertNotNull($home);
        $this->assertStringContainsString('marquee__track', $home['body']);

        $items = MarqueeContent::forSection('index', 'materialenband');
        $this->assertSame(MarqueeContent::STATE_ACTIVE, $items['state']);
        $this->assertNotSame([], $items['items'], 'the homepage marquee must still have its items');
        foreach ($items['items'] as $item) {
            $this->assertStringContainsString(
                htmlspecialchars($item['label_nl'], ENT_QUOTES, 'UTF-8'),
                $home['body']
            );
        }
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
