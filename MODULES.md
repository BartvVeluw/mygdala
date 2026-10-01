# Modules en domeingrenzen

Waar hoort nieuwe functionaliteit thuis, en wat mag waarvan afhangen. Lees dit
samen met `PROJECT-MAP.md` (waar iets staat).

Sinds stap 5 is dit géén plan meer maar de beschrijving van de code: er is een
`ModuleRegistry`, de Shop is een expliciete first-party module, en die is uit
te zetten. Wat er *niet* is staat onderaan, onder "Nog niet geïmplementeerd".

## Het model in één alinea

Eén repository, één deploybare applicatie: een **modulair monoliet**. Een CMS
Core met daaromheen een handjevol **first-party modules** waarvan de code
altijd aanwezig is. Een module uitzetten betekent dat hij *niets bijdraagt* —
geen adminonderdelen, geen permissies, geen routes, geen blokken, geen
sitemapregels, geen frontendbestanden. Het verwijdert niets, en het raakt de
database nooit aan. Geen marktplaats voor plug-ins van derden, geen losse
packages, geen microservices.

## Wat er vandaag is

| Onderdeel | Waar |
|---|---|
| Modulecontract | `src/Module/ModuleDefinition.php` — `key()` en `label()` verplicht, alle bijdragen optioneel met een lege standaard |
| Register | `src/Module/ModuleRegistry.php` — één expliciete, gesloten lijst |
| Configuratie | `src/Module/ModuleConfig.php` — dé volgorde: `MODULE_<KEY>_ENABLED` in `.env`, dan de opgeslagen voorkeur, dan aan |
| Opgeslagen voorkeur | `src/Module/ModuleSettings.php` + tabel `module_settings` — wat de installatiewizard schrijft (`SETUP.md`) |
| Guard | `src/Module/ModuleGuard.php` — het regeltje bovenaan een route of endpoint van een module |
| Modules | `src/Module/ShopModule.php`, `src/Module/PersonalizationModule.php`, `src/Module/BlogModule.php`, `src/Module/ArticlesModule.php`, `src/Module/PortfolioModule.php`, `src/Module/MultilingualModule.php`, `src/Module/PageThemesModule.php` |

Het register:

```php
private const MAP = [
    'shop' => ShopModule::class,
    'personalization' => PersonalizationModule::class,
    'blog' => BlogModule::class,
    'articles' => ArticlesModule::class,
    'portfolio' => PortfolioModule::class,
    'multilingual' => MultilingualModule::class,
    'page_themes' => PageThemesModule::class,
];
```

Expliciet en gesloten, om dezelfde reden als `BlockDefinitions`: geen mapscan,
geen reflectie, geen Composer-plug-ins, geen klassenaam uit een databaserij of
een request. Een modulesleutel kan alleen een sleutel van die lijst raken of
missen, en missen is missen.

## Aan- en uitzetten

Eén omgevingsvariabele per module, in dezelfde `.env` die de database en
`APP_URL` al configureert (`.env.example` beschrijft het volledig):

```dotenv
MODULE_SHOP_ENABLED=false
MODULE_PERSONALIZATION_ENABLED=false
MODULE_BLOG_ENABLED=true
MODULE_PORTFOLIO_ENABLED=true
MODULE_MULTILINGUAL_ENABLED=true
MODULE_PAGE_THEMES_ENABLED=false
```

**De standaard is die van de module zelf, en voor de Shop en Personalisatie is
dat AAN.** Een variabele die ontbreekt, leeg is, of in een onleesbare `.env` staat
betekent voor Shop en Personalisatie "aan". Alleen een expliciete uit-waarde
(`false`, `0`, `off`, `no`, `disabled`) zet zo'n module uit. Voor Van Veluw
Laserdesign staat er dus niets in `.env` en draait de webshop — een fout in dit
bestand kan hem nooit stilletjes offline halen.

De **Blog** is een uitzondering en zegt dat zelf, met
`ModuleDefinition::enabledByDefault()`. Hij staat uit tot iemand hem aanvraagt,
omdat elke site pagina's en beeld heeft maar lang niet elke site artikelen
schrijft, en een lege blog op een levende `/blog`-URL erger is dan geen blog
(`BLOG.md`). Dat is uitsluitend een uitspraak over een installatie die niets
heeft gezegd: een omgevingsvariabele en een opgeslagen voorkeur worden allebei
eerder gelezen.

**Artikelen** start om dezelfde reden uit (`ARTICLES.md`).

**Portfolio** start op een nieuwe installatie ook uit, om dezelfde reden: niet
elke site toont eerder werk. Anders dan de Blog bestond Portfolio al, als
altijd-aanwezig Core, en draait het op bestaande installaties zonder dat iemand
er ooit iets over zei. De migratie
`20260914170000_pin_the_portfolio_module_where_it_is_in_use` slaat daarom voor
elke bestaande installatie, en voor een verse installatie die al
portfolio-inhoud heeft, de voorkeur *aan* op. Hetzelfde patroon als de andere
`pin_*`-migraties: een nieuwe standaard geldt voor een nieuwe site, nooit met
terugwerkende kracht.

**Meertaligheid** start op een nieuwe installatie ook uit: een nieuwe site
publiceert zijn standaardtaal tot iemand om meer vraagt. Elke bestaande
installatie publiceerde Nederlands en Engels, dus
`20260921100000_pin_the_multilingual_module_where_it_is_in_use` slaat de
voorkeur *aan* op voor elke installatie van vóór de installatiemarker en voor
een verse installatie waarvan de wizard al klaar was. Anders dan bij de andere
modules is er een scherm dat hem na de installatie aan- en uitzet:
*Instellingen → Talen* (`MULTILINGUAL.md`).

**Paginathema's** start ook uit: de meeste sites hebben één vormgeving. Het
is een nieuwe module, dus er is geen bestaande installatie om vast te zetten.
Ook deze module heeft na de installatie een schakelaar: *Vormgeving*, kaart
*Onderdelen van de vormgeving* (`THEMING.md`, "Paginathema's").

Waarom de omgeving vóóraan staat: dit is deploy-configuratie, net als `DB_*`.
Het is één regel in het bestand dat de hosting toch al heeft, het werkt op
Vimexx-shared hosting, het heeft geen database, geen migratie en geen
buildstap nodig, en niemand die alleen in het CMS is ingelogd kan het
veranderen.

### En wat het CMS erover mag zeggen

Sinds de installatiewizard is er een tweede stap in de ketting, en één plek
waar die staat — `ModuleConfig`:

```text
1. MODULE_<KEY>_ENABLED in de omgeving, als hij gezet en niet leeg is
2. de voorkeur die in het CMS is opgeslagen (App\Module\ModuleSettings)
3. de eigen standaard van de module (ModuleDefinition::enabledByDefault())
```

Stap 2 bestaat omdat iemand die een nieuwe site via het CMS inricht niet bij
de `.env` van de server kan, en dat vragen precies het handwerk is dat de
wizard weghaalt (`SETUP.md`). Hij staat met opzet **achter** de variabele:
een hostingaccount dat zijn modules in `.env` vastzet houdt het laatste woord,
en de `php_cms`-testcontainer met `MODULE_SHOP_ENABLED=false` beslist
onveranderd. De wizard toont zo'n vinkje uitgeschakeld, met de naam van de
variabele erbij.

`module_settings` is een eigen key/value-tabel naast `site_settings` en
`theme_settings`, om dezelfde reden als die twee uit elkaar staan: dit is
deploy-configuratie, geen identiteit en geen vormgeving. Een ontbrekende rij
betekent "niets gekozen", dus een bestaande installatie merkt er niets van.

`ModuleSettings` is alleen opslag. Hij weet niet wat een webshop is, lost geen
afhankelijkheid op en beslist niet of een module draait — dat blijft
`ModuleRegistry`. Een sleutel die niet geregistreerd is wordt zowel bij het
schrijven als bij het lezen weggelaten.

**Afhankelijkheid.** Personalisatie hangt van de Shop af. Vraagt de
configuratie Personalisatie aan terwijl de Shop uit staat, dan gaat
Personalisatie óók uit, met één regel in het serverlog die zegt waarom. Er is
geen halve stand: liever automatisch uit dan een adminonderdeel dat producten
configureert die er niet zijn.

## Wat een module bijdraagt

Alle methodes hebben een lege standaard; een module implementeert alleen wat
hij gebruikt.

| Bijdrage | Methode | Wie leest het |
|---|---|---|
| Adminnavigatie | `adminNavigationItems()` | `App\Service\AdminNavigation` |
| Een menu in de zijbalk (de Shop) | `adminNavigationMenus()`, plus `menu` op een regel | `App\Service\AdminNavigation::sidebar()` (`ADMIN-UI.md`, "Menu's in de zijbalk") |
| Permissies | `permissionGroups()`, `permissionImplications()`, `superAdminGrantablePermissions()` (wat alleen een Super Admin mag toekennen) | `App\Service\AdminPermissions` |
| Applicatieroutes | `routes()` | `App\Service\RouteRegistry` |
| Gereserveerde slugs | `reservedSlugs()` | `App\Service\ReservedRoutes` |
| Vaste publieke paden | `publicPaths()` | `App\Module\ModuleRegistry::disabledModuleForRoutePath()` |
| Een eigen pagina in Pagina's (de winkelpagina, het Portfolio-overzicht), en of er gewone pagina's onder mogen | `systemPages()` | `App\Service\ModuleSystemPages` (hieronder, "Systeempagina's van modules") |
| Sitemap | `sitemapCollectors()` | `App\Service\Sitemap` |
| Zoeken op de website (producten, projecten, berichten) | `searchProviders()` | `App\Service\Search\SearchService` (`SEARCH.md`) |
| Content-blokken | `blockDefinitions()` | `App\Service\Blocks\BlockDefinitions` |
| Galerijbronnen | `itemGallerySources()` | `App\Service\ItemGallerySources` |
| Bestemmingen voor een knop (blogbericht, product, collectie, portfolioproject) | `linkTargets()` | `App\Service\Routing\LinkTargets` (hieronder, "Bestemmingen van een module") |
| Site-shell-assets | `shellStyles()`, `shellScripts()` | `App\Service\PageAssets` |
| Header | `headerPartials()` | `partials/header.php` |
| Dashboard | `dashboardPanels()`, `dashboardCards()` | `admin/index.php` |
| Mediagebruik | `mediaUsageProviders()` | `App\Service\Media\MediaUsageRegistry` |
| De afbeelding die een item (product, project, blogbericht) toont waar een blok het als gelinkte afbeelding laat zien | `linkedImages()` | `App\Service\Media\LinkedImages` (`CONTENT-BLOCKS.md`, "Detailsectie 2.0") |
| Iets anders dan een pagina dat contentblokken draagt (een product, een project) | `contentOwners()` | `App\Service\ContentOwners\ContentOwners` (`CONTENT-BLOCKS.md`, "Blokken op een product of project") |
| Afhankelijkheden | `dependencies()` | `App\Module\ModuleRegistry` |
| Eén zin over zichzelf | `description()` | `admin/setup.php` (de installatiewizard) |
| Standaard aan of uit | `enabledByDefault()` | `App\Module\ModuleConfig` (stap 3 van de ketting) |
| Andere talen dan de standaardtaal publiceren | `publishesTranslations()` | `App\Service\Language\SiteLanguages::active()`, via `ModuleRegistry::publishesTranslations()` |
| Hoe een gewone CMS-pagina eruitziet als die niet de vormgeving van de site volgt (een paginathema) | `pageAppearance()` | `App\Service\Theme\PageThemeCss`, via `ModuleRegistry::pageAppearance()` (`THEMING.md`, "Paginathema's") |
| Een eigen instelling op het tabblad Pagina van de pagina-editor, opgeslagen met de pagina | `pageSettingsSections()` (`App\Service\PageSettingsSection`) | `admin/page.php` en `api/admin/update-page.php` |
| Een aan/uit-schakelaar op het scherm Vormgeving | `switchableFromAppearance()` | `admin/theme.php`, `api/admin/update-appearance-module.php` |
| Waar de module een lettertype uit de Font Library gebruikt, zodat Core het niet laat verwijderen | `fontFamilyUsage()` | `App\Service\Theme\FontLibrary::usage()`, gevraagd aan elke geregistreerde module, aan of uit (`THEMING.md`, "Font Library") |

Drie van die lijsten komen ergens in het midden van een bestaande, bewust
geordende lijst terecht (de zijbalk, het permissieformulier, de routekiezer).
Die dragen daarom een `order`-getal, en Core sorteert zijn eigen regels en die
van de modules samen. Dat is het enige ordeningsmechanisme; niets hangt af van
de volgorde waarin modules geregistreerd staan.

Een galerijbron hoort bij **één bloktype** van haar module (`block`): de
collectie van de Shop bij de *Collectiegalerij* (`item_gallery`), de
portfolio-items bij *Projecten* (`project_cards`). Een blok biedt, bewaart en
rendert alleen zijn eigen bronnen (`ItemGallerySources::availableFor()`,
`belongsTo()`), dus de Shop-galerij kan nooit projecten tonen. Een bron draagt
ook een `order`: de laagste beschikbare bron van een blok is waarmee een nieuw
blok begint (`defaultSourceFor()`). Tot v0.1.15 had een bron een eigen
kiezerkaart (`picker`); die is weg met de presets (`PAGE-EDITOR.md`).

**Een module bezit een header-slot, niet de header.** `headerPartials()` voegt
iets toe aan de actiezone rechts — vandaag alleen de mini-winkelwagen. De
schil eromheen blijft van Core, en dat geldt ook voor wat daar instelbaar is:
de knoppen in de header, de slotregel in de footer en de social profielen zijn
van Core (`nav_items`, `site_settings`, `footer_social_links`;
`HEADER-FOOTER.md`), geen bijdrage van een module. Een CMS-only deployment krijgt dus dezelfde knop en dezelfde
slotregel, alleen zonder mini-winkelwagen.

Andersom mag die knop wél naar een module wíjzen, en dat gaat via de gewone
linkresolutie zonder dat Core iets van de module hoeft te weten: staat de
module uit, dan bestaat zijn route niet meer in `RouteRegistry` en laat
`LinkResolver` de link vallen in plaats van naar een 404 te wijzen. Een
CMS-pagina die door een uitgeschakelde module wordt geserveerd (`/shop.php`)
telt daarbij hetzelfde. De opgeslagen instelling blijft staan — uitzetten is
ook hier geen deïnstallatie.

Een **redirect** naar zo'n route werkt op dezelfde manier: staat de module uit,
dan wordt de redirect niet uitgevoerd (de bezoeker krijgt de 404 die hij toch
al kreeg, in plaats van een andere), blijft de rij ongewijzigd staan, en werkt
hij weer zodra de module aan gaat. Zie `REDIRECTS.md`.

## Systeempagina's van modules

Een module kan een eigen pagina hebben: de Shop zijn winkelpagina
(`content_key = shop`, `/shop.php`), Portfolio zijn overzicht (`portfolio`,
`/portfolio`). De module noemt haar in `ModuleDefinition::systemPages()`
(content key → `route_path`); Core kent geen module bij naam en vraagt het
aan élke geregistreerde module, aan of uit (`App\Service\ModuleSystemPages`).
Sinds Shop Product & Ordering 2.0 (migratie `20260928150000`).

- **Altijd in Pagina's.** Elke installatie heeft die pagina's, ook met de
  module uit: de lijst zegt *Systeempagina Shop* en, zolang de module uit
  staat, *Module staat uit*; de editor zegt hetzelfde in een melding. Met de
  module uit antwoordt het adres 404 (`ModuleGuard`) en linkt niets ernaar
  (`PageContent::isServedByAnEnabledModule()`). Gaat de module weer aan, dan is
  het dezelfde rij: aan- en uitzetten maakt niets aan en haalt niets weg.
- **Het woord is altijd gereserveerd** (`reservedSlugs()`, `ReservedRoutes`),
  aan of uit, in elke taal: geen gewone pagina kan `shop` of `portfolio`
  claimen.
- **Niet te verwijderen.** `PageService::delete()` weigert met "Dit is de
  systeempagina van Shop en kan niet worden verwijderd"; Pagina's en de editor
  tonen geen *Verwijderen*. Wie haar niet op de website wil, zet haar op
  concept (het adres antwoordt dan 404, ook waar `/shop.php` anders het
  automatische overzicht zou tonen) of zet de module uit.
- **Een lege pagina verandert niets op de website.** De migratie maakt een
  ontbrekende pagina als gepubliceerde systeempagina (`is_system = 1`) zonder
  blokken, met de naam van de module als titel in de standaardtaal en
  `pages.module_default = 1`. Zolang zo'n pagina gepubliceerd is en geen
  zichtbaar blok heeft, is ze een *plaatshouder*
  (`ModuleSystemPages::isPlaceholder()`): `/portfolio` toont het eigen
  overzicht van de module en `/shop.php` doet wat Productoverzicht zegt,
  precies zoals voordat de pagina bestond; de sitemap, de linkkiezers van
  menu en footer en het kruimelpad noemen de pagina zelf niet. Een verborgen
  blok telt niet mee. Zodra een redacteur er een zichtbaar blok op zet, ís het
  de pagina, met haar eigen blokken, titel en SEO. Een pagina die de
  installatie al had (`module_default = 0`) is nooit een plaatshouder.
