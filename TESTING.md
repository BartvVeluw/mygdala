# Testen

Alles draait op PHPUnit 10. Er is geen tweede framework en geen CI-machinerie:
één `phpunit.xml`, een handvol suites, en een testdatabase die losstaat van de
ontwikkeldatabase.

Weet je nog niet waar de code staat die je test? Begin bij
[`PROJECT-MAP.md`](PROJECT-MAP.md).

## Eenmalige setup

```bash
docker exec vvld_php php scripts/test-db.php
```

Maakt `vanveluw_shop_test` aan (schema **en** rijen, gekopieerd uit
`vanveluw_shop`) en geeft `vvld_app` er rechten op. De ontwikkeldatabase wordt
alleen gelezen. Herhaal dit commando wanneer je de testdata wilt verversen —
bijvoorbeeld na een nieuwe migratie of nadat je in de CMS iets hebt toegevoegd
dat de tests moeten zien.

```bash
docker compose --profile test up -d
```

Start twee containers:

- **`vvld_php_test`** (poort 8001) — dezelfde site tegen de testdatabase. Dit
  is de container waarin je de suite draait.
- **`vvld_php_cms`** (poort 8002) — diezelfde code en diezelfde testdatabase,
  maar gestart met `MODULE_SHOP_ENABLED=false`. Dat is de CMS-only
  deployment: geen Shop, geen Personalisatie en geen Blog.
  `Tests\Module\CmsOnlyHttpTest` en `Tests\Blog\BlogRoutingTest` praten
  ermee, en slaan zichzelf over als hij niet draait.

`php_test` krijgt daarnaast `MODULE_BLOG_ENABLED=true` mee. De Blog is de enige
module die standaard uit staat (`BLOG.md`), dus zonder die regel zou elke
`/blog`-test een 404 testen. Het is dezelfde schakelaar die een site-eigenaar
gebruikt, geen test-only mechaniek.

Waarom een tweede container en niet een schakelaar in de suite: welke modules
draaien wordt gelezen uit de omgeving waarmee een proces is *gestart*. Een
test kan dat van buitenaf niet veranderen voor een draaiende webserver, en een
configuratiebestand zou gedeeld worden met de ontwikkelsite (beide mounten
dezelfde map). In-process gebruikt de suite gewoon
`App\Module\ModuleRegistry::overrideForTests()`.

## Het commando

```bash
docker exec vvld_php_test php vendor/bin/phpunit
```

Draai de suite **in `vvld_php_test`**, niet in `vvld_php`. De testcontainer
deelt filesystem én database met de webserver waar de HTTP-tests op schieten;
draai je ze uit `vvld_php`, dan schrijft een uploadtest zijn bestand in de ene
container en leest de HTTP-request het in de andere.

De snelle tiers hebben niets nodig en mogen overal draaien:

```bash
docker exec vvld_php php vendor/bin/phpunit --testsuite fast
```

## De suites

| Suite | Wat erin zit | Nodig |
| --- | --- | --- |
| `fast` | `unit` + `contract` samen | niets |
| `modules` | het modulesysteem: register, aan/uit, en hoe het CMS eruitziet met de Shop uit | deels testdatabase + `php_cms` |
| `blog` | de Blog-module: berichten, taxonomie, publicatie en inplannen, SEO, feed, media en routes | testdatabase + `php_test` (+ `php_cms`) |
| — | meertaligheid zit in `fast` en `cms`; het heeft geen eigen suite, want het is Core en raakt élk domein (`MULTILINGUAL.md`) | niets |
| `unit` | pure logica: geen database, geen webserver | niets |
| `contract` | architectuur- en broncode-afspraken (lezen `src/`, `admin/`, ...) | niets |
| `blocks` | het contentblokkensysteem: register, `page_sections`, de blokken zelf | testdatabase |
| `cms` | pagina's, navigatie, footer, routing, SEO, adminschil, vormgeving | testdatabase |
| `shop` | producten, collecties, bestellingen, facturen, verzending, mail | testdatabase |
| `personalization` | personalisatie: regels, uploads, previews, ordersnapshots | testdatabase |
| `analytics` | pageviews, botdetectie, dashboardcijfers | testdatabase |
| `http` | alles wat een echte request doet | testdatabase + `php_test` |
| `migration` | de backfill-migraties uit het verleden, plus de twee installatietests | testdatabase + MySQL-root |
| `full` | alles, precies één keer (de standaard) | testdatabase + `php_test` |

