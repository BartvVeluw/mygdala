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
| 3 | Contentblokken | **gebouwd**: generiek model + drie blokken (3A), alle overige blokken met hun kindrijen (3B) |
| 4 | Navigatie, footer, instellingen, formulieren | **gebouwd**: getypeerde tabellen per domein, gelokaliseerde site-instellingen, optie-identiteit |
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
`PageLocalization::bilingual()` (hieronder). Sinds fase 3A geldt hetzelfde voor
drie bloktypes, via `BlockLocalization::bilingual()`. De rest van de site is
nog V1.

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
`admin/page-new.php`, en sinds fase 3A ook gebruikt door drie blok-editors (zie
*Contentblokken per taal*).

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
| C | Kaarten-carrousel (`card_carousel`) | `card_carousels`: eyebrow, title, lead; `carousel_cards`: title, body, image_alt, link_label; `carousel_card_tags`: label (kleinkind) |

De vaste blokken (`quicknav`, `product_grid`, `shop_collections`) hebben geen
eigen rijen en dus geen eigen woorden: de quicknav toont de labels van de
Detailsecties.

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
| `bilingual($tabel, $id, $veld)` | de tijdelijke NL/EN-adapter, hieronder |
| `words($tabel, $id)` | `bilingual()` voor elk gedeclareerd veld van één eigenaar: wat een `*Content`-klasse aan de partial geeft |
| `bilingualFirst($tabel, $id, [$veld, …])` | het eerste platte veld met woorden, per taal, en pas dan de standaardtaal: het quicknav-label (korte naam, anders de titel) |
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

### De tijdelijke NL/EN-uitvoeradapter

De publieke wissel (`data-nl`/`data-en`, `core.js`) blijft tot de flip.
`BlockLocalization::bilingual()` bouwt het paar uit `block_translations`, elke
helft al opgelost via `value()`, als `LocalizedValue`. Een inhoudsklasse geeft
de partial per veld zo'n waarde; de partial print hem met drie methodes van
`SiteText`:

| Methode | Print |
|---|---|
| `visibleOf($waarde)` | de woorden die een bezoeker eerst ziet (de standaardtaal, met terugval) |
| `attrsOf($waarde)` | het ge-escapete paar voor platte tekst: `core.js` schrijft het met `textContent` |
| `attrsForOf('alt', $waarde)` | hetzelfde paar voor een attribuut: `data-nl-alt`/`data-en-alt` bij een alt-tekst, `data-nl-aria`/`data-en-aria` bij een label |
| `htmlAttrsOf($waarde)` | het paar met `data-lang-html`, **alleen voor gesaneerde rich text**, en alleen als de talen echt verschillen |

Een partial kent zo geen taal, geen standaard en geen terugval; de flip hoeft
straks `SiteText` te veranderen en niet elke partial. Het XSS-contract blijft:
platte tekst wordt nooit `data-lang-html`, rich text is altijd
`RichTextSanitizer`-uitvoer.

**De rich-textbug is weg.** Met Engels als standaardtaal toont een Tekstblok bij
de eerste render de Engelse body (`visibleOf()`), niet de Nederlandse kolom. Op
een site met Nederlands als standaard is de uitvoer byte-identiek aan vóór 3A:
een body zonder vertaling krijgt nog steeds geen taalattributen. Sinds 3B geldt
hetzelfde voor de body van de Detailsectie, het tweede rich veld.

**Alt-teksten** zijn gewone platte velden op de rij die het beeld houdt
(`alt`, `image_alt`, `main_image_alt`). `BlockImage::fromOwner($rij, $alt)`
legt de alt-tekst van de mediabibliotheek eronder als laatste laag: een blok
zonder eigen alt-tekst in de standaardtaal krijgt die van het media-item.

**Eén uitzondering op "platte tekst is nooit HTML":** de kop van de
Openingssectie homepage. `HomepageHeroContent::titleHtml()` bouwt per taal
markup uit ge-escapete woorden en één vaste `<em>` rond de highlight, en alleen
die `<h1>` krijgt `data-lang-html`. `MultilingualBoundaryTest` bewaakt dat.

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
| `App\Service\Language\LanguageFallback` | **De** terugval van fase 4: gevraagde taal → standaardtaal → leeg. Ook `name()` (beheerdersnaam: eerste gevulde taal als extra stap) en `bilingual()` (het tijdelijke NL/EN-paar) |
| `App\Service\Language\TranslationTable` | Gesloten declaratie van één getypeerde tabel: naam, eigenaarskolom, en per veld zijn maximumlengte. Weigert `language_code` als veldnaam |
| `App\Repository\EntityTranslationRepository` | **Alle** SQL van **alle** getypeerde tabellen, gebouwd uit die declaratie: `findForOwners()`, `save()` (upsert), `delete()` |
| `App\Service\Language\EntityTranslations` | De API per tabel: cache, `words/raw/value/name/bilingual/preload/problems/save/forget` |

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

