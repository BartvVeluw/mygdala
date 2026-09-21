# Het CMS in het Nederlands of het Engels

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Laag 1, de taal waarin het
beheerpaneel aan een beheerder wordt getoond: de tekstcatalogi, hoe een
template om een zin vraagt, en hoe registers en de zijbalk hun woorden
vertaald krijgen. Automatisch vertalen raakt deze laag nergens.

Een sleutel per zin, een bestand per taal, één sjabloon. Geen tweede set
PHP-templates: dat zou elke toekomstige wijziging verdubbelen en garandeert
dat de twee uit elkaar lopen.

```php
AdminTranslator::trans('common.save')            // Opslaan / Save
AdminTranslator::trans('translate.action', ['language' => 'Engels'])
```

Sleutels heten `<scherm>.<ding>`, worden in de broncode geschreven en nooit
uit een verzoek of een databaserij gebouwd.

**Een ontbrekende sleutel rendert de Nederlandse tekst**, nooit een lege
string en nooit de ruwe sleutel. Nederlands is per constructie de complete
catalogus — het is de taal waarin dit CMS geschreven is. Een Engels scherm met
één Nederlands woord is een schoonheidsfoutje; een lege knop is een kapot CMS.
`Tests\Service\AdminLocaleTest` faalt zodra een sleutel wel in het Nederlands
bestaat en niet in het Engels, dus het foutje wordt in de suite gevonden en
niet op iemands scherm.

**De CMS-interface wordt nooit machinaal vertaald.** Dit zijn verzorgde
applicatieteksten. Automatisch vertalen geldt uitsluitend voor
*website-inhoud*, en de twee raken elkaar nergens.

## Hoe een template om een zin vraagt

```php
<?= admin_te('pages.title') ?>      // vertaald én ge-escaped, voor in markup
admin_t('pages.delete_confirm')     // de kale string, voor in een array
```

`admin/_translate.php`, ingesloten door `admin/_header.php` en door
`admin/_localized_fields.php`. Een scherm dat een sleutel nodig heeft vóór de
schil — in zijn `<title>` — sluit het bestand zelf in; het is
`require_once`-veilig.

Het bestaat om één reden: een template dat veertig tekens ceremonie per zin
moet schrijven blijft stilletjes onvertaald. Dat is precies wat er gebeurde —
de eerste versie van deze stap bouwde de hele machinerie en gebruikte hem op
vijf plekken.

## Hoeveel er vertaald is

**Alles wat een beheerder normaal gesproken leest.** Het hele adminpaneel —
84 schermen — plus wat de ruim 200 schrijf-endpoints terugmelden. De catalogi
tellen elk ongeveer 1900 sleutels.

Concreet: de gedeelde schil en alle zijbalklabels (ook die van de modules),
het dashboard, *Pagina's* en de pagina-editor, alle ~17 contentblok-editors,
*Contentblokken*, *Media*, *Formulieren* en *Inzendingen*, *Header &
navigatie*, *Footer*, *Site-instellingen* met al
zijn panelen,
*Vormgeving*, *Redirects*, *Gebruikers* en hun rechten, *Portfolio*,
*Contactaanvragen*, de installatiewizard, *Mijn account*, het tabblad *Talen*,
de opslagbalk, en de modules **Blog**, **Shop** en **Personalisatie**.
`<html lang>` volgt op elk adminscherm de CMS-taal.

Ook vertaald: **statuswoorden** (betaald, concept, gepubliceerd, geannuleerd)
en **validatiemeldingen** van de schrijf-endpoints. Bij een status verandert
alleen het wóórd op het scherm — de opgeslagen waarde blijft `paid`, in de
database, in elke query en in de CSV-export. Daar is geen migratie voor nodig
en er komt er ook geen.

## Wat bewust Nederlands blijft

Geen enkel scherm, maar drie soorten tekst die géén CMS-interface zijn:

| Wat | Waarom |
|---|---|
| Startinhoud die het CMS in de database schrijft — "Nieuwe sectie — pas deze titel aan" | dat is inhoud van de website, die een redacteur zelf overschrijft |
| Waarden uit *Shop-instellingen* — de bestelbevestigingsmail, de factuurteksten | dat is de tekst van de eigenaar, niet van het CMS |
| Protocolantwoorden — `Method not allowed`, `Invalid or missing CSRF token.` | die leest nooit iemand; ze zijn voor een misvormd verzoek |

