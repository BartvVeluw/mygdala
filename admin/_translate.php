<?php

declare(strict_types=1);

/**
 * Two shorthands for printing CMS interface text, so an admin template can
 * ask for a translated string without naming a fully-qualified class in the
 * middle of its markup.
 *
 * `<?= admin_te('pages.title') ?>` instead of
 * `<?= htmlspecialchars(\App\Service\Language\AdminTranslator::trans('pages.title'), ENT_QUOTES, 'UTF-8') ?>`
 *
 * Nothing more than that: the catalog, the fallback rule and the per-account
 * locale all live where they already lived
 * (App\Service\Language\AdminTranslator, ::AdminLocale). This file exists
 * because a template that has to write forty characters of ceremony per
 * sentence quietly stays untranslated, and Multilingual V1's real problem was
 * never the machinery — it was how few screens used it (MULTILINGUAL.md).
 *
 * Required by admin/_header.php, so every screen that renders the shared
 * shell has both helpers. A screen that needs one BEFORE the shell — in its
 * <title>, typically — requires this file itself; it is require_once-safe.
 */

use App\Service\Language\AdminLocale;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\SiteLanguages;

if (!function_exists('admin_t')) {
    /**
     * The CMS interface text for $key in the signed-in account's language.
     *
     * @param array<string, string|int> $replacements substituted as `:name`
     */
    function admin_t(string $key, array $replacements = []): string
    {
        return AdminTranslator::trans($key, $replacements);
    }

    /**
     * The same string, escaped and ready to print into markup. This is the
     * one to reach for: a translated string is application text, but it is
     * still text going into HTML.
     *
     * @param array<string, string|int> $replacements
     */
    function admin_te(string $key, array $replacements = []): string
    {
        return htmlspecialchars(admin_t($key, $replacements), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * The catalogue's words for $key, or the ones a screen was handed.
 *
 * These three registries — page templates, typeface pairings and CMS themes
 * — declare their names and explanations as plain Dutch data, and are
 * translated where a screen prints them. Keyed on the registry key itself,
 * which is a fixed string in source and never comes from a request.
 */
function admin_registry_label(string $key, string $fallback): string
{
    return admin_t($key) === $key ? $fallback : admin_t($key);
}

/**
 * What the CMS calls a website language: its name in the CMS interface
 * language when the closed V1 registry has one ("Engels" in a Dutch CMS),
 * else its own name from the website language registry ("Deutsch"), else its
 * code. A website language is never a CMS language, so a language beyond the
 * V1 pair has no translated name to give, and its own name is the one an
 * editor recognises.
 */
function admin_website_language_label(string $code): string
{
    if (LanguageRegistry::has($code)) {
        return LanguageRegistry::label($code, AdminLocale::current());
    }

    $language = SiteLanguages::find($code);

    return $language !== null && $language->nativeName !== '' ? $language->nativeName : strtoupper($code);
}
