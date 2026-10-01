# Artikelen

Zelfstandige, redactionele content: stukken die je leest om wat ze uitleggen,
niet om wanneer ze verschenen. Een optionele module (`articles`) die
**standaard uit** staat, gebouwd in v0.1.15, fase 6, als tweede soort op de
Publishing Engine (`docs/publishing/ARCHITECTURE.md`).

Wijkt de code af van dit document, dan heeft de code gelijk.

## Blog versus Artikelen

Deze twee lijken op elkaar en zijn het niet. Haal ze niet door elkaar.

| | Blog (`BLOG.md`) | Artikelen |
|---|---|---|
| Wat het is | Een logboek: berichten in de tijd | Evergreen stukken die op zichzelf staan |
| Datum | Hoofdzaak: overzicht op datum, vorige/volgende | Bijzaak: een kleine regel na de auteur |
| Indeling | Categorieën (veel-op-veel, de eerste is primair) en tags | Hoogstens één **onderwerp** per artikel |
| Archieven | Categorie- en tagarchieven, tagarchief `noindex` | Eén onderwerppagina per onderwerp |
| Feed | RSS | Geen |
| Gerelateerd | "Meer lezen" en vorige/volgende | Niets |
| Inhoud | Klassieke tekst of contentblokken (`content_mode`) | Alleen contentblokken |
| Talen | Woorden vallen terug op de standaardtaal | Geen terugval: een taal zonder versie bestaat niet |
| Instellingen | Bloginstellingen (titel, intro, datum, auteur, RSS) | Geen instellingenscherm |
| Recht | `blog.view` en `blog.manage` | Alleen `articles.manage` |
| Type | `blog_post` | `article` |

Wat ze delen, is de infrastructuur, niet de code van de ander: de Publishing
Engine, de contentblokken via `ContentOwner`, Multilingual 2.0
(`EntityTranslations`), `SeoMetadata`, de Mediabibliotheek,
`SlugChangeRedirects`, de sitemap, de zoekfunctie, `LinkTargets` en
`LinkedImages`. Een Articles-klasse noemt nooit een Blog-klasse, en
andersom.

## Paden

| Laag | Paden |
|---|---|
| Module | `src/Module/ArticlesModule.php` |
| Logica | `src/Service/Articles/` |
| Opslag | `src/Repository/ArticleRepository.php`, `ArticleTopicRepository.php` |
| Schermen | `admin/articles.php`, `admin/article.php`, `admin/article-topics.php` |
| Endpoints | `api/admin/{create,update,delete}-article.php`, `save-article-topic.php`, `delete-article-topic.php` |
| Publiek | `articles.php` (overzicht en onderwerp), `article.php` (één artikel) |
| Frontend | `assets/css/articles/articles.css` |
| Migratie | `db/migrations/20261012100000_create_the_articles_module_tables.php` |
| Tests | `tests/Module/ArticlesTest.php`, `tests/Install/ArticlesMigrationTest.php` |

## Datamodel

```text
articles                    id, status, published_at, author_name,
                            featured_media_id (media, RESTRICT),
                            topic_id (article_topics, SET NULL), noindex,
                            created_at, updated_at
article_translations        article_id (CASCADE), language_code (RESTRICT),
                            slug, title, excerpt, meta_title, meta_description
                            UNIQUE(article_id, language_code), UNIQUE(language_code, slug)
article_topics              id, sort_order
article_topic_translations  article_topic_id (CASCADE), language_code, slug, name, description
article_content_pages       article_id -> page_id, RESTRICT aan beide kanten
```

- **Geen neutrale `slug`-kolom en geen `body`.** Artikelen zijn nieuw, dus er
  is geen oud adres en geen klassieke tekst om te bewaren. Het adres in een
  taal is precies de `slug` van die taal.
- **De inhoud is contentblokken**, via `ArticleContentOwner` (soort `article`)
  op een contentpagina van de gewone blokkenmotor.
- Woorden en adressen lopen alleen via `App\Service\Articles\ArticleLocalization`
  (de Multilingual-grens, `MultilingualBoundaryTest`).

## Publiceren

Alle vier de statussen van de engine: Concept, Gepubliceerd, Ingepland,
Gearchiveerd. Zichtbaarheid, klok en regels zijn die van de engine
(`PublicationVisibility`, `PublishingClock`, `PublicationRules`); Articles
voegt alleen zijn eigen publicatieregel toe (`ArticleService::publishErrors()`):

