<?php

namespace App\Service\Blocks;

use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageRegistry;

/**
 * ONE content-block type, in ONE file: its CMS metadata plus every piece of
 * behaviour App\Service\SectionRegistry needs to place, render, label, edit,
 * clean up and cache-clear an instance of it.
 *
 * This class is the INTEGRATION CONTRACT, not the block's logic. The real
 * work still belongs where it already lives: the block's Repository owns its
 * SQL, its <Type>Content class owns its defaults, fallback behaviour and
 * per-request cache, its partial owns the markup and its admin editor owns
 * the form. A definition only wires those together so the registry never has
 * to know which is which.
 *
 * Every method here used to be one `case`/`match` arm inside SectionRegistry,
 * spread over seven places that all had to be edited together — and nothing
 * warned you when you missed one. They are abstract on purpose: a new block
 * that forgets one does not compile. A block whose content is owned by
 * another admin domain (the product catalogue, the theme) extends
 * FixedBlockDefinition instead, which answers the content-row half of this
 * contract once.
 *
 * A definition is stateless and instantiated once per request by
 * BlockDefinitions; it must never be constructed from request data.
 */
abstract class BlockDefinition
{
    /*
     * What a FIXED block's content is backed by — purely admin-facing
     * classification; admin/page.php maps it to a badge.
     */

    /** Behaviour/markup owned by the theme (the Diensten quicknav) — never a CMS field. */
    public const KIND_FUNCTIONAL = 'functional';

    /** Backed by another admin domain (products, portfolio), not page content. */
    public const KIND_DYNAMIC = 'dynamic';

    /** The registry key — must match the key this definition is registered under in BlockDefinitions. */
    abstract public function type(): string;

    /**
     * The block's CMS capabilities, in the shape SectionRegistry::types()
     * exposes: label, manual_add, allow_multiple, max_instances,
     * allowed_pages, deletable, plus the optional denied_pages,
     * app_critical, kind, badge_label, note and edit_links. See
     * SectionRegistry's docblock for what each one means.
     *
     * @return array{label: string, manual_add: bool, allow_multiple: bool, max_instances: ?int, allowed_pages: ?list<string>, denied_pages?: list<string>, deletable: bool, app_critical?: bool, kind?: string, badge_label?: string, note?: string, edit_links?: list<array{label: string, url: string}>}
     */
    abstract public function meta(): array;

    /**
     * The block's admin-facing name, for a human choosing one — the same
     * string meta()['label'] carries, read through one accessor so the picker,
     * the catalogue, the page builder's list and the page-template cards can
     * never drift apart. There is exactly ONE label per block and this is it.
     */
    public function label(): string
    {
        return $this->say('label', (string) ($this->meta()['label'] ?? $this->type()));
    }

    /**
     * The block's own words in the CMS interface language of whoever is
     * signed in.
     *
     * Keyed on type() — `block.rich_text.description` — which is a fixed
     * string in source and can never come from a request. Translated HERE
     * rather than in each of the two dozen block classes, so a block keeps
     * declaring a plain Dutch label and description and never has to know
     * that this CMS runs in two languages. A block with no key in the
     * catalogue keeps its own words (App\Service\Language\AdminTranslator).
     */
    private function say(string $suffix, string $fallback): string
    {
        $key = 'block.' . $this->type() . '.' . $suffix;

        if (!AdminTranslator::has($key, LanguageRegistry::DEFAULT_LANGUAGE)) {
            return $fallback;
        }

        return AdminTranslator::trans($key);
    }

    /** description(), in the reader's language. Editors print THIS. */
    public function describedFor(): string
    {
        return $this->say('description', $this->description());
    }

    /**
     * useCases(), in the reader's language.
     *
     * @return list<string>
     */
    public function useCasesFor(): array
    {
        $cases = [];
        foreach ($this->useCases() as $index => $case) {
            $cases[] = $this->say('use_case_' . ($index + 1), (string) $case);
        }

        return $cases;
    }

    /**
     * One or two sentences telling an editor what this block puts on the page,
     * in the words they would use themselves — never the registry key, never
     * a table name, never "sectie van het type X". This is the whole
     * explanation of the block: the picker shows it on the card and the
     * catalogue shows the same sentence, because a second copy somewhere else
     * is guaranteed to drift.
     */
    abstract public function description(): string;

    /**
     * Which drawer this block sits in, as a key of
     * App\Service\Blocks\BlockCategories — presentation only: it groups the
     * picker and the catalogue and decides nothing else.
     */
    abstract public function category(): string;

