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
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

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
            'submit_label_nl' => 'Verstuur',
            'submit_label_en' => 'Send',
            'success_message_nl' => 'Bedankt.',
            'success_message_en' => 'Thanks.',
            'notification_email' => 'formulierstatus@example.com',
            'reply_to_field_key' => 'e-mail',
            'store_submissions' => true,
        ]);

        $this->createdFormIds[] = $id;

        $fields = [
            ['Naam', 'text', true, null, null],
            ['E-mail', 'email', true, null, null],
            ['Voorkeur', 'radio', false, "Bellen|Call\nMailen|Email", 'Mailen'],
            ['Bericht', 'textarea', false, null, null],
        ];

        foreach ($fields as [$label, $type, $required, $options, $default]) {
            $taken = array_map(static fn (array $row): string => (string) $row['field_key'], $this->forms->fieldsFor($id));

            $this->forms->createField($id, [
                'field_key' => FormFieldKey::fromLabel($label, $taken),
                'field_type' => $type,
                'label_nl' => $label,
                'label_en' => null,
                'placeholder_nl' => null,
                'placeholder_en' => null,
                'help_text_nl' => null,
                'help_text_en' => null,
                'is_required' => $required,
                'options' => $options,
                'default_value' => $default,
            ]);
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
        $response = self::$server->request('GET', '/admin/form.php?id=' . $formId, $session);
        $this->assertSame(200, $response['status'], 'the form editor opens');

        $xpath = $this->xpath($response['body']);
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
        $pageId = (new PageRepository())->create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'title' => 'Formulierstatus testpagina',
            'status' => 'published',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);

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
