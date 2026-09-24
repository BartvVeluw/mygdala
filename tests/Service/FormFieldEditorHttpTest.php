<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormFieldTypes;
use App\Service\Forms\FormFieldWidth;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormLocalization;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FormFixture;

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
 * change was confirmed. A type card is named by its type alone, the save
 * bar watches the settings and nothing else, and options keep their value,
 * their translation and their default wherever a row is moved to. Deleting a
 * field is Tests\Service\FormAdminHttpTest, with the other deletions.
 *
 * ONE WEBSITE LANGUAGE per save since Multilingual 2.0 phase 4: the words
 * are written in the language named by `language_code`, an option keeps the
 * value it was created with whatever its label becomes, and a new option is
 * written in the default language.
 *
 * Like Tests\Service\FormAdminHttpTest this starts PHP's built-in server on
 * this checkout (Tests\Support\BuiltInServer), so it runs wherever the `cms`
 * suite runs. The forms, the page and the accounts are this test's own and
 * are removed in tearDown().
 */
final class FormFieldEditorHttpTest extends TestCase
{
    /** A published page no editor has, for what a visitor sees. */
    private const TEST_PAGE = 'zz-formulier-veldvolgorde-test';

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

        $this->removeTestPage();
        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        $this->removeTestPage();

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

