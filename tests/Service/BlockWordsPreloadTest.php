<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CardCarouselRepository;
use App\Repository\DetailSectionRepository;
use App\Repository\FaqRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\CardCarouselContent;
use App\Service\ContactCardContent;
use App\Service\CtaBandContent;
use App\Service\DetailSectionContent;
use App\Service\FaqContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\RichTextContent;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use App\Service\TextImageSplitContent;
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
 *
 * Phase 3B added child rows that own words (a question, a card, a card's
 * tags): those come with the same query, so a repeater with six times the
 * items costs exactly what it cost with one.
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

        [$english, $englishHtml] = $this->in('en', fn (): array => $this->render(self::LARGE));
        self::assertSame($large, $english, 'another language reads the same one query for its words');

        foreach (range(0, 4) as $i) {
            self::assertStringContainsString('Tekst ' . $i . ' NL', $largeHtml);
            self::assertStringContainsString('Titel ' . $i . ' NL', $largeHtml);
            self::assertStringContainsString('Kaart ' . $i . ' NL', $largeHtml);
            self::assertStringContainsString('Titel ' . $i . ' EN', $englishHtml, 'the page in English shows the English words');
            self::assertStringNotContainsString('Titel ' . $i . ' NL', $englishHtml);
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

    public function testTheItemsOfARepeaterComeWithThePagesOneQueryForWords(): void
    {
        $this->repeaters(self::SMALL, 1);
        $this->repeaters(self::LARGE, 6);

        [$small, $smallHtml] = $this->render(self::SMALL);
        [$large, $largeHtml] = $this->render(self::LARGE);

        self::assertSame($small, $large, 'six questions, points, paragraphs and cards with six tags each cost not one query more than one of each');

        foreach (['Vraag 5 NL', 'Punt 5 NL', 'Alinea 5 NL', 'Kaart 5 NL', 'Label 5.5 NL'] as $words) {
            self::assertStringContainsString($words, $largeHtml);
        }
        self::assertStringContainsString('Question 5 EN', $this->in('en', fn (): array => $this->render(self::LARGE))[1]);
        self::assertStringContainsString('Label 0.0 NL', $smallHtml);
    }

    /**
     * A page with one FAQ, Detailsectie, Tekst met afbeelding and
     * Kaarten-carrousel, each with $items child rows (and each card with
     * $items tags), all with words of their own.
     */
    private function repeaters(string $key, int $items): void
    {
        $pageId = PageFixture::create(['content_key' => $key, 'slug' => $key, 'status' => PageContent::STATUS_PUBLISHED], 'Preloadtest');
        $sections = new PageSectionRepository();

        foreach (['faq', 'detail_section', 'text_image_split', 'card_carousel'] as $type) {
            [$id, $sectionKey] = SectionRegistry::create($type, $key);
            $sections->create($pageId, $key, $type, $sectionKey, $id);

            for ($i = 0; $i < $items; $i++) {
                switch ($type) {
                    case 'faq':
                        $itemId = (new FaqRepository())->createItem($id);
                        BlockLocalization::save('faq_items', $itemId, 'nl', ['question' => 'Vraag ' . $i . ' NL', 'answer' => 'Antwoord']);
                        BlockLocalization::save('faq_items', $itemId, 'en', ['question' => 'Question ' . $i . ' EN']);
                        break;
                    case 'detail_section':
                        BlockLocalization::save('detail_section_points', (new DetailSectionRepository())->createPoint($id), 'nl', ['title' => 'Punt ' . $i . ' NL', 'body' => 'Uitleg']);
                        break;
                    case 'text_image_split':
                        BlockLocalization::save('text_image_split_paragraphs', (new TextImageSplitRepository())->createParagraph($id), 'nl', ['content' => 'Alinea ' . $i . ' NL']);
                        break;
                    case 'card_carousel':
                        $cards = new CardCarouselRepository();
                        $cardId = $cards->createCard($id);
                        BlockLocalization::save('carousel_cards', $cardId, 'nl', ['title' => 'Kaart ' . $i . ' NL']);
                        for ($j = 0; $j < $items; $j++) {
                            BlockLocalization::save('carousel_card_tags', $cards->createTag($cardId), 'nl', ['label' => 'Label ' . $i . '.' . $j . ' NL']);
                        }
                        break;
                }
            }
        }
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
    /**
     * Run $work while the request is answered in $language.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function in(string $language, \Closure $work): mixed
    {
        RequestLanguage::set($language, true);

        try {
            return $work();
        } finally {
            RequestLanguage::reset();
        }
    }

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
        FaqContent::clearCache();
        DetailSectionContent::clearCache();
        TextImageSplitContent::clearCache();
        CardCarouselContent::clearCache();
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
