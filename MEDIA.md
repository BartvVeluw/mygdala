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
| **Herbruikbaar publiek sitebeeld** | logo, favicon, deel-afbeelding, foto in een contentblok, productfoto, portfolio-afbeelding | **Mediabibliotheek** |
| **Domeineigen beeld** | het voorbeeldcanvas van een personalisatieweergave | bij het domein zelf (zie *Nog op een eigen pad*) |
| **Privé klantbestand** | personalisatie-uploads, afbeeldingen bij een bestelvraag (*Afbeelding uploaden*, `storage/order-field-uploads/`, `MODULES.md` "Bestelvelden"), contactbijlagen, ordersnapshots, factuur-PDF's | buiten de webroot, **nooit** hier |

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
original_filename hoe het bestand heette bij de gebruiker (herkomst, verandert nooit)
display_name      de naam die de bibliotheek toont en die een redacteur mag wijzigen
folder_id         de virtuele map; NULL = "Geen map" (zie *Mappen*)
mime_type         uit de bestandsheader, niet uit de naam
width, height     NULL als ze niet te bepalen zijn (een SVG bijvoorbeeld)
file_size
alt_text          de standaard alt-tekst — dé reden om te centraliseren
checksum          SHA-256 van de opgeslagen bytes
created_at, updated_at
```

`path` is uniek en `utf8mb4_bin`: één bestand is één rij, en `Foto.jpg` en
`foto.jpg` zijn twee bestanden, net als op de schijf.

Mappen staan in een eigen tabel, `media_folders` (`id`, `name`, tijden), en
zijn **virtueel**: zie *Mappen*. Er zijn **geen** tags, EXIF of
transformaties op het item. Zie "Bewust niet gebouwd" onderaan. Hoe een beeld
in zijn kader valt — een focuspunt, vullen of de hele afbeelding, een eigen
afbeelding voor een telefoon — hoort bij de plek die het toont, nooit bij het
item in de bibliotheek: zie *Responsive Media*.

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
zijn, plus één die een redacteur eerder een bruikbaar antwoord geeft:

1. het moet echt zijn wat het zegt te zijn, bepaald door de **inhoud**, nooit
   door naam, extensie of Content-Type: een rasterbeeld door zijn header
   (`getimagesize()`), een SVG door de sanitizer, een video door zijn eerste
   bytes (`App\Service\Media\VideoFormat`);
2. de **naam** moet een extensie hebben die de bibliotheek kent
   (`MediaUploader::extensions()`): `.jpg`, `.jpeg`, `.jfif`, `.png`, `.webp`,
   `.gif`, `.svg`, `.mp4`, `.m4v` of `.webm`. De naam bepaalt welke soort er
   *beweerd* wordt, de inhoud of dat klopt: een video die `.jpg` heet wordt
   geweigerd als "geen afbeelding", nooit opgeslagen als wat hij werkelijk is.
   Een `.exe` of `.php` wordt geweigerd nog voordat iemand hem opent;
3. de opgeslagen naam is 32 willekeurige hex-tekens plus de extensie die bij
   het gevonden type hoort — een naam van de gebruiker wordt nooit een
   bestandsnaam;
4. `is_uploaded_file()` moet het eens zijn;
5. maximaal 25 MB voor een afbeelding en 30 MB voor een video (de grens die
   de video van de Homepage Hero altijd had), of minder als PHP minder
   toelaat (`upload_max_filesize`, `post_max_size`). Het scherm noemt de
   grenzen die echt gelden (`MediaUploader::maxBytes($soort)`);
6. één map waarin deze klasse als enige schrijft, `chmod 0644`.

**JPG, PNG, WEBP en GIF.** JPG/PNG/WEBP gaan door `App\Service\ImageOptimizer`
(EXIF-oriëntatie, lange zijde afgetopt, hercomprimeerd, metadata weg,
decompressiebom-grenzen) en krijgen meteen een thumbnail uit dezelfde decode.
GIF wordt ongewijzigd bewaard en krijgt géén thumbnail: GD zou een animatie
platslaan tot één frame.

### SVG

Een SVG is een XML-document, geen plaatje: het kan `<script>`,
event-handlers, `javascript:`-links, ingesloten HTML (`<foreignObject>`) en
verwijzingen naar andere bestanden bevatten. In een `<img>` draait daar niets
van, maar wie de URL van het bestand rechtstreeks opent, draait het als
pagina van deze site: opgeslagen XSS. Daarom is het opgeslagen bestand nooit
het verstuurde bestand, maar wat `App\Service\Media\SvgSanitizer` uit een
geparste boom terugschrijft.

Het contract: **weigeren wat actief is, weghalen wat alleen vreemd is.**

| Geweigerd, met de reden erbij | Stil weggehaald |
|---|---|
| een DOCTYPE of entiteit (XXE, *billion laughs*) | commentaar, processing instructions |
| `<script>`, in welke namespace ook | `<metadata>` |
| elk `on…`-attribuut | onbekende elementen, met hun inhoud |
| `<foreignObject>`, `<iframe>`, `<object>`, `<embed>`, audio, video | attributen in een vreemde namespace (Inkscape, Sodipodi, Illustrator) |
| animaties (`<animate>`, `<set>` …): die kunnen een href achteraf in `javascript:` veranderen | `xml:base` |
| een href, `url()` of `@import` die niet naar `#id` in het bestand zelf wijst | een `<a>` wordt uitgepakt: de inhoud blijft, de link gaat |

Een ingesloten rasterbeeld (`data:image/png;base64,…`) mag blijven; een
ingesloten SVG niet. Elementen staan op een **allowlist**; attributen worden
op regel beoordeeld, omdat SVG honderden onschuldige presentatie-attributen
heeft en maar twee manieren om buiten het bestand te komen (een href en een
`url()`), die allebei op elke waarde gecontroleerd worden. Geparst wordt met
`LIBXML_NONET` en zonder entiteit-expansie; een DOCTYPE wordt al op de ruwe
bytes geweigerd. Weigeren in plaats van stil strippen is bewust: een logo
waar ongemerkt iets uit verdwijnt, kan er anders uitzien dan de maker
exporteerde, en de redacteur zou nooit weten waarom.

Een SVG wordt niet verkleind of heringepakt en krijgt geen thumbnail. Zijn
afmetingen komen uit `width`/`height` in pixels, anders uit de `viewBox`.

Tweede verdedigingslinie: de root-`.htaccess` geeft elk `.svg` een
`Content-Security-Policy` zonder script, zonder externe bronnen en met
`sandbox`, als `mod_headers` er is.

**Eén weg naar binnen.** Elk nieuw SVG-bestand dat Mygdala opslaat, gaat door
`SvgSanitizer`; er is geen tweede sanitizer en geen route die op extensie of
MIME-type vertrouwt. Logo, tweede logo, favicon en deel-afbeeldingen zijn
geen eigen upload: het zijn items uit deze bibliotheek, gekozen met de kiezer.
Alle andere uploaders (sectie-, product-, personalisatie- en portfoliobeelden,
contactbijlagen, lettertypes, de Hero-video) bepalen het type met
`getimagesize()` tegen een rasterlijst, of met een PDF- of font-handtekening;
`getimagesize()` herkent geen SVG, dus daar wordt een SVG op inhoud
geweigerd. `Tests\Service\Media\SvgUploadRoutesTest` houdt de lijst van
klassen die een upload opslaan gesloten: een nieuwe faalt tot iemand hem op
SVG heeft nagekeken. De parser kan niets buiten het bestand bereiken:
`LIBXML_NONET`, geen `LIBXML_NOENT`/`LIBXML_DTDLOAD`, geen XInclude, en een
DOCTYPE wordt al op de ruwe bytes geweigerd.

**Bestaande SVG's.** Een SVG die vóór deze stap is opgeslagen (een overgenomen
logo, een bestand van de oude brandingupload) is nooit door de sanitizer
gegaan. `php scripts/audit-svg.php` (`App\Service\Media\SvgAudit`) zoekt ze
op — media-rijen, de brandingpaden in `site_settings` en de publieke
uploadmappen — en beoordeelt elk bestand met dezelfde sanitizer, **zonder iets
te wijzigen**: *ok*, *refused* met de reden, *missing* of *unreadable*; exit 1
zodra er één niet deugt. Een geweigerd bestand vervangt een redacteur via de
bibliotheek; het script herschrijft niets.

### Video

MP4 en WebM, herkend aan hun eerste bytes (een `ftyp`-box op offset 4, of de
EBML-magic), en **ongewijzigd** opgeslagen: geen transcodering, geen
posterframe en geen afmetingen, omdat er op gedeelde hosting niets is dat ze
kan lezen of maken. Een QuickTime-`.mov` en een HEIC/AVIF-foto delen de
`ftyp`-box en worden aan hun *brand* herkend en geweigerd. Dezelfde klasse
beoordeelt ook de eigen video-upload van de Homepage Hero
(`SectionVideoUploader`), zodat die twee het nooit oneens zijn.

In de bibliotheek krijgt een video een video-icoon in plaats van een
voorbeeld; het itemscherm toont een `<video>` die pas iets laadt als iemand
op afspelen drukt.

Op de site staat een video in de Homepage-hero en in de Mediabanner
(`CONTENT-BLOCKS.md`, "Mediabanner"), altijd als native `<video>` met het
bestand uit de bibliotheek. Een video-item heeft geen alt-tekst (het
itemscherm vraagt er geen) en geen ondertitels; een beeld vóór het afspelen
is een gewone afbeelding die de gebruiksplek zelf kiest, nooit een frame
uit de video.

**Dubbele uploads.** Twee keer hetzelfde bestand levert één item op. Er wordt
op checksum gekeken vóór het opslaan (vangt een GIF) en nog eens ná het
optimaliseren (vangt een foto, die immers heringepakt wordt); in het tweede
geval wordt het net weggeschreven bestand meteen weer opgeruimd. Alleen een
exacte match telt — er wordt niets vergeleken op *gelijkenis*.

### Meerdere bestanden tegelijk

Het bibliotheekscherm heeft één uploadformulier, dat op twee manieren wordt
verstuurd:

| | Zonder JavaScript | Met `admin/assets/media-upload.js` |
|---|---|---|
| Kiezen | `admin_file_input()` met `multiple` (`ADMIN-UI.md`) | idem, of slepen naar het vak eromheen |
| Wat je ziet | hoeveel bestanden je koos | **Nieuwe bestanden**: voorbeeld, naam, type, grootte en een fout per bestand |
| Versturen | alles in één POST naar `create-media.php`, terug met een redirect | **één bestand per request** naar `media-upload.php`, dat JSON teruggeeft |

**De wachtrij staat alleen in de browser.** Een bestand gaat pas naar de
server bij *Toevoegen aan bibliotheek*. Weghalen uit de lijst is niets meer
dan een `File` vergeten, en er bestaan geen tijdelijke bestanden op de server
die iemand moet opruimen (er is ook geen proces dat dat zou doen). Een
voorbeeld is een `URL.createObjectURL()` die wordt vrijgegeven zodra de rij
verdwijnt.

**Eén bestand per request**, omdat een batch grote foto's anders samen over
`post_max_size` gaat (de les van de Portfolio-upload), en zodat elk bestand
zijn eigen antwoord krijgt. **Een batch is niet transactioneel**: elk item
staat op zichzelf, dus een geweigerd bestand kost de andere hun plek niet en
laat zelf niets achter (`MediaService::uploadMany()`). Wat lukt, verdwijnt
uit de lijst en staat meteen in het raster; wat niet lukt, blijft staan met
de reden erbij.

In de lijst kun je een bestand een **naam** geven. Die gaat mee als `name`,
zonder extensie, en wordt gecontroleerd vóór er iets wordt opgeslagen
(`MediaFilename::problemWith()`). Een bezette naam krijgt ook dan een nummer.

De controles in de browser — extensie, grootte, de eerste bytes van het
bestand, de naam — besparen alleen een rondje. De server doet ze allemaal
opnieuw.

## Naam, bestand en adres

Vijf dingen aan een media-item lijken op elkaar en zijn het niet. Elk heeft
één betekenis, en geen veld doet dienst als twee ervan:

| | Kolom | Wat het is | Wie het verandert |
|---|---|---|---|
| **Opgeslagen bestand** | `path` (+ `thumbnail_path`) | `assets/media/<32 hex>.<ext>`: technisch, willekeurig, nooit afgeleid van een naam die iemand typte | niemand — een item wordt nooit verplaatst of hernoemd op de schijf |
| **Oorspronkelijke naam** | `original_filename` | hoe het bestand heette op de computer van wie het uploadde: herkomst, doorzoekbaar | niemand, ooit |
| **Naam** (beheernaam) | `display_name` | het **label** waaronder een redacteur het item in de bibliotheek vindt en herkent; heeft de vorm van een bestandsnaam met de extensie van het item | een redacteur met `media.manage` |
| **Alt-tekst** | `alt_text` | de standaardbeschrijving van wat er op het beeld staat, voor wie het niet ziet (zie *Alt-tekst is gelaagd*) | een redacteur met `media.manage` |
| **Publiek adres** | afgeleid: `/` + `path` | wat de browser van een bezoeker laadt (`MediaItem::publicPath()`) | volgt `path`, dus verandert niet |

Elke feature verwijst naar het `id` (zie hieronder), dus een naam kan geen
pagina breken. Daarom bestaat er ook geen hernoemen op de schijf, en verandert
het publieke adres niet als de naam verandert. Of het adres een leesbare naam
moet dragen staat onder *Publieke adressen*.

Bij het uploaden (`App\Service\Media\MediaFilename`):

- de naam is die van het gekozen bestand, zonder mappen en zonder tekens die
  niet in een bestandsnaam kunnen;
- de **extensie volgt wat het bestand echt is**: een PNG die `logo.jpg` heet,
  heet in de bibliotheek `logo.png`. Een juiste extensie blijft zoals hij
  gespeld was (`.jpeg` blijft `.jpeg`);
- **een upload faalt nooit op een naam**. Bestaat de naam al, dan wordt het
  `naam-2.png`, `naam-3.png`. Hoofdletters maken geen verschil: `Logo.png` en
  `logo.png` zijn één naam.

Bestaande items kregen bij migratie `20260914100000` de naam die de bibliotheek
al toonde. De kolom is in het schema niet uniek, want overgenomen oude beelden
kunnen een naam delen; de bibliotheek houdt **nieuwe** namen zelf uniek.

### Naam en alt-tekst: één opslag

Naam en alt-tekst zijn samen wat een redacteur over een item bezit, en ze
worden **samen** opgeslagen: één formulier, één endpoint, één set regels
(`api/admin/update-media.php` → `MediaService::updateDetails()`). Er zijn geen
losse knoppen *Naam opslaan* en *Alt-tekst opslaan* meer, en er is geen
`rename-media.php` meer.

Twee plekken sturen naar dat ene endpoint:

| | Waar | Hoe |
|---|---|---|
| **Itemscherm** | `admin/media.php?id=` | het kaartje *Naam en alt-tekst*: één formulier onder de opslagbalk (`PAGE-EDITOR.md`); de eigen knop *Opslaan* draagt `data-save-bar-fallback`. Post/Redirect/Get met *Opgeslagen* |
| **Snel bewerken** | *Bewerken* op een kaart of rij in het overzicht | de dialoog *Media bewerken* (naam, alt-tekst, *Annuleren*, *Opslaan*), met `ajax=1`; het antwoord is JSON en de kaart wordt daarna opnieuw getekend |

De regels, op één plek:

- **Beide of geen van beide.** Een geweigerde naam laat ook de alt-tekst die
  meekwam ongeschreven, en het antwoord noemt elk probleem tegelijk, per veld
  (`errors.name`, `errors.alt_text`). Op het itemscherm komen de getypte
  waarden terug in hun veld, met `aria-invalid` en de melding eronder, en het
  formulier draagt `data-save-bar-unsaved`.
- **De extensie hoort bij het item.** Je typt het deel ervoor; de extensie
  staat ernaast en gaat niet mee in het verzoek. Typ je hem toch, dan wordt
  hij niet verdubbeld: `zomer.jpg` blijft `zomer.jpg`, en `foto.exe` wordt
  `foto.exe.jpg` — een naam, geen type.
- Een naam die geen bestandsnaam kan zijn, wordt geweigerd met de reden
  (`MediaFilename::problemWith()`): leeg, een van `/ \ : * ? " < > |`,
  onzichtbare tekens, een punt aan het begin of eind, of langer dan 200
  tekens.
- **Een naam die al bestaat, wordt geweigerd** in plaats van genummerd, zoals
  bij een upload wel gebeurt: hier typte de redacteur de naam zelf. Alleen de
  hoofdletters van de eigen naam veranderen mag.
- **De alt-tekst** is platte tekst van hooguit 255 tekens
  (`MediaService::ALT_MAX_LENGTH`): langer wordt geweigerd in plaats van
  afgekapt, onzichtbare tekens ook. Leeg mag: een decoratief beeld heeft er
  geen. Opmaak wordt opgeslagen zoals getypt en overal ge-escaped geprint.
- Alleen `display_name` en `alt_text` veranderen. Het bestand, `path`, de
  thumbnail, `original_filename`, de map en elke verwijzing blijven wat ze
  waren. Een veld dat het formulier niet heeft (`path`, `folder_id`) wordt
  genegeerd, wat een verzoek ook meestuurt.

## Mappen

Mappen zijn **virtueel**: een map is een rij in `media_folders` met een naam,
en de map van een item is `media.folder_id`. Een item verplaatsen verandert
die ene kolom — niet het bestand, niet `path`, niet het publieke adres, niet
de naam en geen enkele verwijzing. Conceptueel `folder_id 4 → 5`, nooit
`/uploads/halloween/x.jpg → /uploads/kerst/x.jpg`. Een mapnaam wordt nergens
een pad, een directory of een deel van een URL, dus er valt niets aan te
"saneren": het is tekst, ge-escaped waar hij geprint wordt.

`App\Service\Media\MediaFolderService` (en `MediaFolderRepository`):

| | Hoe |
|---|---|
| **Alle media** | geen filter (`?folder=` leeg) |
| **Geen map** | `?folder=none`: de items zonder map. Elk item van vóór deze stap staat hier |
| **Een map** | `?folder=<id>`; een id dat niet (meer) bestaat filtert niets, zoals een onbekende soort |
| **Map maken** | een naam van 1–100 tekens, zonder onzichtbare tekens, uniek ongeacht hoofdletters (de unieke index op `name` in `utf8mb4_unicode_ci` is het vangnet); de nieuwe map gaat meteen open |
| **Map hernoemen** | dezelfde regels; alleen `media_folders.name` verandert |
| **Map verwijderen** | vraagt eerst in de gedeelde dialoog, met de naam van de map en de zin dat de bestanden blijven. De items gaan terug naar **Geen map** (in één transactie, en `ON DELETE SET NULL` op de sleutel is de garantie daaronder). Er wordt nooit een item of bestand verwijderd |
| **Verplaatsen** | een selectie op het raster (dezelfde selectievakjes als verwijderen), met *Verplaatsen naar map* en *Verplaatsen* in de selectiebalk (`api/admin/move-media-items.php`, hooguit 100 tegelijk). Naar *Geen map* kan ook. Een map-id dat niet bestaat wordt geweigerd, nooit aangemaakt en nooit gelezen als "Geen map" |
| **Aantallen** | naast elke map, en naast *Alle media* en *Geen map*: twee queries voor de hele lijst |
| **Uploaden** | een nieuw bestand komt in de map die open staat, op het bibliotheekscherm én in de kiezer (`folder_id` bij de upload). Een map die inmiddels weg is, levert *Geen map* op in plaats van een geweigerd bestand. Een bestand dat de bibliotheek al had, blijft in zijn eigen map; had het geen map, dan komt het in deze |

Mappen beheren en items verplaatsen vraagt `media.manage`. In een map
uploaden hoort bij iets toevoegen en vraagt dus alleen `media.view`.

### Waarom geen submappen

Eén niveau. Een boom brengt een maximale diepte, cyclusbewaking, mappen
verplaatsen en het verwijderen van een hele tak mee, en een bibliotheek van
honderden beelden is met één niveau plus zoeken al overzichtelijk. De
verkenner met slepen die dat zou vragen, staat onder *Bewust niet gebouwd*.
Het model staat een latere `parent_id` toe zonder iets te breken: een
nullable kolom die naar `media_folders.id` wijst, met de diepte en de cycli
in de service begrensd.

## Zoeken en filteren

Links van het raster staan de mappen (op een smal scherm erboven, als een
rij die doorloopt), boven het raster een zoekveld en een keuze voor de
**soort bestand**, allebei de gedeelde bouwstenen (`.admin-search` en
`.admin-select`, `ADMIN-UI.md`). Het blijft een gewone GET
(`?folder=…&q=…&type=…&page=…`) zonder token, dus een gefilterde weergave
heeft een eigen adres dat een herlaadbeurt overleeft. **Een zoekopdracht blijft
binnen de map die open staat**; *Alle media* zoekt overal.

- **Zoeken** is een `LIKE` op de naam, de oorspronkelijke bestandsnaam en de
  alt-tekst. Geen fulltext-index en geen ranking; `_` en `%` betekenen
  zichzelf.
- **Soort** komt uit `App\Service\Media\MediaType`, een gesloten lijst die uit
  `mime_type` afleidt wat een rij is: **`image`** (raster en SVG) en
  **`video`**, omdat de bibliotheek precies die aanneemt. Audio of documenten
  komen er pas bij als de upload ze aanneemt: een filter op iets wat niet kan
  bestaan is een belofte die het scherm niet waarmaakt. Een rij zonder
  bekende soort (een overgenomen bestand met een onbekende extensie) staat
  onder *Alles* en nergens anders.
- **Iconen** (`?type=icon`, `MediaType::ICON`) is het derde onderdeel van het
  filter: de SVG's van de bibliotheek. Het is geen soort en geen tweede
  bibliotheek: een icoon is een gewoon media-item dat een SVG is, met dezelfde
  naam, alt-tekst, gebruikslijst en verwijderregels. `MediaType::libraryFilters()`
  is wat het filter aanbiedt (de soorten plus Iconen), `isLibraryFilter()` wat
  het adres mag vragen.
- Een onbekende `type` in het adres filtert niets, en een pagina voorbij de
  laatste toont de laatste.

Zoeken en filteren gebeuren **op de server**, omdat de bibliotheek per 24
items gepagineerd is. Met `admin/assets/media-library.js` ververst het raster
zonder dat de pagina herlaadt: het script haalt hetzelfde adres op en
vervangt alleen het blok met resultaten, zodat de cursor in het zoekveld
blijft staan en een lijst met nieuwe bestanden gewoon blijft bestaan. De
adresbalk loopt mee, dus Vorige en Volgende van de browser werken.

Een **kaart** toont het voorbeeld (de thumbnail, nooit het origineel als er
een thumbnail is), de naam, de soort (`JPG`, `PNG`, `SVG` — uit het type,
nooit uit de naam), de afmetingen en de grootte als die bekend zijn, de map,
of er een alt-tekst is (*Alt-tekst aanwezig* / *Geen alt-tekst*, in woorden en
met een stip in de waarschuwingskleur), op hoeveel plekken het bestand
gebruikt wordt, en *Bewerken*.

### Raster of lijst

Boven het raster staat *Raster* | *Lijst* (twee knoppen met `aria-pressed`).
Het is **één kaartmarkup**, twee keer anders getekend door CSS
(`data-media-view` op de bibliotheek): de lijst toont dezelfde kaarten als
compacte rijen met een thumbnail van 48px, naam en soort, map en alt-tekst,
gebruik en *Bewerken*. Dezelfde zoekresultaten, dezelfde selectie, geen
tweede verzoek. Op een telefoon vallen de kolommen van een rij onder elkaar.

**De keuze wordt onthouden in deze browser** (`localStorage`,
`mygdala.media-library.view`), zoals de blokkenkiezer zijn weergave onthoudt:
het is een voorkeur van de kijker, geen gegeven van de site, en er is geen
gebruikersvoorkeur in de database om hem in te bewaren. De kiezer leest
dezelfde sleutel. Zonder script, of zonder `localStorage`, is het het raster.

## Publieke adressen