`Tests\Service\MultilingualBoundaryTest::testNoAdminScreenPrintsADutchSentenceOfItsOwn`
en `…testNoAdminEndpointAnswersWithADutchSentenceOfItsOwn` bewaken dat: een
nieuw scherm dat zijn zinnen zelf uitschrijft laat de build vallen. De
uitzonderingen staan met reden in `DUTCH_ON_PURPOSE`, niet in een commentaar.

De regex die daarbij hoort kijkt naar **functiewoorden en CMS-werkwoorden**,
niet naar "elk Nederlands woord". Een eigennaam, een eenheid, een
bestandsextensie en een merknaam zijn allemaal legitieme literals in een
template, en een bewaker die die zou aanwijzen wordt binnen een week
uitgezet.

## Registers die hun eigen woorden meebrengen

Een blok, een permissie, een paginatemplate, een lettertypecombinatie en een
CMS-thema declareren hun naam en uitleg als gewone Nederlandse data in hun
eigen klasse. Vertaald wordt er **op de plek die ze afdrukt**, op de sleutel
die het register toch al heeft:

| Register | Sleutel | Vertaald in |
|---|---|---|
| Contentblokken | `block.<type>.label` / `.description` / `.use_case_N` | `BlockDefinition::label()`, `::describedFor()`, `::useCasesFor()` |
| Blokcategorieën | `blockcategory.<key>` | `BlockCategories::label()` |
| Permissies | `perm.<recht>.label` / `.description`, `perm.group.<naam>` | `AdminPermissions::groupsForDisplay()` |
| Paginatemplates | `pagetemplate.<key>.label` / `.description` | `admin_registry_label()` in de sjabloon |
| Lettertypes en CMS-thema's | `themefont.<key>`, `admintheme.<key>.*` | idem |
| Veldtypes van formulieren | `formfieldtype.<key>.label` / `.description` | `admin/_form_fields.php` |

Waarom daar en niet in de klasse zelf: zo hoeft een module niet te weten dat
dit CMS twee talen heeft. `App\Module\ShopModule` schrijft een Nederlands
label op en krijgt Engels zodra de sleutel bestaat, en een register zonder
sleutel houdt zijn eigen woorden in plaats van een kale punt-sleutel te tonen.

**Veldtypes van formulieren zijn de uitzondering.** Een veldtype komt nooit
uit een module, want de lijst is Core en gesloten. Daarom staan hun woorden
alleen in de catalogus en heeft de typeklasse geen Nederlandse naam die uit de
pas kan lopen. `Tests\Service\FormFieldTypeTest` eist voor elk geregistreerd
type een naam en uitleg in elke catalogus (`FORMS.md`, "Hoe een type heet").

**`AdminPermissions` vertaalt met opzet níét in `groups()`.** Die wordt
gelezen terwijl een account nog wordt geladen — `expand()` draait binnen
`AdminAuth::user()` — en vertalen daar zou `AdminLocale` om een taal vragen
vóórdat de sessie een account heeft. Dat levert de standaardtaal op, die
vervolgens het hele verzoek blijft hangen: het CMS staat dan in het Nederlands
terwijl de voorkeur Engels zegt. Alleen `groupsForDisplay()` vertaalt, en die
wordt pas aangeroepen als een formulier gaat afdrukken.

## De zijbalk

De labels staan nog steeds als gewone Nederlandse strings naast hun entry, in
`AdminNavigation::coreItems()` en in elke module. Vertaald wordt er één keer,
in `AdminNavigation::label()`, op de `key` van de entry: `nav.pages`,
`nav.orders`. Een module hoeft dus niet te weten dat dit CMS twee talen heeft,
en een entry zonder sleutel houdt zijn eigen label in plaats van een kale
punt-sleutel te tonen.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Taal van het CMS, per persoon | `src/Service/Language/AdminLocale.php`, kolom `admin_users.interface_language` |
| CMS-teksten | `src/Service/Language/AdminTranslator.php` + `src/Service/Language/messages/nl.php`, `messages/en.php` |
| CMS-tekst in een template | `admin/_translate.php` (`admin_t()` / `admin_te()`) |
| Labels van de zijbalk | `App\Service\AdminNavigation::label()` + de `nav.*`-sleutels |
| Scherm *Mijn account* | `admin/account.php`, `api/admin/update-account-preferences.php` |