### De tijdelijke NL/EN-uitvoer

Header, footer, het Offerte-/contactformulier en het publieke formulier printen
hun paar uit de nieuwe opslag via `LanguageFallback::bilingual()` en
`SiteText::visibleOf()`/`attrsOf()`, net als de blokken. Geen nieuwe
NL/EN-kolom, geen terugval per template. Het XSS-contract blijft: platte tekst
gaat als `textContent` door `core.js`, HTML alleen met de expliciete marker. Een
menulabel, footerlabel, veldlabel en optielabel zijn altijd platte tekst. De
kop van een submenu draagt zijn paar op een eigen `<span>`, zodat de wissel de
chevron niet wist.

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

### Wat fase 5 nog moet doen

Na fase 4 staat er nog `_nl`/`_en` in de **modules**: `portfolio_gallery_items`,
`portfolio_item_images` en `portfolio_categories` (ook de kaarten in de Galerij
en Projecten lezen die nog als NL/EN-paar), Blog (`*_en`), Shop (`products`,
`collections`, `order_items`, en de gedeelde koppen
`site_settings.related_products_heading_nl/en`) en Personalisatie.
`BlockImage::fromRow()` blijft tot dan voor Blog bestaan, en
`SiteText::attrs()`/`visible()` voor alles wat nog kolommen heeft. De tijdelijke
uitvoeradapter (`BlockLocalization::bilingual()`,
`LanguageFallback::bilingual()`, `SiteText::*Of()`) blijft tot de flip in
fase 7.

## Nog niet, bewust

**Voor de routingfase (6) en de flip (7):**

- slug per taal en publicatie per taal;
- `UNIQUE(language_code, slug)`;
- `/xx/`-prefixen en de dispatcher;
- hreflang, sitemap-alternates en een canonical per taal;
- `<html lang>` per URL en `Accept-Language`.

**In het voorbijgaan gevonden, niet in Pages:**

- De rich-textbug uit fase 2 (een Tekstblok toonde op een Engelstalige site bij
  de eerste render de Nederlandse body) is in fase 3A opgelost, zie
  *De tijdelijke NL/EN-uitvoeradapter* bij de contentblokken; voor de
  Detailsectie in fase 3B.
- **De zichtbare `<title>` en `content` van de meta description** in
  `partials/seo-head.php` (gedeeld met Shop en Blog) printen de NL-helft, ook
  als Engels de standaardtaal is; `core.js` wisselt pas in de browser. Het
  paar zelf komt correct uit `PageLocalization`. De server-side weergave
  hoort bij fase 7.

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

- **Hybride opslag.** Echte domeinentiteiten krijgen **getypeerde**
  `<entiteit>_translations`-tabellen: FK met `ON DELETE CASCADE`, één rij per
  taal, ook voor de standaardtaal. Gebouwd voor pagina's (fase 2) en voor
  menu-items, footer, formulieren, velden en opties (fase 4); nog te doen voor
  blogberichten, categorieën, tags, producten, collecties, portfolio-items,
  productopties en personalisatie (fase 5).
