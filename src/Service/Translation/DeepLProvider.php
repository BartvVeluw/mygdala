<?php

declare(strict_types=1);

namespace App\Service\Translation;

use App\Service\HttpUserAgent;
use App\Service\Language\LanguageRegistry;
use Dotenv\Dotenv;

/**
 * DeepL, the first real translation provider.
 *
 * WHAT THIS CLASS MAY NEVER DO, and what Tests\Service\TranslationProviderTest
 * checks:
 *
 *   - reach the browser. The API key is read from the environment, the
 *     request is made from PHP, and nothing about DeepL is ever rendered into
 *     HTML or into JavaScript. The admin screen posts to an endpoint of this
 *     site; that endpoint talks to DeepL.
 *   - appear in a log line. Failures log the status code and the endpoint,
 *     never the key and never the submitted text — an editor's draft is not
 *     log material.
 *   - be required. An installation without DEEPL_API_KEY gets
 *     NullTranslationProvider and a CMS that works in every other respect.
 *
 * FREE VERSUS PRO IS A DIFFERENT HOSTNAME, not a different parameter. DeepL
 * serves free accounts from api-free.deepl.com and paid ones from
 * api.deepl.com, and a key sent to the wrong host is rejected. The plan is
 * therefore configuration (DEEPL_API_PLAN), with one convenience: DeepL's
 * own free keys end in ':fx', so a key that says so selects the free host
 * even when nobody set the plan.
 *
 * AUTHENTICATION is the header form DeepL documents today:
 *
 *     Authorization: DeepL-Auth-Key <key>
 *
 * HTML. When $html is true the request carries tag_handling=html, so DeepL
 * parses the markup itself, translates the text nodes and returns the same
 * structure. That is the whole reason rich text works: this class never
 * strips tags, never translates the plain text and never rebuilds the markup
 * afterwards. What comes back is still untrusted and goes through the
 * project's own sanitiser at the call site (App\Service\Translation\
 * TranslationService), because a translation service is a third party like
 * any other.
 */
final class DeepLProvider implements TranslationProvider
{
    public const KEY = 'deepl';

    private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    /** DeepL's own marker for a free-tier key. */
    private const FREE_KEY_SUFFIX = ':fx';

    private const HTTP_TIMEOUT_SECONDS = 15;

    /** DeepL rejects oversized requests; this is well under its documented limit. */
    private const MAX_TEXTS_PER_REQUEST = 50;

    private static bool $envLoaded = false;

    public function __construct(
        private readonly ?string $apiKeyOverride = null,
        private readonly ?string $planOverride = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function supports(string $source, string $target): bool
    {
        if (!$this->isConfigured() || $source === $target) {
            return false;
        }

        return $this->sourceCode($source) !== null && $this->targetCode($target) !== null;
    }

    public function translate(string $text, string $source, string $target, bool $html = false): string
    {
        $translated = $this->translateAll(['value' => $text], $source, $target, $html);

        return $translated['value'] ?? '';
    }

    public function translateAll(array $texts, string $source, string $target, bool $html = false): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            throw new TranslationException('DeepL is not configured on this installation.');
        }

        $sourceCode = $this->sourceCode($source);
        $targetCode = $this->targetCode($target);
        if ($sourceCode === null || $targetCode === null) {
            throw new TranslationException('DeepL cannot translate between these two languages.');
        }

        // Only non-empty strings go out. An empty field has nothing to
        // translate, and sending it would spend quota to get an empty string
        // back — while also telling DeepL more about the site than it needs.
        $payload = [];
        foreach ($texts as $field => $text) {
            $value = trim((string) $text);
            if ($value !== '') {
                $payload[$field] = $value;
            }
        }

        if ($payload === []) {
            return [];
        }

        $results = [];
        foreach (array_chunk($payload, self::MAX_TEXTS_PER_REQUEST, true) as $chunk) {
            $results += $this->requestChunk($apiKey, $chunk, $sourceCode, $targetCode, $html);
        }

