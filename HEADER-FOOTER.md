# Header en footer

Wat er in de gedeelde header en footer instelbaar is, en waar de grens ligt.
Het **kruimelpad** staat er sinds fase 5B ook in: het is dezelfde soort
gedeelde schil, van Core, met precies één keuze voor de beheerder.
Lees dit samen met `PROJECT-MAP.md` (waar iets staat). Voor kleuren en
lettertypes zie `THEMING.md`; voor moduleslots `MODULES.md`.

## De grens

**De structuur is van Core. De inhoud is van de beheerder.**

| Van Core, niet instelbaar | Van de beheerder |
|---|---|
| Skip-link, merkblok, plaats van de navigatie, mobiele menumechaniek, sticky gedrag, de taalwissel, moduleslots | Menu-items (Navigatie), footerkolommen en -links (Footer), logo's (Site-instellingen), kleuren (Vormgeving) |
| Waar de knop staat, waar de slotregel staat, hoe een social-icoon eruitziet | Óf de knop er is, wat erop staat en waar hij heen gaat; óf de slotregel er is en wat er staat; welke social profielen bestaan |

Er zijn geen headerregio's, geen tweede knop, geen widgetzones en geen
slepen-en-neerzetten. Dit is een CMS, geen layoutbouwer.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Opslag | `site_settings` — sleutels in `App\Service\SiteSettings::DEFAULTS` |
| Header-knop | `App\Service\HeaderCta` |
| Slotregel | `App\Service\FooterService::slogan()` |
| Social profielen | `App\Service\SocialProfiles` |
| Scherm | Instellingen → **Header & footer** (`admin/header-footer.php`) |
| Opslaan | `api/admin/update-header-footer-settings.php` |
| Rendering | `partials/header.php`, `partials/footer.php` |
| Styling | `.social-row` in `assets/css/core.css` |

`site_settings` en niet `theme_settings`: dit is wie de site *is*, niet hoe hij
er *uitziet*. "Standaardvormgeving herstellen" mag nooit een knoptekst of een
Instagram-adres meenemen — zie de tabel bovenaan `THEMING.md`.

## De taalwissel

Staat links van de knop, en verschijnt **alleen op een site die meer dan één
taal publiceert** (`MULTILINGUAL.md`). Op een eentalige site waren het twee
knoppen die allebei dezelfde pagina in dezelfde woorden toonden, dus daar
rendert de header er geen — geen leeg besturingselement en geen extra
tabstop. Welke talen erin staan en welke voorop staat komt uit
Instellingen → Talen; de vormgeving en de plaats zijn van Core.

## De knop in de header

Precies één, rechts naast de taalwissel en de moduleslots.

```text
header_cta_enabled          '1' / '0'
header_cta_label_nl         de tekst
header_cta_label_en         leeg = gelijk aan NL
header_cta_link_type        page | route | external
header_cta_target_page_id   bij 'page'
header_cta_target_route     bij 'route'
header_cta_external_url     bij 'external'
header_cta_open_in_new_tab  '1' / '0'
```

Dat zijn **dezelfde velden als een menu-item en een footerlink**, en ze gaan
door dezelfde `App\Service\LinkResolver`. Er is met opzet geen tweede
linkmodel. Dat levert direct op:

- een pagina die op concept wordt gezet of verdwijnt, laat de knop verdwijnen;
- een pagina die van slug verandert, neemt de knop mee;
- een route van een **uitgeschakelde module** bestaat niet meer in
  `RouteRegistry`, dus de knop verdwijnt in plaats van naar een 404 te wijzen;
- een pagina die door een uitgeschakelde module wordt geserveerd (`/shop.php`)
  telt hetzelfde: `LinkResolver` laat hem vallen.

**Verdwijnen is niet vergeten.** De instelling blijft staan; zet je de module
weer aan, dan staat de knop er weer zonder dat iemand iets opnieuw invult. Het
adminscherm zegt intussen met een waarschuwing waaróm de knop niet te zien is
(`HeaderCta::adminWarning()`).

Geen tekst = geen knop. Geen werkende bestemming = geen knop. De header blijft
zonder knop coherent, op desktop en op mobiel.

## De slotregel in de footer

```text
footer_slogan_enabled  '1' / '0'
footer_slogan_nl       de tekst
footer_slogan_en       leeg = gelijk aan NL
```

Staat onderin naast het copyright en de juridische links. Uit of leeg betekent
dat er geen `<span>` gerenderd wordt — geen lege regel.

## Social profielen

Eén optionele URL per netwerk, uit een **gesloten lijst** in
`SocialProfiles::NETWORKS`: Instagram, Facebook, Pinterest, LinkedIn, YouTube,
TikTok, Etsy. Sleutels heten `social_<netwerk>_url`.

Gesloten om dezelfde reden als `ThemeFonts` en `ModuleRegistry`: een beheerder
kiest, niemand typt ooit een netwerknaam, een iconklasse of een stuk SVG. Het
enige dat uit de database in de pagina terechtkomt is een `href`, en die moet
langs `SocialProfiles::isValidProfileUrl()`:

