<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Blocks\CardHeading;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-product-grid.php';
require_once dirname(__DIR__, 2) . '/partials/related-products.php';

/**
 * The heading contract of every block of cards (App\Service\Blocks\CardHeading,
 * CONTENT-BLOCKS.md "Koppen in kaarten"), without a database:
 *
 *   - two tags only: h3 under the block's own title, h2 without one;
 *   - every block's sample, with its title and without it, keeps the outline
 *     of a page whose h1 comes first: no level is skipped anywhere, and no
 *     heading is empty;
 *   - the card titles of a block keep one class whichever tag they get, so
 *     only the tag changes and never the look;
 *   - a block without a title of its own (Contactkaart) and
 *     the product cards the browser draws follow the same rule, the latter
 *     through their grid's data-card-heading, and the script accepts only
 *     h2 and h3;
 *   - no block partial writes a card title's tag by hand any more.
 *
 * The same outline on a real page, a removed title and a second language:
 * Tests\Service\CardHeadingPageTest.
 */
final class CardHeadingContractTest extends TestCase
{
    /** The blocks of cards with an optional title of their own, and the class every card title keeps. */
    private const CARD_BLOCKS = [
        'feature_grid' => 'feature-card__title',
        'step_list' => 'process-step__title',
        'card_carousel' => 'orbit-card__title',
        'hover_card_grid' => 'hover-card__title',
        // An optional head of its own since v0.1.15 (App\Service\Blocks\BlockHead).
        'shop_collections' => 'collection-tile__name',
    ];

    /** Blocks that have no title of their own at all, and the class of the heading of each card. */
    private const UNTITLED_BLOCKS = [
        'contact_card' => 'contact-card__title',
    ];

    /** The one h3 a block partial still writes itself: the specifications under a featured product's own h2. */
    private const OWN_H3 = ['section-featured-product.php' => ['product-specs__heading']];

    public function testThereAreTwoLevelsAndTheBlockTitleDecides(): void
    {
        self::assertSame(['h2', 'h3'], CardHeading::LEVELS);
        self::assertSame('h3', CardHeading::under(true), 'under the block title');
        self::assertSame('h2', CardHeading::under(false), 'without one');
    }

    public function testEveryBlockKeepsTheOutlineWithItsTitleAndWithout(): void
    {
        $checked = 0;
        $problems = [];
        foreach (BlockDefinitions::all() as $type => $definition) {
            $sample = $definition->sampleContent(new BlockSamples());
            if ($sample === null) {
                continue;
            }

            $variants = ['as sampled' => $sample];
            if (is_string($sample['title'] ?? null) && $sample['title'] !== '') {
                $variants['without its title'] = ['title' => ''] + $sample;
            }

            foreach ($variants as $variant => $content) {
                $html = self::render($definition, $content);
                if (preg_match('#<(h[1-6])\b[^>]*>\s*</\1>#i', $html) === 1) {
                    $problems[] = "{$type} {$variant}: an empty heading";
                }
                $skip = self::skippedLevel(self::levels($html));
                if ($skip !== null) {
                    $problems[] = "{$type} {$variant}: {$skip}";
                }
                $checked++;
            }
        }

        self::assertSame([], $problems, 'a block breaks the outline of the page it is on');
        self::assertGreaterThan(20, $checked, 'every block with a sample was rendered');
    }

    public function testACardTitleIsAnH3UnderTheBlockTitleAndAnH2WithoutOneInTheSameClass(): void
    {
        foreach (self::CARD_BLOCKS as $type => $class) {
            $definition = self::definition($type);
            $sample = $definition->sampleContent(new BlockSamples());
            self::assertIsArray($sample, $type);
            self::assertNotSame('', (string) ($sample['title'] ?? ''), "{$type}: the sample has a block title to take away");

            $withTitle = self::render($definition, $sample);
            $cards = self::tagsWithClass($withTitle, $class);
            self::assertNotSame([], $cards, "{$type}: the sample has card titles");
            self::assertSame(array_fill(0, count($cards), 'h3'), $cards, "{$type}: under the block title every card title is an h3");
            self::assertSame(1, substr_count($withTitle, '<h2'), "{$type}: the only h2 is the block's own title");

            $withoutTitle = self::render($definition, ['title' => ''] + $sample);
            self::assertSame(array_fill(0, count($cards), 'h2'), self::tagsWithClass($withoutTitle, $class), "{$type}: without a block title every card title is an h2");
            self::assertStringNotContainsString('<h3', $withoutTitle, "{$type}: and no h3 is left");
        }
    }

