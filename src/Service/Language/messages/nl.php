<?php

declare(strict_types=1);

/**
 * The CMS interface in Dutch — the language this CMS was written in, and
 * therefore THE reference catalog: every key that exists anywhere exists
 * here, and App\Service\Language\AdminTranslator falls back to this file
 * whenever another language is missing a key.
 *
 * Keep it sorted by key. Add a key here first, then in every other catalog;
 * Tests\Service\AdminTranslatorTest fails on a key this file has and another
 * one does not.
 */
return [
    // --- The shell every admin screen renders -----------------------------
    'shell.menu' => 'Menu',
    'shell.nav_label' => 'Admin navigatie',
    'shell.logout' => 'Uitloggen',
    'shell.role.super_admin' => 'Super Admin',
    'shell.role.user' => 'CMS-gebruiker',
    'shell.my_account' => 'Mijn account',

    // --- Words that appear on more than one screen -------------------------
    'common.save' => 'Opslaan',
    'common.saved' => 'Opgeslagen',
    'common.cancel' => 'Annuleren',
    'common.back' => 'Terug',
    'common.delete' => 'Verwijderen',
    'common.edit' => 'Bewerken',
    'common.optional' => 'Optioneel',
    'common.required' => 'Verplicht',
    'common.yes' => 'Ja',
    'common.no' => 'Nee',
    'common.settings' => 'Instellingen',

    // --- The account screen (admin/account.php) ----------------------------
    'account.title' => 'Mijn account',
    'account.intro' => 'Voorkeuren die alleen voor jouw account gelden. Ze veranderen niets aan de website of aan wat je collega\'s zien.',
    'account.signed_in_as' => 'Ingelogd als',
    'account.interface_language' => 'Taal van het CMS',
    'account.interface_language_help' => 'De taal waarin dit beheerpaneel aan jou wordt getoond. Dit verandert niets aan de taal van de website.',
    'account.saved' => 'Je voorkeuren zijn opgeslagen.',
    'account.break_glass' => 'Je bent ingelogd met de noodtoegang uit de serverconfiguratie. Die sessie heeft geen account om een voorkeur op te bewaren, dus het CMS staat in de standaardtaal.',
    'account.website_language_hint' => 'Op zoek naar de taal van de website zelf? Die staat bij Instellingen → Site-instellingen.',

    // --- Language concepts, used on several screens ------------------------
    'language.website' => 'Taal van de website',
    'language.cms' => 'Taal van het CMS',
    'language.primary' => 'Hoofdtaal van de website',
    'language.primary_help' => 'De taal waarin je de inhoud van deze website schrijft. Alles valt hierop terug als een vertaling ontbreekt.',
    'language.secondary' => 'Extra taal van de website',
    'language.secondary_help' => 'Optioneel. Zet dit aan als je de website ook in een tweede taal wilt aanbieden. Staat het uit, dan bewerk je maar één taal en blijven bestaande vertalingen gewoon bewaard.',
    'language.secondary_none' => 'Geen extra taal',
    'language.tab_label' => 'Taal kiezen',
    'language.not_translated' => 'Nog niet vertaald',
    'language.settings_title' => 'Talen',
    'language.settings_intro' => 'Bepaal in welke taal deze website geschreven is en of er een vertaling bij komt. De taal van het CMS zelf kies je per persoon bij Mijn account.',
    'language.disabled_preserved' => 'Een taal uitzetten verwijdert niets. Wat er al vertaald is blijft bewaard en komt terug zodra je de taal weer aanzet.',

    // --- Automatic translation --------------------------------------------
    'translate.action' => 'Vertalen naar :language',
    'translate.missing_only' => 'Ontbrekende velden vertalen',
    'translate.section' => 'Deze sectie vertalen',
    'translate.busy' => 'Bezig met vertalen…',
    'translate.done' => 'Vertaald. Controleer de tekst voordat je opslaat.',
    'translate.failed' => 'Automatisch vertalen is niet gelukt: :reason',
    'translate.unavailable' => 'Automatisch vertalen is niet ingesteld op deze installatie.',
    'translate.unavailable_help' => 'Een beheerder van de server kan een vertaaldienst instellen in het .env-bestand. Handmatig vertalen werkt gewoon.',
    'translate.outdated' => 'De brontekst is gewijzigd nadat deze vertaling is gemaakt.',
    'translate.outdated_action' => 'Opnieuw vertalen',
    'translate.manual' => 'Handmatig aangepast',
    'translate.machine' => 'Automatisch vertaald',
    'translate.confirm_overwrite' => 'Deze vertaling is met de hand aangepast. Weet je zeker dat je hem wilt overschrijven?',

    // --- The sidebar, one key per navigation entry -------------------------
    // Read by App\Service\AdminNavigation::label(), keyed on the entry's own
    // 'key'. Core's entries and a module's entries are looked up the same
    // way, so App\Module\ShopModule and App\Module\BlogModule keep declaring
    // a plain Dutch label and never mention that this CMS has two languages.
    'nav.dashboard' => 'Dashboard',
    'nav.pages' => "Pagina's",
    'nav.media' => 'Media',
    'nav.forms' => 'Formulieren',
    'nav.content_blocks' => 'Contentblokken',
    'nav.portfolio' => 'Portfolio',
    'nav.contact_requests' => 'Contactaanvragen',
    'nav.form_submissions' => 'Inzendingen',
    'nav.navigation' => 'Navigatie',
    'nav.footer' => 'Footer',
    'nav.header_footer' => 'Header & footer',
    'nav.settings' => 'Site-instellingen',
    'nav.theme' => 'Vormgeving',
    'nav.redirects' => 'Redirects',
    'nav.users' => 'Gebruikers',
    'nav.blog_posts' => 'Blogberichten',
    'nav.blog_categories' => 'Blogcategorieën',
    'nav.blog_tags' => 'Blogtags',
    'nav.blog_settings' => 'Bloginstellingen',
    'nav.personalization' => 'Personalisatie',
    'nav.catalog' => 'Producten',
    'nav.collections' => 'Collecties',
    'nav.related_products' => 'Gerelateerde producten',
    'nav.shipping' => 'Verzendinstellingen',
    'nav.carrier_rates' => 'Carrier-tarieven',
    'nav.orders' => 'Bestellingen',
    'nav.withdrawal_requests' => 'Retourverzoeken',

    // --- More words that appear on more than one screen --------------------
    'common.add' => 'Toevoegen',
    'common.new' => 'Nieuw',
    'common.status' => 'Status',
    'common.title' => 'Titel',
    'common.url' => 'URL',
    'common.type' => 'Type',
    'common.saving' => 'Opslaan…',
    'common.save_failed' => 'Opslaan mislukt',
    'common.unsaved_changes' => 'Niet-opgeslagen wijzigingen',
    'common.all_saved' => 'Alles opgeslagen',

    // --- The dashboard (admin/index.php) -----------------------------------
    'dashboard.title' => 'Dashboard',
    'dashboard.intro' => 'Welkom terug. Hieronder zie je hoe de site ervoor staat en waar nog iets ligt.',
    'dashboard.pages_failed' => 'De paginagegevens konden niet worden geladen. De onderdelen hieronder werken gewoon.',
    'dashboard.no_permissions' => 'Je hebt op dit moment geen rechten voor een van de CMS-onderdelen. Vraag de beheerder om toegang.',
    'dashboard.content_label' => 'Website-inhoud',
    'dashboard.published_pages' => 'Gepubliceerde pagina\'s',
    'dashboard.published_pages_note' => 'Zichtbaar voor bezoekers.',
    'dashboard.drafts' => 'Concepten',
    'dashboard.drafts_note' => 'Nog niet gepubliceerd — alleen zichtbaar in het CMS.',
    'dashboard.where_to_work' => 'Waar wil je aan werken?',
    'dashboard.card_content_title' => 'Website content',
    'dashboard.card_content_desc' => 'Pas teksten, secties en pagina-inhoud aan.',
    'dashboard.card_content_cta' => 'Pagina\'s beheren',
    'dashboard.card_settings_title' => 'Site-instellingen',
    'dashboard.card_settings_desc' => 'Beheer algemene website-instellingen.',
    'dashboard.card_settings_cta' => 'Instellingen openen',
    'dashboard.card_users_title' => 'Gebruikers',
    'dashboard.card_users_desc' => 'Beheer CMS-accounts en hun rechten.',
    'dashboard.card_users_cta' => 'Gebruikers beheren',

    // --- The pages overview (admin/pages.php) ------------------------------
    'pages.title' => "Pagina's",
    'pages.intro' => 'Alle pagina\'s van de website. Open een pagina om de titel, URL, status en SEO-gegevens aan te passen en de inhoud met de paginabouwer samen te stellen.',
    'pages.new' => 'Nieuwe pagina',
    'pages.created' => 'Pagina aangemaakt.',
    'pages.deleted' => 'Pagina verwijderd.',
    'pages.load_failed' => 'Pagina\'s konden niet worden geladen.',
    'pages.empty' => 'Nog geen pagina\'s.',
    'pages.empty_link' => 'Maak de eerste pagina aan',
    'pages.protected' => 'Beschermd',
    'pages.protected_hint' => 'De webshop heeft deze pagina nodig — titel, SEO en inhoud zijn bewerkbaar, status en verwijderen niet.',
    'pages.fixed_url' => 'Contentpagina (vaste URL)',
    'pages.fixed_url_hint' => 'Gewone contentpagina op een vaste URL — alleen de slug ligt vast.',
    'pages.content_page' => 'Contentpagina',
    'pages.delete_confirm' => 'Deze pagina en alle secties erop definitief verwijderen? Dit kan niet ongedaan worden gemaakt.',

    // --- The page editor (admin/page.php, admin/page-new.php) --------------
    'page.seo' => 'SEO',
    'page.meta_title' => 'SEO-titel',
    'page.meta_description' => 'Meta description',
    'page.google_preview' => 'Voorbeeld in Google',
    'page.no_description' => 'Geen meta description — Google kiest dan zelf een stukje tekst van de pagina.',
    'page.visibility' => 'Zichtbaarheid',
    'page.noindex' => 'Deze pagina niet laten indexeren door zoekmachines',
    'page.save_settings' => 'Instellingen opslaan',
    'page.status_draft' => 'Concept',
    'page.status_published' => 'Gepubliceerd',

    // --- Site settings (admin/settings.php) --------------------------------
    'settings.title' => 'Site-instellingen',
    'settings.intro' => 'Deze gegevens worden overal op de website gebruikt (header, footer, contactpagina). Wijzigingen zijn direct zichtbaar op alle pagina\'s.',
    'settings.saved' => 'Instellingen opgeslagen.',
    'settings.tabs_label' => 'Groepen instellingen',
    'settings.tab_general' => 'Algemeen',
    'settings.tab_seo' => 'SEO',
    'settings.tab_invoices' => 'Facturen',
    'settings.tab_emails' => 'E-mails',
    'settings.tab_dashboard' => 'Dashboard',

    // --- The save bar (admin/_save_bar.php + admin/assets/save-bar.js) -----
    'savebar.error_in' => 'Opslaan mislukt bij “:form”. Niet alles is opgeslagen — probeer het opnieuw.',
];
