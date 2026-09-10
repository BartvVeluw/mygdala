<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\DashboardAttention;
use App\Service\Personalization\ProductPersonalizationContent;
use PHPUnit\Framework\TestCase;

/**
 * Guards the promises the dashboard makes, which no unit test of the
 * calculation classes can cover: it never shows a figure to someone who may
 * not see the screen behind it, and it stays a management overview rather than
 * growing into an analytics layer.
 *
 * The dashboard is two files since the modules arrived. admin/index.php is
 * CORE: the page shell, the content summary, the navigation cards, and a loop
 * over whatever panels the enabled modules contribute. admin/_dashboard_shop.php
 * is the SHOP's panel — the order figures, the attention list and the recent
 * orders — contributed through App\Module\ShopModule::dashboardPanels(). The
 * permission rules below did not change; they are simply asserted against the
 * file that now holds the code.
 *
 * Static source inspection, the same technique and for the same reason as
 * tests/Service/InvoiceAdminSecurityTest.php and PersonalizationCmsSeparationTest.php
 * — this project has no HTTP harness for authenticated admin requests, and
 * these are properties of the file, not of a function.
 */
final class DashboardPageTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function dashboard(): string
    {
        return $this->source('admin/index.php');
    }

    /** The Shop's contributed panel. */
    private function shopPanel(): string
    {
        return $this->source('admin/_dashboard_shop.php');
    }

    /* ------------------------------------------------------------------ */
    /* Permissions                                                         */
    /* ------------------------------------------------------------------ */

    public function testTheDashboardStillRequiresLoginAndItsOwnPermission(): void
    {
        $source = $this->dashboard();

        $this->assertStringContainsString('AdminAuth::requireLogin();', $source);
        $this->assertStringContainsString("AdminAuth::requirePermission('dashboard.view');", $source);
    }

    /**
     * The turnover figures must not merely be hidden from someone without
     * `orders.view` — the queries behind them must not run at all, so there
     * is nothing to leak through an error message or a page source.
     */
    public function testOrderFiguresAreOnlyQueriedWithOrdersView(): void
    {
        $source = $this->shopPanel();

        $this->assertStringContainsString("\$canViewOrders = AdminAuth::can('orders.view');", $source);

        $guardPosition = strpos($source, 'if ($canViewOrders) {');
        $this->assertIsInt($guardPosition, 'the order queries must sit behind an orders.view guard');

        foreach (['orderTotalsBetween', 'findRecentOrders', 'countOrdersAwaitingHandling'] as $method) {
            $callPosition = strpos($source, $method . '(');
            $this->assertIsInt($callPosition, $method . '() should be called by the dashboard');
            $this->assertGreaterThan(
                $guardPosition,
                $callPosition,
                $method . '() must be called after the orders.view guard, never before it'
            );
        }
    }

    public function testProductAndPersonalizationDataAreOnlyQueriedWithTheirOwnPermission(): void
    {
        $source = $this->shopPanel();

        $this->assertMatchesRegularExpression(
            '/if \(\$canViewProducts\) \{\s*\$activeProducts = \$dashboard->findActiveProductsForAttention\(\);/',
            $source
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$canManagePersonalization\) \{\s*\$personalizationRows = \(new ProductPersonalizationRepository\(\)\)->findAllConfigured\(\);/',
            $source
        );
    }

    /**
     * The order list this page links into is read-only for `orders.view`, so
     * the dashboard must not offer any status-changing control of its own —
     * it links, it never posts.
     */
    public function testTheDashboardOnlyLinksAndNeverPosts(): void
    {
        foreach ([$this->dashboard(), $this->shopPanel()] as $source) {
            $this->assertStringNotContainsString('<form', $source);
            $this->assertStringNotContainsString('/api/admin/', $source);
        }
    }

    /**
     * The Core dashboard must not know a webshop exists. It renders whatever
     * panels the enabled modules hand it, so on a CMS-only deployment no order
     * or product query can run — not because a condition is false, but because
     * the file that would run it is never included.
     */
    public function testTheCoreDashboardNamesNoShopClass(): void
    {
        $code = $this->withoutComments($this->dashboard());

        foreach (['DashboardRepository', 'OrderRepository', 'DashboardMetrics', 'DashboardAttention',
                  'ProductPersonalizationRepository', 'orders.view', 'products.view'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $code,
                'the Core dashboard must reach the Shop only through its contributed panel'
            );
        }

        $this->assertStringContainsString("ModuleRegistry::collect('dashboardPanels')", $code);
    }

    /* ------------------------------------------------------------------ */
    /* Shape of the feature                                                */
    /* ------------------------------------------------------------------ */

    public function testTheDashboardContainsNoSqlOfItsOwn(): void
    {
        foreach ([$this->dashboard(), $this->shopPanel()] as $source) {
            foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $keyword) {
                $this->assertStringNotContainsString(
                    $keyword,
                    $source,
                    'dashboard SQL belongs in App\Repository\DashboardRepository'
                );
            }
        }
    }

    /**
     * Shop management, not analytics: no visitor tracking, no event log, no
     * aggregation table. If that ever gets built it must be a separate
     * feature, not something this page grew into.
     *
     * It since HAS been built (App\Service\Analytics, rendered by
     * admin/_dashboard_analytics.php), and both features now land on this
     * one screen. That is exactly the shape this test was written to
     * protect: the dashboard `require`s the statistics panel as a whole and
     * knows nothing else about it. So the single include is allowed, and
     * everything the rule was actually about -- this page querying, hashing
     * or counting visitors itself -- still fails.
     */
    public function testTheDashboardDoesNotReachIntoAnyTrackingLayer(): void
    {
        // Comments are stripped first: the file explains in prose that it is
        // deliberately NOT analytics, and that explanation must not itself
        // trip the check.
        $code = strtolower($this->withoutComments($this->dashboard()));

        // The one permitted mention: including the separate analytics
        // partial. Removed before the check so the rest still has to be
        // clean, and so a SECOND analytics reference would still fail.
        $include = "require __dir__ . '/_dashboard_analytics.php';";
        $this->assertStringContainsString(
            $include,
            $code,
            'the statistics panel is expected to be included as one whole partial'
        );
        $code = str_replace($include, '', $code);

        foreach (['analytics', 'tracking', 'pageview', 'visitor', 'ga4', 'gtag'] as $needle) {
            $this->assertStringNotContainsString($needle, $code);
        }
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }

    public function testTheAttentionListIsTruncatedForDisplayButCountedInFull(): void
    {
        $source = $this->shopPanel();

        $this->assertStringContainsString('DashboardAttention::MAX_VISIBLE', $source);
        $this->assertStringContainsString('$attentionTotal = count($attentionItems);', $source);
        $this->assertGreaterThan(0, DashboardAttention::MAX_VISIBLE);
    }

    public function testTheDashboardReusesTheExistingOrderNumberAndStatusLabels(): void
    {
        $source = $this->shopPanel();

        $this->assertStringContainsString('OrderRepository::formatOrderNumber(', $source);
        $this->assertStringContainsString('adminPaymentStatusLabel(', $source);
        $this->assertStringContainsString('adminFulfilmentBadgeModifier(', $source);
    }

    public function testRecentOrdersLinkToTheExistingOrderDetailPage(): void
    {
        $this->assertStringContainsString('/admin/order.php?id=', $this->shopPanel());
    }

    /* ------------------------------------------------------------------ */
    /* One definition of "onvolledig"                                      */
    /* ------------------------------------------------------------------ */

    public function testTheIncompletenessRuleIsAskedRatherThanRewritten(): void
    {
        $overview = $this->source('admin/personalization.php');

        $this->assertStringContainsString(
            'ProductPersonalizationContent::summaryIsIncomplete(',
            $overview,
            'the Personalisatie overview must ask the shared rule'
        );
        $this->assertStringNotContainsString(
            '($viewsWithImage === 0 || $zoneCount === 0)',
            $overview,
            'the inline copy of the rule should be gone'
        );
    }

    public function testTheSharedIncompletenessRule(): void
    {
        $this->assertFalse(ProductPersonalizationContent::summaryIsIncomplete(1, 1));
        $this->assertTrue(ProductPersonalizationContent::summaryIsIncomplete(0, 1));
        $this->assertTrue(ProductPersonalizationContent::summaryIsIncomplete(1, 0));
        $this->assertTrue(ProductPersonalizationContent::summaryIsIncomplete(0, 0));
    }
}
