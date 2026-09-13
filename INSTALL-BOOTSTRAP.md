# Installatie-bootstrap

Wat een **nieuwe** installatie van deze CMS krijgt, wat een **bestaande**
installatie behoudt, en waarom die twee niet hetzelfde zijn. Wijkt de code af
van dit document, dan heeft de code gelijk — pas het document aan.

Begin bij [`PROJECT-MAP.md`](PROJECT-MAP.md) als je nog niet weet waar iets
staat.

## Het probleem dat dit oplost

Bijna elke vroege migratie in dit project doet twee dingen tegelijk: ze maakt
een tabel én ze vult die met de tekst die op dat moment hardgecodeerd in de
templates van Van Veluw Laserdesign stond. Dat was toen goed — de site moest
na de migratie exact dezelfde HTML blijven renderen.

Het werd fout zodra dezelfde codebase ergens anders werd geïnstalleerd. Een
lege database liep immers ook door al die migraties heen, en kwam dus omhoog
met Diensten, Portfolio, Over mij, Contact, drie Nederlandse juridische
pagina's, een menu met zes items en een footer vol materiaallinks — van een
bedrijf waar die installatie niets mee te maken had.

**"Een latere migratie verwijdert die pagina's" is geen oplossing.** Op de
echte site *zijn* die rijen de site. Een verwijdering die er ook maar een
beetje naast zit, vernietigt live inhoud.

## De scheiding

Er is precies één plek die het verschil kent: `App\Install\InstallState`
(`src/Install/InstallState.php`), met één tabel, `install_state`.

```text
20260903120000_create_products_table.php   de eerste migratie
        ↓
InstallState::recordFreshInstall()          schrijft install_kind
        ↓
install_state.install_kind = 'fresh_generic_install'
```

De eerste migratie draait alléén op een database die vanaf nul wordt
opgebouwd — een bestaande database heeft hem lang geleden al gehad. Daarom:

| Toestand van de database | `install_state` | `isFreshInstall()` |
|---|---|---|
| Vanaf nul opgebouwd | `fresh_generic_install` | **ja** |
| Bestond al vóór deze markering | *tabel ontbreekt* | nee |
| Half gemigreerd en nu bijgewerkt | *tabel ontbreekt* | nee |

Een ontbrekende markering betekent dus **"bestaande installatie"**. Dat is met
opzet de veilige kant: een verkeerde `nee` kost een nieuwe installatie een
paar rijen die de redacteur kan weggooien, een verkeerde `ja` kost een echte
site zijn inhoud.

De vier publieke leden van `InstallState` (`TABLE`, `KEY_INSTALL_KIND`,
`KIND_FRESH`, `KIND_LEGACY` plus `recordFreshInstall()`, `isFreshInstall()` en
`kind()`) liggen **vast**. Migraties zijn historische artefacten; ze moeten
over jaren nog draaien zoals ze geschreven zijn.

## Wat Core aanmaakt bij een nieuwe installatie

`db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php` is de
enige plek waar dit antwoord staat.

| Wat | Waarom het er moet zijn |
|---|---|
| **Homepage** (`/`, `is_system = 1`) | De site-root moet altijd iets renderen. Dat is ook precies wat hem beschermt: `PageContent::isSiteRoot()` → `isProtected()`. |
| **Homepage Hero** op die pagina | Het enige blok dat exclusief op de homepage bestaat en niet verwijderd kan worden. Met duidelijk aanpasbare placeholdertekst, in dezelfde toon als elk vers toegevoegd blok. |
| **Menu-item Home** | Eén link per pagina die bestaat. Meer niet. |

Verder niets. Geen footerkolommen, geen formulier, geen juridische pagina's,
geen blokinhoud.

## Een lege database is nog geen lege pagina

Een verse installatie kan alles goed opslaan en tóch de verkeerde site tonen.
Dat is precies wat er gebeurde, en het is een aparte vraag met een apart
antwoord.

De bootstrap-migratie schrijft met opzet een **lege** afbeelding in de
Homepage Hero. `App\Service\HomepageHeroContent` behandelde leeg als
ontbrekend, viel dus terug op zijn `DEFAULTS`, en die wezen naar de
werkplaatsfoto van deze site plus de bijbehorende alt-tekst. Elke nieuwe
installatie toonde die foto op zijn homepage.

De scheiding die dat oplost is dezelfde als hierboven, één laag lager:

