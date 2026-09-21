# Meertaligheid testen

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Welke tests de
meertaligheid bewaken en welke suite je na een wijziging draait. Commando's en
tiers staan in [`TESTING.md`](../../TESTING.md).

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

Draait de testcontainer niet, of draait je worktree anders, volg dan
[`TESTING.md`](../../TESTING.md): "Het commando", "`fast` wil wél dat alle
modules aan staan" en "Vanuit een git worktree".

## Per wijziging

| Wijziging | Draai |
|---|---|
| Talenregister, sitetalen, terugvalregel | `fast` |
| Het register `site_languages` of zijn repository | `fast` → `cms` |
| CMS-taal, de catalogi, een nieuwe sleutel | `fast` |
| De bewerktaal, of de onafhankelijkheid van de drie | `fast` |
| Vertaalprovider, vertaalstatus, DeepL | `fast` |
| Een editor aansluiten op de taalvelden | `fast` → `blocks` |
| Instellingen-, account- of wizardscherm | `fast` → `cms` |
| Migratie of wat een verse installatie krijgt | `migration` |
| Paginatekst per taal (`PageLocalization`, `page_translations`, de pagina-editor) | `fast` → `cms` |
| Blokwoorden per taal (`BlockLocalization`, `block_translations`, een omgezet blok of zijn editor) | `fast` → `blocks` |
| Navigatie-, footer-, formulier- of instellingentekst per taal (fase 4: een getypeerde `*_translations`-tabel, `LocalizedSiteSettings`, een optie of zijn label) | `fast` → `cms` |
| Woorden van een module per taal (fase 5: `PortfolioLocalization`, `BlogLocalization`, `ShopLocalization`, `PersonalizationLocalization`, `OrderItemNameSnapshot`) | `fast` → de suite van de module |
| Een gelokaliseerde **instelling** van een module (fase 5 golf E: `BlogLocalizedSettings` op de gedeelde `site_setting_translations`) | `fast` → de suite van de module |

## De bestanden

In `fast`:

- `tests/Service/LanguageRegistryTest.php`
- `tests/Service/LocalizedValueTest.php`
- `tests/Service/AdminLocaleTest.php`
- `tests/Service/ThreeLanguageStatesTest.php`
- `tests/Service/TranslationProviderTest.php`
- `tests/Service/MultilingualBoundaryTest.php`
- `tests/Service/LanguageCodeTest.php`
- `tests/Service/SiteLanguagesTest.php`
- `tests/Service/PageLocalizationTest.php` — de Pages-API van fase 2: terugval
  per veld, de naam in het CMS, de NL/EN-uitvoeradapter en een derde taal
- `tests/Service/TranslatableFieldTest.php` — fase 3A: wat een veldsleutel mag
  zijn, trimmen en saneren, verplicht alleen in de standaardtaal
- `tests/Service/BlockLocalizationTest.php` — de Blocks-API: terugval, `raw()`
  zonder terugval, rich text gesaneerd bij het lezen, de naam in het CMS, de
  NL/EN-adapter bij een NL-, EN- en Duitse standaardtaal, het gesloten register
- `tests/Service/BlockLocalizedRenderingTest.php` — de drie omgezette blokken
  door hun echte partials: eerste render in de standaardtaal, terugval, een
  derde taal, `data-lang-html` alleen voor rich text, kwaadaardige markup
- `tests/Service/RemainingBlocksRenderingTest.php` — fase 3B: de overige
  blokken door hun echte partials, met woorden op hun kindrijen; de
  Detailsectie-body bij een NL-, EN- en Duitse standaardtaal, een ontbrekende
  vertaling, de sanitizer en `data-lang-html`; platte labels met HTML erin,
  alt-teksten met aanhalingstekens, de kop van de Openingssectie homepage en de
  quicknav

Alle dertien de bestanden zitten in `fast`: geen database, geen webserver, geen
netwerk. Het talenregister vervangen ze in het geheugen met
`Tests\Support\SiteLanguageFixture`.

