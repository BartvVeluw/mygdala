<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\PageTemplates\PageTemplateDefinition;
use App\Service\PageTemplates\PageTemplates;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The page-template CATALOGUE: which templates exist, what they may name,
 * and the closed-registry rules that keep the list from turning into a
 * plugin system. Nothing here touches the database — a template is code, and
 * so is everything this file checks.
 *
 * The creation side (what a template actually builds, and that it builds it
 * atomically) is Tests\Service\PageTemplateCreationTest.
 *
 * See PAGE-TEMPLATES.md.
 */
final class PageTemplateRegistryTest extends TestCase
{
    /** The V1 catalogue, in the order the picker shows it. */
    private const EXPECTED_KEYS = ['blank', 'standard', 'about', 'services', 'contact', 'landing'];

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    public function testRegistersExactlyTheV1Templates(): void
    {
        self::assertSame(self::EXPECTED_KEYS, PageTemplates::keys());
    }

    public function testTemplateKeysAreUnique(): void
    {
        $keys = PageTemplates::keys();

        self::assertSame(array_values(array_unique($keys)), $keys);
    }

    public function testEveryDefinitionReportsTheKeyItIsRegisteredUnder(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            self::assertSame($key, $template->key(), 'Template "' . $key . '" reports a different key().');
        }
    }

    public function testUnknownKeyIsNotFound(): void
    {
        self::assertFalse(PageTemplates::has('over-mij'));
        self::assertFalse(PageTemplates::has(''));
        self::assertNull(PageTemplates::get('does-not-exist'));
    }

    /**
     * A template key arrives from a request. An unknown one must land on the
     * blank template — the behaviour this endpoint had before templates
     * existed — rather than fataling the save or being turned into a class
     * name.
     */
    public function testUnknownOrAbsentKeyResolvesToTheBlankTemplate(): void
    {
        self::assertSame('blank', PageTemplates::resolve(null)->key());
        self::assertSame('blank', PageTemplates::resolve('')->key());
        self::assertSame('blank', PageTemplates::resolve('../../etc/passwd')->key());
        self::assertSame('blank', PageTemplates::resolve('App\\Service\\PageTemplates\\AboutTemplate')->key());
    }

    public function testResolveReturnsTheRequestedTemplateWhenItExists(): void
    {
        self::assertSame('contact', PageTemplates::resolve('contact')->key());
    }

    public function testEveryTemplateHasAnAdminLabelAndDescription(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            self::assertNotSame('', trim($template->label()), 'Template "' . $key . '" has no label.');
            self::assertNotSame('', trim($template->description()), 'Template "' . $key . '" has no description.');
        }
    }

    public function testTemplateLabelsAreUnique(): void
    {
        $labels = array_map(
            static fn (PageTemplateDefinition $template): string => $template->label(),
            array_values(PageTemplates::all())
        );

        self::assertSame(array_values(array_unique($labels)), $labels);
    }

    /**
     * "Lege pagina" is a heading and nothing else. The Paginakop carries the
     * page's <h1>; every block under it is the editor's own choice, and no
     * text block the editor did not ask for is put there first.
     */
    public function testBlankTemplateStartsWithOnlyAPageHero(): void
    {
        self::assertSame(['page_hero'], PageTemplates::get('blank')->blocks());
    }

    /**
     * The other templates deliberately start with more than a heading, and
     * keep doing so: giving "Lege pagina" its heading changed that one
     * template and no other.
     */
    public function testTheOtherTemplatesKeepTheBlocksTheyStartWith(): void
    {
        self::assertSame(['page_hero', 'rich_text'], PageTemplates::get('standard')->blocks());
        self::assertSame(['page_hero', 'text_image_split', 'rich_text', 'cta_band'], PageTemplates::get('about')->blocks());
        self::assertSame(['page_hero', 'card_carousel', 'rich_text', 'cta_band'], PageTemplates::get('services')->blocks());
        self::assertSame(['page_hero', 'form', 'contact_card'], PageTemplates::get('contact')->blocks());
        self::assertSame(['page_hero', 'text_image_split', 'feature_grid', 'cta_band'], PageTemplates::get('landing')->blocks());
    }

    /**
     * Every template opens with the ordinary Page Hero, the blank one
     * included, because that is what carries the page's <h1>. A template that
     * dropped it would hand the editor a page with no heading element at all.
     */
    public function testEveryTemplateStartsWithAPageHero(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            self::assertSame(
                'page_hero',
                $template->blocks()[0] ?? null,
                'Template "' . $key . '" does not start with a page hero.'
            );
        }
    }

    /**
     * A template may only name a block an editor could also have added by
     * hand. This is the rule App\Service\PageTemplates\PageTemplateInstaller
     * enforces at run time, checked here for the whole catalogue at once.
     */
    public function testEveryNamedBlockIsRegisteredAndManuallyAddable(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            foreach ($template->blocks() as $type) {
                self::assertTrue(
                    SectionRegistry::exists($type),
                    'Template "' . $key . '" names unregistered block "' . $type . '".'
                );
                self::assertTrue(
                    SectionRegistry::isManuallyAddable($type),
                    'Template "' . $key . '" names block "' . $type . '", which cannot be added by hand.'
                );
            }
        }
    }

    /**
     * The homepage hero is registered for the site root only. No template may
     * name a block restricted to specific pages, or it would fail the moment
     * it ran.
     */
    public function testNoTemplateNamesAPageRestrictedBlock(): void
    {
        $types = SectionRegistry::types();

        foreach (PageTemplates::all() as $key => $template) {
            foreach ($template->blocks() as $type) {
                self::assertNull(
                    $types[$type]['allowed_pages'] ?? null,
                    'Template "' . $key . '" names block "' . $type . '", which is restricted to specific pages.'
                );
            }
        }
    }

    /**
     * A capped block (the page hero, the offerte/contact form) may appear at
     * most as often as its own maximum allows. Naming one twice would build
     * a page the page builder itself would refuse to make — and, for a block
     * stored per page rather than per instance, would fail against the
     * database halfway through.
     */
    public function testNoTemplateNamesABlockMoreOftenThanItsMaximum(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            $counts = array_count_values($template->blocks());

            foreach ($counts as $type => $count) {
                $max = SectionRegistry::maxInstances((string) $type);

                if ($max === null) {
                    continue;
                }

                self::assertLessThanOrEqual(
                    $max,
                    $count,
                    'Template "' . $key . '" names block "' . $type . '" ' . $count . ' times, but at most ' . $max . ' is allowed.'
                );
            }
        }
    }

    /**
     * Core templates must behave identically with the Shop on or off, so
     * none of them may name a block a module owns.
     */
    public function testNoTemplateNamesAModuleOwnedBlock(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            foreach ($template->blocks() as $type) {
                self::assertNull(
                    BlockDefinitions::moduleOwnerOf($type),
                    'Template "' . $key . '" names block "' . $type . '", which belongs to a module.'
                );
            }
        }
    }

    public function testTheCatalogueIsIdenticalWithTheShopDisabled(): void
    {
        $withShop = self::catalogueSnapshot();

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
        $withoutShop = self::catalogueSnapshot();

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $withShopAgain = self::catalogueSnapshot();

        self::assertSame($withShop, $withoutShop);
        self::assertSame($withShop, $withShopAgain);
    }

    /** @return array<string, list<string>> */
    private static function catalogueSnapshot(): array
    {
        $snapshot = [];
        foreach (PageTemplates::all() as $key => $template) {
            $snapshot[$key] = $template->blocks();
        }

        return $snapshot;
    }

    /**
     * The registry is a hardcoded map, exactly like
     * App\Service\Blocks\BlockDefinitions: no directory scan, no glob, no
     * class name built from a string. A template key comes from a request,
     * and the only thing it may ever do is hit or miss a key of that map.
     */
    public function testTheRegistryDoesNotDiscoverTemplatesDynamically(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Service/PageTemplates/PageTemplates.php'
        );

        foreach (['glob(', 'scandir(', 'opendir(', 'DirectoryIterator', 'ReflectionClass', 'class_exists('] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'PageTemplates must not discover templates dynamically, but uses ' . $forbidden . '.'
            );
        }
    }

    /**
     * Core code may not reach a module's classes by name. The template
     * package is Core, so nothing in it may mention one.
     */
    public function testTemplatePackageHasNoConcreteShopDependency(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/Service/PageTemplates/*.php');

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (['ProductRepository', 'CollectionService', 'ShopModule', 'ProductGridBlock', 'ShopCollectionsBlock'] as $shopClass) {
                self::assertStringNotContainsString(
                    $shopClass,
                    $source,
                    basename($file) . ' must not depend on the Shop class ' . $shopClass . '.'
                );
            }
        }
    }

    /**
     * Templates are creation-time helpers. A method that ran after creation
     * would be the first step towards a page type, which this design does
     * not have — so the contract deliberately has no render/update/delete.
     */
    public function testTheDefinitionContractIsCreationTimeOnly(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(PageTemplateDefinition::class))->getMethods(ReflectionMethod::IS_PUBLIC)
        );

        sort($methods);

        self::assertSame(['blocks', 'description', 'icon', 'key', 'label'], $methods);
    }
}
