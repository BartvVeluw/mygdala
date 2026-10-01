# Publishing Engine

De gedeelde publicatielaag voor redactionele content: alles wat een concept
kan zijn, ingepland kan worden, verschijnt en weer uit beeld kan gaan. De
eerste gebruiker is de Blog (`BLOG.md`, adapter op bestaande tabellen sinds
fase 4, Blog 2.0 in fase 5); de tweede is Artikelen (`ARTICLES.md`, fase 6),
de eerste soort die vanaf nul op de engine gebouwd is. Gebouwd in v0.1.15.

Wijkt de code af van dit document, dan heeft de code gelijk.

## In één alinea

`App\Service\Publishing` bevat de **woorden, de klok, de regel en het
contract**: een gesloten statuslijst, één klok met een expliciet
tijdzonecontract, één zichtbaarheidsregel in PHP en in SQL, de regels voor
een geldige publicatie, en een providercontract (`Publishable`) dat een
module via `ModuleDefinition::publishables()` aanlevert. De content zelf blijft
bij de module: haar tabellen, kolommen, woorden, URL-vorm, editor, rechten en
taxonomie. **Er is geen centrale tabel en er is geen migratie.**

## Wat de Publishing Engine bezit

| Onderdeel | Klasse | Wat |
|---|---|---|
| Status | `PublicationStatus` | `draft`, `published`, `scheduled`, `archived`, als gesloten lijst |
| Tijd | `PublishingClock` | "nu", het parsen van een opgeslagen moment, strikte invoer uit een formulier, weergave (admin, RSS, ATOM, en `forReader()`: "3 maart 2026" voor een bezoeker), en een test-seam |
| Zichtbaarheid | `PublicationVisibility` | `isListed()`, `isReachable()`, `isPending()` en de SQL-tweelingen `listedSql()`/`reachableSql()` |
| Regels | `PublicationRules` | of een wijziging mag (status, datum, ingepland met datum, de eigen regel van de eigenaar) en welk moment er wordt opgeslagen |
| Contract | `Publishable` | wat een soort content de engine vertelt |
| Register | `Publishables` | de soorten van **ingeschakelde** modules, op type |
| Wijzigen | `PublishingService` + `api/admin/update-publication.php` | één record van een soort, op type en id |
| CMS | `admin/_publication_fields.php` | het status- en datumveld, de byline, de kaart *Publicatie*, de melding |

## Wat de module bezit

- **De tabellen en de rijen**, met haar eigen kolommen `status` en
  `published_at`. De engine kent geen andere kolom.
- **De woorden**: titel, samenvatting, tekst of contentblokken, SEO-teksten,
  per taal in haar eigen vertaaltabel.
- **De URL**: route, slug per taal, segmenten en redirects.
- **De rechten**: de engine heeft geen eigen rechtenmodel. Een soort noemt de
  permissie die nodig is (`blog.manage`).
- **De eigen publicatieregel**: wat er minimaal moet zijn voordat iets naar
  buiten mag (`publishErrors()`).
- **Taxonomie, media, feed, editor en presentatie.**

## Status

```text
draft      nooit publiek, ongeacht de datum
scheduled  in overzichten en bereikbaar vanaf published_at, niet één seconde eerder
published  in overzichten en bereikbaar zodra published_at voorbij is
archived   bereikbaar op het eigen adres (noindex), maar nergens meer vermeld
```

Opgeslagen als precies deze strings in de eigen `status`-kolom van de
eigenaar. Bij de Blog is dat een `varchar(20)`, dus `archived` vroeg geen
migratie.

- **Een onbekende waarde léést als concept.** Een met de hand bewerkte rij
  kan content alleen minder zichtbaar maken.
- **Een onbekende waarde wordt bij opslaan geweigerd** en niet stilletjes als
  concept bewaard (`PublicationRules`).
- **Niet elke soort biedt elke status.** `Publishable::statuses()` zegt welke.
  De Blog biedt er drie; `archived` komt pas met Blog 2.0.

### Waarom gearchiveerd bereikbaar blijft

