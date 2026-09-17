<?php

declare(strict_types=1);

namespace App\Install;

use App\Database;
use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Module\ModuleSettings;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AppUrl;
use App\Service\Branding;
use App\Service\Media\MediaService;
use App\Service\NavigationLocalization;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageTranslation;
use App\Service\PageTemplates\PageTemplateInstaller;
use App\Service\PageTemplates\PageTemplates;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\LocalizedSiteSettings;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeSettings;

/**
 * The Setup Wizard: what it asks, what counts as a valid answer, and what
 * finishing it actually does.
 *
 * It is ONBOARDING, not a settings screen. Everything it writes has a
 * permanent home elsewhere in the CMS — Site-instellingen owns identity,
 * Vormgeving owns appearance, Pagina's owns pages, Navigatie owns the menu —
 * and this class writes through those same owners rather than beside them:
 *
 *   identity     App\Service\SiteSettings   (site_settings rows)
 *   language     App\Service\Language\ContentLanguages::savePrimary()
 *   appearance   App\Service\Theme\ThemeSettings::save()
 *   modules      App\Module\ModuleSettings::save()
 *   pages        App\Service\PageTemplates\PageTemplateInstaller
 *   menu         App\Repository\NavigationRepository
 *
 * There is no second theme engine, no second module state and no second page
 * creator. A field this wizard does not offer keeps whatever default it has,
 * and the owner edits it afterwards on the screen that owns it (SETUP.md).
 *
 * HOW IT AVOIDS FINISHING HALFWAY. {@see complete()} runs in a fixed order,
 * and the completion marker is the very last thing written:
 *
 *   1. validate everything, and stop on the first invalid step
 *   2. persist settings, the website language, appearance and modules in
 *      ONE transaction
 *   3. create the selected starter pages, each one atomic in itself
 *   4. add a menu item for each page that was actually created
 *   5. mark setup complete
 *
 * Anything that throws before step 5 leaves setup INCOMPLETE, so the owner
 * comes back to the wizard rather than to a site that claims to be
 * configured. Steps 3 and 4 cannot join step 2's transaction because
 * PageTemplateInstaller opens its own and PDO has no nested transactions —
 * so instead every one of them is idempotent: a page whose slug already
 * exists is skipped, and a menu item is only added for a page this run
 * created. Running the wizard again after a failure therefore finishes the
 * job instead of duplicating it.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: create an account, invent legal pages,
 * seed products, build a footer, or write anything about a business it was
 * not told. An empty answer is a real answer everywhere here.
 */
final class SetupWizard
{
    /**
     * The optional starter pages, as a CLOSED map — the same registration
     * rule App\Service\PageTemplates\PageTemplates and
     * App\Module\ModuleRegistry follow. A key arrives from a request, and
     * the only thing it may ever do is hit or miss one of these.
     *
     * Each names an existing generic page template. There is no Portfolio
     * entry because there is no generic Portfolio template to build it from,
     * and offering a checkbox that cannot be honoured is worse than not
     * offering it.
     *
     * TWO SLUGS PER PAGE, and the second one is not padding. This codebase
     * still ships `contact.php`, `diensten.php`, `portfolio.php` and
     * `over-mij.php` at the project root for the installation whose URLs
     * they are, so App\Service\ReservedRoutes reserves those words
     * everywhere: a CMS page at /contact would sit permanently behind the
     * file that shadows it. The preferred slug is therefore what an install
     * gets once those legacy files are gone, and `alternate_slug` is what it
     * gets today — chosen to read as a real page name rather than as a
     * collision ("contact-2"). {@see plannedSlug()} is the one place that
     * decides, so the wizard shows the URL it is actually going to make.
     *
     * @var array<string, array{template: string, title: string, slug: string, alternate_slug: string, label: string, description: string}>
     */
    public const STARTER_PAGES = [
        'about' => [
            'template' => 'about',
            'title' => 'Over ons',
            'slug' => 'over-ons',
            'alternate_slug' => 'over-ons-pagina',
            'label' => 'Over ons',
            'description' => 'Page Hero, tekst met afbeelding, tekstblok en een CTA-band.',
        ],
        'services' => [
            'template' => 'services',
            'title' => 'Diensten',
            'slug' => 'diensten',
            'alternate_slug' => 'onze-diensten',
            'label' => 'Diensten',
            'description' => 'Page Hero, kaarten-carrousel, tekstblok en een CTA-band.',
        ],
        'contact' => [
            'template' => 'contact',
            'title' => 'Contact',
            'slug' => 'contact',
            'alternate_slug' => 'contact-opnemen',
            'label' => 'Contact',
            'description' => 'Page Hero, een formulierblok en een contactkaart.',
        ],
    ];

