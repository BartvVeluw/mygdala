# Meertaligheid

Welke talen dit CMS kent, wie ze kiest, hoe ze worden opgeslagen en hoe
automatisch vertalen werkt. Lees dit samen met `PROJECT-MAP.md` (waar iets
staat). Wijkt de code af van dit document, dan heeft de code gelijk — pas het
document aan.

## Drie vragen die niets met elkaar te maken hebben

Vóór deze stap waren ze één ding, en dat was precies het probleem. Elke
redacteur zag overal een Nederlands én een Engels veld, ook op een site die
alleen Nederlands is. Dat is per tekst twee velden lezen, twee velden
overslaan en één veld waarvan je je afvraagt of je er iets mee moet.

```text
Taal van het CMS          ≠   Hoofdtaal van de website   ≠   Tweede taal
per persoon                   per site                       per site, optioneel
admin_users                   site_settings                  site_settings
AdminLocale                   ContentLanguages               ContentLanguages
Mijn account                  Instellingen → Talen           Instellingen → Talen
```

**Deze combinatie moet kunnen, en kan:**

```text
Taal van het CMS      = English
Hoofdtaal website     = Nederlands
Tweede taal           = English
```

**En deze ook:**

```text
Taal van het CMS      = Nederlands
Hoofdtaal website     = English
Geen tweede taal
```

De taal van het CMS wijzigen verandert **nooit** een letter publieke inhoud,
en andersom. Dat is geen belofte maar een eigenschap van de code: het endpoint
dat een persoonsvoorkeur schrijft kan `site_settings` niet bereiken, en het
endpoint dat de sitetalen schrijft kan `admin_users` niet bereiken.
`Tests\Service\MultilingualBoundaryTest` laat de build vallen zodra een van de
twee de ander noemt.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Talenregister (gesloten) | `src/Service/Language/LanguageRegistry.php` + `LanguageDefinition.php` |
| Talen van de website | `src/Service/Language/ContentLanguages.php` |
| Taal van het CMS, per persoon | `src/Service/Language/AdminLocale.php`, kolom `admin_users.interface_language` |
| CMS-teksten | `src/Service/Language/AdminTranslator.php` + `messages/nl.php`, `messages/en.php` |
| Terugvalregel op één plek | `src/Service/Language/LocalizedValue.php` |
| Wat een publieke partial afdrukt | `src/Service/Language/SiteText.php` |
| Taaltabbladen in het CMS | `admin/_language_fields.php`, `admin/assets/admin-language-tabs.js`, `.admin-lang-*` in `admin/assets/admin.css` |
| CMS-tekst in een template | `admin/_translate.php` (`admin_t()` / `admin_te()`) |
| Labels van de zijbalk | `App\Service\AdminNavigation::label()` + de `nav.*`-sleutels |
| Scherm *Mijn account* | `admin/account.php`, `api/admin/update-account-preferences.php` |
| Tabblad *Talen* | `admin/settings.php`, `api/admin/update-language-settings.php` |
| Vertaalcontract | `src/Service/Translation/TranslationProvider.php` |
| Providers | `DeepLProvider.php`, `NullTranslationProvider.php`, `TranslationProviderFactory.php` |
| Vertaaldienst | `src/Service/Translation/TranslationService.php` |
| Vertaalstatus | `src/Service/Translation/TranslationState.php`, `src/Repository/TranslationStateRepository.php`, tabel `content_translation_state` |
| Vertaalendpoint | `api/admin/translate-fields.php` |
| Migraties | `db/migrations/20260910140000_add_multilingual_language_settings.php`, `…150000_create_the_translation_state_table.php` |
| Tests | `tests/Service/LanguageRegistryTest.php`, `LocalizedValueTest.php`, `AdminLocaleTest.php`, `TranslationProviderTest.php`, `MultilingualBoundaryTest.php` |

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

Twee sleutels in `site_settings`, en `ContentLanguages` is de enige plek die
er regels over kent:

```text
primary_content_language     nl
enabled_content_languages    nl        (of: nl,en)
```

`site_settings` en niet `theme_settings`, om de reden die bovenaan
`THEMING.md` staat: dit is wie de site *is*, en "standaardvormgeving
herstellen" mag nooit de talen van een site meenemen.

De regels, allemaal op één plek:

