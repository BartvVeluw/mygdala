<?php

namespace App\Service;

use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\AdminTranslator;

/**
 * THE central content-block registry: the authoritative answer to which block
 * types a page can contain, what the CMS calls them, whether an editor may
 * add one by hand, whether it may repeat, how many instances a page may hold,
 * which pages it is allowed on, and how one is created, rendered, edited and
 * deleted.
 *
 * It no longer KNOWS any of that itself. Every type is one
 * App\Service\Blocks\BlockDefinition — one class per block, holding its own
 * metadata, creation, deletion, file cleanup, rendering, instance label,
 * editor URL and cache clearing — and App\Service\Blocks\BlockDefinitions is
 * the single list of which definitions exist. This class is the stable
 * boundary in front of them: it validates the type, applies the rules that
 * are the same for every block (page restrictions, instance caps, delete
 * ordering and its transaction, render order) and delegates the rest.
 *
 * Adding a block therefore means writing its definition and adding ONE line
 * to BlockDefinitions' map. Nothing in this file changes — there is no
 * per-type branch left in it, and Tests\Service\BlockDefinitionContractTest
 * fails the build if one comes back.
 *
 * Request data is validated against the registered keys first (see
 * PageSectionRepository and api/admin/*-page-section.php) and never used to
 * instantiate a class or build a table name, so an attacker cannot reach
 * arbitrary repository/class behaviour through `section_type`.
 *
 * One page = ONE ordered list of block instances (page_sections rows, ordered
 * by sort_order). There is no second list and no top/bottom split: the
 * content a page template used to hardcode BETWEEN page-builder sections (the
 * collection tiles and product grid on Shop, the quicknav on Diensten) is
 * registered here too, as a FIXED block: a normal, positioned, reorderable
 * instance in the same list that simply cannot be added by hand
 * (`manual_add = false`), cannot be deleted (`deletable = false`) and exists
 * at most once (`max_instances = 1`). That is what let
 * `page_sections.zone_key` disappear — see
 * db/migrations/20260908250000_flatten_page_sections_into_one_list.php.
 *
 * A fixed block's CONTENT is still owned by whatever already owned it (the
 * product catalogue, Site-instellingen); the registry only says where it
 * renders and what the CMS shows about it.
 *
 * Deliberately still OUTSIDE this registry: the portfolio catalogue
 * (App\Service\PortfolioGalleryContent) and the product catalogue. Those are
 * content *catalogues* with their own CRUD, taxonomy and uploaded media — the
 * `item_gallery` / `product_grid` blocks place them on a page, they do not
 * replace the catalogue behind them.
 */
class SectionRegistry
{
    /**
     * What a FIXED block's content is backed by — mirrored here because
     * admin/page.php maps them to badges. The values live on BlockDefinition,
     * which is where a definition declares its own `kind`.
     */
    public const KIND_FUNCTIONAL = BlockDefinition::KIND_FUNCTIONAL;
    public const KIND_DYNAMIC = BlockDefinition::KIND_DYNAMIC;

    /** @var array<string, array<string, mixed>>|null built once per request from the definitions */
    private static ?array $types = null;

    /**
     * page_sections.id => true for every stale row already reported this
     * request, so one unrenderable block logs one line and not one per
     * template that asks for it.
     *
     * @var array<int, true>
     */
    private static array $reportedUnknownSections = [];