```bash
docker exec vvld_php_test php vendor/bin/phpunit --testsuite blocks
docker exec vvld_php_test php vendor/bin/phpunit --testsuite shop
docker exec vvld_php_test php vendor/bin/phpunit --testsuite modules
docker exec vvld_php_test php vendor/bin/phpunit --testsuite blog
docker exec vvld_php      php vendor/bin/phpunit --testsuite unit
```

Een testbestand mag in meerdere suites zitten — `blocks` en `http` overlappen
met opzet. `full` is de standaardsuite, dus een kaal `phpunit` draait elk
bestand precies één keer.

### De groep `migration-backfill`

De historische migratiecontroles ("de backfill is niets kwijtgeraakt") zitten
deels in eigen testklassen en deels als losse methodes tussen de bloktests.
Samen:

```bash
docker exec vvld_php_test php vendor/bin/phpunit --group migration-backfill
```

Sinds de installatie-opruiming zitten in deze suite ook
`Tests\Install\FreshInstallTest`, `Tests\Install\LegacyUpgradeTest` en
`Tests\Install\SetupCompletionTest`. Die draaien phinx vanaf nul tegen een
**wegwerpdatabase** die ze zelf aanmaken en weer weggooien
(`Tests\Support\ScratchInstall`), want de vraag "wat krijgt een nieuwe
installatie" is op de testdatabase niet te stellen: die is een kopie van
ontwikkeling en zit dus al vol met het antwoord. Ze hebben daarvoor het
MySQL-rootaccount nodig (`DB_ROOT_PASSWORD` in `.env`, net als
`scripts/test-db.php`) en slaan zichzelf over waar dat er niet is. Samen kosten
ze ongeveer 30 seconden. Zie [`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md) en
[`SETUP.md`](SETUP.md).

`SetupCompletionTest` draait de wizard bovendien in een **eigen proces**
(`tests/Support/complete-setup-cli.php`), om dezelfde reden als
`create-page-from-template.php`: `App\Database` houdt één statische verbinding
per proces vast, dus de rest van de run zou anders met de wegwerpdatabase
blijven praten.

De rest van deze groep controleert wat een migratie destijds beloofd heeft — dat de rijen die zij
aanmaakte er nog zijn, precies één keer, met dezelfde inhoud en in dezelfde
onderlinge volgorde. Ze mogen nooit leunen op het huidige totaal: een vijfde
footerkolom of een extra blok dat de redactie later aanmaakt is gewone
CMS-data en hoort deze tests niet te laten falen. Ze raken wel echte inhoud
aan en zijn trager, dus ze horen niet in de snelle ontwikkellus. Laat ze staan:
ze zijn het bewijs dat een migratie destijds niets heeft weggegooid. Wil je ze
even buiten beschouwing laten:

```bash
docker exec vvld_php_test php vendor/bin/phpunit --exclude-group migration-backfill
```

## Werkwijze

**Kleine wijziging aan een contentblok**

```
--testsuite fast        (seconden, geen Docker nodig)
--testsuite blocks
--testsuite cms         alleen als je ook aan de pagina-kant zat
```

**Wijziging aan de vormgeving of de branding**

Kleuren, lettertypecombinatie, knopvorm, logo's, favicon, deel-afbeelding of
de tokens in `assets/css/`:

```
--testsuite fast        (ThemeSettingsTest, ThemeRenderingTest,
                         BrandingTest en SiteIdentityTest zitten hierin;
                         database noch webserver nodig)
--testsuite cms         voegt ThemePersistenceTest toe
```

Raakte je de stylesheets aan, controleer dan ook dat de standaardvormgeving
onveranderd rendert — `THEMING.md` beschrijft de vergelijking van
`getComputedStyle` vóór en ná.

**Wijziging aan de gedeelde header of footer**

De knop in de header, de slotregel, de social profielen, of de partials zelf
(`HEADER-FOOTER.md`):

```
--testsuite fast        (HeaderFooterSettingsTest en HeaderFooterContractTest;
                         database noch webserver nodig)
--testsuite cms         voegt HeaderFooterRenderingTest toe: de
                        CMS-paginabestemming tegen echte rijen, en wat een
                        pagina echt rendert
--testsuite modules     als je aan een header-slot van een module zat
```

`HeaderFooterRenderingTest` praat ook met `php_cms`, dus start de
testcontainers (`docker compose --profile test up -d`) als je de CMS-only kant
bewezen wilt zien in plaats van overgeslagen.

**Wijziging aan meertaligheid**

De talen van een site, de taal van het CMS, de bewerktaal, de taalvelden in
een editor of automatisch vertalen (`MULTILINGUAL.md`):

```
--testsuite fast        LanguageRegistryTest (het gesloten register en de
                        sitetalen), LocalizedValueTest (de terugvalregel, in
                        beide richtingen), AdminLocaleTest (de CMS-taal, en
                        dat hij de website niet raakt), ThreeLanguageStatesTest
                        (de matrix CMS-taal × bewerktaal, en dat geen van
                        beide de bezoeker raakt), TranslationProviderTest
                        (DeepL zonder netwerk, en de vier vertaalregels) en
                        MultilingualBoundaryTest (de grenzen) — database,
                        webserver noch netwerk nodig
--testsuite cms         dezelfde zes, plus de scherm- en instellingenkant,
                        en LocalizedNavigationFooterPersistenceTest: de
                        bewaarmatrix van Navigatie en Footer tegen echte
                        rijen (een taal opslaan mag de andere nooit
                        overschrijven)
--testsuite blocks      als je een editor op de taalvelden aansloot
```

De suite praat **nooit** met een echte vertaal-API. `FakeTranslationProvider`
en twee subklassen die de ene HTTP-methode van `DeepLProvider` vervangen
dekken elke tak, zodat een trage of onbereikbare betaalde dienst deze tests
nooit kan laten falen en een testrun nooit iemands quota kost.

**Wijziging aan formulieren**

Veldtypes, validatie, de publieke verwerking, meldingen, bewaarde
inzendingen of de twee formulierblokken (`FORMS.md`):

```
--testsuite fast        (FormFieldTypeTest, FormValidationTest en
                         FormBoundaryTest: veldtypes, validatie, rechten,
                         guards en de grens met de Shop — database noch
                         webserver nodig)
--testsuite cms         voegt FormAdminTest, FormRenderingTest en
                        ContactFormMigrationTest toe: echte definities,
                        echte inzendingen, echte pagina's
--testsuite blocks      als je aan de rendering of de plaatsing zat
```

`FormRenderingTest` doet echte requests, dus start de testcontainers
(`docker compose --profile test up -d`) als je de publieke kant bewezen
wilt zien in plaats van overgeslagen. Draai deze in **`vvld_php_test`**:
de tests maken wegwerp-pagina's aan die de webserver moet kunnen zien.

**Wijziging aan de paginabouwer**

De blokkenkiezer, de Contentblokken-catalogus, de presentatie-metadata van een
blok of de opslagbalk ([`PAGE-EDITOR.md`](PAGE-EDITOR.md)):

```
--testsuite fast        BlockPresentationTest (elk geregistreerd blok heeft
                        een naam, een beschrijving, een categorie en een
                        pictogram, en toont nergens een interne sleutel) en
                        BlockPickerTest (het kiezerspaneel, in-process
                        gerenderd, plus de bron van save-bar.js en van elke
                        blok-editor) — database noch webserver nodig
--testsuite blocks      dezelfde twee, plus ContentBlockArchitectureTest:
                        één lijst, één toevoegknop, en die staat ónder de
                        blokken
--testsuite modules     bewijst dat de blokken van de Shop met hun module
                        mee komen en gaan, ook in de catalogus
```

Voeg je een blok toe, dan hoef je aan deze tests niets te doen:
`BlockPresentationTest` loopt over élk geregistreerd type.

**Wijziging aan paginasjablonen**

Een sjabloon toevoegen of wijzigen, de kiezer bij *Nieuwe pagina*, of de
installer die er de blokken mee aanmaakt (`PAGE-TEMPLATES.md`):

```
--testsuite fast        (PageTemplateRegistryTest: de catalogus, welke
                         blokken een sjabloon mag noemen, geen dynamische
                         ontdekking, geen Shop-afhankelijkheid — database
                         noch webserver nodig)
--testsuite cms         voegt PageTemplateCreationTest toe: echte pagina's,
                        echte blokken, atomiciteit, SEO en de sitemap
--testsuite blocks      als je aan de blokken zat die een sjabloon plaatst
```

Draai `PageTemplateCreationTest` in **`vvld_php_test`**: hij maakt
wegwerp-pagina's met een `zz-tpl-test-`-sleutel aan en ruimt ze in
`tearDown()` op met een exacte id- en sleutelvergelijking — nooit met een
`LIKE`-patroon, waarin `_` op elk teken matcht.

**Wijziging aan een migratie, of aan wat een installatie aanmaakt**

Een nieuwe migratie, een guard in een oude, of de bootstrap van een verse
installatie (`INSTALL-BOOTSTRAP.md`):

```
--testsuite migration   FreshInstallTest, LegacyUpgradeTest,
                        SetupCompletionTest en FreshInstallRenderTest bouwen
                        elk een wegwerpdatabase en draaien phinx daar vanaf
                        nul tegenaan: het eerste bewijst wat een nieuwe
                        installatie krijgt, het tweede dat een bestaande niets
                        kwijtraakt, het derde wat de installatiewizard er
                        daarna van maakt, en het vierde wat zo'n verse
                        installatie een bezoeker echt tóónt
--testsuite cms         dezelfde vier, plus de pagina-kant eromheen
```

**Een lege database is nog geen lege pagina.** `FreshInstallTest` kijkt naar
rijen, `FreshInstallRenderTest` naar HTML — en dat bleek een andere vraag: de
Hero sloeg netjes een lege afbeelding op en de renderer vulde het gat met de
foto van deze site. Raak je iets aan wat op een verse installatie zichtbaar
is, dan is dat tweede bestand de test die het merkt. Hij rendert `/`,
`robots.txt` en `sitemap.xml` in een eigen proces tegen de wegwerpdatabase
(`tests/Support/render-public-route.php`, dezelfde reden als
`complete-setup-cli.php`) en leest ze zoals een vreemde dat zou doen.

**Wat je aan een tweede site meegeeft** (`SETUP.md`, "Een tweede site
beginnen") heeft drie goedkope wachters die geen database en geen webserver
nodig hebben, en dus in `fast` zitten:

```
--testsuite fast        ExampleEnvironmentTest (.env.example draagt geen
                        levende waarde van deze site meer),
                        GenericDistributionTest (de User-Agent naar buiten,
                        het afhaallabel, de mailafzender, de lege
                        winkelwagen) en FreshSiteCopyTest (de grens tussen
                        applicatie en site, plus het exportscript echt
                        gedraaid)
```

**Wijziging aan de installatiewizard**

De stappen, de validatie, de omleiding ernaartoe of wat afronden wegschrijft
(`SETUP.md`):

```
--testsuite fast        SetupWizardValidationTest (wat de wizard accepteert
                        en weigert), SetupAccessTest (de guards, en dat er
                        nergens standaard-inloggegevens worden aangemaakt),
                        ModuleConfigurationTest en AppUrlTest — database noch
                        webserver nodig
--testsuite migration   voegt SetupCompletionTest toe: de wizard echt
                        afronden tegen een database vanaf nul
--testsuite cms         dezelfde, plus de pagina- en instellingenkant
```

Draai daarna ook `docker exec vvld_php php vendor/bin/phinx migrate -c phinx.php`
tegen ontwikkeling: een migratie die in git staat is nog niet toegepast.

**Wijziging aan de mediabibliotheek**

Uploaden, de mediakiezer, alt-teksten, gebruiksbepaling of verwijderen
(`MEDIA.md`):

```
--testsuite fast        (MediaBoundaryTest: rechten, guards, CSRF, de
                         modulegrens — database noch webserver nodig)
--testsuite cms         voegt MediaLibraryTest, MediaUsageTest en
                        MediaAdoptionTest toe: echte uploads, echte
                        blokinstanties, echte bestanden
--testsuite blocks      als je een blok aansloot op de kiezer
```

Draai deze in **`vvld_php_test`**: `MediaLibraryTest` schrijft echte
bestanden in `assets/media/` en ruimt ze weer op, en de container die de
HTTP-tests bedienen moet dezelfde zijn.

**Wijziging aan SEO, sitemap of robots**

Titels, meta descriptions, canonicals, de gedeelde `<head>`, indexeerbaarheid
of `robots.txt` (`SEO.md`):

```
--testsuite fast        (SeoMetadataTest, PageSeoTest en RobotsTest zitten
                         hierin; database noch webserver nodig)
--testsuite cms         voegt SeoRoutingTest toe: echte requests voor
                        robots.txt, een noindex-pagina die uit de sitemap
                        valt, en de transactionele routes
--testsuite shop        als je aan product- of collectiemetadata zat
--testsuite modules     bewijst dat de head ook zonder de Shop compleet is
```

**Wijziging aan redirects**

De redirecttabel, de padnormalisatie, de opzoeking bij een verzoek, `404.php`
of de automatische redirect bij het hernoemen van een pagina (`REDIRECTS.md`):

```
--testsuite fast        (RedirectPathTest en RedirectAdminSecurityTest;
                         database noch webserver nodig)
--testsuite cms         voegt RedirectValidationTest, RedirectRoutingTest en
                        RedirectSlugChangeTest toe: conflicten en kringetjes,
                        en echte verzoeken langs beide integratiepunten
--testsuite modules     bewijst dat een bestemming in een uitgeschakelde
                        module niet afgaat en wel bewaard blijft
```

`RedirectRoutingTest` praat met `php_test`, dus start de testcontainers als je
de routing bewezen wilt zien in plaats van overgeslagen. De redirects die deze
tests maken hebben allemaal een `zz-`-vanaf-pad en worden in `tearDown()`
opgeruimd met een exacte prefixvergelijking — nooit met een `LIKE`-patroon,
waarin `_` op elk teken matcht.

**Wijziging aan de blog**

Berichten, categorieën, tags, de publieke blogpagina, de feed of de
bloginstellingen (`BLOG.md`):

```
--testsuite fast        BlogModuleTest: registratie, de standaard-uit, wat de
                        module bijdraagt en wat verdwijnt, de guards op elk
                        scherm en endpoint, en dat Core geen Blog-klasse
                        noemt — database noch webserver nodig
--testsuite blog        de rest: het berichtmodel, de inplangrens, taxonomie,
                        SEO en feed, media, en echte verzoeken naar alle vijf
                        de URL's
--testsuite cms         als je aan de gedeelde SEO-, redirect- of
                        mediakant zat
```

Draai deze in **`vvld_php_test`**: `BlogRoutingTest` doet echte verzoeken en
`BlogMediaAndSettingsTest` schrijft echte bestanden in `assets/media/`. De
berichten, categorieën, tags en redirects die deze tests maken dragen allemaal
een `zz-blog...`-prefix en worden in `tearDown()` op **exacte id** opgeruimd —
nooit met een `LIKE`-patroon, waarin `_` op elk teken matcht.

**Wijziging in de shop**

```
--testsuite fast
--testsuite shop
--testsuite http        als je een pagina/route/rendering raakte
```

**Wijziging aan een koppelpunt tussen Core en een module**

Alles wat `AdminNavigation`, `AdminPermissions`, `RouteRegistry`,
`ReservedRoutes`, `Sitemap`, `PageAssets`, `BlockDefinitions`,
`ItemGallerySources`, de gedeelde header of het dashboard raakt:

```
--testsuite fast        (ModuleRegistryTest en ShopDisabledTest zitten hierin)
--testsuite modules     ook de CMS-only HTTP-controle
```

**IJkmoment — voor een merge, voor een deploy, na een migratie**

```
docker exec vvld_php_test php vendor/bin/phpunit
```

## Hoe de database gescheiden blijft

`tests/bootstrap.php` draait vóór elke test en zet `DB_DATABASE` om naar de
testdatabase. Dat werkt omdat `App\Database` en `phinx.php` hun waarden via
`Dotenv::createImmutable()` inlezen, en "immutable" betekent dat Dotenv een
variabele die al gezet is niet overschrijft. Geen enkele test hoeft er iets van
te weten.

Daarna controleert de bootstrap het ook echt: hij opent de verbinding die de
hele suite deelt en vraagt de server `SELECT DATABASE()`. Klopt het antwoord
niet, of is de testnaam gelijk aan de ontwikkelnaam, dan stopt de run met een
melding in plaats van door te gaan. Bestaat de testdatabase nog niet, dan zegt
hij welk commando je moet draaien. Is er helemaal geen databaseserver, dan
waarschuwt hij en gaat door — `unit` en `contract` hebben er geen nodig.

De naam is `TEST_DB_DATABASE` uit `.env`, of anders `DB_DATABASE` met `_test`
erachter. `.env.example` beschrijft beide variabelen.

`Tests\Architecture\TestSuiteCoverageTest` bewaakt dat de bootstrap in
`phpunit.xml` aangehaakt blijft.

## De HTTP-tier

De HTTP-tests praten met `http://php_test` (de `php_test`-container), niet met
de ontwikkelsite op `http://localhost`. De CMS-only tests praten met
`http://php_cms` (`TEST_CMS_BASE_URL`), dezelfde afspraak. Anders zouden ze pagina's en blokken
aanmaken in de echte CMS-inhoud. Overschrijven kan met `TEST_BASE_URL`.

Is die server niet bereikbaar, dan slaan deze tests zichzelf over met een
melding die het startcommando noemt — ze falen nooit om de verkeerde reden.
Vanaf je eigen machine is dezelfde site te zien op `http://localhost:8001`.

## Een test toevoegen voor een nieuw contentblok

Het blok zelf bouw je met [`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md); hieronder
staat alleen de testkant.

1. Schrijf de test in `tests/Service/` (of `tests/Repository/` als hij vooral
   over SQL gaat).
2. **Maak je eigen pagina aan.** Hang nooit blokken aan een echte pagina zoals
   `contact`: die inhoud wordt door de redactie beheerd en verandert.
   `tests/Service/SectionRegistryTest.php` is het voorbeeld — `setUp()` maakt
   een pagina met een `content_key` als `__test_sections__` (underscores, dus
   het kan nooit botsen met een echte slug of publiek bereikbaar zijn),
   `tearDown()` haalt de blokken weg via `SectionRegistry::delete()` en daarna
   de pagina zelf. `removeTestPage()` draait ook vóór het aanmaken, zodat een
   afgebroken run de volgende niet blokkeert.
   Test je een migratie of backfill, geef die wegwerp-pagina dan een
   **willekeurige slug** en niet `contact` of `index`: een pagina die een
   gebruiker zelf heeft aangemaakt is gewone CMS-data, en dat is precies het
   geval dat een slug-specifieke migratie mist.
3. Zet het bestand in `phpunit.xml` in de suite `blocks`, plus `http` als hij
   requests doet en `unit`/`contract` als hij database noch webserver nodig
   heeft.

Vergeet je stap 3, dan faalt `TestSuiteCoverageTest`: elk testbestand moet in
minstens één domeinsuite staan, anders zou een gerichte run er nooit langskomen.

## Tests voor een module

De suite `modules` (`tests/Module/`) test het modulesysteem zelf:

- `ModuleRegistryTest` — wat er geregistreerd is, dat elke sleutel uniek is,
  hoe `MODULE_<KEY>_ENABLED` gelezen wordt, en dat Personalisatie nooit aan
  kan staan zonder de Shop. Geen database, geen webserver.
- `ModuleConfigurationTest` — de volgorde omgeving → opgeslagen voorkeur →
  aan, in beide richtingen, en dat de opslag geen tweede modulestaat wordt.
  Geen database, geen webserver (`SETUP.md`).
- `ShopDisabledTest` — hoe het CMS eruitziet met de Shop uit: geen menu-items,
  geen houdbare permissies, geen routes, geen blokken, geen galerijbron, geen
  assets — én dat opgeslagen rechten en blokrijen onaangetast blijven. Ook
  dat de Core-bestanden geen concrete Shop-klasse meer noemen. Geen database,
  geen webserver.
- `CmsOnlyHttpTest` — hetzelfde over echt HTTP, tegen `vvld_php_cms`.

Een module die de Mediabibliotheek gaat gebruiken levert daarnaast een
`MediaUsageProvider` (`MEDIA.md`); `Tests\Service\MediaBoundaryTest`
controleert dat elke geregistreerde provider een hele lijst id's in één keer
beantwoordt, en dat Core Media geen Shop-klasse of Shop-tabel noemt.

Krijgt een module zoveel eigen tests dat ze een eigen domein verdienen (zoals
`shop`, `personalization` en sinds de Blog ook `blog`), voeg dan in
`phpunit.xml` een `<testsuite>` met de naam van de module toe, zet de
testbestanden erin, en neem de naam op in
`TestSuiteCoverageTest::DOMAIN_SUITES` zodat de dekkingscontrole hem meetelt.
De Blog is het voorbeeld: `tests/Blog/` als eigen map, de suite `blog` als
`<directory>`, en het ene database-loze bestand daarnaast in `fast` en
`contract`. Verder verandert er niets: dezelfde bootstrap,
dezelfde testdatabase, dezelfde tiers.

## Wat de snelle tiers echt snel houdt

`unit` en `contract` mogen geen database openen en geen request doen.
`TestSuiteCoverageTest` controleert dat op de broncode, maar de echte proef is
draaien zonder database:

```bash
docker exec -e DB_HOST=no-such-host vvld_php php vendor/bin/phpunit --testsuite fast
```

Blijft dat groen, dan klopt de indeling. Faalt er iets, dan hoort dat bestand
in een domeinsuite thuis en niet in `fast`.

Deze variant is wél véél trager dan de gewone `fast` (minuten in plaats van
seconden), en dat is geen probleem: elke instellingenlaag die op zijn
standaarden terugvalt — `SiteSettings`, `ThemeSettings`, `ModuleSettings` —
probeert het per proces opnieuw zodra een test zijn cache leegmaakt, en elke
poging wacht op een DNS-fout. Het is een structuurcontrole, geen snelheidsmeting.
