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
 *   fetch (Accept: application/json)   200 {"ok":true,"message":"..."}
 *                                      422 {"ok":false,"errors":{"<field>":"..."}}
 *                                      429 / 500 {"ok":false,"message":"..."}
 *
 * Every message is ONE string, in the language of the page the form sat on
 * (see $sourceLanguage below): the browser receives the words it shows and
 * never a pair to choose from.
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

use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormFileTypes;
use App\Service\Forms\FormRenderState;
use App\Service\Forms\FormSourcePath;
use App\Service\Forms\FormSubmissionContext;
use App\Service\Forms\FormSubmissionHandler;
use App\Service\Forms\FormSubmissionOutcome;
use App\Service\Forms\PublicFormSession;
use App\Service\Language\SiteText;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;

/**
 * True when the caller explicitly asked for JSON — the fetch() in
 * assets/js/blocks/form.js sends `Accept: application/json`, a normal
 * browser form submission does not. The single point where the two answers
 * are told apart.
 */
$wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

$sourcePath = FormSourcePath::clean($_POST['form-source'] ?? null);

/**
 * THE LANGUAGE OF THE ANSWER is the language of the page the form sat on:
 * the prefix of its own `form-source` path (docs/multilingual/ROUTING.md,
 * §14). Only an ACTIVE website language counts — LocalizedUrl::strip() peels
 * nothing else — so a path without a prefix, or a forged one, answers in the
 * default language, exactly like an unprefixed URL. The endpoint's own
 * address never carries a language, and no other request value is believed.
 */
[, $sourceLanguage] = LocalizedUrl::strip($sourcePath ?? FormSourcePath::FALLBACK);
if ($sourceLanguage !== null) {
    RequestLanguage::set($sourceLanguage, true);
}
$instanceToken = (string) ($_POST['form-instance'] ?? '');
if (preg_match('/^form-[a-f0-9]{10}$/', $instanceToken) !== 1) {
    $instanceToken = '';
}

/**
 * @param array<string, string> $errors one message per field
 */
function respond(
    bool $wantsJson,
    int $httpStatus,
    string $formStatus,
    string $message,
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
    echo json_encode(['ok' => false, 'message' => SiteText::pick(['nl' => 'Methode niet toegestaan.', 'en' => 'Method not allowed.'])]);
    exit;
}

$genericFailure = SiteText::pick([
    'nl' => 'Er ging iets mis. Probeer het later opnieuw.',
    'en' => 'Something went wrong. Please try again later.',
]);

/**
 * A REQUEST LARGER THAN post_max_size arrives with $_POST and $_FILES both
 * empty: PHP threw the whole body away, the form key included. Answered as
 * what it almost always is — a file far too large — instead of the generic
 * failure below, which would leave the visitor guessing. Without JavaScript
 * the answer goes back to the page the form was on (the Referer, held to the
 * same rules as `form-source`) and to the instance the form's own action URL
 * names, so the message appears above that form.
 */
if ($_POST === [] && $_FILES === []
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > FormFileTypes::iniBytes((string) ini_get('post_max_size'))
    && FormFileTypes::iniBytes((string) ini_get('post_max_size')) > 0
) {
    $refererPath = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    $refererHost = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    $overflowSource = is_string($refererPath) && $refererHost === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
        ? FormSourcePath::clean($refererPath)
        : null;
    $overflowToken = (string) ($_GET['instance'] ?? '');
    $overflowToken = preg_match('/^form-[a-f0-9]{10}$/', $overflowToken) === 1 ? $overflowToken : '';

    // The language of the page it came from, by the same rule as above.
    [, $overflowLanguage] = LocalizedUrl::strip($overflowSource ?? FormSourcePath::FALLBACK);
    if ($overflowLanguage !== null) {
        RequestLanguage::set($overflowLanguage, true);
    }

    $message = SiteText::pick([
        'nl' => 'Wat je verstuurde is te groot om te ontvangen. Kies een kleiner bestand en probeer het opnieuw.',
        'en' => 'What you sent is too large to receive. Choose a smaller file and try again.',
    ]);

    if (!$wantsJson && $overflowToken !== '') {
        PublicFormSession::rememberFailure($overflowToken, [], [FormRenderState::FORM_ERROR => $message]);
    }

    respond($wantsJson, 413, 'error', $message, [], $overflowSource, $overflowToken);
}

$form = FormCatalog::findByInternalKey((string) ($_POST['form-key'] ?? ''));

if ($form === null || !$form->isRenderable()) {
    // Deliberately the same generic answer as any other failure: whether a
    // form key exists is not something a public endpoint should confirm.
    respond($wantsJson, 400, 'error', $genericFailure, [], $sourcePath, $instanceToken);
}

/**
 * FILES ARE ANSWERS. An upload field reads its own entry of $_FILES, by the
 * key its definition gives it (App\Service\Forms\FormValidator); a file under
 * any other name — the old contact block's `bestand` included — is never
 * looked at. Storing, linking, mailing and cleaning up are the handler's.
 */
$outcome = (new FormSubmissionHandler())->handle($form, $_POST, new FormSubmissionContext(
    $sourcePath,
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
), $_FILES);

if ($outcome->looksSuccessfulToTheVisitor()) {
    respond(
        $wantsJson,
        200,
        'success',
        $form->successMessage,
        [],
        $sourcePath,
        $instanceToken
    );
}

if ($outcome->status === FormSubmissionOutcome::INVALID && $outcome->validation !== null) {
    $errors = $outcome->validation->errors;

    // Only the no-JS answer needs the values carried across a redirect; the
    // fetch flow still has them in the page it never left — its chosen files
    // included. A file cannot be carried back, so a redirect also says so
    // beside every file that was accepted (errorsAfterRedirect()).
    if (!$wantsJson && $instanceToken !== '') {
        PublicFormSession::rememberFailure(
            $instanceToken,
            $outcome->validation->retainableValues(),
            $outcome->validation->errorsAfterRedirect($form)
        );
    }

    respond(
        $wantsJson,
        422,
        'error',
        SiteText::pick(['nl' => 'Controleer het formulier en probeer het opnieuw.', 'en' => 'Please check the form and try again.']),
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
        SiteText::pick([
            'nl' => 'Je hebt te veel berichten verstuurd. Probeer het over een paar minuten opnieuw.',
            'en' => 'Too many messages sent. Please try again in a few minutes.',
        ]),
        [],
        $sourcePath,
        $instanceToken
    );
}

respond($wantsJson, 500, 'error', $genericFailure, [], $sourcePath, $instanceToken);
