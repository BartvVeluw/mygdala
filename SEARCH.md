# Zoeken op de website

Mygdala heeft een eigen, optionele zoekfunctie voor bezoekers: een zoekknop in
de header, live resultaten onder het zoekveld en een gewone resultatenpagina.
Er is geen externe zoekdienst en geen zoekindex. Deze functie kwam in v0.1.12.

Wijkt de code af van dit document, dan heeft de code gelijk.

## In één oogopslag

```text
Navigatie → "Zoeken tonen"   site_settings.nav_search_enabled, standaard 0 (uit)
header                        partials/header-search.php: vergrootglas + paneel, of het veld in het telefoonmenu
live resultaten               GET /api/search.php?q=…&lang=…   → eerste 8, plus totaal en "Alle resultaten bekijken"
resultatenpagina              /zoeken?q=…  (/en/search?q=…), noindex, 10 per pagina, ?pagina=N
zoekdienst                    App\Service\Search\SearchService: providers vragen, scoren, sorteren, pagineren
providers                     Core: pagina's. Modules: producten (Shop), projecten (Portfolio), berichten (Blog)
```

## Aan en uit

Zoeken staat **uit** tot een beheerder het aanzet onder **Navigatie →
Zoeken → Zoeken tonen** (`admin/navigation.php`, opslaan via
`api/admin/update-navigation-settings.php`, recht `pages.manage`). De
instelling is één rij in `site_settings` met de standaard `0` in
`SiteSettings::DEFAULTS`. Er is **geen migratie**: een bestaande site ziet na
de update precies dezelfde header.

Staat zoeken uit, dan:

- rendert de header niets van de zoekfunctie, ook geen lege wrapper;
- laadt `App\Service\PageAssets` `assets/css/search.css` en
  `assets/js/search.js` niet;
- antwoorden `/zoeken` en `/api/search.php` met een 404.

Staat het aan, dan zijn die twee bestanden shellbestanden (op elke pagina,
na `core.css` en `core.js`), omdat de knop in de gedeelde header staat.

## Het contract: providers

```php
interface SearchProvider
{
    public function label(string $language): string;            // "Pagina", "Product" …
    public function documents(SearchQuery $query, string $language, int $limit): array; // list<SearchDocument>
}
```

`SearchService::providers()` is Core's `page` plus wat de **ingeschakelde**
modules bijdragen via `ModuleDefinition::searchProviders()`, gelezen met
`ModuleRegistry::collectMap('searchProviders')`. Het is hetzelfde patroon als
`sitemapCollectors()` en `linkTargets()`. Core importeert geen moduleklasse,
en een module die uit staat heeft geen provider, dus haar inhoud kan niet in
de resultaten komen doordat iemand een voorwaarde vergat. Een module kan
Core's `page` niet overschrijven.

| Type | Provider | Wie | Zichtbaar is | Doorzocht |
|---|---|---|---|---|
| `page` | `Service\Search\PageSearchProvider` | Core | `PageSeo::isIndexable()`: gepubliceerd, niet `noindex`, en geserveerd door een ingeschakelde module (geen pagina in de subboom van een uitgeschakelde module, geen placeholder) | titel, meta description |
| `product` | `Service\ProductSearchProvider` | Shop | `active = 1`, dezelfde regel als `product.php` en de sitemap; ook een product dat niet in het winkeloverzicht staat | naam, beschrijving |
| `project` | `Service\PortfolioSearchProvider` | Portfolio | `PortfolioSlug::isPublic()` (zichtbaar, projectpagina aan, een slug); een item dat naar een oude gekoppelde pagina doorverwijst, vindt de paginazoeker onder het adres van die pagina | titel, subtitel, intro |
| `post` | `Service\Blog\BlogSearchProvider` | Blog | het ene publieke predicaat van `BlogPostRepository` (geen concept, `published_at` gezet en niet in de toekomst) en niet `noindex` | titel, samenvatting |

Een provider:

- geeft **alleen wat een bezoeker mag zien**, met de zichtbaarheidsregel van
  zijn eigen publieke pagina, nooit een tweede kopie ervan;
- geeft **kandidaten** in zijn eigen natuurlijke volgorde. Pagina's staan in
  de volgorde van de paginalijst, producten op naam, projecten in de volgorde
  van de catalogus en berichten van nieuw naar oud. `SearchService` beslist
  wat er echt matcht;
- werkt in **batches**: een vast aantal queries per zoekopdracht, nooit één
  per resultaat. `SearchProvidersTest` meet dat met `Com_select`: 4 en 48
  resultaten kosten evenveel queries;
- levert **platte tekst**. Rich text gaat eerst door `SearchText::plain()`, en
  elk scherm escapet wat het toont.

### Een provider toevoegen

1. Een klasse die `SearchProvider` implementeert, bij het domein van de
   module (niet in `src/Service/Search/`).
