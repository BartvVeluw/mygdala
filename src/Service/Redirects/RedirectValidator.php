<?php

declare(strict_types=1);

namespace App\Service\Redirects;

use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Service\ReservedRoutes;

/**
 * Every rule that decides whether a redirect may be SAVED. The admin endpoints
 * (api/admin/create-redirect.php, update-redirect.php) call nothing else, so
 * there is one place these rules live and one set of messages an editor reads.
 *
 * Two families of rule, and they exist for different failure modes:
 *
 * CONFLICT — a redirect must never sit on a URL this site currently serves.
 * At runtime it could not shadow one anyway (the redirect table is consulted
 * only where a request was about to become a 404, see RedirectGate), so this
 * is not a security boundary; it is the difference between an editor being
 * told "that URL already works" at save time and them discovering months later
 * that a row they wrote has silently never fired.
 *
 * The check reuses App\Service\ReservedRoutes rather than growing a second
 * hardcoded list of URLs the application owns. That list is already the single
 * source of truth for "which bare word may a CMS page not claim", it already
 * includes every root-level PHP file, every top-level directory and every
 * namespace a module owns — /shop.php, /product.php, /collecties/..., /admin/,
 * /api/, /assets/ — and, by MODULES.md's deliberate design, it keeps naming a
 * module's routes even while that module is SWITCHED OFF. A redirect source
 * inherits that policy for free: turning the Shop off does not open /shop.php
 * or /collecties/oude-collectie up as somewhere to hang a redirect, because
 * shop.php and collectie.php are still on disk and would still shadow it.
 *
 * Everything the reserved list cannot know — a published page's slug, a file
 * that is physically present at the project root — is checked against the
 * thing itself.
 *
 * LOOP — /a to /a is refused outright, and so is any chain that comes back to
 * where it started within App\Service\Redirects\Redirect::MAX_CHAIN_DEPTH
 * hops. That is a bounded walk over the rows that already exist plus the one
 * being saved, not a graph engine: a chain in this table is at most a page
 * renamed a few times, and the runtime resolver refuses to follow further than
 * the same limit regardless.
 */
final class RedirectValidator
{
    private RedirectRepository $redirects;
    private PageRepository $pages;

    public function __construct(?RedirectRepository $redirects = null, ?PageRepository $pages = null)
    {
        $this->redirects = $redirects ?? new RedirectRepository();
        $this->pages = $pages ?? new PageRepository();
    }

