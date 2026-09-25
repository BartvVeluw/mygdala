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
functionaliteit draagt — niet bij een paginanaam. (Sinds het productoverzicht
een keuze is, draagt geen blok meer `app_critical`; het mechanisme blijft.) Zo
verhuist de bescherming
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
komen uit Instellingen); er een los blok van maken zou de tweekolomsindeling
breken zonder iets bewerkbaar te maken.

Gevolg: een lege knop-URL op een Contactkaart betekent `mailto:` het adres uit
Instellingen. Dat is de gedocumenteerde standaard van het bloktype, geen
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

Sinds Portfolio-fase 4B bepaalt de Portfolio-bron het gedrag van elke kaart
zelf, en sinds Portfolio 2.0 is dat: de kaart is nooit een link, de afbeelding
opent altijd de lightbox (`opens_lightbox`, ook als de lightbox-instelling van
het blok uit staat), en *Bekijk project* (`cta`) is een aparte link naar een
gepubliceerde legacy-pagina of de eigen projectpagina (`MODULES.md`). Een
portfolio-item volgt `fallback_link_url` daarom nooit (`follows_fallback_link`
is `false` op het item). Reden: een klik op een afbeelding moet op elke kaart
hetzelfde doen, en navigeren is de taak van de knop. Voor de kaarten van andere
bronnen doen het veld en de lightbox-instelling wat ze deden.

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

  *Later:* Paginakop 2.0 maakte die extra keuze toch, zie hieronder.
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

## Paginakop 2.0: waar het beeld staat, hoe hoog de band is, en het pad erin

Drie klachten over de kop met een foto: de tekst zakte naar beneden, de
hoogte lag vast, en boven de foto stond een lege balk met alleen het
kruimelpad. Daarbij de wens om het beeld ook naast de tekst te kunnen zetten.

**De oorzaak van het zakken.** `.page-hero--media` was een flexkolom met
`justify-content: flex-end` en een `min-height` van ongeveer 55vh: de tekst
werd dus naar de onderkant van een band geduwd die hoog gemaakt was voor de
foto. Hoe hoger de band, hoe lager de tekst. Dat is weggehaald in plaats van
gecompenseerd. De foto was al een absoluut geplaatste laag (`inset: 0`,
`z-index: -1`) en duwde zelf niets; nu centreren automatische marges
(`margin-block: auto`) de tekst in de ruimte die de band overlaat. Geen
negatieve marges.

**De lege ruimte erboven** kwam uit 5B: het kruimelpad stond sindsdien in een
eigen balk vóór de kop, en die balk droeg de hele vrije ruimte voor de vaste
siteheader, op de kale grond. Nu neemt de eerste Paginakop met een beeld het
pad op (`App\Service\Blocks\CarriesBreadcrumb`, gevraagd door
`SectionRegistry::renderPage()`), en de band begint bovenaan de pagina met
alleen de hoogte van de siteheader plus wat lucht erboven. Dat verandert de
afspraak van 5B niet: het pad blijft van de pagina, met de schakelaar van de
pagina, en elke pagina zonder zo'n kop houdt het op zijn oude plek. Een
optionele interface in plaats van een nieuwe methode op `BlockDefinition`,
omdat maar één blok dit ooit doet en die klasse alleen abstracte methodes
heeft.

**De keuzes**, gesloten lijsten zoals die van 5A (`PageHeroContent`):

- `image_mode`: `none`, `background`, `left`, `right`. Een bestaande kop met een
  foto werd `background` (migratie `20260924120000`), nooit stil `left`/`right`.
  *Geen afbeelding* maakt ook de verwijzing leeg, zodat de bibliotheek het item
  niet als gebruikt meldt voor een beeld dat nergens staat.
- `hero_height`: `small`, `medium`, `large`, alleen bij een achtergrond, als
  minimale hoogte (een lange titel maakt de band hoger). `medium` is precies
  de oude hoogte; op een smal scherm zijn alle drie lager, zodat groot geen
  heel scherm vult. De maten staan als `clamp()` in `page-hero.css`, nooit in
  de database.
- `image_focus`: de bestaande negen punten van `App\Service\Media\ImageFocus`
  (de Kaarten-carrousel en Tekst met afbeelding), als `object-position`, met
  in de editor hetzelfde veld (`media_focus_field()`). Geen tweede helper.

**Naast de tekst** is één vaste verhouding (tekst ongeveer 58%, beeld 42%, in
een kader van 4:3 met `object-fit: cover`) zonder breedtekeuze. De tekst
staat in de markup altijd eerst, zodat de leesvolgorde niet van een
lay-outkeuze afhangt; op een smal scherm staat het beeld links én rechts
boven de tekst, één volgorde op een telefoon.

**Alt-tekst.** Een beeld achter de tekst is versiering (`alt=""`); de woorden
van de kop zeggen al waar de pagina over gaat. Een beeld naast de tekst is
inhoud en krijgt de gewone gelaagde alt-tekst (`MEDIA.md`), dus kreeg de
Paginakop alsnog een eigen `image_alt` als woord in `block_translations`.

**De waas.** Gecentreerde tekst staat hoger dan onderaan vastgezette tekst,
dus buiten het donkerste deel van de waas. Gemeten op een volledig witte foto
zakte het bovenschrift op een telefoon naar 3,7:1; het midden van de waas op
een smal scherm en van de gecentreerde waas is daarom iets dichter gemaakt
(randen ongewijzigd). Gemeten na de wijziging: kruimelpad 7,5:1 of meer,
bovenschrift en inleiding 4,5:1 of meer, titel ruim boven 3:1.

## Leeg is leeg: bovenlabel, kaarttitel en carrouselnummer (UX-polijstfase)

Drie velden rendeerden iets als ze leeg waren. Het bovenlabel (eyebrow) was in
vijf blokken verplicht en tekende in twee blokken ook leeg een `<p>` met het
lijntje van `.eyebrow::before`; de titel van een kaart in *Kenmerken in
kaartjes* was verplicht; en een leeg nummer van een carrouselkaart werd "01",
"02", ... naar de plaats van de kaart.

Besluit: **leeg betekent overal niets renderen.** Het bovenlabel is in geen
enkel blok meer verplicht en gaat door één helper (`partials/eyebrow.php`,
`render_eyebrow()`), die bij een lege waarde niets print; de ruimte die bij
een bovenlabel hoort hangt aan het bovenlabel (`.section-head .eyebrow + h2`),
zodat er ook geen marge overblijft. Een kaart zonder titel toont geen kop.

Het carrouselnummer is een bewuste contractwijziging, en een bestaande site
mocht er niet ineens anders door uitzien. Daarom schreef migratie
`20260923170000` bij elke kaart die nu een automatisch nummer toonde dat
nummer als gewone inhoud weg, in de standaardtaal (vertalingen vallen erop
terug, zoals altijd). Daarna is leeg echt leeg en begint een nieuwe kaart
zonder nummer. Een kaart die verborgen was of geen titel had, toonde niets en
kreeg ook niets. Het alternatief, een schakelaar "automatisch nummeren" per
carrousel, is bewust niet gekozen: het had het oude gedrag als tweede
toestand bewaard, terwijl de eigenaar nu gewoon het nummer typt dat hij wil.