In `cms`:

- `tests/Repository/NavigationFooterTranslationTest.php` — fase 4: de drie
  getypeerde tabellen van navigatie en footer tegen echte rijen, opslaan per
  taal, een Duitse rij zonder schemawijziging, en wat er gebeurt als een
  menu-item, kolom of link verdwijnt
- `tests/Repository/LocalizedSiteSettingsTest.php` — fase 4: de gesloten
  catalogus van Core's gelokaliseerde instellingen, een geweigerde sleutel, een
  geweigerde taal, en opslaan per taal
- `tests/Blog/BlogLocalizedSettingsTest.php` — fase 5 golf E: dezelfde vragen
  voor de eigen catalogus van de Blog, plus dat de twee catalogi dezelfde
  fysieke tabel delen zonder elkaars sleutels te kunnen lezen of schrijven, dat
  de module uitzetten geen woord weggooit, en dat `blog_settings` de vier oude
  sleutels niet terug kan krijgen
- `tests/Repository/SiteLanguageRepositoryTest.php` — de invarianten van
  `site_languages` tegen de testdatabase, elke test in een transactie die
  wordt teruggedraaid
- `tests/Repository/PageTranslationRepositoryTest.php` — `page_translations`:
  uniek per taal, de twee foreign keys, opslaan per taal en een Duitse rij
  zonder schemawijziging
- `tests/Service/PageLocalizationEditorHttpTest.php` — de pagina-editor over
  echte HTTP: één taal op het scherm, opslaan per taal, de verplichte titel in
  de standaardtaal, een geweigerde taal, Duits via het register, een nieuwe
  pagina in de standaardtaal, en de NL/EN-uitvoer op de publieke pagina en in
  het concept-voorbeeld

In `blocks`:

- `tests/Repository/BlockTranslationRepositoryTest.php` — `block_translations`:
  uniek per veld, de taal als foreign key, opslaan per taal, één query voor veel
  eigenaren, wezen vinden en opruimen
- `tests/Service/BlockTranslationIntegrityTest.php` — een blok of pagina
  verwijderen neemt de woorden mee, voor elk omgezet bloktype en al zijn
  kind- en kleinkindrijen; een mislukte delete niets
- `tests/Repository/BlockTranslationTreeTest.php` — fase 3B: de boom van een
  blok (ouder, kind, kleinkind) in één query laden en in één keer verwijderen
- `tests/Service/BlockWordsPreloadTest.php` — één query voor de woorden van een
  hele pagina, ook van alle items van een repeater
- `tests/Service/BlockLocalizationEditorHttpTest.php` — de drie blok-editors
  over echte HTTP: één taal, opslaan per taal, Duits via het register,
  knopregels, sanitizer, een geweigerde save in zijn eigen taal
- `tests/Service/BlockWordsEditorHttpTest.php` — fase 3B: de editors van de
  overige blokken over echte HTTP, tabelgestuurd: één taal, opslaan per taal,
  verplicht alleen in de standaardtaal, Duits via het register, te lang, een
  geweigerde save; plus de galerijknop, de Detailsectie-body en het
  beeldformulier
- `tests/Service/BlockChildWordsEditorHttpTest.php` — fase 3B: de twaalf
  kindtabellen over echte HTTP: een nieuw item in de standaardtaal, opslaan in
  één taal laat de andere talen en items staan, Duits, verwijderen neemt de
  woorden mee; plus het beeldformulier van een kaart
- `tests/Install/BlockTranslationSchemaTest.php` — de vorm van de tabel, dat
  geen bloktabel nog een `_nl`/`_en`-kolom heeft, en dat elke kindtabel echt
  met een cascaderende foreign key aan zijn ouder hangt

De migratietests, `MigrationTableNamesTest`,
`ContentLanguageSettingRepairTest`, `SiteLanguageRegistryMigrationTest`,
`PageTranslationMigrationTest`, `BlockTranslationMigrationTest` en
`RemainingBlockWordsMigrationTest`, staan met uitleg in
[`MIGRATIONS.md`](MIGRATIONS.md).

