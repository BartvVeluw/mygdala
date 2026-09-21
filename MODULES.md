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
| Modules | `src/Module/ShopModule.php`, `src/Module/PersonalizationModule.php`, `src/Module/BlogModule.php`, `src/Module/PortfolioModule.php`, `src/Module/MultilingualModule.php` |

Het register:

```php
private const MAP = [
    'shop' => ShopModule::class,
    'personalization' => PersonalizationModule::class,
    'blog' => BlogModule::class,
    'portfolio' => PortfolioModule::class,
    'multilingual' => MultilingualModule::class,
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
*Site-instellingen → Talen* (`MULTILINGUAL.md`).

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
| Permissies | `permissionGroups()`, `permissionImplications()` | `App\Service\AdminPermissions` |
| Applicatieroutes | `routes()` | `App\Service\RouteRegistry` |
| Gereserveerde slugs | `reservedSlugs()` | `App\Service\ReservedRoutes` |
| Vaste publieke paden | `publicPaths()` | `App\Module\ModuleRegistry::disabledModuleForRoutePath()` |
| Sitemap | `sitemapCollectors()` | `App\Service\Sitemap` |
| Content-blokken | `blockDefinitions()` | `App\Service\Blocks\BlockDefinitions` |
| Galerijbronnen | `itemGallerySources()` | `App\Service\ItemGallerySources` |
| Site-shell-assets | `shellStyles()`, `shellScripts()` | `App\Service\PageAssets` |
| Header | `headerPartials()` | `partials/header.php` |
| Dashboard | `dashboardPanels()`, `dashboardCards()` | `admin/index.php` |
| Mediagebruik | `mediaUsageProviders()` | `App\Service\Media\MediaUsageRegistry` |
| Afhankelijkheden | `dependencies()` | `App\Module\ModuleRegistry` |
| Eén zin over zichzelf | `description()` | `admin/setup.php` (de installatiewizard) |
| Standaard aan of uit | `enabledByDefault()` | `App\Module\ModuleConfig` (stap 3 van de ketting) |
| Andere talen dan de standaardtaal publiceren | `publishesTranslations()` | `App\Service\Language\SiteLanguages::active()`, via `ModuleRegistry::publishesTranslations()` |

Drie van die lijsten komen ergens in het midden van een bestaande, bewust
geordende lijst terecht (de zijbalk, het permissieformulier, de routekiezer).
Die dragen daarom een `order`-getal, en Core sorteert zijn eigen regels en die
van de modules samen. Dat is het enige ordeningsmechanisme; niets hangt af van
de volgorde waarin modules geregistreerd staan.

Een galerijbron draagt ook een `order`, al komen daar alle bronnen uit
modules: de laagste beschikbare bron is de bron waarmee een nieuw galerijblok
begint. Portfolio-items (10) staan vóór een collectie van de Shop (20).

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

Een `page_sections`-rij die naar `product_grid` wijst blijft staan als de Shop
uit gaat. Het bloktype is dan niet geregistreerd, dus:

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
  bij Portfolio.)
- **Instellingen/navigatie** — `NavigationService`, `FooterService`,
  `LinkResolver`, `RouteRegistry`.
- **Vormgeving** — `Service\Theme\*` (kleuren, lettertypecombinatie,
  knopvorm), `Branding`, `admin/theme.php`. Core,
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
  `ProductImageRepository`, `VariantImageRepository`, `admin/products.php`,
  `admin/product-form.php`.
- Collecties — `CollectionService`, `CollectionContent`,
  `CollectionRepository`, `collectie.php`, `CollectionGalleryItems`.
- Winkelpagina — `shop.php`. Heeft de installatie een CMS-pagina met
  `content_key = shop`, dan rendert hij die; anders zijn eigen
  productoverzicht, een kop plus het blok `product_grid`. Een verse
  installatie krijgt die pagina niet (`INSTALL-BOOTSTRAP.md`), en dan komt de
  sitemapregel van `ShopModule::sitemapCollectors()` (`storefront`).
- Gerelateerde producten — `RelatedProductsContent`,
  `admin/related-products.php`, `partials/related-products.php`.
- Winkelwagen — volledig client-side (`vvl-cart` in `localStorage`,
  `assets/js/shop/cart.js`), `cart.php`, `partials/header-cart.php`.
- Afrekenen — `checkout.php`, `api/checkout.php`, `Service\Address\*`.
- Bestellingen en betalingen — `OrderRepository`, `OrderPaymentSync`,
  `MollieClientFactory`, `MolliePaymentData`, `api/mollie-webhook.php`,
  `OrderConfirmationService`, `OrderCsvExport`, `admin/orders.php`. Een
  bestelnummer wordt één keer gemaakt, bij het aanmaken van de bestelling, en
  opgeslagen in `orders.order_number`; mail, Mollie, beheer, export en factuur
  lezen het via `OrderRepository::orderNumber()`.
- Facturen — `InvoiceService`, `PdfInvoiceRenderer`, `InvoiceStorage`.
- Shop-instellingen — `ShopSettings`, `admin/shop-settings.php`,
  `api/admin/update-shop-settings.php`: de bedrijfsgegevens en vaste teksten
  op facturen, het bestelnummerprefix en de tekst van de bestelbevestiging,
  met "Herstel standaardtekst" en uitleg bij de invulvelden. Dit waren de
  tabbladen Facturen en E-mails van Site-instellingen; het zijn dezelfde
  sleutels in `site_settings`, dus uit- en aanzetten raakt ze niet. Adres,
  KVK-nummer, e-mailadres en telefoon staan niet hier maar op
  Site-instellingen, omdat de footer en de mailvoetregel ze ook lezen. Het
  scherm vraagt `settings.manage`, dezelfde permissie als die tabbladen. Dat
  is een Core-permissie die met de Shop uit gewoon houdbaar blijft, dus
  scherm en endpoint hebben als enige Shop-adminbestanden wél een
  `ModuleGuard`.
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

Facturen, retourverzoeken en verzending zijn deelgebieden *binnen* de Shop.
Ze zijn niet zelfstandig bruikbaar (een factuur hoort bij een order, een
herroeping ook, een tarief bij een winkelmandje), dus geen aparte modules.

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
`api/admin/*portfolio*.php` en `move-featured-gallery-item.php`), eigen
categorietaxonomie, eigen publieke routes (`/portfolio.php` en de oude
projectadressen `/portfolio/<slug>` via `portfolio-detail.php`), de
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
| `portfolio_item_translations` | `portfolio_item_id` | `title`, `subtitle`, `alt`, `intro` (rich), `description` (rich) |
| `portfolio_item_image_translations` | `portfolio_item_image_id` | `alt` |

Wat taalneutraal blijft: de slug van een categorie en van een item, de
afbeelding en haar thumbnail, de gekoppelde pagina, de categorieën van een
item, `is_active`, `is_featured` en elke sorteervolgorde. De slug van een
nieuwe categorie wordt eenmalig uit de naam in de **standaardtaal** gemaakt en
daarna nooit hernoemd, dus een vertaling verplaatst nooit een adres.

Beide schermen bewerken **één** taal tegelijk, via `admin/_localized_fields.php`
zoals elk ander omgezet scherm; een nieuwe categorie en een nieuw item worden
altijd in de standaardtaal geschreven. **Portfolio uitzetten verwijdert geen
woord**, en opnieuw aanzetten toont precies dezelfde tekst: de
vertaaltabellen bestaan los van de module, en de migraties vragen nooit of hij
aan staat. Zie `docs/multilingual/ARCHITECTURE.md`.

**Een projectpagina is een gewone CMS-pagina.** Een portfolio-item heeft
hoogstens één koppeling naar een pagina: `portfolio_gallery_items.page_id`,
nullable, met een foreign key naar `pages.id` die `NULL` wordt als de pagina
verdwijnt (`ON DELETE SET NULL`, migratie `20260914200000`). De pagina beheert
zelf haar titel, slug, SEO, canonical, blokken, CTA en publicatiestatus;
Portfolio slaat alleen het id op, nooit een adres.

- In de editor is de projectpagina één keuze: *Geen gekoppelde pagina* of een
  gewone pagina, dus een pagina zonder eigen template en zonder vaste route.
  Een concept mag gekozen worden. *Nieuwe pagina maken* opent het gewone
  scherm *Nieuwe pagina* in een nieuw tabblad; er is geen koppeling terug, de
  redacteur kiest de nieuwe pagina daarna zelf.
- De galerijkaart linkt, in deze volgorde:
  1. naar het huidige adres van de gekoppelde, gepubliceerde pagina, per
     verzoek opgelost, dus een hernoemde pagina gaat vanzelf mee;
  2. anders, zolang het item nog een oude projectpagina heeft
     (`has_detail_page` met een slug), tijdelijk naar `/portfolio/<slug>`,
     zodat een bestaande site na de upgrade blijft werken tot elk oud project
     een gewone pagina heeft;
  3. anders nergens heen: geen link en geen pijl.

  Een concept of een verwijderde pagina telt niet als koppeling. De
  `fallback_link_url` van het galerijblok geldt nooit voor een portfolio-item
  (`follows_fallback_link` is `false`); voor de kaarten van andere bronnen
  werkt hij zoals altijd.
- Een pagina verwijderen laat het item staan, zonder koppeling. Een item
  verwijderen of Portfolio uitzetten raakt de pagina nooit.

**De oude projectpagina blijft, voor haar adres.** Vóór de koppeling had
Portfolio een eigen projectpagina: `has_detail_page`, de slug, introtekst,
beschrijving en `portfolio_item_images`. Niets bewerkt die nog, en niets
ervan is verwijderd. Haar wóórden zijn wel verhuisd — sinds fase 5 van
Multilingual 2.0 staan `intro`, `description` en de alt-teksten van haar foto's
per websitetaal in `portfolio_item_translations` en
`portfolio_item_image_translations`, read-only zoals ze waren. Tijdens de
overgang is `/portfolio/<slug>` een compatibiliteitsroute, en
`portfolio-detail.php` beantwoordt zo'n adres zo:

| Situatie | Antwoord |
|---|---|
| Het item linkt naar een gepubliceerde pagina | tijdelijke redirect (302) naar de canonical van die pagina, per verzoek bepaald op `page_id` |
| Geen koppeling, of een concept | de oude projectpagina zoals altijd, of de 404 die er al was |
| Portfolio uit | 404 via `ModuleGuard`, ook met een koppeling |

Tijdelijk en niet permanent: de koppeling achter het adres kan nog veranderen
of verdwijnen, en een 301 zou een browser het vorige doel laten onthouden.

Dat is bewust geen rij in de Redirect Manager (`REDIRECTS.md`): `/portfolio/`
is daar een gereserveerde naamruimte, Apache stuurt zo'n adres naar
`portfolio-detail.php` zodat `404.php` het nooit ziet, en een opgeslagen
bestemming zou bij elke hernoeming, ontkoppeling of depublicatie moeten
meebewegen. De sitemap van Portfolio noemt alleen oude adressen die nog zelf
een pagina tonen; een gekoppelde pagina staat er één keer in, via de
paginacollector van Core. In het overzicht staat bij een item met alleen nog
een oude projectpagina de badge *Oude projectpagina*.

**Projecten op een gewone pagina.** Portfolio brengt één eigen blok mee:
**Projecten** (`project_cards`, `src/Service/Blocks/ProjectCardsBlock.php`),
in de blokkenkiezer onder *Beeld & media*. Het is geen tweede galerij: het
bewaart zijn instellingen in dezelfde `item_galleries`-rij als het galerijblok,
leest en tekent via `ItemGalleryContent` en `partials/section-item-gallery.php`,
en krijgt zijn projecten en de link van elke kaart van de galerijbron
`portfolio`. Een kaart linkt dus precies volgens de drie regels hierboven.
Waarom het toch een eigen bloktype is, staat in
`docs/content-blocks/DECISIONS.md`.

- De editor (`admin/project-cards.php`, met `pages.manage` zoals elke
  blokeditor) vraagt alleen welke projecten (alle zichtbare, of die met *Toon
  op homepage*), een maximum, filterknoppen per categorie, de achtergrond, een
  optionele titel en introtekst, en of het blok actief is. De volgorde is die
  van Portfolio.
- De bron, een collectie, de lightbox, een link voor kaarten zonder pagina en
  een slottekst of knop legt `api/admin/update-project-cards.php` vast via
  `ProjectCardsBlock::rowValues()`. Een project zonder bestemming blijft een
  kaart die nergens heen gaat.
- Een `item_galleries`-rij wordt alleen bewerkt door de editor van het blok dat
  hem plaatste (`page_sections.section_type`): de galerij-editor weigert een
  Projecten-rij, en de Projecten-editor een galerij.
- Op één categorie selecteren, zelf projecten aanwijzen of een eigen volgorde
  per blok kan nog niet: de galerijcontracten hebben daar geen instelling voor.

Uit betekent: geen zijbalk-item; geen houdbare `portfolio.manage`, dus beide
schermen en elk schrijfendpoint weigeren op hun bestaande permissiecheck; een
404 op `/portfolio.php` en `/portfolio/<slug>` via `ModuleGuard`; geen
sitemapregels; geen blok Projecten in de kiezer, en een geplaatst blok
Projecten dat niets toont, zijn instellingen houdt en in de page builder *Blok
van een uitgeschakeld onderdeel* heet (zijn editor en endpoint antwoorden 404);
en een galerijblok met portfolio-items dat zijn instellingen houdt en niets
toont. De CMS-pagina achter `/portfolio.php` blijft bestaan en
bewerkbaar, maar geldt als geserveerd door een uitgeschakelde module
(`publicPaths()`), dus de sitemap, een menulink en een redirect laten hem los.
Een pagina waar een item naar linkt, hoort bij Core: die blijft bereikbaar, en
de koppeling blijft opgeslagen. De vijf tabellen, de categorieën en de
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
  dat een pagina blijft bestaan — verzin daar geen tweede vorm voor.

## Nog niet geïmplementeerd

Bewust, en niet gepland tenzij er een concrete aanleiding komt:

- **Geen install/uninstall-UI.** Er is geen permanent "Modules
  beheren"-scherm. De installatiewizard vraagt het één keer bij het inrichten
  van een nieuwe site (`SETUP.md`); daarna is aan en uit een regel in `.env`,
  of een rij in `module_settings` die iemand met de hand zet. De enige
  uitzondering is Meertaligheid, die *Site-instellingen → Talen* aan- en
  uitzet, omdat dat scherm toch al over de talen van de site gaat.
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
