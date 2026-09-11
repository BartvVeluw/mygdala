# Meertaligheid

Welke talen dit CMS kent, wie ze kiest, hoe ze worden opgeslagen en hoe
automatisch vertalen werkt. Lees dit samen met `PROJECT-MAP.md` (waar iets
staat). Wijkt de code af van dit document, dan heeft de code gelijk — pas het
document aan.

## Drie talen die niets met elkaar te maken hebben

Dit is het model. Alles hieronder is uitwerking.

```text
1. CMS-taal            waarin het beheerpaneel aan JOU wordt getoond
                       per beheerder      admin_users.interface_language
                       AdminLocale        Mijn account

2. Bewerktaal          WELKE TAALVERSIE VAN DE WEBSITE-INHOUD je bewerkt
                       per beheerder      admin_users.content_editing_language
                       ContentEditingLanguage   de schakelaar in de CMS-schil

3. Bezoekerstaal       waarin een BEZOEKER de website leest
                       per bezoeker       localStorage `vvl-lang`
                       assets/js/core.js  de NL|EN-knoppen in de header
```

**Alle drie zijn onafhankelijk. Alle vier de combinaties van 1 en 2 moeten
kunnen, en kunnen:**

```text
CMS-taal = Nederlands   +   Bewerktaal = NL     Nederlands CMS, Nederlandse velden
CMS-taal = Nederlands   +   Bewerktaal = EN     Nederlands CMS, Engelse velden
CMS-taal = English      +   Bewerktaal = NL     Engels CMS, Nederlandse velden
CMS-taal = English      +   Bewerktaal = EN     Engels CMS, Engelse velden
```

En laag 3 beweegt met geen van beide mee. Een beheerder die zijn CMS op Engels
zet verandert niets aan wat een bezoeker op dat moment leest, en een beheerder
die naar de Engelse bewerktaal wisselt ook niet.

Dat is geen belofte maar een eigenschap van de code: het endpoint dat een
CMS-taal schrijft kan `site_settings` niet bereiken, het endpoint dat de
bewerktaal schrijft kan noch `site_settings` noch de CMS-taalkolom bereiken,
en de publieke taalwissel vraagt geen van beide iets.
`Tests\Service\MultilingualBoundaryTest` laat de build vallen zodra een van de
drie een van de andere noemt, en
`Tests\Service\ThreeLanguageStatesTest` loopt de hele matrix af.

### Wat hiervóór fout was

De eerste versie had laag 2 niet. "Welke taal bewerk ik" werd afgeleid uit de
**instellingen van de site** — `enabled_content_languages` — en die ene
instelling hing aan drie ongerelateerde dingen tegelijk:

- of de bezoeker in de header een taalwissel kreeg;
- of een redacteur überhaupt Engelse velden zag;
- of automatisch vertalen werd aangeboden.

Op een site waar die rij `nl` zei — de standaard — betekende dat: geen
taalwissel voor de bezoeker, en geen enkele manier om Engelse inhoud te
schrijven behalve de configuratie van de wébsite omzetten, die je met al je
collega's deelt. Dat is niet wat een redacteur bedoelde met "ik wil de Engelse
versie van deze pagina bewerken".

De correctie is niet een hernoeming: **de bewerktaal is nieuwe, eigen staat**
(een voorkeur van een persoon), en `enabled_content_languages` beslist
nergens meer iets.

## De taalwissel in het CMS

Eén schakelaar, in de schil, op élk adminscherm:

```text
CONTENT BEWERKEN
[ NL ] [ EN ]
```

Hij staat in `admin/_header.php`, direct onder de sitenaam, en hij POST't naar
`api/admin/update-content-language.php` met een CSRF-token zoals elke andere
schrijfactie hier. Daarna keert hij terug naar het scherm waar je was: welke
taal een formulier toont wordt op de **server** beslist, dus de pagina moet
opnieuw gerenderd worden — maar het is een taalwissel, geen navigatie.

