# Multilingual 2.0: architectuur

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). De contracten van
Multilingual 2.0 die **vastliggen**, wat er per fase al gebouwd is, en de
randvoorwaarden die latere fases moeten volgen. Bron is de architectuuraudit
van fase 0/0B (niet in de repository). Wat hier staat is de afspraak, en de
code heeft gelijk als die afwijkt.

Sinds fase 7 is er geen V1 meer: de server kiest de taal, de browser krijgt
de woorden van één taal per antwoord, en `_nl`/`_en`-kolommen,
`data-nl`/`data-en`, de wisselcode in `core.js` en de bilinguale adapters zijn
weg. Waar een sectie hieronder nog "tot fase 7" zegt, is dat geschiedenis;
*Fase 7* verderop zegt wat ervoor in de plaats kwam.

## Stand

| Fase | Inhoud | Status |
|---|---|---|
| 1 | Taalkern: talenregister, standaardtaal, Core-API, V1-adapter | **gebouwd** |
| 2 | Gelokaliseerde velden (editorcomponent) + Pages | **gebouwd** |
| 3 | Contentblokken | **gebouwd**: generiek model + drie blokken (3A), alle overige blokken met hun kindrijen (3B) |
| 4 | Navigatie, footer, instellingen, formulieren | **gebouwd**: getypeerde tabellen per domein, gelokaliseerde site-instellingen, optie-identiteit |
| 5 | Modules: Portfolio, Blog, Shop, Personalisatie | **gebouwd**: Portfolio (A), Blog (B), Shop (C), Personalisatie (D), de laatste instellingssleutels (E) |
| 6 | Routing per taal: dispatcher, taalresolutie, slugs per taal, links, wisselaar, canonical, hreflang, sitemap | **gebouwd**, zie [`ROUTING.md`](ROUTING.md) |
| 7 | De V1-uitvoer verwijderen (`data-nl`/`data-en`, de wisselcode in `core.js`, de bilinguale adapters), de module `multilingual`, talenbeheer, getypte URL's | **gebouwd**, zie *Fase 7* hieronder |

