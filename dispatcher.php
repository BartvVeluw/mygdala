<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Redirects\RedirectGate;
use App\Service\Routing\LanguagePreference;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\LanguageSwitch;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\RequestPath;
use App\Service\Routing\RouteResolver;

/**
 * The one door every public URL that is not a file on disk comes through
 * (docs/multilingual/ROUTING.md).
 *
 * WHY IT EXISTS. A language prefix makes every content URL at least two
 * segments long, and `.htaccess`'s catch-all matched exactly one; the seven
 * narrow rewrites above it would each have needed a copy per language, in a
 * static file, for a list of languages that lives in a database table. So
 * `.htaccess` keeps doing the one thing it is good at — "is this a real file
 * or a blocked path? then Apache handles it" — and everything else arrives
 * here.
 *
 * WHAT IT IS NOT, and this is deliberate (see the fase-0 assessment): not a
 * front controller. It renders nothing, knows nothing about pages, posts or
 * products, instantiates no controller, and owns no view layer. The twenty-odd
 * root-level templates stay exactly what they were — complete documents that
 * render themselves — and this file only decides WHICH one runs, in WHICH
 * language, and hands it the parameters the old `RewriteRule`s used to append
 * with [QSA].
 *
 * THE FIVE STEPS, in this order and for a reason:
 *
 *   1. take the URI apart safely (App\Service\Routing\RequestPath). A path
 *      that cannot be a route — traversal, a control character, a backslash —
 *      is a 404 and never reaches a lookup.
 *   2. peel off a language segment when the first one names an ACTIVE website
 *      language, and pin App\Service\Routing\RequestLanguage.
 *   3. normalize: exactly one canonical spelling per route, reached with at
 *      most one permanent redirect. The default language's prefix is removed,
 *      a stray trailing slash is removed, a language home gains one.
 *   4. resolve the route against the closed table
 *      (App\Service\Routing\RouteResolver) and require its template.
 *   5. no route? Then, and only then, ask the Redirect Manager
 *      (App\Service\Redirects\RedirectGate) whether this URL was moved, and
 *      otherwise render the site's own 404 — in the request's language.
 *
 * STEP 5's ORDER IS THE INVARIANT this project already had: a redirect may
 * only ever speak once content resolution has failed, so a stored redirect
 * can never shadow a URL that works. It used to be guaranteed by the two
 * places that called the gate; it is now guaranteed by there being one place.
 *
 * ONLY GET AND HEAD ARE REDIRECTED. A POST is answered where it was sent:
 * bouncing one through a Location header loses its body, and locale
 * negotiation must never decide where somebody's form data ends up.
 */

$dispatcherMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$dispatcherIsRetrieval = $dispatcherMethod === 'GET' || $dispatcherMethod === 'HEAD';

/**
 * Send one redirect and stop. Site-relative targets only: every value handed
 * to this comes from App\Service\Routing\LocalizedUrl or from the route
 * table, never from the request, so there is no shape of URL a visitor could
 * talk this into emitting.
 */
$dispatcherRedirect = static function (string $location, int $status): void {
    if (headers_sent()) {
        error_log('[dispatcher] headers already sent, cannot redirect to ' . $location);

        return;
    }

    header('Location: ' . $location, true, $status);
    exit;
};

/** The site's ordinary 404 document, in whatever language was resolved. */
$dispatcherNotFound = static function () use ($dispatcherMethod): void {
    http_response_code(404);

    if ($dispatcherMethod !== 'GET' && $dispatcherMethod !== 'HEAD') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "404 Not Found\n";
        exit;
    }

    require __DIR__ . '/partials/route-not-found-page.php';
    exit;
};

/* 1 — the request path ---------------------------------------------------- */

$dispatcherPath = RequestPath::fromRequestUri((string) ($_SERVER['REQUEST_URI'] ?? '/'));

if ($dispatcherPath === null) {
    $dispatcherNotFound();
}

/**
 * A technical namespace never becomes a public route. `.htaccess` already
 * keeps these away from this file; the same list stands here because a
 * router that could be talked into building the whole site shell for a stray
 * /assets/... request must be impossible on its own terms, not only because
 * of a rewrite rule. Same four prefixes, same terse answer, as 404.php.
 */
foreach (['api', 'admin', 'assets', 'vendor'] as $dispatcherMachinePrefix) {
    if ($dispatcherPath->firstSegment() === $dispatcherMachinePrefix) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "404 Not Found\n";
        exit;
    }
}

/* 2 — the language segment ------------------------------------------------ */

$dispatcherUrlLanguage = null;
$dispatcherRest = $dispatcherPath;

$dispatcherFirst = $dispatcherPath->firstSegment();
if (
    $dispatcherFirst !== null
    && LanguageCode::isValid($dispatcherFirst)
    && SiteLanguages::isActive($dispatcherFirst)
) {
    $dispatcherUrlLanguage = $dispatcherFirst;
    $dispatcherRest = $dispatcherPath->withoutFirstSegment();
}

$dispatcherLanguage = LanguageResolver::forRequest($dispatcherUrlLanguage);
RequestLanguage::set($dispatcherLanguage, $dispatcherUrlLanguage !== null);

/**
 * A registered language that is not published (switched off, or any language
 * but the default while Meertaligheid is off) keeps its prefix reserved: no
 * page can live there (App\Service\Routing\ReservedPaths). Its addresses
 * answer 404 at once, never with the trailing-slash redirect below. That
 * redirect is permanent, so a browser caches /de/ -> /de; once the language
 * is published again /de redirects back to /de/ (a language home keeps its
 * slash), and every visitor who saw the first answer is stuck in a loop.
 */