    /**
     * An item's own title is the heading this is about: its body is rich text
     * an editor wrote, headings of its own included.
     */
    public function testATextAndImageItemFollowsTheSameRule(): void
    {
        $definition = self::definition('text_image_split');
        $sample = $definition->sampleContent(new BlockSamples());
        self::assertIsArray($sample);
        $itemTitle = htmlspecialchars((string) ($sample['items'][0]['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        self::assertNotSame('', $itemTitle, 'the sample has an item with a title');
        $sample['title'] = 'Wat wij doen';

        $withTitle = self::render($definition, $sample);
        self::assertStringContainsString('<h2>Wat wij doen</h2>', $withTitle, 'the block title');
        self::assertStringContainsString('<h3>' . $itemTitle . '</h3>', $withTitle, 'under it an item title is an h3');

        $withoutTitle = self::render($definition, ['title' => ''] + $sample);
        self::assertStringContainsString('<h2>' . $itemTitle . '</h2>', $withoutTitle, 'without it the h2 it always was');
        self::assertStringNotContainsString('<h3>' . $itemTitle, $withoutTitle);
    }

    public function testABlockWithoutATitleOfItsOwnGivesItsCardsAnH2(): void
    {
        foreach (self::UNTITLED_BLOCKS as $type => $class) {
            $definition = self::definition($type);
            $sample = $definition->sampleContent(new BlockSamples());
            self::assertIsArray($sample, $type);

            $html = self::render($definition, $sample);
            $cards = self::tagsWithClass($html, $class);
            self::assertNotSame([], $cards, "{$type}: the sample has a card heading");
            self::assertSame(array_fill(0, count($cards), 'h2'), $cards, "{$type}: no title above, so an h2");
            self::assertStringNotContainsString('<h3', $html, $type);
        }
    }

    public function testTheProductCardsTakeTheirLevelFromTheirGrid(): void
    {
        self::assertStringContainsString('data-card-heading="h2"', self::capture(static fn () => render_section_product_grid()), 'a Productgrid without a head');
        self::assertStringContainsString('data-card-heading="h2"', self::capture(static fn () => render_section_product_grid(['eyebrow' => 'Winkel', 'title' => '', 'lead' => 'Alles'])), 'a head without a title');
        self::assertStringContainsString('data-card-heading="h3"', self::capture(static fn () => render_section_product_grid(['eyebrow' => '', 'title' => 'Alle producten', 'lead' => ''])), 'under a title of the Productgrid');
        self::assertStringContainsString('data-card-heading="h3"', self::capture(static fn () => render_related_products(['product_ids' => [1, 2], 'heading' => 'Meer zoals dit'])), 'under the related products\' heading');
        self::assertStringContainsString('data-card-heading="h2"', self::capture(static fn () => render_related_products(['product_ids' => [1, 2], 'heading' => ''])), 'related products without a heading');

        $root = dirname(__DIR__, 2);
        foreach (['collectie.php', 'personaliseren.php'] as $page) {
            self::assertStringContainsString(
                'data-card-heading="<?= \\App\\Service\\Blocks\\CardHeading::under(false) ?>"',
                (string) file_get_contents($root . '/' . $page),
                "{$page}: its grid stands straight under the h1"
            );
        }

        $script = (string) file_get_contents($root . '/assets/js/shop/shop.js');
        self::assertStringContainsString('var cardHeading = grid.getAttribute("data-card-heading") === "h2" ? "h2" : "h3";', $script, 'only h2 or h3, whatever the attribute says');
        self::assertStringContainsString('"<" + cardHeading + \' class="product-card__title">\' + S.escapeHtml(cardName) + "</" + cardHeading + ">"', $script);
        self::assertStringNotContainsString('"<h3>" + S.escapeHtml(cardName)', $script, 'no card title with a fixed tag');
    }

    public function testNoBlockPartialWritesACardHeadingTagByHand(): void
    {
        $partials = glob(dirname(__DIR__, 2) . '/partials/section-*.php');
        self::assertIsArray($partials);
        self::assertNotEmpty($partials);

        foreach ($partials as $path) {
            $file = basename($path);
            $source = (string) file_get_contents($path);

            preg_match_all('#<h3(?:\s+class="([^"]*)")?#', $source, $matches);
            foreach ($matches[1] as $class) {
                self::assertContains($class, self::OWN_H3[$file] ?? [], "{$file} writes an h3 itself; a card title takes its tag from App\\Service\\Blocks\\CardHeading");
            }

            self::assertDoesNotMatchRegularExpression('#[\'"]h[23][\'"]#', $source, "{$file} names a heading tag; CardHeading holds them");
        }
    }

    // ------------------------------------------------------------ helpers

    private static function definition(string $type): BlockDefinition
    {
        $definition = BlockDefinitions::get($type);
        self::assertNotNull($definition, $type);

        return $definition;
    }

    /** @param array<string, mixed> $content */
    private static function render(BlockDefinition $definition, array $content): string
    {
        return self::capture(static fn () => $definition->renderSample($content, $definition->type() . '-preview'));
    }

    private static function capture(callable $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<int> every heading's level, in document order */
    private static function levels(string $html): array
    {
        preg_match_all('#<h([1-6])\b#i', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    /** @return list<string> the tag of every heading with this class, in document order */
    private static function tagsWithClass(string $html, string $class): array
    {
        preg_match_all('#<(h[1-6])\s+class="' . preg_quote($class, '#') . '"#', $html, $matches);

        return $matches[1];
    }

    /**
     * The block sits on a page whose h1 comes first: every heading may go at
     * most one level deeper than the one before it.
     *
     * @param list<int> $levels
     */
    private static function skippedLevel(array $levels): ?string
    {
        $previous = 1;
        foreach ($levels as $level) {
            if ($level > $previous + 1) {
                return "an h{$level} after an h{$previous} skips a level";
            }
            $previous = $level;
        }

        return null;
    }
}
