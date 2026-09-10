<?php

/**
 * POST /api/form-submit.php
 *
 * THE public endpoint for every form this CMS renders — the generic
 * "Formulier" block and the older `contact_form` block alike. It does no
 * validating, storing or mailing of its own: it resolves which form was
 * submitted, hands the request to App\Service\Forms\FormSubmissionHandler,
 * and turns the one outcome into the two answers a caller can want.
 *
 *   fetch (Accept: application/json)   200 {"ok":true,"message":{...}}
 *                                      422 {"ok":false,"errors":{...}}
 *                                      429 / 500 {"ok":false,"message":{...}}
 *   an ordinary browser POST           303 back to the page it came from,
 *                                      with ?form-status=success|error and
 *                                      the instance token
 *
 * NO JAVASCRIPT IS REQUIRED. The redirect answer is the primary one;
 * assets/js/blocks/form.js only upgrades it so the page does not reload.
 * 303 rather than 302 so refreshing the page it lands on never resubmits.
 *
 * WHY THERE IS NO CSRF TOKEN HERE, and why that is not an oversight: this
 * project's CSRF model (App\Service\Csrf) is bound to the ADMIN session, and
 * there is no anonymous session for a public visitor to hold a token in.
 * Issuing one would mean setting a cookie on every visitor who merely SEES a
 * form. What a CSRF token buys is also close to nothing here: the endpoint
 * takes no action on behalf of a signed-in user, changes nothing that
 * belongs to anybody, and its worst case — a third-party page making a
 * visitor send the site owner an e-mail — is exactly what the form is for
 * and what the rate limiter bounds. The admin side of Forms is a different
 * matter and every one of its write endpoints checks a token.
 * FORMS.md records this decision.
 *
 * WHAT DOES GUARD IT: App\Service\Forms\FormSpamGuard (honeypot, minimum
 * submit time, per-visitor rate limit on a salted IP hash) and, above all,
 * App\Service\Forms\FormValidator, which walks the form definition rather
 * than the request. A field the form does not have is never read.
 *
 * THE RECIPIENT NEVER COMES FROM THE REQUEST. It is resolved from the stored
 * form and the site's settings (App\Service\Forms\FormRecipient), so no POST
 * can turn this endpoint into a way to mail a third party.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\ContactAttachmentStorage;
use App\Service\ContactAttachmentValidator;
use App\Service\Forms\FormAttachmentPolicy;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormSourcePath;
use App\Service\Forms\FormSubmissionContext;
use App\Service\Forms\FormSubmissionHandler;
use App\Service\Forms\FormSubmissionOutcome;
use App\Service\Forms\FormText;
use App\Service\Forms\PublicFormSession;

/**
 * True when the caller explicitly asked for JSON — the fetch() in
 * assets/js/blocks/form.js sends `Accept: application/json`, a normal
 * browser form submission does not. The single point where the two answers
 * are told apart.
 */
$wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

$sourcePath = FormSourcePath::clean($_POST['form-source'] ?? null);
$instanceToken = (string) ($_POST['form-instance'] ?? '');
if (preg_match('/^form-[a-f0-9]{10}$/', $instanceToken) !== 1) {
    $instanceToken = '';
}

/**
 * @param array{nl: string, en: string} $message
 * @param array<string, array{nl: string, en: string}> $errors
 */
function respond(
    bool $wantsJson,
    int $httpStatus,
    string $formStatus,
    array $message,
    array $errors = [],
    ?string $sourcePath = null,
    string $instanceToken = ''
): never {
    if ($wantsJson) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $formStatus === 'success',
            'message' => $message,
            'errors' => (object) $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // A path that failed validation, or none at all, sends the visitor to
    // the site root rather than anywhere a request suggested.
    $target = FormSourcePath::withStatus(
        $sourcePath ?? FormSourcePath::FALLBACK,
        $formStatus,
        $instanceToken !== '' ? $instanceToken : 'form'
    );

    header('Location: ' . $target, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => ['nl' => 'Methode niet toegestaan.', 'en' => 'Method not allowed.']]);
    exit;
}

$genericFailure = [
    'nl' => 'Er ging iets mis. Probeer het later opnieuw.',
    'en' => 'Something went wrong. Please try again later.',
];

