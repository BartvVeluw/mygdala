<?php

/**
 * POST /api/admin/translate-fields.php
 *
 * Machine-translate the fields of ONE editor form from one of this site's
 * languages into another, and hand the result back to the screen. Answers
 * JSON; the editor's own JavaScript fills the fields in.
 *
 * The target is the language version the administrator is currently editing
 * and the source is the other one, so the button reads "Translate from
 * Dutch" while somebody is looking at empty English fields. Omitting
 * `source_language` means the site's default website language, which is what
 * every caller meant before the editor could choose.
 *
 * IT SAVES NOTHING. Not one row is written here. The editor looks at the
 * translation, changes what they want, and then presses their form's normal
 * Save button, which goes to that form's normal endpoint with that endpoint's
 * normal validation and CSRF token. A translate button that wrote straight to
 * the database would be a second, weaker write path into every content table
 * in this CMS, and this project has spent a lot of effort making sure there
 * is only one.
 *
 * WHAT LEAVES THIS SERVER. Only the text in the `fields` list of this
 * request. No ids, no CSRF token, no settings, no other page's content, no
 * user data. The provider is called by PHP with a key that lives in the
 * environment and is never rendered into HTML or JavaScript
 * (App\Service\Translation\DeepLProvider).
 *
 * THE FIELD NAMES ARE DATA, NOT IDENTIFIERS. `field` is only ever used to
 * look up translation state and to key the JSON response. It never reaches a
 * column name, a table name or a file path, so a crafted name can do nothing
 * beyond producing a translation nobody asked for.
 *
 * GUARDS, in the order every write endpoint in this project applies them:
 * login, permission, method, CSRF. `pages.manage` because that is the
 * permission that lets somebody edit the content this translates; a person
 * who cannot change the words cannot spend the site's translation quota on
 * them either.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;
use App\Service\Translation\TranslationException;
use App\Service\Translation\TranslationRequest;
use App\Service\Translation\TranslationService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

header('Content-Type: application/json; charset=utf-8');

$fail = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $fail(405, 'Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    $fail(403, 'Invalid or missing CSRF token.');
}

$target = trim((string) ($_POST['target_language'] ?? ''));
// Absent means "the site's default website language", which is what every
// caller wanted before the editor could choose which version it was editing.
$source = trim((string) ($_POST['source_language'] ?? ''));
if ($source === '') {
    $source = LanguageFallback::defaultLanguage();
}
$entityType = substr(trim((string) ($_POST['entity_type'] ?? '')), 0, 100);
$entityKey = substr(trim((string) ($_POST['entity_key'] ?? '')), 0, 191);

if (!SiteLanguages::exists($target) || !SiteLanguages::exists($source)) {
    $fail(422, 'Deze site publiceert die taal niet.');
}

if ($source === $target) {
    $fail(422, 'Bron- en doeltaal zijn dezelfde taal.');
}

$submitted = $_POST['fields'] ?? null;
if (!is_array($submitted) || $submitted === []) {
    $fail(422, 'Er is niets om te vertalen meegestuurd.');
}

// One request may carry at most this many fields. A form in this CMS has a
// couple of dozen at the very most, so anything beyond it is not an editor
// pressing a button.
const MAX_FIELDS_PER_REQUEST = 60;

$requests = [];
foreach (array_slice($submitted, 0, MAX_FIELDS_PER_REQUEST, true) as $field => $payload) {
    if (!is_string($field) || !is_array($payload)) {
        continue;
    }

    // A conservative shape for a name that only ever keys an array and a
    // state lookup. Rejecting rather than sanitising, because a field name
    // that does not look like one did not come from a form of ours.
    if (preg_match('/^[a-z0-9_]{1,100}$/', $field) !== 1) {
        continue;
    }

    $requests[] = new TranslationRequest(
        field: $field,
        sourceText: (string) ($payload['source'] ?? ''),
        existingTranslation: (string) ($payload['existing'] ?? ''),
        isHtml: ($payload['html'] ?? '') === '1',
        force: ($payload['force'] ?? '') === '1',
    );
}

if ($requests === []) {
    $fail(422, 'Er is niets bruikbaars om te vertalen meegestuurd.');
}

$service = new TranslationService();

if (!$service->isAvailable()) {
    $fail(503, 'Automatisch vertalen is niet ingesteld op deze installatie.');
}

try {
    $result = $service->translateEntity($entityType, $entityKey, $target, $requests, $source);
} catch (TranslationException $e) {
    // The message is written for an editor and carries no key, no endpoint
    // and no submitted text — see App\Service\Translation\DeepLProvider.
    $fail(502, $e->getMessage());
} catch (\Throwable $e) {
    error_log('[api/admin/translate-fields.php] ' . $e->getMessage());
    $fail(500, 'Automatisch vertalen is onverwacht misgegaan.');
}

echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
