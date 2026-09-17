<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\AdminUserRepository;
use App\Repository\BlockTranslationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AdminPermissions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * The three block editors on per-language storage (Multilingual 2.0 phase 3A,
 * docs/multilingual/ARCHITECTURE.md) — Tekstblok, Oproep met knop and
 * Contactkaart — used the way an editor uses them: the screen shows the
 * words of one website language, the one chosen in the CMS shell; the save
 * writes that language and no other; only the default language has required
 * fields; a language the website does not have is refused; a third language
 * is a row in site_languages and nothing else; rich text is sanitized on the
 * way in; and input a refused save hands back starts out unsaved in the save
 * bar, in the language it was typed in.
 *
 * Over real HTTP against PHP's built-in server, like
 * PageLocalizationEditorHttpTest. The page, its three blocks, their words,
 * the accounts and the German registry row are this test's own and are
 * removed in tearDown(). Without a server the test skips itself.
 */
final class BlockLocalizationEditorHttpTest extends TestCase
{
    private const KEY = 'zz-block-localization-editor-test';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;
    private PageSectionRepository $sections;

    private int $pageId = 0;

    /** @var array<string, array{id: int, key: string, section: string}> block type => content row id, section_key, ?section= value */
    private array $blocks = [];

    private bool $addedGerman = false;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->sections = new PageSectionRepository();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        self::assertSame('nl', BlockLocalization::defaultLanguage(), 'this test expects the Dutch-default test database');

        $this->removePage();
        $this->pageId = PageFixture::create(
            ['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED],
            'Blokvertaaltest'
        );

        foreach (['rich_text', 'cta_band', 'contact_card'] as $type) {
            [$id, $key] = SectionRegistry::create($type, self::KEY);
            $this->sections->create($this->pageId, self::KEY, $type, $key, $id);
            $this->blocks[$type] = ['id' => $id, 'key' => (string) $key, 'section' => self::KEY . ':' . $key];
        }

        BlockLocalization::save('rich_text_sections', $this->id('rich_text'), 'nl', ['body' => '<p>Nederlandse tekst</p>']);
        BlockLocalization::save('cta_bands', $this->id('cta_band'), 'nl', ['eyebrow' => 'Nieuw', 'title' => 'Neem contact op', 'lead' => 'Wij helpen graag.', 'primary_label' => 'Contact']);
        BlockLocalization::save('cta_bands', $this->id('cta_band'), 'en', ['title' => 'Get in touch']);
        BlockLocalization::save('contact_cards', $this->id('contact_card'), 'nl', ['title' => 'Liever mailen?', 'button_label' => 'Mail ons']);
    }

    protected function tearDown(): void
    {
        $this->removePage();

        if ($this->addedGerman) {
            Database::connection()->prepare("DELETE FROM block_translations WHERE language_code = 'de'")->execute();
            (new SiteLanguageRepository())->delete('de');
            $this->addedGerman = false;
        }

        $this->accounts->forget();
        BlockLocalization::clearCache();
        PageContent::clearCache();
        SiteLanguages::clearCache();
    }

    // ------------------------------------------------------------ the screens

    public function testEveryEditorShowsTheDefaultLanguageMarkedWithItsStoredWords(): void
    {
        $session = $this->signIn(null);

        foreach ($this->editors() as $type => $expect) {
            $html = $this->editor($session, $type);

            self::assertStringContainsString('name="language_code" value="nl"', $html, $type);
            self::assertStringContainsString('data-localized-language="nl"', $html, $type);
            self::assertStringContainsString('admin-badge admin-badge--info', $html, $type . ': the default language is marked');
            self::assertStringNotContainsString('data-lang-pane', $html, $type . ': no hidden copy of another language');
            self::assertDoesNotMatchRegularExpression('/name="[a-z_]+_(?:nl|en)"/', $html, $type . ': no field is named after a language');
            self::assertStringContainsString($expect['nl'], $html, $type);
        }

        $cta = $this->editor($session, 'cta_band');
        self::assertMatchesRegularExpression('/<input type="text" name="title" maxlength="255" required value="Neem contact op"/', $cta, 'required in the default language');
        self::assertMatchesRegularExpression('/<input type="text" name="title" maxlength="255" required value="Liever mailen\?"/', $this->editor($session, 'contact_card'));
    }