- **De hoofdtaal staat altijd in de lijst**, wat de rij ook zegt. Een site die
  niets publiceert is geen toestand die dit CMS kan renderen, en een
  instellingenrij mag er geen kunnen maken.
- **Een onbekende code valt terug** op Nederlands in plaats van te weigeren.
  Een rij uit een nieuwere versie mag een redacteur nooit buitensluiten.
- **V1 kent één tweede taal** (`MAX_ENABLED = 2`). Dat plafond staat hier en
  nergens anders, dus het later optrekken is een wijziging aan deze klasse:
  alles erachter vraagt al om een *lijst* en loopt eroverheen.

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

## Wat er op de publieke site verandert

**Bijna niets, en op een Nederlandse site letterlijk niets.**

- `partials/section-*.php` schrijven nog steeds `data-nl`/`data-en` en
  `assets/js/core.js` wisselt ze nog steeds in de browser. Die afspraak is
  ongewijzigd.
- Wat wél veranderde is *welke van de twee zichtbaar is*: de hoofdtaal, via
  `SiteText::visible()`. Op een Nederlandse site levert dat byte-voor-byte op
  wat er eerst stond.
- `<html lang>` volgt de hoofdtaal, en draagt daarnaast
  `data-primary-lang`, zodat `core.js` weet in welke taal de server de pagina
  al heeft afgedrukt en hem niet nodeloos overschrijft.
- **De taalwissel in de header verschijnt alleen op een site met meer dan één
  taal.** Op een eentalige site waren het twee knoppen die allebei dezelfde
  pagina in dezelfde woorden toonden.

**Applicatieteksten in de schil** — de winkelwagen, de 404, de foutmeldingen
van een formulier — dragen nog steeds een vast NL/EN-paar in de markup en
worden door `core.js` gewisseld. Een frontend-tekstcatalogus zoals het CMS die
nu heeft is bewust geen onderdeel van V1.

## Het CMS in het Nederlands of het Engels

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

## Bewerken met één taal

Dit is waar de hele stap om begonnen is.

**Eén sitetaal** — de editor toont de velden van de hoofdtaal en verder niets.
Geen tabbladen, geen `(NL)`-achtervoegsels, geen tweede kolom:

```text
Titel
Introtekst
Knoptekst
```

**Twee sitetalen** — één tabbladenrij per formulier, die álle vertaalbare
velden tegelijk omzet:

```text
[ Nederlands ] [ English • ]

Titel
Introtekst
Knoptekst
```

Niet één rij tabbladen per veld: een scherm met acht tabbladenrijen is
onleesbaarder dan de twee kolommen die het verving. Het bolletje op een
tabblad betekent "in deze taal ontbreekt iets wat de hoofdtaal wel heeft".

### Hoe een taal uitzetten niets weggooit

Dit is de belangrijkste eigenschap van het onderdeel. De schrijf-endpoints van
dit project schrijven **elke kolom van hun formulier bij elke opslag** — dat is
precies waarom een gedeeltelijke POST hier gevaarlijk is (`PAGE-EDITOR.md`).
Een veld dat gewoon uit de markup wordt weggelaten zou dus als leeg worden
opgeslagen, en de vertaling zou weg zijn.

Daarom staat het veld er nog steeds, met zijn opgeslagen waarde, en het wordt
nog steeds meegestuurd — het draagt alleen `hidden`, wat het uit de weergave,
uit de tabvolgorde én uit de toegankelijkheidsboom haalt. **Geen van de ±77
schrijf-endpoints hoefde te veranderen**, en geen redacteur kan een vertaling
kwijtraken door een instelling om te zetten.

`required` staat daarom **alleen ooit op de hoofdtaal**. Een verplicht en leeg
veld binnen een `hidden` element laat de browser weigeren te versturen zonder
te kunnen aanwijzen wat er mis is; `admin-language-tabs.js` haalt `required`
weg zolang een paneel het verborgen paneel is. De servervalidatie is
ongewijzigd en blijft de echte grens.

### Een editor aansluiten

```php
require_once __DIR__ . '/_language_fields.php';   // bovenaan

admin_lang_tabs();                                 // één keer per <form>

<?php admin_lang_pane_start('nl'); ?>
  <label>Titel<?= admin_lang_required('nl') ?> … </label>
<?php admin_lang_pane_end(); ?>
<?php admin_lang_pane_start('en'); ?>
  <label>Titel <input … <?= admin_lang_placeholder_attr('en') ?>></label>
<?php admin_lang_pane_end(); ?>

<?php admin_lang_tabs_script(); ?>                 // vóór </body>
```

