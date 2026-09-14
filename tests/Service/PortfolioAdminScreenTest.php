<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The Portfolio screens are built from the shared admin controls
 * (ADMIN-UI.md), ask for nothing but an image, show that image before it is
 * saved, offer the project page as one choice of an ordinary page, and still
 * send exactly what their endpoints read.
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

    /** @var list<string> the words an item has, per language */
    private const WORDS = ['title_nl', 'title_en', 'alt_nl', 'alt_en', 'subtitle_nl', 'subtitle_en'];

    /** @var list<string> what the project page the Portfolio used to own was made of */
    private const OLD_PROJECT_PAGE_FIELDS = ['has_detail_page', 'slug', 'intro_nl', 'intro_en', 'description_nl', 'description_en'];

    public function testTheNewItemFormAsksForAnImageAndNothingElse(): void
    {
        $form = self::form('create-portfolio-item.php');

        $this->assertStringContainsString(
            "admin_file_input(['name' => 'image', 'id' => 'portfolio-image', 'accept' => 'image/jpeg,image/png,image/webp', 'required' => true])",
            $form
        );

        foreach (self::WORDS as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $form, $name . ' is still on the form');
            $this->assertDoesNotMatchRegularExpression('/name="' . $name . '"[^>]*\brequired\b/', $form, $name . ' is optional');
        }

        $this->assertStringNotContainsString('admin_lang_required(', $form, 'no language of any word is required');
        $this->assertStringContainsString('portfolioCategoryField(', $form);
    }

    /**
     * The chosen image is shown the moment it is picked, and on the editor the
     * same box shows the stored one until another is chosen — the preview is
     * paired with the input by its id.
     */
    public function testBothFormsShowTheImageBeforeItIsSaved(): void
    {
        $this->assertStringContainsString("admin_file_preview('portfolio-image')", self::form('create-portfolio-item.php'));

        $edit = self::form('update-portfolio-item.php');
        $this->assertStringContainsString("admin_file_preview('portfolio-image', \$cmsImageSrc(\$item))", $edit);
        $this->assertStringContainsString(
            "admin_file_input(['name' => 'image', 'id' => 'portfolio-image', 'accept' => 'image/jpeg,image/png,image/webp'])",
            $edit,
            'replacing the image stays optional'
        );
    }

    /** The alt text is not required, and its help says when it may stay empty. */
    public function testTheAltTextExplainsWhenItMayStayEmpty(): void
    {
        foreach (['create-portfolio-item.php', 'update-portfolio-item.php'] as $endpoint) {
            $form = self::form($endpoint);

            foreach (['alt_nl', 'alt_en'] as $name) {
                $this->assertMatchesRegularExpression(
                    '/admin_field_label\(\'portfolio-alt-(nl|en)\', admin_t\(\'common\.alt_text\'\), admin_t\(\'help\.portfolio\.alt\'\)\)\s*\?>\s*<input type="text" id="portfolio-alt-\1" name="alt_\1"/',
                    $form,
                    $endpoint . ': ' . $name . ' carries its explanation'
                );
            }
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
     * The project page is one choice in the shared select, explained, with
     * "no page" as its first and valid answer. What the old project page was
     * made of — its switch, slug, rich texts and extra photos — is not on the
     * screen any more.
     */
    public function testTheProjectPageIsOneChoiceOfAnOrdinaryPage(): void
    {
        $edit = self::form('update-portfolio-item.php');
        $item = self::source(self::ITEM_SCREEN);

        $this->assertMatchesRegularExpression(
            '#admin_field_label\(\'portfolio-page\', admin_t\(\'portfolio\.project_page\'\), admin_t\(\'help\.portfolio\.project_page\'\)\)\s*\?>\s*<select class="admin-select" id="portfolio-page" name="page_id">\s*<option value=""><\?= admin_te\(\'portfolio\.no_linked_page\'\) \?></option>#',
            $edit
        );
        $this->assertStringContainsString('$linkablePages = PortfolioGalleryContent::linkablePages();', $item, 'the pages come from the one rule an item may link to');

        foreach (self::OLD_PROJECT_PAGE_FIELDS as $name) {
            $this->assertStringNotContainsString('name="' . $name . '"', $edit, $name . ' belonged to the old project page');
            $this->assertStringNotContainsString("renderRichTextField('" . $name . "'", $edit);
        }

        foreach (['add-portfolio-item-images.php', 'update-portfolio-item-image.php', 'delete-portfolio-item-image.php', 'reorder-portfolio-item-images.php'] as $endpoint) {
            $this->assertStringNotContainsString($endpoint, $item, 'the old extra photos are not edited here any more');
        }

        $this->assertStringNotContainsString('portfolio.geavanceerd_projectpagina', $item);
        $this->assertStringNotContainsString('_richtext_field.php', $item, 'no rich text is left on this screen');
        $this->assertStringNotContainsStringIgnoringCase('quill', $item, 'so neither is its editor');

        $nl = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';
        $en = require dirname(__DIR__, 2) . '/src/Service/Language/messages/en.php';

        $this->assertSame('Projectpagina', $nl['portfolio.project_page']);
        $this->assertSame('Geen gekoppelde pagina', $nl['portfolio.no_linked_page']);
        $this->assertSame(
            'Koppel eventueel een gewone pagina aan dit portfolio-item. Op die pagina kun je de normale paginabouwer gebruiken voor tekst, afbeeldingen en andere contentblokken.',
            $nl['help.portfolio.project_page']
        );

        foreach (['portfolio.project_page', 'portfolio.no_linked_page', 'help.portfolio.project_page', 'portfolio.page_option_draft', 'portfolio.new_page', 'portfolio.new_page_note', 'validation.portfolio_page_unknown'] as $key) {
            $this->assertArrayHasKey($key, $nl, $key);
            $this->assertArrayHasKey($key, $en, $key);
        }
    }

    /**
     * "Nieuwe pagina maken" is the Pages screen itself, shown only to an editor
     * who may use it — the Portfolio has no page creator of its own.
     */
    public function testANewPageIsMadeOnThePagesOwnScreen(): void
    {
        $item = self::source(self::ITEM_SCREEN);

        $this->assertStringContainsString('$canManagePages = AdminAuth::can(AdminPermissions::PAGES_MANAGE);', $item);
        $this->assertMatchesRegularExpression(
            '#<\?php if \(\$canManagePages\): \?>\s*<p>\s*<a href="/admin/page-new\.php" class="admin-btn-secondary" target="_blank" rel="noopener">#',
            $item
        );
        $this->assertStringNotContainsString('create-page.php', $item, 'the Portfolio posts no page of its own');
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

                $this->assertMatchesRegularExpression(
                    '/name="' . preg_quote($name, '/') . '"|renderRichTextField\(\'' . preg_quote($name, '/') . '\'/',
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
