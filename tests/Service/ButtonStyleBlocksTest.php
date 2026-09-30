<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every block with an editor's button renders the button style its editor
 * chose (Button Styles 2.0), and nothing else when it chose none. Each block
 * is rendered through its own renderSample() — the partial render() calls —
 * with the library's sample words, once as stored before Button Styles
 * (no choice) and once with a choice per button:
 *
 *   - no choice: the old classes (btn, btn btn--ghost …) and the old arrow
 *     where the block always drew one, not a single btn-style class;
 *   - a choice: `btn btn-style-<id>` on every button that chose, the old
 *     look class (btn--ghost, btn--sm) gone, layout classes kept, and no old
 *     arrow: the style draws its own icon;
 *   - two buttons of one block, and each row of a repeater, choose on their
 *     own.
 *
 * CONNECTED is the closed list of blocks with a "Knopstijl" field; a block
 * that gets a button of its own belongs in it (CONTENT-BLOCKS.md,
 * "Knopstijl").
 */
final class ButtonStyleBlocksTest extends TestCase
{
    private const STYLE = 91;
    private const OTHER = 92;

    /**
     * Block type => where its choices live in its content.
     *
     *   'fields' => content keys holding a choice
     *   'rows'   => [content key of the rows, key of the choice in a row]
     *
     * @var array<string, array{fields?: list<string>, rows?: array{0: string, 1: string}, span?: bool}>
     */
    private const CONNECTED = [
        'cta_band' => ['fields' => ['primary_button_style', 'secondary_button_style']],
        'homepage_hero' => ['fields' => ['primary_button_style', 'secondary_button_style']],
        'rich_text' => ['fields' => ['button_style']],
        'detail_section' => ['fields' => ['button_style']],
        'contact_card' => ['fields' => ['button_style']],
        'item_gallery' => ['fields' => ['button_style']],
        'featured_product' => ['fields' => ['button_style']],
        'text_image_split' => ['rows' => ['items', 'button_style']],
        'card_carousel' => ['rows' => ['cards', 'button_style']],
        'hover_card_grid' => ['rows' => ['cards', 'button_style'], 'span' => true],
    ];

    protected function setUp(): void
    {
        RequestLanguage::reset();
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true, 'multilingual' => true]);
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        RequestLanguage::reset();
    }

    /** @return iterable<string, array{string}> */
    public static function connected(): iterable
    {
        foreach (array_keys(self::CONNECTED) as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('connected')]
    public function testWithoutAChoiceTheButtonIsWhatItAlwaysWas(string $type): void
    {
        $html = $this->render($type, null);

        self::assertStringNotContainsString('btn-style-', $html, $type);
        self::assertGreaterThan(0, substr_count($html, 'class="btn') + substr_count($html, 'hover-card__cta'), $type . ': the sample shows its button');
    }

    #[DataProvider('connected')]
    public function testAChoiceGivesEveryButtonThatChoseItsStyle(string $type): void
    {
        $spec = self::CONNECTED[$type];
        $legacy = $this->render($type, null);
        $html = $this->render($type, self::STYLE);
        $styled = preg_match_all('/class="btn btn-style-' . self::STYLE . '(?: [a-z_-]+)*"/', $html);

        self::assertGreaterThan(0, $styled, $type . ': the sample has buttons to choose for');
        if (!empty($spec['span'])) {
            self::assertSame(substr_count($legacy, 'class="hover-card__cta hover-card__link"'), $styled, $type);
        } else {
            // Every button is either one that chose, or a functional one the
            // block also draws (Uitgelicht product's "In winkelwagen").
            $remaining = preg_match_all('/class="btn(?: (?!btn-style)[^"]*)?"/', $html);
            self::assertSame(preg_match_all('/class="btn(?: [^"]*)?"/', $legacy), $styled + $remaining, $type);
        }
        self::assertDoesNotMatchRegularExpression('/btn-style-' . self::STYLE . '[^"]*"[^>]*>[^<]*<svg class="btn__arrow"/', $html, $type . ': the style draws the icon');
        self::assertDoesNotMatchRegularExpression('/class="btn btn-style-' . self::STYLE . '[^"]*btn--(?:ghost|sm)/', $html, $type . ': the old look class gives way');

        if (!empty($spec['span'])) {
            self::assertMatchesRegularExpression('/<a class="hover-card__cta hover-card__cta--button hover-card__link" href="[^"]*"><span class="btn btn-style-' . self::STYLE . '">/', $html, 'the card-wide link keeps its own ::after');
        }
    }

    public function testTwoButtonsOfOneBlockChooseOnTheirOwn(): void
    {
        $definition = BlockDefinitions::get('cta_band');
        $content = $definition->sampleContent(new BlockSamples());
        $content['primary_button_style'] = self::STYLE;
        $content['secondary_button_style'] = null;

        $html = $this->capture(static fn () => $definition->renderSample($content, 'cta-preview'));

        self::assertSame(1, substr_count($html, 'btn-style-' . self::STYLE));
        self::assertStringContainsString('class="btn btn--ghost"', $html, 'the second button stays on its default');
    }

    public function testEachRowOfARepeaterChoosesOnItsOwn(): void
    {
        $definition = BlockDefinitions::get('text_image_split');
        $content = $definition->sampleContent(new BlockSamples());
        $rows = array_keys(array_filter($content['items'], static fn (array $item): bool => ($item['button_label'] ?? '') !== ''));
        self::assertGreaterThanOrEqual(1, count($rows));

        $content['items'][$rows[0]]['button_style'] = self::STYLE;
        foreach (array_slice($rows, 1) as $index) {
            $content['items'][$index]['button_style'] = self::OTHER;
        }

        $html = $this->capture(static fn () => $definition->renderSample($content, 'tis-preview'));

        self::assertSame(1, substr_count($html, 'btn-style-' . self::STYLE . ' '));
        self::assertSame(count($rows) - 1, substr_count($html, 'btn-style-' . self::OTHER . ' '));
        self::assertSame(count($rows), substr_count($html, 'text-image__button"'), 'the layout class stays on every row');
    }

    public function testFunctionalButtonsFollowTheDefaultWithoutAChoice(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/form.php');

        self::assertMatchesRegularExpression('/<button type="submit" class="btn btn--block">[^\n]*\n\s*<svg class="btn__arrow"/', $form, 'the form\'s arrow is the default\'s to replace');
        self::assertStringContainsString('class="btn" data-product-add-to-cart', (string) file_get_contents(dirname(__DIR__, 2) . '/partials/product-purchase.php'));
    }

    private function render(string $type, ?int $choice): string
    {
        $definition = BlockDefinitions::get($type);
        $content = $definition->sampleContent(new BlockSamples());
        self::assertIsArray($content, $type);

        $spec = self::CONNECTED[$type];
        foreach ($spec['fields'] ?? [] as $field) {
            $content[$field] = $choice;
        }
        if (isset($spec['rows'])) {
            [$list, $field] = $spec['rows'];
            foreach (array_keys($content[$list] ?? []) as $index) {
                $content[$list][$index][$field] = $choice;
            }
        }

        return $this->capture(static fn () => $definition->renderSample($content, $type . '-preview'));
    }

    private function capture(\Closure $render): string
    {
        ob_start();
        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
