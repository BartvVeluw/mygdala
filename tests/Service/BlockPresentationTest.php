<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockPreview;
use PHPUnit\Framework\TestCase;

/**
 * The PRESENTATION half of the block contract: what the CMS shows a human
 * about a content block before they choose it.
 *
 * Since the page editor gained a visual picker and a Contentblokken
 * catalogue, two screens describe the same nineteen blocks. The failure this
 * file exists to prevent is the obvious one — two copies of that description
 * drifting apart, or a new block appearing in one screen with a blank card
 * because nobody remembered there was a second place to fill in. So the
 * metadata lives on the block's own definition, both screens read it from
 * there, and the checks below are over EVERY registered type rather than over
 * a list someone maintains.
 *
 * Everything here reads registered definitions and project source only — no
 * database and no web server — so it belongs to the `contract` tier.
 * Behaviour of the picker's markup lives in
 * Tests\Service\BlockPickerTest; what an editor may add to which page is
 * still Tests\Service\SectionRegistryTest's.
 */
final class BlockPresentationTest extends TestCase
{
    protected function tearDown(): void
    {
        // overrideForTests(null) and not reset(): reset() forgets the caches
        // but KEEPS the override, so a test that switched the Shop off would
        // leave it off for the rest of the run.
        ModuleRegistry::overrideForTests(null);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function registeredTypes(): array
    {
        $cases = [];
        foreach (BlockDefinitions::types() as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testThePresentationContractIsAbstractSoANewBlockCannotForgetIt(): void
    {
        foreach (['description', 'category', 'icon'] as $method) {
            $this->assertTrue(
                (new \ReflectionMethod(BlockDefinition::class, $method))->isAbstract(),
                "BlockDefinition::{$method}() must stay abstract — a default here is how a block "
                . 'ends up in the picker with a blank card and nothing failing'
            );
        }
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryBlockHasALabelADescriptionAndACategory(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        $this->assertInstanceOf(BlockDefinition::class, $definition);

        $this->assertNotSame('', trim($definition->label()), "{$type} has no label");
        $this->assertSame(
            $definition->meta()['label'],
            $definition->label(),
            "{$type}: label() and meta()['label'] must be the same string — one block, one name"
        );

        $description = trim($definition->description());
        $this->assertNotSame('', $description, "{$type} has no description");
        $this->assertGreaterThan(
            25,
            mb_strlen($description),
            "{$type}'s description is too short to tell an editor what they would get"
        );

        $this->assertTrue(
            BlockCategories::has($definition->category()),
            "{$type} names category \"{$definition->category()}\", which BlockCategories does not register"
        );
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryBlockHasAnIconAndAValidSchematicPreview(string $type): void
    {
        $definition = BlockDefinitions::get($type);

        $icon = trim($definition->icon());
        $this->assertNotSame('', $icon, "{$type} has no icon");
        $this->assertStringStartsWith('<', $icon, "{$type}'s icon must be SVG shape markup");
        $this->assertStringNotContainsStringIgnoringCase(
            '<script',
            $icon,
            "{$type}'s icon is echoed unescaped as trusted first-party markup and must stay shapes only"
        );

        foreach ($definition->preview() as $part) {
            $this->assertTrue(
                BlockPreview::has($part),
                "{$type} asks for preview part \"{$part}\", which is not in BlockPreview's closed vocabulary"
            );
        }

        foreach ($definition->useCases() as $case) {
            $this->assertIsString($case);
            $this->assertNotSame('', trim($case), "{$type} has an empty use case");
        }
    }

    /**
     * An editor sees words, never the key the database stores. A card reading
     * "text_image_split" or "detail_sections" would be exactly the leak the
     * picker exists to close.
     *
     * Only snake_case identifiers are checked, and that is the whole point:
     * a one-word key like `form` is also an ordinary Dutch word, and
     * forbidding "formulier" in a form block's description would be
     * forbidding it from describing itself. What must never appear is a
     * string that reads as an identifier.
     *
     * @dataProvider registeredTypes
     */
    public function testNoBlockShowsAnIdentifierToTheEditor(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        $visible = $definition->label() . ' ' . $definition->description() . ' ' . implode(' ', $definition->useCases());

        $this->assertDoesNotMatchRegularExpression(
            '/[a-z]+_[a-z_]+/',
            $visible,
            "{$type} shows a snake_case identifier — describe the block in the editor's own words"
        );

        foreach (BlockDefinitions::types() as $otherType) {
            if (str_contains($otherType, '_')) {
                $this->assertStringNotContainsString($otherType, $visible, "{$type} names the registry key {$otherType}");
            }
        }

        $table = $definition->contentTable();
        if ($table !== null) {
            $this->assertStringNotContainsString($table, $visible, "{$type} names its own database table to the editor");
        }
    }

    public function testLabelsAreUniqueSoTwoCardsAreNeverTheSameCard(): void
    {
        $labels = [];
        foreach (BlockDefinitions::all() as $type => $definition) {
            $label = mb_strtolower($definition->label());
            $this->assertArrayNotHasKey(
                $label,
                $labels,
                "\"{$definition->label()}\" is the label of both {$type} and " . ($labels[$label] ?? '')
            );
            $labels[$label] = $type;
        }

        $this->assertCount(count(BlockDefinitions::types()), $labels);
    }

    /**
     * Asked with every module ON, because that is the only configuration in
     * which "is this drawer ever used?" is a meaningful question — with the
     * Shop off the Shop category legitimately has nothing in it, and the
     * screens leave the heading out entirely.
     */
    public function testEveryRegisteredCategoryIsOneSomeBlockActuallyUses(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);

        $used = [];
        foreach (BlockDefinitions::all() as $definition) {
            $used[$definition->category()] = true;
        }

        foreach (BlockCategories::keys() as $key) {
            $this->assertArrayHasKey(
                $key,
                $used,
                "category \"{$key}\" is registered but no block uses it — an empty drawer in the picker"
            );
        }
    }

    public function testGroupingFollowsTheRegisteredCategoryOrderAndDropsEmptyDrawers(): void
    {
        $grouped = BlockCategories::group(BlockDefinitions::all());

        $this->assertSame(
            array_values(array_intersect(BlockCategories::keys(), array_keys($grouped))),
            array_keys($grouped),
            'the picker and the catalogue must show categories in BlockCategories order'
        );

        foreach ($grouped as $blocks) {
            $this->assertNotSame([], $blocks);
        }

        $this->assertCount(
            count(BlockDefinitions::types()),
            array_merge(...array_values($grouped)),
            'grouping must lose no block'
        );

        $this->assertSame([], BlockCategories::group([]));
    }

    /**
     * A module's block is presented like any other block while its module is
     * on, and is simply absent while it is off — not greyed out, not
     * "unavailable", absent. The catalogue therefore cannot leak a Shop block
     * to a CMS-only deployment, because there is nothing to leak.
     */
    public function testAModuleBlockIsFullyDescribedWhileEnabledAndGoneWhileDisabled(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true]);
        $enabled = BlockDefinitions::all();

        $this->assertArrayHasKey('product_grid', $enabled);
        $this->assertSame(BlockCategories::SHOP, $enabled['product_grid']->category());
        $this->assertNotSame('', trim($enabled['product_grid']->description()));
        $this->assertArrayHasKey(BlockCategories::SHOP, BlockCategories::group($enabled));

        ModuleRegistry::overrideForTests(['shop' => false]);
        $disabled = BlockDefinitions::all();

        $this->assertArrayNotHasKey('product_grid', $disabled);
        $this->assertArrayNotHasKey('shop_collections', $disabled);
        $this->assertArrayNotHasKey(
            BlockCategories::SHOP,
            BlockCategories::group($disabled),
            'with the Shop off the catalogue must not show an empty Shop heading'
        );

        // And Core is untouched by the module being off.
        $this->assertArrayHasKey('rich_text', $disabled);
    }

    /**
     * The whole reason the metadata sits on the definitions: the two screens
     * that show it must READ it, never carry a second copy of the words.
     */
    public function testThePickerAndTheCatalogueDescribeBlocksOnlyFromTheirDefinitions(): void
    {
        $screens = ['admin/_block_picker.php', 'admin/content-blocks.php'];

        foreach ($screens as $screen) {
            $source = $this->sourceOf($screen);

            foreach (BlockDefinitions::all() as $type => $definition) {
                $this->assertStringNotContainsString(
                    $definition->description(),
                    $source,
                    "{$screen} spells out {$type}'s description instead of asking the definition for it"
                );
                $this->assertStringNotContainsString(
                    "'" . $type . "'",
                    $source,
                    "{$screen} names the block type {$type} — these screens must stay type-agnostic"
                );
            }

            $this->assertStringContainsString(
                '->describedFor()',
                $source,
                "{$screen} must read the description off the definition"
            );
        }
    }

    /**
     * The catalogue is a reading screen. It has no form, and therefore no way
     * to become a second, weaker path to creating or deleting a block beside
     * the guarded endpoints.
     */
    public function testTheCatalogueWritesNothing(): void
    {
        $source = $this->sourceOf('admin/content-blocks.php');

        $this->assertStringNotContainsString('<form', $source);
        $this->assertStringNotContainsString('api/admin/', $source);
        $this->assertStringContainsString("AdminAuth::requirePermission('pages.manage')", $source);
    }
}