2. `searchProviders(): array { return ['<type>' => new …]; }` in de
   moduledefinitie.
3. Een test in `SearchProvidersTest`: zichtbaar gevonden, verborgen niet, en
   met de module uit niets.

## De zoekvraag

`SearchQuery::fromInput()` normaliseert één keer, op de server:

- geen string of geen geldige UTF-8: een lege vraag;
- controle- en onzichtbare opmaaktekens (`\p{Cc}`, `\p{Cf}`, zoals een
  bidi-override) worden spaties, witruimte wordt samengevoegd en de uiteinden
  worden getrimd;
- hoogstens **100 tekens** (Unicode). Wat erna komt, vervalt;
- minder dan **2 tekens** heet te kort: er wordt niets gezocht en het scherm
  zegt dat.

Naast de hele vraag (de *phrase*) kent een vraag zijn **termen**
(`SearchQuery::terms()`). Dat zijn de woorden, gesplitst op witruimte, elk
gevouwen woord één keer. Een woord van één teken telt niet als term (het zou
bijna alles vinden), en na **8 termen** stopt het. Eén woord is één term: de
vraag zelf.

`%` en `_` zijn gewone tekens, ook in een term. De voorselectie van de
modules (`SearchCandidates::ids()`) gebruikt
`EntityTranslationRepository::ownersMatching()`: een prepared `LIKE` waarin
die tekens geëscapet zijn, over alle talen. Die draait één keer per veld en
per term, en een eigenaar blijft over als **elke** term in minstens één veld
staat. Het maakt niet uit in welk veld: de ene term mag in de titel staan,
de andere in de omschrijving. Pagina's hebben geen `LIKE` nodig. Er zijn er
weinig, dus alle gepubliceerde pagina's en hun woorden komen in twee queries
binnen, en `page_translations` blijft het domein van
`PageTranslationRepository` (`MultilingualBoundaryTest`).

## Rangschikken

Eén regel voor elke provider, in `SearchText::score()`. Er is geen AI, geen
woordfrequentie en geen externe dienst:

| Score | Wanneer |
|---|---|
| 400 | de titel is precies de vraag |
| 300 | de titel begint met de vraag |
| 250 | een woord in de titel begint met de vraag |
| 200 | de titel bevat de vraag |
| 100 | alleen de tekst (beschrijving, intro, samenvatting) bevat de vraag |
| 10–70 | niet de hele vraag, maar **elke term** komt ergens voor, in willekeurige volgorde, verdeeld over titel en tekst: 10 + 60 × (het deel van de termen dat in de titel staat) |

Bij gelijke score komt eerst de volgorde van de providers (pagina's, dan de
modules in registervolgorde) en daarna de eigen volgorde van de provider.
Vergelijken gaat zonder hoofdletters en met de gewone accenten gevouwen
(`café` vindt `Cafe`). Het vouwen gaat letter voor letter en heeft de
intl-extensie niet nodig. De voorselectie in MySQL is al ongevoelig voor
hoofdletters en accenten.

De hele vraag weegt dus het zwaarst: een exacte titel en de volledige
phrase, ook als die alleen in de tekst staat, komen altijd boven losse
termen. *laser hout* vindt ook *Laseren en graveren op hout* (beide termen in
de titel, 70) en *Hanglamp* met *laser* en *hout* in de omschrijving (10).
Een resultaat met maar één van de twee termen valt weg. Een vraag van één
woord scoort precies zoals voorheen. Er is geen stemming en geen fuzzy
zoeken: *laser* vindt *Laseren* omdat het er letterlijk in staat, niet
omdat het dezelfde stam heeft.

## Talen

Er wordt gezocht in de taal van de pagina (`RequestLanguage`, of `lang` voor
`api/search.php`, gecontroleerd door `ApiLanguage`). Titel en tekst komen in
die taal, met de gewone terugvalregel voor woorden
(`LanguageFallback`/`PageLocalization`/`ShopLocalization`/…). Het adres is het
adres waar de site zelf in die taal naar linkt:

- een pagina: `PageContent::publicUrl()`, het eigen adres in die taal, en
  anders het adres in de standaardtaal (zoals een menulink);
- een bericht: de regel van `BlogContent::postUrl()`;
- een product en een project: hetzelfde pad met het taalvoorvoegsel.

Een pagina zonder Engelse woorden staat dus in de Engelse resultaten met haar
Nederlandse titel en haar Nederlandse adres, precies zoals het Engelse menu
haar toont.

## De header

`partials/header-search.php` staat vooraan in `.header-actions`
(`partials/header.php`):

- **zonder JavaScript** is het vergrootglas een gewone link naar de
  resultatenpagina en het formulier in het paneel een gewoon GET-formulier;