Het adres dat een bezoeker laadt is `/assets/media/<32 hex>.<ext>`: het pad
van het opgeslagen bestand, statisch geserveerd door Apache, zonder PHP.
**Media Library 2.0 verandert dat niet.** Een leesbaar adres op basis van de
naam (conceptueel `/media/842/gegraveerde-houten-snijplank.jpg`) is
onderzocht en bewust niet half gebouwd: het raakt elke lezer van een
beeldpad, niet de bibliotheek alleen. De afweging, de opties en een voorstel
staan in `docs/media/PUBLIC-URLS.md`.

Wat vastligt, ook zonder leesbaar adres:

- het adres hangt aan het **opgeslagen bestand**, niet aan de naam: een naam
  wijzigen of een item naar een andere map verplaatsen verandert geen enkel
  adres, en breekt dus ook geen gedeelde link, cache of zoekresultaat;
- de naam die een redacteur typt wordt nooit een pad (zie *Naam, bestand en
  adres*), en een mapnaam evenmin.

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
de eigen alt-tekst van het blok, per taal  ->  de alt_text van het media-item
```

Sinds Multilingual 2.0 fase 3B is de eigen alt-tekst van een blok een woord in
`block_translations` (`alt`, `image_alt` of `main_image_alt` op de rij die het
beeld houdt), en legt `BlockImage::fromOwner()` de laag van het media-item
eronder.

Het lokale veld wint als het iets zegt: dezelfde foto kan op twee plekken
iets anders betekenen. Er is niets weggemigreerd — een redacteur die een
onderschrift had, houdt het; wie er geen had, krijgt nu de centrale in plaats
van een lege `alt=""`.

**Geërfd of eigen.** Opgeslagen blijft het onderscheid zoals het was: een
leeg eigen veld betekent "de alt-tekst van de bibliotheek, wat die op dat
moment ook is", zodat een latere wijziging in de bibliotheek doorwerkt op elke
plek die geen eigen tekst heeft. Maar de redacteur *ziet* die tekst nu:

- het alt-veld naast een afbeeldingskiezer staat **ingevuld** met de
  alt-tekst die echt gebruikt wordt — de eigen, anders die van de bibliotheek
  (`media_alt_field()` in `admin/_media_picker.php`, en
  `editor_row_media_alt()` voor rijen);
- kies je een andere afbeelding, dan vult de kiezer meteen díe alt-tekst in
  (`data-media-alt-for` koppelt het veld aan zijn kiezer);
- heeft de afbeelding in de bibliotheek nog geen alt-tekst, dan blijft het veld
  leeg met de placeholder *Deze afbeelding heeft nog geen alt-tekst*;
- het endpoint slaat een tekst die **precies** die van de bibliotheek is weer
  als leeg op (`BlockImage::ownAlt()`, `ownAltInRows()`): zien is niet kiezen,
  dus tonen en onveranderd opslaan maakt geen kopie. Wat je aanpast, is een
  eigen tekst van die plek.

In een **vertaling** geldt de oude regel: leeg valt terug op de standaardtaal,
dus daar wordt niets ingevuld, niets gekoppeld en niets teruggezet — dezelfde
tekst kan daar een echte keuze zijn. Een nieuwe rij wordt altijd in de
standaardtaal geschreven en volgt dus de regel van de standaardtaal.

Gevolg dat je moet kennen: typ je als eigen tekst letterlijk dezelfde tekst
als de bibliotheek, dan wordt dat "geërfd". Voor de bezoeker is dat hetzelfde,
tot iemand de bibliotheektekst verandert.

### Afmetingen

Kent de bibliotheek `width`/`height`, dan printen de geïntegreerde blokken ze
als attributen, zodat de browser de ruimte kan reserveren. Onbekend betekent:
attributen weglaten — nooit gokken, nooit nul. `loading="lazy"` verandert
niet. Een plek kan voor een telefoon een eigen afbeelding krijgen (zie
*Responsive Media*), maar er is geen `srcset`-machinerie met gegenereerde
formaten bij gekomen.

## Responsive Media

Hoe een beeld in zijn kader valt is een keuze van de plek die het toont, niet
van het bibliotheekitem: dezelfde foto kan in een carrouselkaart op een gezicht
gericht staan en in een banner op de horizon. Sinds Responsive Media 2.0 heeft
elke plek die een beeld bijsnijdt dezelfde keuzes, in één veld in de editor,
*Afbeeldingsweergave* (`ADMIN-UI.md`). Responsive Media 3.0 (v0.1.14) voegde
de zoom toe:

| Keuze | Opslag | Standaard |
|---|---|---|
| Focuspunt | `<prefix>focus_x`, `<prefix>focus_y`: hele procenten 0–100, precies wat CSS `object-position` betekent | 50 / 50, het midden |
| Zoom | `<prefix>zoom`: hele procenten 100–200 (`ResponsiveImage::ZOOM_MIN`/`ZOOM_MAX`), `TINYINT UNSIGNED NOT NULL` | 100: het kader precies gevuld zoals `cover` het vult |
| Afbeelding op een telefoon | `<prefix>mobile_media_id`, een afbeelding uit de bibliotheek, `ON DELETE RESTRICT` | `NULL`: de desktopafbeelding |
| Focuspunt op een telefoon | `<prefix>mobile_focus_x`, `<prefix>mobile_focus_y` | `NULL`: volgt het desktoppunt |
| Zoom op een telefoon | `<prefix>mobile_zoom`, alleen samen met het eigen telefoonpunt | `NULL` zolang de telefoon het desktoppunt volgt; een telefoonpunt zonder zoom (van vóór 3.0) leest als 100 |
| Weergave in het kader | `<prefix>fit`: `cover` (vullen, bijsnijden) of `contain` (de hele afbeelding); `<prefix>mobile_fit` | `cover`; op een telefoon `NULL`: zoals op een groot scherm |
| Hoogte op een telefoon | `<prefix>mobile_height`: `compact`, `normal` of `large` | `NULL`: de eigen hoogte van het blok |

De acht plekken, en welke keuzes ze hebben:

Elke plek heeft een zoom: ze snijden allemaal bij met `object-fit: cover` in
een kader van het blok zelf (de audit van 3.0 vond geen plek met een
natuurlijke, onbijgesneden verhouding). Waar een redacteur *Hele afbeelding*
koos, doet de zoom niets (hieronder).

| Plek | Tabel, prefix | Weergave | Telefoonhoogte |
|---|---|---|---|
| Kaart van de Kaarten-carrousel | `carousel_cards`, `image_` | ja | nee: de carrousel kiest één *Beeldverhouding op een rij* voor al zijn kaarten (`card_carousels.flat_image_ratio`: zoals de hoogte, 1:1, 4:3, 3:4, 16:9), op een telefoon en bij *Kaarten naast elkaar*. De afbeelding voor een telefoon is de compacte afbeelding van de kaart: bij *Kaarten naast elkaar* op elke breedte |
| Item van Tekst met afbeelding | `text_image_split_items`, `image_` | ja | ja |
| Paginakop | `page_heroes`, `image_` | ja, alleen naast de tekst | ja, alleen achter de tekst |
| Oproep met knop, achtergrond | `cta_bands`, `background_` | nee: achter tekst altijd vullen | nee |
| Mediabanner | `media_banners`, `image_` | ja | ja |
| Hover kaarten grid, hoofdafbeelding | `hover_card_grid_items`, `image_` | ja | nee: de vorm van het grid |
| Homepage-hero | `homepage_hero`, `image_` | nee | nee |
| Portret van een review (v0.1.14, Reviews) | `review_block_items`, `image_` | nee: altijd een rond kader, vullen | nee; wel een eigen telefoonfoto en telefoonpunt. De kolommen kwamen met de tabel zelf (`20261009100000`), niet met de zoommigratie |
| Galerij-item van een Detailsectie (v0.1.13) | `detail_section_images`, `image_` | nee: altijd een vierkant, vullen | nee; ook geen telefoonafbeelding en geen telefoonpunt in de editor: de strook op een telefoon toont hetzelfde vierkant met hetzelfde punt. De kolommen van het slot bestaan wel, `NULL` |

**Eén model.** `App\Service\Media\ResponsiveImage` is de waarde: lezen uit
een rij, een formulier valideren, en wat de partial krijgt.
`ResponsiveImageSlot` noemt per tabel de kolommen (alleen de prefix
verschilt), `App\Repository\ResponsiveImageRepository` schrijft ze, voor een
gesloten lijst tabellen. De negen vaste punten van vroeger
(`App\Service\Media\ImageFocus`) zijn nu voorinstellingen van één klik. De
migratie `20260928220000` zette elke opgeslagen sleutel om in precies zijn
punt (0, 50 of 100 per as; iets anders werd het midden) en haalde de
sleutelkolom weg: één waarheid per beeld. `20260928230000` voegde de
telefoonkolommen toe, voor een bestaande rij allemaal `NULL` of de
standaard: zoals het was. `20261002100000` voegde op alle acht plekken
`<prefix>zoom` (100) en `<prefix>mobile_zoom` (`NULL`) toe, direct na het
telefoonpunt: elk bestaand beeld blijft precies wat het was.

**Eén breekpunt.** Een telefoon is ten hoogste 640px breed
(`ResponsiveImage::MOBILE_MAX_WIDTH`). Hetzelfde getal staat in de
`<source media>` van elke telefoonafbeelding, in
`assets/css/responsive-media.css` en in de regels voor een telefoonhoogte
(`page-hero.css`, `media-banner.css`, `text-image-split.css`).
`Tests\Service\ResponsiveMediaContractTest` faalt zodra ze uit elkaar lopen.
De eigen breekpunten van een blok voor zijn layout blijven wat ze waren.

**De Kaarten-carrousel volgt zijn eigen presentatie.** De afbeelding voor een
telefoon is daar de *compacte* afbeelding van de kaart, en die verschijnt
zodra de kaart echt compact staat, niet bij de algemene 640px:

- de draaiende carrousel wordt onder 700px een platte rij
  (`CardCarouselContent::COMPACT_MAX_WIDTH` = 699, dezelfde grens als
  `card-carousel.css` en `card-carousel.js`); vanaf 699px toont hij de
  compacte afbeelding, via een `<source media="(max-width: 699px)">` en het
  punt en de weergave in `card-carousel.css`. Er zit dus geen gat meer tussen
  641 en 699px;
- bij *Kaarten naast elkaar* staan de kaarten op elk scherm in dezelfde platte
  rij, dus geldt de compacte afbeelding op elke breedte
  (`ResponsiveImage::compact()`, één `<img>`, geen `<source>`);
- zonder eigen afbeelding toont elk scherm de desktopafbeelding.

Punt, weergave en *Beeldverhouding op een rij* volgen hetzelfde contract. Er is
geen derde afbeelding, en elk ander blok houdt 640px.

**Eén markup.** `partials/responsive-image.php` print elk beeld van zo'n plek:

- zonder telefooninstellingen één `<img>`, teken voor teken wat het blok
  altijd printte: een punt buiten het midden als inline `object-position`,
  `contain` als inline `object-fit`;
- met een telefoonafbeelding een `<picture class="rm-picture">` met precies
  één `<source media="(max-width: 640px)">` om dezelfde `<img>`. De browser
  downloadt één van de twee, nooit allebei, en de alt-tekst staat alleen op
  de `<img>`: het is dezelfde inhoud. `.rm-picture` heeft `display: contents`,
  dus elke regel van een blok voor "de afbeelding in dit kader" blijft werken;
- een eigen punt, weergave of zoom op een telefoon als custom property
  (`--rm-mobile-position`, `--rm-mobile-fit`, `--rm-mobile-zoom`) plus een
  data-attribuut dat hem onder het breekpunt aanzet. Daar is `!important`
  nodig, omdat de desktopwaarde inline staat; het geldt alleen voor een
  `<img>` met dat attribuut, en dat attribuut staat er alleen als een telefoon
  echt iets anders toont;
- een zoom als de CSS-eigenschap `scale` op diezelfde `<img>`, met
  `transform-origin` op het focuspunt (hieronder).

Geen andere partial print een `<source>`, een `object-position` of een zoom,
en elk blok dat deze partial gebruikt vraagt `assets/css/responsive-media.css`
als eerste stylesheet aan (`App\Service\PageAssets`).

**Zoom.** Een zoom vergroot het beeld rond het focuspunt: `scale: 1.5;
transform-origin: <punt>`. Daardoor blijft het punt op zijn plaats en bedekt
het vergrote beeld bij elk punt het hele kader, ook bij 0/0 of 100/100 op
200%: er ontstaat nooit een lege rand. De negen voorinstellingen werken bij
elke zoom (linksboven + 150% zoomt in op de linkerbovenhoek). Het is `scale`
en niet `transform`, zodat een eigen `transform` van een blok (het inzoomen
van *Hover kaarten* bij aanwijzen) er gewoon bovenop komt. Bij 100% print de
partial niets nieuws: de `<img>` is teken voor teken die van vóór 3.0.

- **Geen layout shift.** De zoom verandert de box van de `<img>` niet; elk
  kader waarin een beeld met een punt staat knipt af (`overflow: hidden`):
  `.orbit-card__media` (sinds 3.0), `.text-image__media`, `.page-hero__media`
  en `.page-hero__figure`, `.cta-band__media`, `.media-banner` en
  `.media-sequence`, `.hover-card__frame`, `.hero__media-frame`, en het
  galerij-item van de Detailsectie zelf (sinds 3.0 het kader: rand, schaduw
  en afronding staan op `.service-detail__gallery-item`, het beeld is een
  kaal vierkant, en de focusring van een gelinkt item hoort bij het item).
  `Tests\Service\ResponsiveMediaZoomContractTest` houdt die lijst vast.
- **Cover en contain.** Alleen `cover` zoomt. Bij `contain` print de partial
  geen zoom (`ResponsiveImage::zoomFor()`), maar de opgeslagen waarde blijft
  staan: terug naar *Vullen* en de oude zoom is terug. Een telefoon die de
  afbeelding heel toont krijgt `--rm-mobile-zoom: 1`.
- **Telefoon.** De zoom hoort bij het punt. Heeft een telefoon een eigen punt
  (een eigen telefoonafbeelding, of *Mobiel focuspunt apart instellen*), dan
  heeft hij ook een eigen zoom (`<prefix>mobile_zoom`, in het telefoonkader
  van de editor). Gebruikt de telefoon het desktoppunt, dan ook de
  desktopzoom; er komt geen extra bediening bij. De Kaarten-carrousel schakelt
  de telefoonzoom op zijn eigen compacte breedte (699px), zoals het punt; een
  compacte plek (`ResponsiveImage::compact()`) toont de telefoonzoom overal.
  Een dia van een reeks houdt het desktoppunt en dus ook de desktopzoom.
- **Klikbaar.** Een gelinkt beeld (een gelinkt Detailsectie-item, een Hover
  kaart met link) blijft één link: het vergrote beeld zit binnen de link en
  binnen het kader, dus elk punt van het kader raakt de link.

**Validatie.** `ResponsiveImage::fromRequest()`: een punt dat een getal is
wordt geklemd op 0–100 en een zoom op 100–200 (een schuif kan niet meer
sturen, een nagemaakt verzoek wel); een zoom die geen getal is (`NaN`, `INF`,
`1e3`, `150%`, een lijst) wordt geweigerd, net als al het andere met een zin
bij het veld: een
woord buiten zijn gesloten lijst, en een telefoonafbeelding die geen
afbeelding uit de bibliotheek is. Dat laatste is strenger dan
`MediaService::findImage()` (die alleen een video weigert): een
telefoonafbeelding wordt de `srcset` van een `<source>`, en daar kan een
browser alleen een afbeelding lezen (`MediaItem::isPicture()`). Een formulier
zonder het veld houdt wat er stond. *Gebruik desktopafbeelding* laat een
gekozen telefoonafbeelding los, ook als de kiezer hem nog vasthoudt.

**Een reeks** (Paginakop en Mediabanner, `App\Service\Media\MediaSequence`):
het punt en de weergave van het blok gelden voor elke dia. Een eigen
telefoonafbeelding geldt alleen voor een blok met één beeld; een dia krijgt
hem nooit, want één telefoonbeeld kan niet voor een hele reeks staan, en
ook het punt dat op die telefoonafbeelding is gezet niet: een dia houdt op een
telefoon het desktoppunt. Een eigen telefoonpunt op de desktopafbeelding zelf
(*Mobiel focuspunt apart instellen*) geldt wel voor elke dia, net als de
weergave op een telefoon. Een
punt per dia is bewust niet gebouwd: de reeks is een lijst van keuzes uit de
bibliotheek zonder eigen rij per dia in de editor, en een tweede editor per
dia zou een tweede implementatie zijn.

**Hover kaarten**: alleen de hoofdafbeelding. De tweede afbeelding, bij
aanwijzen, vult het kader altijd vanuit het midden; het veld zegt dat.

**Niet aangesloten, en waarom.** Producten, varianten, collecties,
Portfolio-projecten en blogberichten hebben hun eigen media en schermen;
die vallen buiten deze fase. De hoofdafbeelding van een Detailsectie wordt
niet bijgesneden (volle breedte, eigen verhouding), dus een focuspunt doet
daar niets; haar galerij-items wel (sinds v0.1.13 een plek, hierboven). Hun
punt hoort bij de galerijrij, ook als het item de foto van een product,
project of blogbericht toont: die foto zelf krijgt geen punt, en een nieuwe
foto van dat item staat meteen in beeld met het punt van de galerij. Een icoon (*Kenmerken in kaartjes*)
is een SVG die nooit wordt bijgesneden. Het deelbeeld (`og_media_id`) staat
niet op de site zelf.

### Compact, Normaal, Groot

Responsive Media 3.1 (v0.1.15) geeft de maatstappen van een beeld één
betekenis, op de pagina en in het CMS, op een groot scherm, een tablet en een
telefoon. `App\Service\Media\ImagePresentation` is die betekenis:

- **Drie stappen, kleinste eerst:** `compact`, `normal`, `large`. Een blok mag
  ze anders opslaan (Tekst met afbeelding en de Mediabanner: `small`,
  `medium`, `large`; een eigen telefoonhoogte: `compact`, `normal`, `large`),
  maar het zijn dezelfde drie stappen, één keer gekoppeld in
  `ImagePresentation::STEP_OF`. Een stap houdt zijn plaats op elk scherm:
  op een tablet is Groot nog steeds de grootste, nooit "een maat kleiner".
  Het CMS noemt ze overal *Compact*, *Normaal* en *Groot* (Engels *Compact*,
  *Normal*, *Large*); de opgeslagen woorden blijven wat ze waren.
- **Een telefoon: automatisch of eigen** (`ImagePresentation::onPhone()`).
  Zonder eigen telefoonhoogte volgt een telefoon de stap van het blok
  (`AUTOMATIC`); met een eigen telefoonhoogte toont hij die stap (`OWN`). Een
  blok mag die twee bronnen verschillende lengtes geven: bij Tekst met
  afbeelding, de Mediabanner en de Paginakop is een eigen *Groot* bewust hoger
  dan de automatische. Een verzonnen woord is altijd `AUTOMATIC`.
- **Eén bron voor pagina en voorbeeld.** De lengtes van een stap staan als
  letterlijke CSS-waarden in de Content-klasse van het blok
  (`TextImageSplitContent::WIDE_HEIGHTS`, `STACKED_RATIOS`, `STACKED_MAX`,
  `PHONE_HEIGHTS`, `PHONE_OWN_HEIGHTS`;
  `MediaBannerContent::WIDE_HEIGHTS`, `PHONE_HEIGHTS`, `PHONE_OWN_HEIGHTS`;
  `PageHeroContent::WIDE_HEIGHTS`, `NARROW_HEIGHTS`, `PHONE_OWN_HEIGHTS`,
  `FIGURE_*`). Het stylesheet print ze, en
  `Tests\Service\ImagePresentationContractTest` faalt zodra één teken
  verschilt. Het CMS rekent zijn kaders uit dezelfde waarden uit
  (`ImagePresentation::length()` op het referentiescherm), dus een stap die
  verandert, verandert op de pagina en in het voorbeeld tegelijk. `admin/`
  heeft geen eigen maattabel meer (de `:has()`-tabellen in `admin.css` zijn
  weg).
- **Drie referentieschermen voor het voorbeeld**
  (`ImagePresentation::VIEWPORTS`): Desktop 1280 x 900 (het venster waarin
  elk kader sinds 2.0 gemeten is), Tablet 768 x 1024 (staand), Mobiel
  375 x 812. Dat zijn geen breekpunten: elk blok houdt zijn eigen breekpunten,
  en het ene breekpunt van het contract blijft 640px. De breedte van de
  inhoud is die van `.container` in `core.css`: 1136, 704 en 327px.

**De breekpunten, uit de code.**

| Blok | Groot scherm | Tablet | Telefoon |
|---|---|---|---|
| Responsive Media (telefoonafbeelding, -punt, -zoom, -weergave, -hoogte) | > 640px | > 640px: zoals een groot scherm | ≤ 640px |
| Tekst met afbeelding | > 860px: tekst en beeld naast elkaar | 641–860px: één kolom, de vorm van de stap | ≤ 640px: één kolom, vaste hoogtes of de eigen telefoonhoogte |
| Paginakop | > 900px | ≤ 900px: lagere stappen, beeld naast de tekst gaat erboven | ≤ 640px de eigen telefoonhoogte |
| Mediabanner | > 640px | > 640px: zoals een groot scherm | ≤ 640px: vaste, lagere stappen of de eigen telefoonhoogte |

Tablet 768px valt dus bij Tekst met afbeelding en de Paginakop in hun smalle
lay-out, en bij de Mediabanner in die van een groot scherm.

**Wat de stappen zijn** (Tekst met afbeelding bij 50%; Mediabanner binnen de
container; in px op het referentiescherm, en hoe het CMS-kader ze toont):

| Stap | Desktop 1280 | Tablet 768 | Mobiel 375 | CMS-voorbeeld |
|---|---|---|---|---|
| Tekst met afbeelding, Compact (`small`) | 536 x 307 (`clamp(14rem, 24vw, 20rem)`) | 704 x 396 (16:9) | 327 x 192 (12rem) | dezelfde maat, op halve grootte |
| Tekst met afbeelding, Normaal (`medium`) | 536 x 461 (`clamp(18rem, 36vw, 30rem)`) | 704 x 528 (4:3) | 327 x 256 (16rem) | idem |
| Tekst met afbeelding, Groot (`large`) | 536 x 640 (`clamp(22rem, 50vw, 42rem)`) | 704 x 672 (1:1, tot 42rem) | 327 x 320 (20rem) | idem |
| Tekst met afbeelding, eigen telefoonhoogte Compact / Normaal / Groot | — | — (geldt niet op een tablet) | 327 x 192 / 256 / 384 (12 / 16 / 24rem) | idem |
| Mediabanner, Compact / Normaal / Groot / Extra groot | 1136 x 282 / 384 / 538 / 666 | 704 x 240 / 288 / 352 / 416 | 327 x 192 / 240 / 304 / 384 | idem |
| Paginakop achter de tekst, Compact / Normaal / Groot | 1280 x 360 / 495 / 675 | 768 x 352 / 512 / 576 | 375 x 352 / 512 / 576 | idem |

Een eigen telefoonhoogte geldt alleen op een telefoon, nooit op een tablet.
Bij alle drie de blokken is het een vaste hoogte, en een eigen *Groot* is
hoger dan de automatische (Tekst met afbeelding 24rem tegen 20rem). De
Mediabanner en de Paginakop houden hun telefoonhoogtes (tabel in
`CONTENT-BLOCKS.md`); die waren op een tablet niet fout en zijn in 3.1 niet
veranderd.

**Een telefoon toont Tekst met afbeelding als vóór 3.1 (3.1.1).** De eerste
versie van 3.1 gaf ook een telefoon de vorm van de stap (327 x 184 / 245 /
327, eigen Groot = automatisch Groot). Dat was een bijwerking: de fout zat op
de tablet. Sinds 3.1.1 heeft een telefoon (≤ 640px) weer de hoogtes van
`27311e9`: automatisch 12, 16 en 20rem, eigen telefoonhoogte 12, 16 en 24rem,
op de volle breedte van de kolom. De tablet (641–860px) houdt de vorm van de
stap, en het CMS-kader *Mobiel* rekent uit dezelfde constanten.

**Waarom de tablet een stap kleiner leek (de fout van vóór 3.1).** Tekst met
afbeelding zette onder 860px vaste hoogtes van 12, 16 en 20rem, bedoeld voor
een telefoon van 327px breed. Op een tablet is het beeld 600 tot 790px breed,
dus werd elk beeld een platte strook: gemeten bij 768px was Groot 694 x 320
(2,17:1), platter dan Klein op een groot scherm (1,68:1); Normaal 2,71:1 en
Compact 3,61:1. Nu is het één kolom met de vorm
van de stap, gemeten bij 768px: 694 x 390, 694 x 521 en 694 x 672 (1,78, 1,33
en 1,03). Op een groot scherm en op een telefoon is niets veranderd ten
opzichte van vóór 3.1.

### Een plek aansluiten

1. Migratie: de kolommen van een `ResponsiveImageSlot` op de tabel,
   `<prefix>mobile_media_id` met `ON DELETE RESTRICT` naar `media`; de tabel
   in de lijst van `ResponsiveImageRepository`.
2. Inhoudsklasse: een `imageSlot()`, en
   `ResponsiveImage::fromRow($row, $slot)->forRender($image)` naast het beeld.
3. Partial: `render_responsive_image()`, met een terugval voor een inhoud
   zonder weergave (het voorbeeld in de blokkenbibliotheek); het blok vraagt
   `assets/css/responsive-media.css` als eerste aan, en het kader van het beeld
   knipt af (`overflow: hidden`), anders loopt een zoom erbuiten.
4. Editor: `responsive_image_field()`, de vorm van de plek als
   `--admin-rm-desktop-ratio` en `--admin-rm-mobile-ratio`, en één keer
   `responsive_image_field_script()`. Als preview de kleine versie
   (`MediaItem::displayPath()`, bij een gelinkt item
   `LinkedImages::resolve()['preview_path']`). Een rij-editor geeft de velden
   van een opgeslagen rij door als `ResponsiveImage::fromRow()->toRow()`,
   nooit met de hand: zo komt elke kolom van het slot (ook de zoom) in het
   formulier.
5. Endpoint: `ResponsiveImage::fromRequest()`, fouten als
   `presentation.<onderdeel>`, schrijven in dezelfde transactie. Een lijst met
   rijen zet `presentation`, `focus_x`, `focus_y`, `zoom` (en waar de
   telefoon er is `mobile_source`, `mobile_focus_x/y`, `mobile_zoom`, `fit`,
   `mobile_fit`) in de preset van `EditorChildList`: die velden komen
   ingevuld mee en maken een lege nieuwe rij geen rij.
6. Een tak voor `<prefix>mobile_media_id` in `ContentBlockMediaUsage`.
7. De plek in `ResponsiveMediaContractTest`, het kader in
   `ResponsiveMediaZoomContractTest::FRAMES` en de plek in de tabel hierboven.

### Licht in het CMS

Een scherm met veel beelden (een Detailsectie met 24 galerij-items) mag door
focus en zoom niet zwaarder worden. Het contract, vastgelegd in
`ResponsiveMediaZoomContractTest` en gemeten in v0.1.14 (`TESTING.md`,
"Performance van de editor"):

- **Kleine previews.** Het kader toont de thumbnail van de bibliotheek (480px
  lange zijde), ook voor een gelinkt product, project of bericht
  (`LinkedImages::resolve()['preview_path']`, en zo ook het antwoord van
  `api/admin/linked-image-preview.php`); nooit het origineel alleen om een
  kader van 20rem te vullen. Alleen een beeld van vóór de bibliotheek zonder
  kleinere versie toont zijn eigen pad.
- **Lui laden.** Elke preview heeft `loading="lazy" decoding="async"`: een
  kader ver onder de vouw, in een ingeklapte rij of in het dichte deel *Op een
  telefoon* downloadt niets tot het bijna in beeld komt.
- **Lui wekken.** `admin/assets/responsive-image.js` wekt een veld pas als het
  binnen één schermhoogte van het beeld komt (`IntersectionObserver`), als een
  rijlijst het toevoegt, of als iemand het eerder met muis of Tab bereikt; een
  gewekt veld heeft `data-rm-ready`. De server print de staat al, dus een
  ongewekt veld ziet er goed uit en post goed. Zonder `IntersectionObserver`
  wekt het script alles meteen, zoals vroeger.
- **Vaste listeners.** Eén set gedelegeerde listeners op `document` (elf),
  hoeveel velden er ook zijn; geen listener op een veld, kader of beeld. Een
  rij die een rijlijst uit de DOM haalt meldt dat eerst (`row-list:removed`),
  en het script laat dan de waarneming los. Blok-editors verwijderen een rij
  pas bij opslaan (de schakelaar *Verwijderen*); daar valt niets op te ruimen.
- **Eén tekening per frame.** Slepen tekent hoogstens één keer per
  animatieframe (`requestAnimationFrame`); een schuif tekent direct, en dat
  kost minder dan 0,02 ms.
- **Geen canvas, geen library.** Zoom is CSS (`scale`) op het bestaande
  `<img>`, in de editor en op de site.

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
  ├── ContentBlockMediaUsage      de geïntegreerde blokken (één UNION-query), ook op een product of project
  └── + wat elke INGESCHAKELDE module bijdraagt
```