    /**
     * Validates one complete submission. Returns the Dutch messages to show
     * the editor, empty when the redirect may be saved.
     *
     * $excludeId is the row being edited, so a redirect is never reported as
     * a duplicate of itself.
     *
     * @return list<string>
     */
    public function validate(
        string $rawSource,
        string $targetType,
        string $rawTarget,
        int $statusCode,
        ?int $excludeId = null
    ): array {
        $errors = [];

        $source = RedirectPath::normalize($rawSource);

        if ($source === null) {
            $errors[] = 'Vanaf-pad is ongeldig. Gebruik een pad op deze site, bijvoorbeeld /oude-pagina.';
        } else {
            foreach ($this->sourceErrors($source, $excludeId) as $error) {
                $errors[] = $error;
            }
        }

        if (!RedirectTarget::isValidType($targetType)) {
            $errors[] = 'Ongeldig soort bestemming.';
            $target = null;
        } else {
            $target = RedirectTarget::normalize($targetType, $rawTarget);
            if ($target === null) {
                $errors[] = $targetType === RedirectTarget::TYPE_EXTERNAL
                    ? 'Externe bestemming is ongeldig. Gebruik een volledige URL die met https:// begint, zonder inloggegevens erin.'
                    : 'Bestemming is ongeldig. Gebruik een pad op deze site dat met / begint, bijvoorbeeld /nieuwe-pagina.';
            }
        }

        if (!Redirect::isValidStatusCode($statusCode)) {
            $errors[] = 'Ongeldige statuscode. Kies 301 (permanent) of 302 (tijdelijk).';
        }

        if ($source !== null && $target !== null && $errors === []) {
            foreach ($this->loopErrors($source, $targetType, $target, $excludeId) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Why this already-normalized source path may not be used, if it may not.
     *
     * @return list<string>
     */
    public function sourceErrors(string $source, ?int $excludeId = null): array
    {
        $errors = [];

        if ($source === '/') {
            $errors[] = 'De homepage kan niet worden doorgestuurd.';

            return $errors;
        }

        $existing = $this->redirects->findBySourcePath($source);
        if ($existing !== null && (int) $existing['id'] !== (int) $excludeId) {
            $errors[] = 'Er bestaat al een redirect voor dit pad. Bewerk die redirect in plaats van een tweede aan te maken.';
        }

        $owner = $this->routeOwner($source);
        if ($owner !== null) {
            $errors[] = $owner;
        }

        return $errors;
    }

    /**
     * A description of what already serves this path, or null when nothing
     * does. Public so the admin overview can flag a row that was valid when it
     * was written and has since been overtaken by real content.
     */
    public function routeOwner(string $source): ?string
    {
        $segments = array_values(array_filter(explode('/', $source), static fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return 'De homepage kan niet worden doorgestuurd.';
        }

        $first = $segments[0];

        // The reserved list holds bare words — "shop", "admin", "collecties" —
        // while a URL may name the file itself, /shop.php. Both mean the same
        // route, so the .php is stripped before asking, exactly as .htaccess's
        // own `<word>.php -f` check treats the two as one.
        $bareFirst = str_ends_with($first, '.php') ? substr($first, 0, -4) : $first;

        if (ReservedRoutes::isReserved($bareFirst)) {
            return 'Dit pad hoort bij een vaste route of map van de website ("' . $bareFirst
                . '") en kan geen vanaf-pad zijn.';
        }

        // A file or directory that physically exists at the project root is
        // served by Apache before any PHP runs, so a redirect on it could
        // never fire. Confined to the project root by construction: only the
        // first path segment is looked up, and it cannot contain a slash or a
        // ".." (RedirectPath rejects both).
        if ($this->existsAtProjectRoot($first)) {
            return 'Er staat al een bestand of map op deze site met de naam "' . $first . '".';
        }

        if (count($segments) === 1) {
            $page = $this->pages->findBySlugPublished($first);
            if ($page !== null) {
                return 'Er bestaat al een gepubliceerde pagina op dit pad ("' . $first . '").';
            }
        }

        return null;
    }

    /**
     * Loop detection, at save time.
     *
     * The row being saved is applied on top of what is stored, then the chain
     * is walked from its destination. Coming back to the source — immediately
     * (/a to /a), after one hop (/a to /b, /b to /a) or deeper — is refused,
     * and so is a chain that is simply too long to be a rename history.
     *
     * @return list<string>
     */
    public function loopErrors(string $source, string $targetType, string $target, ?int $excludeId = null): array
    {
        if ($targetType !== RedirectTarget::TYPE_INTERNAL) {
            // An external destination leaves this site; it cannot come back
            // through this table.
            return [];
        }

        $targetPath = RedirectPath::normalize(explode('?', $target, 2)[0]);

        if ($targetPath === null) {
            return [];
        }

        if ($targetPath === $source) {
            return ['Een redirect kan niet naar zichzelf verwijzen.'];
        }

        $seen = [$source => true];
        $current = $targetPath;

        for ($hop = 0; $hop < Redirect::MAX_CHAIN_DEPTH; $hop++) {
            if (isset($seen[$current])) {
                return ['Deze redirect maakt een kringetje: ' . $current . ' verwijst uiteindelijk weer terug.'];
            }
            $seen[$current] = true;

            $next = $this->redirects->findBySourcePath($current);

            if ($next === null || (int) $next['id'] === (int) $excludeId) {
                return [];
            }

            if ((string) $next['target_type'] !== RedirectTarget::TYPE_INTERNAL) {
                return [];
            }

            $nextPath = RedirectPath::normalize(explode('?', (string) $next['target_value'], 2)[0]);
            if ($nextPath === null) {
                return [];
            }

            if ($nextPath === $source) {
                return ['Deze redirect maakt een kringetje: ' . $current . ' verwijst weer terug naar ' . $source . '.'];
            }

            $current = $nextPath;
        }

        return [
            'Deze redirect maakt een keten van meer dan ' . Redirect::MAX_CHAIN_DEPTH
            . ' stappen. Verwijs rechtstreeks naar de uiteindelijke pagina.',
        ];
    }

    /**
     * Is there a file or directory with this name at the project root? One
     * name, never a path — the caller passes a single already-validated path
     * segment.
     */
    private function existsAtProjectRoot(string $name): bool
    {
        $root = dirname(__DIR__, 3);
        $candidate = $root . '/' . $name;

        return file_exists($candidate);
    }
}
