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
HTTP-verificatie. De echte HTTP-tier draait ook zonder `.env`, met
`tests/Support/http-tier.sh` (zie "De HTTP-tier").

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
  -e MODULE_BLOG_ENABLED=true -e MODULE_PORTFOLIO_ENABLED=true \
  -e MODULE_PAGE_THEMES_ENABLED=true php php vendor/bin/phpunit --testsuite fast
```

Zie je precies deze mislukkingen, dan is dit de oorzaak en niet je wijziging:
`NavigationServiceTest`, `RouteRegistryTest`, `FrontendAssetOwnershipTest`
(Shop uit) en `AdminPermissionsTest`, `AdminAccessControlTest` (Blog of
Paginathema's uit).

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
| `updater` | de ingebouwde updater: versies, eigendom, de ondertekende feed, pakketten, back-up en herstel, en de upgradepaden end-to-end op wegwerpinstallaties (`docs/updates/ARCHITECTURE.md`) | testdatabase + MySQL-root |
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

### De suite `updater`

`tests/Update/`. Het grootste deel is snel en heeft niets nodig;
`ResumableDownloadTest` start zelf een ingebouwde server met
`tests/Support/range-feed-router.php` (een releasehost die ranges beantwoordt,
negeert, fout beantwoordt, de verbinding verbreekt of blijft hangen), en
`ApplyAndMaintenanceTest` schiet een apply in een eigen proces echt af
(SIGKILL, `tests/Support/apply-in-child.php`). Drie klassen
(`UpgradeEndToEndTest`, `UpgradeFailureTest`, `ExistingInstallAcceptanceTest`)
bouwen met de echte releasebouwer releases uit deze checkout en werken
wegwerpinstallaties daarvan bij via de endpoints van het Updates-scherm
(`Tests\Support\UpdaterSandbox`). Wat die nodig hebben:

- **het MySQL-rootaccount** (`DB_ROOT_PASSWORD`): elke wegwerpinstallatie
  krijgt een eigen database `mygdala_upd_*`, gekopieerd uit de testdatabase,
  en ruimt hem zelf op. Zonder root slaan ze zichzelf over, net als de
  migratietests;
- **`setpriv`** in de container, als de suite als root draait: de webserver
  van een wegwerpinstallatie draait dan als `www-data`, zodat
  bestandsrechten betekenen wat ze op een host betekenen (scenario F);
- **ext-zip en ext-sodium**.

Alles staat in `/tmp/mygdala-upd-e2e/` van de container, dus buiten de
bind-mount: daar is lezen en schrijven snel. De releases worden één keer per
proces gebouwd (release 0.1.0 leest deze checkout één keer, ongeveer 40
seconden over de Windows-bind-mount; de rest is afgeleid) en verdwijnen als
het proces stopt. Een hele `updater`-run duurt zo'n drie minuten. De
ScratchInstall-databases van de migratietests hebben andere namen, dus
`updater` en `migration` bijten elkaar niet.

Scenario I van `UpgradeEndToEndTest` beëindigt een webserverworker met
SIGKILL, midden in een download en midden in een apply. Welke worker dat is,
zoekt hij in `/proc` op als `www-data` zelf: de root van een Docker-container
mag zonder `CAP_SYS_PTRACE` de open bestanden van een ander proces niet zien.

`ExistingInstallAcceptanceTest` kan ook op een kopie van een échte
installatie draaien: de database wordt dan alleen gelezen, de uploads worden
gekopieerd, en alles gebeurt in de wegwerpinstallatie.

```bash
docker compose -f ../../../docker-compose.yml exec -w /var/www/html/.claude/worktrees/<naam> -e UPDATER_ACCEPTANCE_DATABASE=mygdala -e UPDATER_ACCEPTANCE_UPLOADS=/var/www/html php_test php vendor/bin/phpunit tests/Update/ExistingInstallAcceptanceTest.php
```

#### De Apache-acceptatie

`ApacheAcceptanceTest` bewijst de beveiliging van de updater op de echte
Apache van het image, met `.htaccess` actief: de ingebouwde server van PHP
leest geen `.htaccess`. Hij slaat zichzelf over, behalve in een
wegwerpcontainer van hetzelfde image met een lege documentroot. De
databasegegevens gaan alleen naar het testproces (`docker exec --env-file`),
nooit naar Apache zelf: Dotenv overschrijft geen variabele die het proces al
heeft, en de wegwerpinstallatie moet haar eigen `.env` lezen.

```bash
docker run -d --name mygdala-upd-apache --network <project>_default -v "<pad naar de checkout>:/src" --entrypoint apache2-foreground <project>-php
docker exec -w /src --env-file <bestand met DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_ROOT_PASSWORD, DB_DATABASE en TEST_DB_DATABASE> -e UPDATER_APACHE_ROOT=/var/www/html -e UPDATER_APACHE_URL=http://127.0.0.1 mygdala-upd-apache php vendor/bin/phpunit tests/Update/ApacheAcceptanceTest.php
docker rm -f mygdala-upd-apache
```

Ongeveer twee minuten: release 0.1.0 wordt één keer over de bind-mount
gelezen. De container raakt de ontwikkeldatabase en `mygdala-test` niet; de
installatie krijgt een eigen database `mygdala_upd_*` en ruimt die op.

Na het binnenhalen van de updater heeft een bestaande checkout één keer
`composer dump-autoload` nodig: de onderhoudsguard hangt aan de
autoload-`files` van `composer.json`, en
`ApplyAndMaintenanceTest::testTheInstalledAutoloaderRunsTheGuard` faalt met
precies die instructie zolang `vendor/` hem niet kent.

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
                         ThemePaletteRecipeTest, BrandingTest en
                         SiteIdentityTest zitten hierin; database noch
                         webserver nodig)
--testsuite cms         voegt ThemePersistenceTest en de kleurenpaletten toe
```

