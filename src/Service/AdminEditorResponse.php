<?php

declare(strict_types=1);

namespace App\Service;

use stdClass;

/**
 * The answer an admin endpoint gives the dynamic editor (admin/_admin_editor.php,
 * admin/assets/admin-editor.js): ONE shape for every endpoint that serves
 * one, so the editor script never has to learn a second.
 *
 *     200  {"ok": true,  "message": "Opgeslagen",       "data": {…}, "errors": {}}
 *     422  {"ok": false, "message": "Niet opgeslagen…", "data": {},  "errors": {…}}
 *     500  {"ok": false, "message": "…",                "data": {},  "errors": {}}
 *
 * `errors` is keyed by the NAME of the form field a message is about
 * ("price", "options[new0][name]") or by the key of the section it is about
 * ("variants"), each with a list of messages; the editor puts a message next
 * to that field, or in that section, and opens it. A message about nothing in
 * particular goes under "_form" and is shown in the summary alone.
 * `data.redirect` sends the browser on — a new item that now has an address
 * of its own.
 *
 * ONLY WHEN IT WAS ASKED FOR. An endpoint answers this way when the request
 * says it accepts JSON (wantsJson(); the editor script always does), and
 * keeps its redirect and session flash for a form posted without the script.
 * One set of rules behind both.
 *
 * THE GUARDS STAY AS THEY ARE. Login, permission, POST and CSRF answer before
 * any of this, in their own words (App\Service\AdminAuth); the script reads
 * their status codes and says in the CMS's language what 401 and 403 mean.
 */
final class AdminEditorResponse
{
    /** The key of a message that belongs to no field and no section. */
    public const FORM = '_form';

    /** Whether the request came from the editor script rather than a plain form post. */
    public static function wantsJson(): bool
    {
        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    /**
     * The body, as an array json_encode() turns into the contract: an empty
     * `data` or `errors` is an object ({}), never a list ([]).
     *
     * @param array<string, mixed>                          $data
     * @param array<int|string, string|list<string>>        $errors
     * @return array{ok: bool, message: string, data: array<string, mixed>|stdClass, errors: array<string, list<string>>|stdClass}
     */
    public static function body(bool $ok, string $message, array $data = [], array $errors = []): array
    {
        $grouped = self::group($errors);

        return [
            'ok' => $ok,
            'message' => $message,
            'data' => $data === [] ? new stdClass() : $data,
            'errors' => $grouped === [] ? new stdClass() : $grouped,
        ];
    }

    /**
     * Messages by field or section, each a list; a message under an integer
     * key (appended without a place) goes under FORM.
     *
     * @param array<int|string, string|list<string>> $errors
     * @return array<string, list<string>>
     */
    public static function group(array $errors): array
    {
        $grouped = [];

        foreach ($errors as $key => $messages) {
            $key = is_int($key) ? self::FORM : $key;

            foreach ((array) $messages as $message) {
                if (is_string($message) && $message !== '' && !in_array($message, $grouped[$key] ?? [], true)) {
                    $grouped[$key][] = $message;
                }
            }
        }

        return $grouped;
    }

    /**
     * The same messages as one list, in order and each once: what a session
     * flash shows above a form posted without the script.
     *
     * @param array<int|string, string|list<string>> $errors
     * @return list<string>
     */
    public static function messages(array $errors): array
    {
        $list = [];

        foreach (self::group($errors) as $messages) {
            foreach ($messages as $message) {
                if (!in_array($message, $list, true)) {
                    $list[] = $message;
                }
            }
        }

        return $list;
    }

    /** @param array<string, mixed> $data */
    public static function saved(string $message, array $data = []): never
    {
        self::send(200, self::body(true, $message, $data));
    }

    /** @param array<int|string, string|list<string>> $errors */
    public static function invalid(array $errors, string $message): never
    {
        self::send(422, self::body(false, $message, [], $errors));
    }

    public static function failed(string $message, int $status = 500): never
    {
        self::send($status, self::body(false, $message));
    }

    /** @param array<string, mixed> $body */
    private static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
