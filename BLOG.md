# Blog

De blog van deze applicatie: berichten, categorieën, tags, een publieke
blogpagina en een RSS-feed. Lees dit samen met `PROJECT-MAP.md` (waar iets
staat) en `MODULES.md` (wat een module is en wat aan- en uitzetten betekent).

Wijkt de code af van dit document, dan heeft de code gelijk: pas het document
aan, niet de code.

## Wat het is, in één alinea

De Blog is een **optionele first-party module** (`App\Module\BlogModule`). Hij
draagt alles bij via die ene klasse — vier CMS-schermen, twee permissies, één
publieke route, sitemapregels, mediagebruik en een dashboardkaart — en Core
noemt nergens een blogbericht. Hij is de **eerste module die standaard uit
staat**: elke site heeft pagina's, beeld en formulieren, niet elke site
schrijft artikelen.

## Eigendom

| Van de Blog | Van Core |
|---|---|
| `blog_posts`, `blog_categories`, `blog_tags` en de twee koppeltabellen | de mediabibliotheek, de redirecttabel, de SEO-renderer |
| `blog_settings` | `site_settings`, `theme_settings`, `module_settings`, `admin_settings` |
| `src/Service/Blog/*`, `src/Repository/Blog*Repository.php` | `SeoMetadata`, `Sitemap`, `PageAssets`, `RichTextSanitizer` |
| `admin/blog*.php`, `api/admin/*blog*.php` | de adminschil, de mediakiezer, de tabbladen, de opslagbalk |
| `blog.php`, `blog-post.php`, `blog-feed.php`, `assets/css/blog/blog.css` | de header, de footer, de 404-pagina |

## Aan- en uitzetten

Dezelfde ketting als elke module (`MODULES.md`), met één nieuwe laatste stap:

```text
1. MODULE_BLOG_ENABLED in .env
2. de voorkeur die in het CMS is opgeslagen (installatiewizard)
3. de eigen standaard van de module — voor de Blog: UIT
```

Stap 3 is nieuw: `ModuleDefinition::enabledByDefault()` geeft `true` terug en
alleen `BlogModule` overschrijft dat. Voor Shop en Personalisatie verandert er
dus niets — een ontbrekende variabele betekent daar nog steeds "aan", en een
configuratiefout kan de webshop nooit stilletjes offline halen.

**Met de Blog uit:**

- geen Blog-onderdelen in de zijbalk, geen houdbare `blog.*`-permissies;
- `/blog`, `/blog/<slug>`, de archieven en `/blog/feed.xml` geven een 404 die
  niet te onderscheiden is van een URL die nooit bestond (`ModuleGuard`);
- geen blogregels in de sitemap, geen `blog.css` op welke pagina dan ook;
- geen `/blog` in de routekiezer van Navigatie/Footer;
- de Mediabibliotheek telt blogafbeeldingen niet mee als gebruik;
- **elke rij blijft staan.** Uitzetten is geen deïnstallatie.

Eén ding blijft wél gereserveerd: de slugs `blog`, `blog-post` en `blog-feed`.
Die bestanden staan nog op de schijf, dus een CMS-pagina met zo'n slug zou
voorgoed onbereikbaar zijn.

## Het berichtmodel

Twee tabellen sinds fase 5 van Multilingual 2.0: `blog_posts` voor alles wat
in elke taal gelijk is, en `blog_post_translations` voor de woorden — één rij
per bericht per websitetaal, gelezen en geschreven door
`App\Service\Blog\BlogLocalization`. Er zijn geen `_nl`/`_en`-kolommen meer.

```text
blog_posts
  slug                               uniek; het laatste segment van /blog/<slug>
  featured_media_id                  → media (ON DELETE RESTRICT)
  status                             draft | published | scheduled | archived
  published_at                       wanneer het naar buiten mag
  author_name                        vrije tekst, geen koppeling naar een account
  noindex
  og_media_id                        eigen deel-afbeelding, → media
  content_mode                       legacy | blocks (Blog 2.0, zie hieronder)
  created_at, updated_at

blog_post_content_pages              bericht ↔ zijn inhoudspagina (Blog 2.0)
  blog_post_id, page_id              één op één, echte foreign keys, RESTRICT

blog_post_translations               UNIQUE(blog_post_id, language_code)
  title                              verplicht in de standaardtaal
  excerpt                            platte tekst, voor de kaart en de feed
  body                               gesaneerde rich-text-HTML
  meta_title, meta_description       SEO
```

