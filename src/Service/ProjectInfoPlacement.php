<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageSectionRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ContentOwners\ContentPages;

/**
 * THE MOMENT A PROJECT TURNS FREE (CONTENT-BLOCKS.md "Blokken op een product
 * of project", the free layout). In the free layout the project page shows
 * its blocks and nothing else: no head of its own, whether or not a
 * Projectinformatie block is on it (portfolio-detail.php). So that switching
 * to it never leaves an editor with a page that suddenly lacks its title and
 * picture, the switch itself places ONE Projectinformatie block at the top of
 * a project that has none yet:
 *
 *   - only on the switch (a fixed layout before, the free one after), never
 *     on a later save, so a block the editor deleted stays deleted;
 *   - never a second one (ProjectInfoContent::isPlacedOn(), shown or hidden,
 *     and the block's own max_instances);
 *   - from then on an ordinary block: moved, hidden or deleted like any other.
 *
 * Two switches lead there: a project's own layout
 * (api/admin/update-portfolio-item.php) and the Portfolio default, for every
 * project page that follows it (api/admin/update-portfolio-settings.php).
 *
 * NOT HERE: rendering (portfolio-detail.php, ProjectInfoBlock) and going back
 * to a fixed layout, which changes nothing stored: the blocks stay, and the
 * Projectinformatie block prints nothing in a fixed layout.
 */
final class ProjectInfoPlacement
{
    public const TYPE = 'project_info';

    /**
     * Whether a layout change from $before to $after is the switch to the
     * free layout (layouts as PortfolioProjectLayout::forItem() gives them).
     */
    public static function isSwitchToFree(string $before, string $after): bool
    {
        return $before !== PortfolioProjectLayout::FREE && $after === PortfolioProjectLayout::FREE;
    }

    /**
     * Puts a Projectinformatie block at the top of this project's blocks
     * unless it has one; makes its content page when it has none. True when
     * a block was placed.
     */
    public static function ensureOnTop(int $projectId): bool
    {
        if (ProjectInfoContent::isPlacedOn($projectId)) {
            return false;
        }

        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $projectId);
        $sections = new PageSectionRepository();

        [$sectionId, $sectionKey] = SectionRegistry::create(self::TYPE, (string) $page['content_key']);
        $newId = $sections->create((int) $page['id'], (string) $page['content_key'], self::TYPE, $sectionKey, $sectionId);

        // Rows not named keep their order after the new one.
        $sections->reorder((int) $page['id'], [$newId]);

        PageContent::clearCache();
        ProjectInfoContent::clearCache();

        return true;
    }

    /**
     * The Portfolio default turned free: every project page that follows the
     * default (no layout of its own) gets its block. Projects without a
     * project page have nothing to show it on.
     *
     * @return int how many blocks were placed
     */
    public static function ensureOnTopOfFollowers(): int
    {
        $repository = new PortfolioGalleryRepository();
        $placed = 0;

        foreach ($repository->findItemsByGalleryId((int) ($repository->ensureCatalogue()['id'] ?? 0)) as $item) {
            if (PortfolioProjectLayout::ownChoice($item['project_layout'] ?? null) !== null
                || (int) ($item['has_detail_page'] ?? 0) !== 1
            ) {
                continue;
            }

            if (self::ensureOnTop((int) $item['id'])) {
                $placed++;
            }
        }

        return $placed;
    }
}