**Geen tweede taalkiezer.** Bewerkschermen tónen alleen in welke taal je zit:

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.
```

Passief, één keer per scherm. Er stond vroeger een tabbladenrij per formulier,
en dat was precies de fout: de taalkeuze zat per scherm (dus opnieuw kiezen op
elke pagina die je opende) en was vanuit de rest van het CMS onzichtbaar. Twee
knoppen die het over dezelfde staat oneens kunnen zijn is erger dan één.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Talenregister (gesloten) | `src/Service/Language/LanguageRegistry.php` + `LanguageDefinition.php` |
| Talen van de website | `src/Service/Language/ContentLanguages.php` |
| Taal van het CMS, per persoon | `src/Service/Language/AdminLocale.php`, kolom `admin_users.interface_language` |
| Bewerktaal, per persoon | `src/Service/Language/ContentEditingLanguage.php`, kolom `admin_users.content_editing_language` |
| De schakelaar *Content bewerken* | `admin/_header.php`, `api/admin/update-content-language.php` |
| CMS-teksten | `src/Service/Language/AdminTranslator.php` + `messages/nl.php`, `messages/en.php` |
| Terugvalregel op één plek | `src/Service/Language/LocalizedValue.php` |
| Wat een publieke partial afdrukt | `src/Service/Language/SiteText.php` |
| Taalvelden in het CMS | `admin/_language_fields.php`, `admin/assets/admin-language-translate.js`, `.admin-lang-*` en `.admin-sidebar__contentlang*` in `admin/assets/admin.css` |
| CMS-tekst in een template | `admin/_translate.php` (`admin_t()` / `admin_te()`) |
| Labels van de zijbalk | `App\Service\AdminNavigation::label()` + de `nav.*`-sleutels |
| Scherm *Mijn account* | `admin/account.php`, `api/admin/update-account-preferences.php` |
| Tabblad *Talen* | `admin/settings.php`, `api/admin/update-language-settings.php` |
| Vertaalcontract | `src/Service/Translation/TranslationProvider.php` |
| Providers | `DeepLProvider.php`, `NullTranslationProvider.php`, `TranslationProviderFactory.php` |
| Vertaaldienst | `src/Service/Translation/TranslationService.php` |
| Vertaalstatus | `src/Service/Translation/TranslationState.php`, `src/Repository/TranslationStateRepository.php`, tabel `content_translation_state` |
| Vertaalendpoint | `api/admin/translate-fields.php` |
| Migraties | `db/migrations/20260910140000_add_multilingual_language_settings.php`, `…150000_create_the_translation_state_table.php`, `20260911100000_add_the_content_editing_language_column.php`, `20260911200000_correct_the_stored_content_languages.php` |
| Tests | `tests/Service/LanguageRegistryTest.php`, `LocalizedValueTest.php`, `AdminLocaleTest.php`, `ThreeLanguageStatesTest.php`, `TranslationProviderTest.php`, `MultilingualBoundaryTest.php`, `tests/Repository/LocalizedNavigationFooterPersistenceTest.php` |

## Het talenregister

Gesloten en in code geschreven, om dezelfde reden als `BlockDefinitions`,
`ModuleRegistry`, `ThemeFonts` en `SocialProfiles`: een taalcode komt uit een
adminformulier, uit een instellingenrij en uit een URL, en het enige wat zo'n
waarde ooit mag doen is een sleutel van die lijst raken of missen.

Dat is hier extra dragend. Een taalcode wordt aan een kolomnaam geplakt
(`title_` . `$code`), dus een code die uit een verzoek kon komen zou een
SQL-injectie zijn. Dat kan niet: elke lezer gaat eerst langs `::has()` of
`::get()`.

V1 registreert **Nederlands en Engels**, want dat is wat de bestaande
`_nl`/`_en`-kolommen kunnen opslaan. Per taal legt het register vast:

```text
code                          nl
nativeLabel                   Nederlands
dutchLabel / englishLabel     Nederlands / Dutch
deeplSource / deeplTarget     NL / NL
availableAsAdminLocale        ja
availableAsContentLanguage    ja
```

Duits toevoegen is later één regel in dit register plus opslag ervoor. Geen
editor, geen renderer en geen provider verandert mee — dat is waar de twee
`availableAs*`-vlaggen voor zijn: een taal mag in stappen binnenkomen, want
"er is een verzorgde CMS-vertaling" en "de site kan er inhoud in opslaan" zijn
twee verschillende vragen met twee verschillende antwoorden.

## De talen van de website

**Dit product is tweetalig. Nederlands en Engels zijn er altijd allebei** —
voor de bezoeker en voor de redacteur. Er is geen instelling die er een
weghaalt, want die instelling was het probleem.

Wat een site wél kiest, is welke van de twee de **standaard** is:

```text
primary_content_language     nl        (of: en)
```

Dat betekent twee dingen, en alleen die twee:

- een bezoeker die nog niets gekozen heeft krijgt deze taal;
- een ontbrekende vertaling valt op deze taal terug.

`site_settings` en niet `theme_settings`, om de reden die bovenaan
`THEMING.md` staat: dit is wie de site *is*, en "standaardvormgeving
herstellen" mag nooit de talen van een site meenemen.

De regels, allemaal op één plek (`ContentLanguages`):

- **De hoofdtaal staat altijd vooraan.** "Vooraan" is precies wat de publieke
  wissel, de standaard-bewerktaal en de terugvalregel er alle drie mee
  bedoelen.
- **Een onbekende code valt terug** op Nederlands in plaats van te weigeren.
  Een rij uit een nieuwere versie mag een redacteur nooit buitensluiten.
- **`enabled()` leest het register, niet een instellingenrij.** Elke taal
  waarin dit build inhoud kán opslaan is een taal die deze site publiceert.

### `enabled_content_languages` is deprecated

De rij bestaat nog, wordt nog geschreven en wordt nooit meer gelezen om iets
te beslissen.

| | |
|---|---|
| Gelezen door | `ContentLanguages::storedEnabled()`, alleen voor diagnose |
| Geschreven door | `ContentLanguages::normalise()`, altijd de volledige set |
| Beslist over de publieke taalwissel | **nee** |
| Beslist over de velden van een redacteur | **nee** |
| Beslist over automatisch vertalen | **nee** |

**Er wordt niets verwijderd.** Geen rij gewist, geen `_nl`/`_en`-kolom
aangeraakt, geen vertaling weggegooid. Een bestaande site met
`enabled_content_languages = nl` krijgt gewoon zijn taalwissel terug en zijn
redacteuren hun Engelse velden;
`Tests\Service\LanguageRegistryTest::testAStoredSingleLanguageRowNoLongerTakesEnglishAway`
is de test die dat vasthoudt, inclusief dat de rij zelf onveranderd blijft.

`MAX_ENABLED` blijft 2, want dat is wat de `_nl`/`_en`-kolommen kunnen
opslaan. Een derde taal is een register-entry plus opslag ervoor, niet een
instelling.

## De terugvalregel

Vroeger stond die zo in de code: "Nederlands is de inhoud, Engels optioneel,
leeg Engels betekent gelijk aan Nederlands" — en hij werd op ongeveer 280
plekken in `src/` met de hand toegepast. Dat klopte zolang Nederlands de enige
mogelijke hoofdtaal was.

Nu staat hij één keer, in `LocalizedValue`, en in termen van de **hoofdtaal**
in plaats van van het Nederlands:

```text
gevraagde taal   →  eigen waarde
leeg             →  de waarde van de hoofdtaal
```

Een ontbrekende vertaling toont dus de woorden van de hoofdtaal, nooit een
lege pagina. Voor een bezoeker verandert er niets; alleen de náám van de taal
waarop wordt teruggevallen kan nu anders zijn.

`LocalizedValue::raw()` bestaat naast `::in()` en is wat een **editor**-formulier
toont: een vertaalveld dat stilletjes de woorden van de hoofdtaal laat zien,
zou bij de eerstvolgende Opslaan als echte vertaling worden weggeschreven.

`App\Service\Forms\FormText` doet hetzelfde, één domein eerder, en blijft zoals
hij is: dat is een vast NL/EN-paar binnen Core Forms en hem omzetten zou een
Forms-refactor zijn, geen meertaligheidswijziging.

## De publieke website

**De taalwissel staat er altijd.** `NL | EN`, in de gedeelde header
(`partials/header.php`), op élke publieke route: de homepage, elke CMS-pagina,
de Blog en zijn berichten, de Shop, een product, een collectie, het
portfolio en de portfolio-detailpagina. Eén partial, geen enkele module die
zijn eigen wissel meebrengt.

Hij verscheen vroeger alleen op "een site met meer dan één taal", en dat was
precies de fout: een bezoeker van een site waar niemand Engels had
*aangezet* kreeg geen enkele manier om erom te vragen — ook niet als er
Engelse inhoud in de `_en`-kolommen stond.

- `partials/section-*.php` schrijven `data-nl`/`data-en` en
  `assets/js/core.js` wisselt ze in de browser. Die afspraak is ongewijzigd.
- Welke van de twee bij de eerste paint zichtbaar is, is de **standaardtaal**
  van de site, via `SiteText::visible()`.
- `<html lang>` volgt de standaardtaal en draagt daarnaast
  `data-primary-lang`, zodat `core.js` weet in welke taal de server de pagina
  al heeft afgedrukt en hem niet nodeloos overschrijft.
- De keuze van de bezoeker staat in `localStorage` onder `vvl-lang` en blijft
  staan terwijl hij doorklikt.

**Geen adminvoorkeur raakt hier iets.** Niet de CMS-taal van een beheerder,
niet zijn bewerktaal. Die staan op een rij in `admin_users`; dit leest
`site_settings` en de browser van de bezoeker.

**Applicatieteksten in de schil** — de winkelwagen, de 404, de foutmeldingen
van een formulier — dragen nog steeds een vast NL/EN-paar in de markup en
worden door `core.js` gewisseld. Een frontend-tekstcatalogus zoals het CMS die
nu heeft is bewust geen onderdeel van V1.

### Een ontbrekende vertaling: terugval voor de bezoeker, leeg voor de redacteur

Dit onderscheid draagt het hele model.

```text
                        Nederlands (hoofdtaal)   Engels