- een titel in de standaardtaal;
- een adres in de standaardtaal;
- minstens één blok dat iets zegt (`ContentPages::hasMeaningfulBlocks()`):
  zichtbaar, geregistreerd, geen paginakop, niet decoratief (Witruimte,
  `BlockDefinition::isDecorative()`), en voor een blok dat zijn eigen inhoud
  kan beoordelen niet leeg.

Een afbeelding en een onderwerp zijn niet verplicht. De editor beoordeelt de
regel op wat het artikel **na** het opslaan zou zijn: hij schrijft in een
transactie, leest het terug en rolt alles terug bij een weigering. Het
endpoint `api/admin/update-publication.php` vraagt dezelfde regel.

Een artikel dat al gepubliceerd is, blijft gepubliceerd als iemand later zijn
laatste blok weghaalt; de regel geldt bij het publiceren.

**Gearchiveerd**: het eigen adres blijft werken met `noindex`, maar het
artikel staat niet in het overzicht, niet op de onderwerppagina, niet in de
sitemap en niet in de zoekfunctie. Een knop ernaartoe (`LinkTargets`) blijft
werken, omdat die de *reachable*-regel volgt.

## Routes en talen

```text
/artikelen                      overzicht           articles.php
/artikelen/onderwerp/<slug>     één onderwerp       articles.php
/artikelen/<slug>               één artikel         article.php
/en/articles, /en/articles/topic/<slug>, /en/articles/<slug>
```

De woorden `artikelen` en `onderwerp` zijn route-segmenten per taal
(`ArticlesModule::routeSegments()`); een derde taal krijgt de standaardwoorden
achter zijn eigen prefix. De onderwerproute staat vóór de artikelroute.

- **Een artikel bestaat in een taal als het daar een adres én een titel
  heeft**, en wordt dan alleen in de woorden van die taal getoond. Er is geen
  terugval: `/en/articles/x` van een alleen-Nederlands artikel is een 404, en
  het Engelse overzicht toont het niet. Contentblokken volgen hun eigen
  vertaalcontract (blokwoorden vallen terug op de standaardtaal).
- Een vertaling zonder adres krijgt er bij de eerste opslag een uit haar
  eigen titel; een vertaling zonder titel heeft geen adres.
- **Hreflang** noemt alleen echte versies (`ArticleContent::alternates()`).
- **Redirects**: een nieuw adres van een bereikbaar artikel schrijft in die
  ene taal een redirect via `SlugChangeRedirects`; een concept niet, en een
  statuswissel nooit. Een hernoemd onderwerp krijgt er ook een. Verwijderen
  schrijft er geen (`REDIRECTS.md`).

## Onderwerpen: het taxonomiebesluit

Artikelen krijgen **één optioneel onderwerp per artikel**, geen tags, en
**geen gedeelde taxonomielaag**. Waarom:

- De Blog-taxonomie is veel-op-veel met een primaire categorie, een
  actief-vinkje, tags die op hun slug ontdubbeld worden en `noindex`-
  tagarchieven. Articles heeft één platte waarde nodig. Wat overlapt, is
  alleen "een naam, een omschrijving en een adres per taal", en dat is al
  gedeeld: `EntityTranslations`, `LocalizedSlug`, `SlugChangeRedirects`.
- Een `ContentTaxonomy` zou de Blog-regels moeten nabouwen voor één
  gebruiker, plus een verliesvrije migratie van de Blog. Dat is pas zinvol als
  een derde soort óók veel-op-veel indeling vraagt.

Een onderwerp verwijderen laat de artikelen staan (`SET NULL`). Een
onderwerppagina toont alleen *listed* artikelen met een versie in de
gelezen taal. Een leeg onderwerp staat niet in de onderwerpenrij en niet in
de sitemap, maar zijn adres antwoordt wel (met "nog geen artikelen").

## Het CMS

- **Artikelen** (`admin/articles.php`): titel met live-adres, status,
  publicatie (met "nog niet zichtbaar"), onderwerp, laatst gewijzigd; filters
  op status (Alle/Concept/Gepubliceerd/Ingepland/Gearchiveerd), onderwerp en
  titel, als queryparameters. Nieuw artikel = titel → concept.
- **Artikel** (`admin/article.php`): tabbladen Algemeen (titel, adres, intro,
  uitgelichte afbeelding, auteur, onderwerp), Inhoud (de bloklijst van
  `content_blocks_owner_panel()`), Publicatie (`admin_publication_fields()`
  met alle vier statussen en wat er nog ontbreekt) en SEO (titel,
  omschrijving, noindex, voorbeeld). Eén formulier, één taal per opslag.
