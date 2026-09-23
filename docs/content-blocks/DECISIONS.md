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

Sinds Portfolio-fase 4B bepaalt de Portfolio-bron de link van elke kaart zelf:
de gekoppelde pagina, anders tijdelijk de oude projectpagina, anders niets
(`MODULES.md`). Een portfolio-item volgt `fallback_link_url` daarom nooit meer
(`follows_fallback_link` is `false` op het item). Reden: een kaart zonder eigen
pagina mag niet via een instelling van het blok alsnog klikbaar worden. Voor de
kaarten van andere bronnen doet het veld wat het deed.

## Projecten is de galerij met een vaste bron, geen tweede galerij

`project_cards` (**Projecten**) is een bloktype van de Portfolio-module, maar
geen tweede galerij. Het bewaart in dezelfde `item_galleries`-rij, leest via
`ItemGalleryContent`, tekent met `partials/section-item-gallery.php` en dezelfde
CSS en JS, en krijgt zijn items en kaartlinks van de bron `portfolio`. Het
verschil is presentatie: de bron staat vast, en de editor laat weg wat voor een
project niets doet (collectie, lightbox, fallback-link, slottekst, knop).

Reden: de blokkenkiezer biedt alleen bloktypes aan, en een module kan alleen via
`blockDefinitions()` een keuze toevoegen die meeverdwijnt als de module uit gaat.
Een redacteur die projecten op een pagina wil, hoeft zo niet eerst te begrijpen
dat een galerij een inhoudsbron heeft. De beslissing hierboven blijft staan:
functioneel is Projecten dezelfde instantie, en er kwam geen tweede query, kaart,
linkresolutie of stylesheet bij. Een preset-mechanisme in de kiezer had de
kiezer, `add-page-section.php`, `SectionRegistry::create()` en de `create()` van
elk blok geraakt.

Gevolg: twee bloktypes delen één tabel, dus bewerkt een editor alleen de rijen
die zijn eigen bloktype plaatste (`page_sections.section_type`), en schrijft
`ProjectCardsBlock::rowValues()` bij elke opslag de vaste waarden opnieuw. De
twee delen `item-gallery.css` en `item-gallery.js`
(`FrontendAssetOwnershipTest::SHARED_BLOCK_ASSETS`). En portfolio-items blijven
ook een bron van de galerij, voor de Portfolio-pagina, de homepage-uitlichting en
wie een lightbox, slottekst of knop wil: twee ingangen, één implementatie.

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

## De Paginakop kiest uit woorden, en zijn beeld ligt achter de tekst

De Paginakop (`page_hero`) kreeg een optioneel beeld uit de Mediabibliotheek, een
positie voor de tekst (links, midden, rechts) en een grootte voor de titel en voor
de inleiding (klein, normaal, groot). Het bovenschrift is niet meer verplicht.

Reden, per keuze:

- **Het beeld ligt achter de tekst**, onder een waas in de grondkleur van de site
  (`--color-media-scrim-rgb`, het token dat de homepage-hero daar al voor
  gebruikt). Zo betekent de positie van de tekst met en zonder beeld hetzelfde. Een
  beeld náást de tekst had bij *Midden* een tweede lay-out nodig gehad, en de
  redacteur een extra keuze.
- **Geen video.** De bibliotheek neemt alleen afbeeldingen aan
  (`App\Service\Media\MediaType`). De enige video in dit project is die van de
  homepage-hero: een eigen upload naar `assets/videos/sections/`, naast een apart
  afbeeldingsveld en zonder gebruiksregistratie. Video in de Paginakop was dus een
  tweede uploadsysteem geworden, of een uitbreiding van de bibliotheek met een
  tweede soort bestand. Dat is een eigen stap. Neemt de bibliotheek ooit video aan,
  dan kan dezelfde `media_id` er een aanwijzen.

  *Later:* de bibliotheek neemt sinds de fase "Media, Homepage Hero & Admin UX"
  MP4 en WebM aan, en de homepage-hero kiest zijn video daar
  (`homepage_hero.video_media_id`). De Paginakop heeft nog steeds geen video;
  dat is nu alleen nog een ontwerpkeuze, geen technische grens.
- **Drie stappen per grootte.** Voor de titel zijn dat `--fs-h2`, `--fs-h1` en één
  nieuwe schaalstap `--fs-display` in `core.css`, voor de inleiding `--fs-body`,
  `--fs-lead` en `--fs-h3`. Een vierde stap had nog een verzonnen maat gevraagd die
  op een telefoon niet past; `--fs-display` houdt daarom de ondergrens van h1.
- **Een standaard is geen class.** `left`, `normal` en `normal` zijn hoe elke
  Paginakop er al uitzag. Ze voegen geen modifier toe, dus een bestaande kop valt
  na de migratie onder geen enkele nieuwe CSS-regel.

Gevolg: `page_heroes` kreeg `media_id` (zonder `image_path`-tweeling en zonder eigen
alt-tekst, `MEDIA.md`) en drie `VARCHAR(20)`-kolommen met die standaard als default.
`PageHeroContent` houdt de gesloten lijsten en leest een onbekende waarde als de
standaard; `update-page-hero.php` weigert hem.

## Het kruimelpad hoort bij de pagina, niet bij de Paginakop (5B)

De vraag die 5A openliet. Het kruimelpad zat in de partial van de Paginakop,
dus een pagina zonder dat blok — of met dat blok verborgen — had er geen. Het
gaat over waar een bezoeker *is*, en dat verandert niet als een kop verdwijnt.

Gevolg: één generieke renderer (`partials/breadcrumb.php`) met kleine
waarde-objecten erachter (`App\Service\Breadcrumbs`), aangeroepen door de
route vóór de inhoud van de pagina. De naam komt uit de titel van de pagina
(sinds Multilingual 2.0 fase 2 per taal in `page_translations`) in plaats
van uit een tweede, met de hand overgetypte kolom, en of een pagina er een
toont is `pages.show_breadcrumb` — een eigenschap van de pagina, niet van een
blok. `page_heroes.breadcrumb_label_nl/en` bleven eerst staan als legacydata;
Multilingual 2.0 fase 3B heeft ze gedropt (`20260917180000`).

Wat bewust níet meebewoog: er komt geen `BreadcrumbList`-JSON-LD bij
(`SEO.md`), en de Paginakop is verder ongemoeid gebleven.
