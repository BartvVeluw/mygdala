# Testen

Alles draait op PHPUnit 10. Er is geen tweede framework en geen CI-machinerie:
één `phpunit.xml`, een handvol suites, en een testdatabase die losstaat van de
ontwikkeldatabase.

Weet je nog niet waar de code staat die je test? Begin bij
[`PROJECT-MAP.md`](PROJECT-MAP.md).

## Eenmalige setup

```bash
docker compose exec php php scripts/test-db.php
```

Maakt `mygdala_tests` aan (schema **en** rijen, gekopieerd uit
`mygdala`) en geeft `mygdala_app` er rechten op. De ontwikkeldatabase wordt
alleen gelezen. Herhaal dit commando wanneer je de testdata wilt verversen —
bijvoorbeeld na een nieuwe migratie of nadat je in de CMS iets hebt toegevoegd
dat de tests moeten zien.

```bash
docker compose --profile test up -d
```

Start twee services:

- **`php_test`** — dezelfde site tegen de testdatabase. Dit is de container
  waarin je de suite draait.
- **`php_cms`** — diezelfde code en diezelfde testdatabase,
  maar gestart met `MODULE_SHOP_ENABLED=false`. Dat is de CMS-only
  deployment: geen Shop, geen Personalisatie, geen Blog en geen Portfolio.
  `Tests\Module\CmsOnlyHttpTest` en `Tests\Blog\BlogRoutingTest` praten
  ermee, en slaan zichzelf over als hij niet draait.

`php_test` krijgt daarnaast `MODULE_BLOG_ENABLED=true` en
`MODULE_PORTFOLIO_ENABLED=true` mee. De Blog en Portfolio staan standaard uit
(`MODULES.md`), dus zonder die regels zou elke `/blog`-test een 404 testen en
zou een Portfolio-test afhangen van wat de testdatabase toevallig heeft
opgeslagen. Het zijn dezelfde schakelaars die een site-eigenaar gebruikt, geen
test-only mechaniek.

Waarom een tweede container en niet een schakelaar in de suite: welke modules
draaien wordt gelezen uit de omgeving waarmee een proces is *gestart*. Een
test kan dat van buitenaf niet veranderen voor een draaiende webserver, en een
configuratiebestand zou gedeeld worden met de ontwikkelsite (beide mounten
dezelfde map). In-process gebruikt de suite gewoon
`App\Module\ModuleRegistry::overrideForTests()`.

### Meerdere installaties naast elkaar

Elke clone van deze repository is een eigen Compose-project, genoemd naar zijn
map, met eigen containers, een eigen MySQL-server en dus een eigen
testdatabase. Twee installaties kunnen allebei hun testprofiel draaien zonder
elkaar te raken. Daarom noemt dit document **services** (`php`, `php_test`,
`php_cms`) en geen containernamen: `docker compose exec php_test …` vindt de
container van de installatie in wiens map je staat.

`php_test` en `php_cms` hebben geen vaste poort op je machine. De tests praten
er binnen het Docker-netwerk mee (`http://php_test`), en Docker kiest zelf een
vrije hostpoort voor wie ze met de hand wil openen:

```bash
docker compose port php_test 80
```

### Als `.env` ontbreekt

Zonder `.env` laadt Compose het project niet: elk `docker compose`-commando
voor deze installatie faalt, ook `exec`, en `php_test` start dus niet. Alle
PHP-services lezen hem via `env_file`, en de MySQL-service haalt er zijn
wachtwoorden uit. Dat bestand is lokaal, gitignored en bevat secrets,
waaronder `DB_ROOT_PASSWORD`.

Wat de suite er minimaal uit nodig heeft, voor wie hem als mens aanmaakt:

- **Vereist:** `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` en
  `DB_ROOT_PASSWORD`. Op een machine zonder bestaand MySQL-volume volstaan de
  plaatshouders uit `.env.example`: de MySQL-container maakt die gebruikers
  bij een leeg volume zelf aan. Bestaat het volume al, dan moeten ze kloppen
  met de waarden waarmee het ooit is aangemaakt. Zonder `DB_ROOT_PASSWORD`
  slaan de installatietests zichzelf over.