**Een slug per taal**, sinds Multilingual 2.0 fase 6
(`docs/multilingual/ROUTING.md`). `blog_posts.slug` blijft bestaan als de
neutrale sleutel waar elke bestaande link en elke opgeslagen redirect naar
wijst, en het adres van de **standaardtaal** wordt er byte-identiek aan
gehouden; elke andere taal heeft haar eigen `blog_post_translations.slug`, en
zonder zo'n rij heeft die taal geen publieke URL. Een adres valt nooit terug
zoals woorden dat doen. Bij het aanmaken komt de slug uit de titel in de
standaardtaal; een vertaling krijgt haar eerste adres uit haar eigen titel.

**De terugval** is die van het hele CMS: de gevraagde taal, de standaardtaal,
leeg. En de standaardtaal beslist: een bericht met alleen een Engelse titel op
een Nederlandstalige site heeft geen titel.

**`author_name` is met opzet geen foreign key** naar `admin_users`: wie een
bericht schreef en wiens account het opsloeg zijn twee verschillende vragen, en
een naam onder een artikel hoort niet te veranderen omdat een collega een
typefout verbeterde.

### Klassieke tekst en contentblokken (Blog 2.0)

Een bericht heeft **één** lichaam, en welk dat is, zegt een kolom en niets
anders: `blog_posts.content_mode` (`App\Service\Blog\BlogContentMode`).

| Modus | Wie | Wat de website toont |
|---|---|---|
| `legacy` | elk bericht van vóór Blog 2.0 (de standaard van migratie `20261011100000`) | de rich-text-tekst per taal, **byte voor byte** zoals vóór Blog 2.0 |
| `blocks` | elk nieuw bericht, en een klassiek bericht dat een redacteur omzette | de contentblokken van het bericht, via de gewone blokmotor |

**Geen heuristiek.** Of er blokken zijn, of een tekst, beslist niets. Een leeg
blok kan dus nooit een klassieke tekst laten verdwijnen, en een bericht in
blokmodus valt nooit terug op een oude tekst.

**Een bericht is een eigenaar van contentblokken**, net als een product en een
project (`CONTENT-BLOCKS.md`, "Blokken op een product of project"):
`App\Service\Blog\BlogPostContentOwner` (`blog_post`), de koppeltabel
`blog_post_content_pages` en het recht `blog.manage`. De inhoudspagina ontstaat
pas bij het eerste blok, en een geannuleerd eerste blok neemt haar weer mee.
Dezelfde kiezer, dezelfde blok-editors, Extra vormgeving, Responsive Media,
Knopstijlen en Kaartweergave, dezelfde woorden per taal. Het aanbod is de
bestaande `owners`-capability: alle gewone blokken, geen Paginakop en geen
Projectinformatie.

**Omzetten is een bewuste handeling** van de redacteur, op het tabblad
*Inhoud*, met een vraag in de dialoog van het CMS
(`api/admin/update-blog-post-content-mode.php`,
`App\Service\Blog\BlogContentConversion`):

- **Naar blokken**: heeft het bericht nog geen blokken, dan wordt de tekst in
  elke taal waarin hij bestaat **één Tekstblok** met precies dezelfde HTML.
  Beide gaan door `RichTextSanitizer`, en schone HTML opnieuw saneren verandert
  niets; `BlogTwoTest` bewijst dat per taal. Daarna toont het bericht zijn
  blokken.
- **Terug naar de klassieke tekst**: alleen de modus wisselt. De blokken blijven
  staan, maar worden niet getoond.
- **Er gaat nooit iets weg.** De klassieke tekst blijft na omzetten in
  `blog_post_translations` staan. Een opslag in blokmodus laat hem ongemoeid,
  omdat het veld er niet is en het endpoint de sleutel weglaat. Heen en terug
  is daardoor exact. Een tweede omzetting maakt geen tweede kopie: de blokken
  van eerder komen terug.
- **Er is geen massamigratie.** Een bestaande installatie, met hoeveel berichten
  dan ook, ziet na de update precies hetzelfde. Omzetten gebeurt per bericht,
  wanneer de redacteur dat wil.

Wat na omzetten zichtbaar verandert, is het kader, niet de tekst: de eigen
sectie en leeskolom van het Tekstblok in plaats van het klassieke
artikellichaam.

