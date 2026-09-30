<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CtaBandRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\CtaBandContent;
use App\Service\LinkResolver;
use App\Service\PageContent;
use App\Service\PagePath;
use App\Service\PageService;
use App\Service\RichTextContent;
use App\Service\Routing\LinkTargets;
use App\Service\SectionRegistry;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\ButtonStyleFixture;
use Tests\Support\PageFixture;
use Tests\Support\SavedRedirect;

/**
 * Button Styles 2.0 over PHP's built-in server (Tests\Support\BuiltInServer),
 * with the site's own routing: the tab Knoppen, the editor and its preview,
 * every endpoint, and a content block choosing a style through its real
 * editor endpoint and showing it on the real page. See THEMING.md,
 * "Knopstijlen".
 *
 *   - managing: create, change, duplicate, make default and delete through
 *     the real endpoints; a default and a style in use are refused with a
 *     message that says why;
 *   - security: CSRF, a signed-in editor without settings.manage, GET, an
 *     unknown id and role, an unsafe colour or icon, a name with markup;
 *   - the editor and its preview: the recipe as data, the sandboxed frame,
 *     the preview's Content-Security-Policy and every sample state;
 *   - blocks: the CTA's two buttons and a text block's button choose a style,
 *     the page prints the style once for both, a central change reaches
 *     both, a forged choice is refused, "Standaard" keeps the old markup, and
 *     a repeater row (Tekst met afbeelding) stores its own choice.
 */
final class ButtonStylesHttpTest extends TestCase
{
    private const KEY = 'zz-knopstijl-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    /** @var array{styles: list<array<string, mixed>>, defaults: list<array<string, mixed>>} */
    private array $snapshot;

