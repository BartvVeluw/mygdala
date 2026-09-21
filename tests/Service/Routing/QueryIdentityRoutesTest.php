<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\RouteTable;
use PHPUnit\Framework\TestCase;

/**
 * WHICH PUBLIC ROUTES NAME THEIR RESOURCE IN THE QUERY STRING: a closed list,
 * kept complete against the route table (docs/multilingual/ROUTING.md, §9).
 *
 * The language switch offers a route's own path under each language's prefix
 * and never copies the query string (App\Service\Routing\LanguageAlternates).
 * That is right for a route whose identity is in its path, or that has none,
 * and wrong for a route whose resource is named in the query: undeclared, its
 * switch leads to the bare route, without the resource. It was missed three
 * times (product.php, bestelling-status.php, herroeping.php), each time
 * because nothing asked the question when the route was written.
 *
 * So every template the route table serves is classified here, as one or the
 * other, and a new route fails this test until somebody decides. A route in
 * QUERY_IDENTITY must declare its versions, and
 * Tests\Service\QueryIdentityLanguageSwitchTest proves over HTTP that each
 * one keeps its resource in every language, reads it exactly as the page
 * does, and carries nothing else.
 *
 * VIEW STATE IS NOT IDENTITY, and stays behind on purpose: the Blog index's
 * ?pagina=N (the switch opens page 1, ROUTING.md §15), a form's PRG status, a
 * cart line being edited (?line=, a handle into this browser's own storage),
 * tracking.
 *
 * No database and no web server: the route table is built from code alone.
 */
final class QueryIdentityRoutesTest extends TestCase
{
    /**
     * template => the query parameter that names the resource it shows.
     *
     * @var array<string, string>
     */
    public const QUERY_IDENTITY = [
        'product.php' => 'id',
        'bestelling-status.php' => 'order',
        'herroeping.php' => 'order',
    ];

    /**
     * template => why its switch needs nothing from the query string.
     *
     * @var array<string, string>
     */
    private const NO_QUERY_IDENTITY = [
        'index.php' => 'one page',
        'contact.php' => 'one page; ?form-status and ?form are a form\'s PRG state',
        'diensten.php' => 'one page',
        'over-mij.php' => 'one page',
        'cookiebeleid.php' => 'one page',
        'shop.php' => 'one page',
        'cart.php' => 'the cart lives in the browser',
        'checkout.php' => 'the cart lives in the browser',
        'personaliseren.php' => 'one page',
        'portfolio.php' => 'one page',
        'blog-feed.php' => 'a feed: no language switch',
        'blog.php' => 'the path names the category or tag, and blog.php declares it; ?pagina=N is view state',
        'blog-post.php' => 'the path names the post, and blog-post.php declares it',
        'collectie.php' => 'the path names the collection, and collectie.php declares it',
        'pagina.php' => 'the path names the page, and partials/page-head.php declares it',
        'portfolio-detail.php' => 'the path names the project, and /portfolio/<slug> is the same in every language',
    ];

    public function testEveryRoutedTemplateIsClassified(): void
    {
        $unclassified = [];

        foreach (RouteTable::all() as $route) {
            $template = $route['template'];

            if (!isset(self::QUERY_IDENTITY[$template]) && !isset(self::NO_QUERY_IDENTITY[$template])) {
                $unclassified[$template] = $route['key'];
            }
        }

        self::assertSame(
            [],
            $unclassified,
            'a public route nobody has classified: does its query string name the resource it shows? '
                . 'If it does, add it to QUERY_IDENTITY, declare its versions like bestelling-status.php '
                . 'does and give it a witness in Tests\Service\QueryIdentityLanguageSwitchTest; '
                . 'if it does not, add it to NO_QUERY_IDENTITY with the reason'
        );
    }

    public function testEveryClassifiedTemplateExistsAndIsClassifiedOnce(): void
    {
        self::assertSame([], array_keys(array_intersect_key(self::QUERY_IDENTITY, self::NO_QUERY_IDENTITY)));

        foreach (array_keys(self::QUERY_IDENTITY + self::NO_QUERY_IDENTITY) as $template) {
            self::assertFileExists(self::root() . '/' . $template);
        }
    }

    /**
     * The one thing that makes the switch carry a query identity at all: the
     * assumed paths never do. Proven in behaviour by the HTTP test; asserted
     * here too because this tier always runs.
     */
    public function testEveryQueryIdentityRouteDeclaresItsVersions(): void
    {
        foreach (self::QUERY_IDENTITY as $template => $parameter) {
            self::assertStringContainsString(
                'LanguageAlternates::declareVersions(',
                (string) file_get_contents(self::root() . '/' . $template),
                $template . ' names its resource in ?' . $parameter . '= and must declare its language versions'
            );
        }
    }

    /**
     * bestelling-status.php reads the order for its switch the way
     * assets/js/shop/shop.js reads it for the page, in PHP, because the switch
     * may be neither stricter nor looser than what the customer sees. That
     * copy is only right while the script still reads the order this way:
     * change one and this points at the other.
     */
    public function testTheOrderStatusSwitchStillMirrorsTheScriptThatShowsTheOrder(): void
    {
        $script = (string) file_get_contents(self::root() . '/assets/js/shop/shop.js');

        foreach (['var idParam = params.get("order");', 'String(orderId) !== idParam.trim()'] as $line) {
            self::assertStringContainsString(
                $line,
                $script,
                'shop.js changed how it reads the order; bestelling-status.php reads it the same way for the language switch'
            );
        }
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
