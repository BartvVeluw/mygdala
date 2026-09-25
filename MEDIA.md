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
zijn **virtueel**: zie *Mappen*. Er zijn **geen** tags, EXIF, focuspunt of
transformaties. Zie "Bewust niet gebouwd" onderaan. Een focuspunt bestaat alleen op de plek die
een beeld toont (een carrouselkaart, een item van Tekst met afbeelding, de
Paginakop), nooit op het item in de bibliotheek. Alle drie gebruiken dezelfde
negen punten (`App\Service\Media\ImageFocus`) en hetzelfde veld in de editor
(`media_focus_field()` in `admin/_image_focus.php`, met
`admin/assets/image-focus.js`); elk scherm geeft het voorbeeld alleen de vorm
van zijn eigen plek (`--admin-focus-frame-ratio`).

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
| `ContentBlockMediaUsage` | `pages.manage` |
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
de lijst controleert elk id opnieuw.

## Wat er in V1 is aangesloten

| Feature | Hoe |
|---|---|
| Logo, tweede logo, favicon, standaard deel-afbeelding | `site_settings.*_media_id`, oude `*_path` als terugval |
| Deel-afbeelding per CMS-pagina | `pages.og_media_id` |
| Tekst met afbeelding (`text_image_split`) | `text_image_split_items.media_id`, één per item en optioneel; elk item telt als gebruik, ook twee items van één blok met hetzelfde beeld |
| Detailsectie (`detail_section`) | `detail_sections.main_media_id` + `detail_section_images.media_id` |
| Kaarten-carrousel (`card_carousel`) | `carousel_cards.media_id` |
| Paginakop (`page_hero`) | `page_heroes.media_id`, zonder oud pad: een paginakop had nooit een afbeelding. Eigen alt-tekst per taal (`image_alt` in `block_translations`) alleen voor een beeld náást de tekst; een beeld áchter de tekst is versiering (`alt=""`). *Geen afbeelding* maakt de verwijzing leeg, zodat het item niet meer als gebruikt telt |
| Uitgelichte afbeelding en deel-afbeelding van een blogbericht | `blog_posts.featured_media_id`, `blog_posts.og_media_id` — een module, dus via `BlogModule::mediaUsageProviders()` |
| Eigen icoon van een kaart in *Kenmerken in kaartjes* (`feature_grid`) | `feature_grid_items.icon_media_id`, alleen als `icon_key = custom`; geen oud pad en geen alt-tekst, want het icoon is versiering (`aria-hidden`, `alt=""`): de titel en tekst van de kaart dragen de betekenis. Kiest de kaart weer een standaardicoon of *Geen*, dan wordt de verwijzing leeggemaakt en telt het item niet meer als gebruikt |
| Portfolio-item (module) | `portfolio_gallery_items.media_id`, oude `image_path`/`thumbnail_path` meegeschreven; eigen alt-tekst per taal, anders die van het item |
| Deel-afbeelding van product en collectie (Shop) | `products.og_media_id`, `collections.og_media_id`, oude `og_image_path` meegeschreven |
| Homepage-hero: afbeelding en video | `homepage_hero.media_id` (met eigen alt-tekst per taal) en `homepage_hero.video_media_id`, oude `image_path` / `video_path` als terugval. Het videoveld is de eerste videokiezer (`media_picker_field(…, MediaType::VIDEO)`) |

**Nog op een eigen pad**, ongewijzigd en werkend:

- de afbeelding van een **Portfolio-item van vóór de bibliotheek**
  (`portfolio_gallery_items.media_id` NULL, bestand van
  `PortfolioImageProcessor`): het blijft staan en wordt getoond tot een
  redacteur een andere afbeelding kiest; dan gaat het oude eigen bestand weg.
  De foto's van de oude projectpagina (`portfolio_item_images`) worden niet
  meer bewerkt en ook niet gemigreerd: een projectpagina is nu een gewone
  pagina, en die haalt haar beeld uit deze bibliotheek (`MODULES.md`);
- de **deel-afbeelding van een product of collectie van vóór de bibliotheek**
  (`og_media_id` NULL, `og_image_path` gevuld): blijft tot er een andere wordt
  gekozen of hij met *Deel-afbeelding verwijderen* bewust weg gaat;
- het **voorbeeldcanvas van een personalisatieweergave**
  (`admin/_personalization_builder.php`, `preview_image`): de coördinaten van
  de gebieden op die weergave horen bij precies dat ene beeld, en het is een
  werkvlak van de configurator, geen herbruikbaar sitebeeld. Een eigen upload,
  bewust; een kandidaat voor later;
- lettertypes van de personalisatie en bestanden van formulierinzendingen:
  geen media-items (zie de tabel bovenaan).

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

## Bewust niet gebouwd

Submappen (zie *Waarom geen submappen*), tags/categorieën, andere
bulkbewerkingen dan een selectie verplaatsen of verwijderen, uitsnede-editor,
transformaties-UI, focuspunten, een `srcset`-framework, videotranscodering,
posterframes, audio, PDF's/documenten, objectopslag, CDN, EXIF-browser,
AI-alt-tekst, OCR, stockfoto's, mapbomen met slepen, facetzoeken, een leesbaar
publiek adres per item (zie *Publieke adressen*), en detectie van *gelijkende*
afbeeldingen.

De waarde van V1 is: centrale identiteit, hergebruik, metadata, veilig
verwijderen. 2.0 voegt daar één consistente kies- en uploadflow, virtuele
mappen, één opslag voor naam en alt-tekst en een lijstweergave aan toe. Meer
is er niet, en dat is het punt.
