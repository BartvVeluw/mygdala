# Multilingual 2.0 — fase 6, routinginventaris

Read-only opname van `origin/main` = `2a5647c`, gemaakt vóórdat er één regel
routingcode of schema is gewijzigd. Dit is het feitenblad waar de rest van
fase 6 op bouwt; wijkt de code hiervan af, dan heeft de code gelijk en wordt
dit bestand bijgewerkt.

---

## 1. Alle routable entity-types

| Entity | Tabel | Publieke sleutel vandaag | Slugkolom | Echt routable? |
|---|---|---|---|---|
| Page (vrije CMS-pagina) | `pages` | `slug` | `pages.slug` UNIQUE | **ja** — `/<slug>` |
| Page (route-gebonden) | `pages` | `route_path` | slug ongebruikt in de URL | ja, maar op een **vast** pad (`/`, `/shop.php`, …) |
| Blogbericht | `blog_posts` | `slug` | `blog_posts.slug` UNIQUE | **ja** — `/blog/<slug>` |
| Blogcategorie | `blog_categories` | `slug` | `blog_categories.slug` UNIQUE | **ja** — `/blog/categorie/<slug>` |
| Blogtag | `blog_tags` | `slug` | `blog_tags.slug` UNIQUE | **ja** — `/blog/tag/<slug>` |
| Collectie | `collections` | `slug` | `collections.slug` UNIQUE | **ja** — `/collecties/<slug>` |
| Product | `products` | `id` | `products.slug` bestaat maar **komt in geen enkele URL voor** | ja, maar **op id**: `/product.php?id=N` |
| Portfolio-item (legacy) | `portfolio_gallery_items` | `slug` | `slug` UNIQUE | **half** — `/portfolio/<slug>` is sinds fase 4B een compatibiliteitsroute: bestaat er een gekoppelde gepubliceerde pagina, dan 302 daarheen |
| Portfoliocategorie | `portfolio_categories` | `slug` | `slug` UNIQUE | **nee** — alleen een filterwaarde in een blok, geen URL |
| Bestelling | `orders` | `id` | — | ja, op id: `/bestelling-status.php?order=N` |

**Conclusie.** Zes entity-types hebben een slug die in een URL staat (Page,
Blogbericht, Blogcategorie, Blogtag, Collectie, Portfolio-legacy), één is
routable op id (Product) en één heeft geen slug-URL nodig (Bestelling).
`products.slug` en `portfolio_categories.slug` zijn **geen** URL-bron en
krijgen in fase 6 dus geen vertaalde slug.

---

## 2. Alle huidige publieke routevormen

`.htaccess` kent vandaag zeven smalle rewrites plus één catch-all van één
segment; al het overige is een echt bestand in de projectroot, of valt door
naar `ErrorDocument 404 /404.php`.

| # | Patroon | Entrypoint | Hoe geroute | Taalprefix vandaag mogelijk? |
|---|---|---|---|---|
| 1 | `/` | `index.php` | `DirectoryIndex` | nee |
| 2 | `/<slug>` | `pagina.php?slug=` | catch-all, **één segment**, `!-d` + `.php !-f` | nee |
| 3 | `/sitemap.xml` | `sitemap.php` | eigen regel | n.v.t. |
| 4 | `/robots.txt` | `robots.php` | eigen regel | n.v.t. |
| 5 | `/portfolio/<slug>` | `portfolio-detail.php?slug=` | eigen regel | nee |
| 6 | `/collecties/<slug>` | `collectie.php?slug=` | eigen regel | nee |
| 7 | `/blog/feed.xml` | `blog-feed.php` | eigen regel | nee |
| 8 | `/blog` | `blog.php` | eigen regel | nee |
| 9 | `/blog/categorie/<slug>` | `blog.php?category=` | eigen regel | nee |
| 10 | `/blog/tag/<slug>` | `blog.php?tag=` | eigen regel | nee |
| 11 | `/blog/<slug>` | `blog-post.php?slug=` | eigen regel | nee |
| 12 | `/index.php` | `index.php` | echt bestand | nee |
| 13 | `/shop.php` | `shop.php` | echt bestand | nee |
| 14 | `/product.php?id=N` | `product.php` | echt bestand | nee |
| 15 | `/cart.php` | `cart.php` | echt bestand | nee |
| 16 | `/checkout.php` | `checkout.php` | echt bestand | nee |
| 17 | `/bestelling-status.php?order=N` | `bestelling-status.php` | echt bestand | nee |
| 18 | `/personaliseren.php` | `personaliseren.php` | echt bestand | nee |
| 19 | `/contact.php` | `contact.php` | echt bestand | nee |
| 20 | `/diensten.php` | `diensten.php` | echt bestand | nee |
| 21 | `/over-mij.php` | `over-mij.php` | echt bestand | nee |
| 22 | `/portfolio.php` | `portfolio.php` | echt bestand | nee |
| 23 | `/cookiebeleid.php` | `cookiebeleid.php` | echt bestand | nee |
| 24 | `/herroeping.php` | `herroeping.php` | echt bestand | nee |
| 25 | alles wat Apache niet oplost | `404.php` | `ErrorDocument` | nee |