**De regel voor een provider**: hij krijgt een hele **lijst** media-id's
tegelijk en beantwoordt die in een begrensd aantal queries — één, in alle
huidige implementaties. Het overzicht toont een pagina met tegels en heeft
per tegel een teller nodig; per item vragen zou precies de N+1 zijn die niet
mag.

Een galerij-item van een Detailsectie dat een product, project of
blogbericht toont (`App\Service\Media\LinkedImages`, `CONTENT-BLOCKS.md`,
"Detailsectie 2.0") verwijst naar geen enkel bibliotheekitem: het toont de
afbeelding van dat item, live, en die telt mee bij dat item (de productfoto,
de hoofdfoto van het project, de uitgelichte afbeelding van het bericht).

**Een afbeeldingsbron** (Detailsectie 2.1, `CONTENT-BLOCKS.md`) is precies
dat: de Mediabibliotheek, of een soort item waarvan de module een
hoofdafbeelding aanlevert (`ModuleDefinition::linkedImages()`). Opgeslagen
wordt alleen de bron (`source_type`, `source_id`), nooit een kopie; focus en
zoom horen bij het galerij-item en veranderen de bronafbeelding niet.
`LinkedImages::item()` geeft ook een openbaar item zonder hoofdafbeelding
(lege `image_path`, een naamtegel op de website); `LinkedImages::resolve()`
alleen een item met afbeelding.

