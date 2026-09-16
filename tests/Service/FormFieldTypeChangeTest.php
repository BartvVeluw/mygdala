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

    /**
     * A row holding every setting there is — a placeholder, options, a
     * usable default, and the key the form's Reply-To names — stored as each
     * type in turn. What a change loses depends only on what the OLD type
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
            'text' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P]],
            'textarea' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P]],
            'email' => [
                'text' => [self::R], 'textarea' => [self::R], 'email' => [], 'tel' => [self::R],
                'select' => [self::P, self::R], 'radio' => [self::P, self::R],
                'checkbox' => [self::P, self::R], 'consent' => [self::P, self::R],
            ],
            'tel' => $textLike + ['select' => [self::P], 'radio' => [self::P], 'checkbox' => [self::P], 'consent' => [self::P]],
            'select' => [
                'text' => [self::O, self::D], 'textarea' => [self::O, self::D], 'email' => [self::O, self::D], 'tel' => [self::O, self::D],
                'select' => [], 'radio' => [], 'checkbox' => [self::O, self::D], 'consent' => [self::O, self::D],
            ],
            'radio' => [
                'text' => [self::O, self::D], 'textarea' => [self::O, self::D], 'email' => [self::O, self::D], 'tel' => [self::O, self::D],
                'select' => [], 'radio' => [], 'checkbox' => [self::O, self::D], 'consent' => [self::O, self::D],
            ],
            'checkbox' => array_fill_keys(FormFieldTypes::keys(), []),
            'consent' => array_fill_keys(FormFieldTypes::keys(), []),
        ];

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
            $this->assertSame([], FormFieldTypeChange::losses($this->row('file'), $type, 'contact'), 'file -> ' . $key);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $type, array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'form_id' => 3,
            'field_key' => 'contact',
            'field_type' => $type,
            'label_nl' => 'Contact',
            'label_en' => null,
            'placeholder_nl' => 'Uw naam',
            'placeholder_en' => null,
            'help_text_nl' => null,
            'help_text_en' => null,
            'is_required' => 1,
            'sort_order' => 0,
            'options' => "Ja|Yes\nNee|No",
            'default_value' => 'Ja',
        ];
    }
}
