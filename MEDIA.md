# Mediabibliotheek

Eén plek voor de herbruikbare afbeeldingen van de site: één keer uploaden,
overal kiezen, één alt-tekst onderhouden, en veilig verwijderen. Lees dit
samen met `PROJECT-MAP.md` (waar iets staat). De Mediabibliotheek is **Core**,
geen module (`MODULES.md`).

Wijkt de code af van dit document, dan heeft de code gelijk: pas het document
aan.

## Wat er in hoort, en wat nadrukkelijk niet

| Soort | Voorbeeld | Waar |
|---|---|---|
| **Herbruikbaar publiek sitebeeld** | logo, favicon, deel-afbeelding, foto in een contentblok | **Mediabibliotheek** |
| **Domeineigen beeld** | productfoto's, variantfoto's, collectiebeeld, portfolio-afbeeldingen | bij het domein zelf (nog) |
| **Privé klantbestand** | personalisatie-uploads, contactbijlagen, ordersnapshots, factuur-PDF's | buiten de webroot, **nooit** hier |

De derde rij is een grens, geen achterstand. De bibliotheek bestaat om beeld
te **hergebruiken** en te tónen; een bestand dat een klant heeft geüpload bij
één bestelling mag per definitie nooit ergens anders opduiken. Die staan in
`../storage/` en blijven daar.

## Het opslagmodel

Eén tabel, `media`, en die weet alleen iets over het bestand:

```text
id
path              assets/media/<32 hex>.webp  — root-relatief, zonder / ervoor
thumbnail_path    assets/media/thumbs/<zelfde naam>  — NULL als er geen is
original_filename hoe het bestand heette bij de gebruiker (alleen label)
mime_type         uit de bestandsheader, niet uit de naam
width, height     NULL als ze niet te bepalen zijn (een SVG bijvoorbeeld)
file_size
alt_text          de standaard alt-tekst — dé reden om te centraliseren
checksum          SHA-256 van de opgeslagen bytes
created_at, updated_at
```

`path` is uniek en `utf8mb4_bin`: één bestand is één rij, en `Foto.jpg` en
`foto.jpg` zijn twee bestanden, net als op de schijf.

Er zijn **geen** mappen, tags, EXIF, focuspunt of transformaties. Zie
"Bewust niet gebouwd" onderaan.

## Waar de bestanden staan

| | Pad |
|---|---|
| Nieuwe uploads | `assets/media/` |
| Gegenereerde thumbnails | `assets/media/thumbs/` |
| **Overgenomen oude bestanden** | blijven staan waar ze stonden (`assets/images/…`) |

Dat onderscheid is met opzet. De migratie die de bestaande site overnam
(`20260909270000`) heeft **geen enkel bestand verplaatst**: een media-rij is
een *identiteit*, geen locatie, en bestanden verhuizen tijdens een deploy
betekent dat database en schijf tegelijk goed moeten gaan. De bibliotheek
bezit dus wat zij zelf aanmaakt, en kent de rest.

Gevolg dat je moet kennen: **een overgenomen bestand wordt bij verwijderen
niet van de schijf gehaald.** Alleen de rij verdwijnt. De bibliotheek heeft
de identiteit van dat bestand overgenomen, niet het eigendom — iets anders op
deze site kan er nog met een pad naar wijzen.

## Uploaden

`App\Service\Media\MediaUploader` — dezelfde regels als
`SectionImageUploader` altijd al had, want dat zijn de regels die beoordeeld
zijn:

1. het moet echt een afbeelding zijn, bepaald door de **bestandsheader**
   (`getimagesize()`), nooit door naam, extensie of Content-Type;
2. de opgeslagen naam is 32 willekeurige hex-tekens plus de extensie die bij
   het gevonden type hoort — een naam van de gebruiker wordt nooit een
   bestandsnaam;
3. `is_uploaded_file()` moet het eens zijn;
4. maximaal 25 MB;
5. één map waarin deze klasse als enige schrijft, `chmod 0644`.

**JPG, PNG, WEBP en GIF.** JPG/PNG/WEBP gaan door `App\Service\ImageOptimizer`
(EXIF-oriëntatie, lange zijde afgetopt, hercomprimeerd, metadata weg,
decompressiebom-grenzen) en krijgen meteen een thumbnail uit dezelfde decode.
GIF wordt ongewijzigd bewaard en krijgt géén thumbnail: GD zou een animatie
platslaan tot één frame.

