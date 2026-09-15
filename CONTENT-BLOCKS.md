# Content-blokken — werkdocument

Genoeg om een blok te bouwen of te wijzigen **zonder de bestaande blokken één
voor één te lezen**. Het model en de redenen erachter staan in
`docs/content-blocks/ARCHITECTURE.md` en `docs/content-blocks/DECISIONS.md`;
die worden hier niet herhaald. Wijkt de code af van dit document, dan heeft de code gelijk —
`App\Service\Blocks\BlockDefinitions` is de lijst van bloktypes (Core's eigen
plus die van de ingeschakelde modules), en de blokdefinitie ernaast is de bron
van waarheid over één blok.

## Uit welke onderdelen een blok bestaat

Hoe een redacteur zo'n blok vervolgens kiest en opslaat — de blokkenkiezer,
de Contentblokken-catalogus en de opslagbalk — staat in
[`PAGE-EDITOR.md`](PAGE-EDITOR.md). Hier gaat het over het blok zelf.

Neem Oproep met knop (`cta_band`) als volledig voorbeeld van een gewoon,
herhaalbaar blok:

| Onderdeel | Pad |
|---|---|
| Migratie + tabel | `db/migrations/*_create_cta_bands_table.php` → `cta_bands` |
| Repository (alle SQL) | `src/Repository/CtaBandRepository.php` |
| Inhoudsklasse | `src/Service/CtaBandContent.php` |
| Frontend-partial | `partials/section-cta-band.php` |
| Admin-editor | `admin/cta-band.php` |
| Admin-schrijfendpoint(s) | `api/admin/update-cta-band.php` |
| Blokdefinitie (alles wat het CMS van dit blok moet weten) | `src/Service/Blocks/CtaBandBlock.php` |
| Registratie (één regel) | `src/Service/Blocks/BlockDefinitions.php` |
| Eventuele JS/CSS | `assets/js/blocks/<type>.js`, `assets/css/blocks/<type>.css` — alleen als het blok ze nodig heeft |
| Tests | `tests/Service/`, suite `blocks` |

Een blok met een repeater erin (kaarten, items, punten) heeft extra endpoints
volgens hetzelfde patroon: `create-`, `update-`, `delete-` en soms `reorder-`
per onderdeel — zie `card_carousel` (`api/admin/*-carousel-card*.php`) of
`detail_section`.

## Het inhoudscontract: drie toestanden

Elke `*Content`-klasse geeft `forSection($pageSlug, $sectionKey)` terug met een
veld `state`:

| Toestand | Betekenis | Render |
|---|---|---|
| `STATE_FALLBACK` | Geen inhoudsrij, of de lookup faalde (database onbereikbaar) | Niets — en géén lege sectie met witruimte |
| `STATE_ACTIVE` | Rij bestaat en `is_active = 1` | De eigen inhoud van de instantie |
| `STATE_HIDDEN` | Rij bestaat en `is_active = 0` — een bewuste keuze van de beheerder | Niets |

Er is **geen hardcoded fallback-copy**: `STATE_FALLBACK` betekent "er valt niets
te tonen", nooit "toon de standaardtekst". Een lookup die faalt logt via
`error_log()` en degradeert naar `STATE_FALLBACK` in plaats van te crashen.

Twee onafhankelijke schakelaars verbergen een blok, en beide tellen:

- `page_sections.is_active` — de oogknop in de page builder. `renderPage()`
  filtert die er al uit.
- `is_active` op de inhoudsrij zelf — de editor van het blok. `render()`
  controleert dat apart. Een blok weer aanzetten in de page builder overschrijft
  dus nooit een hide die in de blok-editor is gezet.

**Taal:** een site heeft een hóófdtaal en optioneel één tweede taal
(`MULTILINGUAL.md`). De partial schrijft beide in `data-nl`/`data-en` via
`App\Service\Language\SiteText::attrs()` en print de hoofdtaal als zichtbare
tekst met `::visible()`; een lege vertaling betekent "gelijk aan de
hoofdtaal". Er wordt niets server-side vertaald.

In de **editor** zet je de twee velden in een taalpaneel
(`admin/_language_fields.php`), zodat een eentalige site er maar één toont en
een tweetalige site tabbladen krijgt. Een uitgezette taal blijft verborgen
meegestuurd, dus een taal uitzetten gooit nooit een vertaling weg.