opgeslagen              "Neem contact op"        ""

bezoeker kiest EN   ->  "Neem contact op"        via LocalizedValue::in()
redacteur bewerkt EN -> leeg veld                via LocalizedValue::raw()
```

Een bezoeker krijgt nooit een lege knop of een lege kop. Een redacteur ziet
nooit woorden die hij niet geschreven heeft in een vertaalveld — want de
eerstvolgende Opslaan zou die als échte vertaling wegschrijven, en de site
zou het verschil tussen "vertaald" en "nog niet vertaald" kwijt zijn.

`LocalizedValue::isTranslated()` is dezelfde vraag, expliciet.

## Het CMS in het Nederlands of het Engels## Het CMS in het Nederlands of het Engels

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

### Hoe een template om een zin vraagt

```php
<?= admin_te('pages.title') ?>      // vertaald én ge-escaped, voor in markup
admin_t('pages.delete_confirm')     // de kale string, voor in een array
```

`admin/_translate.php`, ingesloten door `admin/_header.php` en door
`admin/_language_fields.php`. Een scherm dat een sleutel nodig heeft vóór de
schil — in zijn `<title>` — sluit het bestand zelf in; het is
`require_once`-veilig.

Het bestaat om één reden: een template dat veertig tekens ceremonie per zin
moet schrijven blijft stilletjes onvertaald. Dat is precies wat er gebeurde —
de eerste versie van deze stap bouwde de hele machinerie en gebruikte hem op
vijf plekken.

### Hoeveel er vertaald is

**Alles wat een beheerder normaal gesproken leest.** Het hele adminpaneel —
84 schermen — plus wat de ruim 200 schrijf-endpoints terugmelden. De catalogi
tellen elk ongeveer 1900 sleutels.

Concreet: de gedeelde schil en alle zijbalklabels (ook die van de modules),
het dashboard, *Pagina's* en de pagina-editor, alle ~17 contentblok-editors,
*Contentblokken*, *Media*, *Formulieren* en *Inzendingen*, *Navigatie*,
*Footer*, *Header & footer*, *Site-instellingen* met al zijn panelen,
*Vormgeving*, *Redirects*, *Gebruikers* en hun rechten, *Portfolio*,
*Contactaanvragen*, de installatiewizard, *Mijn account*, het tabblad *Talen*,
de opslagbalk, en de modules **Blog**, **Shop** en **Personalisatie**.
`<html lang>` volgt op elk adminscherm de CMS-taal.

Ook vertaald: **statuswoorden** (betaald, concept, gepubliceerd, geannuleerd)
en **validatiemeldingen** van de schrijf-endpoints. Bij een status verandert
alleen het wóórd op het scherm — de opgeslagen waarde blijft `paid`, in de
database, in elke query en in de CSV-export. Daar is geen migratie voor nodig
en er komt er ook geen.

### Wat bewust Nederlands blijft

Geen enkel scherm, maar drie soorten tekst die géén CMS-interface zijn:

| Wat | Waarom |
|---|---|
| Startinhoud die het CMS in de database schrijft — "Nieuwe sectie — pas deze titel aan" | dat is inhoud van de website, die een redacteur zelf overschrijft |
| Waarden uit *Site-instellingen* — de bestelbevestigingsmail, de factuurteksten | dat is de tekst van de eigenaar, niet van het CMS |
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

### Registers die hun eigen woorden meebrengen

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

Waarom daar en niet in de klasse zelf: zo hoeft een module niet te weten dat
dit CMS twee talen heeft. `App\Module\ShopModule` schrijft een Nederlands
label op en krijgt Engels zodra de sleutel bestaat, en een register zonder
sleutel houdt zijn eigen woorden in plaats van een kale punt-sleutel te tonen.

**`AdminPermissions` vertaalt met opzet níét in `groups()`.** Die wordt
gelezen terwijl een account nog wordt geladen — `expand()` draait binnen
`AdminAuth::user()` — en vertalen daar zou `AdminLocale` om een taal vragen
vóórdat de sessie een account heeft. Dat levert de standaardtaal op, die
vervolgens het hele verzoek blijft hangen: het CMS staat dan in het Nederlands
terwijl de voorkeur Engels zegt. Alleen `groupsForDisplay()` vertaalt, en die
wordt pas aangeroepen als een formulier gaat afdrukken.

### De zijbalk

De labels staan nog steeds als gewone Nederlandse strings naast hun entry, in
`AdminNavigation::coreItems()` en in elke module. Vertaald wordt er één keer,
in `AdminNavigation::label()`, op de `key` van de entry: `nav.pages`,
`nav.orders`. Een module hoeft dus niet te weten dat dit CMS twee talen heeft,
en een entry zonder sleutel houdt zijn eigen label in plaats van een kale
punt-sleutel te tonen.

## Bewerken in één taal tegelijk

Dit is waar de hele stap om begonnen is.

Een bewerkscherm toont de velden van **één** taal: die van de schakelaar in de
schil. Nooit twee kolommen, nooit een `(NL)`-achtervoegsel, nooit een tweede
taalkiezer op het scherm zelf.

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.

Titel        [ ............... ]
Introtekst   [ ............... ]
Knoptekst    [ ............... ]
```

