<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\ContactFormRepository;
use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminPermissions;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSpamGuard;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FormFixture;

/**
 * A form switched on and off, over real HTTP: what the public endpoint
 * accepts, what a page that carries the form shows, and what the switch in
 * the form editor does and does not touch.
 *
 * THE CONTRACT (FORMS.md, "Actief en uit"): an active form may be used by
 * the public; an inactive one renders no form anywhere and the endpoint
 * itself refuses it, so hiding a form in the page is never the only thing
 * standing between a visitor and a stored submission. Switching off keeps
 * the fields, their options and defaults, the placements and every stored
 * submission; switching back on restores the form as it was.
 *
 * Tests\Service\FormRenderingTest proves part of this against php_test and
 * skips wherever that container does not run. This class starts PHP's
 * built-in server on this checkout instead (Tests\Support\BuiltInServer), the
 * way Tests\Service\PageHeroEditorHttpTest does, so the contract is checked
 * on every machine that can run the `cms` suite. The form, the page, the
 * submissions and the accounts are this test's own and are removed again in
 * tearDown().
 */
final class FormAdminHttpTest extends TestCase
{
    /** A published page no editor has, so a visitor's view of it can be read. */
    private const TEST_PAGE = 'zz-formulier-status-test';

    private const SUBMIT_ENDPOINT = '/api/form-submit.php';

    private const UPDATE_ENDPOINT = '/api/admin/update-form.php';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private FormRepository $forms;

    /** @var list<int> */
    private array $createdFormIds = [];