    private int $pageId = 0;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PORTFOLIO_ENABLED' => 'true'], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->accounts = new AdminTestSession();
        $this->snapshot = ButtonStyleFixture::snapshot();
        $this->removePage();
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Knopstijl-test');
    }

    protected function tearDown(): void
    {
        $this->removePage();
        ButtonStyleFixture::restore($this->snapshot);
        $this->accounts->forget();
        $this->clearCaches();
    }

    // ------------------------------------------------------------ managing

    public function testTheTabKnoppenListsTheLibrary(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $screen = $this->get($session, '/admin/theme.php?tab=knoppen');

        self::assertStringContainsString('id="knoppen"', $screen);
        self::assertSame(count(ButtonStyles::all()), substr_count($screen, 'data-button-style-row='));
        $primary = ButtonStyles::defaultIds()['primary'];
        self::assertMatchesRegularExpression('/data-button-style-row="' . $primary . '" data-button-style-in-use>.*?data-button-status="primary"/s', $screen);
        self::assertStringContainsString('href="/admin/button-style.php"', $screen);
        // The old Knopvorm is not a second setting next to it.
        self::assertStringNotContainsString('name="button_shape"', $screen);
        self::assertStringContainsString('data-buttons-moved', $screen);
    }

    public function testCreateChangeDuplicateMakeDefaultAndDelete(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);

        $created = $this->post($session, '/api/admin/save-button-style.php', ['csrf_token' => $csrf] + ButtonStyleFixture::form('ZZ Omlijnd', ['appearance' => 'outline', 'border_width' => 'thin', 'text_color' => 'primary']));
        $id = ButtonStyleFixture::idNamed('ZZ Omlijnd');
        self::assertSame('/admin/button-style.php?id=' . $id . '&done=created', $created['location']);

        $saved = $this->post($session, '/api/admin/save-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $id] + ButtonStyleFixture::form('ZZ Omlijnd rood', [
            'appearance' => 'outline', 'border_width' => 'thick', 'text_color' => 'custom', 'shape' => 'round', 'icon' => 'arrow_right',
        ]) + ['text_color_custom' => '#b91c1c']);
        self::assertSame('/admin/button-style.php?id=' . $id . '&done=saved', $saved['location']);
        $this->clearCaches();
        $style = ButtonStyles::find($id);
        self::assertSame(['ZZ Omlijnd rood', 'thick', '#B91C1C', 'round', 'arrow_right'], [$style['name'], $style['border_width'], $style['text_color'], $style['shape'], $style['icon']]);

        $copy = $this->post($session, '/api/admin/duplicate-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $id]);
        $copyId = ButtonStyleFixture::idNamed('ZZ Omlijnd rood (kopie)');
        self::assertSame('/admin/button-style.php?id=' . $copyId . '&done=duplicated', $copy['location']);

        $default = $this->post($session, '/api/admin/set-default-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $copyId, 'role' => 'secondary']);
        self::assertSame('/admin/theme.php?tab=knoppen&buttons=default#knoppen', $default['location']);
        $this->clearCaches();
        self::assertSame($copyId, ButtonStyles::defaultIds()['secondary']);

        $refused = $this->post($session, '/api/admin/delete-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $copyId]);
        self::assertSame('/admin/theme.php?tab=knoppen#knoppen', $refused['location']);
        self::assertStringContainsString('data-buttons-error', $this->get($session, '/admin/theme.php?tab=knoppen'));
        self::assertNotNull(ButtonStyles::find($copyId));

        $deleted = $this->post($session, '/api/admin/delete-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $id]);
        self::assertSame('/admin/theme.php?tab=knoppen&buttons=deleted#knoppen', $deleted['location']);
        $this->clearCaches();
        self::assertNull(ButtonStyles::find($id));
    }

    public function testAStyleInUseIsRefusedWithTheNumberOfButtons(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Bezet'));
        [$section, $bandId] = $this->placeCta();
        (new \App\Repository\ButtonStyleRepository())->saveChoice('cta_bands', 'primary_button_style_id', $bandId, $id);
        (new \App\Repository\ButtonStyleRepository())->saveChoice('cta_bands', 'secondary_button_style_id', $bandId, $id);
        $this->clearCaches();

        $screen = $this->get($session, '/admin/theme.php?tab=knoppen');
        self::assertMatchesRegularExpression('/data-button-style-row="' . $id . '" data-button-style-in-use>.*?data-button-style-uses="2"/s', $screen);

        $this->post($session, '/api/admin/delete-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $id]);
        $screen = $this->get($session, '/admin/theme.php?tab=knoppen');
        self::assertMatchesRegularExpression('/data-buttons-error>[^<]*2 knop/', $screen);
        self::assertNotNull(ButtonStyles::find($id));
    }

    // ------------------------------------------------------------ security

    public function testEveryEndpointRefusesWithoutCsrfPermissionPostOrAKnownId(): void
    {
        [$editor, $editorCsrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $target = ButtonStyles::create(ButtonStyleFixture::values('ZZ Doelwit'));
        $before = ButtonStyleFixture::snapshot();

        foreach (['save-button-style.php', 'duplicate-button-style.php', 'delete-button-style.php', 'set-default-button-style.php'] as $endpoint) {
            $path = '/api/admin/' . $endpoint;
            $fields = ['id' => (string) $target, 'role' => 'primary'] + ButtonStyleFixture::form('ZZ Overgenomen');

            self::assertSame(403, $this->post($session, $path, $fields + ['csrf_token' => 'fout'])['status'], $endpoint . ' without a valid token');
            self::assertSame(403, $this->post($editor, $path, $fields + ['csrf_token' => $editorCsrf])['status'], $endpoint . ' without settings.manage');
            self::assertSame(405, self::$server->request('GET', $path . '?id=' . $target, $session)['status'], $endpoint . ' over GET');
            self::assertSame(404, $this->post($session, $path, ['id' => '999999', 'csrf_token' => $csrf] + $fields)['status'], $endpoint . ' with an unknown id');
            self::assertSame(404, $this->post($session, $path, ['id' => 'abc', 'csrf_token' => $csrf] + $fields)['status'], $endpoint . ' with a malformed id');
        }

        self::assertSame(400, $this->post($session, '/api/admin/set-default-button-style.php', ['csrf_token' => $csrf, 'id' => (string) $target, 'role' => 'tertiary'])['status']);
        $this->clearCaches();
        self::assertSame($before, ButtonStyleFixture::snapshot(), 'nothing changed');

        foreach (['/admin/button-style.php', '/admin/button-style.php?id=' . $target, '/admin/button-style-preview.php', '/admin/theme.php'] as $screen) {
            self::assertNotSame(200, self::$server->request('GET', $screen, $editor)['status'], $screen . ' without settings.manage');
        }
    }

    public function testUnsafeValuesAreRefusedAndAMarkedUpNameIsOnlyEverText(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $count = count(ButtonStyles::all());

        $attacks = [
            ['fill_color' => 'red;}body{display:none'],
            ['text_color' => 'custom', 'text_color_custom' => 'url(https://x.test/a.png)'],
            ['hover_fill_color' => 'var(--color-bg)'],
            ['icon' => '<svg onload=alert(1)>'],
            ['shape' => '50%;}*{x:y'],
            ['appearance' => 'filled" onmouseover="x'],
        ];
        foreach ($attacks as $attack) {
            $response = $this->post($session, '/api/admin/save-button-style.php', ['csrf_token' => $csrf] + $attack + ButtonStyleFixture::form('ZZ Kwaad'));
            self::assertSame('/admin/button-style.php', $response['location'], json_encode($attack));
            $this->clearCaches();
            self::assertCount($count, ButtonStyles::all(), 'nothing stored: ' . json_encode($attack));
        }

        $editor = $this->get($session, '/admin/button-style.php');
        self::assertStringContainsString('data-save-bar-unsaved', $editor, 'the refused form comes back');

        $name = '</style><script>alert(1)</script>';
        $this->post($session, '/api/admin/save-button-style.php', ['csrf_token' => $csrf] + ButtonStyleFixture::form($name));
        $id = ButtonStyleFixture::idNamed($name);
        foreach (['/admin/theme.php?tab=knoppen', '/admin/button-style.php?id=' . $id] as $screen) {
            $body = $this->get($session, $screen);
            self::assertStringNotContainsString($name, $body, $screen);
            self::assertStringContainsString(htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $body, $screen);
        }

        self::assertSame(0, (int) Database::connection()->query(
            "SELECT COUNT(*) FROM button_styles WHERE fill_color REGEXP '[;{}<>()]' OR text_color REGEXP '[;{}<>()]' OR icon REGEXP '[^a-z_]'"
        )->fetchColumn());
    }

    // ------------------------------------------------- the editor and preview

    public function testTheEditorCarriesTheRecipeAndTheFramedPreview(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $id = ButtonStyles::defaultIds()['primary'];
        $editor = $this->get($session, '/admin/button-style.php?id=' . $id);

        self::assertMatchesRegularExpression('/data-button-recipe="\{&quot;colors&quot;:\{&quot;primary&quot;:&quot;var\(--color-primary\)/', $editor);
        self::assertStringContainsString('src="/admin/button-style-preview.php?id=' . $id . '"', $editor);
        self::assertMatchesRegularExpression('/<iframe[^>]*data-button-preview[^>]*sandbox="allow-same-origin"/', $editor);
        self::assertStringContainsString('data-button-state="default"', $editor, 'saving a default says it changes every button');
        self::assertStringContainsString('name="fill_color_custom"', $editor);
        self::assertSame(404, self::$server->request('GET', '/admin/button-style.php?id=999999', $session)['status']);
        self::assertSame(404, self::$server->request('GET', '/admin/button-style.php?id=abc', $session)['status']);

        $preview = self::$server->request('GET', '/admin/button-style-preview.php?id=' . $id, $session);
        self::assertSame(200, $preview['status']);
        self::assertMatchesRegularExpression("/Content-Security-Policy: script-src 'none'; form-action 'none'/i", $preview['headers']);
        self::assertStringNotContainsString('<script', $preview['body']);
        self::assertSame(9, substr_count($preview['body'], ' data-button-sample'));
        self::assertSame(3, substr_count($preview['body'], 'is-preview-hover'));
        self::assertSame(1, substr_count($preview['body'], 'is-preview-focus'));
        self::assertMatchesRegularExpression('/<button type="button" class="btn" data-button-sample disabled>/', $preview['body']);
        self::assertStringContainsString('--btn-hover-shadow: var(--color-glow);', $preview['body']);
        self::assertStringContainsString('assets/css/button-style-preview.css', $preview['body']);
    }

    // ------------------------------------------------------------ blocks

    public function testACtaAndATextBlockChooseAStyleAndACentralChangeReachesBoth(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $outline = ButtonStyles::create(ButtonStyleFixture::values('ZZ Blokknop', ['appearance' => 'outline', 'border_width' => 'thin', 'text_color' => 'primary', 'icon' => 'external']));
        [$cta, $bandId] = $this->placeCta();
        [$text, $textId] = $this->placeRichText();

        $editor = $this->get($session, '/admin/cta-band.php?section=' . urlencode($cta));
        self::assertMatchesRegularExpression('/<select id="cta-primary-style" name="primary_button_style_id"[^>]*>.*?<option value="' . $outline . '">ZZ Blokknop<\/option>/s', $editor, 'the options come from the library');

        $this->assertSaved($this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta) + [
            'primary_button_style_id' => (string) $outline,
            'secondary_button_style_id' => '',
        ]));
        $this->assertSaved($this->post($session, '/api/admin/update-rich-text-section.php', ['csrf_token' => $csrf] + $this->richTextForm($text) + [
            'button_style_id' => (string) $outline,
        ]));

        $band = (new CtaBandRepository())->findById($bandId);
        self::assertSame($outline, (int) $band['primary_button_style_id']);
        self::assertNull($band['secondary_button_style_id']);
        self::assertSame($outline, (int) (new RichTextRepository())->findBySlugAndKey(self::KEY, explode(':', $text, 2)[1])['button_style_id']);

        // Opened again, the editor shows the choice.
        self::assertMatchesRegularExpression('/<option value="' . $outline . '" selected>/', $this->get($session, '/admin/cta-band.php?section=' . urlencode($cta)));

        $page = $this->page();
        $class = ButtonStyleCss::className($outline);
        self::assertSame(2, substr_count($page, 'class="btn ' . $class . '"'), 'the CTA\'s first button and the text block\'s');
        self::assertStringContainsString('class="btn btn--ghost"', $page, 'the second button stays on its default');
        self::assertSame(1, substr_count($this->siteButtons($page), '.btn.' . $class . '{'), 'one rule for both');
        self::assertStringContainsString('--btn-border-width: 1px;', $this->siteButtons($page));
        self::assertStringContainsString('--btn-fg: var(--color-primary);', $this->siteButtons($page), 'a theme colour follows the theme');
        self::assertDoesNotMatchRegularExpression('/class="btn ' . $class . '">[^<]*<svg/', $page, 'the style draws the icon, not the old arrow');

        // One central change reaches both buttons.
        ButtonStyles::update($outline, ButtonStyleFixture::values('ZZ Blokknop', ['appearance' => 'outline', 'border_width' => 'thick', 'text_color' => '#B91C1C']));
        $page = $this->page();
        self::assertStringContainsString('--btn-border-width: 2.5px;', $this->siteButtons($page));
        self::assertStringContainsString('--btn-fg: #B91C1C;', $this->siteButtons($page), 'a fixed colour stays itself');
        self::assertSame(2, substr_count($page, 'class="btn ' . $class . '"'));

        // Back to "Standaard": the old markup, arrow and all.
        $this->assertSaved($this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta) + ['primary_button_style_id' => '']));
        self::assertNull((new CtaBandRepository())->findById($bandId)['primary_button_style_id']);
        self::assertMatchesRegularExpression('/class="btn">Contact\s*<svg class="btn__arrow"/', $this->page());
    }

    public function testAForgedChoiceIsRefusedAndTheStoredOneKept(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $style = ButtonStyles::create(ButtonStyleFixture::values('ZZ Echt'));
        [$cta, $bandId] = $this->placeCta();
        $this->assertSaved($this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta) + ['primary_button_style_id' => (string) $style]));

        foreach (['999999', 'abc', '1 OR 1=1'] as $forged) {
            $response = $this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta) + ['primary_button_style_id' => $forged]);
            self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location'], $forged);
            self::assertSame($style, (int) (new CtaBandRepository())->findById($bandId)['primary_button_style_id'], $forged);
        }

        // A form without the field keeps what is stored.
        $this->assertSaved($this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta)));
        self::assertSame($style, (int) (new CtaBandRepository())->findById($bandId)['primary_button_style_id']);
    }

    public function testARepeaterRowStoresItsOwnChoice(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $style = ButtonStyles::create(ButtonStyleFixture::values('ZZ Rij', ['appearance' => 'text', 'icon' => 'arrow_right']));
        [$id, $key] = SectionRegistry::create('text_image_split', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'text_image_split', $key, $id);
        $section = self::KEY . ':' . $key;
        $row = ['title' => 'Rij', 'body' => '<p>Tekst.</p>', 'button_label' => 'Lees meer', 'button_link_type' => 'url', 'button_url' => '/contact'];

        $post = static fn (array $rows): array => ['csrf_token' => $csrf, 'section' => $section, 'language_code' => 'nl', 'is_active' => '1', 'items_present' => '1', 'items' => $rows];
        $this->assertSaved($this->post($session, '/api/admin/update-text-image-split-section.php', $post([
            'new0' => $row + ['button_style_id' => (string) $style],
            'new1' => ['title' => 'Tweede'] + $row + ['button_style_id' => ''],
        ])));

        $items = Database::connection()->prepare('SELECT id, button_style_id FROM text_image_split_items WHERE text_image_split_id = ? ORDER BY sort_order, id');
        $items->execute([(int) $id]);
        $stored = $items->fetchAll();
        self::assertCount(2, $stored);
        self::assertSame($style, (int) $stored[0]['button_style_id']);
        self::assertNull($stored[1]['button_style_id']);

        $page = $this->page();
        self::assertStringContainsString('class="btn ' . ButtonStyleCss::className($style) . ' text-image__button"', $page);
        self::assertMatchesRegularExpression('/class="btn text-image__button">Lees meer\s*<svg class="btn__arrow"/', $page, 'the other row keeps the old button');

        // A forged choice on an existing row is refused at that row.
        $response = $this->post($session, '/api/admin/update-text-image-split-section.php', $post([
            (string) $stored[0]['id'] => $row + ['button_style_id' => '999999'],
            (string) $stored[1]['id'] => ['title' => 'Tweede'] + $row,
        ]));
        self::assertDoesNotMatchRegularExpression(SavedRedirect::PATTERN, $response['location']);
        $items->execute([(int) $id]);
        self::assertSame($style, (int) $items->fetchAll()[0]['button_style_id']);
    }

    public function testAnEditorWithoutPagesManageCannotChooseForABlock(): void
    {
        [$session, $csrf] = $this->accounts->signIn([AdminPermissions::SETTINGS_MANAGE]);
        $style = ButtonStyles::create(ButtonStyleFixture::values('ZZ Geen recht'));
        [$cta, $bandId] = $this->placeCta();

        $response = $this->post($session, '/api/admin/update-cta-band.php', ['csrf_token' => $csrf] + $this->ctaForm($cta) + ['primary_button_style_id' => (string) $style]);
        self::assertSame(403, $response['status']);
        self::assertNull((new CtaBandRepository())->findById($bandId)['primary_button_style_id']);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{string, int} */
    private function placeCta(): array
    {
        [$id, $key] = SectionRegistry::create('cta_band', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'cta_band', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array{string, int} */
    private function placeRichText(): array
    {
        [$id, $key] = SectionRegistry::create('rich_text', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'rich_text', $key, $id);
        $this->clearCaches();

        return [self::KEY . ':' . $key, (int) $id];
    }

    /** @return array<string, string> */
    private function ctaForm(string $section): array
    {
        return [
            'section' => $section, 'language_code' => 'nl', 'is_active' => '1',
            'eyebrow' => '', 'title' => 'Samenwerken?', 'lead' => '',
            'primary_label' => 'Contact', 'primary_link_type' => 'url', 'primary_url' => '/contact',
            'secondary_label' => 'Meer', 'secondary_link_type' => 'url', 'secondary_url' => '/over',
            'content_align' => 'center', 'lead_width' => 'narrow', 'background_overlay' => 'medium', 'text_panel_opacity' => 'strong',
            'background_media_id' => '',
        ];
    }

    /** @return array<string, string> */
    private function richTextForm(string $section): array
    {
        return [
            'section' => $section, 'language_code' => 'nl', 'is_active' => '1',
            RichTextContent::BODY => '<p>Een tekst.</p>', RichTextContent::BUTTON_LABEL => 'Lees verder',
            'button_link_type' => 'url', 'button_url' => '/over', 'text_align' => 'left', 'content_width' => 'medium',
        ];
    }

    private function page(): string
    {
        $this->clearCaches();
        $response = self::$server->request('GET', '/' . self::KEY);
        self::assertSame(200, $response['status']);

        return $response['body'];
    }

    private function siteButtons(string $body): string
    {
        return preg_match('#<style id="site-buttons">.*?</style>#s', $body, $m) === 1 ? $m[0] : '';
    }

    private function get(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        self::assertSame(200, $response['status'], $path);

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function post(string $session, string $path, array $fields): array
    {
        $response = self::$server->request('POST', $path, $session, $fields);
        $this->clearCaches();

        return $response;
    }

    /** @param array{location: string, body: string} $response */
    private function assertSaved(array $response): void
    {
        self::assertMatchesRegularExpression(SavedRedirect::PATTERN, $response['location'], $response['body']);
    }

    private function clearCaches(): void
    {
        ButtonStyles::clearCache();
        ThemeSettings::clearCache();
        BlockLocalization::clearCache();
        CtaBandContent::clearCache();
        RichTextContent::clearCache();
        PageContent::clearCache();
        PagePath::clearCache();
        LinkResolver::clearCache();
        LinkTargets::reset();
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageService::delete($page);
        }

        PageContent::clearCache();
    }
}