De indicator staat **één keer per scherm**; de vertaalknop staat één keer per
**formulier**. Een detailsectie is zes kleine formulieren onder elkaar en elk
daarvan heeft zijn eigen vertaalknop nodig, want vertalen leest de velden van
het formulier waar de knop in staat. Zes keer "Je bewerkt: English" onder
elkaar is ruis, en de schil zegt het toch al.

### Hoe van taal wisselen niets weggooit

Dit is de belangrijkste eigenschap van het onderdeel. De schrijf-endpoints van
dit project schrijven **elke kolom van hun formulier bij elke opslag** — dat is
precies waarom een gedeeltelijke POST hier gevaarlijk is (`PAGE-EDITOR.md`).
Een veld dat gewoon uit de markup wordt weggelaten zou dus als leeg worden
opgeslagen, en de vertaling zou weg zijn.

Daarom staat het veld van de andere taal er nog steeds, met zijn opgeslagen
waarde, en het wordt nog steeds meegestuurd — het draagt alleen `hidden`, wat
het uit de weergave, uit de tabvolgorde én uit de toegankelijkheidsboom haalt.
**Geen van de ±77 schrijf-endpoints hoefde te veranderen**, en geen redacteur
kan een vertaling kwijtraken door van taal te wisselen.

Datzelfde verborgen paneel is ook waaróm automatisch vertalen werkt zonder
extra verzoek: de woorden om *uit* te vertalen staan al in hetzelfde
formulier.