**Geen SVG.** Een SVG is een document dat script kan bevatten; vanaf de eigen
origin geserveerd is dat opgeslagen XSS. Dit project heeft geen sanitizer,
dus de bibliotheek accepteert er geen. Een SVG die al gedeployd is werkt
gewoon door en is zelfs *overgenomen* als media-item — het logo van deze site
is er een. De bibliotheek bezit dan zijn identiteit en zijn alt-tekst, niet
zijn totstandkoming.

**Dubbele uploads.** Twee keer hetzelfde bestand levert één item op. Er wordt
op checksum gekeken vóór het opslaan (vangt een GIF) en nog eens ná het
optimaliseren (vangt een foto, die immers heringepakt wordt); in het tweede
geval wordt het net weggeschreven bestand meteen weer opgeruimd. Alleen een
exacte match telt — er wordt niets vergeleken op *gelijkenis*.

## Hoe een feature naar media verwijst

```text
<tabel>.media_id        de verwijzing
<tabel>.image_path      het oude pad, blijft staan als terugval
```

Precedentie, op één plek geschreven
(`App\Service\Media\BlockImage` voor blokken,
`App\Service\Branding` voor de site-identiteit):

```text
media_id gezet én het item bestaat  ->  het media-item
anders                              ->  het opgeslagen pad
```

De terugval is niet decoratief: een verwijzing naar een item dat verdwenen is
mag het logo van een site niet leegmaken. Zolang het oude pad bestaat wordt
het **meegeschreven** met het pad van het gekozen item, zodat het waar blijft
in plaats van te verouderen — en samen met de verwijzing leeggemaakt, zodat
de twee elkaar nooit tegenspreken.

Die oude kolommen zijn **tijdelijk**. Ze verdwijnen in een aparte, bewuste
stap, niet als bijvangst.

### Alt-tekst is gelaagd

```text
de eigen alt_nl/alt_en van het blok  ->  de alt_text van het media-item
```

Het lokale veld wint als het iets zegt: dezelfde foto kan op twee plekken
iets anders betekenen. Er is niets weggemigreerd — een redacteur die een
onderschrift had, houdt het; wie er geen had, krijgt nu de centrale in plaats
van een lege `alt=""`.

### Afmetingen

Kent de bibliotheek `width`/`height`, dan printen de geïntegreerde blokken ze
als attributen, zodat de browser de ruimte kan reserveren. Onbekend betekent:
attributen weglaten — nooit gokken, nooit nul. `loading="lazy"` verandert
niet, en er is geen `srcset`-machinerie bij gekomen.

## Waar wordt dit gebruikt?

Er is **geen** `media_references`-tabel. Gebruik wordt **afgeleid** uit de
kolommen die de verwijzing zelf bevatten, want een tweede boekhouding is een
tweede waarheid: de dag dat één schrijfpad hem vergeet bij te werken, meldt
de bibliotheek vol vertrouwen dat een gebruikte afbeelding ongebruikt is en
biedt aan hem te verwijderen.

```text
App\Service\Media\MediaUsageRegistry
  ├── BrandingMediaUsage          logo, tweede logo, favicon, deel-afbeelding
  ├── PageSocialImageMediaUsage   pages.og_media_id
  ├── ContentBlockMediaUsage      de geïntegreerde blokken (één UNION-query)
  └── + wat elke INGESCHAKELDE module bijdraagt
```

**De regel voor een provider**: hij krijgt een hele **lijst** media-id's
tegelijk en beantwoordt die in een begrensd aantal queries — één, in alle
huidige implementaties. Het overzicht toont een pagina met tegels en heeft
per tegel een teller nodig; per item vragen zou precies de N+1 zijn die niet
mag.

Een module beantwoordt zijn eigen tabellen via
`ModuleDefinition::mediaUsageProviders()`. Zo hoeft Core Media nooit te weten
wat een product is. De **Blog** is de eerste module die dat doet
(`App\Service\Blog\BlogPostMediaUsage`, `BLOG.md`): hij meldt de uitgelichte
afbeelding van een bericht als *"Blogbericht: &lt;titel&gt;"* en een eigen
deel-afbeelding apart, allebei met een link naar de editor, in één query voor
een hele reeks id's. De Shop gebruikt de bibliotheek nog niet.

**Staat een module uit, dan telt zijn gebruik niet mee.** Dat is dezelfde
regel als overal (`MODULES.md`): een uitgeschakelde module draagt niets bij.
Met de Blog uit is een afbeelding die alleen op een blogbericht staat dus
verwijderbaar — data van een uitgeschakelde module is geen deel van de
draaiende site. De rijen blijven staan, en zodra de module weer aan gaat is
diezelfde afbeelding weer beschermd.

## Verwijderen