- **Voor de tests niet nodig:** `APP_ENV`, `APP_URL`, de
  `MODULE_*_ENABLED`-schakelaars, `SHOP_NOTIFICATION_EMAIL`,
  `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH` en de sleutels van Mollie,
  Turnstile, DeepL en SMTP. De tests die zo'n waarde nodig hebben, zetten hem
  zelf (zie "Welke omgeving een test ziet"). Wat in `.env` staat, verandert
  de uitkomst dus niet.

**Een agent maakt dit bestand niet aan en reconstrueert het niet** — niet uit
`.env.example`, en niet uit de omgevingsvariabelen van een draaiende
container. Die waarden zijn ooit door een mens gezet en horen niet in een
bestand terug dat een agent heeft geraden.

Ontbreekt `.env`, meld dat dan als reproduceerbaarheidsprobleem en laat het
herstellen aan de eigenaar van de machine. Wil je intussen toch draaien,
gebruik dan alleen de fallback die hieronder al beschreven staat: een andere
container met de moduleschakelaars expliciet meegegeven. Omdat Compose het
project zonder `.env` niet laadt, spreek je een container die nog draait dan
met `docker exec` op zijn naam aan. Welke dat zijn:

```bash
docker ps --filter "label=com.docker.compose.project=<mapnaam>"
```

Daarin draaien `fast` en `full` functioneel groen. De HTTP-tests praten echter
met de testwebcontainers, en slaan zichzelf over als die niet bereikbaar zijn.
Een groene run met veel overgeslagen HTTP-tests is dus nog geen volledige
HTTP-verificatie.

### Vanuit een git worktree

Een worktree is een verse uitchecking en heeft dus **geen `vendor/`**: die map
is gitignored, hij hoort bij de checkout en niet bij de commit. PHPUnit start
er niet voordat je de Composer-dependencies ernaast hebt gezet.

De containers worden gestart vanuit de hoofduitchecking, niet vanuit de
worktree: `docker compose` leest het `.env` dat daar staat, en dat is ook
gitignored. Draait `php_test` nog niet, start dan eerst het profiel uit
"Eenmalige setup" — `exec` kan geen container gebruiken die niet bestaat.

Noem vanuit de worktree daarom de compose-file van de hoofduitchecking, met
`-f`. Die map is dan de projectmap: Compose vindt dezelfde containers en
hetzelfde `.env`, in plaats van de worktree voor een nieuwe installatie aan te
zien.

```bash
docker compose -f ../../../docker-compose.yml exec php_test composer install --working-dir=/var/www/html/.claude/worktrees/<naam>
docker compose -f ../../../docker-compose.yml exec -w /var/www/html/.claude/worktrees/<naam> php_test php vendor/bin/phpunit --testsuite fast
```

Een worktree onder `.claude/worktrees/` ligt binnen de projectmap, en die is
in elke container aangekoppeld — je hoeft er geen eigen container voor te
starten, te herstarten of aan te passen.

**Maak geen symlink naar de `vendor/` van een andere worktree.** De
autoloader van Composer leidt zijn basispad af uit het pad van het
autoloadbestand zelf, en PHP heeft de symlink op dat moment al gevolgd. De
`App\`-klassen komen dan uit de *andere* checkout: je test de code die je
juist niet hebt gewijzigd, en niets faalt om je daarop te wijzen. Een gewone
kopie mag wel — die levert dezelfde pakketten en houdt het basispad bij de
worktree zelf.

## Het commando

```bash
docker compose exec php_test php vendor/bin/phpunit
```

Draai de suite **in `php_test`**, niet in `php`. De testcontainer
deelt filesystem én database met de webserver waar de HTTP-tests op schieten;
draai je ze uit `php`, dan schrijft een uploadtest zijn bestand in de ene
container en leest de HTTP-request het in de andere.

De snelle tiers hebben geen database en geen webserver nodig, en mogen dus
overal draaien:

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite fast
```

### `fast` wil wél dat alle modules aan staan

Dit kost je een half uur als je het niet weet. `unit` en `contract` hebben
geen database nodig, maar ze lezen wél het moduleregister: ze controleren
onder meer dat een Super Admin élke permissie houdt en dat elk adminscherm
bij precies één zijbalkregel hoort. Staat er een module uit in de omgeving
waarin je ze draait, dan bestaan zijn permissies en schermen niet, en falen
die controles terecht.

Draai `fast` daarom in `php_test`, waar de modules aan staan. Draai je
hem in een container waar iets uit staat, zet het dan voor dat ene commando
aan:

```bash
docker compose exec -e MODULE_SHOP_ENABLED=true -e MODULE_PERSONALIZATION_ENABLED=true \
  -e MODULE_BLOG_ENABLED=true -e MODULE_PORTFOLIO_ENABLED=true php php vendor/bin/phpunit --testsuite fast
```

Zie je precies deze mislukkingen, dan is dit de oorzaak en niet je wijziging:
`NavigationServiceTest`, `RouteRegistryTest`, `FrontendAssetOwnershipTest`
(Shop uit) en `AdminPermissionsTest`, `AdminAccessControlTest` (Blog uit).

## De suites

| Suite | Wat erin zit | Nodig |
| --- | --- | --- |
| `fast` | `unit` + `contract` samen | niets |
| `modules` | het modulesysteem: register, aan/uit, en hoe het CMS eruitziet met de Shop of Portfolio uit | deels testdatabase + `php_cms` |
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
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
docker compose exec php_test php vendor/bin/phpunit --testsuite shop
docker compose exec php_test php vendor/bin/phpunit --testsuite modules
docker compose exec php_test php vendor/bin/phpunit --testsuite blog
docker compose exec php      php vendor/bin/phpunit --testsuite unit
```

Een testbestand mag in meerdere suites zitten — `blocks` en `http` overlappen
met opzet. `full` is de standaardsuite, dus een kaal `phpunit` draait elk
bestand precies één keer.

### Het geheugen van de testrunner

`phpunit.xml` zet `memory_limit` voor **het phpunit-proces** op 512M. Eén
proces houdt een hele run vast, dus het geheugen groeit met het **aantal**
tests: onder de 128M van de container haalde `full` het bij 4083 tests nog, en
eindigde hij daarna met een fatal (`Allowed memory size exhausted`, exit 255,
**geen eindtelling**) in een testbestand dat niets met de wijziging te maken
had. Zie je dat, dan is het de runner en niet die test.

De applicatie krijgt hiermee geen ruimer budget: een pagina die een test
opvraagt loopt via `Tests\Support\BuiltInServer`, een apart PHP-proces onder
de limiet van `php.ini`.

### De groep `migration-backfill`

De migratie- en installatiecontroles zitten in eigen testklassen, plus één
losse methode in `FreshInstallTest`. Samen:

```bash
docker compose exec php_test php vendor/bin/phpunit --group migration-backfill
```

Sinds de installatie-opruiming zitten in deze suite ook
`Tests\Install\FreshInstallTest`, `Tests\Install\LegacyUpgradeTest`,
`Tests\Install\SetupCompletionTest` en `Tests\Install\AdminAccountMigrationTest`.
Die draaien phinx vanaf nul tegen een
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

Twee andere, `Tests\Repository\ContactFormMigrationTest` en
`Tests\Service\MediaAdoptionTest`, bewijzen wat een datagedreven backfill doet
met data die eruitziet als de data die hij omzet. Ook zij bouwen een
wegwerpdatabase, maar met `ScratchInstall::upTo()`: tot vlak vóór de
migratie, dan zetten ze er hun eigen oude rijen in (op verzonnen slugs, want
de migratie kiest op data en nooit op een paginanaam), en dan draaien de
overige migraties. `ScratchInstall::replay()` draait de migratie daarna nog
een keer via phinx zelf; dat is het bewijs dat hij idempotent is.

**Geen test in deze groep leest wat er toevallig in `mygdala_tests` staat.**
Dat de pagina's, het menu, de footer en de blokken van één bepaalde site
destijds goed zijn overgekomen, is de geschiedenis van die site, en die tests
staan in de repository van die site. Voor Mygdala zelf bewijst
`LegacyUpgradeTest` dat de migratiegeschiedenis een bestaande installatie
niets afneemt. Ze zijn wel trager, dus ze horen niet in de snelle
ontwikkellus. Wil je ze even buiten beschouwing laten:

```bash
docker compose exec php_test php vendor/bin/phpunit --exclude-group migration-backfill
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

Het menu, de knoppen in de header, de slotregel, de social profielen, of de
partials zelf (`HEADER-FOOTER.md`):