    public function testATranslationShowsItsOwnStoredWordsAndNeverTheFallback(): void
    {
        $session = $this->signIn('en');

        $cta = $this->editor($session, 'cta_band');
        self::assertStringContainsString('name="language_code" value="en"', $cta);
        self::assertMatchesRegularExpression('/<input type="text" name="title" maxlength="255" value="Get in touch" placeholder="[^"]+"/', $cta);
        self::assertMatchesRegularExpression('/<input type="text" name="eyebrow" maxlength="150" value="" placeholder="[^"]+"/', $cta, 'an untranslated field is empty, with the fallback as placeholder');
        self::assertDoesNotMatchRegularExpression('/name="title"[^>]* required/', $cta, 'a translation is never required');
        self::assertStringNotContainsString('value="Nieuw"', $cta);
        self::assertStringNotContainsString('Wij helpen graag.</textarea>', $cta);

        self::assertStringNotContainsString('Nederlandse tekst', $this->editor($session, 'rich_text'));
        self::assertStringNotContainsString('value="Liever mailen?"', $this->editor($session, 'contact_card'));
    }

    public function testThePageBuilderNamesEachBlockByItsWordsInTheDefaultLanguage(): void
    {
        $session = $this->signIn('en');

        $response = self::$server->request('GET', '/admin/page.php?id=' . $this->pageId, $session);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Neem contact op', $response['body']);
        self::assertStringContainsString('Liever mailen?', $response['body']);
        self::assertStringContainsString('Nederlandse tekst', $response['body']);
    }

    // ------------------------------------------------------------ saving

    public function testASaveWritesTheLanguageOnScreenAndLeavesTheOthersAlone(): void
    {
        $session = $this->signIn('en');

        $this->assertSaved($this->save($session, 'rich_text', 'en', ['body' => '<p>English text</p>']));
        $this->assertSaved($this->save($session, 'cta_band', 'en', ['eyebrow' => 'New', 'title' => 'Contact us', 'lead' => '', 'primary_label' => 'Contact']));
        $this->assertSaved($this->save($session, 'contact_card', 'en', ['title' => 'Rather email?', 'body' => '', 'button_label' => '']));

        self::assertSame(['body' => '<p>English text</p>'], $this->stored('rich_text', 'en'));
        self::assertSame(['body' => '<p>Nederlandse tekst</p>'], $this->stored('rich_text', 'nl'), 'the Dutch body is not touched by an English save');
        self::assertSame(['eyebrow' => 'New', 'title' => 'Contact us', 'primary_label' => 'Contact'], $this->stored('cta_band', 'en'));
        self::assertSame(['eyebrow' => 'Nieuw', 'title' => 'Neem contact op', 'lead' => 'Wij helpen graag.', 'primary_label' => 'Contact'], $this->stored('cta_band', 'nl'));
        self::assertSame(['title' => 'Rather email?'], $this->stored('contact_card', 'en'));
        self::assertSame(['title' => 'Liever mailen?', 'button_label' => 'Mail ons'], $this->stored('contact_card', 'nl'));
    }

    public function testEmptyingATranslationRemovesItsRowsSoItFallsBack(): void
    {
        $session = $this->signIn('en');

        $this->assertSaved($this->save($session, 'cta_band', 'en', ['eyebrow' => '', 'title' => '', 'lead' => '', 'primary_label' => '']));

        self::assertSame([], $this->stored('cta_band', 'en'));
        BlockLocalization::clearCache();
        self::assertSame('Neem contact op', BlockLocalization::value('cta_bands', $this->id('cta_band'), 'title', 'en'));
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        $session = $this->signIn('de');

        $html = $this->editor($session, 'cta_band');
        self::assertStringContainsString('name="language_code" value="de"', $html);
        self::assertStringContainsString('data-localized-language="de"', $html);

        $this->assertSaved($this->save($session, 'cta_band', 'de', ['eyebrow' => 'Neu', 'title' => 'Kontakt', 'lead' => '', 'primary_label' => 'Schreiben Sie uns']));
        $this->assertSaved($this->save($session, 'rich_text', 'de', ['body' => '<p>Deutscher Text</p>']));

        self::assertSame(['eyebrow' => 'Neu', 'title' => 'Kontakt', 'primary_label' => 'Schreiben Sie uns'], $this->stored('cta_band', 'de'));
        self::assertSame(['title' => 'Get in touch'], $this->stored('cta_band', 'en'), 'English untouched');
        BlockLocalization::clearCache();
        self::assertSame('Wij helpen graag.', BlockLocalization::value('cta_bands', $this->id('cta_band'), 'lead', 'de'), 'an empty German field falls back to Dutch');
        self::assertSame('<p>Deutscher Text</p>', BlockLocalization::value('rich_text_sections', $this->id('rich_text'), 'body', 'de'));
    }

