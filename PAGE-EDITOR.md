# De paginabouwer — werkdocument

Hoe een redacteur een pagina vult, en waarom die schermen zo in elkaar
zitten: de **tabbladen** die het paginascherm in drie stukken knippen, de
**inklapbare blokrijen** in de blokkenlijst, de **blokkenkiezer** waarmee je
een contentblok toevoegt, de **Contentblokken-catalogus** die uitlegt wat elk
blok doet, en de **opslagbalk** die op elk blok-editorscherm onderaan
meeschuift.

Wat een blok zélf is — tabel, repository, inhoudsklasse, partial, editor,
endpoint, definitie — staat in [`CONTENT-BLOCKS.md`](CONTENT-BLOCKS.md). Dit
document gaat alleen over de schil eromheen. Wijkt de code af van dit
document, dan heeft de code gelijk.

## De drie stukken in één oogopslag

| Onderdeel | Bestanden |
|---|---|
| Tabbladen (herbruikbaar) | `admin/_admin_tabs.php`, `admin/assets/admin-tabs.js`, CSS in `admin/assets/admin.css` (`.admin-tabs*`). Gebruikt door `admin/page.php` en `admin/settings.php` |
| Inklapbare rijen (herbruikbaar) | `admin/_admin_collapse.php`, `admin/assets/admin-collapse.js`, CSS `.admin-collapse*`. Gebruikt door de blokkenlijst op `admin/page.php` |
| Presentatie-metadata van een blok | `src/Service/Blocks/BlockDefinition.php` (`label()`, `description()`, `category()`, `icon()`, `preview()`, `useCases()`), `BlockCategories.php`, `BlockPreview.php` |
| Blokkenkiezer | `admin/_block_picker.php`, `admin/assets/block-picker.js`, gebruikt door `admin/page.php` |
| Schematische tekening en pictogram | `admin/_block_visual.php`, CSS in `admin/assets/admin.css` (`.admin-block-visual`, `.admin-bp--*`) |
| Catalogus | `admin/content-blocks.php` (menu-item `content_blocks` in `App\Service\AdminNavigation`) |
| Opslagbalk | `admin/_save_bar.php`, `admin/assets/save-bar.js`, aangeroepen door `admin/page.php` en elke blok-editor |
| Tests | `tests/Service/BlockPresentationTest.php`, `tests/Service/BlockPickerTest.php`, `tests/Service/AdminEditorNavigationTest.php` |

## Tabbladen op de paginabouwer

```text
[ Inhoud ] [ Pagina ] [ SEO ]
```

**Inhoud** is de blokkenlijst plus *Contentblok toevoegen* en staat vooraan,
want daarvoor komt een redacteur. **Pagina** is Algemeen (titel, slug, status,
de vaste-URL-uitleg) plus *Verwijderen*. **SEO** is de SEO-kaart, ongewijzigd.
Geen veld staat op twee tabbladen.

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

**Welk tabblad opengaat**, van specifiek naar algemeen: wat het scherm eist
(een afgekeurde opslag opent Pagina, want de foutmelding gaat over dat
formulier) → wat er in de URL staat (`#blok-42` opent Inhoud) → wat deze
redacteur op déze pagina het laatst open had (`sessionStorage`, gesleuteld op
groep + pagina-id) → de standaard. Een opslag die lukt herlaadt het scherm en
komt zo terug op hetzelfde tabblad.

## Contentblokken klappen open en dicht

```text
▸ Tekstblok — Over onze diensten
▾ Detailsectie — Hout graveren
     Bewerken →   Verbergen   Verwijderen
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
  *Verbergen/Tonen*, *Verwijderen*.
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
| `useCases()` | Twee tot vier concrete situaties, als korte woordgroepen. Alleen de catalogus toont ze, onder "Geschikt voor" | nee |

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

**Categorieën** staan in `BlockCategories`, gesloten en op volgorde: *Kop van
de pagina*, *Content*, *Beeld & media*, *Actie & interactie*, *Shop*. Een
categorie zonder blokken verdwijnt vanzelf van het scherm — dat is precies
wat er met *Shop* gebeurt zodra de Shop uit staat.

## De blokkenkiezer

```text
[ + Contentblok toevoegen ]        ← altijd direct onder de blokkenlijst
        ↓
Contentblok kiezen                  ← modaal paneel
[ Zoeken… ]  [Alles][Content][Beeld & media][Actie & interactie]

CONTENT
┌───────────────┐ ┌───────────────┐
│ ▤ schets      │ │ ▤ schets      │
│ Tekstblok     │ │ Kenmerken …   │
│ Vrije tekst…  │ │ Kaartjes …    │
│ [Toevoegen]   │ │ [Toevoegen]   │
└───────────────┘ └───────────────┘
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

**Zoeken** is platte substring-vergelijking over naam, beschrijving,
categorienaam en voorbeeldgebruik, voorgekookt in `data-block-terms`. Niet
fuzzy en niet over de type-key: met dit aantal blokken is voorspelbaar
belangrijker dan slim.

**Na het toevoegen** stuurt het endpoint de redacteur rechtstreeks de editor
van het nieuwe blok in — dat deed het al. Heeft een blok geen eigen editor,
dan keert het terug naar de paginabouwer met `&added=<id>`, en licht die rij
kort op.

**Toetsenbord en aanraking.** Het paneel is een `role="dialog"`: Escape
sluit, focus springt bij openen naar het zoekveld en bij sluiten terug naar de
knop, Tab blijft binnen het paneel. Kaarten en filters zijn echte knoppen met
`aria-pressed`, en er is geen enkel gegeven dat alleen bij hover verschijnt.
Onder 640 px wordt het paneel schermvullend en de kaarten één kolom.

**Zonder JavaScript gaat het paneel niet open.** Dat is dezelfde afspraak als
bij de mediakiezer en het slepen van blokken: het adminpaneel gaat uit van
JavaScript. Er is bewust geen `<noscript>`-dropdown teruggezet, want dat zou
de tweede manier van toevoegen zijn die deze stap juist opruimde.

## De Contentblokken-catalogus

`Beheer → Contentblokken` (`admin/content-blocks.php`) is documentatie in het
CMS: per categorie een kaart per blok met de schets, de naam, de beschrijving
en "Geschikt voor". Er staat geen enkel formulier op — het scherm maakt,
wijzigt en verwijdert niets, en is dus ook geen tweede, zwakkere weg naar de
schrijf-endpoints. Blokken die je niet zelf plaatst (de vaste blokken) dragen
het label *Staat er automatisch* en verwijzen naar het beheerscherm dat hun
inhoud wél bezit.

Het menu-item hangt aan `pages.manage`: wie een pagina mag bouwen mag lezen
waar de bouwstenen voor dienen.

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
- de catalogus toont hem onder zijn categorie;
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
database) en leest daarnaast de bron van `save-bar.js` en van elke
blok-editor. Zie verder [`TESTING.md`](TESTING.md).
