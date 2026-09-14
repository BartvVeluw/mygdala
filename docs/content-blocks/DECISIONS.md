# Beslissingen — content-blokken

De blijvende keuzes achter de architectuur in `ARCHITECTURE.md`, met de reden
erbij. Alle beslissingen hieronder zijn genomen tijdens de content-blok-refactor
(2026-09-08); wat er per fase precies is gebouwd en gemigreerd staat in `MAIN.MD`
en in de git-historie — dit is geen changelog.

Voeg hier alleen iets toe als het een **architecturale** keuze is die een
volgende wijziging moet kennen. Formuleer als: beslissing + reden (+ gevolg, als
dat niet uit de code blijkt).

## Vaste template-inhoud is zelf een blok

Elk stuk inhoud dat een paginatemplate tússen de page-builder-secties door
hardcodeerde is een bloktype geworden met `manual_add = false`. Daardoor bestaat
er geen inhoud meer buiten de blokkenlijst en kon `page_sections.zone_key` weg.

Reden: zones bestonden alleen omdat vaste template-inhoud niet mocht verschuiven
bij het herordenen. Zodra die inhoud zelf een positie in de lijst heeft, is dat
probleem verdwenen — en is "één geordende lijst per pagina" ook echt haalbaar
zonder een tweede paginabouwer ernaast. `AdminPageRegistry` (de tweede registry
voor die vaste inhoud) is daarmee opgegaan in `SectionRegistry`.

## Bescherming volgt de functionaliteit, niet het template

`pages.is_system` deed twee dingen tegelijk ("heeft een eigen PHP-template" én
"mag niet hernoemd/gedepubliceerd/verwijderd worden"). Dat zijn nu drie losse
begrippen (`hasOwnTemplate()`, `isRouteBound()`, `isProtected()`), en
`isProtected()` kent precies twee redenen: site-root, of een blok met
`app_critical`.

Reden: een template is een renderdetail. De echte vraag is of de webshop
stukloopt als een pagina verdwijnt, en dat antwoord hoort bij het blok dat de
functionaliteit draagt — niet bij een paginanaam. Zo verhuist de bescherming
vanzelf mee als het blok ergens anders komt te staan.

Gevolg: bij een mislukte lookup valt `isProtected()` terug op de striktere,
oude regel — bij een destructieve actie is voorzichtig degraderen de juiste
keuze. En omdat vier pagina's daarmee verwijderbaar werden, moesten hun
templates een echte 404 gaan geven en moesten menu-/footerlinks ernaartoe van
`link_type = 'route'` naar `'page'`: een route-link is een hardcoded pad dat
blijft bestaan nadat de pagina weg is, en `PageService::references()` ziet 'm
niet.

## Een blok-instantie is `(page_slug, section_key)`, altijd

Ook een bloktype dat vandaag maar één keer voorkomt wordt zo gesleuteld.

Reden: `page_slug`-only is een verkapte "maximaal één per pagina, voor altijd" —
de `ON DUPLICATE KEY UPDATE` in de repository overschreef stilletjes de eerste
instantie. Herhaalbaarheid is dus geen registryvlag maar een schemafeit; alleen
de vlag zetten had dataverlies opgeleverd.

Gevolg: verwijderen gaat per instantie (`deleteSection($id)`), nooit per pagina.
Een template dat een blok van een andere pagina "leent" (zoals
`portfolio-detail.php` met de CTA van Portfolio) vraagt om *de eerste instantie
op die pagina*, zodat er geen `section_key` gehardcodeerd hoeft te worden.

## Geen hardcoded fallback-copy meer

Blokken hebben geen `DEFAULTS`-constanten meer als "database onbereikbaar"-
vangnet. Geen inhoudsrij = niets renderen, en dan ook echt niets: geen leeg
kader met verticale ruimte.

Reden: dat vangnet kon niets meer vangen — een blok wordt alleen via
`SectionRegistry::renderPage()` bereikt, en die rendert bij een mislukte
paginalookup sowieso niets. Twee bronnen van waarheid voor dezelfde copy
leveren alleen maar stille afwijkingen op.

## Het contactblok is formulier + kaart, niet drie blokken

`contact_form` ("Offerte-/contactformulier") bevat het formulier én de kolom
"Direct contact" ernaast, en mag **maximaal één keer per pagina**. De kaart
"Liever direct mailen?" is een eigen, herhaalbaar blok (`contact_card`,
CMS-naam **Contactkaart**).

Reden: het maximum van één is een technisch feit, geen smaak — de veld-id's
(`f-naam`, `f-email`, …) staan vast in de markup, dus een tweede formulier
levert dubbele DOM-id's en kapotte `<label for>`. "Direct contact" is de tweede
kolom van diezelfde grid en draagt geen eigen pagina-inhoud (adres en plaats
komen uit Site-instellingen); er een los blok van maken zou de tweekolomsindeling
breken zonder iets bewerkbaar te maken.

Gevolg: een lege knop-URL op een Contactkaart betekent `mailto:` het adres uit
Site-instellingen. Dat is de gedocumenteerde standaard van het bloktype, geen
uitzondering voor één pagina.

## Een vaste set structurele sleutels is geen bloktype

De `services`-tabel (vier in code vastgelegde sleutels `hout`/`metaal`/
`acryl-glas`/`zakelijk`) is opgegaan in twee gewone bloktypes: **Detailsectie**
(`detail_section`) en **Kaarten-carrousel** (`card_carousel`), allebei overal
toegestaan, herhaalbaar en verwijderbaar.

