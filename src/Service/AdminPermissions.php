<?php

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageRegistry;

/**
 * The single server-side registry of every CMS permission that exists.
 *
 * Deliberately NOT a role/group system (see MAIN.MD): a CMS user is either a
 * Super Admin — implicitly holding everything the application currently runs —
 * or a set of individual grants picked from the list below. There are as many
 * permissions as the admin sidebar has sections, and no more: a permission
 * that no page or endpoint actually checks is dead weight, and a permission
 * per button would be unmaintainable across 174 write endpoints.
 *
 * Nothing outside this class may decide what a valid permission name is.
 * Every submitted permission list passes through sanitize(), which drops
 * anything unknown — so a crafted POST can add characters to the form, but
 * never a grant.
 *
 * ## Core's half and the modules' half
 *
 * Core owns the permissions a CMS has with no modules at all. A module owns
 * its own — App\Module\ShopModule holds `products.*`, `collections.manage`,
 * `orders.*` and `shipping.manage`, App\Module\PersonalizationModule holds
 * `personalization.manage`, App\Module\BlogModule `blog.*` and
 * App\Module\PortfolioModule `portfolio.manage` — and contributes them here
 * through permissionGroups(). The NAMES are the unchanged strings that have
 * always been stored in `admin_user_permissions`.
 *
 * Two different questions are asked of that list, and they have two different
 * answers:
 *
 *   KNOWN   all() / isValid() / sanitize() / expand() — every permission of
 *           every REGISTERED module, enabled or not. This is the vocabulary,
 *           and it is what storage round-trips through: a user's
 *           `products.manage` grant survives the Shop being switched off and
 *           on again, because nothing here ever silently rewrites it.
 *   ENABLED enabled() / groups() / userHas() — only the permissions whose
 *           module is running. A disabled module's permissions are held by
 *           NOBODY, Super Admin included, and are not offered on the user
 *           form. That single rule is what makes every Shop admin screen and
 *           all 174 write endpoints refuse while the Shop is off, without a
 *           second check being bolted onto each file.
 *
 * Some permissions imply another (implies()): being allowed to *manage*
 * products/orders necessarily includes being allowed to *view* them, and
 * every content permission includes being allowed to use the Media Library,
 * because picking an image is part of editing a page. That keeps the guards
 * at the top of each page a single flat check
 * (requirePermission('products.view')) instead of an "any of these" list
 * repeated in dozens of files. Core's half of that list is
 * CORE_IMPLICATIONS below; a module contributes its own.
 */
class AdminPermissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';
    public const CONTACT_MANAGE = 'contact.manage';
    public const PAGES_MANAGE = 'pages.manage';
    public const FORMS_MANAGE = 'forms.manage';
    public const FORMS_SUBMISSIONS = 'forms.submissions';
    public const SETTINGS_MANAGE = 'settings.manage';
    public const USERS_MANAGE = 'users.manage';

    /**
     * Installing a Mygdala release through the built-in updater
     * (admin/updates.php, docs/updates/). It replaces the application's own
     * code and runs its migrations, so it is the owner's decision in the
     * same way widening the circle of user managers is: only a Super Admin
     * holds it by default, and only a Super Admin can hand it out.
     */
    public const UPDATES_MANAGE = 'updates.manage';

    /**
     * The Media Library, and the one place this project splits a permission
     * for a reason other than "the sidebar has a read-only screen".
     *
     * MEDIA_VIEW is "open the library, search it, and add something to it".
     * MEDIA_MANAGE is "change or remove what is already in it".
     *
     * The split is where it is because the library is SHARED. Adding an image
     * is what every content editor in this CMS has always been able to do
     * through the upload field of any block editor, and it takes nothing
     * away from anybody; editing the alt text of an item that four pages use,
     * or deleting an item, reaches beyond the screen the editor is looking at.
     * So the additive half rides along with the content permissions
     * (see CORE_IMPLICATIONS) and only the shared-consequence half is a grant
     * of its own.
     */
    public const MEDIA_VIEW = 'media.view';
    public const MEDIA_MANAGE = 'media.manage';

    /**
     * Permissions only a Super Admin may hand out or take away. A user with
     * users.manage can create colleagues and adjust their content
     * permissions, but can never widen the circle of people who manage users
     * — that stays the owner's decision. See App\Service\AdminUserService.
     */
    public const SUPER_ADMIN_GRANTABLE_ONLY = [
        self::USERS_MANAGE,
        self::UPDATES_MANAGE,
    ];

    /**
     * The permission checkboxes Core itself has, in the order and grouping
     * the CMS user form renders them. `order` places them among the groups
     * modules contribute — see App\Module\ModuleDefinition.
     */
    private const CORE_GROUPS = [
        [
            'label' => 'Dashboard',
            'order' => 100,
            'permissions' => [
                self::DASHBOARD_VIEW => [
                    'label' => 'Dashboard bekijken',
                    'description' => 'Toegang tot de startpagina van het CMS.',
                ],
            ],
        ],
        [
            'label' => 'Website',
            'order' => 400,
            'permissions' => [
                self::PAGES_MANAGE => [
                    'label' => 'Pagina\'s beheren',
                    'description' => 'Pagina\'s en hun secties, plus de navigatie en de footer.',
                ],
                self::MEDIA_VIEW => [
                    'label' => 'Mediabibliotheek gebruiken',
                    'description' => 'De mediabibliotheek openen, doorzoeken en er nieuwe afbeeldingen aan toevoegen. Zit automatisch bij "Pagina\'s beheren", "Portfolio beheren" en "Instellingen beheren".',
                ],
                self::MEDIA_MANAGE => [
                    'label' => 'Mediabibliotheek beheren',
                    'description' => 'Alt-teksten en namen van bestaande media wijzigen en ongebruikte media verwijderen, ook meerdere tegelijk. Bevat automatisch "Mediabibliotheek gebruiken".',
                ],
                self::FORMS_MANAGE => [
                    'label' => 'Formulieren beheren',
                    'description' => 'Formulieren en hun velden maken en wijzigen. Geeft GEEN toegang tot de bewaarde inzendingen — dat is een apart recht.',
                ],
                self::SETTINGS_MANAGE => [
                    'label' => 'Instellingen beheren',
                    'description' => 'Algemene website-, facturatie- en e-mailinstellingen.',
                ],
            ],
        ],
        [
            'label' => 'Klantcontact',
            'order' => 500,
            'permissions' => [
                self::CONTACT_MANAGE => [
                    'label' => 'Contactaanvragen beheren',
                    'description' => 'Binnengekomen contact-/offerteaanvragen inzien, afhandelen en verwijderen.',
                ],
                self::FORMS_SUBMISSIONS => [
                    'label' => 'Formulierinzendingen bekijken',
                    'description' => 'Bewaarde inzendingen van formulieren inzien en verwijderen. Dit zijn persoonsgegevens van bezoekers en zit bewust NIET bij Formulieren beheren.',
                ],
            ],
        ],
        [
            'label' => 'Beheer',
            'order' => 900,
            'permissions' => [
                self::USERS_MANAGE => [
                    'label' => 'Gebruikers beheren',
                    'description' => 'CMS-gebruikers aanmaken en wijzigen. Alleen een Super Admin kan dit recht toekennen.',
                ],
                self::UPDATES_MANAGE => [
                    'label' => 'Updates installeren',
                    'description' => 'Nieuwe versies van Mygdala controleren en installeren. Een update zet de website even in onderhoud. Alleen een Super Admin kan dit recht toekennen.',
                ],
            ],
        ],
    ];

    /**
     * Core's own "holding this necessarily means holding that", merged with
     * every module's in implies().
     *
     * Core's two content permissions imply MEDIA_VIEW because picking an
     * image is part of editing a page or the site's branding — an editor who
     * could already upload one through a block's own file field must not lose
     * that the moment the picker replaces it. A module's content permission
     * says the same for itself (the Blog's, the Portfolio's). Nothing implies
     * MEDIA_MANAGE: deleting from a shared library, and rewriting alt text
     * that several pages depend on, stays a grant somebody hands out on
     * purpose.
     *
     * @var array<string, list<string>>
     */
    private const CORE_IMPLICATIONS = [
        self::MEDIA_MANAGE => [self::MEDIA_VIEW],
        self::PAGES_MANAGE => [self::MEDIA_VIEW],
        self::SETTINGS_MANAGE => [self::MEDIA_VIEW],
    ];

    /** @var array<string, list<array<string, mixed>>> merged group lists, by scope */
    private static array $groupCache = [];

    /** Forgets the merged lists; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$groupCache = [];
    }

    /**
     * Every group of every REGISTERED module, enabled or not — the vocabulary.
     *
     * @return list<array{label: string, order: int, permissions: array<string, array{label: string, description: string}>}>
     */
    public static function knownGroups(): array
    {
        return self::$groupCache['known'] ??= self::mergeGroups(ModuleRegistry::all());
    }

    /**
     * The groups the user form renders: Core's plus those of the modules that
     * are actually running.
     *
     * @return list<array{label: string, order: int, permissions: array<string, array{label: string, description: string}>}>
     */
    public static function groups(): array
    {
        return self::$groupCache['enabled'] ??= self::mergeGroups(ModuleRegistry::enabled());
    }

    /**
     * @param array<string, \App\Module\ModuleDefinition> $modules
     * @return list<array<string, mixed>>
     */
    private static function mergeGroups(array $modules): array
    {
        $groups = self::CORE_GROUPS;

        foreach ($modules as $module) {
            foreach ($module->permissionGroups() as $group) {
                $groups[] = $group;
            }
        }

        usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values($groups);
    }

    /**
     * @return list<string> every KNOWN permission name, in form order
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::knownGroups() as $group) {
            foreach (array_keys($group['permissions']) as $permission) {
                $all[] = $permission;
            }
        }

        return $all;
    }

    /**
     * @return list<string> the permissions a user can actually hold right now
     */
    public static function enabled(): array
    {
        $enabled = [];
        foreach (self::groups() as $group) {
            foreach (array_keys($group['permissions']) as $permission) {
                $enabled[] = $permission;
            }
        }

        return $enabled;
    }

    /** Whether the name exists at all — a disabled module's included. */
    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /** Whether the permission's owning module is running, so it can be held. */
    public static function isEnabled(string $permission): bool
    {
        return in_array($permission, self::enabled(), true);
    }

    /**
     * "Manage implies view", merged from every registered module. Read from
     * the KNOWN set so expand() behaves identically whichever modules happen
     * to be running.
     *
     * @return array<string, list<string>>
     */
    public static function implies(): array
    {
        $implies = self::CORE_IMPLICATIONS;

        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->permissionImplications() as $source => $implied) {
                $implies[$source] = array_values(array_unique(
                    array_merge($implies[$source] ?? [], $implied)
                ));
            }
        }

        return $implies;
    }

    public static function label(string $permission): string
    {
        foreach (self::knownGroups() as $group) {
            if (isset($group['permissions'][$permission])) {
                return $group['permissions'][$permission]['label'];
            }
        }

        return $permission;
    }

    /**
     * The groups as the user form RENDERS them: headings and explanations in
     * the CMS interface language of whoever is reading.
     *
     * Deliberately not part of groups()/knownGroups(). Those two are read
     * while an account is still being loaded — expand() runs inside
     * AdminAuth::user() — and translating there would ask AdminLocale for a
     * language before the session's account exists, which resolves to the
     * default and then stays cached for the rest of the request. Asking only
     * when a form is about to print them keeps that loop closed.
     *
     * @return list<array<string, mixed>>
     */
    public static function groupsForDisplay(): array
    {
        return array_map(self::translateGroup(...), self::groups());
    }

    /**
     * A group's heading and every permission in it, in the CMS interface
     * language of whoever is reading the form.
     *
     * Keyed on the permission name itself — `perm.pages.manage.label` — which
     * is a constant in source and can never come from a request. Translated
     * here, once, so Core's groups and a module's groups switch language
     * together and no module has to know this CMS has two: a module keeps
     * declaring a plain Dutch label, and a group with no key in the catalogue
     * keeps the words it declared.
     *
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function translateGroup(array $group): array
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string) $group['label']));
        $group['label'] = self::say('perm.group.' . trim((string) $slug, '_'), (string) $group['label']);

        foreach (($group['permissions'] ?? []) as $name => $meta) {
            foreach (['label', 'description'] as $part) {
                if (isset($meta[$part])) {
                    $group['permissions'][$name][$part] = self::say(
                        'perm.' . $name . '.' . $part,
                        (string) $meta[$part]
                    );
                }
            }
        }

        return $group;
    }

    /** The catalogue's words for $key, or the ones written in the registry. */
    private static function say(string $key, string $fallback): string
    {
        return AdminTranslator::has($key, LanguageRegistry::DEFAULT_LANGUAGE)
            ? AdminTranslator::trans($key)
            : $fallback;
    }

    /**
     * Turns anything a request may have sent into a safe, canonical list:
     * non-strings and unknown names are dropped (never an error — an unknown
     * grant simply does not exist), duplicates removed, and the result is
     * ordered like all() so stored data never depends on form field order.
     *
     * Validates against the KNOWN vocabulary, not the enabled one: a stored
     * `products.manage` must survive a round trip through this method while
     * the Shop is switched off, or disabling a module would quietly erase
     * grants it had nothing to do with.
     *
     * @return list<string>
     */
    public static function sanitize(mixed $submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $valid = [];
        foreach ($submitted as $permission) {
            if (is_string($permission) && self::isValid($permission)) {
                $valid[$permission] = true;
            }
        }

        return array_values(array_filter(self::all(), static fn (string $p): bool => isset($valid[$p])));
    }

    /**
     * Adds the permissions implied by the ones granted (see implies()), so a
     * caller never has to remember that "manage" covers "view".
     *
     * @param list<string> $granted
     * @return list<string>
     */
    public static function expand(array $granted): array
    {
        $implies = self::implies();

        $expanded = [];
        foreach ($granted as $permission) {
            if (!is_string($permission) || !self::isValid($permission)) {
                continue;
            }
            $expanded[$permission] = true;
            foreach ($implies[$permission] ?? [] as $implied) {
                $expanded[$implied] = true;
            }
        }

        return array_values(array_filter(self::all(), static fn (string $p): bool => isset($expanded[$p])));
    }

    /**
     * THE authorisation decision, as a pure function of an account row and a
     * permission name: a Super Admin holds everything the application is
     * currently running, everyone else holds exactly their (already expanded)
     * grants — and nobody at all holds a permission belonging to a module
     * that is switched off. AdminAuth::can() is this plus "is anybody signed
     * in", which keeps the rule itself testable without a session, a database
     * or a browser.
     *
     * @param array<string, mixed> $user an AdminAuth::user() / AdminUserRepository row
     */
    public static function userHas(array $user, string $permission): bool
    {
        if (!self::isEnabled($permission)) {
            return false;
        }

        if (($user['is_super_admin'] ?? false) === true) {
            return true;
        }

        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

        return in_array($permission, $permissions, true);
    }

    /**
     * The short, human-readable access summary the user overview shows in its
     * "Toegang" column — the granted permissions' own labels, with an implied
     * "view" hidden when the matching "manage" is present (listing both
     * "Producten bekijken" and "Producten beheren" says nothing extra).
     *
     * Only permissions the user can actually exercise are listed: a grant for
     * a module that is switched off is still stored, but claiming it as
     * access would be wrong.
     *
     * @param list<string> $granted
     * @return list<string>
     */
    public static function summarize(array $granted): array
    {
        $granted = array_values(array_filter(self::expand($granted), [self::class, 'isEnabled']));

        $redundant = [];
        foreach (self::implies() as $source => $implied) {
            if (in_array($source, $granted, true)) {
                foreach ($implied as $permission) {
                    $redundant[$permission] = true;
                }
            }
        }

        $labels = [];
        foreach ($granted as $permission) {
            if (!isset($redundant[$permission])) {
                $labels[] = self::label($permission);
            }
        }

        return $labels;
    }
}
