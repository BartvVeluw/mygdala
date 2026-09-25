# SEO

Hoe deze applicatie beslist wat er in de `<head>` van een publieke pagina
staat, wat er in de sitemap komt en wat `robots.txt` zegt. Lees dit samen met
[`PROJECT-MAP.md`](PROJECT-MAP.md) (waar iets staat).

Eén regel vooraf: **een template beslist niets over SEO.** Het vraagt de
resolver van zijn eigen contenttype om de metadata en geeft het antwoord aan
één gedeelde renderer. Er is geen tweede plek waar een titel, een canonical
of een og:image ontstaat.

## De keten

```text
globale standaarden        SiteSettings  →  App\Service\SeoDefaults
        ↓
contenttype-standaarden    PageSeo / ProductSeo / CollectionContent
        ↓
eigen waarden per item     meta_title, meta_description, og_image_path, noindex
        ↓
effectieve metadata        App\Service\SeoMetadata   (read-only)
        ↓
één renderer               partials/seo-head.php
```

| Onderdeel | Waar |
|---|---|
| Globale standaarden | `src/Service/SeoDefaults.php` |
| Effectieve metadata | `src/Service/SeoMetadata.php` |
| CMS-pagina's | `src/Service/PageSeo.php` (leest `PageContent`) |
| Shop-producten | `src/Service/ProductSeo.php` |
| Shop-collecties | `src/Service/CollectionContent.php` |
| Blogberichten en -archieven | `src/Service/Blog/BlogSeo.php` (`BLOG.md`) |
| Welke taal de `<head>` als zichtbare tekst draagt | `src/Service/Language/SiteText.php` (`MULTILINGUAL.md`) |
| Gedeelde hulpjes | `src/Service/Seo.php` (titelconventies, plain text, absolute URL's) |
| Basis-URL | `src/Service/AppUrl.php` — `APP_URL` uit `.env`, anders `site_settings.canonical_base_url` (`SETUP.md`) |
| Renderer | `partials/seo-head.php` |
| Adapters | `partials/page-head.php` (CMS-pagina), `partials/shop-seo-head.php` (shop + personalisatiecatalogus) |
| Sitemap | `src/Service/Sitemap.php`, `sitemap.php` |
| robots.txt | `src/Service/Robots.php`, `robots.php` |
| Omgeving | `src/Service/AppEnvironment.php` (`APP_ENV`) |
| Verhuisde URL's | `src/Service/Redirects/` — zie `REDIRECTS.md` |

## Globale standaarden

Vier waarden, waarvan er **twee al bestonden**. Dat is met opzet: een tweede
naam voor de site of een tweede standaardafbeelding zou vrij zijn om van de
eerste af te wijken.

| Wat | Instelling | Standaard |
|---|---|---|
| Titel-achtervoegsel | `site_name` (Instellingen → Bedrijfsnaam) | `Website` |
| Standaard deel-afbeelding | `og_image_path` (Instellingen → Standaard deel-afbeelding) | leeg |
| Standaard meta description | `seo_default_description` (Instellingen → SEO) | leeg |
| Indexeren toegestaan | `seo_robots_index_default` (Instellingen → SEO) | aan |

De standaard meta description is **leeg** en blijft dat tot iemand er een
schrijft: een pagina zonder eigen beschrijving krijgt dan helemaal geen
`<meta name="description">`, wat beter is dan overal dezelfde zin.

`seo_robots_index_default` staat aan, en alleen een expliciete uit-waarde
(`0`, `false`, `off`, `no`, `noindex`) zet hem uit. Een typefout of een
ontbrekende rij betekent "indexeren" — een configuratiefout kan een live site
nooit uit Google halen.

Er is **geen** keywords-veld, **geen** los "SEO title suffix" en **geen**
mechanisme voor willekeurige eigen `<meta>`-tags.

## De terugvalregels

### Titel

```text
eigen SEO-titel (meta_title)     → letterlijk, precies zoals getypt
→ "<paginatitel> — <site_name>"
→ site_name
```

Een pagina die al zo heet als de site krijgt de naam niet twee keer:
"Testbedrijf — Testbedrijf" ontstaat nergens. Een lege Engelse waarde valt
terug op de Nederlandse, zoals elk tweetalig veld in dit project. Voor
CMS-pagina's staan titel, SEO-titel en omschrijving per websitetaal in
`page_translations`, en valt elk veld terug op de standaardtaal
(`App\Service\PageLocalization`, `docs/multilingual/ARCHITECTURE.md`). Sinds
Multilingual 2.0 fase 6 heeft elke taalversie een eigen URL, en daarmee een
eigen canonical: de tag die een crawler leest is die van de taal van het
verzoek (`SeoMetadata::title()` en `::description()`), nooit een andere.
Zie `docs/multilingual/ROUTING.md`.

Vaste routes zonder CMS-rij (winkelwagen, afrekenen, bestelstatus,
cookiebeleid, herroeping) gebruiken `Seo::routeTitle()`: `"<naam> | <site_name>"`.
De shop houdt zijn eigen conventie `"<naam> | Shop — <site_name>"`
(`Seo::shopTitle()`), de oude Portfolio-projectpagina `"<project> | Portfolio — <site_name>"`, en de
Blog `"<titel> | <blogtitel> — <site_name>"` — dezelfde vorm, zodat een lezer
in een zoekresultaat ziet uit welk deel van de site het komt. Dat
zijn de teksten die er al stonden; ze zijn niet gelijkgetrokken omdat ze
werken en wijzigen betekent dat elke bestaande titel verandert.

### Meta description

```text
eigen meta_description  → letterlijk, nooit ingekort
→ contenttype-terugval waar die al bestond
   (product: platte-tekst-excerpt van de productomschrijving)
→ seo_default_description
→ geen tag
```

Er wordt **nooit** zomaar pagina-inhoud in een meta description gegooid. Een
product had die terugval al (`Seo::excerpt()`), een CMS-pagina niet en krijgt
er ook geen.

### Deel-afbeelding

```text
eigen og_image_path
→ de eigen afbeelding die het item toch al toont
   (product: de foto van de standaardvariant; oude portfolio-projectpagina: de projectfoto)
→ og_image_path uit Instellingen
→ geen og:image
```

Altijd absolute URL's, gebouwd met `AppUrl` — nooit uit de Host-header.

### Open Graph en Twitter

`og:title` en `og:description` **zijn** de effectieve titel en beschrijving.
Er zijn geen aparte social-velden, zodat ze niet uit elkaar kunnen lopen als
iemand de SEO-titel aanpast.

Het hele deelblok (Open Graph én Twitter) wordt alleen gerenderd voor een
pagina die een **canonical URL** heeft — dat is precies wat "een pagina die je
kunt delen" betekent. Een 404 en de bestelstatuspagina hebben er geen, dus
krijgen ze geen deelvoorbeeld. De winkelwagen, het afrekenen en de juridische
pagina's zijn wel `noindex` maar prima te linken, en houden hun tags.

`twitter:card` is `summary_large_image` zodra er een afbeelding is, en het
hele `twitter:*`-blok blijft weg als die er niet is: X leest dan gewoon de
Open Graph-tags.

## Canonical

Altijd absoluut, altijd via `AppUrl::canonical()`, dus altijd tegen `APP_URL`
uit `.env` — nooit tegen de `Host`-header van het verzoek. Elk contenttype
bouwt zijn eigen URL één keer:

| Contenttype | Methode |
|---|---|
| CMS-pagina | `PageContent::canonicalUrl()` |
| Product | `ProductSeo::canonicalUrl()` |
| Collectie | `CollectionContent::canonicalUrl()` |
| Oude portfolio-projectpagina (zonder gekoppelde, gepubliceerde pagina) | `PortfolioGalleryContent::canonicalUrlForSlug()` |
| Systeempagina zonder CMS-pagina (winkelwagen, afrekenen, cookiebeleid, herroeping, personaliseren, de eigen storefront van de Shop) | `LocalizedUrl::absolute('/<route>.php')` in het template |

Een systeempagina roept `AppUrl::canonical()` nooit rechtstreeks aan: dat
kent geen taal, en noemde vanaf `/en/cart.php` de winkelwagen van de
standaardtaal. `Tests\Service\PublicRouteLanguageTest` vraagt elke publieke
route in drie talen op en controleert dat.

Dezelfde methodes vullen de sitemap, dus een `<loc>` is per constructie
identiek aan de canonical van de pagina waar hij naar wijst.

`SeoMetadata` accepteert alleen een absolute `http(s)`-URL en laat al het
andere vallen: een verkeerde canonical is erger dan geen. Een productdetail
houdt zijn `?id=`-querystring, want dát is zijn URL; die wordt uit een
gevalideerd geheel getal gebouwd, nooit uit request-invoer, dus
trackingparameters kunnen er niet in lekken.

**Eén canonical per taalversie, en nooit taaloverschrijdend.** De
standaardtaal heeft geen prefix (`/over-ons`), elke andere taal wel
(`/en/about-us`). Een canonical wijst altijd naar de versie die gerenderd
wordt, ook wanneer een véld is teruggevallen op de standaardtaal: veldterugval
verzint geen route en verplaatst ook geen canonical.

**hreflang, uit verklaarde versies.** `partials/seo-head.php` rendert één
`<link rel="alternate" hreflang="xx">` per taalversie die **echt bestaat**, de
eigen taal inbegrepen, plus `x-default` naar de versie in de standaardtaal. De
lijst komt uit `App\Service\Routing\LanguageAlternates`: een route verklaart
vóór zijn `<head>` welke versies routeerbaar zijn, en een route die niets
verklaart adverteert niets. Daardoor kan een alternate nooit een URL noemen
die 404't of die alleen maar terugvalt — precies de twee signalen waarvan
zoekmachines zeggen dat ze ze niet vertrouwen. Bij minder dan twee versies
komt er geen enkele tag: een hreflang-blok dat één URL noemt zegt niets.

`og:locale` is de taal van het verzoek, `og:locale:alternate` noemt de andere
versies, en `<html lang>` volgt dezelfde taal. Het hele contract staat in
`docs/multilingual/ROUTING.md`.

## Indexeerbaarheid

Twee waarden, meer niet: `index,follow` of `noindex,follow`. Ze komen uit
`SeoDefaults::ROBOTS_INDEX` / `ROBOTS_NOINDEX`, zodat er geen ruwe tekst in
een `<meta name="robots">` kan belanden.

Een CMS-pagina is indexeerbaar tenzij één van deze drie geldt
(`PageSeo::isIndexable()`):

1. de pagina heeft `noindex` aan staan;
2. de pagina is een concept;
3. de pagina wordt geserveerd door het template van een uitgeschakelde
   module (`/shop.php` met de Shop uit), dus zijn URL geeft 404.

Nooit indexeerbaar:

| Route | Waarom |
|---|---|
| 404 (elke onbekende URL, concept, verwijderde pagina) | bestaat niet |
| `/cart.php`, `/checkout.php`, `/bestelling-status.php` | transactioneel |
| `/cookiebeleid.php`, `/herroeping.php` | juridisch, geen zoekresultaat |
| product/collectie "niet gevonden" | inactief of onbekend |
| `/admin/`, `/api/` | renderen helemaal geen publieke head |

## Sitemap

`/sitemap.xml` wordt bij elk verzoek uit de database gebouwd (`sitemap.php`,
rewrite in `.htaccess`). Wat erin komt is **dezelfde beslissing** als de
robots-tag op de pagina zelf: Core's paginacollector vraagt
`PageSeo::isIndexable()`, dus een `noindex`-pagina staat er niet in.

Core levert alleen `pages`; alles daarbuiten komt van een
**ingeschakelde** module via `ModuleDefinition::sitemapCollectors()` — Portfolio
levert zijn projectpagina's die zelf een pagina tonen, in elke taal (een item
met een legacy-koppeling naar een gewone pagina stuurt door en staat er niet
in; die pagina komt uit Core's paginacollector), de Shop producten
en collecties, Personalisatie de
catalogus, de Blog het
overzicht, de gepubliceerde berichten en de categorie-archieven die minstens
één bericht bevatten. Met de Shop uit bestaat er geen codepad dat een shop-URL
kan toevoegen, en met de Blog uit geen dat een blog-URL kan toevoegen.

Tag-archieven staan er met opzet **niet** in: die zijn `noindex,follow` (zie
`BLOG.md`), en de sitemap zegt hetzelfde als de robots-tag op de pagina zelf.

**Meertalig sinds Multilingual 2.0 fase 6.** Elke taalversie van één ding is
een eigen `<url>`, en elk van die `<url>`'s draagt de volledige set
`<xhtml:link rel="alternate">` plus `x-default` — de wederkerigheid ís het
signaal. Een collector geeft de paden die hij al als bestaand heeft
vastgesteld aan `Sitemap::entriesForVersions()`; een versie zonder adres in
een taal komt er in die taal dus niet in. De `xhtml`-naamruimte wordt alleen
gedeclareerd wanneer er alternates zijn, zodat de sitemap van een eentalige
site byte-voor-byte is wat hij was. Zie `docs/multilingual/ROUTING.md`.

`<lastmod>` komt alleen uit de echte `updated_at` van de rij, als datum
(`YYYY-MM-DD`). Geen waarde betekent geen `<lastmod>`; "nu" wordt nooit
gebruikt. Geen `changefreq`, geen `priority`, geen image/video/news-uitbreidingen.

## robots.txt

Gegenereerd door `robots.php` (rewrite in `.htaccess`), net als de sitemap.
Er stond een statisch bestand met de productiedomeinnaam erin getypt; de
`Sitemap:`-regel komt nu uit `AppUrl`.

```text
User-agent: *
Allow: /
Disallow: /admin/
Disallow: /api/

Sitemap: <APP_URL>/sitemap.xml
```

De winkelwagen en het afrekenen staan er met opzet **niet** in: een crawler
moet een pagina mógen ophalen om te zien dat hij hem niet mag indexeren.
Crawlen blokkeren en indexeren weigeren zijn twee verschillende dingen.

**Niet-productie.** Staat `APP_ENV` in `.env` op `local`, `development`,
`dev`, `staging`, `test` of `testing`, dan serveert `robots.txt`
`Disallow: /` en helemaal geen sitemap. Alles anders — ontbrekend, leeg,
onleesbaar, verkeerd gespeld — betekent productie. De hostname wordt nooit
geraadpleegd: een verzoek mag de applicatie niet kunnen wijsmaken dat ze een
testserver is.

## Gestructureerde data

| Waar | Wat | Bron |
|---|---|---|
| Productdetail | `Product` met `Offer`/`AggregateOffer` | `ProductSeo::jsonLd()` |
| Homepage | `Organization` | `PageSeo` |
| Blogbericht | `BlogPosting` | `Blog\BlogSeo` |
| Alles daarbuiten | niets | — |

De `Organization`-node bevat alleen wat `SiteSettings` echt weet: naam, URL,
het logo als er een is ingesteld, en `sameAs` met de social profielen die
zichtbaar in de footer staan (`SocialProfiles::forFooter()`), elk adres één
keer. Een install die alleen een
naam heeft krijgt een naam en een URL, en verder niets.

De `BlogPosting`-node bevat alleen wat het bericht echt draagt: kop, URL,
publicatie- en wijzigingsdatum, beschrijving, afbeelding, en een auteur
uitsluitend als er een naam is ingevuld — géén auteur die stilzwijgend de
bedrijfsnaam wordt. Een bericht dat niet geïndexeerd mag worden levert
helemaal niets.

Bewust **niet**: `LocalBusiness` met een geraden bedrijfstype, openingstijden,
beoordelingen en `FAQPage` alleen omdat er een FAQ-blok bestaat. Er is geen
betrouwbare gegevensbron voor één ervan.

`BreadcrumbList` hoort ook nog in dat rijtje, maar om een andere reden dan
eerst. Sinds fase 5B is er wél echte broodkruimelnavigatie, met een
betrouwbare bron (`HEADER-FOOTER.md`); de JSON-LD erbij is een losse stap die
bewust buiten die fase is gelaten en niet vergeten.

De JSON-LD wordt altijd met `json_encode()` uit een PHP-array gebouwd, nooit
met stringplakwerk, met `JSON_HEX_TAG|AMP|APOS|QUOT` — een productnaam met
`</script>` erin kan het script-element niet afsluiten.

## Beheerscherm

**Instellingen → tabblad SEO** (`admin/settings.php`): de standaard meta
description en de indexeerschakelaar. Het titel-achtervoegsel en de standaard
deel-afbeelding staan op het tabblad *Algemeen* van datzelfde scherm, bij de
bedrijfsgegevens, omdat ze daar al stonden.

**Pagina bewerken → SEO** (`admin/page.php`): SEO-titel en meta description
per taal, een voorbeeld van hoe het zoekresultaat eruitziet, een vinkje "niet
laten indexeren" en een eigen deel-afbeelding.

Validatie:

- titel en beschrijving zijn gewone tekst, worden op lengte gecontroleerd en
  nooit stilletjes afgekapt — andermans SEO-tekst herschrijven is erger dan
  een lange tag;
- het indexeervinkje is een gesloten twee-waardenkeuze, met een verborgen
  companion-veld ernaast, want een niet-aangevinkt vakje stuurt niets mee en
  zou anders wel aan- maar nooit uit te zetten zijn;
- de deel-afbeelding gaat door `SectionImageUploader` (willekeurige
  bestandsnaam, magic-byte-controle, en een `delete()` die niets buiten
  `assets/images/sections/` aanraakt).

## Een module en SEO

Er is geen apart SEO-uitbreidingsmechanisme, en dat is de bedoeling. Een
module gebruikt wat er al is:

- **sitemap** — `ModuleDefinition::sitemapCollectors()`;
- **eigen metadata** — een eigen read model (zoals `ProductSeo`) dat een
  `SeoMetadata` oplevert, of de array-vorm die
  `partials/shop-seo-head.php` aanneemt. Core hoeft er niets van te weten;
- **gestructureerde data** — in dat eigen read model, want het hoort bij het
  domein.

`Tests\Module\ShopDisabledTest` bewaakt dat `SeoMetadata`, `SeoDefaults`,
`PageSeo`, `Robots`, `Sitemap` en `partials/seo-head.php` geen concrete
Shop-klasse noemen.

## Verhuisde URL's

Een URL die verhuisd is, wordt niet in de `<head>` opgelost maar in het
antwoord zelf: de Redirect Manager (`REDIRECTS.md`) stuurt een 301 of 302 met
een `Location`-header en **geen body**. Er is dus geen canonical, geen Open
Graph en niets te indexeren op de oude URL — de redirect zelf is het signaal,
en er zijn geen aparte SEO-tags voor een redirectantwoord.

Drie dingen houden de twee met elkaar in de pas:

- de opzoeking staat pas op het punt waar een verzoek toch al een 404 werd, dus
  een redirect kan nooit een pagina overschaduwen die zijn eigen metadata heeft;
- een automatische slugredirect wijst naar exact de URL die
  `PageContent::canonicalUrl()` voor die pagina bouwt — dezelfde methode die de
  canonical-tag en de sitemap vullen, dus ze kunnen niet uit elkaar lopen;
- een `<loc>` in de sitemap komt altijd uit de eigen `canonicalUrl()` van een
  contenttype, en de redirecttabel is geen contenttype. Een vanaf-pad kan er
  dus per constructie niet in staan.

`ErrorDocument 404 /404.php` in `.htaccess` is wat een URL die Apache niet kon
plaatsen bij PHP brengt. Zo'n URL kreeg voorheen Apache's standaard foutpagina;
nu krijgt hij dezelfde `noindex`-404 als elke andere onbekende URL, met
dezelfde statuscode.

## Tests

| Bestand | Wat |
|---|---|
| `tests/Service/SeoMetadataTest.php` | de terugvalregels, robots-standaarden, canonical-normalisatie, escaping, weggelaten lege tags, en dezelfde code als een ándere site |
| `tests/Service/PageSeoTest.php` | een `pages`-rij → metadata: titel, beschrijving, noindex, deel-afbeelding, `Organization` |
| `tests/Service/RobotsTest.php` | sitemap-URL uit `AppUrl`, geen literal domein, niet-productie |
| `tests/Service/SeoRoutingTest.php` | echte requests: `robots.txt`, noindex-pagina uit de sitemap, transactionele routes, 404 |
| `tests/Service/SitemapTest.php`, `SitemapRoutingTest.php` | de sitemap zelf |
| `tests/Service/ShopSeoRoutingTest.php`, `ProductSeoTest.php`, `CollectionSeoTest.php` | de shop-kant |
| `tests/Module/CmsOnlyHttpTest.php` | de head en `robots.txt` met de Shop uit |
| `tests/Blog/BlogSeoTest.php` | de blogkant: metadata, `BlogPosting`, wat wel en niet in de sitemap komt, en de feed |
| `tests/Service/RedirectSlugChangeTest.php` | de canonical van een hernoemde pagina is de bestemming van zijn redirect, en de sitemap noemt alleen de nieuwe URL |

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

## Bewust niet gedaan

- **Canonical-override per pagina.** Elke pagina in dit CMS heeft precies één
  URL; een override is dan vooral een manier om een pagina per ongeluk uit de
  index te schrijven.
- **Aparte Open Graph titel/beschrijving per pagina.** Twee velden die
  hetzelfde zeggen tot iemand er één van bijwerkt.
- **`og:type` van een product op `product`.** Klopt beter dan `website`, maar
  het is een wijziging aan live metadata die deze stap niet nodig heeft.
- **`ProductSeo`/`CollectionContent` die zelf een `SeoMetadata` teruggeven.**
  Ze leveren nog een array die `partials/shop-seo-head.php` omzet. Dat is een
  refactor van de read models van de Shop, niet van de SEO-laag.
- Keyword-onderzoek, SEO-scores, leesbaarheidsanalyse, rank tracking, Search
  Console-koppeling, een schema-builder.

## Waar de basis-URL vandaan komt

`App\Service\AppUrl` is de enige plek die het weet, en de volgorde is:

```text
1. APP_URL in .env                    deploy-configuratie
2. site_settings.canonical_base_url   wat de installatiewizard schrijft
3. https://localhost                  "niemand heeft iets gezegd"
```

Stap 2 is er sinds de installatiewizard (`SETUP.md`), omdat `APP_URL` niet
bereikbaar is vanuit het CMS op gedeelde hosting. Hij staat met opzet áchter
de variabele, zodat er één antwoord op één vraag blijft: wie `APP_URL` invult
beslist, en het CMS toont dat veld dan alleen-lezen.
`AppUrl::source()` zegt hardop welke stap antwoordde.

Stap 3 was tot deze stap de productiedomeinnaam van deze site, en `.env` op de
productiehosting zet `APP_URL` niet — een verse installatie publiceerde dus
canonieke tags, `og:url` en een sitemap die naar Van Veluw Laserdesign wezen.
Migratie `20260910110000_pin_business_details_before_generic_defaults.php` heeft
de huidige waarde als echte rij vastgezet vóórdat de constante een zichtbaar
lokale placeholder werd, dus aan de uitvoer van deze site verandert niets.
Verder staat er nergens in de SEO-laag nog een domein of bedrijfsnaam
geschreven.

**De `Host`-header wordt nooit geraadpleegd**, in geen van de drie stappen.
Een canoniek adres dat het verzoek volgt is geen canoniek adres.