    /**
     * Every registered type's capabilities, in registration order — the
     * overview that used to be a hardcoded TYPES array here, now assembled
     * from the definitions that own it.
     *
     * Capabilities per block type:
     *
     *   label          admin-facing CMS name.
     *   manual_add     may an editor add one from the block picker?
     *   allow_multiple may a page hold more than one instance?
     *   max_instances  hard cap per page; null = derive from allow_multiple
     *                  (unlimited when true, exactly one when false).
     *   allowed_pages  null = every CMS page; a list restricts the type to
     *                  those pages' content_keys.
     *   denied_pages   the inverse escape hatch — used only to keep the
     *                  ordinary Page Hero off the homepage, which has its
     *                  own richer Homepage Hero instead.
     *   deletable      may the block (and its content) be deleted?
     *   app_critical   does the application itself depend on this block
     *                  being publicly reachable? Only the storefront's
     *                  product grid does. A page carrying such a block
     *                  cannot be unpublished or deleted from the CMS (see
     *                  App\Service\PageContent::isProtected()) — that is the
     *                  ONLY thing besides being the site root that protects
     *                  a page, and it follows the block, not the page name.
     *
     * Fixed blocks additionally carry `kind` (KIND_*), an optional
     * `badge_label`, an optional `note` and optional `edit_links`.
     *
     * @return array<string, array{label: string, manual_add: bool, allow_multiple: bool, max_instances: ?int, allowed_pages: ?list<string>, denied_pages?: list<string>, deletable: bool, app_critical?: bool, kind?: string, badge_label?: string, note?: string, edit_links?: list<array{label: string, url: string}>}>
     */
    public static function types(): array
    {
        if (self::$types === null) {
            $types = [];
            foreach (BlockDefinitions::all() as $type => $definition) {
                $types[$type] = $definition->meta();
            }

            self::$types = $types;
        }

        return self::$types;
    }

    /** Forgets the built metadata; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$types = null;
        self::$reportedUnknownSections = [];
    }

    public static function exists(string $type): bool
    {
        return BlockDefinitions::has($type);
    }

    /**
     * The module that owns an UNREGISTERED block type, or null when nothing
     * declares it. A stored row naming a type of a switched-off module is a
     * different situation from a row naming a type that does not exist
     * anywhere: the first is expected and reversible, the second is data that
     * has outlived its code. Callers use this to say which one it is; neither
     * one renders and neither one is ever deleted.
     */
    public static function disabledModuleFor(string $type): ?string
    {
        if (self::exists($type)) {
            return null;
        }

        return BlockDefinitions::moduleOwnerOf($type);
    }

    public static function label(string $type): string
    {
        // Through the block's own definition rather than off the meta array,
        // so the label arrives in the CMS interface language of whoever is
        // reading it (App\Service\Blocks\BlockDefinition::label()). Asked of
        // the registry, not of self::definition(), because this method has
        // always answered for an unknown type rather than throwing.
        $definition = BlockDefinitions::get($type);

        return $definition !== null ? $definition->label() : $type;
    }

    public static function allowMultiple(string $type): bool
    {
        return self::meta($type)['allow_multiple'] ?? false;
    }

    /**
     * May an editor add this type by hand? False for every fixed block —
     * those exist because a page template renders them, not because someone
     * chose to place one.
     */
    public static function isManuallyAddable(string $type): bool
    {
        return self::meta($type)['manual_add'] ?? false;
    }

    /**
     * How many instances of this type one page may hold; null = unlimited.
     * An explicit `max_instances` wins; otherwise a non-repeatable type
     * means exactly one. An unknown type may never be attached at all.
     */
    public static function maxInstances(string $type): ?int
    {
        $meta = self::meta($type);
        if ($meta === null) {
            return 0;
        }

        if (($meta['max_instances'] ?? null) !== null) {
            return (int) $meta['max_instances'];
        }

        return $meta['allow_multiple'] ? null : 1;
    }

    public static function isDeletable(string $type): bool
    {
        return self::meta($type)['deletable'] ?? false;
    }

    /**
     * A fixed block is one the CMS shows and positions but cannot add or
     * delete — the single property every "this is template-owned content"
     * check should use, instead of naming individual types or pages.
     */
    public static function isFixed(string $type): bool
    {
        return self::exists($type) && !self::isManuallyAddable($type);
    }

    /**
     * Does the application itself depend on this block staying publicly
     * reachable? See types()' `app_critical`.
     */
    public static function isApplicationCritical(string $type): bool
    {
        return self::meta($type)['app_critical'] ?? false;
    }

    /**
     * Every application-critical block type — the input
     * App\Service\PageContent::isProtected() needs to answer "does this page
     * carry storefront functionality?" without naming a single page.
     *
     * @return list<string>
     */
    public static function applicationCriticalTypes(): array
    {
        return array_values(array_filter(
            array_keys(self::types()),
            static fn (string $type): bool => self::isApplicationCritical($type)
        ));
    }