    /**
     * The settings step 1 may write, with the maximum length each accepts.
     * Everything else keeps its default and is edited later under
     * Instellingen → Site-instellingen.
     *
     * footer_description and city are website text
     * (App\Service\LocalizedSiteSettings) and are written in the website
     * language this same wizard run chooses; the rest are site_settings rows.
     *
     * @var array<string, int>
     */
    private const IDENTITY_FIELDS = [
        'site_name' => 120,
        'email' => 190,
        'footer_description' => 300,
        'city' => 120,
        'kvk_number' => 40,
    ];

    /** Only a site name is genuinely required; every other answer may be empty. */
    private const REQUIRED_IDENTITY_FIELDS = ['site_name'];

    /**
     * Validates a whole submitted wizard.
     *
     * Returns the values to store alongside a Dutch, user-facing message per
     * rejected field. Nothing is written here — {@see complete()} validates
     * again anyway, because "the only caller is careful" is not a property a
     * write path should have to assume.
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];

        $identity = self::validateIdentity($input, $errors);
        $languages = self::validateLanguages($input);
        $branding = self::validateBranding($input, $errors);
        $theme = self::validateTheme($input, $errors);
        $modules = self::validateModules($input, $errors);
        $pages = self::validateStarterPages($input);

        return [
            'values' => [
                'identity' => $identity,
                'languages' => $languages,
                'branding' => $branding,
                'theme' => $theme,
                'modules' => $modules,
                'pages' => $pages,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Applies a validated wizard and marks setup complete. See the class
     * docblock for the order and why the marker is last.
     *
     * @param array<string, mixed> $input the raw submission; validated again here
     *
     * @return list<string> the slugs of the pages this run created
     *
     * @throws \RuntimeException when the submission does not validate
     */
    public static function complete(array $input): array
    {
        $validated = self::validate($input);

        if ($validated['errors'] !== []) {
            throw new \RuntimeException('The setup submission is not valid.');
        }

        $values = $validated['values'];

        self::persistConfiguration($values);

        $created = self::createStarterPages($values['pages']);

        self::addMenuItems($created);

        SetupState::markComplete();

        return array_map(static fn (array $page): string => $page['slug'], $created);
    }

    // ----------------------------------------------------------- validation

    /**
     * Step 1: which language this WEBSITE is written in (Multilingual V1, see
     * MULTILINGUAL.md).
     *
     * ONE QUESTION, and now genuinely only one thing to ask: which language a
     * visitor gets before they choose. The site publishes Dutch and English
     * either way, so there is no "do you want a second language" to get wrong
     * on day one, and no setting that could later hide the public language
     * switch or an editor's English fields.
     *
     * NOT the CMS interface language, and not the language an administrator
     * edits content in. Those are two preferences of one PERSON
     * (App\Service\Language\AdminLocale and
     * App\Service\Language\ContentEditingLanguage) and they are chosen under
     * My account. Putting any of the three on one screen is precisely the
     * confusion this feature exists to end, so the wizard labels this one
     * "Taal van de website".
     *
     * There is no error case: {@see websiteLanguage()} turns anything
     * unusable into the project default, and ContentLanguages::savePrimary()
     * is the same writer the settings endpoint uses, so a valid choice means
     * the same thing in both places.
     *
     * @param array<string, mixed> $input
     *
     * @return array{primary: string}
     */
    private static function validateLanguages(array $input): array
    {
        return [
            'primary' => self::websiteLanguage($input['primary_content_language'] ?? null),
        ];
    }

