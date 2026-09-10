<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * The page a form was submitted from: recorded on the submission so the
 * owner can tell a landing page's enquiries from the contact page's, and
 * used as the address the no-JS flow sends the visitor back to.
 *
 * It arrives in a hidden field, which makes it VISITOR INPUT, which makes an
 * unvalidated redirect to it an open redirect — the classic way a phishing
 * link borrows a trusted domain. So exactly one shape is accepted here:
 *
 *   - starts with a single '/', never '//' or '/\' (a protocol-relative URL
 *     to another host);
 *   - no scheme, no '@', no control characters, no backslash;
 *   - at most 255 characters;
 *   - a query string is allowed, a fragment is dropped.
 *
 * Anything else becomes null, and the caller falls back to a path it chose
 * itself. This is deliberately stricter than "is it a URL": there is no
 * situation in which a form should send a visitor off-site.
 */
final class FormSourcePath
{
    public const MAX_LENGTH = 255;

    /** Where a submission goes when no usable source path came with it. */
    public const FALLBACK = '/';

    /**
     * Cleans a submitted path, or null when it is not a safe same-site one.
     */
    public static function clean(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = trim($raw);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters (a CR/LF here would split a Location header),
        // backslashes (some browsers treat '/\' as '//') and '@' (which
        // turns "/@evil.example" style paths into credentials in some
        // parsers) are all rejected outright rather than stripped.
        if (preg_match('/[\x00-\x1F\x7F\\\\@]/', $value) === 1) {
            return null;
        }

        if ($value[0] !== '/' || str_starts_with($value, '//')) {
            return null;
        }

        // A fragment never reaches the server anyway; dropping it keeps the
        // stored value equal to what the request actually asked for.
        $hash = strpos($value, '#');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        return $value === '' ? null : $value;
    }

    /**
     * The path of the request currently being served — what a rendered form
     * puts in its hidden field. Built from REQUEST_URI, cleaned by the same
     * rule, and with any form status parameters of a previous round dropped
     * so they do not accumulate.
     */
    public static function current(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = self::clean($uri);

        if ($path === null) {
            return self::FALLBACK;
        }

        return self::withoutStatusParameters($path);
    }

    /**
     * Adds the status a submission ended in to a path, replacing any status
     * that was already on it.
     */
    public static function withStatus(string $path, string $status, string $formToken): string
    {
        $clean = self::clean($path) ?? self::FALLBACK;
        $base = self::withoutStatusParameters($clean);

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query([
            'form-status' => $status,
            'form' => $formToken,
        ]) . '#' . $formToken;
    }

    /** Strips this feature's own query parameters from a path. */
    public static function withoutStatusParameters(string $path): string
    {
        $questionMark = strpos($path, '?');
        if ($questionMark === false) {
            return $path;
        }

        $base = substr($path, 0, $questionMark);
        parse_str(substr($path, $questionMark + 1), $query);

        unset($query['form-status'], $query['form']);

        if ($query === []) {
            return $base === '' ? self::FALLBACK : $base;
        }

        return ($base === '' ? self::FALLBACK : $base) . '?' . http_build_query($query);
    }
}