    public function testALanguageTheWebsiteDoesNotHaveIsRefused(): void
    {
        $session = $this->signIn(null);

        foreach (['fr', 'x1', '', 'NL; DROP'] as $code) {
            $response = $this->save($session, 'contact_card', $code, ['title' => 'Titre', 'body' => '', 'button_label' => '']);
            self::assertStringNotContainsString('saved=1', $response['location'], $code);
        }

        $count = Database::connection()->prepare("SELECT COUNT(*) FROM block_translations WHERE owner_table = 'contact_cards' AND owner_id = ?");
        $count->execute([$this->id('contact_card')]);
        self::assertSame(2, (int) $count->fetchColumn(), 'only the two Dutch fields exist');
    }

    public function testRequiredFieldsAndButtonRulesFollowTheDefaultLanguage(): void
    {
        $nl = $this->signIn(null);
        $en = $this->signIn('en');

        $this->assertRefused($this->save($nl, 'cta_band', 'nl', ['eyebrow' => 'Nieuw', 'title' => '', 'lead' => '', 'primary_label' => 'Contact']), 'no title in the default language');
        $this->assertSaved($this->save($en, 'cta_band', 'en', ['eyebrow' => '', 'title' => '', 'lead' => '', 'primary_label' => '']), 'a translation may be empty');

        $this->assertRefused($this->save($nl, 'cta_band', 'nl', ['eyebrow' => 'Nieuw', 'title' => 'Titel', 'lead' => '', 'primary_label' => 'Contact', 'secondary_label' => 'Werk'], ['secondary_url' => '']), 'a secondary label without a URL');
        $this->assertRefused($this->save($en, 'cta_band', 'en', ['title' => 'Title', 'secondary_label' => 'Work'], ['secondary_url' => '']), 'a translated secondary label without a URL');
        $this->assertRefused($this->save($en, 'cta_band', 'en', ['title' => 'Title'], ['secondary_url' => '/werk']), 'a secondary URL while the default language has no label for it');
        $this->assertSaved($this->save($nl, 'cta_band', 'nl', ['eyebrow' => 'Nieuw', 'title' => 'Titel', 'lead' => '', 'primary_label' => 'Contact', 'secondary_label' => 'Werk'], ['secondary_url' => '/werk']));
        $this->assertSaved($this->save($en, 'cta_band', 'en', ['title' => 'Title'], ['secondary_url' => '/werk']), 'now the default language has the label');

        $this->assertSaved($this->save($en, 'contact_card', 'en', ['title' => 'Card', 'button_label' => 'Mail'], ['button_url' => 'https://example.test']), 'the Dutch card has a button label');
        $this->assertSaved($this->save($nl, 'contact_card', 'nl', ['title' => 'Kaart', 'body' => '', 'button_label' => ''], ['button_url' => '']));
        $this->assertRefused($this->save($en, 'contact_card', 'en', ['title' => 'Card', 'button_label' => 'Mail'], ['button_url' => 'https://example.test']), 'a URL while the default language has no button label');
        $this->assertRefused($this->save($nl, 'contact_card', 'nl', ['title' => '', 'body' => 'Tekst', 'button_label' => ''], ['button_url' => '']), 'no heading in the default language');
    }

    public function testRichTextIsSanitizedOnTheWayIn(): void
    {
        $session = $this->signIn(null);

        $this->assertSaved($this->save($session, 'rich_text', 'nl', [
            'body' => '<h2>Kop</h2><p>Tekst met <a href="https://example.test" onclick="steal()">link</a></p><script>alert(1)</script><img src=x onerror=alert(1)>',
        ]));

        $stored = $this->stored('rich_text', 'nl')['body'] ?? '';
        self::assertStringContainsString('<h2>Kop</h2>', $stored, 'markup stays markup');
        self::assertStringContainsString('https://example.test', $stored);
        foreach (['<script', 'onclick', 'onerror', '<img'] as $hostile) {
            self::assertStringNotContainsString($hostile, $stored);
        }
    }

