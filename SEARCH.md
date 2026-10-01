# Zoeken op de website

Mygdala heeft een eigen, optionele zoekfunctie voor bezoekers: een zoekknop in
de header, live resultaten onder het zoekveld en een gewone resultatenpagina.
Er is geen externe zoekdienst. Deze functie kwam in v0.1.12; sinds v0.1.15
(Search 2.0) doorzoekt ze ook de tekst van de contentblokken, via één kleine,
afgeleide index ("De tekst van de blokken").

Wijkt de code af van dit document, dan heeft de code gelijk.

## In één oogopslag

```text
Navigatie → "Zoeken tonen"   site_settings.nav_search_enabled, standaard 0 (uit)
header                        partials/header-search.php: vergrootglas + paneel, of het veld in het telefoonmenu
live resultaten               GET /api/search.php?q=…&lang=…   → eerste 8, plus totaal en "Alle resultaten bekijken"
resultatenpagina              /zoeken?q=…  (/en/search?q=…), noindex, 10 per pagina, ?pagina=N
zoekdienst                    App\Service\Search\SearchService: providers vragen, scoren, sorteren, pagineren
providers                     Core: pagina's. Modules: producten (Shop), projecten (Portfolio), berichten (Blog), artikelen
bloktekst                     App\Service\Search\BlockSearchIndex: search_block_texts, per blok en taal, afgeleid
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
| `page` | `Service\Search\PageSearchProvider` | Core | `PageSeo::isIndexable()`: gepubliceerd, niet `noindex`, en geserveerd door een ingeschakelde module (geen pagina in de subboom van een uitgeschakelde module, geen placeholder) | titel, meta description, de tekst van de blokken |
| `product` | `Service\ProductSearchProvider` | Shop | `active = 1`, dezelfde regel als `product.php` en de sitemap; ook een product dat niet in het winkeloverzicht staat | naam, beschrijving, de tekst van de blokken |
| `project` | `Service\PortfolioSearchProvider` | Portfolio | `PortfolioSlug::isPublic()` (zichtbaar, projectpagina aan, een slug); een item dat naar een oude gekoppelde pagina doorverwijst, vindt de paginazoeker onder het adres van die pagina | titel, subtitel, intro, de tekst van de blokken |
| `post` | `Service\Blog\BlogSearchProvider` | Blog | het ene publieke predicaat van `BlogPostRepository` (geen concept, `published_at` gezet en niet in de toekomst) en niet `noindex` | titel, samenvatting, en de klassieke tekst of (blokmodus) de blokken |
| `article` | `Service\Articles\ArticleSearchProvider` | Artikelen | `PublicationVisibility::listedSql()` (niet gearchiveerd, niet ingepland in de toekomst) en niet `noindex`; alleen in een taal met een versie | titel, intro, de tekst van de blokken |

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
4. Heeft de soort contentblokken (een `ContentOwner`), geef dan de
   bloktekst mee: `SearchCandidates::ids(…, fn ($term) =>
   BlockSearchIndex::ownersMatching($owner, $term, $language))` voor de
   voorselectie, en `BlockSearchIndex::ownerTexts()` als `contentHeadings` en
   `content` van het `SearchDocument`. Toont de publieke pagina de blokken niet
   altijd (de klassieke modus van de Blog), geef ze dan ook niet mee.

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
| 90 | alleen een kop in de inhoud (een blokkop, een FAQ-vraag, een `<h2>` in een tekstblok) bevat de vraag |
| 80 | alleen de inhoud (de tekst van de blokken, de klassieke tekst van een bericht) bevat de vraag |
| 10–70 | niet de hele vraag, maar **elke term** komt ergens voor, in willekeurige volgorde, verdeeld over titel en tekst: 10 + 60 × (het deel van de termen dat in de titel staat) |

Bij gelijke score komt eerst de volgorde van de providers (pagina's, dan de
modules in registervolgorde) en daarna de eigen volgorde van de provider.
Vergelijken gaat zonder hoofdletters en met de gewone accenten gevouwen
(`café` vindt `Cafe`). Het vouwen gaat letter voor letter en heeft de
intl-extensie niet nodig. De voorselectie in MySQL is al ongevoelig voor
hoofdletters en accenten.

De hele vraag weegt dus het zwaarst: een exacte titel en de volledige
phrase, ook als die alleen in de tekst staat, komen altijd boven losse
termen. De termregel kijkt ook in de inhoud. *laser hout* vindt ook *Laseren en graveren op hout* (beide termen in
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

De **bloktekst** volgt dezelfde regel als de pagina zelf: per veld de woorden
van de gevraagde taal, anders die van de standaardtaal, zoals de blokken
printen (`BlockLocalization::value()`). Nederlandse zoekopdrachten zien dus
nooit Engelse bloktekst. Een artikel zonder versie in een taal staat in die
taal nergens, ook niet via zijn blokken.

## De tekst van de blokken (Search 2.0)

Sinds v0.1.15 vindt de zoekfunctie een pagina, product, project, blogbericht
of artikel ook op de woorden in zijn **contentblokken**. Wie *lasergraveren op
glas* zoekt, vindt de pagina waar dat alleen in een Tekstblok, een FAQ of een
CTA staat. Eén resultaat per **eigenaar**, nooit "Tekstblok #184".

### Waarom een index

De woorden van een blok staan per veld in `block_translations`, en hoe een
blok ze toont verschilt per bloktype (kindrijen, items die uit staan, een blok
dat uit staat in zijn eigen editor). Dat bij elke toetsaanslag voor elke
pagina opnieuw opbouwen kan niet; een `JOIN` over alle bloktabellen ook niet,
want dan moet Search elk bloktype kennen. Dus is er één kleine, **afgeleide**
tabel met de leesbare tekst per blok:

```text
search_block_texts   page_section_id, page_id, section_type, language_code,
                     heading_text (de koppen), body_text (alle woorden in leesvolgorde)
                     UNIQUE(page_section_id, language_code); FK's CASCADE naar
                     page_sections en pages
