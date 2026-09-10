<?php

namespace App\Service;

/**
 * How this application names itself to a server it calls out to.
 *
 * Two features fetch something from a third party — the Dutch address lookup
 * (PDOK) and the PostNL rate sync — and both are expected by convention to
 * send a User-Agent that says who is calling and how to reach them. Both used
 * to send a string with Van Veluw Laserdesign's name and domain written into
 * it, which is one company's identity compiled into a codebase installed more
 * than once: every copy of this CMS would have introduced itself to PDOK and
 * to PostNL as that shop.
 *
 * WHAT IS STABLE AND WHAT IS NOT. The product token is the application, not
 * the site: it is the same on every installation, so a service operator
 * looking at their logs sees one recognisable client rather than a new name
 * per deployment. The contact is the INSTALLATION's own public base URL
 * (App\Service\AppUrl), because that is what a service operator would
 * actually need if this client misbehaved, and it is already public — it is
 * the domain the site serves from. It is deliberately built from the
 * configured base URL and nothing else: no operator e-mail address, no
 * company name, nothing an owner did not already publish, and never the
 * request's Host header.
 *
 * An installation that has not configured a base URL yet sends the product
 * token alone. AppUrl's last-resort placeholder is https://localhost, and a
 * contact address nobody can reach is worse than none.
 */
final class HttpUserAgent
{
    /**
     * The product token, and it names the application rather than any site
     * running it. Bumped only when the outbound behaviour changes in a way a
     * remote operator could care about.
     */
    private const PRODUCT = 'CMS/1.0';

    /**
     * @param string $purpose short, plain-English reason for the call, e.g.
     *                        'NL address verification'. Shown to whoever
     *                        reads the remote server's logs, so it should say
     *                        what this client is doing, not which feature
     *                        inside the CMS is doing it.
     */
    public static function forPurpose(string $purpose): string
    {
        $details = array_filter([self::contact(), trim($purpose)]);

        if ($details === []) {
            return self::PRODUCT;
        }

        return self::PRODUCT . ' (' . implode('; ', $details) . ')';
    }

    /**
     * The installation's own public base URL as a contact, in the customary
     * `+https://...` form — or an empty string when nobody has configured
     * one, in which case the caller simply leaves the contact out.
     */
    private static function contact(): string
    {
        if (!AppUrl::isConfigured()) {
            return '';
        }

        return '+' . AppUrl::base();
    }
}