| Toestand | Betekenis | Wat er rendert |
|---|---|---|
| Geen rij, of database onbereikbaar | ontbrekende gegevens | niets — geen Hero, en geen vervangende tekst of foto |
| Rij bestaat, afbeelding leeg | **een antwoord**: deze Hero heeft geen beeld | geen beeld, en geen markup ervoor |

Een bestaande rij wordt letterlijk gelezen, tekst- en mediavelden allebei.
`HomepageHeroContent::hasMedia()` is wat de renderer vraagt, en zonder beeld
verdwijnt de hele mediakolom in plaats van een `<img src="">` achter te laten —
een lege `src` verwijst naar de pagina zelf en levert het
gebroken-afbeeldingicoon op.

Een lege kop komt in de praktijk niet voor: de editor eist hem, en de bootstrap
schrijft voor elk verplicht veld zijn eigen placeholder. Diezelfde generieke
placeholders krijgt ook een Hero-rij die de editor zelf aanmaakt
(`HomepageHeroContent::startingValues()`).

Wat een verse installatie verder niet meer toont: de mini-winkelwagen in de
gedeelde schil rendert leeg in plaats van een voorbeeldproduct met een prijs,
en het afhaalpunt bij het afrekenen noemt de plaats uit
`site_settings.company_city` in plaats van Nijmegen. Zie `SETUP.md`, "Een
nieuwe site beginnen".

## Wat modules aanmaken

| Module | Wat | Waarom |
|---|---|---|
| **Shop** | De pagina `/shop.php` met het blok `product_grid`, plus een menu-item | `product_grid` is applicatiekritiek: de bescherming van de winkelpagina volgt dat blok (`PageContent::isProtected()`), zodat de winkel niet per ongeluk offline gezet of verwijderd kan worden. |

**De Shop-pagina wordt ook aangemaakt als `MODULE_SHOP_ENABLED` uit staat.**
Een systeempagina (`is_system = 1` met een `route_path`) kan niet vanuit het
admin ontstaan — `PageRepository::create()` zet `is_system = 0` en
`route_path = NULL` hard — dus een overgeslagen winkelpagina zou nooit meer
terug te krijgen zijn door de module later aan te zetten. Een rij voor een
uitgeschakelde module kost niets: `ModuleGuard` antwoordt al 404 op
`/shop.php`, de sitemap laat hem al weg
(`PageContent::isServedByAnEnabledModule()`) en het menu-item hangt aan de
route van de Shop-module zelf, dus het verdwijnt met de module mee. Schema
laten afhangen van een omgevingsvariabele die daarna nog omgezet kan worden is
hier het echte risico, niet die ene reservepagina.

## Wat historische backfills bewaren

Op een bestaande installatie verandert er **niets**. Elke migratie die
site-inhoud zaaide, draait daar precies zoals altijd; de nieuwe
bootstrap-migratie doet er niets. Bewezen door
`Tests\Install\LegacyUpgradeTest`, dat een database opbouwt die dezelfde
codepad neemt als de echte: pagina's, hun id's, hun routes, hun blokken, het
menu, de footer, het contactformulier en de opgeslagen CTA-bestemmingen.

Concreet blijft op de bestaande site staan wat er stond: Diensten, Portfolio,
Over mij, Contact, Verzenden & retourneren, Algemene voorwaarden,
Privacyverklaring, alle blokinhoud, alle vaste URL's en alle navigatie- en
footerverwijzingen.

## Waarom paginasjablonen de gezaaide pagina's vervangen

Diensten, Over ons, Contact en een landingspagina zijn **gewone
CMS-pagina's**. Ze hoeven niet te bestaan voordat iemand ze wil, en ze zijn in
één handeling te maken: *Nieuwe pagina* → een sjabloon kiezen
([`PAGE-TEMPLATES.md`](PAGE-TEMPLATES.md)). Het sjabloon zet de blokken neer
die zo'n pagina meestal heeft, en daarna is het een doodgewone pagina.

Zaaien had drie nadelen die een sjabloon niet heeft: de pagina bestaat ook als
de site hem niet nodig heeft, hij staat meteen gepubliceerd, en zijn inhoud
komt uit een migratie in plaats van uit de redacteur.

**Juridische pagina's worden bewust niet meer aangemaakt.** Welke pagina's een
bedrijf zijn klanten verschuldigd is, hangt van dat bedrijf af — een webshop
in Nederland heeft andere verplichtingen dan een portfoliosite zonder
verkoop. Drie Nederlandse concepten zaaien is gokken, en een concept dat
niemand aanpast is erger dan geen pagina.

## Wat `site_settings` krijgt