Bij de kleurenpaletten (`THEMING.md`, "Kleurenpaletten") gericht:
`ThemePaletteRecipeTest`, `ColorPaletteTest`, `ColorPalettesHttpTest` (eigen
`php -S`, met Paginathema's aan en uit) en `ColorPalettesMigrationTest`
(ScratchInstall `mygdala_scratch_pal_fresh` en `_upgraded`). Een test die het
actieve palet verandert, zet de tabel terug met `Tests\Support\ColorPaletteFixture`
(`snapshot()` in `setUp()`, `restore()` in `tearDown()`): het actieve palet is
de kleur van elke pagina die een latere test rendert.

Bij de Font Library (`THEMING.md`, "Font Library") gericht:
`FontFileInspectorTest` en `FontLibraryCssTest` (`unit`, `fast`, `cms`),
`FontLibraryTest` (`cms`), `FontLibraryHttpTest` (`cms`, `modules`, eigen
`php -S` met Paginathema's aan en uit), `FontLibraryApacheHttpTest` (`http`,
`cms`) en `FontLibraryMigrationTest` (`migration`, `cms`; ScratchInstall
`mygdala_scratch_fonts_fresh` en `_upgraded`). Er staat **geen echt
lettertype** in de repository: `Tests\Support\FontFileFixture` bouwt
structureel geldige TTF-, OTF-, WOFF- en WOFF2-bytes (en per regel één kapot
bestand), `Tests\Support\FontLibraryFixture` maakt `ZZ Font`-families en zet
de rollen van de website terug. `FontLibraryTest` schrijft in een tijdelijke
map (`FontLibrary::useStorageForTests()`); de HTTP-tests schrijven in
`assets/fonts/library/` van de uitchecking en ruimen dat op.

Bij de knopstijlen (`THEMING.md`, "Knopstijlen") gericht:
`ButtonStyleCssTest` (`unit`, `fast`, `cms`), `ButtonStyleBlocksTest`
(`contract`, `fast`, `blocks`), `ButtonStylesTest` (`cms`),
`ButtonStylesHttpTest` (`cms`, `modules`, `blocks`; eigen `php -S` met de
dispatcher) en `ButtonStylesMigrationTest` (`migration`, `cms`; ScratchInstall
`mygdala_scratch_buttons_fresh` en `_upgraded`). Een test die een stijl of een
standaard verandert, zet de bibliotheek terug met
`Tests\Support\ButtonStyleFixture` (`snapshot()` in `setUp()`, `restore()` in
`tearDown()`): de standaardknoppen tekenen elke `.btn` van een latere test.
Een nieuwe `…button_style_id`-kolom op een bloktabel hoort ook in
`RemainingBlockWordsMigrationTest::LATER_COLUMNS`.

Het beeld van de HTTP-tier heeft geen `mod_headers`. `FontLibraryApacheHttpTest`
bewijst daar het MIME-type, de weigering van elk ander bestand in de map en
de 404, en voor `nosniff` en de cache de regels in de `.htaccess`; op een
server mét `mod_headers` (de SVG-regel in de root-`.htaccess` bewijst dat)
controleert hij ook de headers zelf. Nooit een skip.

Raakte je de stylesheets aan, controleer dan ook dat de standaardvormgeving
onveranderd rendert — `THEMING.md` beschrijft de vergelijking van
`getComputedStyle` vóór en ná.

**Wijziging aan de gedeelde header of footer**

Het menu, de knoppen in de header, de slotregel, de social profielen, of de
partials zelf (`HEADER-FOOTER.md`):

```
--testsuite fast        (NavigationServiceTest, MainNavMarkupTest,
                         NavigationPresentationTest,
                         HeaderFooterSettingsTest en HeaderFooterContractTest;
                         database noch webserver nodig; de adrescontrole van
                         de social profielen zit in HeaderFooterSettingsTest)
--testsuite cms         voegt NavigationRepositoryTest, NavigationAdminHttpTest
                        (scherm, endpoints en publieke header over een eigen
                        php -S, ook met de Shop uit),
                        NavigationFollowsPageTitleHttpTest ("Gebruik titel van
                        bestemming": de paginatitel in elke taal, eigen tekst
                        per taal, weer aan wist alles), FooterRepositoryTest,
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
                         FormValidationTest, FormUploadTest,
                         FormSubmissionBulkContractTest en
                         FormBoundaryTest: veldtypes, wat een typewissel
                         kost, validatie, de keuring van een upload,
                         ids en acties in bulk, rechten, guards en de
                         grens met de Shop — database noch webserver nodig)
--testsuite cms         voegt FormAdminTest, FormAdminHttpTest,
                        FormFieldEditorHttpTest, FormUploadHttpTest,
                        FormSubmissionBulkHttpTest,
                        FormRenderingTest, ContactFormMigrationTest en
                        FormFileUploadMigrationTest toe: echte definities,
                        de veldeditor over echt HTTP, echte inzendingen met
                        echte multipart-uploads, downloaden en verwijderen,
                        gelezen/ongelezen en bulkacties, echte pagina's
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
                        admin/block-preview.php) — geen webserver nodig.
                        BlockPickerTest bewijst ook de presets: de galerij
                        als Collectiegalerij (Shop) en Portfoliogalerij
                        (Portfolio), per module aan en uit
--testsuite blocks      voegt GalleryPickerPresetHttpTest toe: een preset-
                        kaart maakt over echte HTTP het ene item_gallery-blok
                        met zijn bron gekozen, en een preset die het blok
                        niet aanbiedt wordt geweigerd zonder iets toe te voegen
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

Uploaden, de mediakiezer, naam en alt-tekst, mappen, raster en lijst,
gebruiksbepaling of verwijderen (`MEDIA.md`):

```
--testsuite fast        (MediaBoundaryTest: rechten, guards, CSRF, de
                         modulegrens; MediaPickerContractTest: één
                         handeling, één flow, en de gesloten lijst van
                         schermen met een eigen bestandskiezer — database
                         noch webserver nodig)
--testsuite cms         voegt MediaLibraryTest, MediaFolderTest,
                        MediaUsageTest, MediaUsageAccessTest,
                        MediaLibraryTwoHttpTest, ShopShareImageChoiceTest,
                        MediaLibraryTwoMigrationTest en MediaAdoptionTest
                        toe: echte uploads (ook multipart over HTTP, in een
                        map), echte blokinstanties, echte bestanden, en wie
                        waar een bestand gebruikt wordt te lezen krijgt
--testsuite blocks      als je een blok aansloot op de kiezer
                        (MediaBannerHttpTest bewijst het filter
                        afbeelding-of-video: lijst en upload)
--testsuite shop        als het een product of collectie raakt
--testsuite modules     als het Portfolio raakt
--testsuite migration   na een migratie van de bibliotheek
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

**Wijziging aan de paginaboom, een paginalijst of de bestemmingskiezer**

Pagina's nesten, pagina's onder de Shop of het Portfolio, het overzicht in
Pagina's, de volgorde van een paginalijst, de bestemmingskiezer van een
blokknop of de regel voor getypte adressen (`docs/pages/NESTING.md`,
`CONTENT-BLOCKS.md` "Waar een knop heen gaat"):

```
--testsuite fast        PagePathTest, PageOptionsTest en Routing\SafeUrlTest;
                        database noch webserver nodig
--testsuite blocks      DestinationPickerTest (de kiezer in de blok-editors,
                        opslaan per soort, een onbereikbare, verwijderde of
                        vervalste bestemming) en SafeUrlTest
--testsuite cms         voegt PageNestingTest, PageNestingHttpTest,
                        PagesOverviewLayoutTest, ModuleChildPagesTest (Shop en
                        Portfolio als ouder, botsingen beide kanten op, module
                        uit) en NavigationFollowsPageTitleHttpTest toe
--testsuite modules     bewijst dat alles met een module uit nog werkt
--testsuite shop        alleen als je de Shop-kant raakte: haar systeempagina,
                        een product of collectie als bestemming
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

**Wijziging aan de producteditor, de dynamische editor of de menu's in de zijbalk**

Het opslaan zonder herladen, de meldingen per veld, de vertrekdialoog, de
opties en varianten van een product, of de zijbalk met het menu *Shop*
(`ADMIN-UI.md`, "Een editor die opslaat zonder te herladen" en "Menu's in de
zijbalk"):

```
--testsuite fast        AdminEditorResponseTest (de ene antwoordvorm),
                        AdminEditorContractTest en ProductEditorContractTest
                        (wat gewijzigd maakt en wat niet, welke navigatie de
                        dialoog krijgt, rijen op sleutel, wat later binnenkomt)
                        en AdminSidebarMenuTest (het menu als bijdrage van de
                        module, knop en links) — database noch webserver nodig
--testsuite shop        voegt ProductVariantEditorTest toe (opties, waardes en
                        varianten in één opslag, weigeringen vóór er iets
                        geschreven wordt, een teruggedraaide transactie laat
                        niets achter), ProductEditorHttpTest (hetzelfde
                        verzoek met en zonder JSON: 200/422 met meldingen op
                        veldnaam en niets opgeslagen, of de redirect) en
                        AdminSidebarMenuHttpTest (per account en met de Shop
                        uit: één menu, open op zijn eigen schermen)
```

De productgalerij en haar overgang (Product Gallery 2.0, `MODULES.md`):

```
--testsuite fast        ProductGalleryTransitionTest (de resolver: product ??
                        Shop ?? fade, de gesloten lijst, wat de editor mag
                        posten), ShopGalleryContractTest (contain en cover,
                        één show() voor klik, vegen, pijltjes en variant,
                        het woord nogmaals gecontroleerd in het script,
                        vegen zonder preventDefault) en ShopSettingsTest
                        (het tabblad Productpagina) — niets nodig
--testsuite shop        voegt ProductGalleryTransitionHttpTest toe (elke keuze
                        in de ene opslag van de producteditor, weigeren
                        zonder iets op te slaan, een nieuw product volgt de
                        Shop, Shop-instellingen, en het opgeloste woord op de
                        productpagina als de standaard verandert) en
                        ProductGalleryTransitionMigrationTest (vers en
                        upgrade, bestaande producten en foto's ongewijzigd,
                        herhaling) — de laatste ook in --testsuite migration
```

*Factuur bekijken* (Factuurpreview 1.0, `MODULES.md`, "Shop"):

```
--testsuite shop        InvoicePreviewTest (de preview is byte voor byte het
                        bestand dat de mail bijvoegde; een ontbrekend bestand
                        wordt in het geheugen gerenderd uit de bevroren
                        gegevens, gelijk op het tijdstip en het document-id
                        van dompdf na, en niet weggeschreven; kijken geeft
                        nooit een factuur uit; één sjabloon) en
                        InvoicePreviewHttpTest (login, orders.view, Shop uit,
                        400/404, headers, de knop in een nieuw tabblad, een
                        Engels CMS toont de Nederlandse factuur, en elke rij,
                        teller en elk factuurbestand gelijk voor en na)
```

Beide gebruiken `Tests\Support\InvoiceOrderFixture`: een betaalde bestelling
met twee regels, verzendkosten en een apart factuuradres. Hij ruimt ook de
PDF's op en zet de factuurteller van het jaar terug, want een factuur uitgeven
kost een nummer.

Betalingen, de betaalprovider en de geheimenopslag (Mollie Setup 2.0,
`MODULES.md`, "Betalingen"; `SETUP.md`, "Geheimen in het CMS"):

```
--testsuite fast        MasterKeyTest (APP_KEY wint en een verkeerde wordt
                        geweigerd, lezen schrijft nooit, het sleutelbestand
                        eenmalig 0600 in een 0700-map en nooit vervangen,
                        onschrijfbare opslag of een relatief pad geeft geen
                        sleutel, een verse nonce per waarde, gebonden aan slot
                        en sleutel) en
                        ShopPaymentMethodsTest (zonder keuze iDEAL en
                        creditcard met hun oude woorden, een keuze in
                        volgorde, "kaart" is creditcard),
                        AdminPermissionsTest (payments.manage komt met geen
                        andere permissie mee, alleen een Super Admin kent hem
                        toe) en AdminUserServiceTest (een users.manage-houder
                        kan payments.manage niet geven en niet afpakken, een
                        Super Admin wel) — niets nodig
--testsuite shop        voegt toe: MollieConfigurationTest (de keten
                        omgeving → CMS → niets, de placeholder, maskering,
                        onleesbaar is niet ingesteld), MolliePaymentProviderTest
                        (de body uit de bestelling, adressen uit APP_URL ook in
                        een submap, geen webhook naar localhost/.test/privé-IP,
                        elke Mollie-status, refunds, de modus van een nieuwe
                        betaling, opvragen met alleen de sleutel van de modus
                        van de bestelling, de terugval voor NULL, live alleen
                        met een publiek https-adres, de statuskaart, elke fout
                        één soort zonder sleutel erin),
                        PaymentSettingsEditorTest (sleutels, live alleen na
                        een geslaagde check en met een echt webadres, de
                        bevestiging, de gepinde omgeving, methoden
                        beschikbaar vóór aangeboden), TestOrderTest (de modus
                        opslaan, geen factuurnummer/rij/PDF voor een
                        testbestelling, de mail zonder bijlage met [TEST],
                        omzet zonder test en mét live en NULL, de CSV-kolom,
                        een eindstatus die niet terugvalt),
                        OrderPaymentSyncTest (ook: een tragere sync maakt
                        betaald niet ongedaan), PaymentSettingsHttpTest,
                        MollieWebhookHttpTest en CheckoutPaymentMethodsHttpTest
                        (over echt HTTP, zie hieronder), InvoicePrefixTest
                        (het factuurprefix, een oude waarde, bestaande
                        facturen en hun bestand onaangeroerd),
                        SecretSettingsMigrationTest (vers en upgrade,
                        bestaande Mollie-bestellingen en refunds onaangeroerd,
                        herhaling) en PaymentModeMigrationTest (vers en
                        upgrade vanaf main vóór Mollie Setup 2.0, bestaande
                        bestellingen NULL met ids, statussen, bedragen,
                        refunds, facturen en de teller identiek, herhaling)
                        — de laatste twee ook in --testsuite migration
--testsuite cms         SecretStoreTest (versleuteld in de rij, fail closed
                        zonder of met een andere sleutel, een rij werkt niet in
                        een ander slot) en MasterKeyTest: de opslag is Core
```

**Geen enkele test praat met Mollie.** `Tests\Support\FakeMollie` is een
Mollie in een JSON-bestand achter de enige testnaad,
`App\Service\MollieClientFactory::useFactoryForTests()`: de echte
`MolliePaymentProvider` draait tegen de SDK van Mollie zelf (requestklassen,
parsing, exceptions), zonder netwerk en zonder account. In een PHPUnit-proces
zet `FakeMollie::install($scenario)` hem aan; voor `BuiltInServer` geeft een
test `['auto_prepend_file' => …/tests/Support/fake-mollie.php]` als php.ini-
waarde en `FAKE_MOLLIE_SCENARIO` als omgeving mee. Het scenario zegt per
sleutel `ok`, `unauthorized`, `forbidden`, `down` (netwerkfout) of `error`
(503), welke methoden er per modus zijn en welke betalingen bestaan (een
betaling is alleen zichtbaar voor een sleutel van haar eigen modus, zoals bij
Mollie). Elk verzoek komt als JSON-regel in `<scenario>.log`, met de modus en
de laatste vier tekens van de sleutel, nooit de sleutel. Niets in de
applicatie kan de nep aanzetten: het is een statische methode, geen
omgevingsvariabele.

De drie HTTP-klassen starten hun eigen `BuiltInServer` met een eigen
sleutelmap (`SECRETS_STORAGE_PATH`), `MOLLIE_API_KEY` leeg of gepind,
`APP_URL` en, voor de webhook, een eigen factuurmap en geen
`SHOP_NOTIFICATION_EMAIL`, zodat een betaalde test-bestelling haar factuur
krijgt maar er geen mail vertrekt. Ze ruimen hun sleutels, instellingen,
bestellingen en facturen zelf op.

- `PaymentSettingsHttpTest` bewijst ook de permissie: een account met alleen
  `settings.manage` opent Shop-instellingen maar krijgt 403 op Betalingen en
  op elk vervalst verzoek (sleutel, live, methoden, verbindingstest), zonder
  dat er iets geschreven of aan Mollie gevraagd wordt; een Super Admin opent
  en slaat op.
- `MollieWebhookHttpTest` bevat de regressietest die een release nooit mag
  herhalen, in twaalf genummerde stappen: shop op Test, een bestelling met
  een betaling die gemaakt wordt zoals `api/checkout.php` dat doet (in het
  testproces, tegen hetzelfde scenario), de shop op Live, de webhook voor de
  oude testbetaling, en dan: opgevraagd met de testsleutel en geen andere,
  betaald, nog steeds TEST, de factuurteller onveranderd, geen factuur of
  PDF, de omzet onveranderd. Daarnaast een live bestelling in een testshop
  (live sleutel, factuur, omzet) en een bestelling waarvan de sleutel van
  haar modus ontbreekt (503, Mollie niets gevraagd).
- `CheckoutPaymentMethodsHttpTest` start een derde server met een
  live-sleutel en `APP_URL=http://mygdala.localhost`: de checkout weigert
  met 503 vóór er een bestelling is.

Wat de browser met Betalingen doet (de dirty-status, de vertrekdialoog, *Test
deze sleutel* zonder opslaan, de regio's na opslaan, een echte testbestelling
van winkelwagen tot bestelstatus), bewijst geen van deze tests. Na een
wijziging aan `admin/payments.php` of `payments.js` loop je het na in de
Browser-pane, op een wegwerpkopie: een eigen database, een container met
`auto_prepend_file` naar `fake-mollie.php`, een scenario met
`created.checkoutUrl` naar een harnaspagina die Mollie's testpagina speelt
(uitkomst kiezen, desgewenst de webhook afleveren, terug naar de
`redirectUrl`), en `PHP_CLI_SERVER_WORKERS` groter dan 1, zodat die pagina de
webhook op dezelfde server kan aanroepen. Voor de echte checkout zijn de
publieke Turnstile-testsleutels van Cloudflare en een echt Nederlands adres
(PDOK) nodig, en een gepubliceerde voorwaardenpagina met een Rich
text-blok.

**Wijziging aan voorraad, terug-op-voorraad, op aanvraag, bestelvelden of
specificaties** (Shop Product & Ordering 2.0, `MODULES.md`, "Shop")

```
--testsuite shop        InventoryTest (reserveren en teruggeven in de echte
                        database, één keer teruggeven bij een herhaalde
                        webhook, een mislukte betaalstart, en een echte race:
                        twee PHP-processen tegelijk om het laatste stuk,
                        tests/Support/stock-race.php), ProductInventoryHttpTest
                        (winkelwagencheck, checkout 409, een vervalste
                        toevoeging, de editor en een voorraad die intussen
                        veranderde), StockNotificationTest en
                        StockNotificationHttpTest (inschrijven zonder dubbel of
                        verklappen, 0 → meer mailt één keer, 5 → 6 niet, een
                        mislukte mail blijft actief), PurchaseModeHttpTest,
                        OrderFieldsTest, OrderFieldsHttpTest,
                        ProductSpecificationsTest,
                        ProductAdminOverviewTest (voorraadregel per soort,
                        thumbnail, geen query per product, raster/lijst),
                        OrderFieldImageUploadTest (de afbeeldingsvraag:
                        typen en groottes, SVG/tekst/HTML/polyglot/lege en
                        te grote bestanden en pixelbommen geweigerd, tokens
                        vervalst/verlopen/geclaimd/van een andere vraag,
                        vervangen, verwijderen, opruimen, één claim in de
                        ordertransactie met terugrollen, teruggeven na een
                        betaling zonder geld, winkelwagenidentiteit,
                        snapshot en mails; bestanden in een eigen tijdelijke
                        map) en OrderFieldStylingTest (bestelvelden als
                        core.css-formuliervelden)
--testsuite migration   ImageOrderFieldUploadsMigrationTest: upgrade van een
                        winkel met een beantwoorde vraag, vers = geüpgraded,
                        SET NULL/RESTRICT, herhalen verandert niets
--testsuite fast        InventoryContractTest en ProductEditorTabsTest (ook in
                        shop): reserveren binnen de ordertransactie, elke
                        mislukte betaalstart geeft terug, geen voorraadteller
                        buiten InventoryRepository, geen voorraadgetal in een
                        publieke productrij; drie tabbladen om één formulier
--testsuite cms         ModuleSystemPagesTest; ModuleSystemPagesMigrationTest
                        ook in --testsuite migration
```

`ShopStockFixture` (`tests/Support/`) maakt een product met of zonder
varianten en een bestelling met gereserveerde regels, en ruimt ze in de juiste
volgorde op (varianten vóór producten, anders weigert de sleutel van hun
optiewaarden). Wie een terug-op-voorraadmail wil zien mislukken, start de
`BuiltInServer` met `MAIL_HOST=127.0.0.1` en `MAIL_PORT=9`: PHPMailer krijgt
geen verbinding, de melding blijft actief met haar poging geteld, en er gaat
niets de deur uit. Een mail die wél slaagt, bewijst `StockNotificationTest`
met een opnemende mailer in het testproces, niet over SMTP.

**Wijziging aan Uitgelicht product, de productpayload of het koopgedeelte**
(`featured_product`, `ProductDetail`, `ProductPurchasePath`,
`partials/product-purchase.php`, `CONTENT-BLOCKS.md`)

```
--testsuite fast        FeaturedProductContractTest (ook in contract, blocks
                        en shop): shop.js per [data-product-detail] zonder
                        document.querySelector in de productcode, één
                        payloadbouwer, één koopbeslissing en één markup voor
                        het koopgedeelte, geen JSON-LD in het blok, een regel
                        in featured-product.css voor elk woord, en de guards
                        van editor en endpoint met de ModuleGuard ervoor
--testsuite blocks      FeaturedProductBlockTest (ook in shop): het blok op
                        de testdatabase — leeg, verborgen, inactief en
                        verwijderd product, het product live, de schakelaars,
                        de woorden per taal, bestellen per ProductPurchasePath,
                        geen prijs waar die niet hoort, voorraad en varianten,
                        unieke id's bij twee blokken, galerij en layout,
                        Shop uit en weer aan; FeaturedProductHttpTest (ook in
                        shop): editor en endpoint over BuiltInServer, met een
                        tweede server met de Shop uit, en api/cart-check.php
                        en api/stock-notification.php voor wat het blok
                        stuurt; FeaturedProductMigrationTest (ook in
                        migration)
--testsuite shop        ProductPurchasePathTest: de payload en de vier
                        antwoorden van de koopbeslissing
```

De productpagina zelf mag er niet door veranderen. `PersonalizationProductPageTest`
heeft de HTTP-tier nodig; zonder die tier vergelijk je `product.php` en
`api/product.php` van `main` en van je werkkopie op dezelfde database (twee
keer `php -S`, dezelfde producten, witruimte tussen tags weggenormaliseerd).

Wat een klik in het blok doet (drie stuks in één klik, variant wisselen,
bestelvraag leeg laten, terug-op-voorraad, vegen), bewijst geen van deze
tests. Loop het na in de Browser-pane op een wegwerpkopie, met de Shop aan
én uit.

Wat de overgang in een browser doet (vervagen, schuiven, vegen, verticaal
scrollen, reduced motion), bewijst geen van deze tests. Na een wijziging aan
`product-gallery.js` loop je het na in de Browser-pane. Een pane die niet
tekent bevriest CSS-transities en laat `img.decode()` niet oplossen: pauzeer
de `getAnimations()` van beide foto's, zet `currentTime` op de helft en lees
`opacity`/`transform` af.

Wat een klik in de browser doet, bewijst geen van deze tests. Na een
wijziging aan `admin-editor.js`, `product-variants.js`, `row-list.js` of
`admin-sidebar.js` loop je het na in de Browser-pane:

- optie en waarde toevoegen zonder herladen;
- een variant uit die nieuwe waarde maken;
- opslaan: de rijen hebben daarna hun id;
- een geweigerde opslag opent de ingeklapte sectie;
- een zijbalklink met wijzigingen geeft de dialoog, en *Opslaan en doorgaan*
  gaat pas door na een geslaagde opslag;
- Ctrl-klik en een nieuw tabblad worden niet onderschept.

Doe dat op een wegwerpkopie van de database, niet op ontwikkeling.

**Wijziging aan de Hover kaarten grid, een mediareeks, de kop van Tekst met
afbeelding of de flyouts van het menu** (`hover_card_grid`,
`MediaSequence`, de Paginakop en de Mediabanner, `CONTENT-BLOCKS.md`)

```
--testsuite fast        MainNavMarkupTest: alleen het pijltje van niveau 1
                        draait, de flyout sluit op zijn paneel aan
--testsuite blocks      HoverCardGridRenderTest (keuzes als klassen, één
                        link per kaart met een naam, de tweede afbeelding
                        als versiering, lege kaarten en het lege blok) en
                        HoverCardGridHttpTest (editor en endpoint over
                        BuiltInServer: opslaan in één keer, weigeringen bij
                        het veld, volgorde, verwijderen, taal);
                        MediaSequenceTest (de gesloten lijsten, de tokens,
                        de markup) en MediaSequenceHttpTest (Paginakop en
                        Mediabanner met meer items: opslaan, doorschuiven,
                        weigeren, de markup op de pagina, één item
                        ongewijzigd); PageHeroImageModeTest (het kruimelpad
                        links en boven de kolommen); TextImageSplitItemsTest
                        (de kop boven de items, H3 eronder);
                        HoverCardsAndMediaSequenceMigrationTest (ook in
                        migration: vers en na een upgrade, met eigen
                        wegwerpdatabases mygdala_scratch_hover_sequence_*)
```

**Wijziging aan Reviews** (`reviews`, `CONTENT-BLOCKS.md`, "Reviews")

```
--testsuite fast        ReviewsContractTest (ook in contract en blocks,
                        geen database): de vorm van één review (sterren
                        1..5 of geen, een bestaande datum, alleen een
                        webadres als bronlink), de vier weergaven en hun
                        eigen CSS, de uitgelichte review en haar
                        terugval, de carrousel (regio, groepen, pijlen,
                        nooit automatisch, minder beweging), geen lege
                        sterrenrij, escapen en niets afgesneden, de knop
                        met Button Styles, alleen thematokens, Extra
                        vormgeving op de root, de definitie en de editor
--testsuite blocks      ReviewsHttpTest (ook in cms, eigen php -S): de vier
                        guards en de eigenaar van de bloklijst, draft en
                        annuleren zonder achterblijvers, de eerste opslag
                        en terug naar de pagina, toevoegen, wijzigen,
                        verschuiven, verwijderen, verplichte tekst,
                        sterren, datum, bronadres, vervalste ids, de
                        uitgelichte review, de knop, XSS, maximale
                        lengtes, talen en de fallback, de foto als gebruik,
                        de editor
--testsuite migration   ReviewsMigrationTest (ook in blocks; ScratchInstall,
                        wegwerpdatabases mygdala_scratch_reviews_*): vers
                        en na een upgrade, standaarden, de kolommen van
                        het portret, CASCADE en RESTRICT, tweede run
```

Overdag draaien de contracttest en de HTTP-test gericht; de migratietest
hoort bij de nachtelijke run.

**Wijziging aan de weergave van een beeld: focuspunt, zoom,
telefoonafbeelding, vullen of hele afbeelding, beeldverhouding op een rij**
(Responsive Media, `MEDIA.md`)

```
--testsuite fast        ResponsiveImageTest en ResponsiveImageRenderTest
                        (ook in unit en blocks): de waarde zonder database
                        en de markup van partials/responsive-image.php;
                        ResponsiveMediaContractTest (ook in contract en
                        blocks): één breekpunt, één markup, de zeven
                        plekken in repository, migraties en gebruik
--testsuite blocks      ResponsiveImageEditorHttpTest (het veld over
                        BuiltInServer: scherm in NL en EN, opslaan en de
                        <picture> op de pagina, geweigerde
                        telefoonafbeeldingen, gebruik, de beeldverhouding
                        van de carrousel, Hover kaarten, Homepage-hero);
                        ResponsiveMediaMigrationTest (ook in migration en
                        migration-backfill: vers en na een upgrade, met
                        wegwerpdatabases mygdala_scratch_responsive_media_*)
--testsuite fast        (3.0, v0.1.14) ResponsiveImageZoomTest (ook in unit
                        en blocks): de zoom zonder database, klemmen en
                        weigeren, telefoonzoom bij het eigen punt, 100% is
                        de oude <img>; ResponsiveMediaZoomContractTest (ook
                        in contract en blocks): elk kader knipt af, alleen de
                        partial zoomt, het zoomveld, lui wekken, vaste
                        listeners, thumbnails als preview
--testsuite blocks      ResponsiveImageZoomHttpTest (BuiltInServer: een
                        kaart met zoom en telefoonzoom, klemmen en weigeren,
                        contain en terug, een getuige per plek (alle acht)
                        op pagina en in de editor, de vier bronnen van een
                        Detailsectie, een lege nieuwe rij, geen nieuw
                        gebruik); ResponsiveMediaZoomMigrationTest (ook in
                        migration: vers en na een upgrade, 100 en NULL, niets
                        anders veranderd, opnieuw draaien, met
                        wegwerpdatabases mygdala_scratch_rm_zoom_*)
```

**Wijziging aan de koppen van een blok met kaarten**
(`App\Service\Blocks\CardHeading`, `CONTENT-BLOCKS.md` "Koppen in kaarten")

```
--testsuite fast        CardHeadingContractTest (ook in contract en blocks):
                        het voorbeeld van elk blok met en zonder bloktitel
                        zonder overgeslagen niveau en zonder lege kop, h3
                        onder een titel en h2 zonder, één klasse per
                        kaarttitel, de productkaarten via
                        data-card-heading, geen partial die een kaarttag
                        zelf schrijft
--testsuite blocks      CardHeadingPageTest: een echte pagina over
                        BuiltInServer (h1, een raster met en een zonder
                        titel, een stappenplan), een weggehaalde titel, en
                        het niveau in de taal van de request
```

Wat een reeks in een browser doet (de tijd per beeld, een video die
uitspeelt en dan de volgende, pauzeren, pijlen, bolletjes, vegen) en wat een
kaart doet bij hover, focus en een tik, bewijst geen van deze tests. Loop het
na in de Browser-pane op een wegwerpkopie. Een verborgen pane heeft
`document.hidden` op `true`, en dan wacht een reeks (zoals hij hoort te
doen): zet voor een meting `document.hidden` en `visibilityState` met
`Object.defineProperty` op zichtbaar en stuur een `visibilitychange`. Video's
voor zo'n test maak je in de pane zelf met een `<canvas>`,
`captureStream()` en `MediaRecorder` (WebM), en upload je met
`/api/admin/media-upload.php`.

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

### Blokken op een product of project

Product & Portfolio Content Pages 1.0 en de projectlayout hebben drie
testklassen. `ContentOwnerPagesTest` (database: eigenaarschap, isolatie van
pagina's, welke blokken waar mogen, volgorde, taal, bestemmingskiezer,
verwijderen, `RESTRICT`, mediagebruik) en `ProductPortfolioContentPagesHttpTest`
(eigen `php -S` via `Tests\Support\BuiltInServer`: `product.php` en
`portfolio-detail.php` met en zonder blokken, de vier layouts, standaard en
eigen keuze, Projectinformatie, module uit, de admin-endpoints) zitten in
`blocks` en `shop`. `ContentOwnerPagesMigrationTest` (vers en bijgewerkt,
opnieuw draaien) zit in `migration` en `blocks`.

Sinds v0.1.13 ook: `ContentBlockOwnerAccessHttpTest` (eigen `php -S`: wie de
blokken van een pagina, product en project mag beheren, nagemaakte sleutels,
pagina-, blok- en kaart-id's, de weg terug naar de eigenaar; `blocks` en
`shop`) en in `ProductPortfolioContentPagesHttpTest` de echte vrije indeling
(geen automatische kop, de overstap die één Projectinformatie-blok zet, de
waarschuwing, terug naar vast en weer vrij, de standaard die vrij wordt).
`AdminAccessControlTest` houdt de eigenaarsbewuste blokbestanden als gesloten
lijst bij. `ContentBlockMediaUsageOwnerHttpTest` (de links in het mediagebruik
voor een Pagina-, Shop-, Portfolio- en super-admin; `blocks` en `shop`) en
`LinkedImagePreviewHttpTest` (de live foto van een galerijbron voor het
focuskader, wat niet openbaar is, wie mag vragen; `blocks`).

### De levensloop van een nieuw blok (v0.1.14)

Contentblokken UX & Lifecycle 1.0 (`CONTENT-BLOCKS.md`, "De levensloop van een
nieuw blok" en "Een leeg blok herkennen") heeft drie eigen testklassen:

| Test | Suite | Wat |
|---|---|---|
| `ContentBlockLifecycleContractTest` | `contract`, `fast`, `blocks` | Elk blok dat als draft opent: editor met `block_editor_draft_notice()`, endpoint met `ContentBlockDrafts::place()` in de transactie en `afterSaveUrl()`; elk toe te voegen blok implementeert `InspectsContent` of staat in `NEVER_EMPTY`; de guards van `discard-block-draft.php`; geen terugkeeradres uit het request |
| `ContentBlockLifecycleTest` | `blocks`, `cms` | Op de database: een draft staat op geen pagina, `place()` onderaan en één keer, een teruggedraaide opslag plaatst niets, een tweede draft van een type met maximum één wordt geweigerd, annuleren raakt geen ander blok en nooit een geplaatst blok, opruimen na 48 uur, een pagina verwijderen neemt de drafts mee, de inhoudspagina van een product verdwijnt met zijn geannuleerde eerste blok; de lege-blokregels |
| `ContentBlockLifecycleHttpTest` | `blocks`, `cms` | Over echte HTTP (eigen `php -S`, Shop en Portfolio aan): kiezen, annuleren, teruggaan, opslaan, dubbel opslaan, validatiefout, bestaand blok, geneste pagina, product, project, CSRF, vervalste eigenaar, vervalst type, te weinig rechten, een vervalst terugkeeradres, de waarschuwing in de paginabouwer en niet op de website |

**Een blok-save landt niet meer op zijn editor.** Een test die "is het
opgeslagen?" vraagt, gebruikt `Tests\Support\SavedRedirect::PATTERN`: het
nieuwe `saved=<id>#blok-<id>` van een blok en het oude `saved=1` van elk ander
scherm. Een kale `assertStringContainsString('saved=1', …)` slaagt per ongeluk
op `saved=12#blok-12` en faalt op `saved=7#blok-7`. Een test die na het opslaan
de editor opnieuw wil zien, vraagt die editor zelf op in plaats van de redirect
te volgen.

**Een nieuw blok in een test**: `ContentBlockDrafts::open()` gevolgd door
`place()` is precies wat de kiezer en de eerste opslag doen. De oude
`SectionRegistry::create()` + `PageSectionRepository::create()` blijft geldig
voor een blok "zoals de oude flow het achterliet" (een leeg legacyblok).

### Performance van de editor (Responsive Media 3.0)

De focus- en zoomeditor mag een scherm met veel beelden niet zwaarder maken.
Het contract staat in `ResponsiveMediaZoomContractTest`; de meting die het
onderbouwt is geen PHPUnit-test, want een browser moet renderen. Ze is in
v0.1.14 zo gedaan, en zo te herhalen:

- een wegwerpcontainer van het image met `php -S` op de worktree én een op de
  hoofduitchecking (de code van vóór de wijziging), beide op dezelfde
  wegwerpdatabase met een zware testpagina: een Detailsectie met 24
  galerij-items (12 bibliotheek, 4 product, 4 project, 4 bericht), Hover
  kaarten met 12 en Tekst met afbeelding met 8 items (4 met een eigen
  telefoonafbeelding), echte JPEG's van 3000×2000 door de echte uploader;
- headless Chrome over het DevTools-protocol met een eigen tijdelijk profiel,
  elke meting in een eigen browsercontext (eigen renderer, lege cache), base
  en na om en om, vijf keer, de mediaan;
- per scherm: beeldverzoeken en bytes bij het laden, gewekte velden,
  listeners op `document` en binnen de velden
  (`DOMDebugger.getEventListeners`), heap na een GC, DOMContentLoaded en load,
  dan scrollen door het hele scherm (frames, lange taken), een invoer tot het
  volgende frame, een ingeklapte rij openen, een rij toevoegen en markeren
  voor verwijderen, en consolefouten.

Het Browser-paneel van de app is hiervoor ongeschikt zolang het verborgen is:
dan draait er geen `requestAnimationFrame` en laadt geen enkele luie
afbeelding.

### Labels, focuspunten en de menuboom (v0.1.13)

`CardCarouselLabelModeHttpTest` (de vijf labelweergaven van een kaart, nummers
die een volgorde volgen, het icoon en zijn gebruik, eigen tekst per taal) en
`DetailSectionLabelModeHttpTest` (de nummering over de Detailsecties van de
pagina, de modi, de ankernavigatie los daarvan) en
`DetailSectionGalleryFocusHttpTest` (het focuspunt per galerij-item voor alle
vier bronnen, een nieuwe foto van een item met het oude punt, klemmen en
weigeren) zitten in `blocks`; `LabelModesAndGalleryFocusMigrationTest` (vers
en bijgewerkt, wat een kaart toonde blijft, opnieuw draaien houdt een latere
keuze) in `migration` en `blocks`; `NavigationTreeContractTest` (inklappen
alleen als weergave, slepen neemt het submenu mee) in `contract`, `fast` en
`cms`, naast de nieuwe controles in `NavigationAdminHttpTest`.

### Detailsectie 2.0 en het menu

`DetailSectionTwoTest` (database: de ankervorm, de ankernavigatie onder de
kop en per taal, de galerijbronnen live en wat wegblijft, de strook) en
`DetailSectionTwoHttpTest` (eigen `php -S`: het endpoint, beeldpositie,
bronnen) zitten in `blocks`; `DetailSectionTwoContractTest` (de strook, de
modulegrens van `LinkedImages`, de ene ankernavigatie, het mobiele menu) in
`contract` en `fast`; `DetailSectionSourcesMigrationTest` in `migration` en
`blocks`.

Detailsectie 2.1 (`CONTENT-BLOCKS.md`, "Detailsectie 2.1"):
`DetailSectionTwoOneTest` (database: automatische hoofdafbeelding van product
en project, links per taal, een nieuwe hoofdafbeelding volgt, de naamtegel
zonder afbeelding, focus en zoom per item, module uit, vervalste bron, Blog,
escaping) en `DetailSectionTwoOneHttpTest` (eigen `php -S`: één bronkeuze
zonder tweede afbeelding, inklapbare items op hun rij-id, de preview met
`picture`, rechten en CSRF, concept tot de eerste opslag) zitten in `blocks`;
`DetailSectionTwoOneContractTest` (de haak `data-nav-item-form`, de inklapbare
rij, het script, geen `<img>` zonder bron) in `contract`, `fast` en `blocks`.

De hoogte van de Oproep met knop (`CONTENT-BLOCKS.md`, "De hoogte van het
achtergrondvlak"): `CtaBandHeightTest` (geen database: `auto` is byte voor
byte de oude markup, elke keuze een klasse op de kaart of de sectie, een eigen
hoogte één pixellengte, ongeldige waarden en waarden buiten het bereik lezen
als `auto`, alleen `min-height` en geen `height`/`overflow`, de pixels van
`CtaBandContent` gelijk aan het CSS, afbeelding/focus/zoom en knopstijlen
ongewijzigd) en `CtaBandHeightHttpTest` (eigen `php -S`: bestaande en nieuwe
oproep `auto`, elke keuze opgeslagen en heropend, eigen hoogte desktop en
telefoon, weigering bij het veld zonder opslag, het getypte getal terug,
focus en zoom en knopstijlen blijven, concept tot de eerste opslag,
annuleren laat niets achter) in `blocks`; `CtaBandHeightMigrationTest`
(ScratchInstall: bestaande oproepen `auto`, geen bestaande kolom verandert,
vers = geüpgraded, tweede run verandert niets) in `migration` en `blocks`.

Extra vormgeving (`CONTENT-BLOCKS.md`, "Extra vormgeving"):
`BlockAppearanceContractTest` (geen database, in `contract`, `fast` en
`blocks`) dekt:

- de gesloten lijsten en hun standaard;
- validatie tegen de lijsten én tegen wat het blok ondersteunt, met CSS in
  een waarde, een kleurcode en een onbekend effect;
- de klassen, en `apply()` op de root naast eigen klassen en `style`;
- de laag als eerste kind met `aria-hidden`;
- dat een leeg blok leeg blijft en Standaard byte voor byte is;
- de capability-tabel `SUPPORT`, en per ondersteunend blok zijn echte
  voorbeeld met een root en een `.container`;
- de carrousel met `.bg-soft` en de CTA-hoogte naast een vormgeving;
- de stylesheets: alleen tokens, twee klassen, geen hoogte, het effect
  binnen het blok met `pointer-events: none`, reduced motion, geen script.

`BlockAppearanceTest` (database, `blocks` en `cms`) dekt de standaard op een
nieuwe rij, vormgeving per instantie en alleen dat blok anders, terug naar
Standaard is byte voor byte de oude pagina, meerdere effecten met één
stylesheet, niet-ondersteund en verborgen, draft en annuleren,
verslepen/verbergen/verwijderen, en de inhoudspagina van product en project.

`BlockAppearanceHttpTest` (eigen `php -S`, `blocks` en `cms`) dekt het paneel
alleen waar het past, opslaan van één blok tot de publieke pagina en terug,
CSRF, GET, vervalst id, ongeldige en niet-ondersteunde waarden, geen recht,
en de eigen beheerder van een product.

`BlockAppearanceMigrationTest` (ScratchInstall, `migration` en `blocks`) dekt
de standaard voor elk bestaand blok, de galerij op `soft` die verhuist, de
draft die haar waarde houdt, geen andere kolom die verandert, een tweede run
zonder effect, en vers = geüpgraded.

Kaartweergave (`CONTENT-BLOCKS.md`, "Kaartweergave"):
`CardPresentationContractTest` (geen database, in `contract`, `fast` en
`blocks`) dekt de gesloten lijst en het veilig lezen van een opgeslagen
waarde, wat een opslag mag aannemen (alleen wat het blok aanbiedt, de
opgeslagen waarde zonder veld, de rest geweigerd), de klassen uit één
resolver, precies de galerij en Projecten als aangesloten blokken, Standaard
gelijk aan de render zonder waarde en zonder gedeelde klasse, Compact en Breed
met elke link, zoom, knop, alt-tekst en lui beeld, geen link in een link, een
leeg beeldkader, de kaarttitel als kop volgens `CardHeading`, escapen en niets
afgesneden, de stylesheet (alleen tokens, alles onder `.card-presentation`, Breed
gestapeld op een telefoon) en het editorveld.

`CardPresentationHttpTest` (eigen `php -S`, `blocks` en `cms`) dekt beide
editors met de opgeslagen keuze, Breed en Compact op de publieke pagina met
de stylesheet en terug naar Standaard als de oude pagina, Breed samen met
Extra vormgeving, de galerij, een nieuw blok dat zijn weergave bij de eerste
opslag krijgt en een geannuleerde draft zonder rij, een vervalste waarde,
CSRF, een vervalste of andermans sectie, geen recht, en de stylesheet alleen
waar een blok een andere weergave dan de standaard heeft.

`CardPresentationMigrationTest` (ScratchInstall, `migration` en `blocks`)
dekt `default` voor elk bestaand blok, geen andere kolom die verandert, een
tweede run zonder effect, en vers = geüpgraded.

### Zoeken

De zoekfunctie heeft drie testklassen (`SEARCH.md`, "Testen"):
`SearchCoreTest` en `SearchNavigationTest` hebben geen database nodig en zitten
in `unit`, `fast` en `cms`. `SearchProvidersTest` schrijft pagina's, producten,
projecten en berichten in één transactie die na elke test wordt teruggedraaid,
en zit in `modules`, `shop` en `blog`. De module-aan/uit-gevallen gebruiken
`ModuleRegistry::overrideForTests()`, niet de omgevingsvariabelen.

### Paginathema's

De module `page_themes` (`THEMING.md`, "Paginathema's") heeft zeven
testklassen:

| Klasse | Suites | Wat |
|---|---|---|
| `ThemeColorTest` | `unit`, `fast`, `cms` | de gedeelde kleurregel, het WCAG-contrast, de waarschuwing, de waarden van het voorbeeld, een nieuw thema begint als de site |
| `PageThemeCssContractTest` | `contract`, `fast`, `cms` | `core.css` (elke `var()`-token ook op `main[data-page-theme]`, de ondergrond, de headersluier), het attribuut in elk paginatemplate en nergens anders, de volledige tokenset, gemanipuleerde waarden, ontdubbelde lettertypes, een ongewijzigde `<head>` zonder thema |
| `PageThemesModuleTest` | `contract`, `fast`, `modules` | registratie, bijdragen aan en uit, de guards, Core noemt de module niet, geen keuze in de product- en projecteditor |
| `PageThemesAdminHttpTest` | `modules` | eigen `php -S` met de module aan, uit en niet vastgezet: maken, hernoemen, dupliceren, verwijderen, weigeren (ook RESTRICT in de database), de keuze op de pagina, module uit, de schakelaar op Vormgeving |
| `PageThemesRenderingHttpTest` | `modules` | eigen `php -S` met de dispatcher: tokens en lettertypes in `main`, geen overerving, `/en`, SEO gelijk, een pagina zonder thema byte voor byte gelijk, module uit en weer aan, gemanipuleerde rijen, veel bloktypes, het voorbeeld in de editor, product en project zonder thema |
| `PageThemesApacheHttpTest` | `http`, `modules` | echte Apache: `php_test` toont het thema (NL en EN), `php_cms` (module uit) niet |
| `PageThemesMigrationTest` | `migration`, `modules` | vers, bijgewerkt en opnieuw: bestaande pagina's `NULL`, de RESTRICT-sleutel, dezelfde kolommen |
| `FontLibraryHttpTest` | `cms`, `modules` | eigen `php -S`: een paginathema met een eigen lettertype, met de module aan en uit (zie ook de Font Library hierboven) |

Dat een paginathema los staat van het actieve kleurenpalet (activeren
verandert het thema niet; module uit geeft het palet, weer aan het thema;
header en footer volgen het palet) staat in `ColorPalettesHttpTest`, dat ook
in `modules` zit. `PageThemesAdminHttpTest` telt alleen zijn eigen
`ZZ Test`-thema's: een testdatabase kan al een thema bevatten.

`docker-compose.yml` en `tests/Support/http-tier.sh` zetten
`MODULE_PAGE_THEMES_ENABLED` vast: aan in `php_test`, uit in `php_cms`. Na
een wijziging daarin eerst `tests/Support/http-tier.sh down`.

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

### De echte HTTP-tier draaien: `tests/Support/http-tier.sh`

Een run waarin de HTTP-tests zichzelf overslaan bewijst niets over de
HTTP-laag. Het checkpoint en de release draaien de suite `http` daarom altijd
tegen echte servers, met één script, vanuit de uitchecking die je test (de
hoofduitchecking of een worktree):

```bash
tests/Support/http-tier.sh run --db mygdala_tests
tests/Support/http-tier.sh run --db mygdala_tests_<naam> --testsuite fast
tests/Support/http-tier.sh down
```

`run` start zo nodig twee wegwerpcontainers uit het image van de installatie,
op haar eigen Docker-netwerk, onder de aliassen `php_test` en `php_cms`. Ze
serveren **deze** uitchecking tegen de genoemde testdatabase, en PHPUnit draait
in de `php_test`-container, zodat testproces en webserver dezelfde bestanden en
dezelfde database zien. Zonder PHPUnit-argumenten draait `--testsuite http`.
`down` ruimt de containers op; `status` laat ze zien.

Waarom niet `docker compose --profile test up -d`: die services mounten de
hoofduitchecking, dus vanuit een worktree zou je de verkeerde code testen, en
ze hebben het `.env` van de hoofduitchecking nodig. Wat het script van de
installatie overneemt zijn alleen de databasegegevens, uit haar draaiende
`php`-container, als omgevingsvariabelen: nooit in een bestand, nooit op een
commandoregel en nooit geprint.

**Het contract staat in het script en in `docker-compose.yml`, niet in `.env`.**
Beide routes zetten hetzelfde vast:

- `APP_ENV=production`. De SEO-tests bewijzen wat een live site doet
  (robots, `noindex`), en een `APP_ENV=local` uit `.env` veranderde dat
  stilletjes. Productie is ook wat een lege `APP_ENV` betekent.
- Elke module expliciet. `php_test`: Shop, Personalisatie, Blog, Portfolio en
  Meertaligheid aan. `php_cms`: Shop, Personalisatie, Blog en Portfolio uit,
  Meertaligheid aan (dezelfde header als `php_test`). Zonder die regels
  besliste de voorkeur die toevallig in de testdatabase stond, en faalden
  ruim veertig tests om die reden.
- De database: het script weigert de ontwikkeldatabase en elke naam zonder
  `test` erin, nog vóór er een server bestaat; `tests/bootstrap.php` weigert
  daarna nog eens.
- Opslag in een tmpfs: uploads en bestanden van de servers bestaan niet
  langer dan de container.

**Wat een HTTP-test zelf regelt.** Een test mag niet leunen op wat de
testdatabase toevallig bevat. Hij maakt zijn eigen pagina's, producten,
blokken en instellingen aan, via de huidige repositories en
localisatieservices (woorden in de per-taaltabellen, niet in oude kolommen), en
zet in `tearDown()` precies terug wat hij veranderde. Twee helpers:

- `Tests\Support\TemplatePageFixture` — `contact.php`, `diensten.php` en
  `over-mij.php` renderen alleen met hun gepubliceerde `pages`-rij. Die rijen
  hoorden bij de installatie waar deze code uit is gegroeid, niet bij een
  verse. `ensureAll([...])` maakt ze aan waar ze ontbreken, `remove()` haalt
  alleen weg wat hij zelf maakte.
- `Tests\Support\AdminTestSession` — PHPUnit draait in `php_test` als root en
  Apache als `www-data`. Een sessiebestand van root (0600) kon Apache niet
  lezen, zodat elk ingelogd verzoek via Apache uitgelogd aankwam. `signIn()`
  geeft het bestand daarom aan `www-data` als het testproces root is; onder
  `php -S` verandert er niets.

**Taal en cookies.** Elk verzoek in de HTTP-tier gaat zonder cookiejar de
deur uit, dus geen test erft de `site_language`-cookie van een vorige. Een
eerste bezoek zonder die cookie krijgt hem wel: `LanguagePreference` bewaart de
gelezen taal zodra die zou veranderen, en bij een eerste bezoek is dat altijd
(`docs/multilingual/ROUTING.md`). `AnalyticsTrackingHttpTest` staat daarom
precies die ene, noodzakelijke cookie toe en weigert elke andere.

**Stand bij v0.1.13.** Tegen echte servers: 327 tests, 2252 asserties, 0
overgeslagen, 0 failures, ongeveer twee minuten. Tot v0.1.12 sloegen 222 van
de toen 310 tests zichzelf over, en 49 van die 310 faalden tegen echte servers
door verouderde fixtures (van vóór Meertaligheid 2.0), ontbrekende
Van Veluw-pagina's, `APP_ENV=local`, opgeslagen modulevoorkeuren en de
sessierechten hierboven. Een overgeslagen HTTP-test in een run met het script
is dus een fout, geen omstandigheid.

De suite `http` bevat sinds v0.1.13 ook drie Apache-getuigen die alleen een
echte server kan leveren: `Search\SearchHttpTest` (`/zoeken` en
`/en/search` aan/uit, escaping, meerdere woorden),
`ContentOwnerPagesRoutingTest` (blokken op product- en projectpagina's, de
vrije layout, Detailsectie-ankers en een gekoppelde galerijbron, 404 op
`php_cms`) en `OrderFieldUploadHttpTest` (de upload via Apache, SVG
geweigerd, bestanden niet per URL bereikbaar, de beheerdersdownload alleen
met `orders.view`).

**Tien HTTP-tests hebben de testcontainer niet nodig.** `PagePreviewAccessTest`
(suite `cms`), `PageBuilderScreenTest` (suites `blocks` en `cms`),
`MediaUsageAccessTest` (suite `cms`), `PortfolioModuleHttpTest` (suite
`modules`), `PortfolioItemEditingHttpTest` (suite `cms`),
`BlockPreviewAccessTest` (suites `blocks` en `cms`),
`PageHeroEditorHttpTest` (suite `blocks`), `FormAdminHttpTest` (suite `cms`),
`FormFieldEditorHttpTest` (suite `cms`), `FormUploadHttpTest` (suite `cms`) en `FormSubmissionBulkHttpTest` (suite `cms`) starten voor de duur van de klasse PHP's eigen webserver (`php -S`)
op deze uitchecking, tegen de testdatabase, en loggen een beheerder in met
een echte sessie. De twee Portfolio-tests, `BlockPreviewAccessTest`,
`PageHeroEditorHttpTest` en de drie Forms-tests doen dat met
`Tests\Support\BuiltInServer`, dat de server ook een eigen omgeving kan
meegeven, zoals een moduleschakelaar, een mailserver die niet bestaat of een
eigen opslagmap, eigen php.ini-waarden (`FormUploadHttpTest` start een
tweede server met een `post_max_size` van 1 MB), en dat de headers van een
antwoord teruggeeft. Dat kan omdat niets van de
conceptpreview, het blokvoorbeeld, de paginabouwer, de mediabibliotheek, het
Portfolio-beheer, de paginakop of de formulieren in Apache zit:
`admin/page-preview.php`, `admin/block-preview.php`, `admin/page.php`,
`admin/media.php`, `admin/portfolio-item.php`, `admin/page-hero.php`,
`admin/form.php`, `admin/form-field.php`, `admin/form-preview.php`, `api/form-submit.php` en de endpoints onder `api/admin/`
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
  dat een projectadres met een legacy-koppeling doorstuurt voordat het iets van
  de projectpagina leest,
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
- `PortfolioProjectRoutingHttpTest` (suite `modules`) — Portfolio 2.0 via de
  dispatcher (`tests/Support/dispatcher-router.php`): `/portfolio` 200 met
  canonical `/portfolio`, `/portfolio.php` en `/en/portfolio.php` 301 naar de
  root in dezelfde taal met querystring, een POST niet doorgestuurd, de
  sitemap en het kruimelpad met de root; `/portfolio/<slug>` 200
  met eigen titel, canonical, description, og:image, kruimelpad en één
  lightbox; een onbekende, verborgen of uitgeschakelde projectpagina 404; een
  hernoemd project 301 naar zijn nieuwe adres; een legacy-koppeling 302; een
  gewone pagina ernaast antwoordt; geen `pages`-rij; `portfolio` als
  paginaslug geweigerd met de module genoemd.

Voor Portfolio 2.0 verder, per suite:

| Test | Suite | Wat |
|---|---|---|
| `PortfolioTwoContractTest` | `contract`, `fast`, `cms` | één lightbox (dialoog, benoemde knoppen, groep van de opener en alleen wat getoond wordt, toetsenbord, focus), zoomknop en CTA-link, geen paginaflow |
| `PortfolioProjectGalleryTest` | `cms` | galerijtokens (volgorde, geen hoofdafbeelding, geen dubbel, geen foto van een ander item), vervangen en teruggeven, mediagebruik als hoofdafbeelding en galerij, gelaagde alt, slug uniek en genormaliseerd, redirect alleen bij een publieke hernoeming, metadata |
| `PortfolioProjectPageTest` | `cms` | het kaartcontract: nooit een link, altijd zoom, *Bekijk project* naar legacy-pagina of eigen pagina, overlay titel/tekst/knop, fallbacklink genegeerd, sitemap |
| `PortfolioItemEditingHttpTest` | `cms` | over HTTP: projectpagina aan met slug uit de titel en geen `pages`-rij, slug genormaliseerd/geweigerd/uniek gemaakt, 301 bij hernoemen, sanitizer, sectiemarker, galerij via de bibliotheek, legacy-koppeling alleen houden of ontkoppelen, geen paginaflow, guards |
| `PortfolioRootInstallTest` | `migration`, `cms` | `/portfolio` over HTTP op een verse installatie (geen Portfolio-pagina, eigen overzicht 200, projectpagina, 301 van `/portfolio.php`, sitemap één keer, uit = 404 op alle drie, weer aan zonder nieuwe pagina, bootstrap twee keer idempotent, een gewone pagina *Portfolio* niet overgenomen) en op een legacy-installatie (haar eigen pagina op `/portfolio`, zelfde id, één keer in de sitemap) |
| `PortfolioTwoMigrationTest` | `migration`, `cms` | `portfolio_item_images.media_id`: vers en na een upgrade, oude foto onaangeroerd, `RESTRICT`, cascade met het item, tweede run verandert niets |

Voor de gerelateerde projecten, Projecten 2.0 en het verdwijnen van *Toon op
homepage*:

| Test | Suite | Wat |
|---|---|---|
| `PortfolioRelatedProjectsTest` | `unit`, `fast`, `cms` | de keuze zonder database: standaard uit, gesloten lijsten, automatisch op gedeelde categorieën (meest gedeeld eerst, nieuwste bij gelijkspel), handmatig in volgorde, hybride zonder dubbelen, nooit het project zelf, verborgen en verwijderd weg, maximum, aanvullen of niet, de andere volgordes, willekeurig alleen uit de geldige pool en één trekking per keuze |
| `RandomOrderTest` | `unit`, `fast`, `blocks`, `cms` | elk item precies één keer, hoogstens het maximum, een trekking per aanroep, een vaste seed herhaalt zich |
| `PortfolioRelatedProjectsPageTest` | `modules` | op de testdatabase en over HTTP: uit verandert niets, automatisch/handmatig/hybride, maximum en aanvullen, willekeurig per pagina, de kop per taal, de galerijkaarten onder een eigen h2 met het rasterpreset en één lightbox, Portfolio uit = 404 en de instelling bewaard |
| `PortfolioRelatedEditorHttpTest` | `modules` | de inklapbare sectie en geen homepageschakelaar, één opslag van instellingen, volgorde en woorden van één taal, een onbekende keuze geweigerd en teruggegeven, zichzelf/dubbel/verwijderd weggelaten, een formulier zonder de sectie en een oud `is_featured` veranderen niets |
| `ProjectCardsSelectionTest` | `modules`, `blocks` | alle, één categorie en handmatig, elke volgorde, willekeurig per render uit de juiste pool, een verwijderde categorie of project veilig, nooit dubbel, twee blokken op één pagina, Portfolio uit en weer aan |
| `ProjectCardsEditorHttpTest` | `modules`, `blocks` | de keuze op het scherm (bron, categorieën met aantal, volgordes, kiezer met *Verborgen*, maximumlijst plus een oud getal), één opslag met de volgorde, weigeringen, de melding bij een verwijderde categorie, de galerij-editor met dezelfde keuze |
| `PortfolioHomepageFlagRemovedTest` | `contract`, `fast`, `modules` | geen code, scherm, endpoint, script of CMS-tekst die de vlag nog kent, geen bereik `featured` |
| `PortfolioSelectionMigrationTest` | `migration`, `blocks` | de drie migraties vers en na een upgrade met echte featured-data: hetzelfde schema zonder vlag, elke featured-galerij een handmatige selectie van dezelfde projecten in dezelfde volgorde, al het andere onveranderd, tweede run verandert niets, de sleutels |

Willekeur wordt nooit getest op "de volgende trekking moet anders zijn": een
test zet een vaste seed (`RandomOrder::useEngineForTests()`) en bewijst de
pool, het aantal, geen dubbelen en een trekking per render (`calls()`).

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
