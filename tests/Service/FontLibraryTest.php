<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Service\Language\AdminTranslator;
use App\Service\PageAssets;
use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontStorage;
use App\Service\Theme\PageThemeCss;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ColorPaletteFixture;
use Tests\Support\FontFileFixture;
use Tests\Support\FontLibraryFixture;
use Tests\Support\PageThemeFixture;

/**
 * App\Service\Theme\FontLibrary against the database, with files in a
 * temporary folder (FontLibrary::useStorageForTests()):
 *
 *   - a family with several variants, all or nothing; renaming; replacing a
 *     variant (new name, old file gone); a variant twice in one save, or
 *     one the family has, refused; the library's total size;
 *   - deleting a family deletes exactly its files; a family in use by the
 *     website or a page theme is refused with who uses it, and the foreign
 *     keys refuse too; the last variant of a family in use stays;
 *   - ThemeSettings: the website's roles saved in theme_font_roles, the
 *     tokens and the @font-face block on a page, only for what is used;
 *     "Standaardvormgeving herstellen" clears the roles; activating another
 *     colour palette never changes a font;
 *   - a page theme with its own family; a missing file keeps the page
 *     readable (the stack ends in a font every device has).
 */
final class FontLibraryTest extends TestCase
{
    private string $directory = '';

    /** @var list<array<string, mixed>> */
    private array $roles = [];

    /** @var list<array<string, mixed>> */
    private array $palettes = [];

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mygdala-fonts-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        FontLibrary::useStorageForTests(new FontStorage($this->directory));

