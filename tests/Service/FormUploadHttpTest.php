<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Mail\FormSubmissionBuilder;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSpamGuard;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\FormFixture;

/**
 * The upload field end to end, over real HTTP with real multipart uploads
 * (FORMS.md, "Bestand uploaden"): a visitor sends a file, the server keeps it
 * outside the webroot tied to its submission and field, an administrator
 * downloads it, and deleting the submission takes the file with it.
 *
 * PHP's own built-in server on this checkout (Tests\Support\BuiltInServer),
 * so it runs wherever PHP runs, with two things of its own: a storage
 * directory in the system's temporary directory (CONTACT_ATTACHMENTS_PATH),
 * so the test can see every file that is written and removed, and a mail
 * server that is not there, so no notification leaves. A form that keeps its
 * submissions accepts them regardless; one that does not is where that
 * matters, and a test says so.
 *
 * A second server runs with a post_max_size of 1 MB, to prove that a request
 * PHP throws away whole is answered, not silently lost. It runs with
 * display_errors off, as a live site does: PHP's own warning about such a
 * request is printed before the script starts, and printed output would
 * leave the endpoint unable to set its status.
 */
final class FormUploadHttpTest extends TestCase
{
    private const SUBMIT = '/api/form-submit.php';

    private const PAGE = '/zz-upload-test';

    private static ?BuiltInServer $server = null;

    private static ?BuiltInServer $small = null;

    /** The same, with this environment's own mail server: where a form that keeps nothing can succeed. */
    private static ?BuiltInServer $mailing = null;

    private static string $storage = '';

    private AdminTestSession $accounts;

    private FormRepository $forms;

    /** @var list<int> */
    private array $formIds = [];

    /** @var list<string> the local files curl uploaded */
    private array $uploads = [];