    public static function setUpBeforeClass(): void
    {
        // No notification leaves this test: nothing listens on this port, so
        // sending fails at once. A form that stores its submissions accepts
        // them regardless (App\Service\Forms\FormSubmissionHandler stores
        // first and mails on a best-effort basis), which is what these forms
        // do.
        self::$server = BuiltInServer::start(['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9']);
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
        $this->clearRateLimit();
        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        $this->removeTestPage();

        $submissions = new FormSubmissionRepository();
        foreach ($this->createdFormIds as $id) {
            foreach ($submissions->findAllForAdmin($id) as $submission) {
                $submissions->delete((int) $submission['id']);
            }
            $this->forms->delete($id);
        }
        $this->createdFormIds = [];

        $this->accounts->forget();
        $this->clearRateLimit();
        FormCatalog::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The public endpoint                                                 */
    /* ------------------------------------------------------------------ */

    public function testAnActiveFormAcceptsAValidSubmission(): void
    {
        $formId = $this->createForm();

        $response = $this->submit($formId);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertTrue(json_decode($response['body'], true)['ok'] ?? null);
        $this->assertCount(1, (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    /**
     * Posted straight at the endpoint, past any page: the refusal is the
     * endpoint's own, and it is exactly the answer a form key that does not
     * exist gets — a public endpoint does not confirm which forms are off.
     */
    public function testAnInactiveFormIsRefusedByTheEndpointItselfAndStoresNothing(): void
    {
        $formId = $this->createForm(['is_active' => false]);

        $json = $this->submit($formId);
        $unknown = $this->submit($formId, ['form-key' => 'zz-bestaat-niet']);

        $this->assertSame(400, $json['status']);
        $this->assertFalse(json_decode($json['body'], true)['ok'] ?? null);
        $this->assertSame($unknown['body'], $json['body'], 'an inactive form is refused like one that does not exist');

        $browser = $this->submit($formId, [], false);

        $this->assertSame(303, $browser['status'], 'a browser without JavaScript is sent back, not shown an error page');
        $this->assertStringStartsWith('/' . self::TEST_PAGE, $browser['location']);
        $this->assertStringContainsString('form-status=error', $browser['location']);

        $this->assertSame([], (new FormSubmissionRepository())->findAllForAdmin($formId), 'nothing is stored');
        $this->assertSame(0, $this->rateLimitHits(), 'a refused form does not use up a visitor\'s attempts');
    }

    public function testSwitchingTheFormBackOnMakesTheSameSubmissionWorkAgain(): void
    {
        $formId = $this->createForm();

        $this->setActive($formId, false);
        $this->assertSame(400, $this->submit($formId)['status']);

        $this->setActive($formId, true);
        $response = $this->submit($formId);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertCount(1, (new FormSubmissionRepository())->findAllForAdmin($formId));
    }

    /* ------------------------------------------------------------------ */
    /* The switch in the form editor                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The editor sent back exactly as a browser sends it, with only the
     * switch changed. Everything else about the form — its settings, its
     * fields with their options and default, and what people already sent —
     * is the same afterwards, both ways.
     */
    public function testTheEditorSwitchTurnsTheFormOffAndOnAndTouchesNothingElse(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $this->storeSubmissions($formId, 2);

        $formBefore = $this->forms->find($formId);
        $fieldsBefore = $this->forms->fieldsFor($formId);
        $submissionsBefore = $this->submissionSnapshot($formId);

        $editor = $this->editorSubmission($session, $formId);
        $this->assertSame('1', $editor['is_active'] ?? null, 'an active form opens with the switch on');
        unset($editor['is_active']);

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);
        $this->assertSame('/admin/form.php?id=' . $formId . '&saved=1', $response['location'], 'the save went through');

        $off = $this->forms->find($formId);
        $this->assertSame(0, (int) $off['is_active']);
        $this->assertSame($this->withoutStatus($formBefore), $this->withoutStatus($off), 'only the status changed');
        $this->assertSame($fieldsBefore, $this->forms->fieldsFor($formId), 'fields, options and defaults are untouched');
        $this->assertSame($submissionsBefore, $this->submissionSnapshot($formId), 'stored submissions stay');
        $this->assertSame(400, $this->submit($formId)['status'], 'and the endpoint now refuses it');

        $editor = $this->editorSubmission($session, $formId);
        $this->assertArrayNotHasKey('is_active', $editor, 'an inactive form opens with the switch off');
        $editor['is_active'] = '1';

        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);

        $on = $this->forms->find($formId);
        $this->assertSame(1, (int) $on['is_active']);
        $this->assertSame($this->withoutStatus($formBefore), $this->withoutStatus($on));
        $this->assertSame($fieldsBefore, $this->forms->fieldsFor($formId));
        $this->assertSame($submissionsBefore, $this->submissionSnapshot($formId));
        $this->assertSame(200, $this->submit($formId)['status'], 'switched back on, it accepts again');
    }

    /**
     * The four guards of every admin write endpoint, in their order, and
     * none of them writes: a refused switch leaves the row as it was.
     */
    public function testTheSwitchStillNeedsASignedInFormsManagerAndAValidToken(): void
    {
        $formId = $this->createForm();
        $before = $this->forms->find($formId);

        [$manager, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        [$pageEditor, $pageEditorToken] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $fields = ['id' => (string) $formId, 'name' => 'Uitgezet', 'store_submissions' => '1'];

        $this->assertSame(401, self::$server->request('POST', self::UPDATE_ENDPOINT, null, $fields + ['csrf_token' => $token])['status'], 'signed out');
        $this->assertSame(403, self::$server->request('POST', self::UPDATE_ENDPOINT, $pageEditor, $fields + ['csrf_token' => $pageEditorToken])['status'], 'managing pages is not managing forms');
        $this->assertSame(405, self::$server->request('GET', self::UPDATE_ENDPOINT . '?id=' . $formId, $manager)['status'], 'only a POST');
        $this->assertSame(403, self::$server->request('POST', self::UPDATE_ENDPOINT, $manager, $fields + ['csrf_token' => str_repeat('0', 64)])['status'], 'a wrong token');
        $this->assertSame(403, self::$server->request('POST', self::UPDATE_ENDPOINT, $manager, $fields)['status'], 'no token');

        $this->assertSame($before, $this->forms->find($formId), 'nothing was written');
    }

    /* ------------------------------------------------------------------ */
    /* A page that carries the form                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Both blocks that can place a form, on one published page. Off: no form
     * and no form key anywhere in the page, the page itself and the contact
     * block's heading and details card still there, and the page builder
     * saying why. On again: both forms are back.
     */
    public function testAPlacedInactiveFormShowsNoFormWhileThePageAndItsBlocksStay(): void
    {
        $formId = $this->createForm();
        $key = (string) $this->forms->find($formId)['internal_key'];
        $sections = $this->placeBothBlocks($formId);
        $marker = 'name="form-key" value="' . $key . '"';

        $active = $this->publicPage();
        $this->assertSame(2, substr_count($active, $marker), 'both placements render the form');

        $this->setActive($formId, false);

        $inactive = $this->publicPage();
        $this->assertStringNotContainsString($marker, $inactive, 'no usable form anywhere on the page');
        $this->assertStringNotContainsString('data-form-block', $inactive);
        $this->assertStringContainsString('Formulierstatus testpagina', $inactive, 'the page itself renders');
        $this->assertStringContainsString('Neem contact op', $inactive, 'the contact block keeps its heading');
        $this->assertStringContainsString('Direct contact', $inactive, 'and its details card');

        foreach ($sections as $section) {
            $this->assertStringEndsWith('(staat uit)', SectionRegistry::instanceLabel($section), 'the page builder says the form is off');
        }

        $this->setActive($formId, true);

        $this->assertSame(2, substr_count($this->publicPage(), $marker), 'switched back on, both placements show it again');
    }

    /* ------------------------------------------------------------------ */
    /* The editor screen                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Status first, the everyday settings open, and storing plus the reply
     * address folded under Geavanceerd — with its state readable from the
     * closed card.
     */
    public function testTheEditorShowsTheStatusFirstAndFoldsStoringAndTheReplyAddressAway(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        $xpath = $this->editorXpath($session, $formId);
        $form = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';
        $advanced = $form . '//details[@data-form-advanced]';

        $firstControl = $xpath->query($form . '//*[self::input or self::select or self::textarea][@name and not(@type="hidden")]')->item(0);
        $this->assertSame('is_active', $firstControl?->getAttribute('name'), 'whether the form is on comes first');
        $this->assertSame('switch', $firstControl?->getAttribute('role'));

        $this->assertSame(1, $xpath->query($advanced)->length, 'one Geavanceerd card inside the settings form');
        $this->assertFalse($xpath->query($advanced)->item(0)->hasAttribute('open'), 'folded away by default');
        $this->assertSame('switch', $xpath->query($advanced . '//input[@name="store_submissions"]')->item(0)?->getAttribute('role'));
        $this->assertSame(1, $xpath->query($advanced . '//select[@name="reply_to_field_key"]')->length);
        $this->assertSame('e-mail', $xpath->query($advanced . '//select[@name="reply_to_field_key"]/option[@selected]')->item(0)?->getAttribute('value'));

        foreach (['is_active', 'name', 'submit_label', 'success_message', 'notification_email'] as $everyday) {
            $this->assertSame(0, $xpath->query($advanced . '//*[@name="' . $everyday . '"]')->length, $everyday . ' stays on the main screen');
        }

        $this->assertStringContainsString('Inzendingen worden bewaard', $xpath->query($advanced . '/summary')->item(0)->textContent);
    }

    /**
     * Moving storing and the reply address under Geavanceerd changed where
     * they are, not what they hold: the editor sent back untouched stores
     * exactly what was there, with storing on and with storing off.
     */
    public function testSendingTheEditorBackUntouchedKeepsEveryStoredValue(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);

        foreach ([true, false] as $stores) {
            $formId = $this->createForm(['store_submissions' => $stores]);
            $before = $this->forms->find($formId);

            $editor = $this->editorSubmission($session, $formId);
            $this->assertSame($stores, isset($editor['store_submissions']), 'the switch opens as stored');

            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);
            $this->assertSame('/admin/form.php?id=' . $formId . '&saved=1', $response['location']);

            $after = $this->forms->find($formId);
            unset($before['updated_at'], $after['updated_at']);
            $this->assertSame($before, $after, 'storing ' . ($stores ? 'on' : 'off') . ': nothing changed');
        }
    }

    public function testStoringCanBeSwitchedOffAndBackOnWithoutLosingWhatWasStored(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $this->storeSubmissions($formId, 2);
        $submissions = $this->submissionSnapshot($formId);

        $editor = $this->editorSubmission($session, $formId);
        unset($editor['store_submissions']);
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);

        $this->assertSame(0, (int) $this->forms->find($formId)['store_submissions']);
        $this->assertSame($submissions, $this->submissionSnapshot($formId), 'switching storing off deletes nothing');

        $xpath = $this->editorXpath($session, $formId);
        $advanced = '//details[@data-form-advanced]';
        $this->assertStringContainsString('Inzendingen worden niet bewaard', $xpath->query($advanced . '/summary')->item(0)->textContent);
        $this->assertStringContainsString('Er staan nog 2 bewaarde inzendingen', $xpath->query($advanced)->item(0)->textContent, 'and says the old ones are still there');

        $editor = $this->editorSubmission($session, $formId);
        $editor['store_submissions'] = '1';
        self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);