**De harde blokkade.** De catch-all is `^([a-z0-9-]+)/?$` — één segment. Een
URL met een taalprefix heeft er minstens twee en bereikt PHP vandaag helemaal
niet; hij valt door naar `ErrorDocument`. Zonder nieuwe rewrite bestaat er
geen enkele prefix-URL.

Welke `.php`-bestanden een `pages`-rij met `route_path` hebben verschilt per
installatie: een **verse installatie** maakt alleen `/` aan
(`20260909400000_bootstrap_a_generic_fresh_install`), de **Van
Veluw-upgrade** maakte er zes (`20260908100000_create_pages_table`). De
routing mag dus nooit van een vaste lijst systeempagina's uitgaan.

---

## 3. Welke slugs bezoekerstekst zijn

Bezoekerstekst, dus per taal vertaalbaar:

- `pages.slug` — de editor typt hem, hij staat in de adresbalk;
- `blog_posts.slug`;
- `blog_categories.slug`;
- `blog_tags.slug`;
- `collections.slug`;
- `portfolio_gallery_items.slug`, maar alleen nog als legacy-adres.

Geen bezoekerstekst:

- `products.slug` — staat in geen enkele URL;
- `portfolio_categories.slug` — filterwaarde in een blok;
- `pages.content_key` — onveranderlijke interne sleutel;
- `pages.route_path` — technisch pad.

---

## 4. Vaste padsegmenten die technisch/taalonafhankelijk zijn

| Segment | Waarom neutraal |
|---|---|
| `sitemap.xml`, `robots.txt`, `feed.xml` | protocolnamen |
| `assets`, `uploads`, `admin`, `api`, `vendor`, `db`, `src`, `docker`, `docs`, `partials`, `scripts`, `tests`, `storage` | technische naamruimtes |
| elk `<naam>.php` | bestandsnamen, geen woorden die een bezoeker als taal leest |
| `blog` | in het Nederlands hetzelfde woord als in het Engels |
| `tag` | idem |
| `portfolio` | idem |

---

## 5. Vaste padsegmenten die feitelijk taalspecifiek zijn

| Segment | Route | Nederlands | Engels |
|---|---|---|---|
| `categorie` | `/blog/categorie/<slug>` | ja | `category` |
| `collecties` | `/collecties/<slug>` | ja | `collections` |
| `personaliseren` | `/personaliseren.php` | ja, maar het is een **bestandsnaam** | — |

Alleen de eerste twee zijn URL-woorden die een bezoeker leest, en alleen die
twee worden in fase 6 localizable. `personaliseren` is een bestandsnaam;
die route omzetten naar een slug-route is de "systeempagina's worden gewone
pagina's"-stap, en die verplaatst bestaande canonieke URL's — buiten fase 6
(zie §6 en §37 van de opdracht).

---

## 6. Oude URL's die absoluut moeten blijven werken

Alles uit de tabel in §2 blijft **200 op exact hetzelfde adres** in de
standaardtaal. Dat is de harde eis: fase 6 voegt taalprefixen en vertaalde
slugs toe en verplaatst geen enkele bestaande canonieke URL.

Daarnaast blijven ongewijzigd werken:

- elke rij in `redirects` (de Redirect Manager) — `source_path` blijft
  opgeslagen zoals hij is, zonder prefix;
- de slug-wissel-redirects die `SlugChangeRedirects` heeft aangelegd;
- `/portfolio/<slug>` met zijn 302 naar de gekoppelde pagina;
- `/api/**` en `/admin/**`, volledig taalloos.

---

## 7. Consumenten per onderwerp

### URL-bouwers vandaag (de enige vijf)

