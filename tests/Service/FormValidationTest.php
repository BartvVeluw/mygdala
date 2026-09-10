<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormField;
use App\Service\Forms\FormFieldOptions;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormValidator;
use PHPUnit\Framework\TestCase;

/**
 * The read model and the validator: what a form definition makes of its
 * rows, and what the server does with a POST.
 *
 * THE POINT OF ALMOST EVERY TEST HERE is the direction of the loop. The
 * validator walks the FORM DEFINITION, never the request — so a field the
 * form does not have cannot be validated, cannot be stored and cannot reach
 * the notification e-mail, no matter what a request carries under its name.
 *
 * No database and no web server: this file belongs to the `fast` tier
 * (TESTING.md). Definitions are built from row arrays, exactly as
 * App\Service\Forms\FormCatalog builds them from the ones it reads.
 */
class FormValidationTest extends TestCase
{
    // -------------------------------------------------------- the read model

    public function testAFormIsBuiltFromItsRowsWithTheBilingualFallbackApplied(): void
    {
        $form = $this->form(['submit_label_nl' => 'Verstuur', 'submit_label_en' => ''], [
            $this->row('naam', 'text', ['label_nl' => 'Naam', 'label_en' => '']),
            $this->row('email', 'email', ['label_nl' => 'E-mail', 'label_en' => 'Email']),
        ]);

        $this->assertSame(['naam', 'email'], $form->fieldKeys());
        $this->assertSame('Verstuur', $form->submitLabel->en, 'an empty English label falls back to Dutch');
        $this->assertSame('Naam', $form->field('naam')->label->en);
        $this->assertSame('Email', $form->field('email')->label->en);
    }

    /**
     * A stored row naming a type the registry does not know must not take a
     * public page down — the same degradation an unknown content block gets
     * (CONTENT-BLOCKS.md).
     */
    public function testAFieldOfAnUnknownTypeIsSkippedRatherThanRendered(): void
    {
        $form = $this->form([], [
            $this->row('naam', 'text'),
            $this->row('bestand', 'file'),
            $this->row('email', 'email'),
        ]);

        $this->assertSame(['naam', 'email'], $form->fieldKeys());
        $this->assertNull($form->field('bestand'));
    }

    public function testAFieldWithAnUnusableKeyIsSkipped(): void
    {
        $form = $this->form([], [
            $this->row('naam', 'text'),
            $this->row('hp-note', 'text'),
            $this->row('Met Spatie', 'text'),
        ]);

        $this->assertSame(['naam'], $form->fieldKeys());
    }

    /**
     * A dropdown with no options would render an empty list a visitor cannot
     * satisfy, so it is left out until the editor fills it in.
     */
    public function testAChoiceFieldWithoutOptionsIsNotRendered(): void
    {
        $form = $this->form([], [
            $this->row('keuze', 'select', ['options' => null]),
            $this->row('andere', 'radio', ['options' => "Een\nTwee"]),
        ]);

        $this->assertSame(['andere'], $form->fieldKeys());
    }

    public function testAConsentBoxIsRequiredWhateverTheRowSays(): void
    {
        $form = $this->form([], [$this->row('akkoord', 'consent', ['is_required' => 0])]);

        $this->assertTrue($form->field('akkoord')->isRequired);
    }

    public function testAFormWithoutUsableFieldsCannotBeShown(): void
    {
        $this->assertFalse($this->form([], [])->isRenderable());
        $this->assertFalse($this->form([], [$this->row('x', 'select', ['options' => null])])->isRenderable());
    }

    public function testAnInactiveFormCannotBeShown(): void
    {
        $form = $this->form(['is_active' => 0], [$this->row('naam', 'text')]);

        $this->assertFalse($form->isRenderable());
        $this->assertTrue($form->hasFields(), 'it still HAS its fields; it just may not be rendered');
    }

