# Multilingual 2.0: architectuur

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). De contracten van
Multilingual 2.0 die **vastliggen**, wat er per fase al gebouwd is, en de
randvoorwaarden die latere fases moeten volgen. Bron is de architectuuraudit
van fase 0/0B (niet in de repository). Wat hier staat is de afspraak, en de
code heeft gelijk als die afwijkt.

De rest van `docs/multilingual/` beschrijft V1: beide talen in de HTML, een
wissel in de browser en `_nl`/`_en`-kolommen. Dat model blijft werken tot de
frontend-flip in fase 7. Wat 2.0 al vervangen heeft, staat hieronder.

## Stand

| Fase | Inhoud | Status |
|---|---|---|
| 1 | Taalkern: talenregister, standaardtaal, Core-API, V1-adapter | **gebouwd** |
| 2 | Gelokaliseerde velden (editorcomponent) + Pages | **gebouwd** |
| 3 | Contentblokken | gepland |
| 4 | Navigatie, footer, instellingen, formulieren | gepland |
| 5 | Modules: Portfolio, Blog, Shop, Personalisatie | gepland |
| 6 | Dunne dispatcher en schone URL's, nog eentalig | gepland |
| 7 | Server-side taalweergave, de module `multilingual`, de wisselaar, SEO | gepland |

Zolang fase 7 er niet is, ziet een bezoeker niets van 2.0.

## CMS-taal is geen websitetaal

Drie begrippen, drie eigenaren. Geen van de drie leest of schrijft de opslag
van een ander.

| | Waarvoor | Per | Opslag | Code |
|---|---|---|---|---|
| CMS-taal | waarin een beheerder het CMS ziet | beheerder | `admin_users.interface_language` | `AdminLocale` (eigen lijst: nl, en) |
| Websitetaal | welke talen de site publiceert en welke de standaard is | site | `site_languages` | `SiteLanguages` |
| Bewerktaal | welke taalversie een redacteur nu bewerkt | beheerder | `admin_users.content_editing_language` | `ContentEditingLanguage` |

Een websitetaal wordt dus nooit vanzelf een CMS-taal: Duits op de website geeft
het CMS geen Duitse interface. `MultilingualBoundaryTest` en
`SiteLanguagesTest` bewaken dat in beide richtingen.

## Het talenregister

Eén tabel, `site_languages`, gemaakt door migratie `20260917120000`.

| Kolom | Betekenis |
|---|---|
| `code` | taalcode, uniek, `ascii_bin` (hoofdlettergevoelig) |
| `name`, `native_name` | Engelse naam en eigen naam (`Dutch` / `Nederlands`) |
| `is_default` | `1` voor de standaardtaal, `NULL` voor alle andere, **nooit `0`** |
| `is_active` | aan of uit |
| `sort_order` | volgorde; bij gelijke waarde beslist `id` |

**Codes.** `LanguageCode` beslist wat een code is: in V1 precies twee kleine
letters, het patroon `\A[a-z]{2}\z`. Dat is een vorm en geen lijst: `es`, `pt`,
`pl`, `sv`, `da` en `cs` zijn net zo geldig als `nl`, zonder dat PHP ze ergens
noemt. Een taal toevoegen is een rij, geen codewijziging. Hoofdletters en
spaties eromheen worden vergeven, al het andere wordt geweigerd: `pt-BR`,
`en_GB`, `zh-Hans`, drie letters, cijfers, paden en markup. Regionale codes,
schrifttypen en RTL vallen bewust buiten V1. De kolom is 12 tekens breed, dus
die later toestaan wijzigt die klasse en niet het schema.
`MultilingualBoundaryTest` faalt zodra de kern een taalcode of taalnaam in
code noemt.

**De invarianten, en waar ze staan.** MySQL 5.7 kent geen partiële index en
dwingt `CHECK` niet af, en een trigger vraagt rechten die gedeelde hosting
niet altijd geeft. Daarom:

| Invariant | Afgedwongen door |
|---|---|
| hoogstens één standaardtaal | `UNIQUE` op `is_default`: meerdere `NULL`s mogen, één `1` |
| code uniek | `UNIQUE` op `code` |
| de standaardtaal is actief | `SiteLanguageRepository::setDefault()` kiest alleen een actieve rij |
| de standaardtaal wordt niet uitgezet of verwijderd | `deactivate()` en `delete()` matchen nooit de standaardrij (`is_default IS NULL` in de SQL) |
| precies één standaardtaal | de migratie zet er één neer; `SiteLanguages::defaultLanguage()` gooit een fout als het er niet precies één actieve is |
| niemand omzeilt de repository | `MultilingualBoundaryTest`: geen andere SQL noemt de tabel |

Een nieuwe taal wordt nooit als standaard aangemaakt. Alleen `setDefault()`
verplaatst de standaard.

## De Core-API

`App\Service\Language\SiteLanguages`, één keer per request gelezen:

| Methode | Antwoord |
|---|---|
| `all()` | alle talen, op `sort_order` |
| `active()`, `activeCodes()` | de actieve talen, in volgorde |
| `defaultLanguage()`, `defaultCode()` | de standaardtaal; een fout bij een kapot register |
| `find($code)`, `exists($code)`, `isActive($code)` | opzoeken; code wordt eerst genormaliseerd |
| `setDefault($code)` | standaard verplaatsen; neemt deel aan een open transactie |

De klasse kent geen taal bij naam en vraagt geen module na. Is het register
onleesbaar, dan logt ze dat één keer en is het register leeg.
`SiteLanguageFixture` (tests) vervangt het in het geheugen.

## De standaardtaal: één bron

De standaardtaal is **`site_languages.is_default`**, en niets anders.

- **Bestaande installatie.** De migratie leest
  `site_settings.primary_content_language` en maakt twee rijen: die taal als
  standaard op `sort_order` 0, de andere taal op 1, beide actief. Staat er
  niets of iets onbekends, dan wordt het Nederlands. Een NL-site krijgt
  `nl` (standaard) + `en`, een EN-site `en` (standaard) + `nl`. Geen Duits, Frans
  of Italiaans. Daarna verdwijnen `primary_content_language` en
  `enabled_content_languages` uit `site_settings`: hun informatie staat nu in
  het register. Geen `_nl`/`_en`-kolom wordt aangeraakt.
- **Verse installatie.** Dezelfde migratie maakt `nl` (standaard) + `en`. De
  installatiewizard verplaatst de standaard naar de gekozen taal, in dezelfde
  transactie als de rest van de wizard.
- **Opnieuw draaien** voegt niets toe: een gevuld register blijft zoals het
  is, ook als de eigenaar de standaard intussen verplaatst heeft.
- **Schrijven** gaat via één methode, `ContentLanguages::savePrimary()`,
  voor het tabblad *Talen* en voor de wizard.
- **De wizard leest de gekozen taal** via `SetupWizard::websiteLanguage()`:
  de vorm via `LanguageCode`, wat V1 kan publiceren via de adapter. Het
  scherm gebruikt dezelfde methode voor de beginwaarde van de keuzelijst.
  Nooit via `AdminLocale`: die bepaalt alleen in welke taal het CMS zelf
  getoond wordt. Alleen de schermen van de CMS-taal mogen een taal via
  `AdminLocale` valideren, en `MultilingualBoundaryTest` bewaakt dat.

## De V1-adapter

Tot fase 7 verwacht de productiecode het vaste paar NL/EN.
`ContentLanguages` is de kleinste brug:

- **`primary()` leest het register**, maar alleen een taal die de
  `_nl`/`_en`-kolommen kunnen opslaan. Is dat niet zo, of is het register
  onleesbaar, dan geeft hij Nederlands, zoals een onbekende instelling vroeger.
- **`enabled()` blijft V1**: altijd NL en EN, de hoofdtaal eerst, uit het
  gesloten `LanguageRegistry`. `is_active` in het register verbergt tot fase 7
  geen wissel en geen veld.