Een blok op de pagina-inhoud van een product of project
(`CONTENT-BLOCKS.md`, "Blokken op een product of project") staat in dezelfde
bloktabellen en telt dus vanzelf mee. Het label noemt dan de eigenaar in plaats
van de sleutel van zijn inhoudspagina: *Tekst + afbeelding op "Product: Eiken
plank"* (`ContentBlockMediaUsage::ownerName()`), en zo'n afbeelding is niet uit
de bibliotheek te verwijderen.

Een module beantwoordt zijn eigen tabellen via
`ModuleDefinition::mediaUsageProviders()`. Zo hoeft Core Media nooit te weten
wat een product is. De **Blog** is de eerste module die dat doet
(`App\Service\Blog\BlogPostMediaUsage`, `BLOG.md`): hij meldt de uitgelichte
afbeelding van een bericht als *"Blogbericht: &lt;titel&gt;"* en een eigen
deel-afbeelding apart, allebei met een link naar de editor, in één query voor
een hele reeks id's. De **Shop** doet hetzelfde
(`App\Service\ShopMediaUsage`, `MODULES.md`): *"Product: &lt;naam&gt;"* per
productafbeelding, met erbij bij hoeveel varianten hij ook staat, en
*"Collectie: &lt;naam&gt;"*. Een variant heeft geen eigen afbeelding: hij
koppelt aan een afbeelding van zijn product (`product_variant_images`), dus zijn
gebruik is dat van het product.

**Staat een module uit, dan telt zijn gebruik niet mee.** Dat is dezelfde
regel als overal (`MODULES.md`): een uitgeschakelde module draagt niets bij.
Met de Blog uit is een afbeelding die alleen op een blogbericht staat dus
verwijderbaar — data van een uitgeschakelde module is geen deel van de
draaiende site. De rijen blijven staan, en zodra de module weer aan gaat is
diezelfde afbeelding weer beschermd.

### Wie mag lezen wáár

Elke `MediaUsage` noemt de permissie van het scherm waar die plek bewerkt
wordt, dezelfde die dat scherm zelf al vraagt:

| Provider | Permissie |
|---|---|
| `BrandingMediaUsage` | `settings.manage` |
| `PageSocialImageMediaUsage` | `pages.manage` |
| `ContentBlockMediaUsage` | het recht van de bloklijst, naar haar eigenaar (`ContentBlockAccess`): `pages.manage` op een pagina (ook de Paginakop en de Homepage Hero), `products.manage` op een product, `portfolio.manage` op een project |
| `BlogPostMediaUsage` (Blog) | `blog.manage`: het berichtenoverzicht (`blog.view`) opent geen bericht |
| `ShopMediaUsage` (Shop) | `products.manage` voor een product, `collections.manage` voor een collectie |

Een plek staat er **bij naam en met link** alleen voor wie die permissie
heeft. Voor ieder ander wordt die plek **geteld, niet genoemd**: *"2 plekken
die je niet kunt openen"*. Iemand met `media.manage` maar zonder
`pages.manage` weet dus dat een bestand gebruikt wordt en daarom blijft staan,
maar leest niet op welke pagina.

Eén klasse beslist dat: `App\Service\Media\VisibleMediaUsages`, met
`AdminAuth::can()` als vraag. Het itemscherm en het antwoord van
`delete-media-items.php` (de JSON en de flash) gaan er allebei doorheen. De
teller op een kaart en in de bevestigingsdialoog zegt alleen **hoeveel**
plekken, en dat mag iedereen weten.

**Alleen wat er gezegd wordt, wordt gefilterd.** Het gebruik wordt nog steeds
volledig vastgesteld, en verwijderen wordt beslist op die volledige lijst
(`usagesForStrict`). Een gebruikt bestand blijft staan, ook voor een
beheerder die geen enkele van die plekken mag zien.

## Verwijderen