`required` staat daarom alleen op de hoofdtaal **én alleen zolang de hoofdtaal
de taal op het scherm is**. Een verplicht en leeg veld binnen een `hidden`
element laat de browser weigeren te versturen zonder te kunnen aanwijzen wat
er mis is. Dat wordt nu op de server beslist bij het renderen, niet achteraf
door JavaScript weggehaald. De servervalidatie is ongewijzigd en blijft de
echte grens.

Een editor schrijft dat attribuut dus **nooit met de hand** in een taalveld;
hij vraagt het aan `admin_lang_required()`.
`Tests\Service\MultilingualBoundaryTest::testNoLocalizedFieldSpellsRequiredByHand`
loopt over elk scherm en valt op een letterlijke `required` naast een
`_nl`/`_en`-naam.

#### Dat verborgen paneel moet ook echt verborgen zijn

Dit is de regel waar Navigatie en Footer een tijd lang op stuk zijn gegaan, en
hij is de moeite van het opschrijven waard omdat hij in CSS woont en niet in
PHP.

`.admin-lang-pane` geeft het paneel een `display`, en een klasse die `display`
zet wint van de eigen `[hidden]{display:none}` van de browser. Zonder een
eigen `[hidden]`-regel stond het paneel van de taal die je *niet* bewerkt dus
gewoon naast dat van de taal die je wél bewerkt — twee tekstvelden onder
labels die met opzet niet meer zeggen welke taal ze zijn. Een redacteur die
net op **EN** had geklikt typte zijn vertaling in het Nederlandse veld, en de
opslag schreef precies wat het formulier meestuurde.

Het viel niet meteen op omdat de blok-editors hun `<form>` in
`.admin-product-form` zetten, en die component herstelt het attribuut voor zijn
eigen subtree. Navigatie, Footer, het portfolio en de personalisatiebouwer doen
dat niet — en dat was exact de lijst schermen waar de fout zichtbaar was.

```css
.admin-lang-pane{ display: block; }
.admin-lang-pane[hidden]{ display: none !important; }   /* hoort bij het paneel */
```

`Tests\Service\MultilingualBoundaryTest::testAHiddenLanguagePaneIsActuallyHidden`
laat de build vallen zodra die tweede regel verdwijnt.

#### Lezen, schrijven en terugvallen: de drie richtingen

Eén tabel, want de fout hierboven is precies wat er gebeurt als iemand ze door
elkaar haalt.

| | Wat er gebeurt |
|---|---|
| **Lezen voor een bezoeker** | gevraagde taal → leeg? dan de hoofdtaal. `LocalizedValue::in()`, via `SiteText` |
| **Lezen voor een redacteur** | de bewerktaal, **ruw**. Leeg is zichtbaar leeg. `LocalizedValue::raw()` |
| **Schrijven vanuit een editor** | alleen de bewerktaal krijgt een nieuwe waarde; de andere kolom gaat ongewijzigd mee terug naar de database |

**Een teruggevallen waarde wordt nooit opgeslagen.** Terugvallen is een
rendering-regel; zou een editorveld hem tonen, dan schreef de eerstvolgende
Opslaan hem weg als échte vertaling en was het verschil tussen "vertaald" en
"nog niet vertaald" weg.

Daaruit volgt ook wat een schrijf-endpoint mag eisen: **verplicht is alleen de
hoofdtaal**. Een vertaling is per definitie optioneel — de site valt terug —
en een endpoint dat beide talen eist maakt een scherm onopslaanbaar zodra de
helft ervan buiten beeld staat. De vraag wordt overal hetzelfde gesteld:

```php
if (LocalizedValue::ofDutchEnglish($nl, $en)->primaryValue() === '') {
    // pas hier is het veld echt leeg
}
```

### Een editor aansluiten

```php
require_once __DIR__ . '/_language_fields.php';   // bovenaan

admin_lang_bar();                                  // één keer per <form>

<?php admin_lang_pane_start('nl'); ?>
  <label>Titel<?= admin_lang_required('nl') ?> … </label>
<?php admin_lang_pane_end(); ?>
<?php admin_lang_pane_start('en'); ?>
  <label>Titel <input … <?= admin_lang_placeholder_attr('en') ?>></label>
<?php admin_lang_pane_end(); ?>

<?php admin_lang_script(); ?>                      // vóór </body>
```