- **De migratie hergebruikt, dupliceert niet en overschrijft niets.** Een
  pagina met die content key blijft precies zoals ze was: status, adres,
  woorden, SEO en blokken. Houdt een andere pagina het woord al vast (in
  `pages.slug` of in een vertaling), dan maakt de migratie niets, hernoemt ze
  niets en meldt ze het in haar uitvoer; Pagina's toont zolang een melding met
  een link naar die pagina (`ModuleSystemPages::conflicts()`). Zonder eigen
  pagina werkt de module zoals ervoor (Portfolio toont zijn eigen overzicht,
  de Shop volgt Productoverzicht); alleen de regel in Pagina's ontbreekt. Een
  tweede run maakt niets.
- **Pagina's eronder** (Pages & Destinations 3.0,
  `docs/pages/NESTING.md` §12). Noemt de module bij haar pagina een
  `child_prefix` (een van haar eigen `reservedSlugs()`), dan kunnen gewone
  CMS-pagina's onder de systeempagina staan: `/shop/zakelijk`,
  `/portfolio/wolven`. De systeempagina houdt haar vaste URL. Wat de module
  zelf één niveau onder dat voorvoegsel bedient, meldt ze met
  `child_conflicts` (een callable: slugs erin, de botsende slugs met de naam
  van wat ze vasthoudt eruit); Core vraagt dat vóór het opslaan van een pagina
  daar, en de module vraagt Core andersom
  (`ModuleSystemPages::childPageHolding()`) vóór ze een eigen object een slug
  geeft. Portfolio noemt beide (de projecten op `/portfolio/<slug>`), de Shop
  alleen het voorvoegsel: onder `/shop/` bedient ze niets. **Met de module uit
  is de hele subboom van de website** — 404, niet in de sitemap, geen link
  ernaartoe — en blijven alle rijen staan (`ModuleSystemPages::inDisabledModuleSubtree()`).

## Bestemmingen van een module