Er zijn drie handelingen, met elk een eigen betekenis:

| Handeling | Gevolg |
|---|---|
| Terug naar **concept** | offline: 404 |
| **Archiveren** | niet meer promoten: weg uit overzicht, archieven, sitemap, feed, zoeken en "gerelateerd", maar het eigen adres blijft werken, met `noindex` |
| **Verwijderen** | weg, zonder redirect (`REDIRECTS.md`) |

Een gearchiveerd artikel waar al jaren naar gelinkt wordt, mag dus geen dode
link worden. Wie het echt offline wil hebben, zet het terug naar concept.
Archiveren is geen soft-delete en vervangt verwijderen niet.

## Zichtbaarheid

Er zijn twee vragen, en die stelt elke publieke lezing via deze ene klasse:

| | `published` / `scheduled` | `archived` | `draft` |
|---|---|---|---|
| **listed**: in overzichten, archieven, sitemap, feed, zoeken, gerelateerd | ja, als `published_at` gezet en voorbij is | nee | nee |
| **reachable**: het eigen adres antwoordt | ja, als `published_at` gezet en voorbij is | ja, als `published_at` gezet en voorbij is | nee |

`isPending()` is wat het CMS laat zien als *nog niet zichtbaar*: geen concept,
maar het moment is er nog niet (of is nooit gezet).

**In SQL** zet een repository `listedSql('p')` of `reachableSql('p')` in haar
eigen query en bindt `:now` één keer uit `PublishingClock::nowForSql()`.
Nergens anders schrijft iemand `status = 'published' OR …`. Er komen alleen
constanten en een gecontroleerde alias in de SQL.

**Het CMS gebruikt geen van beide.** Een redacteur ziet elke rij via de eigen
admin-queries van de eigenaar. Daardoor kan een preview nooit in de publieke
scope lekken, en verbergt de publieke scope nooit een concept voor zijn
redacteur.

## Tijd

**Geplande publicatie heeft geen cron.** Iets wordt zichtbaar omdat de klok
verder liep; de query vergelijkt `published_at` op het moment van het
verzoek. Een cron mag later de status normaliseren (`scheduled` →
`published`), maar of iets publiek is, hangt daar nooit van af.

**Het tijdzonecontract:**

- Een publicatiemoment staat als naïeve `Y-m-d H:i:s` in **PHP's ingestelde
  tijdzone** (`date_default_timezone_get()`).
- Er wordt vergeleken met `PublishingClock`, nooit met MySQL's `NOW()`. Op
  gedeelde hosting hebben PHP en MySQL elk een eigen tijdzone, en alleen de
  vergelijking met de klok die het moment schreef, kan geen uur verschuiven.
- Het `datetime-local`-veld van de redacteur wordt in diezelfde zone gelezen.
- Er is geen tweede tijdzone-instelling.
- `now()` geeft altijd een moment in die zone, ook als een test een moment in
  UTC vastzet.

**Zomertijd** (in een zone die dat heeft) volgt de regels van PHP 8.2, bij
schrijven en lezen hetzelfde:

- Een uur dat twee keer bestaat (de herfst), is het **tweede**: wintertijd.
- Een uur dat niet bestaat (de lente), schuift op met het gat: 02:30 wordt
  03:30.

**Invoer is strikt** (`PublishingClock::fromInput()`). Toegestaan zijn alleen
`Y-m-d\TH:i`, met of zonder seconden, en de opslagvorm. Niets relatiefs
("morgen"), geen eigen tijdzone en geen 30 februari. Leeg betekent "geen
datum". Al het andere wordt geweigerd, nooit als "nu" gelezen.

**Testen** gaat met `PublishingClock::freezeForTests()`. `BlogClock` is een
dunne naam voor dezelfde klok, dus wie de ene vastzet, zet de andere vast.

## Publiceren valideren

`PublicationRules::validate($status, $datum, $aangeboden, $provider, $id)`:

