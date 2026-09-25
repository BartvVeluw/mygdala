<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The Portfolio screens are built from the shared admin controls
 * (ADMIN-UI.md), ask for nothing but an image, show that image before it is
 * saved, edit the item's own project page and gallery right on the item
 * (Portfolio 2.0) without making, choosing or linking an ordinary page, and
 * still send exactly what their endpoints read.
 *
 * Read from the source, like Tests\Service\AdminUiPrimitivesTest reads the
 * other screens that use the controls: no database and no web server. What a
 * click does is checked in a browser (ADMIN-UI.md, "Handmatig controleren");
 * that the endpoints accept an item without words and store the chosen page is
 * Tests\Service\PortfolioItemEditingHttpTest, and that the editor really
 * renders the stored image and offers the right pages is that test too.
 */
final class PortfolioAdminScreenTest extends TestCase
{
    private const ITEM_SCREEN = 'admin/portfolio-item.php';
    private const OVERVIEW = 'admin/portfolio.php';

    /**
     * @var list<string> the words an item has. One field each since
     *      Multilingual 2.0 phase 5 wave A: the screen edits ONE website
     *      language at a time, so there is no `_nl`/`_en` pair on the form.
     */
    private const WORDS = ['title', 'alt', 'subtitle'];

    public function testTheNewItemFormAsksForAnImageAndNothingElse(): void
    {
        $form = self::form('create-portfolio-item.php');

        // Media Library 2.0: the image is chosen (or uploaded into the
        // library) with the shared picker; the endpoint refuses an item
        // without one (portfolio.image_required).
        $this->assertStringContainsString("media_picker_field('media_id', \$chosenMedia, admin_t('common.image') . ' *'", $form);
        $this->assertStringNotContainsString('admin_file_input(', $form);

        foreach (self::WORDS as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $form, $name . ' is still on the form');
            $this->assertDoesNotMatchRegularExpression('/name="' . $name . '"[^>]*\brequired\b/', $form, $name . ' is optional');
        }