```
--testsuite fast        (NavigationServiceTest, NavigationPresentationTest,
                         HeaderFooterSettingsTest en HeaderFooterContractTest;
                         database noch webserver nodig; de adrescontrole van
                         de social profielen zit in HeaderFooterSettingsTest)
--testsuite cms         voegt NavigationRepositoryTest, NavigationAdminHttpTest
                        (scherm, endpoints en publieke header over een eigen
                        php -S, ook met de Shop uit), FooterRepositoryTest,
                        FooterSocialLinkRepositoryTest, FooterAdminHttpTest
                        (het Footer-scherm, zijn endpoints en de publieke
                        footer over een eigen php -S, ook met de Shop uit) en
                        HeaderFooterRenderingTest toe: een knop naar een
                        CMS-pagina tegen echte rijen, en wat een pagina echt
                        rendert
--testsuite migration   als je aan de kolommen van nav_items, de overzetting
                        van de oude knop, die van de oude social-instellingen
                        of die van de labels en tekstinstellingen naar hun
                        tabel per taal zat (HeaderButtonMigrationTest,
                        FooterSocialLinkMigrationTest,
                        NavigationFooterLabelMigrationTest,
                        LocalizedSiteSettingMigrationTest, LegacyUpgradeTest)
--testsuite modules     als je aan een header-slot van een module zat
```

`HeaderFooterRenderingTest` praat ook met `php_cms`, dus start de
testcontainers (`docker compose --profile test up -d`) als je de CMS-only kant
bewezen wilt zien in plaats van overgeslagen.

**Wijziging aan meertaligheid**

De talen van een site, de taal van het CMS, de bewerktaal, de taalvelden in
een editor of automatisch vertalen (`MULTILINGUAL.md`):

```
--testsuite fast        LanguageRegistryTest (de talen van het CMS zelf),
                        SiteLanguagesTest (de websitetalen en de module
                        Meertaligheid), AdminLocaleTest (de CMS-taal, en
                        dat hij de website niet raakt), ThreeLanguageStatesTest
                        (de matrix CMS-taal × bewerktaal, en dat geen van
                        beide de bezoeker raakt), TranslationProviderTest
                        (DeepL zonder netwerk, en de vier vertaalregels) en
                        MultilingualBoundaryTest (de grenzen) — database,
                        webserver noch netwerk nodig
--testsuite cms         dezelfde zes, plus de scherm- en instellingenkant,
                        en de fase-4-opslag tegen echte rijen:
                        NavigationFooterTranslationTest en
                        LocalizedSiteSettingsTest (een taal opslaan mag de
                        andere nooit overschrijven), met de editors in
                        NavigationAdminHttpTest, FooterAdminHttpTest,
                        FormAdminHttpTest en FormFieldEditorHttpTest, het
                        tabblad Talen in WebsiteLanguageAdminHttpTest, en
                        TypedLinkTest
--testsuite modules     de module Meertaligheid aan en uit
                        (MultilingualModuleTest, MultilingualModuleHttpTest)
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
--testsuite fast        (FormFieldTypeTest, FormFieldTypeChangeTest,
                         FormValidationTest en FormBoundaryTest: veldtypes,
                         wat een typewissel kost, validatie, rechten,
                         guards en de grens met de Shop — database noch
                         webserver nodig)
--testsuite cms         voegt FormAdminTest, FormAdminHttpTest,
                        FormFieldEditorHttpTest, FormRenderingTest en
                        ContactFormMigrationTest toe: echte definities,
                        de veldeditor over echt HTTP, echte inzendingen,
                        echte pagina's
--testsuite blocks      als je aan de rendering of de plaatsing zat
```

`FormRenderingTest` doet echte requests, dus start de testcontainers
(`docker compose --profile test up -d`) als je de publieke kant bewezen
wilt zien in plaats van overgeslagen. Draai deze in **`php_test`**:
de tests maken wegwerp-pagina's aan die de webserver moet kunnen zien.

**Wijziging aan de paginabouwer**

De blokkenkiezer, de Contentblokken-bibliotheek en haar voorbeelden, de
presentatie-metadata van een blok of de opslagbalk
([`PAGE-EDITOR.md`](PAGE-EDITOR.md)):