1. De status hoort bij de gesloten lijst **én** bij wat deze soort aanbiedt.
2. Een ingevulde datum is een echt moment.
3. *Ingepland* heeft een datum nodig.
4. Gaat het naar buiten (*published*, *scheduled*, *archived*), dan vraagt de
   engine de eigen regel van de eigenaar: `publishErrors()`. De Blog eist
   een titel in de standaardtaal en een adres. Een tekst eist de Blog niet,
   want dat deed hij nooit.

Een concept kan altijd worden opgeslagen. `resolvePublishedAt()` bepaalt wat
er wordt opgeslagen:

- een getypt moment blijft dat moment;
- *gepubliceerd* zonder datum wordt **nu**;
- al het andere zonder datum wordt `NULL`.

Een weigering verandert niets, dus een half gepubliceerde toestand bestaat
niet.

## Het providercontract

```php
interface Publishable
{
    public function type(): string;              // "blog_post", dezelfde sleutel als in LinkTargets
    public function module(): string;            // "blog"
    public function permission(): string;        // "blog.manage"
    public function statuses(): array;           // deelverzameling van PublicationStatus::ALL, met draft
    public function publication(int $id): ?array;          // ['status' => …, 'published_at' => …] of null
    public function publishErrors(int $id, string $status): array;  // de eigen "can publish"
    public function savePublication(int $id, string $status, ?string $publishedAt): void;
    public function adminPath(int $id): string;  // waar de redacteur eraan werkt
    public function publicPath(int $id, string $language): ?string;  // alleen als bereikbaar
    public function alternates(int $id): array;  // taal => pad, voor hreflang en sitemap
}
```

Een module levert hem via `ModuleDefinition::publishables()` (`type =>
provider`). `Publishables` leest alleen ingeschakelde modules: een soort van
een uitgeschakelde module bestaat voor de engine dus niet, en dat geldt ook
voor een Super Admin. Een type uit een request is alleen een opzoeksleutel,
nooit een klassenaam of tabelnaam. `find($type, $id)` koppelt een id aan zijn
soort: het id van een blogbericht onder een ander type vindt niets.

De Blog levert `App\Service\Blog\BlogPostPublishable`: een **adapter** op de
bestaande tabellen. Artikelen levert `App\Service\Articles\ArticlePublishable`
(type `article`, `articles.manage`, alle vier statussen). Dat de tweede soort
niets aan de engine hoefde te veranderen, is het bewijs dat hij generiek is:
er kwam alleen `forReader()` bij, voor een datum op een publieke pagina.

## Een publicatie wijzigen

`api/admin/update-publication.php` (`type`, `id`, `status`, `published_at`)
is de knop *Publiceren*, *Archiveren* of *Terug naar concept* buiten de eigen
editor, bijvoorbeeld in een overzicht. De eigen editor van een soort houdt
zijn eigen endpoint met één formulier, en draait daarin dezelfde
`PublicationRules`.

1. Login, dan "mag een of andere soort publiceren"
   (`PublishingService::requireAnyForApi()`), dan POST, dan CSRF. Dit is de
   vorm van `ContentBlockAccess` (`AdminAccessControlTest`,
   `PUBLISHING_ENDPOINTS`).
2. `PublishingService::change()`:
   - een onbekend of uitgeschakeld type, of een id dat geen record van dat
     type is, geeft een **404**;
   - zonder de eigen permissie van díe soort volgt een **403**;
   - daarna de regels;
   - pas dan slaat de eigenaar op.
3. PRG terug naar `adminPath()`: met `updated=1` bij succes, en anders met de
   redenen in de sessie (`admin_publication_flash()`).

## In het CMS

`admin/_publication_fields.php`:

| Functie | Waarvoor |
|---|---|
| `admin_publication_fields([...])` | Status en datum als één gesplitste rij, **binnen** het formulier van de eigen editor. Vaste namen: `status`, `published_at`. Alleen de statussen van de soort, eventueel in de eigen woorden van de soort (de Blog zegt *Ingepland*) |
| `admin_publication_byline(...)` | De publieke byline als vrije tekst (`author_name`) |
| `admin_publication_card($provider, $id, $publication)` | Een volledige kaart *Publicatie* met een eigen formulier naar `update-publication.php`, en een zin over wat er nu zichtbaar is |
| `admin_publication_flash()` | De redenen van een geweigerde wijziging, één keer |