## Instantie-identiteit

Een instantie wordt **altijd** geadresseerd op `(page_slug, section_key)` — ook
bij een type dat vandaag maar één keer voorkomt. Alleen op `page_slug` opslaan
is een verkapte "maximaal één per pagina, voor altijd".

Gevolgen waar je bij het bouwen rekening mee houdt:

- De inhoudstabel heeft een unieke sleutel op `(page_slug, section_key)`.
- `SectionRegistry::create()` genereert een willekeurige key (`custom-xxxxxxxx`)
  per nieuwe instantie; blokken van vóór de herhaalbaarheid dragen een
  gemigreerde key zoals `main`.
- De editor-URL is `admin/<type>.php?section=<page_slug>:<section_key>`, en het
  endpoint splitst die parameter en controleert dat pagina én inhoudsrij
  bestaan voordat het iets doet.
- Alles wat aan één instantie hangt — weergave-instellingen, JS-gedrag, DOM-id's
  — moet zich op die instantie scopen. Twee instanties van hetzelfde type op één
  pagina staan volledig los van elkaar.
- Afgeleide nummering en afwisselende achtergronden tellen over de **actieve
  instanties op de pagina**, nooit over een vaste lijst.

## Een blok toevoegen

```text
blokeigen bestanden maken  →  eventueel blokeigen JS/CSS
                           →  één blokdefinitie schrijven en registreren
                           →  --testsuite blocks
```

Sinds stap 3 heeft één bloktype **één definitie en één registratieregel**. Er is
geen gedeeld bestand meer waarin je op zeven plekken per type moet uitsplitsen:
`SectionRegistry` kent geen enkel bloktype bij naam.

1. **Migratie.** Nieuwe tabel `<type>s` met minimaal `page_slug`,
   `section_key`, `is_active` en `UNIQUE(page_slug, section_key)`.
   Forward-only, idempotent, MySQL-compatibel. Bestaat er al inhoud die dit blok
   overneemt, migreer die dan mee in dezelfde migratie.
2. **Repository** — `src/Repository/<Type>Repository.php`, extends
   `Repository`. Alle SQL hier, inclusief `findBySlugAndKey()`, een
   `createSection()`-achtige en `deleteSection()`.
3. **Inhoudsklasse** — `src/Service/<Type>Content.php` met de drie
   `STATE_*`-constanten, `forSection()`, een statische per-request cache en
   `clearCache()`.
4. **Partial** — `partials/section-<type>.php` met één functie
   `render_section_<type>(array $content, ...)`. De aanroeper heeft
   `STATE_HIDDEN` al afgevangen; de partial zorgt zelf dat lege inhoud geen
   gat achterlaat. URL's root-relatief (`/assets/…`), alle output door
   `htmlspecialchars()`.
5. **Admin-editor** — `admin/<type>.php`, leest `?section=<slug>:<key>`.
6. **Endpoint(s)** — `api/admin/update-<type>.php`, in deze volgorde:
   `AdminAuth::requireLoginForApi()`, `AdminAuth::requirePermissionForApi('pages.manage')`,
   POST-check, `Csrf::validate()`, `section` splitsen en valideren (pagina én
   inhoudsrij moeten bestaan), repository, cache legen, PRG-redirect met
   session-flash.