        $label = $xpath->query($dialog . '//input[@name="label"]')->item(0);
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
                'label' => 'Nieuw ' . $key,
            ]);

            $fields = $this->forms->fieldsFor($formId);
            $this->assertCount($before + 1, $fields, $key . ' was added');

            $field = end($fields);
            $this->assertSame($key, $field['field_type']);
            $this->assertSame('nieuw-' . $key, $field['field_key'], 'the key comes from the label');
            $this->assertSame('/admin/form-field.php?id=' . $field['id'], $response['location'], 'straight into the new field');
            $this->assertSame($key === 'consent' ? 1 : 0, (int) $field['is_required'], 'only consent starts required');
            FormLocalization::clearCache();
            $this->assertSame(
                ['nl' => ['label' => 'Nieuw ' . $key]],
                FormLocalization::fields()->words((int) $field['id']),
                $key . ': the label is written in the default language and nowhere else'
            );

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

        foreach (['upload', 'hidden', 'TextFieldType', 'App\\Service\\Forms\\FieldTypes\\TextFieldType', 'Text', ' text', 'FILE', ''] as $attempt) {
            $response = self::$server->request('POST', self::CREATE_ENDPOINT, $session, [
                'csrf_token' => $token,
                'form_id' => (string) $formId,
                'field_type' => $attempt,
                'label' => 'Bijlage',
            ]);

            $this->assertSame('/admin/form.php?id=' . $formId . '&add_field=1#form-field-add', $response['location'], var_export($attempt, true));
            $this->assertSame([], $this->forms->fieldsFor($formId), var_export($attempt, true) . ' created nothing');

            $xpath = $this->xpath($this->get($session, '/admin/form.php?id=' . $formId));
            $dialog = $xpath->query('//dialog[@id="form-field-add"]')->item(0);
            $this->assertTrue($dialog->hasAttribute('open'), 'the dialog is back');
            $this->assertStringContainsString($this->catalog('nl')['validation.kies_geldig_veldtype'], $dialog->textContent);
            $this->assertSame('Bijlage', $xpath->query('.//input[@name="label"]', $dialog)->item(0)->getAttribute('value'), 'what was typed stays');
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
            'label' => '   ',
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

            $this->assertSame(1, $xpath->query($form . '//input[@name="label"]')->length, $key . ': a label');
            $this->assertSame(1, $xpath->query($form . '//input[@name="help_text"]')->length, $key . ': an explanation');
            $this->assertSame($type->usesPlaceholder() ? 1 : 0, $xpath->query($form . '//input[@name="placeholder"]')->length, $key . ': a placeholder only where it is used');
            $this->assertSame($type->usesOptions(), $xpath->query($form . '//input[starts-with(@name, "option_label[")]')->length > 0, $key . ': options only where they are used');
            $this->assertSame(1, $xpath->query($form . '//input[@name="language_code"]')->length, $key . ': the language its words are saved in');
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
        $fields['label'] = 'Je volledige naam';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame($this->savedAt($fieldId), $response['location']);
        $this->assertSame('uw-naam', $this->forms->findField($fieldId)['field_key'], 'a new label, the same key');
        $this->assertSame('Je volledige naam', $this->fieldWords($fieldId, 'nl')['label']);
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
            $beforeWords = [$this->fieldWords($fieldId, 'nl'), $this->fieldWords($fieldId, 'en')];
            $beforeOptions = [$this->optionValues($fieldId), $this->optionLabels($fieldId, 'nl'), $this->optionLabels($fieldId, 'en')];

            [$fields] = $this->editorSubmission($session, $fieldId);
            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

            $this->assertSame($this->savedAt($fieldId), $response['location'], $name . ' was saved');
            $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)), $name . ': nothing changed');
            $this->assertSame($beforeWords, [$this->fieldWords($fieldId, 'nl'), $this->fieldWords($fieldId, 'en')], $name . ': and no word of any language');
            $this->assertSame($beforeOptions, [$this->optionValues($fieldId), $this->optionLabels($fieldId, 'nl'), $this->optionLabels($fieldId, 'en')], $name . ': nor an option');
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
        $rows = $xpath->query('//input[starts-with(@name, "option_label[")]');
        $this->assertSame(3, $rows->length, 'three empty rows to fill in');
        $this->assertSame('', $fields['default_option'], 'no default yet');

        $fields = $this->withOptionRows($fields, [['Ochtend', ''], ['Middag', ''], ['Avond', '']]);
        $fields['default_option'] = '1';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame($this->savedAt($fieldId), $response['location']);

        $this->assertSame(['Ochtend', 'Middag', 'Avond'], $this->optionValues($fieldId), 'a new option takes its label as its value');
        $this->assertSame(['Ochtend', 'Middag', 'Avond'], $this->optionLabels($fieldId, 'nl'));
        $this->assertSame(['', '', ''], $this->optionLabels($fieldId, 'en'), 'and is written in the default language only');
        $this->assertSame('Middag', $this->forms->findField($fieldId)['default_value'], 'the default of the same save');

        FormCatalog::clearCache();
        $this->assertSame('Middag', FormCatalog::find($formId)->field('dagdeel')->defaultValue, 'and the form starts on it');

        [$again, $xpath] = $this->editorSubmission($session, $fieldId);
        $this->assertSame(6, $xpath->query('//input[starts-with(@name, "option_label[")]')->length, 'three options and three empty rows');
        $this->assertSame('1', $again['default_option'], 'the marked row is the stored default');
    }

    /**
     * Renaming an option changes its LABEL and nothing else: its value, what
     * a visitor posts and what a submission stores, stays what it was, and
     * the mark on that row keeps the field's default pointing at it.
     */
    public function testARenamedOptionKeepsItsValueAndStaysTheDefault(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen", ['default_value' => 'Mailen']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $this->assertSame('1', $fields['default_option']);
        $fields['option_label[1]'] = 'E-mailen';

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame(['Bellen', 'Mailen'], $this->optionValues($fieldId), 'the identity of an option never changes');
        $this->assertSame(['Bellen', 'E-mailen'], $this->optionLabels($fieldId, 'nl'), 'only what a visitor reads');
        $this->assertSame('Mailen', $this->forms->findField($fieldId)['default_value']);

        FormCatalog::clearCache();
        $option = FormCatalog::find($formId)->field('voorkeur')->options->find('Mailen');
        $this->assertNotNull($option);
        $this->assertSame('E-mailen', $option->label, 'the public form shows the new label under the old value');
    }

    /**
     * Translating an option is a save of that language alone: the value, the
     * default and the other languages' labels stay as they are, and a new
     * option added from a translation's screen is written in the DEFAULT
     * language, like a new field.
     */
    public function testTranslatingAnOptionTouchesNoValueAndNoOtherLanguage(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen", ['default_value' => 'Mailen']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['language_code'] = 'en';
        $fields['label'] = 'Preference';
        $fields = $this->withOptionRows($fields, [
            ['Call', $fields['option_id[0]']],
            ['Email', $fields['option_id[1]']],
            ['Visit', ''],
        ]);
        $fields['default_option'] = '1';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame($this->savedAt($fieldId), $response['location']);
        $this->assertSame(['Bellen', 'Mailen', 'Visit'], $this->optionValues($fieldId), 'a new option is its own value; the others keep theirs');
        $this->assertSame(['Bellen', 'Mailen', 'Visit'], $this->optionLabels($fieldId, 'nl'), 'the default language is untouched, and the new option is written there');
        $this->assertSame(['Call', 'Email', ''], $this->optionLabels($fieldId, 'en'));
        $this->assertSame('Mailen', $this->forms->findField($fieldId)['default_value']);
        $this->assertSame(['label' => 'Voorkeur'], $this->fieldWords($fieldId, 'nl'), 'the Dutch label stayed');
        $this->assertSame(['label' => 'Preference'], $this->fieldWords($fieldId, 'en'));
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
        $fields['option_label[1]'] = '';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame($this->savedAt($fieldId), $response['location']);

        $this->assertSame(['Bellen', 'Langskomen'], $this->optionValues($fieldId));
        $this->assertNull($this->forms->findField($fieldId)['default_value']);
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
     * A choice field without any option is refused and nothing is written.
     * A label holding a `|` is now ordinary text: the stored format has no
     * separator any more, because an option's value and its labels are
     * columns of their own.
     */
    public function testAChoiceFieldWithoutOptionsIsRefusedAndAPipeIsJustText(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'select', "Bellen\nMailen");
        $before = $this->optionValues($fieldId);
        $catalog = $this->catalog('nl');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['option_label[0]'] = '';
        $fields['option_label[1]'] = '  ';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->optionValues($fieldId), 'nothing was written');
        $this->assertStringContainsString($catalog['validation.choice_field_needs_option'], $this->get($session, $response['location']));

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['option_label[0]'] = 'Bellen|Call';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame($this->savedAt($fieldId), $response['location']);
        $this->assertSame($before, $this->optionValues($fieldId), 'the value it was created with stays');
        $this->assertSame(['Bellen|Call', 'Mailen'], $this->optionLabels($fieldId, 'nl'), 'and the pipe is part of the label');
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
            $beforeOptions = [$this->optionValues($fieldId), $this->optionLabels($fieldId, 'nl')];

            [$fields] = $this->editorSubmission($session, $fieldId);
            $fields['field_type'] = $to;
            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

            $this->assertSame($this->savedAt($fieldId), $response['location'], '-> ' . $to . ' is saved at once');
            $this->assertSame(array_replace($before, ['field_type' => $to]), $this->withoutTimestamp($this->forms->findField($fieldId)), '-> ' . $to . ': only the type changed');
            $this->assertSame($beforeOptions, [$this->optionValues($fieldId), $this->optionLabels($fieldId, 'nl')], '-> ' . $to . ': its options too');
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
        $fields['label'] = 'Hoe wil je contact?';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->forms->findField($fieldId), 'nothing was written, not even the new label');
        $this->assertSame(['label' => 'Voorkeur'], $this->fieldWords($fieldId, 'nl'), 'and no word of it either');

        [$confirming, $xpath] = $this->editorSubmission($session, $fieldId);
        $card = $xpath->query('//section[contains(@class, "admin-type-change")]')->item(0);
        $this->assertNotNull($card, 'the change waits for confirmation');
        $this->assertStringContainsString('de 2 opties: Bellen, Mailen', $card->textContent);
        $this->assertStringContainsString('de standaardkeuze ‘Mailen’', $card->textContent);
        $this->assertSame('text', $confirming['confirmed_type']);
        $this->assertSame('text', $confirming['field_type'], 'the new type is the one on screen');
        $this->assertSame('Hoe wil je contact?', $confirming['label'], 'what was typed is still there');
        $this->assertArrayHasKey('placeholder', $confirming, 'the text box editor, with its placeholder');
        $this->assertSame(0, $xpath->query('//input[starts-with(@name, "option_label[")]')->length, 'and without options');
        $this->assertStringContainsString($catalog['forms.type_change.submit_losing'], $card->textContent);

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);
        $this->assertSame($this->savedAt($fieldId), $response['location']);

        $after = $this->forms->findField($fieldId);
        $this->assertSame('text', $after['field_type']);
        $this->assertSame([], $this->optionValues($fieldId), 'the options are gone, labels and all');
        $this->assertNull($after['default_value']);
        $this->assertSame('Hoe wil je contact?', $this->fieldWords($fieldId, 'nl')['label']);
        $this->assertSame('Preference', $this->fieldWords($fieldId, 'en')['label'], 'what both types use is kept, in every language');
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
        $this->assertSame($this->savedAt($other), $response['location']);
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
        $this->assertSame(3, $xpath->query('//input[starts-with(@name, "option_label[")]')->length);
        $this->assertArrayNotHasKey('placeholder', $confirming, 'a dropdown has no placeholder');

        $confirming = $this->withOptionRows($confirming, [['Ochtend', ''], ['Middag', '']]);
        $confirming['default_option'] = '0';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirming);

        $this->assertSame($this->savedAt($fieldId), $response['location']);
        $after = $this->forms->findField($fieldId);
        $this->assertSame(['select', 'Ochtend'], [$after['field_type'], $after['default_value']]);
        $this->assertSame(['Ochtend', 'Middag'], $this->optionValues($fieldId));
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

        foreach (['upload', 'TextFieldType', ''] as $attempt) {
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
    /* What a screen reader hears on a type card                           */
    /* ------------------------------------------------------------------ */

    /**
     * A type card is a radio inside a <label> around the whole card, which
     * would make every word on the card the radio's name. The radio is named
     * by its type alone (aria-labelledby) and hears the rest as its
     * description (aria-describedby) — in the add dialog and in the field
     * editor alike. The card is still one click target, the eight radios are
     * still one group the arrow keys move through, and which one is selected
     * is still the radio's own state.
     */
    public function testATypeCardIsNamedByItsTypeAloneAndDescribedByTheRest(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen");
        $catalog = $this->catalog('nl');

        $screens = [
            'add dialog' => ['/admin/form.php?id=' . $formId, '//dialog[@id="form-field-add"]', null],
            'field editor' => ['/admin/form-field.php?id=' . $fieldId, '//form[@action="' . self::UPDATE_ENDPOINT . '"]', 'radio'],
        ];

        foreach ($screens as $screen => [$path, $scope, $current]) {
            $xpath = $this->xpath($this->get($session, $path));
            $radios = $xpath->query($scope . '//input[@type="radio"][@name="field_type"]');
            $this->assertSame(count(FormFieldTypes::keys()), $radios->length, $screen);

            $checked = [];

            foreach ($radios as $radio) {
                $key = $radio->getAttribute('value');
                $where = $screen . ', ' . $key;
                $card = $xpath->query('ancestor::label[1]', $radio)->item(0);
                $this->assertNotNull($card, $where . ': the whole card is still the click target');

                $this->assertSame(
                    $catalog['formfieldtype.' . $key . '.label'],
                    $this->textOfIds($xpath, $radio->getAttribute('aria-labelledby'), $card, $where),
                    $where . ': named by the type alone'
                );

                $description = $this->textOfIds($xpath, $radio->getAttribute('aria-describedby'), $card, $where);
                $explanation = $catalog['formfieldtype.' . $key . '.description'];

                if ($current === null) {
                    $this->assertSame($explanation, $description, $where . ': the explanation is the description');
                } else {
                    // The editor's cards also say what choosing them costs.
                    $note = trim($xpath->query('.//*[contains(@class, "admin-template-card__note")]', $card)->item(0)->textContent);
                    $this->assertSame($explanation . ' ' . $note, $description, $where . ': explanation and note are the description');
                }

                $this->assertFalse($radio->hasAttribute('disabled'), $where);
                $this->assertFalse($radio->hasAttribute('tabindex'), $where . ': reachable like any radio');

                if ($radio->hasAttribute('checked')) {
                    $checked[] = $key;
                }
            }

            $this->assertSame($current === null ? [] : [$current], $checked, $screen . ': the selected type is the checked radio');
        }
    }

    /* ------------------------------------------------------------------ */
    /* The save bar                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The field editor carries the CMS's save bar, which watches every POST
     * form inside <main> that holds something to edit (admin/_save_bar.php).
     * That is the settings form and nothing else: the delete form only
     * carries hidden fields, "Technische gegevens" is no form at all, and the
     * language switch sits outside <main>. A fresh screen starts saved; a
     * successful save lands on the marker the bar reads as saved.
     */
    public function testTheSaveBarWatchesTheSettingsFormAndNothingElse(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'select', "Bellen\nMailen");

        $html = $this->get($session, '/admin/form-field.php?id=' . $fieldId);
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//*[@data-save-bar]')->length, 'one save bar');
        $this->assertSame(1, $xpath->query('//script[contains(@src, "/admin/assets/save-bar.js")]')->length, 'and its script');

        $editable = './/*[(self::input and not(@type="hidden") and not(@type="submit") and not(@type="button")) or self::select or self::textarea]';
        $watched = [];

        foreach ($xpath->query('//main[contains(@class, "admin-main")]//form[@method="post"]') as $form) {
            $isWatched = !str_contains(' ' . $form->getAttribute('class') . ' ', ' admin-inline-form ')
                && !$form->hasAttribute('data-no-dirty-track')
                && $xpath->query($editable, $form)->length > 0;

            if ($isWatched) {
                $watched[] = $form->getAttribute('action');
            }
        }

        $this->assertSame([self::UPDATE_ENDPOINT], $watched, 'only the settings form can make the screen unsaved');
        $this->assertSame(0, $xpath->query('//details[@data-form-field-technical]/ancestor::form')->length, 'opening Technische gegevens is no edit');
        $this->assertSame(0, $xpath->query('//main//form[@action="/api/admin/update-content-language.php"]')->length, 'switching language is no edit of this field');
        $this->assertFalse($xpath->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]')->item(0)->hasAttribute('data-save-bar-unsaved'), 'a fresh screen starts saved');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['label'] = 'Hoe wil je contact?';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        // The save bar reads Response.url, which never carries the fragment
        // that points at the field's row (admin/assets/save-bar.js).
        $this->assertSame($this->savedAt($fieldId), $response['location'], 'back to the form, at the field');
        $this->assertMatchesRegularExpression('/[?&](saved|updated|created)=1(&|$)/', strtok($response['location'], '#'), 'the success marker the save bar reads');
        $this->assertFalse($this->xpath($this->get($session, '/admin/form-field.php?id=' . $fieldId))->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]')->item(0)->hasAttribute('data-save-bar-unsaved'), 'saved is saved');
    }

    /**
     * Input that came back unwritten is not saved, and the bar must not say
     * it is: after a refused save, and while a type change waits for
     * confirmation, the settings form starts out unsaved. Cancelling that
     * change is a link that throws the input away on purpose, so the bar lets
     * it go without the browser asking a second time.
     */
    public function testInputThatCameBackUnwrittenStartsOutUnsaved(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voorkeur', 'radio', "Bellen\nMailen", ['default_value' => 'Mailen']);
        $settings = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['label'] = '';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location'], 'refused');
        $this->assertTrue($this->xpath($this->get($session, $response['location']))->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), 'a refused save is unsaved');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_type'] = 'text';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location'], 'waiting for confirmation');

        $xpath = $this->xpath($this->get($session, $response['location']));
        $this->assertTrue($xpath->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), 'a change waiting for confirmation is unsaved');

        $cancel = $xpath->query('//section[contains(@class, "admin-type-change")]//a[@data-save-bar-discard]');
        $this->assertSame(1, $cancel->length, 'Annuleren throws the input away on purpose');
        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $cancel->item(0)->getAttribute('href'));

        $this->assertStringNotContainsString('data-save-bar-unsaved', $this->get($session, '/admin/form-field.php?id=' . $fieldId), 'and once that is followed, the stored field is saved');
    }

    /* ------------------------------------------------------------------ */
    /* Breedte, en terug naar het formulier                                */
    /* ------------------------------------------------------------------ */

    /**
     * Every kind of field offers the same six widths, by their catalogue
     * names and in the order of App\Service\Forms\FormFieldWidth, with the
     * stored one chosen and a real label. A new field is full width.
     */
    /* ------------------------------------------------------------------ */
    /* Bestand uploaden (Forms 2.0 phase 2)                                */
    /* ------------------------------------------------------------------ */

    /**
     * Added like any field, through "Veld toevoegen". It starts optional,
     * with the plain kinds and a modest size, and its editor shows the
     * "Bestanden" card: a checkbox per kind of the closed list, a select of
     * the sizes this installation takes, no free MIME text and no file input.
     */
    public function testAnUploadFieldIsAddedWithSafeSettingsAndItsOwnCard(): void
    {
        [$session, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        $response = self::$server->request('POST', self::CREATE_ENDPOINT, $session, [
            'csrf_token' => $token,
            'form_id' => (string) $formId,
            'field_type' => 'file',
            'label' => 'Bijlage',
        ]);

        [$field] = $this->forms->fieldsFor($formId);
        $this->assertSame('file', $field['field_type']);
        $this->assertSame('bijlage', $field['field_key']);
        $this->assertSame(0, (int) $field['is_required']);
        $this->assertSame('jpg,png,pdf', $field['file_types']);
        $this->assertSame(FormFileTypes::DEFAULT_MAX_BYTES, (int) $field['file_max_bytes']);
        $this->assertSame('/admin/form-field.php?id=' . $field['id'], $response['location']);

        $body = $this->get($session, $response['location']);
        $xpath = $this->xpath($body);
        $form = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';

        $kinds = [];
        $checked = [];
        foreach ($xpath->query($form . '//input[@type="checkbox"][@name="file_types[]"]') as $box) {
            $kinds[] = $box->getAttribute('value');
            $this->assertStringContainsString('admin-checkbox', $box->getAttribute('class'));
            if ($box->hasAttribute('checked')) {
                $checked[] = $box->getAttribute('value');
            }
        }
        $this->assertSame(FormFileTypes::keys(), $kinds);
        $this->assertSame(['jpg', 'png', 'pdf'], $checked);

        $sizes = [];
        foreach ($xpath->query($form . '//select[@name="file_max_bytes"]/option') as $option) {
            $sizes[] = (int) $option->getAttribute('value');
        }
        $this->assertSame(FormFileTypes::sizeChoices(), $sizes);
        $this->assertSame((string) FormFileTypes::DEFAULT_MAX_BYTES, $xpath->query($form . '//select[@name="file_max_bytes"]/option[@selected]')->item(0)?->getAttribute('value'));

        $this->assertSame(0, $xpath->query($form . '//input[@name="placeholder"]')->length, 'no placeholder for a file');
        $this->assertSame(0, $xpath->query('//input[@type="file"]')->length, 'the editor uploads nothing');
        $this->assertSame(0, $xpath->query($form . '//input[@type="text"][contains(@name, "mime") or contains(@name, "file_types")]')->length, 'no free MIME text');
        $this->assertSame(1, $xpath->query($form . '//input[@type="checkbox"][@name="is_required"]')->length, 'required is a choice');

        $list = $this->get($session, '/admin/form.php?id=' . $formId);
        $this->assertStringContainsString('JPG, PNG, PDF · max. 5 MB', $list, 'the field row says what it accepts');
    }

    /** The kinds and the size come from the closed lists, and only from them. */
    public function testUploadSettingsAreSavedFromTheClosedListsOnly(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Tekening', 'file');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['file_types'] = ['pdf', 'png'];
        $fields['file_max_bytes'] = (string) (2 * 1024 * 1024);
        $fields['layout_width'] = 'third';

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame($this->savedAt($fieldId), $response['location']);

        $saved = $this->forms->findField($fieldId);
        $this->assertSame('png,pdf', $saved['file_types'], 'in the list\'s own order');
        $this->assertSame(2 * 1024 * 1024, (int) $saved['file_max_bytes']);
        $this->assertSame('third', $saved['layout_width']);

        $before = $this->withoutTimestamp($this->forms->findField($fieldId));
        $typesMessage = $this->catalog('nl')['validation.file_types_required'];
        $sizeMessage = $this->catalog('nl')['validation.file_size_unknown'];

        $refused = [
            'no kind' => [[], null, $typesMessage],
            'svg' => [['svg'], null, $typesMessage],
            'a kind and something else' => [['jpg', 'exe'], null, $typesMessage],
            'a MIME string' => [['image/png'], null, $typesMessage],
            'a size not on the list' => [['pdf'], (string) (3 * 1024 * 1024), $sizeMessage],
            'above the ceiling' => [['pdf'], (string) (FormFileTypes::MAX_BYTES * 2), $sizeMessage],
            'no number' => [['pdf'], '5MB', $sizeMessage],
            'negative' => [['pdf'], '-1', $sizeMessage],
        ];

        foreach ($refused as $label => [$types, $size, $message]) {
            [$fields] = $this->editorSubmission($session, $fieldId);
            $fields['file_types'] = $types;
            if ($types === []) {
                unset($fields['file_types']);
            }
            if ($size !== null) {
                $fields['file_max_bytes'] = $size;
            }

            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
            $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location'], $label);
            $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)), $label . ' writes nothing');
            $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $this->get($session, $response['location']), $label);
        }
    }

    /**
     * Into an upload field: the Bestanden card is shown first, with what
     * will be lost, and only the confirmed save writes — kinds and size
     * included, the placeholder cleared.
     */
    public function testATextFieldBecomesAnUploadFieldOnlyAfterSeeingItsCard(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Tekening', 'text', null, ['placeholder_nl' => 'Link naar je ontwerp']);
        $before = $this->withoutTimestamp($this->forms->findField($fieldId));

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_type'] = 'file';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)), 'nothing yet');

        [$confirm, $xpath] = $this->editorSubmission($session, $fieldId);
        $this->assertSame('file', $confirm['confirmed_type']);
        $this->assertSame(['jpg', 'png', 'pdf'], $confirm['file_types'], 'the card, with the safe kinds ticked');
        $this->assertStringContainsString('Link naar je ontwerp', $xpath->query('//*[contains(@class, "admin-type-change__losses")]')->item(0)?->textContent ?? '');

        $confirm['file_types'] = ['pdf'];
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirm);

        $this->assertSame($this->savedAt($fieldId), $response['location']);
        $saved = $this->forms->findField($fieldId);
        $this->assertSame('file', $saved['field_type']);
        $this->assertSame('pdf', $saved['file_types']);
        $this->assertSame(FormFileTypes::DEFAULT_MAX_BYTES, (int) $saved['file_max_bytes']);
        $this->assertSame('', (string) ($this->fieldWords($fieldId, 'nl')['placeholder'] ?? ''), 'the confirmed loss');
    }

    /** Out of an upload field: its kinds and size go, and only when confirmed. */
    public function testAnUploadFieldGivesUpItsSettingsOnlyWhenConfirmed(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Bijlage', 'file');
        $before = $this->withoutTimestamp($this->forms->findField($fieldId));

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['field_type'] = 'text';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)));

        [$confirm, $xpath] = $this->editorSubmission($session, $fieldId);
        $losses = $xpath->query('//*[contains(@class, "admin-type-change__losses")]')->item(0)?->textContent ?? '';
        $this->assertStringContainsString('de toegestane bestanden (JPG, PNG, PDF) en de maximale grootte (5 MB)', $losses);
        $this->assertArrayNotHasKey('file_types', $confirm, 'a text field has no Bestanden card');

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $confirm);
        $saved = $this->forms->findField($fieldId);
        $this->assertSame('text', $saved['field_type']);
        $this->assertNull($saved['file_types']);
        $this->assertNull($saved['file_max_bytes']);
    }

    public function testEveryEditorOffersTheSixWidthsWithTheStoredOneChosen(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $catalog = $this->catalog('nl');

        foreach (array_keys(FormFieldTypes::all()) as $index => $type) {
            $width = FormFieldWidth::keys()[$index % 6];
            $options = FormFieldTypes::get($type)->usesOptions() ? "Ja\nNee" : null;
            $fieldId = $this->addField($formId, 'Breedte ' . $type, $type, $options, ['layout_width' => $width]);

            $xpath = $this->xpath($this->get($session, '/admin/form-field.php?id=' . $fieldId));
            $select = $xpath->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]//select[@name="layout_width"]');
            $this->assertSame(1, $select->length, $type . ' has the width');

            $values = [];
            $labels = [];
            foreach ($xpath->query('.//option', $select->item(0)) as $option) {
                $values[] = $option->getAttribute('value');
                $labels[] = trim($option->textContent);
            }

            $this->assertSame(FormFieldWidth::keys(), $values, $type);
            $this->assertSame(array_map(static fn (string $key): string => $catalog['forms.width.option.' . $key], FormFieldWidth::keys()), $labels, $type);

            $chosen = [];
            foreach ($xpath->query('.//option[@selected]', $select->item(0)) as $option) {
                $chosen[] = $option->getAttribute('value');
            }
            $this->assertSame([$width], $chosen, $type . ' shows its stored width');

            $label = $xpath->query('//label[@for="' . $select->item(0)->getAttribute('id') . '"]');
            $this->assertSame(1, $label->length, 'the select has a real label');
        }

        $this->assertSame('full', $this->forms->findField($this->addField($formId, 'Nieuw', 'text'))['layout_width'], 'a new field is full width');
    }

    /**
     * A width is saved like any other setting, from any website language,
     * and it is the same in all of them; a request that leaves it out keeps
     * the stored one.
     */
    public function testTheWidthIsSavedOnceForEveryLanguage(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Voornaam', 'text', null, ['label_en' => 'First name']);

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['layout_width'] = 'third';
        $this->assertSame($this->savedAt($fieldId), self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields)['location']);
        $this->assertSame('third', $this->forms->findField($fieldId)['layout_width']);

        $fields['language_code'] = 'en';
        $fields['label'] = 'Given name';
        $fields['layout_width'] = 'quarter';
        $this->assertSame($this->savedAt($fieldId), self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields)['location']);
        $this->assertSame('quarter', $this->forms->findField($fieldId)['layout_width'], 'one width, whichever language saved it');
        $this->assertSame('Voornaam', $this->fieldWords($fieldId, 'nl')['label'], 'and the other language kept its words');
        $this->assertSame('Given name', $this->fieldWords($fieldId, 'en')['label']);

        unset($fields['layout_width']);
        $fields['language_code'] = 'nl';
        $fields['label'] = 'Voornaam';
        $this->assertSame($this->savedAt($fieldId), self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields)['location']);
        $this->assertSame('quarter', $this->forms->findField($fieldId)['layout_width'], 'a width that was not sent stays as it is');
    }

    /**
     * Anything that is not one of the six keys is refused: nothing is
     * written, the editor comes back with the error and with what was
     * typed, and no value from the request ever becomes a class or a style.
     */
    public function testAWidthOutsideTheListIsRefusedAndNothingIsWritten(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $fieldId = $this->addField($formId, 'Postcode', 'text', null, ['layout_width' => 'two_thirds']);
        $before = $this->withoutTimestamp($this->forms->findField($fieldId));
        $message = htmlspecialchars($this->catalog('nl')['validation.field_width_unknown'], ENT_QUOTES, 'UTF-8');

        $refused = ['', '50%', '6', 'HALF', 'form-field--half', 'half" onclick="alert(1)', 'half; grid-column: 1 / 13', 'half' . chr(0)];

        foreach ($refused as $value) {
            [$fields] = $this->editorSubmission($session, $fieldId);
            $fields['label'] = 'Postcode (nieuw)';
            $fields['layout_width'] = $value;

            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
            $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location'], json_encode($value) . ' goes back to the editor');
            $this->assertSame($before, $this->withoutTimestamp($this->forms->findField($fieldId)), json_encode($value) . ' writes nothing');
            $this->assertSame('Postcode', $this->fieldWords($fieldId, 'nl')['label'], 'not even the label');

            $html = $this->get($session, $response['location']);
            $this->assertStringContainsString($message, $html);
            $typed = $this->xpath($html)->query('//form[@action="' . self::UPDATE_ENDPOINT . '"]//input[@name="label"]')->item(0);
            $this->assertSame('Postcode (nieuw)', $typed->getAttribute('value'), 'what was typed is still there');
            $this->assertStringNotContainsString('onclick="alert', $html);
        }

        // An array instead of a string is no width either.
        [$fields] = $this->editorSubmission($session, $fieldId);
        unset($fields['layout_width']);
        $fields['layout_width[]'] = 'half';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertSame('/admin/form-field.php?id=' . $fieldId, $response['location']);
        $this->assertSame('two_thirds', $this->forms->findField($fieldId)['layout_width']);
    }

    /**
     * A save that went through goes back to the form the field belongs to,
     * at the field's row, and names the field once. The address is the
     * field's own form whatever the request says, and another form never
     * names a field that is not its own.
     */
    public function testASavedFieldGoesBackToItsFormAtItsRow(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $otherForm = $this->createForm(['name' => 'Ander formulier', 'internal_key' => FormCatalog::internalKeyFor('zz test ander', $this->forms)]);
        $this->addField($formId, 'Naam', 'text');
        $fieldId = $this->addField($formId, 'Achternaam', 'text');
        $message = htmlspecialchars(str_replace(':field', 'Achternaam', $this->catalog('nl')['forms.field_saved']), ENT_QUOTES, 'UTF-8');

        [$fields] = $this->editorSubmission($session, $fieldId);
        $fields['form_id'] = (string) $otherForm;
        $fields['return'] = 'https://example.com/elders';
        $fields['redirect'] = '//example.com';
        $fields['layout_width'] = 'half';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame('/admin/form.php?id=' . $formId . '&saved=1#form-field-' . $fieldId, $response['location'], 'its own form, whatever the request says');

        $html = $this->get($session, $response['location']);
        $this->assertStringContainsString($message, $html, 'the field is named');
        $this->assertSame(1, $this->xpath($html)->query('//*[@id="form-field-' . $fieldId . '"]')->length, 'and its row is the anchor');

        $this->assertStringNotContainsString($message, $this->get($session, $response['location']), 'once');

        [$fields] = $this->editorSubmission($session, $fieldId);
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);
        $this->assertStringNotContainsString($message, $this->get($session, '/admin/form.php?id=' . $otherForm . '&saved=1'), 'another form does not name it');
    }

    /* ------------------------------------------------------------------ */
    /* The order of the options                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Rows moved in the browser arrive in their new order, each still with its
     * own id. The options are stored in that order, each keeping its value
     * and every language's label, the default is still the option that was
     * marked, the editor reopens in that order, and the public form shows the
     * options in that order. The move buttons are the script's: without it
     * they stay hidden and the rows are saved as they stand.
     */
    public function testMovedOptionRowsKeepTheirTranslationAndTheirDefaultEverywhere(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $radio = $this->addField($formId, 'Voorkeur', 'radio', "Bellen|Call\nMailen|Email\nLangskomen|Visit", ['default_value' => 'Mailen']);
        $select = $this->addField($formId, 'Dagdeel', 'select', "Ochtend|Morning\nMiddag|Afternoon\nAvond|Evening");
        $catalog = $this->catalog('nl');

        [$fields, $xpath] = $this->editorSubmission($session, $radio);

        $rows = $xpath->query('//*[@data-form-option-row]');
        $this->assertSame(6, $rows->length, 'three options and three empty rows');

        foreach ($rows as $position => $row) {
            $group = $xpath->query('.//*[@data-form-option-move-group]', $row)->item(0);
            $this->assertNotNull($group, 'row ' . ($position + 1) . ' has move buttons');
            $this->assertTrue($group->hasAttribute('hidden'), 'which the script reveals');

            $labels = [];
            foreach ($xpath->query('.//button[@data-form-option-move]', $group) as $button) {
                $this->assertSame('button', $button->getAttribute('type'), 'moving never submits');
                $labels[$button->getAttribute('data-form-option-move')] = $button->getAttribute('aria-label');
            }

            $this->assertSame([
                'up' => strtr($catalog['forms.option.move_up_label'], [':n' => (string) ($position + 1)]),
                'down' => strtr($catalog['forms.option.move_down_label'], [':n' => (string) ($position + 1)]),
            ], $labels);
        }

        $this->assertSame(1, $xpath->query('//*[@data-form-option-status][@role="status"]')->length, 'a status line says where a row went');

        // Langskomen moved to the top, Mailen to the bottom.
        $fields = $this->withRowsInOrder($fields, [2, 0, 1, 3, 4, 5]);
        $this->assertSame('1', $fields['default_option'], 'the mark stays on Mailen\'s row');
        $this->assertSame($this->savedAt($radio), self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields)['location']);

        [$fields] = $this->editorSubmission($session, $select);
        $fields = $this->withRowsInOrder($fields, [2, 1, 0, 3, 4, 5]);
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $fields);

        $this->assertSame(['Langskomen', 'Bellen', 'Mailen'], $this->optionValues($radio), 'stored in the new order');
        $this->assertSame(['Visit', 'Call', 'Email'], $this->optionLabels($radio, 'en'), 'each option still with its translation');
        $this->assertSame('Mailen', $this->forms->findField($radio)['default_value'], 'and the same option is the default');
        $this->assertSame(['Avond', 'Middag', 'Ochtend'], $this->optionValues($select));

        [$again, $xpath] = $this->editorSubmission($session, $radio);
        $this->assertSame(['Langskomen', 'Bellen', 'Mailen'], [$again['option_label[0]'], $again['option_label[1]'], $again['option_label[2]']], 'the editor reopens in that order');
        $this->assertSame('2', $again['default_option'], 'with the mark on Mailen, now the third row');

        $public = $this->xpath($this->publicPageWith($formId));
        $this->assertSame(['Langskomen', 'Bellen', 'Mailen'], $this->values($public->query('//form[@data-form-block]//input[@type="radio"]')), 'the public form follows');
        $this->assertSame(['Mailen'], $this->values($public->query('//form[@data-form-block]//input[@type="radio"][@checked]')), 'and starts on the same option');
        $this->assertSame(['Avond', 'Middag', 'Ochtend'], $this->values($public->query('//form[@data-form-block]//select/option[@value != ""]')));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The words the elements named by an IDREF list hold, joined by a space,
     * as a browser builds a name or a description from them. Every id must
     * name exactly one element on the page, inside the card it describes.
     */
    private function textOfIds(\DOMXPath $xpath, string $ids, \DOMNode $card, string $where): string
    {
        $parts = preg_split('/\s+/', trim($ids), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertNotSame([], $parts, $where . ': refers to something');

        $texts = [];
        foreach ($parts as $id) {
            $this->assertSame(1, $xpath->query('//*[@id="' . $id . '"]')->length, $where . ': id ' . $id . ' is unique');
            $this->assertSame(1, $xpath->query('.//*[@id="' . $id . '"]', $card)->length, $where . ': id ' . $id . ' is on this card');
            $texts[] = trim($xpath->query('//*[@id="' . $id . '"]')->item(0)->textContent);
        }

        return implode(' ', $texts);
    }

    /**
     * The option rows of an editor submission as a browser sends them after
     * rows were moved: in this order of their indexes, each label still with
     * its own id. The rest of the submission is untouched.
     *
     * @param array<string, string> $fields
     * @param list<int>             $order
     * @return array<string, string>
     */
    private function withRowsInOrder(array $fields, array $order): array
    {
        $rows = [];
        foreach ($order as $index) {
            $rows['option_id[' . $index . ']'] = $fields['option_id[' . $index . ']'];
            $rows['option_label[' . $index . ']'] = $fields['option_label[' . $index . ']'];
        }

        foreach (array_keys($fields) as $name) {
            if (str_starts_with($name, 'option_id[') || str_starts_with($name, 'option_label[')) {
                unset($fields[$name]);
            }
        }

        return $fields + $rows;
    }

    /** @return list<string> the stored option values, in order */
    private function optionValues(int $fieldId): array
    {
        FormLocalization::clearCache();

        return array_map(
            static fn (array $option): string => (string) $option['value'],
            (new \App\Repository\FormFieldOptionRepository())->findForFields([$fieldId])[$fieldId] ?? []
        );
    }

    /** @return list<string> the stored option labels in one language, in order ('' when untranslated) */
    private function optionLabels(int $fieldId, string $language): array
    {
        FormLocalization::clearCache();
        [$field] = FormLocalization::attachFieldWords([['id' => $fieldId]]);

        return array_map(
            static fn (array $option): string => (string) ($option['labels'][$language] ?? ''),
            $field['choices']
        );
    }

    /** The words of a field in one language, as an editor sees them. */
    private function fieldWords(int $fieldId, string $language): array
    {
        FormLocalization::clearCache();

        return FormLocalization::fields()->words($fieldId)[$language] ?? [];
    }

    /** @return list<string> */
    private function values(\DOMNodeList $nodes): array
    {
        $values = [];
        foreach ($nodes as $node) {
            $values[] = $node->getAttribute('value');
        }

        return $values;
    }

    /** This form on a published page of its own, as a visitor gets it. */
    private function publicPageWith(int $formId): string
    {
        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'published',
        ], 'Veldvolgorde testpagina');

        [$sectionId, $sectionKey] = SectionRegistry::create('form', self::TEST_PAGE);
        (new PageSectionRepository())->create($pageId, self::TEST_PAGE, 'form', $sectionKey, $sectionId);
        (new FormBlockRepository())->upsertSection(self::TEST_PAGE, (string) $sectionKey, ['form_id' => $formId, 'is_active' => true]);
        FormCatalog::clearCache();

        $response = self::$server->request('GET', '/pagina.php?slug=' . self::TEST_PAGE);
        $this->assertSame(200, $response['status'], 'the page renders');

        return $response['body'];
    }

    private function removeTestPage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::TEST_PAGE);

        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }

        Database::connection()
            ->prepare('DELETE FROM pages WHERE id = :id')
            ->execute(['id' => (int) $page['id']]);
    }

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

            $value = $control->hasAttribute('value') ? $control->getAttribute('value') : 'on';

            // A group of checkboxes named `name[]` (an upload field's kinds)
            // is sent as the list a browser sends.
            if (str_ends_with($name, '[]')) {
                $sent[substr($name, 0, -2)][] = $value;
                continue;
            }

            $sent[$name] = $value;
        }

        return [$sent, $xpath];
    }

    /**
     * The editor's option rows replaced by these [label, id] pairs, in order,
     * with indexes 0, 1, 2 ... An empty id is a new option.
     *
     * @param array<string, string>     $fields
     * @param list<array{0: string, 1: string}> $rows
     * @return array<string, string>
     */
    private function withOptionRows(array $fields, array $rows): array
    {
        foreach (array_keys($fields) as $name) {
            if (str_starts_with($name, 'option_id[') || str_starts_with($name, 'option_label[')) {
                unset($fields[$name]);
            }
        }

        foreach ($rows as $index => [$label, $id]) {
            $fields['option_id[' . $index . ']'] = $id;
            $fields['option_label[' . $index . ']'] = $label;
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
            'notification_email' => 'veldeditor@example.com',
            'reply_to_field_key' => null,
            'store_submissions' => true,
        ]);

        $this->createdFormIds[] = $id;
        FormFixture::formWords($id, ['nl' => ['submit_label' => 'Verstuur', 'success_message' => 'Bedankt.']]);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addField(int $formId, string $label, string $type, ?string $options = null, array $overrides = []): int
    {
        $taken = array_map(static fn (array $row): string => (string) $row['field_key'], $this->forms->fieldsFor($formId));

        $words = [
            'nl' => array_filter([
                'label' => $label,
                'help_text' => $overrides['help_text_nl'] ?? null,
                'placeholder' => $overrides['placeholder_nl'] ?? null,
            ]),
            'en' => array_filter([
                'label' => $overrides['label_en'] ?? null,
                'help_text' => $overrides['help_text_en'] ?? null,
                'placeholder' => $overrides['placeholder_en'] ?? null,
            ]),
        ];

        foreach (['label_en', 'help_text_nl', 'help_text_en', 'placeholder_nl', 'placeholder_en'] as $moved) {
            unset($overrides[$moved]);
        }

        $id = FormFixture::field($formId, $overrides + [
            'field_key' => FormFieldKey::fromLabel($label, $taken),
            'field_type' => $type,
            'is_required' => $type === 'consent',
            'default_value' => null,
            // What api/admin/create-form-field.php gives a new upload field.
            'file_types' => FormFieldTypes::get($type)?->acceptsFile() ? FormFileTypes::DEFAULT_TYPES : null,
            'file_max_bytes' => FormFieldTypes::get($type)?->acceptsFile() ? FormFileTypes::DEFAULT_MAX_BYTES : null,
        ], array_filter($words), $options);

        FormCatalog::clearCache();

        return $id;
    }

    /**
     * Where a save that went through lands: the field's own form, at its row
     * (api/admin/update-form-field.php).
     */
    private function savedAt(int $fieldId): string
    {
        $formId = (int) $this->forms->findField($fieldId)['form_id'];

        return '/admin/form.php?id=' . $formId . '&saved=1#form-field-' . $fieldId;
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