```text
niets gebruikt het   ->  rij weg, eigen bestand weg, eigen thumbnail weg
iets gebruikt het    ->  geweigerd, met de plekken erbij die je mag openen (de rest geteld)
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

Op het itemscherm vraagt *Verwijderen* het eerst, in de gedeelde dialoog van
het CMS (`admin_confirm_attributes()` en `admin_confirm_dialog()`,
`ADMIN-UI.md`), met de naam van het bestand in de vraag. *Annuleren*, Escape of
een klik naast de dialoog verstuurt niets. Zonder JavaScript gaat het
formulier direct, en de server houdt nog steeds tegen wat gebruikt wordt.

### Meerdere tegelijk

Op het raster heeft elke kaart een selectievakje (`.admin-checkbox`, met de
naam van het bestand als toegankelijke naam), voor wie `media.manage` heeft.
*Alles op deze pagina selecteren* staat erboven; een teller (*3 geselecteerd*)
en de knop *Verwijderen* verschijnen pas als er iets geselecteerd is. De knop
opent een eigen bevestiging, een `<dialog>` van dit scherm: hoeveel bestanden
er echt weggaan, welke nog gebruikt worden en daarom blijven staan, en dat het
niet ongedaan kan worden gemaakt. De focus staat dan eerst op *Annuleren*. Dat
is bewust niet de gedeelde dialoog: die stelt één vaste vraag, en deze toont
per keer wat er van de selectie echt weggaat en wat blijft staan.

`api/admin/delete-media-items.php` → `MediaService::deleteMany()`:

- het gebruik van **de hele selectie** wordt eerst in één strikte vraag
  vastgesteld, vóór er iets weggaat. Kan een provider niet antwoorden, dan
  wordt er niets verwijderd;
- wat niets gebruikt, gaat weg volgens de regel hierboven (rij, dan bestand,
  dan thumbnail). Wat nog gebruikt wordt, blijft staan en komt terug **met de
  plekken** waar het gebruikt wordt, voor zover de beheerder die mag openen
  (zie *Wie mag lezen wáár*). Een id dat niets aanwijst wordt gemeld;
- een gedeeltelijk resultaat is het normale: drie weg en één bewaard is geen
  fout;
- hooguit `MediaService::MAX_DELETE_AT_ONCE` (100) tegelijk. De weg terug naar
  het raster wordt opgebouwd uit `q`, `type` en `page`, nooit uit een adres
  dat het verzoek meestuurt.

Zonder JavaScript is de balk een gewoon formulier dat met een redirect
antwoordt. Er is dan geen bevestiging, net als bij de losse verwijderknop, en
de server houdt nog steeds tegen wat gebruikt wordt.

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
| `media.manage` | Naam en alt-tekst van bestaand materiaal wijzigen, mappen maken, hernoemen en verwijderen, items naar een map verplaatsen, en ongebruikte media verwijderen, ook een selectie tegelijk. Bevat `media.view` |

De knip zit daar omdat de bibliotheek **gedeeld** is. Iets toevoegen kon elke
redacteur al via het uploadveld van elk blok en neemt niemand iets af;
de alt-tekst wijzigen van een item dat op vier pagina's staat, of een item
verwijderen, reikt verder dan het scherm waar iemand naar kijkt.

`pages.manage`, `portfolio.manage` en `settings.manage` bevatten automatisch
`media.view` — beeld kiezen hoort bij het bewerken van een pagina. Via hun
module ook `blog.manage`, `products.manage` en `collections.manage`. Niets
bevat automatisch `media.manage`.

`media.manage` geeft **geen** inzage in andere domeinen. Waar een bestand
gebruikt wordt, staat er bij naam alleen voor wie ook het scherm van die plek
mag openen; voor ieder ander wordt die plek geteld (zie *Wie mag lezen
wáár*).

Alle schrijfacties vragen CSRF en volgen de vaste volgorde login,
permissie, POST, CSRF (`Tests\Service\MediaBoundaryTest`); de lijst-endpoint
is een GET en doet dat bewust niet.

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

### Eén handeling, één flow

**Kies uit mediabibliotheek** opent de bibliotheek, en niets anders. Het
bestandsvenster van Windows of macOS gaat alleen open als de redacteur
**binnen** de bibliotheek kiest voor **Nieuw bestand uploaden**:

```text
Kies uit mediabibliotheek
  → de Mediabibliotheek opent (map, zoeken, raster of lijst)
  → een bestaand item aanklikken      OF   Nieuw bestand uploaden
                                             → bestandsvenster, één keer
                                             → gewoon media-item (media-upload.php),
                                               in de map die open staat, geselecteerd
  → Selecteren
  → terug in het oorspronkelijke scherm, met precies de gekozen items
