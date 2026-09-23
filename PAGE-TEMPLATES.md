# Paginasjablonen

Genoeg om een sjabloon te begrijpen, te gebruiken of erbij te schrijven
**zonder de code eerst helemaal te lezen**. Wijkt de code af van dit
document, dan heeft de code gelijk — pas het document aan.

Begin bij [`PROJECT-MAP.md`](PROJECT-MAP.md) als je nog niet weet waar iets
staat, en bij [`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md) voor de blokken
waaruit een sjabloon put. Voor wat een verse installatie wél en niet zelf
aanmaakt: [`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md).

## Wat dit is

Een paginasjabloon is een **startpunt** dat de redacteur kiest in *Nieuwe
pagina*. Het bepaalt welke contentblokken de nieuwe pagina meekrijgt, en
daarna is het klaar.

```text
Nieuwe pagina
    ↓
titel + slug + sjabloon kiezen
    ↓
pagina aangemaakt (Concept) + de eerste blokken
    ↓
paginabouwer
    ↓
vanaf hier een doodgewone CMS-pagina
```

## De regel die alles bepaalt

**Een sjabloon leeft alleen op het moment van aanmaken.**

Nergens wordt vastgelegd uit welk sjabloon een pagina komt. Er is geen
`pages.template`-kolom, geen sjabloontabel en geen renderpad dat een sjabloon
opvraagt. Een pagina uit *Over ons* is niet "een Over-ons-pagina": het is een
gewone contentpagina waar toevallig vier blokken op stonden toen hij ontstond.

Wat daaruit volgt:

- de redacteur mag élk gegenereerd blok bewerken, verbergen, verslepen en
  verwijderen — ook allemaal;
- de pagina rendert precies zoals elke andere CMS-pagina, met `pagina.php`;
- SEO, sitemap, redirects en verwijderen werken zonder één sjabloonspecifiek
  pad;
- een sjabloon later wijzigen verandert **niets** aan pagina's die er ooit
  uit gemaakt zijn.

Dit is met opzet geen paginatype. Een `page.type = about` zou betekenen dat
render, rechten of validatie ergens op dat type gaan letten, en dan is de
redacteur zijn vrijheid kwijt.
`Tests\Service\PageTemplateCreationTest` bewaakt de regel: geen kolom in
`pages` of `page_sections` mag naar een sjabloon verwijzen.

## Het register

Dezelfde vorm — en dezelfde reden — als
`App\Service\Blocks\BlockDefinitions`.

| Bestand | Wat het is |
|---|---|
| `src/Service/PageTemplates/PageTemplateDefinition.php` | Het contract: `key()`, `label()`, `description()`, `blocks()`, optioneel `icon()` |
| `src/Service/PageTemplates/PageTemplates.php` | Dé registratielijst — één hardgecodeerde map |
| `src/Service/PageTemplates/<Naam>Template.php` | Eén sjabloon, één bestand |
| `src/Service/PageTemplates/PageTemplateInstaller.php` | De enige plek waar een sjabloon wordt toegepast |

Registratie is **expliciet en gesloten**. Geen mapscan, geen reflectie, geen
klassenaam uit een string, geen sjabloon uit de database. De sleutel komt uit
een verzoek, en het enige wat die sleutel ooit kan doen is een key van die map
raken of missen. Een misser valt terug op *Lege pagina*.

**Alleen Core, anders dan bij de blokken.** Een module levert géén
paginasjablonen. Een "Shop-pagina"-sjabloon zou óf botsen met een route die de
Shop zelf al bezit, óf de redacteur een blok geven dat verdwijnt zodra de
module uitgaat. Sjablonen noemen daarom uitsluitend Core-blokken, en de kiezer
ziet er identiek uit met de Shop aan of uit.

## De V1-sjablonen