7. **Blokdefinitie** — `src/Service/Blocks/<Type>Block.php`, extends
   `BlockDefinition`. Dit is het integratiecontract, niet de logica: het
   koppelt de bestanden hierboven aan het CMS. Alle methodes zijn `abstract`,
   dus je kunt er geen vergeten — laat je er één weg, dan laadt de klasse niet.

   | Methode | Wat het teruggeeft |
   |---|---|
   | `type()` | De type-key, gelijk aan de sleutel waaronder je registreert |
   | `meta()` | `label`, `manual_add`, `allow_multiple`, `max_instances`, `allowed_pages`/`denied_pages`, `deletable`, eventueel `app_critical` |
   | `description()` | Eén of twee zinnen: wat zet dit blok op de pagina? In de taal van de redacteur, nooit met de type-key erin — zie [`PAGE-EDITOR.md`](PAGE-EDITOR.md) |
   | `category()` | Een sleutel uit `BlockCategories`; bepaalt alleen onder welk kopje het blok in de kiezer en de catalogus staat |
   | `icon()` | De *binnenkant* van een 24x24 stroke-`<svg>`, net als de sidebar-iconen. Vaste, eigen markup — nooit uit een request of de database |
   | `create()` | Maakt een lege inhoudsrij en geeft `[section_id, section_key]` — een herhaalbaar blok haalt zijn key bij `self::newSectionKey()` |
   | `deleteContent()` | Ruimt de inhoudsrij op; draait in de transactie van de registry, dus zelf niet committen |
   | `render()` | Roept `forSection()` aan, vangt `STATE_HIDDEN` af en roept de partial aan |
   | `editUrl()` | Meestal `$this->sectionEditUrl('<admin-bestand>', $pageSection)` |
   | `clearCache()` | `<Type>Content::clearCache()` |
   | `contentTable()` | De tabelnaam — vertrouwde metadata waarmee tests hun eigen rijen opruimen |
   | `styles()` / `scripts()` / `vendorScripts()` | De eigen frontend van dit blok; standaard leeg — zie stap 8 |

   Optioneel, met een veilige standaard: `preview()` (de vormen waaruit de
   schets op de blokkaart wordt getekend, uit de gesloten lijst in
   `BlockPreview`) en `useCases()` (twee tot vier voorbeeldsituaties: de
   catalogus toont ze, de blokkenkiezer zoekt erop),
   `deleteFiles()` (alleen als het blok
   nog *eigen* uploads heeft — vóór de transactie, gescoopt op déze
   instantie; een blok dat de Mediabibliotheek gebruikt laat hem leeg, want
   een gedeeld bestand is niet van dat blok),
   `instanceTitle()` (waaraan de beheerder twee instanties uit elkaar houdt) en
   `tightensFollowingBlock()` (alleen de hero's).

   Zet de `require_once` van je partial bovenaan het definitiebestand, zodat
   een pagina alleen de partials laadt van de blokken die er echt op staan.

   Deze drie zijn `abstract` om dezelfde reden als de rest: een blok dat
   zichzelf niet beschrijft zou anders met een lege kaart in de blokkenkiezer
   belanden zonder dat iets faalt. De kiezer én de
   Contentblokken-catalogus lezen ze allebei van hier — er is geen tweede
   plek waar een blokbeschrijving staat, en
   `Tests\Service\BlockPresentationTest` faalt zodra er een bij komt.

   **`create()` heeft twee aanroepers.** Naast de blokkenkiezer gebruiken
   ook de paginasjablonen hem, via
   `App\Service\PageTemplates\PageTemplateInstaller`
   ([`PAGE-TEMPLATES.md`](PAGE-TEMPLATES.md)). Dat verandert niets aan het
   contract — het is dezelfde aanroep — maar het betekent wel dat de
   startinhoud die jouw `create()` schrijft óók is wat een redacteur ziet
   die een verse pagina uit een sjabloon aanmaakt. Houd hem daarom generiek
   en duidelijk bewerkbaar ("Nieuwe sectie — pas deze titel aan"), en nooit
   sitespecifiek: een sjabloon vult zelf geen tekst in, juist omdat die tekst
   van het blok is.

   Twee dingen waar de installer op let, en die dus eisen zijn aan je
   `meta()`: een sjabloon mag alleen een blok noemen dat `manual_add` is, en
   niet vaker dan `max_instances` toestaat. Een blok dat per *pagina* wordt
   opgeslagen in plaats van per instantie (zoals de Page Hero) heeft
   daarom `max_instances => 1` nodig, anders zou een tweede aanroep dezelfde
   inhoudsrij teruggeven.
8. **Eigen JS/CSS — alleen als het blok die nodig heeft.** Zet ze in
   `assets/js/blocks/<type>.js` en `assets/css/blocks/<type>.css` en noem ze
   in je definitie:

   ```php
   public function styles(): array  { return ['assets/css/blocks/<type>.css']; }
   public function scripts(): array { return ['assets/js/blocks/<type>.js']; }
   ```

   Meer hoef je niet te doen: `SectionRegistry::collectPageAssets()` vraagt élk
   bloktype op de pagina hiernaar vóórdat de `<head>` geschreven wordt, en
   `App\Service\PageAssets` ontdubbelt en print de tags. Er is geen globale
   lijst om bij te werken en geen `style.css`/`main.js` meer om in te
   schrijven — dat is precies wat stap 4 opgeleverd heeft.

   Regels:

   - **Heeft je blok geen gedrag, maak dan geen leeg JS-bestand.** Laat
     `scripts()` weg; de standaard is een lege lijst.
   - **Is een regel écht gedeeld** (andere blokken of routes gebruiken hem
     ook), laat hem dan in `assets/css/core.css` staan in plaats van hem te
     kopiëren. Je blokbestand laadt ná Core, dus je kunt hem gewoon
     overschrijven.
   - **Het pad moet een bestaand bestand onder `assets/` zijn.**
     `PageAssets` negeert al het andere en
     `Tests\Service\FrontendAssetOwnershipTest` laat de build falen op een
     pad dat niet bestaat, of op een blokasset die geen enkele definitie
     opeist.
   - **Eén script per bloktype, hoe vaak het blok ook op de pagina staat.**
     Scoop je gedrag dus per instantie — zie "Regels voor geïsoleerde
     blokken" hieronder.
   - **Een externe bibliotheek noem je bij naam**, nooit met een URL:
     `vendorScripts()` geeft sleutels terug uit de gesloten lijst in
     `PageAssets`. Vandaag staat daar alleen `gsap` in, gevraagd door
     `homepage_hero`.

9. **Registratie — één regel.** Hoort het blok bij Core, dan in
   `src/Service/Blocks/BlockDefinitions.php`:

   ```php
   '<type>' => <Type>Block::class,
   ```

   Hoort het bij een module, dan in de `blockDefinitions()` van díé module
   (`src/Module/<Naam>Module.php`) — exact dezelfde regel, alleen in het
   bestand van de eigenaar. `BlockDefinitions` voegt Core's lijst en die van
   de ingeschakelde modules samen tot één register met één lookup ervoor; er
   is dus nog steeds één registratielijst en geen tweede dispatch. Zie
   `MODULES.md`.

   De volgorde is de volgorde waarin het CMS de blokken toont: eerst Core, dan
   de modules. Registratie is expliciet en gesloten: geen mapscan, geen
   reflectie, geen klassenaam uit een request of een databaserij.
10. **Tests** — zie hieronder.

Voor een **vast blok** (niet handmatig toe te voegen of te verwijderen) extend
je `FixedBlockDefinition` in plaats van `BlockDefinition`: die beantwoordt de
inhoudshelft van het contract al (niets aanmaken, niets verwijderen, geen cache,
geen tabel), zodat er alleen `type()`, `meta()` en `render()` overblijven. In
`meta()` horen dan `manual_add = false`, `deletable = false`,
`max_instances = 1`, plus `kind`, `badge_label`, `note` en `edit_links` zodat de
beheerder ziet waar de inhoud wél beheerd wordt.

## Een blok verwijderen of vervangen

Een bloktype uit `BlockDefinitions` halen is een **datamigratie**, niet alleen
een codewijziging. Zolang er ergens een `page_sections`-rij met dat type staat,
noemt de database een blok dat het CMS niet meer kent.

Twee regels, allebei geleerd van `related_products` — een blok dat in
ontwikkeling bestond, vóór de commit vervangen werd door iets anders, en één
rij achterliet die de hele publieke pagina neerhaalde:

- **Een migratie over contentblokken selecteert op de data, niet op een lijst
  bekende pagina-slugs.** Dus `WHERE section_type = '<oud type>'` over álle
  rijen, of de legacy-tabel als bron. Een vaste lijst slugs mag alleen als de
  migratie per ontwerp over één pagina gaat (het contactformulier op `contact`,
  de shop-notitie op `shop`) omdat die inhoud nooit ergens anders kón staan —
  en dan zegt de migratie dat er met zoveel woorden bij.
- **Pagina's die een gebruiker zelf heeft aangemaakt zijn gewone CMS-data.** Ze
  hebben een willekeurige slug die niemand bij het schrijven van de migratie
  kende, en ze horen net zo goed in de migratietests als `index` of `contact`.
  Een fixture die alleen een voorgedefinieerde pagina gebruikt bewijst niets
  over de rij die je echt kwijtraakt.

Latere fases vinden zo'n rij niet meer: elke migratie daarna selecteert op een
type dat ze zelf kent, en een verweesd type staat in geen van die lijsten. Ruim
hem dus op in dezelfde stap waarin je het type weghaalt.

## Een onbekend bloktype in de data

Het kan alsnog gebeuren — een half teruggedraaide migratie, een handmatige
import, een oudere back-up. Of, sinds stap 5, iets veel gewoners: het blok
hoort bij een **module die uit staat**. De regel is in beide gevallen:
**niets weggooien, niets laten crashen.**

| Waar | Wat er gebeurt |
|---|---|
| Publieke pagina | `SectionRegistry::render()` slaat de sectie over. De rest van de pagina rendert normaal, de bezoeker ziet geen foutmelding en geen stacktrace. |
| Serverlog | Eén regel per rij per request via `error_log()`: type, pagina, `page_sections.id`, `section_key` en `sort_order`. |
| Page builder | De rij staat er als "Niet-ondersteund contentblok" met het type erbij en de melding dat de gegevens bewaard zijn. |
| De rij zelf | Blijft staan. Er wordt nooit automatisch een sectie verwijderd. |

Hoort het type bij een uitgeschakelde module, dan zegt het CMS dát in plaats
van "niet ondersteund": de page builder toont "Blok van een uitgeschakeld
onderdeel" met de modulenaam en de mededeling dat het blok terugkomt zodra het
onderdeel weer aan staat, en het serverlog noemt de module.
`SectionRegistry::disabledModuleFor()` is het verschil. Publiek gedragen ze
zich identiek: overslaan, rest van de pagina normaal.

Alleen `render()`/`renderPage()` degraderen zo. De schrijfkant
(`create()`, `delete()`) weigert een onbekend type nog steeds hard — daar is
"ik ken dit type niet" een fout, geen situatie om omheen te werken.

## Regels voor geïsoleerde blokken

- **Een blok bouwt geen eigen formulier.** Wil je bezoekers iets laten
  invullen, gebruik dan het blok `form`: dat kiest een formulierdefinitie
  die de beheerder onder Beheer → Formulieren maakt, en de velden, de
  validatie, de melding en de bewaarde inzendingen komen allemaal van Core
  Forms (`FORMS.md`). Een tweede blok met eigen `<input>`-velden zou een
  tweede validatie- en e-mailmotor betekenen, en precies dat is wat Core
  Forms heeft opgeruimd.
- **Een blok bouwt geen eigen uploadveld voor een publieke afbeelding.**
  Gebruik de mediakiezer (`admin/_media_picker.php`): één regel in de editor,
  een `media_id` in je formulier, en `App\Service\Media\BlockImage` in je
  inhoudsklasse. De volledige receptuur staat in `MEDIA.md`; `text_image_split`,
  `detail_section` en `card_carousel` zijn de voorbeelden, en `page_hero` is het
  voorbeeld van een blok dat nooit een eigen afbeelding had en dus alleen een
  `media_id` kreeg.
- **Geen blok leest de opslag van een ander blok.** Wil je andermans gegevens,
  ga dan via de repository of `*Content`-klasse van dat domein.
- **Is een blok een ander blok met een vaste instelling**, zoals Projecten
  (`project_cards`) op de galerij, dan deelt het diens repository,
  inhoudsklasse en partial in plaats van ze te kopiëren. Een rij wordt dan
  alleen bewerkt door de editor van het bloktype dat hem plaatste
  (`page_sections.section_type`), en de gedeelde assets staan in
  `FrontendAssetOwnershipTest::SHARED_BLOCK_ASSETS`.
- **Blokgedrag staat in het bestand van het blok**, niet in
  `assets/js/core.js`. Core is de site-schil: taalwissel, header/navigatie
  en de generieke reveal. Een initialiser voor één bloktype hoort daar
  niet, en `Tests\Service\FrontendAssetOwnershipTest` faalt als er een
  terugkomt.
- **JS scoopt zich op de instantie.** Loop over
  `document.querySelectorAll('[data-<blok>-block]')` en werk binnen dát element.
  Nooit één `document.querySelector()` alsof er maar één per pagina bestaat.
  Gedeelde overlays (lightbox) worden door het eerste blok dat ze nodig heeft
  geclaimd en buiten de `<section>` geprint.
- **Invoer uit een request wordt nooit een klasse- of tabelnaam.** Een type-key
  gaat eerst langs `SectionRegistry`, een bronsleutel langs de gesloten lijst
  van dat blok (`App\Service\ItemGallerySources`) — bij schrijven én bij lezen.
  Elke bron in die lijst is een modulebijdrage (portfolio-items van
  Portfolio, een collectie producten van de Shop), met een `order` die bepaalt
  waarmee een nieuw blok begint, en de lijst blijft gesloten en in code
  geschreven.
- **Gesloten lijsten zijn een beveiligingsgrens**, geen stijlkeuze. Vervang ze
  niet door een generieke query-builder of een "entity + filters"-abstractie.
- **Blokgedrag hoort in de bestanden van het blok.** Voeg geen pagina-specifieke
  `if` toe aan een gedeeld bestand als een blokeigenschap of registry-regel het
  oplost.
- **Weergave-instellingen horen bij de instantie**, niet bij de pagina en niet
  bij de catalogus erachter.
- **Een weergavekeuze is een woord uit een gesloten lijst, nooit een
  CSS-waarde.** De inhoudsklasse houdt de lijst en leest een onbekende waarde
  als de standaard, het endpoint weigert hem, en de partial maakt er een
  modifier-class van. De standaard krijgt géén class, zodat een bestaande
  instantie na de migratie precies blijft zoals hij was, en een maat is een
  stap op de typeschaal in `core.css` (`--fs-*`). `page_hero` is het voorbeeld
  (positie van de tekst, titel- en tekstgrootte).
- **Bestaande inhoud blijft behouden** bij migraties en refactors.

## Tests

Commando's en tiers staan in `TESTING.md`. Draai de suite in de service `php_test`.

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
```

| Wijziging | Draai |
|---|---|
| Nieuw blok | `fast` → `blocks`; plus `cms` als je aan pagina's/registratie zat, en `modules` als het blok van een module is |
| Blok met een formulier erin | ook `fast` → `cms` (`FORMS.md`) |
| Blok-editor of endpoint | `fast` → `blocks` (`PageBuilderSecurityTest` bewaakt de guards) |
| Alleen rendering | `blocks`; de HTTP-tests daarin hebben de `php_test`-container nodig |
| Migratie/backfill | `blocks` → `--group migration-backfill` → volledige suite |

Een nieuw testbestand moet in `phpunit.xml` in de suite `blocks` (en `http` als
het requests doet). Doe je dat niet, dan faalt
`Tests\Architecture\TestSuiteCoverageTest`.

Maak in een test **altijd je eigen wegwerp-pagina** — hang nooit blokken aan een
echte pagina zoals `contact`. `tests/Service/SectionRegistryTest.php` is het
voorbeeld.

`tests/Service/BlockDefinitionContractTest.php` loopt automatisch over élk
geregistreerd type, dus je nieuwe blok wordt daar meegenomen zodra het in
`BlockDefinitions` staat — zonder dat je die test aanpast. Faalt hij, dan mist
je definitie iets uit het contract.

## Waarom het register geen bloktypes meer kent

Vóór stap 3 stond alle blokkennis in `SectionRegistry` zelf: 1058 regels met
zeven plekken die per type uitsplitsten (`TYPES`, `create()`, `delete()`,
`render()`, `instanceLabel()`, `editUrl()`, `clearContentCache()`). Eén nieuw
blok betekende zeven bewerkingen in één bestand, en niets waarschuwde je als je
er één oversloeg — het blok brak pas bij de handeling die die tak gebruikte.

Nu is `SectionRegistry` 484 regels zonder één `switch`, `match` of type-key. Het
valideert de type-key, past de regels toe die voor élk blok gelijk zijn
(paginabeperkingen, instantielimieten, de verwijdervolgorde en -transactie,
rendervolgorde) en delegeert de rest aan de blokdefinitie.

Wat dat bewaakt:

- `Tests\Service\BlockDefinitionContractTest` faalt zodra `SectionRegistry` een
  bloktype weer bij naam noemt, en zodra een methode van `BlockDefinition` niet
  meer `abstract` is — precies de twee manieren waarop de oude situatie
  terugkomt.
- Datzelfde bestand controleert per geregistreerd type of de definitie het hele
  contract levert: metadata, een bestaande editor, een adres per instantie voor
  een herhaalbaar blok, en een tabelnaam die uit de registratie komt en nooit
  uit een request.

De beveiligingsgrens is niet verschoven: `section_type` uit een request kan nog
steeds alleen een sleutel van de registratielijst raken of missen. Missen is
weigeren; een klas- of tabelnaam wordt het nooit.
