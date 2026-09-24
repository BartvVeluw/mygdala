<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FormFieldTypeChange;
use App\Service\Forms\FormFieldTypes;
use PHPUnit\Framework\TestCase;

/**
 * What giving a stored field another type would lose
 * (App\Service\Forms\FormFieldTypeChange), for every pair of registered
 * types — the classification the field editor warns with and
 * api/admin/update-form-field.php refuses to write without confirmation.
 *
 * NO DATABASE AND NO WEB SERVER: a stored row is an array here. The editor
 * and the endpoint acting on the answer are Tests\Service\
 * FormFieldEditorHttpTest.
 */
final class FormFieldTypeChangeTest extends TestCase
{
    private const P = FormFieldTypeChange::PLACEHOLDER;
    private const O = FormFieldTypeChange::OPTIONS;
    private const D = FormFieldTypeChange::DEFAULT_VALUE;
    private const R = FormFieldTypeChange::REPLY_TO;
    private const F = FormFieldTypeChange::FILE_SETTINGS;

    /**
     * A row holding every setting there is — a placeholder, options, a
     * usable default, the key the form's Reply-To names, and an upload
     * field's accepted kinds and size — stored as each type in turn. What a change loses depends only on what the OLD type
     * used and the new one does not; a setting the old type never used was
     * invisible and is no loss.
     *
     * Written out in full rather than derived from the types' declarations,
     * so a change to those declarations shows up here as a changed promise.
     */
    public function testEveryPairOfTypesLosesExactlyWhatTheOldTypeUsedAndTheNewOneDoesNot(): void
    {
        $textLike = ['text' => [], 'textarea' => [], 'email' => [], 'tel' => []];

        $expected = [
            'text' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P], 'file' => [self::P]],
            'textarea' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P], 'file' => [self::P]],
            'email' => [
                'text' => [self::R], 'textarea' => [self::R], 'email' => [], 'tel' => [self::R],
                'select' => [self::P, self::R], 'radio' => [self::P, self::R],
                'checkbox' => [self::P, self::R], 'consent' => [self::P, self::R], 'file' => [self::P, self::R],
            ],
            'tel' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P], 'file' => [self::P]],
            'select' => [
                'text' => [self::O, self::D], 'textarea' => [self::O, self::D], 'email' => [self::O, self::D], 'tel' => [self::O, self::D],
                'select' => [], 'radio' => [], 'checkbox' => [self::O, self::D], 'consent' => [self::O, self::D], 'file' => [self::O, self::D],
            ],
            'radio' => [
                'text' => [self::O, self::D], 'textarea' => [self::O, self::D], 'email' => [self::O, self::D], 'tel' => [self::O, self::D],
                'select' => [], 'radio' => [], 'checkbox' => [self::O, self::D], 'consent' => [self::O, self::D], 'file' => [self::O, self::D],
            ],
            'checkbox' => array_fill_keys(FormFieldTypes::keys(), []),
            'consent' => array_fill_keys(FormFieldTypes::keys(), []),
            // An upload field used none of the text or choice settings, so it
            // only ever loses its own: the accepted kinds and the size.
            'file' => array_fill_keys(FormFieldTypes::keys(), [self::F]),
        ];
        $expected['file']['file'] = [];

        $this->assertSame(FormFieldTypes::keys(), array_keys($expected), 'every registered type is classified here');

        foreach (FormFieldTypes::keys() as $from) {
            foreach (FormFieldTypes::all() as $to => $type) {
                $this->assertSame(
                    $expected[$from][$to],
                    FormFieldTypeChange::losses($this->row($from), $type, 'contact'),
                    $from . ' -> ' . $to
                );
            }
        }
    }

    /** An empty setting holds nothing to lose. */
    public function testAnEmptySettingIsNoLoss(): void
    {
        $empty = $this->row('text', ['placeholder_nl' => '  ', 'placeholder_en' => null]);
        $this->assertSame([], FormFieldTypeChange::losses($empty, FormFieldTypes::get('checkbox'), null));

        $englishOnly = $this->row('text', ['placeholder_nl' => null, 'placeholder_en' => 'Your name']);
        $this->assertSame([self::P], FormFieldTypeChange::losses($englishOnly, FormFieldTypes::get('checkbox'), null), 'either language counts');

        $noOptions = $this->row('radio', ['options' => null, 'default_value' => null]);
        $this->assertSame([], FormFieldTypeChange::losses($noOptions, FormFieldTypes::get('text'), null));
    }

    /**
     * A default that matches none of the options was never applied (the
     * read model drops it), so losing it is no loss.
     */
    public function testADefaultThatMatchesNoOptionIsNoLoss(): void
    {
        $row = $this->row('select', ['default_value' => 'Misschien']);

        $this->assertSame([self::O], FormFieldTypeChange::losses($row, FormFieldTypes::get('text'), null));
    }

    /** Only the Reply-To this very field is counts, not any Reply-To at all. */
    public function testAnEmailFieldLosesOnlyAReplyToThatIsItself(): void
    {
        $row = $this->row('email', ['placeholder_nl' => null]);
        $text = FormFieldTypes::get('text');

        $this->assertSame([self::R], FormFieldTypeChange::losses($row, $text, 'contact'));
        $this->assertSame([], FormFieldTypeChange::losses($row, $text, 'ander-adres'), 'the Reply-To is another field');
        $this->assertSame([], FormFieldTypeChange::losses($row, $text, null), 'the form has no Reply-To');
        $this->assertSame([], FormFieldTypeChange::losses($row, $text, ''));
    }

    /** A stored type nobody registered used nothing anybody could see. */
    public function testAnUnregisteredStoredTypeLosesNothing(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame([], FormFieldTypeChange::losses($this->row('upload'), $type, 'contact'), 'upload -> ' . $key);
        }
    }

    /**
     * An upload field's settings count as soon as either is stored; a field
     * that never had them (a row from before they existed) loses nothing.
     */
    public function testAnUploadFieldLosesItsSettingsOnlyWhenItHasThem(): void
    {
        $text = FormFieldTypes::get('text');

        $this->assertSame([self::F], FormFieldTypeChange::losses($this->row('file', ['file_max_bytes' => null]), $text, null));
        $this->assertSame([self::F], FormFieldTypeChange::losses($this->row('file', ['file_types' => null]), $text, null));
        $this->assertSame([], FormFieldTypeChange::losses($this->row('file', ['file_types' => null, 'file_max_bytes' => null]), $text, null));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    /**
     * A stored field as App\Service\Forms\FormLocalization hands it over: its
     * words per website language and its option rows. The Dutch/English
     * placeholder keys stay in this test's own vocabulary, because "a
     * placeholder in any language is a loss" is exactly what it asks about.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $type, array $overrides = []): array
    {
        $translations = [];
        foreach (['nl' => $overrides['placeholder_nl'] ?? 'Uw naam', 'en' => $overrides['placeholder_en'] ?? null] as $code => $placeholder) {
            $words = $code === 'nl' ? ['label' => 'Contact'] : [];
            if (trim((string) $placeholder) !== '') {
                $words['placeholder'] = (string) $placeholder;
            }
            if ($words !== []) {
                $translations[$code] = $words;
            }
        }

        $options = array_key_exists('options', $overrides) ? $overrides['options'] : 'Ja|Yes';
        $choices = $options === null ? [] : [
            ['id' => 1, 'value' => 'Ja', 'sort_order' => 0, 'labels' => ['nl' => 'Ja', 'en' => 'Yes']],
            ['id' => 2, 'value' => 'Nee', 'sort_order' => 1, 'labels' => ['nl' => 'Nee', 'en' => 'No']],
        ];

        foreach (['placeholder_nl', 'placeholder_en', 'options'] as $moved) {
            unset($overrides[$moved]);
        }

        return $overrides + [
            'id' => 7,
            'form_id' => 3,
            'field_key' => 'contact',
            'field_type' => $type,
            'is_required' => 1,
            'sort_order' => 0,
            'default_value' => 'Ja',
            'file_types' => 'jpg,pdf',
            'file_max_bytes' => 5 * 1024 * 1024,
            'translations' => $translations,
            'choices' => $choices,
        ];
    }
}