`admin/_language_fields.php`, ingesloten door `admin/_header.php` en door de
schermen zelf. Een scherm dat een sleutel nodig heeft vóór de schil — in zijn
`<title>` — sluit het bestand zelf in; het is `require_once`-veilig.

`Tests\Service\MultilingualBoundaryTest` faalt als een editor met panelen de
component niet insluit, geen bar rendert of het script vergeet — en ook
andersom: als er ergens in `admin/` een `_nl`- of `_en`-veld buiten een paneel
staat.

Twee dingen die `php -l` niet ziet en de suite wel:

- een scherm dat `admin_lang_bar()` aanroept zonder `_language_fields.php`
  in te sluiten. Dat is een fatal op de eerste regel van zijn `<form>`.
- een label dat nog `(NL)` of `(EN)` achter zich draagt. Binnen een paneel
  zegt de indicator al in welke taal je zit.

### Meer dan één formulier op een scherm

Een scherm is niet altijd één formulier. Een portfolio-item heeft een klein
formulier per afbeelding, de footer heeft een "kolom toevoegen"-formulier
naast de lijst, en de blogtags zijn een tabel met een formulier per rij.

Dat is geen probleem meer, en dat is het verschil met de tabbladenversie: elk
paneel op elk formulier op elk scherm staat op dezelfde taal, omdat die taal
niet uit het scherm komt maar uit de beheerder. Er is niets te synchroniseren.

### En in de overzichten

`admin_lang_summary($rij, 'label')` geeft de naam in de hoofdtaal, met
terugval op wat er wél is ingevuld. De lijstschermen printten allebei de talen
naast elkaar — "Contact / Contact" — een naam plus een vertaling die niemand
in een lijst vroeg te zien.

Een overzicht volgt dus de **hoofdtaal van de site**, niet de bewerktaal: het
is een lijst van dingen, niet een bewerkscherm, en een rij die van naam
verspringt als je van bewerktaal wisselt is moeilijker terug te vinden.

## Automatisch vertalen

### De lagen

```text
editor (blok, pagina, formulier)
   → api/admin/translate-fields.php     login, permissie, methode, CSRF
   → TranslationService                 de vier regels
   → TranslationProvider                het contract
   → DeepLProvider | NullTranslation…   de implementatie
```

Geen enkele editor noemt DeepL. Een tweede provider — een andere API, een
LLM — is één klasse plus één regel in `TranslationProviderFactory::MAP`.

### Welke richting, en wanneer

De vertaalknop vult **de taal die je op dat moment bewerkt**, uit de andere.

```text
bewerktaal = EN   ->   [ Vertalen vanuit het Nederlands ]
bewerktaal = NL   ->   [ Vertalen vanuit het Engels ]
```

Nederlands → Engels is het geval waar V1 om draait; de omgekeerde richting
werkt omdat `TranslationService` een bróntaal aanneemt in plaats van altijd de
hoofdtaal te veronderstellen, en omdat de provider het paar aankan.

**Van taal wisselen vertaalt niets.** De schakelaar in de schil schrijft één
kolom en herrendert het scherm; er gaat geen enkel woord naar buiten. Vertalen
is altijd een expliciete klik.
`Tests\Service\ThreeLanguageStatesTest::testSwitchingEditingLanguageTranslatesNothing`
is de test die dat vasthoudt.

### De vier regels

1. **Handmatige bewerkingen winnen.** Een vertaling die een mens heeft
   geschreven wordt niet vervangen zonder een expliciete bevestiging. De
   beslissing staat op één plek (`TranslationState::mayOverwrite()`), dus de
   bevestiging die het scherm vraagt en de weigering die de server uitvoert
   kunnen niet uit elkaar lopen.
2. **Alleen de eigen velden van dit item gaan naar buiten.** Geen id, geen
   CSRF-token, geen instelling, geen andere pagina, geen gebruikersgegevens.
   Ook de veldnamen niet: DeepL krijgt de wóórden, niet de kolomnamen van dit
   CMS.
3. **Wat terugkomt is onbetrouwbaar.** De HTML van een provider gaat door
   dezelfde `RichTextSanitizer` als HTML die een redacteur zelf typt. Een
   vertaaldienst is een derde partij, geen autoriteit.
4. **Er wordt niets opgeslagen.** Het endpoint geeft de vertaling terug aan het
   scherm; de redacteur slaat op met de gewone Opslaan-knop van het formulier,
   via het gewone endpoint met de gewone validatie en het gewone CSRF-token.
   Een vertaalknop die rechtstreeks naar de database schreef zou een tweede,
   zwakkere schrijfweg zijn naar élke contenttabel.

### Vertaalstatus

Vier toestanden, en de tabel `content_translation_state` bewaart **geen
vertaalde tekst**. De vertaling blijft staan waar hij altijd stond: in de
`_en`-kolom van de rij zelf.

| Toestand | Wat het betekent |
|---|---|
| ontbreekt | niets vertaald |
| automatisch | een provider schreef het en niemand raakte het daarna aan |
| handmatig | een mens schreef of corrigeerde het |
| mogelijk verouderd | de brontekst is gewijzigd ná de vertaling |

