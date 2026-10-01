<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\FormSubmissionRepository;
use App\Service\Forms\FormSubmissionBulk;
use App\Service\Forms\FormSubmissionBulkRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Submissions in bulk (FORMS.md, "Inzendingen in bulk"), checked without a
 * database: how the submitted ids are read, the closed list of actions and
 * the batch limit, and what the overview, its script and the endpoint may
 * and may not contain.
 *
 * What a request actually does to the rows is Tests\Service\FormSubmissionBulkHttpTest.
 */
final class FormSubmissionBulkContractTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* The ids                                                             */
    /* ------------------------------------------------------------------ */

    public function testIdsBecomeDistinctPositiveIntegersInTheOrderSent(): void
    {
        $this->assertSame([7], FormSubmissionBulk::normalizeIds(['7']));
        $this->assertSame([7, 3, 12], FormSubmissionBulk::normalizeIds(['7', '3', '12']));
        $this->assertSame([7, 3], FormSubmissionBulk::normalizeIds(['7', '3', '7', 7, '+7']), 'a duplicate is folded, not refused');
        $this->assertSame([5], FormSubmissionBulk::normalizeIds([5]));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'no field at all' => [null, FormSubmissionBulkRefused::NOTHING_SELECTED];
        yield 'empty string' => ['', FormSubmissionBulkRefused::NOTHING_SELECTED];
        yield 'empty list' => [[], FormSubmissionBulkRefused::NOTHING_SELECTED];
        yield 'a single value instead of a list' => ['5', FormSubmissionBulkRefused::MALFORMED];
        yield 'a word' => [['5', 'abc'], FormSubmissionBulkRefused::MALFORMED];
        yield 'digits with a tail' => [['5abc'], FormSubmissionBulkRefused::MALFORMED];
        yield 'negative' => [['-1'], FormSubmissionBulkRefused::MALFORMED];
        yield 'zero' => [['0'], FormSubmissionBulkRefused::MALFORMED];
        yield 'a fraction' => [['1.5'], FormSubmissionBulkRefused::MALFORMED];
        yield 'an empty entry' => [['5', ''], FormSubmissionBulkRefused::MALFORMED];
        yield 'a nested list' => [[['5']], FormSubmissionBulkRefused::MALFORMED];
        yield 'a hex number' => [['0x1A'], FormSubmissionBulkRefused::MALFORMED];
        yield 'one too many' => [array_map('strval', range(1, FormSubmissionBulk::MAX_IDS + 1)), FormSubmissionBulkRefused::TOO_MANY];
    }

    #[DataProvider('refusedIds')]
    public function testAnythingButAListOfPositiveWholeNumbersRefusesTheRequest(mixed $submitted, string $reason): void
    {
        try {
            FormSubmissionBulk::normalizeIds($submitted);
            $this->fail('expected a refusal');
        } catch (FormSubmissionBulkRefused $refusal) {
            $this->assertSame($reason, $refusal->reason);
        }
    }

    public function testTheLimitIsReachedExactlyAndLiesAbovePageSize(): void
    {
        $this->assertCount(FormSubmissionBulk::MAX_IDS, FormSubmissionBulk::normalizeIds(range(1, FormSubmissionBulk::MAX_IDS)));
        $this->assertGreaterThanOrEqual(
            FormSubmissionRepository::PAGE_SIZE,
            FormSubmissionBulk::MAX_IDS,
            'selecting a whole page must never reach the limit'
        );
        $this->assertLessThanOrEqual(250, FormSubmissionBulk::MAX_IDS);
    }

    /* ------------------------------------------------------------------ */
    /* The actions                                                         */
    /* ------------------------------------------------------------------ */

    public function testTheActionsAreAClosedListOfThree(): void
    {
        $this->assertSame(['mark_read', 'mark_unread', 'delete'], FormSubmissionBulk::ACTIONS);
    }

    public function testAnUnknownActionIsRefusedBeforeTheDatabaseIsTouched(): void
    {
        foreach (['', 'archive', 'DELETE', 'delete ', 'mark_read;drop'] as $action) {
            try {
                (new FormSubmissionBulk())->apply($action, [1]);
                $this->fail('expected a refusal for "' . $action . '"');
            } catch (FormSubmissionBulkRefused $refusal) {
                $this->assertSame(FormSubmissionBulkRefused::UNKNOWN_ACTION, $refusal->reason);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* The source                                                          */
    /* ------------------------------------------------------------------ */

    public function testTheEndpointTakesItsActionFromTheListAndNothingFromTheRequestAsAUrl(): void
    {
        $source = $this->read('api/admin/bulk-form-submissions.php');

        $this->assertStringContainsString('in_array($action, FormSubmissionBulk::ACTIONS, true)', $source);
        $this->assertStringContainsString('FormSubmissionBulk::normalizeIds(', $source);
        $this->assertStringNotContainsString("\$_POST['return_to']", $source, 'the way back is rebuilt, never sent');
        $this->assertStringNotContainsString('HTTP_REFERER', $source);
        $this->assertDoesNotMatchRegularExpression('/json_encode|echo\s/', $source, 'the endpoint answers with a redirect or a refusal, never with submission data');
    }

    public function testDeletingInBulkRemovesTheRowsFirstAndTheFilesAfterTheCommit(): void
    {
        $source = $this->read('src/Service/Forms/FormSubmissionBulk.php');

        $commit = strpos($source, '$db->commit();');
        $this->assertNotFalse($commit);
        $this->assertGreaterThan(strpos($source, '->attachmentsForMany($ids)'), strpos($source, '->deleteMany($ids)'), 'files are read before their rows go');
        $this->assertGreaterThan($commit, strpos($source, '$this->storage->delete('), 'files go only after the rows are gone for good');
        $this->assertStringContainsString('FOR UPDATE', $this->read('src/Repository/FormSubmissionRepository.php'), 'the checked rows are locked until the change');
    }

    public function testTheScriptDecidesNothingAndRemembersNothing(): void
    {
        $source = $this->read('admin/assets/form-submissions.js');

        foreach (['fetch(', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'console.', 'document.cookie'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, 'form-submissions.js must not use ' . $forbidden);
        }
        $this->assertStringContainsString('indeterminate', $source);
    }

    public function testTheOverviewPutsOnlyIdsIntoItsControls(): void
    {
        $source = $this->read('admin/form-submissions.php');

        $this->assertStringContainsString('name="ids[]" value="<?= $submissionId ?>"', $source);
        $this->assertStringNotContainsString('data-preview', $source);
        $this->assertDoesNotMatchRegularExpression('/data-[a-z-]+="<\?= \$h\(\$preview/', $source, 'no answer in a data attribute');
        $this->assertStringContainsString('<?= admin_confirm_dialog() ?>', $source);
        $this->assertStringContainsString('admin_confirm_attributes(', $source, 'the delete button asks in the CMS dialog');
    }

    public function testTheSharedDialogAsksForTheButtonThatCarriesTheQuestion(): void
    {
        $source = $this->read('admin/assets/admin-ui.js');

        $this->assertStringContainsString('submitter.hasAttribute("data-admin-confirm") ? submitter : form', $source);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