**Niets, op een handvol generieke schakelaars na.** Sinds de Setup Wizard
([`SETUP.md`](SETUP.md)) laten ook de instellingen-seeds een database zonder
geschiedenis met rust:

| Migratie | Wat zij op een bestaande installatie doet | Op een verse |
|---|---|---|
| `20260904190000_create_site_settings_table` | Naam, logo, favicon, e-mailadres, plaats, KVK en de footertekst zaaien | slaat het zaaien over |
| `20260906030000_add_og_image_path_to_site_settings` | De deel-afbeelding van de homepage vastleggen | slaat over |
| `20260907200000_add_invoicing_and_email_settings` | Adres, website, factuurprefix `VLD-F` en de bestelmail vastleggen | slaat over |
| `20260909210000_pin_branding_paths...` | De vier merkpaden vastpinnen vóór de standaarden generiek werden | slaat over |
| `20260910110000_pin_business_details...` | Idem voor e-mailadres, plaats, KVK, footertekst, factuurprefix én de canonieke basis-URL | slaat over |
| `20260913100000_pin_the_order_number_prefix...` | Het bestelnummerprefix `VLD` vastpinnen vóór de standaard `ORD` werd | slaat over, **tenzij er al bestellingen zijn** |

Een ontbrekende rij betekent de generieke code-standaard van
`App\Service\SiteSettings`, en die is leeg: leeg betekent "deze installatie
heeft nog niets gekozen", wat elke lezer al aankan — de footer laat de regel
weg, `App\Mail\EmailIdentity` laat het onderdeel weg, en de factuur slaat de
regel over. Wat een verse installatie wél nog krijgt zijn de footer-
schakelaars en de instellingen voor gerelateerde producten: generieke waarden
die niets over een bedrijf beweren.

**Daarmee blijft ook de Mediabibliotheek leeg.**
`20260909270000_adopt_existing_cms_images_into_the_media_library` neemt de
bestanden over waar `site_settings` naar wijst; zonder merkpaden is er niets
om over te nemen, dus een verse installatie begint zonder logo, favicon en
werkplaatsfoto van een ander bedrijf.

**En de canonieke basis-URL is nu een rij.** `App\Service\AppUrl` viel terug
op een hardgecodeerd `https://www.vanveluwlaserdesign.nl`, en `APP_URL` staat
in dit project niet in `.env` — een verse installatie publiceerde dus canonieke
tags, `og:url` en een sitemap die naar deze site wezen. De ketting is nu
`APP_URL` → `site_settings.canonical_base_url` → een zichtbaar lokale
placeholder, met de huidige waarde van deze site als echte rij vastgepind. Zie
[`SETUP.md`](SETUP.md) en `SEO.md`.

**En het bestelnummerprefix is een instelling.**
`App\Repository\OrderRepository::formatOrderNumber()` schreef `VLD-` met de
hand uit, dus elke installatie nummerde haar bestellingen als deze site. De
standaard is nu `ORD`, en de beheerder wijzigt hem bij Shop-instellingen, op
het tabblad Bestellingen, los van het factuurnummer. Een installatie die al nummers uitgaf
is eerst op `VLD` vastgepind. Dat geldt ook voor een verse installatie die al
bestellingen had: ook zij heeft `VLD-`-nummers verstuurd. Een database die
vanaf nul wordt opgebouwd heeft op dat moment geen bestellingen en krijgt dus
niets.

**En een bestelnummer wordt opgeslagen, niet afgeleid.** Een bestelling krijgt
haar nummer één keer, in de transactie waarin
`App\Repository\OrderRepository::create()` haar aanmaakt, en bewaart het in
`orders.order_number`. Mail, Mollie, beheer, dashboard, orderstatus, export en
factuur lezen dat veld, dus een later gewijzigd prefix geldt alleen voor nieuwe
bestellingen. `20260913120000_snapshot_the_order_number_on_every_order` voegt
de kolom op elke installatie toe en geeft bestaande bestellingen het nummer dat
ze tot dan toe kregen: het vastgepinde prefix, het jaar van `created_at` en het
id. Zij draait altijd na de pin, want Phinx voert openstaande migraties
oplopend uit. Heeft een bestaande bestelling geen `created_at`, dan stopt de
migratie voordat ze iets wijzigt en noemt ze de ids: het jaar waarmee zo'n
bestelling haar nummer kreeg is niet te achterhalen, en een nummer verzinnen
doet ze niet.

Nog wél site-specifiek op een nieuwe installatie: de verzendzones en
-tarieven en de PostNL-tarieven (Shop-bedrijfsconfiguratie). Het ene
beheerdersaccount komt uit `ADMIN_USERNAME`/`ADMIN_PASSWORD_HASH` in de `.env`
van de server en is dus per installatie anders — er worden nergens
standaardgegevens meegeleverd (`SETUP.md`).

