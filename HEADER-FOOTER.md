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
| Skip-link, merkblok, plaats van de navigatie, mobiele menumechaniek, sticky gedrag, de taalwissel, moduleslots | Menu-items en headerknoppen (Header & navigatie), footerkolommen en -links (Footer), logo's (Site-instellingen), kleuren (Vormgeving) |
| Waar de knoppen staan, hoe ze eruitzien, waar de slotregel staat, hoe een social-icoon eruitziet | Óf er knoppen zijn, hoeveel, in welke volgorde, wat erop staat, waar ze heen gaan en welke van twee stijlen; óf de slotregel er is en wat er staat; welke social profielen bestaan |

Er zijn geen headerregio's, geen vrije knopvormgeving, geen megamenu, geen
widgetzones en geen derde menuniveau. Dit is een CMS, geen layoutbouwer.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Opslag menu en headerknoppen | `nav_items` — één tabel, `presentation` zegt link of knop |
| Opslag slotregel en social profielen | `site_settings` — sleutels in `App\Service\SiteSettings::DEFAULTS` |
| Menu en headerknoppen (lezen) | `App\Service\NavigationService::header()` |
| Link of knop, en de knopstijlen | `App\Service\NavigationPresentation` |
| Volgorde, opslag | `App\Repository\NavigationRepository` |
| Slotregel | `App\Service\FooterService::slogan()` |
| Social profielen | `App\Service\SocialProfiles` |
| Schermen | **Header & navigatie** (`admin/navigation.php`, `admin/navigation-item.php`); **Slotregel & social media** (`admin/header-footer.php`) |
| Opslaan | `api/admin/create-nav-item.php`, `update-nav-item.php` (regels in `_nav_item_input.php`), `move-nav-item.php`, `reorder-nav-items.php`, `toggle-nav-item.php`, `delete-nav-item.php`; `update-header-footer-settings.php` |
| Rendering | `partials/header.php`, `partials/footer.php` |
| Styling | `.header-buttons` en `.social-row` in `assets/css/core.css` |

`site_settings` en niet `theme_settings`: dit is wie de site *is*, niet hoe hij
er *uitziet*. "Standaardvormgeving herstellen" mag nooit een knoptekst of een
Instagram-adres meenemen — zie de tabel bovenaan `THEMING.md`. Voor de
headerknoppen geldt hetzelfde, en die staan in `nav_items`, waar
Vormgeving niet bij kan.

## De taalwissel

Staat links van de knoppen, en verschijnt **alleen op een site die meer dan één
taal publiceert** (`MULTILINGUAL.md`). Op een eentalige site waren het twee
knoppen die allebei dezelfde pagina in dezelfde woorden toonden, dus daar
rendert de header er geen — geen leeg besturingselement en geen extra
tabstop. Welke talen erin staan en welke voorop staat komt uit
Instellingen → Talen; de vormgeving en de plaats zijn van Core.

## Het menu en de knoppen: één itemmodel

Alles wat een beheerder bovenaan elke pagina zet, is een rij in `nav_items`:
een tekst per taal, een bestemming, zichtbaar of niet, en een plek in de
volgorde. Of die rij een **link in het menu** of een **knop in de header** is,
zegt één kolom:

```text
presentation     link    in de lijst van het menu (elk item van vóór fase A)
                 button  rechts in de header, na de taalwissel en de moduleslots
button_variant   primary gevulde knop (.btn), wat de ene headerknop altijd was
                 ghost   knop met alleen een rand (.btn--ghost)
                         alleen gelezen bij presentation = button
```

Twee gesloten lijsten in `App\Service\NavigationPresentation`. De CSS-klasse
komt uit die klasse, nooit uit de database, en het zijn bestaande klassen uit
`core.css`: geen nieuwe knopvormgeving.

### Waarom één model en geen tweede tabel

De headerknop had al precies de vorm van een menu-item: dezelfde
linkvelden, door dezelfde `App\Service\LinkResolver`. Wat hij miste was een
volgorde, een zichtbaarheidsschakelaar en de mogelijkheid om er meer dan één
te hebben, en `nav_items` heeft die alle drie. Eén model levert daarbij gratis:

- **dezelfde bestemmingen**: een pagina (op id), een vast onderdeel
  (`RouteRegistry`), een ander adres;
- **dezelfde veiligheid**: een pagina op concept of verwijderd, of een route of
  pagina van een uitgeschakelde module, laat de knop verdwijnen in plaats van
  naar een 404 te wijzen;
- **dezelfde paginaverwijzingen**: `PageUsage` toont een knop in de lijst vóór
  een adreswijziging, en `PageService::references()` weigert een pagina te
  verwijderen zolang er een knop naar wijst — precies zoals bij een menu-item.

Wat een knop **niet** kan, bewaken `NavigationPresentation::errors()` en
`api/admin/_nav_item_input.php`: geen knop in een submenu, geen knop zonder
bestemming (*Nergens heen* is alleen een kop boven een submenu), en een link
die nog submenu-items heeft kan geen knop worden.

### De bestemming

```text
link_type   page      target_page_id   een pagina van deze website
            route     target_route     een vast onderdeel (RouteRegistry)
            external  external_url     https://… of een pad dat met / begint
            none      —                alleen een kop boven een submenu
```

Alleen het veld dat bij de gekozen soort hoort wordt opgeslagen; de andere
twee worden leeg. Het scherm noemt deze velden nooit bij hun technische naam:
*Een pagina van deze website*, *Een vast onderdeel van de website*, *Een ander
adres*.

**Verdwijnen is niet vergeten.** Een item waarvan de bestemming nu niet
bestaat, blijft bewaard. Het overzicht zegt *Niet op de website* en de editor
zegt waarom. Een opgeslagen route van een uitgeschakelde module blijft in de
editor geselecteerd, en opslaan houdt haar vast; zet je de module weer aan,
dan staat het item er weer zonder dat iemand iets opnieuw invult.

### Volgorde

Per groep: één ouder (het hoogste niveau of één submenu) **en** één
presentatie. Het menu en de knoppen hebben dus elk hun eigen volgorde, en een
knop schuift nooit tussen twee menulinks door.

- **↑ en ↓** op elke rij (`move-nav-item.php`, `NavigationRepository::move()`):
  werkt met het toetsenbord, op een telefoon en zonder JavaScript.
- **Slepen** blijft voor een muis (`reorder-nav-items.php`, met `presentation`
  erbij). De sleepgreep is `aria-hidden`; ↑ en ↓ zijn de toegankelijke weg.
- Wordt een link een knop, of andersom, dan sluit hij achteraan de nieuwe groep
  aan.

### In de header

- **Geen knoppen**: geen `.header-buttons` in de markup.
- **Eén knop**: dezelfde markup als de oude ene headerknop,
  `<a href="…" class="btn btn--sm" data-nl="…" data-en="…">`, op dezelfde plek.
- **Meerdere knoppen**: naast elkaar in hun eigen volgorde. Een lange tekst
  breekt binnen zijn eigen knop af (maximaal 16rem breed) in plaats van de
  header breder dan het scherm te duwen.
- **Mobiel**: de knoppen staan in `#main-nav`, het paneel dat de menuknop
  opent, onder de links. De rij met taalwissel, winkelwagen en knoppen loopt
  gecentreerd door naar een volgende regel als hij niet past.

Geen tekst in geen enkele taal = geen knop. Geen werkende bestemming = geen
knop.

### De actieve link

Een menulink krijgt `aria-current="page"` als hij de pagina is waar de
bezoeker op staat (`NavigationService::isCurrent()`):

- een **route**-link via de sleutel die het sjabloon als `$activeNav` zet,
  zoals altijd;
- een **pagina**-link op zijn eigen adres, vergeleken met het opgevraagde pad.
  `/` en `/index.php` zijn dezelfde pagina. Dit is nodig sinds
  `20260908260000` Diensten, Portfolio, Over mij en Contact paginalinks maakte:
  die kregen daarna nooit meer een markering.

Een extern adres en een submenukop zijn nooit actief. Een submenu-item krijgt
geen markering; dat is ongewijzigd gebleven.

### De oude ene headerknop

