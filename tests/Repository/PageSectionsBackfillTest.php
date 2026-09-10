<?php

namespace Tests\Repository;

use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the two page_sections backfill migrations did not lose or
 * duplicate a single piece of content:
 *   - 20260907160000_create_page_sections_table.php attached every section
 *     the page templates used to render from a hardcoded slot;
 *   - 20260908250000_flatten_page_sections_into_one_list.php merged each
 *     page's zone sub-lists into ONE ordered list and promoted the content
 *     the templates hardcoded between them to fixed blocks in that list.
 *
 * Requires the migrations to have been run against the database this suite
 * connects to (the real local dev DB per this project's Docker setup; run
 * `phinx migrate` first if this fails with "no rows").
 *
 * These assertions are deliberately about PRESENCE, and about the list being
 * one contiguous sequence — not about a specific order. Order is editable
 * content: an administrator may drag any block anywhere on its page, so a
 * test pinning today's order would only assert that nobody has used the
 * feature yet (which is exactly how the previous version of this test broke
 * the first time the homepage's blocks were reordered from the CMS).
 */
#[Group('migration-backfill')]
class PageSectionsBackfillTest extends TestCase
{
    /**
     * Every block the original backfill attached, per page content_key —
     * "section_type:section_key" (empty key for the types that never had
     * one). The CTA bands read `cta_band:main` since phase 2 made that type
     * repeatable and gave every existing instance the migrated key `main` —
     * same rows, same content, now addressable per instance (see
     * db/migrations/20260908270000_make_cta_bands_repeatable_per_instance.php).
     *
     * @var array<string, list<string>>
     */
    private const BACKFILLED = [
        'index' => [
            'homepage_hero:',
            'marquee:materialenband',
            'feature_grid:value-props',
            'step_list:werkwijze',
            'stat_strip:capability-band',
            'cta_band:main',
        ],
        'shop' => ['page_hero:', 'cta_band:main'],
        'diensten' => ['page_hero:', 'faq:faq', 'cta_band:main'],
        'portfolio' => ['page_hero:', 'cta_band:main'],
        'over-mij' => [
            'page_hero:',
            'text_image_split:intro',
            'feature_grid:mijn-stijl',
            'text_image_split:idee-naar-product',
        ],
        'contact' => ['page_hero:'],
    ];

    /**
     * The blocks the flatten migration promoted out of the page templates,
     * per page content_key. Several are no longer FIXED — phase 2 turned
     * `contact_form` into an ordinary block and phase 3 turned
     * `services_carousel` into `card_carousel` — but the assertion below is
     * about PRESENCE: whatever they are called now, each must still be
     * attached exactly once to the page whose template used to hardcode it.
     *
     * `service_details` is deliberately absent: phase 3 split that one block
     * into FOUR `detail_section` instances, so "exactly once" is the wrong
     * shape for it — Tests\Service\ReusableBlocksPhase3Test owns that check.
     *
     * Phase 4 folded `portfolio_teaser` and `portfolio_gallery` into one
     * `item_gallery` type; each page still carries exactly one instance of
     * it, so the shape here is unchanged — only the name is.
     *
     * Written as "section_type:section_key", like BACKFILLED above, because
     * `card_carousel`, `item_gallery` and `detail_section` are repeatable
     * types: an administrator may add a second carousel to the homepage, and
     * counting instances by TYPE would read that as the migration having
     * duplicated something. The migrated instance is the one with the key
     * the migration wrote.
     *
     * @var array<string, list<string>>
     */
    private const TEMPLATE_OWNED_BLOCKS = [
        'index' => ['card_carousel:main', 'item_gallery:main'],
        'shop' => ['shop_collections:', 'product_grid:'],
        'diensten' => ['quicknav:'],
        'portfolio' => ['item_gallery:main'],
        'contact' => ['contact_form:main'],
    ];

    /**
     * page_sections is keyed by page_id since the unified page model
     * landed; the page's immutable content_key is still how a test (or
     * a page template) names the page it means.
     *
     * @return list<array<string, mixed>>
     */
    private function sectionsFor(string $contentKey): array
    {
        $page = (new PageRepository())->findByContentKey($contentKey);
        $this->assertNotNull($page, "expected a pages row for \"{$contentKey}\" — run phinx migrate");

        return (new PageSectionRepository())->findForPage((int) $page['id']);
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return list<string>
     */
    private function identities(array $sections): array
    {
        return array_map(
            static fn (array $s): string => $s['section_type'] . ':' . ($s['section_key'] ?? ''),
            $sections
        );
    }

    public function testEveryBackfilledSectionIsStillAttachedToItsPage(): void
    {
        foreach (self::BACKFILLED as $contentKey => $expected) {
            $attached = $this->identities($this->sectionsFor($contentKey));

            foreach ($expected as $identity) {
                $this->assertContains(
                    $identity,
                    $attached,
                    "\"{$identity}\" was rendered on \"{$contentKey}\" before the page builder existed and must still be attached"
                );
            }
        }
    }

    public function testEveryConfigurablePageHasItsPageHeroOrHomepageHeroAttached(): void
    {
        $this->assertNotNull($this->findFirstOfType($this->sectionsFor('index'), 'homepage_hero'));

        foreach (['shop', 'diensten', 'portfolio', 'over-mij', 'contact'] as $contentKey) {
            $this->assertNotNull(
                $this->findFirstOfType($this->sectionsFor($contentKey), 'page_hero'),
                "expected a page_hero attached to \"{$contentKey}\" — run phinx migrate"
            );
        }
    }

    public function testTemplateOwnedContentIsAttachedExactlyOnceAsAFixedBlock(): void
    {
        foreach (self::TEMPLATE_OWNED_BLOCKS as $contentKey => $identities) {
            $attached = $this->identities($this->sectionsFor($contentKey));

            foreach ($identities as $identity) {
                $this->assertCount(
                    1,
                    array_keys($attached, $identity, true),
                    "\"{$identity}\" must be attached to \"{$contentKey}\" exactly once — it is content that page's template used to hardcode"
                );
            }
        }
    }

    public function testEveryPageIsOneContiguousOrderedList(): void
    {
        foreach ((new PageRepository())->findAllForAdmin() as $page) {
            $sections = (new PageSectionRepository())->findForPage((int) $page['id']);
            if ($sections === []) {
                continue;
            }

            $sortOrders = array_map(static fn (array $s): int => (int) $s['sort_order'], $sections);

            $this->assertSame(
                range(0, count($sections) - 1),
                $sortOrders,
                "\"{$page['content_key']}\" must be one list numbered 0..n-1, with no gaps and no duplicate positions"
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private function findFirstOfType(array $sections, string $type): ?array
    {
        foreach ($sections as $section) {
            if ($section['section_type'] === $type) {
                return $section;
            }
        }

        return null;
    }
}
