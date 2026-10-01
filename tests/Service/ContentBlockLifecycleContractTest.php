<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\InspectsContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Content Blocks Lifecycle 1.0 as a contract over the source
 * (CONTENT-BLOCKS.md, "De levensloop van een nieuw blok" and "Een leeg blok
 * herkennen"): what every block that opens as a draft, and every block the
 * page builder may call empty, must have. No database, no server.
 *
 *   - a block that opens as a draft has an editor that prints the draft line
 *     with its Annuleren, and that editor's endpoint places the draft inside
 *     its transaction and lands on the list through afterSaveUrl();
 *   - every block a editor can add either inspects its own content or is on
 *     NEVER_EMPTY, with the reason, so a new block has to decide;
 *   - the Annuleren endpoint keeps the four guards and removes only a draft.
 */
final class ContentBlockLifecycleContractTest extends TestCase
{
    /**
     * Blocks the "Leeg blok" warning never judges, and why. Everything else a
     * editor can add implements App\Service\Blocks\InspectsContent.
     */
    private const NEVER_EMPTY = [
        // Decorative: its whole content is its size.
        'spacer' => 'decorative',
        // Dynamic: they show what their source holds, whatever that is today.
        'product_grid' => 'dynamic',
        'shop_collections' => 'dynamic',
        // Always prints the site's contact details beside its form.
        'contact_form' => 'always shows the direct contact card',
        // The heroes always carry the page's own title or the homepage's opening.
        'page_hero' => 'shows the page title',
        'homepage_hero' => 'the homepage opening',
    ];

    protected function setUp(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'portfolio' => true, 'blog' => true, 'multilingual' => true]);
        BlockDefinitions::reset();
        SectionRegistry::reset();
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        BlockDefinitions::reset();
        SectionRegistry::reset();
    }

    public function testEveryDraftBlockHasACancellableEditorWhoseEndpointPlacesIt(): void
    {
        $checked = 0;

        foreach (array_keys(SectionRegistry::types()) as $type) {
            if (!SectionRegistry::opensAsDraft($type)) {
                continue;
            }

            $url = SectionRegistry::editUrl(['id' => 0, 'page_id' => 1, 'page_slug' => 'zz-page', 'section_type' => $type, 'section_key' => 'custom-zz', 'section_id' => 1]);
            $this->assertNotNull($url, "{$type}: a draft needs an editor to be saved from");
            $this->assertMatchesRegularExpression('~^/admin/([a-z-]+)\.php\?section=~', (string) $url, $type);
            preg_match('~^/admin/([a-z-]+)\.php~', (string) $url, $editorMatch);

            $editor = $this->source('admin/' . $editorMatch[1] . '.php');
            $this->assertStringContainsString("block_editor_draft_notice('{$type}', \$csrfToken)", $editor, "{$type}: its editor says it is new and offers Annuleren");

            $this->assertSame(1, preg_match('~<form method="post" action="/api/admin/(update-[a-z-]+\.php)"~', $editor, $actionMatch), "{$type}: one save form");
            $endpoint = $this->source('api/admin/' . $actionMatch[1]);

            $place = strpos($endpoint, "ContentBlockDrafts::place('{$type}', ");
            $this->assertNotFalse($place, "{$actionMatch[1]} places a new {$type}");
            $this->assertLessThan((int) strpos($endpoint, '$db->commit();'), $place, "{$actionMatch[1]}: inside the save's transaction");
            $this->assertGreaterThan((int) strpos($endpoint, '$db->beginTransaction();'), $place, "{$actionMatch[1]}: inside the save's transaction");
            $this->assertStringContainsString('ContentBlockAccess::afterSaveUrl($placed, ', $endpoint, "{$actionMatch[1]}: lands on the list the block stands in");
            $this->assertStringNotContainsString("'&saved=1'", $endpoint, "{$actionMatch[1]}: no second success redirect");

            $checked++;
        }

        $this->assertGreaterThanOrEqual(20, $checked, 'every editor block opens as a draft');
    }

    public function testOnlyBlocksWithoutAFreshRowOfTheirOwnArePlacedAtOnce(): void
    {
        foreach (['page_hero', 'homepage_hero', 'product_grid', 'shop_collections', 'project_images'] as $type) {
            $this->assertFalse(SectionRegistry::opensAsDraft($type), $type);
        }

        foreach (['rich_text', 'text_image_split', 'item_gallery', 'featured_product', 'spacer'] as $type) {
            $this->assertTrue(SectionRegistry::opensAsDraft($type), $type);
        }
    }

    public function testEveryAddableBlockDecidesWhetherItCanBeEmpty(): void
    {
        foreach (array_keys(SectionRegistry::types()) as $type) {
            if (!SectionRegistry::isManuallyAddable($type)) {
                continue;
            }

            $definition = BlockDefinitions::get($type);
            $inspects = $definition instanceof InspectsContent;

            $this->assertNotSame(
                $inspects,
                array_key_exists($type, self::NEVER_EMPTY),
                "{$type}: implement InspectsContent, or list it in NEVER_EMPTY with the reason — not both, not neither"
            );
        }
    }

    public function testTheEmptyWarningIsAnAdminHintOnly(): void
    {
        $this->assertStringNotContainsString('isEmpty(', $this->source('src/Service/SectionRegistry.php', 'renderPage'), 'the public page never asks');

        foreach (glob(dirname(__DIR__, 2) . '/partials/*.php') ?: [] as $partial) {
            $this->assertStringNotContainsString('blocks.empty_', (string) file_get_contents($partial), basename($partial));
            $this->assertStringNotContainsString('admin-badge--empty', (string) file_get_contents($partial), basename($partial));
        }

        $list = $this->source('admin/_content_blocks.php');
        $this->assertStringContainsString('SectionRegistry::isEmpty($pageSection)', $list);
        $this->assertStringContainsString("admin_te('blocks.empty_badge')", $list);
        $this->assertStringNotContainsString('SectionRegistry::delete(', $list, 'an empty block is never removed by the list');
    }

    public function testTheCancelEndpointKeepsTheGuardsAndRemovesOnlyADraft(): void
    {
        // The code, not the docblock that describes it.
        $source = (string) strstr($this->source('api/admin/discard-block-draft.php'), 'declare(strict_types=1);');

        $login = strpos($source, 'AdminAuth::requireLoginForApi()');
        $any = strpos($source, 'ContentBlockAccess::requireAnyForApi()');
        $post = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $csrf = strpos($source, 'Csrf::validate(');
        $list = strpos($source, 'ContentBlockAccess::pageForKeyForApi(');
        $discard = strpos($source, 'ContentBlockDrafts::discard($draft)');

        foreach ([$login, $any, $post, $csrf, $list, $discard] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertTrue($login < $any && $any < $post && $post < $csrf && $csrf < $list && $list < $discard, 'login, permission, POST, CSRF, the list, then the draft');
        $this->assertStringContainsString('findOnPage((int) $page[\'id\'], $sectionType, $sectionKey)', $source, 'only a draft on that list');
        $this->assertStringNotContainsString('SectionRegistry::delete(', $source, 'never a placed block');
        $this->assertStringNotContainsString('$_POST[\'return', $source, 'no return URL from the request');
    }

    public function testTheSaveReturnIsDerivedFromTheBlockNeverFromTheRequest(): void
    {
        $source = $this->source('src/Service/ContentOwners/ContentBlockAccess.php', 'afterSaveUrl');

        $this->assertStringNotContainsString('$_GET', $source);
        $this->assertStringNotContainsString('$_POST', $source);
        $this->assertStringNotContainsString('HTTP_REFERER', $source);
        $this->assertStringContainsString("self::listUrl(\$page) . '&saved=' . \$id . '#blok-' . \$id", $source);
    }

    public function testTheDraftIsPlacedUnderLockAndCheckedAgainstAvailability(): void
    {
        $source = $this->source('src/Service/Blocks/ContentBlockDrafts.php', 'place');

        $this->assertStringContainsString('findByRow($type, $sectionId, true)', $source, 'the draft row is locked: a double submit places it once');
        $this->assertStringContainsString('lockPage(', $source, 'the page is locked: two drafts never share a position');
        $this->assertStringContainsString('SectionRegistry::availableForPage($page, $sections)', $source, 'the type is asked again at the save');
    }

    public function testTheListNamesTheSavedBlockAndBringsItIntoView(): void
    {
        $list = $this->source('admin/_content_blocks.php');
        $this->assertStringContainsString("data-admin-collapse-focus", $list);
        $this->assertStringContainsString("admin_te('blocks.saved_badge')", $list);

        $script = $this->source('admin/assets/admin-collapse.js');
        $this->assertStringContainsString('data-admin-collapse-focus', $script);

        $page = $this->source('admin/page.php');
        $this->assertStringContainsString("'saved' => true", $page, 'a content page passes the saved block on to its owner');
        $this->assertStringContainsString("\$savedSectionId !== 0 ? 'inhoud' : null", $page, 'the Inhoud tab opens');
    }

    /** A file, or with $function only the body of that one function. */
    private function source(string $path, ?string $function = null): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertNotSame('', $source, $path);

        if ($function === null) {
            return $source;
        }

        $start = strpos($source, 'function ' . $function . '(');
        $this->assertNotFalse($start, $path . '::' . $function);
        $end = strpos($source, "\n    }\n", (int) $start);

        return substr($source, (int) $start, ($end === false ? strlen($source) : $end) - (int) $start);
    }
}