| Sleutel | Label | Blokken |
|---|---|---|
| `blank` | Lege pagina | Page Hero |
| `standard` | Standaard contentpagina | Page Hero, Tekstblok |
| `about` | Over ons | Page Hero, Text + Image Split, Tekstblok, CTA Band |
| `services` | Diensten | Page Hero, Kaarten-carrousel, Tekstblok, CTA Band |
| `contact` | Contact | Page Hero, Formulier, Contactkaart |
| `landing` | Landingspagina | Page Hero, Text + Image Split, Feature Grid, CTA Band |

*Lege pagina* is de standaardkeuze: wie de kiezer negeert, krijgt een pagina
met alleen de paginakop. Daaronder kiest de redacteur zelf het eerste blok, en
de paginabouwer nodigt daartoe uit ([`PAGE-EDITOR.md`](PAGE-EDITOR.md), *Een
pagina zonder inhoud*). Er komt bewust geen leeg tekstblok bij: een blok waar
niemand om vroeg, is een blok dat je eerst moet weghalen.

Elk sjabloon opent met de Page Hero, ook *Lege pagina*. Die draagt de `<h1>`
van de pagina; een pagina die alleen uit een tekstblok bestaat heeft er geen.
Bestaande pagina's veranderen hier niet door: een sjabloon werkt alleen op het
moment van aanmaken.

### Startinhoud