    public function testShownOrHiddenIsTheSameInEveryLanguage(): void
    {
        $en = $this->signIn('en');

        $this->assertSaved($this->save($en, 'contact_card', 'en', ['title' => 'Card', 'body' => '', 'button_label' => ''], ['is_active' => null]));

        $row = Database::connection()->prepare('SELECT is_active FROM contact_cards WHERE id = ?');
        $row->execute([$this->id('contact_card')]);
        self::assertSame(0, (int) $row->fetchColumn());
        self::assertMatchesRegularExpression('/name="is_active" value="1"\s*>/', $this->editor($this->signIn(null), 'contact_card'), 'the Dutch editor shows it hidden too');
    }

    // ------------------------------------------------------------ unsaved input

    public function testAFreshEditorAndASuccessfulSaveStartOutSaved(): void
    {
        $session = $this->signIn(null);

        foreach (array_keys($this->editors()) as $type) {
            self::assertFalse($this->form($this->xpath($this->editor($session, $type)), $type)->hasAttribute('data-save-bar-unsaved'), $type);
        }

        $response = $this->save($session, 'cta_band', 'nl', ['eyebrow' => 'Nieuw', 'title' => 'Neem contact op', 'lead' => '', 'primary_label' => 'Contact']);
        $this->assertSaved($response);
        $saved = $this->xpath(self::$server->request('GET', $response['location'], $session)['body']);
        self::assertFalse($this->form($saved, 'cta_band')->hasAttribute('data-save-bar-unsaved'));
    }

    public function testARefusedSaveKeepsTheInputOnScreenAndStartsOutUnsavedInItsOwnLanguage(): void
    {
        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();

        foreach ([
            'nl without its required title' => ['nl', ''],
            'en with a title too long' => ['en', str_repeat('x', 256)],
            'de with a title too long' => ['de', str_repeat('y', 256)],
        ] as $what => [$language, $title]) {
            $session = $this->signIn($language);

            $response = $this->save($session, 'cta_band', $language, ['eyebrow' => 'Getypt ' . $language, 'title' => $title, 'lead' => 'Lead ' . $language, 'primary_label' => 'Knop'], ['primary_url' => '/getypt']);
            $this->assertRefused($response, $what);

            $xpath = $this->xpath($this->editor($session, 'cta_band'));
            $form = $this->form($xpath, 'cta_band');
            self::assertTrue($form->hasAttribute('data-save-bar-unsaved'), $what . ': what came back unwritten is unsaved');
            self::assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--error")]')->length, $what . ': with the reason');
            self::assertSame('Getypt ' . $language, $this->control($xpath, $form, 'input[@name="eyebrow"]')->getAttribute('value'), $what);
            self::assertSame($title, $this->control($xpath, $form, 'input[@name="title"]')->getAttribute('value'), $what);
            self::assertSame('Lead ' . $language, $this->control($xpath, $form, 'textarea[@name="lead"]')->textContent, $what);
            self::assertSame('/getypt', $this->control($xpath, $form, 'input[@name="primary_url"]')->getAttribute('value'), $what);

            $again = $this->xpath($this->editor($session, 'cta_band'));
            self::assertFalse($this->form($again, 'cta_band')->hasAttribute('data-save-bar-unsaved'), $what . ': the next visit shows what is stored, saved');
        }

        self::assertSame(['eyebrow' => 'Nieuw', 'title' => 'Neem contact op', 'lead' => 'Wij helpen graag.', 'primary_label' => 'Contact'], $this->stored('cta_band', 'nl'), 'nothing was written');
        self::assertSame(['title' => 'Get in touch'], $this->stored('cta_band', 'en'));
        self::assertSame([], $this->stored('cta_band', 'de'));
    }

    public function testRefusedWordsAreNeverShownInAnotherLanguage(): void
    {
        $session = $this->signIn('en');
        $this->assertRefused($this->save($session, 'rich_text', 'en', ['body' => str_repeat('<p>ZZ-GEWEIGERD</p>', 4000)]));

        (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), 'nl');
        $html = $this->editor($session, 'rich_text');

