<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProjectInfoRepository;
use App\Service\ContentOwners\ContentPages;

/**
 * The read model of the Projectinformatie block (App\Service\Blocks\ProjectInfoBlock):
 * how one instance shows its project's head, in the three states every block
 * has (CONTENT-BLOCKS.md), and WHICH project that is.
 *
 * NO SNAPSHOT. The block stores where the picture sits and whether the extra
 * photos follow; the picture, title, short text, intro, description and
 * categories are the project's, read through
 * PortfolioGalleryContent::itemForDetailPageById() at every render, in the
 * language of the request. A renamed project, a new main picture or a new
 * category shows at once, and nothing here can go stale.
 *
 * The project is the owner of the content page the block is on
 * (App\Service\ContentOwners\ContentPages): the block only exists on a
 * project's page (its `owners`), so its own page_slug says which project.
 * A project that is not visible (hidden, its project page off) is no project:
 * the block renders nothing, as the page itself would be a 404.
 */
final class ProjectInfoContent
{
    public const STATE_FALLBACK = 'fallback';
    public const STATE_ACTIVE = 'active';
    public const STATE_HIDDEN = 'hidden';

    /** Where the picture sits, in the editor's order; the first is the default. */
    public const POSITIONS = ['left', 'right', 'top'];

    public const DEFAULT_POSITION = 'left';

    /** @var array<string, array{state: string, image_position: string, show_gallery: bool}> */
    private static array $cache = [];

    /**
     * @return array{state: string, image_position: string, show_gallery: bool}
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new ProjectInfoRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[ProjectInfoContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_FALLBACK);
        }

        if (!(bool) $row['is_active']) {
            return self::$cache[$cacheKey] = self::emptyContent(self::STATE_HIDDEN);
        }

        return self::$cache[$cacheKey] = [
            'state' => self::STATE_ACTIVE,
            'image_position' => self::position((string) ($row['image_position'] ?? '')),
            'show_gallery' => (bool) $row['show_gallery'],
        ];
    }

    /**
     * The project whose page this block list is, as its page shows it now,
     * or null: not a project's content page, the project gone, or not
     * visible.
     *
     * @return array<string, mixed>|null PortfolioGalleryContent::itemForDetailPage()
     */
    public static function projectFor(string $pageSlug): ?array
    {
        $page = PageContent::forContentKey($pageSlug);
        $owner = $page === null ? null : ContentPages::ownerOf($page);

        if ($owner === null || $owner['owner']->kind() !== PortfolioContentOwner::KIND) {
            return null;
        }

        return PortfolioGalleryContent::itemForDetailPageById($owner['id']);
    }

    /**
     * Whether this project's content page holds a Projectinformatie block,
     * shown or hidden. A free project without one shows no head at all
     * (portfolio-detail.php); App\Service\ProjectInfoPlacement asks this
     * before it places one on the switch to the free layout, and the
     * project's Pagina-inhoud tab warns when a free project has none.
     */
    public static function isPlacedOn(int $projectId): bool
    {
        try {
            $page = ContentPages::pageFor(PortfolioContentOwner::KIND, $projectId);
            if ($page === null) {
                return false;
            }

            foreach ((new \App\Repository\PageSectionRepository())->findForPage((int) $page['id']) as $row) {
                if ((string) $row['section_type'] === 'project_info') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[ProjectInfoContent] isPlacedOn failed for #' . $projectId . ': ' . $e->getMessage());
        }

        return false;
    }

    /** A stored position, or the default for anything that is not one. */
    public static function position(string $stored): string
    {
        return in_array($stored, self::POSITIONS, true) ? $stored : self::DEFAULT_POSITION;
    }

    /** @return array{state: string, image_position: string, show_gallery: bool} */
    public static function emptyContent(string $state): array
    {
        return ['state' => $state, 'image_position' => self::DEFAULT_POSITION, 'show_gallery' => true];
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