```
--testsuite fast        BlockPresentationTest (elk geregistreerd blok heeft
                        een naam, een beschrijving, een categorie en een
                        pictogram, en toont nergens een interne sleutel),
                        BlockPickerTest (het kiezerspaneel, in-process
                        gerenderd, plus de bron van save-bar.js en van elke
                        blok-editor), BlockLibraryScreenTest (kaarten en
                        voorbeelddialoog, in-process), BlockSampleContractTest
                        (elk blok een voorbeeld door zijn eigen partial,
                        ge-escaped, zonder sitetekst) en
                        BlockPreviewContractTest (de bron van
                        admin/block-preview.php) — geen webserver nodig
--testsuite blocks      dezelfde vijf, plus ContentBlockArchitectureTest:
                        één lijst, één toevoegknop, en die staat ónder de
                        blokken; PageBuilderScreenTest: het echte
                        paginascherm over php -S (een lege pagina en haar
                        uitnodiging, Verbergen/Tonen, Verwijderen met zijn
                        vraag, herordenen); en BlockPreviewAccessTest: het
                        voorbeeld van elk blok over php -S (guard, headers,
                        404's, een uitgeschakelde module, niets geschreven)
                        — die twee ook in cms
--testsuite modules     bewijst dat de blokken van de Shop met hun module
                        mee komen en gaan, ook in de catalogus
```

Voeg je een blok toe, dan hoef je aan deze tests niets te doen:
`BlockPresentationTest` en `BlockSampleContractTest` lopen over élk
geregistreerd type.

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

Draai `PageTemplateCreationTest` in **`php_test`**: hij maakt
wegwerp-pagina's met een `zz-tpl-test-`-sleutel aan en ruimt ze in
`tearDown()` op met een exacte id- en sleutelvergelijking — nooit met een
`LIKE`-patroon, waarin `_` op elk teken matcht.

**Wijziging aan een migratie, of aan wat een installatie aanmaakt**

Een nieuwe migratie, een guard in een oude, of de bootstrap van een verse
installatie (`INSTALL-BOOTSTRAP.md`):

```
--testsuite fast        MigrationTableNamesTest: elke tabelnaam die een
                        migratie uitspreekt is een tabel die de migraties ook
                        aanmaken. Een typefout in een `hasTable()` of in een
                        `'tabel' => 'kolom_en'`-lijstje is aan een draaiende
                        site onzichtbaar — de lus slaat een onbekende tabel
                        stil over — dus wordt hij hier gevangen, zonder
                        database en zonder webserver
--testsuite migration   FreshInstallTest, LegacyUpgradeTest,
                        SetupCompletionTest, FreshInstallRenderTest en
                        ContentLanguageSettingRepairTest bouwen elk een
                        wegwerpdatabase en draaien phinx daar vanaf nul
                        tegenaan: het eerste bewijst wat een nieuwe
                        installatie krijgt, het tweede dat een bestaande niets
                        kwijtraakt, het derde wat de installatiewizard er
                        daarna van maakt, het vierde wat zo'n verse
                        installatie een bezoeker echt tóónt, en het vijfde dat
                        de opgeslagen talen van een site kloppen, hoe die
                        database ook tot stand kwam (`MULTILINGUAL.md`)
--testsuite cms         dezelfde vijf, plus de pagina-kant eromheen
```

**Een lege database is nog geen lege pagina.** `FreshInstallTest` kijkt naar
rijen, `FreshInstallRenderTest` naar HTML — en dat bleek een andere vraag: de
Hero sloeg netjes een lege afbeelding op en de renderer vulde het gat met de
foto van deze site. Raak je iets aan wat op een verse installatie zichtbaar
is, dan is dat tweede bestand de test die het merkt. Hij rendert `/`,
`robots.txt` en `sitemap.xml` in een eigen proces tegen de wegwerpdatabase
(`tests/Support/render-public-route.php`, dezelfde reden als
`complete-setup-cli.php`) en leest ze zoals een vreemde dat zou doen.

