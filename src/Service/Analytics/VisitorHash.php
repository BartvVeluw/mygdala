<?php

namespace App\Service\Analytics;

/**
 * Turns "who is this" into a value that can be counted but not looked up.
 *
 * A visitor hash is sha256(daily salt + IP address + user agent). The IP and
 * the user agent exist only as arguments to this one function call: nothing
 * writes them anywhere, and the caller (PageViewTracker) discards them the
 * moment it has the hash. The salt is a random 32-byte value generated once
 * per calendar day and stored in `analytics_visitor_salts`.
 *
 * Why a salt at all, and why a rotating one:
 *
 *  - Without a secret, sha256(IP + user agent) is trivially reversible — an
 *    IPv4 address plus a handful of common user agents is a search space a
 *    laptop exhausts in seconds. The hash would then be a stored IP address
 *    wearing a hat.
 *  - Because the salt changes at midnight, the same person visiting on two
 *    days produces two hashes with no computable relationship. There is no
 *    identifier in this system that survives a day, which is what keeps the
 *    data out of "tracking" territory — and equally why counts over a period
 *    longer than a day are approximate (see AnalyticsDashboard).
 *  - Deleting a day's salt (scripts/prune-analytics.php) makes that day's
 *    hashes permanently uncheckable against any IP address, by anyone,
 *    including us.
 *
 * hash_equals-style constant-time behaviour is irrelevant here: nothing is
 * compared against a secret, the hash is only ever grouped and counted.
 */
final class VisitorHash
{
    /**
     * @param string $salt      the day's salt, from
     *                          App\Repository\PageViewRepository::visitorSaltForDate()
     * @param string $ipAddress used, never stored
     * @param string $userAgent used, never stored
     */
    public static function compute(string $salt, string $ipAddress, string $userAgent): string
    {
        // The separator matters: without it the pair ("1.2.3", "45...") and
        // ("1.2.3.4", "5...") would hash identically and silently merge two
        // visitors into one.
        return hash('sha256', $salt . '|' . $ipAddress . '|' . $userAgent);
    }

    /**
     * The client address for the current request.
     *
     * REMOTE_ADDR only, deliberately: X-Forwarded-For is attacker-controlled
     * on a host that does not strip it, so trusting it would let anyone
     * inflate the visitor count by sending a new fake address per request.
     * On Vimexx shared hosting the site is reached directly, so REMOTE_ADDR
     * is the real address; behind a future CDN this would need the proxy's
     * own trusted header instead, and the only consequence of not changing it
     * would be every visitor collapsing into one hash — visibly wrong rather
     * than quietly wrong.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function clientIp(array $server): string
    {
        return trim((string) ($server['REMOTE_ADDR'] ?? ''));
    }
}
