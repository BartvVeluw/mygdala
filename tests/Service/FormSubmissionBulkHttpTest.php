<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\AdminPermissions;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFieldKey;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSpamGuard;
use App\Service\Forms\FormSubmissionBulk;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FormFixture;

/**
 * Submissions 2.0 over real HTTP (FORMS.md, "Gelezen en ongelezen" and
 * "Inzendingen in bulk"): what the overview shows and lets you select, what
 * api/admin/bulk-form-submissions.php does with a selection, and everything
 * it refuses without changing a row.
 *
 * The forms, submissions, files and accounts are this test's own and are
 * removed again in tearDown(). Files go to a storage directory of its own
 * (CONTACT_ATTACHMENTS_PATH), the way Tests\Service\FormUploadHttpTest does.
 */
final class FormSubmissionBulkHttpTest extends TestCase
{
    private const ENDPOINT = '/api/admin/bulk-form-submissions.php';

    private const OVERVIEW = '/admin/form-submissions.php';

    private static ?BuiltInServer $server = null;

    private static string $storage = '';

    private AdminTestSession $accounts;

    private FormRepository $forms;

    private FormSubmissionRepository $submissions;

    /** @var list<int> */
    private array $createdFormIds = [];

    /** The next created_at, so every stored submission is one second newer than the last. */
    private int $clock = 0;

    public static function setUpBeforeClass(): void
    {
        self::$storage = sys_get_temp_dir() . '/mygdala-bulk-http-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/contact-attachments', 0777, true);

