<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique and reasoning as
 * tests/Service/OrderHandlingAdminSecurityTest.php and
 * tests/Service/InvoiceAdminSecurityTest.php — this project has no HTTP test
 * harness) for the product-deletion endpoint and the admin screen that drives
 * it.
 *
 * Covers MAIN.MD "Product verwijderen": a catalog product may only ever be
 * deleted through an authenticated, CSRF-protected POST with a server-side
 * validated id, and the destructive action must never be reachable as a link.
 */
final class ProductDeletionAdminSecurityTest extends TestCase
{
    private const ENDPOINT = 'delete-product.php';

    public function testEndpointChecksLoginBeforeCsrfBeforeAnyDeletion(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source);
        $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source);

        $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
        $csrfPos = strpos($source, 'Csrf::validate(');
        $deletePos = strpos($source, '->delete(');

        $this->assertNotFalse($authPos);
        $this->assertNotFalse($csrfPos);
        $this->assertNotFalse($deletePos);
        $this->assertLessThan($csrfPos, $authPos, 'login must be checked before CSRF');
        $this->assertLessThan($deletePos, $csrfPos, 'CSRF must be validated before anything is deleted');
    }

    /**
     * A GET must never be able to delete a product — no amount of link
     * prefetching, crawling or a pasted URL may destroy catalog data.
     */
    public function testEndpointRejectsNonPostRequests(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source);
        $this->assertStringContainsString('http_response_code(405)', $source);

        $methodPos = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
        $deletePos = strpos($source, '->delete(');

        $this->assertNotFalse($methodPos);
        $this->assertLessThan($deletePos, $methodPos, 'the POST-only check must come before any deletion');
    }

    public function testEndpointValidatesTheProductIdAsAnIntegerServerSide(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString('FILTER_VALIDATE_INT', $source);
        $this->assertStringContainsString('$id < 1', $source);
        $this->assertStringContainsString('http_response_code(400)', $source);

        $validationPos = strpos($source, 'FILTER_VALIDATE_INT');
        $deletePos = strpos($source, '->delete(');

        $this->assertLessThan($deletePos, $validationPos, 'the id must be validated before it is used');
    }

    /**
     * Redirect targets are built from literals plus the validated integer id,
     * never from a caller-supplied URL, so this cannot become an open redirect.
     */
    public function testRedirectTargetsAreBuiltFromLiteralsOnly(): void
    {
        preg_match_all('/header\(\s*.Location: ([^\'"]*)/', $this->endpointSource(), $matches);

        $this->assertNotEmpty($matches[1], 'expected at least one Location redirect');
        foreach ($matches[1] as $target) {
            $this->assertStringStartsWith('/admin/', $target, "redirect target must be a local admin path, got: {$target}");
        }
    }

    /**
     * The deletion rules must live in the service, not in the admin template —
     * so a forged request cannot bypass them by not being the admin UI.
     */
    public function testEndpointDelegatesToTheDeletionService(): void
    {
        $source = $this->endpointSource();

        $this->assertStringContainsString('use App\Service\ProductDeletionService;', $source);
        $this->assertStringContainsString('new ProductDeletionService()', $source);
        $this->assertStringNotContainsString('DELETE FROM', $source, 'no raw SQL belongs in the endpoint');
    }

    // ------------------------------------------------------------- admin UI

    public function testTheProductListRequiresAnAdminLoginAndPostsWithACsrfToken(): void
    {
        $source = $this->adminSource('products.php');

        $this->assertStringContainsString('AdminAuth::requireLogin()', $source);
        $this->assertStringContainsString('method="post" action="/api/admin/delete-product.php"', $source);
        $this->assertStringContainsString('name="csrf_token"', $source);
    }

    public function testTheProductListNeverExposesDeletionAsAGetLink(): void
    {
        $this->assertStringNotContainsString(
            'href="/api/admin/delete-product.php',
            $this->adminSource('products.php'),
            'deletion must not be reachable through a plain link'
        );
    }

    /**
     * The regression this whole change is about: every product must offer a
     * real Delete action, instead of the old "Verwijderen n.v.t." placeholder
     * that appeared for any product that had ever been ordered.
     */
    public function testTheProductListNoLongerShowsTheNotApplicablePlaceholder(): void
    {
        $this->assertStringNotContainsString(
            'Verwijderen n.v.t.',
            $this->adminSource('products.php'),
            'deletion is no longer refused for ordered products — the placeholder must be gone'
        );
    }

    public function testDeletionAsksForConfirmationInDutch(): void
    {
        $source = $this->adminSource('products.php');

        $this->assertStringContainsString('onsubmit="return confirm(', $source);
        $this->assertStringContainsString(
            'Weet je zeker dat je dit product definitief wilt verwijderen?',
            $source
        );
    }

    public function testDeletionIsVisuallyMarkedAsDestructive(): void
    {
        $this->assertStringContainsString(
            'admin-btn-text--danger',
            $this->adminSource('products.php'),
            'the destructive action must be visually distinct'
        );
    }

    /**
     * Activate/Deactivate must remain a separate, non-destructive action —
     * deletion does not replace it.
     */
    public function testActivateDeactivateStillExistsAlongsideDeletion(): void
    {
        $source = $this->adminSource('products.php');

        $this->assertStringContainsString('method="post" action="/api/admin/update-product-status.php"', $source);
        $this->assertStringContainsString('Deactiveren', $source);
        $this->assertStringContainsString('Activeren', $source);
        $this->assertStringContainsString('Verwijderen', $source);
    }

    /**
     * Historical orders are read through a LEFT JOIN so a detached order line
     * (product_id NULL after a deletion) still renders. An INNER JOIN here
     * would silently drop it from the order, the email and the invoice.
     */
    public function testHistoricalOrderItemsAreReadWithALeftJoinToProducts(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Repository/OrderRepository.php');

        $joinClause = 'JOIN products p ON p.id = oi.product_id';

        $total = substr_count($source, $joinClause);
        $left = substr_count($source, 'LEFT ' . $joinClause);

        $this->assertGreaterThan(0, $left, 'order items must be read through a LEFT JOIN to products');
        $this->assertSame(
            $total,
            $left,
            'every join from order_items to products must be a LEFT JOIN — an INNER JOIN drops the '
            . 'order line of a deleted product from the order, the email and the invoice'
        );
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