- `https://` en niets anders — dat sluit `javascript:`, `data:` en een
  relatief pad in één regel uit;
- een parseerbare URL met een host en zonder inloggegevens erin;
- het **registreerbare label** van de host is de naam van het netwerk zelf.
  Dus `www.pinterest.de`, `nl.pinterest.com` en `pinterest.nl` mogen allemaal,
  en `facebook.evil.example` niet. Een naam matchen in plaats van een lijst
  domeinen is wat dit weghoudt van broze aannames over landdomeinen.

**Geen aparte aan/uit-vlag.** Ingevuld en geldig = zichtbaar, leeg = weg —
dezelfde regel als een logo of een og:image. Zonder profielen rendert de
footer géén rij en géén kop.

De iconen zijn van dit project: 24x24 stroke-glyphs in dezelfde stijl als de
adminzijbalk, in `SocialProfiles::NETWORKS`. Geen iconfont, geen stylesheet van
derden, geen buildstap, geen runtime-download — de footer moet het doen op
gedeelde hosting met alleen PHP. Elke link draagt zijn eigen `aria-label`
(tweetalig, via `data-nl-aria`/`data-en-aria`) en de `<svg>` staat op
`aria-hidden`, dus het glyph hoeft de betekenis niet alleen te dragen.

### Een netwerk toevoegen

1. Eén regel in `SocialProfiles::NETWORKS`: sleutel, label, settings-sleutel,
   de domeinlabels die de host mag hebben, en het icoon.
2. Diezelfde settings-sleutel met `''` in `SiteSettings::DEFAULTS`.
3. Klaar — het adminscherm en de footer lezen allebei het register. Houd de
   lijst klein.

Een migratie is niet nodig: `site_settings` is key/value en een ontbrekende
rij betekent de standaard.

## Standaarden: bestaande site versus verse installatie

Dezelfde afspraak als bij branding (`THEMING.md`): de **codestandaard is
generiek** en een **migratie heeft de huidige waarden vastgezet**.

```text
code    knop uit, geen tekst, geen bestemming
        slotregel uit, leeg
        alle social-URL's leeg

rij     migratie 20260909220000 schreef "Vraag offerte aan" /
        "Request a quote" naar de Contact-pagina, en de slotregel,
        als echte rijen — INSERT IGNORE, dus een bestaande rij
        wint altijd
```

Voor social profielen is niets gemigreerd: deze site had er geen, en er
worden er geen verzonnen.

Let op één eigenaardigheid van `SiteSettings::all()`: een opgeslagen **lege**
waarde valt terug op de standaard. Dat werkt alleen omdat elke standaard hier
leeg of `'0'` is. Geef een nieuwe sleutel in deze familie dus nooit een
niet-lege codestandaard, anders kan een beheerder hem niet leegmaken.

## Het kruimelpad

De kleine regel bovenaan een pagina die laat zien waar een bezoeker is:
`Home / Contact`.

### Waar het vandaan komt

Het stond tot fase 5B in de partial van de **Paginakop**. Dat betekende dat
een pagina zonder dat blok, of met dat blok verborgen, ook geen kruimelpad
had — terwijl het niets met een kop te maken heeft. Veertien sjablonen
schreven bovendien hun eigen kopie, met drie verschillende spellingen van de
link naar de homepage, zonder `<nav>`, zonder lijst, met een scheidingsteken
dat werd voorgelezen en met een huidige pagina die soms naar zichzelf linkte.

### De grens

| Van Core, niet instelbaar | Van de beheerder |
|---|---|
| De markup, de plek op de pagina, de vormgeving, het woord *Home*, welke niveaus een route heeft | Óf een pagina zijn kruimelpad toont |

De **naam** in het kruimelpad is de titel van de pagina zelf, per render
gelezen. Er wordt niets gekopieerd: hernoem je een pagina, dan verandert het
kruimelpad mee. `pages.title` is eentalig, dus een bezoeker in het Engels
ziet de Nederlandse paginanaam — dezelfde regel als elk ander veld zonder
vertaling (`MULTILINGUAL.md`). Een tweetalige paginatitel is een losse stap.

### Waar het staat

| Onderdeel | Waar |
|---|---|
| Eén niveau | `App\Service\Breadcrumbs\BreadcrumbItem` — label NL/EN en een adres, of geen adres |
| Het hele pad | `App\Service\Breadcrumbs\BreadcrumbTrail` — `home()`, `to()`, `toPage()`, `toRoute()` |
| Een gewone CMS-pagina | `App\Service\Breadcrumbs\PageBreadcrumb::forPage()` |
| Opslag van de keuze | `pages.show_breadcrumb` (`NOT NULL DEFAULT 1`) |
| Scherm | Pagina bewerken → tabblad **Pagina** (`admin/page.php`) |
| Opslaan | `api/admin/update-page.php` |
| Rendering | `partials/breadcrumb.php`, functie `render_breadcrumb()` |
| Styling | `.breadcrumb-bar` en `.breadcrumb` in `assets/css/core.css` |

