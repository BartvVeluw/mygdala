# Project-kaart

**Begin hier.** Dit bestand vertelt wat de applicatie is, waar iets woont, en
welk document je daarna leest.

## Welke repository is dit

**Mygdala is de canonieke bron van dit CMS.** Alle generieke
CMS-ontwikkeling gebeurt hier: nieuwe functionaliteit wordt in Mygdala
gebouwd, getest en vastgelegd. **Van Veluw Laserdesign is een downstream
officiële site** die dit CMS draait — een stabiele productie-installatie en
referentie, geen ontwikkelomgeving. Een generieke CMS-wijziging is eerst in
Mygdala bewezen en gaat daarna pas naar Van Veluw.

`MAIN.MD` zit niet in deze repository: dat is de projecthistorie van Van
Veluw Laserdesign, en `App\Install\FreshSiteCopyPolicy` rekent die tot
site-geschiedenis en niet tot de applicatie. Waar een document hieronder naar
`MAIN.MD` verwijst, staat die achtergrond in de Van Veluw-repository.

## Wat dit is

Een eigen PHP/MySQL-applicatie: één installatie die tegelijk CMS, webshop en
adminpaneel is.

- **Geen framework en geen buildstap.** Losse PHP-templates in de projectroot,
  `App\`-klassen onder `src/` (PSR-4, Composer), platte CSS/JS die de browser
  rechtstreeks laadt.
- **CMS met herbruikbare content-blokken.** Elke pagina is één geordende lijst
  blok-instanties; beheerders bouwen pagina's in het adminpaneel.
- **Webshop** met producten, varianten, collecties, winkelwagen, afrekenen,
  Mollie-betalingen, facturen en verzendtarieven. Sinds stap 5 een
  **uitschakelbare first-party module**: dezelfde codebase bedient ook een
  site zonder webshop (`MODULES.md`). Voor Van Veluw Laserdesign staat hij
  gewoon aan, en dat is de standaard.
- **Personalisatie**: de klant ontwerpt tekst/afbeeldingen op een product vóór
  het bestellen.
- **Meertalig**: de talen van de website zijn rijen in `site_languages`,
  met één standaardtaal; de module *Meertaligheid* beslist of de andere talen
  gepubliceerd worden. Elke taal heeft eigen URL's (`/en/…`) en de server
  drukt per antwoord één taal af. Daarnaast twee voorkeuren per beheerder, die
  niets met elkaar of met de bezoeker te maken hebben: in welke taal het CMS
  aan hem wordt getóónd, en welke taalversie van de inhoud hij bewérkt. Een
  lege vertaling betekent voor de bezoeker "gelijk aan de standaardtaal" en
  voor de redacteur een leeg veld (`MULTILINGUAL.md`).
- **Hosting is de belangrijkste beperking**: Vimexx gedeelde hosting, PHP +
  MySQL, geen Node.js. De projectroot is de siteroot, dus alles wat niet
  publiek mag zijn staat buiten de webroot of wordt door `.htaccess` geblokt.
  Migraties zijn forward-only en MySQL-compatibel. Lokaal draait dezelfde stack
  in Docker (`docker-compose.yml`).

## Repository-kaart

| Pad | Wat er staat |
|---|---|
| `index.php`, `shop.php`, `diensten.php`, `portfolio.php`, `over-mij.php`, `contact.php` | De zes pagina's met een eigen template en vaste URL |
| `pagina.php` | Generiek template voor élke andere CMS-pagina (`/<slug>`) |
| `product.php`, `collectie.php`, `portfolio-detail.php`, `cart.php`, `checkout.php`, `bestelling-status.php`, `personaliseren.php` | Functionele routes (geen CMS-pagina's) |
| `blog.php`, `blog-post.php`, `blog-feed.php` | De publieke Blog: het overzicht met zijn twee archieven, één bericht, en de RSS-feed (`BLOG.md`) |
| `partials/section-*.php` | Eén frontend-partial per bloktype |
| `partials/` (overig) | Header, footer, seo-head (dé SEO-`<head>`), page-head en shop-seo-head (adapters ernaartoe), branding-head (theme-color + favicon), 404, cookiebanner |
| `src/Install/` | `InstallState` kent het verschil tussen een database die vanaf nul wordt opgebouwd en een die al inhoud draagt (`INSTALL-BOOTSTRAP.md`); `SetupState` en `SetupWizard` zijn de installatiewizard die daarop bouwt; `FreshSiteCopyPolicy` is de grens tussen applicatie en site waar `scripts/create_fresh_site_copy.php` op loopt (`SETUP.md`) |
| `src/Module/` | Het moduleregister en de first-party modules (`ShopModule`, `PersonalizationModule`, `BlogModule`) |
| `src/Service/` | Applicatielogica; `*Content`-klassen lezen blokinhoud |
| `src/Service/Blocks/` | Eén blokdefinitie per bloktype, plus `BlockDefinitions` — dé registratielijst. `BlockCategories` en `BlockPreview` zijn de gesloten lijstjes waarmee een blok zichzelf in de blokkenkiezer presenteert |
| `src/Service/Language/` | Meertaligheid: het gesloten talenregister, het talenregister van de website (`SiteLanguages`, tabel `site_languages`) met zijn standaardtaal, de talen van de website, de CMS-taal en de bewerktaal per beheerder, de terugvalregel en de CMS-tekstcatalogi (`MULTILINGUAL.md`) |
| `src/Service/Translation/` | Automatisch vertalen: het providercontract, DeepL, de dienst die editors aanroepen en de vertaalstatus (`MULTILINGUAL.md`) |
| `src/Service/Theme/` | De vormgeving van de website: instellingen, kleurenrekenwerk, lettertypecombinaties en het CSS-overrideblok. Hoe het CMS zelf eruitziet is `Service\AdminTheme` |
| `src/Service/Redirects/` | De Redirect Manager: padnormalisatie, bestemmingen, opslaanregels, de opzoeking bij een verzoek |
| `src/Service/Media/` | De Mediabibliotheek: het media-item, de uploadpijplijn, de kiezerlogica en wie welk item gebruikt |
| `src/Service/Forms/` | Core Forms: de veldtypes, het leesmodel, validatie, spam-afweer, verwerking en veilig verwijderen |
| `src/Service/Blog/` | De Blog-module: het leesmodel, de statussen en hun klok, slugs en URL's, de metadata, de RSS-feed en het mediagebruik (`BLOG.md`) |
| `src/Service/Breadcrumbs/` | Het kruimelpad: één niveau als waarde-object, het hele pad, en de keuze per pagina. De markup staat in `partials/breadcrumb.php` (`HEADER-FOOTER.md`) |
| `src/Service/PageTemplates/` | Paginasjablonen: het contract, dé registratielijst, één klasse per sjabloon en de installer die er een pagina mee opbouwt |
| `src/Repository/` | Alle SQL, één klasse per tabelgroep, basisklasse `Repository.php` |
| `src/Mail/` | Opbouw van transactionele e-mails |
| `src/Database.php` | De gedeelde PDO-verbinding |
| `admin/*.php` | Adminschermen (login, dashboard, page builder, blok-editors, catalogus). `content-blocks.php` is de Contentblokken-bibliotheek: uitleg per blok en een voorbeeld van het echte blok (`block-preview.php`), geen bewerkscherm |
| `admin/_*.php` | Gedeelde admin-includes (`_header.php` = de shell, `_media_picker.php` = de mediakiezer, `_block_picker.php` + `_block_visual.php` = de blokkenkiezer en zijn schetsen, `_save_bar.php` = de opslagbalk, `_admin_tabs.php` = tabbladen over een lang scherm, `_admin_collapse.php` = inklapbare rijen, `_dashboard_shop.php` = het dashboardpaneel van de Shop) |
| `admin/assets/` | `admin.css`, `admin.js` en per-domein admin-JS |
| `api/*.php` | Publieke endpoints (checkout, contact, Mollie-webhook, personalisatie) |
| `api/admin/*.php` | Admin-schrijfendpoints, ±174 stuks, één per handeling |
| `assets/css/core.css`, `assets/js/core.js` | De frontend die élke pagina nodig heeft: tokens, basis, header/footer, taalwissel, reveal |
| `assets/css/blocks/`, `assets/js/blocks/` | Per bloktype, alleen geladen op een pagina waar dat blok staat |
| `assets/css/blog/` | De Blog-frontend, alleen geladen op een Blog-route |
| `assets/css/shop/`, `assets/js/shop/` | Shop-frontend: `cart.*` (de mini-winkelwagen in de gedeelde header, dus overal), `shop.*` (catalogus, product, afrekenen, bestelstatus) en `personalization.css` |
| `assets/js/portfolio-detail.js`, `assets/js/personalization.js`, `assets/js/cookie-consent.js` | Frontend van één route/onderdeel |
| `assets/images/`, `assets/fonts/`, `assets/videos/` | Publieke media (uploads incl.) |
| `assets/media/` | Wat de Mediabibliotheek zelf uploadt, plus de thumbnails die zij genereert. Oudere beelden zijn *op hun plek* overgenomen en staan dus nog in `assets/images/` (`MEDIA.md`) |
| `../storage/` | Niet-publieke uploads (contactbijlagen, personalisatiebestanden) — standaard één map **boven** de projectroot, want de projectroot is de siteroot |
| `db/migrations/` | Phinx-migraties, ±110, `YYYYMMDDHHMMSS_naam.php`. `bootstrap_a_generic_fresh_install` is dé plek waar staat wat een nieuwe installatie krijgt (`INSTALL-BOOTSTRAP.md`) |
| `tests/` | PHPUnit; zie `TESTING.md` |
| `docs/content-blocks/` | Architectuur en beslissingen achter de content-blokken |
| `sitemap.php`, `robots.php` | De twee gegenereerde crawlerdocumenten, geserveerd op `/sitemap.xml` en `/robots.txt` via een rewrite (`SEO.md`) |
| `404.php` | Apache's `ErrorDocument`: het enige punt waar een URL die Apache niet kon plaatsen PHP bereikt — eerst de Redirect Manager, anders de eigen 404-pagina (`REDIRECTS.md`) |
| `.htaccess` | Blokkades, de twee crawlerdocumenten en één regel: een bestaand bestand is van Apache, al het andere gaat naar `dispatcher.php` |
| `dispatcher.php` | De ene deur voor elke publieke URL die geen bestand is: taalsegment afpellen, normaliseren, route zoeken in `App\Service\Routing\RouteTable`, het bestaande template `require`n. Rendert zelf niets (`docs/multilingual/ROUTING.md`) |

## Domeinen

Dit zijn de praktische domeinen zoals de code er vandaag uitziet. Welke
daarvan een echte, uitschakelbare module zijn — Shop en Personalisatie — en
welke Core, staat in `MODULES.md`; dat document gaat over de grenzen zelf.

| Domein | Verantwoordelijk voor | Belangrijkste paden |
|---|---|---|
| **CMS/pagina's** | `pages`-rijen, slug/status/SEO, publicatie, bescherming, 404 | `PageContent`, `PageService`, `PageRepository`, `admin/pages.php`, `admin/page.php`, `pagina.php` |
| **Installatie-bootstrap** | Het verschil tussen een verse en een bestaande database, en wat Core en de modules op een verse aanmaken | `Install\InstallState`, `db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php` — zie `INSTALL-BOOTSTRAP.md` |
| **Installatiewizard** | Of een verse installatie nog ingericht moet worden, wat er dan gevraagd wordt, en het één keer atomair wegschrijven daarvan | `Install\SetupState`, `Install\SetupWizard`, `admin/setup.php`, `api/admin/complete-setup.php` — zie `SETUP.md` |
| **Paginasjablonen** | Het startpunt dat een redacteur kiest bij *Nieuwe pagina*: welke blokken een verse pagina meekrijgt. Alleen op het moment van aanmaken — daarna is het een gewone pagina | `Service\PageTemplates\*`, `admin/page-new.php`, `api/admin/create-page.php` — zie `PAGE-TEMPLATES.md` |
| **Content-blokken** | Bloktypes, instanties, volgorde, render | `src/Service/Blocks/` (definities + registratie), `SectionRegistry`, `PageSectionRepository`, `src/Service/*Content.php`, `partials/section-*.php`, `admin/<type>.php`, `assets/{css,js}/blocks/` |
| **Paginabouwer (redacteurs-UX)** | Hoe een redacteur een blok kiest, wat het CMS over een blok vertelt, of er nog iets openstaat, en hoe een lang bewerkscherm bevaarbaar blijft | `BlockDefinition::description()/category()/icon()/preview()/sampleContent()`, `BlockCategories`, `BlockPreview`, `BlockSamples`, `admin/_block_picker.php`, `admin/_block_library.php`, `admin/block-preview.php`, `admin/_block_visual.php`, `admin/_save_bar.php`, `admin/_admin_tabs.php`, `admin/_admin_collapse.php`, `admin/content-blocks.php` — zie `PAGE-EDITOR.md` |
| **Admin-UI-bouwstenen** | Uitleg bij velden, de help-knop in de schil, de infobalk, en zoekveld, select, checkbox, switch, bestandskiezer en knoppen in de CMS-stijl | `admin/_admin_ui.php`, `admin/assets/admin-ui.js`, sectie *ADMIN UI PRIMITIVES* in `admin/assets/admin.css` — zie `ADMIN-UI.md` |
| **Auth/rechten** | Adminlogin, sessie, permissies, CSRF | `AdminAuth`, `AdminPermissions`, `AdminUserService`, `Csrf`, `admin/login.php`, `admin/users.php` |
| **Instellingen/navigatie** | Site-instellingen, menu, footer, de headerknoppen, de slotregel, social profielen, linkresolutie | `SiteSettings`, `NavigationService`, `NavigationPresentation`, `FooterService`, `FooterRepository`, `FooterSocialLinkRepository`, `SocialProfiles`, `LinkResolver`, `RouteRegistry`, `admin/settings.php`, `admin/navigation.php`, `admin/footer.php` (één scherm voor de hele footer; `admin/header-footer.php` verwijst ernaar door) |
| **Meertaligheid** | De drie onafhankelijke taalstaten — CMS-taal, bewerktaal, bezoekerstaal — de terugvalregel, de taalvelden in elke editor en automatisch vertalen | `Service\Language\*` (waaronder `SiteLanguages`), `SiteLanguageRepository`, `Service\Routing\*`, `Service\Translation\*`, `Module\MultilingualModule`, `admin/_localized_fields.php`, `admin/_header.php`, `admin/account.php`, het tabblad *Talen* in `admin/settings.php` — zie `MULTILINGUAL.md` |
| **Vormgeving/branding** | Kleuren, lettertypecombinatie, knopvorm; logo, tweede logo, favicon, deel-afbeelding | `Service\Theme\*`, `Branding`, `partials/head-branding.php`, `admin/theme.php` |
| **Dashboard-thema** | Hoe het adminpaneel er voor de redactie uitziet: vier gesloten skins over één stylesheet en één set schermen | `Service\AdminTheme`, `AdminSettingRepository`, `admin/assets/admin.css`, de kaart *Dashboard uiterlijk* op `admin/settings.php` |
| **Mediabibliotheek** | Herbruikbaar publiek sitebeeld: identiteit, alt-tekst, hergebruik, gebruiksoverzicht, veilig verwijderen | `Service\Media\*`, `MediaRepository`, `admin/media.php`, `admin/_media_picker.php` — zie `MEDIA.md` |
| **Media/uploads (overig)** | Beeld- en videoverwerking en -opslag die nog bij hun eigen feature horen | `SectionImageUploader`, `SectionVideoUploader`, `ProductImageUploader`, `ImageOptimizer` (`PortfolioImageProcessor` hoort bij Portfolio) |
| **SEO/sitemap** | Effectieve metadata, canonicals, OG/Twitter, robots-tag, sitemap.xml, robots.txt | `SeoMetadata`, `SeoDefaults`, `PageSeo`, `Seo`, `ProductSeo`, `AppUrl`, `AppEnvironment`, `Sitemap`, `Robots`, `partials/seo-head.php`, `sitemap.php`, `robots.php` — zie `SEO.md` |
| **Redirects** | Verhuisde publieke URL's: opslag, normalisatie, conflicten, automatische slugredirects | `Service\Redirects\*`, `RedirectRepository`, `404.php`, `admin/redirects.php` — zie `REDIRECTS.md` |
| **Shop/catalogus** | Producten, varianten, opties, afbeeldingen, collecties, gerelateerde producten | `ProductRepository`, `ProductVariantRepository`, `ProductOptionRepository`, `CollectionService`, `RelatedProductsContent`, `admin/products.php`, `admin/collections.php` |
| **Winkelwagen/afrekenen** | Winkelwagen (client-side), afrekenformulier, adres, verzendkeuze | `assets/js/shop/cart.js` (`vvl-cart` in `localStorage`), `assets/js/shop/shop.js`, `cart.php`, `checkout.php`, `api/checkout.php`, `Service\Address\*` |
| **Bestellingen/betalingen** | Orders, Mollie, statussen, bevestiging, facturen, herroeping | `OrderRepository`, `OrderPaymentSync`, `MollieClientFactory`, `MolliePaymentData`, `OrderConfirmationService`, `InvoiceService`, `PdfInvoiceRenderer`, `api/mollie-webhook.php`, `admin/orders.php` |
| **Verzending** | Zones, tarieven, berekening, PostNL-synchronisatie | `Service\Shipping\*`, `ShippingRateRepository`, `ShippingZoneRepository`, `api/shipping-quote.php`, `admin/shipping.php` |
| **Personalisatie** | Views/zones per product, fonts, uploads, preview, ordersnapshot | `Service\Personalization\*`, `ProductPersonalizationRepository`, `OrderItemPersonalizationRepository`, `personaliseren.php`, `admin/personalization*.php` |
| **Blog** | Blogberichten, categorieën, tags, publicatie en inplannen, het publieke overzicht en de RSS-feed. Een uitschakelbare module die standaard **uit** staat | `Module\BlogModule`, `Service\Blog\*`, `BlogPostRepository`, `BlogCategoryRepository`, `BlogTagRepository`, `admin/blog*.php`, `blog.php`, `blog-post.php` — zie `BLOG.md` |
| **Portfolio** | Portfolio-items, categorieën, afbeeldingen, de koppeling met een gewone CMS-pagina als projectpagina, het blok Projecten en de oude projectadressen. Een uitschakelbare module die op een nieuwe installatie standaard **uit** staat | `Module\PortfolioModule`, `PortfolioGalleryContent`, `PortfolioGalleryRepository`, `PortfolioCategoryRepository`, `PortfolioItemImageRepository`, `PortfolioImageProcessor`, `Blocks\ProjectCardsBlock`, `portfolio.php`, `portfolio-detail.php`, `admin/portfolio.php`, `admin/portfolio-item.php`, `admin/project-cards.php` — zie `MODULES.md` |
| **Formulieren** | Formulierdefinities, velden, publieke verwerking, meldingen, bewaarde inzendingen | `Service\Forms\*`, `FormRepository`, `FormSubmissionRepository`, `partials/form.php`, `api/form-submit.php`, `admin/forms.php`, `admin/form-submissions.php` — zie `FORMS.md` |
| **Contact/aanvragen** | Historische offerteaanvragen met bijlagen, herroepingsverzoeken, rate limiting | `ContactRequestRepository`, `ContactAttachmentStorage`, `ContactRateLimiter`, `TurnstileVerifier`, `admin/contact-requests.php` — nieuwe inzendingen lopen sinds Core Forms via `FORMS.md` |
| **Analytics** | Pageviews, botfilter, dashboardcijfers | `Service\Analytics\*`, `PageViewRepository`, `DashboardMetrics`, `DashboardAttention`, `admin/index.php` |

## Levenscyclus

**Een CMS-pagina renderen**

```text
.htaccess  →  dispatcher.php (taal + route)  →  paginatemplate (pagina.php);
              een eigen template als /shop.php serveert Apache rechtstreeks
           →  ModuleGuard (alleen op een route van een module: uit = 404)
           →  PageContent::forContentKey()        pagina bestaat + gepubliceerd?
                                                   zo nee: RedirectGate (REDIRECTS.md), dan 404
           →  SectionRegistry::collectPageAssets()  welke CSS/JS de blokken nodig hebben
           →  partials/page-head.php              vraagt PageSeo om de metadata
           →  partials/seo-head.php                titel, description, canonical,
                                                   robots, OG, Twitter, JSON-LD
           →  partials/page-assets.php            <link>-tags in de <head>
           →  SectionRegistry::renderPage()       de blokkenlijst, op volgorde
           →  per blok: <Type>Content::forSection()  →  partials/section-<type>.php
           →  partials/page-scripts.php          <script>-tags vóór </body>
```

`renderPage()` slaat blokken over die op `page_sections.is_active = 0` staan;
`render()` slaat daarnaast blokken over waarvan de eigen editor `is_active = 0`
zegt (`STATE_HIDDEN`). Zie `CONTENT-BLOCKS.md`.

**Een blok bewerken in het admin**

```text
admin/page.php (page builder)      lijst, volgorde, toevoegen, verbergen, verwijderen
   →  "+ Contentblok toevoegen"        opent de blokkenkiezer (PAGE-EDITOR.md);
                                       één klik op een kaart POST't meteen
   →  api/admin/add-page-section.php | reorder-page-sections.php
      | toggle-page-section.php | delete-page-section.php
   →  SectionRegistry::create() / ::delete()      maakt of ruimt de inhoudsrij op
   →  editorlink per instantie: admin/<type>.php?section=<page_slug>:<section_key>
      →  api/admin/update-<type>.php              login + permissie + CSRF, dan repository
      →  <Type>Content::clearCache()              de per-request cache
```

**Afrekenen**

```text
product.php / personaliseren.php  →  winkelwagen in localStorage (assets/js/shop/cart.js)
checkout.php                      →  api/shipping-quote.php  (verzendkosten)
                                  →  api/checkout.php        order + Mollie-betaling
Mollie  →  api/mollie-webhook.php →  OrderPaymentSync  →  OrderConfirmationService
                                                        →  InvoiceService (PDF + mail)
bestelling-status.php             toont de status aan de klant
```

## Frontend-assets

Sinds stap 4 heeft élk frontendbestand één eigenaar, en vraagt die eigenaar
zelf om zijn bestanden. Er is geen globale `style.css`/`main.js` meer.

| Laag | Bestanden | Geladen |
|---|---|---|
| **Lettertype** | één stylesheet-URL van de gekozen combinatie | Altijd, vóór Core — behalve bij de combinatie `system`, die niets downloadt |
| **Core** | `assets/css/core.css`, `assets/js/core.js` | Altijd, op elke publieke pagina |
| **Blok** | `assets/css/blocks/<type>.css`, `assets/js/blocks/<type>.js` | Alleen op een pagina waar dat blok staat |
| **Shop** | `assets/{css,js}/shop/shop.*` | Shop-routes en de Shop-blokken (`product_grid`, `shop_collections`) |
| **Shop (mini-winkelwagen)** | `assets/{css,js}/shop/cart.*` | Overal zolang de Shop aan staat, want de gedeelde header rendert de mini-winkelwagen. Gevraagd door `ShopModule::shellStyles()`, niet door Core |
| **Blog** | `assets/css/blog/blog.css` | Alleen `/blog` en de berichten/archieven eronder; gevraagd door die routes, nooit door de schil |
| **Route** | `assets/js/portfolio-detail.js`, `assets/js/personalization.js` | Alleen die route |
| **Thema** | `<style id="site-theme">`, server-gerenderd | Ná alle stylesheets, en alléén als de vormgeving van de standaard afwijkt (`THEMING.md`) |

`App\Service\PageAssets` is het enige mechanisme: een blokdefinitie, een route,
een module of een partial vraagt om een pad, de klasse ontdubbelt, zet de
site-shell er altijd vóór en print de tags. De shell zelf is `core.css` en
`core.js` plus wat de ingeschakelde modules eraan toevoegen — met de Shop uit
laadt een pagina geen enkel Shop-bestand. `partials/page-assets.php` doet dat in de
`<head>`, `partials/page-scripts.php` vlak voor `</body>`.

**De volgorde die dat oplost:** een blokpartial draait pas ver ná `</head>`,
maar zijn stylesheet moet erin. Daarom leest
`SectionRegistry::collectPageAssets()` de blokkenlijst van de pagina vóórdat
de `<head>` geschreven wordt en vraagt elke blokdefinitie wat die nodig heeft.
Scripts hebben dat probleem niet — die staan onderaan.

Derde-partijbibliotheken staan in één gesloten lijst in `PageAssets`. Vandaag
is dat alleen GSAP, gevraagd door het `homepage_hero`-blok en door niets
anders; vóór stap 4 stond die `<script>`-tag in twaalf templates.

`Tests\Service\FrontendAssetOwnershipTest` bewaakt de indeling: geen
blok- of Shop-gedrag in Core, geen Shop-bestanden in de site-shell behalve de
mini-winkelwagen, en geen handgeschreven asset-tag in een template.

## Architectuur-hotspots

Bekend, ingepland, **niet** in deze stap op te lossen:

- **Herhalende admin-endpointfamilies**: ±174 bestanden onder `api/admin/`
  volgen bijna hetzelfde patroon (guard, CSRF, valideren, repository, flash).
- **Shop-bestanden staan nog tussen die van Core**: stap 5 verhuisde het
  *eigenaarschap* van de koppelpunten naar `src/Module/`, niet de bestanden
  zelf. Een fysieke `Core/`- en `Modules/`-indeling is bewust uitgesteld tot
  ze aantoonbaar iets oplevert (`MODULES.md`).

## Welke documentatie lees ik

| Taak | Lees eerst |
|---|---|
| Content-blok toevoegen of wijzigen | `PROJECT-MAP.md` + `CONTENT-BLOCKS.md` |
| De blokkenkiezer, de Contentblokken-catalogus of de opslagbalk | `PAGE-EDITOR.md` |
| Tabbladen of inklapbare rijen op een lang adminscherm | `PAGE-EDITOR.md` |
| Uitleg bij een veld, de help-knop, de infobalk of een formulierelement in de CMS-stijl | `ADMIN-UI.md` |
| Shop, bestellingen, verzending, personalisatie | `PROJECT-MAP.md` + `MODULES.md` |
| Blogberichten, categorieën, tags, de blogpagina of de feed | `BLOG.md` |
| Domeingrenzen, "waar hoort dit thuis?" | `MODULES.md` |
| Een module aan-/uitzetten of toevoegen | `MODULES.md` (+ `.env.example`) |
| Talen van de site, de taal van het CMS, de bewerktaal, automatisch vertalen | `MULTILINGUAL.md` |
| Kleuren, lettertypes, knopvorm, logo's | `THEMING.md` |
| Hoe het CMS zelf eruitziet: dashboard-uiterlijk | `THEMING.md` |
| Afbeeldingen uploaden, hergebruiken, alt-teksten, verwijderen | `MEDIA.md` |
| Een formulier maken, plaatsen, een veldtype toevoegen, inzendingen | `FORMS.md` |
| Een nieuw paginasjabloon, of waarom een sjabloon geen paginatype is | `PAGE-TEMPLATES.md` |
| Wat een verse installatie aanmaakt, en wat een bestaande behoudt | `INSTALL-BOOTSTRAP.md` |
| Een nieuwe site beginnen (een clone met eigen `.env`, database, uploads en poorten), of een kopie zonder site-inhoud | `SETUP.md` |
| De installatiewizard, de basis-URL, of modules vanuit het CMS aan kunnen | `SETUP.md` |
| Headerknoppen, het Footer-scherm, footer-slotregel, social profielen | `HEADER-FOOTER.md` |
| Titels, meta description, canonical, sitemap, robots | `SEO.md` |
| Een oude URL die moet blijven werken, een pagina hernoemen | `REDIRECTS.md` |
| Tests draaien of toevoegen | `TESTING.md` |
| De schrijfstijl van code, commentaar en CMS-teksten | `CODE-STYLE.md` |
| Hoe je aan dit project werkt: de skills, de regels, waar nieuwe kennis heen gaat | `WORKFLOW.md` |
| Waaróm werkt een blok zo | `docs/content-blocks/ARCHITECTURE.md`, `DECISIONS.md` |

## Verwijzingen naar bestanden die hier niet bestaan

Docblocks en oudere documenten verwijzen naar vier bestanden die **niet in
deze repository zitten**. Ga er niet naar zoeken:

| Verwijzing | Wat het was |
|---|---|
| `MAIN.MD` | De projecthistorie van Van Veluw Laserdesign, ±632 KB. `App\Install\FreshSiteCopyPolicy` rekent die tot site-geschiedenis, niet tot de applicatie. Staat in de Van Veluw-repository |
| `docs/CMS_CONTENT_AUDIT.md` | De inventarisatie van de site van vóór het CMS |
| `docs/content-blocks/ROADMAP.md` | De vier fases van de contentblok-refactor, afgerond op 2026-09-08 |
| `docs/content-blocks/PHASE-1.md` t/m `PHASE-4.md` | De implementatie-instructies per fase |

Ruim 100 PHP-docblocks noemen er een. Dat is bewust zo gelaten: die zinnen
leggen uit waaróm iets zo werkt, en de historische bron erbij vermelden kost
niets zolang je weet dat je hem hier niet hoeft te openen. De code en de
docblocks zijn de referentie.

Wat er blijvend uit `PHASE-1.md` t/m `PHASE-4.md` kwam staat nu in
`docs/content-blocks/ARCHITECTURE.md` en `DECISIONS.md`. Oudere code- en
testcommentaren die naar een fase verwijzen bedoelen die twee bestanden.

Wijkt de code af van deze documenten, dan heeft de code gelijk: pas het
document aan, niet de code.