search_index_state   index_name → fingerprint, built_at
```

(migratie `20261013100000`). De index bevat **alleen tekst**. Of iets
gevonden mag worden, beslist de provider bij elke zoekopdracht uit zijn eigen
tabellen, zoals altijd. Daardoor kan een statuswissel, een module die uit gaat
of een verwijderde eigenaar nooit een verouderd resultaat opleveren: de index
hoeft daar niets van te weten.

### Hoe een blok zijn zoektekst aanbiedt

Een blok zegt het zelf, in zijn definitie: `BlockDefinition::searchFields()`
geeft voor **elk** veld uit `translatableFields()` een rol
(`App\Service\Blocks\BlockSearchRole`):

| Rol | Wat | Voorbeelden |
|---|---|---|
| `HEADING` | een kop die de bezoeker ziet; weegt zwaarder | bloktitel, titel van een item, FAQ-vraag |
| `TEXT` | woorden die de bezoeker leest | intro, tekst, antwoord, review, voetnoot, eyebrow |
| `NONE` | opgeslagen, maar niet iets waar iemand op zoekt | alt-tekst, knop- en linklabel, ankerlabel, een label dat maar één weergave toont |

```php
public function searchFields(): array
{
    return [
        'faq_sections' => ['eyebrow' => BlockSearchRole::TEXT, 'title' => BlockSearchRole::HEADING],
        'faq_items' => ['question' => BlockSearchRole::HEADING, 'answer' => BlockSearchRole::TEXT],
    ];
}
```

`BlockSearchContractTest` eist dat elk blok elk veld classificeert, zodat een
**nieuw blok** moet kiezen en Search zelf nooit verandert. Een blok zonder
eigen woorden (Witruimte, Productraster, Collecties, Projectinformatie,
Mediabanner, het Diensten-snelmenu) geeft niets. De koppen binnen een rich
text (`<h1>`–`<h6>`) tellen als kop.

**Alleen de eigen woorden van een blok.** Een blok dat andere records toont
(Uitgelicht product, Projecten, een galerij uit een collectie, een
formulier, de afbeeldingsbron van een Detailsectie) biedt alleen zijn eigen
titel, intro en noot aan, nooit de tekst van het gekoppelde record. Dat
record heeft zijn eigen resultaat via zijn eigen provider; kopiëren zou
dezelfde tekst tien keer laten vinden.

### Wat er in de index komt

`App\Service\Search\BlockTextExtractor`, deterministisch, per taal:

- alleen wat een bezoeker ziet: niets van een blok dat **verborgen** is in de
  bloklijst (`page_sections.is_active`) of **uit staat in zijn eigen editor**
  (`is_active` van zijn rij), van een type dat nu niet geregistreerd is (een
  module die uit staat), of van een **kindrij die uit staat** (een item, een
  kaart);
- per veld de woorden van die taal met de eigen terugval van de blokken naar
  de standaardtaal (`BlockLocalization::value()`, precies wat de pagina
  print); nooit een andere taal dan die twee;
- rich text als tekst: tags eruit, entiteiten gedecodeerd, witruimte
  samengevoegd (`SearchText::plain()`); platte tekst alleen witruimte en
  stuurtekens. Scripts en stijlen bereiken dit nooit: rich text is al
  `RichTextSanitizer`-uitvoer;
- hoogstens **100 000 tekens** tekst en 10 000 tekens koppen per blok en taal
  (`SearchIndexRepository::MAX_BODY`). Ruim genoeg dat ook de onderkant van een
  lang tekstblok vindbaar blijft.

Kosten van het lezen: één query per inhoudstabel (uit?), één per kindtabel,
en één voor alle woorden (`BlockLocalization::preloadBlocks()`), per batch
blokken. Nooit één per blok, taal of veld.

### Actueel houden

Binnen de transactie van de wijziging zelf, zodat een teruggedraaide opslag
ook de index terugdraait:

| Wanneer | Waar |
|---|---|
| elke opslag van een blokeditor (ook items, leegmaken, uitzetten) | `ContentBlockDrafts::place()` |
| verbergen en tonen in de bloklijst | `SectionRegistry::setActive()` |
| een kaart van de carrousel, de twee hero-editors | hun endpoints, `BlockSearchIndex::reindexBlock()` |
| een blok dat meteen geplaatst wordt, een Blog-conversie, Projectinformatie | `add-page-section.php`, `BlogContentConversion`, `ProjectInfoPlacement` |
| een blok, pagina of eigenaar verwijderd | `CASCADE`, niets te doen |
| volgorde wijzigen | niets: de volgorde komt bij het zoeken uit `page_sections` |
| status, publicatiedatum, module aan/uit, titel of intro | niets: de provider leest die zelf |

`BlockSearchContractTest` controleert dat elk endpoint dat blokwoorden
schrijft `place()` aanroept of zelf herindexeert. Herindexeren gooit **nooit**
een fout naar de opslag: het logt, en vergeet de vingerafdruk zodat de
volgende zoekopdracht opnieuw opbouwt.

### Opnieuw opbouwen

De **vingerafdruk** in `search_index_state` is een hash van
`BlockSearchIndex::VERSION`, de websitetalen (standaardtaal eerst) en de
geregistreerde bloktypes. Wijkt hij af, dan bouwt de **eerstvolgende
zoekopdracht** de index één keer helemaal opnieuw
(`BlockSearchIndex::ensureCurrent()`, onder een MySQL-lock per database; een
verzoek dat de lock niet krijgt, zoekt met wat er is). Dat gebeurt:

- na de update die deze tabellen brengt: de migratie leest geen enkel blok,
  de eerste zoekopdracht vult de index (bij 259 pagina's met 750 blokken:
  1,8 s, één keer);
- na een nieuwe of uitgezette taal, of een andere standaardtaal (de terugval
  verandert);
- als een module aan of uit gaat (andere bloktypes);
- als `VERSION` omhoog gaat. **Verhoog `VERSION`** als wat bestaande blokken
  aanbieden verandert (een veld krijgt een andere rol, een nieuw veld in
  `searchFields()`), dan herindexeert elke site bij zijn volgende zoekopdracht.

Met de hand, bijvoorbeeld na het met de hand wijzigen van blokrijen:

```bash
docker compose exec php php scripts/rebuild-search-index.php
docker compose exec php php scripts/rebuild-search-index.php --if-needed
```

De blokken zijn de waarheid; beide tabellen leeggooien is altijd veilig.

### Per eigenaar, en het fragment

Een provider haalt de bloktekst van zijn eigen kandidaten op via de
koppeltabel van zijn eigenaar (`BlockSearchIndex::ownerTexts()`; pagina's met
`pageTexts()`), de blokken in paginavolgorde samengevoegd tot één `headings`
en één `body`. Die komen in `SearchDocument` als `contentHeadings` en
`content`, naast de eigen titel en tekst. Matcht de titel, dan blijft het
fragment de eigen samenvatting; anders komt het van waar de woorden staan,
met de bestaande afkapregel (woordgrens, `…`, 160 tekens).

| Eigenaar | Eigen velden | Plus |
|---|---|---|
| pagina | titel, meta description | de blokken van de pagina |
| product | naam, beschrijving | de blokken van zijn inhoudspagina |
| project | titel, subtitel, intro | de blokken van zijn inhoudspagina |
| blogbericht, klassiek | titel, samenvatting | de klassieke tekst (body) |
| blogbericht, blokmodus | titel, samenvatting | de blokken; de bewaarde body telt niet |
| artikel | titel, intro | de blokken (een artikel heeft geen body) |

Een bericht in klassieke modus met blokken van een eerdere conversie wordt
**niet** op die blokken gevonden: de pagina toont ze niet (`BlogContentMode`).

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
kandidaten. Een `FULLTEXT`-index zou hier niets winnen: een `LIKE '%…%'`
gebruikt toch geen index, en het doorzochte deel is klein.

De bloktekst kost per provider twee queries extra (een geëscapete `LIKE` per
term op de index, dan één keer de tekst van alle kandidaten samen). Gemeten
op een wegwerpdatabase met 50 pagina's, producten, projecten, berichten en
artikelen met elk drie blokken (750 blokken): 5 tot 8 queries per provider,
gelijk voor 5 en 50 resultaten; een hele zoekopdracht ongeveer 32 queries en
30 tot 70 ms, ook met 250 treffers. Opnieuw opbouwen: 1,8 s.

## Later

- Markeren van de gevonden woorden in het fragment.
- Alt-teksten doorzoeken (bewust `NONE`: ze beschrijven een beeld, niet de
  inhoud waar iemand op zoekt).
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

Search 2.0 heeft er drie bij:

| Klasse | Suites | Wat |
|---|---|---|
| `BlockSearchContractTest` | `contract`, `fast`, `blocks` | elk blok classificeert elk veld; alt- en knopteksten nooit; Search noemt geen bloktype; elke schrijver van blokwoorden houdt de index bij; de volgorde van de scores; het fragment |
| `SearchBlockTextTest` | `modules`, `shop`, `blog` | per bloksoort en per eigenaar gevonden, klassiek en blokmodus, rangschikking en één resultaat per eigenaar, zichtbaarheid (concept, ingepland, gearchiveerd, inactief, verborgen, module uit), talen, toevoegen/wijzigen/verbergen/verwijderen, eigenaar weg, zelf opnieuw opbouwen, wildcards en quotes, queries gelijk voor 3 en 30 eigenaren |
| `SearchIndexMigrationTest` | `migration`, `modules` | vers en bijgewerkt dezelfde tabellen en sleutels, de migratie leest geen blok, opnieuw zonder effect, CASCADE |