**Verwijderen.** Een bericht verwijderen ruimt eerst zijn blokken op
(`ContentPages::deleteFor()`: woorden, kindrijen, bestanden, drafts, de
koppeling en de inhoudspagina). De RESTRICT-sleutel weigert de andere
volgorde. Categorieën, tags en bibliotheekbeelden blijven.

### De publicatiecyclus

```text
draft      nooit publiek, ongeacht de datum
scheduled  publiek zodra published_at bereikt is
published  publiek zodra published_at nu of in het verleden ligt
archived   (Blog 2.0) het eigen adres antwoordt, als noindex; verder nergens
```

**Gearchiveerd** (Blog 2.0) is de vierde status van de Publishing Engine.
Het eigen adres en de canonical blijven, met `noindex,follow`
(`BlogSeo::forPost()`). Het bericht staat niet meer in het overzicht, de
categorie- en tagarchieven, de feed, de sitemap, de zoekresultaten of bij
gerelateerde berichten en buren. De detailroute, een slugredirect en een
blokknop naar het bericht vragen *reachable*
(`BlogPostStatus::isReachable()`, `findReachableById()`/`findReachableBySlug()`);
al het andere vraagt *listed*. De zoekfunctie vindt een bericht op wat zijn
pagina als tekst toont: in klassieke modus de body, in blokmodus de tekst van
de blokken, nooit allebei (Search 2.0, `SEARCH.md`, "De tekst van de
blokken"). Terugzetten naar gepubliceerd laat hetzelfde
adres weer gewoon meedoen. Er komt geen redirect en geen archiefpagina.

**Sinds v0.1.15 staat de Blog op de Publishing Engine**
([`docs/publishing/ARCHITECTURE.md`](docs/publishing/ARCHITECTURE.md)), als
eerste soort, via een **adapter**: geen kolom en geen rij is verhuisd. De drie
woorden hierboven zijn die van `PublicationStatus`. De engine kent ook
`archived`, maar die biedt de Blog pas aan in Blog 2.0, en tot die tijd leest
een gearchiveerde rij hier als concept.

Dat is in de praktijk **één regel**: een publicerende status (`published` of
`scheduled`), én een publicatiemoment dat gezet is en gepasseerd. Het is de
"listed"-regel van de engine, op twee plekken die elkaars spiegelbeeld zijn:
`BlogPostRepository::publicWhere()` (=
`PublicationVisibility::listedSql('p')`) in SQL en `BlogPostStatus::isPublic()`
(= `PublicationVisibility::isListed()`) in PHP. Het overzicht, de detailroute,
de archieven, de gerelateerde berichten, de sitemap en de feed lezen allemaal
daar doorheen. Er is dus geen query die per ongeluk een concept toont.

Tot v0.1.14 sloot de SQL alleen `draft` uit. Een met de hand bewerkte rij met
een onbekende status was daardoor publiek in SQL en een concept in PHP. Nu
is ze in beide een concept. Voor elke geldige rij is de uitkomst gelijk;
v0.1.15 heeft dat vóór en na vergeleken op dezelfde fixtures.

**Er draait niets op de achtergrond.** Een ingepland bericht verschijnt omdat
de klok verder liep, niet omdat er een cronjob was. Die klok is altijd die van
**PHP** (`App\Service\Blog\BlogClock`), nooit MySQL's `NOW()`: het adminformulier
schrijft `published_at` met PHP's klok, en op gedeelde hosting hebben PHP en
MySQL hun eigen tijdzone. Elke query bindt "nu" dus als parameter.

`BlogClock::freezeForTests()` bestaat zodat de interessante grens — één
seconde vóór en één seconde ná het moment — te testen is zonder te wachten.
`BlogClock` is sinds v0.1.15 een dunne naam voor `PublishingClock`: er is
één klok. Het tijdzonecontract en de strikte datuminvoer staan in
`docs/publishing/ARCHITECTURE.md`, "Tijd". Een getypte datum die geen echt
moment is ("morgen", 30 februari), wordt geweigerd met een melding in plaats
van als "nu" gelezen.

**Opslaan valideert** via `PublicationRules`: een status uit de lijst van de
Blog, een echte datum, en een datum bij *Ingepland*. De velden status, datum
en auteur zijn de gedeelde velden van `admin/_publication_fields.php`.
`App\Service\Blog\BlogPostPublishable` vertelt de engine hoe een bericht
heet en wat het nodig heeft om te publiceren: een titel in de standaardtaal
en een adres. Met dat laatste kan `api/admin/update-publication.php` een
bericht ook buiten de editor publiceren of terugzetten.

## Taxonomie

**Categorieën** zijn de vaste indeling: een actief-vinkje, een volgorde, en
per websitetaal een naam, een optionele korte omschrijving en het **adres van
haar archief** (`blog_category_translations`). Een bericht mag in
meerdere categorieën staan. De **primaire** categorie — die op een kaart en
boven een bericht staat — is simpelweg de eerste in de volgorde die de
redacteur zelf instelde. Er is geen `is_primary`-kolom die daarmee in
tegenspraak kan raken. Een inactieve categorie geeft een 404 op zijn archief
en staat niet in de sitemap; zijn berichten blijven gewoon bereikbaar.

**Tags** zijn, per websitetaal, een naam en het adres van hun archief
(`blog_tag_translations`). Meer niet: geen
hiërarchie, geen omschrijving, geen eigen SEO-teksten. Ze worden aangemaakt
waar ze gebruikt worden — op een bericht — en de **genormaliseerde slug is de
identiteit**, dus "Laser graveren", "laser-graveren" en "LASER GRAVEREN" zijn
één tag. Het scherm *Blogtags* bestaat voor de twee dingen die je niet vanaf
een bericht kunt: hernoemen, en overal tegelijk verwijderen.

### Veilig verwijderen

| Wat je verwijdert | Wat er gebeurt |
|---|---|
| Een bericht | de koppelrijen gaan mee (CASCADE); categorieën, tags en **mediabestanden** blijven |
| Een categorie | de koppelrijen gaan mee; geen bericht wordt verwijderd of gedepubliceerd — een bericht dat zijn enige categorie kwijtraakt is gewoon ongecategoriseerd |
| Een tag | idem |

Een categorie mét berichten is dus gewoon te verwijderen, met het aantal
zichtbaar naast de knop. Weigeren zou betekenen dat een redacteur zijn blog
niet kan herindelen zonder hem eerst leeg te maken. Elke verwijdering vraagt
eerst een bevestiging, de conventie van dit CMS.

## Beheerschermen

De zijbalk van dit CMS is plat en groepeert met witruimte, niet met kopjes
(`admin/_header.php`), dus de vier Blog-onderdelen noemen zichzelf en staan bij
elkaar in de 400-groep, naast Portfolio:

```text
Blogberichten     /admin/blog.php            blog.view
Blogcategorieën   /admin/blog-categories.php blog.manage
Blogtags          /admin/blog-tags.php       blog.manage
Bloginstellingen  /admin/blog-settings.php   blog.manage
```

*Nieuw bericht* is bewust géén vijfde item: dit CMS maakt vanuit een overzicht
aan (Pagina's, Formulieren, Producten doen dat ook), en `admin/blog.php` heeft
die knop.

**Het berichtoverzicht** toont titel, status, publicatiedatum, categorie en
laatst gewijzigd, met filters op status en categorie en een zoekveld op titel.
Alle drie zijn queryparameters, dus elke weergave is te bookmarken, de
terugknop werkt en er is geen regel JavaScript nodig. Een ingepland bericht
zegt er "nog niet zichtbaar" bij.

**De editor** heeft sinds Blog 2.0 vier tabbladen (`admin/_admin_tabs.php`):

```text
[ Algemeen ]    titel en samenvatting in één websitetaal, de uitgelichte
                afbeelding, de categorieën en de tags
[ Inhoud ]      klassiek: de rich-text-tekst, en een kaart "Omzetten naar
                contentblokken"; blokken: de bloklijst van het bericht (de
                lijst van een product en een project) en een kaart terug
[ Publicatie ]  de gedeelde velden van de Publishing Engine (status met
                Gearchiveerd, datum), de byline, de slug
[ SEO ]         SEO-titel, meta description, indexeerbaarheid, deel-afbeelding,
                en een voorbeeld van het zoekresultaat
```

De bloklijst en de twee wisselknoppen staan ná het formulier van het bericht:
een formulier kan geen formulier bevatten. Het tabblad *Inhoud* loopt
daarom door na `</form>`, zoals bij een project. Een blok-editor, toevoegen,
verwijderen en omzetten landen terug op `?tab=inhoud`.

**Eén formulier voor het bericht.** `api/admin/update-blog-post.php` leest het hele
bericht uit één verzoek, dus drie formulieren zouden elke opslag een
gedeeltelijke POST maken die leegmaakt wat de redacteur net niet bekeek.
Daarom eindigt elk paneel in dezelfde knop, en slaat elke knop alles op. De
opslagbalk (`admin/_save_bar.php`) bewaakt datzelfde formulier.

De tekst is het gedeelde rich-text-veld (`admin/_richtext_field.php`): een
gewone `<textarea>` die Quill vervangt als hij laadt. De server saneert wat
binnenkomt, welke van de twee het ook stuurde.

**Eén websitetaal per keer**, via `admin/_localized_fields.php` zoals elk
ander omgezet scherm (fase 5 van Multilingual 2.0): de taal komt uit de
schakelaar in de schil, rijdt mee als verborgen veld op het ene formulier, en
het endpoint schrijft precies die taal. De taalneutrale velden — de
afbeelding, de categorieën, de tags, de status — staan er in elke taal. De
**slug hoort sinds fase 6 bij de bewerkte taal**: hij is alleen in de
standaardtaal verplicht, een lege slug in een vertaling betekent "in deze taal
geen publieke URL", en het scherm zegt dat met zoveel woorden. Datzelfde geldt
op *Blogcategorieën* en *Blogtags*. Een nieuw bericht en een nieuwe categorie
worden in de standaardtaal geschreven; het zoekveld op het overzicht zoekt in
élke taal.

## Publieke kant

```text
/blog                     het overzicht, nieuwste eerst
/blog?pagina=2            volgende pagina
/blog/<slug>              één bericht
/blog/categorie/<slug>    categorie-archief
/blog/tag/<slug>          tag-archief
/blog/feed.xml            de RSS-feed
```

De routes staan sinds Multilingual 2.0 fase 6 niet meer in `.htaccess` maar in
`BlogModule::publicRoutes()`, die `App\Service\Routing\RouteTable` voedt
(`docs/multilingual/ROUTING.md`). De vorm is dezelfde gebleven: vaste
segmenten en een slug-tekenset, geen generieke padrouter. Omdat de hele module
onder één URL-woord leeft, kan een berichtslug nooit botsen met een
applicatieroute — alleen met een ander bericht **in dezelfde taal**, en dat
bewaakt de unieke index op `(language_code, slug)`.

**Elke taal heeft haar eigen adressen.** De standaardtaal houdt precies de
URL's hierboven; elke andere taal krijgt dezelfde vorm achter haar prefix, met
een eigen slug en een eigen woord voor het segment dat taal ís:

```text
/en/blog/my-post            /en/blog/category/wood
/en/blog/feed.xml           <language>en</language>, itemlinks in het Engels
```

Een bericht, categorie of tag heeft in een taal alleen een URL wanneer hij
daar een adres heeft (`BlogLocalization::postSlug()` en verwanten, die de
gedeelde regel `App\Service\Routing\LocalizedSlug` toepassen). Een kaart of
een "vorige/volgende"-link naar een bericht zonder adres in de gelezen taal
wijst naar het adres in de standaardtaal; de taalwisselaar biedt die taal
dan juist níét aan. In de editor hoort het adresveld bij de taal op het
scherm, en een vertaling krijgt haar eerste adres uit haar eigen titel.

**Eén template voor de drie lijsten** (`blog.php`), om dezelfde reden waarom
`collectie.php` het productraster van de shop hergebruikt: een archief ís het
overzicht onder een andere kop. **Paginering is echt**: het aantal per pagina
komt uit de instellingen, de server vraagt precies één pagina op, en de links
ertussen zijn gewone `<a>`-elementen. Er wordt nooit alles geladen en de helft
verborgen, en er hoort geen JavaScript bij deze module.

Een berichtpagina toont titel, categorie, datum, auteur, uitgelichte
afbeelding, de tekst, categorieën en tags, vorige/volgende bericht en
gerelateerde berichten.

**Gerelateerde berichten** zijn de berichten die de meeste categorieën en tags
delen, nieuwste eerst, maximaal drie. Deterministisch en uit te leggen: er
wordt niets gemeten aan wat iemand las of aanklikte. Een bericht zonder
taxonomie krijgt er géén in plaats van willekeurige.

De vormgeving komt volledig uit de publieke Theme-tokens (`THEMING.md`): in
`assets/css/blog/blog.css` staat geen hexwaarde en geen lettertypenaam.

## Media

Een uitgelichte afbeelding is een **verwijzing naar de Mediabibliotheek** en
niets anders — er is geen `image_path`-tweeling zoals bij de oudere tabellen,
want die is een historische terugval en deze tabel heeft geen historie
(`MEDIA.md`). Alt-tekst en afmetingen komen van het item zelf; er is bewust
géén eigen alt-veld per bericht, omdat dat een tweede waarheid zou zijn die
niemand onderhoudt.

`App\Service\Blog\BlogPostMediaUsage` beantwoordt de vraag van de bibliotheek
in één query voor een hele reeks id's, en meldt:

```text
Blogbericht: <titel>
Deel-afbeelding van blogbericht: <titel>
```

met een link naar de editor. Zolang een bericht een afbeelding gebruikt, kan
die niet verwijderd worden; de `RESTRICT`-foreign key is het vangnet
daaronder. Een bericht verwijderen haalt alleen de verwijzing weg — het
bestand is van de bibliotheek en staat misschien op drie andere plekken.

## SEO

De Blog voegt **geen SEO-machinerie toe**. Hij levert een
`App\Service\SeoMetadata` op, precies zoals `PageSeo` dat voor een CMS-pagina
doet, en `partials/seo-head.php` rendert het (`SEO.md`).

```text
titel         meta_title, letterlijk
              → "<titel> | <blogtitel> — <sitenaam>"
description   meta_description → de samenvatting → de site-standaard → geen tag
canonical     /blog/<slug>, altijd tegen APP_URL
robots        noindex als het bericht dat zegt, anders index,follow
afbeelding    eigen deel-afbeelding → uitgelichte afbeelding → site-standaard
```

**Gestructureerde data**: één `BlogPosting`-node per bericht, gebouwd uit wat
de rij echt bevat — kop, URL, publicatie- en wijzigingsdatum, beschrijving,
afbeelding, en een auteur alléén als er een naam is ingevuld. Geen
beoordelingen, geen verzonnen feiten, geen broodkruimellijst die deze site niet
rendert. Overzicht en archieven leveren niets: dat zijn lijsten.

**Indexeerbaarheid van archieven**:

| Pagina | Robots | Sitemap |
|---|---|---|
| `/blog` | index | ja |
| `/blog/<slug>` | index tenzij het bericht `noindex` zegt | dezelfde beslissing |
| `/blog/categorie/<slug>` | index | ja, zodra hij minstens één publiek bericht heeft |
| `/blog/tag/<slug>` | **noindex,follow** | nee |

Tags zijn vrije tekst en vermenigvuldigen zich; een handvol vrijwel identieke,
dunne lijstpagina's is precies wat je een zoekmachine niet moet aanbieden. Ze
blijven wel gewoon te linken en te crawlen. Een gepagineerde lijst is canoniek
naar zichzelf, dus pagina 2 is geen duplicaat van pagina 1.

## RSS

`/blog/feed.xml` wordt bij elk verzoek gebouwd, net als de sitemap. Per item:
titel, de eigen canonieke URL als `link` én als `guid`, de publicatiedatum en
de samenvatting als `description`. De **volledige tekst wordt bewust niet
meegestuurd**. De kanaalgegevens komen uit de instellingen van de site en uit
`APP_URL`; er staat geen domeinnaam in de code.

**Elke taal heeft haar eigen feed, helemaal in die taal.** `/blog/feed.xml` is
de standaardtaal, `/en/blog/feed.xml` Engels. De kanaaltitel en -beschrijving,
de titel en samenvatting van elk item, `<language>` en elke link volgen de taal
van het verzoek, met dezelfde terugval als de pagina's zelf: gevraagde taal →
standaardtaal → leeg, of "Blog" voor de titel. Categorie- en tagnamen staan
niet in de feed. Elke feed bevat dezelfde berichten; een bericht zonder adres in
die taal linkt naar zijn adres in de standaardtaal, zoals elke andere interne
link. De `<head>` van `/en/blog` en van een Engels bericht verwijst naar de
Engelse feed, onder de Engelse blogtitel.

Concepten en berichten die nog moeten verschijnen zitten er niet in. Een
`noindex`-bericht wél: `noindex` is een instructie aan een zoekmachine over
zijn resultatenpagina's, en wie zich op deze blog abonneert heeft om alle
berichten gevraagd.

Staat de RSS-schakelaar uit, dan geeft die URL een 404 en verdwijnt de
verwijzing uit de `<head>`.

## Redirects

Een gepubliceerd bericht dat een andere slug krijgt, houdt zijn oude URL:
`App\Service\Blog\BlogPostService::recordSlugChange()` gebruikt **dezelfde**
`SlugChangeRedirects` die een hernoemde CMS-pagina gebruikt (`REDIRECTS.md`).
Eén redirecttabel, één origin-waarde, dezelfde regel dat een handmatig
geschreven rij nooit overschreven wordt, en herhaald hernoemen klapt in tot één
sprong.

Voorwaarden, gelijk aan die van een pagina: de slug is echt veranderd, en het
bericht was publiek vóór het opslaan én daarna. Een concept had nooit een
werkende URL, en hernoemen terwijl je iets offline haalt zou de ene dode URL
naar de andere wijzen.

Een hernoemd **categorie- of tag-archief** doet hetzelfde
(`App\Service\Blog\BlogTaxonomy`), zolang het archief bereikbaar was.
**Verwijderen schrijft nooit een redirect** — een bestemming verzinnen voor
inhoud die weg is, is hoe een schone 404 een soft 404 wordt.

Twee routes vragen het aan de Redirect Manager vóórdat ze een 404 geven
(`blog.php` en `blog-post.php`), om dezelfde reden als `pagina.php`: Apache
heeft het verzoek wél gerouteerd, dus `404.php` ziet het nooit.

## Rechten

```text
blog.view    het berichtenoverzicht inzien
blog.manage  schrijven, publiceren, verwijderen, en categorieën, tags en
             instellingen beheren — bevat blog.view en media.view
```

Twee, niet drie. Er is geen aparte publicatiepermissie, want die is pas iets
waard op een site met een redactionele goedkeuringsstroom, en die is voor V1
uitdrukkelijk niet gebouwd. Een derde toevoegen kost later één constante en
één regel in de permissiegroep.

Elk Blog-scherm en elk endpoint vraagt zelf om zijn permissie; dat is ook wat
ze laat weigeren zodra de module uit staat, want een permissie van een
uitgeschakelde module wordt door niemand gehouden — ook niet door een Super
Admin. De publieke routes hebben daarnaast een expliciete `ModuleGuard`.

## Instellingen

Een eigen key/value-tabel, `blog_settings`, om dezelfde reden als de vier
andere: niets wat de een reset mag bij de ander kunnen. Leeg geseed, dus een
ontbrekende rij betekent de codestandaard.

```text
berichten per pagina          9   (tussen 3 en 48)
publicatiedatum tonen         aan
auteur tonen                  aan
gerelateerde berichten        aan
RSS-feed                      aan
```

Meer wordt dit niet. Hoe de blog eruitziet komt uit Vormgeving, net als bij
elke andere pagina; een tweede vormgevingsscherm voor één module is precies wat
een module duur maakt.

**De blogtitel en de introtekst staan per websitetaal.** Het zijn geen rijen
in `blog_settings` maar woorden, en woorden staan sinds Multilingual 2.0 per
taal. Ze wonen in `site_setting_translations` — dezelfde fysieke tabel als de
gelokaliseerde Core-instellingen — maar in een **eigen gesloten catalogus** van
de Blog:

```text
App\Service\Blog\BlogLocalizedSettings   blog_title, blog_intro
App\Service\LocalizedSiteSettings        city, footer_description,
                                         footer_slogan,
                                         related_products_heading
```

Beide staan op hetzelfde opslagprimitief, `LocalizedSettings` in
`App\Service\Language`, dat zelf geen enkele sleutel kent. **De opslag is
gedeeld, de catalogus niet**: Core noemt `blog_title` nergens, dus Core weet
nog steeds niet dat er een blog bestaat (`MODULES.md`), en de Blog kent de
Core-sleutels niet. Geen van beide kan de sleutels van de ander lezen of
schrijven, en een request kan bij geen van beide een sleutel verzinnen.

De terugval is die van `LanguageFallback`: gevraagde taal, standaardtaal, leeg.
Daarna komt nog één codestandaard: een blog die niemand hernoemd heeft heet in
elke taal "Blog". Een lege introtekst blijft leeg — dan staat er gewoon geen
alinea. Migratie `20260918260000` heeft de vier oude sleutels
(`blog_title`/`blog_title_en`, `blog_intro`/`blog_intro_en`) uit
`blog_settings` gehaald en verwijderd.

Het instellingenscherm toont daarom **één websitetaal tegelijk**, die van de
schil, net als elke andere editor: opslaan in het Engels laat het Nederlands
staan, en een derde taal is een rij in `site_languages`, geen kolom.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast   # BlogModuleTest
docker compose exec php_test php vendor/bin/phpunit --testsuite blog   # alles
```

| Bestand | Wat het bewaakt |
|---|---|
| `tests/Blog/BlogModuleTest.php` | registratie, de standaard-uit, wat de module bijdraagt en wat er verdwijnt, de guards op elk scherm en endpoint, en dat Core geen Blog-klasse noemt. Geen database |
| `tests/Blog/BlogPostLifecycleTest.php` | CRUD, slugs, de drie statussen, de inplangrens op de seconde, tweetalige terugval, buren en gerelateerde berichten |
| `tests/Blog/BlogTaxonomyTest.php` | categorieën, tag-ontdubbeling, veilig verwijderen, archief-redirects |
| `tests/Blog/BlogSeoTest.php` | metadata, `BlogPosting`, sitemap in/uit, de feed |
| `tests/Blog/BlogFeedLanguageTest.php` | één feed per taal: kanaal, items, `<language>` en links in de taal van het verzoek, de terugval, XML-escaping, en `/en/blog/feed.xml` over echt HTTP |
| `tests/Blog/BlogMediaAndSettingsTest.php` | uitgelichte afbeeldingen, gebruiksmelding, niet-verwijderbaar, en de instellingen |
| `tests/Blog/BlogRoutingTest.php` | echte verzoeken naar alle vijf de URL's, paginering, de slugredirect, en de CMS-only stand |
| `tests/Blog/BlogTwoTest.php` | Blog 2.0 over echt HTTP: gearchiveerd overal, concept en toekomst niet bereikbaar, de editor met Gearchiveerd, een klassiek bericht dat een blok niet kan verbergen, de levensloop van blokken op een nieuw bericht (annuleren zonder wees, opslaan, volgorde, verwijderen, bericht verwijderen), rechten en vervalste eigenaars, omzetten heen en terug zonder verlies, hreflang |
| `tests/Install/BlogContentBlocksMigrationTest.php` | migratie `20261011100000` op een blog zoals hij live staat: geen rij verandert, elk bericht `legacy`, RESTRICT-sleutels, nogmaals draaien, vers = geüpgraded (ook in `migration`) |
| `tests/Blog/BlogPublishingTest.php` | de Blog op de Publishing Engine: de adapter, geweigerde en toegestane wijzigingen, sitemap met hreflang, `update-publication.php` over echt HTTP, de gedeelde velden in de editor |

De HTTP-tests praten met `php_test`, die daarvoor `MODULE_BLOG_ENABLED=true`
meekrijgt in `docker-compose.yml` — anders zou elke `/blog`-test een 404
testen. `php_cms` vraagt de Blog juist níét aan, en is daarmee het bewijs dat
een uitgeschakelde module over echt HTTP niets uitzendt.

## Bewust niet gedaan

Niet gebouwd, en niet gepland tenzij er een concrete aanleiding komt:

- **reacties**, gebruikersaccounts voor lezers, likes;
- **een nieuwsbrief**, sociale publicatie, webhooks, externe blog-API's;
- **AI**: geen gegenereerde artikelen, geen gegenereerde SEO-teksten, geen
  aanbevelingen op basis van gedrag;
- **een redactionele goedkeuringsstroom**, revisies, versiegeschiedenis,
  gelijktijdig bewerken;
- **een eigen blogthema**, en een Paginathema per bericht: thema's horen bij
  gewone pagina's (`THEMING.md`); een bericht in blokken gebruikt Extra
  vormgeving per blok. Een paginabouwer per bericht is er sinds Blog 2.0
  wél: de gewone blokmotor, geen eigen;
- **een generieke taxonomie**: categorieën en tags blijven van de Blog. De data
  (de repositories met hun vertalingen) staat al los van de archiefroutes
  (`BlogUrls`, `BlogTaxonomy`). Een gedeelde laag volgt pas als Articles 1.0
  haar echt nodig heeft (`docs/publishing/ARCHITECTURE.md`, "Taxonomie");
- **een contentblok** ("laatste berichten" op de homepage). Dat is een echte
  functie met echte ontwerpkeuzes; een half doordacht blok is erger dan geen;
- **koppeling met producten** ("dit product hoort bij dit artikel").
