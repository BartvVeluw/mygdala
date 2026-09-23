# De paginabouwer — werkdocument

Hoe een redacteur een pagina vult, en waarom die schermen zo in elkaar
zitten: de **tabbladen** die het paginascherm in drie stukken knippen, de
**inklapbare blokrijen** in de blokkenlijst, de **blokkenkiezer** waarmee je
een contentblok toevoegt, de **Contentblokken-bibliotheek** die uitlegt wat elk
blok doet en het met voorbeeldinhoud laat zien, en de **opslagbalk** die op
elk blok-editorscherm onderaan meeschuift.

Wat een blok zélf is — tabel, repository, inhoudsklasse, partial, editor,
endpoint, definitie — staat in [`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md). Dit
document gaat alleen over de schil eromheen. Wijkt de code af van dit
document, dan heeft de code gelijk.

## De drie stukken in één oogopslag

| Onderdeel | Bestanden |
|---|---|
| Tabbladen (herbruikbaar) | `admin/_admin_tabs.php`, `admin/assets/admin-tabs.js`, CSS in `admin/assets/admin.css` (`.admin-tabs*`). Gebruikt door `admin/page.php` en `admin/settings.php` |
| Inklapbare rijen (herbruikbaar) | `admin/_admin_collapse.php`, `admin/assets/admin-collapse.js`, CSS `.admin-collapse*`. Gebruikt door de blokkenlijst op `admin/page.php` |
| Presentatie-metadata van een blok | `src/Service/Blocks/BlockDefinition.php` (`label()`, `description()`, `category()`, `icon()`, `preview()`, `useCases()`, `sampleContent()`, `renderSample()`), `BlockCategories.php`, `BlockPreview.php`, `BlockSamples.php` |
| Blokkenkiezer | `admin/_block_picker.php`, `admin/assets/block-picker.js`, gebruikt door `admin/page.php` |
| Schematische tekening en pictogram | `admin/_block_visual.php`, CSS in `admin/assets/admin.css` (`.admin-block-visual`, `.admin-bp--*`) |
| Bibliotheek | `admin/content-blocks.php` (menu-item `content_blocks` in `App\Service\AdminNavigation`), `admin/_block_library.php`, `admin/assets/block-library.js`, CSS `.admin-catalogue-*` en `.admin-block-preview*` |
| Voorbeeld van één blok | `admin/block-preview.php`, `assets/css/block-preview.css`, `assets/js/block-preview.js`, `assets/images/block-preview/sample.svg` |
| Opslagbalk | `admin/_save_bar.php`, `admin/assets/save-bar.js`, aangeroepen door `admin/page.php`, elke blok-editor, `admin/settings.php`, `admin/shop-settings.php` en de formulier- en veldeditor van Formulieren (`admin/form.php`, `admin/form-field.php`) |
| Tests | `tests/Service/BlockPresentationTest.php`, `tests/Service/BlockPickerTest.php`, `tests/Service/BlockLibraryScreenTest.php`, `tests/Service/BlockSampleContractTest.php`, `tests/Service/BlockPreviewContractTest.php`, `tests/Service/BlockPreviewAccessTest.php`, `tests/Service/AdminEditorNavigationTest.php`, `tests/Service/PageBuilderScreenTest.php` |

## Tabbladen op de paginabouwer

```text
[ Inhoud ] [ Pagina ] [ SEO ]
```

**Inhoud** is de blokkenlijst plus *Contentblok toevoegen* en staat vooraan,
want daarvoor komt een redacteur. **Pagina** is Algemeen (titel, het webadres
achter *Webadres wijzigen*, status) plus *Verwijderen*. **SEO** is de
SEO-kaart. Geen veld staat op twee tabbladen.

**Pagina en SEO zijn twee panelen van één `<form>`.** Niet uit gemakzucht:
`api/admin/update-page.php` leest titel, slug, status én elk metaveld uit
hetzelfde verzoek en schrijft ze allemaal. Twee formulieren zouden dus elk
leegmaken wat het andere draagt — precies de partiële-POST-fout die dit
project al eens gemaakt heeft. Tabbladen mogen het *scherm* opdelen, niet de
opslag. Daarom staat onder allebei dezelfde knop *Instellingen opslaan*, met
één regel eronder die zegt dat hij beide tabbladen bewaart.

**Verwijderen is een tweede Pagina-paneel.** Die kaart heeft een eigen
`<form>` en kon dus niet ín het instellingenformulier staan. Een tab mag meer
dan één paneel openen; `aria-controls` noemt ze allebei.

**Een nieuw webadres wordt eerst bevestigd, binnen hetzelfde formulier.** Het
adres staat als link op het tabblad; het veld zit achter *Webadres wijzigen*,
een `<details>` in de stijl van de inklapbare rijen, met erboven waar de
pagina gebruikt wordt (`PageUsage`). Verandert een opslag het adres, dan
schrijft `update-page.php` niets en komt het scherm terug met bovenaan het
tabblad Pagina een bevestigingskaart: huidig en nieuw adres, en wat er met het
oude gebeurt. Die kaart is geen tweede formulier. De velden eronder bevatten
al wat de redacteur typte, dus bevestigen is hetzelfde formulier opnieuw
versturen, met het bevestigde adres erbij. Het hele verhaal, redirect
inbegrepen, staat in [`REDIRECTS.md`](REDIRECTS.md).

**Welk tabblad opengaat**, van specifiek naar algemeen: wat het scherm eist
(een afgekeurde opslag opent Pagina, want de foutmelding gaat over dat
formulier) → wat er in de URL staat (`#blok-42` opent Inhoud) → wat deze
redacteur op déze pagina het laatst open had (`sessionStorage`, gesleuteld op
groep + pagina-id) → de standaard. Een opslag die lukt herlaadt het scherm en
komt zo terug op hetzelfde tabblad.

## Voorbeeld bekijken

Een pagina in Concept heeft nog geen publiek adres: `pagina.php` en de
templates van de vaste pagina's geven er een 404 op, en dat blijft zo. Toch
wil een redacteur zien wat hij bouwt. Daarom staat bovenaan het paginascherm
bij een concept **Voorbeeld bekijken** in plaats van *Bekijk pagina*.

Die knop opent `admin/page-preview.php?id=<id>`: een adminscherm achter
dezelfde toegangscontrole als de paginabouwer (`pages.manage`). Het rendert de
pagina uit precies de onderdelen van een publiek template: de `<head>` van de
pagina, de assets van haar blokken, de header, de blokkenlijst, de footer en
de scripts. Een kleine balk linksonder zegt dat het een voorbeeld is en leidt
terug naar het paginascherm. Die balk heeft één eigen stylesheet,
`assets/css/page-preview.css`, dat alleen dit scherm vraagt.

| Vraag | Antwoord |
|---|---|
| Kan een bezoeker een concept zien? | Nee. Het publieke adres kijkt niet naar een parameter, een token of de adminsessie; het voorbeeld bestaat alleen onder `/admin/` |
| En wie niet (meer) ingelogd is? | Die komt op het inlogscherm, zoals bij elk adminscherm. Uitloggen maakt de sessie leeg, en daarmee het voorbeeld |
| Verandert een voorbeeld iets? | Nee. Het scherm leest alleen: status, `updated_at` en sitemap blijven zoals ze waren |
| Zoekmachines, caches, statistiek? | `noindex` in de head, `X-Robots-Tag: noindex, nofollow` en `Cache-Control: private, no-store` op het antwoord, `/admin/` staat uit in `robots.txt`, en de bezoekstatistiek telt `/admin`-paden niet |

Het voorbeeld toont de **opgeslagen** inhoud, niet wat er op dat moment in een
formulier getypt staat. `Tests\Service\PagePreviewAccessTest` bewijst het over
echt HTTP (zie [`TESTING.md`](TESTING.md)); `PagePreviewContractTest` bewaakt
de bron.

## Contentblokken klappen open en dicht

```text
≡ ▸ Tekstblok — Over onze diensten
≡ ▾ Detailsectie — Hout graveren                      [Verborgen]
       [Bewerken →] [Tonen]                        [Verwijderen]
```

Elke rij in de blokkenlijst is een `<details>` met een `<summary>`. Dat is de
hele mechaniek: de browser levert klikken, Enter, Spatie, de tabvolgorde en
de open/dicht-toestand die een schermlezer voorleest, zonder één
`aria`-attribuut van ons. Dezelfde keuze als in de personalisatie-editor.

- **Dichtgeklapt** staat er één regel: de titel die
  `SectionRegistry::instanceLabel()` al maakte (bloknaam + de titel van dít
  blok), plus de badges die zeggen wat er bijzonder is — *Verborgen*,
  *Onderdeel uit*, *Niet ondersteund*, *Beheerd elders*. Geen bloktype hoeft
  dus een eigen samenvatting te verzinnen, en een type zonder titel toont
  gewoon zijn naam.
- **Opengeklapt** komen de toelichtingen en de knoppen: *Bewerken*,
  *Verbergen* of *Tonen*, en *Verwijderen*. Het zijn knoppen uit de familie
  van het CMS (`.admin-btn-secondary`, `.admin-btn-danger`), een maat kleiner
  zodat ze even hoog zijn als *Bewerken*. *Verwijderen* staat apart aan het
  eind: nooit de knop direct naast de bedoelde.
- **Verbergen of Tonen** zegt wat de knop dóét. De toestand zelf staat op de
  rij: een verborgen blok heeft een gestippelde rand, een gedempte naam en de
  badge *Verborgen*, dus nooit alleen een kleur. De knoppen houden hun volle
  contrast.
- **Verwijderen vraagt eerst**, in de gedeelde dialoog van het CMS
  ([`ADMIN-UI.md`](ADMIN-UI.md), *Bevestigen voordat iets weg is*), en noemt
  het blok bij naam. *Annuleren* laat alles staan en zet de focus terug op de
  knop. Het formulier, de CSRF-token en het endpoint zijn niet veranderd.
- **De sleepgreep staat buiten de `<details>`.** Een dichtgeklapte rij
  verslepen is het halve punt van inklappen, dus die greep mag nooit in het
  deel zitten dat wegvouwt.

**Standaard dicht, maar niet vergeetachtig.** Wat je openzette blijft open na
een herlaadbeurt (`sessionStorage`, per pagina). Een blok dat net is
toegevoegd staat open — met het `open`-attribuut in de HTML zelf, dus ook
zonder JavaScript.

**Er is geen collapse-code per bloktype**, en er komt er ook geen:
`AdminEditorNavigationTest` faalt zodra `admin-collapse.js` een bloktype bij
naam noemt.

## Een pagina zonder inhoud

```text
≡ ▸ Paginakop — Over ons
┌──────────────────────────────────────────────┐
│       Je pagina heeft nog geen inhoud.       │
│  Voeg hieronder je eerste contentblok toe.   │
│         [ + Contentblok toevoegen ]          │
└──────────────────────────────────────────────┘
```

Zolang een pagina onder haar kop nog niets heeft, staat onder de lijst niet de
gestippelde toevoegknop maar een uitnodiging: één zin die zegt hoe het zit, en
de knop naar het eerste blok. Een nieuwe *Lege pagina* begint precies zo
([`PAGE-TEMPLATES.md`](PAGE-TEMPLATES.md)).

- **Wat telt als inhoud** beslist `SectionRegistry::hasContentBlocks()`: elk
  blok buiten de categorie *Kop van de pagina*. Een verborgen blok telt mee,
  want het is van de redacteur, en een rij van een type dat nu niet
  geregistreerd is ook, want de lijst toont hem. Er staat geen bloktype bij
  naam in.
- **Het is dezelfde kiezer.** De knop is nog een opener
  (`data-block-picker-open`) van het ene paneel; er is geen tweede kiezer en
  geen tweede manier om toe te voegen. Zodra er een blok is, verdwijnt de
  uitnodiging en staat de gewone knop er weer.
- Mag er op deze pagina helemaal niets bij, dan zegt de uitnodiging dat, in
  plaats van een knop te tonen die een lege kiezer opent.

## Terug naar waar je was

Een blok bewerken gebeurt op een eigen scherm. Zonder hulp kom je daarna
bovenaan een lange pagina terug. Drie kleine dingen samen lossen dat op:

1. **Elke blokrij heeft `id="blok-<id>"`.** `api/admin/add-page-section.php`
   stuurt een nieuw blok daarheen, en `api/admin/toggle-page-section.php`
   stuurt je na *Verbergen*/*Tonen* terug naar de rij die je aanklikte in
   plaats van naar de bovenkant.
2. **De browser onthoudt welk blok je aanraakte.** Klik je op *Bewerken* of op
   een knop van een rij, dan legt `admin-collapse.js` dat blok-id vast als
   het "terugkeerdoel" van deze pagina. De eerstvolgende keer dat het
   paginascherm laadt, opent dat blok, gaat zijn tabblad open en scrolt het in
   beeld — en daarna is het doel verbruikt, zodat een gewoon bezoek later weer
   gewoon bovenaan begint.
3. **De terugkoppeling wijst naar het juiste scherm.** Zeven blok-editors
   linkten terug naar `/admin/pages.php?page=<slug>` — het pagina-*overzicht*,
   dat die parameter negeert. "Terug naar Diensten" kwam dus uit op de lijst
   van álle pagina's. Ze gebruiken nu `PageContent::builderUrl()`.

Er is hiervoor geen enkel opslag-endpoint herschreven en geen redirect een
parameter rijker geworden: het is één `sessionStorage`-sleutel plus twee
ankers.

## Presentatie-metadata

Een blokdefinitie beschrijft sinds deze stap ook zichzelf. Vier methodes zijn
`abstract` — precies zoals de rest van het contract, zodat een nieuw blok ze
niet kan vergeten — en twee hebben een veilige standaard:

| Methode | Wat het is | Verplicht |
|---|---|---|
| `label()` | De naam. Leest `meta()['label']`, zodat er één naam per blok bestaat en de kiezer, de catalogus en de blokkenlijst nooit uit elkaar lopen | via `meta()` |
| `description()` | Eén of twee zinnen in de taal van de redacteur: wat zet dit blok op de pagina? | ja |
| `category()` | Een sleutel uit `BlockCategories` — alleen groepering, verder niets | ja |
| `icon()` | De *binnenkant* van een 24x24 stroke-`<svg>`, net als de sidebar-iconen en de sjabloonkaarten | ja |
| `preview()` | Vormen uit de gesloten lijst in `BlockPreview` — "een kop, dan drie kolommen" | nee (leeg = alleen het pictogram) |
| `useCases()` | Twee tot vier concrete situaties, als korte woordgroepen. De catalogus toont ze onder "Geschikt voor"; de kiezer zoekt erop en toont er één alleen zolang een zoekterm erop past | nee |
| `sampleContent()` | De inhoud waarmee de bibliotheek het blok laat zien: dezelfde vorm die `render()` aan de partial geeft, gemaakt van de woorden in `BlockSamples`. `null` betekent geen voorbeeld | nee, maar `BlockSampleContractTest` eist er een of een gedocumenteerde uitzondering |
| `renderSample()` | Roept met die inhoud dezelfde partial aan als `render()` | hoort bij `sampleContent()` |

**Eén bron, twee schermen.** De kiezer en de catalogus lezen allebei van de
definitie; geen van beide bevat een letter blokbeschrijving.
`Tests\Service\BlockPresentationTest` laat de build falen zodra een van die
twee schermen een beschrijving zelf uitschrijft of een bloktype bij naam
noemt.

**Interne sleutels blijven intern.** `text_image_split` is een databasewaarde,
geen woord voor een redacteur. Er staat dus nergens een type-key op het
scherm, ook niet in de zoektermen van een kaart — dezelfde test bewaakt dat.

**Waarom vormen en geen screenshot.** De frontend is themeerbaar
([`THEMING.md`](THEMING.md)), dus er *is* geen enkele juiste foto van een
blok, en een foto die stilletjes veroudert is erger dan geen foto. Een
definitie noemt daarom vormen uit een gesloten lijst
(`BlockPreview::HEADING`, `::COLUMNS`, `::CAROUSEL`, …) en `admin.css`
tekent die als vakjes in de kleuren van het CMS. Het antwoordt op "wat krijg
ik ongeveer?", niet op "hoe ziet het er precies uit". De tekening is
`aria-hidden`: alles wat ze suggereert staat er in woorden naast.

"Hoe ziet het er precies uit" beantwoordt de bibliotheek, en ook daar met
geen foto: *Voorbeeld bekijken* toont het echte blok in het thema van deze
site, met voorbeeldinhoud (zie hieronder). De tekening blijft op de kaarten
staan, omdat twintig echte blokken in een raster te zwaar en te klein zijn om
tussen te kiezen.

**Categorieën** staan in `BlockCategories`, gesloten en op volgorde: *Kop van
de pagina*, *Content*, *Beeld & media*, *Actie & interactie*, *Shop*. Een
categorie zonder blokken verdwijnt vanzelf van het scherm — dat is precies
wat er met *Shop* gebeurt zodra de Shop uit staat.

## De blokkenkiezer

```text
[ + Contentblok toevoegen ]        ← altijd direct onder de blokkenlijst
        ↓
Contentblok kiezen                                     ← modaal paneel
[ ⌕ Zoeken…                     ]  [ Kaarten | Lijst ]
[Alles][Content][Beeld & media][Actie & interactie]

CONTENT
┌──────────────────────┐ ┌──────────────────────┐
│ ▤ schets             │ │ ▤ schets             │
│ Tekstblok            │ │ Kenmerken …          │
│ Vrije tekst…         │ │ Kaartjes …           │
│ Content  [Toevoegen] │ │ Content  [Toevoegen] │
└──────────────────────┘ └──────────────────────┘

of, als lijst:
Tekstblok      Vrije tekst met koppen, opsommi…   Content  [Toevoegen]
Kenmerken …    Kaartjes met een pictogram, een…   Content  [Toevoegen]
```

**Eén klik voegt toe.** Elke kaart *is* een
`<button type="submit" name="section_type" value="…">` binnen één gewoon
POST-formulier naar `api/admin/add-page-section.php`. Kiezen en toevoegen
zijn dus dezelfde handeling: er is geen tweede knop meer om daarna op te
drukken. Het endpoint, de CSRF-token en de servervalidatie zijn onveranderd —
de browser doet het versturen, JavaScript niet.

**Wat er op het scherm staat is wat de server accepteert.**
`SectionRegistry::availableDefinitionsForPage()` beantwoordt beide vragen:
de kiezer tekent er zijn kaarten mee en het endpoint valideert het geposte
`section_type` ertegen. Een blok zonder kaart is dus ook een verzoek dat
geweigerd wordt — vaste blokken, blokken die op deze pagina niet mogen,
blokken die hun maximum al bereikt hebben, en blokken van een uitgeschakelde
module.

**Een kaart zegt wat het blok is, niet alles wat erover te zeggen valt.** De
schets, de naam met het pictogram, de beschrijving en onderaan de categorie.
De voorbeelden uit `useCases()` staan er niet vast op. Ze zitten in de
zoektermen, en wie zoekt ziet op de kaart het voorbeeld waar de zoekterm op
paste (*Geschikt voor: voorwaarden en privacyteksten*), zolang die zoekterm er
staat. Een kaart groeit dus precies met de regel die uitlegt waarom hij tussen
de resultaten staat. Alle voorbeelden staan in de catalogus.

**Kaarten of lijst.** Kaarten zijn de standaard. De lijst toont dezelfde
knoppen als rijen: naam, categorie en één regel beschrijving, voor een
redacteur die al weet wat hij wil. De schetsen en de categoriekoppen wijken
daar; een schermlezer hoort de koppen nog wel. Het zijn geen twee lijsten: de
knop zet alleen `data-block-picker-layout` op het paneel, en `admin.css` legt
dezelfde knoppen anders neer. Zoeken, filteren, de tabvolgorde en het toevoegen
zijn in beide weergaven dus dezelfde code, en de markup heeft altijd precies één
submitknop per blok.

`block-picker.js` onthoudt de keuze per browser in `localStorage`, onder
**`mygdalaAdminBlockPickerView`** (`cards` of `list`), naar het voorbeeld van
`mygdalaAdminHelp`. Geen databasekolom: het is een weergavevoorkeur zonder
gevolg voor de inhoud. Is `localStorage` geblokkeerd of leeg, dan zijn het
kaarten, en een ander tabblad van het CMS neemt een nieuwe keuze meteen over.

**Zoeken** gebeurt in het zoekveld van het CMS (`.admin-search`,
[`ADMIN-UI.md`](ADMIN-UI.md)): vergrootglas, hoogte en focusring zoals elk
ander zoekveld in de admin. Het filteren is platte substring-vergelijking over
naam, beschrijving, categorienaam en voorbeeldgebruik, voorgekookt in
`data-block-terms`. Niet fuzzy en niet over de type-key: met dit aantal blokken
is voorspelbaar belangrijker dan slim.

**Na het toevoegen** stuurt het endpoint de redacteur rechtstreeks de editor
van het nieuwe blok in — dat deed het al. Heeft een blok geen eigen editor,
dan keert het terug naar de paginabouwer met `&added=<id>`, en licht die rij
kort op.

**Toetsenbord en aanraking.** Het paneel is een `role="dialog"`: Escape
sluit, focus springt bij openen naar het zoekveld en bij sluiten terug naar de
knop, Tab blijft binnen het paneel. Kaarten, filters en *Kaarten*/*Lijst* zijn
echte knoppen; filters en weergave dragen hun toestand in `aria-pressed`. Er is
geen enkel gegeven dat alleen bij hover verschijnt. Onder 640 px wordt het
paneel schermvullend, staan de kaarten in één kolom en krijgt een lijstrij twee
regels: naam met categorie, dan de beschrijving.

**Zonder JavaScript gaat het paneel niet open.** Dat is dezelfde afspraak als
bij de mediakiezer en het slepen van blokken: het adminpaneel gaat uit van
JavaScript. Er is bewust geen `<noscript>`-dropdown teruggezet, want dat zou
de tweede manier van toevoegen zijn die deze stap juist opruimde.

## De Contentblokken-bibliotheek

`Beheer → Contentblokken` (`admin/content-blocks.php`) is documentatie in het
CMS: per categorie een kaart per blok met de schets, de categorie, de naam met
het pictogram, de beschrijving, "Geschikt voor" en de knop *Voorbeeld
bekijken*. De kaarten drukt `admin/_block_library.php` af, uit dezelfde
definitie als de kiezer. Er staat geen enkel formulier op — het scherm maakt,
wijzigt en verwijdert niets, en is dus ook geen tweede, zwakkere weg naar de
schrijf-endpoints. Blokken die je niet zelf plaatst (de vaste blokken) dragen
het label *Staat er automatisch* en verwijzen naar het beheerscherm dat hun
inhoud wél bezit.

Het menu-item hangt aan `pages.manage`: wie een pagina mag bouwen mag lezen
waar de bouwstenen voor dienen, en ze bekijken.

```text
Kop van de pagina · Paginakop                                  [×]
De kop van een gewone pagina: de paginatitel, met naar keuze …
[ Desktop | Tablet | Mobiel ]   Met voorbeeldtekst, in de opmaak van je eigen site.
┌──────────────────────────────────────────────────────────────┐
│  het echte blok, in een eigen frame                          │
└──────────────────────────────────────────────────────────────┘
```

### Het voorbeeld is het echte blok

*Voorbeeld bekijken* opent een dialoog met het blok zoals een bezoeker het
ziet: de eigen partial van het blok, zijn eigen CSS en JS, na `core.css` en
met het thema van deze installatie ([`THEMING.md`](THEMING.md)). Alleen de
woorden en het beeld zijn voorbeeld. Wie een partial of een blokstylesheet
wijzigt, ziet dat meteen in het voorbeeld; er is geen foto die veroudert en
geen tweede versie van de markup.

| Stuk | Wat het doet |
|---|---|
| `BlockDefinition::sampleContent()` | Geeft inhoud in precies de vorm die `render()` aan de partial geeft, gemaakt van woorden uit `BlockSamples`. Staat in de definitie, dus een blok van een module brengt zijn voorbeeld zelf mee en Core noemt geen modulebloktype |
| `BlockDefinition::renderSample()` | Roept met die inhoud dezelfde partial aan als `render()`. Wat `render()` vooraf opzoekt (de positie van een detailsectie, de toestand van een formulier) krijgt hier een vaste waarde |
| `App\Service\Blocks\BlockSamples` | Álle voorbeeldwoorden, in het Nederlands en het Engels, het ene voorbeeldbeeld (`assets/images/block-preview/sample.svg`), de link `#voorbeeld`, voorbeeld-rich-text en een formulier dat alleen in het geheugen bestaat |
| `admin/block-preview.php?type=<type>` | Het document in het frame: de assets van het blok via `SectionRegistry::collectBlockAssets()` (dezelfde aanroep als voor een pagina), dan het blok |

**Voorbeeldinhoud is geen fallback.** Niets aan de publieke kant leest
`BlockSamples`: een blok zonder opgeslagen rij rendert nog steeds niets
([`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md)). De woorden zeggen alleen iets over
het blok zelf — geen bedrijf, geen product, geen prijs, geen belofte, geen
bereikbaar adres (het e-mailadres staat op `example.com`).

**Waarom de partials alleen renderen.** Vier partials zochten vroeger zelf op
wat ze toonden: het formulier en de contactgegevens (`form`, `contact_form`),
de collecties (`shop_collections`) en de ankers (`quicknav`). Dat opzoeken
doet nu de `render()` van hun definitie, zodat de partial zijn inhoud als
argument krijgt en het voorbeeld dezelfde partial kan gebruiken. Op de site
verandert er niets.

### Een blok zonder voorbeeld

Het *Productoverzicht* van de Shop heeft geen voorbeeld. Zijn kaarten staan
niet in zijn markup: `assets/js/shop/shop.js` bouwt ze uit `/api/products.php`,
dus met voorbeeldinhoud valt er niets te tonen, en echte producten zouden het
voorbeeld afhankelijk maken van de winkel. De dialoog toont dan de schets
groot met één zin uitleg, nooit een leeg frame.
`BlockSampleContractTest::WITHOUT_SAMPLE` noemt het en zegt waarom; een nieuw
blok zonder voorbeeld laat de build falen tot het daar ook staat.

### Wat een voorbeeld nooit doet

| Vraag | Antwoord |
|---|---|
| Wie ziet het? | Alleen wie ingelogd is en `pages.manage` heeft, net als de paginabouwer. Het adres staat onder `/admin/`; niets publieks leest een voorbeeldparameter |
| Welk blok? | Het type raakt of mist een sleutel van het register. Onbekend, misvormd, zonder voorbeeld of van een uitgeschakelde module: 404 |
| Schrijft het iets? | Nee. Er wordt geen pagina en geen blokrij gelezen en niets geschreven; alleen het thema en de talen van de site worden gelezen, zodat het blok eruitziet als op de site. `BlockPreviewAccessTest` vergelijkt een checksum van elke tabel voor en na |
| Zoekmachines, caches, statistiek? | `noindex` in de head, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: private, no-store`. Geen header, dus geen paginaweergave (`PageViewTracker`), en geen cookiebanner over het blok |
| Kan een formulier iets versturen? | Nee, vier keer niet: de `Content-Security-Policy` zegt `form-action 'none'`, het frame heeft geen `allow-forms`, `assets/js/block-preview.js` houdt de submit tegen vóór `form.js` hem met `fetch()` zou versturen, en het voorbeeldformulier heeft een sleutel (`BlockSamples::FORM_KEY`) die geen opgeslagen formulier kan hebben |
| Kan een link ergens heen? | Nee. Elke voorbeeldlink wijst naar `#voorbeeld`, de klik wordt tegengehouden, en het frame mag de CMS eromheen niet navigeren en geen venster openen |
| Kan een ander de pagina inlijsten? | Nee: `frame-ancestors 'self'` |
| Kan een script in het voorbeeld bij het CMS? | Nee. Het frame heeft `sandbox="allow-scripts"` en bewust **geen** `allow-same-origin`, dus het voorbeeld draait in een eigen, ondoorzichtige origin: geen toegang tot het CMS-scherm eromheen, tot de sessiecookie of tot de opslag van het CMS. `BlockLibraryScreenTest` faalt als `allow-same-origin` terugkomt |

Wat wél draait, zijn de scripts van het blok zelf: een carrousel draait, een
galerij filtert en vergroot, een woordenband schuift. Geen blok heeft daar de
origin van het CMS voor nodig: stylesheets, beelden en scripts laden als
gewone verzoeken, en waar een blokscript `localStorage` gebruikt (de taal in
`core.js`, de winkelwagen in `cart.js`) vangt het een geweigerde opslag al af.

### De dialoog

Een native `<dialog>` met `showModal()`, net als de bevestigingsdialoog
([`ADMIN-UI.md`](ADMIN-UI.md)) maar een eigen: die vraagt ja of nee over een
formulier, deze laat iets groots zien. De kop, de beschrijving en de categorie
komen als tekst van de kaart die hem opende; `admin/assets/block-library.js`
heeft geen woorden van zichzelf.

- **Openen** zet de focus op *Sluiten*. De pagina erachter is inert en Tab
  blijft in de dialoog.
- **Sluiten** kan met de knop, met Escape (ook als de focus ín het voorbeeld
  staat: `block-preview.js` stuurt dan één vast bericht naar het CMS, en
  `block-library.js` neemt het alleen van dát frame aan) en met een klik naast
  de dialoog. Het frame gaat terug naar
  `about:blank`, zodat een carrousel stopt, en de focus staat weer op de knop
  die opende.
- **Desktop, Tablet en Mobiel** veranderen alleen de breedte van het frame
  (vol, 768 px, 390 px). Het frame is een eigen viewport, dus de media queries
  van de site doen de rest. De keuze geldt zolang het scherm open is.
- **Op een telefoon** vult de dialoog het scherm en het frame de hele breedte;
  de breedteknoppen vallen weg.

Waarom een frame en geen markup in het CMS-scherm: `admin.css` en `core.css`
stylen dezelfde elementen, dus het ene op de pagina van het andere breekt
beide, en alleen een frame met een eigen breedte laat de media queries van de
site een tablet of een telefoon zien.

### Modules

De bibliotheek toont `BlockDefinitions::all()`: de blokken van Core en van
de ingeschakelde modules. Staat Portfolio uit, dan zijn *Projecten* en zijn
voorbeeld er niet; staat de Shop uit, dan verdwijnen haar blokken en de kop
*Shop*.

## De opslagbalk

```text
────────────────────────────────────────────
● Niet-opgeslagen wijzigingen      [Opslaan]
────────────────────────────────────────────
```

Vier toestanden, in woorden en niet alleen in kleur: **Alles opgeslagen**,
**Niet-opgeslagen wijzigingen**, **Opslaan…** en **Opslaan mislukt**. De balk
staat vast onderaan, rechts van de sidebar, en op mobiel over de volle
breedte; `.admin-save-bar-spacer` houdt onderaan `<main>` de ruimte vrij, dus
alleen schermen mét balk betalen ervoor.

### Wat de balk NIET is

Geen pagina-breed formulier en geen nieuw endpoint. Elk formulier op deze
schermen post nog steeds naar zijn eigen `api/admin/`-endpoint, met zijn eigen
validatie en zijn eigen PRG-redirect, en elke bestaande Opslaan-knop werkt
onveranderd. `Tests\Service\BlockPickerTest` faalt als er ooit een
`/api/admin/`-URL in `save-bar.js` verschijnt.

### Welke formulieren de balk bewaakt

Elk POST-formulier binnen `<main class="admin-main">` dat minstens één
bewerkbaar veld heeft, behalve:

- `.admin-inline-form` — de eenknopsformulieren voor verbergen, verplaatsen
  en verwijderen; daar valt niets in te typen;
- alles met `data-no-dirty-track` — vandaag het formulier van de blokkenkiezer,
  want een blok kiezen is geen wijziging die je "later opslaat".

De begrenzing tot `<main>` is geen detail: **het uitlogformulier in de sidebar
is ook een POST-formulier**, en een opslagknop die dat zou versturen logt de
redacteur midden in een bewerking uit.

### Wat "gewijzigd" betekent

Een vlag per formulier, gezet door `input` en `change` erin, en pas gewist na
een geslaagde opslag. Bewust géén vergelijking met de oorspronkelijke
waarden: de rich-text-editor herschrijft zijn eigen veld zodra hij laadt, dus
een waardevergelijking zou elke net geopende editor als "gewijzigd"
aanmerken — de omgekeerde fout, en de gevaarlijke. Gevolg: een letter typen en
weer wissen laat de vlag staan.

Rich text doet mee doordat `admin.js` bij een `text-change` mét
`source === "user"` een gewone `input`-event op de achterliggende textarea
afvuurt. De mediakiezer deed dat al met `change`.

### Wat er gebeurt bij Opslaan

| Situatie | Wat de balk doet |
|---|---|
| Niets gewijzigd | Knop staat uit |
| **Eén** formulier gewijzigd | `form.requestSubmit()` — de browser verstuurt het formulier precies zoals zijn eigen knop dat zou doen. Zelfde verzoek, zelfde servervalidatie, zelfde foutpagina. Weigert de browser op een verplicht veld, dan blijft de balk eerlijk op "Niet-opgeslagen wijzigingen" |
| **Meerdere** formulieren gewijzigd | Op volgorde met `fetch()`, want de browser kan maar één keer navigeren. Stopt bij het eerste formulier dat de server niet accepteerde; daarna één `location.reload()` |
| Een opslag mislukt | Toestand **Opslaan mislukt**, met de naam van het formulier erbij. Dat formulier én alles erna blijft gewijzigd, de pagina herlaadt niet, en wat er getypt was staat er nog |

**"Opgeslagen" is nooit een gok.** Een formulier gaat pas schoon als de server
naar zijn eigen succesadres omleidde — `?saved=1`, `?updated=1` of
`?created=1`, de markering die elk schrijf-endpoint in dit project al
toevoegde. Een afgewezen opslag leidt terug zónder die markering, en precies
daaraan herkent de balk het verschil.

Bij een mislukte achtergrondopslag is de sessieflash met de echte foutmelding
opgegaan aan het verzoek dat de balk deed. De redacteur ziet daarom een
melding die het formulier bij naam noemt en kan met de eigen Opslaan-knop van
dat formulier de volledige serverfout opvragen — de getypte waarden staan er
nog.

### Weg navigeren met openstaande wijzigingen

`beforeunload`, en alléén zolang er echt iets openstaat: een browser die bij
elke navigatie waarschuwt is een browser waarvan niemand de waarschuwing nog
leest. Een opslag van de balk zelf waarschuwt niet. Het versturen van één
formulier terwijl een ánder nog gewijzigd is waarschuwt wél — dat is nu net
het geval dat de losse knoppen vroeger stil lieten verdwijnen.

### Twee dingen die alleen de server weet

De balk vergelijkt geen waarden, dus twee toestanden kan hij niet zelf zien.
Een scherm zegt ze in de markup:

| Attribuut | Waar | Wat de balk doet |
|---|---|---|
| `data-save-bar-unsaved` | op een formulier dat invoer toont die verstuurd maar niet geschreven is: een geweigerde opslag, of een wijziging die op bevestiging wacht | het formulier begint als gewijzigd, ook na de melding *Opgeslagen* van een vorige opslag, en weggaan waarschuwt |
| `data-save-bar-discard` | op de link die zulke invoer bewust weggooit (*Annuleren*) | een gewone klik laat de pagina gaan zonder dat de browser nog eens vraagt; een klik met Ctrl, Cmd, Shift of Alt (nieuw tabblad of venster) telt niet |

Zonder die attributen verandert er niets. De eerste gebruiker is de veldeditor
van Formulieren, waar een typewissel eerst terugkomt met wat hij kost
([`FORMS.md`](FORMS.md), "Niet-opgeslagen wijzigingen"). De pagina-editor
gebruikt ze allebei: het instellingenformulier van `admin/page.php` begint als
gewijzigd na een geweigerde opslag en na een nieuw webadres dat op bevestiging
wacht, in welke websitetaal er ook getypt was, en *Annuleren* van die
bevestiging draagt `data-save-bar-discard`.

## Eén formulier per blok-editor

Het doel voor elke blok-editor: **één scherm, één formulier, één Opslaan**. Wat
op het scherm staat — de velden van het blok, de woorden in de taal op het
scherm, de rijen eronder (kaarten, tags), de instellingen — gaat in één
verzoek naar één endpoint, en wordt in één transactie opgeslagen of helemaal
niet. Geen *Opslaan* per rij, per sectie of per tag.

Waarom: met een formulier per rij sloeg de knop naast een rij alleen die rij
op. De rest van wat er getypt was, ging bij de redirect verloren (de
opslagbalk waarschuwde, maar een bevestigde waarschuwing gooide het alsnog
weg).

### De vorm

| Wat | Hoe |
|---|---|
| Rijen van een lijst | `<lijst>[<sleutel>][<veld>]`: de sleutel is het id van een opgeslagen rij, of `new<n>` voor een rij die net op het scherm is toegevoegd. De volgorde waarin de browser ze stuurt, is de volgorde die wordt opgeslagen |
| Verwijderde rijen | Twee vormen. Een **markering** `<lijst>[<sleutel>][remove]` (de kaarten van de carrousel en elke lijst van `admin/_editor_rows.php`): de rij blijft grijs op het scherm staan tot de opslag, en is terug te zetten. Of een rij die **niet meer meekomt** (de tags van een carrouselkaart, waar × de rij van het scherm haalt). Allebei alleen als het formulier `<lijst>_present` meestuurt: een formulier zonder de lijst kan hem nooit leegmaken. Bij een lijst met markeringen blijft een opgeslagen rij die niet meekwam (intussen in een ander tabblad toegevoegd) gewoon staan, achter de rest |
| ↑, ↓, × | Submitknoppen van hetzelfde formulier, `editor_action=<lijst>:<up\|down\|remove>:<sleutel>`. Met JavaScript (`admin/assets/row-list.js`) verschuiven of verdwijnen ze op het scherm en wordt er niets verstuurd; zonder JavaScript sturen ze het hele formulier, en de server voert de actie uit op de rijen die meekwamen. Getypte waarden gaan dus nooit verloren |
| Andere acties | Een scherm mag eigen werkwoorden hebben: `cards:add` (kaart toevoegen) en `cards:edit:<id>` (naar de kaart) slaan eerst alles op en gaan dan verder |
| Enter in een tekstveld | Drukt de eerste submitknop van het formulier in. Daarom begint zo'n formulier met een visueel verborgen gewone *Opslaan*, zodat Enter nooit een rij verplaatst |
| Een geweigerde opslag | Er wordt niets opgeslagen. Alle getypte waarden komen terug (rijen, volgorde en nieuwe rijen inbegrepen), het formulier krijgt `data-save-bar-unsaved`, en elke melding staat bij zijn eigen veld (`aria-invalid` + `aria-describedby`) en bovenaan in de lijst |

`App\Service\Blocks\EditorRows` is de pure helper aan de serverkant
(`fromPost()`, `parseAction()`, `apply()`); het endpoint bepaalt zelf welke
ids echt rijen van zijn blok zijn.

Een lijst van **vertaalde kindrijen** (vragen, stats, stappen, punten,
afbeeldingen) gaat door `App\Service\Blocks\EditorChildList`, bovenop
`EditorRows`. Die doet voor elke lijst hetzelfde: rijen van een ander blok
weglaten, een lege nieuwe rij overslaan, de woorden van een opgeslagen rij
controleren in de taal op het scherm en die van een nieuwe rij in de
standaardtaal, bij een geweigerde opslag alles teruggeven (`old()`, met een
regel per rij bovenaan: "Vraag 2: …"), en binnen de transactie van het
endpoint opslaan: een verwijderde rij met zijn woorden in elke taal, een
nieuwe rij in de standaardtaal, de rest in de taal op het scherm, en dan de
volgorde. Wat een rij behalve woorden heeft (een schakelaar, een icoon, een
media-item) schrijft het endpoint zelf, in de `create`/`update` die het
meegeeft. De schermkant is `admin/_editor_rows.php`: een rij is een
`fieldset` met de naam "<ding> <plaats>", ↑, ↓ en *Verwijderen* bovenaan, en
velden zonder `required` (een gemarkeerde of lege rij mag het formulier nooit
tegenhouden; de server controleert).

`Tests\Service\Blocks\EditorRowsTest`, `Tests\Service\Blocks\EditorChildListTest`,
`Tests\Service\CardCarouselEditorHttpTest` en het tabelgestuurde
`Tests\Service\BlockRowEditorsHttpTest` (per lijst: één formulier en één
*Opslaan*, blok en rij in één opslag, volgorde, verwijderen, toevoegen, een
geweigerde opslag die niets schrijft, en de taal) bewaken het contract.

### Welke editors het volgen

Alle blok-editors met rijen volgen dit contract; geen enkele heeft nog een
*Opslaan* per rij, per sectie of per afbeelding.

| Blok | Editor | Endpoint |
|---|---|---|
| Kaarten-carrousel | `admin/card-carousel.php` (kop, weergave, kaarten: volgorde, *Actief*, verwijderen) | `api/admin/update-card-carousel.php` |
| — een kaart daarvan | `admin/carousel-card.php` (woorden, nummer, afbeelding, knop, tags) | `api/admin/update-carousel-card.php` |
| FAQ | `admin/faq.php` (kop, *Actief*, vragen) | `api/admin/update-faq-section.php` |
| Cijferbalk | `admin/stat-strip.php` (*Actief*, stats) | `api/admin/update-stat-strip.php` |
| Stappenplan | `admin/step-list.php` (kop, *Actief*, stappen) | `api/admin/update-step-list-section.php` |
| Woordenband | `admin/marquee.php` (*Actief*, items) | `api/admin/update-marquee-section.php` |
| Kaartenraster | `admin/feature-grid.php` (kop, *Actief*, kaarten met icoon) | `api/admin/update-feature-grid.php` |
| Tekst met afbeelding | `admin/text-image-split.php` (sectie, knop, *Actief*, alinea's, afbeeldingen uit de mediabibliotheek) | `api/admin/update-text-image-split-section.php` |
| Detailsectie | `admin/detail-section.php` (woorden en rich text, anker, CTA, *Actief*, hoofdafbeelding, kenmerken, galerij) | `api/admin/update-detail-section.php` |
| Homepage-hero | `admin/homepage-hero.php` (teksten, knoppen met linkdoel, badge, media en lay-out, afbeelding en video uit de mediabibliotheek, statistieken, max. 3) | `api/admin/update-homepage-hero.php` |

Een kaart heeft een eigen scherm omdat hij zelf een lijst (tags) draagt;
*Bewerken* en *Kaart toevoegen* slaan de carrousel eerst op.

De Homepage-hero kiest zijn afbeelding en zijn video uit de mediabibliotheek
(`MEDIA.md`, "De mediakiezer"; het videoveld toont alleen video). Kiezen is
dus deel van dezelfde opslag, en na een geweigerde opslag komen de gekozen
items terug zoals alle tekst. Een Hero van vóór de bibliotheek houdt zijn
eigen bestand (`image_path`, `video_path`) tot er een item gekozen wordt of
*Deze afbeelding weghalen* aangevinkt is; pas na de commit gaat dat oude
bestand weg, en alleen als het een eigen upload was. Waar elke knop heen
gaat, is dezelfde keuze als bij een carrouselkaart
(`admin/_link_target_field.php`, `CONTENT-BLOCKS.md`); de primaire knop heeft
geen *Geen knop*. Langere uitleg staat achter het `?` naast een label.

De statistieken zijn een gewone rijenlijst: *Statistiek toevoegen*, ↑/↓ en
*Verwijderen* werken op het scherm zonder herladen, en pas *Opslaan* bewaart
alles in één transactie (`row-list.js`, `EditorChildList`).

### Afbeeldingen in een rij en de hoofdafbeelding

Een afbeelding in een rij (Tekst met afbeelding, de galerij van de
Detailsectie) is een item uit de mediabibliotheek, gekozen met de kiezer van
`admin/_media_picker.php` (`editor_row_media()`). Kiezen vult alleen het veld;
er wordt pas bij *Opslaan* iets geschreven. Een nieuwe rij heeft een item
nodig. Een opgeslagen rij waarvan het veld leeg terugkomt, houdt wat hij had:
een afbeelding van vóór de bibliotheek (een pad zonder item) mag nooit de
rest van de opslag tegenhouden.

De hoofdafbeelding van de Detailsectie hoort bij het blok zelf en staat in
hetzelfde formulier. *Wissen* in de kiezer haalt hem bij *Opslaan* weg, met
zijn alt-tekst in elke taal; een oude afbeelding zonder item heeft daarvoor
een eigen vinkje. Alleen een formulier dat de kiezer of het alt-veld
meestuurt, kan ze veranderen.

## Dezelfde tabbladen op een ander scherm

`admin/_admin_tabs.php` weet niets van pagina's of blokken. Een scherm zegt
welke tabbladen het heeft, opent per tab een paneel, en sluit af:

```php
require_once __DIR__ . '/_admin_tabs.php';
...
admin_tabs_start('site-settings', ['algemeen' => 'Algemeen', 'seo' => 'SEO'], [
    'scope' => '',            // waarbinnen de keuze onthouden wordt
    'label' => 'Groepen instellingen',
    'force'  => null,         // dwing dit tabblad af voor déze render
]);
admin_tab_panel('algemeen');  /* kaarten */  admin_tab_panel_end();
admin_tab_panel('seo');       /* kaarten */  admin_tab_panel_end();
admin_tabs_end();
...
admin_tabs_script();
```

De panelen worden gebufferd tot `admin_tabs_end()`, omdat de tabstrip pas
geschreven kan worden als alle paneel-id's bekend zijn — een tab mag er meer
dan één hebben. Pijltjestoetsen, Home/End, `aria-selected`, een roving
`tabindex` en het openen van een tabblad met een door de browser afgekeurd
verplicht veld zitten in het script. Zonder JavaScript verschijnt de tabstrip
niet en staat alles gewoon onder elkaar, zoals daarvoor.

**Site-instellingen** (`admin/settings.php`) gebruikt hetzelfde:
*Algemeen* (naam, logo's, favicon, deel-afbeelding, e-mail, telefoon, plaats,
footertekst, adresgegevens en een ingeklapte groep met het KVK-nummer),
*Talen*, *SEO* en *Dashboard*. Elk tabblad is één formulier met zijn eigen
endpoint. Welke velden het formulier van *Algemeen* en *SEO* mag opsturen, en
welke verplicht zijn, staat in `App\Service\SiteSettingsValidator`; alleen de
naam van de website is verplicht.

**Shop-instellingen** (`admin/shop-settings.php`, alleen met de Shop aan)
gebruikt het ook: *Bedrijfsgegevens*, *Facturen*, *Bestellingen* en *E-mails*.
Dat waren de tabbladen Facturen en E-mails van Site-instellingen. De velden en
tabbladen staan in `App\Service\ShopSettings`.

## Een nieuw blok doet automatisch mee

Niets aan de kiezer, de catalogus of de opslagbalk hoeft aangepast te worden.
Schrijf de blokdefinitie zoals [`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md)
beschrijft — inclusief `description()`, `category()` en `icon()`, want die
zijn `abstract` — registreer hem met één regel, en:

- de kaart verschijnt in de kiezer op elke pagina waar het blok mag;
- de bibliotheek toont hem onder zijn categorie, en met *Voorbeeld bekijken*
  zodra de definitie `sampleContent()` en `renderSample()` heeft —
  `BlockSampleContractTest` faalt zolang een nieuw blok geen voorbeeld heeft
  en ook niet als uitzondering genoemd is;
- de blok-editor krijgt de opslagbalk zodra hij `_save_bar.php` insluit
  (`save_bar()` na `</main>`, `save_bar_script()` vóór `</body>`) —
  `Tests\Service\BlockPickerTest` faalt als een editor die vanaf de
  paginabouwer bereikbaar is dat niet doet.

Hoort het blok bij een module, dan komt en gaat hij met die module, zonder
dat Core-bestanden hem noemen.

## Tests

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
```

`AdminEditorNavigationTest` rendert de tabbladen in-process en leest daarnaast
de bron van de twee schermen die ze gebruiken: in welk paneel elk veld staat
(dát is de indeling, en een veld dat verhuist of verdwijnt laat de build
falen), dat Pagina en SEO één formulier naar één endpoint blijven, dat elke
blokrij dezelfde generieke `<details>` is met de sleepgreep erbuiten, en dat
geen blok-editor nog terugwijst naar een URL die het pagina-overzicht niet
beantwoordt.

`BlockPresentationTest` loopt over élk geregistreerd type, dus een nieuw blok
wordt daar meegenomen zodra het in `BlockDefinitions` staat. `BlockPickerTest`
rendert het kiezerspaneel in-process (geen login, geen request, geen
database) — zoekveld, beide weergaven, de categorie op elke kaart, de
verborgen voorbeelden — en leest daarnaast de bron van `block-picker.js` (de
bewaarde weergave, het filteren), van `save-bar.js` en van elke blok-editor.
Wat een klik doet, loop je na in de browser. Zie verder
[`TESTING.md`](TESTING.md).

De bibliotheek heeft vier eigen tests:

- **`BlockLibraryScreenTest`** rendert kaarten en dialoog in-process en leest
  de bron van `block-library.js`.
- **`BlockSampleContractTest`** loopt over élk geregistreerd type:
  - een voorbeeld, of een genoemde uitzondering;
  - dezelfde partial als `render()`, zonder ontbrekende sleutel;
  - elk voorbeeldwoord ge-escaped;
  - geen sitenaam, prijs of bereikbaar adres in de voorbeeldwoorden, en geen
    sitegebonden tekst (naam, plaats, contactbelofte) in wat de partial er
    zelf omheen print;
  - elke link een fragment;
  - niets dan `BlockSamples`, en niets publieks dat een voorbeeld kan bereiken.
- **`BlockPreviewContractTest`** leest de bron van `admin/block-preview.php`
  en `block-preview.js`.
- **`BlockPreviewAccessTest`** (suites `blocks` en `cms`) bewijst over echt
  HTTP:
  - de guard, de headers en de 404's;
  - een uitgeschakelde module;
  - "schrijft niets" met een checksum van elke tabel;
  - dat het voorbeeldformulier met de hand verstuurd niets bereikt.

`PageBuilderScreenTest` (suites `blocks` en `cms`) rendert het echte
paginascherm over HTTP, met PHP's eigen webserver, en gebruikt het zoals een
browser dat doet: een nieuwe *Lege pagina* met alleen haar kop en de
uitnodiging, *Verbergen* en *Tonen* op de juiste rij en via hun eigen
formulier, *Verwijderen* met zijn vraag en een endpoint dat een verzoek zonder
token nog steeds weigert, en een herordenlijst die haar endpoint nog bereikt.