- `SiteText`, `LocalizedValue`, `data-nl`/`data-en`, `applyLang()` en de
  `data-lang-html`-regel (platte tekst via `textContent`, alleen gemarkeerde
  HTML via `innerHTML`) zijn **ongewijzigd**.

Er is geen dual-read: niets leest nog een instellingenrij voor de taal.

Sinds fase 2 leest Pages zijn tekst niet meer uit `_nl`/`_en`-kolommen; de
`data-nl`/`data-en`-paren van een pagina komen uit
`PageLocalization::bilingual()` (hieronder). De rest van de site is nog V1.

## Pagina's per taal (fase 2)

Pages is het eerste contentdomein op de nieuwe opslag. De tekst van een pagina
staat niet meer in `pages`, maar per websitetaal in `page_translations`.

### De tabel

| Kolom | Betekenis |
|---|---|
| `page_id` | de pagina; FK op `pages.id`, `ON DELETE CASCADE` |
| `language_code` | de taal; FK op `site_languages.code`, `ON DELETE RESTRICT`, zelfde type (`ascii_bin`, 12 tekens) |
| `title` | de naam van de pagina: kruimelpad, automatische `<title>`, naam in het CMS |
| `meta_title` | de volledige `<title>` als hij gezet is |
| `meta_description` | de omschrijving voor zoekmachines |
| `created_at`, `updated_at` | zoals elke tabel hier |

`UNIQUE(page_id, language_code)`: één rij per pagina per taal, ook voor de
standaardtaal. Een tekstveld zonder woorden is `NULL`. Een taal zonder één
woord heeft **geen rij**; dat is hetzelfde als "niet vertaald".

Een taal verwijderen die nog paginatekst heeft, weigert de database. Een taal
uitzetten wist niets. Een derde taal is een rij in `site_languages`: geen
schemawijziging, geen code.

**Wat bewust in `pages` blijft:** `slug`, `status`, `route_path`,
`content_key`, `noindex`, `show_breadcrumb` en de deelafbeelding. Die horen bij
de pagina, niet bij een taal. Een slug of publicatie per taal komt pas als de
routing hem gebruikt (fase 6); nu zou het een tweede bron van waarheid zijn
die niemand leest.

### De migratie

`20260917140000` maakt de tabel. `20260917150000` verhuist de tekst en dropt
de zes oude kolommen in dezelfde stap:

| Oude kolommen | Naar |
|---|---|
| `title`, `meta_title`, `meta_description` | rij `nl` |
| `title_en`, `meta_title_en`, `meta_description_en` | rij `en` |

De kolommen houden de betekenis die V1 ze gaf, wat de standaardtaal ook is.
Een waarde met woorden wordt byte voor byte gekopieerd. `NULL`, `''` en alleen
spaties worden `NULL`, en een taal zonder woorden krijgt geen rij. Id's, slugs
en status blijven ongemoeid. Opnieuw draaien doet niets. Staat er tekst in een
taal die het register niet heeft, dan stopt de migratie vóór de drop, met een
melding.

Geen dual-read, geen dual-write: de code van dezelfde commit leest en schrijft
alleen de nieuwe tabel.

### De Pages-API

`App\Service\PageLocalization` is de enige lezer en schrijver van
`page_translations`. `App\Repository\PageTranslationRepository` heeft de SQL
en wordt door niets anders aangeroepen. `App\Service\PageTranslation` is één
rij.

| Methode | Antwoord |
|---|---|
| `translations($id)`, `translation($id, $code)`, `has($id, $code)` | wat er per taal is opgeslagen |
| `raw($id, $veld, $code)` | de opgeslagen woorden, **zonder** terugval: voor een redacteur |
| `value($id, $veld, $code)`, `title($id, $code)` | met terugval: voor een bezoeker |
| `name($id)` | hoe het CMS een pagina noemt: de titel in de standaardtaal |
| `preload($ids)` | alle tekst van een lijst pagina's in één query |
| `save($id, $code, $velden)` | één taal opslaan; weigert een taal die het register niet heeft |
| `defaultLanguage()` | de standaardtaal waarop alles terugvalt |
| `bilingual($id, $veld)` | de tijdelijke NL/EN-adapter, zie hieronder |

