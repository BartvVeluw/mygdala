<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique and reasoning as
 * tests/Service/InvoiceAdminSecurityTest.php — this project has no HTTP test
 * harness) for the handling-status endpoint and the two admin screens that
 * drive it. Covers MAIN.MD "Afhandelingsstatus": an order's handling status
 * may only ever change through an authenticated, CSRF-protected POST, and
 * only a paid order may be marked handled.
 */
final class OrderHandlingAdminSecurityTest extends TestCase
{
    private const ENDPOINT = 'update-fulfilment-status.php';

    public function testEndpointChecksLoginBeforeCsrfBeforeAnyMutation(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source);
        $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source);

        $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
        $csrfPos = strpos($source, 'Csrf::validate(');
        $mutationPos = strpos($source, '->markHandled(');

        $this->assertNotFalse($authPos);
        $this->assertNotFalse($csrfPos);
        $this->assertNotFalse($mutationPos);
        $this->assertLessThan($csrfPos, $authPos, 'login must be checked before CSRF');
        $this->assertLessThan($mutationPos, $csrfPos, 'CSRF must be validated before anything is written');
    }

    public function testEndpointRejectsNonPostRequests(): void
    {
        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $this->endpointSource());
    }

    /**
     * The whole point of the paid-order rule: an order that was never paid
     * must not be presentable as dealt with. Checked here in the endpoint,
     * and again in SQL (see OrderHandlingStatusTest).
     */
    public function testEndpointRefusesToMarkANonPaidOrderAsHandled(): void
    {
        $source = $this->endpointSource();

        $paidCheckPos = strpos($source, "\$order['status'] !== 'paid'");
        $markHandledPos = strpos($source, '->markHandled(');

        $this->assertNotFalse($paidCheckPos, 'endpoint must check the payment status');
        $this->assertNotFalse($markHandledPos);
        $this->assertLessThan($markHandledPos, $paidCheckPos, 'the paid check must come before markHandled()');
        $this->assertStringContainsString('jsonFail(409', $source);
    }

    /**
     * Order ids arrive from a form field, so they must be validated as
     * integers rather than interpolated anywhere.
     */
    public function testEndpointValidatesTheOrderIdAsAnInteger(): void
    {
        $this->assertStringContainsString('FILTER_VALIDATE_INT', $this->endpointSource());
    }

    /**
     * The status value is only ever accepted from the fixed whitelist, so no
     * arbitrary string can reach the database.
     */
    public function testEndpointOnlyAcceptsWhitelistedStatusValues(): void
    {
        $this->assertStringContainsString(
            'in_array($fulfilmentStatus, OrderRepository::FULFILMENT_STATUSES, true)',
            $this->endpointSource()
        );
    }

    /**
     * Redirect targets are built from literals plus the already-validated
     * integer id — never from a caller-supplied URL — so the return-to
     * parameter cannot be abused as an open redirect.
     */
    public function testRedirectTargetsAreBuiltFromLiteralsOnly(): void
    {
        $source = $this->endpointSource();

        preg_match_all('/header\(\s*.Location: ([^\'"]*)/', $source, $matches);

        $this->assertNotEmpty($matches[1], 'expected at least one Location redirect');
        foreach ($matches[1] as $target) {
            $this->assertStringStartsWith('/admin/', $target, "redirect target must be a local admin path, got: {$target}");
        }
    }

    /**
     * Both admin screens must submit by POST with a CSRF token — the overview
     * quick action just as much as the detail page, which is the whole reason
     * it is a form and not a link.
     */
    public function testBothAdminScreensPostToTheEndpointWithACsrfToken(): void
    {
        foreach (['orders.php', 'order.php'] as $page) {
            $source = $this->adminSource($page);

            $this->assertStringContainsString(
                'method="post" action="/api/admin/update-fulfilment-status.php"',
                $source,
                "{$page} must submit the handling change as a POST form"
            );
            $this->assertStringContainsString('name="csrf_token"', $source, "{$page} must include a CSRF token");
            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, "{$page} must require an admin login");
        }
    }

    /**
     * No handling change may ever be reachable through a plain link.
     */
    public function testNoAdminScreenLinksToTheEndpointWithAGetRequest(): void
    {
        foreach (['orders.php', 'order.php'] as $page) {
            $this->assertStringNotContainsString(
                'href="/api/admin/update-fulfilment-status.php',
                $this->adminSource($page),
                "{$page} must not expose the handling change as a GET link"
            );
        }
    }

    /**
     * The overview must not offer "mark as handled" on an order that cannot
     * legally be marked handled — nor to an account that only holds
     * orders.view (the endpoint refuses it either way, see
     * AdminAccessControlTest).
     */
    public function testTheOverviewOnlyOffersTheQuickActionForPaidOrHandledOrders(): void
    {
        $source = $this->adminSource('orders.php');

        $this->assertStringContainsString("\$isPaid = \$order['status'] === 'paid';", $source);
        $this->assertStringContainsString("\$canManageOrders = AdminAuth::can('orders.manage');", $source);
        $this->assertStringContainsString('if ($canManageOrders && ($isHandled || $isPaid)):', $source);
    }

    private function endpointSource(): string
    {
        $path = dirname(__DIR__, 2) . '/api/admin/' . self::ENDPOINT;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function adminSource(string $page): string
    {
        $path = dirname(__DIR__, 2) . '/admin/' . $page;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