## Wat de twee grenstests bewaken

`ThreeLanguageStatesTest` loopt de matrix af — CMS NL/EN × bewerktaal NL/EN —
en controleert per combinatie dat de CMS-labels de interfacetaal volgen, dat
de editor naar de gekozen contenttaal wijst, dat de andere taal bewaard
blijft, en dat geen van beide voorkeuren de bezoeker raakt.

`MultilingualBoundaryTest` bewaakt de grenzen — de API-sleutel, de
onafhankelijkheid van de drie taalstaten, dat het vertaalendpoint niets
schrijft, dat de publieke taalwissel niet achter een instelling zit, dat
`::enabled()` geen opgeslagen waarde leest, dat er geen `_nl`/`_en`-kolom
verdwijnt en dat er geen hreflang binnensluipt. Sinds Multilingual 2.0 fase 1
ook: dat de taalkern en de CMS-taal elkaars opslag niet noemen, dat de
taalkern geen taalcode of taalnaam in code noemt (commentaar telt niet mee),
dat geen websitetaal via `AdminLocale` gevalideerd wordt (ook niet in de
installatiewizard), en dat alleen `SiteLanguageRepository` SQL op
`site_languages` uitvoert. Sinds fase 2 ook: dat alleen
`PageTranslationRepository` SQL op `page_translations` uitvoert en alleen
`PageLocalization` die repository gebruikt, dat niets de zes gedropte
paginakolommen nog leest, dat de terugval van paginatekst op één plek staat,
dat de editorcomponent geen taal bij naam kent, dat de pagina-endpoints alleen
via de API schrijven, en dat de schakelaar in de schil zijn talen uit het
register haalt. Sinds fase 3A ook: dat alleen `BlockTranslationRepository` SQL
op `block_translations` uitvoert en alleen `BlockLocalization` die repository
gebruikt, dat een blok verwijderen zijn woorden in dezelfde transactie
meeneemt, dat een pagina de woorden van al zijn blokken in één keer laadt, dat
de drie omgezette blokken geen gedropte kolom meer lezen en zelf geen taal
kiezen, dat alleen rich text `data-lang-html` krijgt, en dat hun editors één
taal tonen en hun endpoints alleen die taal schrijven.

`LanguageCodeTest` bewijst dat de coderegel een vorm is en geen lijst: alle
676 paren van twee kleine letters zijn geldig, ook talen die nergens in PHP
staan. `SiteLanguagesTest` doet hetzelfde voor het register met `es`, `pt`,
`pl`, `sv`, `da` en `cs`. `SetupWizardValidationTest` (`fast`) legt vast hoe
de wizard de websitetaal leest.

## Fase 5: de modules

**Golf A, Portfolio.** `PortfolioLocalizationTest` (`fast`) vraagt zonder
database wat `PortfolioLocalization` boven `EntityTranslations` toevoegt: welk
veld bij welke tabel hoort, de lengtes die de kolommen hadden, de terugval van
een kaart, het saneren van de twee rich velden *voordat* de terugval loopt, een
derde taal zonder schema- of codewijziging, en het weigeren van een veld dat
niet van dit domein is. Het legt ook vast dat de **standaardtaal** beslist of
een kaart woorden heeft: een item met alleen een vertaling heeft geen titel.

`PortfolioTranslationTest` (`cms`) doet hetzelfde tegen de echte database, met
de vragen die alleen daar te stellen zijn: één taal opslaan laat de andere
staan, een formulier dat een veld niet toont kan het niet leegmaken, een taal
zonder woorden heeft geen rij, een te lang woord wordt geweigerd in plaats van
afgekapt, en de regels die het schema afdwingt — een item verwijderen neemt
zijn woorden én de alt-teksten van zijn foto's mee (`CASCADE`), en een taal met
portfoliowoorden is niet te verwijderen (`RESTRICT`).

