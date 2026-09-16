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
| 2 | Gelokaliseerde velden (editorcomponent) + Pages | gepland |
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
letters (`nl`, `en`, `de`, `fr`, `it`). Hoofdletters en spaties eromheen worden
vergeven, al het andere wordt geweigerd: `en-gb`, `pt_BR`, drie letters, paden
en markup. De kolom is 12 tekens breed, dus regionale varianten later
toestaan wijzigt die klasse en niet het schema.

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

## Waar het staat

| Wat | Waar |
|---|---|
| Schema en bootstrap | `db/migrations/20260917120000_create_the_site_language_registry.php` |
| SQL en invarianten | `src/Repository/SiteLanguageRepository.php` |
| Core-API | `src/Service/Language/SiteLanguages.php`, `SiteLanguage.php`, `LanguageCode.php` |
| V1-adapter | `src/Service/Language/ContentLanguages.php` |
| Tests | `LanguageCodeTest`, `SiteLanguagesTest` (`fast`); `SiteLanguageRepositoryTest` (`cms`); `SiteLanguageRegistryMigrationTest` (`migration`); grenzen in `MultilingualBoundaryTest` |
