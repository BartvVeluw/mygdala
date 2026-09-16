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
 * what a field is in words, not the name it is posted under.
 *
 * Like Tests\Service\FormAdminHttpTest this starts PHP's built-in server on
 * this checkout (Tests\Support\BuiltInServer), so it runs wherever the `cms`
 * suite runs. The forms and accounts are this test's own and are removed in
 * tearDown().
 */
final class FormFieldEditorHttpTest extends TestCase
{
    private const CREATE_ENDPOINT = '/api/admin/create-form-field.php';

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
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function createForm(): int
    {
        $id = $this->forms->create([
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
