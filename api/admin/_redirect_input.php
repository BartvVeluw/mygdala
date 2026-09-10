<?php

declare(strict_types=1);

/**
 * The half of api/admin/create-redirect.php and update-redirect.php that is
 * genuinely identical: reading the form, choosing which of the two destination
 * fields applies, and validating the result.
 *
 * Shared the same way api/admin/_collection_validation.php and
 * _portfolio_validation.php are — a plain include with one function, not a
 * class — because the two endpoints differ only in what they do with a valid
 * submission, and a source path that is accepted when creating but rejected
 * when editing would be the worst kind of bug to find.
 */

use App\Service\Redirects\RedirectPath;
use App\Service\Redirects\RedirectTarget;
use App\Service\Redirects\RedirectValidator;

/**
 * Reads $_POST into the shape the repository wants, plus the messages to show
 * and the values to hand back to the form on a rejection.
 *
 * @return array{data: array{source_path: string, target_type: string, target_value: string, status_code: int, is_active: bool}, errors: list<string>, old: array<string, mixed>}
 */
function redirect_input_from_post(?int $excludeId, ?RedirectValidator $validator = null): array
{
    $sourceInput = trim((string) ($_POST['source_path'] ?? ''));
    $targetType = (string) ($_POST['target_type'] ?? RedirectTarget::TYPE_INTERNAL);

    // Two separate destination inputs, one per kind, so switching the dropdown
    // in the form never silently reinterprets a path as a URL or the other way
    // round. Only the one belonging to the chosen type is read.
    $targetInput = $targetType === RedirectTarget::TYPE_EXTERNAL
        ? trim((string) ($_POST['target_external'] ?? ''))
        : trim((string) ($_POST['target_internal'] ?? ''));

    // A status code is a closed choice, never free text: it becomes an HTTP
    // response code. filter_var keeps a non-numeric submission from arriving
    // here as 0 and being reported as anything other than invalid.
    $statusCode = (int) filter_var((string) ($_POST['status_code'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
    $isActive = isset($_POST['is_active']);

    $validator ??= new RedirectValidator();
    $errors = $validator->validate($sourceInput, $targetType, $targetInput, $statusCode, $excludeId);

    return [
        'data' => [
            'source_path' => (string) (RedirectPath::normalize($sourceInput) ?? ''),
            'target_type' => $targetType,
            'target_value' => (string) (RedirectTarget::normalize($targetType, $targetInput) ?? ''),
            'status_code' => $statusCode,
            'is_active' => $isActive,
        ],
        'errors' => $errors,
        'old' => [
            'source_path' => $sourceInput,
            'target_type' => $targetType,
            'target_value' => $targetInput,
            'status_code' => $statusCode,
            'is_active' => $isActive,
        ],
    ];
}