        $this->assertStringNotContainsString('admin_lang_required(', $form, 'no language of any word is required');
        $this->assertStringContainsString('portfolioCategoryField(', $form);
    }

    /**
     * Media Library 2.0: both forms choose the image from the library with the
     * shared picker, so "Afbeelding kiezen" never opens the operating
     * system's file dialog first. The picker shows the chosen image before
     * anything is saved; an item from before the library shows its own
     * picture beside the picker until another one is chosen.
     */
    public function testBothFormsChooseTheImageFromTheLibrary(): void
    {
        foreach (['create-portfolio-item.php', 'update-portfolio-item.php'] as $endpoint) {
            $form = self::form($endpoint);

            $this->assertStringContainsString("media_picker_field('media_id', \$chosenMedia,", $form, $endpoint);
            $this->assertStringNotContainsString('admin_file_input(', $form, $endpoint . ' has no file input of its own');
            $this->assertStringNotContainsString('type="file"', $form, $endpoint);
        }

        $edit = self::form('update-portfolio-item.php');
        $this->assertStringContainsString('<?php if ($hasLegacyImage && $chosenMedia === null): ?>', $edit);
        $this->assertStringContainsString('$cmsImageSrc($item)', $edit);

        $screen = self::source(self::ITEM_SCREEN);
        $this->assertStringContainsString('<?php media_picker_modal(); ?>', $screen);
        $this->assertStringContainsString('<?php media_picker_script(); ?>', $screen);

        foreach (['create-portfolio-item.php', 'update-portfolio-item.php'] as $endpoint) {
            $reads = self::source('api/admin/' . $endpoint);
            $this->assertStringContainsString("portfolioLibraryImage(\$_POST['media_id'] ?? null)", $reads, $endpoint);
            $this->assertStringNotContainsString('$_FILES', $reads, $endpoint . ' stores no upload of its own');
        }
    }

    /** The alt text is not required, and its help says when it may stay empty. */
    public function testTheAltTextExplainsWhenItMayStayEmpty(): void
    {
        // ONE alt field, in the website language the screen is editing
        // (Multilingual 2.0 phase 5 wave A) — not a Dutch and an English pane.
        foreach (['create-portfolio-item.php', 'update-portfolio-item.php'] as $endpoint) {
            $form = self::form($endpoint);

            $this->assertMatchesRegularExpression(
                '/admin_field_label\(\'portfolio-alt\', admin_t\(\'common\.alt_text\'\), admin_t\(\'help\.portfolio\.alt\'\)\)\s*\?>\s*<input type="text" id="portfolio-alt" name="alt"/',
                $form,
                $endpoint . ': the alt text carries its explanation'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/name="alt_(?:nl|en)"/',
                $form,
                $endpoint . ': no fixed Dutch/English alt field is left'
            );
        }

        $nl = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';
        $en = require dirname(__DIR__, 2) . '/src/Service/Language/messages/en.php';

        $this->assertStringContainsString(
            'Beschrijf de afbeelding wanneer deze inhoudelijke informatie bevat. Is de afbeelding alleen decoratief, dan mag dit veld leeg blijven.',
            $nl['help.portfolio.alt']
        );
        $this->assertArrayHasKey('help.portfolio.alt', $en);
    }

    /** Every on/off setting is a switch, every category a checkbox — none is the browser's own box. */
    public function testEveryCheckboxOnThePortfolioScreensIsTheSharedControl(): void
    {
        $item = self::source(self::ITEM_SCREEN);

        foreach (['is_active', 'is_featured'] as $name) {
            $this->assertStringContainsString('class="admin-switch" role="switch" name="' . $name . '" value="1"', $item, $name);
        }

        $this->assertStringContainsString('class="admin-checkbox" name="categories[]"', $item);

        foreach ([self::ITEM_SCREEN, self::OVERVIEW] as $screen) {
            $this->assertSame(
                0,
                preg_match_all('/<input type="checkbox"(?![^>]*class="admin-(?:checkbox|switch)")/', self::source($screen)),
                $screen . ' draws a checkbox of its own'
            );
        }
    }

    /**
     * A category is optional: no star, no required marker, and the wording
     * the old required label was built from is gone.
     */
    public function testTheCategoryIsNotMarkedRequired(): void
    {
        $item = self::source(self::ITEM_SCREEN);

        $this->assertStringNotContainsString('categories_required', $item);
        $this->assertStringNotContainsString("admin_t('portfolio.text'", $item);
        $this->assertMatchesRegularExpression('/function portfolioCategoryField\([\s\S]*?admin_help\(admin_t\(\'portfolio\.categories_field\'\), admin_t\(\'help\.portfolio\.categories\'\)\)/', $item);
    }

    public function testNothingAsksWithTheBrowsersOwnConfirm(): void
    {
        foreach ([self::ITEM_SCREEN, self::OVERVIEW] as $screen) {
            $source = self::source($screen);

            $this->assertStringNotContainsString('onsubmit=', $source, $screen);
            $this->assertStringContainsString('admin_confirm_attributes(', $source, $screen);
            $this->assertStringContainsString('<?= admin_confirm_dialog() ?>', $source, $screen);
        }
    }

    public function testTheOverviewUsesTheSharedSearchAndSelects(): void
    {
        $overview = self::source(self::OVERVIEW);

        $this->assertMatchesRegularExpression(
            '#<label class="admin-search[^"]*">\s*<span class="admin-visually-hidden">#',
            $overview,
            'the search field keeps a name a screen reader can say'
        );
        $this->assertStringContainsString('<input type="search"', $overview);

        $toolbar = substr($overview, (int) strpos($overview, 'data-portfolio-toolbar'), 4000);
        $this->assertSame(4, substr_count($toolbar, '<select class="admin-select"'), 'every filter is the shared select');
        $this->assertStringContainsString('<option value="_none"', $toolbar, 'uncategorised items can be found');

        $script = self::source('admin/assets/portfolio-admin.js');
        $this->assertStringContainsString('state.cat === "_none"', $script);
    }

    /**
     * The project page is the item's own (Portfolio 2.0): a switch, an
     * address and two rich texts, edited right on the item — the fields the
     * page the Portfolio used to own was made of, back on the screen.
     */
    public function testTheProjectPageIsEditedOnTheItemItself(): void
    {
        $edit = self::form('update-portfolio-item.php');
        $item = self::source(self::ITEM_SCREEN);

        $this->assertStringContainsString('class="admin-switch" role="switch" name="has_detail_page" value="1"', $edit);
        $this->assertStringContainsString("admin_help(admin_t('portfolio.show_project_page'), admin_t('help.portfolio.show_project_page'))", $edit);
        $this->assertStringContainsString('<input type="text" id="portfolio-slug" name="slug"', $edit);
        $this->assertStringContainsString('autocomplete="off" spellcheck="false" data-slug-target>', $edit);
        $this->assertStringContainsString('<input type="hidden" name="slug_auto" value="0" data-slug-auto>', $edit);
        $this->assertStringContainsString("renderRichTextField(PortfolioLocalization::INTRO, admin_t('portfolio.intro')", $edit);
        $this->assertStringContainsString("renderRichTextField(PortfolioLocalization::DESCRIPTION, admin_t('portfolio.description')", $edit);
        $this->assertStringContainsString("require_once __DIR__ . '/_richtext_field.php';", $item);
        $this->assertStringContainsString('/admin/assets/vendor/quill/quill.min.js', $item, 'its editor comes along');

        $nl = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';
        $en = require dirname(__DIR__, 2) . '/src/Service/Language/messages/en.php';

        $this->assertSame('Projectpagina', $nl['portfolio.project_page']);
        $this->assertSame('Projectpagina tonen', $nl['portfolio.show_project_page']);
        foreach (['portfolio.show_project_page', 'help.portfolio.show_project_page', 'portfolio.slug', 'help.portfolio.slug', 'portfolio.intro', 'portfolio.description', 'portfolio.gallery.heading', 'portfolio.gallery.add', 'portfolio.legacy_page', 'portfolio.unlink_page', 'validation.portfolio_slug_taken', 'validation.portfolio_slug_empty'] as $key) {
            $this->assertArrayHasKey($key, $nl, $key);
            $this->assertArrayHasKey($key, $en, $key);
        }
    }

    /**
     * No ordinary page is made, chosen or linked from the Portfolio any more:
     * no "Nieuwe pagina maken", no page select, and no endpoint of Pages. A
     * LEGACY link is shown only on an item that has one, with unlinking as
     * the one thing left to do with it.
     */
    public function testNoPageIsMadeOrLinkedFromThePortfolio(): void
    {
        $item = self::source(self::ITEM_SCREEN);
        $edit = self::form('update-portfolio-item.php');

        $this->assertStringNotContainsString('page-new.php', $item, 'no "Nieuwe pagina maken"');
        $this->assertStringNotContainsString('create-page.php', $item, 'the Portfolio posts no page of its own');
        $this->assertStringNotContainsString('name="page_id"', $item, 'no page can be chosen');
        $this->assertStringNotContainsString('linkablePages', $item);

        $this->assertMatchesRegularExpression('#<\?php if \(\$legacyPage !== null\): \?>[\s\S]*name="unlink_page" value="1"[\s\S]*<\?php endif; \?>#', $edit);

        $reads = self::source('api/admin/update-portfolio-item.php') . self::source('api/admin/_portfolio_validation.php');
        $this->assertStringNotContainsString("\$_POST['page_id']", $reads, 'a posted page id is never read');
        $this->assertStringNotContainsString("\$input['page_id']", $reads);
    }

    /**
     * Categorieën and Zichtbaarheid are two cards side by side, one column on
     * a narrow screen — not a category list pressed against the switches.
     */
    public function testCategoriesAndVisibilityAreTwoCards(): void
    {
        $edit = self::form('update-portfolio-item.php');

        $this->assertMatchesRegularExpression(
            '#<div class="admin-card-pair">\s*<section class="admin-card">\s*<h2><\?= admin_te\(\'portfolio\.categories_field\'\) \?></h2>\s*<\?= portfolioCategoryField\([\s\S]*?</section>\s*<section class="admin-card">\s*<h2><\?= admin_te\(\'portfolio\.visibility\'\) \?></h2>[\s\S]*?name="is_active"[\s\S]*?name="is_featured"[\s\S]*?</section>\s*</div>#',
            $edit
        );

        $css = self::source('admin/assets/admin.css');
        $this->assertMatchesRegularExpression('/\.admin-card-pair\{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 900px\)\{[\s\S]*?\.admin-card-pair\{ grid-template-columns: 1fr; \}/', $css);
    }

    /**
     * The gallery is chosen with the shared picker in collect mode and ordered
     * by the product editor's own script — no file input, no second strip.
     */
    public function testTheGalleryUsesThePickerAndTheSharedStrip(): void
    {
        $edit = self::form('update-portfolio-item.php');
        $item = self::source(self::ITEM_SCREEN);

        $this->assertStringContainsString('data-picture-gallery data-gallery-input="gallery[]" data-gallery-first-badge="" data-gallery-exclude-input="media_id"', $edit);
        $this->assertStringContainsString('<input type="hidden" name="gallery_submitted" value="1" data-gallery-marker>', $edit);
        $this->assertStringContainsString('data-media-picker data-media-picker-kind="image" data-media-picker-collect', $edit);
        $this->assertStringContainsString('<input type="hidden" name="gallery[]" value="', $item);
        $this->assertStringContainsString('/admin/assets/product-gallery.js', $item);
        $this->assertStringNotContainsString('type="file"', $item);

        $script = self::source('admin/assets/product-gallery.js');
        $this->assertStringContainsString('document.querySelector("[data-product-gallery], [data-picture-gallery]")', $script);
    }

    /**
     * What the forms SEND is what their endpoints read: the same names, and
     * the same hidden item id.
     */
    public function testTheFormsStillSendWhatTheirEndpointsRead(): void
    {
        foreach (['create-portfolio-item.php', 'update-portfolio-item.php'] as $endpoint) {
            $form = self::form($endpoint);
            $reads = self::source('api/admin/' . $endpoint);

            preg_match_all("/\\\$_POST\\['([a-z_]+)'\\]/", $reads, $posted);

            foreach (array_unique($posted[1]) as $name) {
                if ($name === 'categories') {
                    $this->assertStringContainsString('portfolioCategoryField(', $form, $endpoint . ' reads categories[]');
                    continue;
                }

                // Each photo's hidden input is drawn by portfolioGalleryCard()
                // and by the strip's script; the form names the list.
                if ($name === 'gallery') {
                    $this->assertStringContainsString('data-gallery-input="gallery[]"', $form, $endpoint . ' reads gallery[]');
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/name="' . preg_quote($name, '/') . '"|renderRichTextField\(\'' . preg_quote($name, '/') . '\'|media_picker_field\(\'' . preg_quote($name, '/') . '\'/',
                    $form,
                    $endpoint . ' reads ' . $name . ', so the form must still send it'
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */

    /** One form of the item screen, from its opening tag to its close. */
    private static function form(string $endpoint): string
    {
        $source = self::source(self::ITEM_SCREEN);
        $start = strpos($source, 'action="/api/admin/' . $endpoint . '"');
        self::assertNotFalse($start, 'no form posts to ' . $endpoint);

        $start = (int) strrpos(substr($source, 0, $start), '<form');
        $end = strpos($source, '</form>', $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