```

Hoe dat vastligt, en wat `Tests\Service\MediaPickerContractTest` bewaakt:

- de open-handler roept `preventDefault()` aan (geen label-activatie, geen
  formulierverzending op dezelfde klik) en opent alleen een gesloten modal;
- het script bindt zich één keer per pagina (`data-media-picker-ready`), zodat
  twee keer laden geen tweede handler voor dezelfde klik oplevert;
- het bestandsveld van de modal is `hidden` en staat in **geen** `<label>`: de
  knop *Nieuw bestand uploaden* is de enige deur, en `openFileDialog()` de
  enige code die `click()` op dat veld doet;
- een upload maakt het bestand een gewoon media-item en **selecteert** het;
  het gaat pas naar het veld bij *Selecteren*, net als een bestaand item.

**De oorzaak van de dubbele flow.** In de oude kiezer was de upload een
zichtbaar, native bestandsveld in een `<label>` bovenin de modal, direct naast
het zoekveld: een klik op het label of de knop ernaast opende het
bestandsvenster, en de keuze daaruit werd meteen gekozen en de modal gesloten.
Daarnaast hadden drie schermen een eigen bestandskiezer naast de bibliotheek:
Portfolio (*Afbeelding*) en de deel-afbeelding van een product en een
collectie waren een `admin_file_input()` — een klik daarop opende het
bestandsvenster zonder bibliotheek, en de upload ging buiten de bibliotheek om;
op het productscherm stond die kiezer in hetzelfde formulier, vlak onder de
afbeeldingen en hun *Afbeelding toevoegen*.
Die twee wegen zijn nu weg: elk regulier afbeeldingsveld is de kiezer, en de
kiezer heeft één deur naar het bestandsvenster. In de testomgeving is een
synthetische klik op *Afbeelding toevoegen* van een product nooit bij het
bestandsveld uitgekomen; wat een echte muisklik in de browser van de
eigenaar deed, is dus niet in een test te reproduceren geweest, en de
oplossing sluit alle paden uit in plaats van één.

### Kiezen, dan bevestigen

- Een kaart is een knop met `aria-pressed`: klikken selecteert, nog eens
  klikken haalt de selectie weg. Onderaan staat hoeveel er geselecteerd is.
- Een gewoon veld neemt **één** item: een tweede klik vervangt de eerste. Een
  verzamelveld (`data-media-picker-collect`) neemt er meerdere.
- **De selectie blijft staan** bij een andere map, een zoekopdracht en *Meer
  laden*: ze wordt per id buiten het raster bijgehouden, en een kaart die
  terugkomt wordt weer als geselecteerd getekend.
- **Selecteren** geeft precies de selectie door, in de volgorde van kiezen.
  **Annuleren**, Escape, × en de achtergrond sluiten de modal en veranderen
  **niets** in het oorspronkelijke scherm.
- De modal heeft dezelfde mappen (een `.admin-select` met *Alle media*, *Geen
  map* en de mappen, uit het eerste antwoord van `media-list.php?folder=`) en
  hetzelfde *Raster* | *Lijst* als de bibliotheek, met dezelfde onthouden
  voorkeur.
Er komt **een id uit en verder niets** — geen pad, geen naam, geen URL. Het
endpoint controleert dat id alsnog tegen de bibliotheek
(`BlockImage::fromRequest()`): een getal dat niets aanwijst is "geen
afbeelding", nooit een opgeslagen verwijzing.

**Eén soort per veld.** `media_picker_field(…, $kind)` neemt
`MediaType::IMAGE` (de standaard) of `MediaType::VIDEO`. De modal toont dan
alleen die soort (`media-list.php?type=`) en de upload in de modal neemt alleen
die soort aan (`media-upload.php`, `kind=`; ook een al bestaand bestand van de
andere soort wordt dan geweigerd in plaats van hergebruikt). Een
afbeeldingsveld biedt dus nooit een MP4 aan. Het endpoint achter het veld
controleert het nog eens: `BlockImage::fromRequest()` en
`MediaService::findImage()` behandelen een video-id als "geen afbeelding",
`MediaService::findVideo()` omgekeerd. Een SVG is een afbeelding en staat in
elke afbeeldingskiezer, **behalve die van een deel-afbeelding**.

**Afbeelding óf video: één veld.** Het derde kiezerfilter gaat de andere
kant op: `MediaType::VISUAL` neemt beide soorten, voor een gebruiksplek waar
het gekozen item zelf bepaalt of het een afbeelding of een video is (de
Mediabanner, `CONTENT-BLOCKS.md`). De modal toont en uploadt dan afbeeldingen
en video's (`media-list.php?type=visual`, `media-upload.php` met
`kind=visual`: de soort blijft open, zoals in de wachtrij van de bibliotheek
zelf), en nog steeds niets zonder soort. Het veld zet het type naast de naam
(*Afbeelding* of *Video*) en draagt het in `data-media-picker-chosen`, zodat
het scherm kan tonen wat alleen bij die soort hoort. Het endpoint leest het
id via zijn eigen regel (`MediaBannerContent::usableItem()`: een afbeelding
of een video, verder niets). Het is geen onderdeel van het filter boven de
bibliotheek.

**Deel-afbeelding: geen SVG.** Sociale netwerken tonen geen SVG als preview.
Daarom heeft `MediaType` naast de soorten één *kiezerfilter*:
`MediaType::SOCIAL_IMAGE`, een afbeelding in een van de rasterformaten
(JPG, PNG, WEBP, GIF; hetzelfde contract als de deel-afbeeldingen van de Shop
altijd hadden). Het is geen soort: het filter boven de bibliotheek biedt het
niet aan, en een gewone afbeeldingskiezer blijft SVG tonen. De vier
deel-afbeeldingsvelden (pagina, blogbericht, standaard in Instellingen en in
de installatiewizard) vragen erom; de lijst en de upload in de modal volgen
het, en de endpoints lezen het id via `MediaService::findSocialImage()`. De
deel-afbeelding die een pagina al had, blijft geldig, zodat geen bestaande
pagina onopslaanbaar wordt.

**Icoon: alleen SVG.** Het tweede kiezerfilter is `MediaType::ICON`: een
afbeelding die een SVG is. De eerste gebruiker is het eigen icoon van een
kaart in *Kenmerken in kaartjes* (`feature_grid_items.icon_media_id`). De
kiezer toont dan alleen SVG's en de upload in de modal neemt alleen een SVG
aan (`MediaUploader::nameFitsFilter()`, `acceptAttribute(ICON)`); zo'n upload
gaat door dezelfde `SvgSanitizer` als elke andere SVG en is daarna een gewoon
item, ook in elke afbeeldingskiezer. Het endpoint leest het id met
`MediaService::findIcon()`, dat alleen een SVG als antwoord geeft. Andere
afbeeldingskiezers veranderen niet: die tonen raster én SVG.

Zet je JavaScript uit, dan blijft het formulier gewoon opslaan wat er al
gekozen was.

Een nieuw blok hoeft dus **geen eigen uploadveld en geen eigen
upload-endpoint** meer te bouwen om een publieke afbeelding te kiezen. Dat is
de belangrijkste opbrengst van deze stap.

**Verzamelmodus.** Een lijst afbeeldingen (de afbeeldingen van een product,
`admin/_product_gallery.php`) heeft geen verborgen veld per keuze. Haar knop
*Afbeelding toevoegen* staat in een element met `data-media-picker-collect`:
de kiezer geeft bij *Selecteren* elk geselecteerd item (ook de net geüploade)
door als een bubbelend `media-picker:choose`-event met het item als `detail`,
en de upload in de modal neemt meerdere bestanden tegelijk. Wat de lijst ermee doet is haar zaak; de
kiezer geeft nog steeds alleen bibliotheekitems door, en het endpoint achter
de lijst controleert elk id opnieuw. Dezelfde lijst, en dus dezelfde
verzamelmodus, is het veld van een mediareeks (`admin/_media_sequence_field.php`,
`CONTENT-BLOCKS.md`, "Mediareeks"): de Paginakop verzamelt afbeeldingen, de
Mediabanner afbeeldingen en video's (`MediaType::VISUAL`), en een video staat
er met een icoon in, omdat er van een video geen stilstaand beeld is.

## Wat er in V1 is aangesloten

| Feature | Hoe |
|---|---|
| Logo, tweede logo, favicon, standaard deel-afbeelding | `site_settings.*_media_id`, oude `*_path` als terugval |
| Deel-afbeelding per CMS-pagina | `pages.og_media_id` |
| Tekst met afbeelding (`text_image_split`) | `text_image_split_items.media_id`, één per item en optioneel; elk item telt als gebruik, ook twee items van één blok met hetzelfde beeld |
| Detailsectie (`detail_section`) | `detail_sections.main_media_id` + `detail_section_images.media_id` |
| Kaarten-carrousel (`card_carousel`) | `carousel_cards.media_id`, en het icoon van een kaartlabel (`carousel_cards.label_icon_media_id`, alleen bij *Labelweergave: Icoon*; een SVG, `MediaType::ICON`, versiering met `alt=""` en `aria-hidden`). Kiest de kaart een andere labelweergave, dan wordt de verwijzing leeggemaakt en telt het icoon niet meer als gebruikt. Tak *Carrousel-kaart (label-icoon)*; `ON DELETE RESTRICT` |
| Paginakop (`page_hero`) | `page_heroes.media_id`, zonder oud pad: een paginakop had nooit een afbeelding. Eigen alt-tekst per taal (`image_alt` in `block_translations`) alleen voor een beeld náást de tekst; een beeld áchter de tekst is versiering (`alt=""`). *Geen afbeelding* maakt de verwijzing leeg, zodat het item niet meer als gebruikt telt |
| Uitgelichte afbeelding en deel-afbeelding van een blogbericht | `blog_posts.featured_media_id`, `blog_posts.og_media_id` — een module, dus via `BlogModule::mediaUsageProviders()` |
| Eigen icoon van een kaart in *Kenmerken in kaartjes* (`feature_grid`) | `feature_grid_items.icon_media_id`, alleen als `icon_key = custom`; geen oud pad en geen alt-tekst, want het icoon is versiering (`aria-hidden`, `alt=""`): de titel en tekst van de kaart dragen de betekenis. Kiest de kaart weer een standaardicoon of *Geen*, dan wordt de verwijzing leeggemaakt en telt het item niet meer als gebruikt |
| Achtergrond van een Oproep met knop (`cta_band`) | `cta_bands.background_media_id`, zonder oud pad (een oproep had nooit een afbeelding) en zonder alt-tekst: de afbeelding is versiering (`alt=""`, `aria-hidden`), de woorden van de oproep dragen de betekenis. Een video is geen antwoord (`MediaService::findImage()`). Wissen in de kiezer maakt de verwijzing leeg; `ON DELETE RESTRICT` als tweede verdediging (`CONTENT-BLOCKS.md`, "Oproep met knop") |
| Portfolio-item (module) | `portfolio_gallery_items.media_id`, oude `image_path`/`thumbnail_path` meegeschreven; eigen alt-tekst per taal, anders die van het item |
| Galerij van een Portfolio-projectpagina (module) | `portfolio_item_images.media_id`, `image_path`/`thumbnail_path` meegeschreven; eigen alt van een oude foto, anders die van het item |
| Deel-afbeelding van product en collectie (Shop) | `products.og_media_id`, `collections.og_media_id`, oude `og_image_path` meegeschreven |
| Homepage-hero: afbeelding en video | `homepage_hero.media_id` (met eigen alt-tekst per taal) en `homepage_hero.video_media_id`, oude `image_path` / `video_path` als terugval. Het videoveld is de eerste videokiezer (`media_picker_field(…, MediaType::VIDEO)`) |
| Mediabanner (`media_banner`) | `media_banners.media_id`, een afbeelding of een video in één veld (`media_picker_field(…, MediaType::VISUAL)`), en `media_banners.poster_media_id`, het beeld vóór het afspelen van een video. Geen oud pad en geen eigen alt-tekst: een afbeelding krijgt de alt-tekst van de bibliotheek, leeg is decoratief. Twee takken in `ContentBlockMediaUsage`: *Mediabanner op "…"* en *Mediabanner (poster) op "…"*. Het endpoint leegt de poster zodra het item geen video is, dus een poster die niemand ziet telt niet als gebruik. `ON DELETE RESTRICT` op allebei |
| Mediabanner: meer items na elkaar | `media_banner_items.media_id`, de afbeeldingen en video's na het eerste item, in hun volgorde. Alt-tekst van de bibliotheek. Tak *Mediabanner (reeks) op "…"*; `ON DELETE RESTRICT` |
| Paginakop: meer afbeeldingen na elkaar | `page_hero_images.media_id`, de afbeeldingen na die van de kop zelf. Achter de tekst versiering (`alt=""`), naast de tekst de alt-tekst van de bibliotheek. Tak *Paginakop (diavoorstelling) op "…"*; `ON DELETE RESTRICT`. *Geen afbeelding* in de kop leegt ook deze lijst |
| Hover kaarten grid (`hover_card_grid`) | `hover_card_grid_items.media_id` (verplicht, de alt-tekst van de bibliotheek) en `hover_card_grid_items.hover_media_id` (optioneel, de tweede afbeelding bij hover: `alt=""` en `aria-hidden`). Allebei alleen een afbeelding (`MediaService::findImage()`), zonder oud pad. Twee takken: *Hover kaarten grid op "…"* en *Hover kaarten grid (tweede afbeelding) op "…"*; `ON DELETE RESTRICT` op allebei |
| Reviews (`reviews`) | `review_block_items.media_id` (optioneel, een portret of representatieve foto; de alt-tekst van de bibliotheek). Alleen een afbeelding (`ReviewsContent::picture()`), zonder oud pad. Eén tak: *Reviews op "…"*; `ON DELETE RESTRICT`. Op de pagina de thumbnail van de bibliotheek (`MediaItem::displayPath()`, 480px), want het kader is klein |
| Afbeelding voor een telefoon, op elk van de plekken van *Responsive Media* | `<prefix>mobile_media_id` op `carousel_cards`, `text_image_split_items`, `page_heroes`, `cta_bands`, `media_banners`, `hover_card_grid_items`, `homepage_hero`, `detail_section_images` en `review_block_items` (die laatste zet de editor nooit, maar de tak staat er, zodat het contract overal hetzelfde is). Alleen een afbeelding (`MediaItem::isPicture()`), zonder oud pad en zonder eigen alt-tekst: het is dezelfde inhoud als het beeld op een groot scherm. Eén tak per tabel in `ContentBlockMediaUsage`, met *(telefoon)* in het label; `ON DELETE RESTRICT` |

**Nog op een eigen pad**, ongewijzigd en werkend:

- de afbeelding van een **Portfolio-item van vóór de bibliotheek**
  (`portfolio_gallery_items.media_id` NULL, bestand van
  `PortfolioImageProcessor`): het blijft staan en wordt getoond tot een
  redacteur een andere afbeelding kiest; dan gaat het oude eigen bestand weg.
  Zo ook een galerijfoto van een projectpagina van vóór Portfolio 2.0
  (`portfolio_item_images.media_id` NULL): hij blijft staan tot een redacteur
  hem uit de galerij haalt, en dan gaat zijn eigen bestand mee. Een nieuwe
  galerijfoto is altijd een bibliotheekitem (`MODULES.md`, "Portfolio");
- de **deel-afbeelding van een product of collectie van vóór de bibliotheek**
  (`og_media_id` NULL, `og_image_path` gevuld): blijft tot er een andere wordt
  gekozen of hij met *Deel-afbeelding verwijderen* bewust weg gaat;
- het **voorbeeldcanvas van een personalisatieweergave**
  (`admin/_personalization_builder.php`, `preview_image`): de coördinaten van
  de gebieden op die weergave horen bij precies dat ene beeld, en het is een
  werkvlak van de configurator, geen herbruikbaar sitebeeld. Een eigen upload,
  bewust; een kandidaat voor later;
- lettertypes van de personalisatie en bestanden van formulierinzendingen:
  geen media-items (zie de tabel bovenaan);
- eigen lettertypen van de Font Library (Vormgeving → Lettertypen,
  `assets/fonts/library/`, `THEMING.md`): een lettertype is een onderdeel
  van de vormgeving met een rol (koppen, tekst), geen beeld met een alt-tekst,
  en heeft daarom een eigen bibliotheek met eigen gebruiksbescherming. Een
  latere iconenbibliotheek hoort juist wél bij de media (SVG-iconen door
  `SvgSanitizer`), zie `THEMING.md`, "Font Awesome en een latere Icon Library".

`Tests\Service\MediaPickerContractTest` houdt de lijst van CMS-schermen met
een eigen bestandskiezer gesloten.

**De Shop** is aangesloten zoals hierboven beschreven stond: `product_images.media_id`
en `collections.media_id` naast het bestaande `image_path` (dat wordt
meegeschreven), plus `ShopMediaUsage` via `ShopModule::mediaUsageProviders()`.
Een product kiest zijn afbeeldingen met de kiezer in verzamelmodus
(`data-media-picker-collect`, zie *De mediakiezer*); een collectie met een
gewoon veld. Productafbeeldingen van vóór de bibliotheek houden hun eigen pad
en blijven werken; ze worden niet overgenomen en niet verplaatst. De Shop
verwijdert nooit een bibliotheekbestand: een product, collectie of variant
weghalen haalt alleen de verwijzing weg.

Sinds Media Library 2.0 komt ook de **deel-afbeelding van een product en een
collectie** uit de bibliotheek: `products.og_media_id` en
`collections.og_media_id` (migratie `20260925140000`, `RESTRICT`), met
`og_image_path` meegeschreven zodat `ProductSeo` en `CollectionContent`
lezen wat ze altijd lazen. De kiezer staat in deel-afbeeldingsmodus
(`MediaType::SOCIAL_IMAGE`, geen SVG). Wat een formulier bedoelt, staat op één
plek (`shop_share_image_choice()`, `api/admin/_shop_share_image.php`): een
bibliotheek-id is die afbeelding; *Wissen* haalt een bibliotheekafbeelding
weg; een oude eigen upload blijft staan tot er een andere wordt gekozen of
*Deel-afbeelding verwijderen* is aangevinkt, en alleen zo'n eigen bestand wordt
daarna van de schijf gehaald. `ShopMediaUsage` meldt het gebruik als
*"Deel-afbeelding van product: &lt;naam&gt;"* en *"… van collectie"*.

**Portfolio** kiest de afbeelding van een item sinds Media Library 2.0 uit de
bibliotheek (`portfolio_gallery_items.media_id`, migratie `20260925130000`,
`RESTRICT`): `image_path` en `thumbnail_path` worden meegeschreven met het pad
en de thumbnail van het item (of het pad zelf voor een bestand zonder
thumbnail), dus de galerij, het blok *Projecten* en elke andere lezer tonen
hetzelfde als voorheen. Het editorscherm heeft geen bestandskiezer meer; een
nieuw bestand gaat via *Nieuw bestand uploaden* in de kiezer de bibliotheek in.
Een item verwijderen haalt alleen de verwijzing weg. De alt-tekst is gelaagd:
de eigen alt-tekst van het item in de taal van de bezoeker, anders die van het
bibliotheekitem (`PortfolioGalleryContent::itemAlt()`). `PortfolioMediaUsage`
(via `PortfolioModule::mediaUsageProviders()`) meldt *"Portfolio: &lt;titel&gt;"*
aan wie `portfolio.manage` heeft.

Sinds **Portfolio 2.0** komt ook de **galerij van een projectpagina** uit de
bibliotheek: `portfolio_item_images.media_id` (migratie `20260925160000`,
`RESTRICT`, pad en thumbnail meegeschreven), gekozen met de kiezer in
verzamelmodus (`data-media-picker-collect`) op het item zelf. Een foto uit de
galerij halen haalt alleen de koppeling weg. `PortfolioMediaUsage` meldt een
galerijfoto als *"Portfolio: &lt;titel&gt; (galerij)"*, naast het gebruik als
hoofdafbeelding, zodat de bibliotheek beide weigert te verwijderen. De alt-tekst
is gelaagd zoals bij de hoofdafbeelding: een eigen alt van een oude foto
(`portfolio_item_image_translations`), anders die van het bibliotheekitem.

**Een tabel die na de bibliotheek is gemaakt heeft geen `image_path`-tweeling.**
`blog_posts` heeft alleen een `media_id`: de oude padkolommen zijn een
historische terugval voor rijen van vóór de bibliotheek, en een nieuwe tabel
heeft die historie niet. Zo'n tabel heeft ook geen eigen alt-veld, want de
gelaagde alt-tekst bestaat om bestaande onderschriften te bewaren — nieuwe
inhoud gebruikt gewoon die van het item. Hetzelfde geldt voor een bestaande
tabel die pas ná de bibliotheek een afbeelding kreeg: `page_heroes` had er nooit
een, en heeft dus ook alleen een `media_id`. Sinds Paginakop 2.0 kan dat beeld
ook náást de tekst staan, en daar is het inhoud: dan geldt de gewone gelaagde
alt-tekst (`image_alt` als woord van het blok, leeg is die van het item), met
`media_alt_field()` in de editor en `BlockImage::ownAlt()` in het endpoint.

## Een nieuw blok aansluiten

1. Migratie: `media_id INT UNSIGNED NULL` met een foreign key naar `media`,
   `ON DELETE RESTRICT`.
2. Repository: `media_id` meeschrijven, en `image_path` met het pad van het
   gekozen item (zolang die kolom bestaat).
3. Inhoudsklasse: `BlockImage::fromOwner($row, BlockLocalization::text(<tabel>, $id, 'alt'))`
   in plaats van het pad zelf lezen. Je krijgt `image_path`, `alt` (in de
   taal van het verzoek, anders die van het media-item), `width`, `height` en
   `media_id`. De alt-tekst declareer
   je als plat veld in `translatableFields()`.
4. Editor: `media_picker_field()`, plus één keer `media_picker_modal()` en
   `media_picker_script()`.
5. Endpoint: `BlockImage::fromRequest($_POST['media_id'])`.
6. Partial: `BlockImage::dimensionAttributes($image)` in de `<img>`.
7. Voeg een tak toe aan `ContentBlockMediaUsage` (Core) of aan de provider
   van je module, anders meldt de bibliotheek jouw blok als "niet gebruikt".
   Elke `MediaUsage` noemt de permissie van het scherm dat die plek bewerkt
   (zie *Wie mag lezen wáár*).
8. `deleteFiles()` van het blok blijft **leeg**: een gedeeld bestand is niet
   van jou.
9. Snijdt het blok zijn beeld bij in een kader, sluit de plek dan ook aan op
   *Responsive Media* ("Een plek aansluiten").

## Modulegrens

Core Media weet van media, en van niets anders — geen product, geen
personalisatie-preview, geen orderbijlage, geen collectie. Een module mag
media *kiezen*, gebruik *melden* en eigen metadata houden (volgorde,
uitsnede-intentie). `Tests\Service\MediaBoundaryTest` faalt zodra een
Core-mediabestand een Shop-klasse of een Shop-tabel noemt.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast   # MediaBoundaryTest
docker compose exec php_test php vendor/bin/phpunit --testsuite cms    # de rest
docker compose exec php_test php vendor/bin/phpunit --group migration-backfill
```

