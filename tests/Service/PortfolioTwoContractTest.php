<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The contracts of Portfolio 2.0 that live in source, read from it (tier
 * `contract`: no database, no web server):
 *
 *   - ONE LIGHTBOX (assets/js/lightbox.js, partials/lightbox.php) for a
 *     gallery block and a project page: a dialog with names on its buttons,
 *     the sequence taken from the opener's own group and only from what is
 *     shown, keyboard and focus handled, and no second copy anywhere;
 *   - a project page renders from the item and asks for nothing but that one
 *     script;
 *   - the Portfolio makes no page: no Pages endpoint, no page-new.php link.
 *
 * What a click does is checked in a browser (the Portfolio 2.0 report); what
 * the endpoints store is Tests\Service\PortfolioItemEditingHttpTest.
 */
final class PortfolioTwoContractTest extends TestCase
{
    public function testThereIsOneLightboxScriptAndTheOldOnesAreGone(): void
    {
        $this->assertFileExists(self::path('assets/js/lightbox.js'));
        $this->assertFileDoesNotExist(self::path('assets/js/portfolio-detail.js'), 'the project page has no lightbox of its own any more');

        $gallery = self::source('assets/js/blocks/item-gallery.js');
        $this->assertStringNotContainsString('lightbox.classList', $gallery, 'the gallery block drives no lightbox itself');
        $this->assertStringNotContainsString('data-item-lightbox', $gallery);

        foreach (['partials/section-item-gallery.php', 'portfolio-detail.php'] as $renderer) {
            $source = self::source($renderer);
            $this->assertStringContainsString("require_once __DIR__ . '/", $source);
            $this->assertStringContainsString('lightbox.php', $source, $renderer . ' prints the shared overlay');
            $this->assertStringNotContainsString('data-project-lightbox', $source);
            $this->assertStringNotContainsString('data-item-lightbox', $source);
        }

        $this->assertStringContainsString("\\App\\Service\\PageAssets::requireScript('assets/js/lightbox.js');", self::source('portfolio-detail.php'));
    }

    public function testTheOverlayIsADialogWithNamedButtons(): void
    {
        $overlay = self::source('partials/lightbox.php');

        $this->assertMatchesRegularExpression('/<div class="lightbox" data-lightbox role="dialog" aria-modal="true" aria-hidden="true" aria-label="/', $overlay);
        foreach (['data-lightbox-close', 'data-lightbox-prev', 'data-lightbox-next'] as $button) {
            $this->assertMatchesRegularExpression('/<button type="button" class="lightbox__[a-z_ -]+" ' . $button . ' aria-label="<\?= /', $overlay, $button . ' has a name');
        }
        $this->assertStringContainsString("'nl' => 'Vorige afbeelding'", $overlay);
        $this->assertStringContainsString("'nl' => 'Volgende afbeelding'", $overlay);
        $this->assertStringContainsString('data-lightbox-counter aria-live="polite"', $overlay);

        $css = self::source('assets/css/core.css');
        $this->assertStringContainsString('.lightbox__nav[hidden], .lightbox__counter[hidden], .lightbox__caption[hidden]{ display: none; }', $css, 'a hidden arrow really is hidden');
    }

    /**
     * The sequence is the opener's group, filtered to what is shown right now:
     * a filtered category never lends a hidden picture to previous/next, and a
     * project page never steps into another block's pictures.
     */
    public function testTheSequenceIsTheOpenersGroupAndOnlyWhatIsShown(): void
    {
        $script = self::source('assets/js/lightbox.js');

        $this->assertStringContainsString('trigger.closest("[data-lightbox-group]")', $script);
        $this->assertStringContainsString('group.querySelectorAll("[data-lightbox-trigger]")', $script);
        $this->assertStringContainsString('return element.getClientRects().length > 0;', $script, 'a card hidden by the filter is left out');
        $this->assertStringContainsString('(current + delta + slides.length) % slides.length', $script, 'it wraps around, like the project lightbox always did');

        $this->assertStringContainsString('data-gallery-block data-lightbox-group', self::source('partials/section-item-gallery.php'), 'each gallery block is its own group');
        $this->assertStringContainsString('<section class="project-hero" data-lightbox-group>', self::source('portfolio-detail.php'), 'a project page is one group');
    }

    public function testKeyboardAndFocusAreHandled(): void
    {
        $script = self::source('assets/js/lightbox.js');

        foreach (['"Escape"', '"ArrowRight"', '"ArrowLeft"', '"Tab"'] as $key) {
            $this->assertStringContainsString('event.key === ' . $key, $script, $key);
        }
        $this->assertStringContainsString('closeBtn.focus();', $script, 'focus moves into the dialog');
        $this->assertStringContainsString('opener.focus();', $script, 'and back to the picture that opened it');
        $this->assertStringContainsString('"touchend"', $script, 'a swipe steps on a touch screen');
        $this->assertStringContainsString('captionEl.textContent = slide.caption;', $script, 'a caption is text, never markup');
        $this->assertStringNotContainsString('innerHTML', $script);
    }

    /** A zoomable card's picture is a real button; its call to action a real link, not inside it. */
    public function testAZoomableCardIsAButtonAndItsCallToActionALink(): void
    {
        $partial = self::source('partials/section-item-gallery.php');

        $this->assertStringContainsString('<button type="button" class="gallery-item__zoom" data-lightbox-trigger', $partial);
        $this->assertStringContainsString('aria-label="<?= $h($zoomLabel) ?>"><?= $imageTag ?></button>', $partial);
        $this->assertStringContainsString("\$overlay .= '<a class=\"gallery-item__cta\" href=\"'", $partial);
        $this->assertStringContainsString("!empty(\$item['opens_lightbox'])", $partial, 'a source may ask for the zoom whatever the block says');

        $css = self::source('assets/css/blocks/item-gallery.css');
        $this->assertStringContainsString('.gallery-item--zoom .gallery-item__overlay{ pointer-events: none; }', $css, 'the overlay lets a click through to the picture');
        $this->assertMatchesRegularExpression('/\.gallery-item__cta\{\s*pointer-events: auto;/', $css, 'except on the button');
    }

    /** The Portfolio makes, chooses and links no ordinary page. */
    public function testThePortfolioMakesNoPage(): void
    {
        foreach (['admin/portfolio-item.php', 'api/admin/update-portfolio-item.php', 'api/admin/create-portfolio-item.php', 'portfolio-detail.php'] as $file) {
            $source = self::source($file);
            $this->assertStringNotContainsString('page-new.php', $source, $file);
            $this->assertStringNotContainsString('create-page.php', $source, $file);
            $this->assertStringNotContainsString('PageRepository())->create', $source, $file);
            $this->assertStringNotContainsString('PageFixture', $source, $file);
        }
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private static function source(string $relative): string
    {
        $path = self::path($relative);
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
