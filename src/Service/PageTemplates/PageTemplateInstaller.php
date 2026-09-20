<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\SectionRegistry;

/**
 * Creates a page and, in the same breath, the blocks its template starts it
 * with — the ONE place a template is ever applied.
 *
 * It owns no storage logic of its own. The page row goes through
 * App\Repository\PageRepository, the page's text in each language through
 * App\Service\PageLocalization, each block's content row through
 * App\Service\SectionRegistry::create() (which hands off to the block's own
 * definition), and the attachment through
 * App\Repository\PageSectionRepository — the very same three calls
 * api/admin/add-page-section.php makes when an editor adds a block by hand.
 * A template therefore cannot drift from what the page builder does, and a
 * new block type needs no change here.
 *
 * ALL OR NOTHING. Everything below runs inside one transaction, so a
 * template that fails halfway leaves no page behind at all — not an empty
 * one, not a half-filled one. That is only possible because none of the
 * three calls opens a transaction of its own (PDO cannot nest them):
 * PageRepository::create() and PageSectionRepository::create() are single
 * inserts, and no BlockDefinition::create() starts one either. The reverse
 * case, SectionRegistry::delete(), DOES open one, which is exactly why
 * App\Service\PageService::delete() cannot wrap its loop the way this class
 * can.
 *
 * The caches are cleared only after the commit succeeds: emptying them
 * earlier would advertise rows a rollback is about to take away again.
 */
final class PageTemplateInstaller
{
    /**
     * Creates the page described by $pageData and applies $template to it,
     * returning the new pages.id.
     *
     * $pageData is passed straight to PageRepository::create(), which
     * hardcodes is_system = 0 and route_path = NULL — so a page made here is
     * an ordinary CMS content page by construction, whatever the caller
     * sends. Its slug and status must already have been resolved and
     * validated by App\Service\PageService, exactly as
     * api/admin/create-page.php does.
     *
     * $translations is the page's text, per website language: title, SEO
     * title and meta description, handed to PageLocalization::save() inside
     * the same transaction, so a page never exists without the name it was
     * created with.
     *
     * Nothing about the template is stored on the page. Once this method
     * returns, no column, table or file records which template ran — the
     * result is indistinguishable from a page an editor built block by
     * block, which is the whole point (PAGE-TEMPLATES.md).
     *
     * $slugs is the page's public address per language (Multilingual 2.0
     * phase 6): a language named here becomes routable, one that is not has
     * no public URL at all. A caller that names none — every test fixture, and
     * anything creating a route-bound page — gets a page whose only address is
     * the neutral `pages.slug`, which is what this project had before phase 6.
     *
     * @param array<string, mixed> $pageData the resolved `pages` row fields
     * @param array<string, array<string, string|null>> $translations language code => the page's text in it
     * @param array<string, string> $slugs language code => that language's slug
     *
     * @throws \RuntimeException when the template names a block that cannot be placed
     * @throws \InvalidArgumentException when a language is not a registered website language
     */
    public static function install(
        PageTemplateDefinition $template,
        array $pageData,
        array $translations = [],
        array $slugs = []
    ): int {
        $blocks = $template->blocks();

        // Checked BEFORE anything is written, and against the same registry
        // the page builder uses, so a template naming an unusable block type
        // fails as a programming error at the door instead of rolling back a
        // page it had already started to build.
        foreach ($blocks as $type) {
            self::assertPlaceable($template, $type);
        }

        $db = Database::connection();
        $db->beginTransaction();

        try {
            $pageRepository = new PageRepository();
            $pageId = $pageRepository->create($pageData);

            foreach ($translations as $languageCode => $fields) {
                $code = (string) $languageCode;
                PageLocalization::save($pageId, $code, $fields, $slugs[$code] ?? null);
            }

            // A language that has an address but no words is still a language
            // this page is routable in, so its row has to be written too.
            foreach ($slugs as $languageCode => $slug) {
                $code = (string) $languageCode;
                if (!array_key_exists($code, $translations)) {
                    PageLocalization::save($pageId, $code, [], $slug);
                }
            }

            $page = $pageRepository->findById($pageId);
            if ($page === null) {
                throw new \RuntimeException('The page row disappeared immediately after being created.');
            }

            $contentKey = (string) $page['content_key'];
            $sectionRepository = new PageSectionRepository();

            foreach ($blocks as $type) {
                if (!SectionRegistry::isAllowedOnPage($type, $page)) {
                    throw new \RuntimeException(
                        "Page template \"{$template->key()}\" names block \"{$type}\", which is not allowed on this page."
                    );
                }

                // Ordered by the loop, not by a sort_order this class picks:
                // PageSectionRepository::create() appends to the bottom of
                // the page's list, so blocks() reads top to bottom.
                [$sectionId, $sectionKey] = SectionRegistry::create($type, $contentKey);

                $sectionRepository->create($pageId, $contentKey, $type, $sectionKey, $sectionId);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        PageContent::clearCache();

        foreach (array_unique($blocks) as $type) {
            BlockDefinitions::get($type)?->clearCache();
        }

        return $pageId;
    }

    /**
     * A template may only name a block an editor could also have added by
     * hand: registered right now, and manually addable. Both conditions are
     * true for every block the V1 templates use — this exists so that a
     * template added later cannot quietly start seeding a fixed block, or a
     * block belonging to a module that happens to be switched off.
     */
    private static function assertPlaceable(PageTemplateDefinition $template, string $type): void
    {
        if (!SectionRegistry::exists($type)) {
            $module = SectionRegistry::disabledModuleFor($type);

            throw new \RuntimeException(
                "Page template \"{$template->key()}\" names block \"{$type}\", which is not registered"
                . ($module !== null ? " (it belongs to the disabled module \"{$module}\")" : '') . '.'
            );
        }

        if (!SectionRegistry::isManuallyAddable($type)) {
            throw new \RuntimeException(
                "Page template \"{$template->key()}\" names block \"{$type}\", which cannot be added to a page by hand."
            );
        }
    }
}