Fase 6 is breder uitgevallen dan deze tabel eerder aankondigde ("nog
eentalig"): de routing is meteen meertalig gebouwd, inclusief server-side
weergave in de taal van het verzoek, omdat een taal-URL die nog de
standaardtaal toont precies het signaal is dat deze fase moest voorkomen.
Sindsdien ziet een bezoeker 2.0 wél: elke actieve taal heeft eigen URL's.
Fase 7 heeft opgeruimd wat daardoor dood was.

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
| een taal met woorden wordt niet verwijderd | `ON DELETE RESTRICT` op `language_code` in elke vertaaltabel (21 tabellen); `SiteLanguages::remove()` meldt dat als reden `in_use` |
| alleen een taal die uit staat wordt verwijderd | `SiteLanguages::remove()` (reden `active`) |
| niemand omzeilt de repository | `MultilingualBoundaryTest`: geen andere SQL noemt de tabel |

Een nieuwe taal wordt nooit als standaard aangemaakt, en start sinds fase 7
uit. Alleen `setDefault()` verplaatst de standaard.

## De Core-API

`App\Service\Language\SiteLanguages`, één keer per request gelezen:

| Methode | Antwoord |
|---|---|
| `all()` | alle talen, op `sort_order` |
| `active()`, `activeCodes()` | de **gepubliceerde** talen, in volgorde: de actieve talen als de module Meertaligheid aan staat, anders alleen de standaardtaal (fase 7) |
| `switchedOn()` | de talen die zelf aan staan, wat de module ook zegt: waaruit de wizard en het tabblad *Talen* een standaard kiezen |
| `defaultLanguage()`, `defaultCode()` | de standaardtaal; een fout bij een kapot register |
| `find($code)`, `exists($code)`, `isActive($code)` | opzoeken; code wordt eerst genormaliseerd |
| `setDefault($code)` | standaard verplaatsen; neemt deel aan een open transactie |
| `add()`, `rename()`, `activate()`, `deactivate()`, `move()`, `remove()` | talenbeheer (fase 7); een weigering is een `InvalidArgumentException` met een reden (`code`, `exists`, `name`, `default`, `active`, `in_use`, `unknown`) |

De klasse kent geen taal bij naam en vraagt geen module bij naam na: sinds
fase 7 vraagt `active()` één capaciteit, `ModuleRegistry::publishesTranslations()`. Is het register
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
- **Schrijven** gaat via één methode, `SiteLanguages::setDefault()`, voor het
  tabblad *Talen*, de wizard en het endpoint (tot fase 7
  `ContentLanguages::savePrimary()`).
- **De wizard leest de gekozen taal** via `SetupWizard::websiteLanguage()`:
  de vorm via `LanguageCode`, de keuze uit `SiteLanguages::switchedOn()`, zodat
  ook een site met de module uit zijn standaard kan kiezen. Het scherm gebruikt
  dezelfde methode voor de beginwaarde van de keuzelijst.
  Nooit via `AdminLocale`: die bepaalt alleen in welke taal het CMS zelf
  getoond wordt. Alleen de schermen van de CMS-taal mogen een taal via
  `AdminLocale` valideren, en `MultilingualBoundaryTest` bewaakt dat.

## De V1-adapter (tot fase 7)

Van fase 1 tot fase 7 verwachtte de productiecode het vaste paar NL/EN, en
`ContentLanguages` was de brug: `primary()` las het register, maar alleen een
taal die de `_nl`/`_en`-kolommen konden opslaan, en `enabled()` gaf altijd NL
en EN. `LocalizedValue`, de `bilingual()`-methodes en `SiteText::visibleOf()`/
`attrsOf()` bouwden daaruit de `data-nl`/`data-en`-paren voor de wissel in de
browser.

Fase 7 heeft dat allemaal verwijderd. Een onleesbaar register valt nu terug op
`LanguageFallback::defaultLanguage()`, en die op de projectstandaard
`LanguageRegistry::DEFAULT_LANGUAGE`: één terugval, op één plek.

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

### Uitvoer

Een pagina print één waarde per veld, in de taal van het verzoek, met de
terugval al toegepast (`PageLocalization::value()`, via `PageContent`). Het
kruimelpad (`PageBreadcrumb`, `BreadcrumbTrail::toPage()`) en `PageSeo` lezen
dezelfde waarden. Paginavelden zijn platte tekst en worden altijd ge-escaped.
Tot fase 7 bouwde `PageLocalization::bilingual()` er een paar van voor de
wissel in de browser; die is weg.

### De editorcomponent

`admin/_localized_fields.php` is de Admin-primitieve voor velden die per
websitetaal worden opgeslagen. Hij is bewezen op `admin/page.php` en
`admin/page-new.php`, en sinds fase 3A ook gebruikt door drie blok-editors (zie
*Contentblokken per taal*).

| Functie | Doet |
|---|---|
| `admin_localized_languages()` | de actieve talen uit het register, standaardtaal eerst |
| `admin_localized_language()` | de taal van dit scherm: de keuze in de schil, als de site die taal heeft, anders de standaardtaal |
| `admin_localized_bar($code)` | *Taal: English*, de badge *standaardtaal* (uitleg achter `?`) of wat een leeg veld betekent; één keer per scherm en taal; niets bij één taal |
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

## Contentblokken per taal (fase 3A en 3B)

Blokken zijn het tweede contentdomein op de nieuwe opslag, en het eerste op
het **generieke** model. Fase 3A bouwt dat model en bewijst het op drie
bloktypes; fase 3B zet alle overige blokken om, met hun kind- en
kleinkindrijen. Sinds 3B heeft geen enkel blok nog een `_nl`/`_en`-kolom.

| Bloktype | Tabel | Velden per taal | Taalneutraal gebleven |
|---|---|---|---|
| Tekstblok (`rich_text`) | `rich_text_sections` | `body` (rich text) | `is_active` |
| Oproep met knop (`cta_band`) | `cta_bands` | `eyebrow`, `title`, `lead`, `primary_label`, `secondary_label` | `primary_url`, `secondary_url`, `is_active` |
| Contactkaart (`contact_card`) | `contact_cards` | `title`, `body`, `button_label` | `button_url`, `is_active` |

Samen bewijzen ze rich en platte tekst, verplicht en optioneel, taalneutrale
velden naast vertaalde, een regel over twee velden heen (label en URL van een
knop) en een consument buiten het blok (`portfolio-detail.php`, `LegalPages`).

Fase 3B, in drie golven (één migratie per golf):

| Golf | Bloktype | Eigenaartabellen → velden per taal |
|---|---|---|
| A | Paginakop (`page_hero`) | `page_heroes`: eyebrow, title, lead |
| A | Formulier (`form`) | `form_blocks`: title, intro |
| A | Offerte-/contactformulier (`contact_form`) | `contact_form_sections`: title |
| A | Galerij (`item_gallery`) en Projecten (`project_cards`, Portfolio) | `item_galleries` (gedeeld): eyebrow, title, lead, footer_note, button_label |
| B | Openingssectie homepage (`homepage_hero`) | `homepage_hero`: eyebrow, title, title_highlight, lead, primary_label, secondary_label, image_alt, badge_title, badge_text; `homepage_hero_stats`: primary_text, secondary_text |
| B | Kenmerken in kaartjes (`feature_grid`) | `feature_grids`: eyebrow, title, lead; `feature_grid_items`: title, body |
| B | Veelgestelde vragen (`faq`) | `faq_sections`: eyebrow, title; `faq_items`: question, answer |
| B | Cijferbalk (`stat_strip`) | `stat_strip_items`: primary_text, secondary_text (de balk zelf heeft geen woorden) |
| B | Stappenplan (`step_list`) | `step_list_sections`: eyebrow, title; `step_list_items`: title, body |
| B | Woordenband (`marquee`) | `marquee_items`: label (de band zelf heeft geen woorden) |
| C | Tekst met afbeelding (`text_image_split`) | `text_image_splits`: eyebrow, title, button_label; `text_image_split_paragraphs`: content; `text_image_split_images`: alt |
| C | Detailsectie (`detail_section`) | `detail_sections`: nav_label, title, lead, **body (rich)**, main_image_alt, closing_note, cta_label; `detail_section_points`: title, body; `detail_section_images`: alt |
| C | Kaarten-carrousel (`card_carousel`) | `card_carousels`: eyebrow, title, lead; `carousel_cards`: title, body, image_alt, link_label, number_label; `carousel_card_tags`: label (kleinkind) |

Het vaste blok `quicknav` en de Shop-blokken `product_grid` en
`shop_collections` hebben geen eigen rijen en dus geen eigen woorden: de
quicknav toont de labels van de Detailsecties, het productgrid de namen van de
producten, de collectie-tegels die van de collecties.

### De tabel `block_translations`

Eén tabel voor álle blokken (`20260917160000`): **één rij per veld, per taal,
per eigenaar**.

| Kolom | Betekenis |
|---|---|
| `owner_table` | de inhoudstabel van het blok (`cta_bands`); in 3B ook een kindtabel. `ascii_bin` |
| `owner_id` | de rij in die tabel; `page_sections.section_id` van het blok |
| `language_code` | FK op `site_languages.code`, `ON DELETE RESTRICT`, zelfde type |
| `field` | de veldsleutel uit `translatableFields()`, zonder taal (`title`, nooit `title_nl`). `ascii_bin` |
| `value` | de woorden, `MEDIUMTEXT NOT NULL`: platte tekst of gesaneerde HTML |
| `created_at`, `updated_at` | per veld; een onveranderd veld houdt zijn `updated_at` |

`UNIQUE(owner_table, owner_id, language_code, field)`. **Een veld zonder woorden
heeft geen rij**, dus "niet vertaald" en "leeg vertaald" zijn één toestand. Er
is geen `value_type`: of een veld rich is, staat in de definitie.

**Waarom één rij per veld en niet één JSON-rij per blok per taal.** Beide zijn
één generieke tabel en beide valideren in PHP. De doorslag:

- een migratie is een gewone `INSERT … SELECT` per oude kolom, zonder
  JSON-functies, dus gelijk op MySQL 5.7 en MariaDB;
- de `UNIQUE` bewaakt elk veld, en een veld dat geen blok meer declareert is
  met SQL te vinden (`orphans()`);
- elk veld heeft een eigen `updated_at`, zodat een afgeleide vertaalstatus
  later kan zien welk veld na zijn vertaling veranderd is;
- een verhuizing is per kolom te controleren (kolom tegen rij).

De prijs is dat opslaan twee statements zijn (overbodige velden weg, de rest
upserten). Die lopen in een transactie.

**Waarom geen getypeerde `<blok>_translations`.** Blokken worden nooit op
tekst gerouteerd, gesorteerd of doorzocht, en een nieuw bloktype mag geen
vertaalmigratie kosten.

### Het integriteitscontract

`owner_id` wijst polymorf naar de tabel die `owner_table` noemt en kan dus
**geen foreign key** hebben. Wat een FK anders zou doen, staat hier:

| Invariant | Afgedwongen door |
|---|---|
| een veld bestaat één keer per taal per eigenaar | `UNIQUE` |
| alleen een geregistreerde taal; een taal met blokwoorden is niet te verwijderen | FK `RESTRICT` op `site_languages.code` |
| alleen een tabel en veld die een geregistreerd blok declareert | `BlockLocalization` (gesloten register uit `BlockDefinitions`); `owner_table` komt nooit uit een request |
| geen woorden voor een eigenaar die niet bestaat | `BlockLocalization::save()` controleert de eigenaarrij vóór het schrijven |
| een verwijderd blok neemt zijn woorden mee, ook die van al zijn kind- en kleinkindrijen | `SectionRegistry::delete()` roept `BlockLocalization::deleteOwner()` aan **vóór `deleteContent()`, in dezelfde transactie**, voor elk blok met een inhoudstabel; `deleteOwner()` loopt de `childTables()` van het blok af. `PageService::delete()` loopt voor elk verwijderbaar blok via die methode |
| een los verwijderde kindrij neemt zijn woorden mee | het `delete-`-endpoint van die rij: `deleteOwner(<kindtabel>, $id)` en dan de rij, in één transactie (`MultilingualBoundaryTest` noemt elk endpoint) |
| een gedeclareerde kindtabel hangt echt aan zijn ouder | `BlockTranslationSchemaTest`: elke `childTables()`-regel is een foreign key met `ON DELETE CASCADE`, en elke tabel die cascadeert van een blok is gedeclareerd |
| niemand omzeilt de API | `MultilingualBoundaryTest`: alleen `BlockTranslationRepository` noemt de tabel in SQL, alleen `BlockLocalization` gebruikt die repository |
| wat er toch doorheen glipt, wordt gevonden | `BlockLocalization::orphans()` / `purgeOrphans()`, en `scripts/block-translation-orphans.php` als cronjob |

`orphans()` meldt drie soorten: woorden waarvan de eigenaarrij weg is (alleen
díé verwijdert `purgeOrphans()`), woorden in een veld dat het blok niet meer
declareert, en woorden van een tabel die geen geregistreerd blok declareert.
Die laatste twee worden alleen gemeld: zo ziet ook een blok van een
uitgeschakelde module eruit, en een module uitzetten gooit nooit data weg.
Er zijn geen triggers.

### `BlockDefinition::translatableFields()`

Een blok declareert zijn woorden in zijn eigen definitie, per eigenaartabel:

```php
public function translatableFields(): array
{
    return ['cta_bands' => [
        TranslatableField::plain('title', 255)->required(),
        TranslatableField::plain('lead', 500),
    ]];
}
```

Een blok met kindrijen noemt elke kindtabel ook in `childTables()`, met de
tabel en kolom waaraan zijn rijen hangen:

```php
public function childTables(): array
{
    return [
        'carousel_cards' => ['parent' => 'card_carousels', 'column' => 'carousel_id'],
        'carousel_card_tags' => ['parent' => 'carousel_cards', 'column' => 'card_id'],
    ];
}
```

`App\Service\Blocks\TranslatableField` is klein: een sleutel (geen
taalachtervoegsel), `plain` of `rich`, een maximumlengte in tekens en
`required()` (alleen in de standaardtaal). `normalise()` trimt platte tekst en
stuurt rich text door `RichTextSanitizer`. Er is geen label en geen widget: het
editorscherm blijft een handgeschreven formulier.

Die declaratie is de enige lijst. `BlockLocalization` weigert elke andere tabel
of sleutel, leest er lengte en verplicht uit, en saneert een veld omdat het
`rich` gedeclareerd is. `BlockDefinitionContractTest` bewaakt dat een blok
alleen zijn eigen inhoudstabel en zijn eigen kindtabellen declareert, dat de
keten van ouders bij de inhoudstabel eindigt, en dat blokken die een tabel
delen hetzelfde declareren.

**`abstract` sinds 3B.** Elk blok met eigen rijen declareert zijn woorden; een
nieuw blok kan dat niet vergeten. Een vast blok zonder eigen rijen declareert
`[]` (`FixedBlockDefinition`). `BlockTranslationSchemaTest` faalt zodra een
bloktabel nog een `_nl`/`_en`- of `content_html`-kolom heeft.

**Een gedeelde tabel.** `item_galleries` hoort bij twee bloktypes: de Galerij
(core) en Projecten (module Portfolio). `ProjectCardsBlock` geeft de declaratie
van `ItemGalleryBlock` door, dus er is één lijst. De Projecten-editor toont en
schrijft er twee van (title, lead). Omdat de Galerij de tabel ook declareert,
blijven de woorden geldig en onaangeroerd als de module uit staat.

### De Blocks-API

`App\Service\Blocks\BlockLocalization` is de enige lezer en schrijver van
`block_translations`, de tegenhanger van `PageLocalization`.
`App\Repository\BlockTranslationRepository` heeft de SQL.

| Methode | Antwoord |
|---|---|
| `fields($tabel)`, `ownerTables()` | het gesloten register uit de blokdefinities |
| `translations($tabel, $id)` | alle opgeslagen woorden, per taal per veld |
| `raw($tabel, $id, $veld, $taal)` | de opgeslagen woorden, **zonder** terugval: voor een redacteur |
| `value($tabel, $id, $veld, $taal)` | met terugval, rich text gesaneerd: voor een bezoeker |
| `name($tabel, $id, $veld)` | hoe het CMS een blok noemt (de standaardtaal, anders de eerste taal met woorden) |
| `text($tabel, $id, $veld)` | `value()` in de taal van het verzoek (fase 7) |
| `words($tabel, $id)` | `text()` voor elk gedeclareerd veld van één eigenaar: wat een `*Content`-klasse aan de partial geeft |
| `first($tabel, $id, [$veld, …])` | het eerste platte veld met woorden in de taal van het verzoek, en pas dan de standaardtaal: het quicknav-label (korte naam, anders de titel) |
| `hasDefaultWords($tabel, $id, $veld)` | heeft dit veld woorden in de standaardtaal? Die beslist of het blok iets toont, in elke taal (fase 7) |
| `hasRequiredWords($tabel, $id)` | heeft deze eigenaar zijn verplichte woorden in de standaardtaal? De vraag die een `*Content`-klasse per item stelt |
| `preload([$tabel => $ids])`, `preloadSections($rijen)`, `preloadBlocks([$tabel => $ids])` | woorden van veel blokken in één query; de laatste twee met de woorden van alle kind- en kleinkindrijen erbij |
| `problems($tabel, $taal, $waarden)`, `messageKeys()` | de validatie uit de declaratie: `missing` (alleen standaardtaal), `too_long` |
| `save($tabel, $id, $taal, $waarden)` | één taal opslaan; andere talen blijven staan; neemt deel aan een open transactie |
| `deleteOwner($tabel, $id)` | alle talen van één eigenaar weg, en van al zijn kindrijen uit `childTables()` |
| `orphans()`, `purgeOrphans()` | het vangnet |
| `defaultLanguage()` | de taal waarop alles terugvalt |

Lezen gooit nooit een fout (gelogd, leest als "geen woorden"); schrijven wel.

### Terugval

Per veld, hetzelfde contract als Pages:

1. de gevraagde taal;
2. de standaardtaal;
3. leeg.

Dat staat in `BlockLocalization::value()` en nergens anders. De inhoudsklassen,
partials en editors vragen de standaardtaal niet zelf;
`MultilingualBoundaryTest` bewaakt dat voor de omgezette blokken.

**De standaardtaal beslist of een blok iets toont.** Een Oproep met knop zonder
titel én knoplabel in de standaardtaal, een Contactkaart zonder kop én tekst,
of een Tekstblok zonder body in de standaardtaal rendert niets, ook als een
andere taal wél woorden heeft. Een vertaling alleen laat geen blok verschijnen.
Een secundaire knop verschijnt alleen met een label in de standaardtaal én een
URL.

**Dat geldt ook per item.** Een vraag, kenmerk, stap, kaart, tag, alinea of punt
zonder zijn verplichte woorden in de standaardtaal verschijnt niet
(`BlockLocalization::hasRequiredWords()`), ook als een vertaling ze wel heeft.
Een blokinstantie en zijn items zijn **taalneutrale structuur**: of ze er
zijn, hoe ze gesorteerd zijn en of ze aan staan, is voor elke taal gelijk. De
woorden verschillen per taal, de structuur niet. Reden: één pagina heeft in V1
één taalneutrale blokstructuur voor alle talen. Een blok of item dat alleen in
een vertaling bestaat, zou een tweede structuur per taal nodig hebben, en die
is er niet.

### Eén query per pagina

`SectionRegistry::renderPage()` roept `BlockLocalization::preloadSections()`
aan vóór het eerste blok: één `SELECT` met de woorden van alle blokken op de
pagina, in alle talen. Het lijstje van de paginabouwer (`admin/page.php`) doet
hetzelfde voor zijn bloklabels. Een blok dat buiten een pagina gelezen wordt
(`CtaBandContent::firstOnPage()`) kost één query voor zijn eigen woorden.

Gemeten op een pagina met 15 blokken (5 × Tekstblok, 5 × Oproep met knop, 5 ×
Contactkaart, woorden in NL en EN), koude caches:

| | Vóór (main, `_nl`/`_en`) | Na (3A) | Zonder preload |
|---|---|---|---|
| `renderPage()` | 19 SELECTs | 20 | 34 (één extra per blok) |
| bloklabels in de paginabouwer | 17 | 3 | — |
| tweede render in hetzelfde request | 1 | 1 | — |

De ene query per blok die blijft, is de inhoudsrij van het blok zelf; die was er
al. `BlockWordsPreloadTest` bewaakt dat twaalf blokken meer precies twaalf
queries meer kosten.

**Kindrijen (3B).** `preloadSections()` haalt in diezelfde ene query ook de
woorden van alle kind- en kleinkindrijen op: één `UNION ALL` met per kindtabel
een join langs `childTables()` naar de inhoudsrij van het blok. Een kindrij
zonder woorden telt ook als geladen, dus niets vraagt het later nog eens. De
kindrijen zelf komen, net als vóór 3B, uit één query per kindtabel per blok,
hoeveel items er ook zijn.

Gemeten op een pagina met 14 blokken van twaalf 3B-bloktypes, met 62 kind- en
kleinkindrijen en woorden in NL en EN, koude caches, drie keer:

| | Vóór 3B (`_nl`/`_en`) | Na 3B |
|---|---|---|
| `renderPage()` | 33 SELECTs | 34 (de ene query voor alle woorden) |
| tweede render in hetzelfde request | 1 | 1 |

De HTML was op witruimte na gelijk. `BlockWordsPreloadTest` bewaakt dat een
FAQ, Detailsectie, Tekst met afbeelding en Kaarten-carrousel met zes keer zoveel
items en tags geen enkele query meer kosten.

### Uitvoer

Een inhoudsklasse geeft de partial per veld **één string**, in de taal van het
verzoek, met de terugval al toegepast: `BlockLocalization::text()`,
`::first()` en `::words()`. De partial print platte tekst met
`htmlspecialchars()` en gesaneerde rich text (`RichTextSanitizer`) zoals hij
is. Een partial kent zo geen taal, geen standaard en geen terugval. Tot fase 7
was dit een `LocalizedValue`-paar uit `BlockLocalization::bilingual()`, met
`SiteText::visibleOf()`/`attrsOf()`/`htmlAttrsOf()` in de partial; die zijn
weg.

**De standaardtaal beslist of een blok iets toont**, in elke taal:
`BlockLocalization::hasDefaultWords()`. Een Tekstblok zonder body in de
standaardtaal toont ook op `/en/` niets, ook als er een Engelse body is.

**De rich-textbug is weg** (sinds 3A): met Engels als standaardtaal toont een
Tekstblok de Engelse body, niet de Nederlandse. Sinds 3B geldt hetzelfde voor
de body van de Detailsectie, het tweede rich veld.

**Alt-teksten** zijn gewone platte velden op de rij die het beeld houdt
(`alt`, `image_alt`, `main_image_alt`). `BlockImage::fromOwner($rij, $alt)`
legt de alt-tekst van de mediabibliotheek eronder als laatste laag: een blok
zonder eigen alt-tekst in de standaardtaal krijgt die van het media-item.

**Eén uitzondering op "platte tekst is nooit HTML":** de kop van de
Openingssectie homepage. `HomepageHeroContent::renderTitleFragment()` bouwt
markup uit ge-escapete woorden en één vaste `<em>` rond de highlight.

### De editors

Alle blok-editors staan op `admin/_localized_fields.php` (in 3A de drie
proefblokken, in 3B de rest), hetzelfde patroon als de pagina-editor:

- de taal uit de schakelaar in de schil, als het register hem heeft; anders de
  standaardtaal. Een derde taal is een rij in `site_languages`;
- alleen de velden van die taal, zoals opgeslagen (`raw()`), met de
  terugval als placeholder; geen verborgen panelen van andere talen;
- `required` alleen in de standaardtaal, op het scherm en in het endpoint
  (`BlockLocalization::problems()`);
- URL's en *Actief* staan in elke taal, en worden in dezelfde transactie als de
  woorden opgeslagen;
- een geweigerde save komt terug met `data-save-bar-unsaved`; de getypte woorden
  alleen in de taal waarin ze getypt zijn;
- **knopregels volgen de standaardtaal**: een secundaire knop (Oproep) heeft een
  label in de standaardtaal én een URL, of geen van beide, en een vertaald label
  zonder URL wordt geweigerd; een knop-URL op een Contactkaart of Galerij vraagt
  een label in de standaardtaal. De knoppen van Tekst met afbeelding,
  Detailsectie en een carrouselkaart werden nooit geweigerd en worden dat nog
  steeds niet: ze verschijnen alleen met een label in de standaardtaal én een
  URL;
- **een nieuw item** (vraag, kaart, tag, alinea, punt, afbeelding) wordt altijd
  in de standaardtaal toegevoegd, ook vanaf het scherm van een andere taal; het
  formulier zegt dat (`admin_localized_new_item_note()`), net als bij een nieuwe
  pagina;
- **een beeldformulier** schrijft alleen zijn eigen alt-tekst in de getoonde
  taal. Het tekstformulier van dezelfde rij geeft die alt-tekst ongewijzigd door,
  omdat `save()` een hele taal schrijft. Een verwijderd beeld neemt zijn
  alt-tekst in alle talen mee.

De vertaalknop van V1 (`admin_lang_translate_bar()`) staat op geen enkele
blok-editor meer, net als op de pagina-editor: automatisch vertalen valt buiten
V1.

### De migratie

`20260917170000` verhuist de woorden en dropt de oude kolommen in dezelfde stap.

| Oude kolommen | Naar |
|---|---|
| `rich_text_sections.content_html` / `content_html_en` | `body` in `nl` / `en` |
| `cta_bands.<veld>_nl` / `<veld>_en` voor eyebrow, title, lead, primary_label, secondary_label | `<veld>` in `nl` / `en` |
| `contact_cards.<veld>_nl` / `<veld>_en` voor title, body, button_label | `<veld>` in `nl` / `en` |

De kolommen houden hun V1-betekenis, wat de standaardtaal ook is. Woorden gaan
byte voor byte mee. `NULL`, `''` en alleen spaties, tabs of regeleinden krijgen
geen rij. Id's, pagina, sleutel, URL's en `is_active` blijven ongemoeid, net als
elk ander bloktype. Opnieuw draaien doet niets. Staan er woorden in een taal die
het register niet heeft, dan stopt de migratie vóór de drop.

Fase 3B volgt precies dat patroon, met één migratie per golf:

| Migratie | Tabellen | Kolommen verhuisd en gedropt |
|---|---|---|
| `20260917180000` (golf A) | `page_heroes`, `form_blocks`, `contact_form_sections`, `item_galleries` | 24, waarvan `page_heroes.breadcrumb_label_nl/en` **zonder** verhuizing: niets las ze nog (fase 5B) |
| `20260917190000` (golf B) | `homepage_hero`, `homepage_hero_stats`, `feature_grids`, `feature_grid_items`, `faq_sections`, `faq_items`, `stat_strip_items`, `step_list_sections`, `step_list_items`, `marquee_items` | 54 |
| `20260917200000` (golf C) | `text_image_splits`, `text_image_split_paragraphs`, `text_image_split_images`, `detail_sections` (`content_html`/`content_html_en` → `body`), `detail_section_points`, `detail_section_images`, `card_carousels`, `carousel_cards`, `carousel_card_tags` | 46 |

Een kindrij krijgt zijn woorden onder zijn eigen tabel en `id`. Vóór elke drop
bewees een grep over de hele repository dat geen productiecode de kolom nog
las.

### Kopiëren, verwijderen en media

- **Kopiëren of dupliceren** van blokken bestaat niet in het CMS (geen
  "pagina dupliceren", geen herbruikbare blokken, geen export).
  `PageTemplateInstaller` maakt verse blokken via `create()`, en die schrijft zijn
  startwoorden in de standaardtaal via `BlockLocalization::save()`. Komt er ooit
  een kopie, dan kopieert die ook de rijen van `block_translations`.
- **Verwijderen** loopt altijd via `SectionRegistry::delete()` voor een heel
  blok, ook bij het verwijderen van een pagina, en via het `delete-`-endpoint
  van een item voor één kindrij; zie het integriteitscontract. Een module
  uitzetten verwijdert niets.
- **Media**: gebruik wordt alleen uit `media_id`-kolommen afgeleid.
  Blokwoorden zijn tekst, alt-teksten ook; een id of pad in een Tekstblok telt
  niet als gebruik (`MediaUsageTest`).

### Kindrijen: het contract van fase 3B

Twaalf kindtabellen hebben vertaalde velden: `homepage_hero_stats`,
`feature_grid_items`, `faq_items`, `stat_strip_items`, `step_list_items`,
`text_image_split_paragraphs`, `text_image_split_images` (alt), `marquee_items`,
`detail_section_points`, `detail_section_images` (alt), `carousel_cards` en
`carousel_card_tags` (kleinkind).

- **Hetzelfde model**: een kindrij is een eigenaar als elke andere,
  `owner_table = 'faq_items'`, `owner_id` = de id van het item. Geen tweede
  model, geen JSON en geen `items.12.question`-sleutels.
- **Eigen stabiele identiteit**: elke kindtabel heeft een eigen `id`; die is de
  identiteit. Sorteren, verbergen en verplaatsen raken hem niet.
- **De declaratie**: `translatableFields()` noemt elke kindtabel met zijn
  velden, en `childTables()` noemt waaraan hij hangt. Zo vindt `deleteOwner()`
  de kindrijen vóór ze weg zijn: de `ON DELETE CASCADE` van de database draait
  geen PHP. `purgeOrphans()` blijft alleen het vangnet; niets rekent erop.
- **Verwijderen**: een los item via zijn `delete-`-endpoint (woorden eerst, dan
  de rij, één transactie); een heel blok via `SectionRegistry::delete()`, dat
  de hele boom meeneemt.
- **Alt-teksten** zijn gewone platte velden op de rij die het `media_id`
  houdt; `BlockImage::fromOwner()` leest ze via de API.

**Het repeater-save-contract.** Een item wordt nooit gewist en opnieuw
aangemaakt. `create-` voegt één rij toe met zijn woorden in de standaardtaal;
`update-` schrijft één taal onder hetzelfde `id`, zodat NL opslaan de EN- en
DE-woorden van dat item laat staan; `move-` verandert alleen `sort_order`;
`delete-` neemt één rij met zijn woorden. `BlockChildWordsEditorHttpTest`
bewijst toevoegen, opslaan per taal en verwijderen voor alle twaalf
kindtabellen over echte HTTP.

### Wat fase 4 gedaan heeft

Geen blok heeft nog woorden in kolommen, en sinds fase 4 geen menu-item,
footerkolom, footerlink, formulier, veld of optie ook. Wat daarvoor gebouwd
is, staat hieronder: *Navigatie, footer, instellingen en formulieren*.

## Navigatie, footer, instellingen en formulieren (fase 4)

Vier domeinen die geen contentblok zijn en toch tekst tonen. Ze delen één
fundament en houden ieder een eigen, dunne API. Er is bewust **geen**
`LocalizationService` die alles weet: het fundament kent geen enkel domein, en
een domein-API kent alleen zijn eigen tabel.

### Het gedeelde fundament

| Klasse | Wat het is |
|---|---|
| `App\Service\Language\LanguageFallback` | **De** terugval van fase 4: gevraagde taal → standaardtaal → leeg. Ook `name()` (beheerdersnaam: eerste gevulde taal als extra stap) en `defaultLanguage()`, de enige terugval bij een onleesbaar register (fase 7) |
| `App\Service\Language\TranslationTable` | Gesloten declaratie van één getypeerde tabel: naam, eigenaarskolom, en per veld zijn maximumlengte. Weigert `language_code` als veldnaam |
| `App\Repository\EntityTranslationRepository` | **Alle** SQL van **alle** getypeerde tabellen, gebouwd uit die declaratie: `findForOwners()`, `save()` (upsert), `delete()` |
| `App\Service\Language\EntityTranslations` | De API per tabel: cache, `words/raw/value/name/preload/problems/save/forget` |

`save()` legt één taal neer over wat er staat, en verwijdert de rij zodra elk
veld van die taal leeg is. Lengte wordt alleen gemeten over de velden die de
aanroeper meestuurt, zodat NL opslaan nooit afketst op een te lange EN-tekst.

### De getypeerde tabellen

Elke tabel heeft dezelfde vorm: eigenaar-id, `language_code`, alleen
gelokaliseerde velden, `UNIQUE(eigenaar, language_code)`, FK op de eigenaar met
`ON DELETE CASCADE` en FK `language_code` → `site_languages.code` met
`ON DELETE RESTRICT`. Geen JSON, geen `_de`/`_fr`-kolommen, geen polymorfe
eigenaar.

| Tabel | Eigenaar | Velden (max) | Domein-API |
|---|---|---|---|
| `nav_item_translations` | `nav_item_id` | `label` (100) | `App\Service\NavigationLocalization` |
| `footer_column_translations` | `footer_column_id` | `title` (100) | `App\Service\FooterLocalization` |
| `footer_link_translations` | `footer_link_id` | `label` (100) | `App\Service\FooterLocalization` |
| `form_translations` | `form_id` | `submit_label` (150), `success_message` (1000) | `App\Service\Forms\FormLocalization` |
| `form_field_translations` | `form_field_id` | `label` (200), `placeholder` (200), `help_text` (500) | `App\Service\Forms\FormLocalization` |
| `form_field_option_translations` | `form_field_option_id` | `label` (255) | `App\Service\Forms\FormLocalization` |

Alles wat geen tekst is, blijft staan waar het stond: soort en bestemming van
een menu-item, `page_id`, routesleutel, externe URL, presentatie, knopvariant,
ouder, volgorde en zichtbaarheid; social-URL's, logo's, media-id's en neutrale
bedrijfsgegevens; formulier-id, status, veldtype, veldsleutel, verplichtheid,
volgorde, opslag- en mailinstellingen. Een paginalink blijft een `page_id`:
gelokaliseerde URL's zijn fase 6.

### Gelokaliseerde site-instellingen

Eén kleine winkel, geen vergaarbak: `site_setting_translations`
(`setting_key`, `language_code`, `value`), `UNIQUE(setting_key,
language_code)`, FK op `site_languages` met `RESTRICT`.

`App\Service\LocalizedSiteSettings` heeft een **gesloten catalogus** van
sleutels die echt websitetekst zijn — `city` (150), `footer_description` (500),
`footer_slogan` (200) — en weigert elke andere sleutel en elke taal die niet in
het register staat. Een request kan dus nooit een nieuw instellingstype laten
ontstaan. Neutrale configuratie (`footer_show_*`, `footer_copyright_template`,
KVK, e-mailadressen) blijft gewoon `site_settings`, en CMS-teksten horen hier
nooit: die staan in `src/Service/Language/messages/`.

Niet elk oud `_nl`/`_en`-paar is meeverhuisd. Per paar is gekozen: tekst →
nieuwe winkel, neutraal → `site_settings`, dood → weg. Zo zijn de acht
`header_cta_*`-sleutels verdwenen (hun enige lezer was de migratie die de
headerknoppen naar `nav_items` bracht), en blijft
`related_products_heading_nl/en` staan tot fase 5, omdat de Shop hem deelt met
`collections.related_heading_nl/en`.

### Formulieren: identiteit en label

Het scherpste punt van deze fase. Een keuzeoptie heeft sinds nu **twee**
dingen: een waarde en een label.

- `form_field_options.value` — de identiteit. Uniek per veld, vergeleken in
  `utf8mb4_bin`, in elke taal dezelfde. Dit is wat het publieke formulier post,
  wat `ChoiceFieldType` accepteert, wat een inzending bewaart en waar
  `form_fields.default_value` naar wijst.
- `form_field_option_translations.label` — wat de bezoeker leest, per taal.

Een taalwissel verandert dus wél wat er op het scherm staat en **nooit** wat er
verstuurd of opgeslagen wordt. De migratie neemt als waarde de Nederlandse
helft van de oude optieregel, byte voor byte, zodat bestaande defaults en alle
historische inzendingen blijven kloppen. Een nieuwe optie krijgt het label in
de standaardtaal als waarde, zo nodig uniek gemaakt met ` (2)`.

**Inzendingen zijn een momentopname.** `form_submission_values` bewaart
`field_label` en `value` zoals ze golden toen de bezoeker verstuurde, zonder
join terug naar het veld. Fase 4 raakt geen enkele bestaande inzending aan en
voegt geen taalkolom toe: dat zou een schema-uitbreiding buiten deze fase zijn.

### De editors

Navigatie-item, footerkolom, footerlink, site-instellingen, footerinstellingen,
formulier en veld bewerken **één** websitetaal tegelijk, via hetzelfde
`admin/_localized_fields.php` als Pages en de blokken. Actieve talen komen uit
`site_languages`, de standaardtaal is herkenbaar, neutrale velden staan er
altijd, en taal A opslaan laat taal B staan. Een nieuw item, een nieuwe kolom,
link of veld begint in de standaardtaal. Rij, woorden en opties zijn één
transactie; een geweigerde opslag schrijft niets en houdt de POST vast.

### Uitvoer

Header, footer, het Offerte-/contactformulier en het publieke formulier printen
één waarde per veld, in de taal van het verzoek, opgelost via
`LanguageFallback::resolve()`. Geen terugval per template. Een menulabel,
footerlabel, veldlabel en optielabel zijn altijd platte tekst en worden
ge-escaped. Tot fase 7 was dat een paar via `LanguageFallback::bilingual()` en
`SiteText::visibleOf()`/`attrsOf()`.

### De migraties

Forward-only, één golf per paar, en elke migratie weigert te droppen wat hij
niet kon verplaatsen.

| Migratie | Wat |
|---|---|
| `20260918100000` | de drie navigatie-/footertabellen |
| `20260918110000` | labels erheen, daarna de zes kolommen weg |
| `20260918120000` | `site_setting_translations` |
| `20260918130000` | `city`, `footer_description` en `footer_slogan` erheen, de zes oude sleutels en de acht dode `header_cta_*`-sleutels weg |
| `20260918140000` | `form_field_options` en de drie formuliertabellen |
| `20260918150000` | woorden en opties erheen, daarna elf kolommen weg |

NL wordt `nl` en EN wordt `en`; lege en witruimte-waarden krijgen geen rij; een
tweede run verandert niets; en als het register de taal niet kent die de
kolommen nog bevatten, stopt de migratie vóór elke drop met een melding die de
taal noemt.

## De modules per taal (fase 5)

Vier domeinen die geen Core zijn: Portfolio (golf A), Blog (B), Shop (C) en
Personalisatie (D). Ze staan op het fundament van fase 4 — `LanguageFallback`,
`TranslationTable`, `EntityTranslationRepository`, `EntityTranslations` — en
elk houdt een eigen dunne API. Er komt geen tweede generieke laag en geen
`LocalizationService`.

**De inventaris bij de start van fase 5** (na fase 4, `5bc9a2e`): 38
`_nl`/`_en`-kolommen plus hun 22 kale NL-tegenhangers, dus 60 kolommen voor 30
velden, en één gelokaliseerde instellingssleutel
(`related_products_heading_nl/en`). Daarvan is er één géén live content maar
een historische momentopname: `order_items.product_name(_en)`.

### Portfolio (golf A)

| Tabel | Eigenaar | Velden (max) | Domein-API |
|---|---|---|---|
| `portfolio_category_translations` | `portfolio_category_id` | `name` (100) | `App\Service\PortfolioLocalization` |
| `portfolio_item_translations` | `portfolio_item_id` | `title` (150), `subtitle` (150), `alt` (255), `intro` (rich), `description` (rich) | idem |
| `portfolio_item_image_translations` | `portfolio_item_image_id` | `alt` (255) | idem |

Dezelfde vorm als de tabellen van fase 4: `UNIQUE(eigenaar, language_code)`, FK
op de eigenaar met `ON DELETE CASCADE`, FK `language_code` →
`site_languages.code` met `ON DELETE RESTRICT`. De twee rich velden zijn
`MEDIUMTEXT`, zoals `block_translations.value`, zodat geen waarde uit de oude
`TEXT`-kolom ineens niet meer past.

**Eén API, geen omweg.** `PortfolioLocalization` is de enige lezer en schrijver
van die drie tabellen. De repositories bewaren rijen en kennen geen woord meer;
`PortfolioGalleryContent` geeft per veld één waarde in de taal van het verzoek
(tot fase 7 een `LocalizedValue`-paar), de terugval al toegepast, en beslist
zelf geen taal. `MultilingualBoundaryTest` bewaakt dat.

**Rich text wordt per taal gesaneerd, vóór de terugval.** `intro` en
`description` zijn de rich text van de oude projectpagina. De editor die ze
schreef is er niet meer, dus `itemRichValue()` haalt elke taal apart door
`RichTextSanitizer` en geeft het resultaat daarna aan de terugval
(`LanguageFallback`). Een taal waarvan de markup wegsaneert heeft
dus geen woorden, en de terugval neemt het over. Eén sanitizer, geen tweede.

**Wat taalneutraal blijft:** de slug van een categorie en van een item, de
afbeelding en haar thumbnail, de gekoppelde pagina, de categorieën van een
item, `is_active`, `is_featured` en elke sorteervolgorde. De slug van een
nieuwe categorie komt eenmalig uit de naam in de **standaardtaal** en wordt
nooit hernoemd, dus een vertaling verplaatst nooit een adres. Gelokaliseerde
URL's zijn fase 6.

**De standaardtaal beslist of een kaart woorden heeft**, hetzelfde contract als
de blokken sinds fase 3A: hij is het eind van de keten en valt terug op niets.
Een item met alleen Engelse woorden heeft op een Nederlandstalige site dus geen
titel. Op elke bestaande installatie is Nederlands de standaardtaal
(`20260917120000` leest `primary_content_language`) en is de uitvoer
byte-identiek aan vóór deze golf: Engels valt terug op Nederlands zoals de
kolommen deden. Alleen op een Engelstalige site verandert het: daar kopieerden
de kolommen de Nederlandse helft naar de Engelse voordat ze werden geprint, en
dat doet de nieuwe opslag niet.

**De gedeelde kaartvorm.** Het blok Galerij (Core) en Projecten (Portfolio)
renderen dezelfde kaart, met Portfolio-items óf producten als bron. Die
genormaliseerde vorm draagt sinds deze golf `alt`, `title` en `subtitle` per
veld — sinds fase 7 als één string in de taal van het verzoek — welke bron hem
ook bouwt. `CollectionGalleryItems`
bouwt dat paar tot golf C nog uit de kolommen van de Shop; wat dan verandert is
de bron, niet de partial.

**De editors.** `admin/portfolio.php` (de categoriebeheerder) en
`admin/portfolio-item.php` staan op `admin/_localized_fields.php`, net als
Pages, de blokken en de schermen van fase 4: één taal op het scherm én in het
verzoek, `language_code` in een verborgen veld, de standaardtaal herkenbaar, de
terugval als placeholder. Een nieuwe categorie en een nieuw item worden altijd
in de standaardtaal geschreven. Rij, woorden, categorieën en de paginakeuze
zijn één transactie, en een geweigerde opslag schrijft niets en houdt de POST
vast.

**Het item-formulier kan de oude projectpagina niet leegmaken.** Het toont
`title`, `subtitle` en `alt`, en stuurt precies die drie; `EntityTranslations::save()`
laat een gedeclareerd veld dat niet meegestuurd is staan. `intro` en
`description` houden dus hun woorden, in elke taal.

**Module uit verandert niets.** De drie tabellen worden gemaakt en gevuld of
Portfolio aan staat of niet, en uitzetten verwijdert geen rij en geen woord.
Een verse installatie met de module uit eindigt op hetzelfde schema als een met
hem aan.

**De migraties.** `20260918160000` maakt de drie tabellen. `20260918170000`
verhuist veertien kolommen (zeven velden × twee talen) en dropt ze in dezelfde
stap. Anders dan de tabellen van fase 4 heeft één eigenaar hier **meerdere**
velden in één rij: per veld per taal is het één `INSERT … SELECT` voor de
eigenaars die nog geen rij hebben, en één `UPDATE … JOIN` die een nog lege
kolom van een bestaande rij vult. Woorden gaan byte voor byte mee, rich text
inbegrepen; `NULL`, `''` en alleen witruimte krijgen geen rij; opnieuw draaien
doet niets; en kan een taal niet verhuisd worden omdat het register hem niet
heeft, dan stopt de migratie vóór elke drop met een melding die de taal noemt.

**De hardcoded filtercategorieën zijn weg.** `PortfolioGalleryContent` had nog
een lijstje van drie categorieën met een Nederlandse en een Engelse naam, als
vangnet voor een onbereikbare database. Datzelfde vangnet was voor de items al
verwijderd, met de reden die de klasse zelf opschrijft: een blok wordt alleen
bereikt via `SectionRegistry::renderPage()`, die bij een mislukte paginalookup
helemaal niets rendert, dus het net kon nooit iets vangen. Het was ook de
laatste vaste NL/EN-opslag van deze module, in code.

### Blog (golf B)

| Tabel | Eigenaar | Velden (max) | Domein-API |
|---|---|---|---|
| `blog_post_translations` | `blog_post_id` | `title` (200), `excerpt` (500), `body` (rich, 50000), `meta_title` (255), `meta_description` (500) | `App\Service\Blog\BlogLocalization` |
| `blog_category_translations` | `blog_category_id` | `name` (150), `description` (500) | idem |
| `blog_tag_translations` | `blog_tag_id` | `name` (100) | idem |

Dezelfde vorm en dezelfde regels als golf A. Eén verschil in de *oude* opslag:
bij Blog was de Nederlandse helft de **kale** kolom (`title`, niet `title_nl`)
en droeg alleen de Engelse een achtervoegsel. Beide houden hun V1-betekenis.

**De slug is niet verhuisd, en dat is het punt.** `blog_posts.slug`,
`blog_categories.slug` en `blog_tags.slug` zijn enkelvoudig en taalneutraal, en
dat blijven ze: `/blog/<slug>`, `/blog/categorie/<slug>` en `/blog/tag/<slug>`
antwoorden na deze golf precies wat ze ervoor antwoordden, en elke opgeslagen
redirect van een hernoemd archief (`BlogTaxonomy`) blijft wijzen waar hij
wees. Een slug per taal vraagt een router die hem gebruikt, en dat is fase 6;
nu zou het een tweede bron van waarheid zijn die niemand leest. De slug van een
nieuw bericht of een nieuwe categorie komt uit de titel in de **standaardtaal**.
`MultilingualBoundaryTest` en `BlogWordsMigrationTest` bewaken beide kanten:
geen vertaaltabel van Blog kent het woord `slug`, en na de migratie staat elke
slug nog op zijn eigen rij.

**Eén sanitizer, op één plek.** `body` is het enige rich veld.
`BlogLocalization::body()` en `bodyValue()` halen het door de bestaande
`RichTextSanitizer` — per taal, vóór de terugval, net als bij Portfolio — en
`api/admin/update-blog-post.php` doet hetzelfde op de weg naar binnen. Nergens
anders in de Blog wordt gesaneerd, en `MultilingualBoundaryTest` faalt zodra
dat verandert.

**Twee vormen van dezelfde woorden.** `BlogContent::title()/excerpt()/body()`
geven één taal — dat is wat de SEO-kop, de RSS-feed en de JSON-LD willen —
en tot fase 7 gaven `titleValue()`, `excerptValue()`, `bodyValue()`,
`categoryNameValue()` en `tagNameValue()` een `LocalizedValue`-paar voor de
wissel in de browser. Sinds fase 7 is er alleen de eerste vorm, in de taal van
het verzoek, en zijn de UI-woorden ("Alles", "Lees verder") een codecatalogus.

**De RSS-feed volgt de taal van het verzoek.** In deze fase was hij nog de
standaardtaal: één document op één adres, en de vaste `'nl'` werd
`BlogLocalization::defaultLanguage()`. Sinds fase 6 heeft elke taal een eigen
feed-URL (`/en/blog/feed.xml`), en `BlogFeed` schrijft kanaal en items in de
taal van die URL, met de gewone terugval (`BLOG.md`, "RSS").

**Sorteren mag niet van de lezer afhangen.** Een naam was een kolom om op te
sorteren: `blog_categories` viel bij een gelijke `sort_order` terug op de
Nederlandse naam, en `blog_tags` had helemaal geen andere orde dan zijn naam.
Beide sorteren nu taalneutraal — categorieën op `sort_order`, dan `id`; tags op
`id` — en waar een alfabetische lijst telt, sorteert de leeslaag op het
**CMS-label** (de standaardtaal), zodat de chips en de tagbeheerder op elke
taal van de site dezelfde volgorde hebben. `MultilingualBoundaryTest` faalt
zodra een Blog-query weer op woorden sorteert.

**Zoeken kijkt in élke taal.** De titelzoekbalk van `admin/blog.php` gebruikte
twee kolommen, `title` en `title_en`. Nu vraagt hij
`BlogLocalization::postIdsMatchingTitle()`, en die vraagt
`EntityTranslations::ownersMatching()` — nieuw in het fundament — om de
eigenaars met een treffer in wélke taal dan ook. `BlogPostRepository` krijgt
daarna alleen id's, want een domeinrepository noemt geen vertaaltabel in zijn
eigen SQL. Geen treffers is iets anders dan niet zoeken, dus dat is een eigen
sleutel (`title_ids`) en geen leeg zoekwoord.

**Identiteit versus label.** Welke categorieën en tags een bericht heeft, in
welke volgorde, en welke slug ze hebben is in elke taal gelijk; alleen hun
label verschilt. Een tag hernoemen in een tweede taal maakt geen tweede tag,
en een tag die op een bericht wordt getypt wordt **hergebruikt** op zijn slug —
nooit hernoemd. Een nieuwe tag wordt in de standaardtaal benoemd, zodat een
chip nooit leeg kan zijn.

**De editors.** `admin/blog-post.php` (drie tabbladen, één formulier),
`admin/blog-categories.php` en `admin/blog-tags.php` staan op
`admin/_localized_fields.php`. De tagbeheerder is een tabel met één formulier
per rij, dus zijn besturingselementen staan buiten hun formulier;
`admin_localized_input()` heeft daarvoor een tweede argument gekregen, het
`form`-id — zonder dat zou het verborgen taalveld bij geen enkel formulier
horen en zou het endpoint geen taal horen.

**De migraties.** `20260918180000` maakt de drie tabellen, `20260918190000`
verhuist zestien kolommen en dropt ze in dezelfde stap, met hetzelfde
`INSERT … SELECT` + `UPDATE … JOIN`-paar per veld per taal als golf A.

### Shop (golf C)

| Tabel | Eigenaar | Velden (max) | Domein-API |
|---|---|---|---|
| `product_translations` | `product_id` | `name` (150), `description` (rich, 50000), `meta_title` (255), `meta_description` (500) | `App\Service\ShopLocalization` |
| `collection_translations` | `collection_id` | dezelfde vier, plus `related_heading` (255) | idem |
| `order_item_translations` | `order_item_id` | `product_name` (255) | `App\Service\OrderItemNameSnapshot` |

De eerste twee hebben dezelfde vorm en dezelfde regels als golf A en B. De
derde is iets anders, en dat staat hieronder.

**Woorden zijn geen identiteit.** Dat is de regel waar deze hele golf aan
hangt. Alles waar een winkel een besluit mee neemt blijft op zijn eigen rij en
is in elke taal hetzelfde: het id, de slug, de prijs, de voorraad, de
verzendinstellingen, `active`, `in_shop`, `in_personalization_catalog`, de
afbeeldingspaden, de varianten en hun identiteit, de
personalisatie-instellingen; van een collectie het id, de slug, de
afbeeldingen, `is_active`, `show_related_products` en de sorteervolgorde; en
elke relatie ertussen. Een bezoeker die van taal wisselt leest andere woorden
en krijgt hetzelfde product, voor dezelfde prijs, opgezocht op hetzelfde id, in
een winkelwagen met dezelfde regel. `MultilingualBoundaryTest` en
`ShopWordsMigrationTest` bewaken beide kanten: geen vertaaltabel van de Shop
kent een van die kolommen, en na de migratie staat elke prijs, elk id en elke
slug nog precies zoals hij stond.

**Een momentopname is geen vertaling.** `order_items.product_name(_en)` is wat
een product **heette** toen iemand het kocht. Dat is geen vertaling van wat het
nu heet, en het mag nooit meer veranderen — niet als het product hernoemd
wordt, niet als het verwijderd wordt, en niet als de standaardtaal van de site
verschuift. Daarom heeft het een eigen klasse met een eigen leesregel:

- `order_items.product_name` blijft één **taalvrije** naam op de regel zelf.
  Dat is wat de factuur, de bevestigingsmail en het CMS-besteloverzicht
  afdrukken, en dat is het enige wat zij lezen.
- `order_item_translations` houdt de **andere** taalversies, voor de
  besteloverzichtspagina die een bezoeker beide helften aanbiedt.
- De leesregel is "de rij van deze taal, anders de taalvrije momentopname" —
  níet `LanguageFallback`. De taalvrije naam is de terugval *door zijn vorm*
  in plaats van doordat hij van een taal is, dus een taal toevoegen,
  verwijderen of tot standaard maken kan een geplaatst document niet raken.
  Dat is ook precies wat de browser al deed: `assets/js/shop/shop.js` rendert
  een regel als `item.name_en || item.name`.

Wat afrekenen schrijft: de taalvrije naam is de naam in de **standaardtaal**,
plus één rij per andere actieve websitetaal die eigen woorden heeft —
`rawProduct()`, niet `product()`, zodat een taal zonder eigen naam geen kopie
van de standaardtaal krijgt. Op een Nederlandstalige site met Nederlands en
Engels is dat byte voor byte wat de twee kolommen hielden.

**De kop boven de gerelateerde producten** was de enige gelokaliseerde
*instelling* van deze golf. Hij gaat naar de bestaande catalogus
`site_setting_translations` (`App\Service\LocalizedSiteSettings`), niet naar
een tabel van de Shop zelf, omdat hij echt site-breed is: één kop voor de hele
winkel, op één scherm. De kop van een collectie is wél een woord van die
collectie. `RelatedProductsContent::heading()` leest ze samen als één
voorrangsketen:

1. de kop van de collectie in deze taal;
2. de kop van de collectie in de standaardtaal;
3. de algemene instelling in deze taal;
4. de algemene instelling in de standaardtaal;
5. niets — en dan rendert het blok geen kop.

De collectie wint dus **als eenheid**: een collectie die überhaupt een kop
heeft spreekt voor zichzelf, in welke taal ze hem ook heeft. Binnen elke bron
geldt de gewone terugval, en dat is `LanguageFallback`'s ene regel, twee keer
toegepast in bronvolgorde — geen tweede terugval van de Shop zelf. Op een
Nederlandstalige site geeft dit waarde voor waarde dezelfde twee ketens als
vóór de golf; `RelatedProductsContentTest` legt dat vast. Op een site met een
ándere standaardtaal verschilt het bewust: de oude Engelse keten eindigde op de
Nederlandse kop, en één terugvalregel kan dat niet.

**Sorteren mag niet van de lezer afhangen.** `products.name` en
`collections.name` waren kolommen om op te sorteren — het productoverzicht van
het dashboard, de personalisatielijst en zijn productkiezer deden dat alle
drie. Ze sorteren nu taalneutraal in SQL (op `id`, respectievelijk op de
`sort_order` die de beheerder zelf sleepte), en waar een alfabetische lijst
telt, sorteert het scherm op het **CMS-label** (de standaardtaal), zodat de
lijst in elke CMS-taal dezelfde volgorde heeft.

**De editors.** `admin/product-form.php`, `admin/collection.php` en
`admin/related-products.php` staan op `admin/_localized_fields.php`: één naam,
één beschrijving, één SEO-paar en één kop, in de taal die de schil aanwijst.
Een **nieuw** product en een **nieuwe** collectie worden in de standaardtaal
geschreven, zoals een nieuwe pagina en een nieuw bericht, zodat de slug uit een
naam komt die de winkel ook echt toont. De slug van een collectie blijft
taalneutraal: wordt het slugveld leeg gelaten, dan wordt hij hergenereerd uit
de naam in de **standaardtaal** — nooit uit de vertaling op het scherm.

**De migraties.** `20260918200000` maakt de twee tabellen, `20260918210000`
verhuist negentien kolommen plus de twee instellingsrijen en dropt ze in
dezelfde stap; `20260918220000` maakt de momentopnametabel en `20260918230000`
verhuist `order_items.product_name_en` en dropt die ene kolom. `product_name`
zelf wordt niet aangeraakt.

### Personalisatie (golf D)

| Tabel | Eigenaar | Velden (max) | Domein-API |
|---|---|---|---|
| `product_personalization_translations` | `settings_id` | `instructions` (500) | `App\Service\Personalization\PersonalizationLocalization` |
| `product_personalization_view_translations` | `view_id` | `label` (100) | idem |
| `product_personalization_zone_translations` | `zone_id` | `label` (100), `instructions` (500), `placeholder` (100) | idem |

Dezelfde vorm en dezelfde regels als de vorige drie golven. Niets hiervan is
rich text — een uitleg, een label en een voorbeeldtekst worden ge-escaped
afgedrukt — dus deze API heeft geen sanitizer en hoort die ook niet te hebben.

**Woorden zijn niet de configuratie.** Dat is hier de regel waar alles aan
hangt. Wat bepaalt wat een klant mág doen blijft op de eigen rij en is in elke
taal hetzelfde: `view_key` en `zone_key` — de sleutels waar een bestelregel
naar wijst — de geometrie (`area_x`, `area_y`, `area_width`, `area_height`),
`allow_text`, `allow_image`, `is_enabled`, `is_required`, `allow_rotation`,
`max_text_length`, `surcharge`, de lettertype-instellingen,
`personalization_mode`, de voorbeeldafbeelding en elke sorteervolgorde. Een
taalwissel kan dus geen zone verplaatsen, aanzetten of duurder maken.

**De momentopname van een bestelling houdt haar vorm.** Wat een zone heette
toen iemand hem kocht staat in de eigen `config_snapshot_json` (versie 3) van
die regel, met precies de sleutels die hij had — `label`/`label_en` incluis —
zodat een document van vóór deze golf net zo leest als een van erna. Er wordt
niets herschreven, en niets in deze golf leest of schrijft
`order_item_personalizations`. Eén ding verandert wél aan wat een *nieuwe*
momentopname vastlegt: een taal zonder eigen label legde vroeger `null` vast en
legt nu de terugvalwaarde vast — dus wat de klant werkelijk op het scherm zag,
wat voor een momentopname het juiste antwoord is.

**Eén terugvalregel, ook in de validator.**
`PersonalizationValidator::zoneLabel()` antwoordde "het Nederlandse label,
anders het Engelse, anders de sleutel" — een tweede terugvalregel van de module
zelf. Dat is nu "het label zoals de leeslaag het al opgeloste heeft, anders de
sleutel": de module kiest geen taal meer en `MultilingualBoundaryTest` faalt
zodra dat terugkomt.

**De editor.** `admin/_personalization_builder.php` staat op
`admin/_localized_fields.php`: één uitleg, één naam per voorbeeld en één label,
uitleg en voorbeeldtekst per zone, in de taal die de schil aanwijst. Een
**nieuw** voorbeeld en een **nieuwe** zone worden in de standaardtaal benoemd,
zodat een kaart in het CMS nooit naamloos kan zijn. De koppen van de blokken
noemen een voorbeeld en een zone bij de naam die het CMS gebruikt (de
standaardtaal), zodat ze vindbaar blijven terwijl een vertaling nog geschreven
wordt.

**De migraties.** `20260918240000` maakt de drie tabellen, `20260918250000`
verhuist tien kolommen en dropt ze in dezelfde stap.

### Golf E: de laatste instellingssleutels

Golf B verhuisde de *entiteiten* van de Blog en liet zijn twee
**tekstinstellingen** staan: `blog_title(_en)` en `blog_intro(_en)`, vier vaste
sleutels in de eigen `blog_settings` van de module. Dat waren de laatste levende
NL/EN-opslagplekken van het project. Migratie `20260918260000` haalt ze weg.

**Eén fysieke store, een catalogus per domein.** De woorden gaan naar
`site_setting_translations`, dezelfde tabel als de gelokaliseerde
Core-instellingen, maar niet in dezelfde catalogus. Het opslagprimitief is
losgetrokken:

```text
App\Service\Language\LocalizedSettings        kent geen enkele sleutel
  ├── App\Service\LocalizedSiteSettings       city, footer_description,
  │                                           footer_slogan,
  │                                           related_products_heading
  └── App\Service\Blog\BlogLocalizedSettings  blog_title, blog_intro
```

`LocalizedSettings` is voor sleutel/waarde-instellingen wat
`EntityTranslations` is voor de getypeerde vertaaltabellen: het leest en
schrijft, en verder niets. Een domein houdt er één van, geeft hem zijn eigen
gesloten lijst en zet er een getypeerde gevel op. Daarom hoeft **Core de
Blog-sleutels niet te kennen**: `LocalizedSiteSettings::KEYS` noemt
`blog_title` nergens, dus Core weet nog steeds niet dat er een blog bestaat
(`MODULES.md`), terwijl de Blog geen tweede tabel, geen tweede terugval en geen
generieke vertaalbak nodig had. Een verzoek kan bij geen van beide catalogi een
sleutel verzinnen; `MultilingualBoundaryTest` bewaakt dat er precies twee
houders van een catalogus zijn en dat hun sleutels elkaar niet overlappen.

`blog_settings` houdt wat in elke taal hetzelfde leest: de paginagrootte en de
vier schakelaars. Het instellingenscherm staat op
`admin/_localized_fields.php` en toont één websitetaal tegelijk; het endpoint
schrijft de twee stores in één transactie. De codestandaard blijft: een blog
die niemand hernoemd heeft heet in elke taal "Blog".

**Daarmee is er geen levende `_nl`/`_en`-opslag meer.** Geen kolom
(sinds golf D) en geen instellingssleutel (sinds golf E), gecontroleerd op een
database die vanaf nul is opgebouwd. Wat overblijft is geen opslag:

- de vaste `label_nl`/`label_en`-paren in **code**-catalogi (`RouteRegistry`,
  `ModuleDefinition`, `CookieConsentConfig`);
- de tijdelijke `data-nl`/`data-en`-paren en JSON-sleutels van de frontend;
- de historische migraties en hun testfixtures.

De eerste twee heeft fase 7 opgeruimd: de codecatalogi zijn kaarten per
taalcode (`SiteText::pick()`), de frontend drukt één taal af en de JSON-API's
geven één waarde per veld. De historische migraties blijven zoals ze zijn.

## Fase 7: één taal per antwoord, de module, en het einde van V1

Fase 6 gaf elke taal eigen URL's; fase 7 maakte de server de enige die een
taal kiest en ruimde op wat daardoor dood was. Zes golven, elk een commit.

### Golf A: de frontend-flip

De browser krijgt de woorden van **één** taal per antwoord, en wisselt nooit
meer een document in een andere taal.

- **`core.js`** kent geen taal meer: `applyLang()`, `initLang()`, de
  `localStorage`-sleutel `vvl-lang` en `data-primary-lang` zijn weg. De
  taalkeuze is de rij links van fase 6.
- **Geen publiek sjabloon of partial print nog een paar**: geen
  `data-nl`/`data-en`, geen `data-lang-html`, geen `-alt`/`-aria`/`-content`/
  `-placeholder`-families. `MultilingualBoundaryTest::testNoPublicTemplatePrintsALanguagePair`
  loopt alle root-sjablonen en partials af.
- **Inhoudsklassen** geven een partial één string per veld, in de taal van het
  verzoek, met de terugval al toegepast (`BlockLocalization::text()`,
  `::first()`, `::words()`), en hun caches zijn per taal gesleuteld.
- **De standaardtaal beslist of iets bestaat**, ook op `/en/` en `/de/`: een
  Tekstblok, Oproep, Contactkaart, Paginakop, de body van een Detailsectie of
  een galerij-/contactknop zonder woorden in de standaardtaal toont in geen
  taal (`BlockLocalization::hasDefaultWords()`). Vóór de flip hield alleen de
  zichtbare helft zich daaraan.
- **Systeemtekst** staat in kleine, gesloten codecatalogi per taalcode naast
  de code die hem gebruikt: `SiteText::pick()` en `::escaped()`, met terugval
  op de standaardtaal. `SeoMetadata`, het kruimelpad, de routelabels
  (`RouteRegistry`), de cookiebanner en het cookiebeleid, de meldingen van
  formulieren (`FormText` is weg), blogdata en archieftitels, de JSON-LD van
  een product en alle schiltekst van de sjablonen volgen de taal van het
  verzoek.

### Golf B: API's, Shop en winkelwagen

- **Elke publieke JSON-API** antwoordt in de taal van de pagina die vraagt
  (`?lang=`, `App\Service\Routing\ApiLanguage`: alleen een gepubliceerde taal,
  anders de standaardtaal), met één waarde per veld: `products.php`,
  `product.php`, `order-status.php` (uit de eigen naam-momentopnames van de
  bestelling), `shipping-zones.php` en `shipping-quote.php`. Geen `*_en` meer.
- **De scripts kiezen geen taal**: `cart.js`, `shop.js` en
  `personalization.js` krijgen hun zinnen uit `ShopScriptText` (een
  JSON-datablok in de mini-winkelwagen) en `PersonalizationScriptText` (in de
  configuratie van het paneel). `bilingualAttrs()`, `currentLangText()` en
  `currentLangHtml()` zijn weg — en daarmee ook dubbel ge-escapete namen en
  "Terms &amp;amp; Conditions".
- **De winkelwagen** in `localStorage`: zie *Momentopnames en opgeslagen
  browserdata* hieronder.
- Landnamen (`ShippingCountries`) en verzendprofielnamen (`ShippingProfile`)
  zijn codecatalogi; het CMS leest profielnamen in zijn interfacetaal, de
  (Nederlandstalige) bevestigingsmail in het Nederlands.

### Golf C: de V1-adapters weg

`ContentLanguages`, `LocalizedValue`, elke `bilingual()` (`LanguageFallback`,
`EntityTranslations`, `LocalizedSettings`, `LocalizedSiteSettings`,
`PageLocalization`), `renderableLanguages()`, `OrderItemNameSnapshot::pair()`,
de contentrol van `LanguageRegistry` (en `filter()`),
`ContentEditingLanguage::source()`/`isPrimary()`, `admin/_language_fields.php`,
`admin/assets/admin-language-translate.js` en de
`.admin-lang-pane`/`.admin-lang-translate`-styling. Niets daarvan had nog een
aanroeper; `MultilingualBoundaryTest::testNoStoredValueDecidesWhichLanguagesArePublished`
houdt vast dat geen applicatiecode ze terugbrengt.

- **Eén terugval bij een onleesbaar register**:
  `LanguageFallback::defaultLanguage()` (de projectstandaard). Elke plek die
  vroeger "het register, anders `ContentLanguages::primary()`" deed, vraagt
  die.
- **`LanguageRegistry`** is alleen nog de lijst van talen die het **CMS zelf**
  spreekt: interfacetalen, hun namen, de DeepL-codes.
- **De standaardtaal verplaatsen** gaat via `SiteLanguages::setDefault()`, voor
  het tabblad *Talen*, de installatiewizard en het endpoint.
- **Automatisch vertalen** heeft sinds de editors per taal werken (fases 2–5)
  geen knop meer; `TranslationService` en `api/admin/translate-fields.php`
  staan klaar, nu op het register (backlog).

### Golf D: de module Meertaligheid en talenbeheer

`App\Module\MultilingualModule` (sleutel `multilingual`) beslist of de website
méér dan zijn standaardtaal publiceert. Core vraagt dat op capaciteit
(`ModuleDefinition::publishesTranslations()`), één keer, in
`SiteLanguages::active()`; alles wat talen publiceert, vraagt al
`SiteLanguages`. Uit: alleen de standaardtaal, geen prefix-routes, geen
taalkeuze, geen hreflang of `x-default`, een eentalige sitemap, geen
`Accept-Language`-onderhandeling, editors en schrijf-endpoints op de
standaardtaal. Het register, de eigen vlag van elke taal en elke vertaling
blijven staan.

**Op een nieuwe installatie uit**, zoals bij *Vastgelegd voor later* al
stond. Bestaande installaties houden hun gedrag: migratie
`20260921100000` pint de module aan voor elke installatie van vóór de
installatiemarker en voor een verse installatie waarvan de wizard al klaar
was. Die keuze volgt uit de regel van elke `pin_*`-migratie — een nieuwe
standaard geldt voor een nieuwe site, nooit met terugwerkende kracht — en uit
het principe van heel 2.0 dat een bestaande site niets ziet veranderen.

Talenbeheer staat onder *Instellingen → Talen*
([`WEBSITE-LANGUAGES.md`](WEBSITE-LANGUAGES.md)): toevoegen (start uit),
namen, aan/uit, standaard, volgorde, verwijderen. `SiteLanguages` kreeg
`add()`, `rename()`, `activate()`, `deactivate()`, `move()` en `remove()`;
`SiteLanguageRepository` `activate()`, `rename()` en `move()`. Verwijderen kan
alleen voor een taal die uit staat, niet de standaard is en nergens woorden
heeft: de 21 vertaaltabellen verwijzen er met `ON DELETE RESTRICT` naar.

### Golf E: de laatste locale-links

- **Door redacteuren getypte URL's** in blokken (knoppen, kaarten, "bekijk
  alles") gaan door `App\Service\Routing\TypedLink`: extern, anker, query en
  een pad dat al een taal noemt blijven letterlijk; `/` wordt de home van die
  taal; `/<slug>` van een gepubliceerde standaardtaalpagina wordt die pagina
  in deze taal (of zijn standaardadres als hij hier geen versie heeft); het
  adres van een geregistreerde route wordt die route in deze taal, zoals een
  menulink; al het andere blijft letterlijk. Geen opslag, geen migratie, geen
  naïeve `/en`-prefix. Zie [`ROUTING.md`](ROUTING.md).
- **Sitemap**: de personalisatiecatalogus en de oude projectpagina's bestaan
  in elke gepubliceerde taal, declareren die versies (hreflang) en staan met
  alle versies in de sitemap.
- `/verzenden-retourneren` en `/privacyverklaring` waren al sinds golf A op
  content-key opgelost (`LegalPages::publishedPageUrl()`), in de taal van het
  verzoek.

### Golf F: harden en opruimen

- **Een zoektocht door de hele repository** naar `_nl`/`_en`, `data-nl`,
  `data-lang-html`, `bilingual(`, `ContentLanguages`, `LocalizedValue`,
  `admin-lang-pane` en `_language_fields`: wat in code overblijft is
  commentaar dat zegt dat het er niet meer is, de bewakers in
  `MultilingualBoundaryTest`, historische migraties met hun tests, en de
  upgrade van oude winkelwagenregels in `cart.js`.
- **`db/seeds/ProductSeeder.php`** schreef nog `products.name`/`name_en`, die
  fase 5 gedropt had, zodat `phinx seed:run` uit de README op elke
  installatie faalde. Hij schrijft de woorden nu als `product_translations` in
  de standaardtaal; `ProductSeederSchemaTest` leest hem tegen het schema.
- **De blogindex** noemde in zijn `<head>` geen enkele taalversie, terwijl de
  sitemap hem met alternates gaf: `blog.php` verklaarde alleen de versies van
  een categorie- of tagarchief. Hij verklaart nu ook de zijne
  (`MultilingualModuleHttpTest`); gevonden met het browserharnas.
- **Een uitgezette taalprefix** (of elke taal behalve de standaard met de
  module uit) antwoordt in `dispatcher.php` meteen 404, niet eerst met de
  permanente redirect die een sluitende slash weghaalt. Die redirect bleef in
  de browser hangen en gaf een lus zodra de taal weer aan ging (`/de` → `/de/`
  → `/de`). Gevonden met het browserharnas; `MultilingualModuleHttpTest` houdt
  het vast.
- **`shop.js`** las op de productpagina nog een variabele (`titleText`) die
  golf B met de oude `currentLangText()`-regel had weggehaald. Elke
  productpagina gooide daardoor een fout in `renderProduct()`, die de
  fetch-keten opving als "product niet gevonden". Geen PHP-test voert het
  script uit; `ShopScriptTextContractTest` pint de declaratie nu vast, en een
  scan van alle projectscripts op niet-gedeclareerde namen vond verder niets.
  Gevonden met het browserharnas.
- **Metingen** (SELECT's per verzoek, voor en na fase 7, op dezelfde
  geüpgradede database): gelijk op elke route behalve de checkout (21 naar
  24: de twee pagina-opzoekingen van golf A, vast en niet per product); de HTML
  is 25–30% kleiner omdat elke tekst er nog één keer in staat.

### Momentopnames en opgeslagen browserdata

Wat opgeslagen is op het moment van een bestelling, of in de browser van een
klant, blijft leesbaar; het wordt nooit herschreven.

| Opslag | Beleid |
|---|---|
| `order_item_translations` (`OrderItemNameSnapshot`) | de historische naam per taal; een bestelling toont de naam in de taal van de pagina, anders de neutrale momentopname, nooit de huidige productnaam |
| Personalisatie `config_snapshot_json` | versie 4 legt **één** label vast, in de standaardtaal; versies 1–3 met `label` + `label_en` blijven leesbaar zoals ze zijn opgeslagen |
| Winkelwagen in `localStorage` | lezen-oud/schrijven-nieuw: een regel is product + variant + personalisatie; `name` + `lang` zijn weergave en worden na een taalwissel één keer per id opnieuw gelezen. Een regel van vóór fase 7 (`name` + `name_en`, zone `label` + `label_en`) wordt in `upgradeLegacyLines()` gelezen en in de nieuwe vorm teruggeschreven — de enige plek die de oude veldnamen kent |
| `site_language`-cookie | alleen een taalcode; beslist alleen iets op de siteroot |
| Cookie-toestemming | taalneutraal; een taalwissel reset niets |

Geen van deze vraagt om meerdere taalversies tegelijk op te slaan.

### Codecatalogi: wat is CMS-tekst, wat is sitetekst

| Catalogus | Soort | Waar |
|---|---|---|
| `RouteRegistry` routelabels | sitetekst (kruimelpad) en CMS-tekst (`adminLabel()`) | `label()` via `SiteText::pick()`, `adminLabel()` via `AdminLocale` |
| `ModuleDefinition::label()`/`description()` | CMS-tekst (wizard, zijbalk) | Nederlands, zoals elke CMS-tekst voor de redacteur |
| `CookieConsentConfig` | sitetekst | `SiteText::pick()` |
| `LanguageDefinition` | CMS-tekst (namen van talen in de interface) | `labelIn()` per interfacetaal |
| `PersonalizationColors` | sitetekst (kleurnamen), momentopname in de standaardtaal | `SiteText::pick()` |
| `ShippingProfile`, `ShippingCountries`, `PickupLocation` | sitetekst, en CMS-tekst waar het CMS ze toont | `SiteText::pick()` met de taal van de aanroeper |
| `ShopScriptText`, `PersonalizationScriptText` | sitetekst voor scripts | JSON in de taal van het verzoek |
| `BlogContent` maanden, `BlogSeo` paginaknip | sitetekst | `SiteText::pick()` |

Geen van deze wordt opgeslagen en geen heeft een `label_de`: een derde taal is
een sleutel in de catalogus, anders valt hij terug op de standaardtaal.

### Neutrale slugkolommen

Bewust **niet** gedropt:

- `pages`, `blog_posts`, `blog_categories`, `blog_tags`, `collections`: de
  neutrale `slug` is het adres van de standaardtaal en blijft byte-gelijk aan
  de standaardrij (migratie `20260920100000`). Hij bedient de
  terugval voor rijen die alleen de neutrale kolom schreven (fixtures,
  scripts), bestaande redirects en oude links.
- `portfolio_gallery_items`, `portfolio_categories`: één slug voor elke taal
  (`/portfolio/<slug>`, de filterbalk); per-taal-slugs zijn er niet.
- `products`: een interne sleutel, geen adres — een product blijft
  `/product.php?id=N`.

## Backlog na fase 7

- **Meldingen van de checkout-API** (`api/checkout.php`, en de uitzonderingen
  van adrescontrole, verzending en personalisatievalidatie) zijn nog Engels,
  in elke taal. Geen V1-paar, wel een gat.
- **De orderbevestiging** is Nederlandstalig, in welke taal er ook besteld is.
- **Automatisch vertalen** heeft geen knop in de editors per taal.
- **Productopties en variantlabels** zijn één-talige gegevens.
- **Publicatie per taal** als eigen vlag bestaat niet: een taalversie is
  publiek zodra hij een adres heeft.
- **Vimexx-staging**: het `.htaccess`-gedrag van fase 6 is lokaal alleen
  tegen Apache in Docker bewezen.

## Nog niet, bewust

**Gebouwd in de routingfase (6)**, zie [`ROUTING.md`](ROUTING.md):

- slug per taal, met `UNIQUE(language_code, slug)`, op `page_translations` en
  op de vier moduletabellen die een slug-URL hebben;
- `/xx/`-prefixen en de dispatcher;
- hreflang, sitemap-alternates en een canonical per taal;
- `<html lang>` per URL, en `Accept-Language` op de siteroot.

**Nog niet gebouwd:** *publicatie* per taal als eigen vlag. Een taalversie is
publiek zodra hij een adres heeft en de pagina zelf gepubliceerd is; een
aparte `is_published` per taal is er niet, en fase 6 had hem niet nodig.

**In het voorbijgaan gevonden, niet in Pages:**

- De rich-textbug uit fase 2 (een Tekstblok toonde op een Engelstalige site bij
  de eerste render de Nederlandse body) is in fase 3A opgelost, zie
  *Uitvoer* bij de contentblokken; voor de Detailsectie in fase 3B.
- **De zichtbare `<title>` en `content` van de meta description** in
  `partials/seo-head.php` printten de NL-helft, ook als Engels de
  standaardtaal was. Opgelost in fase 6: `SeoMetadata::title()` en
  `::description()` geven de taal van het verzoek, en Open Graph en Twitter
  volgen dezelfde waarden.

## V1-scope van Multilingual 2.0

**Binnen:** Multilingual als module die standaard uit staat; dynamische
websitetalen; één standaardtaal; talen toevoegen, uitzetten en ordenen; een
dynamische editor; server-side weergave; handmatig vertalen; gelokaliseerde
URL's en slugs; canonical, hreflang en sitemap; een taalwisselaar;
`Accept-Language` als suggestie; de keuze van een bezoeker onthouden; een
terugval op veldniveau. Sinds fase 7 is dat allemaal gebouwd.

**Buiten, en voor geen enkele fase een voorwaarde:** externe API's
(automatisch vertalen, DeepL, Azure), bulkvertaling, een statusworkflow voor
vertalingen, land- of IP-detectie, meerdere providers. De bestaande
providerklassen blijven ongebruikt staan.

## Vastgelegd voor later, nog niet gebouwd

- **Hybride opslag.** Echte domeinentiteiten krijgen **getypeerde**
  `<entiteit>_translations`-tabellen: FK met `ON DELETE CASCADE`, één rij per
  taal, ook voor de standaardtaal. Gebouwd voor pagina's (fase 2) en voor
  menu-items, footer, formulieren, velden en opties (fase 4) en voor
  blogberichten, categorieën, tags, producten, collecties, portfolio-items en
  personalisatie (fase 5). Productopties en variantlabels zijn nog één-talig
  (backlog).
- **Contentblokken** op één generieke `block_translations`, met de
  weesrij-guards: gebouwd in fase 3A en 3B, alle bloktypes en hun kindrijen,
  zie *Contentblokken per taal*.
- **Gelokaliseerde slugs**: gebouwd in fase 6 voor pagina's, blogberichten,
  blogcategorieën, blogtags en collecties, met `UNIQUE(language_code, slug)`.
  De neutrale `slug`-kolommen blijven staan als sleutel van de standaardtaal.
- **Productslugs per taal** vallen buiten deze keten. Een product houdt
  `/product.php?id=N`, met een taalprefix voor elke niet-standaardtaal.
- **Router.** Eén dunne `dispatcher.php` achter `.htaccess`: gebouwd in fase 6.
  De bestaande templates blijven renderen, er is geen front controller en geen
  frameworklaag. **Nog te doen: het bewijs op een Vimexx-staging** — het
  `.htaccess`-gedrag, `MultiViews` en een eventuele cachelaag zijn lokaal niet
  na te bootsen, en lokaal is alleen tegen Apache in Docker getest.
- **Verse installaties** krijgen de module `multilingual` **uit**: gebouwd
  in fase 7.
- **Bestaande installaties met de oude NL/EN-wissel** houden bij de flip hun
  gedrag: de module aan (migratie `20260921100000`), hun talen zoals ze
  stonden. Gekozen in fase 7, zie *Golf D*.
- **Oude kolommen** vallen per domein, in de migratie van de fase die dat
  domein omzet. Dat mag pas na een grep die bewijst dat geen andere fase ze
  nog leest. Pages is zo gegaan in fase 2 (`20260917150000`), de drie
  proof-blocks in fase 3A (`20260917170000`), alle overige blokken in fase 3B
  (`20260917180000`, `190000`, `200000`) en navigatie, footer, instellingen en
  formulieren in fase 4 (`20260918110000`, `130000`, `150000`), de modules in
  fase 5; `MultilingualBoundaryTest` bewaakt dat niets de gedropte kolommen nog leest.

## Waar het staat

| Wat | Waar |
|---|---|
| Schema en bootstrap | `db/migrations/20260917120000_create_the_site_language_registry.php` |
| SQL en invarianten | `src/Repository/SiteLanguageRepository.php` |
| Core-API | `src/Service/Language/SiteLanguages.php`, `SiteLanguage.php`, `LanguageCode.php` |
| Module en talenbeheer (fase 7) | `src/Module/MultilingualModule.php`, `ModuleDefinition::publishesTranslations()`; het tabblad *Talen* in `admin/settings.php`; `api/admin/create-`, `update-`, `toggle-`, `move-` en `delete-website-language.php`, `update-multilingual-publishing.php`; `db/migrations/20260921100000_pin_the_multilingual_module_where_it_is_in_use.php` |
| Uitvoer in één taal (fase 7) | `SiteText::pick()`/`escaped()`, `App\Service\Routing\ApiLanguage`, `App\Service\Routing\TypedLink`, `ShopScriptText`, `PersonalizationScriptText`, `ShippingCountries`, `LegalPages::publishedPageUrl()` |
| Tests fase 7 | `ShopScriptTextContractTest`, de fase-7-grenzen in `MultilingualBoundaryTest` (`fast`); `ShopApiLanguageTest` (`shop`); `TypedLinkTest` (`cms`, `blocks`); `WebsiteLanguageAdminHttpTest` (`cms`); `MultilingualModuleTest`, `MultilingualModuleHttpTest` (`modules`); `MultilingualModulePinTest` (`migration`, `cms`); `ProductSeederSchemaTest` (`migration`, `shop`) |
| Tests | `LanguageCodeTest`, `SiteLanguagesTest` (`fast`); `SiteLanguageRepositoryTest` (`cms`); `SiteLanguageRegistryMigrationTest` (`migration`); grenzen in `MultilingualBoundaryTest` |
| Paginatekst: schema en verhuizing | `db/migrations/20260917140000_create_the_page_translations_table.php`, `20260917150000_move_page_text_into_page_translations.php` |
| Paginatekst: SQL, rij en API | `src/Repository/PageTranslationRepository.php`, `src/Service/PageTranslation.php`, `src/Service/PageLocalization.php` |
| Editorcomponent | `admin/_localized_fields.php`, gebruikt door `admin/page.php`, `admin/page-new.php` en (fase 3A) `admin/rich-text.php`, `admin/cta-band.php`, `admin/contact-card.php`; schrijven in `api/admin/update-page.php`, `create-page.php` en de drie blok-endpoints |
| Schakelaar in de schil | `ContentEditingLanguage::choices()`, `admin/_header.php`, `.admin-sidebar__contentlang-option.is-default` |
| Tests fase 2 | `PageLocalizationTest` (`fast`); `PageTranslationRepositoryTest`, `PageLocalizationEditorHttpTest` (`cms`); `PageTranslationMigrationTest` (`migration`); de Pages-grenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\PageFixture` |
| Blokwoorden: schema en verhuizing | `db/migrations/20260917160000_create_the_block_translations_table.php`, `20260917170000_move_rich_text_cta_band_and_contact_card_words_into_block_translations.php`; fase 3B `20260917180000_move_page_hero_form_and_gallery_words_into_block_translations.php`, `20260917190000_move_homepage_hero_and_repeater_words_into_block_translations.php`, `20260917200000_move_text_image_detail_and_carousel_words_into_block_translations.php` |
| Blokwoorden: SQL, declaratie en API | `src/Repository/BlockTranslationRepository.php`, `src/Service/Blocks/TranslatableField.php`, `src/Service/Blocks/BlockLocalization.php`, `BlockDefinition::translatableFields()` en `::childTables()`, `BlockImage::fromOwner()` |
| Blokwoorden: integriteit | `SectionRegistry::delete()`, `BlockLocalization::orphans()`/`purgeOrphans()`, `scripts/block-translation-orphans.php` |
| Blokwoorden: uitvoer | `BlockLocalization::text()`/`first()`/`words()`/`hasDefaultWords()`; preload in `SectionRegistry::renderPage()` en `admin/page.php` |
| Blokwoorden: de drie blokken | `RichTextBlock`, `CtaBandBlock`, `ContactCardBlock` met hun `*Content`, repository, partial, editor en endpoint; consumenten `LegalPages`, `portfolio-detail.php` |
| Tests fase 3A | `TranslatableFieldTest`, `BlockLocalizationTest`, `BlockLocalizedRenderingTest` (`fast`); `BlockTranslationRepositoryTest`, `BlockTranslationIntegrityTest`, `BlockWordsPreloadTest`, `BlockLocalizationEditorHttpTest`, `BlockTranslationSchemaTest` (`blocks`); `BlockTranslationMigrationTest` (`migration`); het declaratiecontract in `BlockDefinitionContractTest`; de blokgrenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\BlockTextFixture` |
| Tests fase 3B | `RemainingBlocksRenderingTest` (`fast`); `BlockTranslationTreeTest`, `BlockWordsEditorHttpTest`, `BlockChildWordsEditorHttpTest` (`blocks`); `RemainingBlockWordsMigrationTest` (`migration` en `blocks`); de kindrijen in `BlockTranslationIntegrityTest`, `BlockTranslationSchemaTest`, `BlockDefinitionContractTest` en `BlockWordsPreloadTest`; de 3B-grenzen in `MultilingualBoundaryTest` |
| Fase 4: het fundament | `src/Service/Language/LanguageFallback.php`, `TranslationTable.php`, `EntityTranslations.php`, `src/Repository/EntityTranslationRepository.php` |
| Fase 4: navigatie en footer | `db/migrations/20260918100000_create_the_navigation_and_footer_translation_tables.php`, `20260918110000_move_navigation_and_footer_labels_into_translation_tables.php`; `src/Service/NavigationLocalization.php`, `src/Service/FooterLocalization.php` |
| Fase 4: site-instellingen | `db/migrations/20260918120000_create_the_site_setting_translations_table.php`, `20260918130000_move_localized_site_settings_into_site_setting_translations.php`; `src/Service/LocalizedSiteSettings.php`, `src/Repository/SiteSettingTranslationRepository.php` |
| Fase 4: formulieren | `db/migrations/20260918140000_create_the_form_translation_and_option_tables.php`, `20260918150000_move_form_words_and_options_into_translation_tables.php`; `src/Service/Forms/FormLocalization.php`, `FormOption.php`, `FormFieldOptions.php`, `src/Repository/FormFieldOptionRepository.php` |
| Tests fase 4 | `EntityTranslationsTest`, `FormFieldTypeTest` (`fast`); `LocalizedSiteSettingsTest`, `NavigationFooterTranslationTest`, `NavigationAdminHttpTest`, `FooterAdminHttpTest`, `FormAdminHttpTest`, `FormFieldEditorHttpTest` (`cms`); `NavigationFooterLabelMigrationTest`, `LocalizedSiteSettingMigrationTest`, `FormWordsAndOptionMigrationTest` (`migration`); de fase-4-grenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\FormFixture` |
| Fase 5 golf A: Portfolio | `db/migrations/20260918160000_create_the_portfolio_translation_tables.php`, `20260918170000_move_portfolio_words_into_translation_tables.php`; `src/Service/PortfolioLocalization.php`; `PortfolioGalleryContent`, `CollectionGalleryItems`, `partials/section-item-gallery.php`, `portfolio-detail.php`, `admin/portfolio.php`, `admin/portfolio-item.php` en de vier `*-portfolio-*`-endpoints |
| Tests fase 5 golf A | `PortfolioLocalizationTest` (`fast`); `PortfolioTranslationTest`, `PortfolioItemContentTest`, `PortfolioItemEditingHttpTest`, `PortfolioProjectPageTest`, `PortfolioPageLinkTest` (`cms`); `PortfolioModuleHttpTest` (`modules`, ook het bewaren bij module uit/aan); `PortfolioWordsMigrationTest` (`migration`); de golf-A-grenzen in `MultilingualBoundaryTest` |
| Fase 5 golf B: Blog | `db/migrations/20260918180000_create_the_blog_translation_tables.php`, `20260918190000_move_blog_words_into_translation_tables.php`; `src/Service/Blog/BlogLocalization.php`; `BlogContent`, `BlogSeo`, `BlogFeed`, `BlogPostService`, `BlogPostMediaUsage`, de drie Blog-repositories, `blog.php`, `blog-post.php`, `admin/blog*.php` en de vijf `*-blog-*`-endpoints; `EntityTranslations::ownersMatching()` in het fundament |
| Tests fase 5 golf B | `BlogLocalizationTest` (`fast`); `BlogPostLifecycleTest`, `BlogTaxonomyTest`, `BlogSeoTest`, `BlogMediaAndSettingsTest` (`blog`); `BlogWordsMigrationTest` (`migration` en `blog`); de golf-B-grenzen in `MultilingualBoundaryTest` |
