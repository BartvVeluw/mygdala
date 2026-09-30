<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ButtonStyleRepository;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ButtonStyleFixture;
use Tests\Support\ColorPaletteFixture;

/**
 * The button style library against the test database (Button Styles 2.0,
 * App\Service\Theme\ButtonStyles): making, changing, copying and deleting a
 * style, the two defaults, what a delete refuses and why, the checks on
 * every value, a content button's choice, where the library is used, the old
 * Knopvorm as the defaults' shape, and the CSS a page gets. See THEMING.md,
 * "Knopstijlen".
 */
final class ButtonStylesTest extends TestCase
{
    private const SLUG = 'zz-knopstijlen';

    /** @var array{styles: list<array<string, mixed>>, defaults: list<array<string, mixed>>} */
    private array $snapshot;

    protected function setUp(): void
    {
        $this->snapshot = ButtonStyleFixture::snapshot();
        ButtonStyles::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM contact_cards WHERE page_slug = ?')->execute([self::SLUG]);
        $db->prepare('DELETE FROM rich_text_sections WHERE page_slug = ?')->execute([self::SLUG]);
        ButtonStyleFixture::restore($this->snapshot);
    }

    // ------------------------------------------------------------------ CRUD

    public function testANewStyleIsStoredAndChangesNothingUntilItIsChosen(): void
    {
        $before = ButtonStyles::styleBlock();
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Nieuw', ['appearance' => 'outline', 'border_width' => 'thin']));

        $style = ButtonStyles::find($id);
        self::assertSame('ZZ Nieuw', $style['name']);
        self::assertSame('outline', $style['appearance']);
        self::assertSame(['roles' => [], 'buttons' => 0], ButtonStyles::usage($id));
        self::assertSame($before, ButtonStyles::styleBlock());
    }

    public function testEditingAndRenaming(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Oud'));
        ButtonStyles::update($id, ButtonStyleFixture::values('ZZ Hernoemd', ['shape' => 'square', 'fill_color' => '#0F766E']));

        $style = ButtonStyles::find($id);
        self::assertSame('ZZ Hernoemd', $style['name']);
        self::assertSame('square', $style['shape']);
        self::assertSame('#0F766E', $style['fill_color']);
    }

    public function testADuplicateIsAnIndependentCopy(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Bron', ['icon' => 'arrow_right']));
        $copy = ButtonStyles::duplicate($id);
        $second = ButtonStyles::duplicate($id);

        self::assertSame('ZZ Bron (kopie)', ButtonStyles::find($copy)['name']);
        self::assertSame('ZZ Bron (kopie 2)', ButtonStyles::find($second)['name']);
        self::assertSame('arrow_right', ButtonStyles::find($copy)['icon']);
        self::assertSame(['roles' => [], 'buttons' => 0], ButtonStyles::usage($copy));

        ButtonStyles::update($copy, ButtonStyleFixture::values('ZZ Bron (kopie)', ['icon' => 'plus']));
        self::assertSame('arrow_right', ButtonStyles::find($id)['icon'], 'changing the copy leaves the original');
        self::assertNull(ButtonStyles::duplicate(999999));
    }

    public function testAnUnusedStyleCanBeDeleted(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Weg'));

        self::assertSame(ButtonStyles::DELETE_DELETED, ButtonStyles::delete($id));
        self::assertNull(ButtonStyles::find($id));
        self::assertSame(ButtonStyles::DELETE_MISSING, ButtonStyles::delete($id));
    }

    public function testAStyleAContentButtonUsesIsNotDeleted(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ In gebruik'));
        $this->contactCardWith($id, 'a');
        $this->contactCardWith($id, 'b');

        self::assertSame(['roles' => [], 'buttons' => 2], ButtonStyles::usage($id));
        self::assertSame(ButtonStyles::DELETE_IN_USE, ButtonStyles::delete($id));
        self::assertNotNull(ButtonStyles::find($id));
        self::assertStringContainsString('2', ButtonStyles::refusal(ButtonStyles::DELETE_IN_USE, $id));

        // The database refuses it too, whatever was checked before.
        try {
            Database::connection()->exec('DELETE FROM button_styles WHERE id = ' . $id);
            self::fail('a style a block uses must be refused by the foreign key');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }
        self::assertFalse((new ButtonStyleRepository())->delete($id));
    }

    public function testTheDefaultsAreNotDeleted(): void
    {
        foreach (['primary', 'secondary'] as $role) {
            $id = ButtonStyles::defaultIds()[$role];
            self::assertSame(ButtonStyles::DELETE_DEFAULT, ButtonStyles::delete($id), $role);
            self::assertNotNull(ButtonStyles::find($id));
        }

        try {
            Database::connection()->exec('DELETE FROM button_styles WHERE id = ' . ButtonStyles::defaultIds()['primary']);
            self::fail('the default must be refused by the foreign key');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }
    }