*Verouderd* wordt **berekend, nooit opgeslagen**: het is "de bewaarde hash komt
niet meer overeen met de brontekst die ik nu zie". Opslaan zou betekenen dat
elke bewerking van een bronveld eraan moest denken zijn vertalingen ongeldig
te maken, en degene die dat vergat zou een verouderde vertaling als actueel
tonen.

De rij bewaart twee hashes:

```text
source_hash        de brontekst op het moment van vertalen  →  verouderd?
translation_hash   wat de provider terúggaf                 →  met de hand aangepast?
```

Die tweede is waarom "handmatige bewerkingen winnen" werkt **zonder dat één
van de ±77 schrijf-endpoints iets van vertalen hoeft te weten**: staat er in de
kolom niet meer de tekst waar die hash bij hoort, dan heeft iemand hem
herschreven, en automatisch vertalen blijft er vanaf. De tekst zelf meldt de
bewerking.

Een hash is van de **brontekst alleen** en normaliseert witruimte, zodat een
alinea opnieuw laten lopen niet elke vertaling verouderd maakt. `updated_at`
vergelijken zou dat wél doen: die verspringt ook als iemand een blok versleept.

### Rich text

Bij een rich-text-veld gaat `tag_handling=html` mee, zodat DeepL de markup
zelf ontleedt, de tekstknopen vertaalt en dezelfde structuur teruggeeft. Tags,
attributen en links blijven staan. Er wordt **nooit** markup gestript, platte
tekst vertaald en de opmaak daarna opnieuw opgebouwd — dat verliest elke link
en elke opmaakkeuze van de redacteur. Wat terugkomt gaat alsnog door de
sanitizer (regel 3).

Platte en opgemaakte velden gaan in **aparte verzoeken**: door elkaar zou de
provider iemands `<` als een tag lezen, of hun tags als woorden.

## DeepL instellen

Optioneel. Een verse Mygdala start zonder vertaalcredentials, elke editor
werkt, handmatige vertalingen werken, en het enige verschil is dat de
vertaalknoppen niet worden aangeboden.

```env
TRANSLATION_PROVIDER=deepl
DEEPL_API_KEY=
DEEPL_API_PLAN=free
```

- **De sleutel blijft server-side.** Hij wordt door PHP uit de omgeving
  gelezen; niets in `admin/`, `partials/` of `assets/` mag hem noemen, en
  `MultilingualBoundaryTest` faalt als dat verandert.
- **Free en Pro zijn verschillende hosts**, niet een parameter:
  `api-free.deepl.com` tegenover `api.deepl.com`. Een sleutel op de verkeerde
  host wordt geweigerd. DeepL merkt zijn eigen gratis sleutels met `:fx`, en
  zo'n sleutel wint van een `DEEPL_API_PLAN` die iemand vergeten is bij te
  werken.
- **Authenticatie** is de header die DeepL vandaag documenteert:
  `Authorization: DeepL-Auth-Key <sleutel>`. Nooit in de URL — een querystring
  belandt in proxylogs en in browsergeschiedenis.
- **Een mislukking logt de statusregel en het endpoint**, nooit de sleutel en
  nooit de tekst die vertaald werd. Het concept van een redacteur is geen
  logmateriaal.

De suite praat **nooit** met een echte vertaal-API: `tests/Support/FakeTranslationProvider.php`
en twee subklassen die de ene HTTP-methode vervangen dekken elke tak.

## Wat er met een bestaande site gebeurt

**Er wordt niets weggegooid en niets herschreven.**

| | |
|---|---|
| `_nl` / `_en`-kolommen | onaangeraakt, niet hernoemd, niet verwijderd |
| Bestaande NL-waarden | onaangeraakt |
| Bestaande EN-waarden | onaangeraakt |
| Kale Nederlandse kolommen | onaangeraakt |
| `content_translation_state` | onaangeraakt |
| `enabled_content_languages` | rij blijft staan; wordt niet meer gelezen om iets te beslissen |

Migratie `20260910140000` zette destijds de talen van bestaande sites vast.
Migratie `20260911100000` voegt één nullable kolom toe,
`admin_users.content_editing_language`, en verder niets. Forward-only en
additief, net als de vorige.

`NULL` betekent "deze persoon heeft niets gekozen", zodat een bestaande
beheerder geen voorkeur krijgt toegewezen die hij nooit heeft uitgesproken —
dezelfde afspraak als bij `interface_language`. Zonder keuze bewerk je de
standaardtaal van de website.

**Wat een bestaande site wél merkt**, en dat is de correctie zelf:

- de publieke `NL | EN`-wissel verschijnt weer, ook als
  `enabled_content_languages` `nl` zei;
- redacteuren kunnen weer Engelse inhoud schrijven, zonder de configuratie van
  de website om te zetten;
- de taaltabbladen in de editors zijn weg, vervangen door de ene schakelaar in
  de schil.


### De tabelnamen van `20260910140000` klopten niet

Die migratie besliste per database of zij `nl` of `nl,en` opsloeg, door een
lijstje tabellen af te tasten op Engelse inhoud. Twee van de negen namen in
dat lijstje bestaan niet in dit schema:

| Wat de migratie zei | Hoe de tabel heet |
|---|---|
| `navigation_items` | `nav_items` |
| `homepage_heroes` | `homepage_hero` |

De lus slaat een tabel over die `hasTable()` niet kent, dus beide missers
waren stil. En juist daar zet de generieke bootstrap zijn Engels neer, dus
een verse installatie sloeg `nl` op en beweerde eentalig te zijn.

Migratie `20260911200000_correct_the_stored_content_languages` herstelt dat.
Zij tast niets af: zij schrijft de volledige set die dit product publiceert,
hoofdtaal eerst — precies wat `ContentLanguages::normalise()` schrijft zodra
een eigenaar zelf iets opslaat. Daarmee kan dezelfde fout niet terugkomen,
want er staat geen tabelnaam meer in.

`20260910140000` blijft staan zoals zij gedraaid heeft. Zij is al toegepast
op echte installaties, dus haar geschiedenis blijft eerlijk en de nieuwe
migratie corrigeert de staat die zij achterliet. Vers of bijgewerkt: beide
eindigen op `nl,en`.

Twee tests houden dit vast.
`Tests\Install\MigrationTableNamesTest` vergelijkt elke tabelnaam die een
migratie uitspreekt met de namen die de migraties aanmaken — een nieuwe
typefout in een `hasTable()` of in een `'tabel' => 'kolom_en'`-lijstje faalt
daar, en de twee bestaande missers staan er met naam en toenaam in.
`Tests\Install\ContentLanguageSettingRepairTest` draait de kapotte migratie
echt, tegen een database vanaf nul, en controleert daarna de uitkomst voor
een verse installatie, voor een bijgewerkte, voor Engels dat alleen in het
menu staat, voor Engels dat alleen in de hero staat, voor een site zonder
Engels en voor een database zonder inhoud.

## Wat V1 bewust niet doet## Wat V1 bewust niet doet

- **Geen `/en/`- of `/nl/`-URL's en geen hreflang.** Beide talen wonen op één
  URL en wisselen in de browser, precies zoals eerder (`SEO.md`). Een
  Multilingual V2 kan gelokaliseerde URL's introduceren zodra het
  inhoudsmodel zich bewezen heeft; dit is uitgesteld en niet vergeten.
- **Geen taaldetectie op IP of browser.** Een bezoeker krijgt de hoofdtaal van
  de site, tenzij hij zelf wisselt.
- **Geen generieke vertaaltabellen.** De bestaande kolommen blijven de opslag.
  Het lange-termijnmodel mag daar later heen; V1 vereist die migratie niet.
- **Geen slugs, e-mailadressen, telefoonnummers, bestandsnamen, mediapaden,
  CSS, code, SKU's, id's, module-instellingen of gebruikersnamen vertalen.**
- **Geen vertaaldashboard** en geen hele-site-bulkvertaling. De provider-laag
  is er wel op gebouwd: een toekomstige "vertaal alles wat nog ontbreekt" is
  een nieuwe aanroeper van dezelfde `TranslationService`.
- **Geen tien talen in de editors.** Twee, met een register dat er meer aankan.
- **Geen instelling die een taal uitzet.** Dit product is NL + EN. De rij
  `enabled_content_languages` bestaat nog voor databasecompatibiliteit en
  beslist nergens meer iets.
- **Geen automatische vertaling van de CMS-interface zelf.**

## Testen

Commando's en tiers staan in [`TESTING.md`](TESTING.md).

```bash
docker exec vvld_php      php vendor/bin/phpunit --testsuite fast
docker exec vvld_php_test php vendor/bin/phpunit --testsuite cms
```

| Wijziging | Draai |
|---|---|
| Talenregister, sitetalen, terugvalregel | `fast` |
| CMS-taal, de catalogi, een nieuwe sleutel | `fast` |
| De bewerktaal, of de onafhankelijkheid van de drie | `fast` |
| Vertaalprovider, vertaalstatus, DeepL | `fast` |
| Een editor aansluiten op de taalvelden | `fast` → `blocks` |
| Instellingen-, account- of wizardscherm | `fast` → `cms` |
| Migratie of wat een verse installatie krijgt | `migration` |

Alle zes de bestanden zitten in `fast`: geen database, geen webserver, geen
netwerk.

`ThreeLanguageStatesTest` loopt de matrix af — CMS NL/EN × bewerktaal NL/EN —
en controleert per combinatie dat de CMS-labels de interfacetaal volgen, dat
de editor naar de gekozen contenttaal wijst, dat de andere taal bewaard
blijft, en dat geen van beide voorkeuren de bezoeker raakt.

`MultilingualBoundaryTest` bewaakt de grenzen — de API-sleutel, de
onafhankelijkheid van de drie taalstaten, dat het vertaalendpoint niets
schrijft, dat de publieke taalwissel niet achter een instelling zit, dat
`::enabled()` de deprecated rij niet leest, dat er geen `_nl`/`_en`-kolom
verdwijnt en dat er geen hreflang binnensluipt.