    public static function kind(string $type): ?string
    {
        return self::meta($type)['kind'] ?? null;
    }

    public static function badgeLabel(string $type): ?string
    {
        return self::meta($type)['badge_label'] ?? null;
    }

    public static function note(string $type): ?string
    {
        return self::meta($type)['note'] ?? null;
    }

    /**
     * The table holding this type's content rows, or null when it has none of
     * its own (every fixed block). Trusted, registered metadata: it lets a
     * test clean up the rows it created without keeping a second,
     * hand-maintained list of tables, and it must never be built from request
     * data.
     */
    public static function contentTable(string $type): ?string
    {
        return BlockDefinitions::get($type)?->contentTable();
    }

    /**
     * Whether this block type may be attached to this page at all (ignoring
     * how many instances already exist — see availableForPage()).
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function isAllowedOnPage(string $type, array $page): bool
    {
        $meta = self::meta($type);
        if ($meta === null) {
            return false;
        }

        $contentKey = (string) $page['content_key'];

        $allowed = $meta['allowed_pages'] ?? null;
        if ($allowed !== null && !in_array($contentKey, $allowed, true)) {
            return false;
        }

        return !in_array($contentKey, $meta['denied_pages'] ?? [], true);
    }

    /**
     * Every block an editor may add to this page right now — registered,
     * manually addable, allowed on this page, and still under its maximum
     * number of instances there — as the definitions themselves, in
     * registration order.
     *
     * THIS IS THE ONE ANSWER. The block picker draws its cards from it, and
     * api/admin/add-page-section.php validates the posted `section_type`
     * against it before creating anything, so a card that is not on screen is
     * also a request that is refused: there is no second list of "what may be
     * added" for the two to disagree about. What the picker needs beyond the
     * label — the description, the category, the schematic preview — comes
     * off the same definition, never from a parallel table of copy.
     *
     * @param array<string, mixed> $page a `pages` row
     *
     * @return array<string, BlockDefinition> type => definition
     */
    public static function availableDefinitionsForPage(array $page, PageSectionRepository $repository): array
    {
        $counts = [];
        foreach ($repository->findForPage((int) $page['id']) as $row) {
            $existingType = (string) $row['section_type'];
            $counts[$existingType] = ($counts[$existingType] ?? 0) + 1;
        }

        $available = [];
        foreach (array_keys(self::types()) as $type) {
            if (!self::isManuallyAddable($type) || !self::isAllowedOnPage($type, $page)) {
                continue;
            }

            $max = self::maxInstances($type);
            if ($max !== null && ($counts[$type] ?? 0) >= $max) {
                continue;
            }

            $definition = BlockDefinitions::get($type);
            if ($definition !== null) {
                $available[$type] = $definition;
            }
        }

        return $available;
    }

    /**
     * The same list as availableDefinitionsForPage(), reduced to type =>
     * label — the shape callers that only need names have always used.
     *
     * @param array<string, mixed> $page a `pages` row
     *
     * @return array<string, string> type => label
     */
    public static function availableForPage(array $page, PageSectionRepository $repository): array
    {
        return array_map(
            static fn (BlockDefinition $definition): string => $definition->label(),
            self::availableDefinitionsForPage($page, $repository)
        );
    }

    /**
     * Creates a brand-new, empty/default content row for $type on $pageSlug
     * and returns [section_id, section_key] ready to pass to
     * PageSectionRepository::create(). Never called for a type/page
     * combination availableForPage() wouldn't offer — callers (the API
     * layer) must validate that first; a fixed block is refused outright,
     * because it has no content row of its own to create.
     *
     * @return array{0: int, 1: ?string}
     */
    public static function create(string $type, string $pageSlug): array
    {
        if (!self::isManuallyAddable($type)) {
            throw new \RuntimeException("Section type \"{$type}\" cannot be created from the page builder.");
        }

        return self::definition($type)->create($pageSlug);
    }

