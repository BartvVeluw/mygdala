<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Database;
use App\Service\ContactRateLimiter;

/**
 * Three cheap layers of bot defence in front of every public form, and not
 * one paid service among them.
 *
 *   HONEYPOT             a field a person never sees and never fills in.
 *   MINIMUM SUBMIT TIME  a page that was posted back within three seconds of
 *                        being rendered was not read and filled in by hand.
 *   RATE LIMIT           the same visitor may not submit endlessly.
 *
 * The first two are the ones the quote form has used since it was written,
 * moved here unchanged so every form gets them. They fail SILENTLY WITH A
 * FAKE SUCCESS: a bot that is told "your honeypot gave you away" learns to
 * leave the field alone next time, so it is told nothing, nothing is stored,
 * no e-mail is sent and no rate-limit slot is consumed. The rate limit is
 * the one layer that answers honestly, because a real person can hit it.
 *
 * NO reCAPTCHA, hCaptcha, Turnstile or Akismet on a public form. This site
 * does use Cloudflare Turnstile — on the checkout, where an abusive request
 * creates an order and a payment — and that is a deliberately different
 * trade-off: a contact form guarded by a third-party challenge costs every
 * honest visitor a puzzle and sends their IP to somebody else to protect an
 * e-mail. FORMS.md records that decision.
 *
 * NOT security on its own. The authority on what a submission may contain is
 * App\Service\Forms\FormValidator; this class only decides whether to look
 * at it at all.
 */
final class FormSpamGuard
{
    /** The hidden field a bot fills in and a person cannot see. */
    public const HONEYPOT_FIELD = 'hp-note';

    /** The hidden render timestamp. */
    public const TIMESTAMP_FIELD = 'form-ts';

    /** Faster than this and it was not a human filling in a page. */
    public const MIN_SUBMIT_SECONDS = 3;

    /** Its own rate-limit budget, separate from the checkout's and the uploads'. */
    public const RATE_LIMIT_SALT = 'vvl-forms-rate-limit';

    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 600;

    /** A silent rejection: answer as if it worked, do nothing at all. */
    public const VERDICT_SILENT_DISCARD = 'silent_discard';

    /** An honest rejection: too many attempts, try again later. */
    public const VERDICT_RATE_LIMITED = 'rate_limited';

    /** Carry on. */
    public const VERDICT_ACCEPT = 'accept';

    /**
     * The two checks that need no database. Kept separate from the rate
     * limit so a bot never reaches the database at all, and so the fast
     * tests can prove this half without one.
     *
     * @param array<string, mixed> $request usually $_POST
     */
    public function inspectRequest(array $request, ?int $now = null): string
    {
        $now ??= time();

        $honeypot = $request[self::HONEYPOT_FIELD] ?? '';
        if (is_string($honeypot) && trim($honeypot) !== '') {
            return self::VERDICT_SILENT_DISCARD;
        }
        if (!is_string($honeypot) && $honeypot !== null) {
            // An array or an object under that name is not a person either.
            return self::VERDICT_SILENT_DISCARD;
        }

        $renderedAt = filter_var($request[self::TIMESTAMP_FIELD] ?? '', FILTER_VALIDATE_INT);
        if ($renderedAt === false || ($now - $renderedAt) < self::MIN_SUBMIT_SECONDS) {
            return self::VERDICT_SILENT_DISCARD;
        }

        return self::VERDICT_ACCEPT;
    }

    /**
     * The rate limit, keyed on a salted hash of the visitor's IP — the raw
     * address is never stored (App\Service\ContactRateLimiter, reused here
     * with a salt of its own so a form submission and a checkout never
     * consume each other's budget).
     *
     * A database that cannot be reached returns true: a form that stops
     * accepting enquiries because a throttle table is unavailable would be
     * a worse failure than a missing throttle.
     */
    public function allowsAnotherAttempt(string $ip): bool
    {
        try {
            $limiter = new ContactRateLimiter(
                Database::connection(),
                self::RATE_LIMIT_SALT,
                self::MAX_ATTEMPTS,
                self::WINDOW_SECONDS
            );

            return $limiter->allow($ip);
        } catch (\Throwable $e) {
            error_log('[FormSpamGuard] rate limiter unavailable: ' . $e->getMessage());

            return true;
        }
    }
}
