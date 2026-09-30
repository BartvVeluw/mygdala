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

Dit is waar het meestal misgaat. `forSection()` geeft een `state` terug, en
twee van de drie toestanden renderen niets:

- **`STATE_FALLBACK`** — er is geen rij, of de lookup faalde (database
  onbereikbaar). Render **niets**, en laat ook geen gat achter: geen lege
  sectie met verticale ruimte. Er is géén hardcoded fallback-copy. Een
  mislukte lookup logt via `error_log()` en degradeert naar deze toestand in
  plaats van te crashen.
- **`STATE_ACTIVE`** — de rij bestaat en is actief. Render de eigen inhoud van
  de instantie, en nooit een standaardtekst in de plaats daarvan. Is die
  inhoud leeg, doordat alle items verborgen zijn of er nog geen enkel item is,
  dan rendert de partial om dezelfde reden ook niets: een leeg kader met
  witruimte is geen inhoud. `partials/section-marquee.php` en
  `partials/section-card-carousel.php` zijn de voorbeelden.
- **`STATE_HIDDEN`** — de rij bestaat en staat op `is_active = 0`. Render
  **niets**. Anders kan de "Actief"-checkbox niets verbergen.

Losse items volgen die regel niet: een individueel verborgen item blijft
verborgen, ook als de lijst daardoor leeg is.

**Een lege structuur, geen fallback-copy.** Bij `STATE_FALLBACK` en
`STATE_HIDDEN` geeft een `*Content`-klasse dezelfde velden terug, maar leeg; een
nieuw blok volgt `CtaBandContent::emptyContent()`. Waarmee een nog niet
bestaande rij in de editor begint, is een aparte vraag met een eigen methode,
zoals `PageHeroContent::startingValues()`: generieke, bewerkbare tekst die
nooit rendert in plaats van een ontbrekende rij. `Tests\Service\NoFallbackCopyTest`
en `GenericBlockDefaultsTest` bewaken beide.

## Waar je op moet letten

- **Heeft je blok een anker?** Implementeer `ContributesAnchor`; de
  ankernavigatie onder de paginakop neemt het dan vanzelf mee
  (`App\Service\Blocks\AnchorNavigation`, vorm via `AnchorName`). Laat een
  blok een product, project of bericht als gelinkte afbeelding tonen, gebruik
  dan `App\Service\Media\LinkedImages` en `admin/_gallery_source_field.php`,
  nooit een eigen `if shop`/`if blog`. Het formulier draagt dan
  `data-nav-item-form`, anders staan alle bronpanelen tegelijk open
  (`CONTENT-BLOCKS.md`, "Detailsectie 2.1").
