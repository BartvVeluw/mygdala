<?php

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Every other admin API endpoint in this project follows the same fixed
 * guard order (see any api/admin/*.php file): AdminAuth::requireLoginForApi()
 * first, then a POST-only check, then Csrf::validate() before anything can
 * mutate state. There's no existing HTTP test harness in this project to
 * exercise that end-to-end (every test here is a pure-PHP unit test — see
 * tests/Service/ReservedRoutesTest.php), so this is a static, but concrete,
 * regression guard: it fails loudly if a future edit removes one of these
 * checks from the page builder's mutation endpoints, or reorders them so a
 * mutation could run before the guard.
 */
class PageBuilderSecurityTest extends TestCase
{
    private const ENDPOINTS = [
        'add-page-section.php',
        'delete-page-section.php',
        'toggle-page-section.php',
        'reorder-page-sections.php',
        'update-rich-text-section.php',
        // The page itself (settings + lifecycle), not just its sections.
        'create-page.php',
        'update-page.php',
        'delete-page.php',
    ];

    public function testEveryEndpointChecksLoginBeforeAnyMutation(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->sourceOf($endpoint);

            $this->assertMatchesRegularExpression(
                '/AdminAuth::requireLoginForApi\(\)/',
                $source,
                "{$endpoint} must call AdminAuth::requireLoginForApi()"
            );
        }
    }

    public function testEveryEndpointValidatesCsrfBeforeAnyMutation(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->sourceOf($endpoint);

            $this->assertMatchesRegularExpression(
                '/Csrf::validate\(\$_POST\[.csrf_token.\] \?\? null\)/',
                $source,
                "{$endpoint} must validate the csrf_token POST field"
            );

            $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $csrfPos = strpos($source, 'Csrf::validate(');
            $this->assertNotFalse($authPos);
            $this->assertNotFalse($csrfPos);
            $this->assertLessThan($csrfPos, $authPos, "{$endpoint} must check login before CSRF");
        }
    }

    public function testEveryEndpointRejectsNonPostMethods(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->sourceOf($endpoint);

            $this->assertStringContainsString(
                "\$_SERVER['REQUEST_METHOD'] !== 'POST'",
                $source,
                "{$endpoint} must reject non-POST methods"
            );
        }
    }

    private function sourceOf(string $endpoint): string
    {
        $path = dirname(__DIR__, 2) . '/api/admin/' . $endpoint;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