- **Contentblokken** op één generieke `block_translations`, met de
  weesrij-guards: gebouwd in fase 3A en 3B, alle bloktypes en hun kindrijen,
  zie *Contentblokken per taal*.
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
  nog leest. Pages is zo gegaan in fase 2 (`20260917150000`), de drie
  proof-blocks in fase 3A (`20260917170000`), alle overige blokken in fase 3B
  (`20260917180000`, `190000`, `200000`) en navigatie, footer, instellingen en
  formulieren in fase 4 (`20260918110000`, `130000`, `150000`);
  `MultilingualBoundaryTest` bewaakt dat niets de gedropte kolommen nog leest.

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
| Editorcomponent | `admin/_localized_fields.php`, gebruikt door `admin/page.php`, `admin/page-new.php` en (fase 3A) `admin/rich-text.php`, `admin/cta-band.php`, `admin/contact-card.php`; schrijven in `api/admin/update-page.php`, `create-page.php` en de drie blok-endpoints |
| Schakelaar in de schil | `ContentEditingLanguage::choices()`, `admin/_header.php`, `.admin-sidebar__contentlang-option.is-default` |
| Tests fase 2 | `PageLocalizationTest` (`fast`); `PageTranslationRepositoryTest`, `PageLocalizationEditorHttpTest` (`cms`); `PageTranslationMigrationTest` (`migration`); de Pages-grenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\PageFixture` |
| Blokwoorden: schema en verhuizing | `db/migrations/20260917160000_create_the_block_translations_table.php`, `20260917170000_move_rich_text_cta_band_and_contact_card_words_into_block_translations.php`; fase 3B `20260917180000_move_page_hero_form_and_gallery_words_into_block_translations.php`, `20260917190000_move_homepage_hero_and_repeater_words_into_block_translations.php`, `20260917200000_move_text_image_detail_and_carousel_words_into_block_translations.php` |
| Blokwoorden: SQL, declaratie en API | `src/Repository/BlockTranslationRepository.php`, `src/Service/Blocks/TranslatableField.php`, `src/Service/Blocks/BlockLocalization.php`, `BlockDefinition::translatableFields()` en `::childTables()`, `BlockImage::fromOwner()` |
| Blokwoorden: integriteit | `SectionRegistry::delete()`, `BlockLocalization::orphans()`/`purgeOrphans()`, `scripts/block-translation-orphans.php` |
| Blokwoorden: uitvoer | `SiteText::visibleOf()`/`attrsOf()`/`htmlAttrsOf()`; preload in `SectionRegistry::renderPage()` en `admin/page.php` |
| Blokwoorden: de drie blokken | `RichTextBlock`, `CtaBandBlock`, `ContactCardBlock` met hun `*Content`, repository, partial, editor en endpoint; consumenten `LegalPages`, `portfolio-detail.php` |
| Tests fase 3A | `TranslatableFieldTest`, `BlockLocalizationTest`, `BlockLocalizedRenderingTest` (`fast`); `BlockTranslationRepositoryTest`, `BlockTranslationIntegrityTest`, `BlockWordsPreloadTest`, `BlockLocalizationEditorHttpTest`, `BlockTranslationSchemaTest` (`blocks`); `BlockTranslationMigrationTest` (`migration`); het declaratiecontract in `BlockDefinitionContractTest`; de blokgrenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\BlockTextFixture` |
| Tests fase 3B | `RemainingBlocksRenderingTest` (`fast`); `BlockTranslationTreeTest`, `BlockWordsEditorHttpTest`, `BlockChildWordsEditorHttpTest` (`blocks`); `RemainingBlockWordsMigrationTest` (`migration` en `blocks`); de kindrijen in `BlockTranslationIntegrityTest`, `BlockTranslationSchemaTest`, `BlockDefinitionContractTest` en `BlockWordsPreloadTest`; de 3B-grenzen in `MultilingualBoundaryTest` |
| Fase 4: het fundament | `src/Service/Language/LanguageFallback.php`, `TranslationTable.php`, `EntityTranslations.php`, `src/Repository/EntityTranslationRepository.php` |
| Fase 4: navigatie en footer | `db/migrations/20260918100000_create_the_navigation_and_footer_translation_tables.php`, `20260918110000_move_navigation_and_footer_labels_into_translation_tables.php`; `src/Service/NavigationLocalization.php`, `src/Service/FooterLocalization.php` |
| Fase 4: site-instellingen | `db/migrations/20260918120000_create_the_site_setting_translations_table.php`, `20260918130000_move_localized_site_settings_into_site_setting_translations.php`; `src/Service/LocalizedSiteSettings.php`, `src/Repository/SiteSettingTranslationRepository.php` |
| Fase 4: formulieren | `db/migrations/20260918140000_create_the_form_translation_and_option_tables.php`, `20260918150000_move_form_words_and_options_into_translation_tables.php`; `src/Service/Forms/FormLocalization.php`, `FormOption.php`, `FormFieldOptions.php`, `src/Repository/FormFieldOptionRepository.php` |
| Tests fase 4 | `EntityTranslationsTest`, `FormFieldTypeTest` (`fast`); `LocalizedSiteSettingsTest`, `NavigationFooterTranslationTest`, `NavigationAdminHttpTest`, `FooterAdminHttpTest`, `FormAdminHttpTest`, `FormFieldEditorHttpTest` (`cms`); `NavigationFooterLabelMigrationTest`, `LocalizedSiteSettingMigrationTest`, `FormWordsAndOptionMigrationTest` (`migration`); de fase-4-grenzen in `MultilingualBoundaryTest`; test-helper `Tests\Support\FormFixture` |