    /**
     * The website language a submitted wizard value stands for.
     *
     * A WEBSITE language, so it goes through the website language layer only:
     * the shape through App\Service\Language\LanguageCode, and what V1 can
     * publish through the ContentLanguages adapter, whose savePrimary() then
     * stores it in App\Service\Language\SiteLanguages. Never through
     * AdminLocale: that is the CMS interface language of one person, and that
     * its list holds Dutch and English as well is a coincidence of V1, not a
     * rule (Tests\Service\MultilingualBoundaryTest).
     *
     * Anything unusable becomes the project default, as it did before: a
     * tampered dropdown must not lock an owner out of their own site.
     * admin/setup.php takes the dropdown's starting value from here too, so
     * the screen and the save cannot disagree.
     */
    public static function websiteLanguage(mixed $submitted): string
    {
        $code = LanguageCode::normalise(is_string($submitted) ? $submitted : null);

        return ContentLanguages::normalisePrimary($code ?? '');
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private static function validateIdentity(array $input, array &$errors): array
    {
        $values = [];

        foreach (self::IDENTITY_FIELDS as $key => $maxLength) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value === '' && in_array($key, self::REQUIRED_IDENTITY_FIELDS, true)) {
                $errors[$key] = 'Dit veld is verplicht.';
                continue;
            }

            if (mb_strlen($value) > $maxLength) {
                $errors[$key] = 'Dit veld mag maximaal ' . $maxLength . ' tekens zijn.';
                continue;
            }

            $values[$key] = $value;
        }

        if (($values['email'] ?? '') !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Vul een geldig e-mailadres in, of laat het veld leeg.';
            unset($values['email']);
        }

        $baseUrl = self::validateBaseUrl($input, $errors);
        if ($baseUrl !== null) {
            $values[AppUrl::SETTING_KEY] = $baseUrl;
        }