Tot fase A was er precies één knop, als acht instellingen in `site_settings`
(`header_cta_*`) op het scherm *Header & footer*. Migratie `20260916230000`
voegde de twee kolommen toe en zette een ingestelde knop éénmalig over als één
item met `presentation = button`:

- tekst, soort bestemming, het bijbehorende veld en *nieuw tabblad* zoals ze
  waren; stijl `primary`, dus dezelfde knop;
- zichtbaar precies als de oude knop zichtbaar was (aan én een Nederlandse
  tekst); een knop die uit stond komt verborgen over, niet weg;
- een pagina-id die niet meer bestaat wordt `NULL` (de foreign key eist het,
  en het rendert niets, net als vroeger);
- een verse installatie heeft geen knop en krijgt er geen.

**De oude rijen blijven staan.** Niets leest of schrijft ze nog: de header leest
`nav_items`, en het opslaan van *Slotregel & social media* raakt ze niet meer
aan. Ze echt verwijderen is een aparte, destructieve beslissing, net als bij
`page_heroes.breadcrumb_label_*`.

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
code    geen headerknoppen (een verse installatie krijgt er geen)
        slotregel uit, leeg
        alle social-URL's leeg

rij     migratie 20260909220000 schreef "Vraag offerte aan" /
        "Request a quote" naar de Contact-pagina, en de slotregel,
        als echte rijen — INSERT IGNORE, dus een bestaande rij
        wint altijd; migratie 20260916230000 zette die knop over
        naar nav_items
