<?php

declare(strict_types=1);

namespace App\Service;

use Dotenv\Dotenv;

/**
 * Server-side verification of a Cloudflare Turnstile token via Cloudflare's
 * Siteverify endpoint. Mirrors App\Service\MollieClientFactory's env-loading
 * pattern (own static Dotenv::createImmutable() call, no shared bootstrap in
 * this project) and App\Service\Shipping\PostNl\PostNlRateFetcher's HTTP
 * approach (file_get_contents()+stream_context, no ext-curl dependency —
 * works on Vimexx shared hosting without a specific compiled extension).
 *
 * `postSiteverify()` is the only method that touches the network, kept
 * `protected` specifically so tests can fake it with an anonymous subclass
 * instead of ever hitting Cloudflare — same pattern as
 * tests/Service/Shipping/PostNl/PostNlRateSyncServiceTest.php's fake fetcher.
 *
 * Fails closed on every ambiguous case (missing/unset secret key, network
 * failure, unreadable response, success !== true): verify() returns false,
 * never throws to the caller, and never lets checkout proceed on anything
 * other than an explicit `success: true` from Cloudflare. Technical details
 * are only ever logged (error_log), never returned to the caller/customer —
 * see api/checkout.php for the generic customer-facing message.
 */
class TurnstileVerifier
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const HTTP_TIMEOUT_SECONDS = 10;

    /**
     * $secretKeyOverride exists purely for tests (see
     * tests/Service/TurnstileVerifierTest.php) to deterministically exercise
     * the "no secret key configured" path without touching real env/.env
     * loading. Production code always calls `new TurnstileVerifier()` —
     * leaving this null makes secretKey() read TURNSTILE_SECRET_KEY from
     * .env, exactly as before.
     */
    public function __construct(private readonly ?string $secretKeyOverride = null)
    {
    }

    public function verify(mixed $token, ?string $remoteIp = null): bool
    {
        if (!is_string($token) || trim($token) === '') {
            error_log('[TurnstileVerifier] missing/empty token');

            return false;
        }

        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            error_log('[TurnstileVerifier] TURNSTILE_SECRET_KEY is not set (.env) — refusing to verify.');

            return false;
        }

        try {
            $response = $this->postSiteverify($secretKey, $token, $remoteIp);
        } catch (\Throwable $e) {
            error_log('[TurnstileVerifier] Siteverify request failed: ' . $e->getMessage());

            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log('[TurnstileVerifier] Siteverify returned an unreadable response.');

            return false;
        }

        if (($decoded['success'] ?? false) !== true) {
            $errorCodes = is_array($decoded['error-codes'] ?? null) ? $decoded['error-codes'] : [];
            error_log('[TurnstileVerifier] Siteverify rejected the token: ' . implode(',', $errorCodes));

            return false;
        }

        return true;
    }

    /** @throws \RuntimeException */
    protected function postSiteverify(string $secretKey, string $token, ?string $remoteIp): string
    {
        $fields = ['secret' => $secretKey, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents(self::SITEVERIFY_URL, false, $context);

        $statusLine = $http_response_header[0] ?? '';
        $statusOk = (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine);

        if ($body === false || !$statusOk) {
            throw new \RuntimeException('Could not reach Cloudflare Siteverify (' . ($statusLine ?: 'no response') . ')');
        }

        return $body;
    }

    /** The public site key, safe to render into HTML — never the secret key. */
    public static function siteKey(): string
    {
        self::loadEnv();

        return $_ENV['TURNSTILE_SITE_KEY'] ?? '';
    }

    private function secretKey(): ?string
    {
        if ($this->secretKeyOverride !== null) {
            return $this->secretKeyOverride !== '' ? $this->secretKeyOverride : null;
        }

        self::loadEnv();

        $key = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';

        return $key !== '' ? $key : null;
    }

    private static function loadEnv(): void
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->load();
        }
    }
}