    /**
     * Permanently deletes the content behind one page_sections row — the row
     * itself and its underlying content (including, for types that have any,
     * uploaded media files and child rows via ON DELETE CASCADE) — inside
     * one DB transaction, so a failure partway through can never leave a
     * dangling page_sections reference. Throws (never silently no-ops) for a
     * non-deletable type; callers must check isDeletable() before offering
     * the action.
     *
     * @param array<string, mixed> $pageSection the full page_sections row (from PageSectionRepository)
     */
    public static function delete(array $pageSection, PageSectionRepository $pageSectionRepository): void
    {
        $type = (string) $pageSection['section_type'];

        if (!self::isDeletable($type)) {
            throw new \RuntimeException("Section type \"{$type}\" cannot be deleted via the page builder.");
        }

        $definition = self::definition($type);

        // Filesystem cleanup happens outside the DB transaction (it isn't
        // transactional itself) and BEFORE the DB rows disappear, so a failed
        // unlink never leaves an orphaned file with no DB row pointing at it.
        // The definition is handed this page_sections row, so its cleanup can only
        // ever reach the files of THIS instance.
        $definition->deleteFiles($pageSection);

        $db = \App\Database::connection();
        $db->beginTransaction();
        try {
            // The block's words in every website language, and those of its
            // child rows, go in the same transaction, for every block with a
            // content table: there is no foreign key that could cascade them
            // (see BlockLocalization), so this line is what keeps
            // block_translations free of orphans. BEFORE deleteContent(),
            // because the child rows are found through the block's row and
            // the database's own cascade removes them without a trace.
            $contentTable = $definition->contentTable();
            if ($contentTable !== null) {
                BlockLocalization::deleteOwner($contentTable, (int) ($pageSection['section_id'] ?? 0));
            }

            $definition->deleteContent($pageSection);

            $pageSectionRepository->delete((int) $pageSection['id']);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $definition->clearCache();
    }

    /**
     * Renders one attached block (echoes markup directly, matching every
     * partials/section-*.php convention) — hands off to the type's own
     * definition, which resolves its Content class and returns without
     * printing anything when that block's own dedicated editor has it hidden
     * (STATE_HIDDEN). This is a SEPARATE check from page_sections.is_active
     * (the page builder's own hide toggle) — the caller (renderPage()) is
     * expected to have already filtered to is_active = 1 rows; either flag
     * hides a block, and showing it again from the page builder never
     * overrides a hide set from the block's own editor.
     *
     * A fixed block has no content row of its own: it renders the markup its
     * page template used to hardcode, and its partial decides for itself
     * whether there is anything to show.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public static function render(array $pageSection, bool $tightTop = false, string $revealGroup = 'section'): void
    {
        self::renderableDefinition($pageSection)?->render($pageSection, $tightTop, $revealGroup);
    }

    /**
     * Renders every visible block attached to one page, in order — the one
     * call each page template needs instead of a hardcoded sequence of
     * Content:: calls interleaved with hardcoded markup. One page, one list,
     * one order. Keeps the previous block's definition purely so that one can
     * say whether the next block should tighten its top spacing (the heroes
     * do — see BlockDefinition::tightensFollowingBlock()), and gives each
     * rendered block a stable, unique data-reveal-group value.
     */
    public static function renderPage(string $pageContentKey): void
    {
        $previous = null;
        $sections = self::visibleSections($pageContentKey);

        // The words of every block on the page, in every language, in one
        // query rather than one per block (BlockLocalization).
        BlockLocalization::preloadSections($sections);

        foreach ($sections as $pageSection) {
            $definition = self::renderableDefinition($pageSection, $pageContentKey);

            // An unregistered type is skipped, not fatal — see
            // renderableDefinition(). $previous stays the last block that
            // actually rendered, so the spacing of the next one is decided
            // by what the visitor really sees above it.
            if ($definition === null) {
                continue;
            }

            $tightTop = $previous !== null && $previous->tightensFollowingBlock();
            $revealGroup = $pageSection['section_type'] . '-' . $pageSection['id'];

            $definition->render($pageSection, $tightTop, $revealGroup);

            $previous = $definition;
        }
    }

    /**
     * Asks App\Service\PageAssets for the CSS and JS every block on this page
     * needs, so the page template can call this BEFORE it writes its <head>.
     *
     * This is the answer to the one ordering problem a server-rendered page
     * has: a block partial does not run until well after </head>, but its
     * stylesheet has to be in the head. So the block list is read up front —
     * the same list renderPage() walks a moment later — and each type's
     * definition is asked what it needs. It costs one extra indexed SELECT
     * per page, deliberately preferred over caching the list on this class,
     * which would then have to be invalidated by everything that reorders,
     * hides or deletes a block.
     *
     * Scripts have no such ordering problem (they are printed before
     * </body>, long after every block has rendered) but are collected here
     * too, so a block declares all of its assets in one obvious place.
     *
     * Duplicates are impossible: PageAssets deduplicates, so five instances
     * of the same block ask five times and load one file.
     */
    public static function collectPageAssets(string $pageContentKey): void
    {
        $seen = [];

        foreach (self::visibleSections($pageContentKey) as $pageSection) {
            $type = (string) $pageSection['section_type'];

            if (isset($seen[$type]) || !BlockDefinitions::has($type)) {
                continue;
            }

            $seen[$type] = true;
            self::collectBlockAssets(self::definition($type));
        }
    }

    /**
     * Asks App\Service\PageAssets for everything ONE block type declares: its
     * stylesheets, its scripts and its third-party libraries. What
     * collectPageAssets() does per type on a page, and what the block
     * library's preview (admin/block-preview.php) does for the one block it
     * shows, so a block's assets are asked for the same way everywhere.
     */
    public static function collectBlockAssets(BlockDefinition $definition): void
    {
        foreach ($definition->styles() as $style) {
            PageAssets::requireStyle($style);
        }

        foreach ($definition->scripts() as $script) {
            PageAssets::requireScript($script);
        }

        foreach ($definition->vendorScripts() as $vendor) {
            PageAssets::requireVendorScript($vendor);
        }
    }

    /**
     * The blocks a visitor sees on one page, in render order — what
     * collectPageAssets() and renderPage() both walk.
     *
     * A missing page row (or an unreachable database) yields an empty list
     * rather than an exception: the same fallback philosophy every Content
     * class in this project follows.
     *
     * @return list<array<string, mixed>>
     */
    private static function visibleSections(string $pageContentKey): array
    {
        $page = PageContent::forContentKey($pageContentKey);

        if ($page === null) {
            return [];
        }

        return (new PageSectionRepository())->findForPage((int) $page['id'], true);
    }

    /**
     * A short, admin-facing label distinguishing one instance from another of
     * the same type on the same page (e.g. two Feature Grids) — best effort
     * from that instance's own title, with the definition deciding what
     * counts as its title, falling back to the type label alone.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public static function instanceLabel(array $pageSection): string
    {
        $type = (string) $pageSection['section_type'];
        $label = self::label($type);

        $definition = BlockDefinitions::get($type);
        $title = $definition === null ? '' : $definition->instanceTitle($pageSection);

        return $title !== '' ? "{$label} — {$title}" : $label;
    }

    /**
     * Does this page carry anything below its head yet? The page builder asks
     * before it shows its empty state: a page whose only block is its
     * Paginakop has a title and nothing to read.
     *
     * "Its head" is read from the one place that already says so,
     * BlockCategories::HERO, so no block type is named here. Every other row
     * counts, hidden or not: a hidden block is content the editor made, and a
     * row whose type is not registered right now — a switched-off module's
     * block, data that outlived its code — is still a row the list shows.
     *
     * @param list<array<string, mixed>> $pageSections the page's page_sections rows
     */
    public static function hasContentBlocks(array $pageSections): bool
    {
        foreach ($pageSections as $pageSection) {
            $definition = BlockDefinitions::get((string) ($pageSection['section_type'] ?? ''));

            if ($definition === null || $definition->category() !== BlockCategories::HERO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where this instance is edited — always one or more of the existing,
     * dedicated editors (never a generic page-builder-owned form), per the
     * "reuse existing admin forms" convention. A fixed block links to
     * whichever admin domain owns its content. An empty list means "nothing
     * to edit here".
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     *
     * @return list<array{label: string, url: string}>
     */
    public static function editLinks(array $pageSection): array
    {
        $fixedLinks = self::meta((string) $pageSection['section_type'])['edit_links'] ?? null;
        if ($fixedLinks !== null) {
            return $fixedLinks;
        }

        $url = self::editUrl($pageSection);

        // The same word every other edit link in the CMS uses, so it changes
        // language with the rest of the shell.
        return $url === null ? [] : [['label' => AdminTranslator::trans('common.edit'), 'url' => $url]];
    }

    /**
     * The admin URL to edit this instance — the type's own dedicated editor,
     * or (for a fixed block) the first admin domain that owns its content.
     * Used on its own by api/admin/add-page-section.php, which redirects
     * straight into the editor of the block it just created.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public static function editUrl(array $pageSection): ?string
    {
        $type = (string) $pageSection['section_type'];

        $fixedLinks = self::meta($type)['edit_links'] ?? null;
        if ($fixedLinks !== null) {
            return $fixedLinks[0]['url'];
        }

        return BlockDefinitions::get($type)?->editUrl($pageSection);
    }

    /**
     * @return array<string, mixed>|null null for an unregistered type
     */
    private static function meta(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    /**
     * The definition to RENDER one stored row with, or null when its
     * `section_type` is not registered.
     *
     * A page_sections row can outlive the block type it names: a type that
     * was removed, renamed or never shipped leaves its attachments behind,
     * and so does a type belonging to a module that has been switched off,
     * and no later migration sees them because every one of those selects on
     * a type it knows. Rendering must not turn that into a fatal error — one
     * stale row would take down the whole public page, footer and all, and
     * on a server with display_errors on it would print a stack trace to the
     * visitor. So the section is SKIPPED (nothing is printed for it, and
     * nothing about it reaches the browser), the rest of the page renders
     * normally, and the row is left exactly as it is — the editor still sees
     * it in the page builder, flagged as unsupported.
     *
     * The diagnosis goes to error_log() instead, the same channel every
     * *Content class already degrades to, with everything needed to find the
     * row: type, page, page_sections.id, section_key and sort_order. Once
     * per row per request — a page holding five stale rows logs five lines,
     * not five per block that follows them.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    private static function renderableDefinition(array $pageSection, ?string $pageContentKey = null): ?BlockDefinition
    {
        $type = (string) $pageSection['section_type'];
        $definition = BlockDefinitions::get($type);

        if ($definition !== null) {
            return $definition;
        }

        $sectionId = (int) ($pageSection['id'] ?? 0);

        if (!isset(self::$reportedUnknownSections[$sectionId])) {
            self::$reportedUnknownSections[$sectionId] = true;

            $module = BlockDefinitions::moduleOwnerOf($type);

            error_log(sprintf(
                '[SectionRegistry] %s section type "%s" on page "%s" (page_sections.id=%d, section_key="%s",'
                . ' sort_order=%s) — section skipped, row preserved',
                $module === null ? 'unknown' : ('module "' . $module . '" is disabled, so'),
                $type,
                $pageContentKey ?? (string) ($pageSection['page_slug'] ?? '?'),
                $sectionId,
                (string) ($pageSection['section_key'] ?? ''),
                (string) ($pageSection['sort_order'] ?? '?')
            ));
        }

        return null;
    }

    /**
     * The definition behind a type, refusing anything that is not registered
     * — the one place an unknown `section_type` becomes an exception instead
     * of a lookup on something the request chose. Used by the paths that
     * CHANGE something (create, delete); rendering goes through
     * renderableDefinition() and degrades instead.
     */
    private static function definition(string $type): BlockDefinition
    {
        $definition = BlockDefinitions::get($type);

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown section type \"{$type}\".");
        }

        return $definition;
    }
}
