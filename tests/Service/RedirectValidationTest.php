<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Service\PageContent;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectTarget;
use App\Service\Redirects\RedirectValidator;
use PHPUnit\Framework\TestCase;

/**
 * What may and may not be SAVED as a redirect: the conflict rules that keep a
 * redirect off a URL this site already serves, and the loop rules that keep a
 * chain from folding back on itself.
 *
 * Uses its own disposable rows throughout — a page with an obviously fake slug
 * and redirect sources prefixed "zz-" — created in setUp() and removed in
 * tearDown(), so a run leaves the CMS content exactly as it found it.
 */
class RedirectValidationTest extends TestCase
{
    private const SOURCE_PREFIX = '/zz-redirect-validation';
    private const PAGE_KEY = 'zz-redirect-validation-page';

    private RedirectRepository $redirects;
    private PageRepository $pages;
    private RedirectValidator $validator;

    protected function setUp(): void
    {
        $this->redirects = new RedirectRepository();
        $this->pages = new PageRepository();
        $this->validator = new RedirectValidator($this->redirects, $this->pages);

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        // Only ever the rows this test made. LEFT(...) = :prefix rather than
        // LIKE: in a LIKE pattern "_" matches any character, so a pattern
        // built from a path is a wider net than it looks, and this table is
        // where an editor's own redirects live.
        $stmt = $db->prepare('DELETE FROM redirects WHERE LEFT(source_path, :length) = :prefix');
        $stmt->execute(['length' => strlen(self::SOURCE_PREFIX), 'prefix' => self::SOURCE_PREFIX]);

        $stmt = $db->prepare('DELETE FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::PAGE_KEY]);

        PageContent::clearCache();
    }

    private function source(string $suffix): string
    {
        return self::SOURCE_PREFIX . $suffix;
    }

    /** @return list<string> */
    private function validate(string $source, string $target, ?int $excludeId = null, int $status = 301): array
    {
        return $this->validator->validate($source, RedirectTarget::TYPE_INTERNAL, $target, $status, $excludeId);
    }

    private function store(string $source, string $target, string $origin = Redirect::ORIGIN_MANUAL): int
    {
        return $this->redirects->create([
            'source_path' => $source,
            'target_type' => RedirectTarget::TYPE_INTERNAL,
            'target_value' => $target,
            'status_code' => Redirect::STATUS_PERMANENT,
            'is_active' => true,
            'origin' => $origin,
        ]);
    }

    public function testAnOrdinaryRedirectIsAccepted(): void
    {
        $this->assertSame([], $this->validate($this->source('-old'), '/contact.php'));
    }

    public function testAnExternalDestinationIsAcceptedWhenItIsSafe(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate(
                $this->source('-old'),
                RedirectTarget::TYPE_EXTERNAL,
                'https://voorbeeld.nl/pagina',
                Redirect::STATUS_PERMANENT
            )
        );
    }