`PortfolioItemEditingHttpTest` (`cms`) bewijst over echte HTTP dat het endpoint
precies één taal schrijft: Nederlands opslaan laat Engels staan en omgekeerd,
een taal buiten het register wordt geweigerd en schrijft niets, en een
geweigerde opslag houdt de getypte woorden vast.
`PortfolioModuleHttpTest` (`modules`) neemt de woorden mee in zijn
momentopname, zodat "module uit en weer aan" ook over vertalingen gaat.
`PortfolioWordsMigrationTest` (`migration`) is de backfill, op een verse, een
bijgewerkte en een kapotte database.

De golf-A-grenzen in `MultilingualBoundaryTest`: dat niets de veertien gedropte
kolommen nog leest (op twee gemarkeerde uitzonderingen na — de eigen
attributenparen van de lightbox en de SEO-kop), dat de module zelf geen taal
of terugval kiest, dat alleen `PortfolioLocalization` bij de woorden komt en de
partial alles via `SiteText` print, en dat beide editors één taal tonen en hun
endpoints alleen die taal schrijven, in één transactie met de rij.

**Golf B, Blog.** `BlogLocalizationTest` (`fast`) vraagt zonder database wat
`BlogLocalization` toevoegt: welk veld bij welke tabel hoort, de lengtes die de
editor al valideerde, de terugval, het saneren van de body per taal *voordat*
de terugval loopt, een derde taal, en dat **geen enkele vertaaltabel van Blog
het woord `slug` kent** — de belofte waaraan de hele golf hangt.

De echte SQL en het gedrag staan in de bestaande Blog-suite, nu op de nieuwe
opslag: `BlogPostLifecycleTest` (een bericht met en zonder woorden, een titel
die alleen in de standaardtaal verplicht is, één taal opslaan die de andere
laat staan), `BlogTaxonomyTest` (een categorie met woorden in twee talen, een
naam die geen kolom meer is), `BlogSeoTest` en `BlogMediaAndSettingsTest`
(waar de mediabibliotheek een bericht bij naam noemt).
`BlogWordsMigrationTest` (`migration` en `blog`) is de backfill op een verse,
een bijgewerkte en een kapotte database, en let er apart op dat elke slug en
elke taxonomiekoppeling ongemoeid blijft. `BlogSettingsTextMigrationTest`
(dezelfde twee suites) doet hetzelfde voor golf E — de blogtitel en de
introtekst — en zet er een rij van een andere catalogus naast om te bewijzen
dat die byte voor byte blijft staan.

De golf-B-grenzen in `MultilingualBoundaryTest`: dat niets de zestien gedropte
kolommen nog leest, dat elke slug één taalneutrale kolom is gebleven, dat de
Blog geen taal en geen terugval zelf kiest, dat er precies **twee** plekken
zijn waar rich text door de sanitizer gaat (de weg naar binnen en de weg naar
buiten) en geen derde, dat geen Blog-query op woorden sorteert, en dat de drie
editors één taal tonen en hun endpoints alleen die taal schrijven, in één
transactie met de rij.

**Golf C, Shop.** `ShopLocalizationTest` (`fast`) vraagt zonder database wat
`ShopLocalization` toevoegt: welk veld bij welke tabel hoort, de lengtes die de
kolommen hadden, de terugval, het saneren van `description` per taal *vóór* de
terugval, een derde taal, en — de assertie waar de golf aan hangt — dat **geen
enkele vertaaltabel van de Shop iets kent waar een winkel een besluit mee
neemt**: geen slug, prijs, voorraad, verkoopkanaal, afbeeldingspad of
sorteervolgorde.

`OrderItemNameSnapshotTest` (`fast`) doet hetzelfde voor de momentopname, en is
het bestand dat uitlegt waaróm dat een eigen klasse is: de leesregel is "de rij
van deze taal, anders de taalvrije momentopname" en níet `LanguageFallback`, en
daarom verandert een geplaatst document niet als de website een taal
verwijdert, toevoegt of tot standaard maakt. Het legt ook vast dat alleen de
naam een woord werd — prijs, aantal en variantlabel blijven op de regel zelf.