        // Nothing listens on port 9, so no notification leaves this test.
        self::$server = BuiltInServer::start([
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '9',
            'CONTACT_ATTACHMENTS_PATH' => self::$storage,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        foreach (glob(self::$storage . '/contact-attachments/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$storage . '/contact-attachments');
        @rmdir(self::$storage);
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->forms = new FormRepository();
        $this->submissions = new FormSubmissionRepository();

        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFormIds as $id) {
            foreach ($this->submissions->findAllForAdmin($id) as $submission) {
                $this->submissions->delete((int) $submission['id']);
            }
            $this->forms->delete($id);
        }
        $this->createdFormIds = [];

        $this->accounts->forget();
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
        FormCatalog::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Read and unread                                                     */
    /* ------------------------------------------------------------------ */

    public function testANewPublicSubmissionArrivesUnread(): void
    {
        $formId = $this->createForm('Publiek');

        $response = self::$server->request('POST', '/api/form-submit.php', null, [
            'form-key' => (string) $this->forms->find($formId)['internal_key'],
            'form-instance' => FormRenderState::tokenFor('zz-bulk-test', 'bulk-test'),
            'form-source' => '/zz-bulk-test',
            FormSpamGuard::TIMESTAMP_FIELD => (string) (time() - 30),
            'naam' => 'Test Bezoeker',
        ], [], ['Accept: application/json']);

        $this->assertSame(200, $response['status'], $response['body']);
        $this->assertTrue(json_decode($response['body'], true)['ok'] ?? null);

        $stored = $this->submissions->findAllForAdmin($formId);
        $this->assertCount(1, $stored);
        $this->assertSame(0, (int) $stored[0]['is_read'], 'a new submission is unread');
    }

    public function testTheOverviewSaysUnreadInWordsAndFiltersOnStatus(): void
    {
        [$reader] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Statusfilter');
        $unread = $this->store($formId, 2);
        $read = $this->store($formId, 3);
        $this->submissions->setReadStateForMany($read, true);

        $rows = $this->rows($this->overview($reader, ['form' => $formId]));
        $this->assertCount(5, $rows, 'Alle');
        foreach ($rows as $id => $row) {
            $isUnread = in_array($id, $unread, true);
            $this->assertSame($isUnread, $row['unread_class'], 'row ' . $id . ': the unread row stands out');
            $this->assertSame($isUnread ? 'Nieuw' : 'Gelezen', $row['status'], 'row ' . $id . ': the state is also a word');
        }

        $this->assertEqualsCanonicalizing($unread, array_keys($this->rows($this->overview($reader, ['form' => $formId, 'read' => 'unread']))), 'Ongelezen');
        $this->assertEqualsCanonicalizing($read, array_keys($this->rows($this->overview($reader, ['form' => $formId, 'read' => 'read']))), 'Gelezen');

        // Status filters; it does not sort. Newest first, read or not.
        $this->assertSame(array_reverse(array_merge($unread, $read)), array_keys($rows));
    }

    public function testOpeningASubmissionMarksItReadOnTheServerAndItCanBeMarkedUnreadAgain(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Detail');
        [$id] = $this->store($formId, 1);

        $this->assertSame(200, self::$server->request('GET', '/admin/form-submission.php?id=' . $id, $reader)['status']);
        $this->assertTrue($this->isRead($id), 'opening it marks it read, without any script');

        $detail = self::$server->request('GET', '/admin/form-submission.php?id=' . $id, $reader);
        $this->assertTrue($this->isRead($id), 'a reload does not flip it');

        $form = $this->xpath($detail['body'])->query('//form[@action="' . self::ENDPOINT . '"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form, 'the detail offers "Markeren als ongelezen"');
        $button = $this->xpath($detail['body'])->query('//form[@action="' . self::ENDPOINT . '"]//button[@name="action"]')->item(0);
        $this->assertSame(FormSubmissionBulk::MARK_UNREAD, $button->getAttribute('value'));
        $this->assertSame('Markeren als ongelezen', trim($button->textContent));

        $response = self::$server->request('POST', self::ENDPOINT, $reader, ['csrf_token' => $token, 'action' => 'mark_unread', 'ids' => [(string) $id]]);
        $this->assertSame(302, $response['status']);
        $this->assertSame(self::OVERVIEW, $response['location'], 'back to the overview, not to the screen that would mark it read again');
        $this->assertFalse($this->isRead($id));
    }

    /* ------------------------------------------------------------------ */
    /* Selecting                                                           */
    /* ------------------------------------------------------------------ */

    public function testTheOverviewPagesAndSelectAllCoversOnlyThatPage(): void
    {
        [$reader] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Pagina\'s');
        $ids = $this->store($formId, FormSubmissionRepository::PAGE_SIZE + 2);

        $first = $this->xpath($this->overview($reader, ['form' => $formId]));
        $boxes = $first->query('//input[@type="checkbox"][@name="ids[]"]');
        $this->assertSame(FormSubmissionRepository::PAGE_SIZE, $boxes->length, 'one checkbox per row of this page');
        $this->assertSame(0, $first->query('//input[@type="checkbox"][@checked]')->length, 'a page starts with nothing selected');

        foreach ($boxes as $box) {
            $label = $first->query('//label[@for="' . $box->getAttribute('id') . '"]')->item(0);
            $this->assertNotNull($label, 'every row checkbox has a real label');
            $this->assertStringContainsString('Pagina\'s', $label->textContent);
            $this->assertSame(['type', 'class', 'name', 'value', 'id', 'data-submission-select'], array_values(array_map(static fn (\DOMAttr $a): string => $a->name, iterator_to_array($box->attributes))), 'only the id goes into the control');
        }

        $all = $first->query('//input[@id="submission-select-all"]')->item(0);
        $this->assertNotNull($all);
        $this->assertFalse($all->hasAttribute('name'), 'select all sends nothing of its own');
        $this->assertSame('Alles op deze pagina selecteren', trim($first->query('//label[@for="submission-select-all"]')->item(0)->textContent));
        $this->assertSame(1, $first->query('//*[contains(@class, "admin-submission-bulk__scope")]')->length, 'with more than one page, the bar says selection is per page');
        $this->assertSame('status', $first->query('//*[@data-submission-selected-count]')->item(0)->getAttribute('role'));

        $second = $this->xpath($this->overview($reader, ['form' => $formId, 'page' => 2]));
        $this->assertSame(2, $second->query('//input[@name="ids[]"]')->length);
        $this->assertSame('2', $second->query('//input[@name="return_page"]')->item(0)->getAttribute('value'));
        $this->assertSame((string) $formId, $second->query('//input[@name="form"]')->item(0)->getAttribute('value'), 'the form filter is the scope of the request');

        $beyond = $this->xpath($this->overview($reader, ['form' => $formId, 'page' => 9]));
        $this->assertSame(2, $beyond->query('//input[@name="ids[]"]')->length, 'a page past the last shows the last');

        $onePage = $this->xpath($this->overview($reader, ['form' => $formId, 'read' => 'read']));
        $this->assertSame(0, $onePage->query('//*[contains(@class, "admin-submission-bulk__scope")]')->length);
        $this->assertCount(FormSubmissionRepository::PAGE_SIZE + 2, $ids);
    }

    public function testTheDeleteButtonAloneAsksAndNamesTheNumber(): void
    {
        [$reader] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Vragen');
        $this->store($formId, 2);

        $xpath = $this->xpath($this->overview($reader, ['form' => $formId]));
        $form = $xpath->query('//form[@action="' . self::ENDPOINT . '"]')->item(0);
        $this->assertFalse($form->hasAttribute('data-admin-confirm'), 'marking read or unread does not ask');

        $buttons = [];
        foreach ($xpath->query('.//button[@name="action"]', $form) as $button) {
            $buttons[$button->getAttribute('value')] = $button;
        }
        $this->assertSame(FormSubmissionBulk::ACTIONS, array_keys($buttons), 'three actions, in this order, and the default (Enter) is harmless');

        $delete = $buttons['delete'];
        $this->assertSame('Inzendingen verwijderen?', $delete->getAttribute('data-admin-confirm-title'));
        $this->assertSame('Verwijderen', $delete->getAttribute('data-admin-confirm-action'));
        $this->assertNotSame('', $delete->getAttribute('data-admin-confirm'));
        $this->assertStringContainsString('Weet je zeker dat je :count inzendingen wilt verwijderen?', $delete->getAttribute('data-text-many'));
        $this->assertFalse($buttons['mark_read']->hasAttribute('data-admin-confirm'));
        $this->assertSame(1, $xpath->query('//dialog[@data-admin-confirm-dialog]')->length);
    }

    /* ------------------------------------------------------------------ */
    /* Acting                                                              */
    /* ------------------------------------------------------------------ */

    public function testMarkingReadAndUnreadInBulk(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Markeren');
        $ids = $this->store($formId, 4);
        $untouched = $ids[3];

        $one = $this->bulk($reader, $token, 'mark_read', [$ids[0]], ['form' => (string) $formId, 'return_read' => 'unread', 'return_page' => '1']);
        $this->assertSame(302, $one['status']);
        $this->assertSame(self::OVERVIEW . '?read=unread&form=' . $formId, $one['location'], 'back to the same filter');
        $this->assertTrue($this->isRead($ids[0]));
        $this->assertStringContainsString('1 inzending gemarkeerd als gelezen.', $this->overview($reader, ['form' => $formId]), 'a message, once');
        $this->assertStringNotContainsString('gemarkeerd als gelezen', $this->overview($reader, ['form' => $formId]));

        $many = $this->bulk($reader, $token, 'mark_read', [$ids[1], $ids[2], $ids[1], $ids[2]], ['return_page' => '3']);
        $this->assertSame(self::OVERVIEW . '?page=3', $many['location']);
        $this->assertTrue($this->isRead($ids[1]) && $this->isRead($ids[2]), 'a duplicate id is folded');
        $this->assertStringContainsString('2 inzendingen gemarkeerd als gelezen.', $this->overview($reader, ['form' => $formId]));
        $this->assertFalse($this->isRead($untouched), 'nothing outside the selection');

        $this->bulk($reader, $token, 'mark_unread', [$ids[0], $ids[1]]);
        $this->assertFalse($this->isRead($ids[0]));
        $this->assertFalse($this->isRead($ids[1]));
        $this->assertTrue($this->isRead($ids[2]));

        // Marking what already is read is not an error.
        $again = $this->bulk($reader, $token, 'mark_read', [$ids[2]]);
        $this->assertSame(302, $again['status']);
        $this->assertTrue($this->isRead($ids[2]));
    }

    public function testDeletingInBulkTakesRowsValuesAndFilesAndNothingElse(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Verwijderen');
        $ids = $this->store($formId, 4);
        $files = [];
        foreach ($ids as $id) {
            $files[$id] = $this->attachFile($id);
        }
        [$kept] = array_slice($ids, 3, 1);

        $one = $this->bulk($reader, $token, 'delete', [$ids[0]], ['form' => (string) $formId]);
        $this->assertSame(302, $one['status']);
        $this->assertNull($this->submissions->findForAdmin($ids[0]));
        $this->assertStringContainsString('1 inzending verwijderd.', $this->overview($reader, ['form' => $formId]));

        $this->bulk($reader, $token, 'delete', [$ids[1], $ids[2]], ['form' => (string) $formId]);

        foreach (array_slice($ids, 0, 3) as $id) {
            $this->assertNull($this->submissions->findForAdmin($id));
            $this->assertSame([], $this->submissions->valuesFor($id), 'its answers went with it');
            $this->assertSame([], $this->submissions->attachmentsFor($id), 'and its attachment rows');
            $this->assertFileDoesNotExist(self::$storage . '/contact-attachments/' . $files[$id], 'and the file itself');
        }

        $this->assertNotNull($this->submissions->findForAdmin($kept));
        $this->assertCount(1, $this->submissions->valuesFor($kept));
        $this->assertFileExists(self::$storage . '/contact-attachments/' . $files[$kept], 'another submission\'s file stays');

        $orphans = (int) Database::connection()->query(
            'SELECT (SELECT COUNT(*) FROM form_submission_values v LEFT JOIN form_submissions s ON s.id = v.submission_id WHERE s.id IS NULL)
                  + (SELECT COUNT(*) FROM form_submission_attachments a LEFT JOIN form_submissions s ON s.id = a.submission_id WHERE s.id IS NULL)'
        )->fetchColumn();
        $this->assertSame(0, $orphans, 'no orphan rows anywhere');
    }

    public function testEmptyingTheLastPageLandsOnTheLastPageThereIs(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Laatste pagina');
        $ids = $this->store($formId, FormSubmissionRepository::PAGE_SIZE + 1);

        // The oldest one is alone on page 2.
        $response = $this->bulk($reader, $token, 'delete', [$ids[0]], ['form' => (string) $formId, 'return_page' => '2']);
        $this->assertSame(self::OVERVIEW . '?form=' . $formId . '&page=2', $response['location']);

        $page = $this->xpath(self::$server->request('GET', $response['location'], $reader)['body']);
        $this->assertSame(FormSubmissionRepository::PAGE_SIZE, $page->query('//input[@name="ids[]"]')->length, 'page 2 no longer exists, so page 1 shows');
        $this->assertSame('1', $page->query('//input[@name="return_page"]')->item(0)->getAttribute('value'));
    }

    /* ------------------------------------------------------------------ */
    /* Refusing                                                            */
    /* ------------------------------------------------------------------ */

    public function testEveryForgedOrPartlyWrongSelectionIsRefusedWholeAndChangesNothing(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        $formId = $this->createForm('Weigeren');
        $other = $this->createForm('Ander formulier');
        [$a, $b] = $this->store($formId, 2);
        [$foreign] = $this->store($other, 1);
        $gone = $this->store($formId, 1)[0];
        $this->submissions->delete($gone);
        $before = $this->snapshot([$a, $b, $foreign]);

        $refusedOutright = [
            'unknown action' => ['archive', [$a]],
            'empty action' => ['', [$a]],
            'string id' => ['mark_read', [(string) $a, 'abc']],
            'negative id' => ['mark_read', ['-' . $a]],
            'zero' => ['delete', ['0']],
            'too many' => ['delete', array_map('strval', range(1, FormSubmissionBulk::MAX_IDS + 1))],
            'outside the form' => ['delete', [$a, $foreign], ['form' => (string) $formId]],
            'a form id that is no number' => ['delete', [$a], ['form' => 'x']],
            'a negative form id' => ['delete', [$a], ['form' => '-3']],
        ];

        foreach ($refusedOutright as $case => $request) {
            [$action, $ids] = $request;
            $response = $this->bulk($reader, $token, $action, $ids, $request[2] ?? []);
            $this->assertSame(400, $response['status'], $case);
            $this->assertSame($before, $this->snapshot([$a, $b, $foreign]), $case . ': nothing changed');
        }

        $ids = self::$server->request('POST', self::ENDPOINT, $reader, ['csrf_token' => $token, 'action' => 'delete', 'ids' => (string) $a]);
        $this->assertSame(400, $ids['status'], 'ids must be a list');

        // What the screen itself can send: back with a message.
        $empty = self::$server->request('POST', self::ENDPOINT, $reader, ['csrf_token' => $token, 'action' => 'delete']);
        $this->assertSame(302, $empty['status'], 'nothing selected');
        $this->assertStringContainsString('Kies eerst een of meer inzendingen.', self::$server->request('GET', $empty['location'], $reader)['body']);

        $stale = $this->bulk($reader, $token, 'delete', [$a, $gone, $b]);
        $this->assertSame(302, $stale['status']);
        $this->assertStringContainsString('bestaan niet meer', self::$server->request('GET', $stale['location'], $reader)['body']);
        $this->assertSame($before, $this->snapshot([$a, $b, $foreign]), 'one gone id refuses the whole set: the other two stay');

        $partial = $this->bulk($reader, $token, 'mark_read', [$a, $foreign], ['form' => (string) $formId]);
        $this->assertSame(400, $partial['status']);
        $this->assertFalse($this->isRead($a), 'not even the allowed half was marked');

        // Without a form scope, every form's submissions may be selected.
        $this->bulk($reader, $token, 'mark_read', [$a, $foreign]);
        $this->assertTrue($this->isRead($a) && $this->isRead($foreign));
    }

    public function testTheEndpointsGuardsComeFirst(): void
    {
        [$reader, $token] = $this->accounts->signIn([AdminPermissions::FORMS_SUBMISSIONS]);
        [$builder, $builderToken] = $this->accounts->signIn([AdminPermissions::FORMS_MANAGE, AdminPermissions::PAGES_MANAGE]);
        $formId = $this->createForm('Bewaking');
        [$id] = $this->store($formId, 1);
        $request = ['action' => 'delete', 'ids' => [(string) $id]];

        $this->assertSame(401, self::$server->request('POST', self::ENDPOINT, null, ['csrf_token' => $token] + $request)['status'], 'signed out');
        $this->assertSame(403, self::$server->request('POST', self::ENDPOINT, $builder, ['csrf_token' => $builderToken] + $request)['status'], 'building forms is not deleting what people sent');
        $this->assertSame(405, self::$server->request('GET', self::ENDPOINT . '?action=delete&ids[]=' . $id, $reader)['status'], 'only a POST');
        $this->assertSame(403, self::$server->request('POST', self::ENDPOINT, $reader, ['csrf_token' => str_repeat('0', 64)] + $request)['status'], 'a wrong token');
        $this->assertSame(403, self::$server->request('POST', self::ENDPOINT, $reader, $request)['status'], 'no token');
        $this->assertNotNull($this->submissions->findForAdmin($id), 'nothing was deleted');

        $this->assertSame(403, self::$server->request('GET', self::OVERVIEW, $builder)['status'], 'nor reading the overview');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function createForm(string $name): int
    {
        $id = $this->forms->create([
            'name' => $name,
            'internal_key' => FormCatalog::internalKeyFor('zz test bulk ' . $name, $this->forms),
            'is_active' => true,
            'notification_email' => 'bulk@example.com',
            'reply_to_field_key' => null,
            'store_submissions' => true,
        ]);
        $this->createdFormIds[] = $id;

        FormFixture::formWords($id, [
            'nl' => ['submit_label' => 'Verstuur', 'success_message' => 'Bedankt.'],
            'en' => ['submit_label' => 'Send', 'success_message' => 'Thanks.'],
        ]);
        FormFixture::field($id, [
            'field_key' => FormFieldKey::fromLabel('Naam', []),
            'field_type' => 'text',
            'is_required' => true,
            'default_value' => null,
        ], ['nl' => ['label' => 'Naam']], null);
        FormCatalog::clearCache();

        return $id;
    }

    /**
     * Stored submissions, oldest first, one second apart (also across
     * calls) so the overview's newest-first order is unambiguous.
     *
     * @return list<int>
     */
    private function store(int $formId, int $count): array
    {
        $name = (string) $this->forms->find($formId)['name'];
        $this->clock = max($this->clock, time() - 7200);
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $id = $this->submissions->create($formId, $name, '/zz-bulk-test', [
                ['field_key' => 'naam', 'field_label' => 'Naam', 'field_type' => 'text', 'value' => 'Iemand ' . $i],
            ]);
            Database::connection()->prepare('UPDATE form_submissions SET created_at = :at WHERE id = :id')
                ->execute(['at' => date('Y-m-d H:i:s', ++$this->clock), 'id' => $id]);
            $ids[] = $id;
        }

        return $ids;
    }

    /** One file on disk plus its row, the way an upload field leaves it. */
    private function attachFile(int $submissionId): string
    {
        $name = bin2hex(random_bytes(12)) . '.pdf';
        file_put_contents(self::$storage . '/contact-attachments/' . $name, '%PDF-1.4 test');

        Database::connection()->prepare(
            'INSERT INTO form_submission_attachments (submission_id, field_key, stored_filename, original_filename, mime_type, file_size, sha256, created_at)
             VALUES (:id, :field, :stored, :original, :mime, 13, NULL, NOW())'
        )->execute(['id' => $submissionId, 'field' => 'bestand-' . $submissionId, 'stored' => $name, 'original' => 'offerte.pdf', 'mime' => 'application/pdf']);

        return $name;
    }

    /**
     * @param list<int|string> $ids
     * @param array<string, string> $extra
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function bulk(string $session, string $token, string $action, array $ids, array $extra = []): array
    {
        return self::$server->request('POST', self::ENDPOINT, $session, [
            'csrf_token' => $token,
            'action' => $action,
            'ids' => array_map('strval', $ids),
        ] + $extra);
    }

    /**
     * @param array<string, int|string> $query
     */
    private function overview(string $session, array $query = []): string
    {
        $response = self::$server->request('GET', self::OVERVIEW . ($query === [] ? '' : '?' . http_build_query($query)), $session);
        $this->assertSame(200, $response['status'], 'the overview opens');

        return $response['body'];
    }

    /**
     * @return array<int, array{unread_class: bool, status: string}> keyed by submission id, in screen order
     */
    private function rows(string $html): array
    {
        $xpath = $this->xpath($html);
        $rows = [];

        foreach ($xpath->query('//tr[@data-submission-row]') as $row) {
            $id = (int) $xpath->query('.//input[@name="ids[]"]', $row)->item(0)->getAttribute('value');
            $cells = $xpath->query('./td', $row);
            $rows[$id] = [
                'unread_class' => str_contains($row->getAttribute('class'), 'admin-row--unread'),
                'status' => trim($cells->item($cells->length - 1)->textContent),
            ];
        }

        return $rows;
    }

    private function isRead(int $id): bool
    {
        return (int) ($this->submissions->findForAdmin($id)['is_read'] ?? -1) === 1;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{0: int|null, 1: int}>
     */
    private function snapshot(array $ids): array
    {
        $state = [];
        foreach ($ids as $id) {
            $row = $this->submissions->findForAdmin($id);
            $state[$id] = [$row === null ? null : (int) $row['is_read'], count($this->submissions->valuesFor($id))];
        }

        return $state;
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