Een knop in een blok kiest zijn bestemming in de bestemmingskiezer
(`admin/_link_target_field.php`, `CONTENT-BLOCKS.md`, "Waar een knop heen
gaat"). Core levert *Geen*, *Een pagina* en *Een ander adres*; elke andere soort
komt van een module via `linkTargets()`, bij id bewaard en bij elke weergave
opnieuw opgezocht (`App\Service\Routing\LinkTargets`, de docblock daar heeft
de volledige vorm):

| Soort | Module | Adres |
|---|---|---|
| `blog_post` | Blog | het bericht |
| `article` | Artikelen | het artikel, in de gelezen taal (anders de standaardtaal) |
| `product` | Shop | `/product.php?id=` |
| `collection` | Shop | `/collecties/<slug>` |
| `portfolio_project` | Portfolio | `/portfolio/<slug>` |

- Een soort draagt een label per CMS-taal, een `order` naast Core's `page`
  (10), zijn keuzes (met een `note` voor wat een bezoeker nu niet kan openen,
  en voor de doorzoekbare lijst een miniatuur), de href in de gelezen taal
  (`null` = geen link) en optioneel de zichtbare titel per taal.
- **Alleen wat een eigen publiek adres heeft, is een bestemming.** Een
  portfoliocategorie heeft er geen en staat er dus niet in.
- **Module uit**: de soort verdwijnt uit de kiezer voor een nieuwe keuze. Een
  opgeslagen keuze van die soort blijft bewaard, staat in de editor met een
  melding, en rendert geen link tot de module weer aan staat
  (`LinkTargets::disabledModuleOf()`).

## De Core→Shop-koppelpunten van vóór stap 5

Alle elf uit de vorige versie van dit document zijn weg. Wat er per punt voor
in de plaats kwam:

| Koppelpunt (was) | Nu |
|---|---|
| Adminnavigatie — zes vaste Shop-items in `AdminNavigation` | `ShopModule::adminNavigationItems()` + `PersonalizationModule::adminNavigationItems()` |
| Permissies — `products.*`, `orders.*`, ... als constanten in `AdminPermissions` | Constanten op `ShopModule` / `PersonalizationModule`, groepen via `permissionGroups()`. De *namen* zijn onveranderd |
| Sitemap — vijf vaste collectors, twee met Shop-repositories | Core levert alleen `pages`; de rest komt uit `sitemapCollectors()`, sinds Portfolio een module is ook `portfolio` |
| Routeregister — `shop`, `cart`, `checkout` vast in `RouteRegistry` | `ShopModule::routes()` |
| Gereserveerde slugs | `ShopModule::reservedSlugs()` / `PersonalizationModule::reservedSlugs()` |
| Header-winkelwagen — 40 regels markup in `partials/header.php` | `partials/header-cart.php`, via `ShopModule::headerPartials()` |
| Dashboard — ordercijfers, aandachtslijst en orderlijst ín `admin/index.php` | `admin/_dashboard_shop.php`, via `ShopModule::dashboardPanels()` |
| Shop-blokken — vast in `BlockDefinitions` | `ShopModule::blockDefinitions()` |
| Galerijbron — `SOURCE_COLLECTION` in `ItemGalleryContent` | `ShopModule::itemGallerySources()`, gelezen door `ItemGallerySources` |
| Frontend-assets — `cart.css`/`cart.js` in `PageAssets::SHELL_*` | `ShopModule::shellStyles()`/`shellScripts()` |
| Analytics in `partials/header.php` | Ongewijzigd: analytics is Core, geen module |

`Tests\Module\ShopDisabledTest` bewaakt dat: het faalt zodra een van die
Core-bestanden weer een concrete Shop-klasse of een Shop-assetpad noemt.

**Eén koppelpunt blijft met opzet staan.** `ReservedRoutes` leest de
gereserveerde slugs van **élke** geregistreerde module, ook een uitgeschakelde.
Dat geldt sinds de Redirect Manager ook voor een vanaf-pad: `/collecties/...`
is geen plek om een redirect op te hangen terwijl de Shop uit staat.
`shop.php`, `cart.php` en `product.php` staan nog gewoon op de schijf als de
Shop uit staat, en een CMS-pagina die zo'n slug zou claimen is voorgoed
onbereikbaar achter het bestand dat hem afschermt. Een naam reserveren kost
niets; een slug uitdelen die nooit kan werken kost de eigenaar een pagina.

## Guards: een menu verbergen is geen beveiliging

De bestanden van een module blijven bestaan als hij uit staat, dus `/shop.php`
en `/api/checkout.php` zijn nog steeds fysiek bereikbaar. `App\Module\ModuleGuard`
is het regeltje bovenaan zo'n bestand, met drie antwoorden die aansluiten op
wat het project al deed:

| Soort | Antwoord |
|---|---|
| Publieke route | 404 met de eigen "Pagina niet gevonden"-pagina (`partials/route-not-found-page.php`) — niet te onderscheiden van een pagina die er nooit was |
| Adminscherm | De eigen 403 "Geen toegang"-pagina |
| Endpoint | 404 in platte tekst, vóór er iets gelezen, gevalideerd of geschreven is |

**De meeste adminbestanden hebben helemaal geen guard nodig.** Een module
bezit zijn permissies, en een permissie van een uitgeschakelde module wordt
door *niemand* gehouden — ook niet door een Super Admin
(`AdminPermissions::userHas()`). Elk Shop-adminscherm en alle 174
schrijfendpoints vragen al `AdminAuth::requirePermission()`, dus die weigeren
vanzelf. Alleen de publieke routes en de publieke API's hebben een expliciete
regel gekregen.

Wat er níét gebeurt: opgeslagen rechten worden nooit herschreven. De
permissie*naam* blijft geldig (`AdminPermissions::all()`), alleen niet
*houdbaar* (`::enabled()`). Een `products.manage` op een collega's account
overleeft de Shop uitzetten en komt onveranderd terug.

## Blokken van een uitgeschakelde module

Een `page_sections`-rij die naar `product_grid` wijst (of naar
`shop_collections`, `featured_product` of `item_gallery`, de Collectiegalerij)
blijft staan als de Shop uit gaat. Het bloktype is dan niet geregistreerd, dus:

- **publiek**: de sectie wordt overgeslagen, de rest van de pagina rendert
  normaal — hetzelfde vangnet als voor een onbekend bloktype
  (`CONTENT-BLOCKS.md`);
- **serverlog**: één regel per rij per request, met de reden erbij ("module
  disabled" of "unknown");
- **page builder**: de rij staat er als *"Blok van een uitgeschakeld
  onderdeel"* met de modulenaam en de mededeling dat de gegevens bewaard
  blijven — bewust andere woorden dan het "Niet-ondersteund contentblok" dat
  echt verweesde data krijgt;
- **schrijfkant**: onveranderd hard geweigerd.

Hetzelfde geldt voor een galerijbron van een uitgeschakelde module — `collection`
van de Shop, `portfolio` van Portfolio: die is dan niet *kiesbaar* maar nog wel
*bekend*, dus een bestaand blok houdt zijn instelling, toont niets, en de
editor zegt waarom in plaats van de bron stilletjes te veranderen. Biedt geen
enkele ingeschakelde module een bron, dan staat het galerijblok niet in de
blokkenkiezer.

## De database blijft met rust

Uitzetten is geen deïnstallatie:

```text
code aanwezig
tabellen aanwezig
module draagt niets bij
```

Er worden geen producten, bestellingen, betalingen, verzendgegevens of
personalisaties verwijderd, en er zijn geen uninstall-migraties. Dat is
precies wat aan- en uitzetten veilig maakt.

## Huidige grenzen per domein

De grenzen zijn nog steeds grotendeels conceptueel: op `src/Module/` na staat
alles in `src/Service/` en `src/Repository/`, gescheiden door naamgeving en
verantwoordelijkheid. Alleen Personalisatie, Analytics, Verzending, Adressen
en Mail hebben al een eigen namespace-map.

### CMS Core

Alles wat er ook zou zijn zonder webshop.

- **Infrastructuur** — `Database`, `AppUrl`, `AssetVersion`, `Csrf`,
  `Mailer`, `SiteSettings`.
- **Auth/rechten** — `AdminAuth`, `AdminPermissions` (zes Core-permissies),
  `AdminUserService`, `AdminUserRepository`.
- **Adminschil** — `admin/_header.php`, `AdminNavigation`, `admin/index.php`.
- **Pagina's** — `PageContent`, `PageService`, `PageRepository`,
  `ReservedRoutes`, `pagina.php`, `admin/pages.php`.
- **Content-blokmechanisme** — `SectionRegistry`, `BlockDefinitions`,
  `PageSectionRepository`, `admin/page.php`. Het mechanisme is Core;
  individuele bloktypes horen bij het domein waarvan ze de inhoud tonen.
- **Mediabibliotheek** — `Service\Media\*`, `MediaRepository`, `admin/media.php`
  en de herbruikbare kiezer `admin/_media_picker.php`. Core, en het blijft
  Core: een CMS zonder afbeeldingen bestaat niet. Zij weet van media en van
  niets anders — welke *feature* een item gebruikt, vraagt zij aan de
  eigenaar van die feature via `mediaUsageProviders()`, precies zodat Core
  nooit een product of een collectie bij naam hoeft te noemen. Zie
  `MEDIA.md`.
- **Media/uploads die nog bij hun feature horen** — `SectionImageUploader`,
  `SectionVideoUploader`, `ImageOptimizer`. (`PortfolioImageProcessor` hoort
  bij Portfolio en ruimt alleen nog de eigen bestanden van items van vóór de
  Mediabibliotheek op: een nieuw Portfolio-beeld komt uit de bibliotheek.)
- **Instellingen/navigatie** — `NavigationService`, `FooterService`,
  `LinkResolver`, `RouteRegistry`.
- **Vormgeving** — `Service\Theme\*` (kleurenpaletten met één actief,
  lettertypecombinatie, Font Library, knopstijlen), `Branding`, `admin/theme.php`,
  `admin/color-palette.php`, `admin/button-style.php`. Een module met een
  blokknop noemt zijn kolom in `buttonStyleSlots()`. Core,
  nadrukkelijk geen module: een site zonder vormgeving bestaat niet. Een
  module mág de semantische tokens gebruiken — de Shop doet dat — maar houdt
  zijn eigen presentatie: er komt geen Shop- of personalisatie-instelling in
  de vormgeving te staan. Zie `THEMING.md`.
- **SEO/sitemap-mechanisme** — `SeoMetadata`, `SeoDefaults`, `PageSeo`,
  `Seo`, `AppUrl`, `AppEnvironment`, `Sitemap`, `Robots`,
  `partials/seo-head.php`. Core, en het blijft Core: de gedeelde renderer
  weet niet wat een product is. Een module levert sitemapregels via
  `sitemapCollectors()` en lost zijn eigen metadata op in zijn eigen read
  model (`ProductSeo`, `CollectionContent`) — er is geen apart
  SEO-uitbreidingspunt en dat is de bedoeling. Zie `SEO.md`.

### Shop (module `shop`)

- Producten, varianten, opties, productafbeeldingen —
  `ProductRepository`, `ProductVariantRepository`, `ProductOptionRepository`,
  `ProductImageRepository`, `ProductVariantImageRepository`, `ProductGallery`,
  `ProductVariantEditor`, `admin/products.php`, `admin/product-form.php`,
  `admin/_product_gallery.php`, `admin/_product_variants.php`.
  **Eén afbeeldingenpool per product.** Een afbeelding is van het product
  (`product_images`, met `media_id` naar de Mediabibliotheek); een variant
  kiest daaruit een deelverzameling in een eigen volgorde
  (`product_variant_images`, een koppeling, geen kopie). Een variant die niets
  kiest toont alle algemene afbeeldingen van het product, dus een variant
  toevoegen verbergt nooit een productfoto. Een variant verwijderen haalt
  alleen zijn koppelingen weg; een bibliotheekbestand verwijdert de Shop
  nooit (`ShopMediaUsage`, `MEDIA.md`). De oude `variant_images`, met eigen
  bestanden per variant, is door `20260923120000` omgezet naar dit model en
  wordt niet meer gelezen.

  **Algemeen of alleen voor varianten** (v0.1.15 fase 12.1,
  `product_images.variant_only`, migratie `20261016100000`). Elke afbeelding
  in de pool is één van twee soorten:

  - **Algemeen** (`variant_only = 0`, de standaard en wat elke bestaande rij
    na de migratie is): staat in *Productafbeeldingen* en in de algemene
    productgalerij, en mag daarnaast aan één of meer varianten gekoppeld
    zijn. De eerste algemene afbeelding is de hoofdfoto.
  - **Alleen voor varianten** (`variant_only = 1`): blijft van het product
    (zelfde rij, zelfde `media_id`), maar staat niet in de algemene galerij
    en niet in *Productafbeeldingen*. Alleen een variant die eraan koppelt
    toont hem. Hij is nooit hoofdfoto (`is_primary` blijft 0, ook als hij
    de enige afbeelding is; dan heeft het product geen hoofdfoto), en nooit
    het deel-, kaart- of structured-data-beeld van het product.

  Het onderscheid wordt **nooit afgeleid uit de koppelingen**: de editor
  stuurt twee lijsten (`gallery[]` en `gallery_variant_only[]` met de
  marker `gallery_variant_only_submitted`) en `ProductGallery::save()` neemt
  die over. Een afbeelding wisselt van soort door in de andere lijst te
  staan; rij, bibliotheekitem en koppelingen blijven. Algemeen gemaakt komt
  hij achteraan in de algemene volgorde. Staat hij in beide lijsten, dan is
  hij algemeen. Een verzoek zonder de tweede lijst laat de variant-only
  afbeeldingen zoals ze zijn. Haal je de laatste koppeling van een
  variant-only afbeelding weg, dan blijft hij in de pool staan, ongekoppeld,
  tot hij bewust wordt weggehaald (×); dan gaat alleen de rij, nooit het
  bibliotheekbestand.

  **Wie leest wat.** `ProductImageRepository::findByProductId()`,
  `findPrimary()` en `primaryForProducts()` geven alleen algemene
  afbeeldingen; alleen wat de pool beheert (de editor, `ProductGallery`,
  `ProductDeletionService`) vraagt `findPoolByProductId()`. Variantkoppelingen
  (`ProductVariantImageRepository::findByVariantIds()`) geven beide soorten,
  met `variant_only` erbij. Waar een standaardvariant het product
  vertegenwoordigt (de winkelkaart in `api/products.php`, de thumbnail van
  `ProductAdminOverview` en `admin/collection.php`, `ProductSeo::imagePaths()`,
  de aandachtslijst van het dashboard) telt alleen zijn eerste **algemene**
  koppeling (`ProductVariantImageRepository::generalOnly()`). Koppelt de
  standaardvariant alleen aan variant-only afbeeldingen, dan vallen SEO en
  kaart terug op de algemene afbeeldingen van het product; koppelt hij aan
  niets, dan blijft alles zoals het was.

  **Op de productpagina en in Uitgelicht product** (beide `ProductDetail`,
  `shop.js`): `images` zijn de algemene afbeeldingen; een variant toont
  precies zijn koppelingen (algemeen en variant-only, elk één keer, in zijn
  eigen volgorde) en zonder koppelingen de algemene afbeeldingen. Een
  variantkeuze terug naar zo'n variant laat de variant-only afbeeldingen dus
  weer verdwijnen. Let op: de pagina selecteert bij het laden de
  standaardvariant (de eerste actieve), zoals altijd. Koppelt díe aan een
  variant-only afbeelding, dan staat die bij het laden in beeld: dat is een
  bewuste variantcontext, geen lek. Er is geen toestand "geen variant
  gekozen".
- **Het productoverzicht** (`admin/products.php`, Shop Admin UX & Order
  Fields 2.0) is *Raster* of *Lijst*: één kaartmarkup die CSS twee keer
  tekent (`data-product-view`), met een schakelaar van twee knoppen
  (`aria-pressed`) die `admin/assets/product-overview.js` in deze browser
  onthoudt (`localStorage`, geen instelling, geen migratie), zoals de
  Mediabibliotheek. Zonder JavaScript blijft het raster. Beide tonen
  thumbnail, naam, status, prijs (of *Op aanvraag*) en de voorraadregel; de
  lijst is een compacte rij met *Bewerken*, die onder 1000 px de details
  achter de naam laat doorlopen en onder 700 px een kaartrij wordt, zonder
  tabel en zonder zijwaarts scrollen. Thumbnail en voorraad komen uit
  `App\Service\ProductAdminOverview`: een vast aantal queries, hoeveel
  producten er ook zijn (de thumbnail volgt de winkelkaart: eerste foto van
  de standaardvariant, anders de hoofdfoto van het product, het kleine
  bibliotheekformaat waar dat er is).
- **De producteditor is één dynamische editor** (Shop Admin UX 2.0,
  `ADMIN-UI.md`, "Een editor die opslaat zonder te herladen"). Eén
  formulier en één *Opslaan*, zonder herladen:
  - het product zelf, zijn collecties en SEO;
  - de kaart *Productafbeeldingen*, met alleen de algemene afbeeldingen van
    het product (de eerste is de *Hoofdfoto*; met minstens één variant heeft
    elke kaart *Alleen voor varianten*) en de *Overgang productgalerij* (zie
    hieronder);
  - de kaart *Varianten*: de opties met hun waardes, de varianten met hun
    prijs, schakelaar, afbeeldingen uit de hele pool (een variant-only tegel
    heeft een stippelrand), *+ Afbeelding alleen voor deze variant* (een
    nieuw bibliotheekbeeld wordt variant-only en aangevinkt; een beeld dat
    het product al heeft wordt alleen aangevinkt) en eigen beschrijving, en
    onderaan *Afbeeldingen alleen voor varianten*: elke variant-only
    afbeelding met *Naar productgalerij*, × en, als geen variant hem
    aanvinkt, *Niet aan een variant gekoppeld*.

  Een optie, waarde of variant toevoegen, verplaatsen of weghalen verandert
  alleen het scherm. Rijen gaan op sleutel: een id, of `new<n>` voor een rij
  die net getypt is. Een nieuwe variant kiest zijn waardes ook op sleutel,
  dus hij kan bestaan uit een optie en een waarde van hetzelfde bezoek.

  `api/admin/update-product.php` controleert alles en schrijft alles in één
  transactie (`ProductVariantEditor`). De volgorde op het scherm is de
  volgorde die wordt opgeslagen. De server weigert een optie of waarde weg
  te halen die een blijvende variant gebruikt. Dat geldt ook voor een
  variant waar een bestelling naar wijst (zet hem op inactief), en voor een
  tweede variant met dezelfde combinatie. Een weggehaalde variant mag in
  dezelfde opslag zijn optie meenemen.

  De twaalf endpoints die dit per rij deden, met een herlaadbeurt per klik
  (`create-`, `update-`, `delete-` en `move-product-option`,
  `-option-value` en `-variant`), bestaan niet meer.

  Een nieuw product is de enige aparte stap. `create-product.php` maakt de
  rij, want opties en varianten hebben het id nodig, en opent daarna direct
  de editor van dat product (`?created=1`).

  **Drie tabbladen** (Shop Product & Ordering 2.0): *Product* (het product
  zelf, Voorraad, Afbeeldingen, Varianten, Specificaties, Bestelvelden en de
  Personalisatie-wegwijzer), *SEO* (titel, omschrijving en deelafbeelding
  samen) en *Verzending* (profiel, gewicht, altijd als pakket). Nog steeds
  één formulier en één *Opslaan*; een melding opent het tabblad van haar veld
  (`ADMIN-UI.md`, "Tabbladen in een editor die zonder herladen opslaat").
- **De productgalerij op de productpagina** (Product Gallery 2.0).
  - **De grote foto is altijd heel.** `object-fit: contain` in het vierkante
    vak, met wat binnenruimte zodat de afgeronde hoeken van het vak nooit een
    hoek van de foto afsnijden. Staat een foto rechtop of liggend, dan blijft
    de achtergrond van het vak eromheen zichtbaar. De thumbnails vullen hun
    kleine vierkant juist wél (`cover`).
  - **Eén controller**, `assets/js/shop/product-gallery.js`
    (`window.VVLProductGallery`), die `shop.js` de foto's geeft. Klik op een
    thumbnail, vegen, ← en → op een thumbnail en een andere variant lopen
    allemaal via één `show(target, direction, animate)`. Zo kunnen de grote
    foto, de actieve thumbnail (`aria-current`) en de index niet uit elkaar
    lopen. Voorbij het eind begint hij weer vooraan, net als de lightbox.
  - **Vegen is bediening, geen overgang.** Het werkt met touch en pen
    (Pointer Events): minstens 50 px en duidelijk meer opzij dan omhoog.
    Het vak heeft `touch-action: pan-y pinch-zoom` en er is geen
    `preventDefault()`, dus verticaal scrollen blijft gewoon werken. Vegen is
    nooit de enige manier: de thumbnails blijven knoppen.
  - **De thumbnails lopen door naar een volgende regel** (v0.1.12). Ze
    hebben één vaste maat (64 px) en de bestaande tussenruimte, en
    flex-wrap bepaalt hoeveel er op een regel passen. Er is geen horizontale
    scrollbalk, geen thumbnailcarrousel en geen vast aantal kolommen per
    breakpoint. Omdat niets de rij afsnijdt, zijn de hover-lift, de actieve
    ring en de focusrand altijd helemaal te zien. Het script hoeft de rij niet
    meer opzij te scrollen. Het blok Uitgelicht product gebruikt dezelfde rij
    en hetzelfde script, dus daar werkt het ook zo, zonder eigen regel
    (`ShopGalleryContractTest`).
  - **Drie overgangen, een gesloten lijst**
    (`App\Service\ProductGalleryTransition`): `none` (direct), `fade`
    (overvloeien) en `slide` (de nieuwe foto schuift in vanaf de kant waar
    hij vandaan komt; bij een thumbnail bepaalt het indexverschil de kant).
    Elke wissel wacht tot de volgende foto gedecodeerd is (hooguit 400 ms),
    zodat het vak nooit leeg flitst of van maat verandert.
    `prefers-reduced-motion` maakt elke wissel direct.
  - **Twee niveaus.** Shop-instellingen → Productpagina →
    *Standaard overgang productgalerij* (`site_settings.shop_gallery_transition`,
    standaard `fade`, omdat de galerij al vervaagde). Per product staat in de
    producteditor, kaart *Afbeeldingen*, *Overgang productgalerij*:
    *Standaard van Shop* (`products.gallery_transition` = NULL) of een eigen
    keuze. NULL is geen kopie, dus een product dat de Shop volgt verandert
    mee als de standaard verandert. Een nieuw product volgt de Shop.
    `ProductGalleryTransition::resolve()` is de enige plek die
    "product ?? Shop ?? fade" uitrekent. `product.php` zet het resultaat in
    `data-gallery-transition`, en het script controleert het nogmaals tegen
    dezelfde drie woorden. Een onbekende opgeslagen waarde valt terug en komt
    nooit zelf op de pagina.
- **In de zijbalk één menu *Shop*** (`ShopModule::adminNavigationMenus()`,
  `ADMIN-UI.md`, "Menu's in de zijbalk"). Het staat op de plek waar
  Producten stond en bevat alle Shop-schermen plus Personalisatie, in hun
  eigen volgorde. Staat de Shop uit, dan is er geen menu en geen regel.
- Variantbeschrijving — optioneel, per websitetaal, in
  `product_variant_translations` via `ShopLocalization`. Wat een bezoeker
  leest is `eigen tekst van de variant in deze taal ?? productbeschrijving in
  deze taal` (`ShopLocalization::variantDescription()`). Uit betekent: geen rij,
  dus nooit een kopie van de producttekst; een latere wijziging aan het product
  werkt door. De tekst van een variant valt niet terug op een andere taal:
  zonder eigen tekst in die taal toont hij de productbeschrijving van die taal.
- **Voorraad** (Shop Product & Ordering 2.0, `App\Service\Inventory\*`,
  migratie `20260928100000`). Per product optioneel: *Voorraad bijhouden*
  (`products.track_stock`, uit voor elk bestaand product). Uit is
  onbeperkt, precies zoals vóór deze functie.
  - **De verkoopbare eenheid.** Een product zonder varianten houdt zijn
    voorraad in `products.stock` (de kolom bestond al en werd nergens
    gelezen); een product mét varianten per variant in
    `product_variants.stock`, en dan telt de productvoorraad niet mee. De
    twee concurreren nooit: `ProductStock::unitFor()` is de ene resolver, en
    een regel voor een bijgehouden variantproduct zonder variant heeft geen
    eenheid en wordt geweigerd. Voorraad is een heel getal van 0 of meer.
  - **Reserveren gebeurt in de ordertransactie van `api/checkout.php`**,
    vóór de order bestaat: één voorwaardelijke `UPDATE … SET stock = stock -
    :q WHERE … stock >= :q` per eenheid (`InventoryRepository::take*()`),
    op een vaste volgorde. Van twee klanten voor het laatste stuk krijgt er
    precies één het; InnoDB's rijlock beslist, er is geen "eerst lezen, dan
    schrijven". Te weinig over: de hele order rolt terug (409, in de taal
    van de klant, met de productnaam). Wat een regel nam en van welke teller,
    staat op de regel (`order_items.stock_reserved`, `stock_source`).
  - **Teruggeven gebeurt precies één keer.** Bij `failed`, `canceled` en
    `expired` claimt `Inventory::releaseForOrder()` de marker
    `orders.stock_released_at` in één voorwaardelijke `UPDATE` en geeft in
    dezelfde transactie elke eenheid terug aan de teller waar ze vandaan
    kwam. `OrderPaymentSync` roept dat na elke statusschrijf aan; een
    herhaalde webhook of twee syncs tegelijk vinden de marker al gezet.
    `paid` maakt de reservering definitief. Een order die niets reserveerde
    krijgt nooit een marker.
  - **Een betaling die niet start**, na de commit (Mollie weigert, time-out,
    het betaal-id kan niet worden opgeslagen): `OrderPaymentStartFailure`
    zet de order op `failed` (alleen `pending` zonder betaal-id) en geeft de
    voorraad meteen terug. Vroeger bleef zo'n order voorgoed op *in
    afwachting* staan.
  - **Controle vóór het afrekenen.** De winkelwagen staat in de browser, dus
    `api/cart-check.php` (`App\Service\CartAvailability`) zegt per regel
    `ok`, `sold_out`, `insufficient` (met hoeveel er nog zijn),
    `unavailable`, `inquiry` of `order_fields`. De productpagina vraagt het
    bij *Toevoegen aan winkelwagen* (met wat al in de wagen zit), de
    winkelwagen bij elke weergave en elke aantalwijziging (*Afrekenen*
    wacht zolang een regel niet kan), het afrekenscherm bij het openen.
    Beslissend blijft de checkout zelf.
  - **Op de productpagina**: de gekozen eenheid bepaalt. Uitverkocht toont
    *Uitverkocht* / *Out of stock* in plaats van aantal en knop; een andere
    variant op voorraad blijft bestelbaar; het aantal gaat niet hoger dan
    wat er is. `api/product.php` geeft `sold_out` en `max_quantity`, nooit
    het getal zelf; de publieke productrijen selecteren `stock` niet meer.
    De Product JSON-LD zegt `OutOfStock` als elke kiesbare eenheid
    uitverkocht is.
  - **In de producteditor**: de sectie *Voorraad* (switch en, zonder
    varianten, het aantal) en een voorraadveld per variant, met de status
    ernaast (*Voorraad niet bijgehouden*, *12 op voorraad*, *Uitverkocht*).
    **Een beheerder maakt nooit een verkoop ongedaan**: een ongewijzigde
    waarde wordt niet geschreven, een gewijzigde alleen over de waarde die
    het scherm toonde (`stock_seen`); veranderde de voorraad intussen, dan
    weigert de hele opslag met de huidige stand (`StockConflictException`).
  - **In het productoverzicht** (Shop → Producten, Shop Admin UX & Order
    Fields 2.0) staat per product één voorraadregel als badge
    (`App\Service\Inventory\StockSummary`, over dezelfde `ProductStock`):
    *Onbeperkt*, *12 op voorraad* of *Uitverkocht*; met varianten telt hij
    alleen de actieve: *4 varianten · 23 op voorraad*, *4 varianten · 1
    uitverkocht* of *Uitverkocht · 4 varianten* (optellen klopt, want elke
    variant is een eigen eenheid). Een product op aanvraag toont geen getal
    maar *Niet direct te bestellen*. De tint is een bestaande badge-tint:
    uitverkocht is fout, deels uitverkocht een waarschuwing.
  - Geen magazijn, geen inkoop, geen waarschuwing bij weinig voorraad.
    Zonder publiek bereikbare webhook (lokaal) komt voorraad van een
    verlopen betaling pas terug als iemand de bestelstatuspagina opent.
- **Terug op voorraad** (`App\Service\Inventory\StockNotifications`,
  `stock_notifications`, migratie `20260928110000`). Op een uitverkochte
  bijgehouden eenheid vraagt de productpagina *Mail mij als dit weer
  beschikbaar is*. `api/stock-notification.php` bewaart product, variant,
  adres (kleine letters), taal, status en tijden; één actieve aanvraag per
  eenheid en adres dwingt de database af (unieke sleutel met
  `active_marker`, een verstuurde aanvraag botst nooit). Geen account,
  geen nieuwsbrief, geen lijst die iemand kan zien. Een bekend adres krijgt
  hetzelfde antwoord als een nieuw; een honeypot en `ContactRateLimiter`
  (20 per tien minuten per bezoeker) houden scripts buiten.
  - **Wanneer er gemaild wordt**: zodra de eenheid weer te bestellen is —
    voorraad van 0 naar meer, na een opslag in de editor
    (`dispatchForProduct()`, ook als bijhouden uit gaat of het product weer
    actief wordt) of doordat een mislukte, geannuleerde of verlopen betaling
    voorraad teruggeeft (`OrderPaymentSync`, `OrderPaymentStartFailure`).
    Van 5 naar 6 wacht er niemand. Elke mail wordt eerst geclaimd, dus twee
    afzenders tegelijk schrijven hem niet dubbel; een verstuurde aanvraag
    wordt nooit opnieuw geschreven. Hooguit 25 mails per eenheid per keer.
  - **Een mislukte mail blijft actief** met zijn poging geteld en wordt
    opnieuw geprobeerd door de volgende afzender: de volgende opslag van dat
    product, of *Wachtende meldingen nu versturen* onder Shop-instellingen →
    E-mails. Geen wachtrij en geen cron.
  - **De mail** (`App\Mail\StockNotificationBuilder`) is in de taal waarin de
    bezoeker het vroeg. Onderwerp en tekst stelt de eigenaar in onder
    Shop-instellingen → E-mails → *Terug op voorraad*, per websitetaal
    (`App\Service\ShopLocalizedSettings`, een Shop-catalogus in
    `site_setting_translations`); leeg is de standaardtekst in die taal.
    Platte tekst, geëscaped, met `{{product_name}}`, `{{variant}}`,
    `{{product_url}}` en `{{site_name}}` (`EmailPlaceholders::STOCK`); de
    knop naar het product staat er altijd onder.
  - **Een aanvraag is een persoonsgegeven.** Het adres blijft na verzending
    in `stock_notifications` staan (status `sent`, met `notified_at`), en er
    is geen automatische opschoning; een product verwijderen haalt zijn
    aanvragen mee (de sleutel cascadeert). Het CMS toont geen adressen,
    alleen aantallen.
- **Op aanvraag** (`App\Service\PurchaseMode`, `products.purchase_mode`,
  migratie `20260928120000`): per product *Direct bestellen* (standaard,
  elk bestaand product) of *Op aanvraag*. Zo'n product houdt naam,
  afbeeldingen, beschrijving, variantkiezer en specificaties, maar heeft
  nergens een prijs: niet op de productpagina, niet op een productkaart
  (grid, collectie, gerelateerde producten, Personalisatie; de kaart zegt
  *Op aanvraag*), niet in `api/product.php` en `api/products.php` (ook niet
  per variant), en de JSON-LD heeft geen `Offer`. In plaats van aantal en
  winkelwagen staat er een blok met een contactknop; bestelvelden en de
  personalisatie-configurator worden niet getoond. De server weigert het
  product in `api/cart-check.php` (`inquiry`) en `api/checkout.php`, en er
  kan geen terug-op-voorraadmelding voor worden aangevraagd. De prijs
  blijft bewaard voor als het product terug gaat naar Direct bestellen.
- **Bestelvelden** (`App\Service\OrderFields\*`, migratie `20260928130000`).
  Per product *Bestelgegevens vragen* (`products.order_fields_enabled`, uit
  voor elk bestaand product) met vragen die de klant beantwoordt vóór het
  product in de winkelwagen gaat, zoals "Naam op het bord". Los van
  Personalisatie: dat plaatst tekst en beeld op een voorbeeldfoto en
  verdwijnt met die module; bestelvelden zijn gewone antwoorden van de
  Shop.
  - **Zes soorten** (`OrderFieldType`): kort tekstveld, lang tekstveld,
    keuzerondjes, dropdown, selectievakje en *Afbeelding uploaden* (zie
    hieronder). Per vraag een label en optionele uitleg (per websitetaal,
    `product_order_field_translations` via `ShopLocalization`), verplicht of
    niet, een maximale lengte voor tekst (standaard 100 en 1000, hooguit 255
    en 2000), keuzes voor keuzerondjes en dropdown
    (`product_order_field_options`, met hun label per taal) en een maximale
    bestandsgrootte voor een afbeelding. Geen datum of voorwaarden.
  - **In de producteditor** de sectie *Bestelvelden*: vragen en keuzes zijn
    rijen op sleutel (id of `new<n>`), toevoegen, verplaatsen en verwijderen
    zonder herladen, in de ene opslag (`ProductOrderFieldEditor`). Een vraag
    toont alleen wat zijn soort gebruikt. Wat bij een andere soort hoort
    wordt bij opslaan niet bewaard: een lengte alleen bij tekst, keuzes
    alleen bij keuzerondjes en dropdown, een bestandsgrootte alleen bij een
    afbeelding. Een vraag van soort wisselen laat dus niets achter.
  - **Op de productpagina** is elke vraag een gewoon formulierveld van de
    site (`core.css` `.form-field`, `.checkbox-field`, `.hint`, `.req`):
    dezelfde rand, achtergrond, focusring en foutkleur als het
    contactformulier en het afrekenen. `shop.css` past ze alleen in de
    koopkolom (keuzes als rij van 44 px, lange labels breken af). Een
    onbeantwoorde vraag krijgt `aria-invalid` en een melding onder het veld
    (`aria-describedby`), die verdwijnt zodra de klant antwoordt.
  - **Controle**: de browser zegt wat ontbreekt; de server
    (`OrderFields::validate()`) neemt alleen de eigen vragen van het
    product, eist verplichte antwoorden, knipt stuurtekens weg, bewaakt de
    lengte en accepteert alleen een keuze van déze vraag. Een melding noemt
    de vraag, in de taal van de klant (422).
  - **Winkelwagenidentiteit**: andere antwoorden zijn een andere regel, in de
    browser (`sameOrderFields()`, een eigen `line_id`) en op de server
    (`OrderFields::fingerprint()` in de regelsleutel): "Luna" en "Kyra"
    blijven twee regels, twee keer "Luna" telt op. Aanpassen gaat in V1 door
    de regel te verwijderen en opnieuw toe te voegen. De winkelwagen, de
    mini-winkelwagen en het afrekenoverzicht tonen de antwoorden.
  - **Snapshot**: bij het afrekenen legt `order_item_fields` per antwoord het
    label, het type en de waarde vast in de standaardtaal (een keuze als haar
    label, een vinkje als Ja/Nee), in de ordertransactie. Een vraag die later
    verandert of verdwijnt, verandert geen bestelling. Het besteloverzicht en
    beide bevestigingsmails tonen ze onder de regel; de factuur niet (zoals
    personalisatie er ook niet op staat).
  - **Afbeelding uploaden** (Shop Admin UX & Order Fields 2.0, migratie
    `20260929100000`, `OrderFieldUploads` en `OrderFieldUpload*`). De klant
    stuurt **één** afbeelding per vraag mee (huisdier, logo, ontwerp); wie er
    twee nodig heeft, stelt twee vragen. Per vraag kiest de eigenaar 2, 5 of
    10 MB (`product_order_fields.max_file_size_mb`, leeg is 10 MB), maar
    nooit meer dan PHP aanneemt (`upload_max_filesize`, en `post_max_size`
    met ruimte voor de rest van het verzoek): `OrderFieldUploadPolicy::
    effectiveMaxBytes()` is wat gecontroleerd en op de pagina genoemd wordt.
    - **Alleen JPEG, PNG en WebP**: rasterbeelden die GD hier echt kan lezen.
      Geen SVG (kan script bevatten, en een klantfoto heeft het niet nodig),
      geen GIF, en geen HEIC/HEIF/AVIF zolang GD ze op deze server niet leest
      (een telefoon zet HEIC om naar JPEG als de browser de foto kiest).
    - **De server gelooft niets van de browser**
      (`OrderFieldUploadValidator`): één bestand, PHP's uploadstatus,
      `is_uploaded_file()`, niet leeg, niet boven de limiet, het MIME-type van
      de bytes (`finfo`) op de lijst, `getimagesize()` leest hetzelfde type
      uit de kop (anders is het een polyglot), hooguit 12000 px per zijde en
      40 megapixel (een klein bestand dat een reuzenbeeld claimt komt niet
      tot decoderen), en GD decodeert hem echt (`ImageOptimizer`, dat ook de
      kleine her-encodeerde thumbnail maakt). Extensie en browser-MIME tellen
      niet mee. Elke weigering is een zin voor de klant, in de taal van de
      pagina.
    - **Privé en buiten de webroot** (`OrderFieldUploadStorage`): standaard
      `storage/order-field-uploads/` één map boven de projectroot (lokaal het
      Docker-volume op `/var/www/storage`); `ORDER_FIELD_UPLOADS_PATH` (.env)
      verplaatst die basis, maar alleen naar een absoluut pad buiten het
      project. Twee bestanden per afbeelding, genoemd naar een willekeurige
      opslagnaam die niets met het token of de bestandsnaam van de klant te
      maken heeft: het origineel (onaangeroerd, met eventuele EXIF, alleen
      voor het CMS) en een her-encodeerde thumbnail. De database
      (`order_field_uploads`) bewaart alleen metadata: veilige originele
      bestandsnaam (weergave, zonder pad, stuur- of onzichtbare
      opmaaktekens), MIME-type, grootte, afmetingen. Nooit een blob, nooit
      base64, nooit in de Mediabibliotheek, een blokkiezer, de sitemap of
      `assets/`.
    - **Tijdelijk tot de bestelling.** De productpagina uploadt bij het
      kiezen (`api/order-field-upload.php`) en krijgt een **token** terug:
      256 willekeurige bits die de browser als antwoord op de vraag bij de
      winkelwagenregel bewaart, met de bestandsnaam voor de weergave. De
      database kent alleen de SHA-256 ervan. Geen afbeelding, pad of id in
      `localStorage`. De upload hoort bij het product en de vraag waarvoor
      hij gedaan is en verloopt na 72 uur (`TTL_HOURS`, net als een
      personalisatie-upload). De winkel heeft geen sessie, dus het token ís
      de sleutel: niet te raden, nooit in de database, nooit in een URL,
      alleen voor deze vraag van dit product, en maar voor één bestelling.
    - **Vervangen en verwijderen** gooien de oude tijdelijke upload meteen
      weg (`action=discard`). Alleen de laatst gekozen afbeelding telt: een
      eerdere die later binnenkomt wordt ook weggegooid. *Toevoegen aan
      winkelwagen* wacht tot de upload klaar is, dus een regel krijgt nooit
      een half verstuurde afbeelding.
    - **Winkelwagenidentiteit**: het token is het antwoord, dus twee
      afbeeldingen zijn twee regels, ook bij verder gelijke antwoorden. Een
      regel met aantal 3 heeft één afbeelding voor alle drie; drie
      verschillende afbeeldingen zijn drie regels. Na toevoegen is het veld
      weer leeg; de afbeelding hoort dan bij de regel.
    - **Winkelwagencheck en afrekenen** controleren het token elke keer
      opnieuw (`OrderFieldUploads::resolve()`): bestaat, past bij dit product
      en deze vraag, niet geclaimd, niet verlopen, bestand aanwezig. Een
      verplichte afbeelding zonder geldig token wordt geweigerd; een
      optionele mag leeg blijven.
    - **De claim zit in de ordertransactie** (`OrderFields::record()`): eerst
      de snapshotrij (label, type `image`, de bestandsnaam als waarde), dan
      één voorwaardelijke `UPDATE … WHERE claimed_at IS NULL AND expires_at >
      NOW()` die de upload aan die rij bindt. Een tweede bestelling met
      hetzelfde token, of hetzelfde token op twee regels, laat de claim
      mislukken en de hele bestelling terugrollen (409). Het bestand
      verhuist niet, dus er valt op schijf niets terug te draaien.
    - **Een betaling zonder geld geeft de afbeelding terug.** De winkelwagen
      blijft in de browser tot een bestelling betaald is. Start de betaling
      niet, of wordt ze `failed`, `canceled` of `expired`, dan maakt
      `OrderFieldUploadRepository::returnToCartForOrder()` de afbeeldingen weer
      tijdelijk, met een nieuwe levensduur, naast het teruggeven van de
      voorraad (`OrderPaymentStartFailure`, `OrderPaymentSync`). Opnieuw
      afrekenen met dezelfde winkelwagen bestelt ze dan alsnog. De
      mislukte bestelling houdt de bestandsnaam in haar antwoord.
    - **Opruimen**: een verlopen tijdelijke upload gaat met zijn bestanden weg
      bij ongeveer één op de twintig uploads, en via
      `php scripts/prune-order-field-uploads.php` (dagelijks als cronjob is
      ruim genoeg; `--dry-run` telt alleen). Dezelfde sweep haalt ook oude
      bestanden weg die geen rij meer hebben (een crash tussen rij en
      bestand). Een geclaimde afbeelding wordt nooit geveegd.
    - **Misbruik**: `ContactRateLimiter` met een eigen zout, 30 uploads per
      tien minuten per bezoeker (IPv6 per /64), en een plafond van 2 GB voor
      alle tijdelijke afbeeldingen samen (`MAX_TEMPORARY_BYTES`); daarboven
      zegt de server "probeer het later", na eerst te vegen. Geen
      CSRF-token, net als de andere publieke Shop-endpoints: de winkel is
      anoniem en sessieloos, en een vervalst verzoek kan hooguit namens de
      bezoeker zelf uploaden.
    - **In de bestelling** toont `admin/order.php` bij de vraag een
      thumbnail, de bestandsnaam, afmetingen en grootte, *Bekijken* en
      *Downloaden*, via `api/admin/order-field-upload.php`: ingelogd,
      `orders.view` (bij elk verzoek), alleen een geclaimde upload, op
      upload-id (nooit een bestandsnaam uit het verzoek), met het opgeslagen
      MIME-type, `nosniff`, `private, no-store`, een sandbox-CSP, en een
      downloadnaam met de extensie van het **gecontroleerde** type, nooit die
      van de klant.
    - **Mails en factuur**: beide mails noemen de afbeelding met haar
      bestandsnaam ("Foto huisdier: luna.jpg"), zonder bijlage (grootte,
      privacy, bezorgbaarheid) en zonder link; de klant krijgt nooit een
      CMS-adres. De factuur verandert niet.
    - **Bewaren** volgt de bestelling. Mygdala verwijdert geen bestellingen;
      de sleutel van de upload naar zijn antwoord is `RESTRICT`, dus wie ooit
      een bestelling verwijdert, moet eerst de uploadrij en de bestanden
      verwijderen, anders weigert de database (nooit een weesbestand). Een
      product verwijderen laat geclaimde afbeeldingen staan (`product_id`
      wordt NULL); tijdelijke verlopen dan gewoon.
- **Specificaties** (`App\Service\ProductSpecifications`, migratie
  `20260928140000`). Shop → *Specificaties* (`admin/product-specifications.php`,
  `products.manage`) is een bibliotheek van eigenschappen — Dikte, Hoogte,
  Materiaal — met een naam per websitetaal en een optionele korte eenheid,
  als één lijst met één opslag (`SpecificationLibraryEditor`). Elke rij zegt
  bij hoeveel producten hij is ingevuld; verwijderen haalt de waarde daar
  ook weg (de sleutels cascaderen). In de producteditor kiest de sectie
  *Specificaties* eigenschappen uit de bibliotheek, elk één keer, in de
  eigen volgorde van het product, met een waarde per taal (een getal typ je
  één keer, het valt terug). De productpagina toont ze als lijst onder de
  beschrijving, met eenheid, zonder lege rijen. Alleen presentatie: geen
  filters, zoeken of vergelijken.
- Collecties — `CollectionService`, `CollectionContent`,
  `CollectionRepository`, `collectie.php`, `CollectionGalleryItems`.
- Productoverzicht — **geen vanzelfsprekende pagina.** De Shop betekent niet
  dat er een publieke pagina met alle producten is. Onder Shop-instellingen →
  Productoverzicht kiest de eigenaar *Geen overzichtspagina* of een bestaande
  CMS-pagina (`App\Service\ShopOverview`, site-instelling `shop_overview`).
  Die pagina toont producten met het gewone Shop-blok **Productgrid**
  (`product_grid`): handmatig toevoegbaar op elke gewone pagina, op de plek
  die de eigenaar kiest, hooguit één per pagina, verwijderbaar, en niet meer
  applicatiekritisch. Het scherm toont bij elke pagina of er een Productgrid
  op staat en laat alleen zo'n pagina kiezen (plus de huidige keuze); een
  pagina kiezen voegt nooit zelf een blok toe. Het blok **Collectie-tegels**
  (`shop_collections`) volgt hetzelfde contract: handmatig, op elke gewone
  pagina, hooguit één per pagina, verwijderbaar. Beide kunnen sinds v0.1.15
  een eigen, optionele kop krijgen (bovenkop, titel, tekst, per taal; editor
  `admin/shop-listing.php`) en Extra vormgeving (`CONTENT-BLOCKS.md`,
  "Productgrid en Collectie-tegels: een eigen kop"). De derde Shop-lijst is de
  **Collectiegalerij** (`item_gallery`): de producten van één collectie als
  beeldraster, sinds v0.1.15 een blok van de Shop en alleen van de Shop. De winkelpagina
  (`content_key = shop`) is de systeempagina van de Shop ("Systeempagina's
  van modules"): een installatie die haar al had houdt haar eigen blokken,
  een andere kreeg haar leeg, en leeg verandert ze niets. Elke Shop-link
  naar "de shop" — productpagina, winkelwagen, afrekenen, collectie,
  bestelstatus, personaliseren en hun kruimelpaden — vraagt het adres aan
  `ShopOverview` en schrijft `/shop.php` niet zelf. `shop.php` volgt de keuze:
  404 zonder overzicht, 302 naar de gekozen pagina, de pagina met
  `content_key = shop` op haar eigen adres (een concept: 404), of — alleen voor een bestaande
  installatie die dat al toonde (`builtin`, gepind door `20260923140000`) — het
  oude automatische overzicht. De route `shop` in de linkkiezer en de
  sitemapregel `storefront` bestaan alleen zolang er een overzicht is. Zie
  `INSTALL-BOOTSTRAP.md` voor de tabel per situatie.
- **Uitgelicht product** (`featured_product`, `CONTENT-BLOCKS.md`): één
  product groot op een gewone pagina, herhaalbaar, met naar keuze de
  bestelmogelijkheid van de productpagina. Het blok bewaart alleen welk
  product en hoe het getoond wordt; het product zelf komt live uit dezelfde
  code als `product.php`. Daarvoor zijn twee stukken van de productpagina
  gedeeld gemaakt:
  - `App\Service\ProductDetail` bouwt de productpayload (zichtbaarheid,
    woorden, foto's, varianten, voorraad als *uitverkocht* en een maximum, en
    alleen voor een product dat direct verkocht wordt de prijzen).
    `api/product.php` geeft hem door, het blok drukt hem af in zijn eigen
    sectie. `withoutPrices()` haalt de prijzen eruit voor een plek die geen
    prijs toont, ook als ze verkoopt: de winkelwagenregel vraagt de prijs dan
    bij het toevoegen aan `api/product.php` (`shop.js`, `linePrice()`).
  - `App\Service\ProductPurchasePath` beslist hoe een zichtbaar product te
    koop is: `inquiry`, `personalize`, `unorderable` of `cart`, in die
    volgorde. `product.php` en het blok vragen het hier; het blok kan alleen
    minder aanbieden (*Alleen product bekijken*), nooit meer.

  De markup van het koopgedeelte staat in `partials/product-purchase.php`,
  voor de productpagina en het blok. `render_product_order_fields()` en
  `shop.js` zetten een voorvoegsel voor elk id, zodat twee producten op één
  pagina nooit een id of een radiogroep delen; op de productpagina is het
  voorvoegsel leeg en zijn de id's zoals ze waren. `shop.js` draait de
  productcode per `[data-product-detail]`-element en zoekt alleen daarbinnen.
  Een verborgen koopregel (`.product-detail__add-row[hidden]`) is sindsdien
  ook echt weg: vóór het blok bleven aantal en knop op de productpagina naast
  *Uitverkocht* staan, omdat `display: flex` het attribuut `hidden`
  overschreef. Met de Shop uit is het blok niet geregistreerd; zijn editor en
  endpoint vragen het recht van hun bloklijst (`pages.manage` op een pagina,
  Core) en hebben daarom een eigen `ModuleGuard`.
- Gerelateerde producten — `RelatedProductsContent`,
  `admin/related-products.php`, `partials/related-products.php`.
- Winkelwagen — volledig client-side (`vvl-cart` in `localStorage`,
  `assets/js/shop/cart.js`), `cart.php`, `partials/header-cart.php`.
- Afrekenen — `checkout.php`, `api/checkout.php`, `Service\Address\*`.
- Bestellingen en betalingen — `OrderRepository`, `OrderPaymentSync`, de
  betaalprovider in `Service\Payment\*` (hieronder, *Betalingen*),
  `MolliePaymentData`, `api/mollie-webhook.php`, `OrderConfirmationService`,
  `OrderCsvExport`, `admin/orders.php`. Een bestelnummer wordt één keer
  gemaakt, bij het aanmaken van de bestelling, en opgeslagen in
  `orders.order_number`; mail, Mollie, beheer, export en factuur lezen het via
  `OrderRepository::orderNumber()`.
- **Betalingen** (Mollie Setup 2.0). Mollie is de enige betaalprovider; de
  architectuur laat later een tweede toe zonder checkout of bestellingen
  opnieuw te ontwerpen, maar er is er nu geen.
  - **Eén contract, één provider.** `App\Service\Payment\PaymentProvider` heeft
    vier methoden, elk met een aanroeper: `isConfigured()` en
    `createPayment()` (`api/checkout.php`), `fetchPayment()` (webhook en
    `api/order-status.php`) en `availableMethods()` (Betalingen).
    `MolliePaymentProvider` is de enige implementatie en de enige klasse
    buiten het scherm die de Mollie-SDK aanroept; `PaymentProviders::active()`
    is de enige regel die hem noemt. `OrderPaymentSync` past een
    `PaymentSnapshot` toe: de vijf woorden van `orders.status`, en het eigen
    woord van Mollie in `mollie_status`. Er is **geen `refund()`**: Mygdala
    maakt geen terugbetaling, die gebeurt in het Mollie-dashboard en komt met
    het volgende `fetchPayment()` binnen. De kolommen heten nog
    `mollie_payment_id` en `mollie_status`, en er is geen
    `payment_provider`-kolom: met één provider zou die niets zeggen. Elke fout
    wordt één `PaymentProviderException` van vijf soorten (niet ingesteld,
    sleutel geweigerd, niet gevonden, tijdelijk, geweigerd) met een bericht
    dat Mygdala zelf schrijft en waarin alles wat op een sleutel lijkt
    `[redacted]` is.
  - **Welke sleutel** (`MollieConfiguration`), één keten, zoals bij `AppUrl`:
    1. `MOLLIE_API_KEY` in de serveromgeving. Die pint de shop: de modus is het
       voorvoegsel van de sleutel (`test_` of `live_`), Betalingen zegt
       *Geconfigureerd via serveromgeving*, toont geen sleutelvelden en
       weigert een sleutel of modus uit een verzoek. Zo blijft een installatie
       van vóór dit scherm betalen zonder dat iemand iets instelt. De
       placeholder uit `.env.example` (`test_xxxx…`) telt niet als sleutel.
    2. De *Test API-sleutel* en *Live API-sleutel* van Shop → Betalingen,
       versleuteld in `App\Service\Secrets\SecretStore` (`SETUP.md`,
       "Geheimen in het CMS"), en de gekozen modus
       (`site_settings.shop_payment_mode`, `test` tot iemand bewust live
       kiest).
    3. Niets. Dan weigert `api/checkout.php` meteen (503), vóór een
       adrescontrole of een bestelling.

    Een opgeslagen sleutel die niet meer te ontsleutelen is, telt als geen
    sleutel: er wordt nooit met iets anders betaald, en het scherm vraagt hem
    opnieuw.

    Die keten kiest de sleutel voor een **nieuwe** betaling. Een bestaande
    betaling wordt opgezocht met de sleutel van de modus waarin ze gemaakt is
    (hieronder, *Test of live per bestelling*).
  - **Wie mag het: `payments.manage`.** Sleutels opslaan of vervangen, test
    of live kiezen, de betaalmethoden en de verbindingstest vallen onder een
    eigen permissie, *Betalingen beheren* in de groep Shop. Wie die heeft,
    bepaalt op welk Mollie-account klanten betalen; daarom kan **alleen een
    Super Admin** hem toekennen of intrekken
    (`ShopModule::superAdminGrantablePermissions()`, dat samen met Cores
    `users.manage` en `updates.manage` in
    `AdminPermissions::superAdminGrantableOnly()` komt), en krijgt niemand hem
    vanzelf: niet wie `settings.manage` heeft, niet wie bestellingen of
    verzending beheert. Een Super Admin heeft hem zoals elke permissie. Een
    beheerder zonder `payments.manage` ziet Betalingen niet in het menu en
    krijgt 403 op het scherm en op elk rechtstreeks verzoek aan de twee
    endpoints; Shop-instellingen blijft gewoon onder `settings.manage`. Na de
    update heeft dus alleen een Super Admin Betalingen: wie het eerder via
    `settings.manage` deed, krijgt het terug van een Super Admin.
  - **Live alleen na een werkende verbinding.** Live kiezen, of een live shop
    een nieuwe live-sleutel geven, voert in diezelfde opslag de
    verbindingstest uit op díe sleutel; weigert Mollie, dan wordt niets
    geschreven, ook de sleutel niet. Er is geen onthouden "geverifieerd" dat
    na een sleutelwissel zou blijven staan. Een opgeslagen live-sleutel zet
    nooit zelf live. De live-sleutel vervangen van een shop die live is en al
    betalingen had, vraagt een bevestigingsvinkje.
  - **Live alleen met een echt webadres.** Een echte betaling heeft een
    adres nodig waar Mollie de webhook kan afleveren en waar de klant naar
    terugkomt. Zonder een publiek https-adres uit `AppUrl` (geen `APP_URL` en
    geen adres uit de installatiewizard; `http://`; of localhost,
    `*.localhost`, `.test`, een privé-IP en de andere adressen die Mollie niet
    bereikt) geldt voor een live-sleutel
    (`MolliePaymentProvider::liveBaseUrlProblem()`: `missing`, `not_https`,
    `not_public`):
    - Live kiezen op Betalingen wordt geweigerd met de reden erbij;
    - de statuskaart zegt *Probleem* met "Website-URL moet correct ingesteld
      zijn voordat Live gebruikt kan worden.", ook als de sleutel zelf werkt
      (bijvoorbeeld een live-sleutel in `MOLLIE_API_KEY`);
    - `isConfigured()` is onwaar, dus `api/checkout.php` weigert met 503
      vóór er een adres gecontroleerd of een bestelling opgeslagen wordt, en
      `createPayment()` weigert ook zelf, zonder verzoek aan Mollie.

    **Testmodus werkt op elk adres**, lokaal ook: zonder bereikbaar adres
    gaat er geen webhook mee en werkt de bestelstatuspagina de bestelling
    bij (hieronder, *De webhook*). Er is geen tunnel en er hoeft er geen te
    zijn.
  - **Test of live per bestelling** (`orders.payment_mode`, migratie
    `20260927160000`). Bij het aanmaken van de betaling legt
    `api/checkout.php` de modus vast die Mollie teruggeeft (`CreatedPayment::$mode`,
    anders het voorvoegsel van de gebruikte sleutel): `test` of `live`. Hij
    wordt daarna nooit meer afgeleid van de huidige modus van de shop, de
    actieve sleutel of het betaal-id; een testbestelling blijft een
    testbestelling als de shop vijf minuten later live gaat.
    `OrderRepository::setMolliePaymentId()` schrijft alleen `test` of `live`,
    al het andere wordt NULL; `OrderRepository::isTestOrder()` is alleen
    `test`.

    Webhook en bestelstatuspagina vragen de betaling op met `fetchPayment(id,
    modus van de bestelling)`: **alleen de sleutel van die modus**, welke
    modus de shop nu ook heeft, en geen andere. Een testbetaling die na de
    overstap naar live wordt afgerond, wordt dus met de testsleutel
    gevonden; ontbreekt die sleutel (of pint de serveromgeving een sleutel
    van de andere modus), dan wordt Mollie niets gevraagd, antwoordt de
    webhook 503 en blijft de bestelling staan tot de sleutel terug is.

    **NULL** is een bestelling van vóór deze kolom. Welke sleutel die betaalde
    is niet meer na te gaan, dus ze gedraagt zich precies als voorheen: een
    echte verkoop met een echte factuur, en de oude terugval bij het opvragen
    (de actieve sleutel, en bij *niet gevonden* één keer de opgeslagen
    sleutel van de andere modus). De migratie vult niets in.
  - **Testbestellingen** (`payment_mode = 'test'`):
    - **TEST-badge** (`.admin-badge--test`, amber met woord) naast het
      bestelnummer in Bestellingen, op het besteloverzicht (met een
      waarschuwing bovenaan) en in *Recente bestellingen* op het dashboard.
      Ze blijven in die lijsten, in het aantal *Af te handelen* en in de
      CSV-export (kolom *Betaalmodus*: `test`, `live` of leeg): het zijn echte
      bestellingen om af te handelen of weg te gooien, alleen geen omzet.
    - **Geen omzet.** De enige query die geld optelt voor de eigenaar,
      `DashboardRepository::orderTotalsBetween()` (omzet, aantal bestellingen
      en gemiddelde orderwaarde op het dashboard, voor de periode en de
      vergelijkingsperiode), voegt `OrderRepository::REAL_SALE_CONDITION`
      toe: `live` en NULL tellen, `test` niet. De andere orderqueries
      (`findRecentOrders()`, het aantal *Af te handelen*, de bestellijst, de
      export, retourverzoeken) zijn operationeel en zijn bewust niet
      veranderd.
    - **Geen factuur.** `InvoiceService::issueForOrderIfNeeded()` geeft een
      testbestelling niets uit: geen nummer uit de doorlopende teller, geen
      rij in `invoices`, geen PDF. Er is geen aparte testreeks en geen
      pro-forma. De kaart *Factuur* zegt "Testbestelling — er wordt geen
      echte factuur uitgegeven." en heeft geen knop die er een maakt;
      `api/admin/generate-invoice.php` weigert een testbestelling ook bij een
      rechtstreeks verzoek.
    - **De bevestigingsmail** gaat wel, zonder bijlage, met `[TEST] ` voor
      het onderwerp en een melding bovenaan (klant en winkel): het was een
      testbetaling, er is geen geld overgemaakt en er hoort geen factuur bij.
      Opnieuw versturen kan, ook zonder factuur.
  - **Een definitieve betaalstatus blijft staan.** Webhook en
    bestelstatuspagina kunnen dezelfde bestelling tegelijk bijwerken.
    `OrderRepository::updateStatusFromMollie()` schrijft een status alleen
    als de bestelling nog niet `paid`, `failed`, `canceled` of `expired` is
    (`FINAL_PAYMENT_STATUSES`), of als het dezelfde status is: in één
    `UPDATE`, zonder lezen ertussen. Een tragere sync met een oudere status
    wordt geweigerd; `OrderPaymentSync` gaat dan verder met wat is
    opgeslagen. Van `pending` naar een eindstatus blijft gewoon mogelijk.
  - **De verbindingstest** (`MollieConnectionResult`) is één alleen-lezende
    aanroep, `methods->allEnabled`: geen bestelling, geen betaling, geen
    terugbetaling, geen webhook. Hij houdt drie soorten problemen uit elkaar:
    verkeerd ingesteld (geen sleutel, geen sleutelvorm, onleesbaar), sleutel
    geweigerd (401/403) en Mollie niet bereikbaar (netwerk, time-out, 429,
    5xx). De statuskaart doet hem bij elke weergave, *Test deze sleutel* ook
    met een sleutel die nog niet is opgeslagen. Voor wat een beheerder
    afwacht probeert de SDK één keer opnieuw in plaats van vijf keer.
  - **Een sleutel komt nooit terug.** Na opslaan toont het scherm alleen
    `test_••••••••abcd` (voorvoegsel en laatste vier tekens); het veld is
    leeg, en een leeg veld houdt de opgeslagen sleutel. Geen JSON-antwoord,
    flash, logregel of redirect bevat een sleutel.
  - **Betaalmethoden: beschikbaar en aangeboden** (`ShopPaymentMethods`).
    Beschikbaar is wat Mollie voor de actieve sleutel aan heeft staan, live
    gevraagd: het enige dat bepaalt wat kán. Aangeboden is de keuze van de
    eigenaar, opgeslagen als method-id's (`shop_payment_methods`) met de namen
    die Mollie ze op dat moment in elke ingeschakelde websitetaal gaf
    (`shop_payment_method_names`), zodat de checkout Mollie bij een
    paginaweergave niets vraagt. Zonder keuze biedt de checkout precies wat
    hij altijd bood: iDEAL en creditcard, met hun oude woorden; een update zet
    dus niets nieuws aan. Een methode gaat alleen aan als Mollie hem nú
    aanbiedt voor de sleutel die na de opslag actief is; een aangeboden
    methode die Mollie niet meer aanbiedt mag blijven, gemarkeerd; er blijft
    er altijd minstens één. `checkout.php` toont de aangeboden methoden in
    volgorde (de eerste gekozen), `api/checkout.php` accepteert alleen een
    aangeboden methode (het oude `kaart` blijft creditcard) en geeft hem mee
    als `method`.
  - **De webhook** hoeft niemand in Mollie in te stellen: elke betaling geeft
    `<AppUrl::base()>/api/mollie-webhook.php` mee, nooit de Host-header van het
    verzoek. Voor een adres dat Mollie niet kan bereiken (localhost,
    `*.localhost`, `.test`, `.local`, `.internal`, een privé-IP of een naam
    zonder punt) gaat er geen mee; dan werkt de bestelstatuspagina de
    bestelling bij, zoals lokaal altijd. Het webhook-antwoord zegt of Mollie
    opnieuw moet afleveren: **400** voor iets dat geen Mollie-betaal-id is
    (vóór er iets gevraagd wordt), **200** als het klaar is en ook voor een
    betaling die niemand kent of die Mollie definitief weigert, **503** met
    `Retry-After` als Mollie niet bereikbaar is, de sleutel niet werkt of het
    opslaan faalt. Een betaal-id dat geen bestelling heeft, wordt beantwoord
    zonder Mollie iets te vragen: een anonieme POST met een verzonnen id kost
    de shop geen verzoeken bij Mollie. Welke sleutel de betaling opvraagt,
    bepaalt de bestelling (*Test of live per bestelling*, hierboven).
  - **Een testbetaling** is de echte checkout in testmodus; Mollie toont dan
    zijn testpagina waarop je de uitkomst kiest. Er is geen nepcheckout in het
    CMS. Betalingen legt de acht stappen uit en linkt in testmodus naar de
    winkel.
  - **Geen auditlog.** De Shop heeft er geen; het serverlog krijgt
    `[payments] test API key replaced by admin user #3` en dergelijke, nooit
    met de sleutel.
  - **Bekend** (open punten voor de eigenaar):
    - Testbestellingen van vóór deze update hebben NULL en tellen dus als
      echte verkoop, met hun factuur. Dat is niet terug te rekenen en wordt
      niet geraden; zo'n factuur blijft wat ze is.
    - Een testbestelling krijgt wel een bestelnummer uit de gewone reeks.
      Bestelnummers hoeven niet aaneengesloten te zijn, factuurnummers wel;
      alleen die reeks blijft schoon.
    - Een testbestelling die na de overstap naar live nog open staat, wordt
      alleen bijgewerkt zolang de testsleutel is opgeslagen. Wie die wist,
      laat haar op *in afwachting*; de webhook antwoordt 503 tot Mollie het
      opgeeft.
  - Scherm en endpoints: `admin/payments.php` (menu Shop, `payments.manage`
    plus `ModuleGuard`),
    `api/admin/update-payment-settings.php` (`PaymentSettingsEditor`,
    `AdminEditorResponse`), `api/admin/test-payment-connection.php`,
    `admin/assets/payments.js`. De teksten voor de beheerder, het stappenplan
    in het Nederlands en het Engels inbegrepen, staan in de catalogus onder
    `payments.*` en `help.payments.*`.
- Facturen — `InvoiceService`, `PdfInvoiceRenderer`, `InvoiceStorage`. Een
  factuur wordt één keer uitgegeven, zodra een bestelling betaald is
  (`OrderPaymentSync` → `InvoiceService::issueForOrderIfNeeded()`): een
  nummer uit de doorlopende teller per jaar, een rij in `invoices` met de
  bedrijfsgegevens bevroren in `seller_snapshot`, en een PDF buiten de
  webroot. De bestelbevestiging voegt precies dat bestand bij. Er is één
  sjabloon, `PdfInvoiceRenderer`, en alleen `InvoiceService` roept hem aan.
  De factuur is altijd Nederlands; een bestelling kent geen eigen taal. Een
  testbestelling krijgt geen factuur (*Betalingen*, hierboven).

  **Factuur bekijken** staat op het besteloverzicht (`admin/order.php`, kaart
  *Factuur*) zodra er een factuur is, en opent
  `api/admin/invoice-download.php` in een nieuw tabblad: de PDF inline in de
  pdf-viewer van de browser, met *Download PDF* ernaast (`&mode=download`,
  dezelfde bytes als bijlage). Dat is precies wat de klant kreeg:
  `InvoiceService::issuedPdfForOrder()` geeft het opgeslagen bestand, of, als
  dat weg is, dezelfde factuur opnieuw in het geheugen gerenderd uit haar
  bevroren gegevens (eigen nummer, datum en `seller_snapshot`, de
  orderregels van de bestelling zelf). Bekijken is **alleen-lezen**: het geeft
  geen factuur uit, reserveert geen nummer, schrijft geen bestand en raakt
  geen rij. Een ontbrekend bestand zet het mailpad terug
  (`regeneratePdfIfMissing()`), niet het kijken. `orders.view` is genoeg, net
  als voor het besteloverzicht; niet ingelogd gaat naar de login, zonder
  `orders.view` (en met de Shop uit) volgt 403, een bestelling zonder factuur
  geeft 404. Headers: `application/pdf`, `inline` of `attachment` met
  `filename="factuur-<nummer>.pdf"` uit `InvoiceService::pdfFilename()`
  (dezelfde naam als de mailbijlage, teruggebracht tot `[A-Za-z0-9._-]`
  omdat het factuurprefix vrije tekst is), `nosniff` en
  `Cache-Control: private, no-store`. Een opnieuw gerenderde PDF heeft dezelfde
  inhoud maar niet dezelfde bytes: dompdf zet er het tijdstip en een
  willekeurig document-id in.
- Shop-instellingen — `ShopSettings`, `admin/shop-settings.php`,
  `api/admin/update-shop-settings.php`: de bedrijfsgegevens en vaste teksten
  op facturen, het bestelnummerprefix, de tekst van de bestelbevestiging
  (met "Herstel standaardtekst" en uitleg bij de invulvelden), het
  productoverzicht en de standaard overgang van de productgalerij. Dit waren de
  tabbladen Facturen en E-mails van Instellingen; het zijn dezelfde
  sleutels in `site_settings`, dus uit- en aanzetten raakt ze niet. Adres,
  KVK-nummer, e-mailadres en telefoon staan niet hier maar op
  Instellingen, omdat de footer en de mailvoetregel ze ook lezen. Het
  scherm vraagt `settings.manage`, dezelfde permissie als die tabbladen. Dat
  is een Core-permissie die met de Shop uit gewoon houdbaar blijft, dus
  scherm en endpoint hebben wél een `ModuleGuard`. Betalingen vraagt sinds
  de release-hardening `payments.manage`, een Shop-permissie, en houdt zijn
  `ModuleGuard` toch: de volgorde van de guards blijft zoals hij was.

  **De prefixen van bestel- en factuurnummer** staan in één klasse,
  `App\Service\DocumentNumberPrefix`, die ook het `pattern` van de velden
  levert. Het bestelnummerprefix is letters en cijfers (hooguit 10): de
  formatter zet de streepjes zelf. Het factuurprefix begint met een letter of
  cijfer, gevolgd door letters, cijfers, `-` en `_` (hooguit 20), zodat
  `INV-` kan; nooit `/`, `\`, `:`, een punt, aanhalingstekens of spaties,
  want het factuurnummer is ook de naam van het PDF-bestand. Een gewijzigd
  ongeldig prefix wordt geweigerd. Een prefix van vóór deze regel wordt niet
  herschreven en blokkeert geen opslag van de andere factuurteksten: het
  tabblad Facturen waarschuwt en noemt wat nieuwe facturen gebruiken, namelijk
  alleen de toegestane tekens (of `INV`). Bestaande facturen, hun nummer en hun
  `pdf_path` blijven zoals ze zijn; het opslagpad van een nieuwe factuur is
  altijd `<jaar>/<nummer>.pdf`, met alles buiten `[A-Za-z0-9_-]` als `-`.
- Retourverzoeken (herroepingsrecht) — `WithdrawalRequestRepository`,
  `herroeping.php`, `api/withdrawal-request.php`,
  `admin/withdrawal-requests.php`, `admin/withdrawal-request.php`,
  `api/admin/update-withdrawal-request-status.php`. Handmatige beoordeling:
  een verzoek krijgt alleen een status, de Shop beslist nooit zelf of het
  terecht is.
- Verzending — `Service\Shipping\*`, `ShippingZoneRepository`,
  `ShippingRateRepository`, `admin/shipping.php`, `admin/carrier-rates.php`.
- Dashboardpaneel — `admin/_dashboard_shop.php`, met `DashboardMetrics`,
  `DashboardAttention` en `DashboardRepository`.

  **De deel-afbeelding van een product en een collectie** komt sinds Media
  Library 2.0 ook uit de bibliotheek (`og_media_id`, met `og_image_path`
  meegeschreven; `shop_share_image_choice()` in
  `api/admin/_shop_share_image.php`, `MEDIA.md`). Een oude eigen upload blijft
  tot er een andere wordt gekozen of hij bewust wordt verwijderd.

Facturen, retourverzoeken en verzending zijn deelgebieden *binnen* de Shop.
Ze zijn niet zelfstandig bruikbaar (een factuur hoort bij een order, een
herroeping ook, een tarief bij een winkelmandje), dus geen aparte modules.

**Pagina-inhoud van een product** (Product & Portfolio Content Pages 1.0).
Een product kan dezelfde contentblokken krijgen als een pagina, op het
tabblad *Pagina-inhoud* van de producteditor (alleen voor een bestaand
product, voor wie het product mag beheren: `products.manage`; niet
`pages.manage`, dat alleen geeft geen toegang tot de blokken van een product,
`CONTENT-BLOCKS.md`, "Wie mag welke blokken beheren"). `product.php` rendert ze onder de productdetail (galerij, naam, prijs,
voorraad, varianten, bestelvelden, specificaties, bestellen) en de
personalisatie, en boven *Gerelateerde producten*. De productdetail blijft de
kop van de pagina: blokken zijn aanvullende redactionele inhoud, en de
`<head>` (titel, canonical, Product-JSON-LD) blijft van `ProductSeo`. Een
product zonder blokken rendert byte voor byte wat het deed. De Shop draagt de
eigenaar bij (`ShopModule::contentOwners()`, `App\Service\ProductContentOwner`,
koppeltabel `product_content_pages`); het product verwijderen neemt de blokken
en de inhoudspagina mee (`ProductDeletionService`). Met de Shop uit is de
productpagina een 404 en blijven de blokken bewaard. Zoeken (Search 1.0)
doorzoekt ze nog niet. Het model: `CONTENT-BLOCKS.md`, "Blokken op een product
of project".

**De woorden van de Shop staan per websitetaal.** Sinds Multilingual 2.0
fase 5 (`docs/multilingual/ARCHITECTURE.md`) is `App\Service\ShopLocalization`
dé ingang naar de naam, de beschrijving en de SEO-velden van een product en
een collectie, plus de eigen kop van een collectie boven de gerelateerde
producten. Ze staan in `product_translations` en `collection_translations`,
één rij per eigenaar per taal; geen repository, geen `*Content`-klasse en geen
endpoint komt er buiten die klasse om bij.

**Woorden zijn geen identiteit.** Alles waar de winkel een besluit mee neemt
blijft op de rij zelf en is in elke taal hetzelfde: id, de neutrale slug,
prijs, voorraad, verzendinstellingen, `active`, `in_shop`,
`in_personalization_catalog`, afbeeldingspaden, varianten, `is_active`,
`show_related_products`, sorteervolgorde en elke relatie. Een taalwissel
verandert alleen zichtbare labels — nooit welk product in de winkelwagen zit
of wat het kost.

**Eén ding is er sinds Multilingual 2.0 fase 6 bij gekomen, en het is geen
woord: het adres.** Een collectie heeft per taal een `slug` in
`collection_translations`, want `/collecties/hout` en `/en/collections/wood`
zijn twee URL's voor één collectie (`docs/multilingual/ROUTING.md`). Het wordt
gelezen zonder terugval, en de collectie-editor schrijft alleen het adres van
de taal die op dat moment bewerkt wordt — het id, de neutrale slug, de
productkoppelingen met hun volgorde en de gerelateerde-producteninstelling
blijven staan. **Een product krijgt er met opzet geen**: dat is één pagina op
`/product.php?id=…`, hoeveel collecties het ook in zit.

**En een bestelling is een momentopname, geen vertaling.**
`order_items.product_name` blijft één taalvrije naam op de regel zelf: dat is
wat de factuur, de bevestigingsmail en het CMS-besteloverzicht afdrukken. Wat
het product in de *andere* websitetalen heette staat in
`order_item_translations`, via `App\Service\OrderItemNameSnapshot`, met een
eigen leesregel ("de rij van deze taal, anders de taalvrije naam") die niet van
het talenregister afhangt. Een geplaatste bestelling verandert dus niet als een
product hernoemd of verwijderd wordt, en ook niet als de site een taal
toevoegt, verwijdert of tot standaard maakt.

### Blog (module `blog`)

Eigen namespace (`App\Service\Blog`), eigen CMS-sectie (`admin/blog*.php`),
eigen tabellen, eigen instellingentabel, eigen publieke routes (`blog.php`,
`blog-post.php`, `blog-feed.php`) en één eigen stylesheet die alleen op die
routes geladen wordt.

**Hangt nergens van af** — hij werkt identiek met de Shop aan en uit — en staat
standaard **uit**, net als Portfolio. Hij levert als eerste module een
`MediaUsageProvider`, zodat de Mediabibliotheek weet dat een uitgelichte
afbeelding in gebruik is zonder ooit een blogtabel te noemen. Zijn
slugwijzigingen lopen door dezelfde Redirect Manager als die van een
CMS-pagina, en zijn metadata door dezelfde `SeoMetadata`. Zie `BLOG.md`.

`Tests\Blog\BlogModuleTest` bewaakt de grens, net zoals `ShopDisabledTest`
dat voor de Shop doet.

### Artikelen (module `articles`)

Eigen namespace (`App\Service\Articles`), eigen tabellen, eigen CMS-sectie
(`admin/article*.php`), eigen routes (`articles.php`, `article.php`) en één
eigen stylesheet. De tweede soort op de Publishing Engine, met contentblokken
als enige inhoud en hoogstens één onderwerp per artikel. Hangt nergens van af
en staat standaard **uit**. Een Articles-klasse noemt nooit een Blog-klasse.
Zie `ARTICLES.md`, ook voor het verschil met de Blog.

### Personalisatie (module `personalization`)

Eigen namespace (`App\Service\Personalization`), eigen CMS-sectie
(`admin/personalization*.php`), eigen tabellen, eigen frontend
(`personaliseren.php`, `assets/js/personalization.js`), eigen opslag buiten de
webroot.

**Hangt van de Shop af, en dat is nu ook afgedwongen**: een personalisatie is
geconfigureerd *op een product* en wordt vastgelegd op een *orderregel*
(`OrderItemPersonalizationRepository`). De poort zit op één plek,
`ProductPersonalizationContent::resolve()`: staat de module uit, dan meldt élk
product "niet te personaliseren", dus er rendert geen editor, er geldt geen
prijsopslag en `api/checkout.php` weigert een regel die tóch
personalisatiegegevens meestuurt. Andersom hoeft de Shop niets van
personalisatie te weten behalve die prijsopslag.

**De woorden van de module staan per websitetaal.** Sinds Multilingual 2.0
fase 5 (`docs/multilingual/ARCHITECTURE.md`) is
`App\Service\Personalization\PersonalizationLocalization` dé ingang naar de
algemene uitleg van een product, de naam van een voorbeeld en het label, de
uitleg en de voorbeeldtekst van een zone. Ze staan in drie getypeerde
tabellen, één rij per eigenaar per taal.

**Woorden zijn niet de configuratie.** `view_key` en `zone_key` — de sleutels
waar een bestelregel naar wijst — de geometrie, `allow_text`, `allow_image`,
`is_enabled`, `is_required`, `allow_rotation`, `max_text_length`, de meerprijs,
de lettertype-instellingen, `personalization_mode`, de voorbeeldafbeelding en
elke sorteervolgorde blijven op hun eigen rij en zijn in elke taal hetzelfde.
Een taalwissel verandert alleen zichtbare labels — nooit wat een klant mag
graveren, waar, of wat dat kost. En wat een zone heette toen iemand hem kocht
staat in de eigen `config_snapshot_json` van die bestelregel, die zijn vorm
houdt.

### Portfolio (module `portfolio`)

Eigen tabellen, eigen admin (`admin/portfolio.php`, `admin/portfolio-item.php`,
`api/admin/*portfolio*.php`), eigen
categorietaxonomie, eigen publieke routes (het overzicht `/portfolio`, het oude
`/portfolio.php` dat daarheen doorstuurt, en de
projectpagina's `/portfolio/<slug>` via `portfolio-detail.php`), de
galerijbron `portfolio` en het blok Projecten. Alles loopt via
`src/Module/PortfolioModule.php`; Core
noemt geen portfolio-item meer (`Tests\Module\PortfolioModuleTest`).

Het was Core, met als argument dat niemand het ooit uit zou willen zetten. Maar
een nieuwe installatie van dit CMS is niet vanzelf een portfoliosite, en een
Portfolio dat altijd aanwezig is kost elke site zonder portfolio een
zijbalk-item, een permissie, twee gereserveerde URL-woorden en een galerijbron.
Daarom is het een module, en staat het op een **nieuwe installatie standaard
uit**, net als de Blog. Een installatie die Portfolio al draaide toen het nog
Core was, houdt het via de opgeslagen voorkeur die een migratie schreef (zie
"Aan- en uitzetten").

**Woorden per websitetaal.** Sinds fase 5 van Multilingual 2.0 staan de
woorden van Portfolio niet meer in `_nl`/`_en`-kolommen, maar per websitetaal
in drie getypeerde tabellen. `App\Service\PortfolioLocalization` is de enige
lezer en schrijver; de rest van de module bewaart rijen, geen woorden.

| Tabel | Eigenaar | Velden |
|---|---|---|
| `portfolio_category_translations` | `portfolio_category_id` | `name` |
| `portfolio_item_translations` | `portfolio_item_id` | `title`, `subtitle`, `alt`, `intro` (rich), `description` (rich), `related_title`, `related_lead` (de kop van de gerelateerde projecten) |
| `portfolio_item_image_translations` | `portfolio_item_image_id` | `alt` |

**De afbeelding van een item komt uit de Mediabibliotheek** (Media Library
2.0, `MEDIA.md`): `portfolio_gallery_items.media_id`, gekozen of geüpload met
de gedeelde kiezer, met `image_path`/`thumbnail_path` meegeschreven zodat elke
lezer hetzelfde leest als voorheen. Een item van vóór de bibliotheek houdt zijn
eigen bestand tot er een andere afbeelding wordt gekozen. De module meldt het
gebruik via `PortfolioMediaUsage`, en een item verwijderen haalt nooit een
bibliotheekbestand weg. De eigen alt-tekst van een item wint; leeg valt terug
op die van het bibliotheekitem.

Wat taalneutraal blijft: de slug van een categorie en van een item, de
afbeelding en haar thumbnail, de galerijfoto's en hun volgorde, de schakelaar
van de projectpagina, de legacy-koppeling naar een pagina, de categorieën van
een item, `is_active`, de instellingen van de gerelateerde projecten en elke
sorteervolgorde. De slug van een
nieuwe categorie wordt eenmalig uit de naam in de **standaardtaal** gemaakt en
daarna nooit hernoemd, dus een vertaling verplaatst nooit een adres.

Beide schermen bewerken **één** taal tegelijk, via `admin/_localized_fields.php`
zoals elk ander omgezet scherm; een nieuwe categorie en een nieuw item worden
altijd in de standaardtaal geschreven. **Portfolio uitzetten verwijdert geen
woord**, en opnieuw aanzetten toont precies dezelfde tekst: de
vertaaltabellen bestaan los van de module, en de migraties vragen nooit of hij
aan staat. Zie `docs/multilingual/ARCHITECTURE.md`.

**Een projectpagina is van het item zelf** (Portfolio 2.0). Een
portfolio-item is de inhoudsbron van zijn eigen pagina op
`/portfolio/<slug>`, en die pagina is **geen `pages`-rij**: `portfolio-detail.php`
rendert haar dynamisch uit het item. Portfolio-hiërarchie en paginahiërarchie
staan los van elkaar; er komt geen verborgen pagina, geen `parent_id` en geen
"Nieuwe pagina maken" meer aan te pas.

| Onderdeel | Waar het staat | Waar je het bewerkt (Portfolio 3.0) |
|---|---|---|
| Aan/uit ("Projectpagina tonen") | `portfolio_gallery_items.has_detail_page` | kaart *Zichtbaarheid* |
| Adres (*Webadres*) | `portfolio_gallery_items.slug`, uniek, taalneutraal | kaart *Basisgegevens*, onder de titel |
| Titel, korte tekst, alt | `portfolio_item_translations` (`title`, `subtitle`, `alt`) | *Basisgegevens*, alt bij *Hoofdafbeelding* |
| Inleiding en beschrijving (rich text) | `portfolio_item_translations` (`intro`, `description`) | *Basisgegevens*, na de korte tekst |
| Hoofdafbeelding | `portfolio_gallery_items.media_id` | kaart *Hoofdafbeelding* |
| Galerij (de extra afbeeldingen) | `portfolio_item_images` (`media_id`, `sort_order`), alt per taal in `portfolio_item_image_translations` | kaart *Galerij*; waar ze op de pagina staan: het blok *Projectafbeeldingen* |

De aparte kaart *Projectpagina* is met Portfolio 3.0 verdwenen; geen van haar
velden. Uit de audit: alle vier hebben nog een functie die nergens anders
zit. *Projectpagina tonen* maakt `/portfolio/<slug>` (zonder staat er alleen
een kaart die vergroot), *Webadres* is die slug (redirects bij een rename),
en *Inleiding* en *Beschrijving* zijn de tekst van de vaste projectkop, de
bron van de meta description (`PortfolioSeo`) en, de inleiding, van de
sitezoekfunctie (`PortfolioSearchProvider`). Ze zijn dus verhuisd naar de
kaart waar ze bij horen, met dezelfde veldnamen: het endpoint, de opslag en de
publieke pagina veranderden niet en er is niets gemigreerd.

Alles hergebruikt wat er al was: de kolommen en tabellen van de projectpagina
die Portfolio vóór fase 4B had. De enige migratie is
`20260925160000_let_a_portfolio_photo_use_a_library_image`
(`portfolio_item_images.media_id`, `RESTRICT`, niets overgezet).

- **De slug** (`App\Service\PortfolioSlug`) wordt genormaliseerd zoals een
  paginaslug, is uniek binnen Portfolio, en wordt gemaakt uit de titel in de
  **standaardtaal** als het veld leeg blijft (of door het scherm zelf uit de
  titel is ingevuld): `gegraveerde-snijplank`, en `-2`, `-3` … bij een botsing.
  Een getypte slug die al van een ander item is, wordt **geweigerd**, nooit
  stil veranderd. De gereserveerde woorden van de site gelden hier niet: onder
  `/portfolio/` staat niets anders, dus `/portfolio/contact` is gewoon een
  project. Eén slug voor alle talen: `/en/portfolio/<slug>` is de Engelse
  versie. Een slug per taal is een aparte uitbreiding (eigen tabel, eigen
  routing) en bewust niet gebouwd.
- **Een gepubliceerd adres gaat nooit stil dood.** Wie de slug van een
  zichtbare projectpagina wijzigt, krijgt in elke actieve taal een 301 van het
  oude naar het nieuwe adres, via dezelfde `SlugChangeRedirects` als pagina's
  en blogberichten (`origin` `slug_change`, `REDIRECTS.md`).
  `portfolio-detail.php` vraagt de Redirect Manager pas als geen item het
  adres beantwoordt.
- **Zichtbaar** is de projectpagina als het item zichtbaar is
  (`is_active`), de schakelaar aan staat en er een slug is. Anders 404.
- **V1 is gestructureerde inhoud, geen paginabouwer.** Geen contentblokken,
  geen hero, geen kolommen of formulieren op een projectpagina. Blokken zijn
  een mogelijke latere uitbreiding.

**Gerelateerde projecten** (`db/migrations/20260928190000`,
`App\Service\PortfolioRelatedProjects`). Onder een projectpagina kan een rij
andere projecten staan, als precies dezelfde kaarten als in elke
Portfolio-galerij (`PortfolioGalleryContent::cards()`,
`partials/section-item-gallery.php`), met een eigen kop. Het staat **per
project uit** tot een redacteur het aanzet, dus een bestaande pagina verandert
niet.

| Instelling | Kolom op `portfolio_gallery_items` | Waarden (standaard eerst) |
|---|---|---|
| *Gerelateerde projecten tonen* | `related_enabled` | uit, aan |
| *Selectie* | `related_mode` | `automatic`, `manual`, `hybrid` |
| *Maximum aantal* | `related_max` | 3; de keuzes 2, 3, 4, 6, 8 |
| *Volgorde (automatisch)* | `related_sort` | `relevance`, `newest`, `oldest`, `title`, `random` |
| *Als er te weinig projecten uit dezelfde categorie zijn* | `related_fallback` | `available` (toon alleen wat er is), `fill` (vul aan met andere projecten) |
| *Kaarten* | `related_layout` | `normal` (drie naast elkaar, zoals de galerij), `compact` (vier kleinere), `large` (twee grote) |
| *Korte tekst op de kaarten tonen* | `related_show_text` | aan, uit |

- **Automatisch** kiest projecten die minstens één categorie met dit project
  delen. Bij *Meest relevant* gaan de projecten met de meeste gedeelde
  categorieën voor, bij een gelijke stand het nieuwste (`created_at`) en dan
  het hoogste id: een vaste volgorde, geen aanbevelingsmachine.
- **Handmatig** toont precies de gekozen projecten, in hun volgorde
  (`portfolio_related_items`: project, gekozen project, volgorde; de
  samengestelde sleutel maakt twee keer hetzelfde project onmogelijk).
- **Hybride** toont eerst de gekozen projecten en vult de overige plekken
  automatisch aan; een gekozen project komt nooit nog eens uit de automatische
  keuze, ook niet als het door het maximum niet paste.
- **Altijd**: het project zelf verschijnt nooit (niet gekozen, niet
  getrokken, niet aangevuld); alleen zichtbare projecten (`is_active`), dus
  een verborgen project wacht in de lijst en een verwijderd project verdwijnt
  eruit (`ON DELETE CASCADE`); nooit meer dan het maximum.
- **Geen snapshot.** De rij wordt per request uit de rijen van dat moment
  gekozen: een project dat verborgen, verwijderd of van categorie veranderd
  wordt, verandert de rij mee. *Willekeurig* trekt bij elke echte request
  opnieuw, op de server (`App\Service\RandomOrder`; zie CONTENT-BLOCKS.md,
  "Willekeurige volgorde").
- **De kop** is `related_title` in de taal van de bezoeker, met de gewone
  terugval naar de standaardtaal, en zonder eigen titel de ingebouwde
  *Gerelateerde projecten* / *Related projects*. `related_lead` is optioneel.
  Beide zijn woorden in `portfolio_item_translations`.
- **In de editor** is het een eigen, inklapbare sectie van het item
  (`admin/portfolio-item.php`): de schakelaar, en alleen als die aan staat de
  rest; de volgorde en de aanvulling alleen bij automatisch en hybride, de
  projectkiezer (`admin/_item_picker.php`: zoeken, miniatuur, titel,
  categorieën, *Verborgen*, ↑ ↓ en slepen) alleen bij handmatig en hybride.
  Eén formulier en één *Opslaan*, met de opslagbalk; wat verborgen is gaat
  gewoon mee, zodat heen en weer schakelen niets kwijtraakt
  (`validatePortfolioRelated()`).
- **Toegankelijk en responsief** omdat het de galerijkaart is: een echte
  zoomknop met naam, *Bekijk project* als echte link, de h2 onder de h1 van de
  projectpagina, en de galerijkolommen per breedte. Er is één lightbox-overlay
  voor de hele pagina (`ItemGalleryContent::claimLightboxOverlay()`).

**"Toon op homepage" bestaat niet meer** (`db/migrations/20260928210000`). Het
was `is_featured` met een eigen volgorde `featured_sort_order`, met als enige
lezer een galerij op de bron `portfolio` met het bereik *featured* — de
homepage-teaser van een bestaande installatie, en een Projecten-blok dat erop
stond. `20260928200000` maakt van elke zo'n galerij een **handmatige selectie
van precies dezelfde projecten in precies dezelfde volgorde** (ook een
verborgen project met de vlag, dat terugkomt zodra het weer zichtbaar is), en
`20260928210000` dropt daarna beide kolommen. Een galerij op *alle* keek nooit naar de vlag, dus geen
project verdwijnt ergens. Het schakeltje in de editor, de *Homepage-uitlichting*
en het homepagefilter op het overzicht, `move-featured-gallery-item.php` en de
bijbehorende CMS-teksten zijn weg; welke projecten een homepage toont, kies je
voortaan in het blok zelf.

**De galerij en de hoofdafbeelding.** De hoofdafbeelding staat apart en komt
bovenaan; de galerij bevat de aanvullende foto's. Dezelfde
bibliotheekafbeelding kan niet ook in de galerij (`App\Service\PortfolioProjectGallery`
laat haar vallen, het scherm meldt het). Foto's kies je met de gedeelde
kiezer in verzamelmodus, uploaden kan in de kiezer zelf, en de volgorde zet je
met ← →, slepen of ×; het script is dat van de producteditor
(`admin/assets/product-gallery.js`, root `[data-picture-gallery]`). × haalt
alleen de koppeling weg: het bibliotheekitem blijft. Alleen een oude foto op
Portfolio's eigen pad (`media_id` NULL) was van het item alleen, en haar
bestand verdwijnt met de koppeling. Alt-tekst is gelaagd: een eigen alt van
een oude foto wint, anders die van het bibliotheekitem. Een eigen alt per foto
bewerken kan in V1 niet.

**De kaart in een galerij** (`PortfolioGalleryContent::mapItemRow()`):

- de **afbeelding opent altijd de lightbox**, ongeacht de lightbox-instelling
  van het blok en ongeacht of er een projectpagina is. De kaart zelf is nooit
  een link, en de `fallback_link_url` van het blok geldt er niet voor;
- **"Bekijk project"** is een aparte, echte link in de overlay, in deze
  volgorde: (1) de gepubliceerde legacy-pagina van het item, (2) anders
  `/portfolio/<slug>` als de projectpagina aan staat, (3) anders geen knop.
  De regel is de schakelaar, geen heuristiek op hoeveel tekst of foto's er zijn;
- de overlay leest van boven naar beneden: titel, korte tekst, knop. Op een
  scherm zonder hover blijft de overlay van een kaart met knop zichtbaar.

**Eén lightbox** (`assets/js/lightbox.js`, `partials/lightbox.php`) voor het
overzicht én de projectpagina. De volgorde is de groep van de opener
(`[data-lightbox-group]`: een galerijblok, of de beelden van één project),
beperkt tot wat op dat moment getoond wordt. Filteren op een categorie beperkt
dus ook vorige/volgende; een projectpagina stapt nooit in de beelden van een
ander blok. Hij loopt rond aan beide kanten, is een dialoog met benoemde
knoppen, houdt de focus vast, reageert op Escape, ← →, Tab en een veeg, en
geeft de focus terug aan de opener.

**De projectpagina** toont: kruimelpad *Home / Portfolio / project* (de
Portfolio-pagina via content key `portfolio`; zolang die leeg is, of als er
geen is, de route *Portfolio*), de vaste projectkop (categorieën, titel, korte
tekst, hoofdafbeelding, inleiding, beschrijving, *Terug naar portfolio*) en
daaronder de pagina-inhoud, met de galerij als blok *Projectafbeeldingen*. SEO gaat via
`App\Service\PortfolioSeo`: titel `<project> | Portfolio — <site>`, als
description de korte tekst, anders het begin van de inleiding of beschrijving,
canonical in de gelezen taal, hreflang voor elke actieve taal, en de
hoofdafbeelding als deelafbeelding. De sitemap noemt elke projectpagina die
antwoordt, in elke taal (`projectPagesForSitemap()`), en nooit een adres dat
doorstuurt.

**Vaste projectkop en verplaatsbare pagina-inhoud** (Portfolio 3.0, op
Portfolio layout 2.0 en Product & Portfolio Content Pages 1.0). Een
projectpagina heeft twee lagen:

| Laag | Wat | Bepaald door |
|---|---|---|
| **Vaste projectkop** (`partials/project-hero.php`) | hoofdafbeelding, categorieën, titel, korte tekst, inleiding, beschrijving, *Terug naar portfolio* | de **Projectlayout**, en verder niets. Geen contentblok: hij staat altijd bovenaan |
| **Pagina-inhoud** (de blokken van het tabblad *Pagina-inhoud*) | **Projectafbeeldingen**, Tekst, Tekst met afbeelding, CTA, FAQ, Galerij, Reviews … | de blokkenlijst: slepen, verbergen, toevoegen, Extra vormgeving |

De Projectlayout (`App\Service\PortfolioProjectLayout`) gaat alleen over de kop:

| Layout | De kop |
|---|---|
| Afbeelding links (`image_left`) | zoals altijd, geen modifier-klasse |
| Afbeelding rechts (`image_right`) | de foto rechts (`project-hero--image-right`) |
| Afbeelding boven (`image_top`) | de foto breed boven de tekst (`project-hero--image-top`) |

- **De standaard** staat bij *Portfolio → Instellingen* (site-instelling
  `portfolio_project_layout`, standaard `image_left`;
  `api/admin/update-portfolio-settings.php`).
- **Per project** kiest het tabblad *Pagina-inhoud* *Gebruik
  standaardinstelling* (`portfolio_gallery_items.project_layout` NULL) of een
  eigen layout. Een project op de standaard verandert mee; een eigen keuze
  blijft staan.
- **Projectafbeeldingen** (`project_images`, `App\Service\Blocks\ProjectImagesBlock`)
  is de enige plek waar de extra afbeeldingen van een project renderen
  (`partials/section-project-images.php`, dezelfde markup en stijl die de kop
  er vroeger onder zette). **Het blok bezit de media niet**: geen eigen
  tabel, geen mediakiezer, geen kopie van een mediareferentie. Het leest bij
  elke render de foto's van het project waarvan de pagina is
  (`PortfolioGalleryContent::itemForDetailPageById()`), in de volgorde van het
  project. Een foto toevoegen of weghalen bij het project staat dus meteen
  goed op de pagina, zonder synchronisatie; verbergen of verplaatsen raakt de
  foto's en het mediagebruik (dat naar het project wijst) niet. Geen foto's:
  geen sectie, ook geen lege.
- **Een vast blok** (`FixedBlockDefinition`, badge *Beheerd elders*): niet in
  de kiezer, nooit te verwijderen, alleen op een project (`owners`), en hoogstens
  één per project. Zijn `page_sections.section_id` is het id van het project,
  dus `UNIQUE(section_type, section_id)` laat de database zelf een tweede
  weigeren. `App\Service\ProjectImagesPlacement` zet het bovenaan bij een nieuw
  project (`create-portfolio-item.php`) en zet het terug bij een opslag als het
  ontbreekt (`update-portfolio-item.php`). Wie de foto's niet wil tonen,
  verbergt het blok.
- **Eén lightbox.** De kop en het blok dragen dezelfde benoemde groep
  (`data-lightbox-group="project-<id>"`, `assets/js/lightbox.js`), dus
  hoofdfoto en extra foto's blijven één reeks, ook met blokken ertussen.
- **Extra vormgeving**: achtergrond, randen, ruimte en de rustige effecten
  (`glow`, `pattern`), zoals de galerij. Direct onder de kop en zonder eigen
  look sluit het blok aan op de kop zoals de foto's dat vroeger deden; elders
  heeft het de gewone sectieruimte.
- **Niets te zoeken**: geen eigen woorden (`searchFields()` leeg); alt-teksten
  blijven buiten de zoekfunctie (`SEARCH.md`).

**Projectinformatie en de vrije indeling zijn weg** (Portfolio 3.0). Het blok
Projectinformatie zette de kop als blok neer voor de layout *Vrije indeling*;
nu elke projectpagina een vaste kop heeft, waren beide overbodig. Migratie
`20261014100000_give_every_project_a_project_images_block`:

| Bestaand project | Na de migratie |
|---|---|
| vaste layout | Projectafbeeldingen bovenaan (waar de foto's al stonden), zichtbaar; andere blokken in hun volgorde erna |
| vrije indeling met een Projectinformatie-blok | Projectafbeeldingen op de plek van dat blok, zichtbaar alleen als dat blok zijn foto's toonde; de layout wordt de vaste layout van de kop die het toonde (foto links/rechts/boven) |
| vrije indeling zonder Projectinformatie-blok | Projectafbeeldingen bovenaan, **verborgen** (er stonden geen foto's); layout `image_left` |
| standaard `free` | standaard `image_left`; een project dat hem volgde en de foto rechts of boven had, krijgt dat als eigen keuze |

Bij een vrij project is één ding onvermijdelijk anders: de kop staat weer
bovenaan, dus een blok dat boven het Projectinformatie-blok stond, volgt nu
de kop. Alle Projectinformatie-rijen, hun drafts en de tabel
`portfolio_project_infos` zijn weg; die tabel bevatte geen projectdata, alleen
"waar staat de foto" en "foto's tonen", allebei hierboven overgenomen. Een
inhoudspagina wordt gemaakt voor elk project dat er geen had. Idempotent.

- **Gerelateerde projecten en de oproep** van de Portfolio-pagina blijven de
  vaste slotzone onder de blokken, in elke layout. Ze als blok verplaatsbaar
  maken was geen V1-werk.
- Het project verwijderen neemt de blokken (Projectafbeeldingen ook), de
  inhoudspagina en de fotorijen mee (`api/admin/delete-portfolio-item.php`);
  een bibliotheekfoto blijft in de bibliotheek. Met de Portfolio uit is de
  projectpagina een 404 en blijft alles bewaard.
- **Rechten.** De blokken van een project beheert wie het project mag
  beheren: `portfolio.manage`, niet `pages.manage` (`CONTENT-BLOCKS.md`,
  "Wie mag welke blokken beheren").

**De legacy-koppeling naar een gewone pagina blijft werken.** Tussen fase 4B
en Portfolio 2.0 kon een item naar een gewone CMS-pagina linken
(`portfolio_gallery_items.page_id`, `ON DELETE SET NULL`). Zo'n koppeling
wordt **niet** verwijderd, omgezet of stil ontkoppeld:

| Situatie | Antwoord |
|---|---|
| Het item linkt naar een gepubliceerde pagina | "Bekijk project" gaat naar die pagina, en `/portfolio/<slug>` stuurt tijdelijk (302) door naar haar canonical |
| De gekoppelde pagina is een concept of weg | de eigen projectpagina van het item geldt |
| Portfolio uit | 404 via `ModuleGuard`, ook met een koppeling |

Een **nieuwe** koppeling kan niet meer ontstaan: de editor heeft geen
paginakeuze, `page_id` wordt niet meer gelezen, en een item zonder koppeling
krijgt er nooit een. Een item met een koppeling toont de kaart *Gekoppelde
pagina* met de naam en status van die pagina en één handeling:
*Koppeling met deze pagina verwijderen*. Dat raakt de pagina zelf nooit. In het
overzicht heet zo'n item *Gekoppelde pagina*.

Automatisch omzetten gebeurt niet: de inhoud van een gewone pagina (blokken)
past niet verliesvrij in één beschrijvingsveld. De handmatige route: schrijf de
inleiding, beschrijving en galerij op het item, zet *Projectpagina tonen* aan,
ontkoppel de pagina, en zet eventueel zelf een redirect van het oude
paginaadres naar `/portfolio/<slug>` in de Redirect Manager.

**De module-root.** Het publieke routecontract van Portfolio is:

| Adres | Antwoord |
|---|---|
| `/portfolio` | het Portfolio-overzicht (route `portfolio.index`), canonical `/portfolio` |
| `/portfolio/<slug>` | een projectpagina (route `portfolio.project`) |
| `/portfolio.php` | een permanente redirect (301) naar `/portfolio`, in dezelfde taal, querystring behouden; een POST wordt beantwoord waar hij heen ging (route `portfolio.index.file`, `App\Service\PortfolioUrls`) |

Het overzicht is de CMS-pagina met content key `portfolio`, en haar
`route_path` is sinds migratie `20260925170000` `/portfolio` in plaats van
`/portfolio.php`. Omdat alles wat die pagina adresseert haar `route_path` leest
— canonical, hreflang, sitemap, een menu- of footerlink, het Portfolio-niveau
in het kruimelpad van een projectpagina, *Terug naar portfolio* — verhuizen
die in één keer mee, zonder dat er ergens een link wordt herschreven. Een
getypt `/portfolio.php` in inhoud, een bladwijzer of een zoekresultaat komt via
de 301 in één stap aan. Alle drie de routes staan vóór de catch-all van
Pagina's 2.0, en `portfolio` is gereserveerd, dus `/portfolio` kan nooit als
pagina of als `{slug}` gelezen worden.

**Het `portfolio`-woord en `portfolio-2`.** `portfolio` is geen paginarij maar
een woord dat deze module reserveert (`reservedSlugs()`: de module-root, het
template `/portfolio.php` en de naamruimte `/portfolio/…`), ook als de module
uit staat.
Een pagina met de titel *Portfolio* kreeg daarom stil `/portfolio-2`. Sinds
Portfolio 2.0 noemt de weigering de eigenaar (`ReservedRoutes::moduleReserving()`),
en een nieuwe pagina waarvan het automatische adres moest uitwijken, zegt op
haar eigen scherm waarom.

**Het overzicht zolang de Portfolio-pagina leeg is.** Het overzicht
`/portfolio` is de CMS-pagina met content key `portfolio` zodra die iets
toont. Elke installatie heeft die pagina, als systeempagina van Portfolio
("Systeempagina's van modules"); een installatie die haar niet had, kreeg haar
leeg van `20260928150000`. Zolang ze leeg is, volgt Portfolio het
modulepatroon van `/blog` en van het ingebouwde overzicht van `shop.php`:
**`portfolio.php` rendert het eigen overzicht van de module** — kruimelpad
*Home / Portfolio*, de kop *Portfolio*, elk zichtbaar project in de galerij met
filterbalk en lightbox (`PortfolioGalleryContent::builtinOverviewGallery()`),
of een regel dat er nog geen projecten zijn. De metadata komen uit
`SeoMetadata` (titel *Portfolio — site*, canonical `/portfolio` in de gelezen
taal, een versie per actieve taal), de sitemap krijgt het overzicht van
Portfolio's eigen collector, en `PortfolioModule::routes()` biedt `/portfolio`
aan als bestemming voor menu, footer en kruimelpad.

| Situatie | `/portfolio` | Menu/footer | Sitemap |
|---|---|---|---|
| De pagina is leeg: door de migratie gemaakt, gepubliceerd, geen zichtbaar blok (elke verse installatie) | het eigen overzicht van de module | route *Portfolio* | Portfolio's collector |
| Geen pagina (een andere pagina hield het woord vast toen de migratie draaide) | het eigen overzicht van de module | route *Portfolio* | Portfolio's collector |
| De pagina heeft een zichtbaar blok, of de installatie had haar al, en ze is gepubliceerd | die pagina, met haar eigen blokken, titel en SEO | de pagina, als pagina | Core's paginacollector |
| De pagina is een concept | 404, de keuze van de redacteur | — | — |
| Portfolio uit | 404, ook `/portfolio.php` en `/portfolio/<slug>` | — | — |

**Alleen de migratie maakt de pagina, één keer**: niet het aanzetten van de
module (die heeft geen lifecycle, zie "Nog niet geïmplementeerd"), niet een
request en niet een tweede run. Daardoor kan er geen tweede overzicht
ontstaan, en wordt geen pagina op haar titel of slug als overzicht
aangenomen: alleen de
content key `portfolio` telt (`PortfolioUrls::overviewPage()`), en die kan een
redacteur niet kiezen. Een keuze "welke pagina is het Portfolio-overzicht"
(zoals `ShopOverview`) en een eigen titel of inleiding voor het ingebouwde
overzicht zijn aparte uitbreidingen.

**Projecten op een gewone pagina.** Portfolio brengt één eigen blok mee:
**Projecten** (`project_cards`, `src/Service/Blocks/ProjectCardsBlock.php`),
in de blokkenkiezer onder *Portfolio*. Het is dé manier om projecten in een
blok te tonen: de oude *Portfoliogalerij* (het galerijblok op portfolio-items)
is in v0.1.15 opgegaan in Projecten (`db/migrations/20261015110000`,
`CONTENT-BLOCKS.md`, "Galerijen opgeschoond"). Het is geen tweede galerij: het
bewaart zijn instellingen in dezelfde `item_galleries`-rij als het galerijblok,
leest en tekent via `ItemGalleryContent` en `partials/section-item-gallery.php`,
en krijgt zijn projecten en de link van elke kaart van de galerijbron
`portfolio`. Een kaart gedraagt zich dus precies volgens de regels
hierboven: de afbeelding zoomt, en "Bekijk project" volgt de drie regels.
Waarom het toch een eigen bloktype is, staat in
`docs/content-blocks/DECISIONS.md`.

- De editor (`admin/project-cards.php`, met het recht van zijn bloklijst
  zoals elke blokeditor) vraagt welke projecten (Projecten 2.0, `CONTENT-BLOCKS.md`):
  *Alle projecten*, *Eén categorie* of *Handmatige selectie*, in welke volgorde
  (de standaard Portfolio-volgorde, nieuwste, oudste, A–Z, Z–A of
  willekeurig), een maximum (3, 4, 6, 8, 12 of alles), filterknoppen per
  categorie, de achtergrond, een optionele titel en introtekst, en of het blok
  actief is.
- De bron, een collectie, de lightbox, een link voor kaarten zonder pagina en
  een slottekst of knop legt `api/admin/update-project-cards.php` vast via
  `ProjectCardsBlock::rowValues()`. Een project zonder bestemming is een kaart
  zonder knop, waarvan de afbeelding wel zoomt.
- Een `item_galleries`-rij wordt alleen bewerkt door de editor van het blok dat
  hem plaatste (`page_sections.section_type`): de galerij-editor weigert een
  Projecten-rij, en de Projecten-editor een galerij.
- De categorie, de gekozen projecten en de volgorde zijn instellingen van de
  galerijrij (`item_galleries.portfolio_category_id`, `item_sort`) en een
  relatie van de module (`item_gallery_portfolio_items`), bereikt via de
  galerijbron (`ItemGallerySources`: `category_choices`, `item_choices`,
  `selected_items`, `save_selection`). Het blok zelf heeft nog steeds geen
  query, kaart of link van zichzelf (`PortfolioModuleTest`). Meerdere
  Projecten-blokken op één pagina hebben elk hun eigen keuze.

Uit betekent: geen zijbalk-item; geen houdbare `portfolio.manage`, dus beide
schermen en elk schrijfendpoint weigeren op hun bestaande permissiecheck; een
404 op `/portfolio`, `/portfolio.php` en `/portfolio/<slug>` via `ModuleGuard`; geen
sitemapregels; geen gebruik in de Mediabibliotheek (de rijen blijven, en de
`RESTRICT`-sleutels houden een gebruikte afbeelding toch vast); geen blok Projecten in de kiezer, en een geplaatst blok
Projecten dat niets toont, zijn instellingen houdt en in de page builder *Blok
van een uitgeschakeld onderdeel* heet (zijn editor en endpoint antwoorden 404);
en een galerijblok met portfolio-items dat zijn instellingen houdt en niets
toont. De CMS-pagina achter `/portfolio` blijft bestaan en
bewerkbaar, maar geldt als geserveerd door een uitgeschakelde module
(`publicPaths()`), dus de sitemap, een menulink en een redirect laten hem los.
Een legacy-pagina waar een item naar linkt, hoort bij Core: die blijft
bereikbaar, en de koppeling blijft opgeslagen. De vijf tabellen, de categorieën en de
geüploade afbeeldingen blijven staan.

De bestanden staan nog waar ze stonden (`src/Service/Portfolio*.php`,
`src/Repository/Portfolio*Repository.php`): verhuisd is het eigenaarschap van
de koppelpunten, geen namespace.

### Meertaligheid (module `multilingual`)

Eén bijdrage: `publishesTranslations()`. Staat de module aan, dan publiceert
de website elke actieve taal van zijn talenregister; staat hij uit, alleen de
standaardtaal. `SiteLanguages::active()` is de enige plek die dat vraagt, op
capaciteit en niet op sleutel, en elke route, taalkeuze, editor en elk
endpoint vraagt `SiteLanguages` — dus niemand in Core noemt de module. Het
talenregister zelf (welke talen er zijn, welke de standaard is) is Core en
blijft beheerd met de module aan of uit. Uitzetten raakt geen taal en geen
vertaling (`MULTILINGUAL.md`, `docs/multilingual/WEBSITE-LANGUAGES.md`).

### Paginathema's (module `page_themes`)

Eigen tabel (`page_themes`) en één kolom op `pages` (`page_theme_id`, FK
RESTRICT), eigen repository (`PageThemeRepository`), eigen service
(`App\Service\PageThemes\*`), eigen schermen (`admin/page-themes.php`,
`admin/page-theme.php`, `admin/page-theme-preview.php`) en endpoints
(`api/admin/*-page-theme.php`) achter `page_themes.manage`. Vier bijdragen:
`pageAppearance()` (het uiterlijk van een pagina met een thema),
`pageSettingsSections()` (de keuze op het tabblad Pagina),
`switchableFromAppearance()` (de schakelaar op Vormgeving) en
`fontFamilyUsage()` (welke thema's een Font Library-familie gebruiken; twee
kolommen op `page_themes` met een RESTRICT-sleutel naar Core's
`font_families`). De vormgeving
zelf — de kleurregel, de afgeleide tinten, de lettertypes en het afdrukken
van het blok — blijft Core (`App\Service\Theme\*`); Core noemt de module
nergens (`Tests\Module\PageThemesModuleTest`). Uitzetten verwijdert geen
thema en geen keuze van een pagina. Zie `THEMING.md`, "Paginathema's".

De kleurenpaletten van de website (`color_palettes`, één actief) zijn Core,
geen deel van deze module, en ook niet van haar schakelaar: met de module uit
toont elke pagina het actieve palet, met de module aan houdt een pagina met
thema haar eigen kleuren, welk palet ook actief is. De module leest geen
palet en het palet kent geen thema (`THEMING.md`, "Kleurenpaletten").

### Formulieren

`Service\Forms\*` plus `FormRepository`, `FormSubmissionRepository`, de twee
formulierblokken en de adminschermen. **Core, en het blijft Core**: een CMS
zonder formulieren bestaat niet, en Forms weet niets van bestellingen,
afrekenen, producten, personalisatie of Mollie — het werkt identiek met de
Shop aan en uit. `Tests\Service\FormBoundaryTest` faalt zodra een
Core-Forms-bestand een Shop-klasse of Shop-tabel noemt. Zie `FORMS.md`.

### Contact/aanvragen

De historische offerteaanvragen met hun bijlagen, plus
`admin/contact-requests.php` om ze te lezen. Sinds Core Forms is dit alleen
nog archief: het `contact_form`-blok toont een gewone formulierdefinitie en
nieuwe inzendingen komen bij Formulieren binnen, en `api/contact.php` is een
compatibiliteitsschil (`FORMS.md`). Core.

### Analytics

`Service\Analytics\*` plus `PageViewRepository` en `AnalyticsDashboard`. Meet
alle pagina's, ook niet-shop. Geen module: een dwarsdoorsnijdende voorziening
van Core.

## Een module toevoegen

1. `src/Module/<Naam>Module.php`, extends `ModuleDefinition`. Implementeer
   `key()`, `label()` en alleen de bijdragen die je echt hebt.
2. Eén regel in `ModuleRegistry::MAP`.
3. Zet `ModuleGuard::requirePublicRoute()` / `::requireApi()` bovenaan de
   publieke routes en endpoints die van de module zijn. Adminschermen met een
   eigen permissie hebben niets nodig. Serveert een eigen template een
   CMS-pagina (zoals `/portfolio.php`), noem dat pad dan in `publicPaths()`,
   zodat die pagina meegaat als de module uit staat.
4. Documenteer de variabele in `.env.example`.
5. Testbestanden in de suite `modules` (`phpunit.xml`), zie `TESTING.md`.

## Afhankelijkheidsregel

> Een module mag leunen op **stabiele Core-contracten**. Ze mag niet in de
> interne werking van een andere module grijpen tenzij daar een expliciet
> publiek contract voor is.

In de praktijk:

- Lees andermans gegevens via de repository of `*Content`-klasse van dat
  domein, nooit met eigen SQL op hun tabellen.
- Moet een module weten of een andere draait, vraag het dan aan
  `ModuleRegistry::isEnabled()` — expliciet, op één regel. Zo doet het
  Shop-dashboardpaneel het voor de personalisatieregels in de aandachtslijst.
- Nieuwe functionaliteit blijft in het domein dat hem bezit. Moet Core iets
  weten van een module, voeg dan een bijdrage toe aan `ModuleDefinition` in
  plaats van een tweede `if` op een domeinnaam.
- Een `app_critical`-blok is het enige mechanisme waarmee een module afdwingt
  dat een pagina blijft bestaan — verzin daar geen tweede vorm voor. Vandaag
  gebruikt geen blok het: de Shop hangt niet meer van één pagina af.

## Nog niet geïmplementeerd

Bewust, en niet gepland tenzij er een concrete aanleiding komt:

- **Geen install/uninstall-UI.** Er is geen permanent "Modules
  beheren"-scherm. De installatiewizard vraagt het één keer bij het inrichten
  van een nieuwe site (`SETUP.md`); daarna is aan en uit een regel in `.env`,
  of een rij in `module_settings` die iemand met de hand zet. De twee
  uitzonderingen zijn Meertaligheid, die *Instellingen → Talen* aan- en
  uitzet, omdat dat scherm toch al over de talen van de site gaat, en
  Paginathema's, die *Vormgeving* aan- en uitzet
  (`switchableFromAppearance()`), omdat het een deel van de vormgeving is.
- **Geen plug-ins van derden**, geen marktplaats, geen runtime downloaden of
  laden van code.
- **Geen packages per module**, geen aparte repositories, geen Composer-
  pakketten, geen versieresolver of semver-compatibiliteitscontrole.
- **Geen lifecycle-API** (install/update/uninstall), geen event bus, geen
  service container.
- **Geen opruimen van de database** bij uitzetten.
- **Geen fysieke herindeling** naar `Core/` en `Modules/`. De bestanden van de
  Shop staan nog gewoon tussen die van Core; verhuisd is het *eigenaarschap*
  van de koppelpunten. Dat blijft uitgesteld tot het aantoonbaar iets oplevert.
