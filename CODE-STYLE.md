# Schrijfstijl

Hoe code, commentaar en teksten in dit project geschreven worden. Dit is
geen wensenlijst: het beschrijft wat de bestaande ±99.000 regels al doen.
Wijkt de code af, dan heeft de code gelijk.

Er is **geen linter en geen formatter**. Deze afspraken worden door lezen
gehandhaafd, dus ze moeten kort en herkenbaar zijn.

## Welke taal, waar

Dit is de regel die het vaakst fout gaat.

| Wat | Taal |
|---|---|
| Prozadocumentatie (`*.md`) | **Nederlands** |
| Code: klassenamen, methodes, variabelen | **Engels** |
| Docblocks en commentaar in PHP, CSS en JS | **Engels** |
| Commitberichten | **Engels** |
| Teksten die een **beheerder** in het CMS ziet: labels, beschrijvingen, foutmeldingen, knoppen | **Nederlands** |
| Teksten die een **bezoeker** ziet | in elke websitetaal: inhoud per taal in de vertaaltabellen, systeemtekst als codecatalogus per taalcode (`SiteText::pick()`) |
| Database: tabel- en kolomnamen | **Engels**; woorden per taal staan in een `*_translations`-tabel met `language_code`, nooit in een `_nl`/`_en`-kolom |

Een `'label' => 'Veelgestelde vragen'` in een blokdefinitie is dus Nederlands,
en de docblock erboven Engels. Dat ziet er raar uit en het klopt.

## PHP

PSR-12, vier spaties, nooit tabs. PSR-4 onder namespace `App\` op `src/`.

- **`declare(strict_types=1);`** in nieuwe klassen en in **elk** endpoint.
  Ongeveer de helft van `src/` heeft hem nog niet; nieuwe code wel.
- **`final class`** tenzij je een concrete reden hebt om overerving toe te
  staan. Bijna de helft van `src/` is al `final`.
- **Constanten voor gesloten lijsten.** `STATE_FALLBACK`, `SECTIONS`,
  `SHIPPING_METHOD_CHOICES`. Nooit een losse string op twee plekken.
- **Typehints overal**, ook op returnwaarden. `array` mag, met een docblock
  die zegt wat erin zit.
- **Repositories bevatten alle SQL**, en verder niets. Een `*Content`-klasse
  leest, een repository query't, een partial rendert.
- **Geen statische state behalve de per-request cache** die elke
  `*Content`-klasse heeft, met de bijbehorende `clearCache()`.

De volgorde bovenin een bestand:

```php
<?php

/**
 * POST /api/admin/update-faq-section.php
 *
 * Wat dit bestand doet, en waarom het zo werkt.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
```

## Commentaar

Dit project schrijft **veel** commentaar, en dat is een bewuste keuze. De
maatstaf is niet "hoeveel" maar "wat".

- **Leg uit waaróm, niet wat.** `// increment the counter` is ruis. `// Never
  trust a shipping price sent by the frontend` is het punt.
- **Noem de buur.** Docblocks verwijzen naar de klasse waar hetzelfde patroon
  al staat: *"Same repeater architecture as `FeatureGridContent` — read that
  class's docblock first."* Dat scheelt de volgende lezer een zoektocht.
- **Noem het document.** Een verwijzing naar `THEMING.md` of `MODULES.md` in
  een docblock is goedkoper dan de uitleg herhalen.
- **Schrijf op wat er NIET hoort.** De banner boven `core.css` zegt letterlijk
  wat er niet in mag. Dat is het commentaar dat een bestand schoon houdt.
- **Een bewuste uitzondering krijgt een regel.** Als iets er verkeerd uitziet
  maar klopt, zeg dan waarom, anders "repareert" iemand het.

Wat je **niet** schrijft: een docblock die de signatuur herhaalt, een
`@param` zonder informatie, of een `TODO` zonder naam en datum.

## SQL en migraties

- Alle SQL staat in een repository, in een **prepared statement**. Nooit een
  waarde uit een request in een querystring.
- Migraties zijn forward-only, idempotent en MySQL-compatibel.
- Nieuwe kolommen zijn nullable of hebben een default, zodat een bestaande
  installatie niets merkt.

## Beveiliging: vier dingen die nooit ter discussie staan

1. **Alle output door `htmlspecialchars()`.** Rich text gaat door
   `RichTextSanitizer` (HTMLPurifier), nooit rechtstreeks naar de pagina.
2. **Elk schrijfendpoint**: login, permissie, POST-check, CSRF. In die
   volgorde, vóór er iets gelezen of geschreven wordt.
3. **Een request bepaalt nooit een prijs, een bestemming of een
   klassenaam.** Prijzen worden herberekend uit de database, registers zijn
   gesloten lijsten.
4. **Niet-publieke uploads** staan in `storage/`, buiten de webroot. Nooit in
   `assets/`.

## CSS

- **Semantische tokens**, nooit merknamen: `--color-primary`, niet
  `--goud`. Een tweede installatie moet er anders uit kunnen zien zonder dat
  er één regel CSS verandert.
- **Eén eigenaar per bestand.** Blok-CSS staat in `assets/css/blocks/<type>.css`
  en wordt gevraagd door de blokdefinitie. Core bevat alleen wat élke pagina
  gebruikt.
- **Elk bestand opent met een banner** die zegt wie de eigenaar is, wanneer
  het geladen wordt, en wat er nadrukkelijk niet in hoort.
- Geen `!important` zonder een regel commentaar erboven die het verdedigt.
- `prefers-reduced-motion` wordt overal gerespecteerd.

## JavaScript

- Platte ES-modules, geen framework, geen buildstap, geen transpiler. Wat de
  browser niet snapt, gebruik je niet.
- **Eén bestand per blok of route**, alleen geladen waar het nodig is.
- De pagina moet werken **zonder** het script. JavaScript verbetert, het
  draagt niet.
- Nooit een handgeschreven `<script>`-tag in een template: vraag hem via
  `App\Service\PageAssets`.

## Teksten voor de beheerder

De redacteur is geen ontwikkelaar. Wat hij leest is onderdeel van het product.

- **Geen jargon en geen type-keys.** "Veelgestelde vragen", niet "faq-blok".
- **Beschrijf wat het op de pagina doet**, in één of twee zinnen: *"Veelgestelde
  vragen onder elkaar. De bezoeker klikt een vraag open om het antwoord te
  lezen."*
- **Een foutmelding zegt wat er nu moet gebeuren**, niet wat er technisch
  misging.
- **Een lege staat legt uit hoe je hem vult**, niet dat hij leeg is.

## Commits

Engels, imperatief, één onderwerp per commit. De eerste regel zegt wat er
verandert voor de gebruiker van de code, niet welk bestand je aanraakte.

```text
Correct the content languages the multilingual migration stored
Fix localized Navigation and Footer persistence
Separate CMS, editing and public language state
```