$form = FormCatalog::findByInternalKey((string) ($_POST['form-key'] ?? ''));

if ($form === null || !$form->isRenderable()) {
    // Deliberately the same generic answer as any other failure: whether a
    // form key exists is not something a public endpoint should confirm.
    respond($wantsJson, 400, 'error', $genericFailure, [], $sourcePath, $instanceToken);
}

/**
 * The optional file the `contact_form` block still allows. Validated with
 * the machinery that has always validated it (magic bytes, not the
 * client-supplied name or MIME) and only when a block on this site actually
 * offers it for this form — see App\Service\Forms\FormAttachmentPolicy.
 */
$attachment = null;
$attachmentStorage = null;

if (($_FILES['bestand']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    && FormAttachmentPolicy::allowsAttachment($form)
) {
    try {
        $validated = (new ContactAttachmentValidator())->validate($_FILES['bestand']);
    } catch (\RuntimeException $e) {
        respond(
            $wantsJson,
            422,
            'error',
            ['nl' => $e->getMessage(), 'en' => $e->getMessage()],
            [],
            $sourcePath,
            $instanceToken
        );
    }

    if ($validated !== null) {
        try {
            $attachmentStorage = new ContactAttachmentStorage();
            $storedFilename = $attachmentStorage->store($validated['tmp_path'], $validated['extension']);

            $attachment = [
                'stored_filename' => $storedFilename,
                'original_filename' => $validated['original_filename'],
                'mail_name' => $validated['mail_name'],
                'mime' => $validated['mime'],
                'size' => $validated['size'],
                'path' => $attachmentStorage->path($storedFilename),
            ];
        } catch (\Throwable $e) {
            error_log('[api/form-submit.php] could not store an attachment: ' . $e->getMessage());
            respond($wantsJson, 500, 'error', $genericFailure, [], $sourcePath, $instanceToken);
        }
    }
}

$outcome = (new FormSubmissionHandler())->handle($form, $_POST, new FormSubmissionContext(
    $sourcePath,
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    $attachment
));

// A file that was moved into permanent storage for a submission that was
// then rejected or lost would be an orphan nothing can find. Remove it.
if ($attachment !== null && $attachmentStorage !== null && $outcome->submissionId === null) {
    $attachmentStorage->delete($attachment['stored_filename']);
}

if ($outcome->looksSuccessfulToTheVisitor()) {
    respond(
        $wantsJson,
        200,
        'success',
        ['nl' => $form->successMessage->nl, 'en' => $form->successMessage->en],
        [],
        $sourcePath,
        $instanceToken
    );
}

if ($outcome->status === FormSubmissionOutcome::INVALID && $outcome->validation !== null) {
    $errors = [];
    $errorsNl = [];
    $errorsEn = [];

    foreach ($outcome->validation->errors as $key => $message) {
        /** @var FormText $message */
        $errors[$key] = ['nl' => $message->nl, 'en' => $message->en];
        $errorsNl[$key] = $message->nl;
        $errorsEn[$key] = $message->en;
    }

    // Only the no-JS answer needs the values carried across a redirect; the
    // fetch flow still has them in the page it never left.
    if (!$wantsJson && $instanceToken !== '') {
        PublicFormSession::rememberFailure(
            $instanceToken,
            $outcome->validation->retainableValues(),
            $errorsNl,
            $errorsEn
        );
    }

    respond(
        $wantsJson,
        422,
        'error',
        ['nl' => 'Controleer het formulier en probeer het opnieuw.', 'en' => 'Please check the form and try again.'],
        $errors,
        $sourcePath,
        $instanceToken
    );
}

if ($outcome->status === FormSubmissionOutcome::REJECTED) {
    // The rate limit is the only rejection a real person can hit, so it is
    // the only one that says anything — and it still says nothing about how
    // it was detected.
    respond(
        $wantsJson,
        429,
        'error',
        [
            'nl' => 'Je hebt te veel berichten verstuurd. Probeer het over een paar minuten opnieuw.',
            'en' => 'Too many messages sent. Please try again in a few minutes.',
        ],
        [],
        $sourcePath,
        $instanceToken
    );
}

respond($wantsJson, 500, 'error', $genericFailure, [], $sourcePath, $instanceToken);