    public function testTheSubmitLabelAndSuccessMessageFallBackToGenericWording(): void
    {
        $form = $this->form(['submit_label_nl' => '', 'submit_label_en' => '', 'success_message_nl' => '', 'success_message_en' => '']);

        $this->assertSame('Versturen', $form->submitLabel->nl);
        $this->assertSame('Send', $form->submitLabel->en);
        $this->assertNotSame('', $form->successMessage->nl);

        foreach ([$form->submitLabel->nl, $form->submitLabel->en, $form->successMessage->nl, $form->successMessage->en] as $text) {
            $this->assertStringNotContainsStringIgnoringCase('veluw', $text, 'a generic default must not name a company');
            $this->assertStringNotContainsStringIgnoringCase('offerte', $text, 'a generic default must not name one site\'s business');
        }
    }

    // ---------------------------------------------------------- the reply-to

    public function testOnlyAnEmailFieldCanBeTheReplyTo(): void
    {
        $form = $this->form(['reply_to_field_key' => 'email'], [
            $this->row('naam', 'text'),
            $this->row('email', 'email'),
        ]);

        $this->assertSame('email', $form->replyToField()->key);
        $this->assertSame(['email'], array_map(static fn ($f) => $f->key, $form->replyToCandidates()));
    }

    public function testAReplyToPointingAtSomethingElseIsIgnored(): void
    {
        $this->assertNull($this->form(['reply_to_field_key' => 'naam'], [$this->row('naam', 'text')])->replyToField());
        $this->assertNull($this->form(['reply_to_field_key' => 'weg'], [$this->row('naam', 'text')])->replyToField());
        $this->assertNull($this->form(['reply_to_field_key' => ''], [$this->row('email', 'email')])->replyToField());
    }

    // ------------------------------------------------------ default values

    /**
     * A choice field may start on one of its own options — the property
     * that gives the contact form's audience radio its "Particulier" back
     * without any renderer knowing that word.
     */
    public function testAChoiceFieldCanStartOnOneOfItsOptions(): void
    {
        $form = $this->form([], [
            $this->row('voorkeur', 'radio', ['options' => "Ochtend|Morning\nMiddag|Afternoon", 'default_value' => 'Middag']),
            $this->row('aantal', 'select', ['options' => "Een|One\nTwee|Two", 'default_value' => 'Een']),
        ]);

        $this->assertSame('Middag', $form->field('voorkeur')->defaultValue);
        $this->assertTrue($form->field('voorkeur')->hasDefaultValue());
        $this->assertSame('Een', $form->field('aantal')->defaultValue);
    }

    public function testAFieldWithoutADefaultSaysSo(): void
    {
        $form = $this->form([], [$this->row('voorkeur', 'radio', ['options' => "Ochtend\nMiddag"])]);

        $this->assertSame('', $form->field('voorkeur')->defaultValue);
        $this->assertFalse($form->field('voorkeur')->hasDefaultValue());
    }

    /**
     * A default naming something the field does not offer is dropped rather
     * than rendered — an option that was renamed, a type that was switched,
     * a hand-edited row.
     */
    public function testADefaultThatIsNotOneOfTheOptionsIsIgnored(): void
    {
        foreach (['Overheid', 'ochtend', '', '   ', '<script>'] as $stored) {
            $form = $this->form([], [
                $this->row('voorkeur', 'radio', ['options' => "Ochtend\nMiddag", 'default_value' => $stored]),
            ]);

            $this->assertSame(
                '',
                $form->field('voorkeur')->defaultValue,
                var_export($stored, true) . ' must not become a default'
            );
        }
    }

    public function testOnlyAChoiceFieldCanHaveADefaultAtAll(): void
    {
        foreach (FormFieldTypes::all() as $key => $type) {
            $this->assertSame(
                in_array($key, ['select', 'radio'], true),
                $type->usesDefaultValue(),
                $key . ': only a choice field may start out pre-selected'
            );
        }

        // Even with a value in the row, a non-choice type keeps none.
        $form = $this->form([], [
            $this->row('naam', 'text', ['default_value' => 'Jan']),
            $this->row('akkoord', 'consent', ['default_value' => 'Ja']),
        ]);

        $this->assertSame('', $form->field('naam')->defaultValue);
        $this->assertSame('', $form->field('akkoord')->defaultValue, 'consent nobody gave is not consent');
    }

