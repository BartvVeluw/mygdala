<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static source-inspection guard (same technique and reasoning as
 * tests/Service/ProductDeletionAdminSecurityTest.php and
 * PageBuilderSecurityTest.php — this project has no HTTP test harness for
 * authenticated admin POSTs) over every collection mutation endpoint.
 *
 * Covers the security requirements of the Collections feature: an admin
 * mutation is only ever reachable through an authenticated, CSRF-protected
 * POST, ids are validated server-side, and the destructive action can never
 * be triggered by a GET.
 */
final class CollectionAdminSecurityTest extends TestCase
{
    private const ENDPOINTS = [
        'create-collection.php',
        'update-collection.php',
        'delete-collection.php',
        'reorder-collections.php',
    ];

    /**
     * The first call in each endpoint that actually changes something —
     * every check below must come before it.
     *
     * Markers are written so they cannot also match the endpoint's own
     * docblock (which names the classes it delegates to): the delete marker
     * includes the argument it is actually called with, not just the method
     * name the docblock also mentions.
     */
    private const MUTATION_MARKERS = [
        'create-collection.php' => ['->create(', '->setCollectionProducts('],
        'update-collection.php' => ['->update(', '->setCollectionProducts('],
        'delete-collection.php' => ['CollectionService::delete($id'],
        'reorder-collections.php' => ['->reorderCollections('],
    ];

    private function endpointSource(string $endpoint): string
    {
        $path = dirname(__DIR__, 2) . '/api/admin/' . $endpoint;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function firstMutationPosition(string $source, string $endpoint): int
    {
        $positions = [];
        foreach (self::MUTATION_MARKERS[$endpoint] as $marker) {
            $pos = strpos($source, $marker);
            $this->assertNotFalse($pos, "{$endpoint} should contain {$marker}");
            $positions[] = $pos;
        }

        return min($positions);
    }

    public function testEveryEndpointChecksLoginBeforeCsrfBeforeAnyMutation(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringContainsString('AdminAuth::requireLoginForApi()', $source, $endpoint);
            $this->assertStringContainsString("Csrf::validate(\$_POST['csrf_token'] ?? null)", $source, $endpoint);

            $authPos = strpos($source, 'AdminAuth::requireLoginForApi()');
            $csrfPos = strpos($source, 'Csrf::validate(');
            $mutationPos = $this->firstMutationPosition($source, $endpoint);

            $this->assertLessThan($csrfPos, $authPos, "{$endpoint}: login must be checked before CSRF");
            $this->assertLessThan($mutationPos, $csrfPos, "{$endpoint}: CSRF must be validated before anything is written");
        }
    }

    public function testEveryEndpointRejectsNonPostRequests(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringContainsString("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $source, $endpoint);
            $this->assertStringContainsString('http_response_code(405)', $source, $endpoint);

            $methodPos = strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'");
            $mutationPos = $this->firstMutationPosition($source, $endpoint);

            $this->assertLessThan($mutationPos, $methodPos, "{$endpoint}: the POST-only check must come first");
        }
    }

    public function testTheIdCarryingEndpointsValidateTheirIdServerSide(): void
    {
        foreach (['update-collection.php', 'delete-collection.php'] as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringContainsString('FILTER_VALIDATE_INT', $source, $endpoint);
            $this->assertStringContainsString('$id < 1', $source, $endpoint);
            $this->assertStringContainsString('http_response_code(400)', $source, $endpoint);

            $validationPos = strpos($source, 'FILTER_VALIDATE_INT');
            $mutationPos = $this->firstMutationPosition($source, $endpoint);

            $this->assertLessThan($mutationPos, $validationPos, "{$endpoint}: the id must be validated before it is used");
        }
    }

