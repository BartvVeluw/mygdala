# Admin-UI — de gedeelde bouwstenen van het CMS

Eén set bouwstenen voor elk adminformulier: uitleg bij een veld, de knop die
alle uitleg aan- of uitzet, een infobalk bovenaan een scherm, en de gewone
formulierelementen in de stijl van het CMS. Dit document is de handleiding.
De code staat op drie plekken en nergens anders.

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
| Checkbox | `<input type="checkbox" class="admin-checkbox">` | In een `.admin-checkbox-label` |
| Switch | `<input type="checkbox" class="admin-switch" role="switch">` | Voor één aan/uit-instelling |
| Bestand | `admin_file_input(['name' => 'image', 'accept' => '…', 'required' => true])` | Binnen het `<label>` van het veld |
| Knoppen | `.admin-btn-primary`, `.admin-btn-secondary`, `.admin-btn-danger`, `.admin-btn-ghost`, `.admin-btn-text` | Uitgeschakeld met `disabled`, of `aria-disabled="true"` op een link |

Elk element heeft een hover-, focus- en disabled-toestand. In Windows' hoog
contrast (`forced-colors`) krijgen checkbox en switch het eigen element van de
browser terug, en `prefers-reduced-motion` zet de overgangen uit.

**Een switch verandert niets aan wat er verstuurd wordt.** Hij is een
checkbox: aangevinkt stuurt hij zijn `value`, uit stuurt hij niets. Leest een
endpoint `isset()`, dan blijft dat zo. Verwacht het een verborgen `0` ervoor
(zoals Site-instellingen), dan blijft die verborgen `0` staan.

**Een bestandskiezer verandert niets aan het uploaden.** Met het script ligt
het echte `<input type="file">` onzichtbaar over een eigen knop en een regel
met de gekozen bestandsnaam; een klik overal in het vak opent de dialoog, en
`required`, `accept` en `multiple` blijven van het echte veld. Zonder script
is het de knop van de browser zelf, in de vorm van `.admin-btn-secondary`. Een
script dat al op het veld reageert (de portfolio-upload in `admin.js`) vindt
het nog steeds. Slepen en neerzetten en uploadvoortgang horen bij de
Mediabibliotheek (`MEDIA.md`).

## Waar het al gebruikt wordt

Vijf schermen, als bewijs dat de bouwstenen herbruikbaar zijn. De rest van het
CMS volgt scherm voor scherm; een scherm dat nog niet is omgezet, werkt zoals
het werkte.

| Scherm | Wat |
|---|---|
| Site-instellingen (`admin/settings.php`) | Infobalk bij *Algemeen* en bij *Adresgegevens*; uitleg bij naam van de website, e-mailadres, telefoonnummer, plaats, footer-omschrijving, plaats en land van het adres, KVK-nummer, standaardtaal, standaard meta description en indexeren. De standaardtaal is een `.admin-select`, indexeren een switch |
| Shop-instellingen (`admin/shop-settings.php`) | Infobalk per tabblad; uitleg bij elk veld; één `?` bij *Invulvelden* die elk invulveld van de bestelmail uitlegt, opgebouwd uit `EmailPlaceholders::KNOWN`; *Herstel standaardtekst* als `.admin-btn-secondary` (`admin/assets/shop-settings.js`) |
| Pagina's (`admin/pages.php`) | Infobalk; zoekveld (`?q=`, filtert de al geladen lijst via `PageContent::matchesAdminSearch()`); knoppen uit de familie; de status als badge met woord én kleur: `.admin-badge--draft` (amber, `--admin-warning`) en `.admin-badge--published` (groen, `--admin-success`) |
| Formulier bewerken (`admin/form.php`) | *Actief* is een switch, *Inzendingen bewaren* een checkbox, beide selects zijn `.admin-select` |
| Pagina bewerken en Nieuwe pagina (`admin/page.php`, `admin/page-new.php`) | Uitleg bij *Webadres* (het woord *slug* staat alleen in die uitleg); op een bestaande pagina het adres als link en het veld achter *Webadres wijzigen*, een `<details>` in de stijl van de inklapbare rijen; op een nieuwe pagina een live voorbeeld van het hele adres. SEO: een infobalk over wat SEO is, en uitleg bij de SEO-titel (met de automatische titel) en bij de *Omschrijving voor zoekmachines*. Op *Nieuwe pagina* staat de SEO-kaart vóór *Template* en klapt hij dicht (`.admin-collapse--card`) |

De bestandskiezer bestaat en is getest, maar staat nog op geen scherm. Het
eerste scherm dat hem krijgt, is de upload in de Mediabibliotheek.

`AdminUiPrimitivesTest` pint per scherm vast welke velden uitleg hebben, dat
een label naar zijn eigen veld wijst, en dat de formulieren hetzelfde
versturen als voorheen: dezelfde namen, dezelfde verplichte velden, de
verborgen `0` vóór de indexeer-switch, en geen verborgen veld vóór *Actief*.

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
    voorheen.