Een sjabloon levert **structuur, geen tekst**. De startinhoud van een blok
hoort bij het blok dat de velden bezit — elke `BlockDefinition::create()`
schrijft al duidelijk generieke, bewerkbare tekst ("Nieuwe sectie — pas deze
titel aan"). Zou een sjabloon daar een eigen kopie van bijhouden, dan waren er
twee eigenaren van dezelfde tekst, en dan loopt die uit elkaar zodra een blok
een veld krijgt, hernoemt of kwijtraakt.

Er staat dus geen sitespecifieke tekst in de sjablonen, en geen verzonnen
beweringen over het bedrijf. Nieuwe pagina's beginnen bovendien in **Concept**,
dus een startblok komt nooit per ongeluk publiek te staan.

### Het formulierblok van Contact

Een Contact-pagina aanmaken maakt **geen formulierdefinitie** aan. Dat zou
stiekem een ding met velden, meldingsadressen en bewaarde inzendingen
opleveren dat de redacteur niet heeft gevraagd.

Het blok wordt aangemaakt met `form_id = NULL`. Dat is een toestand die het
Formulier-blok overal al aankan: de paginabouwer toont *"nog geen formulier
gekozen"*, en de publieke pagina rendert er niets voor tot de redacteur er een
kiest (`partials/section-form.php`). Geen kapotte verwijzing, geen half
formulier — een lege plek met een duidelijke volgende stap.

## Hoe de secties ontstaan

`PageTemplateInstaller` heeft geen eigen opslaglogica. Het doet per blok
precies wat `api/admin/add-page-section.php` doet als een redacteur er met de
hand een toevoegt:

```text
begin transactie
  → PageRepository::create()               de pagina (is_system = 0, route_path = NULL)
  → per bloktype:
       SectionRegistry::create()           de inhoudsrij van het blok
       PageSectionRepository::create()     de koppeling, onderaan de lijst
commit
  → caches legen
```

**Alles of niets.** Faalt een sjabloon halverwege, dan blijft er geen pagina
achter — geen lege, geen half gevulde. Dat kan omdat geen van die drie
aanroepen zelf een transactie opent; PDO kent geen geneste transacties. (Het
omgekeerde, `SectionRegistry::delete()`, opent er wél een, en dáárom kan
`PageService::delete()` zijn lus níet zo omvatten.)

Vóór de transactie controleert de installer nog dat elk genoemd bloktype
geregistreerd is én met de hand plaatsbaar — een sjabloon dat een vast blok of
een blok van een uitgeschakelde module noemt, faalt aan de deur in plaats van
een pagina terug te draaien die het al aan het bouwen was.

## Wat er ná het aanmaken gebeurt

De redacteur belandt meteen in `admin/page.php?id=<id>&created=1`: de gewone
pagina-editor. Er is geen aparte "sjablooneditor" en er komt er ook geen.

**SEO** loopt volledig via het bestaande generieke systeem (`SEO.md`). Een
sjabloon zet geen `meta_title`, geen `meta_description`, geen `noindex` en
geen deel-afbeelding. De pagina krijgt dus de standaardtitel
"*Titel* — *sitenaam*", de globale meta description en deel-afbeelding, een
canonical uit `PageContent::canonicalUrl()`, en hij komt in de sitemap zodra
hij gepubliceerd en indexeerbaar is. Precies zoals een pagina die iemand met
de hand heeft gebouwd.

**De URL** is een gewone CMS-slug: `/over-ons`, niet `/over-mij.php`. Slug,
reserveringen, botsingen en de automatische redirect bij hernoemen komen
ongewijzigd van `PageService` en `ReservedRoutes` (`REDIRECTS.md`). Er is geen
tweede URL-validatie bijgekomen.

## Waarom een verse installatie deze pagina's niet krijgt

Tot deze opruiming kreeg élke nieuwe database Diensten, Portfolio, Over mij,
Contact en drie Nederlandse juridische pagina's mee: de migraties die de
inhoud van Van Veluw Laserdesign uit de templates haalden, draaiden ook op een
database die nooit iets met dat bedrijf te maken had gehad.

Dat is nu weg. Core maakt de Homepage en verder niets; de Shop-module brengt
zijn winkel als eigen route, niet als pagina — zie
[`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md).

**Sjablonen zijn wat daarvoor in de plaats komt.** Een Diensten-, Over ons- of
Contactpagina is een gewone CMS-pagina; die hoeft niet te bestaan voordat
iemand hem wil, en hij is met *Nieuwe pagina* + een sjabloon in één handeling
gemaakt. Dat is op drie manieren beter dan zaaien: de pagina bestaat alleen
als de site hem nodig heeft, hij begint in Concept in plaats van gepubliceerd,
en zijn inhoud komt van de redacteur in plaats van uit een migratie.

Wat een sjabloon daarom níét doet: hij maakt geen juridische pagina's aan en
er is er ook geen voor. Welke pagina's een bedrijf zijn klanten verschuldigd
is hangt van dat bedrijf af, en een sjabloon dat concept-voorwaarden neerzet
zou precies dezelfde fout maken als de migratie die dat deed.

## Grenzen

**Homepage.** De site-root blijft beschermd. Een sjabloon maakt altijd een
gewone contentpagina (`PageRepository::create()` zet `is_system = 0` en
`route_path = NULL` hard), dus er kan geen tweede homepage uit komen en de
bestaande kan er niet door veranderen.

**Modules.** De Shop bezit zijn eigen routes. Sjablonen bieden geen
Shop-pagina aan, noemen geen blok van een module, en gedragen zich identiek
met de Shop uit.

**Vaste legacy-URL's.** `/diensten.php`, `/portfolio.php`, `/over-mij.php` en
`/contact.php` blijven zoals ze zijn op de installatie die ze heeft.
Sjablonen maken zulke pagina's niet en raken ze niet aan — zie hieronder. Op
een verse installatie bestaan ze niet meer: daar levert het sjabloon *Diensten*
een gewone pagina op `/diensten`.

## Vaste pagina's versus gewone CMS-pagina's

Op de bestaande Van Veluw-installatie worden zes pagina's gerenderd door een
eigen bestand in de projectroot (`is_system = 1`, met een `route_path`). Een
verse installatie heeft er één, de Homepage: de andere vier zijn gewone
contentpagina's die daar niet meer gezaaid worden, en `/shop.php` rendert daar
het productoverzicht van de module zonder pagina
([`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md)). Dat zegt alleen iets over *waar hun
URL zit*, niet over wat de redacteur ermee mag (`PageContent::isRouteBound()`
tegenover `isProtected()`).

