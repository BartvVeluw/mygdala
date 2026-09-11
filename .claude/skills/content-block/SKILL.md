---
name: content-block
description: Een content-blok toevoegen, wijzigen of verwijderen in Mygdala - het recept van migratie tot blokdefinitie, het inhoudscontract met zijn drie toestanden, de blokkenkiezer en de frontend-assets van een blok. Gebruik dit bij elk werk aan een bloktype, een blok-editor of partials/section-*.php.
---

# Content-blok

Elke CMS-pagina is één geordende lijst blok-instanties. Een instantie is
altijd `(page_slug, section_key)`.

## Lees dit eerst

`CONTENT-BLOCKS.md`, hoofdstuk "Een blok toevoegen". Dat is het volledige
recept. `docs/content-blocks/DECISIONS.md` alleen als je een architecturale
keuze raakt.

## De acht onderdelen van één blok

| # | Bestand | Wat het doet |
|---|---|---|
| 1 | `db/migrations/<datum>_<naam>.php` | Tabel `<type>s` met `page_slug`, `section_key`, `is_active`, `UNIQUE(page_slug, section_key)` |
| 2 | `src/Repository/<Type>Repository.php` | Alle SQL, met `findBySlugAndKey()`, een `createSection()` en `deleteSection()` |
| 3 | `src/Service/<Type>Content.php` | Het leesmodel: drie `STATE_*`-constanten, `forSection()`, per-request cache, `clearCache()` |
| 4 | `partials/section-<type>.php` | Eén functie `render_section_<type>()` |
| 5 | `admin/<type>.php` | De editor, leest `?section=<slug>:<key>` |
| 6 | `api/admin/update-<type>.php` | Het schrijfendpoint |
| 7 | `src/Service/Blocks/<Type>Block.php` | Het integratiecontract |
| 8 | `assets/{css,js}/blocks/<type>.*` | Alleen als het blok eigen frontend heeft |

Plus één regel in `BlockDefinitions` (of in `blockDefinitions()` van de module
die het blok bezit).

## Het inhoudscontract: drie toestanden

Dit is waar het meestal misgaat. `forSection()` geeft een `state` terug:

- **`STATE_FALLBACK`** — er is geen rij, of de database was onbereikbaar.
  Render de standaardinhoud, zodat de publieke site nooit breekt door een
  CMS-probleem.
- **`STATE_ACTIVE`** — de rij bestaat en is actief. Render zijn eigen inhoud,
  ook als die leeg is.
- **`STATE_HIDDEN`** — de rij bestaat en staat op `is_active = 0`. Render
  **niets**, en val nadrukkelijk **niet** terug op de standaardinhoud.
  Anders kan de "Actief"-checkbox niets verbergen.

Losse items volgen die regel niet: een individueel verborgen item blijft
verborgen, ook als de lijst daardoor leeg is.

## Waar je op moet letten

- **Alle methodes van `BlockDefinition` zijn `abstract`.** Vergeet je er een,
  dan laadt de klasse niet. Dat is de bedoeling.
- **`create()` heeft twee aanroepers**: de blokkenkiezer en de
  paginasjablonen. De startinhoud die je schrijft is dus ook wat een
  redacteur ziet bij een verse pagina. Houd hem generiek en bewerkbaar
  ("Nieuwe sectie — pas deze titel aan"), nooit sitespecifiek.
- **`description()`, `category()` en `icon()` zijn verplicht** omdat een blok
  dat zichzelf niet beschrijft anders met een lege kaart in de kiezer belandt.
  Schrijf ze in de taal van de redacteur, zonder de type-key.
- **Zet de `require_once` van je partial bovenaan het definitiebestand**, dan
  laadt een pagina alleen de partials van de blokken die er echt op staan.
- **Vraag CSS en JS via `styles()` / `scripts()`**, nooit met een
  handgeschreven tag in een template.
- **Een onbekend bloktype is geen fout**: de sectie wordt overgeslagen en de
  rest van de pagina rendert normaal.

## Testen

```bash
docker exec mygdala_php_test php vendor/bin/phpunit --testsuite blocks
```

`Tests\Service\BlockPresentationTest` faalt zodra een blok zijn presentatie
niet beschrijft, en `FrontendAssetOwnershipTest` zodra een asset geen eigenaar
heeft.