```

Voor social profielen is niets gemigreerd: deze site had er geen, en er
worden er geen verzonnen.

Let op één eigenaardigheid van `SiteSettings::all()`: een opgeslagen **lege**
waarde valt terug op de standaard. Dat werkt alleen omdat elke standaard hier
leeg of `'0'` is. Geef een nieuwe sleutel in deze familie dus nooit een
niet-lege codestandaard, anders kan een beheerder hem niet leegmaken.

## Footer fase B (nog niet gebouwd)

Na fase A staat de footer nog verspreid over drie schermen: *Footer*
(kolommen, links, het bedrijfsblok en de copyrighttekst), *Slotregel & social
media* (de rest van het vroegere *Header & footer*) en *Site-instellingen*
(logo's, en de footer-omschrijving, die daar én op *Footer* te bewerken is).
Fase B maakt er één beheergebied van:

1. **Eén scherm Footer** met kaarten voor kolommen en links, het bedrijfsblok,
   de slotregel en de social profielen. `admin/header-footer.php` wordt een
   doorverwijzing; de footer-omschrijving krijgt één plek.
2. **Social profielen als herhaalbare items** in een eigen tabel, bijvoorbeeld
   `footer_social_links` (`network`, `url`, `sort_order`, `is_visible`).
   `network` blijft een sleutel uit de gesloten lijst `SocialProfiles::NETWORKS`,
   dus iconen en domeincontrole blijven van Core; wat erbij komt is een
   volgorde en verbergen.
3. **Migratie** zet elke ingevulde `social_<netwerk>_url` om in één rij, in de
   volgorde van het register, en laat de instellingen staan. Zelfde aanpak als
   `20260916230000`: idempotent, geen verwijderingen, een verse installatie
   krijgt niets.
4. **Twee keer hetzelfde netwerk** (twee Instagram-accounts) is dan mogelijk;
   `isValidProfileUrl()` blijft per rij gelden, en het toegankelijke label
   noemt het netwerk.

Tot dan leest `SocialProfiles::forFooter()` de zeven vaste sleutels.

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
gelezen, in beide talen. Er wordt niets gekopieerd: hernoem je een pagina, dan
verandert het kruimelpad mee, en vertaal je hem, dan vertaalt het kruimelpad
mee. De paginatitel is een gewoon tweetalig veld: `pages.title` is de
hoofdtaal en `pages.title_en` de vertaling, gelezen via
`App\Service\PageContent::titleValue()` — de enige plek die die twee kolommen
kent. Een lege vertaling betekent "hetzelfde als de hoofdtaal"
(`MULTILINGUAL.md`), nooit een lege naam.

De **slug verandert niet mee**. Beide talen wonen op één URL; gelokaliseerde
adressen zijn bewust uitgesteld (`MULTILINGUAL.md`, *Wat V1 bewust niet doet*).
Een adres wordt alleen uit de hoofdtaaltitel gemaakt, en alleen bij het
aanmaken.

### Waar het staat

| Onderdeel | Waar |
|---|---|
| Eén niveau | `App\Service\Breadcrumbs\BreadcrumbItem` — label NL/EN en een adres, of geen adres |
| Het hele pad | `App\Service\Breadcrumbs\BreadcrumbTrail` — `home()`, `to()`, `toPage()`, `toRoute()` |
| Een gewone CMS-pagina | `App\Service\Breadcrumbs\PageBreadcrumb::forPage()` |
| Opslag van de naam | `pages.title` + `pages.title_en` (vertaling optioneel, `NULL` = niet vertaald) |
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
- `toRoute('cart')` hangt er een **applicatieroute** onder, met het label en
  het adres uit `App\Service\RouteRegistry`. Een sleutel die het register
  niet kent voegt niets toe, zodat een pad nooit naar een 404 wijst.
- `toPage('shop', 'shop')` combineert de twee: de Shop-pagina als die bestaat,
  anders de gelijknamige route. Een installatie waarvan de winkel de
  moduleoverzichtspagina is en geen `pages`-rij heeft
  (`INSTALL-BOOTSTRAP.md`) houdt zo hetzelfde niveau.
- De **homepagelink** komt uit `PageContent::publicUrl()` van de siteroot:
  één spelling, dezelfde resolutie als het menu, de canonical en de sitemap.
- Een niveau dat een bezoeker leeg zou zien valt weg, en een pad met alleen
  *Home* erin rendert niets.

### Per routegroep

| Groep | Kruimelpad | Schakelaar |
|---|---|---|
| Siteroot (`/`) | Nooit — je begint er | — |
| Gewone CMS-pagina, en de sjablonen Diensten, Portfolio, Over mij, Contact, Shop | `Home / paginatitel` | `pages.show_breadcrumb` |
| Shop-routes: product, collectie, winkelwagen, afrekenen, bestelstatus, personaliseren | Vast, met hun eigen niveaus; het Shop-niveau is de Shop-pagina zelf | Geen — geen `pages`-rij |
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
worden niet meer gelezen en niet meer overschreven. Wat een redacteur ooit in
het **Engelse** label typte is wél éénmalig overgenomen als `pages.title_en`
(migratie `20260916140000`), want dat was de enige plek waar de Engelse naam
van een pagina kon staan; alleen waar de pagina nog bestaat, `title_en` nog
leeg is en het label iets anders zegt dan de Nederlandse titel. Verder: de INSERT van
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

`fast` bevat `NavigationServiceTest` (menu, knoppen en de actieve link, zonder
database), `NavigationPresentationTest` (de twee gesloten lijsten en wat een
knop niet mag), `HeaderFooterSettingsTest` (slotregel en social profielen,
zonder database) en `HeaderFooterContractTest` (geen sitespecifieke tekst of
bestemming meer in de gedeelde schil, en de knoppen uit de navigatie). `cms`
voegt toe:

- `NavigationRepositoryTest` — opslaan, de volgorde per groep, ↑ en ↓;
- `NavigationAdminHttpTest` — het scherm en zijn endpoints over echt HTTP, en
  wat de publieke header daarvan maakt, ook met de Shop uit;
- `HeaderFooterRenderingTest` — een knop naar een CMS-pagina tegen echte rijen,
  en wat een pagina echt rendert, ook op de CMS-only deployment;
- `HeaderButtonMigrationTest` (ook in `migration`) — de overzetting van de
  oude knop op wegwerpdatabases.

`LegacyUpgradeTest` (suite `migration`) laat zien dat een bestaande site na
alle migraties precies één zichtbare knop naar de Contact-pagina heeft. Zie
verder `TESTING.md`.

Raakte je een **taalveld** van de navigatie of de footer aan — een menulabel,
een kolomtitel, een footerlink of de footertekst — dan is
`Tests\Repository\LocalizedNavigationFooterPersistenceTest` (suite `cms`) de
test die vasthoudt dat het opslaan van de ene taal de andere niet
overschrijft, en `MULTILINGUAL.md` de regel erachter.