if (
    $dispatcherUrlLanguage === null
    && $dispatcherFirst !== null
    && LanguageCode::isValid($dispatcherFirst)
    && SiteLanguages::exists($dispatcherFirst)
) {
    $dispatcherNotFound();
}

/* 3 — one canonical spelling ---------------------------------------------- */

$dispatcherRequested = $dispatcherPath->path();
if ($dispatcherPath->hadTrailingSlash && $dispatcherRequested !== '/') {
    $dispatcherRequested .= '/';
}

// LocalizedUrl decides what the canonical form of this route is: no prefix
// for the default language, "/xx/" for a language home, no trailing slash
// anywhere else. One comparison therefore covers the default-language prefix,
// the stray trailing slash and the missing one, and produces a single hop.
$dispatcherCanonical = LocalizedUrl::path($dispatcherRest->path(), $dispatcherLanguage);

if ($dispatcherIsRetrieval && $dispatcherCanonical !== $dispatcherRequested) {
    $dispatcherRedirect($dispatcherPath->withQuery($dispatcherCanonical), 301);
}

/**
 * THE ONE PLACE A VISITOR IS MOVED BY ANYTHING OTHER THAN THE URL: the site
 * root, and only when it carries no prefix.
 *
 * Everywhere else an unprefixed URL *is* the default language's URL. If a
 * cookie or an Accept-Language header could move a visitor away from
 * /over-ons, then every canonical URL of this site would answer differently
 * per visitor, which is precisely what a canonical URL may not do. At `/`
 * there is no such promise to keep — nobody has said which language they want
 * yet — so that is where the question is asked, once, with a TEMPORARY
 * redirect (no permanent signal may be attached to a per-visitor decision)
 * and a Vary header naming exactly what the answer depended on.
 */
if ($dispatcherIsRetrieval && $dispatcherUrlLanguage === null && $dispatcherRest->isRoot()) {
    header('Vary: Accept-Language, Cookie', false);

    /**
     * AN EXPLICIT CHOICE BEATS A REMEMBERED ONE. The language switch's link
     * to the default language's home carries the choice in a parameter
     * (App\Service\Routing\LanguageSwitch::CHOICE_PARAMETER), because "/" is
     * the one URL that cannot say which language was asked for — without it a
     * visitor whose cookie says English could never reach the Dutch homepage
     * through the switch at all.
     *
     * Only an ACTIVE website language counts; anything else is ignored and
     * the request is negotiated as if the parameter were not there. The
     * answer is the chosen language's CLEAN home, built by LocalizedUrl, so
     * the parameter never reaches a page and nothing a visitor types can
     * name another destination.
     */
    $dispatcherChosen = LanguageCode::normalise((string) ($_GET[LanguageSwitch::CHOICE_PARAMETER] ?? ''));

    if ($dispatcherChosen !== null && SiteLanguages::isActive($dispatcherChosen)) {
        LanguagePreference::remember($dispatcherChosen);
        $dispatcherRedirect(LocalizedUrl::home($dispatcherChosen), 302);
    }

    $dispatcherNegotiated = LanguageResolver::negotiate();

    if (!LanguageResolver::isDefault($dispatcherNegotiated)) {
        $dispatcherRedirect(
            $dispatcherPath->withQuery(LocalizedUrl::home($dispatcherNegotiated)),
            302
        );
    }
}

/* 4 — the route ----------------------------------------------------------- */

$dispatcherMatch = RouteResolver::resolve($dispatcherRest->segments, $dispatcherLanguage);

if ($dispatcherMatch === null) {
    /* 5 — moved, or genuinely gone ---------------------------------------- */
    RedirectGate::handleOr404();

    $dispatcherNotFound();
}

// A fixed segment spelled in another language's words still resolves, and is
// then sent to its canonical form once: /en/blog/categorie/x keeps working
// and lands on /en/blog/category/x.
if ($dispatcherIsRetrieval && $dispatcherMatch->needsCanonicalRedirect()) {
    $dispatcherRedirect(
        $dispatcherPath->withQuery(
            LocalizedUrl::path((string) $dispatcherMatch->canonicalPath, $dispatcherLanguage)
        ),
        301
    );
}

/**
 * The template name is a literal out of App\Service\Routing\RouteTable — it is
 * written in this project's own code and can never come from a request. The
 * check below is therefore not input validation but a guard against a typo in
 * that table turning into a path expression: anything that is not a plain
 * root-level .php name is refused rather than required.
 */
if (preg_match('/\A[a-z0-9-]+\.php\z/', $dispatcherMatch->template) !== 1) {
    error_log('[dispatcher] route "' . $dispatcherMatch->key . '" names an impossible template');
    http_response_code(500);
    exit;
}

$dispatcherTemplate = __DIR__ . '/' . $dispatcherMatch->template;

if (!is_file($dispatcherTemplate)) {
    error_log('[dispatcher] route "' . $dispatcherMatch->key . '" points at a missing template');
    http_response_code(500);
    exit;
}

// What Apache's [QSA] used to do: the captured segments become query
// parameters, next to whatever the visitor's own query string already held.
// The templates read them exactly as before, so not one of them had to learn
// that a router exists.
foreach ($dispatcherMatch->query as $dispatcherParameter => $dispatcherValue) {
    $_GET[$dispatcherParameter] = $dispatcherValue;
    $_REQUEST[$dispatcherParameter] = $dispatcherValue;
}

// The language this visitor is actually reading, remembered for the next time
// they arrive at `/` without saying. Written before the first byte of the
// template's output, because a cookie cannot be set afterwards.
if ($dispatcherIsRetrieval) {
    LanguagePreference::remember($dispatcherLanguage);
}

require $dispatcherTemplate;
