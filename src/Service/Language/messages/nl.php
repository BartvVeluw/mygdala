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
    'language.primary' => 'Hoofdtaal',
    'language.primary_help' => 'De taal waarin je de inhoud van deze website schrijft. Alles valt hierop terug als een vertaling ontbreekt.',
    'language.secondary' => 'Tweede taal',
    'language.secondary_help' => 'Optioneel. Zet dit aan als je de website ook in een tweede taal wilt aanbieden. Staat het uit, dan bewerk je maar één taal en blijven bestaande vertalingen gewoon bewaard.',
    'language.secondary_none' => 'Geen tweede taal',
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
];