- **Een blok staat niet alleen op pagina's.** Een product en een project
  hebben een inhoudspagina met dezelfde blokken (`CONTENT-BLOCKS.md`, "Blokken
  op een product of project"). Een gewoon blok hoeft daar niets voor te doen.
  Hangt je blok echt aan een gewone pagina (haar titel, haar adres), of alleen
  aan één soort eigenaar, zet dan `owners` in `meta()`; nooit een eigen
  `page_id`-aanname in `render()`.
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
- **Wat aan de instantie hangt, blijft op de instantie gescoopt.**
  `render()` krijgt van `SectionRegistry` een `$revealGroup` van de vorm
  `<type>-<page_sections.id>`; geef die door aan je partial en print hem in
  `data-reveal-group`. Vervang hem nooit door een vaste string: `core.js`
  groepeert documentbreed op die waarde, dus bij een herhaalbaar blok
  belanden alle instanties in één stagger-groep en verschijnt de tweede te
  laat. `render_section_feature_grid()` is het voorbeeld. Hetzelfde geldt
  voor DOM-id's en blok-JS.
- **Een blok zonder woorden** (Witruimte, Mediabanner) geeft in
  `translatableFields()` een lege lijst en staat met naam in
  `BlockDefinitionContractTest::WORDLESS_WITH_ROWS`. Een blok dat een
  afbeelding óf een video neemt, gebruikt één mediakiezer met
  `MediaType::VISUAL` en laat het gekozen item beslissen (`MEDIA.md`), nooit
  een eigen keuzelijst voor het type.
- **Snijdt je blok een beeld bij in een kader?** Sluit de plek aan op
  Responsive Media (`MEDIA.md`, "Een plek aansluiten"): een
  `ResponsiveImageSlot`, `render_responsive_image()` in de partial,
  `responsive_image_field()` in de editor en de plek in
  `ResponsiveMediaContractTest`. Het kader knipt af (`overflow: hidden`, en
  het kader in `ResponsiveMediaZoomContractTest::FRAMES`), anders loopt een
  zoom erbuiten. Nooit een eigen `object-position`, `scale` of `<picture>`.
- **Eén blok, meer kiezerkaarten?** Implementeer `OffersPickerPresets`
  (presets, zoals de galerij als Collectiegalerij en Portfoliogalerij), nooit
  een tweede bloktype met dezelfde tabel of editor (`PAGE-EDITOR.md`).
- **Een onbekend bloktype is geen fout**: de sectie wordt overgeslagen en de
  rest van de pagina rendert normaal.
- **Toont je blok items uit een bron** (zoals de galerij en Projecten), laat
  de bron dan kiezen en sorteren (`ItemGallerySources`), controleer de keuze
  met `ItemGallerySelection` en toon hem met `admin/_gallery_selection.php`;
  willekeur gaat via `App\Service\RandomOrder`, op de server
  (`CONTENT-BLOCKS.md`, "Projecten 2.0").
- **Editor en endpoint zijn eigenaarsbewust.** Een blok kan op een pagina,
  een product of een project staan, en het recht hoort bij de eigenaar, niet
  bij de houderpagina: `ContentBlockAccess::requireAny()` /
  `requireAnyForApi()` op de plek van het recht, de pagina via
  `pageForKey()` / `pageForKeyForApi()`, de terug-link via
  `ContentBlockAccess::listUrl($page)`, en beide bestanden in de lijsten van
  `AdminAccessControlTest` (`CONTENT-BLOCKS.md`, "Wie mag welke blokken
  beheren").
- **Een nieuw blok is een draft tot zijn eerste opslag.** Het endpoint zet
  in zijn transactie, als laatste schrijfactie,
  `$placed = ContentBlockDrafts::place('<type>', $id);` en landt na de commit
  op `ContentBlockAccess::afterSaveUrl($placed, $redirect)`; de editor zet zijn
  terug-link in `block_editor_draft_notice('<type>', $csrfToken)`. Nooit een
  terugkeeradres uit het request (`CONTENT-BLOCKS.md`, "De levensloop van een
  nieuw blok").
- **Kan je blok leeg zijn?** Implementeer `App\Service\Blocks\InspectsContent`
  (`hasContent()`, dezelfde regel als je partial), of zet het met reden in
  `ContentBlockLifecycleContractTest::NEVER_EMPTY` (decoratief of dynamisch).
- **Een nummer of label boven een titel?** Gebruik `App\Service\Blocks\LabelMode`
  en het veld `admin/_label_mode_field.php`; een nummer is een plek, nooit
  opgeslagen.
- **Een blok van een module met een eigen editor** (Uitgelicht product van de
  Shop) vraagt het recht van zijn bloklijst (`pages.manage` op een pagina), een Core-recht dat ook met de module uit
  gehouden wordt. Zet daarom `ModuleGuard::requireAdmin()` bovenaan de editor
  en `ModuleGuard::requireApi()` bovenaan het endpoint, en claim het scherm in
  `AdminNavigation` onder *Pagina's*.
- **Heeft je blok een knop of een klikbare kaart?** Gebruik de
  bestemmingskiezer (`admin/_link_target_field.php`, en
  `link_target_scripts()` op het scherm), bewaar met
  `App\Service\Routing\LinkChoice` en render met `LinkChoice::href()`: nooit
  een eigen paginalijst, een eigen typekeuze of een eigen URL-controle. Een
  ander getypt adresveld gaat door `App\Service\Routing\SafeUrl`
  (`CONTENT-BLOCKS.md`, "Waar een knop heen gaat").
- **Moet de redacteur kunnen kiezen hoe die knop eruitziet?** Het veld
  *Knopstijl*: `admin_button_style_field()`, een nullable
  `…button_style_id` met RESTRICT-sleutel in `ButtonStyleRepository::SLOTS`,
  `ButtonStyles::choiceFromRequest()` in het endpoint en
  `ButtonStyles::classes()` in de partial (`CONTENT-BLOCKS.md`, "Hoe een knop
  eruitziet"). Nooit eigen knop-CSS in de blokstylesheet, en het blok in
  `ButtonStyleBlocksTest::CONNECTED`.
- **Heeft je blok kaarten met een titel?** De tag komt van
  `App\Service\Blocks\CardHeading::under()`: een `<h3>` onder de eigen
  bloktitel, een `<h2>` zonder. Geef de kaarttitel een klasse die zijn maat
  draagt en stijl hem nooit op het element (`CONTENT-BLOCKS.md`, "Koppen in
  kaarten"); `CardHeadingContractTest` rendert je voorbeeld met en zonder
  titel.
- **Kan je blok Extra vormgeving dragen?** Laat de partial één root-element
  printen (een `<section>` met de inhoud in een `.container` direct eronder)
  en zet `appearanceSupport()` op `AppearanceSupport::section()`, eventueel
  met minder effecten. Zet het type in `BlockAppearanceContractTest::SUPPORT`.
  Geen eigen achtergrond-, rand- of ruimteveld in je editor: het paneel in de
  bloklijst doet dat voor elk blok (`CONTENT-BLOCKS.md`, "Extra vormgeving").
- **Een partial rendert alleen, en een blok heeft een voorbeeld.** Wat de
  partial toont komt als argument binnen; opzoeken doet `render()`. Schrijf
  `sampleContent()` (woorden uit `BlockSamples`, in de vorm van je
  `*Content`) en `renderSample()` (dezelfde partial-aanroep), zodat de
  Contentblokken-bibliotheek het echte blok laat zien (`PAGE-EDITOR.md`).

## Testen

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
```

`Tests\Service\BlockPresentationTest` faalt zodra een blok zijn presentatie
niet beschrijft, `BlockSampleContractTest` zodra het geen bruikbaar voorbeeld
heeft, en `FrontendAssetOwnershipTest` zodra een asset geen eigenaar heeft.