    /**
     * The one rule, in the form the admin applies it before writing — see
     * api/admin/update-form-field.php.
     */
    public function testTheDefaultRuleIsTheSameOneTheAdminApplies(): void
    {
        $options = FormFieldOptions::fromStored("Ochtend|Morning\nMiddag|Afternoon");
        $radio = FormFieldTypes::get('radio');
        $text = FormFieldTypes::get('text');

        $this->assertTrue(FormField::isUsableDefault($radio, $options, 'Ochtend'));
        $this->assertTrue(FormField::isUsableDefault($radio, $options, '  Middag  '));

        $this->assertFalse(FormField::isUsableDefault($radio, $options, 'Morning'), 'the English label is a display value');
        $this->assertFalse(FormField::isUsableDefault($radio, $options, 'Avond'));
        $this->assertFalse(FormField::isUsableDefault($radio, $options, ''));
        $this->assertFalse(FormField::isUsableDefault($text, $options, 'Ochtend'), 'a text field takes no default');
        $this->assertFalse(FormField::isUsableDefault($radio, FormFieldOptions::fromStored(null), 'Ochtend'));
    }

    // --------------------------------------------------- rendering precedence

    /**
     *     ingestuurde waarde  ->  ingestelde standaardwaarde  ->  leeg
     *
     * A fresh form starts on the default.
     */
    public function testAFreshFormStartsOnTheConfiguredDefault(): void
    {
        $field = $this->form([], [
            $this->row('voorkeur', 'radio', ['options' => "Ochtend\nMiddag", 'default_value' => 'Middag']),
        ])->field('voorkeur');

        $this->assertSame('Middag', FormRenderState::fresh('form-abc1234567')->valueFor($field));
    }

    /**
     * THE rule this whole feature turns on: after a failed submission the
     * visitor's own choice wins, and the default does not quietly reassert
     * itself.
     */
    public function testAfterAFailedSubmissionTheVisitorsOwnChoiceWins(): void
    {
        $field = $this->form([], [
            $this->row('voorkeur', 'radio', ['options' => "Ochtend\nMiddag", 'default_value' => 'Middag']),
        ])->field('voorkeur');

        $state = FormRenderState::withErrors(
            'form-abc1234567',
            ['voorkeur' => 'Ochtend'],
            ['naam' => 'Naam is verplicht.'],
            ['naam' => 'Name is required.']
        );

        $this->assertSame('Ochtend', $state->valueFor($field));
    }

    /**
     * And a visitor who ended up with NOTHING selected keeps nothing: an
     * empty recorded value is still an answer the visitor gave, so the
     * default must not paper over it.
     */
    public function testAnEmptySubmittedValueIsNotReplacedByTheDefault(): void
    {
        $field = $this->form([], [
            $this->row('aantal', 'select', ['options' => "Een\nTwee", 'default_value' => 'Een']),
        ])->field('aantal');

        $state = FormRenderState::withErrors(
            'form-abc1234567',
            ['aantal' => ''],
            ['aantal' => 'Maak een keuze uit de lijst.'],
            ['aantal' => 'Please choose one of the options.']
        );

        $this->assertSame('', $state->valueFor($field));
        $this->assertSame('', $state->submittedValueFor('aantal'));
    }

    public function testAFieldWithNoDefaultAndNothingSubmittedStartsEmpty(): void
    {
        $field = $this->form([], [$this->row('voorkeur', 'radio', ['options' => "Ochtend\nMiddag"])])->field('voorkeur');
        $state = FormRenderState::fresh('form-abc1234567');

        $this->assertSame('', $state->valueFor($field));
        $this->assertNull($state->submittedValueFor('voorkeur'));
    }

