<?php

declare(strict_types=1);

require_once __DIR__ . '/project-hero.php';

/**
 * The Projectinformatie block (App\Service\Blocks\ProjectInfoBlock): the
 * project's own head — the very markup a project page with a fixed layout
 * prints (partials/project-hero.php) — where the editor placed it on a
 * project with the free layout. Everything it shows comes in as arguments:
 * the block's settings and the project as its page reads it now.
 *
 * @param array{state: string, image_position: string, show_gallery: bool} $content
 * @param array<string, mixed> $project PortfolioGalleryContent::itemForDetailPage()
 */
function render_section_project_info(array $content, array $project, ?string $backUrl, string $revealGroup): void
{
    render_project_hero(
        $project,
        (string) $content['image_position'],
        (bool) $content['show_gallery'],
        $backUrl,
        $revealGroup,
        true
    );
}