`ShopEditingHttpTest` (`shop`) bewijst over echte HTTP dat de endpoints precies
één taal schrijven: Nederlands opslaan laat Engels staan en omgekeerd, een taal
buiten het register wordt geweigerd en schrijft niets, een geweigerde opslag
houdt de getypte woorden én hun taal vast, een nieuw product wordt in de
standaardtaal geschreven en krijgt daaruit zijn slug, een hergenereerde
collectieslug volgt de standaardtaal en niet het scherm, en — het belangrijkste
— een vertaling opslaan verandert geen id, geen slug en geen prijs.

Datzelfde bestand rendert ook élk Shop-scherm en controleert dat de
productvorm zijn collecties écht bij naam noemt. **Dat laatste staat er om een
reden**: een golf die kolommen dropt kan één lezer in een *sjabloon* laten
staan, en daar kijkt geen enkele andere test. Precies dat gebeurde in golf C
met de collectiekiezer van `admin/product-form.php` — een
`Warning: Undefined array key "name"` boven het formulier, met alle tests
groen. Het browserharnas ving het; de test hierboven vangt het voortaan. De
vorm om te kopiëren is niet "geen waarschuwing in de body" (die haalt de body
alleen met `display_errors` aan) maar "het scherm drukt écht af waarvoor het
naar de database ging".

De echte SQL en het leesgedrag staan in de bestaande Shop-suite, nu op de
nieuwe opslag: `CollectionContentTest`, `CollectionSeoTest`, `ProductSeoTest`,
`ProductBreadcrumbTest`, `CollectionRepositoryIntegrationTest`,
`OrderSnapshotIntegrationTest` en `ProductDeletionIntegrationTest` (die er een
test bij kreeg: een product verwijderen neemt zijn woorden mee, via `CASCADE`).
De vier `RelatedProducts*`-bestanden (`blocks`) staan op de nieuwe
voorrangsketen, met één test die alle vijf stappen naast elkaar zet.
`ShopWordsMigrationTest` en `OrderItemNameSnapshotMigrationTest` (`migration`
en `shop`) zijn de backfills op een verse, een bijgewerkte en een kapotte
database; de eerste let apart op elke prijs en elk collectielidmaatschap, de
tweede op elke regel van een geplaatste bestelling.

De golf-C-grenzen in `MultilingualBoundaryTest`: dat niets de twintig gedropte
kolommen of de twee verdwenen instellingssleutels nog leest (op de
V1-uitvoerparen van de JSON-payloads en de SEO-kop na, die als zodanig
gemarkeerd zijn), dat niets waar een winkel een besluit mee neemt een woord is
geworden, dat een momentopname de levende catalogus niet leest en de factuur,
de mail en het besteloverzicht nooit een actuele productnaam afdrukken, dat de
Shop geen taal en geen terugval zelf kiest en één sanitizer houdt, dat geen
Shop-query op woorden sorteert, en dat de drie editors één taal tonen en hun
vijf endpoints alleen die taal schrijven, in één transactie met de rij.

**Golf D, Personalisatie.** `PersonalizationLocalizationTest` (`fast`) vraagt
zonder database wat `PersonalizationLocalization` toevoegt: welk veld bij welke
tabel hoort, de lengtes die de kolommen hadden, de terugval, een derde taal, en
— de assertie waar deze golf aan hangt — dat **geen enkele vertaaltabel iets
kent waar de configuratie een besluit mee neemt**: geen `view_key`, geen
`zone_key`, geen coördinaat, geen schakelaar en geen meerprijs.