    /**
     * The block's icon: the INNER markup of a 24x24 stroke <svg>, matching
     * the sidebar icons in admin/_header.php and the page-template cards in
     * admin/page-new.php. The view supplies the <svg> wrapper, so a definition
     * contributes shape data and never a whole element.
     *
     * This is trusted, hardcoded, first-party constant markup — it is echoed
     * unescaped, and must therefore never be built from request data, database
     * content or anything an administrator can type.
     */
    abstract public function icon(): string;

    /**
     * The schematic drawing on the block's card, as an ordered list of shapes
     * from App\Service\Blocks\BlockPreview's closed vocabulary — "a heading,
     * then three columns". Two or three parts is plenty; the card is small.
     *
     * Deliberately shapes and not a picture: a screenshot of a themeable
     * frontend goes stale silently. See BlockPreview's docblock. Returning
     * nothing is allowed and falls back to the icon alone.
     *
     * @return list<string>
     */
    public function preview(): array
    {
        return [];
    }

    /**
     * Two to four concrete situations this block is the right answer to, each
     * a short noun phrase ("een introductie", "een prijslijst"). The
     * catalogue prints them under "Geschikt voor"; the picker only searches
     * them, and shows one on a card while a search matches it — so they may
     * be more specific than the description.
     *
     * @return list<string>
     */
    public function useCases(): array
    {
        return [];
    }

    /**
     * What the Contentblokken library previews this block with: content in
     * exactly the shape render() hands this block's partial, made from the
     * words, the picture and the link App\Service\Blocks\BlockSamples holds.
     * Null means the block cannot be shown with sample content, and the
     * library says so next to its schematic drawing instead.
     *
     * Sample content is never a fallback. Nothing on the public site calls
     * this; a block without a stored row still renders nothing.
     *
     * @return array<string, mixed>|null
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        return null;
    }

    /**
     * Echoes this block with $content from sampleContent(), through the SAME
     * partial render() uses. That is the whole point: the preview is the
     * production markup with other words in it, never a second copy of it.
     * Only what render() looks up before calling the partial (a position,
     * a form's state) is supplied here instead. See admin/block-preview.php.
     *
     * @param array<string, mixed> $content from sampleContent()
     * @param string               $revealGroup stable and unique in the preview
     */
    public function renderSample(array $content, string $revealGroup): void
    {
    }

    /**
     * Creates a brand-new, empty/default content row on $pageSlug and returns
     * [section_id, section_key] ready for PageSectionRepository::create().
     * A repeatable block mints its own key with newSectionKey().
     *
     * @return array{0: int, 1: ?string}
     */
    abstract public function create(string $pageSlug): array;

    /**
     * Deletes this instance's own content row (and whatever cascades from
     * it). Runs inside SectionRegistry::delete()'s transaction, so it must
     * not commit, roll back or touch the filesystem — see deleteFiles().
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    abstract public function deleteContent(array $pageSection): void;

    /**
     * Echoes the block's markup, exactly like the partials/section-*.php it
     * calls. Responsible for its own STATE_HIDDEN check: a block hidden in
     * its own editor renders nothing, and that is separate from the page
     * builder's page_sections.is_active toggle, which the caller has already
     * filtered on.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     * @param bool                 $tightTop    this block directly follows a hero
     * @param string               $revealGroup stable, unique per attached instance
     */
    abstract public function render(array $pageSection, bool $tightTop, string $revealGroup): void;

    /**
     * The admin URL that edits THIS instance, or null when there is nothing
     * to edit. Almost always sectionEditUrl('<type>', $pageSection).
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    abstract public function editUrl(array $pageSection): ?string;

    /** Empties the block's per-request content cache after a write. */
    abstract public function clearCache(): void;

    /**
     * The table holding this block's content rows, or null when the block
     * has none of its own. Trusted, registered metadata — it exists so tests
     * can clean up after themselves without a second hand-maintained list,
     * and must never be built from request data.
     */
    abstract public function contentTable(): ?string;

