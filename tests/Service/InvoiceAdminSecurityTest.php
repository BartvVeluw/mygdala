<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique and reasoning as
 * tests/Service/PageBuilderSecurityTest.php — this project has no HTTP test
 * harness) for the three new invoice/resend admin endpoints. Covers MAIN.MD
 * "Admin invoice download requires authentication" and the general
 * AdminAuth-before-CSRF-before-mutation guard order used everywhere else in
 * admin/api.
 */
final class InvoiceAdminSecurityTest extends TestCase
{
    private const MUTATING_ENDPOINTS = [
        'generate-invoice.php',
        'resend-order-confirmation.php',
    ];

    private const READ_ONLY_ENDPOINTS = [
        'invoice-download.php',
    ];

    public function testMutatingEndpointsCheckLoginBeforeCsrfBeforeAnyMutation(): void
    {
        foreach (self::MUTATING_ENDPOINTS as $endpoint) {
            $source = $this->sourceOf($endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, "{$endpoint} must call AdminAuth::requireLoginForApi()");
            $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source, "{$endpoint} must validate the csrf_token POST field");
            $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source, "{$endpoint} must reject non-POST methods");

            $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $csrfPos = strpos($source, 'Csrf::validate(');
            $this->assertNotFalse($authPos);
            $this->assertNotFalse($csrfPos);
            $this->assertLessThan($csrfPos, $authPos, "{$endpoint} must check login before CSRF");
        }
    }

    public function testReadOnlyInvoiceEndpointsRequireAdminLogin(): void
    {
        foreach (self::READ_ONLY_ENDPOINTS as $endpoint) {
            $source = $this->sourceOf($endpoint);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, "{$endpoint} must call AdminAuth::requireLogin()");
        }
    }

    public function testGenerateInvoiceNeverIssuesANewInvoiceWhenOneAlreadyExists(): void
    {
        $source = $this->sourceOf('generate-invoice.php');

        // issueForOrderIfNeeded() is InvoiceService's own idempotent entry
        // point (see InvoiceServiceTest) — this just guards against someone
        // wiring this endpoint to a different, non-idempotent method later.
        $this->assertStringContainsString('issueForOrderIfNeeded', $source);
    }

    public function testResendNeverCreatesANewInvoice(): void
    {
        $source = $this->sourceOf('resend-order-confirmation.php');

        $this->assertStringContainsString('->resend(', $source);
        $this->assertStringNotContainsString('InvoiceService', $source, 'resend endpoint must not touch invoice issuance at all');
    }

    private function sourceOf(string $endpoint): string
    {
        $path = dirname(__DIR__, 2) . '/api/admin/' . $endpoint;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
