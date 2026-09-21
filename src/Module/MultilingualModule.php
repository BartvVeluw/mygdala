<?php

declare(strict_types=1);

namespace App\Module;

/**
 * Meertaligheid: the website published in more than its default language.
 *
 * What it CONTRIBUTES is one answer: publishesTranslations(). While it is on,
 * every active language of the website language registry is published — a
 * prefix, a route, a place in the language switch, the sitemap and hreflang,
 * Accept-Language negotiation, and a language editors can write in. While it
 * is off, App\Service\Language\SiteLanguages publishes the default language
 * only, and every one of those follows from that single answer: Core asks
 * SiteLanguages, never this class and never the module's key.
 *
 * TURNING IT OFF DELETES NOTHING, like every module (MODULES.md). The
 * languages stay registered with their own active flag, every translation
 * row stays where it is, and switching the module on again brings back the
 * same languages at the same addresses. The language registry itself — which
 * languages exist, which one is the default — is Core and is managed with the
 * module on or off (Site-instellingen > Talen), because a website always has
 * a default language.
 *
 * OFF ON A NEW INSTALLATION (docs/multilingual/ARCHITECTURE.md): a new site
 * publishes its default language until somebody asks for more — in the Setup
 * Wizard, under Settings > Talen, or with MODULE_MULTILINGUAL_ENABLED. Every
 * installation that already published more than one language keeps doing so:
 * db/migrations/20260921100000 stores "on" for it, the same pin the Portfolio
 * got, so a new default never applies with hindsight.
 */
final class MultilingualModule extends ModuleDefinition
{
    public function key(): string
    {
        return 'multilingual';
    }

    public function label(): string
    {
        return 'Meertaligheid';
    }

    public function description(): string
    {
        return 'De website in meer dan één taal: een taalkeuze voor bezoekers, adressen per taal en vertalingen per veld. Uitzetten verwijdert geen vertaling.';
    }

    public function enabledByDefault(): bool
    {
        return false;
    }

    public function publishesTranslations(): bool
    {
        return true;
    }
}