## Is de installatie al ingericht?

`InstallState` beantwoordt "wat voor database is dit". De tweede vraag — "is
de wizard al afgerond" — hoort bij `App\Install\SetupState`, dat dezelfde
key/value-tabel gebruikt en er één rij aan toevoegt:

```text
state_key = 'install_kind'        'fresh_generic_install'
state_key = 'setup_completed_at'  afwezig tot de wizard klaar is
```

Geen tweede tabel, geen tweede markering, geen migratie. Een bestaande
installatie heeft `install_state` niet en geldt daarom als **al ingericht** —
dezelfde veilige richting die hierboven al gekozen is. Zie
[`SETUP.md`](SETUP.md).

## Testen

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite migration
```

| Bestand | Wat het bewaakt |
|---|---|
| `tests/Install/FreshInstallTest.php` | Wat een lege database oplevert: alle migraties draaien, Homepage bestaat en is beschermd, de Shop-pagina draagt zijn kritieke blok, géén Diensten/Portfolio/Over mij/Contact/juridische pagina's, geen blokinhoud, geen formulier, géén bedrijfsgegeven in `site_settings`, een lege Mediabibliotheek, en een redacteur kan de weggelaten pagina's daarna alsnog uit een sjabloon maken |
| `tests/Install/FreshInstallRenderTest.php` | Wat een lege database *toont*: `/`, `robots.txt` en `sitemap.xml` gerenderd tegen diezelfde wegwerpdatabase, zonder de naam of het domein van deze site, zonder de oude hero-afbeelding, zonder leeg `<img>`-element, met een lege winkelwagen — en zonder ergens de hostname van het verzoek over te nemen |
| `tests/Install/ExampleEnvironmentTest.php` | Dat `.env.example` geen levende waarde van deze site meer draagt: `APP_URL`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` en `SHOP_NOTIFICATION_EMAIL`, plus elke andere waarde die de buitenwereld bereikt. Leest het bestand per sleutel, niet als momentopname, zodat de toelichtingen erin vrij blijven veranderen |
| `tests/Install/GenericDistributionTest.php` | De identiteit die dit programma hardop uitspreekt: de User-Agent naar buiten, het afhaallabel, de afzender van transactionele mail, de lege winkelwagen, en dat de twee verwijderde publieke bestanden weg blijven |
| `tests/Install/FreshSiteCopyTest.php` | De grens tussen applicatie en site: `App\Install\FreshSiteCopyPolicy` rechtstreeks bevraagd, het exportscript echt gedraaid, en beide vergeleken met `.gitignore` |
| `tests/Service/HomepageHeroEmptyImageTest.php` | De twee betekenissen van "leeg" hierboven, in alle vier de gevallen: geen rij, opgeslagen leeg, ingesteld beeld, en video zonder poster |
| `tests/Install/LegacyUpgradeTest.php` | Wat een bestaande installatie behoudt (zie hierboven), inclusief elk bedrijfsgegeven, de merkbestanden, de canonieke basis-URL en het feit dat zij nooit in de installatiewizard belandt |
| `tests/Install/SetupCompletionTest.php` | Wat de installatiewizard bouwt op zo'n verse database, en dat een geweigerde inzending niets schrijft (`SETUP.md`) |
| `tests/Service/GenericBlockDefaultsTest.php` | Dat een vers blok geen vaste URL van deze site als startwaarde meekrijgt. Dat opgeslagen blokken daarbij niet zijn aangeraakt, bewijst `LegacyUpgradeTest` |

Beide installatietests bouwen een **wegwerpdatabase** (`ScratchInstall`,
`tests/Support/`) en draaien phinx daartegen vanaf nul. Ze kunnen niet op de
testdatabase leunen: die is een kopie van ontwikkeling en zit dus al vol met
precies de inhoud waar de vraag over gaat. Ze hebben het MySQL-rootaccount
nodig (`DB_ROOT_PASSWORD` in `.env`, net als `scripts/test-db.php`) en slaan
zichzelf over waar dat er niet is.

Let op bij `scripts/test-db.php`: de testdatabase is een kopie van
ontwikkeling, dus hij erft ook of `install_state` daar bestaat. Geen test leunt
daarop: wat een bestaande installatie behoudt, bewijst `LegacyUpgradeTest` op
een eigen wegwerpdatabase waarin de markering bewust is teruggezet.
