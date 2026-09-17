<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContactCardContent;
use App\Service\CtaBandContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;

/**
 * A page of blocks on per-language storage costs ONE query for all their
 * words, however many blocks, languages and fields it has (Multilingual 2.0
 * phase 3A, docs/multilingual/ARCHITECTURE.md): SectionRegistry::renderPage()
 * preloads them before the first block renders.
 *
 * Counted with MySQL's own per-session SELECT counter on the shared
 * connection, so nothing is mocked: two pages that differ only in how many
 * blocks they carry, rendered from cold caches. The one query per block that
 * is left is the block's own content row, which every block type has always
 * had.
 */
final class BlockWordsPreloadTest extends TestCase
{
    private const SMALL = 'zz-block-words-preload-small';
    private const LARGE = 'zz-block-words-preload-large';

    protected function setUp(): void
    {
        $this->removePages();
    }

    protected function tearDown(): void
    {
        $this->removePages();
        $this->coldCaches();
    }

    public function testAPageOfFifteenBlocksReadsAllItsWordsInOneQuery(): void
    {
        $this->page(self::SMALL, 1);
        $this->page(self::LARGE, 5);

        [$small, $smallHtml] = $this->render(self::SMALL);
        [$large, $largeHtml] = $this->render(self::LARGE);

        self::assertSame(12, $large - $small, 'twelve more blocks cost twelve more content rows and not one query more for their words');

        foreach (range(0, 4) as $i) {
            self::assertStringContainsString('Tekst ' . $i . ' NL', $largeHtml);
            self::assertStringContainsString('data-en="Titel ' . $i . ' EN"', $largeHtml);
            self::assertStringContainsString('Kaart ' . $i . ' NL', $largeHtml);
        }
        self::assertStringContainsString('Tekst 0 NL', $smallHtml);
    }

    public function testWithoutThePreloadEveryBlockWouldCostAQueryForItsWords(): void
    {
        $this->page(self::LARGE, 5);
        $page = (new PageRepository())->findByContentKey(self::LARGE);
        $sections = (new PageSectionRepository())->findForPage((int) $page['id'], true);

        $this->coldCaches();
        BlockLocalization::defaultLanguage();
        $before = $this->selects();
        foreach ($sections as $section) {
            BlockLocalization::translations((string) SectionRegistry::contentTable((string) $section['section_type']), (int) $section['section_id']);
        }
        self::assertSame(15, $this->selects() - $before, 'one by one: a query per block');

        $this->coldCaches();
        BlockLocalization::defaultLanguage();
        $before = $this->selects();
        BlockLocalization::preloadSections($sections);
        foreach ($sections as $section) {
            BlockLocalization::translations((string) SectionRegistry::contentTable((string) $section['section_type']), (int) $section['section_id']);
        }
        self::assertSame(1, $this->selects() - $before, 'preloaded: one query for the page');
    }

    /** A page with $perType instances of each of the three block types, with words in two languages. */
    private function page(string $key, int $perType): void
    {
        $pageId = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'Preloadtest');
        $sections = new PageSectionRepository();

        for ($i = 0; $i < $perType; $i++) {
            foreach (['rich_text', 'cta_band', 'contact_card'] as $type) {
                [$id, $sectionKey] = SectionRegistry::create($type, $key);
                $sections->create($pageId, $key, $type, $sectionKey, $id);

                match ($type) {
                    'rich_text' => BlockLocalization::save('rich_text_sections', $id, 'nl', ['body' => '<p>Tekst ' . $i . ' NL</p>']),
                    'cta_band' => [
                        BlockLocalization::save('cta_bands', $id, 'nl', ['eyebrow' => 'Nieuw', 'title' => 'Titel ' . $i . ' NL', 'primary_label' => 'Knop']),
                        BlockLocalization::save('cta_bands', $id, 'en', ['title' => 'Titel ' . $i . ' EN']),
                    ],
                    'contact_card' => BlockLocalization::save('contact_cards', $id, 'nl', ['title' => 'Kaart ' . $i . ' NL']),
                };
            }
        }
    }

    /** @return array{0: int, 1: string} the SELECTs the render cost, and its markup */
    private function render(string $key): array
    {
        $this->coldCaches();

        $before = $this->selects();
        ob_start();
        try {
            SectionRegistry::renderPage($key);
        } finally {
            $html = (string) ob_get_clean();
        }

        return [$this->selects() - $before, $html];
    }

    private function coldCaches(): void
    {
        PageContent::clearCache();
        SiteLanguages::clearCache();
        SiteSettings::clearCache();
        RichTextContent::clearCache();
        CtaBandContent::clearCache();
        ContactCardContent::clearCache();
        BlockLocalization::clearCache();
    }

    private function selects(): int
    {
        return (int) Database::connection()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }

    private function removePages(): void
    {
        foreach ([self::SMALL, self::LARGE] as $key) {
            $page = (new PageRepository())->findByContentKey($key);
            if ($page !== null) {
                PageService::delete($page);
            }
        }

        PageContent::clearCache();
    }
}
