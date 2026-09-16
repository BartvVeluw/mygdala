<?php

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Same static-source regression guard as PageBuilderSecurityTest, applied
 * to every new Global Navigation + Footer admin mutation endpoint: each
 * must check AdminAuth::requireLoginForApi() before Csrf::validate(), and
 * reject non-POST methods, before anything can mutate state.
 */
class NavigationFooterSecurityTest extends TestCase
{
    private const ENDPOINTS = [
        'create-nav-item.php',
        'update-nav-item.php',
        'delete-nav-item.php',
        'toggle-nav-item.php',
        'reorder-nav-items.php',
        'move-nav-item.php',
        'create-footer-column.php',
        'update-footer-column.php',
        'delete-footer-column.php',
        'toggle-footer-column.php',
        'reorder-footer-columns.php',
        'create-footer-link.php',
        'update-footer-link.php',
        'delete-footer-link.php',
        'toggle-footer-link.php',
        'reorder-footer-links.php',
        'update-footer-settings.php',
        'update-header-footer-settings.php',
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