- **met** `assets/js/search.js` wordt het vergrootglas een disclosure-knop
  (`role="button"`, `aria-expanded`, `aria-controls`). Die opent een compact
  paneel onder de header, met het veld (met label), *Zoeken* en *Sluiten*.
  Het paneel is geen modal: er is geen focusval. Escape, de sluitknop en een
  klik ernaast sluiten het, en na Escape of de sluitknop gaat de focus terug
  naar het vergrootglas;
- **live resultaten** komen na een pauze in het typen (220 ms), met één
  request per pauze. Een ouder antwoord overschrijft nooit een nieuwer. Er
  verschijnen hoogstens 8 resultaten, met *Alle resultaten bekijken* naar de
  resultatenpagina. Pijl omlaag gaat van het veld naar de resultaten, en de
  pijlen bewegen daartussen. Een `role="status"`-regel zegt hoeveel
  resultaten er zijn, of dat de vraag te kort is, of dat er niets is;
- **op een telefoon** (≤ 900px) staat het paneel gewoon open bovenaan de
  acties in het hamburgermenu. Het vergrootglas en de sluitknop zijn daar
  verborgen, want het menu sluit met zijn eigen knop.

Het script zet alles met `textContent` op de pagina en linkt alleen naar een
root-relatief adres op de eigen site. Elke zin komt als `data-text-*` uit de
partial, in de taal van de pagina.

## De resultatenpagina

`zoeken.php`, routesleutel `core.search`, met het segment `core.search` in
`RouteSegments::CORE_SEGMENTS`: `/zoeken`, `/en/search`, en in elke andere
taal het standaardwoord. Beide woorden zijn daarmee gereserveerd tegen een
paginaslug (`ReservedPaths`), en `zoeken` staat in
`ReservedRoutes::CORE_RESERVED`.

- `noindex`, en de canonical is de kale route in de eigen taal, zonder vraag.
  `?q=` en `?pagina=` zijn weergavestaat (`QueryIdentityRoutesTest`), dus de
  taalwissel leidt naar de resultatenpagina van de andere taal zonder vraag;
- 10 resultaten per pagina, met vorige/volgende en *Pagina X van Y*, zoals de
  pager van de Blog;
- lege toestanden voor nog niets ingevuld, te kort, geen resultaten, een
  soort die niet doorzocht kon worden (een provider die faalt, wordt gelogd
  en de rest antwoordt gewoon) en een zoekfout;
- de vraag staat alleen geëscapet in het veld, de titel en de melding.

## Veiligheid en privacy

- **SQL**: alles gaat via prepared statements in repositories, en `LIKE` is
  geëscapet.
- **XSS**: de vraag en de resultaten zijn platte tekst. De pagina gebruikt
  `htmlspecialchars()`, het script `textContent`, en de JSON heeft
  `JSON_HEX_TAG|AMP|APOS|QUOT`.
- **Adressen**: `SearchService::isSafeUrl()` laat alleen root-relatieve
  adressen op de eigen site door, voor een resultaat en voor zijn miniatuur.
  Een provider bouwt ze al met de URL-bouwers van de site, dus dit is een
  tweede lijn.
- **Verborgen inhoud**: elke provider past de regel van zijn eigen publieke
  pagina toe, en een uitgeschakelde module heeft geen provider.
- **Privacy**: een zoekterm wordt nergens opgeslagen, geteld of verstuurd.
  `PageViewTracker` bewaart van `/zoeken` alleen het pad, omdat `q` niet op
  zijn toegestane lijst staat.

## Prestaties

Een Mygdala-site heeft tientallen tot honderden pagina's, producten,
projecten en berichten. Een zoekopdracht kost per provider een paar queries,
onafhankelijk van het aantal resultaten. Een provider levert hoogstens 200
kandidaten. Een `FULLTEXT`-index of een eigen zoekindex zou hier niets
winnen: een `LIKE '%…%'` gebruikt toch geen index, en het doorzochte deel is
klein.

## Later

- **De tekst van de blokken van een pagina.** Die staat per veld van elk
  bloktype in `block_translations`. Doorzoeken zou betekenen dat elk blok van
  elke pagina bij elke toetsaanslag wordt opgebouwd. Dat vraagt een eigen
  zoekindex, bijgewerkt bij opslaan, en dat is bewust niet V1.
- Stemming, synoniemen en spelfouten.
- Instellen per soort welke inhoud mee doet: V1 neemt elke actieve publieke
  provider mee.

## Testen

`SearchCoreTest` (de vraag, de scores, het afkappen, de volgorde, pagineren,
een falende provider, veilige adressen) en `SearchNavigationTest` (standaard
uit, de header en de shell, de knop en het formulier, de route per taal, de
404 als zoeken uit staat, escapen, het script, het CMS-schakelaartje) zitten
in `unit`, `fast` en `cms`. Ze hebben geen database nodig.
`SearchProvidersTest` zit in `modules`, `shop` en `blog` en test de vier
providers tegen de database: zichtbaar en verborgen, een module uit, talen,
wildcards, de ene rangschikking en het aantal queries.