    /**
     * The fields of this block whose words are stored per website language
     * in `block_translations` (Multilingual 2.0, docs/multilingual/ARCHITECTURE.md),
     * keyed by the table whose rows own them — contentTable(), and in phase
     * 3B also a child table:
     *
     *     return ['cta_bands' => [
     *         TranslatableField::plain('title', 255)->required(),
     *         TranslatableField::plain('lead', 500),
     *     ]];
     *
     * This declaration is the only list of those fields. App\Service\Blocks\
     * BlockLocalization refuses any other table or key, validates length and
     * "required in the default language" from it, and sanitizes a rich field
     * because it is declared rich. Everything else about the block (URLs,
     * switches, media, layout) is language-neutral and stays in its own table.
     *
     * Not abstract YET, and on purpose: until phase 3B converts them, most
     * blocks still keep their words in fixed `_nl`/`_en` columns, and an empty
     * declaration here would claim otherwise. [] means "not on
     * block_translations". BlockTranslationSchemaTest fails when a block
     * declares fields while its table still has `_nl`/`_en` columns.
     *
     * @return array<string, list<TranslatableField>> owner table => fields
     */
    public function translatableFields(): array
    {
        return [];
    }

    /**
     * Uploaded files belonging to this instance, deleted BEFORE the
     * transaction that removes the rows pointing at them, because the
     * filesystem is not transactional: a failed unlink must never leave a
     * file no row can find. Most blocks have none.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public function deleteFiles(array $pageSection): void
    {
    }

    /**
     * The block's OWN stylesheets, project-relative
     * ('assets/css/blocks/<type>.css'). A page collects these BEFORE it
     * writes its <head> (SectionRegistry::collectPageAssets()), so a block
     * never has to be added to a global stylesheet somebody else maintains,
     * and a page that does not carry the block never downloads them.
     *
     * Most blocks return nothing: their styling is shared enough to belong
     * in assets/css/core.css. Declare a file here only when it is genuinely
     * this block's own. App\Service\PageAssets ignores a path that is not a
     * real file under assets/, and Tests\Service\FrontendAssetOwnershipTest
     * fails on one that does not exist.
     *
     * @return list<string>
     */
    public function styles(): array
    {
        return [];
    }

    /**
     * The block's OWN scripts, project-relative
     * ('assets/js/blocks/<type>.js'). Same rules as styles(); they are
     * printed just before </body>, in the order the page asked for them.
     *
     * A block with no interactive behaviour declares nothing — there is no
     * empty file to create. A block rendered twice on one page still loads
     * its script once, and that script scopes itself per instance (see
     * CONTENT-BLOCKS.md).
     *
     * @return list<string>
     */
    public function scripts(): array
    {
        return [];
    }

    /**
     * Third-party libraries this block needs, by their key in
     * App\Service\PageAssets' closed VENDOR_SCRIPTS map — a block names a
     * library, never a URL. Today only 'gsap', wanted by the homepage hero
     * and by nothing else on the site.
     *
     * @return list<string>
     */
    public function vendorScripts(): array
    {
        return [];
    }

    /**
     * What tells this instance apart from another of the same type on the
     * same page in the page builder's list — usually its own title. Empty
     * means "the type label alone is enough".
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public function instanceTitle(array $pageSection): string
    {
        return '';
    }

    /**
     * Should a block rendered directly BELOW this one drop its own top
     * spacing? True for the heroes, whose bottom edge already supplies it —
     * without this the two would stack their padding twice (see
     * partials/section-text-image-split.php). It is a property of the block
     * above, so the block below never has to know what a hero is.
     */
    public function tightensFollowingBlock(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $pageSection
     */
    protected function pageSlug(array $pageSection): string
    {
        return (string) $pageSection['page_slug'];
    }

    /**
     * @param array<string, mixed> $pageSection
     */
    protected function sectionKey(array $pageSection): string
    {
        return (string) ($pageSection['section_key'] ?? '');
    }

    /**
     * @param array<string, mixed> $pageSection
     */
    protected function sectionId(array $pageSection): int
    {
        return (int) $pageSection['section_id'];
    }

    /**
     * The standard per-instance editor URL: admin/<file>.php?section=<page_slug>:<section_key>,
     * the one address shape every block editor and write endpoint parses.
     *
     * @param array<string, mixed> $pageSection
     */
    protected function sectionEditUrl(string $adminFile, array $pageSection): string
    {
        return '/admin/' . $adminFile . '.php?section='
            . urlencode($this->pageSlug($pageSection) . ':' . $this->sectionKey($pageSection));
    }

    /**
     * A short, random, URL-safe section_key for a brand-new instance — never
     * derived from admin-supplied text (no slugify/collision handling
     * needed), the same "random suffix" approach as SectionImageUploader's
     * filenames.
     */
    protected static function newSectionKey(): string
    {
        return 'custom-' . bin2hex(random_bytes(4));
    }
}