De echte SQL en het gedrag staan in de bestaande Personalisatie-suite, nu op de
nieuwe opslag: `ProductPersonalizationRepositoryIntegrationTest`,
`PersonalizationCmsSeparationTest`, `PersonalizationValidationTest`,
`PersonalizationPreviewImageTest` (waar een voorbeeld hernoemen zijn
afbeelding nooit mag raken — nu twee schrijfacties in één transactie) en
`OrderPersonalizationIntegrationTest` (waar de momentopname van een geplaatste
bestelling moet blijven zeggen wat ze zei). `Tests\Support\PersonalizationTestConfig`
is het gedeelde fixture: het spreekt nog het `<veld>`/`<veld>_en`-paar, omdat
dat is wat een test wil zeggen, en is de ene plek die weet waar elke helft
heen gaat. `PersonalizationWordsMigrationTest` (`migration` en
`personalization`) is de backfill op een verse, een bijgewerkte en een kapotte
database.

De golf-D-grenzen in `MultilingualBoundaryTest`: dat niets de tien gedropte
kolommen nog leest (op de V1-uitvoersleutels van de opgeloste configuratie na,
die als zodanig gemarkeerd zijn), dat niets waar de configuratie een besluit
mee neemt een woord is geworden, dat de module geen taal en geen terugval zelf
kiest — met een eigen assertie op de oude `label ?? label_en`-regel van de
validator — dat geen personalisatiequery op woorden sorteert, en dat de
bouwer één taal toont en zijn vijf endpoints alleen die taal schrijven, in één
transactie met de rij.

**De lezer die achterbleef.** Elke golfgrens hierboven zoekt een `_nl`/`_en`
achter een kolomnaam. Dat werkt voor het Portfolio, waar de kolommen een paar
vormden, en het ziet de Blog en de Shop niet: daar wás de Nederlandse kolom de
kale naam. Een scherm dat `$category['name']` van een repositoryrij bleef
lezen, kwam dus door elke controle heen, drukte een lege string af en zette
`Warning: Undefined array key` in het log. Zes schermen deden dat na de golven
A tot en met D; `e685c77` had er met de hand al een zevende gevonden.

Twee grenstests sluiten dat gat, en geen van beide probeert een repositoryrij
van een zelfgebouwde array te onderscheiden — dat kan een reguliere expressie
niet. Ze leggen de zeven aanroepplekken vast:
`testEveryScreenThatNamesAStrippedRowAsksTheWordsStore` eist dat elk van die
schermen de woordenopslag noemt, wat de enige plek is waar de naam nog vandaan
kan komen, en `testADeleteEndpointReadsTheNameBeforeItDeletesTheRow` eist dat
een verwijderendpoint die naam **boven** zijn eigen `delete(` leest: de
vertaalrijen hangen met `CASCADE` aan hun eigenaar, dus wie erna vraagt krijgt
`Bericht "" is verwijderd`. Het is de vorm van `e685c77` — beweer dat het
scherm echt afdrukt waarvoor het naar de database ging — statisch uitgevoerd,
omdat deze schermen geen eigen HTTP-test hebben.

## Fase 6: routing per taal

**Zonder database (`fast`)**, in `tests/Service/Routing/`:

- `AcceptLanguageTest` — de parser tegen de headers die browsers echt sturen:
  q-waarden, `q=0` als weigering, `de-DE`/`pt-BR`/`zh-Hans` versmald tot hun
  basistaal, een genegeerde `*`, en een absurd lange header die geweigerd wordt.
- `RequestPathTest` — half routing, half beveiliging: traversal, een
  gecodeerde `%2F` die nooit een scheidingsteken mag worden, controletekens,
  backslashes en de querystring die woordelijk meereist.
- `LocalizedUrlTest` — het URL-contract, met als belangrijkste de
  **standaardtaalwissel**: maak Engels de standaard en de prefixen wisselen om.
- `LanguageResolverTest` — de keten, en vooral dat een URL zonder prefix
  **niet** onderhandeld wordt.
- `RouteResolverTest` — compatibiliteit: elke URL-vorm die `.htaccess` bediende
  resolvt naar hetzelfde template met dezelfde parameters, en elke vorm die
  Apache weigerde resolvt nog steeds naar niets.
