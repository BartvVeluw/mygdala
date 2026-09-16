<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\FormRepository;
use App\Service\AdminPermissions;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormFieldTypes;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * Adding and editing the fields of a form, over real HTTP and without a
 * browser — which is also exactly how the screens work without JavaScript.
 *
 * THE CONTRACT (FORMS.md, "Velden toevoegen en bewerken"): a field starts
 * with a choice of what kind of field it is, from cards named in the admin
 * catalogue; only a registered kind can be created; the list of fields says
 * what a field is in words, not the name it is posted under. The field
 * editor shows only the settings its kind uses, saves options and their
 * default in one go, sends an untouched field back unchanged, never changes
 * the key, and never lets a type change throw a setting away until that
 * change was confirmed.
 *
 * Like Tests\Service\FormAdminHttpTest this starts PHP's built-in server on
 * this checkout (Tests\Support\BuiltInServer), so it runs wherever the `cms`
 * suite runs. The forms and accounts are this test's own and are removed in
 * tearDown().
 */
final class FormFieldEditorHttpTest extends TestCase
{
    private const CREATE_ENDPOINT = '/api/admin/create-form-field.php';

    private const UPDATE_ENDPOINT = '/api/admin/update-form-field.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private FormRepository $forms;

    /** @var list<int> */
    private array $createdFormIds = [];

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
        $this->forms = new FormRepository();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFormIds as $id) {
            $this->forms->delete($id);
        }
        $this->createdFormIds = [];

        $this->accounts->forget();
        FormCatalog::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Veld toevoegen                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Every registered kind is offered, once, as a radio card that carries
     * its catalogue name and description, in registration order — and the
     * registry key is only ever the value that is sent, never the text an
     * editor reads.
     */
    public function testTheAddDialogOffersEveryTypeByItsCatalogueName(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $catalog = $this->catalog('nl');

        $xpath = $this->xpath($this->get($session, '/admin/form.php?id=' . $formId));
        $dialog = '//dialog[@id="form-field-add"]';

        $this->assertSame(1, $xpath->query($dialog)->length, 'one dialog');
        $this->assertFalse($xpath->query($dialog)->item(0)->hasAttribute('open'), 'closed until asked for');
        $form = $xpath->query($dialog . '//form')->item(0);
        $this->assertSame('post', $form?->getAttribute('method'), 'the dialog is an ordinary POST form');
        $this->assertSame(self::CREATE_ENDPOINT, $form?->getAttribute('action'));

        $radios = $xpath->query($dialog . '//fieldset//input[@type="radio"][@name="field_type"]');
        $offered = [];

        foreach ($radios as $radio) {
            $key = $radio->getAttribute('value');
            $offered[] = $key;

            $card = $xpath->query('ancestor::label[1]', $radio)->item(0);
            $this->assertNotNull($card, $key . ' is a whole clickable card');
            $this->assertTrue($radio->hasAttribute('required'), 'a kind must be chosen');
            $this->assertFalse($radio->hasAttribute('checked'), 'no kind is chosen for the editor');

            $name = trim($xpath->query('.//*[contains(@class, "admin-template-card__name")]', $card)->item(0)->textContent);
            $description = trim($xpath->query('.//*[contains(@class, "admin-template-card__desc")]', $card)->item(0)->textContent);

            $this->assertSame($catalog['formfieldtype.' . $key . '.label'], $name, $key . ' is named from the catalogue');
            $this->assertSame($catalog['formfieldtype.' . $key . '.description'], $description, $key . ' is described from the catalogue');
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($key, '/') . '\b/i', $card->textContent, $key . ': the registry key is not what the editor reads');
        }

        $this->assertSame(FormFieldTypes::keys(), $offered, 'every registered kind, once, in registration order');

        $label = $xpath->query($dialog . '//input[@name="label_nl"]')->item(0);
        $this->assertTrue($label?->hasAttribute('required'), 'and the label is asked for too');

        $opener = $xpath->query('//a[@data-form-field-add-open]')->item(0);
        $this->assertSame('/admin/form.php?id=' . $formId . '&add_field=1#form-field-add', $opener?->getAttribute('href'), 'the opener is a link that works without a script');
    }

    /** Without JavaScript the opener's link renders the very same dialog, open. */
    public function testTheOpenersLinkRendersTheDialogOpenWithoutAScript(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        $xpath = $this->xpath($this->get($session, '/admin/form.php?id=' . $formId . '&add_field=1'));

        $this->assertTrue($xpath->query('//dialog[@id="form-field-add"]')->item(0)->hasAttribute('open'));
        $this->assertSame('/admin/form.php?id=' . $formId, $xpath->query('//dialog//a[@data-form-field-add-close]')->item(0)?->getAttribute('href'), 'Annuleren is a way back without a script');
    }

    /**
     * The whole no-JavaScript add: one POST with a kind and a label creates
     * the field, generates its key from the label and opens its editor.
     */
    public function testAddingAFieldCreatesItAndOpensItsEditor(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        foreach (FormFieldTypes::keys() as $key) {
            $before = count($this->forms->fieldsFor($formId));

            $response = self::$server->request('POST', self::CREATE_ENDPOINT, $session, [
                'csrf_token' => $token,
                'form_id' => (string) $formId,
                'field_type' => $key,
                'label_nl' => 'Nieuw ' . $key,
            ]);

            $fields = $this->forms->fieldsFor($formId);
            $this->assertCount($before + 1, $fields, $key . ' was added');

            $field = end($fields);
            $this->assertSame($key, $field['field_type']);
            $this->assertSame('nieuw-' . $key, $field['field_key'], 'the key comes from the label');
            $this->assertSame('/admin/form-field.php?id=' . $field['id'], $response['location'], 'straight into the new field');
            $this->assertSame($key === 'consent' ? 1 : 0, (int) $field['is_required'], 'only consent starts required');

            $this->assertSame(200, self::$server->request('GET', $response['location'], $session)['status'], $key . ': its editor opens');
        }
    }

    /**
     * A kind nobody registered — a deferred type, a class name, nothing at
     * all — creates nothing, and the dialog comes back open with the reason
     * and with the label that was typed.
     */
    public function testAnUnknownTypeCreatesNothingAndReopensTheDialog(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        foreach (['file', 'hidden', 'TextFieldType', 'App\\Service\\Forms\\FieldTypes\\TextFieldType', 'Text', ' text', ''] as $attempt) {
            $response = self::$server->request('POST', self::CREATE_ENDPOINT, $session, [
                'csrf_token' => $token,
                'form_id' => (string) $formId,
                'field_type' => $attempt,
                'label_nl' => 'Bijlage',
            ]);

            $this->assertSame('/admin/form.php?id=' . $formId . '&add_field=1#form-field-add', $response['location'], var_export($attempt, true));
            $this->assertSame([], $this->forms->fieldsFor($formId), var_export($attempt, true) . ' created nothing');

            $xpath = $this->xpath($this->get($session, '/admin/form.php?id=' . $formId));
            $dialog = $xpath->query('//dialog[@id="form-field-add"]')->item(0);
            $this->assertTrue($dialog->hasAttribute('open'), 'the dialog is back');
            $this->assertStringContainsString($this->catalog('nl')['validation.kies_geldig_veldtype'], $dialog->textContent);
            $this->assertSame('Bijlage', $xpath->query('.//input[@name="label_nl"]', $dialog)->item(0)->getAttribute('value'), 'what was typed stays');
            $this->assertSame(0, $xpath->query('.//input[@name="field_type"][@checked]', $dialog)->length, 'nothing unregistered is pre-selected');
        }
    }

    /** Refused for a missing label, the chosen kind stays chosen. */
    public function testAMissingLabelKeepsTheChosenType(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        self::$server->request('POST', self::CREATE_ENDPOINT, $session, [
            'csrf_token' => $token,
            'form_id' => (string) $formId,
            'field_type' => 'radio',
            'label_nl' => '   ',
        ]);

        $this->assertSame([], $this->forms->fieldsFor($formId));

        $xpath = $this->xpath($this->get($session, '/admin/form.php?id=' . $formId));
        $this->assertSame('radio', $xpath->query('//dialog//input[@name="field_type"][@checked]')->item(0)?->getAttribute('value'));
    }

    /**
     * The list of fields says what each field is in words: its label, its
     * kind by catalogue name, how many options. The name a field is posted
     * under is not on the everyday screen.
     */
    public function testTheFieldListNamesTheKindAndNotThePostName(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $this->addField($formId, 'Uw voorkeur', 'radio', "Bellen\nMailen");
        $this->addField($formId, 'E-mailadres', 'email');

        $body = $this->get($session, '/admin/form.php?id=' . $formId);
        $catalog = $this->catalog('nl');

        $this->assertStringContainsString($catalog['formfieldtype.radio.label'], $body);
        $this->assertStringContainsString('2 opties', $body);
        $this->assertStringNotContainsString('uw-voorkeur', $body, 'the post name is not in the list');
        $this->assertStringNotContainsString('postnaam', strtolower($body));
    }

    /* ------------------------------------------------------------------ */
    /* The field editor: only what the type uses                           */
    /* ------------------------------------------------------------------ */

    /**
     * Per kind, exactly the settings it uses and nothing with a note that it
     * is ignored: a placeholder only on the four text-like kinds, option rows
     * and a default only on the two choice kinds, a required switch on every
     * kind but consent, and for consent a plain sentence instead of a
     * disabled checkbox.
     */
    public function testTheEditorShowsOnlyTheSettingsTheTypeUses(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $catalog = $this->catalog('nl');

        foreach (FormFieldTypes::all() as $key => $type) {
            $fieldId = $this->addField($formId, 'Veld ' . $key, $key, $type->usesOptions() ? "Een\nTwee" : null);

            $body = $this->get($session, '/admin/form-field.php?id=' . $fieldId);
            $xpath = $this->xpath($body);
            $form = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';

            $this->assertSame(1, $xpath->query($form . '//input[@name="label_nl"]')->length, $key . ': a label');
            $this->assertSame(1, $xpath->query($form . '//input[@name="help_text_nl"]')->length, $key . ': an explanation');
            $this->assertSame($type->usesPlaceholder() ? 1 : 0, $xpath->query($form . '//input[@name="placeholder_nl"]')->length, $key . ': a placeholder only where it is used');
            $this->assertSame($type->usesOptions(), $xpath->query($form . '//input[starts-with(@name, "option_nl[")]')->length > 0, $key . ': options only where they are used');
            $this->assertSame($type->usesDefaultValue(), $xpath->query($form . '//input[@type="radio"][@name="default_option"]')->length > 0, $key . ': a default only where it is used');
            $this->assertSame(0, $xpath->query($form . '//*[@name="options" or @name="default_value"]')->length, $key . ': no textarea of options and no typed default');

            $switch = $xpath->query($form . '//input[@type="checkbox"][@name="is_required"]');
            $this->assertSame($type->requiredIsFixed() ? 0 : 1, $switch->length, $key . ': a required switch unless required is fixed');
            $this->assertSame(0, $xpath->query($form . '//input[@name="is_required"][@disabled]')->length, $key . ': never a disabled checkbox');

            if ($type->requiredIsFixed()) {
                $this->assertStringContainsString($catalog['forms.akkoordvinkje_altijd_verplicht_akkoord'], $body, $key . ' says it is always required');
            } else {
                $this->assertSame('switch', $switch->item(0)->getAttribute('role'));
            }

            $this->assertSame($key, $xpath->query($form . '//input[@name="field_type"][@checked]')->item(0)?->getAttribute('value'), $key . ': its own card is chosen');
            $this->assertSame(0, $xpath->query($form . '//input[@name="confirmed_type"]')->length, $key . ': nothing to confirm');
            $this->assertStringNotContainsString('genegeerd', $body, $key . ': no setting explained away');
        }
    }

    /**
     * The name the field is posted under is on the field's own screen, under
     * Technische gegevens and outside the settings form, and nowhere a
     * request could change it.
     */
    public function testThePostNameIsUnderTechnicalDetailsAndNeverChanges(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Uw naam', 'text');

        $xpath = $this->xpath($this->get($session, '/admin/form-field.php?id=' . $fieldId));
        $technical = $xpath->query('//details[@data-form-field-technical]')->item(0);

        $this->assertNotNull($technical);
        $this->assertFalse($technical->hasAttribute('open'), 'folded away');
        $this->assertSame('uw-naam', trim($xpath->query('.//code', $technical)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]//*[@name="field_key"]')->length);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_key'] = 'iets-anders';
        $fields['label_nl'] = 'Je volledige naam';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location']);
        $this->assertSame('uw-naam', $this->forms->findField($fieldId)['field_key'], 'a new label, the same key');
        $this->assertSame('Je volledige naam', $this->forms->findField($fieldId)['label_nl']);
    }

    /**
     * Every kind of field, with every setting it can hold filled in — and a
     * dropdown carrying a placeholder from an earlier type, and an e-mail
     * field that is the form's Reply-To — goes back through the editor
     * untouched and comes out byte for byte the same.
     */
    public function testSendingTheEditorBackUntouchedChangesNothing(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm(['reply_to_field_key' => 'e-mail']);

        $filled = ['label_en' => 'In English', 'help_text_nl' => 'Uitleg', 'help_text_en' => 'Explanation', 'placeholder_nl' => 'Voorbeeld', 'placeholder_en' => 'Example', 'is_required' => true];

        $ids = [];
        foreach (FormFieldTypes::keys() as $key) {
            $ids[$key] = $this->addField($formId, $key === 'email' ? 'E-mail' : 'Veld ' . $key, $key, FormFieldTypes::get($key)->usesOptions() ? "Een\nTwee" : null, $filled);
        }
        $ids['radio-met-standaard'] = $this->addField($formId, 'Voorkeur', 'radio', "Bellen|Call\nMailen|Email\nLangskomen", ['default_value' => 'Mailen', 'placeholder_nl' => 'Van vroeger'] + $filled);
        $ids['select-zonder-standaard'] = $this->addField($formId, 'Aantal', 'select', "Een\nTwee|Two", ['is_required' => false]);
        $ids['optioneel'] = $this->addField($formId, 'Bedrijf', 'text');

        $formBefore = $this->forms->find($formId);

        foreach ($ids as $name => $fieldId) {
            $before = $this->withoutTimestamp($this->forms->findField($fieldId));

            [$fields] = $this->editorSubmission($session, $fieldId);
            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

            $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location'], $name . ' was saved');
            $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)), $name . ': nothing changed');
        }

        $this->assertSame($this->withoutTimestamp($formBefore), $this->withoutTimestamp($this->forms->find($formId)), 'and the form kept its Reply-To');
    }

    /* ------------------------------------------------------------------ */
    /* Options and their default, in one save                              */
    /* ------------------------------------------------------------------ */

    /**
     * A new dropdown has no options yet. Its editor offers empty rows, and
     * the options and the one that is the default are filled in and saved
     * together, in one request.
     */
    public function testOptionsAndTheirDefaultAreSavedInOneGo(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Dagdeel', 'select');

        [$fields, $xpath] = $this->editorSubmission($session, $fieldId);
        $rows = $xpath->query('//input[starts-with(@name, "option_nl[")]');
        $this->assertSame(3, $rows->length, 'three empty rows to fill in');
        $this->assertSame('', $fields['default_option'], 'no default yet');

        $fields = $this->withOptionRows($fields, [['Ochtend', 'Morning'], ['Middag', ''], ['Avond', 'Evening']]);
        $fields['default_option'] = '1';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location']);

        $row = $this->forms->findField($fieldId);
        $this->assertSame("Ochtend|Morning\nMiddag\nAvond|Evening", $row['options']);
        $this->assertSame('Middag', $row['default_value'], 'the default of the same save');

        FormCatalog::clearCache();
        $this->assertSame('Middag', FormCatalog::find($formId)->field('dagdeel')->defaultValue, 'and the form starts on it');

        [$again, $xpath] = $this->editorSubmission($session, $fieldId);
        $this->assertSame(6, $xpath->query('//input[starts-with(@name, "option_nl[")]')->length, 'three options and three empty rows');
        $this->assertSame('1', $again['default_option'], 'the marked row is the stored default');
    }

    /** An option renamed in the same save stays the default: the mark is on the row. */
    public function testARenamedOptionStaysTheDefault(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen", ['default_value' => 'Mailen']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $this->assertSame('1', $fields['default_option']);
        $fields['option_nl[1]'] = 'E-mailen';

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $row = $this->forms->findField($fieldId);
        $this->assertSame("Bellen\nE-mailen", $row['options']);
        $this->assertSame('E-mailen', $row['default_value']);
    }

    /**
     * Emptying the option that was the default removes that option and
     * leaves the field without a default — never with one that points at
     * nothing — and the editor says so. A default naming a row that was not
     * sent, or a typed default_value, is no default at all.
     */
    public function testRemovingTheDefaultOptionLeavesNoDefaultAndSaysSo(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen\nLangskomen", ['default_value' => 'Mailen']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['option_nl[1]'] = '';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location']);

        $row = $this->forms->findField($fieldId);
        $this->assertSame("Bellen\nLangskomen", $row['options']);
        $this->assertNull($row['default_value']);
        $this->assertStringContainsString($this->catalog('nl')['forms.default_dropped'], $this->get($session, $response['location']));

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['default_option'] = '99';
        $fields['default_value'] = 'Bellen';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertNull($this->forms->findField($fieldId)['default_value'], 'a row that was not sent, or a typed value, is no default');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['default_option'] = '0';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('Bellen', $this->forms->findField($fieldId)['default_value']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['default_option'] = '';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertNull($this->forms->findField($fieldId)['default_value'], 'and a default can be taken away again');
    }

    /**
     * The existing option rules, now on rows: a choice field without any
     * option is refused, as is a Dutch option holding the separator the
     * stored format splits on. Nothing is written, and what was typed comes
     * back.
     */
    public function testAChoiceFieldWithoutOptionsOrWithTheSeparatorIsRefused(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'select', "Bellen\nMailen");
        $before = $this->forms->findField($fieldId);
        $catalog = $this->catalog('nl');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['option_nl[0]'] = '';
        $fields['option_nl[1]'] = '  ';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->forms->findField($fieldId));
        $this->assertStringContainsString($catalog['validation.choice_field_needs_option'], $this->get($session, $response['location']));

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['option_nl[0]'] = 'Bellen|Call';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame($before, $this->forms->findField($fieldId));
        $body = $this->get($session, $response['location']);
        $this->assertStringContainsString($catalog['validation.form_option_separator'], $body);
        $this->assertStringContainsString('value="Bellen|Call"', $body, 'what was typed comes back');
    }

    /* ------------------------------------------------------------------ */
    /* Changing the type                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Changes that lose nothing and need nothing the screen did not show are
     * saved at once, with every setting both kinds use kept.
     */
    public function testALosslessTypeChangeIsSavedAtOnce(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        $choice = $this->addField($formId, 'Voorkeur', 'select', "Bellen\nMailen", ['default_value' => 'Mailen', 'is_required' => true]);
        $text = $this->addField($formId, 'Omschrijving', 'text', null, ['placeholder_nl' => 'Vertel het maar', 'help_text_nl' => 'Kort']);

        foreach ([[$choice, 'radio'], [$text, 'textarea'], [$text, 'tel'], [$text, 'email']] as [$fieldId, $to]) {
            $before = $this->withoutTimestamp($this->forms->findField($fieldId));

            [$fields] = $this->editorSubmission($session, $fieldId);
            $fields['field_type'] = $to;
            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

            $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location'], '-> ' . $to . ' is saved at once');
            $this->assertSame(array_replace($before, ['field_type' => $to]), $this->withoutTimestamp($this->forms->findField($fieldId)), '-> ' . $to . ': only the type changed');
        }
    }

    /**
     * A radio group becoming a text box would lose its options and its
     * default. The first save writes nothing and shows the text box's editor
     * with exactly that listed; only sending that screen again saves, and
     * then clears exactly those two settings.
     */
    public function testALossyTypeChangeWritesNothingUntilItIsConfirmed(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen", ['default_value' => 'Mailen', 'label_en' => 'Preference', 'is_required' => true]);
        $before = $this->forms->findField($fieldId);
        $catalog = $this->catalog('nl');

        [$fields, $xpath] = $this->editorSubmission($session, $fieldId);
        $textCard = $xpath->query('//input[@name="field_type"][@value="text"]/ancestor::label[1]')->item(0);
        $this->assertStringContainsString('de opties, de standaardkeuze', $textCard->textContent, 'the card says it in advance');

        $fields['field_type'] = 'text';
        $fields['label_nl'] = 'Hoe wil je contact?';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->forms->findField($fieldId), 'nothing was written, not even the new label');

        [$confirming, $xpath] = $this->editorSubmission($session, $fieldId);
        $card = $xpath->query('//section[contains(@class, "admin-type-change")]')->item(0);
        $this->assertNotNull($card, 'the change waits for confirmation');
        $this->assertStringContainsString('de 2 opties: Bellen, Mailen', $card->textContent);
        $this->assertStringContainsString('de standaardkeuze ‘Mailen’', $card->textContent);
        $this->assertSame('text', $confirming['confirmed_type']);
        $this->assertSame('text', $confirming['field_type'], 'the new type is the one on screen');
        $this->assertSame('Hoe wil je contact?', $confirming['label_nl'], 'what was typed is still there');
        $this->assertArrayHasKey('placeholder_nl', $confirming, 'the text box editor, with its placeholder');
        $this->assertSame(0, $xpath->query('//input[starts-with(@name, "option_nl[")]')->length, 'and without options');
        $this->assertStringContainsString($catalog['forms.type_change.submit_losing'], $card->textContent);

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location']);

        $after = $this->forms->findField($fieldId);
        $this->assertSame('text', $after['field_type']);
        $this->assertNull($after['options']);
        $this->assertNull($after['default_value']);
        $this->assertSame('Hoe wil je contact?', $after['label_nl']);
        $this->assertSame('Preference', $after['label_en'], 'what both types use is kept');
        $this->assertSame(1, (int) $after['is_required']);
        $this->assertSame($before['field_key'], $after['field_key']);
    }

    /** A confirmation is for the type it names, and only for that type. */
    public function testAConfirmationCountsOnlyForTheTypeItNamed(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen");
        $before = $this->forms->findField($fieldId);

        [$fields] = $this->editorSubmission($session, $fieldId);

        foreach ([['text', 'textarea'], ['checkbox', 'text'], ['text', ''], ['text', 'TEXT']] as [$to, $confirmed]) {
            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, ['field_type' => $to, 'confirmed_type' => $confirmed] + $fields);

            $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location'], $to . ' confirmed as ' . var_export($confirmed, true));
            $this->assertSame($before, $this->forms->findField($fieldId));
            $this->get($session, $response['location']);
        }
    }

    /**
     * An e-mail field that is the form's Reply-To would stop being it: that
     * too waits for confirmation, and is then cleared. An e-mail field that
     * is not the Reply-To changes kind at once.
     */
    public function testGivingUpTheReplyToIsConfirmedFirst(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm(['reply_to_field_key' => 'e-mail']);
        $replyTo = $this->addField($formId, 'E-mail', 'email');
        $other = $this->addField($formId, 'Tweede adres', 'email');

        [$fields] = $this->editorSubmission($session, $replyTo);
        $fields['field_type'] = 'text';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('email', $this->forms->findField($replyTo)['field_type']);
        $this->assertSame('e-mail', $this->forms->find($formId)['reply_to_field_key']);

        [$confirming, $xpath] = $this->editorSubmission($session, $replyTo);
        $this->assertStringContainsString('antwoordadres van de melding', $xpath->query('//section[contains(@class, "admin-type-change")]')->item(0)->textContent);

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);
        $this->assertSame('text', $this->forms->findField($replyTo)['field_type']);
        $this->assertNull($this->forms->find($formId)['reply_to_field_key']);

        [$fields] = $this->editorSubmission($session, $other);
        $fields['field_type'] = 'tel';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $other . '&saved=1', $response['location']);
        $this->assertSame('tel', $this->forms->findField($other)['field_type']);
    }

    /**
     * A text box becoming a dropdown loses nothing, but needs options the
     * text box's screen never showed: the first save writes nothing and
     * shows the dropdown's editor, where the options and the default are
     * filled in and saved together.
     */
    public function testATypeThatNeedsNewSettingsShowsThemBeforeSaving(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Dagdeel', 'text');
        $before = $this->forms->findField($fieldId);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_type'] = 'select';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->forms->findField($fieldId));

        [$confirming, $xpath] = $this->editorSubmission($session, $fieldId);
        $card = $xpath->query('//section[contains(@class, "admin-type-change")]')->item(0);
        $this->assertStringContainsString($this->catalog('nl')['forms.type_change.loses_nothing'], $card->textContent);
        $this->assertSame(3, $xpath->query('//input[starts-with(@name, "option_nl[")]')->length);
        $this->assertArrayNotHasKey('placeholder_nl', $confirming, 'a dropdown has no placeholder');

        $confirming = $this->withOptionRows($confirming, [['Ochtend', ''], ['Middag', '']]);
        $confirming['default_option'] = '0';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId . '&saved=1', $response['location']);
        $after = $this->forms->findField($fieldId);
        $this->assertSame(['select', "Ochtend\nMiddag", 'Ochtend'], [$after['field_type'], $after['options'], $after['default_value']]);
    }

    /**
     * Consent is always required. A consent box that becomes an ordinary
     * checkbox stays required until the editor switches that off on the
     * checkbox's own screen, where the switch is shown first.
     */
    public function testLeavingConsentKeepsTheFieldRequired(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Akkoord', 'consent');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $this->assertArrayNotHasKey('is_required', $fields, 'consent sends no required setting');
        $fields['field_type'] = 'checkbox';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('consent', $this->forms->findField($fieldId)['field_type'], 'not yet');

        [$confirming] = $this->editorSubmission($session, $fieldId);
        $this->assertSame('1', $confirming['is_required'], 'the switch is shown, on');

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);
        $after = $this->forms->findField($fieldId);
        $this->assertSame(['checkbox', 1], [$after['field_type'], (int) $after['is_required']]);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['is_required'] = '0';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame(0, (int) $this->forms->findField($fieldId)['is_required'], 'and can then be made optional');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_type'] = 'consent';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $after = $this->forms->findField($fieldId);
        $this->assertSame(['consent', 1], [$after['field_type'], (int) $after['is_required']], 'back to consent: required again, at once');
    }

    /** Only a registered type can be saved; anything else writes nothing. */
    public function testAnUnknownTypeIsRefusedOnSave(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Naam', 'text');
        $before = $this->forms->findField($fieldId);

        foreach (['file', 'TextFieldType', ''] as $attempt) {
            [$fields] = $this->editorSubmission($session, $fieldId);
            $fields['field_type'] = $attempt;
            $fields['confirmed_type'] = $attempt;

            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

            $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
            $this->assertSame($before, $this->forms->findField($fieldId), var_export($attempt, true));
            $this->assertStringContainsString($this->catalog('nl')['validation.kies_geldig_veldtype'], $this->get($session, $response['location']));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * What a browser sends when the field editor's save button is pressed:
     * every named control of the form that posts to the update endpoint, in
     * document order, a later control of the same name replacing an earlier
     * one as PHP does (so the switch after its hidden 0 wins when it is on).
     * Hidden language panes are sent too; a checkbox or radio only when it
     * is checked.
     *
     * @return array{0: array<string, string>, 1: \DOMXPath}
     */
    private function editorSubmission(string $session, int $fieldId): array
    {
        $xpath = $this->xpath($this->get($session, '/admin/form-field.php?id=' . $fieldId));
        $form = $xpath->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]');
        $this->assertSame(1, $form->length, 'one form saves the field');

        $sent = [];

        foreach ($xpath->query('.//*[self::input or self::textarea or self::select][@name]', $form->item(0)) as $control) {
            $name = $control->getAttribute('name');

            if ($control->nodeName === 'textarea') {
                $sent[$name] = $control->textContent;
                continue;
            }

            if ($control->nodeName === 'select') {
                $chosen = $xpath->query('.//option[@selected]', $control)->item(0) ?? $xpath->query('.//option', $control)->item(0);
                $sent[$name] = $chosen === null ? '' : $chosen->getAttribute('value');
                continue;
            }

            $type = strtolower($control->getAttribute('type'));
            if ($control->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) {
                continue;
            }

            $sent[$name] = $control->hasAttribute('value') ? $control->getAttribute('value') : 'on';
        }

        return [$sent, $xpath];
    }

    /**
     * The editor's option rows replaced by these [nl, en] pairs, in order,
     * with indexes 0, 1, 2 ...
     *
     * @param array<string, string>     $fields
     * @param list<array{0: string, 1: string}> $rows
     * @return array<string, string>
     */
    private function withOptionRows(array $fields, array $rows): array
    {
        foreach (array_keys($fields) as $name) {
            if (str_starts_with($name, 'option_nl[') || str_starts_with($name, 'option_en[')) {
                unset($fields[$name]);
            }
        }

        foreach ($rows as $index => [$nl, $en]) {
            $fields['option_nl[' . $index . ']'] = $nl;
            $fields['option_en[' . $index . ']'] = $en;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function withoutTimestamp(?array $row): array
    {
        $this->assertNotNull($row);
        unset($row['updated_at']);

        return $row;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createForm(array $overrides = []): int
    {
        $id = $this->forms->create($overrides + [
            'name' => 'Veldeditor test',
            'internal_key' => FormCatalog::internalKeyFor('zz test veldeditor', $this->forms),
            'is_active' => true,
            'submit_label_nl' => 'Verstuur',
            'submit_label_en' => null,
            'success_message_nl' => 'Bedankt.',
            'success_message_en' => null,
            'notification_email' => 'veldeditor@example.com',
            'reply_to_field_key' => null,
            'store_submissions' => true,
        ]);

        $this->createdFormIds[] = $id;

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addField(int $formId, string $label, string $type, ?string $options = null, array $overrides = []): int
    {
        $taken = array_map(static fn (array $row): string => (string) $row['field_key'], $this->forms->fieldsFor($formId));

        $id = $this->forms->createField($formId, $overrides + [
            'field_key' => FormFieldKey::fromLabel($label, $taken),
            'field_type' => $type,
            'label_nl' => $label,
            'label_en' => null,
            'placeholder_nl' => null,
            'placeholder_en' => null,
            'help_text_nl' => null,
            'help_text_en' => null,
            'is_required' => $type === 'consent',
            'options' => $options,
            'default_value' => null,
        ]);

        FormCatalog::clearCache();

        return $id;
    }

    private function get(string $session, string $path): string
    {
        $response = self::$server->request('GET', $path, $session);
        $this->assertSame(200, $response['status'], $path . ' opens');

        return $response['body'];
    }

    /** @return array<string, string> */
    private function catalog(string $language): array
    {
        return require dirname(__DIR__, 2) . '/src/Service/Language/messages/' . $language . '.php';
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
}