        self::assertStringContainsString('Nederlandse tekst', $html, 'the Dutch editor shows the stored Dutch body');
        self::assertStringNotContainsString('ZZ-GEWEIGERD', $html, 'not the English input that was refused');
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, array{nl: string}> */
    private function editors(): array
    {
        return [
            'rich_text' => ['nl' => 'Nederlandse tekst'],
            'cta_band' => ['nl' => 'value="Neem contact op"'],
            'contact_card' => ['nl' => 'value="Liever mailen?"'],
        ];
    }

    private function id(string $type): int
    {
        return $this->blocks[$type]['id'];
    }

    private function signIn(?string $editingLanguage): string
    {
        [$session] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        if ($editingLanguage !== null) {
            (new AdminUserRepository())->updateContentEditingLanguage((int) $this->accounts->read($session, 'admin_user_id'), $editingLanguage);
        }

        return $session;
    }

    private function editor(string $session, string $type): string
    {
        $screen = ['rich_text' => 'rich-text', 'cta_band' => 'cta-band', 'contact_card' => 'contact-card'][$type];
        $response = self::$server->request('GET', '/admin/' . $screen . '.php?section=' . urlencode($this->blocks[$type]['section']), $session);
        self::assertSame(200, $response['status'], $type);

        return $response['body'];
    }

    /**
     * @param array<string, string> $words
     * @param array<string, ?string> $settings language-neutral fields; null leaves a checkbox unticked
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function save(string $session, string $type, string $language, array $words, array $settings = []): array
    {
        $endpoint = ['rich_text' => 'update-rich-text-section', 'cta_band' => 'update-cta-band', 'contact_card' => 'update-contact-card'][$type];
        $defaults = [
            'rich_text' => ['is_active' => '1'],
            'cta_band' => ['primary_url' => '/contact', 'secondary_url' => '', 'is_active' => '1'],
            'contact_card' => ['button_url' => '', 'is_active' => '1'],
        ][$type];

        $fields = array_filter(
            ['csrf_token' => (string) $this->accounts->read($session, 'csrf_token'), 'section' => $this->blocks[$type]['section'], 'language_code' => $language]
                + $words + $settings + $defaults,
            static fn ($value): bool => $value !== null
        );

        $response = self::$server->request('POST', '/api/admin/' . $endpoint . '.php', $session, $fields);
        BlockLocalization::clearCache();

        return $response;
    }

    /** @param array{location: string} $response */
    private function assertSaved(array $response, string $what = ''): void
    {
        self::assertStringContainsString('saved=1', $response['location'], $what);
    }

    /** @param array{location: string} $response */
    private function assertRefused(array $response, string $what = ''): void
    {
        self::assertStringNotContainsString('saved=1', $response['location'], $what);
        self::assertStringStartsWith('/admin/', $response['location'], $what . ': back to the editor');
    }

    /** @return array<string, string> the stored words of one block in one language, in declaration order */
    private function stored(string $type, string $language): array
    {
        $table = ['rich_text' => 'rich_text_sections', 'cta_band' => 'cta_bands', 'contact_card' => 'contact_cards'][$type];
        $words = (new BlockTranslationRepository())->findForOwners([$table => [$this->id($type)]])[$table][$this->id($type)][$language] ?? [];

        $ordered = [];
        foreach (array_keys(BlockLocalization::fields($table)) as $field) {
            if (array_key_exists($field, $words)) {
                $ordered[$field] = $words[$field];
            }
        }

        return $ordered;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function form(\DOMXPath $xpath, string $type): \DOMElement
    {
        $endpoint = ['rich_text' => 'update-rich-text-section', 'cta_band' => 'update-cta-band', 'contact_card' => 'update-contact-card'][$type];
        $forms = $xpath->query('//form[@action="/api/admin/' . $endpoint . '.php"]');
        self::assertSame(1, $forms->length, $type);

        return $forms->item(0);
    }

    private function control(\DOMXPath $xpath, \DOMElement $form, string $path): \DOMElement
    {
        $controls = $xpath->query('.//' . $path, $form);
        self::assertSame(1, $controls->length, $path);

        return $controls->item(0);
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);

        if ($page !== null) {
            PageService::delete($page);
        }

        $this->blocks = [];
        PageContent::clearCache();
    }
}