**Wat je aan een nieuwe site meegeeft** (`SETUP.md`, "Een nieuwe site
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

Draai daarna ook `docker compose exec php php vendor/bin/phinx migrate -c phinx.php`
tegen ontwikkeling: een migratie die in git staat is nog niet toegepast.

**Wijziging aan de mediabibliotheek**

Uploaden, de mediakiezer, alt-teksten, gebruiksbepaling of verwijderen
(`MEDIA.md`):

```
--testsuite fast        (MediaBoundaryTest: rechten, guards, CSRF, de
                         modulegrens — database noch webserver nodig)
--testsuite cms         voegt MediaLibraryTest, MediaUsageTest,
                        MediaUsageAccessTest en MediaAdoptionTest toe:
                        echte uploads, echte blokinstanties, echte
                        bestanden, en wie waar een bestand gebruikt wordt
                        te lezen krijgt, ook over HTTP
--testsuite blocks      als je een blok aansloot op de kiezer
```

Draai deze in **`php_test`**: `MediaLibraryTest` schrijft echte
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
--testsuite fast        (RedirectPathTest, RedirectAdminSecurityTest en
                         PageUrlChangeTest; database noch webserver nodig)
--testsuite cms         voegt RedirectValidationTest, RedirectRoutingTest,
                        RedirectSlugChangeTest en PageUsageTest toe:
                        conflicten en kringetjes, echte verzoeken langs beide
                        integratiepunten, en waar een pagina gelinkt wordt
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

Draai deze in **`php_test`**: `BlogRoutingTest` doet echte verzoeken en
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
--testsuite fast        (ModuleRegistryTest, ShopDisabledTest en PortfolioModuleTest zitten hierin)
--testsuite modules     ook de CMS-only HTTP-controle en PortfolioModuleHttpTest
```

**IJkmoment — voor een merge, voor een deploy, na een migratie**

```
docker compose exec php_test php vendor/bin/phpunit
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

## Welke omgeving een test ziet

Een test hangt nooit af van wat er toevallig in de `.env` van een
ontwikkelmachine staat. Uit de omgeving komt alleen de infrastructuur: welke
database en welke webserver (hierboven), en de moduleschakelaars waarmee
de testcontainers gestart zijn (`docker-compose.yml`). Verder gelden drie
regels.

1. **Heeft een test een omgevingswaarde nodig, dan zet hij die zelf** en
   herstelt hij hem in `tearDown()`. Heeft de klasse een testnaad, gebruik die
   dan: `AppEnvironment::overrideForTests()` of
   `ModuleRegistry::overrideForTests()`. Leest de code `$_ENV` rechtstreeks,
   zet dan `$_ENV` en onthoud de vorige waarde.
   `OrderConfirmationInvoiceTest` doet dat met `SHOP_NOTIFICATION_EMAIL`.
2. **Een subprocess krijgt zijn omgeving expliciet mee.** `ScratchInstall`
   start phinx en `runScript()` als een *onbeschreven* deployment. Daarin zijn
   `APP_ENV`, `APP_URL`, elke `MODULE_<KEY>_ENABLED`, `ADMIN_USERNAME`,
   `ADMIN_PASSWORD_HASH`, `ADMIN_EMAIL`, `MAIL_FROM_ADDRESS` en
   `SHOP_NOTIFICATION_EMAIL` wel gezet, maar leeg. Leeg en niet weggelaten,
   omdat Dotenv een gezette variabele niet overschrijft: ook een `.env` op de
   machine vult ze dan niet terug. Gaat een installatietest over een
   deployment die wél iets heeft ingesteld, dan geeft hij dat mee aan
   `ScratchInstall::fresh($database, [...])`.
3. **Geen test vergelijkt met een echte credential.** Of de migratie het
   `.env`-account ongewijzigd overnam, bewijst `AdminAccountMigrationTest` op
   een eigen wegwerpdatabase, met de hash van een verzonnen wachtwoord.

## De HTTP-tier

De HTTP-tests praten met `http://php_test` (de `php_test`-container), niet met
de ontwikkelsite op `http://localhost`. De CMS-only tests praten met
`http://php_cms` (`TEST_CMS_BASE_URL`), dezelfde afspraak. Anders zouden ze pagina's en blokken
aanmaken in de echte CMS-inhoud. Overschrijven kan met `TEST_BASE_URL`.

Is die server niet bereikbaar, dan slaan deze tests zichzelf over met een
melding die het startcommando noemt — ze falen nooit om de verkeerde reden.
Vanaf je eigen machine is dezelfde site te zien op de poort die `docker compose port php_test 80` noemt.

**Negen HTTP-tests hebben de testcontainer niet nodig.** `PagePreviewAccessTest`
(suite `cms`), `PageBuilderScreenTest` (suites `blocks` en `cms`),
`MediaUsageAccessTest` (suite `cms`), `PortfolioModuleHttpTest` (suite
`modules`), `PortfolioItemEditingHttpTest` (suite `cms`),
`BlockPreviewAccessTest` (suites `blocks` en `cms`),
`PageHeroEditorHttpTest` (suite `blocks`), `FormAdminHttpTest` (suite `cms`)
en `FormFieldEditorHttpTest` (suite `cms`) starten voor de duur van de klasse PHP's eigen webserver (`php -S`)
op deze uitchecking, tegen de testdatabase, en loggen een beheerder in met
een echte sessie. De twee Portfolio-tests, `BlockPreviewAccessTest`,
`PageHeroEditorHttpTest` en de twee Forms-tests doen dat met
`Tests\Support\BuiltInServer`, dat de server ook een eigen omgeving kan
meegeven, zoals een moduleschakelaar of een mailserver die niet bestaat, en
dat de headers van een antwoord teruggeeft. Dat kan omdat niets van de
conceptpreview, het blokvoorbeeld, de paginabouwer, de mediabibliotheek, het
Portfolio-beheer, de paginakop of de formulieren in Apache zit:
`admin/page-preview.php`, `admin/block-preview.php`, `admin/page.php`,
`admin/media.php`, `admin/portfolio-item.php`, `admin/page-hero.php`,
`admin/form.php`, `admin/form-field.php`, `api/form-submit.php` en de endpoints onder `api/admin/`
zijn gewone bestanden, en `pagina.php`, `portfolio-detail.php` en
`sitemap.php` worden rechtstreeks aangesproken. De rewrite zelf blijft de zaak
van `PageRoutingTest`. Kan de server niet starten, dan slaan deze tests
zichzelf over. Ze staan niet in de suite `http`: die telt alleen de tests die
op de webserver van `php_test` schieten.

**Nooit een sessie openhouden terwijl je de server erom vraagt.** `php -S` is
eenkoppig en `session_start()` neemt een exclusieve lock op het sessiebestand.
Heeft het testproces diezelfde sessie nog open, dan wacht het verzoek op de
lock van het testproces zelf tot cURL het opgeeft: dertig seconden, een
antwoord met status `0`, en niets in het serverlog dat het uitlegt. Alles wat
in het testproces de ingelogde beheerder leest opent die sessie — ook
`AdminTranslator::trans()`, via `AdminLocale`. `BuiltInServer::request()`
sluit daarom een actieve sessie vóór elk verzoek. Doe je hetzelfde met de hand,
vergeet dan `session_write_close()` niet.

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
- `CmsOnlyHttpTest` — hetzelfde over echt HTTP, tegen `php_cms`.
- `PortfolioModuleTest` — Portfolio aan en uit: zijbalk, permissie,
  galerijbron, het blok Projecten (alleen met de module aan, zonder eigen
  query, kaart of link, met de guards van zijn editor), sitemapcollector,
  gereserveerde slugs en `publicPaths()`, de guards op elk scherm en endpoint,
  dat een oud projectadres doorstuurt voordat het iets van de oude pagina leest,
  en dat Core geen Portfolio-klasse noemt. Geen database, geen webserver. Wat
  het blok Projecten over de database doet (kaartlinks, lege toestand, uit en
  weer aan) is `Tests\Service\ProjectCardsBlockTest`, in de suite `blocks`.
- `PortfolioModuleHttpTest` — hetzelfde over echt HTTP, plus dat de data een
  keer uit en weer aan overleeft, de koppeling met een pagina inbegrepen. Een
  oud projectadres geeft een tijdelijke redirect (302) naar de gekoppelde
  pagina (ook na een hernoeming of een nieuwe koppeling, nooit naar een
  concept, na ontkoppelen weer de oude pagina, een 404 met de module uit), de
  galerijkaart linkt naar die pagina, en de sitemap noemt een gekoppeld
  project één keer. Start zelf twee ingebouwde PHP-servers, één met
  `MODULE_PORTFOLIO_ENABLED=true` en één met `false`
  (`Tests\Support\BuiltInServer`), dus hij draait ook zonder `php_test`.

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
docker compose exec -e DB_HOST=no-such-host php php vendor/bin/phpunit --testsuite fast
```

Blijft dat groen, dan klopt de indeling. Faalt er iets, dan hoort dat bestand
in een domeinsuite thuis en niet in `fast`.

Deze variant is wél véél trager dan de gewone `fast` (minuten in plaats van
seconden), en dat is geen probleem: elke instellingenlaag die op zijn
standaarden terugvalt — `SiteSettings`, `ThemeSettings`, `ModuleSettings` —
probeert het per proces opnieuw zodra een test zijn cache leegmaakt, en elke
poging wacht op een DNS-fout. Het is een structuurcontrole, geen snelheidsmeting.