    public static function setUpBeforeClass(): void
    {
        self::$storage = sys_get_temp_dir() . '/mygdala-upload-http-' . bin2hex(random_bytes(4));
        mkdir(self::$storage . '/contact-attachments', 0777, true);

        $environment = ['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9', 'CONTACT_ATTACHMENTS_PATH' => self::$storage];

        self::$server = BuiltInServer::start($environment);
        self::$small = BuiltInServer::start($environment, null, ['post_max_size' => '1M', 'upload_max_filesize' => '512K', 'display_errors' => '0']);
        self::$mailing = BuiltInServer::start(['CONTACT_ATTACHMENTS_PATH' => self::$storage]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$small?->stop();
        self::$mailing?->stop();
        self::$server = null;
        self::$small = null;
        self::$mailing = null;

        foreach (glob(self::$storage . '/contact-attachments/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir(self::$storage . '/contact-attachments');
        @rmdir(self::$storage);
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        $this->accounts = new AdminTestSession();
        $this->forms = new FormRepository();
        $this->clearRateLimit();
        FormCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        $submissions = new FormSubmissionRepository();
        foreach ($this->formIds as $id) {
            foreach ($submissions->findAllForAdmin($id) as $submission) {
                $submissions->delete((int) $submission['id']);
            }
            $this->forms->delete($id);
        }
        $this->formIds = [];

        $this->accounts->forget();
        $this->clearRateLimit();
        FormCatalog::clearCache();

        foreach ($this->uploads as $path) {
            @unlink($path);
        }
        $this->uploads = [];
    }

    // ------------------------------------------------------------ submitting

    public function testAValidFileIsStoredOutsideTheWebrootTiedToItsSubmissionAndField(): void
    {
        $formId = $this->createForm();
        $pdf = $this->pdf();

        $response = $this->submit($formId, ['bijlage' => $this->file('Offerte 2026.pdf', $pdf)]);

        $this->assertSame(200, $response['status'], $response['body']);
        [$submission] = $this->submissions($formId);
        [$file] = (new FormSubmissionRepository())->attachmentsFor((int) $submission['id']);

        $this->assertSame('bijlage', $file['field_key']);
        $this->assertSame('Offerte 2026.pdf', $file['original_filename']);
        $this->assertSame('application/pdf', $file['mime_type']);
        $this->assertSame(strlen($pdf), (int) $file['file_size']);
        $this->assertSame(hash('sha256', $pdf), $file['sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', (string) $file['stored_filename'], 'a random name, never the visitor\'s');
        $this->assertStringNotContainsString('/', (string) $file['stored_filename'], 'a name, not a machine path');

        $stored = self::$storage . '/contact-attachments/' . $file['stored_filename'];
        $this->assertFileExists($stored);
        $this->assertSame($pdf, file_get_contents($stored));
        $this->assertStringNotContainsString(dirname(__DIR__, 2), realpath($stored), 'outside the checkout the server serves');

        $values = (new FormSubmissionRepository())->valuesFor((int) $submission['id']);
        $this->assertSame('file', $values[0]['field_type']);
        $this->assertStringStartsWith('Offerte 2026.pdf (', (string) $values[0]['value']);
    }

    public function testAJpegAndAPngAreAcceptedToo(): void
    {
        $formId = $this->createForm();

        foreach (['foto.jpg' => 'jpg', 'scan.png' => 'png'] as $name => $kind) {
            $this->clearRateLimit();
            $response = $this->submit($formId, ['bijlage' => $this->file($name, $this->image($kind))]);
            $this->assertSame(200, $response['status'], $name . ': ' . $response['body']);
        }

        $this->assertCount(2, $this->submissions($formId));
    }

    /**
     * Every refusal: 422, the message beside the upload field, and nothing
     * written — no submission, no file.
     */
    public function testARefusedFileWritesNothing(): void
    {
        $formId = $this->createForm(required: true, maxBytes: 1024 * 1024);
        $before = $this->storedFiles();

        $cases = [
            'required without a file' => [[], 'Kies een bestand bij Bijlage.'],
            'too large' => [['bijlage' => $this->file('groot.pdf', '%PDF-1.4' . str_repeat('x', 1024 * 1024 + 10))], 'te groot'],
            'wrong extension' => [['bijlage' => $this->file('script.php', '<?php echo 1;')], 'niet toegestaan'],
            'svg' => [['bijlage' => $this->file('logo.svg', '<svg onload="alert(1)"/>')], 'niet toegestaan'],
            'bytes do not match the name' => [['bijlage' => $this->file('scan.pdf', $this->image('png'))], 'geen echt PDF-bestand'],
            'a script called .jpg' => [['bijlage' => $this->file('foto.jpg', '<?php echo 1;')], 'geen echt JPG-bestand'],
        ];

        foreach ($cases as $label => [$files, $message]) {
            $this->clearRateLimit();
            $response = $this->submit($formId, $files);
            $body = json_decode($response['body'], true);

            $this->assertSame(422, $response['status'], $label);
            $this->assertStringContainsString($message, (string) ($body['errors']['bijlage'] ?? ''), $label);
        }

        $this->assertSame([], $this->submissions($formId));
        $this->assertSame($before, $this->storedFiles());
    }

    /** A file under a name the form has no upload field for is never looked at. */
    public function testAFileUnderAnotherNameIsIgnored(): void
    {
        $formId = $this->createForm();

        $response = $this->submit($formId, ['bestand' => $this->file('x.pdf', $this->pdf()), 'naam' => $this->file('y.pdf', $this->pdf())]);

        $this->assertSame(200, $response['status'], $response['body']);
        [$submission] = $this->submissions($formId);
        $this->assertSame([], (new FormSubmissionRepository())->attachmentsFor((int) $submission['id']));
    }

    /**
     * Another field failing without JavaScript: back to the page, nothing
     * stored; the reselect message is the validator's (FormUploadTest).
     */
    public function testAnotherFieldFailingStoresNoFile(): void
    {
        $formId = $this->createForm();
        $before = $this->storedFiles();

        $response = $this->submit($formId, ['bijlage' => $this->file('x.pdf', $this->pdf())], ['naam' => ''], false);

        $this->assertSame(303, $response['status']);
        $this->assertStringContainsString('form-status=error', $response['location']);
        $this->assertSame([], $this->submissions($formId));
        $this->assertSame($before, $this->storedFiles());
    }

    /**
     * A form that keeps nothing: the e-mail is the delivery. With no mail
     * server the visitor hears it failed, and the file that was stored for
     * the e-mail is gone again — no orphan.
     */
    public function testAFormThatKeepsNothingLeavesNoFileBehind(): void
    {
        $formId = $this->createForm(store: false);
        $before = $this->storedFiles();

        $response = $this->submit($formId, ['bijlage' => $this->file('x.pdf', $this->pdf())]);

        $this->assertSame(500, $response['status'], 'no mail server, so nothing was delivered');
        $this->assertSame($before, $this->storedFiles());
    }

    /** PHP refusing the file itself (upload_max_filesize) is a message, not a lost file. */
    public function testPhpsOwnSizeLimitIsAnsweredBesideTheField(): void
    {
        $this->requireSmallServer();
        $formId = $this->createForm();

        $response = $this->submit($formId, ['bijlage' => $this->file('x.pdf', '%PDF-1.4' . str_repeat('x', 700 * 1024))], [], true, self::$small);
        $body = json_decode($response['body'], true);

        $this->assertSame(422, $response['status'], $response['body']);
        $this->assertStringContainsString('te groot', (string) ($body['errors']['bijlage'] ?? ''));
        $this->assertStringContainsString('0,5 MB', (string) ($body['errors']['bijlage'] ?? ''), 'the limit this server really has');
    }

    /**
     * A request larger than post_max_size arrives with nothing in it, the
     * form key included. It is answered as too large — to fetch() with 413,
     * to a browser with a redirect to its page and the instance its form's
     * action named — never with the generic failure, and never silently.
     */
    public function testARequestPhpThrowsAwayIsAnsweredAsTooLarge(): void
    {
        $this->requireSmallServer();
        $formId = $this->createForm();
        $token = FormRenderState::tokenFor('zz-upload-test', 'upload');
        $big = ['bijlage' => $this->file('x.pdf', '%PDF-1.4' . str_repeat('x', 1500 * 1024))];

        $json = $this->submit($formId, $big, [], true, self::$small, '?instance=' . $token);
        $this->assertSame(413, $json['status']);
        $this->assertStringContainsString('te groot', (string) (json_decode($json['body'], true)['message'] ?? ''));

        $plain = $this->submit($formId, $big, [], false, self::$small, '?instance=' . $token, ['Referer: http://127.0.0.1:' . self::$small->port . self::PAGE]);
        $this->assertSame(303, $plain['status']);
        $this->assertSame(self::PAGE . '?form-status=error&form=' . $token . '#' . $token, $plain['location']);
        $this->assertStringContainsString('Set-Cookie:', $plain['headers'], 'the message waits in the public form session');

        $foreign = $this->submit($formId, $big, [], false, self::$small, '?instance=' . $token, ['Referer: https://evil.example/phish']);
        $this->assertSame(303, $foreign['status']);
        $this->assertStringStartsWith('/?', $foreign['location'], 'a foreign Referer is never a destination');
        $this->assertSame([], $this->submissions($formId));
    }

    // ------------------------------------- a form that keeps nothing: the mail budget

    /**
     * A form that keeps nothing delivers its files only by e-mail. Files that
     * together exceed what one notification carries
     * (FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET) are refused before
     * anything happens — no thanks, no submission, no file, no row — to
     * fetch() and to a browser without JavaScript alike.
     */
    public function testAFormThatKeepsNothingRefusesFilesTogetherOverTheMailBudget(): void
    {
        [$budget, $half] = $this->budget();
        $formId = $this->createForm(store: false, maxBytes: FormFileTypes::MAX_BYTES, secondUpload: true);
        $storedBefore = $this->storedFiles();
        $rowsBefore = $this->attachmentRows();
        $files = fn (): array => ['bijlage' => $this->file('a.pdf', $this->pdfOf($half)), 'tekening' => $this->file('b.pdf', $this->pdfOf($budget - $half + 1))];

        $json = $this->submit($formId, $files());
        $body = json_decode($json['body'], true);
        $this->assertSame(422, $json['status'], $json['body']);
        $this->assertFalse($body['ok']);
        foreach (['bijlage', 'tekening'] as $key) {
            $this->assertStringContainsString('samen te groot', (string) ($body['errors'][$key] ?? ''), $key);
        }

        $this->clearRateLimit();
        $plain = $this->submit($formId, $files(), [], false);
        $this->assertSame(303, $plain['status']);
        $this->assertStringContainsString('form-status=error', $plain['location'], 'no success status');

        $this->assertSame([], $this->submissions($formId));
        $this->assertSame($storedBefore, $this->storedFiles(), 'no file left behind');
        $this->assertSame($rowsBefore, $this->attachmentRows(), 'no attachment row left behind');
    }

    /** Under the budget and exactly on it, a form that keeps nothing mails its files and keeps none. */
    public function testAFormThatKeepsNothingAcceptsFilesUpToTheMailBudget(): void
    {
        [$budget, $half] = $this->budget();
        $this->requireMailingServer();
        $formId = $this->createForm(store: false, maxBytes: FormFileTypes::MAX_BYTES, secondUpload: true);
        $storedBefore = $this->storedFiles();
        $rowsBefore = $this->attachmentRows();

        foreach (['under' => [1024 * 1024, 1024 * 1024], 'exactly on' => [$half, $budget - $half]] as $label => [$a, $b]) {
            $this->clearRateLimit();
            $response = $this->submit($formId, ['bijlage' => $this->file('a.pdf', $this->pdfOf($a)), 'tekening' => $this->file('b.pdf', $this->pdfOf($b))], [], true, self::$mailing);

            $this->assertSame(200, $response['status'], $label . ': ' . $response['body']);
            $this->assertTrue(json_decode($response['body'], true)['ok'] ?? null, $label);
        }

        $this->assertSame([], $this->submissions($formId), 'it keeps nothing');
        $this->assertSame($storedBefore, $this->storedFiles(), 'the mailed files are not kept either');
        $this->assertSame($rowsBefore, $this->attachmentRows());
    }

    /**
     * A form that keeps its submissions may take more than the mail carries:
     * every file stays downloadable with the submission, and only the e-mail
     * leaves the rest out (FormSubmissionHandler::mailAttachments(), proven
     * in FormUploadTest).
     */
    public function testAFormThatKeepsItsSubmissionsTakesMoreThanTheMailBudget(): void
    {
        [$budget, $half] = $this->budget();
        $formId = $this->createForm(maxBytes: FormFileTypes::MAX_BYTES, secondUpload: true);
        $a = $this->pdfOf($half);
        $b = $this->pdfOf($budget - $half + 1024);

        $response = $this->submit($formId, ['bijlage' => $this->file('a.pdf', $a), 'tekening' => $this->file('b.pdf', $b)]);
        $this->assertSame(200, $response['status'], $response['body']);

        [$submission] = $this->submissions($formId);
        $files = (new FormSubmissionRepository())->attachmentsFor((int) $submission['id']);
        $this->assertSame(['bijlage', 'tekening'], array_column($files, 'field_key'));
        $this->assertGreaterThan($budget, array_sum(array_map('intval', array_column($files, 'file_size'))));

        [$session] = $this->accounts->signIn(['forms.submissions']);
        foreach ($files as $index => $file) {
            $download = self::$server->request('GET', $this->downloadUrl((int) $submission['id'], (int) $file['id']), $session);
            $this->assertSame(200, $download['status']);
            $this->assertSame([$a, $b][$index], $download['body'], (string) $file['field_key'] . ' is downloadable');
        }

        [, $leftOut] = \App\Service\Forms\FormSubmissionHandler::mailAttachments(array_map(
            static fn (array $file): array => ['field_key' => (string) $file['field_key'], 'size' => (int) $file['file_size'], 'path' => '', 'mail_name' => '', 'mime' => ''],
            $files
        ));
        $this->assertSame(['tekening'], $leftOut, 'the e-mail leaves out what does not fit, as before');
    }

    // ------------------------------------------------------------- downloading

    public function testAnAdministratorDownloadsTheFileAsAnAttachment(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        [$session] = $this->accounts->signIn(['forms.submissions']);

        $response = self::$server->request('GET', $this->downloadUrl($submissionId, (int) $file['id']), $session);

        $this->assertSame(200, $response['status']);
        $this->assertSame($this->pdf(), $response['body']);
        $this->assertSame('application/pdf', BuiltInServer::header($response, 'Content-Type'));
        $this->assertStringStartsWith('attachment; filename="', BuiltInServer::header($response, 'Content-Disposition'));
        $this->assertStringContainsString("filename*=UTF-8''", BuiltInServer::header($response, 'Content-Disposition'));
        $this->assertSame('nosniff', BuiltInServer::header($response, 'X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', BuiltInServer::header($response, 'Content-Security-Policy'));
        $this->assertStringContainsString('no-store', BuiltInServer::header($response, 'Cache-Control'));
    }

    /**
     * Only a signed-in administrator with `forms.submissions`, and only the
     * file the pair of ids names. Every way past that is refused.
     */
    public function testADownloadIsRefusedToEveryoneAndEverythingElse(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        [$otherId, $otherFile] = $this->storedSubmission();
        $fileId = (int) $file['id'];

        $anonymous = self::$server->request('GET', $this->downloadUrl($submissionId, $fileId));
        $this->assertSame(302, $anonymous['status']);
        $this->assertStringContainsString('login', $anonymous['location']);
        $this->assertStringNotContainsString('%PDF', $anonymous['body']);

        [$builder] = $this->accounts->signIn(['forms.manage']);
        $this->assertSame(403, self::$server->request('GET', $this->downloadUrl($submissionId, $fileId), $builder)['status'], 'building forms is not reading submissions');

        [$session] = $this->accounts->signIn(['forms.submissions']);
        $idor = [
            $this->downloadUrl($submissionId, (int) $otherFile['id']),
            $this->downloadUrl($otherId, $fileId),
            $this->downloadUrl($submissionId, 999999999),
            '/api/admin/form-submission-attachment.php?submission=' . $submissionId . '&file=../../../../etc/passwd',
            '/api/admin/form-submission-attachment.php?submission=' . $submissionId . '&file=' . urlencode((string) $file['stored_filename']),
            '/api/admin/form-submission-attachment.php?submission=' . $submissionId,
            '/api/admin/form-submission-attachment.php?id=' . $submissionId,
        ];

        foreach ($idor as $url) {
            $response = self::$server->request('GET', $url, $session);
            $this->assertSame(404, $response['status'], $url);
            $this->assertStringNotContainsString('%PDF', $response['body'], $url);
        }

        // The right pair still works.
        $this->assertSame(200, self::$server->request('GET', $this->downloadUrl($otherId, (int) $otherFile['id']), $session)['status']);
    }

    public function testTheSubmissionScreenLinksEachFileByItsIds(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        [$session] = $this->accounts->signIn(['forms.submissions']);

        $page = self::$server->request('GET', '/admin/form-submission.php?id=' . $submissionId, $session);

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString(htmlspecialchars($this->downloadUrl($submissionId, (int) $file['id'])), $page['body']);
        $this->assertStringContainsString('offerte.pdf', $page['body']);
        $this->assertStringNotContainsString((string) $file['stored_filename'], $page['body'], 'the stored name never reaches a page');
    }

    /** A contact-block attachment from before this phase stays readable. */
    public function testAnOlderAttachmentWithoutAFieldStaysDownloadable(): void
    {
        [$submissionId] = $this->storedSubmission(withFile: false);
        $name = bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(self::$storage . '/contact-attachments/' . $name, $this->pdf());

        Database::connection()->prepare(
            "INSERT INTO form_submission_attachments (submission_id, field_key, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (:id, NULL, :name, 'oud.pdf', 'application/pdf', :size, NOW())"
        )->execute(['id' => $submissionId, 'name' => $name, 'size' => strlen($this->pdf())]);

        [$file] = (new FormSubmissionRepository())->attachmentsFor($submissionId);
        [$session] = $this->accounts->signIn(['forms.submissions']);

        $page = self::$server->request('GET', '/admin/form-submission.php?id=' . $submissionId, $session);
        $this->assertStringContainsString('oud.pdf', $page['body']);
        $this->assertSame(200, self::$server->request('GET', $this->downloadUrl($submissionId, (int) $file['id']), $session)['status']);
    }

    // --------------------------------------------------------------- deleting

    public function testDeletingASubmissionDeletesItsFilesAndNoOtherOnes(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        [, $otherFile] = $this->storedSubmission();
        [$session, $csrf] = $this->accounts->signIn(['forms.submissions']);

        $response = self::$server->request('POST', '/api/admin/delete-form-submission.php', $session, ['csrf_token' => $csrf, 'id' => (string) $submissionId]);

        $this->assertSame(302, $response['status']);
        $this->assertNull((new FormSubmissionRepository())->findForAdmin($submissionId));
        $this->assertFileDoesNotExist(self::$storage . '/contact-attachments/' . $file['stored_filename']);
        $this->assertFileExists(self::$storage . '/contact-attachments/' . $otherFile['stored_filename'], 'another submission\'s file is untouched');
    }

    /** A file that is already gone does not stop the submission from going. */
    public function testDeletingASubmissionWhoseFileIsMissingStillWorks(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        unlink(self::$storage . '/contact-attachments/' . $file['stored_filename']);
        [$session, $csrf] = $this->accounts->signIn(['forms.submissions']);

        $page = self::$server->request('GET', '/admin/form-submission.php?id=' . $submissionId, $session);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Bestand ontbreekt', $page['body']);
        $this->assertSame(404, self::$server->request('GET', $this->downloadUrl($submissionId, (int) $file['id']), $session)['status']);

        $response = self::$server->request('POST', '/api/admin/delete-form-submission.php', $session, ['csrf_token' => $csrf, 'id' => (string) $submissionId]);

        $this->assertSame(302, $response['status']);
        $this->assertNull((new FormSubmissionRepository())->findForAdmin($submissionId));
    }

    /** Without the token nothing is deleted, the file included. */
    public function testDeletingWithoutTheTokenDeletesNothing(): void
    {
        [$submissionId, $file] = $this->storedSubmission();
        [$session] = $this->accounts->signIn(['forms.submissions']);

        $response = self::$server->request('POST', '/api/admin/delete-form-submission.php', $session, ['csrf_token' => 'nope', 'id' => (string) $submissionId]);

        $this->assertSame(403, $response['status']);
        $this->assertNotNull((new FormSubmissionRepository())->findForAdmin($submissionId));
        $this->assertFileExists(self::$storage . '/contact-attachments/' . $file['stored_filename']);
    }

    // ---------------------------------------------------------------- e-mail

    /** What the notification says about a file that did not fit. */
    public function testTheNotificationNamesAFileThatDidNotFit(): void
    {
        RequestLanguage::set('nl', true);
        $form = FormCatalog::find($this->createForm());
        $values = [
            ['field_key' => 'bijlage', 'field_label' => 'Bijlage', 'field_type' => 'file', 'value' => '<b>.pdf (9,0 MB)'],
        ];

        $kept = FormSubmissionBuilder::build($form, $values, ['attachment_names' => [], 'not_attached' => ['bijlage'], 'kept_in_cms' => true]);
        $this->assertStringContainsString('niet bijgevoegd, te groot voor deze e-mail; te downloaden bij de inzending in het CMS', $kept['text']);
        $this->assertStringContainsString('&lt;b&gt;.pdf', $kept['html'], 'a file name is escaped like any answer');
        $this->assertStringNotContainsString('Bijlage:', strtok($kept['text'], "\n") . "\n", 'no attachment line when nothing was attached');

        $lost = FormSubmissionBuilder::build($form, $values, ['attachment_names' => [], 'not_attached' => ['bijlage'], 'kept_in_cms' => false]);
        $this->assertStringContainsString('het bestand is niet bewaard', $lost['text']);

        $attached = FormSubmissionBuilder::build($form, [['field_key' => 'bijlage', 'field_label' => 'Bijlage', 'field_type' => 'file', 'value' => 'a.pdf (1 kB)']], ['attachment_names' => ['bijlage.pdf']]);
        $this->assertStringContainsString("Bijlage: bijlage.pdf\n", $attached['text'], 'the generic name, as the contact form always did');
        RequestLanguage::reset();
    }

    // ---------------------------------------------------------------- helpers

    private function createForm(bool $required = false, int $maxBytes = 5 * 1024 * 1024, bool $store = true, bool $secondUpload = false): int
    {
        $id = $this->forms->create([
            'name' => 'Uploadtest',
            'internal_key' => FormCatalog::internalKeyFor('zz test upload', $this->forms),
            'is_active' => true,
            'notification_email' => 'upload@example.com',
            'reply_to_field_key' => null,
            'store_submissions' => $store,
        ]);
        $this->formIds[] = $id;

        FormFixture::formWords($id, ['nl' => ['submit_label' => 'Verstuur', 'success_message' => 'Bedankt.']]);
        FormFixture::field($id, ['field_key' => 'bijlage', 'field_type' => 'file', 'is_required' => $required, 'layout_width' => 'half', 'file_types' => ['jpg', 'png', 'pdf'], 'file_max_bytes' => $maxBytes], ['nl' => ['label' => 'Bijlage']]);
        if ($secondUpload) {
            FormFixture::field($id, ['field_key' => 'tekening', 'field_type' => 'file', 'is_required' => false, 'layout_width' => 'half', 'file_types' => ['pdf'], 'file_max_bytes' => $maxBytes], ['nl' => ['label' => 'Tekening']]);
        }
        FormFixture::field($id, ['field_key' => 'naam', 'field_type' => 'text', 'is_required' => true], ['nl' => ['label' => 'Naam']]);

        return $id;
    }

    /**
     * @param array<string, \CURLFile> $files
     * @param array<string, string>    $overrides
     * @param list<string>             $headers
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function submit(int $formId, array $files, array $overrides = [], bool $asJson = true, ?BuiltInServer $server = null, string $query = '', array $headers = []): array
    {
        $fields = $overrides + [
            'form-key' => (string) $this->forms->find($formId)['internal_key'],
            'form-instance' => FormRenderState::tokenFor('zz-upload-test', 'upload'),
            'form-source' => self::PAGE,
            FormSpamGuard::TIMESTAMP_FIELD => (string) (time() - 30),
            'naam' => 'Test Bezoeker',
        ];

        return ($server ?? self::$server)->request(
            'POST',
            self::SUBMIT . $query,
            null,
            $fields,
            $files,
            array_merge($asJson ? ['Accept: application/json'] : [], $headers)
        );
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function storedSubmission(bool $withFile = true): array
    {
        $formId = $this->createForm();
        $this->clearRateLimit();
        $response = $this->submit($formId, $withFile ? ['bijlage' => $this->file('offerte.pdf', $this->pdf())] : []);
        $this->assertSame(200, $response['status'], $response['body']);

        [$submission] = $this->submissions($formId);
        $files = (new FormSubmissionRepository())->attachmentsFor((int) $submission['id']);

        return [(int) $submission['id'], $files[0] ?? []];
    }

    /** @return list<array<string, mixed>> */
    private function submissions(int $formId): array
    {
        return (new FormSubmissionRepository())->findAllForAdmin($formId);
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        $files = array_map('basename', glob(self::$storage . '/contact-attachments/*') ?: []);
        sort($files);

        return $files;
    }

    private function downloadUrl(int $submissionId, int $fileId): string
    {
        return '/api/admin/form-submission-attachment.php?submission=' . $submissionId . '&file=' . $fileId;
    }

    private function file(string $name, string $bytes): \CURLFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);
        $this->uploads[] = $path;

        return new \CURLFile($path, 'application/octet-stream', $name);
    }

    /** A PDF of exactly $bytes bytes. */
    private function pdfOf(int $bytes): string
    {
        return str_pad("%PDF-1.4\n", $bytes, 'x');
    }

    /**
     * The mail budget and half of it, rounded down; skips where PHP takes no
     * single file that large (each file must pass its field on its own).
     *
     * @return array{0: int, 1: int}
     */
    private function budget(): array
    {
        $budget = \App\Service\Forms\FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET;
        $half = intdiv($budget, 2);

        if (FormFileTypes::systemMaxBytes() < $budget - $half + 1024) {
            $this->markTestSkipped('PHP here takes no single file of half the mail budget');
        }

        return [$budget, $half];
    }

    /** Every attachment row there is, by id: nothing may be left behind. */
    private function attachmentRows(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM form_submission_attachments')->fetchColumn();
    }

    /** A server with this environment's own mail settings, whose mail server answers. */
    private function requireMailingServer(): void
    {
        $host = (string) ($_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?: '');
        $port = (int) ($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 0);
        $socket = $host === '' || $port < 1 ? false : @fsockopen($host, $port, $errorCode, $errorMessage, 1.0);

        if (self::$mailing === null || !self::$mailing->answers() || $socket === false) {
            $this->markTestSkipped('no reachable mail server here, and a form that keeps nothing can only succeed by sending');
        }

        fclose($socket);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    private function image(string $kind): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        $kind === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function requireSmallServer(): void
    {
        if (self::$small === null || !self::$small->answers()) {
            $this->markTestSkipped('could not start the second built-in server');
        }
    }

    private function clearRateLimit(): void
    {
        Database::connection()->exec('DELETE FROM contact_rate_limit_hits');
    }
}
