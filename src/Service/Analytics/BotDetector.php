<?php

namespace App\Service\Analytics;

/**
 * Decides whether a request is a crawler, a monitor or a script rather than a
 * person, so it is never counted as a pageview.
 *
 * A user-agent blocklist is not a security control and is not trying to be
 * one: a bot that wants to be counted can simply claim to be Chrome. It is a
 * data-quality filter, and the bar it has to clear is "the owner's dashboard
 * is not dominated by Googlebot, uptime checks and the project's own test
 * suite". Everything below identifies itself honestly, which is exactly why
 * matching on the name works.
 *
 * Three deliberate design points:
 *
 *  - An EMPTY user agent counts as a bot. Real browsers always send one; what
 *    does not is curl without flags, PHP's own stream wrapper (which is how
 *    this project's HTTP tests hit public pages — a suite run must not show up
 *    in the statistics), and most scanners.
 *
 *  - Most signatures are matched as plain lowercase substrings against one
 *    lowercased haystack rather than as a regular expression each. This runs
 *    on every public pageview, so it stays a single str_contains loop over a
 *    short list instead of dozens of preg_match calls.
 *
 *  - "bot" is the one exception, matched as 'bot' followed by a non-word
 *    character or end of string. A bare substring would also match the phone
 *    brand Cubot, which appears in ordinary Android user agents
 *    ("...; CUBOT_NOTE_7 Build/..."), and silently drop those visitors. The
 *    boundary form still catches googlebot/2.1, bingbot, AhrefsBot and the
 *    long tail that ends in "bot". Known and accepted residual: a device
 *    string that writes the brand with a space ("CUBOT NOTE 20") is still
 *    read as a bot — one lost visitor beats a home-grown device database.
 *
 * No IP-based or reverse-DNS verification, and no external bot database:
 * both would mean a network round trip inside a page render, for a number
 * that is allowed to be approximate.
 */
final class BotDetector
{
    /** 'bot' as a whole trailing word — see the class docblock. */
    private const BOT_WORD_PATTERN = '/bot\b/';

    /**
     * Lowercase substrings that mark a user agent as non-human. Grouped by
     * what they are, so the list stays reviewable as it grows.
     */
    private const SIGNATURES = [
        // Generic self-identification — covers the long tail of crawlers
        // that never get their own entry here.
        'crawl', 'spider', 'scraper', 'slurp', 'archiver',

        // Search, social and AI crawlers whose name contains none of the
        // words above and does not end in "bot" (anything that does —
        // Googlebot, bingbot, AhrefsBot, PetalBot, GPTBot, ClaudeBot,
        // UptimeRobot — needs no entry, BOT_WORD_PATTERN has it).
        'facebookexternalhit', 'ia_archiver', 'yandex', 'baiduspider',
        'pinterest', 'embedly', 'quora link preview', 'skypeuripreview',
        'vkshare', 'bytespider', 'anthropic-ai', 'claude-web', 'perplexity',

        // Availability monitors and performance tooling.
        'pingdom', 'statuscake', 'site24x7', 'newrelic',
        'lighthouse', 'pagespeed', 'gtmetrix', 'headless', 'phantomjs',
        'puppeteer', 'playwright', 'selenium',

        // Scripts, libraries and API clients.
        'curl/', 'wget', 'libwww-perl', 'python-requests', 'python-urllib',
        'httpclient', 'okhttp', 'axios', 'node-fetch', 'go-http-client',
        'java/', 'guzzle', 'postmanruntime', 'insomnia', 'phpunit',
        'scrapy', 'zgrab', 'masscan', 'nmap',

        // Feed readers: a real subscriber, but not a page a person looked at.
        'feedfetcher', 'feedburner', 'newsblur', 'feedly',
    ];

    public static function isBot(string $userAgent): bool
    {
        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return true;
        }

        $haystack = strtolower($userAgent);

        if (preg_match(self::BOT_WORD_PATTERN, $haystack) === 1) {
            return true;
        }

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($haystack, $signature)) {
                return true;
            }
        }

        return false;
    }
}
