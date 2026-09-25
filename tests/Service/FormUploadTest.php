<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Forms\FormDefinition;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSubmissionHandler;
use App\Service\Forms\FormUpload;
use App\Service\Forms\FormUploadInspector;
use App\Service\Forms\FormValidator;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/form.php';

/**
 * The upload field (FORMS.md, "Bestand uploaden") without a database or a
 * web server: the closed list of kinds and sizes, the inspection of one
 * $_FILES entry, the validator reading $_FILES by the definition, the control
 * the renderer prints, and what the notification says about files.
 *
 * Real files, written to a temporary directory: a JPEG, PNG, GIF and WebP
 * drawn with GD, a minimal PDF, and the hostile ones — a script, an SVG, a
 * PNG called `.pdf`. is_uploaded_file() cannot be true outside a real
 * upload, so the inspector gets a stand-in that says yes, except in the one
 * test about a path that was not uploaded. The same code over real HTTP is
 * Tests\Service\FormUploadHttpTest.
 */
final class FormUploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mygdala-form-upload-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        RequestLanguage::set('nl', true);
    }

    protected function tearDown(): void
    {
        RequestLanguage::reset();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    // ------------------------------------------------------- the closed lists

    public function testTheKindsAreAClosedListWithoutAnythingActive(): void
    {
        $this->assertSame(['jpg', 'png', 'webp', 'gif', 'pdf'], FormFileTypes::keys());

        foreach (FormFileTypes::keys() as $key) {
            foreach (['svg', 'svgz', 'zip', 'html', 'htm', 'php', 'phtml', 'phar', 'js', 'exe'] as $never) {
                $this->assertNotContains($never, FormFileTypes::extensions($key), $key);
            }
        }

        $this->assertSame(['jpg', 'pdf'], FormFileTypes::fromStored('pdf, jpg,svg,../x'), 'unknown keys dropped, list order kept');
        $this->assertSame(FormFileTypes::DEFAULT_TYPES, FormFileTypes::fromStored('svg,zip'), 'nothing known is the default, never nothing');
        $this->assertSame(FormFileTypes::DEFAULT_TYPES, FormFileTypes::fromStored(null));
        $this->assertSame('png,pdf', FormFileTypes::toStored(['pdf', 'png', 'png']));
        $this->assertNull(FormFileTypes::toStored(['svg', 'image/png']));
        $this->assertSame('.jpg,.jpeg,image/jpeg,.pdf,application/pdf', FormFileTypes::acceptAttribute(['jpg', 'pdf']));
    }

    /** No stored limit can exceed what the application and PHP accept. */
    public function testASizeNeverExceedsTheCeiling(): void
    {
        $system = FormFileTypes::systemMaxBytes();

        $this->assertLessThanOrEqual(FormFileTypes::MAX_BYTES, $system);
        $this->assertNotSame([], FormFileTypes::sizeChoices());
        foreach (FormFileTypes::sizeChoices() as $bytes) {
            $this->assertLessThanOrEqual($system, $bytes);
        }

        $this->assertSame(min(FormFileTypes::DEFAULT_MAX_BYTES, $system), FormFileTypes::effectiveMaxBytes(null));
        $this->assertSame($system, FormFileTypes::effectiveMaxBytes(500 * 1024 * 1024), 'a hand-edited row is capped');
        $this->assertSame(1024, FormFileTypes::effectiveMaxBytes('1024'));
        $this->assertFalse(FormFileTypes::isSizeChoice(FormFileTypes::MAX_BYTES + 1));
        $this->assertFalse(FormFileTypes::isSizeChoice(3 * 1024 * 1024), 'only the listed sizes');

        $this->assertSame(35 * 1024 * 1024, FormFileTypes::iniBytes('35M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, FormFileTypes::iniBytes('2g'));
        $this->assertSame(512 * 1024, FormFileTypes::iniBytes('512K'));
        $this->assertSame(0, FormFileTypes::iniBytes(''));
        $this->assertSame(0, FormFileTypes::iniBytes('lots'));
        $this->assertSame('5 MB', FormFileTypes::sizeLabel(5 * 1024 * 1024));
    }

    // ---------------------------------------------------------- the inspector

    public function testEveryAcceptedKindIsRecognisedByItsBytes(): void
    {
        $field = $this->field(['jpg', 'png', 'gif', 'webp', 'pdf']);

        foreach (['foto.jpg' => 'jpg', 'foto.JPEG' => 'jpg', 'scan.png' => 'png', 'anim.gif' => 'gif', 'tekening.pdf' => 'pdf'] as $name => $kind) {
            $result = $this->inspect($this->entry($name, $this->bytes($kind)), $field);

            $this->assertInstanceOf(FormUpload::class, $result, $name . ': ' . (is_string($result) ? $result : ''));
            $this->assertSame($kind, $result->typeKey);
            $this->assertSame($name, $result->originalName);
            $this->assertSame(FormFileTypes::mime($kind), $result->mime());
            $this->assertSame(hash('sha256', $this->bytes($kind)), $result->sha256);
        }

        if (function_exists('imagewebp')) {
            $this->assertInstanceOf(FormUpload::class, $this->inspect($this->entry('x.webp', $this->bytes('webp')), $field));
        }
    }

    public function testNoFileIsNoAnswerAndNotAnError(): void
    {
        $field = $this->field(['pdf']);

        $this->assertNull($this->inspect(null, $field));
        $this->assertNull($this->inspect(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], $field));
    }

    /** PHP's own refusals say something a visitor can act on, never nothing. */
    public function testPhpsOwnRefusalsBecomeMessages(): void
    {
        $field = $this->field(['pdf'], 2 * 1024 * 1024);

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
            $this->assertStringContainsString('te groot', (string) $this->inspect($this->failed($error), $field));
            $this->assertStringContainsString('2 MB', (string) $this->inspect($this->failed($error), $field));
        }

        $this->assertStringContainsString('niet helemaal', (string) $this->inspect($this->failed(UPLOAD_ERR_PARTIAL), $field));

        foreach ([UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION, 99] as $error) {
            $message = @$this->inspect($this->failed($error), $field);
            $this->assertIsString($message);
            $this->assertStringContainsString('niet worden ontvangen', $message);
        }
    }

    public function testATooLargeOrEmptyFileIsRefusedByItsRealSize(): void
    {
        $field = $this->field(['pdf'], 1024);

        $big = $this->entry('groot.pdf', '%PDF-1.4' . str_repeat('x', 2000));
        $big['size'] = 10; // the client's figure is never believed
        $this->assertStringContainsString('te groot', (string) $this->inspect($big, $field));

        $this->assertStringContainsString('leeg', (string) $this->inspect($this->entry('leeg.pdf', ''), $field));
    }

    public function testAPathThatWasNotUploadedIsRefused(): void
    {
        $inspector = new FormUploadInspector(static fn (string $path): bool => false);

        $this->assertStringContainsString(
            'geen geldige upload',
            (string) $inspector->inspect($this->entry('x.pdf', $this->bytes('pdf')), $this->field(['pdf']))
        );
    }

    /** The name decides the kind it claims, the bytes must then be exactly that. */
    public function testSpoofedAndForbiddenFilesAreRefused(): void
    {
        $field = $this->field(['jpg', 'png', 'pdf']);

        $cases = [
            'script.php' => '<?php echo 1;',
            'foto.php.jpg' => '<?php system($_GET["c"]); ?>',
            'pagina.html' => '<html><script>alert(1)</script></html>',
            'tekening.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
            'archief.zip' => "PK\x03\x04" . str_repeat("\0", 30),
            'scan.pdf' => $this->bytes('png'),
            'foto.jpg' => $this->bytes('pdf'),
            'plaatje.png' => $this->bytes('jpg'),
            'geen-extensie' => $this->bytes('pdf'),
            'dubbel.pdf.exe' => $this->bytes('pdf'),
        ];

        foreach ($cases as $name => $bytes) {
            $this->assertIsString($this->inspect($this->entry($name, $bytes), $field), $name . ' must be refused');
        }

        // A kind the field did not tick is refused even when it is honest.
        $this->assertIsString($this->inspect($this->entry('anim.gif', $this->bytes('gif')), $field));

        // A real JPEG with a second extension in its name is a JPEG, stored as .jpg.
        $double = $this->inspect($this->entry('foto.php.jpg', $this->bytes('jpg')), $field);
        $this->assertInstanceOf(FormUpload::class, $double);
        $this->assertSame('jpg', $double->storedExtension());
    }

    /** The client's MIME type is not even read. */
    public function testTheClientsMimeTypeDecidesNothing(): void
    {
        $field = $this->field(['pdf']);

        $lying = $this->entry('scan.pdf', $this->bytes('pdf'));
        $lying['type'] = 'text/html';
        $this->assertInstanceOf(FormUpload::class, $this->inspect($lying, $field));

        $claiming = $this->entry('scan.pdf', '<script>');
        $claiming['type'] = 'application/pdf';
        $this->assertIsString($this->inspect($claiming, $field));
    }

    /** One file per field: whatever PHP builds for `name[]` is refused. */
    public function testMalformedAndMultiFileEntriesAreRefused(): void
    {
        $field = $this->field(['pdf']);
        $path = $this->write($this->bytes('pdf'));

        $multi = [
            'name' => ['a.pdf', 'b.pdf'],
            'type' => ['application/pdf', 'application/pdf'],
            'tmp_name' => [$path, $path],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [10, 10],
        ];

        $this->assertStringContainsString('één bestand', (string) $this->inspect($multi, $field));
        $this->assertIsString($this->inspect('a.pdf', $field));
        $this->assertIsString($this->inspect(['name' => 'a.pdf'], $field));
        $this->assertIsString($this->inspect(['name' => 'a.pdf', 'tmp_name' => [$path], 'error' => 0], $field));
    }

    /** A file name is a label: no path, no control, direction or quote character survives. */
    public function testTheOriginalNameIsCleanedForDisplayOnly(): void
    {
        $this->assertSame('passwd.jpg', FormUploadInspector::cleanName('../../etc/passwd.jpg'));
        $this->assertSame('y.jpg', FormUploadInspector::cleanName('C:\\x\\y.jpg'));
        $this->assertSame('a.phpx.jpg', FormUploadInspector::cleanName("a.php\0x.jpg"));
        $this->assertSame('gpj.exe', FormUploadInspector::cleanName("\u{202E}gpj.exe"));
        $this->assertSame('ab.pdf', FormUploadInspector::cleanName("a\"b.pdf"));
        $this->assertSame('htaccess', FormUploadInspector::cleanName('.htaccess'));
        $this->assertSame('', FormUploadInspector::cleanName(['x']));
        $this->assertSame('', FormUploadInspector::cleanName("\xff\xfe.pdf"), 'broken UTF-8 is no name');
        $this->assertSame(255, mb_strlen(FormUploadInspector::cleanName(str_repeat('a', 400) . '.pdf')));
        $this->assertStringEndsWith('.pdf', FormUploadInspector::cleanName(str_repeat('a', 400) . '.pdf'), 'the end, with its extension, is what is kept');

        // A name that cleans to nothing gets the field's own.
        $result = $this->inspect($this->entry('../', $this->bytes('pdf')), $this->field(['pdf']));
        $this->assertIsString($result, 'no extension left, so no kind');
    }

    // ---------------------------------------------------------- the validator

    /** $_FILES is read by the definition's key, exactly like $_POST. */
    public function testTheValidatorReadsFilesByTheDefinition(): void
    {
        $form = $this->form([$this->fieldRow('bijlage', true, ['pdf']), $this->textRow('naam')]);
        $validator = $this->validator();

        $missing = $validator->validate($form, ['naam' => 'Ann', 'bijlage' => 'een tekst in $_POST'], ['ander' => $this->entry('x.pdf', $this->bytes('pdf'))]);
        $this->assertSame('Kies een bestand bij Bijlage.', $missing->errorFor('bijlage'), 'a POST value or another key is no file');
        $this->assertSame([], $missing->uploads);

        $valid = $validator->validate($form, ['naam' => 'Ann'], ['bijlage' => $this->entry('offerte.pdf', $this->bytes('pdf'))]);
        $this->assertTrue($valid->isValid());
        $this->assertSame(['bijlage'], array_keys($valid->uploads));
        $this->assertMatchesRegularExpression('/^offerte\.pdf \(\d+ kB\)$/', $valid->valueFor('bijlage'));
        $this->assertSame(['bijlage', 'naam'], array_keys($valid->values));

        $snapshot = $validator->snapshot($form, $valid->values);
        $this->assertSame('file', $snapshot[0]['field_type']);
        $this->assertSame($valid->valueFor('bijlage'), $snapshot[0]['value']);
    }

    public function testAnOptionalUploadFieldMayStayEmpty(): void
    {
        $result = $this->validator()->validate($this->form([$this->fieldRow('bijlage', false, ['pdf'])]), [], []);

        $this->assertTrue($result->isValid());
        $this->assertSame('', $result->valueFor('bijlage'));
    }

    /**
     * Another field failing: the text comes back, the file cannot. Without
     * JavaScript the form says so beside the file; the fetch() answer does
     * not, because there the file is still chosen.
     */
    public function testAFileThatWasAcceptedAsksToBeChosenAgainAfterARedirect(): void
    {
        $form = $this->form([$this->textRow('naam', true), $this->fieldRow('bijlage', false, ['pdf'])]);

        $result = $this->validator()->validate($form, ['naam' => ''], ['bijlage' => $this->entry('offerte.pdf', $this->bytes('pdf'))]);

        $this->assertFalse($result->isValid());
        $this->assertNull($result->errorFor('bijlage'), 'the file itself was fine');
        $this->assertArrayNotHasKey('bijlage', $result->retainableValues(), 'a file name is not put back');
        $this->assertArrayHasKey('naam', $result->retainableValues());

        $redirect = $result->errorsAfterRedirect($form);
        $this->assertStringContainsString('opnieuw', $redirect['bijlage']);
        $this->assertSame($result->errorFor('naam'), $redirect['naam']);
    }

    /**
     * A form that keeps nothing delivers its files only by e-mail, so the
     * accepted files together may not exceed the notification's budget:
     * under it and exactly on it pass, one byte over is refused beside every
     * file. A form that keeps its submissions has no such limit.
     */
    public function testAFormThatKeepsNothingLimitsItsFilesTogetherToTheMailBudget(): void
    {
        $budget = FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET;
        $half = intdiv($budget, 2);
        if (FormFileTypes::systemMaxBytes() < $half + 1) {
            $this->markTestSkipped('PHP here takes no single file of half the mail budget');
        }

        $fields = [$this->fieldRow('a', false, ['pdf'], FormFileTypes::MAX_BYTES), $this->fieldRow('b', false, ['pdf'], FormFileTypes::MAX_BYTES)];
        $mailOnly = $this->form($fields);
        $stores = $this->form($fields, true);
        $files = fn (int $a, int $b): array => ['a' => $this->entry('a.pdf', $this->pdfOf($a)), 'b' => $this->entry('b.pdf', $this->pdfOf($b))];

        $under = $this->validator()->validate($mailOnly, [], $files(5 * 1024 * 1024, 5 * 1024 * 1024));
        $this->assertTrue($under->isValid(), 'under the budget');

        $exact = $this->validator()->validate($mailOnly, [], $files($half, $budget - $half));
        $this->assertTrue($exact->isValid(), 'exactly on the budget');
        $this->assertSame($budget, $exact->uploads['a']->size + $exact->uploads['b']->size);

        $over = $this->validator()->validate($mailOnly, [], $files($half, $budget - $half + 1));
        $this->assertFalse($over->isValid(), 'one byte over');
        foreach (['a', 'b'] as $key) {
            $this->assertStringContainsString('samen te groot', (string) $over->errorFor($key));
            $this->assertStringContainsString(FormFileTypes::sizeLabel($budget), (string) $over->errorFor($key));
        }
        $this->assertSame($over->errors, $over->errorsAfterRedirect($mailOnly), 'no reselect note hides the reason');

        $this->assertTrue($this->validator()->validate($stores, [], $files($half, $budget - $half + 1))->isValid(), 'a form that keeps its submissions keeps the rest in the CMS');
    }

    // ------------------------------------------------------------ the control

    public function testTheControlIsAFileInputWithItsRules(): void
    {
        $form = $this->form([$this->fieldRow('bijlage', true, ['jpg', 'pdf'], 2 * 1024 * 1024, 'third')]);
        $html = $this->render($form, FormRenderState::fresh('form-0123456789'));

        $this->assertMatchesRegularExpression('/<input type="file" id="form-0123456789-bijlage" name="bijlage" required aria-required="true"/', $html);
        $this->assertStringContainsString('accept=".jpg,.jpeg,image/jpeg,.pdf,application/pdf"', $html);
        $this->assertStringContainsString('aria-describedby="form-0123456789-bijlage-error form-0123456789-bijlage-rules"', $html);
        $this->assertStringContainsString('<span class="hint form-file-rules" id="form-0123456789-bijlage-rules">JPG of PDF, max. 2 MB.</span>', $html);
        $this->assertStringContainsString('class="form-field form-field--third"', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('action="/api/form-submit.php?instance=form-0123456789"', $html);
    }

    /** Every width, and never a value back in a file input. */
    public function testTheControlTakesEveryWidthAndNoValue(): void
    {
        foreach (['full', 'three_quarters', 'two_thirds', 'half', 'third', 'quarter'] as $width) {
            $form = $this->form([$this->fieldRow('bijlage', false, ['pdf'], null, $width)]);
            $html = $this->render($form, FormRenderState::withErrors('form-0123456789', ['bijlage' => 'C:\\fakepath\\x.pdf'], []));

            $this->assertStringContainsString('form-field--' . str_replace('_', '-', $width), $html, $width);
            $this->assertStringNotContainsString('fakepath', $html, 'a file input never carries a value');
        }
    }

    public function testAFormLevelErrorIsShownAboveTheFields(): void
    {
        $form = $this->form([$this->fieldRow('bijlage', false, ['pdf'])]);
        $html = $this->render($form, FormRenderState::withErrors('form-0123456789', [], [FormRenderState::FORM_ERROR => 'Te groot.']));

        $this->assertStringContainsString('<li>Te groot.</li>', $html);
    }

    // -------------------------------------------------------------- the e-mail

    /**
     * Files are attached, in field order, within the budget; the rest are
     * named in their answer instead (the wording is
     * Tests\Service\FormUploadSubmissionTest's, which has a database for the
     * e-mail's footer).
     */
    public function testTheNotificationAttachesWithinItsBudget(): void
    {
        $stored = [
            ['field_key' => 'a', 'size' => 9 * 1024 * 1024, 'path' => '/x/a', 'mail_name' => 'a.pdf', 'mime' => 'application/pdf'],
            ['field_key' => 'b', 'size' => 9 * 1024 * 1024, 'path' => '/x/b', 'mail_name' => 'b.pdf', 'mime' => 'application/pdf'],
            ['field_key' => 'c', 'size' => 1024, 'path' => '/x/c', 'mail_name' => 'c.jpg', 'mime' => 'image/jpeg'],
        ];

        [$attachments, $leftOut] = FormSubmissionHandler::mailAttachments($stored);

        $this->assertSame(['a.pdf', 'c.jpg'], array_column($attachments, 'name'));
        $this->assertSame(['/x/a', '/x/c'], array_column($attachments, 'path'));
        $this->assertSame(['b'], $leftOut);
        $this->assertSame([[], []], FormSubmissionHandler::mailAttachments([]));
    }

    // ---------------------------------------------------------------- helpers

    private function inspect(mixed $entry, \App\Service\Forms\FormField $field): FormUpload|string|null
    {
        return (new FormUploadInspector(static fn (string $path): bool => true))->inspect($entry, $field);
    }

    private function validator(): FormValidator
    {
        return new FormValidator(new FormUploadInspector(static fn (string $path): bool => true));
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function entry(string $name, string $bytes): array
    {
        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $this->write($bytes), 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function failed(int $error): array
    {
        return ['name' => 'x.pdf', 'type' => '', 'tmp_name' => '', 'error' => $error, 'size' => 0];
    }

    private function write(string $bytes): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);

        return $path;
    }

    /** A PDF of exactly $bytes bytes. */
    private function pdfOf(int $bytes): string
    {
        return str_pad("%PDF-1.4\n", $bytes, 'x');
    }

    /** A real, tiny file of one kind. */
    private function bytes(string $kind): string
    {
        if ($kind === 'pdf') {
            return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        }

        $image = imagecreatetruecolor(4, 4);
        ob_start();
        match ($kind) {
            'jpg' => imagejpeg($image),
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
        };

        return (string) ob_get_clean();
    }

    /** @param list<string> $types */
    private function field(array $types, ?int $maxBytes = null): \App\Service\Forms\FormField
    {
        return $this->form([$this->fieldRow('bijlage', false, $types, $maxBytes)])->field('bijlage');
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function form(array $fields, bool $storesSubmissions = false): FormDefinition
    {
        $words = static fn (string $nl): array => ['nl' => ['submit_label' => $nl, 'success_message' => 'Bedankt.']];

        return FormDefinition::fromRows([
            'id' => 1,
            'name' => 'Uploadformulier',
            'internal_key' => 'uploadformulier',
            'is_active' => 1,
            'notification_email' => null,
            'reply_to_field_key' => null,
            'store_submissions' => $storesSubmissions ? 1 : 0,
            'translations' => $words('Versturen'),
        ], $fields);
    }

    /**
     * @param list<string> $types
     * @return array<string, mixed>
     */
    private function fieldRow(string $key, bool $required, array $types, ?int $maxBytes = null, string $width = 'full'): array
    {
        static $id = 100;

        return [
            'id' => ++$id,
            'field_key' => $key,
            'field_type' => 'file',
            'is_required' => $required ? 1 : 0,
            'layout_width' => $width,
            'sort_order' => $id,
            'default_value' => null,
            'file_types' => implode(',', $types),
            'file_max_bytes' => $maxBytes ?? 5 * 1024 * 1024,
            'translations' => ['nl' => ['label' => ucfirst($key)]],
            'choices' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function textRow(string $key, bool $required = false): array
    {
        static $id = 500;

        return [
            'id' => ++$id,
            'field_key' => $key,
            'field_type' => 'text',
            'is_required' => $required ? 1 : 0,
            'layout_width' => 'half',
            'sort_order' => $id,
            'default_value' => null,
            'translations' => ['nl' => ['label' => ucfirst($key)]],
            'choices' => [],
        ];
    }

    private function render(FormDefinition $form, FormRenderState $state): string
    {
        ob_start();
        render_form($form, $state);

        return (string) ob_get_clean();
    }
}