    /**
     * A default is a RENDERING convenience, never a submitted answer: it
     * does not stand in for a required field the visitor never filled in.
     */
    public function testADefaultDoesNotSatisfyValidationOnItsOwn(): void
    {
        $form = $this->form([], [
            $this->row('voorkeur', 'radio', ['is_required' => 1, 'options' => "Ochtend\nMiddag", 'default_value' => 'Middag']),
        ]);

        $result = (new FormValidator())->validate($form, []);

        $this->assertFalse($result->isValid());
        $this->assertSame('', $result->valueFor('voorkeur'));
    }

    // ---------------------------------------------------------- the validator

    public function testARequiredFieldThatIsEmptyGetsAMessageInBothLanguages(): void
    {
        $result = (new FormValidator())->validate(
            $this->form([], [$this->row('naam', 'text', ['is_required' => 1, 'label_nl' => 'Naam', 'label_en' => 'Name'])]),
            ['naam' => '   ']
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('Naam is verplicht.', $result->errorFor('naam')->nl);
        $this->assertSame('Name is required.', $result->errorFor('naam')->en);
    }

    public function testAnOptionalFieldLeftBlankIsFine(): void
    {
        $result = (new FormValidator())->validate(
            $this->form([], [$this->row('telefoon', 'tel', ['is_required' => 0])]),
            []
        );

        $this->assertTrue($result->isValid());
        $this->assertSame('', $result->valueFor('telefoon'));
    }

    public function testAnEmailMustBeAnEmailWhenItIsFilledIn(): void
    {
        $validator = new FormValidator();
        $form = $this->form([], [$this->row('email', 'email', ['is_required' => 1])]);

        $this->assertTrue($validator->validate($form, ['email' => 'a@b.nl'])->isValid());
        $this->assertFalse($validator->validate($form, ['email' => 'kapot'])->isValid());
        $this->assertFalse($validator->validate($form, [])->isValid());
    }

    public function testATextareaIsCappedRatherThanRefusedForBeingLong(): void
    {
        $form = $this->form([], [$this->row('bericht', 'textarea', ['is_required' => 1])]);
        $result = (new FormValidator())->validate($form, ['bericht' => str_repeat('a', 9000)]);

        $this->assertTrue($result->isValid(), 'a long message is trimmed, not rejected');
        $this->assertSame(5000, mb_strlen($result->valueFor('bericht')));
    }

    public function testAChoiceOutsideTheConfiguredOptionsIsRefused(): void
    {
        $validator = new FormValidator();

        foreach (['select', 'radio'] as $type) {
            $form = $this->form([], [$this->row('keuze', $type, ['is_required' => 1, 'options' => "Ja|Yes\nNee|No"])]);

            $this->assertTrue($validator->validate($form, ['keuze' => 'Ja'])->isValid(), $type);
            $this->assertFalse($validator->validate($form, ['keuze' => 'Misschien'])->isValid(), $type);
            $this->assertFalse($validator->validate($form, ['keuze' => 'Yes'])->isValid(), $type . ': the English label is a display value, not a submittable one');
        }
    }

    public function testARequiredCheckboxMustActuallyBeTicked(): void
    {
        $validator = new FormValidator();
        $form = $this->form([], [$this->row('akkoord', 'consent', ['label_nl' => 'Voorwaarden', 'label_en' => 'Terms'])]);

        $this->assertFalse($validator->validate($form, [])->isValid());
        $this->assertTrue($validator->validate($form, ['akkoord' => 'Ja'])->isValid());

        $error = $validator->validate($form, [])->errorFor('akkoord');
        $this->assertStringContainsString('Voorwaarden', $error->nl);
        $this->assertStringContainsString('Terms', $error->en);
    }

    /**
     * THE rule that makes "never blindly persist $_POST" true by
     * construction: the result can only ever contain keys the form has.
     */
    public function testAPostedFieldTheFormDoesNotHaveIsNeverReadOrStored(): void
    {
        $form = $this->form([], [$this->row('naam', 'text', ['is_required' => 1])]);

        $result = (new FormValidator())->validate($form, [
            'naam' => 'Echt veld',
            'geheim' => 'zou nergens mogen landen',
            'notification_email' => 'aanvaller@example.com',
            'store_submissions' => '1',
            'form-key' => 'ander-formulier',
            'hp-note' => '',
            'form-ts' => '123',
            'csrf_token' => 'x',
        ]);

        $this->assertTrue($result->isValid());
        $this->assertSame(['naam'], array_keys($result->values));

        $snapshot = (new FormValidator())->snapshot($form, $result->values);
        $this->assertSame(['naam'], array_column($snapshot, 'field_key'));
    }

    /** A form's own control fields are never stored as answers. */
    public function testTheFormsOwnControlFieldsAreNeverStoredAsAnswers(): void
    {
        $form = $this->form([], [$this->row('naam', 'text'), $this->row('email', 'email')]);
        $result = (new FormValidator())->validate($form, ['naam' => 'A', 'email' => 'a@b.nl']);
        $snapshot = (new FormValidator())->snapshot($form, $result->values);

        foreach (['hp-note', 'form-ts', 'form-key', 'form-instance', 'form-source', 'csrf_token', 'bestand'] as $control) {
            $this->assertNotContains($control, array_column($snapshot, 'field_key'), $control . ' is machinery, not an answer');
        }
    }

    public function testEveryFieldGetsARowInTheSnapshotEvenWhenItWasLeftBlank(): void
    {
        $form = $this->form([], [
            $this->row('naam', 'text'),
            $this->row('telefoon', 'tel'),
        ]);

        $snapshot = (new FormValidator())->snapshot($form, ['naam' => 'Iemand']);

        $this->assertCount(2, $snapshot);
        $this->assertSame('', $snapshot[1]['value']);
    }

    /**
     * The snapshot carries the label and the type it was sent with, which is
     * what lets a submission outlive the form being edited.
     */
    public function testTheSnapshotCarriesTheLabelAndTypeOfTheMoment(): void
    {
        $form = $this->form([], [$this->row('naam', 'text', ['label_nl' => 'Hoe heet je?'])]);

        $snapshot = (new FormValidator())->snapshot($form, ['naam' => 'Iemand']);

        $this->assertSame([
            'field_key' => 'naam',
            'field_label' => 'Hoe heet je?',
            'field_type' => 'text',
            'value' => 'Iemand',
        ], $snapshot[0]);
    }

    public function testFailedValuesComeBackSoTheVisitorDoesNotRetypeThem(): void
    {
        $form = $this->form([], [
            $this->row('naam', 'text', ['is_required' => 1]),
            $this->row('email', 'email', ['is_required' => 1]),
        ]);

        $result = (new FormValidator())->validate($form, ['naam' => 'Behouden', 'email' => 'kapot']);

        $this->assertFalse($result->isValid());
        $this->assertSame(['naam' => 'Behouden', 'email' => 'kapot'], $result->retainableValues());
    }

    /**
     * @param array<string, mixed>       $form
     * @param list<array<string, mixed>> $fields
     */
    private function form(array $form = [], array $fields = []): FormDefinition
    {
        return FormDefinition::fromRows($form + [
            'id' => 1,
            'name' => 'Testformulier',
            'internal_key' => 'testformulier',
            'is_active' => 1,
            'submit_label_nl' => 'Versturen',
            'submit_label_en' => 'Send',
            'success_message_nl' => 'Bedankt.',
            'success_message_en' => 'Thanks.',
            'notification_email' => null,
            'reply_to_field_key' => null,
            'store_submissions' => 0,
        ], $fields);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $key, string $type, array $overrides = []): array
    {
        static $id = 0;

        return $overrides + [
            'id' => ++$id,
            'field_key' => $key,
            'field_type' => $type,
            'label_nl' => ucfirst($key),
            'label_en' => null,
            'placeholder_nl' => null,
            'placeholder_en' => null,
            'help_text_nl' => null,
            'help_text_en' => null,
            'is_required' => 0,
            'sort_order' => $id,
            'options' => null,
            'default_value' => null,
        ];
    }
}