### Wie stelt het pad samen

**De route, nooit Core.** Een domeinroute weet welke niveaus hij heeft; Core
weet hoe je die opschrijft. `BreadcrumbTrail` kent daarom geen enkele
repository van de Shop, de Blog of het Portfolio, en dat mag zo blijven
(`MODULES.md`).

```php
render_breadcrumb(
    BreadcrumbTrail::home()
        ->toRoute('shop')
        ->to(BreadcrumbItem::current($naamNl, $naamEn))
);
```

- `toPage('portfolio')` hangt er een **CMS-pagina** onder: haar eigen titel,
  haar eigen adres. Een pagina in concept of van een uitgezette module houdt
  haar naam maar verliest haar link.
- `toRoute('shop')` hangt er een **applicatieroute** onder, met het label en
  het adres uit `App\Service\RouteRegistry`. Een sleutel die het register
  niet kent voegt niets toe, zodat een pad nooit naar een 404 wijst.
- De **homepagelink** komt uit `PageContent::publicUrl()` van de siteroot:
  één spelling, dezelfde resolutie als het menu, de canonical en de sitemap.
- Een niveau dat een bezoeker leeg zou zien valt weg, en een pad met alleen
  *Home* erin rendert niets.

### Per routegroep

| Groep | Kruimelpad | Schakelaar |
|---|---|---|
| Siteroot (`/`) | Nooit — je begint er | — |
| Gewone CMS-pagina, en de sjablonen Diensten, Portfolio, Over mij, Contact, Shop | `Home / paginatitel` | `pages.show_breadcrumb` |
| Shop-routes: product, collectie, winkelwagen, afrekenen, bestelstatus, personaliseren | Vast, met hun eigen niveaus | Geen — geen `pages`-rij |
| Blog: overzicht, archief, bericht | Vast, met de blogtitel uit `BlogSettings` | Geen |
| Cookiebeleid, Herroepingsrecht | Vast, uit `RouteRegistry` | Geen |
| 404 | `Home / Pagina niet gevonden` | Geen |
| Portfolio legacy-detail | Alleen op de niet-gevonden-tak; een gevonden project houdt zijn eigen terugkoppeling | Geen |

Een vaste applicatieroute is geen pagina die een beheerder beheert, dus er is
ook niets om daar uit te zetten.

### De plek op de pagina

Het kruimelpad is het **eerste** in `<main>`, vóór de inhoud, in een eigen
`.breadcrumb-bar`. Die balk draagt de ruimte die de vaste siteheader nodig
heeft — dezelfde `clamp()` die `.page-hero` had — en een Paginakop die er
direct op volgt laat zijn eigen bovenruimte weg. Op een gewone pagina staat
de titel daardoor op dezelfde hoogte als voorheen.

Heeft de Paginakop een **achtergrondfoto**, dan staat het kruimelpad bóven de
fotoband in plaats van erover. Dat is het zichtbare gevolg van de
ontkoppeling: de foto begint nu onder de balk. De regel in `page-hero.css`
die het kruimelpad boven een foto lichter kleurde is daarmee overbodig en
verwijderd.

### Legacy

`page_heroes.breadcrumb_label_nl` en `breadcrumb_label_en` bestaan nog. Ze
worden niet meer gelezen en niet meer overschreven: de INSERT van
`PageHeroRepository::upsert()` zet `breadcrumb_label_nl` op de lege string
(de kolom is `NOT NULL`), en de UPDATE laat beide met rust, zodat een waarde
die een redacteur ooit typte blijft staan. Het veld *Naam in het kruimelpad*
is uit de Paginakop-editor verdwenen. De kolommen echt verwijderen is een
aparte, destructieve beslissing.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

Het kruimelpad zit in `cms` (en in `blocks`, omdat de Paginakop meeveranderde):
`Tests\Service\BreadcrumbTest` houdt de markup, de niveaus, de schakelaar en de
onafhankelijkheid van de Paginakop vast, `Tests\Service\ProductBreadcrumbTest`
(suite `shop`) dat het productpad server-side staat en `shop.js` het niet meer
schrijft.

`fast` bevat `HeaderFooterSettingsTest` (de instellingen zelf, zonder database)
en `HeaderFooterContractTest` (geen sitespecifieke tekst of bestemming meer in
de gedeelde schil). `cms` voegt `HeaderFooterRenderingTest` toe: de
CMS-paginabestemming tegen echte rijen, en wat een pagina echt rendert — ook op
de CMS-only deployment. Zie verder `TESTING.md`.

Raakte je een **taalveld** van de navigatie of de footer aan — een menulabel,
een kolomtitel, een footerlink of de footertekst — dan is
`Tests\Repository\LocalizedNavigationFooterPersistenceTest` (suite `cms`) de
test die vasthoudt dat het opslaan van de ene taal de andere niet
overschrijft, en `MULTILINGUAL.md` de regel erachter.