`Tests\Service\MultilingualBoundaryTest` faalt als een editor met panelen de
component niet insluit, geen tabbladenrij rendert of het script vergeet — en,
sinds de browsertest die deze stap uitlokte, **ook andersom**: als er ergens in
`admin/` een `_nl`- of `_en`-veld buiten een paneel staat. Dat is de test die
er eerst niet was. Alle oude controles keken alleen naar schermen die de
component al gebruikten, dus precies de schermen die nooit waren omgebouwd —
de pagina-editor, het tekstblok, de blog-instellingen, de portfoliocategorieën,
de footerkolommen, de personalisatiebouwer — werden nooit bekeken en toonden
nog steeds twee kolommen.

Twee dingen die `php -l` niet ziet en de suite nu wel:

- een scherm dat `admin_lang_tabs()` aanroept zonder `_language_fields.php`
  in te sluiten. Dat is een fatal op de eerste regel van zijn `<form>`, en
  rendert als een halve pagina zonder melding. `admin/rich-text.php` deed dat.
- een label dat nog `(NL)` of `(EN)` achter zich draagt. Binnen een paneel
  zegt het tabblad al in welke taal je zit, en op een eentalige site is het
  een vraag over een taal die de site niet publiceert.

### Meer dan één formulier op een scherm

Een scherm is niet altijd één formulier. Een portfolio-item heeft een klein
formulier per afbeelding, de footer heeft een "kolom toevoegen"-formulier
naast de lijst, en de blogtags zijn een tabel met een formulier per rij. Zes
tabbladenrijen op één scherm zou precies het probleem zijn dat deze component
oplost, dus:

- een formulier **zonder** eigen tabbladenrij volgt de rij die er wél is;
- alle rijen op één scherm staan op dezelfde taal. Wie bovenaan naar Engels
  wisselt en verderop een Nederlands veld tegenkomt, krijgt te horen dat het
  CMS de draad kwijt is.

### En in de overzichten

`admin_lang_summary($rij, 'label')` geeft de naam in de hoofdtaal, met
terugval op wat er wél is ingevuld. De lijstschermen printten allebei de talen
naast elkaar — "Contact / Contact" — wat op een eentalige site één naam twee
keer is.

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

Migratie `20260910140000` beslist **per database** in plaats van één standaard
voor twee verschillende vragen te laten gelden:

```text
database met echte Engelse inhoud   →  primary=nl, enabled=nl,en   (blijft tweetalig)
database zonder                     →  primary=nl, enabled=nl      (wordt eentalig)
verse installatie                   →  geen rijen, dus de code-standaard: één taal
```

Dezelfde vorm als `20260909210000` (merk) en `20260910110000`
(bedrijfsgegevens): zet het huidige gedrag als échte rijen vast vóórdat een
generieke standaard het overneemt, met `INSERT IGNORE`, zodat een rij die een
eigenaar al geschreven heeft altijd wint.

**Geen enkele `_nl`- of `_en`-kolom wordt aangeraakt, hernoemd of verwijderd**,
en er wordt geen inhoud herschreven. De kolom `admin_users.interface_language`
is nullable zonder standaard: `NULL` betekent "deze persoon heeft niets
gekozen", zodat een bestaande beheerder geen voorkeur krijgt toegewezen die
hij nooit heeft uitgesproken.

## Wat V1 bewust niet doet

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
| Vertaalprovider, vertaalstatus, DeepL | `fast` |
| Een editor aansluiten op de taaltabbladen | `fast` → `blocks` |
| Instellingen-, account- of wizardscherm | `fast` → `cms` |
| Migratie of wat een verse installatie krijgt | `migration` |

Alle vijf de bestanden zitten in `fast`: geen database, geen webserver, geen
netwerk. `MultilingualBoundaryTest` bewaakt de grenzen — de API-sleutel, de
onafhankelijkheid van de twee taalinstellingen, dat het vertaalendpoint niets
schrijft, dat er geen `_nl`/`_en`-kolom verdwijnt en dat er geen hreflang
binnensluipt.