    public function testAnUnknownOrMalformedIdIsNothing(): void
    {
        self::assertNull(ButtonStyles::find(999999));
        self::assertNull(ButtonStyles::find(0));
        self::assertNull(ButtonStyles::find(-3));
        self::assertFalse(ButtonStyles::setDefault('primary', 999999));
        self::assertFalse(ButtonStyles::setDefault('tertiary', ButtonStyles::defaultIds()['primary']));
        self::assertSame(ButtonStyles::DELETE_MISSING, ButtonStyles::delete(999999));
    }

    // -------------------------------------------------------------- defaults

    public function testThereIsOneStandardButtonAndOneSecondButton(): void
    {
        $ids = ButtonStyles::defaultIds();
        self::assertSame(['primary', 'secondary'], array_keys($ids));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM button_style_defaults WHERE role = 'primary'")->fetchColumn());

        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Nieuwe standaard', ['size' => 'large']));
        self::assertTrue(ButtonStyles::setDefault('primary', $id));
        self::assertSame($id, ButtonStyles::defaultFor('primary')['id']);
        self::assertSame(['primary'], ButtonStyles::usage($id)['roles']);

        // The standard button draws every plain .btn from now on.
        self::assertStringContainsString("--btn-pad-y: 1.15rem;", ButtonStyles::styleBlock());
    }