- `ReservedPathsTest` — taalcodes (ook van een uitgeschakelde taal) en elk
  woord dat een vast segment kan spellen.

**Met database (`cms`):**

- `PageLocalizedRoutingTest` — één adres per taal, strikt in beide richtingen,
  een taal zonder adres heeft geen route, de neutrale kolom telt voor de
  standaardtaal en nooit voor een andere, en een standaardtaalwissel
  verplaatst prefixen maar geen slugs.
- `DispatcherRoutingTest` — over echte HTTP, omdat een redirect een header is
  en "de URL wint van de cookie" alleen waar is als er een cookie gestuurd
  werd. PHP's ingebouwde server leest geen `.htaccess`, dus hij start met
  `tests/Support/dispatcher-router.php`, dat die regels spiegelt; `.htaccess`
  blijft de productiewaarheid.
- `PageLocalizationEditorHttpTest`, `PageUrlChangeTest`, `PageServiceTest` —
  het adres hoort bij de bewerkte taal, een vertaling krijgt haar eerste adres
  uit haar titel, en een lege vertaling laat woorden noch URL achter.
- `BlogTaxonomyAddressEditorTest` (`blog`) en `CollectionAddressEditorTest`
  (`shop`) — hetzelfde contract voor de drie schermen die het als laatste
  kregen: blogcategorieën, blogtags en collecties. Ze draaien over echte HTTP
  met `tests/Support/dispatcher-router.php` ervoor, zodat één test zowel de
  opslag als de URL die eruit komt kan bewijzen: taal A opslaan laat taal B
  staan, een hernoeming verplaatst een bestaand adres niet, een botsing geldt
  binnen één taal (plus de neutrale kolom voor de standaardtaal), `categorie`
  én `category` zijn gereserveerd, een geweigerde opslag houdt de getypte
  invoer vast op de kaart waar hij getypt werd, en het adres dat eruit komt
  antwoordt met 200 — terwijl het adres van de ándere taal 404't. De
  collectietest bewaakt bovendien dat een vertaling niets anders verplaatst:
  id, neutrale slug, productkoppelingen met volgorde, de
  gerelateerde-producteninstelling en de zichtbaarheid.
- `ProductLanguageSwitchTest` (`shop`), `BlogFeedLanguageTest` (`blog`) en
  `LinkResolverTest` — de afronding van deze fase. De wisselaar op een
  productpagina leidt naar hetzelfde id in elke taal en neemt verder niets uit
  de querystring mee; een feed is helemaal in de taal van zijn adres; en twintig
  paginalinks in een menu kosten evenveel queries als één (`Com_select`).
- `CollectionRepositoryIntegrationTest` — beide schrijvers van de
  product/collectie-koppeling **sluiten aan** bij een transactie die de
  aanroeper al open heeft. PDO weigert een geneste `beginTransaction()`, en
  sinds fase 5 golf C openen alle vier de endpoints er zelf een.

**Wat de grenstests sindsdien bewaken** (`MultilingualBoundaryTest`,
`BlogLocalizationTest`, `ShopLocalizationTest`): hreflang wordt op één plek
gerenderd en alleen uit verklaarde versies; een adres is per taal opgeslagen
maar wordt **nooit** door een terugvallende lezer gelezen — `value()`,
`name()` en `bilingual()` weigeren het — en alleen wat een URL heeft krijgt
een adres per taal (een collectie wel, een product niet).

**Twee dingen die lokaal niet te bewijzen zijn** en op een staging horen:
Apache's eigen padnormalisatie (`/en/%2e%2e` wordt `/` vóórdat er PHP draait —
identiek op de baseline, dus geen gedrag van deze fase) en het antwoord op een
ontbrekend bestand onder `/admin/` of `/assets/`, dat onder Apache via
`ErrorDocument` loopt en onder `php -S` op de dichtstbijzijnde `index.php`
terugvalt.
