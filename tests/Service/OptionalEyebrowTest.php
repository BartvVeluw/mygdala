<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Blocks\TranslatableField;
use PHPUnit\Framework\TestCase;

/**
 * The eyebrow contract, over EVERY registered block that has one: empty
 * means nothing is rendered.
 *
 *   - no block declares its eyebrow required, so the server never refuses
 *     an empty one and no editor marks it (admin_localized_optional_attr);
 *   - an empty eyebrow prints no element, so .eyebrow::before draws no line;
 *   - the spacing that belongs to an eyebrow hangs off the eyebrow, so no
 *     margin is left above a heading that has none.
 *
 * The save itself over HTTP is BlockWordsEditorHttpTest
 * ::testAnEmptyEyebrowIsSavedInTheDefaultLanguage.
 */
final class OptionalEyebrowTest extends TestCase
{
    private const ALL_MODULES = ['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true, 'multilingual' => true];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(self::ALL_MODULES);
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function blocksWithAnEyebrow(): array
    {
        ModuleRegistry::overrideForTests(self::ALL_MODULES);

        $cases = [];
        foreach (BlockDefinitions::types() as $type) {
            foreach (BlockDefinitions::get($type)?->translatableFields() ?? [] as $fields) {
                foreach ($fields as $field) {
                    if ($field->key === 'eyebrow') {
                        $cases[$type] = [$type];
                    }
                }
            }
        }

        ModuleRegistry::overrideForTests(null);

        return $cases;
    }

    public function testTheAuditFoundEveryBlockWithAnEyebrow(): void
    {
        $types = array_keys(self::blocksWithAnEyebrow());
        sort($types);

        self::assertSame(
            ['card_carousel', 'cta_band', 'faq', 'feature_grid', 'homepage_hero', 'item_gallery', 'page_hero', 'project_cards', 'step_list', 'text_image_split'],
            $types
        );
    }

    /**
     * @dataProvider blocksWithAnEyebrow
     */
    public function testNoBlockRequiresItsEyebrow(string $type): void
    {
        foreach (BlockDefinitions::get($type)?->translatableFields() ?? [] as $table => $fields) {
            foreach ($fields as $field) {
                self::assertInstanceOf(TranslatableField::class, $field);
                if ($field->key === 'eyebrow') {
                    self::assertFalse($field->required, "{$type}: {$table}.eyebrow must be optional");
                }
            }
        }
    }

    /**
     * @dataProvider blocksWithAnEyebrow
     */
    public function testAnEmptyEyebrowPrintsNoElement(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        self::assertNotNull($definition);
        $sample = $definition->sampleContent(new BlockSamples());
        if ($sample === null || (!array_key_exists('eyebrow', $sample) && !array_key_exists('items', $sample))) {
            // project_cards has no eyebrow input; its sample carries none.
            self::assertSame('project_cards', $type);

            return;
        }

        $with = $this->render($type, self::withEyebrow($sample, 'Bovenlabel'));
        self::assertStringContainsString('>Bovenlabel</p>', $with, $type);
        self::assertMatchesRegularExpression('/<p class="eyebrow[" ]/', $with, $type);

        foreach (['', '   '] as $empty) {
            $without = $this->render($type, self::withEyebrow($sample, $empty));
            self::assertStringNotContainsString('class="eyebrow', $without, $type);
            self::assertStringNotContainsString('eyebrow"', $without, $type);
        }
    }

    /**
     * The sample with this eyebrow: on the block, or on every item of a block
     * whose eyebrow is an item's (Tekst met afbeelding).
     *
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    private static function withEyebrow(array $sample, string $eyebrow): array
    {
        if (array_key_exists('eyebrow', $sample)) {
            return ['eyebrow' => $eyebrow] + $sample;
        }

        $sample['items'] = array_map(static fn (array $item): array => ['eyebrow' => $eyebrow] + $item, $sample['items']);

        return $sample;
    }

    public function testTheHelperEscapesAndPrintsNothingWhenEmpty(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/eyebrow.php';

        ob_start();
        render_eyebrow('');
        render_eyebrow(" \t ");
        self::assertSame('', ob_get_clean());

        ob_start();
        render_eyebrow('<b>Nieuw</b>', 'hero__eyebrow');
        self::assertSame('<p class="eyebrow hero__eyebrow">&lt;b&gt;Nieuw&lt;/b&gt;</p>', ob_get_clean());
    }

    public function testNoPartialWritesItsOwnEyebrowElement(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/partials/section-*.php') ?: [] as $file) {
            self::assertStringNotContainsString('<p class="eyebrow', (string) file_get_contents($file), basename($file) . ' must use render_eyebrow()');
        }
    }

    public function testTheHeadingSpacingBelongsToTheEyebrow(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');

        self::assertStringContainsString('.section-head .eyebrow + h2{ margin-top: var(--sp-2); }', $css);
        self::assertDoesNotMatchRegularExpression('/\.section-head h2\s*\{[^}]*margin-top/', $css);
    }

    /** @param array<string, mixed> $content */
    private function render(string $type, array $content): string
    {
        $definition = BlockDefinitions::get($type);
        self::assertNotNull($definition);

        ob_start();
        try {
            $definition->renderSample($content, $type . '-preview');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
