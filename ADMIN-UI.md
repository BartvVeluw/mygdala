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
| Switch | `<input type="checkbox" class="admin-switch" role="switch">` | Voor één aan/uit-instelling |
| Bestand | `admin_file_input(['name' => 'image', 'accept' => '…', 'required' => true])` | Binnen het `<label>` van het veld, of met een `id` naast `admin_field_label()` |
| Voorbeeld van een afbeelding | `admin_file_preview('id-van-het-veld', $huidigeAfbeelding)` | Hoort bij één `admin_file_input()` met dat `id`; zie hieronder |
| Knoppen | `.admin-btn-primary`, `.admin-btn-secondary`, `.admin-btn-danger`, `.admin-btn-ghost`, `.admin-btn-text` | Uitgeschakeld met `disabled`, of `aria-disabled="true"` op een link |

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

## Waar het al gebruikt wordt

Twaalf schermen, als bewijs dat de bouwstenen herbruikbaar zijn. De rest van het
CMS volgt scherm voor scherm; een scherm dat nog niet is omgezet, werkt zoals
het werkte.

| Scherm | Wat |
|---|---|
| Mediabibliotheek (`admin/media.php`) | De upload is `admin_file_input()` met `multiple`; het sleepvak, de lijst met nieuwe bestanden en de voorbeelden eromheen zijn van dat scherm zelf, en nieuwe bestanden komen in de map die open staat (`MEDIA.md`). Links de mappen als links (`?folder=`, *Alle media*, *Geen map*, elke map met zijn aantal; op een smal scherm een doorlopende rij erboven), met *Nieuwe map*, en bij een open map *Map hernoemen* (een `<details>`) en *Map verwijderen* in `admin_confirm_dialog()`. Zoekveld (`?q=`, blijft binnen de open map) en de soort bestand als `.admin-select` (`?type=`), die het raster verversen zonder te herladen. *Raster* \| *Lijst* als twee knoppen met `aria-pressed` (`.admin-media-view`), onthouden in `localStorage`. Per kaart een `.admin-checkbox` om meerdere bestanden tegelijk te selecteren; de selectiebalk verplaatst naar een map (`.admin-select`) of verwijdert. *Bewerken* op een kaart opent *Media bewerken* (naam en alt-tekst, één opslag). Het itemscherm heeft één formulier *Naam en alt-tekst* onder de opslagbalk. *Verwijderen* op een item vraagt eerst in `admin_confirm_dialog()`; een selectie verwijderen heeft een eigen dialoog, omdat die per keer toont wat er echt weggaat en wat blijft staan |
| Instellingen (`admin/settings.php`) | Infobalk bij *Algemeen* en bij *Adresgegevens*; uitleg bij naam van de website, e-mailadres, telefoonnummer, plaats, plaats en land van het adres, KVK-nummer, standaardtaal, standaard meta description en indexeren. De standaardtaal is een `.admin-select`, indexeren een switch |
| Shop-instellingen (`admin/shop-settings.php`) | Infobalk per tabblad; uitleg bij elk veld; één `?` bij *Invulvelden* die elk invulveld van de bestelmail uitlegt, opgebouwd uit `EmailPlaceholders::KNOWN`; *Herstel standaardtekst* als `.admin-btn-secondary` (`admin/assets/shop-settings.js`); het tabblad *Productoverzicht* met één `.admin-select` (*Geen overzichtspagina* of een bestaande pagina, een concept gemarkeerd) met uitleg, en een melding als de gekozen pagina nog een concept is of nog geen blok *Productgrid* heeft (`MODULES.md`, "Shop") |
| Product en collectie (`admin/product-form.php`, `admin/collection.php`) | Beide met de opslagbalk. Op een product de afbeeldingen als raster (`admin/_product_gallery.php`, `admin/assets/product-gallery.js`): *Afbeelding toevoegen* opent de mediakiezer, ← en → per afbeelding (knoppen met een `aria-label` dat de afbeelding noemt, en een live regio die de nieuwe positie zegt) en slepen met de muis veranderen dezelfde volgorde, × haalt hem weg, en pas *Opslaan* bewaart. Per variant een eigen strook met dezelfde bediening, de productafbeeldingen als tegels om aan te vinken (`aria-pressed`), en *Eigen beschrijving voor deze variant* als switch. Op een collectie de afbeelding met de mediakiezer, en per product *Bewerken* naar de producteditor voor wie producten mag beheren. De deel-afbeelding op beide is de mediakiezer in deel-afbeeldingsmodus (`MediaType::SOCIAL_IMAGE`); een oude eigen upload staat erboven met *Deel-afbeelding verwijderen* |
| Pagina's (`admin/pages.php`) | Infobalk; zoekveld (`?q=`, filtert de al geladen lijst via `PageContent::matchesAdminSearch()` en toont een treffer met elke pagina erboven, gemarkeerd als *bovenliggend*); knoppen uit de familie; de status als badge met woord én kleur: `.admin-badge--draft` (amber, `--admin-warning`) en `.admin-badge--published` (groen, `--admin-success`). De lijst is een boom in twee groepen, *Websitepagina's* (open) en *Service & juridisch* (dicht, met aantal): een knop met `aria-expanded` per groep en per pagina met onderliggende pagina's, ingesprongen per niveau, in- en uitklappen zonder herladen en onthouden in `localStorage` (`admin/assets/page-tree.js`); per rij *Bewerken*, *Bekijken* (of *Preview* voor een concept) en *Subpagina toevoegen* (`docs/pages/NESTING.md`) |
| Formulieren (`admin/forms.php`, `admin/form.php`) | In het overzicht een infobalk en de status als badge met woord én kleur: `.admin-badge--paid` voor *Actief*, `.admin-badge--draft` voor *Inactief*. In de editor staat *Actief* bovenaan als switch; uitleg bij *Actief*, de naam, het bedankbericht en het e-mailadres voor de melding. *Inzendingen bewaren in het CMS* (switch) en *Antwoordadres van de melding* (`.admin-select`) staan onder *Geavanceerd*, een `<details>` in de stijl van de inklapbare rijen (`.admin-collapse--card`) met in de kop of inzendingen bewaard worden; hij opent na een geweigerde opslag. De editor heeft de opslagbalk. *Veld toevoegen* is een native `<dialog>` met radiokaarten (`.admin-template-card`) en het label met uitleg; zonder script rendert de link hem open in de pagina. Elke radio heet alleen het type (`aria-labelledby`) en krijgt de uitleg als beschrijving (`aria-describedby`). De velden staan als compacte rijen in de stijl van *Header & navigatie* (`.admin-section-row`, ↑ en ↓ als `.admin-row-move`, *Bewerken* als `.admin-section-row__edit`, *Verwijderen* als `.admin-btn-danger`), met een tweede regel voor type, verplicht, breedte en opties of, bij een uploadveld, de toegestane soorten en de maximale grootte. Naast de velden staat het voorbeeld (`admin/form-preview.php`) in een eigen frame, met de breedteknoppen van de blokbibliotheek (`.admin-block-preview__viewport`: *Desktop* en *Mobiel*). De eigen knop *Opslaan* van de editor en de veldeditor draagt `data-save-bar-fallback`: met script is de opslagbalk de enige knop. De veldeditor (`admin/form-field.php`) heeft uitleg bij label, uitleg en voorbeeldtekst, *Verplicht invullen* als switch, *Breedte in het formulier* als `.admin-select`, bij *Bestand uploaden* de kaart *Bestanden* met een `.admin-checkbox` per toegestane soort (naast elkaar, `.admin-form-file-types`) en *Maximale grootte* als `.admin-select` — nooit een vrij tekstveld en nooit een bestandskiezer, *Ander soort veld kiezen* en *Technische gegevens* als `<details>` in de stijl van de inklapbare rijen, ↑ en ↓ per optie als `.admin-btn-ghost`, en de opslagbalk. Een inzending (`admin/form-submission.php`) noemt een bestand bij zijn veld als downloadlink met soort en grootte, en *Bestand ontbreekt* als badge wanneer het van schijf weg is; een lange bestandsnaam breekt af (`.admin-submission-file`). Het offerte-/contactblok (`admin/contact-form.php`) heeft geen bijlageschakelaar meer, alleen de zin waar je een uploadveld toevoegt. Veld, formulier en inzending verwijderen vraagt eerst in `admin_confirm_dialog()` (`FORMS.md`) |
| Pagina bewerken en Nieuwe pagina (`admin/page.php`, `admin/page-new.php`) | De *Titel* en de SEO-velden zijn velden per websitetaal (`admin/_localized_fields.php`): één regel *Taal: …* boven de tabbladen met de badge *standaardtaal*, alleen de velden van die taal, niets van een andere taal verborgen meegestuurd; een nieuwe pagina schrijft in de standaardtaal en het adres komt uit die titel. Uitleg bij *Webadres* (het woord *slug* staat alleen in die uitleg); op een bestaande pagina het adres als link en het veld achter *Webadres wijzigen*, een `<details>` in de stijl van de inklapbare rijen; op een nieuwe pagina een live voorbeeld van het hele adres. *Bovenliggende pagina* als `.admin-select` met uitleg (de boom ingesprongen, zonder de pagina zelf en alles eronder), *Beheergroep* als segmentkeuze met uitleg voor een hoofdpagina of de regel welke groep een geneste pagina volgt, en het pad dat een opslag oplevert, bijgewerkt terwijl je kiest en typt (`admin/_page_placement.php`, `admin/assets/page-placement.js`, `docs/pages/NESTING.md`); een pagina met een vaste URL zegt dat ze altijd op het hoogste niveau staat. Onder *Status* staat *Kruimelpad tonen op deze pagina* als switch met uitleg, behalve op de homepage (`HEADER-FOOTER.md`). SEO: een infobalk over wat SEO is, en uitleg bij de SEO-titel (met de automatische titel) en bij de *Omschrijving voor zoekmachines*. Op *Nieuwe pagina* staat de SEO-kaart vóór *Template* en klapt hij dicht (`.admin-collapse--card`). In de blokkenkiezer het zoekveld (`.admin-search`); op elke blokrij *Verbergen*/*Tonen* (`.admin-btn-secondary`) en *Verwijderen* (`.admin-btn-danger`), dat eerst vraagt in `admin_confirm_dialog()` |
| Portfolio (`admin/portfolio.php`, `admin/portfolio-item.php`) | Infobalk; in het overzicht het zoekveld (`.admin-search`) en de filters als `.admin-select`, met *Zonder categorie*; op een item de mediakiezer (een afbeelding uit de bibliotheek, of een nieuwe die daar geüpload wordt; een afbeelding van vóór de bibliotheek staat erboven tot er een andere wordt gekozen) en uitleg bij afbeelding, alt-tekst, titel, onderschrift en categorieën; *Categorieën* (`.admin-checkbox`, onder elkaar) en *Zichtbaarheid* (*Zichtbaar op de portfolio-pagina* en *Toon op homepage* als switch) als twee kaarten naast elkaar (`.admin-card-pair`, één kolom onder 900px); de kaart *Projectpagina* met *Projectpagina tonen* als switch, het webadres met voorbeeld (uit de titel ingevuld zolang er geen is, het gedeelde `data-slug-target`), en *Inleiding* en *Beschrijving* als rich text; de kaart *Galerij* met de mediakiezer in verzamelmodus en de strip van de producteditor (← → ×, slepen, een live regio); alleen bij een item met een legacy-koppeling de kaart *Gekoppelde pagina* met *Koppeling met deze pagina verwijderen*. Er is geen paginakeuze en geen *Nieuwe pagina maken*; verwijderen vraagt eerst in `admin_confirm_dialog()` |
| Kaarten-carrousel (`admin/card-carousel.php`, `admin/carousel-card.php`) | Eén formulier per scherm, met de opslagbalk (`PAGE-EDITOR.md`, "Eén formulier per blok-editor"). De kaarten als tegels uit het Portfolio-overzicht (`.admin-portfolio-grid`): afbeelding, titel, *Actief* als switch per kaart, *Bewerken* (`.admin-btn-secondary`), ← en → als `.admin-btn-ghost` met een `aria-label` dat de kaart noemt, en *Verwijderen*, dat de kaart markeert tot er opgeslagen wordt. *Carrousel tonen op de pagina* als switch met uitleg en de weergave op grotere schermen als radiokaarten (`.admin-template-card`). Op een kaart: *Actief* als switch met uitleg, uitleg bij nummer, knop en adres, de bestemming van de knop als `.admin-select` (alleen de soorten waarvan de module aan staat; de velden verbergt `admin/assets/navigation-item.js`), de afbeelding met de mediakiezer, en de tags als compacte rijen (`.admin-option-row--plain`) met ↑, ↓ en × en één taalbalk voor alles |
| Tekst met afbeelding (`admin/text-image-split.php`) | Eén formulier met de opslagbalk en *Actief* als checkbox. De items als rijen (`.admin-row-card`, `admin/_editor_rows.php`) met ↑, ↓ en *Verwijderen* als markering, en *Item toevoegen*. Per item de tekst in de gedeelde rich-texteditor (`editor_row_rich()`), de afbeelding met de mediakiezer (met *Wissen*) en de alt-tekst, kant, breedte en hoogte als `.admin-segmented` in een fieldset met legend (`editor_row_choice()`), en het focuspunt met voorbeeld (`media_focus_field()`, dezelfde als op een carrouselkaart). Elk item klapt apart in (`.admin-collapse`, de kopregel "Item 2 — Over ons" is de knop, ↑, ↓ en *Verwijderen* blijven zichtbaar). De knop kiest zijn doel met het gedeelde linkveld (`admin/_link_target_field.php`, `.admin-select`): *Geen knop*, een bestaande pagina, een blogbericht, een product of een eigen adres, en de knoptekst verschijnt alleen bij een doel |
| Paginakop (`admin/page-hero.php`) | Uitleg bij bovenschrift, titel, inleiding, *Afbeeldingsweergave*, hoogte, focuspunt, alt-tekst, positie van de tekst, beide groottes en *Tonen op de pagina*. *Afbeeldingsweergave* en de drie vormgevingskeuzes als `.admin-select`, de hoogte als `.admin-segmented`, *Tonen op de pagina* als switch, de afbeelding met de mediakiezer (`MEDIA.md`) en het focuspunt met voorbeeld (`media_focus_field()`, dezelfde als op een carrouselkaart en bij Tekst met afbeelding). Alleen de groep *Afbeelding* is voorwaardelijk: `admin/assets/page-hero.js` toont per gekozen plek de kiezer, de hoogte (achtergrond), de alt-tekst en een korte uitleg (naast de tekst) en het focuspunt, zonder te herladen, en de server print dezelfde `hidden`. *Inhoud*, *Afbeelding* en *Vormgeving* zijn kopjes in één formulier, zodat de opslagbalk één formulier bewaakt |
| Header & navigatie (`admin/navigation.php`, `admin/navigation-item.php`) | In het overzicht een infobalk en twee kaarten, *Menu* en *Knoppen*, elk met een eigen *toevoegen*-knop en een lege staat die zegt hoe je hem vult. Elke rij noemt de bestemming in woorden (*Pagina: Contact*), met badges voor *Verborgen*, *Niet op de website* en de knopstijl; ↑ en ↓ als `.admin-btn-ghost` met een `aria-label` dat het item noemt; *Verbergen*/*Tonen* (`.admin-btn-secondary`) en *Verwijderen* (`.admin-btn-danger`), dat eerst vraagt in `admin_confirm_dialog()` en ontbreekt zolang er submenu-items onder staan. Submenu's staan eronder als eigen, ingesprongen lijst met een eigen volgorde, tot drie niveaus (`HEADER-FOOTER.md`, "Drie niveaus"); *+ Submenu-item* staat alleen op een menulink op niveau 1 of 2, en op een telefoon springt elk niveau minder ver in. De editor heeft *Tonen op de website* als switch met uitleg, de tekst in de taalpanes met uitleg, de soort bestemming, de pagina, het onderdeel en de knopstijl als `.admin-select` met uitleg, *Openen in een nieuw tabblad* als switch, en de opslagbalk. De velden die niet bij de gekozen soort horen verbergt `admin/assets/navigation-item.js`; zonder script staan ze er allemaal |
| Footer (`admin/footer.php`, `admin/footer-column.php`, `admin/footer-link.php`) | Eén scherm met een infobalk en vier kaarten. *Bedrijfsblok*: een switch per bedrijfsgegeven met de huidige waarde uit Instellingen ernaast (alleen-lezen, met uitleg en een link naar Instellingen), en de omschrijving in de taalpanes met uitleg. *Kolommen & links*: rijen zoals op Header & navigatie, met de bestemming in woorden, badges voor *Verborgen* en *Niet op de website*, ↑ en ↓ als `.admin-btn-ghost` met een `aria-label` dat het item noemt, *Verbergen*/*Tonen* en *Verwijderen* in `admin_confirm_dialog()`. *Social media*: per profiel een eigen formulier met het netwerk als `.admin-select`, het adres met uitleg en *Tonen op de website* als switch, met ↑/↓ en *Verwijderen* ernaast; een geweigerd adres krijgt `aria-invalid` en zijn melding in de rij. *Slotregel & copyright*: de copyright-tekst met uitleg, *Slotregel tonen* als switch met uitleg, de slotregel in de taalpanes. Beide instellingenformulieren en elke social rij vallen onder de opslagbalk. De kolom- en linkeditor hebben *Tonen op de website* als switch, uitleg bij tekst en bestemming, de soorten bestemming als `.admin-select` (de velden verbergt `admin/assets/navigation-item.js`, dezelfde als bij Header & navigatie), en de opslagbalk |

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
