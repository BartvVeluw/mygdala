<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * How a failed no-JS submission gets back to the page it came from with the
 * errors AND with what the visitor typed still in the boxes.
 *
 * The problem it solves: the endpoint answers a normal browser POST with a
 * 303 back to the page (post/redirect/get, so a refresh never resubmits),
 * and a redirect carries nothing. Putting the answers in the query string is
 * out of the question — that is personal data in a URL, in the browser
 * history and in every access log. So they go in a session, and the redirect
 * carries only a status and which form it was about.
 *
 * A COOKIE ONLY FOR SOMEBODY WHO ACTUALLY SUBMITTED. Nothing starts a
 * session while a page is merely being viewed: the endpoint starts one when
 * a submission fails, and the page starts one only when the redirect it just
 * followed says there is something to read. An ordinary visitor who never
 * touches the form never gets a cookie, which is what keeps a public page
 * cacheable and keeps this out of the cookie banner.
 *
 * Its own session NAME, separate from `vvl_admin_session`
 * (App\Service\AdminAuth): a public visitor and a signed-in administrator
 * are different sessions, and an anonymous form must never be able to touch
 * the admin one.
 *
 * The flash is READ ONCE and cleared, and the session is closed again right
 * after, so nothing about a visitor's enquiry lingers past the page that
 * shows it back to them.
 */
final class PublicFormSession
{
    private const SESSION_NAME = 'vvl_public_session';

    private const FLASH_KEY = 'vvl_form_flash';

    /** Whether prime() has already run for this request. */
    private static bool $primed = false;

    /**
     * What prime() read, held for the rest of the request.
     *
     * @var array{token: string, values: array<string, string>, errors_nl: array<string, string>, errors_en: array<string, string>}|null
     */
    private static ?array $flash = null;

    /**
     * Remembers a failed submission. $errors and $values are already
     * normalised by the validator; only keys the form actually has can be in
     * them (App\Service\Forms\FormValidator).
     *
     * @param array<string, string> $values
     * @param array<string, string> $errorsNl
     * @param array<string, string> $errorsEn
     */
    public static function rememberFailure(
        string $formToken,
        array $values,
        array $errorsNl,
        array $errorsEn
    ): void {
        self::start();

        $_SESSION[self::FLASH_KEY] = [
            'token' => $formToken,
            'values' => $values,
            'errors_nl' => $errorsNl,
            'errors_en' => $errorsEn,
        ];

        session_write_close();
    }

    /**
     * READ THE FLASH BEFORE A SINGLE BYTE OF HTML IS WRITTEN, and hold it in
     * memory for the rest of the request.
     *
     * `session_start()` refuses once output has begun, and a content block
     * renders long after the `<head>` — so by the time the form asks for its
     * errors it is far too late to open a session. Every public page
     * template therefore calls this on its first line, next to its
     * `require vendor/autoload.php`. It is the same shape, and the same
     * reason, as `SectionRegistry::collectPageAssets()`: the answer is
     * needed later than it can be fetched, so it is fetched first.
     *
     * It does NOTHING at all unless the visitor was just redirected here by
     * a failed submission — no session is opened, no cookie is set and no
     * file is touched for an ordinary page view, which is what keeps a
     * public page cacheable.
     *
     * Tests\Service\FormContractTest fails the build if a page template
     * forgets the call.
     */
    public static function prime(): void
    {
        if (self::$primed) {
            return;
        }

        self::$primed = true;

        if (($_GET['form-status'] ?? null) !== 'error') {
            return;
        }

        $token = $_GET['form'] ?? null;
        if (!is_string($token) || $token === '' || !self::hasCookie()) {
            return;
        }

        if (headers_sent()) {
            // Nothing can be done about it now, and a warning printed into
            // the page would be worse than the missing values.
            error_log('[PublicFormSession] prime() ran after output had started; a failed submission could not be restored.');

            return;
        }

        self::start();

        $flash = $_SESSION[self::FLASH_KEY] ?? null;

        // Read once and clear: a refresh must show a clean form, not the
        // same errors for ever.
        unset($_SESSION[self::FLASH_KEY]);
        session_write_close();

        if (is_array($flash) && ($flash['token'] ?? null) === $token) {
            self::$flash = [
                'token' => $token,
                'values' => is_array($flash['values'] ?? null) ? $flash['values'] : [],
                'errors_nl' => is_array($flash['errors_nl'] ?? null) ? $flash['errors_nl'] : [],
                'errors_en' => is_array($flash['errors_en'] ?? null) ? $flash['errors_en'] : [],
            ];
        }
    }

    /**
     * The remembered failure for this rendered form instance, or null. It
     * comes out of what prime() read at the top of the request; nothing is
     * opened here, so it is safe to call from deep inside a template.
     *
     * @return array{values: array<string, string>, errors_nl: array<string, string>, errors_en: array<string, string>}|null
     */
    public static function takeFailure(string $formToken): ?array
    {
        // A page that never primed (a test, an unusual entry point) still
        // gets its chance, as long as nothing has been printed yet.
        self::prime();

        if (self::$flash === null || self::$flash['token'] !== $formToken) {
            return null;
        }

        $flash = self::$flash;
        self::$flash = null;

        return [
            'values' => $flash['values'],
            'errors_nl' => $flash['errors_nl'],
            'errors_en' => $flash['errors_en'],
        ];
    }

    /** Forgets what prime() read; tests use it between requests. */
    public static function reset(): void
    {
        self::$primed = false;
        self::$flash = null;
    }

    /**
     * Whether this visitor has a public session cookie at all. Checked
     * before start() so a page render never CREATES one just to find out
     * there was nothing to read.
     */
    public static function hasCookie(): bool
    {
        return isset($_COOKIE[self::SESSION_NAME]);
    }

    private static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
        ]);
        session_start();
    }
}