| Pagina | Echt technisch bijzonder? |
|---|---|
| **Homepage** (`/`) | **Ja.** De site-root moet altijd renderen, en `homepage_hero` is exclusief voor deze pagina en niet verwijderbaar. |
| **Shop** (`/shop.php`) | **Nauwelijks, waar hij bestaat.** Alleen `shop_collections` is er een vast blok; zijn `product_grid` is een gewoon Shop-blok dat hij houdt. Niet meer beschermd. Een verse installatie heeft deze pagina niet; het productoverzicht is een keuze (`MODULES.md`). |
| **Diensten** (`/diensten.php`) | **Bijna niet.** Alleen `quicknav` bindt hem: een functioneel blok dat op `allowed_pages: ['diensten']` staat. Alle overige blokken zijn gewone, handmatig toevoegbare blokken. |
| **Portfolio** (`/portfolio.php`) | **Nee.** Uitsluitend gewone blokken (Page Hero, Feature Grid, Portfolio-/collectiegalerij, CTA Band, Marquee). Een legacy vaste route met volledig CMS-beheerde inhoud. |
| **Over mij** (`/over-mij.php`) | **Nee.** Uitsluitend gewone blokken. Legacy vaste route. |
| **Contact** (`/contact.php`) | **Nee.** Uitsluitend gewone blokken (Page Hero, Offerte-/contactformulier, Contactkaart). Legacy vaste route. |

`over-mij.php`, `portfolio.php`, `contact.php` en `diensten.php` zijn
onderling **byte-identiek** op de paginanaam na, en doen niets wat
`pagina.php` niet ook doet. Ze bestaan voor compatibiliteit met de bestaande
site-URL's, niet omdat ze techniek bevatten.

Er wordt hier niets geconverteerd. Deze tabel staat er zodat een latere
beslissing daarover op feiten kan rusten: Portfolio, Over mij en Contact
kunnen in principe gewone slugs worden zodra iemand de oude URL's via de
Redirect Manager wil laten doorlopen; Diensten heeft daarvóór een thuis nodig
voor de `quicknav`-koppeling.

## Een sjabloon toevoegen

```text
één definitieklasse schrijven  →  één regel in PageTemplates::MAP
                               →  --testsuite fast
```

1. `src/Service/PageTemplates/<Naam>Template.php`, extends
   `PageTemplateDefinition`. Vul `key()`, `label()`, `description()` en
   `blocks()`; `icon()` is optioneel en levert de *binnenkant* van een 24×24
   stroke-`<svg>` (vertrouwde, hardgecodeerde markup — nooit uit data).
2. Registreer de sleutel in `PageTemplates::MAP`, op de plek waar hij in de
   kiezer moet staan (leegste eerst).
3. `blocks()` mag alleen Core-blokken noemen die met de hand toevoegbaar
   zijn, niet vaker dan hun maximum, en geen blok dat aan bepaalde pagina's
   gebonden is. `Tests\Service\PageTemplateRegistryTest` controleert dat
   allemaal voor de hele catalogus tegelijk.

Meer is er niet: de kiezer, de validatie en de installer lezen het register.

## Wat dit bewust niet is

Geen door gebruikers gemaakte sjablonen, geen "sla deze pagina op als
sjabloon", geen import/export, geen versiebeheer, geen sjabloon dat zich later
nog in bestaande pagina's laat gelden. Sjablonen zijn code — er is dan ook
geen adminscherm om ze te maken, te wijzigen of te verwijderen.

## Tests

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
```

| Bestand | Wat het bewaakt | Database nodig |
|---|---|---|
| `tests/Service/PageTemplateRegistryTest.php` | De catalogus: sleutels, labels, welke blokken een sjabloon mag noemen, geen dynamische ontdekking, geen Shop-afhankelijkheid | nee |
| `tests/Service/PageTemplateCreationTest.php` | Wat een sjabloon bouwt: blokken en volgorde, gewone CMS-pagina, geen sjabloonidentiteit, atomiciteit, SEO, sitemap, het lege formulierblok | ja |
