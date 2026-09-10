<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * THE list of page templates this CMS offers — the ONE place a new template
 * is registered, and the only shared file adding one has to touch.
 * Everything else about a template lives in its own definition class next to
 * this one. Same shape, and the same reasoning, as
 * App\Service\Blocks\BlockDefinitions.
 *
 * Registration is EXPLICIT and closed on purpose. There is no directory
 * scanning, no reflection over class names and no database-defined template:
 * a template key arrives from a request (the picker in admin/page-new.php),
 * and the only thing it may ever do is hit or miss a key of the array below.
 * A miss is rejected — it can never become a class name.
 *
 * CORE ONLY, unlike BlockDefinitions. A module does not contribute page
 * templates, and this is a deliberate difference rather than an oversight:
 * a template is a starting point for an ORDINARY CMS content page, so a
 * "Shop page" template would either mint a page that collides with a route
 * the Shop already owns, or hand the editor a block that vanishes the moment
 * the module is switched off. Templates therefore name Core blocks only, and
 * the picker looks identical whether the Shop is on or off. If a module ever
 * genuinely needs to seed a page, it should do so from its own admin screen,
 * where it can own the result.
 *
 * The order below is the order the picker shows: emptiest first, so the
 * least opinionated choice is the one an editor lands on.
 *
 * Templates are code, not content. There is no admin screen to create, edit
 * or delete one, and none is planned for V1 — see PAGE-TEMPLATES.md.
 */
final class PageTemplates
{
    /** The template a page is created with when the editor picks nothing. */
    public const DEFAULT_KEY = 'blank';

    /** @var array<string, class-string<PageTemplateDefinition>> */
    private const MAP = [
        'blank' => BlankTemplate::class,
        'standard' => StandardTemplate::class,
        'about' => AboutTemplate::class,
        'services' => ServicesTemplate::class,
        'contact' => ContactTemplate::class,
        'landing' => LandingTemplate::class,
    ];

    /**
     * Definitions are stateless, so one instance per key per request is
     * enough.
     *
     * @var array<string, PageTemplateDefinition>
     */
    private static array $instances = [];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::MAP);
    }

    /**
     * The definition for one key, or null when the key is not registered —
     * the single lookup every caller goes through, so an unknown template
     * key fails the same way everywhere.
     */
    public static function get(string $key): ?PageTemplateDefinition
    {
        if (!array_key_exists($key, self::MAP)) {
            return null;
        }

        if (!isset(self::$instances[$key])) {
            $class = self::MAP[$key];
            self::$instances[$key] = new $class();
        }

        return self::$instances[$key];
    }

    /**
     * Every registered template, in registration order — what the picker
     * renders.
     *
     * @return array<string, PageTemplateDefinition>
     */
    public static function all(): array
    {
        $templates = [];
        foreach (array_keys(self::MAP) as $key) {
            $templates[$key] = self::get($key);
        }

        return $templates;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The requested key if it is registered, otherwise the default — how the
     * create endpoint turns an absent, stale or forged `template` field into
     * something safe without failing the whole save. Picking nothing means
     * "Lege pagina", which is exactly what creating a page did before
     * templates existed.
     */
    public static function resolve(?string $key): PageTemplateDefinition
    {
        if ($key !== null && self::has($key)) {
            return self::get($key);
        }

        return self::get(self::DEFAULT_KEY);
    }
}