Leest nooit met een fout: een mislukte lookup wordt gelogd en leest als "geen
tekst". Schrijven gooit wel een fout.

### Het terugvalcontract

Per veld, voor gewone paginatekst:

1. de gevraagde taal;
2. de standaardtaal;
3. leeg.

Dat staat in `PageLocalization::value()` en nergens anders. Het kruimelpad, de
SEO-kop, de editor en de templates vragen de standaardtaal niet zelf op;
`MultilingualBoundaryTest` bewaakt dat.

- **SEO-titel** (`PageContent::seoTitle()`): `meta_title` met die terugval;
  is die in beide talen leeg, dan de paginanaam met die terugval, plus de
  sitenaam.
- **Meta description** (`PageContent::metaDescription()`): `meta_description`
  met die terugval, en daarna de sitebrede standaard (`SEO.md`).
- **Naam in het CMS** (`name()`): één stap extra, alleen voor beheer. Heeft de
  standaardtaal geen titel, dan de eerste taal die er wel een heeft. Een
  naamloze rij in een lijst kun je niet aanklikken. Een bezoeker krijgt die
  stap nooit.

Dit is terugval op **veldniveau**. Of een complete taalversie van een URL
bestaat, beslist pas de routingfase.

### De tijdelijke NL/EN-uitvoeradapter

De publieke wissel in de browser (`data-nl`/`data-en`, `core.js`) blijft tot de
frontend-flip. `PageLocalization::bilingual()` bouwt dat paar uit
`page_translations`, met elke helft al opgelost via `value()`, en geeft een
`LocalizedValue` terug. De twee codes komen uit het gesloten V1-register.

Gebruikt door `PageBreadcrumb`, `BreadcrumbTrail::toPage()` en `PageSeo` (via
`PageContent::seoTitle()`/`metaDescription()` met `nl` en `en`). Er is geen
nieuwe NL/EN-opslag. Paginavelden zijn platte tekst: alles wat ze print blijft
`textContent`, nooit `data-lang-html`.

### De editorcomponent

`admin/_localized_fields.php` is de Admin-primitieve voor velden die per
websitetaal worden opgeslagen. Hij is bewezen op `admin/page.php` en
`admin/page-new.php`.

| Functie | Doet |
|---|---|
| `admin_localized_languages()` | de actieve talen uit het register, standaardtaal eerst |
| `admin_localized_language()` | de taal van dit scherm: de keuze in de schil, als de site die taal heeft, anders de standaardtaal |
| `admin_localized_bar($code)` | *Je bewerkt: English*, de badge *standaardtaal* of wat een leeg veld betekent; niets bij één taal |
| `admin_localized_input($code)` | het verborgen veld `language_code`, één keer per formulier |
| `admin_localized_required($code)` | `required` alleen in de standaardtaal |
| `admin_localized_placeholder_attr($code)` | de terugvaltekst op een vertaalveld |

- **Één taal op het scherm én in het verzoek.** Er staan geen verborgen
  panelen van andere talen in het formulier. Het endpoint schrijft alleen de
  taal uit `language_code`, dus opslaan kan een andere vertaling nooit met een
  oude kopie overschrijven.
- **Taalneutrale velden** (adres, status, kruimelpad, indexeren,
  deelafbeelding) staan er in elke taal.
- **Van taal wisselen** gaat met de schakelaar *Content bewerken* in de schil.
  Een tweede taalkiezer is er niet. Het wisselen is een navigatie: staat er
  niet-opgeslagen invoer, dan vraagt de opslagbalk eerst
  (`beforeunload`), zodat niets stil verloren gaat. Zonder JavaScript werken
  de schakelaar en het opslaan gewoon als formulier.