```text
niets gebruikt het   ->  rij weg, eigen bestand weg, eigen thumbnail weg
iets gebruikt het    ->  geweigerd, met de lijst plekken erbij
niet vast te stellen ->  geweigerd
```

De volgorde is: eerst het gebruik vaststellen, dan de rij, dan de bestanden.
Klapt er iets tussen rij en bestand, dan blijft er een ongebruikt bestand op
de schijf staan — onzichtbaar en onschadelijk. Andersom zou een rij overblijven
die naar niets wijst, en dat is een kapotte afbeelding op een publieke pagina.
Lukt het wissen van het bestand niet, dan zegt het CMS dat hardop.

Er is **geen** "toch verwijderen" en geen "overal weghalen". Een knop die
stilletjes het logo van een site en de beelden van vier pagina's leegmaakt is
geen functie.

Een blok verwijderen haalt alleen de *verwijzing* weg. Het bestand is van de
bibliotheek en staat mogelijk op drie andere pagina's.

## Rij en schijf oneens

| Situatie | Wat er gebeurt |
|---|---|
| Rij bestaat, bestand weg | Admin toont "Bestand ontbreekt", publiek wordt de afbeelding weggelaten. De rij is gewoon te bewerken en te verwijderen — zo ruim je het op. |
| Bestand bestaat, geen rij | Blijft staan. Niets ruimt "wezen" automatisch op; een legacy-bestand hoort daar gewoon. |
| Rij weg, bestand niet | Wordt gemeld, niet verzwegen. |

Er is geen achtergrondproces dat dit repareert. Een onderhoudsscherm kan dat
later doen.

## Rechten

| Permissie | Wat het geeft |
|---|---|
| `media.view` | De bibliotheek openen, doorzoeken, kiezen — **en er iets aan toevoegen** |
| `media.manage` | Alt-tekst van bestaand materiaal wijzigen, en ongebruikte media verwijderen. Bevat `media.view` |

De knip zit daar omdat de bibliotheek **gedeeld** is. Iets toevoegen kon elke
redacteur al via het uploadveld van elk blok en neemt niemand iets af;
de alt-tekst wijzigen van een item dat op vier pagina's staat, of een item
verwijderen, reikt verder dan het scherm waar iemand naar kijkt.

`pages.manage`, `portfolio.manage` en `settings.manage` bevatten automatisch
`media.view` — beeld kiezen hoort bij het bewerken van een pagina. Niets
bevat automatisch `media.manage`.

Alle schrijfacties vragen CSRF; de lijst-endpoint is een GET en doet dat
bewust niet.

## De mediakiezer

`admin/_media_picker.php` plus `admin/assets/media-picker.js`. Een scherm dat
een afbeelding nodig heeft, doet dit:

```php
require_once __DIR__ . '/_media_picker.php';
...
media_picker_field('media_id', MediaService::find((int) $row['media_id']));
...
media_picker_modal();   // één keer, vlak voor </body>
media_picker_script();
```

Het veld is een gewone `<input type="hidden">` die het omliggende formulier
meestuurt als elke andere waarde; één modal bedient alle velden op de pagina.
Er komt **een id uit en verder niets** — geen pad, geen naam, geen URL. Het
endpoint controleert dat id alsnog tegen de bibliotheek
(`BlockImage::fromRequest()`): een getal dat niets aanwijst is "geen
afbeelding", nooit een opgeslagen verwijzing.

Zet je JavaScript uit, dan blijft het formulier gewoon opslaan wat er al
gekozen was.

Een nieuw blok hoeft dus **geen eigen uploadveld en geen eigen
upload-endpoint** meer te bouwen om een publieke afbeelding te kiezen. Dat is
de belangrijkste opbrengst van deze stap.

## Wat er in V1 is aangesloten

| Feature | Hoe |
|---|---|
| Logo, tweede logo, favicon, standaard deel-afbeelding | `site_settings.*_media_id`, oude `*_path` als terugval |
| Deel-afbeelding per CMS-pagina | `pages.og_media_id` |
| Tekst + afbeelding (`text_image_split`) | `text_image_split_images.media_id` |
| Detailsectie (`detail_section`) | `detail_sections.main_media_id` + `detail_section_images.media_id` |
| Kaarten-carrousel (`card_carousel`) | `carousel_cards.media_id` |
| Uitgelichte afbeelding en deel-afbeelding van een blogbericht | `blog_posts.featured_media_id`, `blog_posts.og_media_id` — een module, dus via `BlogModule::mediaUsageProviders()` |

**Bewust nog op hun eigen paden**, ongewijzigd en werkend:

- Portfolio (`portfolio_gallery_items`, `portfolio_item_images`) — die hebben
  een eigen thumbnail-pijplijn (`PortfolioImageProcessor`) die eerst een plek
  in dit model moet krijgen;
- Homepage-hero (afbeelding én video) en Item-galerij;
- Shop: producten, varianten, collecties. Productbeeld heeft volgorde,
  varianten en catalogus-semantiek; dat is een eigen stap.

Hoe de Shop later aansluit: een `media_id` naast het bestaande `image_path`,
plus één `MediaUsageProvider` in `ShopModule`. Core hoeft daar niets voor te
weten — precies zoals de Blog het al doet.

**Een tabel die na de bibliotheek is gemaakt heeft geen `image_path`-tweeling.**
`blog_posts` heeft alleen een `media_id`: de oude padkolommen zijn een
historische terugval voor rijen van vóór de bibliotheek, en een nieuwe tabel
heeft die historie niet. Zo'n tabel heeft ook geen eigen alt-veld, want de
gelaagde alt-tekst bestaat om bestaande onderschriften te bewaren — nieuwe
inhoud gebruikt gewoon die van het item.

## Een nieuw blok aansluiten

1. Migratie: `media_id INT UNSIGNED NULL` met een foreign key naar `media`,
   `ON DELETE RESTRICT`.
2. Repository: `media_id` meeschrijven, en `image_path` met het pad van het
   gekozen item (zolang die kolom bestaat).
3. Inhoudsklasse: `BlockImage::fromRow($row)` in plaats van het pad zelf
   lezen. Je krijgt `image_path`, `alt_nl`, `alt_en`, `width`, `height`.
4. Editor: `media_picker_field()`, plus één keer `media_picker_modal()` en
   `media_picker_script()`.
5. Endpoint: `BlockImage::fromRequest($_POST['media_id'])`.
6. Partial: `BlockImage::dimensionAttributes($image)` in de `<img>`.
7. Voeg een tak toe aan `ContentBlockMediaUsage` (Core) of aan de provider
   van je module, anders meldt de bibliotheek jouw blok als "niet gebruikt".
8. `deleteFiles()` van het blok blijft **leeg**: een gedeeld bestand is niet
   van jou.

## Modulegrens

Core Media weet van media, en van niets anders — geen product, geen
personalisatie-preview, geen orderbijlage, geen collectie. Een module mag
media *kiezen*, gebruik *melden* en eigen metadata houden (volgorde,
uitsnede-intentie). `Tests\Service\MediaBoundaryTest` faalt zodra een
Core-mediabestand een Shop-klasse of een Shop-tabel noemt.

## Testen

```bash
docker exec mygdala_php      php vendor/bin/phpunit --testsuite fast   # MediaBoundaryTest
docker exec mygdala_php_test php vendor/bin/phpunit --testsuite cms    # de rest
docker exec mygdala_php_test php vendor/bin/phpunit --group migration-backfill
```

| Bestand | Wat het bewaakt |
|---|---|
| `MediaBoundaryTest` | Rechten, guards, CSRF, "de kiezer stuurt alleen een id", modulegrens. Geen database |
| `MediaLibraryTest` | Upload, wat er geweigerd wordt, alt-tekst, ontdubbelen, zoeken, verwijderen, ontbrekend bestand |
| `MediaUsageTest` | Gebruik afgeleid uit echte blokinstanties, en de verwijderregel |
| `MediaAdoptionTest` | Wat de overnamemigratie beloofde (`migration-backfill`) |
| `BrandingTest` | Media wint van het pad, en het pad blijft de terugval |
| `Tests\Blog\BlogMediaAndSettingsTest` | de eerste module-provider: gebruik melden, niet kunnen verwijderen, en niets melden met de module uit |

Uploaden in een test gaat via `Tests\Support\TestMediaUploader`: de échte
uploader met één naad open (`is_uploaded_file`/`move_uploaded_file` kunnen
niet waar zijn voor een bestand dat een test schreef). Alle overige regels —
headercontrole, willekeurige naam, limiet, map, optimalisatie — zijn de
echte.

## Bewust niet gebouwd

Mappen, tags/categorieën, bulkbewerking, uitsnede-editor, transformaties-UI,
focuspunten, een `srcset`-framework, video, audio, PDF's/documenten,
objectopslag, CDN, EXIF-browser, AI-alt-tekst, OCR, stockfoto's, mapbomen met
slepen, en detectie van *gelijkende* afbeeldingen.

De waarde van V1 is: centrale identiteit, hergebruik, metadata, veilig
verwijderen. Meer is er niet, en dat is het punt.