    public function testTheOldKnopvormIsTheShapeOfTheDefaults(): void
    {
        $primary = ButtonStyles::defaultIds()['primary'];
        $secondary = ButtonStyles::defaultIds()['secondary'];

        ThemeSettings::clearCache();
        self::assertSame(ButtonStyles::find($primary)['shape'], ThemeSettings::get('button_shape'));

        ThemeSettings::save(['button_shape' => 'square']);
        self::assertSame('square', ButtonStyles::find($primary)['shape']);
        self::assertSame('square', ButtonStyles::find($secondary)['shape'], 'the old setting shaped every button');
        self::assertSame('square', ThemeSettings::get('button_shape'));
        self::assertSame('0px', ThemeSettings::buttonRadius());
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM theme_settings WHERE setting_key = 'button_shape'")->fetchColumn(), 'no second copy');

        ThemeSettings::save(['button_shape' => '20px;}']);
        self::assertSame('square', ButtonStyles::find($primary)['shape']);

        // "Standaardvormgeving herstellen" gives the defaults the shipped
        // shape back; it also resets the active palette and the font roles,
        // which are put back afterwards.
        $db = Database::connection();
        $palettes = ColorPaletteFixture::snapshot();
        $settings = $db->query('SELECT * FROM theme_settings')->fetchAll();
        $roles = $db->query('SELECT * FROM theme_font_roles')->fetchAll();
        try {
            ThemeSettings::reset();
            self::assertSame('pill', ButtonStyles::find($primary)['shape']);
            self::assertSame('pill', ThemeSettings::get('button_shape'));
        } finally {
            ColorPaletteFixture::restore($palettes);
            $db->exec('DELETE FROM theme_settings');
            foreach ($settings as $row) {
                $db->prepare('INSERT INTO theme_settings (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            }
            foreach ($roles as $row) {
                $db->prepare('INSERT INTO theme_font_roles (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            }
            ThemeSettings::clearCache();
        }
    }

    // ------------------------------------------------------------ validation

    public function testEveryValueIsCheckedAgainstItsList(): void
    {
        $bad = [
            'appearance' => 'filled;}body{x',
            'shape' => '20px',
            'size' => 'huge',
            'border_width' => '9px',
            'shadow' => '0 0 99px red',
            'font_weight' => '900',
            'font_role' => 'mono',
            'icon' => '<svg onload=alert(1)>',
            'icon_position' => 'top',
            'icon_gap' => '5rem',
            'hover_effect' => 'spin',
        ];

        foreach ($bad as $field => $value) {
            $result = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Fout', [$field => $value]));
            self::assertArrayHasKey($field, $result['errors'], $field);
            self::assertArrayNotHasKey($field, $result['values'], $field);
        }
    }

    public function testAColourIsAThemeColourOrAFixedHex(): void
    {
        foreach (['red;}body{display:none', 'url(https://x.test/a.png)', 'var(--color-bg)', 'expression(1)', '#12', 'rgb(1,2,3)'] as $bad) {
            $result = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Kleur', ['fill_color' => $bad]));
            self::assertArrayHasKey('fill_color', $result['errors'], $bad);

            $custom = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Kleur', ['text_color' => 'custom']) + ['text_color_custom' => $bad]);
            self::assertArrayHasKey('text_color', $custom['errors'], $bad);
        }

        $ok = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Kleur', ['fill_color' => 'custom', 'hover_text_color' => 'surface']) + ['fill_color_custom' => '0f766e']);
        self::assertSame([], $ok['errors']);
        self::assertSame('#0F766E', $ok['values']['fill_color']);
        self::assertSame('surface', $ok['values']['hover_text_color']);
        self::assertNull($ok['values']['hover_fill_color'], 'empty = unchanged');

        $required = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Kleur', ['text_color' => '']));
        self::assertArrayHasKey('text_color', $required['errors'], 'the text colour is never "unchanged"');
    }

    public function testTheName(): void
    {
        self::assertArrayHasKey('name', ButtonStyles::validate(ButtonStyleFixture::form('  '))['errors']);
        self::assertArrayHasKey('name', ButtonStyles::validate(ButtonStyleFixture::form(str_repeat('x', 81)))['errors']);
        self::assertArrayHasKey('name', ButtonStyles::validate(ButtonStyleFixture::form('Primair'))['errors'], 'unique');

        $id = ButtonStyles::create(ButtonStyles::validate(ButtonStyleFixture::form('<b>ZZ</b> "knop"'))['values']);
        self::assertSame('<b>ZZ</b> "knop"', ButtonStyles::find($id)['name'], 'stored as text, escaped where printed');
        self::assertArrayNotHasKey('name', ButtonStyles::validate(ButtonStyleFixture::form('<b>ZZ</b> "knop"'), $id)['errors'], 'its own name is not taken');
    }

    public function testAnOutlineAlwaysHasABorder(): void
    {
        $values = ButtonStyles::validate(ButtonStyleFixture::form('ZZ Rand', ['appearance' => 'outline', 'border_width' => 'none']))['values'];

        self::assertSame('normal', $values['border_width']);
    }

    // --------------------------------------------------- a content button

    public function testAContentButtonsChoiceFromAForm(): void
    {
        $id = ButtonStyles::defaultIds()['secondary'];

        self::assertSame([7, null], ButtonStyles::choiceFromRequest([], 'button_style_id', 7), 'a form without the field keeps what is stored');
        self::assertSame([null, null], ButtonStyles::choiceFromRequest(['button_style_id' => ''], 'button_style_id', 7), '"Standaard"');
        self::assertSame([$id, null], ButtonStyles::choiceFromRequest(['button_style_id' => (string) $id], 'button_style_id', null));

        foreach (['999999', 'abc', '1 OR 1=1', '-1', ['1']] as $forged) {
            [$choice, $error] = ButtonStyles::choiceFromRequest(['button_style_id' => $forged], 'button_style_id', 7);
            self::assertSame(7, $choice, 'the stored choice stays');
            self::assertNotNull($error);
        }
    }

    public function testAChoiceIsStoredOnlyInAKnownSlot(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Slot'));
        $card = $this->contactCardWith(null, 'c');
        $repository = new ButtonStyleRepository();

        $repository->saveChoice('contact_cards', 'button_style_id', $card, $id);
        self::assertSame(1, ButtonStyles::usage($id)['buttons']);
        $repository->saveChoice('contact_cards', 'button_style_id', $card, null);
        ButtonStyles::clearCache();
        self::assertSame(0, ButtonStyles::usage($id)['buttons']);

        foreach ([['admin_users', 'button_style_id'], ['contact_cards', 'is_active'], ['contact_cards`; DROP TABLE x; --', 'button_style_id']] as [$table, $column]) {
            try {
                $repository->saveChoice($table, $column, $card, $id);
                self::fail($table . '.' . $column . ' is no slot');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertArrayHasKey('featured_products', ButtonStyleRepository::slots(), 'a module names its own slots');
    }

    public function testUsageCountsEveryBlockThatChoseTheStyle(): void
    {
        $id = ButtonStyles::create(ButtonStyleFixture::values('ZZ Teller'));
        $this->contactCardWith($id, 'd');
        Database::connection()->prepare('INSERT INTO rich_text_sections (page_slug, section_key, button_style_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
            ->execute([self::SLUG, 'tekst', $id]);
        ButtonStyles::clearCache();

        self::assertSame(2, ButtonStyles::usage($id)['buttons']);
        $listed = array_values(array_filter(ButtonStyles::all(), static fn (array $s): bool => $s['id'] === $id))[0];
        self::assertSame(2, $listed['uses']);
    }

    // ------------------------------------------------------------ the page

    public function testOnlyChosenStylesReachThePageAndACentralChangeReachesThemAll(): void
    {
        $chosen = ButtonStyles::create(ButtonStyleFixture::values('ZZ Gekozen', ['appearance' => 'outline', 'border_width' => 'thin']));
        $unused = ButtonStyles::create(ButtonStyleFixture::values('ZZ Ongebruikt'));
        $this->contactCardWith($chosen, 'e');
        $this->contactCardWith($chosen, 'f');
        ButtonStyles::clearCache();

        $block = ButtonStyles::styleBlock();
        self::assertSame(1, substr_count($block, '.btn.' . ButtonStyleCss::className($chosen) . '{'), 'two buttons, one rule');
        self::assertStringNotContainsString(ButtonStyleCss::className($unused) . '{', $block);
        self::assertStringContainsString('--btn-border-width: 1px;', $block);

        ButtonStyles::update($chosen, ButtonStyleFixture::values('ZZ Gekozen', ['appearance' => 'outline', 'border_width' => 'thick']));
        $block = ButtonStyles::styleBlock();
        self::assertStringContainsString('--btn-border-width: 2.5px;', $block);
        self::assertStringNotContainsString('--btn-border-width: 1px;', $block);
    }

    private function contactCardWith(?int $styleId, string $key): int
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO contact_cards (page_slug, section_key, button_style_id, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())')
            ->execute([self::SLUG, $key, $styleId]);
        ButtonStyles::clearCache();

        return (int) $db->lastInsertId();
    }
}