- **Een nieuwe pagina** schrijft altijd in de standaardtaal. Vertalen gebeurt
  daarna, op de pagina zelf.
- **Titel verplicht** alleen in de standaardtaal, op het scherm en in
  `api/admin/update-page.php`.

### De schakelaar in de schil

`ContentEditingLanguage::choices()` geeft sinds fase 2 de actieve talen van
het register, standaardtaal eerst, plus het V1-paar. Een taal buiten het
register, of een verkeerde code, wordt de standaardtaal. De schakelaar markeert
de standaardtaal met een stip en met *standaardtaal* in de naam van de knop.

Een scherm dat nog op de V1-panelen staat (`admin/_language_fields.php`) kan
alleen NL/EN opslaan. Kiest een beheerder een andere taal, dan toont dat
scherm de standaardtaal en zegt dat. Het typt dus nooit Duits in een
Nederlands veld.

### Wie een pagina bij naam noemt

Andere domeinen lezen geen paginatekst meer uit `pages`, maar vragen
`PageLocalization::name()`:

- de blok-editors (terug-link, kop);
- de keuzelijsten van navigatie, footer en portfolio, en *Pagina: …* in de
  overzichten;
- `FormUsage` (waar een formulier staat);
- `PageSocialImageMediaUsage` (waar een afbeelding in gebruik is).

Hun eigen opslag en hun eigen labels zijn niet veranderd. Navigatie en footer
koppelen een pagina nog steeds via `page_id`.

### Installatie

- De homepage van een verse installatie (bootstrapmigratie) krijgt zijn naam
  via `20260917150000` als rij `nl`.
- De startpagina's van de wizard en *Nieuwe pagina* krijgen hun titel in de
  standaardtaal (`PageTemplateInstaller::install()` met `$translations`, in
  dezelfde transactie).

## Nog niet, bewust

**Voor de routingfase (6) en de flip (7):**

- slug per taal en publicatie per taal;
- `UNIQUE(language_code, slug)`;
- `/xx/`-prefixen en de dispatcher;
- hreflang, sitemap-alternates en een canonical per taal;
- `<html lang>` per URL en `Accept-Language`.

**In het voorbijgaan gevonden, niet in Pages:**

- **De rich-textbug is Blocks, fase 3.** Met Engels als standaardtaal toont
  een tekstblok bij de eerste render Nederlands, omdat
  `partials/section-rich-text.php` altijd `content_html` (de NL-kolom) print.
  Heeft het blok geen Engelse tekst, dan krijgt het ook geen
  `data-nl`/`data-en`. Dat zit niet in de taallaag van fase 2 en is daarom
  niet hier opgelost.
- **De zichtbare `<title>` en `content` van de meta description** in
  `partials/seo-head.php` (gedeeld met Shop en Blog) printen de NL-helft, ook
  als Engels de standaardtaal is; `core.js` wisselt pas in de browser. Het
  paar zelf komt correct uit `PageLocalization`. De server-side weergave
  hoort bij fase 7.
- `page_heroes.breadcrumb_label_nl/en` staan er nog als legacy (Paginakop,
  fase 3).

## V1-scope van Multilingual 2.0

**Binnen:** Multilingual als module die standaard uit staat; dynamische
websitetalen; één standaardtaal; talen toevoegen, uitzetten en ordenen; een
dynamische editor; server-side weergave; handmatig vertalen; gelokaliseerde
URL's en slugs; canonical, hreflang en sitemap; een taalwisselaar;
`Accept-Language` als suggestie; de keuze van een bezoeker onthouden; een
terugval op veldniveau.

**Buiten, en voor geen enkele fase een voorwaarde:** externe API's
(automatisch vertalen, DeepL, Azure), bulkvertaling, een statusworkflow voor
vertalingen, land- of IP-detectie, meerdere providers. De bestaande
providerklassen blijven ongebruikt staan.

## Vastgelegd voor later, nog niet gebouwd