| Bestand | Wat het bewaakt |
|---|---|
| `MediaBoundaryTest` | Rechten, guards (ook de volgorde in elk schrijvend endpoint van het scherm: details, verplaatsen, mappen, een selectie verwijderen), CSRF, "de kiezer stuurt alleen een id", modulegrens, en het scherm: de gedeelde bestandskiezer, zoekveld, select en checkboxes, werken met en zonder JavaScript, geen inline handlers, scripts zonder markup uit strings en zonder zinnen, en nergens een bestand hernoemen op de schijf; en dat het itemscherm en het verwijderen van een selectie plekken alleen via `VisibleMediaUsages` noemen. Geen database |
| `MediaLibraryTest` | Upload, wat er geweigerd wordt (op naam én op inhoud), meerdere bestanden en een gemengde batch, namen, naam en alt-tekst samen (`updateDetails()`: beide of geen van beide), zoeken en filteren, alt-tekst, ontdubbelen, verwijderen (ook een selectie), ontbrekend bestand |
| `MediaPickerContractTest` | De kiezer: één handeling, één flow (preventDefault, één binding, het bestandsveld verborgen en in geen label, `openFileDialog()` de enige `click()`), alleen *Selecteren* schrijft, de selectie overleeft map en zoeken, en de gesloten lijst van CMS-schermen met een eigen bestandskiezer. Geen database |
| `MediaFolderTest` | Mappen maken, hernoemen (ook een naam die geen pad is), verwijderen zonder items te verliezen, verplaatsen, een map die niet bestaat, de limiet, bladeren per map samen met zoeken |
| `MediaLibraryTwoHttpTest` | Over echte HTTP: de ene opslag van naam en alt-tekst (PRG, geweigerd met getypte waarden terug, JSON voor snel bewerken), escaping van naam, alt en mapnaam, mappen en verplaatsen, een echte multipart-upload in de open map, de lijst van de kiezer per map, Portfolio uit de bibliotheek, en de guards van elk nieuw schrijvend endpoint |
| `ShopShareImageChoiceTest` | Wat een product- of collectieformulier met zijn deel-afbeelding bedoelt (`shop_share_image_choice()`) |
| `Tests\Install\MediaLibraryTwoMigrationTest` | De drie migraties van Media Library 2.0 op een verse en een bijgewerkte database: alles in *Geen map*, niets oud naar de bibliotheek gewezen, een map weg zonder zijn items, en een tweede run verandert niets |
| `MediaUsageTest` | Gebruik afgeleid uit echte blokinstanties, de verwijderregel — ook voor een selectie — en dat een hernoemd item overal blijft werken. En wie mag lezen wáár: een pagina alleen met `pages.manage`, het logo alleen met `settings.manage`, een selectie volgens dezelfde regel, en alles voor een volledig bevoegde beheerder |
| `MediaUsageAccessTest` | Hetzelfde over echte HTTP, met een eigen `php -S`: de JSON van een selectie, een geweigerde losse verwijdering en het itemscherm noemen geen pagina voor wie die niet mag openen, en wel voor wie dat mag. Slaat zichzelf over als de server niet start |
| `MediaAdoptionTest` | Wat de overnamemigratie beloofde, op een wegwerpdatabase met eigen oude afbeeldingsrijen (`migration-backfill`), en de namen die een upgrade meekrijgt |
| `BrandingTest` | Media wint van het pad, en het pad blijft de terugval |
| `Tests\Blog\BlogMediaAndSettingsTest` | de eerste module-provider: gebruik melden, niet kunnen verwijderen, niets melden met de module uit, en de berichttitel alleen noemen voor wie berichten mag bewerken (`blog.manage`) |
| `Tests\Service\Media\ResponsiveImageTest` | Responsive Media: de waarde zonder database — onmogelijke waarden, een rij met vreemde cellen, de eigen kolommen van een plek, wat een formulier mag veranderen, wat de partial krijgt |
| `ResponsiveImageRenderTest` | De markup: zonder telefooninstellingen de oude `<img>`, met een telefoonafbeelding één `<picture>` met één `<source>` en de alt-tekst één keer, custom properties voor punt en weergave, alles ge-escaped. Geen database |
| `ResponsiveMediaContractTest` | Eén breekpunt, één markup, één stylesheet eerst, en dezelfde acht plekken in repository, migraties en gebruik; niets leest de oude sleutelkolommen. Geen database |
| `ResponsiveImageEditorHttpTest` | Het veld over echte HTTP: het scherm in het Nederlands en Engels, opslaan en de `<picture>` op de pagina, geweigerde telefoonafbeeldingen, klemmen, *Gebruik desktopafbeelding*, een telefoonafbeelding als gebruik, de beeldverhouding van de carrousel, een Hover-kaart en de Homepage-hero |
| `Tests\Install\ResponsiveMediaMigrationTest` | De twee migraties van Responsive Media op een verse en een bijgewerkte database: elke sleutel wordt precies zijn punt, de rest van een rij blijft, geen telefooninstelling op een bestaande rij, `RESTRICT` overal, een tweede run verandert niets (`migration-backfill`) |

Uploaden in een test gaat via `Tests\Support\TestMediaUploader`: de échte
uploader met één naad open (`is_uploaded_file`/`move_uploaded_file` kunnen
niet waar zijn voor een bestand dat een test schreef). Alle overige regels —
headercontrole, willekeurige naam, limiet, map, optimalisatie — zijn de
echte.

## Handmatig controleren

De suite heeft geen browser: de tests bewaken de regels, de markup en de
endpoints, niet wat er op een klik gebeurt. Loop na een wijziging aan
`admin/media.php` of een van zijn scripts dit na, als beheerder met
`media.manage`:

1. Eén afbeelding kiezen: hij staat in *Nieuwe bestanden* met voorbeeld,
   naam, type en grootte.
2. Meerdere afbeeldingen tegelijk kiezen.
3. Een bestand uit de lijst halen: de focus gaat naar het volgende bestand.
4. Eén bestand naar het vak slepen: het vak zegt dat je kunt loslaten. Een
   klik naast de knop opent geen bestandsdialoog.
5. Meerdere bestanden slepen.
6. Een ongeldig bestand proberen (`.exe`, een tekstbestand dat `.png` of
   `.mp4` heet, een SVG met een `<script>`): een reden bij dat bestand, en het
   gaat niet mee. Een gewone SVG, een MP4 en een WEBM gaan wel mee; de video
   krijgt een video-icoon.
7. Een bestand groter dan de grens proberen.
8. *Toevoegen aan bibliotheek*: wat lukt, verdwijnt uit de lijst.
9. De nieuwe items staan meteen bovenaan het raster, zonder herladen.
10. Filteren op *Alles*, *Afbeeldingen* en *Video*.
11. Zoeken: het raster ververst terwijl je typt, en de cursor blijft staan.
12. Zoeken en filteren samen, en daarna *Vorige* in de browser.
13. Een naam aanpassen in *Nieuwe bestanden* vóór het toevoegen.
14. *Bewerken* op een kaart: naam en alt-tekst in één dialoog; een bestaande
    naam wordt geweigerd bij het veld, de extensie blijft staan, en na
    *Opslaan* toont de kaart de nieuwe waarden.
15. Meerdere kaarten selecteren: de teller en *Verwijderen* verschijnen, en
    *Alles op deze pagina selecteren* werkt.
16. *Verwijderen* en dan *Annuleren* (of Escape): er gebeurt niets, en de
    focus staat weer op de knop. Op een item doet *Verwijderen* hetzelfde in
    de gedeelde dialoog van het CMS, met de naam van het bestand in de vraag.
17. *Verwijderen* en dan *Definitief verwijderen*: de melding zegt wat er weg
    is.
18. Een gebruikt bestand in de selectie: de dialoog noemt het, het blijft
    staan, en de melding zegt waar het gebruikt wordt. Log daarna in als
    beheerder met alleen `media.manage`: de melding en het itemscherm zeggen
    dan alleen *dat* het gebruikt wordt, niet op welke pagina.
19. Op een telefoon: kiezen via de knop, twee kaarten naast elkaar, de
    selectiebalk blijft in beeld, en niets steekt buiten het scherm.
20. In een licht (*classic*) en een donker thema, en alles ook met alleen het
    toetsenbord.
21. Een map maken, hernoemen, items erheen verplaatsen, zoeken binnen de map,
    en de map verwijderen: de items staan daarna in *Geen map*.
22. *Raster* en *Lijst*: dezelfde resultaten, de keuze blijft na herladen, en
    op 375px steekt niets buiten beeld.
23. Het itemscherm: naam en alt-tekst in één formulier, bewaard via de
    opslagbalk; een geweigerde naam houdt beide getypte waarden vast.
24. De kiezer op een product, een collectie en een portfolio-item: *Kies uit
    mediabibliotheek* of *Afbeelding toevoegen* opent alleen de bibliotheek;
    *Nieuw bestand uploaden* opent het bestandsvenster één keer; het nieuwe
    bestand staat daarna in de bibliotheek en is geselecteerd; *Annuleren*
    verandert niets, *Selecteren* zet precies de keuze in het veld.
25. Responsive Media, op een carrouselkaart: sleep de afbeelding in het kader,
    kies een van de negen punten en gebruik de schuiven met de pijltjestoetsen;
    na opslaan en herladen staat alles er nog. *Op een telefoon*: *Eigen
    afbeelding*, een eigen punt, *Hele afbeelding*. Op de pagina toont 1440px
    de desktopafbeelding en 375px de telefoonafbeelding, en in het
    netwerkpaneel is er per breedte één download.
26. Kies een telefoonafbeelding en probeer die daarna in de bibliotheek te
    verwijderen: de melding noemt de plek met *(telefoon)*.

## Bewust niet gebouwd

Submappen (zie *Waarom geen submappen*), tags/categorieën, andere
bulkbewerkingen dan een selectie verplaatsen of verwijderen, uitsnede-editor
(een focuspunt per plek wel: *Responsive Media*), een focuspunt per dia van een
reeks, transformaties-UI, een `srcset`-framework met gegenereerde formaten,
videotranscodering,
posterframes, audio, PDF's/documenten, objectopslag, CDN, EXIF-browser,
AI-alt-tekst, OCR, stockfoto's, mapbomen met slepen, facetzoeken, een leesbaar
publiek adres per item (zie *Publieke adressen*), en detectie van *gelijkende*
afbeeldingen.

De waarde van V1 is: centrale identiteit, hergebruik, metadata, veilig
verwijderen. 2.0 voegt daar één consistente kies- en uploadflow, virtuele
mappen, één opslag voor naam en alt-tekst en een lijstweergave aan toe. Meer
is er niet, en dat is het punt.