De Blog-editor gebruikt de eerste twee sinds v0.1.15. Opslaan blijft één
formulier naar `update-blog-post.php`.

## Talen, slugs en URL's

De engine voegt **niets taalafhankelijks** toe. Status, datum en byline zijn
taalneutraal en staan in de eigen hoofdtabel van de eigenaar. Slug, titel,
SEO-titel en meta description staan per taal in de vertaaltabel van de
eigenaar, volgens Multilingual 2.0 (`MULTILINGUAL.md`,
`docs/multilingual/ROUTING.md`). Er komen geen `_nl`/`_en`-kolommen en geen
tweede slug-engine:

- **Normaliseren en uniciteit** komen van de eigenaar, met de gedeelde regel
  `App\Service\Routing\LocalizedSlug`. Bij de Blog is dat uniek per
  `(language_code, slug)`.
- **Een adres valt niet terug.** Een taal zonder slug heeft geen URL.
  Woorden vallen wel terug: gevraagde taal, standaardtaal, leeg.
- **Redirects** bij een hernoemde slug lopen via `SlugChangeRedirects`, in de
  save van de eigenaar.
- **Route**: de module bepaalt hem (`publicRoutes()`, `BlogUrls`). De engine
  vraagt alleen `publicPath()` en `alternates()`.

## SEO, sitemap en hreflang

Er is geen tweede SEO-model. De eigenaar levert een `SeoMetadata` (de Blog
via `BlogSeo`) en `partials/seo-head.php` rendert die (`SEO.md`).

- **Sitemap**: de eigenaar levert een collector
  (`ModuleDefinition::sitemapCollectors()`). Die leest via de listed-regel
  en `Sitemap::entriesForVersions(alternates)`. Daarmee staan alleen
  zichtbare content en echt bestaande taalversies erin, met hreflang
  ertussen. Een concept, iets wat nog moet verschijnen of iets gearchiveerds
  staat er niet in.
- **Canonical** is het eigen adres van de taal van de pagina.
- **Querygedrag**: één query voor de rijen, één voor alle adressen
  (`preload`). Geen query per item.

## Media

De uitgelichte afbeelding is generiek nodig, maar de engine bezit haar niet.
Het model van de Blog is het voorbeeld voor elke soort:

- een **verwijzing naar de Mediabibliotheek** (`featured_media_id`, met
  `RESTRICT`), geen kopie en geen pad;
- alt-tekst en afmetingen van het item zelf (`MEDIA.md`);
- een `MediaUsageProvider`, zodat de bibliotheek het gebruik ziet;
- `linkedImages()` voor het beeld dat een blok bij een link laat zien.

Een afbeelding is nooit verplicht om te publiceren, tenzij een soort dat in
haar eigen `publishErrors()` eist.

## Auteur en byline

De Blog kent alleen een **vrije byline** (`author_name`), bewust zonder
foreign key naar een account. Dat blijft het gedeelde model:
`admin_publication_byline()`. Een gastauteur heeft geen login nodig. Een
koppeling naar een CMS-gebruiker (`author_user_id`, optioneel naast de
byline) is pas zinvol met een auteurspagina. Die is niet gebouwd, en er komt
geen Author Management.

## Taxonomie: besloten bij Artikelen

