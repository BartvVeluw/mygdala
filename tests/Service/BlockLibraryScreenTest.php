<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Blocks\BlockCategories;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Language\AdminLocale;
use PHPUnit\Framework\TestCase;

/**
 * The Contentblokken library, rendered in-process: a card per registered
 * block with what an editor needs to choose one, a "Voorbeeld bekijken"
 * button on every card, and the one dialog that shows the block.
 *
 * No login, no request and no database: admin/_block_library.php is
 * rendered directly, the way Tests\Service\BlockPickerTest renders the
 * picker, and the dialog's script is read as source. What a click, Escape
 * or a width button really does is checked in the browser (PAGE-EDITOR.md).
 */
final class BlockLibraryScreenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/admin/_block_library.php';
    }

    protected function setUp(): void
    {
        AdminLocale::overrideForTests('nl');
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'blog' => true, 'personalization' => true]);
    }

    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
        AdminLocale::overrideForTests(null);
        parent::tearDown();
    }

    private static function library(): string
    {
        ob_start();
        block_library(BlockDefinitions::all());

        return (string) ob_get_clean();
    }

    private static function dialog(): string
    {
        ob_start();
        block_library_preview_dialog();

        return (string) ob_get_clean();
    }

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // --- The cards ---------------------------------------------------------

    public function testEveryRegisteredBlockHasOneCardWithItsNameDescriptionCategoryAndDrawing(): void
    {
        $xpath = self::xpath(self::library());
        $cards = $xpath->query('//article[@data-block-library-card]');

        $this->assertNotFalse($cards);
        $this->assertSame(count(BlockDefinitions::all()), $cards->length);

        foreach (BlockDefinitions::all() as $type => $definition) {
            $name = $xpath->query('//article[@data-block-library-card][.//*[@data-block-library-name][normalize-space(.)="' . $definition->label() . '"]]');
            $this->assertNotFalse($name);
            $this->assertSame(1, $name->length, "{$type} has no card of its own");

            $card = $name->item(0);
            $this->assertSame($definition->describedFor(), trim((string) $xpath->query('.//*[@data-block-library-description]', $card)?->item(0)?->textContent));
            $this->assertSame(BlockCategories::label($definition->category()), trim((string) $xpath->query('.//*[@data-block-library-category]', $card)?->item(0)?->textContent), "{$type}'s card does not name its category");
            $this->assertSame(1, $xpath->query('.//svg[contains(@class, "admin-catalogue-card__icon")]', $card)?->length, "{$type}'s card has no icon");

            if ($definition->preview() !== []) {
                $this->assertSame(1, $xpath->query('.//*[contains(@class, "admin-block-visual")]', $card)?->length, "{$type}'s card has no drawing");
            }

            foreach ($definition->useCasesFor() as $case) {
                $this->assertStringContainsString(htmlspecialchars($case, ENT_QUOTES, 'UTF-8'), (string) $card->ownerDocument?->saveHTML($card), "{$type}'s card leaves out an example use");
            }
        }
    }

    /**
     * One button per card, named after its block for a screen reader, that
     * opens a dialog. A block with a sample points it at the preview of that
     * block; one without points it nowhere and gets the drawing instead.
     */
    public function testEveryCardHasAPreviewButtonThatPointsAtItsOwnPreviewOrAtNothing(): void
    {
        $xpath = self::xpath(self::library());
        $samples = new BlockSamples();

        foreach (BlockDefinitions::all() as $type => $definition) {
            $card = $xpath->query('//article[@data-block-library-card][.//*[@data-block-library-name][normalize-space(.)="' . $definition->label() . '"]]')?->item(0);
            $this->assertNotNull($card, $type);

            $buttons = $xpath->query('.//button[@data-block-preview-open]', $card);
            $this->assertNotFalse($buttons);
            $this->assertSame(1, $buttons->length, "{$type}'s card has no preview button");

            $button = $buttons->item(0);
            $this->assertInstanceOf(\DOMElement::class, $button);
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('dialog', $button->getAttribute('aria-haspopup'));
            $this->assertStringContainsString('Voorbeeld bekijken', $button->textContent);
            $this->assertStringContainsString($definition->label(), $button->textContent, 'the accessible name says which block');

            if ($definition->sampleContent($samples) === null) {
                $this->assertFalse($button->hasAttribute('data-block-preview-src'), "{$type} has no sample, so nothing to load");
                continue;
            }

            $this->assertSame('/admin/block-preview.php?type=' . rawurlencode($type), $button->getAttribute('data-block-preview-src'));
            $this->assertSame('Voorbeeld van ' . $definition->label(), $button->getAttribute('data-block-preview-frame-title'));
        }
    }

    public function testTheLibraryIsGroupedLikeThePickerAndChangesNothing(): void
    {
        $html = self::library();
        $xpath = self::xpath($html);

        $headings = [];
        foreach ($xpath->query('//h2[contains(@class, "admin-catalogue-group__title")]') ?: [] as $heading) {
            $headings[] = trim($heading->textContent);
        }

        $expected = array_map(
            static fn (string $key): string => BlockCategories::label($key),
            array_keys(BlockCategories::group(BlockDefinitions::all()))
        );

        $this->assertSame($expected, $headings);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('api/admin/', $html);
    }

    public function testASwitchedOffModulesBlocksAreNotInTheLibrary(): void
    {
        $this->assertStringContainsString('type=project_cards', self::library());

        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => false, 'blog' => false, 'personalization' => false]);
        $html = self::library();

        foreach (['project_cards', 'shop_collections', 'product_grid'] as $type) {
            $this->assertStringNotContainsString('type=' . $type, $html);
        }

        $this->assertStringNotContainsString('>' . BlockCategories::label(BlockCategories::SHOP) . '</h2>', $html, 'no empty Shop heading');
        $this->assertStringContainsString('type=rich_text', $html, 'Core is unaffected');
    }

    // --- The dialog --------------------------------------------------------

    public function testThePreviewIsAModalDialogWithAHeadingACloseButtonAndWidthChoices(): void
    {
        $xpath = self::xpath(self::dialog());

        $dialog = $xpath->query('//dialog[@data-block-preview]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $dialog);
        $this->assertSame('admin-block-preview-title', $dialog->getAttribute('aria-labelledby'));
        $this->assertSame(1, $xpath->query('//h2[@id="admin-block-preview-title"][@data-block-preview-title]')?->length);
        $this->assertSame(1, $xpath->query('//*[@id="admin-block-preview-description"][@data-block-preview-description]')?->length);

        $close = $xpath->query('//button[@data-block-preview-close][@type="button"]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $close);
        $this->assertSame('Sluiten', $close->getAttribute('aria-label'));

        $viewports = $xpath->query('//*[@role="group"]/button[@data-block-preview-viewport]');
        $this->assertNotFalse($viewports);

        $choices = [];
        foreach ($viewports as $button) {
            $this->assertInstanceOf(\DOMElement::class, $button);
            $choices[$button->getAttribute('data-block-preview-viewport')] = [trim($button->textContent), $button->getAttribute('aria-pressed')];
        }

        $this->assertSame(['desktop' => ['Desktop', 'true'], 'tablet' => ['Tablet', 'false'], 'mobile' => ['Mobiel', 'false']], $choices);
        $this->assertStringNotContainsString('<form', self::dialog());
    }

    /**
     * The frame may run the block's scripts and read its own origin's
     * stylesheets, and nothing more: no forms, no popups, no navigation of
     * the CMS around it.
     */
    public function testTheFrameIsSandboxedSoNothingInItCanBeSentOrLeaveIt(): void
    {
        $frame = self::xpath(self::dialog())->query('//iframe[@data-block-preview-frame]')?->item(0);

        $this->assertInstanceOf(\DOMElement::class, $frame);
        $this->assertSame('allow-scripts allow-same-origin', $frame->getAttribute('sandbox'));
        $this->assertSame('about:blank', $frame->getAttribute('src'));

        foreach (['allow-forms', 'allow-popups', 'allow-top-navigation', 'allow-modals'] as $permission) {
            $this->assertStringNotContainsString($permission, self::dialog());
        }
    }

    public function testTheDialogScriptOpensClosesAndReturnsFocusWithoutTextOrMarkupOfItsOwn(): void
    {
        $script = self::source('admin/assets/block-library.js');
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $script) ?? '';

        $this->assertStringContainsString('dialog.showModal()', $code, 'a native modal: inert page, Escape, focus kept inside');
        $this->assertStringContainsString('addEventListener("close"', $code, 'Escape, the button and a click beside it end in one place');
        $this->assertStringContainsString('lastFocused.focus()', $code, 'focus returns to the button that opened it');
        $this->assertStringContainsString('"about:blank"', $code, 'a closed preview stops running');
        $this->assertStringContainsString('getAttribute("data-block-preview-src")', $code, 'the address is the one the server printed');
        $this->assertStringContainsString('.textContent', $code);

        $this->assertStringNotContainsString('innerHTML', $code);
        $this->assertStringNotContainsString('fetch(', $code);
        $this->assertStringNotContainsString('block-preview.php', $code, 'the script never builds a preview address itself');
        $this->assertStringNotContainsString(':hover', $code);

        // No words of its own: every label is in the markup, in the CMS language.
        $this->assertDoesNotMatchRegularExpression('/"[^"]*\b(Voorbeeld|Sluiten|bekijken|Mobiel)\b[^"]*"/', $code);

        $this->assertStringContainsString("AssetVersion::url('/admin/assets/block-library.js')", self::source('admin/content-blocks.php'));
        $this->assertStringContainsString('block_library_preview_dialog();', self::source('admin/content-blocks.php'));
    }
}
