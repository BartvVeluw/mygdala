<?php

namespace App\Mail;

use App\Service\SiteSettings;

/**
 * Who a transactional e-mail is from, and the small identity line at the
 * foot of every one of them.
 *
 * All three builders — contact request, order confirmation, withdrawal
 * request — used to end with the same hardcoded line:
 *
 *     <company name> · <city> · <e-mail address>
 *
 * with one company's actual values typed into it.
 *
 * Three copies of one company's details, in a codebase meant to be installed
 * more than once. They now come from App\Service\SiteSettings, which is
 * where an owner already edits them, and a part that is not filled in is
 * simply left out rather than leaving a stray separator behind.
 *
 * The FROM name keeps its existing precedence: MAIL_FROM_NAME in .env wins,
 * because that is deploy configuration and may have to match what the mail
 * provider is allowed to send as. The site name is the fallback it lands on
 * when nothing is configured.
 */
final class EmailIdentity
{
    /** The site's own name — the sender name and the footer's first part. */
    public static function name(): string
    {
        $configured = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? ''));

        return $configured !== '' ? $configured : SiteSettings::get('site_name');
    }

    /** The address transactional mail is sent from. */
    public static function fromAddress(): string
    {
        $configured = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? ''));

        return $configured !== '' ? $configured : SiteSettings::get('email');
    }

    /**
     * Whether this installation has a sender at all.
     *
     * There is deliberately no fallback address. A fresh installation ships
     * with MAIL_FROM_ADDRESS empty and no contact e-mail chosen yet, and the
     * only addresses available to hardcode here would be somebody else's —
     * which is exactly how the previous default sent a new site's mail as
     * Van Veluw Laserdesign. App\Service\Mailer asks this before it sends and
     * refuses with a named configuration error instead, so a half-configured
     * install fails loudly at the one moment an operator can act on it.
     */
    public static function isConfigured(): bool
    {
        return self::fromAddress() !== '';
    }

    /**
     * What to tell whoever has to fix an unconfigured sender: the variable
     * and the setting, named rather than described, so the message matches
     * the two places the value can actually come from.
     */
    public static function unconfiguredMessage(): string
    {
        return 'No sender address is configured for this installation: '
            . 'set MAIL_FROM_ADDRESS in .env, or fill in the contact e-mail address '
            . 'under Instellingen.';
    }

    /**
     * The footer line, HTML-escaped and ready to print: name, city and
     * e-mail address, separated by middots, with empty parts omitted.
     */
    public static function footerLine(): string
    {
        $parts = array_filter([
            self::name(),
            SiteSettings::get('company_city'),
            SiteSettings::get('email'),
        ], static fn (string $part): bool => trim($part) !== '');

        $escaped = array_map(
            static fn (string $part): string => htmlspecialchars($part, ENT_QUOTES, 'UTF-8'),
            $parts
        );

        return implode(' &middot; ', $escaped);
    }
}