        return $values;
    }

    /**
     * The public base URL, and the one field whose ownership depends on the
     * deployment. When APP_URL is set in .env it decides, the wizard shows
     * it read-only, and a value posted anyway is IGNORED rather than stored
     * — storing it would leave two answers to one question, with the stored
     * one silently losing (App\Service\AppUrl).
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     */
    private static function validateBaseUrl(array $input, array &$errors): ?string
    {
        if (AppUrl::isPinnedByEnvironment()) {
            return null;
        }

        if (!array_key_exists(AppUrl::SETTING_KEY, $input)) {
            return null;
        }

        $submitted = trim((string) $input[AppUrl::SETTING_KEY]);

        if ($submitted === '') {
            // Nothing configured is a valid state: canonical URLs then use
            // the placeholder until somebody fills this in, which is visible
            // rather than wrong.
            return '';
        }

        $normalized = AppUrl::normalizeBase($submitted);

        if ($normalized === null) {
            $errors[AppUrl::SETTING_KEY] =
                'Vul het volledige webadres in, inclusief https:// — bijvoorbeeld https://www.voorbeeld.nl.';

            return null;
        }

        return $normalized;
    }

    /**
     * The four branding images, each optional. A media id must name a real
     * item in the library; the picker is a convenience, never the
     * validation.
     *
     * Both halves of the reference are written, exactly as
     * api/admin/update-site-settings.php does: the id, and the legacy
     * `*_path` fallback that App\Service\Branding reads when an item has
     * gone missing (MEDIA.md). The two can therefore never disagree about
     * whether this site has a logo.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private static function validateBranding(array $input, array &$errors): array
    {
        $values = [];

        foreach (Branding::MEDIA_KEYS as $pathKey => $mediaKey) {
            $submitted = trim((string) ($input[$mediaKey] ?? ''));

            if ($submitted === '') {
                $values[$mediaKey] = '';
                $values[$pathKey] = '';
                continue;
            }

            $media = MediaService::find((int) $submitted);

            if ($media === null) {
                $errors['branding'] = 'Een gekozen afbeelding bestaat niet (meer) in de mediabibliotheek.';
                continue;
            }

            $values[$mediaKey] = (string) $media->id;
            $values[$pathKey] = $media->path;
        }

        return $values;
    }

    /**
     * Appearance, straight through the existing theme engine. Every rule
     * about what a colour, a font pairing or a button shape may be already
     * lives in ThemeSettings, and this wizard adds none of its own.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private static function validateTheme(array $input, array &$errors): array
    {
        $result = ThemeSettings::validate(array_intersect_key($input, array_flip(ThemeSettings::keys())));

        foreach ($result['errors'] as $key => $message) {
            $errors[$key] = $message;
        }

        return $result['values'];
    }

    /**
     * Which optional modules the site wants. Only registered keys are
     * considered, and a module the ENVIRONMENT pins is not the wizard's to
     * change — its checkbox is shown disabled and whatever the request sent
     * for it is ignored (App\Module\ModuleConfig).
     *
     * The dependency is enforced here rather than silently applied:
     * ModuleRegistry would switch Personalisatie off by itself, but somebody
     * who ticked both boxes deserves to be told why one of them cannot
     * happen instead of finding it off afterwards.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $errors
     *
     * @return array<string, bool>
     */
    private static function validateModules(array $input, array &$errors): array
    {
        $submitted = $input['modules'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $wanted = [];

        foreach (ModuleRegistry::keys() as $moduleKey) {
            if (ModuleConfig::isPinnedByEnvironment($moduleKey)) {
                continue;
            }

            $wanted[$moduleKey] = !empty($submitted[$moduleKey]);
        }

        foreach ($wanted as $moduleKey => $isWanted) {
            if (!$isWanted) {
                continue;
            }

            foreach (ModuleRegistry::definition($moduleKey)?->dependencies() ?? [] as $dependency) {
                if (!ModuleRegistry::has($dependency)) {
                    continue;
                }

                // Satisfied either by another checkbox or by the environment
                // pinning that dependency on.
                $dependencySatisfied = $wanted[$dependency]
                    ?? (ModuleConfig::environmentValue($dependency) ?? true);

                if ($dependencySatisfied) {
                    continue;
                }

                $errors['modules'] = sprintf(
                    '%s werkt alleen samen met %s. Zet %s aan, of laat %s uit.',
                    ModuleRegistry::label($moduleKey),
                    ModuleRegistry::label($dependency),
                    ModuleRegistry::label($dependency),
                    ModuleRegistry::label($moduleKey)
                );
            }
        }

        return $wanted;
    }

    /**
     * The optional starter pages. Nothing is required, an unknown key is
     * dropped, and a key whose slug is already taken is dropped too — the
     * wizard must never create a second Contact page next to one that
     * already exists.
     *
     * @param array<string, mixed> $input
     *
     * @return list<string> the starter keys to create
     */
    private static function validateStarterPages(array $input): array
    {
        $submitted = $input['pages'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $selected = [];

        foreach (array_keys(self::STARTER_PAGES) as $key) {
            if (!empty($submitted[$key])) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    /**
     * The URL a starter page would get on THIS installation, or null when
     * such a page already exists and the wizard will leave it alone.
     *
     * One place decides, and both the checkbox's label and the creation path
     * ask it — so the wizard never promises a URL it is not going to make.
     * The order is: the preferred slug, the alternate one when a legacy
     * route file shadows the first, and finally whatever
     * App\Service\PageService can make unique from the title. The last step
     * is a safety net rather than an expectation; it exists so a site that
     * has taken both names still gets its page.
     */
    public static function plannedSlug(string $key, ?PageRepository $repository = null): ?string
    {
        $definition = self::STARTER_PAGES[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $repository ??= new PageRepository();

        foreach ([$definition['slug'], $definition['alternate_slug']] as $candidate) {
            $slug = PageService::sanitizeSlug($candidate);

            if ($slug === '') {
                continue;
            }

            if ($repository->slugExists($slug)) {
                // Somebody already has this page. Creating a second one under
                // a different name is exactly the duplicate this must avoid.
                return null;
            }

            if (PageService::validateSlug($repository, $slug, null) === null) {
                return $slug;
            }
        }

        return PageService::generateSlug($repository, $definition['title']);
    }

    // -------------------------------------------------------------- writing

    /**
     * Steps 2 of {@see complete()}: identity, branding, appearance and
     * modules, all committed together or not at all.
     *
     * @param array<string, mixed> $values
     */
    private static function persistConfiguration(array $values): void
    {
        $db = Database::connection();
        $db->beginTransaction();

        try {
            $settings = array_merge($values['identity'], $values['branding']);

            // Only keys SiteSettings knows about, so a field added to the
            // form without being added to the settings can never create a
            // stray row.
            $settings = array_intersect_key($settings, SiteSettings::defaults());

            if ($settings !== []) {
                (new SiteSettingRepository($db))->upsertMany($settings);
            }

            // These reach for App\Database::connection() themselves, which is
            // the very connection the transaction above is open on — so they
            // take part in it rather than committing early. The website
            // language is the default of the language registry, not a
            // settings row (docs/multilingual/ARCHITECTURE.md).
            ContentLanguages::savePrimary($values['languages']['primary']);

            // The description and the place are words in that language.
            $localized = array_intersect_key($values['identity'], LocalizedSiteSettings::KEYS);
            if ($localized !== []) {
                LocalizedSiteSettings::save($values['languages']['primary'], $localized);
            }

            if ($values['theme'] !== []) {
                ThemeSettings::save($values['theme']);
            }

            if ($values['modules'] !== []) {
                ModuleSettings::save($values['modules']);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();

            throw $e;
        }

        SiteSettings::clearCache();
        LocalizedSiteSettings::clearCache();
        ThemeSettings::clearCache();
        ModuleSettings::clearCache();
        ModuleRegistry::reset();
    }

    /**
     * Step 3: one ordinary draft CMS page per selected template, built by
     * the same installer admin/page-new.php uses. Nothing marks them as
     * having come from the wizard, exactly as nothing marks a page as having
     * come from a template (PAGE-TEMPLATES.md).
     *
     * @param list<string> $selected
     *
     * @return list<array{key: string, id: int, slug: string, title: string}>
     */
    private static function createStarterPages(array $selected): array
    {
        if ($selected === []) {
            return [];
        }

        $repository = new PageRepository();
        $created = [];

        foreach ($selected as $key) {
            $definition = self::STARTER_PAGES[$key] ?? null;

            if ($definition === null) {
                continue;
            }

            $template = PageTemplates::get($definition['template']);

            if ($template === null) {
                continue;
            }

            $slug = self::plannedSlug($key, $repository);

            // Null means the page is already there, and it is left exactly
            // as it is. That is what makes finishing an interrupted run
            // safe, and it is also the answer for an owner who created the
            // page by hand first.
            if ($slug === null) {
                continue;
            }

            $id = PageTemplateInstaller::install($template, [
                'content_key' => PageService::generateContentKey($repository, $slug),
                'slug' => $slug,
                // Draft, like every page a template makes: a starter page is
                // a beginning, not something to put in front of visitors
                // before its owner has read it.
                'status' => PageContent::STATUS_DRAFT,
            ], [
                // Its name in the website's default language, which step 2
                // has just stored: a new page is always created in the
                // default language, as admin/page-new.php creates one. No SEO
                // text is invented; the automatic title covers it.
                PageLocalization::defaultLanguage() => [PageTranslation::TITLE => $definition['title']],
            ]);

            $created[] = [
                'key' => $key,
                'id' => $id,
                'slug' => $slug,
                'title' => $definition['title'],
            ];
        }

        return $created;
    }

    /**
     * Step 4: a menu item per page this run created, and nothing else.
     *
     * This is NOT a navigation wizard. Home is already in the menu from the
     * install bootstrap; a link to the Shop's storefront is the owner's to
     * add (Navigatie, route "Shop"), because the bootstrap no longer seeds a
     * Shop page or its menu item (INSTALL-BOOTSTRAP.md); and no footer column
     * is invented — an empty footer on a new site looks intentional, a
     * made-up one does not.
     *
     * The items point at the PAGE rather than at a path, like every other
     * link in this CMS, so they follow a later slug change and simply do not
     * render while the page is still a draft (App\Service\LinkResolver).
     *
     * The label is the page's title, written in the website's DEFAULT
     * language like the page itself (App\Service\NavigationLocalization);
     * every other language falls back to it. Row and label are one
     * transaction, so an item never exists without its words.
     *
     * @param list<array{key: string, id: int, slug: string, title: string}> $created
     */
    private static function addMenuItems(array $created): void
    {
        if ($created === []) {
            return;
        }

        $db = Database::connection();
        $navigation = new NavigationRepository($db);
        $language = LanguageFallback::defaultLanguage();

        foreach ($created as $starter) {
            if ($navigation->countByTargetPageId($starter['id']) > 0) {
                continue;
            }

            $db->beginTransaction();
            try {
                $itemId = $navigation->create([
                    'link_type' => 'page',
                    'target_page_id' => $starter['id'],
                    'target_route' => null,
                    'external_url' => null,
                    'open_in_new_tab' => false,
                    'parent_id' => null,
                    'is_visible' => true,
                ]);
                NavigationLocalization::save($itemId, $language, $starter['title']);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();

                throw $e;
            }
        }
    }
}
