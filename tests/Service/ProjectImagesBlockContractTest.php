<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinition;
use App\Service\Blocks\FixedBlockDefinition;
use App\Service\Blocks\ProjectImagesBlock;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioProjectLayout;
use PHPUnit\Framework\TestCase;

/**
 * Portfolio 3.0, read from the source and the definitions alone (no
 * database): the project page is a fixed head plus page content, and the
 * extra photos are the Projectafbeeldingen block.
 *
 *   - the block is fixed, one per project, never deleted, only on a project,
 *     and owns nothing: no table, no words, no media field, nothing to search;
 *   - the photos have exactly one renderer, the block's partial, which prints
 *     nothing without photos; the head prints none;
 *   - the project layout has three values, all about the head;
 *   - Projectinformatie and the free layout are gone, code and words.
 */
final class ProjectImagesBlockContractTest extends TestCase
{
    public function testTheBlockIsFixedSingleAndOnlyOnAProject(): void
    {
        $block = new ProjectImagesBlock();
        $meta = $block->meta();

        $this->assertInstanceOf(FixedBlockDefinition::class, $block, 'no content row of its own');
        $this->assertSame('project_images', $block->type());
        $this->assertFalse($meta['manual_add'], 'never offered in the picker');
        $this->assertFalse($meta['deletable'], 'hidden, never deleted');
        $this->assertFalse($meta['allow_multiple']);
        $this->assertSame(1, $meta['max_instances']);
        $this->assertSame([PortfolioContentOwner::KIND], $meta['owners'], 'only on a project\'s page');
        $this->assertSame(BlockDefinition::KIND_DYNAMIC, $meta['kind']);
        $this->assertSame('Projectafbeeldingen', $meta['label']);
    }

    public function testTheBlockOwnsNothing(): void
    {
        $block = new ProjectImagesBlock();

        $this->assertNull($block->contentTable(), 'no table');
        $this->assertSame([], $block->translatableFields(), 'no words');
        $this->assertSame([], $block->searchFields(), 'nothing to search: alt texts stay out of the search');
        $this->assertNull($block->editUrl(['section_type' => 'project_images']), 'no editor: the photos are edited on the project');

        $source = self::source('src/Service/Blocks/ProjectImagesBlock.php') . self::source('partials/section-project-images.php');
        foreach (['media_picker', 'MediaService::', 'INSERT ', 'UPDATE ', 'DELETE '] as $absent) {
            $this->assertStringNotContainsString($absent, $source, $absent . ': the block reads the project\'s photos, it never keeps or picks one');
        }
        $this->assertStringContainsString('PortfolioGalleryContent::itemForDetailPageById(', self::source('src/Service/Blocks/ProjectImagesBlock.php'), 'live from the project');
    }

    public function testThePhotosHaveOneRendererThatPrintsNothingWithoutPhotos(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/section-project-images.php';

        ob_start();
        render_section_project_images([], 'ZZ', 'project-1', 'project_images-1');
        $this->assertSame('', ob_get_clean(), 'no photos, no section');

        $hero = self::source('partials/project-hero.php');
        $this->assertStringNotContainsString('project-gallery', $hero, 'the head prints no photos');
        $this->assertStringNotContainsString("['images']", $hero);

        $detail = self::source('portfolio-detail.php');
        $this->assertStringNotContainsString('project-gallery', $detail, 'no automatic renderer on the page');
        $this->assertStringNotContainsString('PortfolioProjectLayout::FREE', $detail);
        $this->assertSame(1, substr_count($detail, 'render_project_hero('), 'one fixed head, always');
    }

    public function testTheLayoutIsAboutTheHeadOnly(): void
    {
        $this->assertSame(['image_left', 'image_right', 'image_top'], PortfolioProjectLayout::LAYOUTS);
        $this->assertSame(PortfolioProjectLayout::LAYOUTS, PortfolioProjectLayout::DEFAULTS);
        $this->assertFalse(PortfolioProjectLayout::isValid('free'), 'the free layout is retired');
        $this->assertNull(PortfolioProjectLayout::ownChoice('free'), 'a stored "free" reads as the default');
        $this->assertStringNotContainsString('ProjectImages', self::source('src/Service/PortfolioProjectLayout.php') . "\n" . self::source('partials/project-hero.php'), 'the layout never decides about the photos');
    }

    public function testProjectinformatieIsGone(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Service/Blocks/ProjectInfoBlock.php', 'src/Service/ProjectInfoContent.php', 'src/Service/ProjectInfoPlacement.php',
            'src/Repository/ProjectInfoRepository.php', 'partials/section-project-info.php', 'admin/project-info.php', 'api/admin/update-project-info.php',
        ] as $file) {
            $this->assertFileDoesNotExist($root . '/' . $file);
        }

        $module = self::source('src/Module/PortfolioModule.php');
        $this->assertStringNotContainsString("'project_info'", $module);
        $this->assertStringContainsString("'project_images' => \\App\\Service\\Blocks\\ProjectImagesBlock::class", $module);

        foreach (['nl', 'en'] as $language) {
            $messages = require $root . '/src/Service/Language/messages/' . $language . '.php';
            foreach (array_keys($messages) as $key) {
                $this->assertStringNotContainsString('project_info', $key, $language . ': ' . $key);
                $this->assertNotSame('portfolio.layout.free', $key, $language);
            }
            $this->assertArrayHasKey('block.project_images.label', $messages, $language);
        }
    }

    private static function source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }
}
