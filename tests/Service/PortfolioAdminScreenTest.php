<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The Portfolio screens are built from the shared admin controls
 * (ADMIN-UI.md), ask for nothing but an image, show that image before it is
 * saved, and still send exactly what their endpoints read.
 *
 * Read from the source, like Tests\Service\AdminUiPrimitivesTest reads the
 * other screens that use the controls: no database and no web server. What a
 * click does is checked in a browser (ADMIN-UI.md, "Handmatig controleren");
 * that the endpoints accept an item without words is
 * Tests\Service\PortfolioItemEditingHttpTest, and that the editor really
 * renders the stored image is that test too.
 */
final class PortfolioAdminScreenTest extends TestCase
{
    private const ITEM_SCREEN = 'admin/portfolio-item.php';
    private const OVERVIEW = 'admin/portfolio.php';

    /** @var list<string> the words an item has, per language */
    private const WORDS = ['title_nl', 'title_en', 'alt_nl', 'alt_en', 'subtitle_nl', 'subtitle_en'];

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

        foreach (['is_active', 'is_featured', 'has_detail_page'] as $name) {
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

    /** The project page is still there, folded under "Geavanceerd", and unchanged underneath. */
    public function testTheProjectPageIsFoldedAwayButStillComplete(): void
    {
        $edit = self::form('update-portfolio-item.php');

        // The <details> tag carries a PHP echo (open when a project page is on),
        // whose closing angle bracket must not be read as the end of the tag.
        $this->assertMatchesRegularExpression(
            '#<details class="admin-collapse admin-collapse--card"(?:\?>|[^>])*>\s*<summary class="admin-collapse__summary">[\s\S]*?portfolio\.geavanceerd_projectpagina#',
            $edit
        );

        foreach (['has_detail_page', 'slug'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $edit);
        }

        foreach (['intro_nl', 'intro_en', 'description_nl', 'description_en'] as $field) {
            $this->assertStringContainsString("renderRichTextField('" . $field . "'", $edit);
        }

        $item = self::source(self::ITEM_SCREEN);
        foreach (['add-portfolio-item-images.php', 'update-portfolio-item-image.php', 'delete-portfolio-item-image.php', 'reorder-portfolio-item-images.php'] as $endpoint) {
            $this->assertStringContainsString('/api/admin/' . $endpoint, $item, 'the project images keep their endpoint');
        }
    }

    /**
     * What the forms SEND did not change: the same names the endpoints read,
     * and the same hidden item id.
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
