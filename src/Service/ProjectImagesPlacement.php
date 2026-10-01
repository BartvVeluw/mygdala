<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageSectionRepository;
use App\Service\Blocks\ProjectImagesBlock;
use App\Service\ContentOwners\ContentPages;

/**
 * Puts a project's Projectafbeeldingen block (App\Service\Blocks\ProjectImagesBlock)
 * on its content page (Portfolio 3.0). The block is fixed — never added from
 * the picker, never deleted — so this is the only way one comes to exist,
 * besides db/migrations/20261014100000, which placed it on every project that
 * was there before.
 *
 * Called when a project is made (api/admin/create-portfolio-item.php): the
 * project has no blocks yet, so the photos stand directly under the project's
 * head, where they always stood. From then on it is an ordinary row in the
 * project's block list: dragged, hidden, shown, styled.
 *
 * AT MOST ONE. Its page_sections.section_id is the project's id, so the
 * table's UNIQUE(section_type, section_id) refuses a second row for the same
 * project whatever happens at the same moment; a duplicate is the answer
 * "already there", not an error.
 *
 * NOT HERE: the photos themselves (the project's gallery,
 * portfolio_item_images) and rendering (the block).
 */
final class ProjectImagesPlacement
{
    /**
     * Places the block at the top of this project's blocks unless it has one;
     * makes its content page when it has none. True when a block was placed.
     */
    public static function ensure(int $projectId): bool
    {
        $page = ContentPages::ensure(PortfolioContentOwner::KIND, $projectId);
        $sections = new PageSectionRepository();

        if ($sections->findBySectionTypeAndId(ProjectImagesBlock::TYPE, $projectId) !== null) {
            return false;
        }

        try {
            $newId = $sections->create((int) $page['id'], (string) $page['content_key'], ProjectImagesBlock::TYPE, null, $projectId);
        } catch (\PDOException $e) {
            // The same project's block, placed by another request just now.
            if ($sections->findBySectionTypeAndId(ProjectImagesBlock::TYPE, $projectId) !== null) {
                return false;
            }

            throw $e;
        }

        // Rows not named keep their order after the new one.
        $sections->reorder((int) $page['id'], [$newId]);

        PageContent::clearCache();

        return true;
    }
}