        return $results;
    }

    /**
     * @param array<string, string> $chunk
     * @return array<string, string>
     */
    private function requestChunk(string $apiKey, array $chunk, string $source, string $target, bool $html): array
    {
        $fields = [
            'source_lang' => $source,
            'target_lang' => $target,
            // DeepL returns translations in submission order, so the order of
            // this list is what maps a result back to a field. Keys are kept
            // out of the request entirely: they are this CMS's column names
            // and DeepL has no business knowing them.
            'text' => array_values($chunk),
        ];

        if ($html) {
            $fields['tag_handling'] = 'html';
        }

        $body = $this->post($this->endpoint($apiKey), $apiKey, $fields);

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['translations']) || !is_array($decoded['translations'])) {
            error_log('[DeepLProvider] Unreadable response from the translation API.');

            throw new TranslationException('The translation service returned something this CMS could not read.');
        }

        $fieldNames = array_keys($chunk);
        $translated = [];
        foreach (array_values($decoded['translations']) as $index => $translation) {
            if (!isset($fieldNames[$index]) || !is_array($translation)) {
                continue;
            }

            $translated[$fieldNames[$index]] = (string) ($translation['text'] ?? '');
        }

        if (count($translated) !== count($chunk)) {
            throw new TranslationException('The translation service returned fewer translations than were asked for.');
        }

        return $translated;
    }

    /**
     * The HTTP call itself, isolated so a test can subclass and replace it
     * without ever reaching the network — the same seam
     * App\Service\TurnstileVerifier uses for exactly the same reason.
     *
     * @param array<string, mixed> $fields
     * @throws TranslationException
     */
    protected function post(string $endpoint, string $apiKey, array $fields): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                    // The key travels in a header, never in the URL: a query
                    // string ends up in proxy logs and in browser history.
                    . 'Authorization: DeepL-Auth-Key ' . $apiKey . "\r\n"
                    . 'User-Agent: ' . HttpUserAgent::forPurpose('content translation') . "\r\n",
                'content' => http_build_query($fields),
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $context);

        $statusLine = $http_response_header[0] ?? '';
        $statusOk = (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine);

        if ($body === false || !$statusOk) {
            // The status line and the endpoint, and nothing else. Never the
            // key, never the text that was being translated.
            error_log('[DeepLProvider] Translation request failed: ' . ($statusLine ?: 'no response'));

            throw new TranslationException($this->readableFailure($statusLine));
        }

        return $body;
    }

    /** An explanation an editor can act on, from a status line they never see. */
    private function readableFailure(string $statusLine): string
    {
        if (str_contains($statusLine, ' 403') || str_contains($statusLine, ' 401')) {
            return 'the translation service refused the configured API key';
        }

        if (str_contains($statusLine, ' 456')) {
            return 'the translation account has used up its quota';
        }

        if (str_contains($statusLine, ' 429')) {
            return 'the translation service is rate-limiting this site; try again shortly';
        }

        return 'the translation service could not be reached';
    }

    /** Free and Pro are different hosts; the plan, or the key itself, decides. */
    private function endpoint(string $apiKey): string
    {
        return $this->isFreePlan($apiKey) ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
    }

    private function isFreePlan(string $apiKey): bool
    {
        // DeepL stamps its free keys, and that beats a configuration value
        // somebody forgot to change.
        if (str_ends_with($apiKey, self::FREE_KEY_SUFFIX)) {
            return true;
        }

        $plan = strtolower(trim((string) ($this->planOverride ?? $this->env('DEEPL_API_PLAN'))));

        // Anything that is not explicitly "pro" is treated as free, because a
        // free key sent to the paid host fails outright while the reverse is
        // the same single misconfiguration with the same single fix.
        return $plan !== 'pro';
    }

    private function sourceCode(string $language): ?string
    {
        return LanguageRegistry::get($language)?->deeplSource;
    }

    private function targetCode(string $language): ?string
    {
        return LanguageRegistry::get($language)?->deeplTarget;
    }

    private function apiKey(): ?string
    {
        if ($this->apiKeyOverride !== null) {
            return $this->apiKeyOverride !== '' ? $this->apiKeyOverride : null;
        }

        $key = trim($this->env('DEEPL_API_KEY'));

        return $key !== '' ? $key : null;
    }

    private function env(string $name): string
    {
        self::loadEnv();

        if (isset($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);

        return $value === false ? '' : (string) $value;
    }

    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }

        self::$envLoaded = true;

        $root = dirname(__DIR__, 3);
        if (file_exists($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }
    }
}