    public function testAnUnsafeExternalDestinationIsRefused(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'https://user:pass@voorbeeld.nl/'] as $unsafe) {
            $errors = $this->validator->validate(
                $this->source('-old'),
                RedirectTarget::TYPE_EXTERNAL,
                $unsafe,
                Redirect::STATUS_PERMANENT
            );

            $this->assertNotSame([], $errors, $unsafe . ' must be refused');
        }
    }

    public function testOnlyThisApplicationsStatusCodesAreAccepted(): void
    {
        $this->assertSame([], $this->validate($this->source('-old'), '/contact.php', null, 302));

        foreach ([0, 200, 307, 308, 404] as $refused) {
            $this->assertNotSame(
                [],
                $this->validate($this->source('-old'), '/contact.php', null, $refused),
                $refused . ' must be refused'
            );
        }
    }

    public function testTheHomepageCannotBeRedirectedAway(): void
    {
        $this->assertNotSame([], $this->validate('/', '/contact.php'));
    }

    /**
     * The reserved-route list is the single source of truth for which URLs the
     * application owns, and it deliberately keeps naming a module's routes
     * even while that module is off (MODULES.md) — so a redirect can never
     * take over /shop, /cart, /admin or /api either way.
     */
    public function testASourceThatIsAReservedRouteIsRefused(): void
    {
        foreach (['/shop', '/shop.php', '/cart.php', '/admin', '/admin/login.php', '/api/checkout.php', '/assets/css/core.css', '/collecties/iets', '/portfolio/iets', '/index.php'] as $reserved) {
            $this->assertNotSame(
                [],
                $this->validate($reserved, '/contact.php'),
                $reserved . ' belongs to the application and cannot be a redirect source'
            );
        }
    }

    public function testASourceThatIsAFileOnDiskIsRefused(): void
    {
        // A real file at the project root, and not one of the .php templates
        // ReservedRoutes already names — otherwise this would be proving the
        // test above again. The root used to hold favicon.svg for this; it
        // was one of the legacy site-content files the fresh-install cleanup
        // removed, so this asks about composer.json instead.
        $this->assertNotSame([], $this->validate('/composer.json', '/contact.php'));
    }

    public function testASourceThatIsAPublishedPageIsRefused(): void
    {
        \Tests\Support\PageFixture::create([
            'content_key' => self::PAGE_KEY,
            'slug' => self::PAGE_KEY,
            'status' => PageContent::STATUS_PUBLISHED,
        ], 'Redirect-validatie testpagina');

        $this->assertNotSame([], $this->validate('/' . self::PAGE_KEY, '/contact.php'));
    }

    public function testOneUrlCannotHaveTwoRedirects(): void
    {
        $id = $this->store($this->source('-old'), '/contact.php');

        $this->assertNotSame(
            [],
            $this->validate($this->source('-old'), '/diensten.php'),
            'a second row for the same source would make the destination ambiguous'
        );

        $this->assertSame(
            [],
            $this->validate($this->source('-old'), '/diensten.php', $id),
            'editing a row must not report it as a duplicate of itself'
        );
    }

    /** /foo, /foo/ and /foo// are one URL, so they are one row. */
    public function testTheDuplicateCheckSeesThroughTrailingSlashes(): void
    {
        $this->store($this->source('-old'), '/contact.php');

        $this->assertNotSame([], $this->validate($this->source('-old') . '/', '/diensten.php'));
        $this->assertNotSame([], $this->validate($this->source('-old') . '//', '/diensten.php'));
    }

    public function testASelfLoopIsRefused(): void
    {
        $errors = $this->validate($this->source('-a'), $this->source('-a'));

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('zichzelf', $errors[0]);
    }

    public function testASelfLoopThroughATrailingSlashIsRefused(): void
    {
        $this->assertNotSame([], $this->validate($this->source('-a'), $this->source('-a') . '/'));
    }

    public function testATwoWayLoopIsRefused(): void
    {
        $this->store($this->source('-b'), $this->source('-a'));

        $this->assertNotSame(
            [],
            $this->validate($this->source('-a'), $this->source('-b')),
            '/a to /b while /b already goes to /a is a loop'
        );
    }

    public function testALongerLoopIsRefused(): void
    {
        $this->store($this->source('-b'), $this->source('-c'));
        $this->store($this->source('-c'), $this->source('-a'));

        $this->assertNotSame([], $this->validate($this->source('-a'), $this->source('-b')));
    }

    public function testAnHonestChainIsAccepted(): void
    {
        $this->store($this->source('-b'), '/contact.php');

        $this->assertSame(
            [],
            $this->validate($this->source('-a'), $this->source('-b')),
            'a chain that ends at a real page is a rename history, not a loop'
        );
    }

    public function testAChainLongerThanTheResolverWillFollowIsRefused(): void
    {
        $previous = '/contact.php';
        for ($step = Redirect::MAX_CHAIN_DEPTH + 2; $step >= 1; $step--) {
            $current = $this->source('-chain-' . $step);
            $this->store($current, $previous);
            $previous = $current;
        }

        $errors = $this->validate($this->source('-head'), $previous);

        $this->assertNotSame([], $errors, 'a chain the resolver would refuse to follow must not be saveable');
    }
}