        $this->assertSame(1, (int) $this->forms->find($formId)['store_submissions']);
        $this->assertSame($submissions, $this->submissionSnapshot($formId));
    }

    /** After a refused save the editor shows everything that was sent, folded card included. */
    public function testARefusedSaveOpensGeavanceerdAndKeepsWhatWasSent(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $before = $this->forms->find($formId);

        $editor = $this->editorSubmission($session, $formId);
        $editor['notification_email'] = 'geen adres';
        unset($editor['store_submissions']);

        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);
        $this->assertSame('/admin/form.php?id=' . $formId, $response['location'], 'refused, back to the editor');
        $this->assertSame($before, $this->forms->find($formId), 'nothing was written');

        $xpath = $this->editorXpath($session, $formId);
        $advanced = $xpath->query('//details[@data-form-advanced]')->item(0);

        $this->assertTrue($advanced->hasAttribute('open'));
        $this->assertFalse($xpath->query('.//input[@name="store_submissions"]', $advanced)->item(0)->hasAttribute('checked'), 'the switch shows what was sent');
    }

    /* ------------------------------------------------------------------ */
    /* The overview                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * One row per form: its status in words, its field count, and its stored
     * submissions — also once storing is off, because they are still there.
     * Only somebody who may read submissions gets a link to them.
     */
    public function testTheOverviewShowsStatusFieldsAndStoredSubmissions(): void
    {
        [$reader] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE, AdminPermissions::FORMS_SUBMISSIONS]);
        [$builder] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $this->storeSubmissions($formId, 3);

        $row = $this->overviewRow($reader, $formId);
        $this->assertStringContainsString('Actief', $row['status']);
        $this->assertSame('4', $row['fields']);
        $this->assertSame('3', trim($row['submissions']));
        $this->assertTrue($row['submissions_linked']);
        $this->assertTrue($row['edit_linked']);
        $this->assertFalse($row['deletable'], 'stored submissions keep the form');

        $stored = $this->forms->find($formId);
        $stored['is_active'] = false;
        $stored['store_submissions'] = false;
        $this->forms->update($formId, $stored);

        $row = $this->overviewRow($reader, $formId);
        $this->assertStringContainsString('Inactief', $row['status']);
        $this->assertStringContainsString('3', $row['submissions'], 'still there after storing was switched off');
        $this->assertStringContainsString('bewaren staat uit', $row['submissions']);

        $this->assertFalse($this->overviewRow($builder, $formId)['submissions_linked'], 'a count, but no way in without the permission');
    }

    /* ------------------------------------------------------------------ */
    /* Deleting a field, a form or a submission                            */
    /* ------------------------------------------------------------------ */

    /**
     * Every deletion in Forms asks in the CMS's own dialog rather than with
     * the browser's confirm(): a field from the list and from its own screen,
     * a form from the overview and from its editor, and a stored submission.
     * Each question has a title, names what goes, and has a button that says
     * what it does; the dialog's own form only closes the dialog. Opening
     * the screens deletes nothing.
     */
    public function testEveryDeletionAsksInTheCmsDialogAndNamesWhatGoes(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE, AdminPermissions::FORMS_SUBMISSIONS]);
        $catalog = require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php';

        $formId = $this->createForm(['name' => 'Offerte aanvragen']);
        $fields = [];
        foreach ($this->forms->fieldsFor($formId) as $field) {
            $fields[(int) $field['id']] = \App\Service\Forms\FormLocalization::fieldName((int) $field['id']);
        }
        $fieldId = array_key_first($fields);

        $kept = $this->createForm(['name' => 'Met inzendingen', 'internal_key' => FormCatalog::internalKeyFor('zz test met inzendingen', $this->forms)]);
        $submissionId = (new FormSubmissionRepository())->create($kept, 'Met inzendingen', '/' . self::TEST_PAGE, [
            ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand'],
        ]);
        $sentAt = date('d-m-Y H:i', strtotime((string) (new FormSubmissionRepository())->findForAdmin($submissionId)['created_at']));

        $screens = [
            'overview' => '/admin/forms.php',
            'form editor' => '/admin/form.php?id=' . $formId,
            'field editor' => '/admin/form-field.php?id=' . $fieldId,
            'submission' => '/admin/form-submission.php?id=' . $submissionId,
        ];
        $xpaths = [];

        foreach ($screens as $screen => $path) {
            $response = self::$server->request('GET', $path, $session);
            $this->assertSame(200, $response['status'], $screen . ' opens');
            $this->assertStringNotContainsString('onsubmit', $response['body'], $screen . ': no inline handler asks the browser');
            $this->assertStringNotContainsString('confirm(', $response['body'], $screen);

            $xpaths[$screen] = $this->xpath($response['body']);
            $this->assertCmsConfirmDialog($xpaths[$screen], $screen);
        }

        $asks = function (\DOMElement $form, string $title, array $names, string $screen) use ($catalog): void {
            $this->assertSame($catalog[$title], $form->getAttribute('data-admin-confirm-title'), $screen . ': a title');
            foreach ($names as $name) {
                $this->assertStringContainsString($name, $form->getAttribute('data-admin-confirm'), $screen . ': names ' . $name);
            }
            $this->assertSame($catalog['common.delete'], $form->getAttribute('data-admin-confirm-action'), $screen . ': says what the button does');
        };

        foreach ($fields as $id => $label) {
            $asks($this->deleteForm($xpaths['form editor'], '/api/admin/delete-form-field.php', 'field_id', $id, 'field list'), 'forms.delete_field.title', ['“' . $label . '”', '“Offerte aanvragen”'], 'field list');
        }
        $asks($this->deleteForm($xpaths['field editor'], '/api/admin/delete-form-field.php', 'field_id', $fieldId, 'field editor'), 'forms.delete_field.title', ['“' . $fields[$fieldId] . '”', '“Offerte aanvragen”'], 'field editor');

        foreach (['overview', 'form editor'] as $screen) {
            $asks($this->deleteForm($xpaths[$screen], '/api/admin/delete-form.php', 'id', $formId, $screen), 'forms.delete_form.title', ['“Offerte aanvragen”'], $screen);
        }
        $this->assertSame(0, $xpaths['overview']->query('//form[@action="/api/admin/delete-form.php"][.//input[@name="id"][@value="' . $kept . '"]]')->length, 'a form that cannot go still offers no delete');

        $asks($this->deleteForm($xpaths['submission'], '/api/admin/delete-form-submission.php', 'id', $submissionId, 'submission'), 'forms.delete_submission.title', [$sentAt, '“Met inzendingen”'], 'submission');

        $this->assertNotNull($this->forms->find($formId));
        $this->assertCount(4, $this->forms->fieldsFor($formId));
        $this->assertNotNull((new FormSubmissionRepository())->findForAdmin($submissionId), 'opening the screens deleted nothing');
    }

    /**
     * What the dialog lets through is the request the button always sent,
     * and the endpoints still decide: signed out, without the permission, not
     * a POST or without the right token, nothing is deleted. A confirmed
     * request deletes exactly the field, form or submission it names, and
     * nothing next to it.
     */
    public function testAConfirmedDeletionRemovesExactlyItsObjectAndTheEndpointsStillGuard(): void
    {
        [$manager, $token] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        [$reader, $readerToken] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        [$pageEditor, $pageEditorToken] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);
        $submissions = new FormSubmissionRepository();

        $formId = $this->createForm();
        $neighbour = $this->createForm(['internal_key' => FormCatalog::internalKeyFor('zz test buurformulier', $this->forms)]);
        $fieldIds = array_map(static fn (array $row): int => (int) $row['id'], $this->forms->fieldsFor($formId));
        $doomedField = $fieldIds[2];
        $firstSubmission = $submissions->create($neighbour, 'Buur', null, [['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Een']]);
        $secondSubmission = $submissions->create($neighbour, 'Buur', null, [['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Twee']]);

        $cases = [
            'field' => ['/admin/form-field.php?id=' . $doomedField, '/api/admin/delete-form-field.php', 'field_id', $doomedField, $manager, $token],
            'form' => ['/admin/form.php?id=' . $formId, '/api/admin/delete-form.php', 'id', $formId, $manager, $token],
            'submission' => ['/admin/form-submission.php?id=' . $firstSubmission, '/api/admin/delete-form-submission.php', 'id', $firstSubmission, $reader, $readerToken],
        ];

        $exists = [
            'field' => fn (): bool => $this->forms->findField($doomedField) !== null,
            'form' => fn (): bool => $this->forms->find($formId) !== null,
            'submission' => fn (): bool => $submissions->findForAdmin($firstSubmission) !== null,
        ];

        foreach ($cases as $what => [$screen, $endpoint, $idName, $id, $session, $sessionToken]) {
            $response = self::$server->request('GET', $screen, $session);
            $sent = $this->hiddenFields($this->deleteForm($this->xpath($response['body']), $endpoint, $idName, $id, $what));
            $this->assertSame(['csrf_token' => $sessionToken, $idName => (string) $id], $sent, $what . ': the form sends what it always sent');

            $this->assertSame(401, self::$server->request('POST', $endpoint, null, $sent)['status'], $what . ': signed out');
            $this->assertSame(403, self::$server->request('POST', $endpoint, $pageEditor, ['csrf_token' => $pageEditorToken] + $sent)['status'], $what . ': without the permission');
            $this->assertSame(405, self::$server->request('GET', $endpoint . '?' . $idName . '=' . $id, $session)['status'], $what . ': only a POST');
            $this->assertSame(403, self::$server->request('POST', $endpoint, $session, ['csrf_token' => str_repeat('0', 64)] + $sent)['status'], $what . ': a wrong token');
            $this->assertSame(403, self::$server->request('POST', $endpoint, $session, [$idName => (string) $id])['status'], $what . ': no token');
            $this->assertTrue($exists[$what](), $what . ': nothing was deleted');

            self::$server->request('POST', $endpoint, $session, $sent);
            $this->assertFalse($exists[$what](), $what . ': deleted once confirmed');

            // The field goes first, so the form it belonged to still has three.
            if ($what === 'field') {
                $this->assertSame(array_values(array_diff($fieldIds, [$doomedField])), array_map(static fn (array $row): int => (int) $row['id'], $this->forms->fieldsFor($formId)), 'only that field');
            }
        }

        $this->assertCount(4, $this->forms->fieldsFor($neighbour), 'the other form keeps its fields');
        $this->assertNotNull($submissions->findForAdmin($secondSubmission), 'and its other submission');
        $this->assertSame(403, self::$server->request('POST', '/api/admin/delete-form-submission.php', $manager, ['csrf_token' => $token, 'id' => (string) $secondSubmission])['status'], 'building forms is not deleting what people sent');
        $this->assertNotNull($submissions->findForAdmin($secondSubmission));
    }

    /* ------------------------------------------------------------------ */
    /* The save bar                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The form editor carries the CMS's save bar, which watches every POST
     * form inside <main> that has a submit button and something to edit,
     * minus the one-button forms and those that opt out (admin/_save_bar.php,
     * admin/assets/save-bar.js). Only an input or change event inside such a
     * form makes the screen unsaved.
     *
     * So: every setting is an editable control of the settings form, the ones
     * under Geavanceerd included, and that form is the only one watched.
     * Opening Geavanceerd or a help mark touches no control: the summary holds
     * none, and every help button is a plain button outside any label, so it
     * cannot tick a switch either. The "Veld toevoegen" dialog opts out, the
     * field list's buttons are one-button forms, and "Formulier verwijderen"
     * has only hidden fields and keeps its question in the CMS dialog.
     */
    public function testTheSaveBarWatchesTheSettingsFormAndEverySettingInIt(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();

        $xpath = $this->editorXpath($session, $formId);
        $settings = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';

        $this->assertSame(1, $xpath->query('//*[@data-save-bar]')->length, 'one save bar');
        $this->assertSame(1, $xpath->query('//script[contains(@src, "/admin/assets/save-bar.js")]')->length, 'and its script');

        $editable = '(self::input and not(@type="hidden") and not(@type="submit") and not(@type="button")) or self::select or self::textarea';
        $watched = [];

        foreach ($xpath->query('//main[contains(@class, "admin-main")]//form[@method="post"]') as $form) {
            $isWatched = !str_contains(' ' . $form->getAttribute('class') . ' ', ' admin-inline-form ')
                && !$form->hasAttribute('data-no-dirty-track')
                && $xpath->query('.//*[@type="submit" or (self::button and not(@type))]', $form)->length > 0
                && $xpath->query('.//*[' . $editable . ']', $form)->length > 0;

            if ($isWatched) {
                $watched[] = $form->getAttribute('action');
            }
        }

        $this->assertSame([self::UPDATE_ENDPOINT], $watched, 'only the settings form can make the screen unsaved');

        $settingNames = [];
        foreach ($xpath->query($settings . '//*[' . $editable . '][@name]') as $control) {
            $settingNames[] = $control->getAttribute('name');
        }
        sort($settingNames);
        $this->assertSame([
            'is_active', 'name', 'notification_email', 'reply_to_field_key', 'store_submissions',
            'submit_label', 'success_message',
        ], $settingNames, 'every setting is a control of the watched form');

        $advanced = $settings . '//details[@data-form-advanced]';
        $this->assertSame(1, $xpath->query($advanced)->length, 'Geavanceerd sits inside the watched form');
        $this->assertFalse($xpath->query($advanced)->item(0)->hasAttribute('open'), 'and starts closed');
        $this->assertSame(2, $xpath->query($advanced . '//*[@name="store_submissions" or @name="reply_to_field_key"]')->length, 'storing and the reply address are inside it');
        $this->assertSame(0, $xpath->query($advanced . '/ancestor-or-self::*[@disabled] | ' . $advanced . '//fieldset[@disabled]')->length, 'nothing disables what a closed card sends');
        $this->assertSame(0, $xpath->query($advanced . '/summary//*[' . $editable . ' or self::button]')->length, 'opening or closing it is no edit');

        $helpButtons = $xpath->query($settings . '//*[@data-admin-help-trigger or @data-admin-help-close]');
        $this->assertGreaterThan(0, $helpButtons->length, 'the settings have help marks');
        foreach ($helpButtons as $button) {
            $this->assertSame('button', $button->getAttribute('type'), 'a help mark neither submits nor counts as an edit');
        }
        $this->assertSame(0, $xpath->query($settings . '//label//*[@data-admin-help-trigger]')->length, 'no help mark sits inside a label, where a click would tick its switch');
        $this->assertSame(1, $xpath->query($settings . '//*[@type="submit" or (self::button and not(@type))]')->length, 'the form has its own Opslaan and nothing else that sends');

        $add = $xpath->query('//form[@action="/api/admin/create-form-field.php"]');
        $this->assertSame(1, $add->length);
        $this->assertTrue($add->item(0)->hasAttribute('data-no-dirty-track'), 'choosing a new field is no edit to save later');

        foreach (['/api/admin/move-form-field.php', '/api/admin/delete-form-field.php'] as $action) {
            foreach ($xpath->query('//form[@action="' . $action . '"]') as $form) {
                $this->assertStringContainsString('admin-inline-form', $form->getAttribute('class'), $action . ' is a one-button form');
            }
        }

        $delete = $this->deleteForm($xpath, '/api/admin/delete-form.php', 'id', $formId, 'form editor');
        $this->assertSame(0, $xpath->query('.//*[' . $editable . ']', $delete)->length, 'deleting the form holds nothing to edit');
        $this->assertSame(0, $xpath->query('ancestor::form', $delete)->length, 'and sits outside the settings form');
        $this->assertTrue($delete->hasAttribute('data-admin-confirm'), 'and still asks in the CMS dialog');

        $this->assertFalse($xpath->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), 'a fresh screen starts saved');
        $this->assertSame(0, $xpath->query('//*[@data-save-bar-discard]')->length, 'nothing on this screen throws input away on purpose');
    }

    /**
     * A save, of an everyday setting or of one under Geavanceerd, lands on the
     * success marker the bar reads as saved, with one "Opgeslagen" and the
     * screen saved. A refused save comes back without that marker and with
     * what was sent still on screen, so the form starts out unsaved; the next
     * plain visit shows the stored form again, saved.
     */
    public function testASaveLandsSavedAndARefusedSaveStartsOutUnsaved(): void
    {
        [$session] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE]);
        $formId = $this->createForm();
        $settings = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';

        $changes = [
            'the name' => ['name' => 'Offerte aanvragen'],
            'a setting under Geavanceerd' => ['reply_to_field_key' => '', 'store_submissions' => null],
        ];

        foreach ($changes as $what => $change) {
            $editor = $this->editorSubmission($session, $formId);
            foreach ($change as $name => $value) {
                if ($value === null) {
                    unset($editor[$name]);
                } else {
                    $editor[$name] = $value;
                }
            }

            $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);
            $this->assertSame('/admin/form.php?id=' . $formId . '&saved=1', $response['location'], $what . ': the PRG redirect');
            $this->assertMatchesRegularExpression('/[?&](saved|updated|created)=1(&|$)/', $response['location'], $what . ': the success marker the save bar reads');

            $xpath = $this->xpath(self::$server->request('GET', $response['location'], $session)['body']);
            $this->assertFalse($xpath->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), $what . ': saved is saved');
            $this->assertSame(1, $xpath->query('//*[contains(@class, "admin-alert--success")]')->length, $what . ': one "Opgeslagen"');
            $this->assertSame(0, $xpath->query('//*[contains(@class, "admin-alert--error")]')->length, $what . ': and no error');
        }

        $stored = $this->forms->find($formId);
        $this->assertSame('Offerte aanvragen', $stored['name']);
        $this->assertSame('', (string) ($stored['reply_to_field_key'] ?? ''));
        $this->assertSame(0, (int) $stored['store_submissions']);

        $editor = $this->editorSubmission($session, $formId);
        $editor['notification_email'] = 'geen adres';
        $response = self::$server->request('POST', self::UPDATE_ENDPOINT, $session, $editor);
        $this->assertSame('/admin/form.php?id=' . $formId, $response['location'], 'refused, without the marker');

        $xpath = $this->editorXpath($session, $formId);
        $this->assertTrue($xpath->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), 'what came back unwritten is unsaved');
        $this->assertSame('geen adres', $xpath->query($settings . '//input[@name="notification_email"]')->item(0)->getAttribute('value'), 'and is still on screen');

        $this->assertFalse($this->editorXpath($session, $formId)->query($settings)->item(0)->hasAttribute('data-save-bar-unsaved'), 'the next visit shows the stored form, saved');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** One shared confirmation dialog, whose own form sends nothing. */
    private function assertCmsConfirmDialog(\DOMXPath $xpath, string $screen): void
    {
        $dialogs = $xpath->query('//dialog[@data-admin-confirm-dialog]');
        $this->assertSame(1, $dialogs->length, $screen . ': the CMS dialog, once');
        $this->assertSame('dialog', $xpath->query('.//form', $dialogs->item(0))->item(0)?->getAttribute('method'), $screen . ': answering only closes it');
        $this->assertSame(1, $xpath->query('.//button[@data-admin-confirm-yes][contains(@class, "admin-btn-danger")]', $dialogs->item(0))->length, $screen . ': the button that goes ahead looks destructive');
    }

    /** The delete form for exactly this object. */
    private function deleteForm(\DOMXPath $xpath, string $action, string $idName, int $id, string $screen): \DOMElement
    {
        $forms = $xpath->query('//form[@method="post"][@action="' . $action . '"][.//input[@name="' . $idName . '"][@value="' . $id . '"]]');
        $this->assertSame(1, $forms->length, $screen . ': one delete form for ' . $idName . ' ' . $id);

        return $forms->item(0);
    }

    /** @return array<string, string> */
    private function hiddenFields(\DOMElement $form): array
    {
        $sent = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('type') === 'hidden') {
                $sent[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }

        return $sent;
    }

    private function editorXpath(string $session, int $formId): \DOMXPath
    {
        $response = self::$server->request('GET', '/admin/form.php?id=' . $formId, $session);
        $this->assertSame(200, $response['status'], 'the form editor opens');

        return $this->xpath($response['body']);
    }

    /**
     * @return array{status: string, fields: string, submissions: string, submissions_linked: bool, edit_linked: bool, deletable: bool}
     */
    private function overviewRow(string $session, int $formId): array
    {
        $response = self::$server->request('GET', '/admin/forms.php', $session);
        $this->assertSame(200, $response['status'], 'the overview opens');

        $xpath = $this->xpath($response['body']);
        $row = $xpath->query('//table//tr[td[1]/a[@href="/admin/form.php?id=' . $formId . '"]]')->item(0);
        $this->assertNotNull($row, 'the form has a row');

        $cells = $xpath->query('./td', $row);

        return [
            'status' => $cells->item(1)->textContent,
            'fields' => trim($cells->item(2)->textContent),
            'submissions' => preg_replace('/\s+/', ' ', $cells->item(3)->textContent),
            'submissions_linked' => $xpath->query('.//a[@href="/admin/form-submissions.php?form=' . $formId . '"]', $cells->item(3))->length === 1,
            'edit_linked' => $xpath->query('.//a[@href="/admin/form.php?id=' . $formId . '"]', $cells->item(5))->length === 1,
            'deletable' => $xpath->query('.//form[@action="/api/admin/delete-form.php"]', $cells->item(5))->length === 1,
        ];
    }

    /**
     * A form with a required text field, an e-mail field as Reply-To, a
     * choice field with options and a default, and a textarea — enough for
     * "nothing else changed" to mean something.
     *
     * @param array<string, mixed> $overrides
     */
    private function createForm(array $overrides = []): int
    {
        $id = $this->forms->create($overrides + [
            'name' => 'Formulierstatus test',
            'internal_key' => FormCatalog::internalKeyFor('zz test formulierstatus', $this->forms),
            'is_active' => true,
            'notification_email' => 'formulierstatus@example.com',
            'reply_to_field_key' => 'e-mail',
            'store_submissions' => true,
        ]);

        $this->createdFormIds[] = $id;
        FormFixture::formWords($id, [
            'nl' => ['submit_label' => 'Verstuur', 'success_message' => 'Bedankt.'],
            'en' => ['submit_label' => 'Send', 'success_message' => 'Thanks.'],
        ]);

        $fields = [
            ['Naam', 'text', true, null, null],
            ['E-mail', 'email', true, null, null],
            ['Voorkeur', 'radio', false, "Bellen|Call\nMailen|Email", 'Mailen'],
            ['Bericht', 'textarea', false, null, null],
        ];

        foreach ($fields as [$label, $type, $required, $options, $default]) {
            $taken = array_map(static fn (array $row): string => (string) $row['field_key'], $this->forms->fieldsFor($id));

            FormFixture::field($id, [
                'field_key' => FormFieldKey::fromLabel($label, $taken),
                'field_type' => $type,
                'is_required' => $required,
                'default_value' => $default,
            ], ['nl' => ['label' => $label]], $options);
        }

        FormCatalog::clearCache();

        return $id;
    }

    /**
     * Posts a filled-in form to the public endpoint, the way the form block's
     * script does (JSON) or the way a browser without it does (a redirect).
     *
     * @param array<string, string> $overrides
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function submit(int $formId, array $overrides = [], bool $asJson = true): array
    {
        $fields = $overrides + [
            'form-key' => (string) $this->forms->find($formId)['internal_key'],
            'form-instance' => FormRenderState::tokenFor(self::TEST_PAGE, 'status-test'),
            'form-source' => '/' . self::TEST_PAGE,
            FormSpamGuard::TIMESTAMP_FIELD => (string) (time() - 30),
            'naam' => 'Test Bezoeker',
            'e-mail' => 'bezoeker@example.com',
            'voorkeur' => 'Bellen',
            'bericht' => 'Een vraag',
        ];

        return self::$server->request(
            'POST',
            self::SUBMIT_ENDPOINT,
            null,
            $fields,
            [],
            $asJson ? ['Accept: application/json'] : []
        );
    }

    private function setActive(int $formId, bool $active): void
    {
        $row = $this->forms->find($formId);
        $row['is_active'] = $active;

        $this->forms->update($formId, $row);
        FormCatalog::clearCache();
    }

    /**
     * What a browser sends when the editor's save button is pressed and
     * nothing was changed: every named control of the form that posts to
     * update-form.php, wherever on the screen it sits — a folded-away
     * section included, because a closed <details> still submits what is
     * inside it. A checkbox is only sent when it is checked.
     *
     * @return array<string, string>
     */
    private function editorSubmission(string $session, int $formId): array
    {
        $xpath = $this->editorXpath($session, $formId);
        $formPath = '//form[@action="' . self::UPDATE_ENDPOINT . '"]';
        $this->assertSame(1, $xpath->query($formPath)->length, 'one form saves the form settings');

        $sent = [];

        foreach ($xpath->query($formPath . '//input[@name]') as $input) {
            $type = strtolower($input->getAttribute('type'));
            if ($input->hasAttribute('disabled') || in_array($type, ['submit', 'button', 'file'], true)) {
                continue;
            }
            if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
                continue;
            }
            $sent[$input->getAttribute('name')] = $input->hasAttribute('value') ? $input->getAttribute('value') : 'on';
        }

        foreach ($xpath->query($formPath . '//textarea[@name]') as $textarea) {
            $sent[$textarea->getAttribute('name')] = $textarea->textContent;
        }

        foreach ($xpath->query($formPath . '//select[@name]') as $select) {
            $options = $xpath->query('.//option', $select);
            $chosen = $xpath->query('.//option[@selected]', $select)->item(0) ?? $options->item(0);
            $sent[$select->getAttribute('name')] = $chosen === null ? '' : $chosen->getAttribute('value');
        }

        return $sent;
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withoutStatus(array $row): array
    {
        unset($row['is_active'], $row['updated_at']);

        return $row;
    }

    private function storeSubmissions(int $formId, int $count): void
    {
        $repository = new FormSubmissionRepository();

        for ($i = 1; $i <= $count; $i++) {
            $repository->create($formId, 'Formulierstatus test', '/' . self::TEST_PAGE, [
                ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Eerder ' . $i],
            ]);
        }
    }

    /** @return list<array{submission: array<string, mixed>, values: array<int, array<string, mixed>>}> */
    private function submissionSnapshot(int $formId): array
    {
        $repository = new FormSubmissionRepository();
        $snapshot = [];

        foreach ($repository->findAllForAdmin($formId) as $submission) {
            $snapshot[] = ['submission' => $submission, 'values' => $repository->valuesFor((int) $submission['id'])];
        }

        return $snapshot;
    }

    /**
     * A published page with a "Formulier" block and an "Offerte-/
     * contactformulier" block, both showing this form.
     *
     * @return list<array<string, mixed>> the two page_sections rows
     */
    private function placeBothBlocks(int $formId): array
    {
        $pageId = \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'published',
        ], 'Formulierstatus testpagina');

        $pageSections = new PageSectionRepository();
        $rows = [];

        [$sectionId, $sectionKey] = SectionRegistry::create('form', self::TEST_PAGE);
        $rows[] = $pageSections->findById($pageSections->create($pageId, self::TEST_PAGE, 'form', $sectionKey, $sectionId));
        (new FormBlockRepository())->upsertSection(self::TEST_PAGE, (string) $sectionKey, ['form_id' => $formId, 'is_active' => true]);

        [$sectionId, $sectionKey] = SectionRegistry::create('contact_form', self::TEST_PAGE);
        $rows[] = $pageSections->findById($pageSections->create($pageId, self::TEST_PAGE, 'contact_form', $sectionKey, $sectionId));
        (new ContactFormRepository())->upsertSection(self::TEST_PAGE, (string) $sectionKey, [
            'title_nl' => 'Neem contact op',
            'title_en' => 'Get in touch',
            'form_id' => $formId,
            'allow_attachment' => false,
            'is_active' => true,
        ]);

        return $rows;
    }

    private function publicPage(): string
    {
        $response = self::$server->request('GET', '/pagina.php?slug=' . self::TEST_PAGE);
        $this->assertSame(200, $response['status'], 'the page renders');

        return $response['body'];
    }

    private function rateLimitHits(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM contact_rate_limit_hits')->fetchColumn();
    }

    /** Every visitor of these tests is 127.0.0.1, so the limiter starts empty each time. */
    private function clearRateLimit(): void
    {
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
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
}