    /**
     * Product and collection ids submitted by a form are never written
     * straight through — they always pass CollectionService, which confirms
     * each id exists, and the ordering is derived from the validated list's
     * position rather than from any submitted sort_order value.
     */
    public function testSubmittedProductAndCollectionIdsAreAlwaysValidatedBeforeUse(): void
    {
        foreach (['create-collection.php', 'update-collection.php'] as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringContainsString('CollectionService::validateProductIds(', $source, $endpoint);

            $validationPos = strpos($source, 'CollectionService::validateProductIds(');
            $writePos = strpos($source, '->setCollectionProducts(');

            $this->assertNotFalse($writePos, $endpoint);
            $this->assertLessThan($writePos, $validationPos, "{$endpoint}: ids must be validated before they are stored");
        }

        foreach (['create-product.php', 'update-product.php'] as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringContainsString('CollectionService::validateCollectionIds(', $source, $endpoint);

            $validationPos = strpos($source, 'CollectionService::validateCollectionIds(');
            $writePos = strpos($source, '->setProductCollections(');

            $this->assertNotFalse($writePos, $endpoint);
            $this->assertLessThan($writePos, $validationPos, "{$endpoint}: collection ids must be validated before they are stored");
        }
    }

    /**
     * No endpoint reads a sort_order straight out of the request: positions
     * are always derived from the validated id list.
     */
    public function testNoEndpointTrustsASubmittedSortOrderValue(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $source = $this->endpointSource($endpoint);

            $this->assertStringNotContainsString("\$_POST['sort_order']", $source, $endpoint);
            $this->assertStringNotContainsString("\$_GET['sort_order']", $source, $endpoint);
        }
    }

    /**
     * Redirect targets are built from literals plus the validated integer id,
     * never from a caller-supplied URL, so these cannot become open redirects.
     */
    public function testRedirectTargetsAreBuiltFromLiteralsOnly(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            preg_match_all('/header\(\s*.Location: ([^\'"]*)/', $this->endpointSource($endpoint), $matches);

            foreach ($matches[1] as $target) {
                $this->assertStringStartsWith('/admin/', $target, "{$endpoint}: redirect target must be a literal admin path");
            }
        }
    }

    /**
     * A save that did not carry the product picker must not be read as
     * "unticked everything". The editor renders a products_submitted marker
     * next to the picker, and the update endpoint only synchronises
     * membership when that marker is present — so a save made while the
     * catalogue was empty or the product query had failed leaves the
     * collection's contents alone instead of silently emptying it.
     */
    public function testMembershipIsOnlySynchronisedWhenTheFormCarriedThePicker(): void
    {
        $endpoint = $this->endpointSource('update-collection.php');
        $this->assertStringContainsString("isset(\$_POST['products_submitted'])", $endpoint);

        $markerPos = strpos($endpoint, "isset(\$_POST['products_submitted'])");
        $syncPos = strpos($endpoint, '->setCollectionProducts(');

        $this->assertNotFalse($markerPos);
        $this->assertNotFalse($syncPos);
        $this->assertLessThan($syncPos, $markerPos, 'the guard must come before the sync');

        $editor = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/collection.php');
        $this->assertStringContainsString('name="products_submitted"', $editor);
    }

    /**
     * Deleting a collection must never touch the products table. This is the
     * defining safety property of the feature, so it is asserted at the
     * source level too, not only behaviourally (see
     * CollectionRepositoryIntegrationTest).
     */
    public function testTheDeleteEndpointNeverTouchesProducts(): void
    {
        $source = $this->endpointSource('delete-collection.php');

        $this->assertStringNotContainsString('ProductRepository', $source);
        $this->assertStringNotContainsString('ProductDeletionService', $source);
        $this->assertStringNotContainsString('DELETE FROM products', $source);
    }

    /**
     * The admin screens offer deletion only as a POST form with a
     * confirmation dialog — the project's established pattern for a
     * destructive CMS action.
     */
    public function testTheAdminScreensGuardDeletionWithAConfirmationPost(): void
    {
        foreach (['collections.php', 'collection.php'] as $screen) {
            $path = dirname(__DIR__, 2) . '/admin/' . $screen;
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);

            $this->assertStringContainsString('action="/api/admin/delete-collection.php"', $source, $screen);
            $this->assertStringContainsString('onsubmit="return confirm(', $source, $screen);
            $this->assertStringContainsString('name="csrf_token"', $source, $screen);
            $this->assertStringNotContainsString('href="/api/admin/delete-collection.php', $source, $screen);
        }
    }

    /**
     * Both admin screens are behind the session login, like every other
     * /admin page.
     */
    public function testTheAdminScreensRequireLogin(): void
    {
        foreach (['collections.php', 'collection.php'] as $screen) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/' . $screen);

            $this->assertStringContainsString('AdminAuth::requireLogin()', $source, $screen);
        }
    }
}
