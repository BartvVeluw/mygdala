# Admin-UI — de gedeelde bouwstenen van het CMS

Eén set bouwstenen voor elk adminformulier: uitleg bij een veld, de knop die
alle uitleg aan- of uitzet, een infobalk bovenaan een scherm, de gewone
formulierelementen in de stijl van het CMS, en de dialoog die vraagt voordat
iets weg is. Dit document is de handleiding. De code staat op drie plekken en
nergens anders.

| Wat | Waar |
|---|---|
| Markup | `admin/_admin_ui.php` |
| Uiterlijk | `admin/assets/admin.css`, sectie **ADMIN UI PRIMITIVES** (onderaan) |
| Gedrag | `admin/assets/admin-ui.js` |
| Teksten | `src/Service/Language/messages/nl.php` en `en.php`, sleutels `ui.*` en `help.*` |
| Tests | `Tests\Service\AdminUiPrimitivesTest` (suites `contract`, `fast`, `cms`) |

Twee hoofdstukken verderop hebben hun eigen bestanden, en noemen die daar:
[de menu's in de zijbalk](#menus-in-de-zijbalk) en
[de editor die opslaat zonder te herladen](#een-editor-die-opslaat-zonder-te-herladen).

## Vier afspraken

- **Eén versie per component.** Een scherm vraagt een bouwsteen aan en
  schrijft de markup nooit zelf. Er is één toegankelijke help-knop, niet twintig
  bijna-gelijke.
- **Het native element doet het werk.** Een select blijft een `<select>`, een
  switch is een checkbox, een bestandskiezer is een `<input type="file">`.
  Toetsenbord, schermlezer, `required` en wat het formulier verstuurt, werken
  zoals ze altijd werkten.
- **Zonder JavaScript werkt alles nog.** Het script verbetert, het draagt niet
  (`CODE-STYLE.md`).
- **Alleen bestaande tokens.** De sectie leest de `--admin-*`-tokens bovenaan
  `admin.css` en schrijft geen eigen kleur en geen eigen token. Alle vier
  dashboardthema's krijgen de bouwstenen dus vanzelf (`THEMING.md`).

## Het script

`admin/_header.php` (de schil) laadt `admin-ui.js` als eerste in `<body>`, en
**zonder `defer`**. Het eerste wat het script doet is de opgeslagen keuze op
`<html>` zetten; een uitgestelde versie zou alle uitleg eerst tekenen en
daarna pas verbergen. Het bestand is klein, wordt gecachet, en doet verder
niets tot er iets gebeurt: één listener per soort event op `document`, geen
listener per icoon, geen netwerkverzoek.

Elk scherm dat de schil rendert heeft de functies en het script dus al.
`login.php` en `setup.php` renderen geen schil en hebben geen help.

## Uitleg bij een veld

```php
<div class="admin-field">
  <?= admin_field_label('settings-email', admin_t('common.email_address'), admin_t('help.settings.email'), true) ?>
  <input type="email" id="settings-email" name="email" required>
</div>
```

- `admin_field_label($for, $label, $help = '', $required = false)` schrijft
  het `<label for>` met het `?` ernaast. Het veld zelf schrijf je eronder, met
  hetzelfde `id`.
- `admin_help($onderwerp, $uitleg)` is alleen het `?` met zijn uitleg. Voor
  een checkbox of switch zet je hem ná het label:

```php
<div class="admin-field admin-field--inline">
  <label class="admin-checkbox-label">
    <input type="checkbox" class="admin-switch" role="switch" name="…" value="1">
    <?= admin_te('…') ?>
  </label>
  <?= admin_help(admin_t('…'), admin_t('help.…')) ?>
</div>
```

**Het `?` staat naast het `<label>`, nooit erin.** Binnen een label wordt de
naam van de knop een deel van de naam van het veld ("E-mailadres Uitleg over
E-mailadres"), en een klik in de open uitleg wordt doorgegeven aan het veld.

### Gedrag

| Handeling | Wat er gebeurt |
|---|---|
| Muis op het `?` | de uitleg verschijnt na 120 ms |
| Muis weg van het `?` én van de uitleg | de uitleg verdwijnt na 220 ms, zodat je naar de uitleg toe kunt bewegen |
| Klik, Enter of Spatie op het `?` | de uitleg staat vast; nog eens sluit hem |
| Tik op het `?` | idem: een touchscherm heeft geen hover, een tik zet vast |
| Kruisje | sluit, en de focus gaat terug naar het `?` |
| Escape | sluit de laatst geopende uitleg, en alleen die |
| Klik of tik buiten de uitleg | sluit |
| Scrollen of het venster verkleinen | de uitleg schuift mee en blijft binnen het scherm |

Vastzetten sluit elke andere open uitleg. De uitleg is een native
`popover="manual"`. Hij staat daardoor in de *top layer* en valt nooit achter
de zijbalk, een modal of de opslagbalk. `manual` en niet `auto`, omdat het
script bepaalt wanneer hij sluit: een `auto`-popover zou een vastgezette uitleg
sluiten zodra je over een ander `?` beweegt.

Zonder script opent de browser de uitleg zelf via `popovertarget`, gecentreerd
op het scherm en met het kruisje.

### Toegankelijkheid

- Het `?` is een echte `<button type="button">` met `aria-label` ("Uitleg over
  E-mailadres"), `aria-expanded`, `aria-controls` en `aria-haspopup="dialog"`.
- De uitleg heeft `role="dialog"` en `aria-labelledby` naar zijn kop.
- De uitleg volgt in de bron direct op zijn knop: Tab gaat van het `?` naar het
  kruisje.
- Het `?` is een klikdoel van 24 × 24 px (WCAG 2.5.8) met een zichtbare
  focusring.
- Geen `title=""` als uitleg. Die is met een toetsenbord en op een touchscherm
  niet te bereiken.

### Teksten

De uitleg is CMS-tekst en staat in de catalogus, onder `help.<scherm>.<veld>`,
in `nl.php` én `en.php` (`AdminLocaleTest` houdt ze gelijk). Het script bevat
geen enkel woord dat een beheerder leest; de test controleert dat.

Schrijf voor iemand die nog nooit een website heeft beheerd. Begin met wat het
veld dóét, gebruik geen woord als *slug* of *meta description* zonder uit te
leggen wat het is, en zeg het liefst waar de bezoeker het terugziet.

Toegestaan in de tekst:

- `<strong>` en `<em>`, zonder attributen;
- een lege regel voor een nieuwe alinea. Schrijf de tekst dan tussen dubbele
  aanhalingstekens, zodat `\n\n` een echte regelovergang is;
- een enkele regelovergang voor een `<br>`.

Al het andere wordt ge-escaped en is dus als tekst te zien. Entiteiten die de
catalogus al gebruikt (`&rsquo;`, `&mdash;`) worden het teken zelf.

## De help-knop in de schil

Bovenaan de zijbalk, en op een smal scherm (tot 900 px, waar de zijbalk is
ingeklapt) naast de menuknop. Het is dezelfde functie op twee plekken:
`admin_help_toggle('sidebar')` en `admin_help_toggle('topbar')`. De CSS toont
er één.

| Help | Wat je ziet |
|---|---|
| Aan | alle `?`-iconen en infobalken |
| Uit | geen iconen en geen infobalken; open uitleg sluit. Labels, velden en wat een formulier verstuurt, blijven gelijk |

- **Opslag:** `localStorage`, sleutel **`mygdalaAdminHelp`**, waarde `on` of
  `off`. Dezelfde naamgeving als `mygdalaAdminTab:` en `mygdalaSaveBarSaved`.
  Een tweede tabblad van het CMS neemt een wijziging meteen over.
- **Standaard:** aan, voor een browser die nog niets gekozen heeft, en ook als
  `localStorage` geblokkeerd is.
- **Waarom geen databasekolom:** het is een weergavevoorkeur zonder gevolg
  voor de inhoud, en er is geen bestaande plek voor zulke voorkeuren per
  gebruiker. De CMS-taal en de bewerktaal staan wél op het account, omdat die
  bepalen wát er op een scherm staat.
- **Hoe:** het script zet `data-admin-help="on"` of `"off"` op `<html>`, en de
  CSS leest de toestand daar. `aria-pressed` volgt zodra de knop bestaat. Naast
  de kleur zegt het woord *aan* of *uit* de toestand.
- **Zonder script** is de knop verborgen en blijft help aan.

## Infobalk

```php
<?= admin_info_panel(admin_t('help.pages.overview')) ?>
```

Een korte uitleg bovenaan een scherm: waar dit scherm voor is, zonder jargon.
`role="note"`, geen alert-kleur, want er is niets mis. Hij volgt de help-knop
in de schil en heeft geen eigen knop. Dezelfde tekstregels als de uitleg.

## Formulierelementen

| Element | Markup | Let op |
|---|---|---|
| Zoekveld | `<label class="admin-search"><span class="admin-visually-hidden">…</span><input type="search" …></label>` | Elk `input[type="search"]` heeft de invoerstijl van het CMS; `.admin-search` voegt het vergrootglas toe |
| Select | `<select class="admin-select">` | Voor één keuze. Niet voor `multiple` of `size`. Foutstaat met `aria-invalid="true"` |
| Checkbox | `<input type="checkbox" class="admin-checkbox">` | In een `.admin-checkbox-label`. Élke zichtbare checkbox van het CMS heeft deze klasse of `.admin-switch` |
| Getal | `<input type="number">` | Heeft de invoerstijl van het CMS, net als tekst en zoeken; geen eigen klasse |
| Telefoon, webadres | `<input type="tel">`, `<input type="url">` | Idem: dezelfde gedeelde selectors als tekst en e-mail (hoogte, binnenruimte, rand, afronding, achtergrond, letter, focus). Geen `.phone-input` of andere eigen klasse; het blijft een echt `tel`-veld, zodat een telefoon het juiste toetsenbord toont |
| Datum, datum met tijd | `<input type="date">`, `<input type="datetime-local">` | Hetzelfde tekstveld, in een eigen regelblok in `admin.css` (look, focus, foutrand). Het kalendericoon van de browser is altijd zwart; het wordt als masker in `--admin-text-muted` getekend, dus leesbaar op elk dashboardthema, ook `custom`. Tot v0.1.14 was het de kale browserbox, 21px hoog (reviewdatum, publicatiemoment, bestelfilter) |
| Switch | `<input type="checkbox" class="admin-switch" role="switch">` | Voor één aan/uit-instelling |
| Bestand | `admin_file_input(['name' => 'image', 'accept' => '…', 'required' => true])` | Binnen het `<label>` van het veld, of met een `id` naast `admin_field_label()` |
| Voorbeeld van een afbeelding | `admin_file_preview('id-van-het-veld', $huidigeAfbeelding)` | Hoort bij één `admin_file_input()` met dat `id`; zie hieronder |
| Knoppen | `.admin-btn-primary`, `.admin-btn-secondary`, `.admin-btn-danger`, `.admin-btn-ghost`, `.admin-btn-text` | Uitgeschakeld met `disabled`, of `aria-disabled="true"` op een link |

Een tekstveld (tekst, wachtwoord, e-mail, zoeken, getal, telefoon, webadres,
tekstvak) met `aria-invalid="true"` krijgt de foutrand van het CMS
(`--admin-error`, en bij focus een zachte foutring), net als `.admin-select`.
Uitgeschakeld is het half doorzichtig met `cursor: not-allowed`.

Elk element heeft een hover-, focus- en disabled-toestand. In Windows' hoog
contrast (`forced-colors`) krijgen checkbox en switch het eigen element van de
browser terug, en `prefers-reduced-motion` zet de overgangen uit.

**Eén checkbox voor het hele CMS.** `.admin-checkbox` tekent de native
checkbox in de thema-tokens (`appearance: none`, dus Spatie, `checked`, de
naam en de waarde blijven van de browser). Aangevinkt is hij gevuld in
`--admin-accent` met een vinkje in `--admin-on-accent`; hover over de box
óf over zijn label kleurt de rand (en aangevinkt de vulling in
`--admin-accent-hover`); `:focus-visible` geeft de focusring van het CMS;
uitgeschakeld is hij half doorzichtig en krijgt zijn label de gedempte
tekstkleur en `cursor: not-allowed`. Het label blijft klikbaar omdat de
checkbox erin staat. Alleen een checkbox die niemand ziet (de verborgen
menuschakelaar, het visueel verborgen verwijdervinkje van een rij met een
eigen getekend label) heeft geen klasse. `AdminUiPrimitivesTest` scant elk
scherm onder `admin/` en faalt op een zichtbare checkbox zonder
`.admin-checkbox` of `.admin-switch`.

**Een switch verandert niets aan wat er verstuurd wordt.** Hij is een
checkbox: aangevinkt stuurt hij zijn `value`, uit stuurt hij niets. Leest een
endpoint `isset()`, dan blijft dat zo. Verwacht het een verborgen `0` ervoor
(zoals Instellingen), dan blijft die verborgen `0` staan.

**Een bestandskiezer verandert niets aan het uploaden.** Met het script ligt
het echte `<input type="file">` onzichtbaar over een eigen knop en een regel
met de gekozen bestandsnaam; een klik overal in het vak opent de dialoog, en
`required`, `accept` en `multiple` blijven van het echte veld. Zonder script
is het de knop van de browser zelf, in de vorm van `.admin-btn-secondary`. Een
script dat al op het veld reageert (de upload van de Mediabibliotheek in
`admin/assets/media-upload.js`) vindt het nog steeds. Slepen en neerzetten en uploadvoortgang horen bij de
Mediabibliotheek (`MEDIA.md`).

**Een voorbeeld verandert daar ook niets aan.** `admin_file_preview()` hoort bij
precies één bestandskiezer, via het `id` van dat veld. Kiest een redacteur een
afbeelding, dan tekent het script het bestand dat de browser al heeft
(`URL.createObjectURL()`): er gaat niets naar de server en er wordt niets
opgeslagen tot het formulier zelf dat doet. Een nieuwe keuze vervangt het
voorbeeld, *Keuze wissen* maakt het veld leeg en toont weer de huidige
afbeelding (of niets, op een nieuw item), en een tijdelijke URL wordt
vrijgegeven zodra hij niet meer getoond wordt. Op een bewerkscherm krijgt de
functie de huidige afbeelding mee; die staat er ook zonder script. De regel
die zegt welke afbeelding het is, is `aria-live`, zodat een schermlezer de
wissel ook hoort.

## Bevestigen voordat iets weg is

Eén dialoog die vraagt of het echt moet, voor een formulier dat iets doet wat
niet terug te draaien is. Een scherm drukt de dialoog één keer af, onderaan,
en zet de vraag zelf op elk formulier dat moet vragen:

```php
<form method="post" action="/api/admin/delete-…" class="admin-inline-form"<?= admin_confirm_attributes(
    admin_t('…_title'),
    admin_t('…_message', ['block' => $naam]),
    admin_t('common.delete')
) ?>>
  …
</form>
…
<?= admin_confirm_dialog() ?>
```

| Functie | Wat het is |
|---|---|
| `admin_confirm_dialog()` | De dialoog: een native `<dialog>` met een kop, de uitleg, *Annuleren* en de knop die doorgaat. Eén keer per scherm |
| `admin_confirm_attributes($titel, $uitleg, $knop)` | De vraag van één formulier, als `data-admin-confirm*`-attributen. Alles ge-escaped, dus de eigen titel van een blok mag erin. Een lege titel of knop laat *Weet je het zeker?* en *Doorgaan* staan |

**Het formulier doet het werk.** Het script houdt het versturen tegen, vraagt,
en geeft bij *ja* hetzelfde formulier terug aan de browser
(`requestSubmit()`, met de knop die was ingedrukt). Hetzelfde verzoek, dezelfde
CSRF-token, hetzelfde endpoint en dezelfde guards: de dialoog beslist niets en
is nooit de beveiliging.

### Gedrag

| Handeling | Wat er gebeurt |
|---|---|
| Op de knop van het formulier drukken | De dialoog opent met de vraag van dát formulier; de focus staat op *Annuleren* |
| *Annuleren*, Escape of een klik naast de dialoog | Dicht, er is niets verstuurd, en de focus staat weer op de knop die vroeg |
| De knop die doorgaat | Het formulier gaat alsnog, precies zoals zonder vraag |
| Tab | Blijft binnen de dialoog; de pagina erachter reageert niet zolang hij open is |

`showModal()` levert de modaliteit, Escape en het vasthouden van de focus zelf;
er zit geen eigen focus-trap in. *Annuleren* staat vooraan in de bron, zodat
een verdwaalde Enter nooit de knop is die verwijdert. Het script luistert in de
capture-fase op `document`: het vraagt voordat iets anders op de pagina op het
versturen reageert, de opslagbalk inbegrepen.

**Zonder dialoog wordt er nog steeds gevraagd.** Heeft een formulier een vraag
maar staat er op het scherm geen `admin_confirm_dialog()`, of kent de browser
`<dialog>` niet, dan stelt het script de vraag met `window.confirm()`. Zonder
script gaat het formulier direct, zoals met de inline `confirm()` die dit
vervangt.

Het eerste scherm dat hem gebruikt, is de blokkenlijst van de paginabouwer
([`PAGE-EDITOR.md`](PAGE-EDITOR.md)); de losse verwijderknop van een item in
de Mediabibliotheek volgde ([`MEDIA.md`](MEDIA.md)), en daarna Portfolio,
alle verwijderingen in Formulieren ([`FORMS.md`](FORMS.md), "Eerst vragen, in
de dialoog van het CMS"), de menu-items en knoppen van Header & navigatie, en
de kolommen, links en social profielen van Footer
([`HEADER-FOOTER.md`](HEADER-FOOTER.md)). De rest van het CMS gebruikt nog
`onsubmit="return confirm(…)"`. Een scherm dat overgaat, haalt die weg en
gebruikt de twee functies hierboven; meer is het niet.

## Menu's in de zijbalk

Een module kan haar schermen onder één regel in de zijbalk zetten: een
**menu**. De Shop doet dat sinds Shop Admin UX 2.0. Het gaat om tien
schermen: Producten, Collecties, Gerelateerde producten, Personalisatie,
Verzendinstellingen, Carrier-tarieven, Shop-instellingen, Betalingen (sinds
Mollie Setup 2.0), Bestellingen en Retourverzoeken. Die staan niet meer los
tussen Pagina's en Portfolio, maar onder één regel *Shop* met een pijltje.

| Wat | Waar |
|---|---|
| Het menu zelf (naam, icoon, plek) | `ModuleDefinition::adminNavigationMenus()`, bij de Shop `ShopModule::adminNavigationMenus()` |
| Welke schermen erin staan | de sleutel `menu` op een zijbalkregel (`ShopModule::ADMIN_MENU`; Personalisatie hangt aan de Shop en zet zich erin) |
| Wat een gebruiker ziet | `AdminNavigation::sidebar()`: regels en menu's in volgorde |
| Tekenen | `admin/_header.php` |
| In- en uitklappen | `admin/assets/admin-sidebar.js` |
| Tests | `Tests\Module\AdminSidebarMenuTest` (`contract`, `fast`, `modules`), `Tests\Service\AdminSidebarMenuHttpTest` (`shop`) |

Afspraken:

- **Core noemt geen menu.** Een menu is een bijdrage van een module, zoals
  haar regels. Staat de module uit, dan is er geen menu en geen lege
  submenucontainer.
- **Het menu neemt de plek van zijn `order`.** De Shop staat op 300, waar
  Producten stond. De regels erin houden hun eigen volgorde, dus
  Bestellingen (500) staat nu binnen het menu en niet meer onder Portfolio en
  Blog.
- **`items()` blijft de platte lijst.** De guards, de markering van het
  actieve scherm en de tests vragen naar regels. `sidebar()` is de vorm die
  de schil tekent. Een gebruiker die geen enkele regel van een menu mag
  openen, ziet het menu niet. De eerste regel van `sidebar()` is ook het
  scherm waarop hij na het inloggen landt.
- **Een echte knop.** De menuregel is een `<button type="button">` met
  `aria-expanded` en `aria-controls`. Enter, Spatie, de tabvolgorde en de
  focusring komen van de browser. De regels erin blijven gewone links, en de
  actieve krijgt `aria-current="page"`.
- **De server bepaalt of het menu open staat.** Het menu staat open op zijn
  eigen schermen, met het actieve scherm gemarkeerd, en dicht op alle andere.
  Er wordt dus niets onthouden en er klapt niets open nadat de pagina getekend
  is. Zonder script staat elk menu open (een `<noscript>`-regel).
- **Dezelfde maat als elke regel.** De knop gebruikt `.admin-sidebar__link`
  en haalt de gedeelde knoplook eraf. Hoogte, icoon en tekst liggen gelijk met
  de links erboven en eronder. De regels in het menu hangen aan een dunne lijn
  onder het icoon, en hun tekst lijnt uit met de naam van het menu.

## Een editor die opslaat zonder te herladen

Sinds Shop Admin UX 2.0 heeft het CMS een tweede manier van opslaan naast de
opslagbalk (`PAGE-EDITOR.md`, "De opslagbalk"). Het gaat om **één formulier,
één knop *Opslaan***, waarmee het hele scherm in één verzoek wordt bewaard,
zonder dat de pagina herlaadt. De producteditor is het eerste scherm dat
hiermee werkt, Betalingen (`admin/payments.php`) het tweede. Elke editor die
uit één formulier bestaat, kan volgen. Het hele CMS in één keer omzetten is
uitdrukkelijk géén doel.

**Een actie naast de opslag.** Op Betalingen zijn *Test deze sleutel* en
*Verbinding testen* geen deel van *Opslaan*: ze testen, ook met een sleutel
die nog niet is opgeslagen, en slaan niets op. Zo'n knop is een
`type="submit"` met `formaction` naar zijn eigen endpoint, zodat hij zonder
script het formulier daarheen post. Met script houdt het scherm de klik
tegen vóór het versturen (anders zou de editor hem opvangen en opslaan),
post alleen wat de test nodig heeft en zet het antwoord in een live regio
(`admin/assets/payments.js`). Een test maakt het scherm niet gewijzigd.

| Wat | Waar |
|---|---|
| Markup: balk, samenvatting, vertrekdialoog, script | `admin/_admin_editor.php` |
| Gedrag | `admin/assets/admin-editor.js` |
| Het antwoord van een endpoint | `App\Service\AdminEditorResponse` |
| Uiterlijk | `admin/assets/admin.css`, sectie "The dynamic editor" (de balk is `.admin-save-bar`, de dialoog `.admin-confirm`) |
| Teksten | sleutels `editor.*` in `nl.php` en `en.php` |
| Tests | `AdminEditorContractTest`, `AdminEditorResponseTest` (`contract`/`unit`, `fast`, `cms`), `ProductEditorHttpTest` (`shop`), `BlockAppearanceHttpTest` (het paneel als begeleidend formulier) |

### Het contract

- **Verzoek.** Het hele formulier als `FormData`, met
  `Accept: application/json`. Tijdens het versturen is het formulier `inert`,
  zodat wat verstuurd wordt en wat op het scherm staat gelijk blijven.
- **Antwoord.** Altijd dezelfde vorm (`AdminEditorResponse`):
  - `200 {"ok": true, "message", "data", "errors": {}}` als alles is
    opgeslagen;
  - `422 {"ok": false, …, "errors": {…}}` als er niets is opgeslagen;
  - `500` als de database faalde.

  `errors` is gesleuteld op de **naam van het formulierveld**
  (`price`, `options[new0][name]`) of op de **sleutel van een sectie**
  (`variants`), elk met een lijst meldingen. Een melding zonder plek staat
  onder `_form`. `data.redirect` stuurt de browser door, bijvoorbeeld naar een
  nieuw item dat nu een eigen adres heeft.
- **Hetzelfde endpoint zonder script.** Zonder `Accept: application/json`
  krijgt een formulier de gewone PRG-redirect met sessie-flash. Er is één set
  regels voor beide.
- **De guards blijven zoals ze zijn.** Login, permissie, POST en CSRF
  antwoorden eerst, in hun eigen vorm. Het script leest hun statuscode en
  zegt het in CMS-woorden:
  - 401: je bent uitgelogd;
  - 403: te oud of geen recht;
  - elk ander antwoord, en geen verbinding: opslaan mislukt.

  In al die gevallen blijft wat er getypt is op het scherm staan.
- **Alles of niets.** Een endpoint van dit soort controleert eerst alles en
  schrijft dan alles in één transactie (bij het product:
  `api/admin/update-product.php`). Een geweigerde opslag schrijft niets, dus
  ook geen geldig veld dat toevallig naast een fout stond.

### Na het opslaan: de regio's

Een rij die op het scherm is toegevoegd, heeft nog geen id (`new0`). Na een
geslaagde opslag vraagt het script de pagina **op haar eigen adres** opnieuw
op. Daarna vervangt het elk element met `data-admin-editor-region="<sleutel>"`
door de versie van de server, waarop elke rij haar echte id draagt. Zo maakt
een volgende opslag een rij niet nog een keer aan. De pagina blijft de enige
plek die de editor tekent. Welke `<details>` open stonden en de scrollpositie
blijven behouden. Scripts die iets in een regio verbeteren, horen
`admin-editor:replaced` op die regio:

- `row-list.js` bedraadt de lijsten;
- `admin.js` zet de rich-texteditors op;
- `product-gallery.js` begint opnieuw.

Lukt dat opvragen niet, dan herlaadt de pagina. Wat er dan op het scherm
stond, zou de volgende opslag verkeerd versturen.

### Wat "gewijzigd" betekent

Gewijzigd is een vlag en geen vergelijking, net als bij de opslagbalk. Elke
`input` of `change` binnen het formulier zet de vlag. Die komt van:

- typen, een select, een checkbox;
- de mediakiezer, die een `change` geeft als hij een veld vult;
- `row-list.js` bij toevoegen, verwijderen of verplaatsen van een rij;
- `product-gallery.js` bij elke wijziging aan de afbeeldingen.

Een script dat het formulier op een andere manier verandert, stuurt
`admin-editor:change`. **Niet** gewijzigd wordt het scherm door:

- een sectie open- of dichtklappen (een `<details>`-toggle);
- uitleg openen;
- de mediakiezer openen en weer sluiten zonder keuze (zijn zoekveld staat
  buiten het formulier);
- het opnieuw tekenen van een regio na een opslag.

Na een geslaagde opslag is het scherm schoon. Na elke mislukte opslag blijft
het gewijzigd.

### Formulieren naast de editor (begeleidende formulieren)

Een scherm kan naast het editorformulier gewone POST-formulieren hebben die
hun eigen endpoint houden. Het voorbeeld is het paneel *Extra vormgeving* van
elk blok in het tabblad *Inhoud* van de producteditor
(`admin/_block_appearance.php`), dat buiten `form[data-admin-editor]` staat.
Sinds v0.1.15 bewaakt `admin-editor.js` zulke formulieren zelf. Er komt dus
geen tweede opslagbalk en geen `save-bar.js` op hetzelfde scherm: **twee
trackers op één scherm zijn verboden**.

- **Welke formulieren: dezelfde regel als `save-bar.js`.** Een POST-formulier
  in `main.admin-main` met een bewerkbaar veld en een verzendknop, geen
  `.admin-inline-form` (verbergen, verwijderen) en zonder
  `data-no-dirty-track` (de blokkenkiezer). De regel staat letterlijk in
  beide scripts, en `AdminEditorContractTest` bewaakt dat hij gelijk blijft.
  Een nieuw ingebed formulier valt er dus vanzelf onder, op de pagina-, de
  project- en de producteditor.
- **Gewijzigd per formulier.** Het scherm is gewijzigd zolang de editor óf
  een begeleidend formulier niet is opgeslagen. Een opslag van de editor maakt
  een begeleidend formulier nooit schoon.
- **Centraal *Opslaan*.** Eerst de editor (als die gewijzigd is), daarna elk
  gewijzigd begeleidend formulier precies één keer, op volgorde, met
  `fetch()`. Een begeleidend formulier is opgeslagen als zijn endpoint
  doorstuurt naar zijn succesadres (`saved=<id>`, net als bij de opslagbalk).
  Bij de eerste weigering stopt de reeks: dat formulier en de formulieren erna
  blijven gewijzigd, en de balk noemt het formulier (`data-save-name`). Na de
  laatste opslag herlaadt de pagina, want alleen de server kan tekenen wat er
  nu is opgeslagen.
- **De eigen knop van het formulier** blijft de gewone POST. Is verder niets
  gewijzigd, dan is dat de opslag en vraagt het scherm niets. Is de editor of
  een ander formulier wel gewijzigd, dan verschijnt de vertrekdialoog.
  *Opslaan en doorgaan* slaat dan de rest op **zonder** dat formulier, en
  verstuurt het daarna één keer zelf.
- **`data-save-bar-unsaved`** op een begeleidend formulier werkt zoals bij de
  opslagbalk: het formulier begint als gewijzigd.

### Meldingen

De sleutel van een melding wijst haar plek aan, in deze volgorde:

1. **Een veld met die `name`.** De melding komt onder het veld (of het label
   eromheen), het veld krijgt `aria-invalid` en `aria-describedby`, en de
   sectie eromheen gaat open.
2. **Een element met `data-admin-editor-error-for="<naam>"`.** Dit is voor
   een melding over een groep velden, zoals de keuzes van een nieuwe variant.
3. **Een sectie met `data-admin-editor-section="<sleutel>"`.** De melding komt
   in haar `[data-admin-editor-errors]`, en een ingeklapte sectie gaat open.

Alle meldingen staan daarnaast in de samenvatting boven het formulier
(`admin_editor_summary()`, `role="alert"`). Die krijgt de focus, zodat een
schermlezer ze als eerste voorleest. Een browsercontrole (`required`) die
faalt in een dichte sectie, opent die sectie eerst.

### Weggaan met wijzigingen die nog niet zijn opgeslagen

- **Navigatie binnen het CMS** krijgt de eigen dialoog *Niet-opgeslagen
  wijzigingen* (`admin_editor_leave_dialog()`). Dat is een gewone klik op een
  link naar een andere pagina van deze site, of een formulier buiten de editor
  dat wegnavigeert (de taalwissel, *Uitloggen*). De dialoog heeft drie
  antwoorden:
  - *Blijven* staat eerst en heeft de focus;
  - *Zonder opslaan doorgaan*;
  - *Opslaan en doorgaan* gaat pas door als de server alles heeft opgeslagen.
    Bij een geweigerde opslag sluit de dialoog en staan de meldingen er.
- **Het is een native modale `<dialog>`**, net als `admin_confirm_dialog()`.
  De pagina erachter is inert, Tab blijft binnen de dialoog, en Escape of een
  klik op de gedimde pagina betekent *Blijven*. De focus gaat terug naar de
  link die de vraag opriep. Op een telefoon staan de knoppen onder elkaar.
- **Niet onderschept** worden:
  - een link met `target="_blank"` of `download`;
  - een klik met Ctrl, Cmd, Shift of Alt;
  - `mailto:`, `tel:` en een ander domein;
  - een sprong naar een anker op dezelfde pagina;
  - een link met `data-admin-editor-leave` (bijvoorbeeld *Annuleren*: dat
    antwoord is al gegeven).
- **Wat de browser zelf doet** kan alleen de eigen vraag van de browser
  krijgen (`beforeunload`): herladen, het tabblad sluiten, de adresbalk,
  Terug. Geen enkele pagina mag die vraag opmaken. Het is het laatste
  vangnet, en alleen actief zolang er iets niet is opgeslagen.

### Tabbladen in een editor die zonder herladen opslaat

De producteditor deelt zijn ene formulier op in drie tabbladen: *Product*,
*SEO* en *Verzending* (Shop Product & Ordering 2.0). Het zijn de gewone
tabbladen van het CMS (`admin/_admin_tabs.php`, `admin-tabs.js`,
`PAGE-EDITOR.md`), om het formulier heen gezet, niet een formulier per
tabblad:

- **Eén formulier, één *Opslaan*, één dirty-state.** Een wijziging op
  *Verzending* maakt de hele editor gewijzigd, en *Opslaan* in de balk bewaart
  alle drie de tabbladen in één verzoek. Een verborgen paneel verstuurt zijn
  velden gewoon mee.
- **Een melding opent haar tabblad.** Weigert de browser een veld
  (`checkValidity()`) of de server een veldnaam, dan opent `admin-editor.js`
  het tabblad van het eerste veld met een melding
  (`window.AdminTabs.reveal()`) en zet daar de focus. De samenvatting boven
  het formulier noemt alle meldingen, ook die op een ander tabblad.
- **Het tabblad blijft na een opslag staan**: de editor herlaadt niet, en na
  een herlaadbeurt onthoudt `sessionStorage` het tabblad per product (de scope
  van `admin_tabs_start()` is het product-id, of `new`).
- **Wat boven de tabbladen staat, geldt voor alle drie**: de taalbalk van de
  vertaalbare velden en de meldingen. De knop zonder script
  (`data-save-bar-fallback`) staat onder de panelen.
- **Op een telefoon** scrolt de tabstrip horizontaal in plaats van af te
  breken.

### Een volgende editor omzetten

1. Het scherm wordt **één formulier** (de rijen van een lijst als
   `admin/_editor_rows.php` / `row-list.js`, geen formulier per rij), met
   `data-admin-editor` en de onderdelen uit `_admin_editor.php` in plaats van
   `_save_bar.php`.
2. Het endpoint valideert alles vóórdat het schrijft, geeft zijn meldingen een
   veldnaam als sleutel, en antwoordt met `AdminEditorResponse` als
   `wantsJson()`. De redirect voor een formulier zonder script blijft.
3. Wat na een opslag een id krijgt, zit in een `data-admin-editor-region`.
4. Een test zoals `ProductEditorHttpTest`, die hetzelfde verzoek met en zonder
   JSON stuurt.

## Een live voorbeeld naast de instellingen

De paletteneditor (`admin/color-palette.php`) is het eerste scherm waar
instellingen en hun voorbeeld naast elkaar staan en het voorbeeld zonder
request meebeweegt. Wie dat voor een volgende instelling wil (lettertypes,
knopstijlen — gebouwd: `admin/button-style.php`), volgt dezelfde afspraken:

- **Het voorbeeld is een eigen document** in een `<iframe>`, met de echte
  sitestylesheets (`PageAssets`), nooit de site-CSS in het CMS. Het document
  heeft een Content-Security-Policy zonder script en zonder formulier.
- **`sandbox="allow-same-origin"` en verder niets**, zodat het script van het
  scherm de tokens in het frame kan zetten (`element.style.setProperty()`).
  Nooit samen met `allow-scripts`.
- **Het script rekent niets zelf uit** wat de server ook uitrekent: de server
  geeft de regels mee als data (hier `ThemePalette::recipe()` in
  `data-palette-model`), het script voert ze uit (`MygdalaTheme` in
  `admin/assets/theme-admin.js`).
- **Opslaan blijft opslaan**: de opslagbalk (`_save_bar.php`) toont de
  niet-opgeslagen staat en waarschuwt bij weggaan, *Annuleren* is een link met
  `data-save-bar-discard`, het formulier heeft `autocomplete="off"` en het
  script zet bij het laden elk veld terug op zijn `defaultValue`.
- **Layout**: `.admin-palette-editor` is twee kolommen met `minmax(0, …)`,
  het voorbeeld `position: sticky`. Onder 1100 px stapelen ze, met het
  voorbeeld eerst als lage plakkende strook. Een formulier dat de volle
  breedte nodig heeft, heft de 720 px van `.admin-product-form` op met een
  eigen modifier (`.admin-palette-form`).

## Afbeeldingsweergave

Eén veld voor hoe een beeld in zijn kader valt, op elke plek die bijsnijdt
(`MEDIA.md`, *Responsive Media*): `responsive_image_field()` in
`admin/_responsive_image_field.php`, met `admin/assets/responsive-image.js` en
de regels `.admin-rm` in `admin.css`. Het staat op een kaart van de
Kaarten-carrousel, een item van Tekst met afbeelding, de Paginakop, de
achtergrond van een Oproep met knop, de Mediabanner, een kaart van Hover
kaarten (de hoofdafbeelding), de Homepage-hero (alleen bij een afbeelding) en
elk galerij-item van een Detailsectie (alleen focuspunt en zoom, `'mobile' =>
false`, legend *Focuspunt*).

Compact per kader: het voorbeeld, de negen punten, de schuiven *Horizontaal*,
*Verticaal* en *Zoom* met hun waarde, en één knop *Afbeelding resetten*. De
korte hint staat onder de schuiven; de langere uitleg (slepen = welk deel van
de foto centraal staat, zoom = hoe ver je inzoomt, resetten = midden en 100%)
zit achter de helpknop van het veld.

- **Focuspunt.** Het voorbeeld is een kader met de vorm van de plek
  (`--admin-rm-desktop-ratio`, gezet door het scherm of met `:has()` uit de
  keuzes van hetzelfde formulier: de hoogte van een Paginakop, de vorm van een
  Hover-grid). Je sleept de afbeelding in het kader: naar rechts slepen brengt
  meer van haar linkerkant in beeld. Pointer events, dus muis, vinger en pen;
  `touch-action: none`, zodat een vinger niet de pagina schuift; de pointer
  wordt vastgehouden tot hij loslaat, en het kader tekent hoogstens één keer
  per animatieframe. Alleen de richting waarin de afbeelding groter is dan het
  kader beweegt iets; bij een zoom boven 100% is het beeld in beide richtingen
  groter dan het kader, dus beweegt het in beide, en een pixel slepen is
  een pixel beeld. Ernaast staan de negen punten van één klik, elk met
  `aria-pressed`; ze werken bij elke zoom.
- **De twee schuiven zijn de waarde.** *Horizontaal* en *Verticaal*, 0–100 in
  stappen van 1, met `aria-valuetext` ("37%"). Het toetsenbord en een
  schermlezer gebruiken die (pijltjestoetsen, Page Up/Down, Home/End); het
  kader zelf is `aria-hidden`. Onder de schuiven staat de waarde in woorden
  (`aria-live="polite"`). Slepen en de negen punten zetten alleen de schuiven
  en sturen dezelfde `input` en `change`, dus de opslagbalk hoort één gewone
  wijziging.
- **Zoom** (Responsive Media 3.0): een derde schuif, 100–200% in stappen van
  1, met de waarde ernaast (`<output>`, "125%") en als `aria-valuetext`. Het
  voorbeeld verandert direct, zonder verzoek naar de server: dezelfde CSS
  `scale` rond het punt als de site (`MEDIA.md`, "Zoom"), in een kader dat
  afknipt. *Afbeelding resetten* (een tekstknop, pas zichtbaar met het
  script) zet het punt op 50/50 en de zoom op 100%, als één gewone wijziging.
  Bij *Hele afbeelding* verdwijnt de zoomrij (`.is-contained .admin-rm__zoom`);
  de waarde blijft in het formulier en wordt gewoon opgeslagen, dus terug
  naar *Vullen* brengt de oude zoom terug. Het telefoonkader heeft zijn eigen
  zoom, die hoort bij het eigen telefoonpunt. Wiel- of knijpzoom is bewust
  niet gebouwd: de schuif is voorspelbaar en toegankelijk.
- **Weergave in het kader**, waar de plek die heeft: *Vullen* of *Hele
  afbeelding* als `.admin-segmented`. Bij *Hele afbeelding* toont het kader
  de hele afbeelding en valt er niets te slepen.
- **Op een telefoon**: een ingeklapte `<details>`. De samenvatting zegt of een
  telefoon hetzelfde toont (*zoals op een groot scherm*) of *eigen
  instellingen*; met eigen instellingen staat hij open. Daarin *Afbeelding op
  een telefoon* (*Gebruik desktopafbeelding* of *Eigen afbeelding*, met een
  tweede mediakiezer), *Mobiel focuspunt apart instellen* (een switch; met een
  eigen afbeelding heeft een telefoon altijd een eigen punt), het tweede kader
  in de vorm van de plek op een telefoon van 375px
  (`--admin-rm-mobile-ratio`), *Weergave op een telefoon* (*Zoals op een
  groot scherm*, *Vullen*, *Hele afbeelding*) en, waar het blok een hoogte heeft,
  *Hoogte op een telefoon* (*Automatisch*, *Compact*, *Normaal*, *Groot*).
  Alleen wat geldt is zichtbaar; de server print dezelfde `hidden`.
- **Zonder JavaScript** zijn de schuiven, de keuzes en de kiezers gewone
  formuliervelden; het voorbeeld en de negen punten verschijnen pas met het
  script. Er is geen animatie in het veld: slepen volgt de pointer direct, dus
  *minder beweging* vraagt hier niets extra.
- **Een rij die later komt** (*Item toevoegen*, *Kaart toevoegen*): het script
  is gedelegeerd en wekt een nieuw veld bij `row-list:added`.
- **Licht op een lang scherm.** Het voorbeeld is de thumbnail, met
  `loading="lazy"`; het script wekt een veld pas als het binnen één
  schermhoogte komt (`IntersectionObserver`, `data-rm-ready`) of als iemand
  het eerder bereikt, en alle velden delen één set listeners op `document`
  (`MEDIA.md`, "Licht in het CMS").
- **Een beeld dat niet uit een mediakiezer komt** (de foto van een product,
  project of blogbericht in een Detailsectie-galerij): een ander script
  (`admin/assets/gallery-source.js`, dat de foto live opvraagt bij
  `api/admin/linked-image-preview.php`) stuurt vanuit de rij een
  `rm:picture`-event met de URL in `detail.src`; het kader toont hem, of
  verbergt zich bij `''`. Is het item openbaar maar zonder hoofdafbeelding
  (`"picture": false`), dan toont de rij de waarschuwing
  `[data-linked-image-no-picture]` (Detailsectie 2.1).
- **Teksten** komen uit `media.responsive.*` (Nederlands en Engels); het
  script heeft geen eigen woorden (`data-rm-value-template`,
  `data-rm-zoom-template`).
- **Een melding** staat per onderdeel bij het veld (`presentation.<onderdeel>`
  in de foutenlijst van het scherm).

## Waar het al gebruikt wordt

De schermen hieronder, als bewijs dat de bouwstenen herbruikbaar zijn. De rest van het
CMS volgt scherm voor scherm; een scherm dat nog niet is omgezet, werkt zoals
het werkte.

| Scherm | Wat |
|---|---|
| Mediabibliotheek (`admin/media.php`) | De upload is `admin_file_input()` met `multiple`; het sleepvak, de lijst met nieuwe bestanden en de voorbeelden eromheen zijn van dat scherm zelf, en nieuwe bestanden komen in de map die open staat (`MEDIA.md`). Links de mappen als links (`?folder=`, *Alle media*, *Geen map*, elke map met zijn aantal; op een smal scherm een doorlopende rij erboven), met *Nieuwe map*, en bij een open map *Map hernoemen* (een `<details>`) en *Map verwijderen* in `admin_confirm_dialog()`. Zoekveld (`?q=`, blijft binnen de open map) en de soort bestand als `.admin-select` (`?type=`), die het raster verversen zonder te herladen. *Raster* \| *Lijst* als twee knoppen met `aria-pressed` (`.admin-media-view`), onthouden in `localStorage`. Per kaart een `.admin-checkbox` om meerdere bestanden tegelijk te selecteren; de selectiebalk verplaatst naar een map (`.admin-select`) of verwijdert. *Bewerken* op een kaart opent *Media bewerken* (naam en alt-tekst, één opslag). Het itemscherm heeft één formulier *Naam en alt-tekst* onder de opslagbalk. *Verwijderen* op een item vraagt eerst in `admin_confirm_dialog()`; een selectie verwijderen heeft een eigen dialoog, omdat die per keer toont wat er echt weggaat en wat blijft staan |
| Lettertypen (tabblad van `admin/theme.php`) en een lettertype (`admin/font-family.php`) | Twee tabbladen op Vormgeving (`admin_tabs_start()`: *Kleuren en stijl*, *Lettertypen*), het tweede geopend na een verwijdering of weigering en met `?tab=lettertypen`. De licentiewaarschuwing als `.admin-alert--warning` (altijd zichtbaar waar een bestand gekozen wordt), per familie de naam in haar eigen letter, *In gebruik*/*Niet in gebruik* als badge met wie haar gebruikt, *Bestand ontbreekt* als `.admin-badge--canceled`, *Verwijderen* alleen voor een ongebruikte familie en in `admin_confirm_dialog()`. De handleiding als vier `<details>` in de stijl van de inklapbare kaarten (`admin_font_help()`, teksten `help.fonts.*`), geen lappen tekst onder de velden. De editor: uitleg bij naam, soort (`.admin-select`), bron en bestanden; de bestandskiezer (`admin_file_input()` met `multiple`) krijgt per bestand een regel met een variantkeuze (`.admin-select`, voorgesteld uit de bestandsnaam) en een voorbeeldregel in dat bestand vóór het uploaden (`admin/assets/font-library-admin.js`); per variant *Vervangen* (een `<details>` met een eigen bestandskiezer) en *Verwijderen* in `admin_confirm_dialog()`; het voorbeeld (kop, tekst, vet, cursief, cijfers, Nederlandse tekens); *Gebruikt door* met links; *Lettertype verwijderen* of de reden waarom niet. Op *Kleuren en stijl* → Typografie *Lettertype voor koppen* en *voor lopende tekst* (`admin_font_role_select()`, met uitleg), alleen met een gevulde bibliotheek, en het previewframe van de kleurenpaletten (`sandbox="allow-same-origin"`) dat bij elke keuze herlaadt (`admin/assets/theme-fonts-admin.js`); dezelfde twee velden in de paginathema-editor |
| Knoppen (tabblad van `admin/theme.php`) en een knopstijl (`admin/button-style.php`) | Het derde tabblad op Vormgeving (`?tab=knoppen`, geopend na een actie of weigering). Infobalk over de twee standaarden; per stijl een badge *Standaardknop*, *Standaard tweede knop* of *Beschikbaar* en het aantal knoppen dat hem koos; *Maak standaardknop* en *Maak standaard tweede knop* in `admin_confirm_dialog()`, *Dupliceren*, en *Verwijderen* alleen voor een ongebruikte stijl (anders de reden). De editor volgt "Een live voorbeeld naast de instellingen": links de keuzes als `.admin-select` per gesloten lijst, `.admin-checkbox` voor glans, hoofdletters, onderstrepen en meebewegen, een kleur als `admin_button_color_field()` (themakleur of vaste kleur met kleurkiezer), velden die bij de gekozen weergave niet horen verborgen (`data-button-when`); rechts het sticky voorbeeld (`admin/button-style-preview.php`, `admin/assets/button-style-admin.js`) in elke toestand en op licht en donker. In elke blokeditor met een knop het veld *Knopstijl* (`admin_button_style_field()`, met uitleg), in de groep van de knop |
| Vormgeving (`admin/theme.php`) en een kleurenpalet (`admin/color-palette.php`) | Infobalk bij *Kleurenpaletten* over paginathema's; per palet een badge *Actief*/*Inactief*; *Activeren* en *Verwijderen* in `admin_confirm_dialog()`, het actieve palet zonder verwijderknop maar met de reden; de editor met uitleg bij de naam, het kleurveld (`_theme_color_field.php`), de contrastwaarschuwing, het live voorbeeld ernaast, de opslagbalk en *Annuleren* (`data-save-bar-discard`) |
| Instellingen (`admin/settings.php`) | Infobalk bij *Algemeen* en bij *Adresgegevens*; uitleg bij naam van de website, e-mailadres, telefoonnummer, plaats, plaats en land van het adres, KVK-nummer, standaardtaal, standaard meta description en indexeren. De standaardtaal is een `.admin-select`, indexeren een switch |
| Shop-instellingen (`admin/shop-settings.php`) | Infobalk per tabblad; uitleg bij elk veld; één `?` bij *Invulvelden* die elk invulveld van de bestelmail uitlegt, opgebouwd uit `EmailPlaceholders::KNOWN`; *Herstel standaardtekst* als `.admin-btn-secondary` (`admin/assets/shop-settings.js`); in het tabblad *E-mails* de kaart *Terug op voorraad*, een eigen formulier met de taalbalk (onderwerp en tekst per websitetaal), de invulvelden uit `EmailPlaceholders::STOCK`, *Herstel standaardtekst*, daaronder hoeveel meldingen er wachten en klaarstaan, een `.admin-alert--warning` als er mails mislukt zijn, en *Wachtende meldingen nu versturen*; het tabblad *Productoverzicht* met één `.admin-select` (*Geen overzichtspagina* of een bestaande pagina, een concept gemarkeerd) met uitleg, en een melding als de gekozen pagina nog een concept is of nog geen blok *Productgrid* heeft (`MODULES.md`, "Shop"). Het bestel- en het factuurprefix krijgen hun `pattern` uit `App\Service\DocumentNumberPrefix`; een factuurprefix van vóór die regel krijgt geen `pattern` (zodat hij het opslaan van de andere factuurteksten niet blokkeert) maar een waarschuwing die zegt wat nieuwe facturen gebruiken |
| Betalingen (`admin/payments.php`) | Alleen met `payments.manage`, dat alleen een Super Admin kan toekennen; zonder die permissie staat Betalingen niet in het menu. De dynamische editor (hierboven) met één formulier voor de twee API-sleutels, de modus en de betaalmethoden, en daarbuiten vier kaarten. *Status* met een badge met woord én kleur (`.admin-payments-badge`: *Niet ingesteld* grijs, *Testmodus* amber gevuld, *Live* groen, *Probleem* rood) en een gekleurde linkerrand, feiten als `<dl>` (op een telefoon onder elkaar) en *Verbinding testen*. Werkt de live-sleutel maar is het webadres geen publiek https-adres, dan staat er *Probleem* met een `.admin-alert--error` die zegt waarom en wat te doen. *Mollie koppelen, stap voor stap*: zes `<details>` in de stijl van de inklapbare kaarten met *Klaar* (`.admin-badge--paid`) of *Nu* (`.admin-badge--pending`), de eerste onafgemaakte open, externe links met `target="_blank"` en `rel="noopener noreferrer"` alleen naar mollie.com en my.mollie.com. De sleutelvelden zijn `type="password"`, altijd leeg, met uitleg, de gemaskeerde opgeslagen sleutel erboven en *Test deze sleutel* ernaast (op een telefoon eronder); met een sleutel in de serveromgeving staat er in plaats daarvan *Geconfigureerd via serveromgeving*. De modus als `.admin-segmented` met uitleg; de betaalmethoden als `.admin-checkbox` per methode met *Beschikbaar bij Mollie* of *Niet beschikbaar bij Mollie* als badge. Status, stappenplan, sleutels, modus, methoden en testbetaling zijn regio's (`data-admin-editor-region`), zodat een opslag ze meteen bijwerkt. *Een testbetaling doen* en *Webhook* (het adres als `<code>` met *Kopiëren*, alleen zichtbaar als de browser een klembord-API heeft) staan buiten het formulier. Een bevestiging bij het vervangen van de live-sleutel is een switch in het formulier, niet de dialoog: de balk van de editor verstuurt geen `submit` die de dialoog zou kunnen opvangen, en de server is de poort (`MODULES.md`, "Betalingen") |
| Bestelling (`admin/order.php`) | Alleen-lezen, zonder opslagbalk of dirty-state. In de kaart *Factuur* een regel uitleg en *Factuur bekijken* als `.admin-btn-link` met `target="_blank"` en `rel="noopener"`, zodat de bestelling open blijft, met *Download PDF* als `.admin-btn-secondary` ernaast (`.admin-invoice-actions`). Beide staan er alleen als de bestelling een factuur heeft; wat de knop toont staat in `MODULES.md`, "Shop". Een testbestelling heeft de badge *TEST* naast het nummer in de kop, een `.admin-alert--warning` eronder, en in de kaart *Factuur* alleen "Testbestelling — er wordt geen echte factuur uitgegeven." en *Bevestigingsmail opnieuw versturen*, nooit een knop die een factuur maakt |
| Bestellingen (`admin/orders.php`) en *Recente bestellingen* op het dashboard | Een testbestelling (`orders.payment_mode = 'test'`) krijgt `.admin-badge--test` naast het nummer: het woord *TEST* in amber (`--admin-warning`, `--admin-warning-soft`), vet en gespatieerd, zodat hij ook zonder kleur leesbaar is. Hij blijft in de lijst; alleen de omzet laat hem weg (`MODULES.md`, "Betalingen") |
| Producten (`admin/products.php`) | *Raster* \| *Lijst* als twee knoppen met `aria-pressed` (`.admin-view-toggle`, dezelfde vorm als `.admin-media-view`), verborgen tot `admin/assets/product-overview.js` draait en onthouden in `localStorage`. Eén kaartmarkup: thumbnail (of *Geen foto*), naam, status als badge, prijs of *Op aanvraag*, en de voorraadregel als badge in een bestaande tint (`App\Service\Inventory\StockSummary`: *Onbeperkt*, *12 op voorraad*, *Uitverkocht*, *4 varianten · 1 uitverkocht*). In de lijst een compacte rij met *Bewerken*, *(De)activeren* en *Verwijderen* in een kolom van vaste breedte; onder 1000 px loopt de regel achter de naam door, onder 700 px wordt het een kaartrij zonder zijwaarts scrollen |
| Product en collectie (`admin/product-form.php`, `admin/collection.php`) | Het product is de dynamische editor (hierboven, "Een editor die opslaat zonder te herladen"): één formulier van kaarten met één *Opslaan* in de balk, zonder herladen, verdeeld over de tabbladen *Product*, *SEO* en *Verzending* (hierboven, "Tabbladen in een editor die zonder herladen opslaat"); de collectie heeft de opslagbalk. *Verkoopmodus* is een `.admin-select` (*Direct bestellen* of *Op aanvraag*) met uitleg; een product op aanvraag heeft in de productlijst de badge *Op aanvraag*. *Voorraad* (`admin/_product_inventory.php`, `admin/assets/product-inventory.js`) heeft *Voorraad bijhouden* als switch met uitleg en, zonder varianten, het aantal; met varianten staat het aantal per variant in zijn kaart. De status staat ernaast als badge met woord én kleur: *Voorraad niet bijgehouden* (grijs), *12 op voorraad* (`.admin-badge--in-stock`, groen) of *Uitverkocht* (`.admin-badge--sold-out`, rood); met varianten vat een regel samen hoeveel er uitverkocht zijn. *Specificaties* (`admin/_product_specifications.php`) en *Bestelvelden* (`admin/_product_order_fields.php`, `admin/assets/product-order-fields.js`) zijn rijen zoals de varianten (`row-list.js`, ↑ ↓ en *Verwijderen*); een bestelveld kiest zijn soort als `.admin-select` (ook *Afbeelding uploaden*), heeft *Verplicht* als switch, keuzes als eigen rijen die alleen bij keuzerondjes en dropdown zichtbaar zijn, en bij een afbeelding *Maximale bestandsgrootte* als `.admin-select` (*Standaard (10 MB)*, 2, 5 of 10 MB; een regel eronder als de server minder aanneemt) met een uitleg dat de afbeelding privé bij de bestelling staat. Een vraag toont alleen wat zijn soort gebruikt: `.admin-product-order-fields [hidden]` herstelt het attribuut tegen de eigen `display` van `.admin-field`. Op een product staan *Afbeeldingen* en *Varianten* elk in een eigen kaart die inklapt (`.admin-collapse--card`, met het aantal in de kopregel; open of dicht onthoudt het tabblad, `admin-collapse.js`). *Afbeeldingen* is alleen de pool van het product als raster (`admin/_product_gallery.php`, `admin/assets/product-gallery.js`): *Afbeelding toevoegen* opent de mediakiezer, ← en → per afbeelding (knoppen met een `aria-label` dat de afbeelding noemt, en een live regio die de nieuwe positie zegt) en slepen met de muis veranderen dezelfde volgorde, × haalt hem weg. Daaronder *Overgang productgalerij* als `.admin-select` met uitleg: *Standaard van Shop (…)*, dat de huidige Shop-standaard noemt en NULL opslaat, of *Geen*, *Vervagen* of *Schuiven*. Het is een gewoon veld van het ene formulier: wijzigen maakt de editor dirty, het gaat mee met de ene *Opslaan*, en een geweigerde waarde komt als melding bij het veld (`gallery_transition`). Het staat buiten de regio die na opslaan opnieuw getekend wordt. *Varianten* (`admin/_product_variants.php`, `admin/assets/product-variants.js`): de opties als rijkaarten (`.admin-row-card`) met hun waardes, *Optie toevoegen*, *Waarde toevoegen* en *Variant toevoegen* als rijen op het scherm (`row-list.js`), ↑ ↓ en *Verwijderen*; een optie of waarde die een variant gebruikt en een variant waar een bestelling naar wijst hebben een uitgeschakelde *Verwijderen* met een regel die zegt waarom. Per variant de prijs, *Actief* als switch, een eigen strook met dezelfde bediening als de pool, de productafbeeldingen als tegels om aan te vinken (`aria-pressed`) en *Eigen beschrijving voor deze variant* als switch; een nieuwe variant kiest per optie een waarde uit wat er op het scherm staat. Op een collectie de afbeelding met de mediakiezer, en per product *Bewerken* naar de producteditor voor wie producten mag beheren. De deel-afbeelding op beide is de mediakiezer in deel-afbeeldingsmodus (`MediaType::SOCIAL_IMAGE`); een oude eigen upload staat erboven met *Deel-afbeelding verwijderen* |
| Specificaties (`admin/product-specifications.php`) | Shop → *Specificaties*: de bibliotheek van eigenschappen als rijen van één dynamische editor (hierboven), met naam per websitetaal (de taalbalk), eenheid, ↑ ↓ en *Verwijderen*, en bij een gebruikte rij hoeveel producten hem invullen en dat *Verwijderen* die waarden meeneemt |
| Pagina's (`admin/pages.php`) | De systeempagina van een module (`MODULES.md`, "Systeempagina's van modules") draagt de badge *Systeempagina Shop* of *Systeempagina Portfolio* en, als die module uit staat, *Module staat uit*; zonder *Verwijderen*, en met de module uit zonder *Bekijken*. Houdt een andere pagina het woord van een ontbrekende systeempagina vast, dan staat bovenaan een `.admin-alert--warning` met een link naar die pagina. Infobalk; zoekveld (`?q=`, filtert de al geladen lijst via `PageContent::matchesAdminSearch()` en toont een treffer met elke pagina erboven, gemarkeerd als *bovenliggend*); knoppen uit de familie; de status als badge met woord én kleur: `.admin-badge--draft` (amber, `--admin-warning`) en `.admin-badge--published` (groen, `--admin-success`). De lijst is een boom in twee groepen, *Websitepagina's* (open) en *Service & juridisch* (dicht, met aantal): een knop met `aria-expanded` per groep en per pagina met onderliggende pagina's, ingesprongen per niveau, in- en uitklappen zonder herladen en onthouden in `localStorage` (`admin/assets/page-tree.js`). Elke rij even hoog in een tabel met vaste kolommen (titel, status, type, acties; nooit zijwaarts scrollen): de naam en op een eigen regel het publieke adres, altijd zichtbaar en met een weglatingsteken als het niet past; dan op één regel *Bewerken* (`.admin-section-row__edit`, de hoofdactie), *Bekijken* (of *Voorbeeld* voor een concept) en *Subpagina toevoegen*, en *Verwijderen* alleen in het menu *…* van de rij (hieronder), dat eerst vraagt in `admin_confirm_dialog()` of uitgeschakeld zegt waarom niet. Op een smallere boom staan de acties op twee regels (*+ Subpagina*), op een telefoon is elke pagina een kaart (container queries, `docs/pages/NESTING.md` §8) |
| Formulieren (`admin/forms.php`, `admin/form.php`) | In het overzicht een infobalk en de status als badge met woord én kleur: `.admin-badge--paid` voor *Actief*, `.admin-badge--draft` voor *Inactief*. In de editor staat *Actief* bovenaan als switch; uitleg bij *Actief*, de naam, het bedankbericht en het e-mailadres voor de melding. *Inzendingen bewaren in het CMS* (switch) en *Antwoordadres van de melding* (`.admin-select`) staan onder *Geavanceerd*, een `<details>` in de stijl van de inklapbare rijen (`.admin-collapse--card`) met in de kop of inzendingen bewaard worden; hij opent na een geweigerde opslag. De editor heeft de opslagbalk. *Veld toevoegen* is een native `<dialog>` met radiokaarten (`.admin-template-card`) en het label met uitleg; zonder script rendert de link hem open in de pagina. Elke radio heet alleen het type (`aria-labelledby`) en krijgt de uitleg als beschrijving (`aria-describedby`). De velden staan als compacte rijen in de stijl van *Header & navigatie* (`.admin-section-row`, ↑ en ↓ als `.admin-row-move`, *Bewerken* als `.admin-section-row__edit`, *Verwijderen* als `.admin-btn-danger`), met een tweede regel voor type, verplicht, breedte en opties of, bij een uploadveld, de toegestane soorten en de maximale grootte. Naast de velden staat het voorbeeld (`admin/form-preview.php`) in een eigen frame, met de breedteknoppen van de blokbibliotheek (`.admin-block-preview__viewport`: *Desktop* en *Mobiel*). De eigen knop *Opslaan* van de editor en de veldeditor draagt `data-save-bar-fallback`: met script is de opslagbalk de enige knop. De veldeditor (`admin/form-field.php`) heeft uitleg bij label, uitleg en voorbeeldtekst, *Verplicht invullen* als switch, *Breedte in het formulier* als `.admin-select`, bij *Bestand uploaden* de kaart *Bestanden* met een `.admin-checkbox` per toegestane soort (naast elkaar, `.admin-form-file-types`) en *Maximale grootte* als `.admin-select` — nooit een vrij tekstveld en nooit een bestandskiezer, *Ander soort veld kiezen* en *Technische gegevens* als `<details>` in de stijl van de inklapbare rijen, ↑ en ↓ per optie als `.admin-btn-ghost`, en de opslagbalk. Een inzending (`admin/form-submission.php`) noemt een bestand bij zijn veld als downloadlink met soort en grootte, en *Bestand ontbreekt* als badge wanneer het van schijf weg is; een lange bestandsnaam breekt af (`.admin-submission-file`). Het offerte-/contactblok (`admin/contact-form.php`) heeft geen bijlageschakelaar meer, alleen de zin waar je een uploadveld toevoegt. Veld, formulier en inzending verwijderen vraagt eerst in `admin_confirm_dialog()` (`FORMS.md`) |
| Pagina bewerken en Nieuwe pagina (`admin/page.php`, `admin/page-new.php`) | De *Titel* en de SEO-velden zijn velden per websitetaal (`admin/_localized_fields.php`): één regel *Taal: …* boven de tabbladen met de badge *standaardtaal*, alleen de velden van die taal, niets van een andere taal verborgen meegestuurd; een nieuwe pagina schrijft in de standaardtaal en het adres komt uit die titel. Uitleg bij *Webadres* (het woord *slug* staat alleen in die uitleg); op een bestaande pagina het adres als link en het veld achter *Webadres wijzigen*, een `<details>` in de stijl van de inklapbare rijen; op een nieuwe pagina een live voorbeeld van het hele adres. *Bovenliggende pagina* als `.admin-select` met uitleg (de boom ingesprongen, zonder de pagina zelf en alles eronder), *Beheergroep* als segmentkeuze met uitleg voor een hoofdpagina of de regel welke groep een geneste pagina volgt, en het pad dat een opslag oplevert, bijgewerkt terwijl je kiest en typt (`admin/_page_placement.php`, `admin/assets/page-placement.js`, `docs/pages/NESTING.md`); een pagina met een vaste URL zegt dat ze altijd op het hoogste niveau staat. Onder *Status* staat *Kruimelpad tonen op deze pagina* als switch met uitleg, behalve op de homepage (`HEADER-FOOTER.md`). SEO: een infobalk over wat SEO is, en uitleg bij de SEO-titel (met de automatische titel) en bij de *Omschrijving voor zoekmachines*. Op *Nieuwe pagina* staat de SEO-kaart vóór *Template* en klapt hij dicht (`.admin-collapse--card`). In de blokkenkiezer het zoekveld (`.admin-search`); op elke blokrij *Verbergen*/*Tonen* (`.admin-btn-secondary`) en *Verwijderen* (`.admin-btn-danger`), dat eerst vraagt in `admin_confirm_dialog()` |
| Portfolio (`admin/portfolio.php`, `admin/portfolio-item.php`) | Infobalk; in het overzicht het zoekveld (`.admin-search`) en de filters als `.admin-select`, met *Zonder categorie*; op een item de mediakiezer (een afbeelding uit de bibliotheek, of een nieuwe die daar geüpload wordt; een afbeelding van vóór de bibliotheek staat erboven tot er een andere wordt gekozen) en uitleg bij afbeelding, alt-tekst, titel, onderschrift en categorieën; *Categorieën* (`.admin-checkbox`, onder elkaar) en *Zichtbaarheid* (*Zichtbaar op de portfolio-pagina* als switch) als twee kaarten naast elkaar (`.admin-card-pair`, één kolom onder 900px); de kaart *Projectpagina* met *Projectpagina tonen* als switch, het webadres met voorbeeld (uit de titel ingevuld zolang er geen is, het gedeelde `data-slug-target`), en *Inleiding* en *Beschrijving* als rich text; de kaart *Galerij* met de mediakiezer in verzamelmodus en de strip van de producteditor (← → ×, slepen, een live regio); de kaart *Gerelateerde projecten* als `<details>` in de stijl van de inklapbare kaarten (`.admin-portfolio-collapsible`, met *Aan*/*Uit* als badge in de kop): *Gerelateerde projecten tonen* als switch, *Selectie* als `.admin-segmented`, *Maximum aantal*, *Volgorde*, *Als er te weinig projecten uit dezelfde categorie zijn* en *Kaarten* als `.admin-select`, *Korte tekst op de kaarten tonen* als switch, de projectkiezer (`admin/_item_picker.php`: zoekveld, rij met `.admin-checkbox`, miniatuur, titel, categorieën, *Verborgen* als `.admin-badge--canceled`, ↑ ↓ als knoppen met een `aria-label` dat het project noemt, slepen, een live regio) en titel en introtekst van die taal; alleen bij een item met een legacy-koppeling de kaart *Gekoppelde pagina* met *Koppeling met deze pagina verwijderen*. Het item heeft de opslagbalk. Er is geen paginakeuze, geen *Nieuwe pagina maken* en geen *Toon op homepage* meer; het overzicht heeft geen *Homepage-uitlichting* en geen homepagefilter. Verwijderen vraagt eerst in `admin_confirm_dialog()` |
| Pagina-inhoud van een product en een project (`admin/product-form.php`, `admin/portfolio-item.php`, `admin/_content_blocks.php`) | Een eigen tabblad *Pagina-inhoud* voor wie het product (`products.manage`) of project (`portfolio.manage`) mag beheren, zonder `pages.manage` (`CONTENT-BLOCKS.md`, "Wie mag welke blokken beheren"); elke blok-editor linkt terug naar dat tabblad (*← Terug naar Product: …*, *Project: …*), met precies de bloklijst van een pagina (rijen, *Verbergen*/*Tonen*, *Verwijderen* in `admin_confirm_dialog()`, slepen, de blokkenkiezer) buiten het formulier van het product of project. Op een project staat daarboven de kaart *Projectlayout* (`.admin-select` met uitleg, *Gebruik standaardinstelling (…)* noemt de huidige standaard), die met het project zelf wordt opgeslagen; bij *Vrije indeling* zegt de regel eronder wat het blok *Projectinformatie* doet, en zonder dat blok staat er een `.admin-alert--warning` *Deze vrije indeling bevat geen Projectinformatie-blok.* (niet blokkerend: opslaan kan). De standaard staat in de kaart *Instellingen* op `admin/portfolio.php`. Het blok *Projectinformatie* (`admin/project-info.php`) heeft alleen *Afbeelding* (`.admin-select`) en *Meer afbeeldingen tonen* (switch), een link naar de projectgegevens, en een `.admin-alert--info` zolang het project geen vrije indeling heeft (`CONTENT-BLOCKS.md`, "Blokken op een product of project") |
| Detailsectie (`admin/detail-section.php`) | Uitleg bij anker, navigatielabel, slotnotitie en de CTA in de help-knop van het veld, en een infobalk bij *Hoofdafbeelding*, *Kenmerken* en *Galerij*, in plaats van alinea's onder elk veld. *Beeldpositie* staat in de kaart *Hoofdafbeelding* en alleen zolang er een hoofdafbeelding is (`admin/assets/detail-section.js`; de server print dezelfde `hidden`). Een galerij-item is een inklapbare rij ("Item 3 — Product: …") en kiest eerst zijn *Afbeeldingsbron* (`.admin-select`: *Mediabibliotheek*, of een product, portfolioproject of blogbericht; het formulier draagt `data-nav-item-form`, zodat alleen dat paneel in beeld staat) en toont dan alleen die kiezer: de mediakiezer met alt-tekst, of de doorzoekbare lijst van de bestemmingskiezer met foto en status (`admin/_gallery_source_field.php`, dezelfde `data-nav-link-*`-wissel en `destination-picker.js`). Een keuze die niet meer openbaar of niet meer te kiezen is, of van een module die uit staat, blijft bewaard met een regel die zegt waarom. Een ongeldig of dubbel anker krijgt zijn melding bij het veld (`CONTENT-BLOCKS.md`, "Detailsectie 2.0"). Boven de titel *Nummer / label* (dezelfde keuze als op een carrouselkaart, zonder *Icoon*; de help zegt dat het niet de ankernavigatie is). Elk galerij-item heeft onder zijn bron de *Afbeeldingsweergave* met alleen het *Focuspunt* in een vierkant kader (hieronder), zonder telefoondeel; het kader volgt de bron van de rij. De help bij *Beeldpositie* zegt dat die de plek van de hoofdafbeelding is en geen focuspunt |
| Projecten en galerij (`admin/project-cards.php`, `admin/item-gallery.php`) | Eén formulier met de opslagbalk. De keuze van de projecten (`admin/_gallery_selection.php`): *Bron*, *Categorie* (naam en aantal zichtbare projecten) en *Volgorde* als `.admin-select` met uitleg, bij *Handmatige selectie* dezelfde projectkiezer als bij een item en *In willekeurige volgorde tonen* als `.admin-checkbox`, met een regel die zegt hoeveel er gekozen zijn en hoeveel het blok toont; bij een verwijderde categorie een `.admin-alert--warning`. Op Projecten is *Maximum aantal projecten* een `.admin-select` (*Alles*, 3, 4, 6, 8, 12, plus een eerder opgeslagen ander getal) |
| Kaarten-carrousel (`admin/card-carousel.php`, `admin/carousel-card.php`) | Eén formulier per scherm, met de opslagbalk (`PAGE-EDITOR.md`, "Eén formulier per blok-editor"). De kaarten als tegels uit het Portfolio-overzicht (`.admin-portfolio-grid`): afbeelding, titel, *Actief* als switch per kaart, *Bewerken* (`.admin-btn-secondary`), ← en → als `.admin-btn-ghost` met een `aria-label` dat de kaart noemt, en *Verwijderen*, dat de kaart markeert tot er opgeslagen wordt. *Carrousel tonen op de pagina* als switch met uitleg, de weergave op grotere schermen als radiokaarten (`.admin-template-card`) en *Beeldverhouding op een rij* als `.admin-segmented`. Op een kaart: *Actief* als switch met uitleg, *Labelweergave* als `.admin-select` met uitleg (*Geen label*, *Nummering 01, 02, 03…*, *Nummering 1, 2, 3…*, *Icoon*, *Eigen tekst*; `admin/_label_mode_field.php`, dat bij *Eigen tekst* het tekstveld per taal en bij *Icoon* de mediakiezer voor een SVG toont, `admin/assets/label-mode.js`, de server print dezelfde `hidden`), uitleg bij knop en adres, de bestemming van de knop met de bestemmingskiezer (hieronder; alleen de soorten waarvan de module aan staat, de velden verbergt `admin/assets/navigation-item.js`), de afbeelding met de mediakiezer en de *Afbeeldingsweergave* (hieronder; de kaders hebben de vorm die de kaart krijgt), en de tags als compacte rijen (`.admin-option-row--plain`) met ↑, ↓ en × en één taalbalk voor alles |
| Tekst met afbeelding (`admin/text-image-split.php`) | Eén formulier met de opslagbalk en *Actief* als checkbox. De items als rijen (`.admin-row-card`, `admin/_editor_rows.php`) met ↑, ↓ en *Verwijderen* als markering, en *Item toevoegen*. Per item de tekst in de gedeelde rich-texteditor (`editor_row_rich()`), de afbeelding met de mediakiezer (met *Wissen*) en de alt-tekst, kant, breedte en hoogte als `.admin-segmented` in een fieldset met legend (`editor_row_choice()`), en de *Afbeeldingsweergave* (hieronder, `responsive_image_field()`, dezelfde als op een carrouselkaart). Elk item klapt apart in (`.admin-collapse`, de kopregel "Item 2 — Over ons" is de knop, ↑, ↓ en *Verwijderen* blijven zichtbaar). De knop kiest zijn doel met de bestemmingskiezer (hieronder): *Geen knop*, een pagina, een blogbericht, product, collectie of portfolioproject, of een eigen adres, en de knoptekst verschijnt alleen bij een doel |
| Oproep met knop (`admin/cta-band.php`) | Eén formulier met de opslagbalk en vijf kaarten: *Inhoud* (bovenlabel, titel, introtekst, *Actief* als checkbox), *Weergave* (*Uitlijning* en *Breedte introtekst* als `.admin-segmented` in een fieldset met legend en uitleg, *Achtergrond over de volledige paginabreedte* als switch), *Achtergrond* (de mediakiezer, nooit een eigen bestandskiezer; de *Afbeeldingsweergave* en *Overlay* als `.admin-segmented`, beide alleen zichtbaar met een afbeelding; *Hoogte van het achtergrondvlak* en *Hoogte op telefoon* als `.admin-segmented`, altijd zichtbaar, elk met een getalveld *Eigen hoogte in pixels* (`type="number"` met `min`/`max`, het bereik als uitleg via `aria-describedby`) dat alleen bij *Eigen hoogte* verschijnt; de focuskaders nemen de vorm van de gekozen hoogte aan), *Tekstvlak* (switch, en de dekking als `.admin-segmented` alleen als hij aan staat) en *Knoppen* (twee keer het gedeelde linkveld, `.admin-select`; de knoptekst verschijnt alleen bij een doel, en de tweede knop alleen als de eerste een doel heeft). Een onbekende keuze krijgt een melding bij het veld zelf (`admin/assets/cta-band.js` en `navigation-item.js` tonen en verbergen; de server print dezelfde `hidden`) |
| Mediabanner (`admin/media-banner.php`) | Eén formulier met de opslagbalk en drie kaarten: *Media* (de mediakiezer in de stand *afbeelding of video*, `MediaType::VISUAL`, met het type naast de naam en *Wissen*; nooit een eigen bestandskiezer en geen keuzelijst voor het type), *Weergave* (*Breedte* en *Hoogte* als `.admin-segmented` in een fieldset met legend en uitleg, en bij een afbeelding de *Afbeeldingsweergave*, `responsive_image_field()`) en *Video* (*Automatisch afspelen*, *Herhalen* en *Bediening tonen* als switch met uitleg, en de mediakiezer voor het beeld vóór het afspelen), alleen zichtbaar bij een video. `admin/assets/media-banner.js` toont en verbergt op het type van het gekozen item; de server print dezelfde `hidden`. Een geweigerde keuze krijgt haar melding bij het veld, een switch krijgt `aria-invalid` |
| Uitgelicht product (`admin/featured-product.php`) | Eén formulier met de opslagbalk en zes inklapbare kaarten (`.admin-collapse--card`, `admin/_admin_collapse.php`): *Product* (een regel met wat er gekozen is, `.admin-alert--info` zolang er niets gekozen is en `.admin-alert--warning` bij een product op inactief; daaronder de productrijen van de collectie-editor, `.admin-collection-product-row`, met één keuzerondje per product, thumbnail, prijs of *Op aanvraag* en de badge *Inactief*, voorafgegaan door *Geen product*, in een lijst die zelf scrolt, met een zoekveld `.admin-search` dat alleen het scherm filtert en de opslagbalk niet raakt), *Inhoud* (vier switches met uitleg en de introtekst per websitetaal), *Afbeeldingen*, *Bestellen* en *Uitlijning* (keuzes als `.admin-segmented` in een fieldset met legend en uitleg) en *Productknop* (switch en knoptekst, met als placeholder wat een bezoeker in die taal ziet). `admin/assets/featured-product.js` houdt de regel bij de keuze; zonder script zegt het aangevinkte rondje het |
| Paginakop (`admin/page-hero.php`) | Uitleg bij bovenschrift, titel, inleiding, *Afbeeldingsweergave*, hoogte, focuspunt, alt-tekst, positie van de tekst, beide groottes en *Tonen op de pagina*. *Afbeeldingsweergave* en de drie vormgevingskeuzes als `.admin-select`, de hoogte als `.admin-segmented`, *Tonen op de pagina* als switch, de afbeelding met de mediakiezer (`MEDIA.md`) en de *Afbeeldingsweergave* (`responsive_image_field()`, dezelfde als op een carrouselkaart en bij Tekst met afbeelding; *Weergave in het kader* alleen naast de tekst, *Hoogte op een telefoon* alleen achter de tekst). Alleen de groep *Afbeelding* is voorwaardelijk: `admin/assets/page-hero.js` toont per gekozen plek de kiezer, de hoogte (achtergrond), de alt-tekst en een korte uitleg (naast de tekst) en het focuspunt, zonder te herladen, en de server print dezelfde `hidden`. *Inhoud*, *Afbeelding* en *Vormgeving* zijn kopjes in één formulier, zodat de opslagbalk één formulier bewaakt |
| Header & navigatie (`admin/navigation.php`, `admin/navigation-item.php`) | In het overzicht een infobalk en twee kaarten, *Menu* en *Knoppen*, elk met een eigen *toevoegen*-knop en een lege staat die zegt hoe je hem vult. Elke rij noemt de bestemming in woorden (*Pagina: Contact*), met badges voor *Verborgen*, *Niet op de website* en de knopstijl; ↑ en ↓ als `.admin-btn-ghost` met een `aria-label` dat het item noemt; *Verbergen*/*Tonen* (`.admin-btn-secondary`) en *Verwijderen* (`.admin-btn-danger`), dat eerst vraagt in `admin_confirm_dialog()` en ontbreekt zolang er submenu-items onder staan. Submenu's staan eronder als eigen, ingesprongen lijst met een eigen volgorde, tot drie niveaus (`HEADER-FOOTER.md`, "Drie niveaus"); *+ Submenu-item* staat alleen op een menulink op niveau 1 of 2, en op een telefoon springt elk niveau minder ver in. Een item met submenu-items heeft vóór zijn naam een knop die zijn submenu inklapt (`.admin-nav-tree__toggle` met het driehoekje van de paginaboom, `aria-expanded`, `aria-controls` naar de zone, de naam *Subitems van …*; `admin/assets/navigation-tree.js`): alles eronder verdwijnt, elk item houdt zijn eigen stand, en die blijft per browser bewaard (localStorage, `mygdalaNavigationTree`). Alleen weergave: niets wordt opgeslagen, slepen neemt een ingeklapt submenu mee, en een adres met `#nav-item-<id>` klapt de items erboven voor dat bezoek open. De editor heeft *Tonen op de website* als switch met uitleg; in de kaart *Tekst* bij een paginalink *Gebruik titel van bestemming* als switch met uitleg (aan voor een nieuw item) en eronder in een live regio wat het menu toont (*In het menu staat: …*), of anders de tekst in de taal die bewerkt wordt met uitleg (`HEADER-FOOTER.md`, "Tekst van een menu-item"); een item dat zijn pagina volgt heeft in het overzicht de badge *Volgt paginatitel*. Verder de soort bestemming, de pagina (in de boomvolgorde van Pagina's, ingesprongen: `App\Service\PageOptions`), het onderdeel en de knopstijl als `.admin-select` met uitleg, *Openen in een nieuw tabblad* als switch, en de opslagbalk. De velden die niet bij de gekozen soort horen verbergt `admin/assets/navigation-item.js`; zonder script staan ze er allemaal, behalve de eigen tekst van een item dat zijn pagina volgt |
| Footer (`admin/footer.php`, `admin/footer-column.php`, `admin/footer-link.php`) | Eén scherm met een infobalk en vier kaarten. *Bedrijfsblok*: een switch per bedrijfsgegeven met de huidige waarde uit Instellingen ernaast (alleen-lezen, met uitleg en een link naar Instellingen), en de omschrijving in de taalpanes met uitleg. *Kolommen & links*: rijen zoals op Header & navigatie, met de bestemming in woorden, badges voor *Verborgen* en *Niet op de website*, ↑ en ↓ als `.admin-btn-ghost` met een `aria-label` dat het item noemt, *Verbergen*/*Tonen* en *Verwijderen* in `admin_confirm_dialog()`. *Social media*: per profiel een eigen formulier met het netwerk als `.admin-select`, het adres met uitleg en *Tonen op de website* als switch, met ↑/↓ en *Verwijderen* ernaast; een geweigerd adres krijgt `aria-invalid` en zijn melding in de rij. *Slotregel & copyright*: de copyright-tekst met uitleg, *Slotregel tonen* als switch met uitleg, de slotregel in de taalpanes. Beide instellingenformulieren en elke social rij vallen onder de opslagbalk. De kolom- en linkeditor hebben *Tonen op de website* als switch, uitleg bij tekst en bestemming, de soorten bestemming als `.admin-select` (de velden verbergt `admin/assets/navigation-item.js`, dezelfde als bij Header & navigatie), en de opslagbalk |
| Bloklijst en blok-editors (`admin/_content_blocks.php`, `admin/_block_editor.php`) | Contentblokken Lifecycle 1.0 (`CONTENT-BLOCKS.md`, "De levensloop van een nieuw blok"). In de lijst twee badges erbij, alleen in de ene samenvattingsregel zodat een ingeklapte rij niet hoger wordt: *Opgeslagen* (`.admin-badge--saved`, groen) op het blok waar een opslag mee terugkwam, dat ook één keer oplicht (`.is-just-saved`) en via `data-admin-collapse-focus` in beeld komt, en *Leeg blok* (`.admin-badge--empty`, rustig amber, geen fout) met opengeklapt één zin en een link *Inhoud toevoegen*. In de editor van een nieuw blok bovenaan één `.admin-alert--note` met *Annuleren* als `.admin-btn-secondary` in een `.admin-inline-form` met `data-save-bar-discard`, dus zonder tweede vraag bij weggaan |
| Reviews (`admin/reviews.php`) | Eén formulier met de opslagbalk en vier kaarten. *Weergave*: een `.admin-select` met uitleg, daarnaast vier kleine schetsen (`.admin-reviews-sketch`, `aria-hidden`: de select is het bedieningselement) waarvan de gekozen oplicht, en één zin over de gekozen weergave; `admin/assets/reviews.js` volgt de select, de server print dezelfde stand. *Kop boven de reviews*: bovenlabel, titel, introtekst en de uitlijning als `.admin-segmented`. *Reviews*: inklapbare rijen (`editor_row_open()` met `$collapse`) met "Review 2 — Peter" als kopregel en "Review 3 — Anoniem" zonder naam (`data-row-list-title-fallback` op het naamveld, `admin/assets/row-list.js`); per review de tekst, naam, omschrijving, sterren als `.admin-select` (*Geen sterren* tot *5 sterren*), een `type="date"`, de mediakiezer met *Wissen* en de *Afbeeldingsweergave* in een rond kader, brontekst en een `type="url"`; *Deze review uitlichten* (een keuzerondje) alleen bij *Uitgelichte review*. *Knop onder de reviews*: het gedeelde linkveld, de knoptekst en de knopstijl, die alleen verschijnen bij een doel. Geen achtergrond, rand of kleur: dat is Extra vormgeving |
| Extra vormgeving (`admin/_block_appearance.php`, in elke rij van de bloklijst) | Contentblock Styling 1.0 (`CONTENT-BLOCKS.md`, "Extra vormgeving"). Onder de knoppen van een rij één inklapbaar paneel, standaard dicht, gebouwd als `.admin-collapse` zonder `data-admin-collapse-id` (dus niet onthouden). De samenvattingsregel noemt wat gekozen is, in rustig grijs. Opengeklapt: een korte uitleg en de velden die het blok ondersteunt, elk een `.admin-select` met uitleg via `admin_field_label()`, naast elkaar in een grid dat op een telefoon stapelt. Eronder *Vormgeving opslaan* als `.admin-btn-secondary`. Het formulier is eigen, maar de opslagbalk bewaakt het wel: een gewijzigde vormgeving maakt het scherm *Niet-opgeslagen*, weggaan waarschuwt, en *Opslaan* in de balk of de eigen knop slaat het één keer op. Na opslaan klapt de rij open met *Opgeslagen* |

**Het menu *…* van een rij** (`.admin-row-menu`, Pages & Destinations 3.0,
nu op Pagina's). Een `<details data-row-menu>` met een `<summary>` als knop:
het teken *…* is `aria-hidden`, het `aria-label` noemt de rij (*Meer acties
voor Over ons*). Het paneel opent onder de knop en houdt de acties die niet
elke keer nodig zijn, zoals *Verwijderen*; een actie die nu niet kan staat er
uitgeschakeld met de reden eronder (`aria-describedby`). `admin/assets/page-tree.js`
houdt er één tegelijk open en sluit het met Escape (de focus terug op de knop)
of met een klik ernaast; zonder script opent en sluit het als elke
`<details>`. Op een telefoon is het paneel zo breed als de kaart.

**De bestemmingskiezer** (`admin/_link_target_field.php`,
`admin/assets/destination-picker.js`; `CONTENT-BLOCKS.md`, "Waar een knop heen
gaat"). Eerst de soort als `.admin-select`, dan alleen de kiezer van die
soort: een pagina als `.admin-select` in de boomvolgorde; een product,
collectie, project of blogbericht als zoekveld met een lijst resultaten met
afbeelding en status (elk resultaat een knop: Tab, Enter en Spatie,
`aria-pressed` op de gekozen, pijltjes tussen zoekveld en resultaten; de
huidige keuze en het aantal treffers in een live regio, Enter in het zoekveld
verstuurt niets); een eigen adres als tekstveld met uitleg. Het script
bouwt de lijst uit de `<select>` van het veld, die blijft wat er verstuurd
wordt en zonder script de hele kiezer is. Een opgeslagen bestemming die niet
meer te kiezen is of van een module die uit staat, blijft staan met een
`.admin-alert--warning` die zegt waarom de knop niet op de website staat.

De bestandskiezer staat op de upload van de Mediabibliotheek. Slepen en
neerzetten, de lijst met nieuwe bestanden en de voorbeelden horen bij dat
scherm (`admin/assets/media-upload.js`) en liggen óm de bouwsteen heen: het
echte `<input type="file">` blijft de manier om bestanden te kiezen. Op het
Portfolio-item staat hij ook, met het voorbeeld van de afbeelding ernaast
(`admin_file_preview()`), en bij de deel-afbeelding van een product en een
collectie.

`AdminUiPrimitivesTest` pint per scherm vast welke velden uitleg hebben, dat
een label naar zijn eigen veld wijst, en dat de formulieren hetzelfde
versturen als voorheen: dezelfde namen, dezelfde verplichte velden, de
verborgen `0` vóór de indexeer-switch, en geen verborgen veld vóór *Actief*.
Voor de twee Portfolio-schermen doet `Tests\Service\PortfolioAdminScreenTest`
hetzelfde, en `PortfolioItemEditingHttpTest` laat over echt HTTP zien dat het
voorbeeld op een bestaand item met de opgeslagen afbeelding begint, dat de
projectpagina alleen gewone pagina's aanbiedt en dat een onbruikbare keuze
niets opslaat.

## Wat hier niet in hoort

- **Layout van één scherm.** Die blijft bij dat scherm in `admin.css`.
- **Een kleur of een token.** `AdminUiPrimitivesTest` faalt op een hexwaarde,
  een `rgb()` of een nieuwe `--admin-*`-declaratie in de sectie.
- **Een tekst in JavaScript**, en `innerHTML`.
- **Een inline eventhandler.** Geen enkel adminscherm heeft een `onclick`, en
  `admin_file_input()` weigert een `on…`-attribuut.
- **Een eigen, geanimeerde select, drag-and-drop of uploadvoortgang.** Dat komt
  later, bovenop deze bouwstenen.

## Handmatig controleren

De testsuite heeft geen browser: de tests bewaken de markup, de escaping, het
laden en de opslagsleutel, niet wat er op een klik gebeurt. Loop na een
wijziging aan het script of de sectie dit na, in minstens één licht en één
donker thema:

1. Muis op een `?`: de uitleg verschijnt naast het icoon; muis weg: hij
   verdwijnt. Beweeg van het `?` naar de uitleg: hij blijft staan.
2. Klik op het `?`: de uitleg blijft staan als de muis weggaat.
3. Het kruisje sluit, en de focus staat weer op het `?`.
4. Escape sluit.
5. Een klik naast de uitleg sluit.
6. Op een telefoon (of in de mobiele weergave van de browser): een tik opent,
   het kruisje sluit, en de uitleg valt niet buiten het scherm.
7. Help uit in de schil: iconen en infobalk weg, open uitleg dicht, labels en
   velden onveranderd.
8. Herladen: help staat nog steeds uit.
9. Help weer aan: alles terug.
10. Alleen het toetsenbord: Tab bereikt het `?`, Enter opent, Tab gaat naar het
    kruisje, Escape sluit, en elke stap heeft een zichtbare focusring.
11. Zoekveld, select, checkbox, switch en bestandskiezer: hover, focus,
    uitgeschakeld, en een formulier dat verstuurd wordt slaat hetzelfde op als
    voorheen. Bestandskiezer met voorbeeld: kies een afbeelding (het voorbeeld
    verschijnt), kies een andere (het voorbeeld wisselt), *Keuze wissen* (terug
    naar de huidige afbeelding, of weg).
12. Een formulier met een vraag: de dialoog opent met die vraag en de focus op
    *Annuleren*. *Annuleren*, Escape en een klik naast de dialoog sluiten hem
    zonder iets te versturen, en de focus staat weer op de knop. De knop die
    doorgaat verstuurt het formulier. Op een telefoon staan de twee knoppen
    onder elkaar.