- **Artikelonderwerpen** (`admin/article-topics.php`): één lijst met
  volgorde; bewerken per taal.

De blokkenkiezer biedt elk blok dat zich niet tot een andere eigenaar beperkt
(`meta()['owners']`); er staat geen lijst in Articles. Extra vormgeving werkt
zoals op elke pagina. Een paginathema per artikel is er niet.

## Publiek

- **Overzicht**: een rustige kolom rijen (beeld, onderwerp, titel, intro,
  auteur en datum klein onderaan), twaalf per pagina met echte links, de
  onderwerpen als linkrij. Kaarten gebruiken de thumbnail van de bibliotheek
  als `srcset`-kandidaat. Bewust **niet** `item_gallery`/Card Presentation:
  dat is een galerij van items; een "laatste artikelen"-blok via dat contract
  is een latere aansluiting.
- **Artikel**: kruimelpad (met onderwerp), onderwerp als eyebrow, titel, intro
  als lead, "Door …" en datum als stille regel, uitgelichte afbeelding, dan de
  blokken op hun eigen breedte. Geen zijbalk, geen tags, geen vorige/volgende.

## Integraties

| Wat | Hoe |
|---|---|
| SEO | `ArticleSeo` → `SeoMetadata`: eigen of afgeleide titel en omschrijving, canonical per taal, `og:type` article, `Article`-JSON-LD (auteur alleen als er een byline is), noindex bij archief of vinkje |
| Sitemap | `ArticlesModule::sitemapCollectors()`: overzicht per taal, *listed* en indexeerbare artikelen per echte versie met hreflang, onderwerpen met artikelen; één query voor de rijen en één voor de woorden |
| Zoeken | `ArticleSearchProvider` (type `article`): titel en intro, alleen *listed* en indexeerbaar, per taal. **Niet** de tekst van de blokken: dat is Search 2.0 |
| LinkTargets | type `article`; href volgt *reachable*, in de gelezen taal, anders de standaardtaal, anders een andere versie |
| LinkedImages | `article` → de uitgelichte afbeelding, zodat de Detailsectie een artikel als beeld met link kan tonen |
| Media | `ArticleMediaUsage` (één query per batch) plus `RESTRICT`: een uitgelichte afbeelding is niet te verwijderen zolang een artikel haar gebruikt |

## Module uit

Uit betekent: geen menu, geen houdbaar recht (scherm en endpoints 403), een
404 op elke publieke URL (`ModuleGuard`), geen publishable, geen link target,
geen linked image, geen mediagebruik, geen sitemapregels, geen zoekprovider.
Er verdwijnt geen rij en geen blok; weer aanzetten herstelt alles. De woorden
`artikelen`, `articles` en `article` blijven gereserveerd.

## Verwijderen

`api/admin/delete-article.php`: eerst `ContentPages::deleteFor()` (blokken via
`SectionRegistry::delete()`, drafts, link en pagina, in eigen transactie;
de `RESTRICT`-sleutel eist die volgorde), dan de rij met haar vertalingen
(`CASCADE`). Mislukt de tweede stap, dan blijft een artikel zonder blokken
over dat opnieuw verwijderd kan worden. Bestanden in de bibliotheek blijven.

## Testen

```bash
docker compose exec php_test php vendor/bin/phpunit tests/Module/ArticlesTest.php
docker compose exec php_test php vendor/bin/phpunit tests/Install/ArticlesMigrationTest.php
```

`ArticlesTest` (suite `modules`) start zijn eigen ingebouwde server met de
module aan en voor de uit-controle een tweede met de module uit.
`ArticlesMigrationTest` (suites `migration` en `modules`) bouwt een verse en
een geüpgradede database. De testcontainer zet `MODULE_ARTICLES_ENABLED=true`.

## Bewust niet gebouwd

- Een gedeelde taxonomielaag, tags, meerdere onderwerpen per artikel.
- Een instellingenscherm (titel en intro van het overzicht zijn vaste woorden).
- Een feed, archieven op datum, gerelateerde artikelen.
- Author Management: de byline is vrije tekst.
- Een paginathema per artikel.
- Een "laatste artikelen"-blok of een `item_gallery`-bron.
- Zoeken in de tekst van de blokken (Search 2.0).