        ModuleRegistry::overrideForTests(['page_themes' => true]);
        $this->roles = FontLibraryFixture::rolesSnapshot();
        $this->palettes = ColorPaletteFixture::snapshot();
        FontLibraryFixture::removeAll($this->roles);
        PageThemeFixture::removeAll();
    }

    protected function tearDown(): void
    {
        PageThemeFixture::removeAll();
        FontLibraryFixture::removeAll($this->roles, new FontStorage($this->directory));
        ColorPaletteFixture::restore($this->palettes);
        FontLibrary::useStorageForTests(null);
        ModuleRegistry::overrideForTests(null);
        ThemeSettings::clearCache();
        PageThemeCss::reset();

        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    // ---------------------------------------------------------- families

    public function testAFamilyWithSeveralVariantsIsStoredWithItsFiles(): void
    {
        $id = $this->createFamily('Roboto', [
            ['Roboto-Regular.ttf', FontFileFixture::ttf(), '400'],
            ['Roboto-Bold.woff2', FontFileFixture::woff2(), '700'],
            ['Roboto-Italic.woff', FontFileFixture::woff(), '400-italic'],
        ]);

        $family = FontLibrary::family($id);
        self::assertSame(FontLibraryFixture::PREFIX . 'Roboto', $family['name']);
        self::assertCount(3, $family['variants']);
        self::assertSame(
            [['400', 'normal', 'ttf'], ['700', 'normal', 'woff2'], ['400', 'italic', 'woff']],
            array_map(static fn (array $v): array => [(string) $v['weight'], (string) $v['style'], (string) $v['format']], $family['variants'])
        );

        foreach ($family['variants'] as $variant) {
            self::assertMatchesRegularExpression(FontStorage::NAME_PATTERN, $variant['file_name']);
            self::assertFileExists($this->directory . '/' . $variant['file_name']);
            self::assertStringNotContainsString('Roboto', $variant['file_name'], 'the original name never becomes the stored name');
        }
        self::assertSame(0, $family['missing_files']);
        self::assertTrue(FontLibrary::isUsable($id));
    }

    public function testOneRefusedFileStoresNothingAtAll(): void
    {
        $result = FontLibrary::createFamily(
            ['name' => FontLibraryFixture::PREFIX . 'Half', 'category' => 'sans', 'source_url' => null],
            [
                $this->upload('Half-Regular.ttf', FontFileFixture::ttf(), '400'),
                $this->upload('Half-Bold.woff2', FontFileFixture::png(), '700'),
            ],
            false
        );

        self::assertNull($result['id']);
        self::assertSame([AdminTranslator::trans('fonts.error_not_a_font', ['file' => 'Half-Bold.woff2'])], $result['errors']);
        self::assertSame([], glob($this->directory . '/*'));
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM font_families WHERE name LIKE 'ZZ Font Half%'")->fetchColumn());
    }

    public function testRenamingKeepsTheCssNameAndTheNameIsUnique(): void
    {
        $id = $this->createFamily('Oud', [['a.ttf', FontFileFixture::ttf(), '400']]);
        $other = $this->createFamily('Ander', [['b.ttf', FontFileFixture::ttf(), '400']]);

        $renamed = FontLibrary::validateFamily(['name' => FontLibraryFixture::PREFIX . 'Nieuw', 'category' => 'serif', 'source_url' => 'https://fonts.google.com/specimen/Lora'], $id);
        self::assertSame([], $renamed['errors']);
        FontLibrary::updateFamily($id, $renamed['values']);

        self::assertSame(FontLibraryFixture::PREFIX . 'Nieuw', FontLibrary::family($id)['name']);
        self::assertSame('serif', FontLibrary::family($id)['category']);
        self::assertSame("'mygdala-font-" . $id . "', " . FontLibrary::CATEGORIES['serif'], FontLibrary::stack($id));

        $taken = FontLibrary::validateFamily(['name' => strtolower(FontLibraryFixture::PREFIX . 'ander')], $id);
        self::assertSame(AdminTranslator::trans('fonts.error_name_taken'), $taken['errors']['name']);
        self::assertSame([], FontLibrary::validateFamily(['name' => FontLibraryFixture::PREFIX . 'Ander'], $other)['errors'], 'its own name is not taken');
    }

    public function testAFamilysFieldsAreValidated(): void
    {
        $checked = FontLibrary::validateFamily(['name' => '', 'category' => 'script', 'source_url' => 'javascript:alert(1)']);
        self::assertSame(['name', 'category', 'source_url'], array_keys($checked['errors']));

        self::assertArrayHasKey('name', FontLibrary::validateFamily(['name' => str_repeat('x', 81)])['errors']);
        self::assertArrayHasKey('source_url', FontLibrary::validateFamily(['name' => 'x', 'source_url' => 'ftp://example.com/font'])['errors']);
        self::assertSame('Naam met tab', FontLibrary::validateFamily(['name' => "Naam met\ttab"])['values']['name'], 'control characters never reach a name');
    }

    public function testVariantsAreAddedAndAnExistingOrDoubleVariantIsRefused(): void
    {
        $id = $this->createFamily('Varianten', [['v.ttf', FontFileFixture::ttf(), '400']]);

        $exists = FontLibrary::addVariants($id, [$this->upload('v2.ttf', FontFileFixture::ttf(), '400')], false);
        self::assertSame([AdminTranslator::trans('fonts.error_variant_exists', ['file' => 'v2.ttf', 'variant' => 'Normaal'])], $exists);

        $twice = FontLibrary::addVariants($id, [
            $this->upload('b1.ttf', FontFileFixture::ttf(), '700'),
            $this->upload('b2.ttf', FontFileFixture::ttf(), '700'),
        ], false);
        self::assertSame([AdminTranslator::trans('fonts.error_variant_twice', ['file' => 'b2.ttf', 'other' => 'b1.ttf', 'variant' => 'Vet'])], $twice);

        $unknown = FontLibrary::addVariants($id, [$this->upload('x.ttf', FontFileFixture::ttf(), '450')], false);
        self::assertSame([AdminTranslator::trans('fonts.error_variant_missing', ['file' => 'x.ttf'])], $unknown);
        self::assertCount(1, FontLibrary::family($id)['variants'], 'nothing was added by a refusal');

        self::assertSame([], FontLibrary::addVariants($id, [
            $this->upload('b.woff2', FontFileFixture::woff2(), '700'),
            $this->upload('bi.woff2', FontFileFixture::woff2(), '700-italic'),
        ], false));
        self::assertCount(3, FontLibrary::family($id)['variants']);
        self::assertCount(3, glob($this->directory . '/*'));
    }

    public function testReplacingAVariantGivesItANewFileAndDeletesTheOld(): void
    {
        $id = $this->createFamily('Vervang', [['r.ttf', FontFileFixture::ttf(), '400']]);
        $before = FontLibrary::family($id)['variants'][0];

        self::assertSame([], FontLibrary::replaceVariant((int) $before['id'], $this->upload('r-nieuw.woff2', FontFileFixture::woff2(), '')['file'], false));

        $after = FontLibrary::family($id)['variants'][0];
        self::assertSame($before['id'], $after['id']);
        self::assertSame(['400', 'normal'], [(string) $after['weight'], (string) $after['style']]);
        self::assertSame('woff2', $after['format']);
        self::assertSame('r-nieuw.woff2', $after['original_filename']);
        self::assertNotSame($before['file_name'], $after['file_name'], 'a new name, so no cache keeps the old file');
        self::assertFileDoesNotExist($this->directory . '/' . $before['file_name']);
        self::assertFileExists($this->directory . '/' . $after['file_name']);

        $refused = FontLibrary::replaceVariant((int) $after['id'], $this->upload('kapot.woff2', FontFileFixture::png(), '')['file'], false);
        self::assertNotSame([], $refused);
        self::assertFileExists($this->directory . '/' . $after['file_name'], 'a refused replacement keeps the old file');
    }

    public function testTheLibraryHasATotalSize(): void
    {
        $id = $this->createFamily('Vol', [['v.ttf', FontFileFixture::ttf(), '400']]);
        Database::connection()->prepare('UPDATE font_files SET byte_size = ? WHERE font_family_id = ?')
            ->execute([FontLibrary::MAX_LIBRARY_BYTES, $id]);

        $refused = FontLibrary::addVariants($id, [$this->upload('b.ttf', FontFileFixture::ttf(), '700')], false);

        self::assertSame([AdminTranslator::trans('fonts.error_library_full', ['max' => 50])], $refused);
    }

    // ---------------------------------------------------------- deleting

    public function testDeletingAFamilyDeletesExactlyItsFiles(): void
    {
        $gone = $this->createFamily('Weg', [['w.ttf', FontFileFixture::ttf(), '400'], ['wb.ttf', FontFileFixture::ttf(), '700']]);
        $kept = $this->createFamily('Blijft', [['k.ttf', FontFileFixture::ttf(), '400']]);
        $goneFiles = array_column(FontLibrary::family($gone)['variants'], 'file_name');
        $keptFiles = array_column(FontLibrary::family($kept)['variants'], 'file_name');

        self::assertSame(['deleted' => true, 'reason' => null], FontLibrary::deleteFamily($gone));

        self::assertNull(FontLibrary::family($gone));
        foreach ($goneFiles as $file) {
            self::assertFileDoesNotExist($this->directory . '/' . $file);
        }
        foreach ($keptFiles as $file) {
            self::assertFileExists($this->directory . '/' . $file);
        }
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM font_files WHERE font_family_id = ' . $gone)->fetchColumn());
    }

    public function testAFamilyInUseIsNotDeletedAndSaysWhoUsesIt(): void
    {
        $id = $this->createFamily('Gebruikt', [['g.ttf', FontFileFixture::ttf(), '400']]);
        ThemeSettings::save(['heading_font_family_id' => (string) $id, 'body_font_family_id' => (string) $id]);
        $actie = PageThemeFixture::create('Actie', ['heading_font_family_id' => (string) $id]);
        $zomer = PageThemeFixture::create('Zomer', ['body_font_family_id' => (string) $id]);

        $usage = FontLibrary::usage($id);
        self::assertSame(['heading', 'body'], $usage['site']);
        self::assertSame([
            ['label' => 'paginathema "ZZ Test Actie" (koppen)', 'url' => '/admin/page-theme.php?id=' . $actie],
            ['label' => 'paginathema "ZZ Test Zomer" (lopende tekst)', 'url' => '/admin/page-theme.php?id=' . $zomer],
        ], $usage['others']);

        $result = FontLibrary::deleteFamily($id);
        self::assertFalse($result['deleted']);
        self::assertSame(
            'Dit lettertype wordt gebruikt door het website-thema (koppen, lopende tekst); paginathema "ZZ Test Actie" (koppen); paginathema "ZZ Test Zomer" (lopende tekst). Kies daar eerst een ander lettertype.',
            $result['reason']
        );
        self::assertNotNull(FontLibrary::family($id));
        self::assertCount(1, glob($this->directory . '/*'));
    }

    public function testThePageThemesModuleOffStillProtectsItsChoice(): void
    {
        $id = $this->createFamily('ModuleUit', [['m.ttf', FontFileFixture::ttf(), '400']]);
        PageThemeFixture::create('Uit', ['heading_font_family_id' => (string) $id]);
        ModuleRegistry::overrideForTests(['page_themes' => false]);

        self::assertFalse(FontLibrary::deleteFamily($id)['deleted']);
    }

    public function testTheDatabaseRefusesToo(): void
    {
        $id = $this->createFamily('Sleutel', [['s.ttf', FontFileFixture::ttf(), '400']]);
        ThemeSettings::save(['body_font_family_id' => (string) $id]);

        try {
            Database::connection()->exec('DELETE FROM font_families WHERE id = ' . $id);
            self::fail('the website role must hold the family (RESTRICT)');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        ThemeSettings::save(['body_font_family_id' => '']);
        $theme = PageThemeFixture::create('Sleutel', ['body_font_family_id' => (string) $id]);
        try {
            Database::connection()->exec('DELETE FROM font_families WHERE id = ' . $id);
            self::fail('a page theme must hold the family (RESTRICT)');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        PageThemeFixture::removeAll();
        self::assertTrue(FontLibrary::deleteFamily($id)['deleted'], 'out of use, it can go');
        self::assertGreaterThan(0, $theme);
    }

    public function testTheLastVariantOfAFamilyInUseStays(): void
    {
        $id = $this->createFamily('Laatste', [['l.ttf', FontFileFixture::ttf(), '400'], ['lb.ttf', FontFileFixture::ttf(), '700']]);
        ThemeSettings::save(['body_font_family_id' => (string) $id]);
        [$regular, $bold] = FontLibrary::family($id)['variants'];

        self::assertNull(FontLibrary::removeVariant((int) $bold['id']));
        self::assertFileDoesNotExist($this->directory . '/' . $bold['file_name']);

        $refused = FontLibrary::removeVariant((int) $regular['id']);
        self::assertStringStartsWith(AdminTranslator::trans('fonts.last_variant_refused'), (string) $refused);
        self::assertFileExists($this->directory . '/' . $regular['file_name']);

        ThemeSettings::save(['body_font_family_id' => '']);
        self::assertNull(FontLibrary::removeVariant((int) $regular['id']), 'out of use, the last one can go');
        self::assertFalse(FontLibrary::isUsable($id), 'a family without a file cannot be chosen');
    }

    // ---------------------------------------------------------- the website

    public function testTheWebsitesRolesAreStoredAndPrinted(): void
    {
        $body = $this->createFamily('Tekst', [['t.woff2', FontFileFixture::woff2(), '400'], ['tb.woff2', FontFileFixture::woff2(), '700'], ['ti.woff2', FontFileFixture::woff2(), '400-italic']]);
        $heading = $this->createFamily('Kop', [['k.woff2', FontFileFixture::woff2(), '700']], 'serif');
        for ($i = 1; $i <= 10; $i++) {
            $this->createFamily('Ongebruikt ' . $i, [['o' . $i . '.woff2', FontFileFixture::woff2(), '400']]);
        }

        ThemeSettings::save(['body_font_family_id' => (string) $body]);
        self::assertSame([['role' => 'body', 'font_family_id' => $body]], array_map(
            static fn (array $row): array => ['role' => $row['role'], 'font_family_id' => (int) $row['font_family_id']],
            FontLibraryFixture::rolesSnapshot()
        ));
        self::assertSame((string) $body, ThemeSettings::get('body_font_family_id'));

        $head = $this->head();
        self::assertStringContainsString('--font-body: ' . FontLibrary::stack($body) . ';', $head);
        self::assertSame(3, substr_count($head, '@font-face'), 'the three variants of the one family in use, and nothing of the ten unused ones');
        self::assertStringContainsString('font-family:"mygdala-font-' . $body . '"', $head);
        self::assertStringContainsString('font-weight:700;font-style:normal', $head);
        self::assertStringContainsString('font-weight:400;font-style:italic', $head);
        self::assertStringContainsString('font-display:swap', $head);
        self::assertStringContainsString('fonts.googleapis.com', $head, 'the headings still use the pairing');

        ThemeSettings::save(['heading_font_family_id' => (string) $heading]);
        $head = $this->head();
        self::assertSame(4, substr_count($head, '@font-face'));
        self::assertSame(1, substr_count($head, '<style id="site-fonts">'), 'one block, each family once');
        self::assertStringNotContainsString('fonts.googleapis.com', $head, 'both roles self-hosted: no Google request');
    }

    public function testNoLibraryUseMeansThePageIsAsItWas(): void
    {
        $this->createFamily('Bewaard', [['b.woff2', FontFileFixture::woff2(), '400']]);

        $head = $this->head();

        self::assertStringNotContainsString('site-fonts', $head);
        self::assertStringNotContainsString('@font-face', $head);
        self::assertStringContainsString(htmlspecialchars((string) ThemeFonts::pairing(ThemeSettings::get('font_pairing'))['url'], ENT_QUOTES, 'UTF-8'), $head);
    }

    public function testRestoringTheDefaultDesignClearsTheRolesAndKeepsTheLibrary(): void
    {
        $id = $this->createFamily('Herstel', [['h.ttf', FontFileFixture::ttf(), '400']]);
        ThemeSettings::save(['body_font_family_id' => (string) $id]);

        ThemeSettings::reset();

        self::assertSame([], FontLibraryFixture::rolesSnapshot());
        self::assertSame('', ThemeSettings::get('body_font_family_id'));
        self::assertNotNull(FontLibrary::family($id));
        ColorPaletteFixture::restore($this->palettes);
    }

    public function testAnotherColourPaletteNeverChangesAFont(): void
    {
        $id = $this->createFamily('Palet', [['p.ttf', FontFileFixture::ttf(), '400']]);
        ThemeSettings::save(['font_pairing' => 'lora-montserrat', 'heading_font_family_id' => (string) $id]);
        $stacks = ThemeCss::stacks();
        $ids = ThemeCss::fontFamilyIds();

        $other = ColorPaletteService::create(['name' => 'ZZ Ander palet'] + ColorPaletteFixture::COLORS);
        ColorPaletteService::activate($other);
        ThemeSettings::clearCache();

        self::assertSame($stacks, ThemeCss::stacks());
        self::assertSame($ids, ThemeCss::fontFamilyIds());
        self::assertSame('lora-montserrat', ThemeSettings::get('font_pairing'));
        self::assertSame('#FF7518', ThemeSettings::get('primary_color'), 'the colours did change');
        ThemeSettings::save(['font_pairing' => ThemeFonts::DEFAULT_KEY]);
    }

    public function testAPageThemeUsesItsOwnFamilyAndTheSiteKeepsItsOwn(): void
    {
        $site = $this->createFamily('Site', [['s.woff2', FontFileFixture::woff2(), '400']]);
        $own = $this->createFamily('Thema', [['t.woff2', FontFileFixture::woff2(), '400'], ['tb.woff2', FontFileFixture::woff2(), '700']]);
        ThemeSettings::save(['body_font_family_id' => (string) $site]);

        $values = PageThemeService::validate(['name' => PageThemeFixture::PREFIX . 'Eigen', 'heading_font_family_id' => (string) $own] + PageThemeService::defaults());
        self::assertSame([], $values['errors']);
        self::assertSame((string) $site, $values['values']['body_font_family_id'], 'a new theme starts as the website, fonts included');
        $themeId = PageThemeService::create($values['values']);

        $appearance = PageThemeService::appearanceOf(PageThemeService::theme($themeId));
        self::assertSame(FontLibrary::stack($own), $appearance->declarations()['--font-display']);
        self::assertSame(FontLibrary::stack($site), $appearance->declarations()['--font-body']);

        PageThemeCss::declare($appearance);
        $head = $this->head(false);
        self::assertSame(3, substr_count($head, '@font-face'), 'the site family once, the theme family\'s two variants');

        $bad = PageThemeService::validate(['name' => PageThemeFixture::PREFIX . 'Kapot', 'heading_font_family_id' => '999999'] + PageThemeService::defaults());
        self::assertArrayHasKey('heading_font_family_id', $bad['errors']);
    }

    public function testAMissingFileLeavesTheTextReadable(): void
    {
        $id = $this->createFamily('Kwijt', [['k.woff2', FontFileFixture::woff2(), '400']]);
        ThemeSettings::save(['body_font_family_id' => (string) $id]);
        $file = FontLibrary::family($id)['variants'][0]['file_name'];
        unlink($this->directory . '/' . $file);

        $family = FontLibrary::family($id);
        self::assertSame(1, $family['missing_files'], 'the CMS says so');
        self::assertStringEndsWith('sans-serif', (string) FontLibrary::stack($id), 'the browser falls back to a font every device has');
        self::assertStringContainsString('--font-body: ' . FontLibrary::stack($id), $this->head());
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $files name, bytes, variant
     */
    private function createFamily(string $name, array $files, string $category = 'sans'): int
    {
        $uploads = array_map(fn (array $file): array => $this->upload($file[0], $file[1], $file[2]), $files);
        $result = FontLibrary::createFamily(['name' => FontLibraryFixture::PREFIX . $name, 'category' => $category, 'source_url' => null], $uploads, false);
        self::assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @return array{file: array<string, mixed>, variant: string} */
    private function upload(string $name, string $bytes, string $variant): array
    {
        $upload = FontFileFixture::upload($name, $bytes);
        $this->temporary[] = $upload['tmp_name'];

        return ['file' => $upload, 'variant' => $variant];
    }

    private function head(bool $resetPageTheme = true): string
    {
        ThemeSettings::clearCache();
        $appearance = PageThemeCss::current();
        PageAssets::reset();
        if (!$resetPageTheme && $appearance !== null) {
            PageThemeCss::declare($appearance);
        }

        ob_start();
        PageAssets::renderStyles();

        return (string) ob_get_clean();
    }
}
