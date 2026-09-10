# Content-blokken — architectuur

De refactor naar herbruikbare CMS content-blokken is afgerond (2026-09-08).
Dit document beschrijft de architectuur zoals die er **nu** staat, niet een
beoogde eindsituatie. Bij twijfel is de code leidend: `App\Service\SectionRegistry`
is de bron van waarheid voor bloktypes, `App\Service\PageContent` voor
paginabescherming. Wijkt de code af, pas dan dit document aan — niet andersom.

## Model

```text
Page
└── één geordende lijst van content-blok-instanties
```

Eén pagina = één consistente, geordende lijst blok-instanties (`page_sections`,
één doorlopende `sort_order`-reeks). Geen aparte "boven"- en "onder"-gebieden en
geen tweede lijst ernaast; `zone_key` bestaat niet meer. Elk paginatemplate
rendert die lijst precies één keer via `SectionRegistry::renderPage($contentKey)`.

Inhoud die een paginatemplate vroeger zelf hardcodeerde staat óók in die lijst,
als **vast blok**: een gewone, herordenbare en te verbergen instantie die alleen
niet handmatig toe te voegen of te verwijderen is (`manual_add = false`,
`deletable = false`, `max_instances = 1`, en `section_id = 0` — samen met de
bestaande `UNIQUE(section_type, section_id)` dwingt dat "maximaal één in de hele
site" op databaseniveau af). Vaste inhoud buiten de lijst houden zou de lijst
weer opsplitsen; dát sluit deze architectuur uit.

Er zijn nog drie vaste blokken: `shop_collections`, `product_grid` en
`quicknav`. Al het andere is een gewoon, toevoegbaar blok.

## Blok-instanties

- Een instantie wordt geadresseerd op `(page_slug, section_key)` — altijd, ook
  bij een type dat vandaag maar één keer voorkomt. Alleen `page_slug` is een
  verkapte "maximaal één per pagina, voor altijd": herhaalbaarheid is een
  schemafeit, niet alleen een registryvlag.
- Een blok-instantie heeft **eigen** inhoud/configuratie, tenzij het blok
  expliciet als gedeelde/globale inhoud is ontworpen (bv. Site-instellingen).
- Elk bloktype heeft één inhoudstabel, één partial (`partials/section-*.php`),
  één editor (`admin/<type>.php`) en zijn eigen `api/admin/*`-endpoints.
- **Geen hardcoded fallback-copy.** Geen inhoudsrij (of een bron zonder items)
  betekent: het blok rendert niets, en laat ook geen gat achter — geen lege
  sectie met verticale ruimte.
- Tweetaligheid: NL is de inhoud, EN is optioneel; leeg EN = zelfde als NL.
- Rich content loopt altijd via de Quill-editor en `RichTextSanitizer` (bij
  schrijven én bij lezen). Geen tweede manier om alinea's te beheren.
- Afgeleide nummering en afwisselende achtergronden tellen over de **actieve
  instanties op de pagina**, nooit over een vaste lijst.

## Pagina's versus routes

Drie **losse** begrippen; knoop ze nooit weer aan elkaar:

- **heeft een eigen template** (`PageContent::hasOwnTemplate()`) — een
  renderfeit, verder niets;
- **vaste URL** (`isRouteBound()`) — de pagina wordt op een vast pad geserveerd,
  dus haar slug ligt vast. Een routeringsfeit, geen bescherming;
- **beschermd** (`isProtected()`) — mag niet gedepubliceerd of verwijderd
  worden.

Bescherming is afgeleid van wat de pagina *is of draagt*, nooit van een
hardcoded lijst paginanamen en nooit van het bestaan van een eigen template.
Precies twee redenen tellen:

1. de pagina is de **site-root** (`route_path = '/'`) — "/" moet altijd iets
   renderen;
2. de pagina draagt een **applicatie-kritisch blok** (`app_critical => true` in
   de registry; vandaag alleen `product_grid`).

Daarmee zijn alleen de **Homepage** en de **Shop** beschermd. Diensten,
Portfolio, Over mij en Contact zijn gewone inhoudspagina's: te depubliceren en
te verwijderen, ook al ligt hun URL vast omdat een eigen bestand ze serveert.
Verhuist het productraster ooit, dan verhuist de bescherming mee.

Een niet-beschermde pagina moet ook echt weg kunnen: haar URL geeft dan een
**404** (`partials/page-not-found.php`; de paginalookup staat bovenaan het
template, want `http_response_code()` werkt alleen vóór de eerste uitvoer) in
plaats van een lege 200. Links ernaartoe zijn echte verwijzingen
(`link_type = 'page'`, geen hardcoded pad), zodat het CMS waarschuwt in plaats
van een dood menu-item achter te laten.

**Functionele/systeemroutes** (checkout, winkelwagen, bestelstatus, API's,
product-/collectiedetail) zijn geen CMS-inhoudspagina's. Ze mogen CMS-inhoud
tonen, maar zijn niet door de beheerder te verwijderen of te hernoemen.

## Centrale blokregistratie

`App\Service\Blocks\BlockDefinitions` registreert elk bloktype met één regel;
de blokdefinitie erachter declareert per type:

- interne type/key en leesbare CMS-naam (`label`);
- `manual_add` — handmatig toe te voegen via "+ Sectie toevoegen"?
- `allow_multiple` + optioneel `max_instances` — herhaalbaarheid en maximum;
- `allowed_pages` / `denied_pages` — toegestane contexten (`null` = overal);
- `deletable` — mag het blok (en zijn inhoud) weg?
- `app_critical` — hangt de applicatie zelf van dit blok af (zie bescherming);
- voor vaste blokken: `kind`, `badge_label`, `note` en `edit_links`.

Regels:

- Hetzelfde bloktype mag op elke toegestane pagina staan; van een herhaalbaar
  blok mogen meerdere instanties op dezelfde pagina staan.
- Een vast blok wordt elders beheerd; de registry vermeldt wáár (badge,
  toelichting, editor-link), zodat de beheerder nooit hoeft te raden.
- Een vast blok mag ook **afgeleid** zijn: zijn inhoud volgt uit andere blokken
  op dezelfde pagina (vandaag `quicknav`, afgeleid van de ankers van de actieve
  Detailsecties). De registry zegt dat dan met zoveel woorden en verwijst naar
  geen enkele editor, in plaats van naar een scherm dat niet bestaat.
- Verzoeken uit de browser worden altijd eerst tegen de registry gevalideerd;
  een type-key uit een request wordt nooit direct gebruikt om een klasse of
  tabelnaam op te bouwen.

## Inhoudsbronnen

Een blok mag zijn inhoud uit een **instelbare bron** halen. Die bronnen zijn
altijd een expliciete, gesloten lijst — nooit een generieke query-builder — en
een bronsleutel uit een request wordt daartegen gevalideerd vóór gebruik,
precies zoals een bloktype tegen de registry (en nog eens bij het lezen, zodat
een handmatig aangepaste rij terugvalt op de standaard). Vandaag geldt dit voor
`item_gallery` (`ItemGalleryContent::SOURCES`: `portfolio` en `collection`).

Elke bron levert dezelfde genormaliseerde itemvorm, dus het blok houdt één
renderpad, en leest door de bestaande repositories heen zodat er geen tweede
definitie van "wat zit er in een collectie" ontstaat. Wat een bron niet heeft,
toont het blok niet: alleen `portfolio` heeft een taxonomie, dus een
collectiegalerij tekent geen filterbalk — een broneigenschap, geen
pagina-uitzondering.

## Frontend

- Blok-JS is **per blok gescoped**, nooit `document.querySelector()` op één
  component per pagina: `assets/js/main.js` loopt over elk
  `[data-gallery-block]` en koppelt de filterbalk aan het raster binnen dát blok.
- Precies één lightbox-overlay per pagina, geclaimd door het eerste blok dat 'm
  nodig heeft, en geprint **buiten** de `<section>` — een `position: fixed`-
  overlay binnen een GSAP-getransformeerde sectie positioneert zich tegen die
  sectie in plaats van tegen de viewport.
- URL's in herbruikbare blokken zijn root-relatief (`/assets/…`,
  `/portfolio/<slug>`), want een blok mag op elke CMS-pagina staan, ook op een
  genest pad.

## Blijvende ontwerpregels

- Vermijd pagina-specifieke `if`-constructies waar een generieke
  blok-eigenschap of registry-regel het probleem oplost.
- **Weergave-instellingen horen bij de blok-instantie**, niet bij de pagina en
  niet bij de catalogus erachter. Een tweede instantie op dezelfde pagina staat
  er volledig los van.
- Blokken zijn data-gedreven: een blok met een variabel aantal onderdelen
  (kaarten, items, secties) wordt nooit vastgezet op een vast aantal, en "welke
  inhoud bestaat er" is nooit een codewijziging.
- Bestaande inhoud blijft behouden bij migraties/refactors. Migraties zijn
  forward-only, idempotent en MySQL/Vimexx-compatibel.
- Breid de huidige architectuur uit; bouw geen tweede, parallel
  pagina-bouwsysteem ernaast.
- We bouwen in dit project **geen** volledige visuele page builder en **geen**
  formulierbouwer.
