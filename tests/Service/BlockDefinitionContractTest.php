<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\FixedBlockDefinition;
use App\Service\Blocks\TranslatableField;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The INTEGRATION CONTRACT behind one content block: every registered type is
 * one App\Service\Blocks\BlockDefinition that supplies, itself, everything
 * App\Service\SectionRegistry used to dispatch on per type.
 *
 * Why this test exists: adding a block used to mean editing seven places in
 * one 1050-line registry, and nothing warned you when you missed one — the
 * block simply broke later, at whichever action used the branch you forgot.
 * Two properties replace that vigilance, and this file pins both:
 *
 *  1. the contract is ABSTRACT, so a definition that forgets a piece does not
 *     load at all;
 *  2. registration is ONE list, so there is no second place to keep in sync.
 *
 * Everything here reads registered definitions and project source only — no
 * database and no web server — so it belongs to the `contract` tier and runs
 * in the fast loop. Behaviour against the real content tables (create, delete,
 * what a delete takes with it) stays in Tests\Service\SectionRegistryTest, and
 * the architecture rules around pages/instances in
 * Tests\Service\ContentBlockArchitectureTest.
 */
final class BlockDefinitionContractTest extends TestCase
{
    /**
     * Everything a block must answer for itself. These are abstract on
     * BlockDefinition on purpose — see testTheContractIsAbstract().
     */
    private const REQUIRED = [
        'type',
        'meta',
        'create',
        'deleteContent',
        'render',
        'editUrl',
        'clearCache',
        'contentTable',
    ];

    /**
     * A page_sections row shaped like a real one, without touching the
     * database: enough for the address-building half of the contract
     * (editUrl), which is pure string work.
     *
     * @return array<string, mixed>
     */
    private function pageSectionFor(string $type): array
    {
        return [
            'id' => 42,
            'page_id' => 7,
            'page_slug' => '__contract__',
            'section_type' => $type,
            'section_key' => 'custom-a1b2c3d4',
            'section_id' => 99,
            'sort_order' => 1,
            'is_active' => 1,
        ];
    }

    private function sourceOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
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