| Klasse | Methoden | Dekt |
|---|---|---|
| `App\Service\PageContent` | `publicUrl()`, `canonicalPath()`, `canonicalUrl()` | pagina's |
| `App\Service\Blog\BlogUrls` | dertien methoden | de hele Blog |
| `App\Service\ProductSeo` | `publicPath()`, `canonicalUrl()` | producten |
| `App\Service\CollectionContent` | `publicPath()`, `canonicalUrl()`, `canonicalUrlForSlug()` | collecties |
| `App\Service\PortfolioGalleryContent` | `publicPath()`, `canonicalUrlForSlug()`, `legacyProjectRedirectUrl()` | portfolio-legacy |

Daaronder `App\Service\RouteRegistry` (drie Core-routes, drie van de Shop,
één van de Blog) en `App\Service\AppUrl::canonical()` als absolute-maker.

### Breadcrumbconsumenten

`App\Service\Breadcrumbs\BreadcrumbTrail` (`home()`, `toPage()`,
`toRoute()`), `PageBreadcrumb::forPage()`, `partials/breadcrumb.php`.
Gebruikt door `pagina.php`, `shop.php`, `blog.php`, `blog-post.php`,
`collectie.php`, `portfolio-detail.php`, `cookiebeleid.php`,
`herroeping.php`.

### Sitemapconsumenten

`App\Service\Sitemap` plus de `sitemapCollectors()` van Core, Shop, Blog,
Portfolio en Personalisatie. Contract vandaag: `list<{loc, lastmod}>`.

### SEO-consumenten

`App\Service\PageSeo`, `ProductSeo`, `CollectionContent`, `Blog\BlogSeo`,
`SeoMetadata`, `partials/seo-head.php`. Eén canonical per pagina, en **geen
hreflang**: `Tests\Service\MultilingualBoundaryTest` verbiedt het vandaag
expliciet.

### JSON/API-consumenten die een publieke URL uitsturen

- `ProductSeo` JSON-LD `Product.url` en `Offer.url`;
- `BlogSeo` JSON-LD `BlogPosting.url` en `mainEntityOfPage.@id`;
- `PageSeo` JSON-LD `Organization`/`WebSite` `url` = `AppUrl::canonical('/')`;
- `CollectionGalleryItems` en `CollectionContent` (`url` in blokpayloads);
- `Blog\BlogContent` (`url` en `canonical_url` in elke rij);
- `MolliePaymentData` `redirectUrl` — **gebouwd uit `HTTP_HOST`** in
  `api/checkout.php`, niet uit `AppUrl`. De enige publieke return-URL die de
  request-host vertrouwt;
- `api/products.php` en `api/product.php` sturen **geen** URL mee: de
  productkaart-link wordt client-side gebouwd in `assets/js/shop/shop.js`.

### Interne-linkconsumenten

Navigatie en footer via `LinkResolver`; kopknoppen via
`NavigationPresentation`; CTA's, hero's, kaarten en gallery's via vrije
`*_url`-velden die een editor typt; breadcrumbs; blogkaarten, categorie- en
tagchips; collectietegels; gerelateerde producten; winkelwagenregels.

### Form-, POST- en PRG-consumenten

- `partials/form.php` → `POST /api/form-submit.php` → **303** terug naar
  `form-source` (`App\Service\Forms\FormSourcePath`, met open-redirect-guard);
- `herroeping.php` → `POST /api/withdrawal-request.php` → **303** naar
  `/herroeping.php?status=…`;
- `checkout.php` → `fetch POST /api/checkout.php` → JS-redirect naar Mollie →
  Mollie keert terug op `/bestelling-status.php?order=N`;
- `api/contact.php` is een shim die `form-source` hardcodeert op
  `/contact.php`.

---

## 8. Latente bugs die fase 6 raakt

Tien publieke links zijn **relatief zonder leidende slash** en resolven nu al
verkeerd onder een genest pad (`/collecties/<slug>`, `/blog/<slug>`); onder
een taalprefix zouden ze opnieuw breken:

`product.php`, `cart.php` (drie), `checkout.php`, `bestelling-status.php`
(twee), `partials/footer.php`, `partials/cookie-consent.php`,
`assets/js/shop/cart.js`.

Twee paginaslugs staan hard in de code zonder pagina-opzoeking en volgen dus
geen vertaalde slug: `/verzenden-retourneren` en `/privacyverklaring`
(`checkout.php`, `bestelling-status.php`, `herroeping.php`).

De productkaart-URL wordt client-side gebouwd en kent geen taal
(`assets/js/shop/shop.js`, `assets/js/shop/cart.js`).