- **Hybride opslag.** Echte domeinentiteiten (pagina's, blogberichten,
  categorieën, tags, producten, collecties, portfolio-items, formulieren,
  velden en opties, menu-items, footer, productopties, personalisatie) krijgen
  **getypeerde** `<entiteit>_translations`-tabellen: FK met `ON DELETE
  CASCADE`, één rij per taal, ook voor de standaardtaal.
- **Contentblokken** krijgen **één generieke** `block_translations`
  (`owner_table`, `owner_id`, `field`, `language_code`, `value`), met de
  veldspecificatie in `BlockDefinition::translatableFields()`. `owner_table`
  komt nooit uit een request en wordt tegen het gesloten register gehouden.
- **Weesrijen in `block_translations`.** Een polymorfe verwijzing kan geen FK
  hebben. Fase 3 levert daarom expliciete guards mee: verwijderen in dezelfde
  transactie als de blokrij, een idempotente opruimquery en een
  integriteitstest.
- **Gelokaliseerde slugs** alleen voor contenttypes waarvan V1 echt een
  gelokaliseerde publieke URL heeft, met `UNIQUE(language_code, slug)`.
- **Productslugs per taal** vallen buiten deze keten. Een product houdt
  `/product.php?id=N`, met een taalprefix zodra er meer talen zijn.
- **Router.** Eén dunne `dispatcher.php` achter `.htaccess`. De bestaande
  templates blijven renderen, en er komt geen front controller en geen
  frameworklaag. De standaardtaal krijgt geen prefix, andere talen `/xx/`.
  Pas in fase 6, en bewezen op een Vimexx-staging.
- **Verse installaties** krijgen de module `multilingual` straks **uit**.
- **Bestaande installaties met de oude NL/EN-wissel.** Wat die bij de flip
  krijgen (module aan of uit, Engels actief of niet), wordt pas vlak vóór de
  frontend-flip definitief gekozen. Fase 1 zet beide talen actief omdat dat
  het huidige gedrag is, niet als die keuze.
- **Oude kolommen** vallen per domein, in de migratie van de fase die dat
  domein omzet. Dat mag pas na een grep die bewijst dat geen andere fase ze
  nog leest (bekend: het blok *Contactkaart* leest `city_nl`/`city_en`).
  Pages is zo gegaan in fase 2 (`20260917150000`);
  `MultilingualBoundaryTest` bewaakt dat niets de zes kolommen nog leest.

## Waar het staat

| Wat | Waar |
|---|---|
| Schema en bootstrap | `db/migrations/20260917120000_create_the_site_language_registry.php` |
| SQL en invarianten | `src/Repository/SiteLanguageRepository.php` |
| Core-API | `src/Service/Language/SiteLanguages.php`, `SiteLanguage.php`, `LanguageCode.php` |
| V1-adapter | `src/Service/Language/ContentLanguages.php` |
| Tests | `LanguageCodeTest`, `SiteLanguagesTest` (`fast`); `SiteLanguageRepositoryTest` (`cms`); `SiteLanguageRegistryMigrationTest` (`migration`); grenzen in `MultilingualBoundaryTest` |
| Paginatekst: schema en verhuizing | `db/migrations/20260917140000_create_the_page_translations_table.php`, `20260917150000_move_page_text_into_page_translations.php` |
| Paginatekst: SQL, rij en API | `src/Repository/PageTranslationRepository.php`, `src/Service/PageTranslation.php`, `src/Service/PageLocalization.php` |
| Editorcomponent | `admin/_localized_fields.php`, gebruikt door `admin/page.php` en `admin/page-new.php`; schrijven in `api/admin/update-page.php` en `create-page.php` |
| Schakelaar in de schil | `ContentEditingLanguage::choices()`, `admin/_header.php`, `.admin-sidebar__contentlang-option.is-default` |
| Tests fase 2 | `PageLocalizationTest` (`fast`); `PageTranslationRepositoryTest`, `PageLocalizationEditorHttpTest` (`cms`); `PageTranslationMigrationTest` (`migration`); de Pages-grenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\PageFixture` |