    public function testTheContractIsAbstractSoANewBlockCannotSilentlyForgetAPiece(): void
    {
        foreach (self::REQUIRED as $method) {
            $this->assertTrue(
                (new \ReflectionMethod(BlockDefinition::class, $method))->isAbstract(),
                "BlockDefinition::{$method}() must stay abstract — a default here is exactly how a "
                . 'block used to end up half-registered without anything failing'
            );
        }
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryRegisteredKeyResolvesToADefinitionThatKnowsItsOwnType(string $type): void
    {
        $definition = BlockDefinitions::get($type);

        $this->assertInstanceOf(BlockDefinition::class, $definition);
        $this->assertSame(
            $type,
            $definition->type(),
            'a definition registered under one key must not claim another — the key is the identity'
        );
        $this->assertTrue(BlockDefinitions::has($type));
        $this->assertTrue(SectionRegistry::exists($type));
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryDefinitionDeclaresTheCapabilitiesTheCmsNeeds(string $type): void
    {
        $meta = BlockDefinitions::get($type)->meta();

        $this->assertIsString($meta['label'], "{$type} needs a CMS name");
        $this->assertNotSame('', $meta['label']);
        $this->assertArrayHasKey('manual_add', $meta, "{$type} must declare whether it can be added by hand");
        $this->assertArrayHasKey('allow_multiple', $meta, "{$type} must declare whether it repeats");
        $this->assertArrayHasKey('max_instances', $meta, "{$type} must declare its instance cap (null = unlimited)");
        $this->assertArrayHasKey('allowed_pages', $meta, "{$type} must declare where it is allowed");
        $this->assertArrayHasKey('deletable', $meta);

        $this->assertSame(
            $meta,
            SectionRegistry::types()[$type],
            'the registry must publish the definition\'s own metadata, not a second copy of it'
        );
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testAnOrdinaryBlockOwnsContentAndAFixedBlockOwnsNone(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        $table = $definition->contentTable();

        if (SectionRegistry::isFixed($type)) {
            $this->assertInstanceOf(
                FixedBlockDefinition::class,
                $definition,
                "{$type} is not manually addable, so it must declare itself a fixed block"
            );
            $this->assertNull($table, 'a fixed block has no content row of its own');
            $this->assertFalse(SectionRegistry::isDeletable($type));
            $this->assertNotNull(SectionRegistry::kind($type), 'a fixed block must say what backs its content');

            return;
        }

        $this->assertNotInstanceOf(FixedBlockDefinition::class, $definition);
        $this->assertIsString($table, "{$type} is an ordinary block, so it must name the table holding its rows");
        $this->assertMatchesRegularExpression(
            '/^[a-z][a-z0-9_]*$/',
            (string) $table,
            'a content table name is a trusted identifier from a registered definition — never request data'
        );
        $this->assertSame($table, SectionRegistry::contentTable($type));
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryEditableInstanceLinksToAnEditorThatExists(string $type): void
    {
        $pageSection = $this->pageSectionFor($type);

        foreach (SectionRegistry::editLinks($pageSection) as $link) {
            $file = dirname(__DIR__, 2) . '/' . ltrim((string) parse_url($link['url'], PHP_URL_PATH), '/');
            $this->assertFileExists($file, "\"{$type}\" links to an editor that does not exist");
        }

        if (SectionRegistry::isFixed($type)) {
            $this->assertNull(
                BlockDefinitions::get($type)->editUrl($pageSection),
                "\"{$type}\" is a fixed block: its content is edited in the admin domain that owns it, "
                . 'so it has no per-instance editor of its own'
            );

            return;
        }

        $url = SectionRegistry::editUrl($pageSection);
        $this->assertIsString($url, "an editable block must say where it is edited; \"{$type}\" returned null");
        $this->assertStringStartsWith('/admin/', (string) $url);
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testARepeatableBlockAddressesTheInstanceAndNotJustThePage(string $type): void
    {
        if (!SectionRegistry::allowMultiple($type)) {
            // A block capped at one per page has nothing to disambiguate.
            $this->assertSame(1, SectionRegistry::maxInstances($type));

            return;
        }

        $url = (string) SectionRegistry::editUrl($this->pageSectionFor($type));

        $this->assertStringContainsString(
            urlencode('__contract__:custom-a1b2c3d4'),
            $url,
            "\"{$type}\" repeats, so its editor URL must carry (page_slug, section_key) — "
            . 'addressing the page alone is a hidden "one per page, for ever"'
        );
    }

    public function testAFixedBlockAnswersTheContentHalfOfTheContractByRefusingIt(): void
    {
        foreach (BlockDefinitions::all() as $type => $definition) {
            if (!$definition instanceof FixedBlockDefinition) {
                continue;
            }

            try {
                $definition->create('shop');
                $this->fail("\"{$type}\" is a fixed block and must refuse to be created");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($type, $e->getMessage());
            }

            try {
                $definition->deleteContent($this->pageSectionFor($type));
                $this->fail("\"{$type}\" is a fixed block and must refuse to be deleted");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($type, $e->getMessage());
            }
        }
    }

    public function testAnUnregisteredKeyNeverResolvesToAnything(): void
    {
        foreach (['__nope__', '', 'Product_Grid', 'product_grid ', '../product_grid'] as $key) {
            $this->assertFalse(BlockDefinitions::has($key), "\"{$key}\" must not be a registered type");
            $this->assertNull(BlockDefinitions::get($key));
            $this->assertNull(SectionRegistry::contentTable($key));
            $this->assertFalse(SectionRegistry::exists($key));
        }

        // Registration is by KEY, never by class name: a request that supplies
        // a class or a table cannot reach one.
        foreach (['App\\Service\\Blocks\\ProductGridBlock', 'ProductGridBlock', 'product_grids'] as $key) {
            $this->assertNull(
                BlockDefinitions::get($key),
                "\"{$key}\" is a class or table name — request data must never select one"
            );
        }
    }

    public function testTheRegistryNoLongerDispatchesPerBlockType(): void
    {
        $source = $this->sourceOf('src/Service/SectionRegistry.php');

        foreach (BlockDefinitions::types() as $type) {
            $this->assertStringNotContainsString(
                "'{$type}'",
                $source,
                "SectionRegistry names \"{$type}\" again. Block-specific behaviour belongs in that block's "
                . 'definition under src/Service/Blocks/, not in a branch here — that split is the whole point '
                . 'of step 3, and one such branch is how the next seven come back.'
            );
        }
    }

    /**
     * One block, one registration line — in exactly one shared list. Core's
     * blocks are registered in BlockDefinitions itself; a module's are
     * registered in that module's own blockDefinitions() and merged in. Either
     * way there is one line per type and one lookup in front of all of them,
     * and no type is registered twice.
     */
    public function testAddingABlockTouchesExactlyOneSharedList(): void
    {
        $registrations = $this->sourceOf('src/Service/Blocks/BlockDefinitions.php');

        foreach (BlockDefinitions::types() as $type) {
            $owner = BlockDefinitions::moduleOwnerOf($type);

            $where = $owner === null
                ? $registrations
                : $this->sourceOf('src/Module/' . ucfirst($owner) . 'Module.php');

            $this->assertStringContainsString(
                "'{$type}' =>",
                $where,
                $owner === null
                    ? "\"{$type}\" must be registered in BlockDefinitions"
                    : "\"{$type}\" must be registered by the {$owner} module that owns it"
            );

            if ($owner !== null) {
                $this->assertStringNotContainsString(
                    "'{$type}' =>",
                    $registrations,
                    "\"{$type}\" belongs to the {$owner} module and must not also be registered in Core"
                );
            }
        }

        $this->assertSame(
            BlockDefinitions::types(),
            array_keys(SectionRegistry::types()),
            'the registry must publish the registration list itself, in its own order — the CMS shows blocks in it'
        );

        // Explicit registration only: no filesystem scan, no reflection over
        // class names, nothing a request or a database row could steer.
        foreach (['glob(', 'scandir(', 'ReflectionClass', 'get_declared_classes', '$_GET', '$_POST'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $registrations,
                'block registration must stay an explicit, closed list'
            );
        }
    }

    /**
     * @dataProvider registeredTypes
     */
    public function testEveryDefinitionLivesInItsOwnFileUnderTheBlocksNamespace(string $type): void
    {
        $class = new \ReflectionClass(BlockDefinitions::get($type));

        $this->assertSame(
            'App\\Service\\Blocks',
            $class->getNamespaceName(),
            'one block, one definition, one place to look for it'
        );
        $this->assertStringEndsWith(
            '/src/Service/Blocks/' . $class->getShortName() . '.php',
            str_replace('\\', '/', (string) $class->getFileName())
        );
    }

    /**
     * translatableFields() is the one list of a block's words per language
     * (Multilingual 2.0, docs/multilingual/ARCHITECTURE.md). Its keys become
     * `block_translations.owner_table`, so they may only name the block's own
     * content table or a child table the block declares as its own: a block
     * that declared somebody else's table could write words onto rows it does
     * not own.
     *
     * @dataProvider registeredTypes
     */
    public function testTranslatableFieldsBelongToTheBlocksOwnTables(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        $declared = $definition->translatableFields();

        if ($declared === []) {
            $this->assertTrue(true, "{$type} keeps its words in its own columns until it is converted");

            return;
        }

        $this->assertNotNull($definition->contentTable(), "{$type} declares translatable fields but owns no content table");
        $ownTables = array_merge([$definition->contentTable()], array_keys($definition->childTables()));
        $this->assertSame([], array_diff(array_keys($declared), $ownTables), "{$type} may only declare fields for its own content table and its declared child tables");

        foreach ($declared as $fields) {
            $this->assertNotSame([], $fields);
            $keys = [];

            foreach ($fields as $field) {
                $this->assertInstanceOf(TranslatableField::class, $field);
                $keys[] = $field->key;
            }

            $this->assertSame(array_values(array_unique($keys)), $keys, "{$type} declares a field twice");
        }
    }

    /**
     * A child table hangs under a table of the SAME block, and the chain of
     * parents ends at the block's content table: that chain is how
     * BlockLocalization finds a block's child rows before they are deleted.
     * The column is checked against the real foreign key in
     * Tests\Install\BlockTranslationSchemaTest.
     *
     * @dataProvider registeredTypes
     */
    public function testChildTablesHangUnderTheBlocksOwnRows(string $type): void
    {
        $definition = BlockDefinitions::get($type);
        $children = $definition->childTables();

        if ($children === []) {
            $this->assertTrue(true, "{$type} has no child rows with words of their own");

            return;
        }

        $this->assertNotNull($definition->contentTable(), "{$type} declares child tables but owns no content table");

        foreach ($children as $child => $link) {
            $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9_]*\z/', (string) $child);
            $this->assertSame(['parent', 'column'], array_keys($link), "{$type}: {$child} names its parent table and the column holding the parent's id");
            $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9_]*\z/', $link['column']);
            $this->assertNotSame($definition->contentTable(), $child);

            $table = (string) $child;
            $steps = 0;
            while ($table !== $definition->contentTable()) {
                $this->assertArrayHasKey($table, $children, "{$type}: the chain above {$child} leaves the block");
                $table = $children[$table]['parent'];
                $this->assertLessThan(10, ++$steps, "{$type}: the chain above {$child} never ends");
            }
        }
    }

    public function testBlocksThatShareATableDeclareTheSameFields(): void
    {
        $byTable = [];
        $childrenByTable = [];

        foreach (BlockDefinitions::all() as $type => $definition) {
            $this->assertIsArray($definition->translatableFields(), $type);

            foreach ($definition->translatableFields() as $table => $fields) {
                $byTable[$table][$type] = array_map(
                    static fn (TranslatableField $field): array => [$field->key, $field->kind, $field->maxLength, $field->required],
                    $fields
                );
            }

            if ($definition->contentTable() !== null) {
                $childrenByTable[$definition->contentTable()][$type] = $definition->childTables();
            }

            foreach ($definition->childTables() as $child => $link) {
                $childrenByTable['child:' . $child][$type] = $link;
            }
        }

        foreach ($byTable as $table => $declarations) {
            $this->assertCount(1, array_unique(array_map('serialize', $declarations)), "the blocks sharing {$table} disagree about its words");
        }

        foreach ($childrenByTable as $table => $declarations) {
            $this->assertCount(1, array_unique(array_map('serialize', $declarations)), "the blocks sharing {$table} disagree about its child tables");
        }
    }
}