Reden: die vier sleutels waren de laatste plek waar "welke inhoud bestaat er"
een codewijziging was in plaats van een redactiehandeling. De carrousel kon
bovendien alleen de diensten tonen, en de vier detailblokken konden niet op een
andere pagina staan, niet vermenigvuldigd worden en niet weg.

Gevolg (bewust): de "homepage teaser"-velden waren een projectie van dezelfde
rij naar een tweede weergave. Die koppeling is losgelaten — een carrouselkaart
is nu gewoon een kaart. Prijs: een titelwijziging op Diensten werkt de
homepagekaart niet meer automatisch bij. Winst: de carrousel is overal te
gebruiken en hoeft niet over "diensten" te gaan.

## Anker en navigatielabel horen bij de sectie, niet bij de nav

Een Detailsectie draagt zelf haar `anchor` (leeg = niet aanlinkbaar) en een kort
`nav_label`. De quicknav is daarvan afgeleid: hij toont elke actieve sectie met
een anker, in de blokvolgorde van de pagina.

Reden: ankers als `#hout` zijn echte afhankelijkheden buiten de pagina (de
footerkolom "Materialen" linkt eruit). Zodra secties toevoegbaar, verwijderbaar
en herordenbaar zijn, is een hardcoded ankerlijst in de quicknav gegarandeerd
een keer onwaar. Een anker dat bij de sectie hoort, verhuist met de sectie mee.
Het aparte navigatielabel bestaat omdat een kop ("Hout graveren") langer is dan
wat in een nav past ("Hout"); leeg = val terug op de titel.

## Een vast blok mag afgeleid zijn

`quicknav` is een vast blok waarvan de inhoud niet "ergens anders in het CMS"
wordt beheerd maar wordt berekend uit andere blokken op dezelfde pagina. Zo'n
type krijgt wél een `note`, maar geen `badge_label` en geen `edit_links`.

Reden: verwijzen naar een editor die niet bestaat laat de beheerder zoeken naar
een scherm dat er niet is. De eerlijke tekst is "dit volgt de secties op deze
pagina".

## De inhoudsbron is een gesloten lijst, geen query-builder

`item_gallery` (**Portfolio-/collectiegalerij**) heeft één instelling
`source_type` met een expliciete lijst waarden in `ItemGallerySources`, die de
modules aanvullen met `itemGallerySources()`: `portfolio` van Portfolio,
`collection` van de Shop. Een derde bron is straks één bijdrage in de module
die de inhoud bezit.

Reden: ruimte voor toekomstige bronnen zonder generieke abstractie. De gesloten
lijst is bovendien de veiligheidsgrens: `source_type` uit een request wordt eerst
gevalideerd (het endpoint weigert vóór het opslaan) en bij het lezen nog eens,
zodat een handmatig aangepaste rij terugvalt op de eerste beschikbare bron in
plaats van blind uitgevoerd te worden.

Gevolg: een niet-gekozen, onbekende of gedepubliceerde bron levert géén items
(en dus geen blok), nooit een terugval op een andere bron. Een collectiebron
linkt naar de canonieke `/product.php?id=…`, nooit naar een URL onder
`/collecties/`.

## De Portfolio-galerij en de homepage-uitlichting waren hetzelfde blok

`portfolio_gallery` en `portfolio_teaser` zijn allebei opgegaan in
`item_gallery`. Ze renderden hetzelfde component en verschilden alleen in wélke
items, of er een filterbalk/lightbox was, en welke kop-, slot- en knopteksten het
template eromheen hardcodeerde — elk van die verschillen is nu een veld.

Reden: één van de twee laten staan zou betekenen dat de beheerder twee manieren
heeft om hetzelfde te doen. En "herbruikbaar op passende pagina's" is pas waar
als de tweede bestaande weergave er ook echt een instantie van is.

Gevolg: drie velden bestaan uitsluitend om beide oude weergaven exact te kunnen
blijven tonen — `background`, `tight_top` en `fallback_link_url` (waar een kaart
zónder eigen projectpagina heen linkt; leeg = die kaart is geen link, en juist
dán kan de lightbox 'm vergroten). Eén regel die beide oude gedragingen dekt, in
plaats van een `if` per pagina.

## Weergave-instellingen horen bij het blok, dus de catalogus is geen sectie

De zichtbaarheid van de portfolio-sectie is de `is_active` van het blok
geworden; `portfolio_galleries` is puur nog de itemcatalogus (zonder
`page_slug`, `section_key` en `is_active`).

Reden: zolang de catalogus zelf een pagina-positie en een zichtbaarheidsvlag
droeg, waren er twee plekken die "wordt dit getoond?" beantwoordden — en één
daarvan wist niet eens hoevéél galerijen er inmiddels waren. Met een herhaalbaar
blok is dat geen detail meer maar een echte tegenstrijdigheid.

## Blok-JS is per blok gescoped, niet per pagina

`initFilters()` en `initLightbox()` werken per `[data-gallery-block]` in plaats
van op de eerste `.filter-bar` / `.gallery-grid` van de pagina.

Reden: `document.querySelector()` betekent "de eerste op de pagina". Zodra een
blok herhaalbaar is, filtert de balk van de tweede galerij het raster van de
eerste — een stille bug die pas opvalt als iemand het blok twee keer plaatst.

Gevolg: de lightbox-overlay wordt door een blok geprint in plaats van door het
paginatemplate, maar bewust buiten de `<section>`; welk blok 'm print bepaalt
`ItemGalleryContent::claimLightboxOverlay()` (en niet een `static` in de partial,
zodat een test die meerdere blokken rendert niet aan de eerste vastzit).