**Er is geen gedeelde taxonomielaag, en die komt er voorlopig ook niet.**
Artikelen hebben één optioneel *onderwerp* per artikel nodig (een eigen
kleine tabel, `ARTICLES.md`, "Onderwerpen"), geen tags en geen primaire
categorie. Wat Blog en Artikelen echt delen ("een naam, een omschrijving en
een adres per taal", uniciteit per taal, redirects bij hernoemen) is al
gedeeld via `EntityTranslations`, `LocalizedSlug` en `SlugChangeRedirects`.
Een `ContentTaxonomy` wordt pas zinvol als een derde soort óók veel-op-veel
indeling vraagt. Wat hieronder staat, was het contract vóór dat besluit en
blijft de vorm als het ooit zover is.

De categorieën en tags van de Blog zijn sterk Blog-eigen:

- eigen tabellen per taal met een **archiefadres** per taal;
- een actief-vinkje en volgorde;
- de eerste categorie is de primaire;
- tags zijn ontdubbeld op hun slug;
- redirects bij hernoemen;
- `noindex` op tagarchieven.

Een generieke taxonomy-engine zou dat allemaal moeten nabouwen voor één
gebruiker. Daarom is er in deze fase **geen code** en ligt alleen het
contract vast.

Een gedeelde laag (`ContentTaxonomy`, bij Blog 2.0) krijgt:

- `kind` als gesloten type (`blog_post`, later `article`) op gedeelde
  `content_categories`/`content_tags` + `_translations` + koppeltabellen;
- per term: naam en adres per taal, een actief-vinkje en een volgorde;
- de archief-URL van de **module** (de engine kent geen route);
- de bestaande Blog-regels als gedrag: de eerste is primair, de slug is de
  identiteit van een tag, en tagarchieven zijn `noindex`.

De Blog verhuist daar pas naartoe met een verliesvrije migratie en
redirects, in Blog 2.0.

## Contentblokken

Artikelen hebben alleen blokken (`ArticleContentOwner`, `article_content_pages`).
Voor "mag dit naar buiten" vraagt een soort `ContentPages::hasMeaningfulBlocks()`:
een zichtbaar, geregistreerd blok dat geen paginakop is, niet decoratief
(`BlockDefinition::isDecorative()`) en niet zelf zegt leeg te zijn. De Blog
eist dat (nog) niet.

Het owner-aware model
(`App\Service\ContentOwners`: `ContentOwner`, `ContentPages`,
`ContentBlockAccess`, het draft-model van blokken en `SectionRegistry`) is
generiek genoeg. Product en project gebruiken het al. Een soort die blokken
wil, levert via `ModuleDefinition::contentOwners()` een `ContentOwner` met
haar eigen permissie en editor, en krijgt een contentpagina van de gewone
blokkenmotor (`CONTENT-BLOCKS.md`, "Wie mag welke blokken beheren"). Er is
geen nieuwe pagina-engine nodig en in deze fase niets gebouwd.

## Zoeken (voorbereid, niet gebouwd)

Search 2.0 kan per record alles uit de engine en de provider halen:

| Veld | Bron |
|---|---|
| soort | `Publishable::type()` |
| publiek | `PublicationVisibility::isListed()` |
| canonieke URL per taal | `alternates()` (absoluut via `AppUrl::canonical()`) |
| taal | de sleutels van `alternates()` |
| publicatiedatum | `PublishingClock::forAtom(published_at)` |

De huidige site-zoekfunctie (`SearchProvider` per module) blijft zoals hij
is. Er is geen index. Artikelen leveren daar titel en intro van *listed*,
indexeerbare artikelen, niet de tekst van hun blokken.

## Preview

Er is geen previewtoken-mechaniek, en die is hier niet gebouwd. Een concept
of iets wat nog moet verschijnen, is alleen in het CMS te zien: het eigen
adres geeft een 404 (`isReachable()` is onwaar). De editor zegt *nog niet
zichtbaar* met de datum. Een preview komt later in de vorm van
`admin/block-preview.php` en de paginapreview: achter de permissie van de
soort, `noindex`, en buiten de publieke scope. Een token voor wie geen
login heeft, is een eigen ontwerp.

## Beveiliging

- Status, type en datum komen uit gesloten lijsten of strikte formaten.
  Alles wat erbuiten valt, wordt geweigerd.
- Type en id worden samen gecontroleerd. Alleen de provider van dat type
  leest zijn eigen tabel.
- De permissie is die van de soort. Zonder die permissie volgt een 403, en
  een uitgeschakelde module levert geen soort meer.
- CSRF zit op elke schrijfactie. De weg terug is `adminPath()`, nooit een URL
  uit het request.
- Byline en SEO-teksten worden overal ge-escaped (`htmlspecialchars()`).
- Een concept en iets wat nog moet verschijnen, zijn niet bereikbaar via het
  directe adres. Gearchiveerd volgt het contract hierboven.

## Testen

| Test | Suite | Wat |
|---|---|---|
| `Tests\Service\PublishingContractTest` | `contract`, `fast`, `blog` | Geen database. De statuslijst, de zichtbaarheidstabel op een vastgezette klok (gisteren, nu, over een minuut, een minuut geleden, gearchiveerd, onbekend), de SQL-tweeling en geweigerde aliassen, strikte datuminvoer, middernacht en zomertijd in Europe/Amsterdam, de regels en de eigen regel van de eigenaar, het register met de Blog aan en uit, en dat de engine in code geen soort noemt |
| `Tests\Blog\BlogPublishingTest` | `blog` | De adapter op echte rijen, elke vervalste of te vroege wijziging geheel geweigerd, scheduled → draft → published, de sitemap met hreflang en canonical, het endpoint over echt HTTP (401/403/405/404, CSRF, redenen, opgeslagen), de editor met de gedeelde velden en een ge-escapete byline, en de kaart |
| `Tests\Service\AdminAccessControlTest` | `contract` | De volgorde van de guards van `update-publication.php` |
| `Tests\Module\ArticlesTest` | `modules` | De tweede soort: alle vier statussen op elk publiek oppervlak, de eigen publicatieregel (ook via `update-publication.php`), geplande publicatie zonder cron, vervalste types en ids |

## Implementatieroute

### Blog 2.0 (gebouwd in v0.1.15, fase 5)

1. **Gedaan.** De Blog biedt `archived` aan (`BlogPostStatus::ALL`). Het eigen
   adres leest via `reachableSql()`, met `noindex`. Overzichten, archieven,
   feed, sitemap, zoeken en gerelateerd lopen via `listedSql()`.
2. **Niet gebouwd.** Een snelknop in het overzicht (`admin_publication_card()`)
   bleef achterwege: de editor heeft de velden, en het endpoint staat klaar.
3. **Gedaan, maar anders dan hier eerst stond.** Contentblokken komen via
   `BlogPostContentOwner`, met een **expliciete** `content_mode` en **zonder
   massamigratie**. Een klassiek bericht houdt zijn tekst tot een redacteur het
   omzet; omzetten maakt één Tekstblok per bericht en houdt de tekst
   (`BLOG.md`, "Klassieke tekst en contentblokken").
4. **Bewust uitgesteld.** De taxonomie blijft van de Blog (zie *Taxonomie*).

### Articles 1.0 (gebouwd in v0.1.15, fase 6)

Gebouwd zoals hier stond, met drie bijstellingen (`ARTICLES.md`):

1. **Gedaan.** Module `articles` (standaard uit) met `articles`,
   `article_translations` (geen neutrale slug, geen body), `article_topics`,
   `article_topic_translations` en `article_content_pages`.
2. **Gedaan.** `ArticlePublishable`, type `article`, `articles.manage`, alle
   vier statussen, `publishErrors()` = titel en adres in de standaardtaal plus
   een blok dat iets zegt.
3. **Gedaan.** Publiek alleen via `listedSql()`/`reachableSql()`.
4. **Gedaan.** De editor gebruikt de gedeelde velden en `PublicationRules`, en
   beoordeelt de eigen regel binnen de transactie van de opslag.
5. **Gedaan.** Route, sitemap, SEO, link targets, linked images, media en
   zoeken als eigen bijdragen van de module.
6. **Anders.** Contentblokken direct via het owner-model; taxonomie bewust
   **niet** gedeeld (zie *Taxonomie*). En: een artikel valt in een andere
   taal niet terug op de woorden van de standaardtaal.

## Bewust niet gebouwd

- Een centrale `publishing_entries`-tabel. Die zou polymorf zijn, zonder
  echte foreign keys, en elke publieke query een join opleggen.
- Een cron die publiceert.
- Een redactionele goedkeuringsstroom, revisies of een previewtoken.
- Author Management.
- Een generieke taxonomy-engine.
- Search 2.0.
