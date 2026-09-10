<?php

declare(strict_types=1);

namespace App\Service\Redirects;

/**
 * The one line a route runs at the exact moment it has decided it cannot serve
 * a request: "before this becomes a 404, is this URL one we moved?"
 *
 * WHY IT IS SHAPED LIKE THIS, and not as middleware. This application has no
 * front controller — see PROJECT-MAP.md and .htaccess. A public request either
 * hits a real PHP file at the project root, or matches one of three narrow
 * rewrites, or is refused by Apache itself. There is no single point every
 * request passes through, and inventing one would mean rewriting the routing
 * of a live site to add a feature that only ever acts on URLs that already
 * fail. So the gate is called from the two places a request can still be
 * rescued, and nowhere else:
 *
 *   1. 404.php — Apache's ErrorDocument. Everything Apache itself could not
 *      resolve reaches PHP here and nowhere else: /oud_pad (an underscore, so
 *      the CMS rewrite's [a-z0-9-] pattern never matched it), /oude/pagina
 *      (two segments), /legacy.html, /Oude-Pagina.
 *   2. pagina.php — the generic CMS page template, at the point where the slug
 *      resolved to no published page. Apache DID route this request, so its
 *      ErrorDocument never fires; PHP is answering 404 itself, and this is the
 *      only place that 404 can be intercepted.
 *
 * Both call sites are past the point of no return for normal content: the page
 * lookup has already happened and already failed. That is what guarantees a
 * redirect can never shadow a live URL, independently of whether the
 * save-time conflict rules (RedirectValidator) ever miss a case.
 *
 * WHAT IT SENDS. A Location header, the stored status code, and no body at
 * all — a redirect response has no page to render, so it also has no canonical
 * tag, no Open Graph block and no sitemap presence to get wrong. The redirect
 * IS the SEO signal; see SEO.md.
 */
final class RedirectGate
{
    /**
     * Redirects and exits when this request's path has a usable redirect;
     * returns normally when it does not, leaving the caller to render its 404.
     *
     * Never fatals: a lookup this could not perform is a request that 404s,
     * which is what was about to happen anyway.
     */
    public static function handleOr404(?RedirectResolver $resolver = null): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if ($requestUri === '') {
            return;
        }

        // A redirect answers a retrieval. HEAD gets one too (it is a GET
        // without the body, and a crawler checking a moved URL uses it), but
        // a POST to a path that does not exist is not a visitor following a
        // stale link and must not be bounced somewhere it might be replayed.
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        try {
            $redirect = ($resolver ?? new RedirectResolver())->resolveRequestUri($requestUri);
        } catch (\Throwable $e) {
            error_log('[RedirectGate] ' . $e->getMessage());

            return;
        }

        if ($redirect === null) {
            return;
        }

        if (headers_sent()) {
            // Nothing can be salvaged once the body has started; say so rather
            // than emitting a Location header the client will ignore.
            error_log('[RedirectGate] headers already sent, cannot redirect ' . $requestUri);

            return;
        }

        header('Location: ' . $redirect['location'], true, $redirect['status']);
        exit;
    }
}
