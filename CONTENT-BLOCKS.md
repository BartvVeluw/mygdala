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
| Woorden per taal | `translatableFields()` in de definitie → `block_translations`, via `App\Service\Blocks\BlockLocalization` (geen eigen migratie) |
| Repository (alle SQL) | `src/Repository/CtaBandRepository.php` |
| Inhoudsklasse | `src/Service/CtaBandContent.php` |
| Frontend-partial | `partials/section-cta-band.php` |
| Admin-editor | `admin/cta-band.php` |
| Admin-schrijfendpoint(s) | `api/admin/update-cta-band.php` |
| Blokdefinitie (alles wat het CMS van dit blok moet weten) | `src/Service/Blocks/CtaBandBlock.php` |
| Registratie (één regel) | `src/Service/Blocks/BlockDefinitions.php` |
| Eventuele JS/CSS | `assets/js/blocks/<type>.js`, `assets/css/blocks/<type>.css` — alleen als het blok ze nodig heeft |
| Tests | `tests/Service/`, suite `blocks` |

Een blok met een repeater erin (kaarten, items, punten) slaat die rijen op
met het blok zelf, in één formulier en één endpoint: zie `card_carousel` en
[`PAGE-EDITOR.md`](PAGE-EDITOR.md), "Eén formulier per blok-editor". De oudere
editors (onder meer `detail_section`) hebben nog `create-`, `update-`,
`delete-` en `move-`endpoints per onderdeel. Elke rij van zo'n onderdeel bezit zijn eigen woorden in
`block_translations`, onder zijn eigen tabel en id: zie *Taal* hieronder.

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

**Taal.** Elk blok bewaart zijn woorden per websitetaal in `block_translations`
(Multilingual 2.0, fase 3A en 3B). Er is geen blok meer met
`_nl`/`_en`-kolommen, en er komt er geen bij. Het volledige contract staat in
[`docs/multilingual/ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md),
*Contentblokken per taal*.

- De definitie declareert de woorden in `translatableFields()`, per tabel die
  ze bezit: de inhoudstabel en elke kindtabel. De tabellen houden alleen wat in
  elke taal gelijk is (URL's, `is_active`, media, volgorde, weergavekeuzes).
- **Kindrijen** (een vraag, een kaart, de tags van een kaart) zijn eigenaar van
  hun eigen woorden, onder hun eigen tabel en `id`. De definitie noemt elke
  kindtabel in `childTables()`, met de tabel en kolom waaraan hij hangt;
  `BlockTranslationSchemaTest` houdt dat tegen de echte foreign key.
- De `*Content`-klasse geeft de partial per veld één string in de taal van
  het verzoek (`BlockLocalization::words()`, `::text()` of `::first()`); de
  terugval (gevraagde taal → standaardtaal → leeg) zit daar, niet in het
  blok. **De standaardtaal
  beslist of iets verschijnt**: een blok, en elk item, zonder zijn verplichte
  woorden in de standaardtaal rendert niet (`BlockLocalization::hasRequiredWords()`).
- De partial print platte tekst met `htmlspecialchars()` en gesaneerde rich
  text zoals hij is. Hij kent geen taal. Een URL die de redacteur typte gaat
  in de `*Content`-klasse door `App\Service\Routing\TypedLink::href()`, zodat
  een knop op `/en/` naar de Engelse versie van de pagina wijst.
- **Een blokknop met een linkdoel** (een carrouselkaart, de twee knoppen van
  de Homepage-hero, de knop van het Tekstblok, de knop van een item van Tekst
  met afbeelding, de twee knoppen van de Oproep met knop, een kaart van het
  Hover-kaarten grid) bewaart `link_type` + `link_target_id` naast de getypte
  URL: *Geen knop*, een pagina, blogbericht, product, collectie of
  portfolioproject van de site (`App\Service\Routing\LinkTargets`), of een
  eigen adres. Hoe de redacteur dat kiest staat hieronder, in "Waar een knop
  heen gaat".
  `App\Service\Routing\LinkChoice` controleert wat er gepost is en maakt er
  per render een adres van in de taal van het verzoek; een intern doel is een
  id, dus een nieuwe slug of taal volgt vanzelf. De editor gebruikt
  `admin/_link_target_field.php`; met meer dan één knop op een formulier zit
  elke knop in een eigen `[data-nav-link-group]`. Een rij van vóór het type
  heeft een adres en geen type, en is een adres (`LinkChoice::storedType()`).
  Daarom schrijft *Geen knop* ook geen adres weg: een achtergebleven adres
  zonder type zou weer een knop worden. Elk endpoint met zo'n knop doet dat
  (Tekstblok, carrouselkaart, Homepage-hero, Tekst met afbeelding, Oproep met
  knop), en bij
  *Geen knop* controleert het verder niets: het label en het adres zijn dan
  verborgen maar worden nog meegestuurd, en een oude waarde daarin houdt de
  opslag niet tegen. Rijen die vóór die regel met *Geen knop* en een adres
  zijn opgeslagen, zijn niet van een oude rij te onderscheiden en blijven
  staan; er is geen datamigratie. Een lijst van rijen met elk een knop (de items van
  Tekst met afbeelding) is hetzelfde veld, met één `[data-nav-link-group]` per
  rij. `admin/assets/navigation-item.js` luistert op het formulier, dus een rij
  die later op het scherm komt doet ook mee. Bouw er geen kopie van per blok.
- De editor staat op `admin/_localized_fields.php`: één taal op het scherm,
  verplicht alleen in de standaardtaal. Het endpoint controleert
  `language_code` tegen `SiteLanguages::isActive()`, valideert met
  `BlockLocalization::problems()` en schrijft instellingen en
  `BlockLocalization::save()` in één transactie.
- **Een repeater-item** heeft eigen endpoints. `create-` schrijft de rij en
  zijn woorden in de standaardtaal (`admin_localized_new_item_note()` zegt dat
  op het scherm); `update-` slaat één taal op onder hetzelfde `id`, zodat de
  andere talen blijven staan; `delete-` roept
  `BlockLocalization::deleteOwner()` aan vóór de rij weggaat, in dezelfde
  transactie. Nooit wissen en opnieuw aanmaken.
- Een heel blok verwijderen hoef je niet te regelen: `SectionRegistry::delete()`
  neemt de woorden van het blok en van al zijn kindrijen mee.

Er wordt niets server-side vertaald.

### Waar een knop heen gaat

De bestemmingskiezer 2.0 (Pages & Destinations 3.0,
`admin/_link_target_field.php`). Eén veld voor elke blokknop, zodat ze niet
uit elkaar kunnen groeien; bouw er geen kopie van per blok.

- **Eerst de soort, dan alleen de kiezer van die soort.** *Geen knop*, *Een
  pagina*, dan wat de modules bijdragen die aan staan (*Een blogbericht*, *Een
  product*, *Een collectie*, *Een portfolioproject*; `MODULES.md`,
  "Bestemmingen van een module"), en *Een eigen adres*. Een
  portfoliocategorie heeft geen eigen adres en is dus geen bestemming.
- **Een pagina** kies je uit de boom van het paginaoverzicht, ingesprongen,
  een concept gemarkeerd (`App\Service\PageOptions`, `docs/pages/NESTING.md`
  §8).
- **Een product, collectie, project of blogbericht** kies je uit een
  doorzoekbare lijst met afbeelding en status
  (`admin/assets/destination-picker.js`: een zoekveld en resultaten als
  knoppen, met het toetsenbord te bedienen, de keuze voorgelezen; `ADMIN-UI.md`).
  Het script bouwt die uit de eigen `<select>` van het veld; die blijft wat er
  gepost wordt en is zonder script de hele kiezer.
- **Een eigen adres** gaat door `App\Service\Routing\SafeUrl`
  (`docs/multilingual/ROUTING.md` §11): geen `javascript:` of ander schema,
  geen stuurtekens, witruimte eromheen weg.

**Opslag: een uitbreiding, geen nieuwe vorm.** Dezelfde drie velden als
voorheen (`link_type`, `link_target_id`, de getypte URL; `LinkChoice`). Een
nieuwe soort is alleen een nieuwe waarde van `link_type`; bestaande rijen
blijven precies zoals ze zijn en er is geen datamigratie.

**Bij elke weergave opnieuw opgezocht, op id**, in de taal van het verzoek.
Wat de bezoeker nu niet kan openen (een concept, een inactief product, een
verborgen project) rendert geen knop, en die komt vanzelf terug. In de
editor:

| Opgeslagen bestemming | De editor zegt | Op de website |
|---|---|---|
| nog niet te openen | de keuze met *(concept)*, *(niet actief)* of *(niet op de website)* erbij | geen knop tot het wel kan |
| verwijderd, of niet meer te kiezen | *Niet meer beschikbaar (#id, bewaard)* en een waarschuwing eronder | geen knop, nooit een kapotte link |
| van een module die uit staat | *Hoort bij Shop, dat uit staat (bewaard)* en waarom | geen knop tot de module weer aan staat |

Openen en opslaan gooit niets weg: `LinkChoice` houdt een opgeslagen keuze die
de kiezer nu niet kan tonen vast, en een nieuwe, vervalste of onbekende keuze
wordt geweigerd.

**Getypte adresvelden buiten de kiezer** (het eigen adres van de
Contactkaart, de Detailsectie en de Galerij) volgen dezelfde regel via
`SafeUrl::optionalFieldMessage()`: leeg mag, onveilig wordt geweigerd.

**Nog niet omgezet** (bewust later): links in rich text, de bestemmingen van
menu en footer (die kennen pagina, vast onderdeel en adres, met de
paginalijst en de tekstregel van `HEADER-FOOTER.md`), redirects, social
profielen, en de automatische links van Uitgelicht product, Projecten en
Collectietegels, die hun bestemming uit het gekozen object halen en niet uit
een knop.

**Een bovenlabel (eyebrow) is altijd optioneel.** Geen blok declareert het als
`->required()`, geen editor zet er `required` op (de placeholder is
`admin_localized_optional_attr()`), en een partial print het nooit zelf maar
via `render_eyebrow()` uit `partials/eyebrow.php`: leeg is geen element en dus
ook geen lijntje of marge. `Tests\Service\OptionalEyebrowTest` controleert dat
voor elk geregistreerd blok met een bovenlabel.

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
  instanties op de pagina**, nooit over een vaste lijst. Een nummer wordt
  nooit opgeslagen (`App\Service\Blocks\LabelMode`): de Detailsectie telt
  zijn eigen actieve instanties, een kaart van de Kaarten-carrousel de kaarten
  die zijn carrousel toont.

## Blokken op een product of project

Product & Portfolio Content Pages 1.0. Een Shop-product en een
Portfolio-project dragen dezelfde contentblokken als een pagina, via **dezelfde
blok-engine**: dezelfde blokkenkiezer, dezelfde blok-editors en endpoints,
dezelfde volgorde, dezelfde woorden per taal, dezelfde bestemmingskiezer,
Responsive Media en mediagebruik. Er is geen tweede engine en geen gekopieerde
editor.

**Het model: een inhoudspagina.** De engine adresseert een bloklijst al met één
ondoorzichtige sleutel (`page_slug` = `pages.content_key`) en bewaart de lijst
in `page_sections`, die bij `pages` hoort. Een eigenaar die geen pagina is,
krijgt daarom een eigen `pages`-rij om zijn blokken te houden
(`App\Service\ContentOwners\ContentPages`):

| Onderdeel | Wat |
|---|---|
| `pages.owner_type` | NULL voor elke gewone pagina; `product` of `portfolio_project` voor een inhoudspagina |
| `product_content_pages`, `portfolio_content_pages` | eigenaar ↔ inhoudspagina, één op één, echte foreign keys aan beide kanten, `RESTRICT` |
| sleutel | `<kind>_<id>` (`product_12`, `portfolio_project_3`); een paginasleutel is een slug (a-z, 0-9, `-`), dus de underscore houdt ze voor altijd uit elkaar |
| eigenaars | een gesloten lijst uit `ModuleDefinition::contentOwners()` (`App\Service\ContentOwners\ContentOwners`); Core noemt geen product of project |

- **Pas bij het eerste blok.** Een product zonder blokken heeft geen
  inhoudspagina. De kiezer op het tabblad *Pagina-inhoud* post `content_owner`
  (een soort, nooit een klasse) en `content_owner_id`;
  `api/admin/add-page-section.php` controleert het blok tegen een stand-in
  (`ContentPages::placeholder()`) en maakt de pagina pas daarna.
- **Nooit een pagina.** Elke lijst van pagina's en elke publieke lookup laat
  een inhoudspagina weg (`PageRepository`: paginaboom, menu, sitemap, zoeken,
  bestemmingskiezer, adres); ze heeft geen slug, geen eigen tekst en staat op
  concept. `update-page.php`, `delete-page.php` en `page-preview.php`
  behandelen haar als geen pagina. Opzoeken op id en op content_key vindt haar
  wel: zo werken de blok-editors ongewijzigd.
- **Terug naar de eigenaar.** Elke blok-editor linkt terug naar de lijst van
  zijn blok (`ContentBlockAccess::listUrl()`, ook achter
  `PageContent::builderUrl()`): voor een inhoudspagina de editor van de
  eigenaar, tabblad *Pagina-inhoud* (`?tab=inhoud`), anders
  `admin/page.php?id=<id>`. Ook toevoegen, verbergen en verwijderen landen
  daar (met `added`/`deleted`), zodat de weg terug nooit langs een scherm gaat
  dat `pages.manage` vraagt. `admin/page.php` stuurt een inhoudspagina nog
  steeds door. `PageLocalization::name()` noemt haar naar de eigenaar
  ("Product: Eiken plank"), en zo ook het mediagebruik.
- **Eén bloklijst.** `admin/_content_blocks.php` is de lijst die
  `admin/page.php` toont en die het tabblad *Pagina-inhoud* van een product en
  een project toont (`content_blocks_list()`, `content_blocks_owner_panel()`).
  De uitvoer van `admin/page.php` is daardoor niet veranderd.
- **Verwijderen.** De eigenaar verwijderen verwijdert eerst zijn blokken via
  `SectionRegistry::delete()` (woorden, kindrijen, bestanden) en dan de
  koppeling en de pagina (`ContentPages::deleteFor()`, aangeroepen door
  `ProductDeletionService` en `delete-portfolio-item.php`). De `RESTRICT`
  weigert de andere volgorde. Een bibliotheekafbeelding blijft staan.
- **Waar het blok mag staan: `owners`.** Een optionele sleutel in `meta()`:
  de soorten bloklijst (`ContentOwners::PAGE`, `product`, `portfolio_project`)
  waar het blok aan toegevoegd mag worden. Zonder die sleutel overal. Vandaag:

| Blok | Op een product | Op een project | Waarom |
|---|---|---|---|
| alle gewone blokken (tekst, tekst met afbeelding, detailsectie, kaarten, galerij, mediabanner, FAQ, CTA, formulieren, witruimte, Uitgelicht product, Projecten, ...) | ja | ja | gewone inhoud, niets hangt aan de pagina |
| Paginakop (`page_hero`) | nee | nee | de kop van een gewone pagina met haar titel; een product en een project hebben hun eigen kop |
| Homepage Hero, Diensten-snelmenu | nee | nee | al beperkt tot hun eigen pagina (`allowed_pages`) |
| Projectinformatie (`project_info`) | nee | ja | toont het project waarop het staat |

### Wie mag welke blokken beheren

De inhoudspagina is een technische houder en bepaalt **nooit** het recht. Het
type eigenaar doet dat (`App\Service\ContentOwners\ContentBlockAccess`,
`ContentOwner::permission()`), met de rechten die de modules al hadden:

| Bloklijst van | Recht | Niet genoeg |
|---|---|---|
| een gewone pagina (`owner_type` NULL) | `pages.manage` | `products.manage`, `portfolio.manage` |
| een product | `products.manage` (Shop) | `pages.manage` |
| een Portfolio-project | `portfolio.manage` | `pages.manage`, `products.manage` |

Een Shop-beheerder ziet dus het tabblad *Pagina-inhoud* van een product,
voegt blokken toe, bewerkt, verbergt, sorteert, vertaalt en verwijdert ze,
kiest er afbeeldingen voor, en ziet de voorbeelden in de blokkenkiezer
(`admin/block-preview.php`) — zonder toegang tot één CMS-pagina.

**Twee stappen, in elk gedeeld blokscherm en -endpoint.** Waar een ander
scherm zijn ene letterlijke recht vraagt, vraagt een blokbestand
`ContentBlockAccess::requireAny()` (scherm) of `requireAnyForApi()`
(endpoint): minstens één blokrecht, na de login en vóór de POST- en
CSRF-check. Zodra de lijst bekend is — `pageForKey()` /
`pageForKeyForApi()` voor een `<slug>:<key>`, `requirePage()` /
`requirePageForApi()` voor een bloklijst die via een `page_sections`-id, een
pagina-id of een kaart-id gevonden is — volgt het recht van die lijst, uit
haar eigen `pages`-rij, vóór er iets gelezen of geschreven wordt. Een
nagemaakte sleutel, pagina-id, blok-id of kaart-id bereikt dus alleen een lijst
die de afzender toch al mocht beheren. Een vast blok van een eigen pagina
(`FaqContent::SECTIONS` en dergelijke) vraagt `pages.manage`
(`requirePages()`). `Tests\Service\AdminAccessControlTest` houdt de
eigenaarsbewuste bestanden als gesloten lijst bij en eist dat elk de tweede
stap zet; `ContentBlockOwnerAccessHttpTest` test het gedrag.

**Niet gewijzigd:** de Paginakop en de Homepage Hero (alleen op gewone
pagina's, dus letterlijk `pages.manage`), de paginabouwer `admin/page.php`
zelf, de Contentblokken-bibliotheek en `translate-fields.php`.

**Recht is geen beschikbaarheid.** Het recht zegt wie een lijst mag beheren;
`owners` in de blokmeta zegt welke blokken erin mogen. Een Shop-beheerder kan
nog steeds geen Paginakop of Projectinformatie op een product zetten.

**Open:** de links in het mediagebruik (`ContentBlockMediaUsage`) naar een
blok-editor vragen nog `pages.manage` om getoond te worden, ook voor een blok
op een product.

**Wat een blok niet doet** op een product of project: de titel, canonical,
structured data of deelafbeelding veranderen. Die blijven van `ProductSeo` en
`PortfolioSeo`. Zoeken (Search 1.0) doorzoekt de blokken nog niet.

**Herbruikbare blokken** zijn in deze codebase bloktypes die op elke pagina
terug kunnen komen, geen gedeelde bibliotheek met verwijzingen; een blok op een
product is een eigen instantie en kan dus niet van betekenis veranderen.

## Detailsectie 2.0

- **Anker.** Eén vorm (`App\Service\Blocks\AnchorName`): kleine letters,
  cijfers, `-` en `_`; `#Hout ` wordt `hout`, ook voor een anker dat vóór deze
  regel is opgeslagen. Wat niets overlaat en een anker dat een andere
  Detailsectie op dezelfde pagina al heeft, weigert het endpoint bij het veld.
  Een sectie met een anker staat vanzelf in de **ankernavigatie** van haar
  pagina, direct onder de paginakop, met haar navigatielabel (anders de titel)
  in de taal van het verzoek. Die navigatie is de bestaande Snelnavigatie, die
  `SectionRegistry::renderPage()` nu op elke pagina zelf afdrukt
  (`docs/content-blocks/DECISIONS.md`, "Anker en navigatielabel horen bij de
  sectie").
- **Beeldpositie** hoort bij de hoofdafbeelding: het veld staat in de kaart
  *Hoofdafbeelding* en is alleen zichtbaar met een afbeelding
  (`admin/assets/detail-section.js`). Het wordt altijd gepost, dus een
  opgeslagen positie gaat nooit verloren; zonder afbeelding negeert de
  website haar, zoals altijd. Geen Responsive Media: de hoofdafbeelding wordt
  in haar eigen verhouding getoond, zonder kader om bij te snijden. De help
  zegt het: *Beeldpositie* is de plek van de afbeelding in de sectie, geen
  focuspunt.
- **Nummer / label** (v0.1.13, `detail_sections.label_mode`,
  `App\Service\Blocks\LabelMode`): *Geen label*, *Nummering 01, 02, 03…*,
  *Nummering 1, 2, 3…* of *Eigen tekst* (het vertaalde veld `label`, per
  taal). Het nummer is de plek van de sectie onder de **actieve Detailsecties**
  van de pagina, in de volgorde van de paginabouwer — andere blokken tellen
  niet mee, een verborgen sectie is geen plek — en wordt bij elke weergave
  uitgerekend, nooit opgeslagen (`DetailSectionContent::positionMarkers()`).
  Elke bestaande en elke nieuwe sectie begint op `padded`, dus wat er stond
  blijft staan. *Geen label* print niets en houdt geen ruimte vrij. Het label
  is iets anders dan de ankernavigatie: die blijft navigatielabel, anders de
  titel. Het veld is de gedeelde keuze `admin/_label_mode_field.php`, dezelfde
  als op een kaart van de Kaarten-carrousel.
- **Focuspunt per galerij-item** (v0.1.13): een galerij-item staat in een
  vierkant (`object-fit: cover`), dus welk deel zichtbaar is, is een keuze van
  dat item. Het is een plek van Responsive Media (`MEDIA.md`,
  `DetailSectionContent::imageSlot()`: alleen een focuspunt, geen weergave,
  geen telefoonhoogte, geen telefoonafbeelding) met de gedeelde editor per
  rij, zonder telefoondeel: de strook op een telefoon toont hetzelfde vierkant
  met hetzelfde punt. Opgeslagen op de galerijrij (`image_focus_x/y`),
  nooit op het bibliotheekitem of op het product, project of bericht: krijgt
  dat item later een andere foto, dan staat die meteen in beeld met het punt
  van hier. Het kader in de editor volgt de bron van de rij (een
  `rm:picture`-event uit `admin/assets/detail-section.js`); een product of
  bericht zonder miniatuur in de keuzelijst toont zijn foto pas na
  *Opslaan*.
- **Galerijbronnen.** Een galerij-item is een afbeelding uit de
  Mediabibliotheek, of een product, portfolioproject of blogbericht dat zijn
  eigen afbeelding en naam toont en naar zijn pagina linkt
  (`admin/_gallery_source_field.php`, `App\Service\Media\LinkedImages`).
  Opgeslagen: alleen `source_type` + `source_id` op `detail_section_images`
  (`db/migrations/20260930120000`); afbeelding, naam en adres worden bij elke
  weergave opgezocht, dus een nieuwe slug of foto volgt meteen. Wat een
  bezoeker niet kan openen (concept, inactief, verborgen, verwijderd, module
  uit) laat de website weg; de editor houdt de keuze vast en zegt waarom. De
  soorten komen uit de bestemmingskiezer (`LinkTargets`) plus een afbeelding
  per module (`ModuleDefinition::linkedImages()`): een toekomstige module als
  Articles wordt een bron zonder dat de Detailsectie verandert. De alt-tekst
  is die van het bibliotheekitem achter de afbeelding van dat item.
- **Op een telefoon** (640 px en smaller) is de galerij een strook met één
  item tegelijk, native scroll-snap (vegen is de scroll van de browser, dus
  een tik op een gelinkt item blijft een tik), met twee pijlknoppen die aan
  de uiteinden uitgeschakeld zijn, zoals de platte strook van de
  Kaarten-carrousel, en de pijltjestoetsen op de strook
  (`assets/css/blocks/detail-section.css`, `assets/js/blocks/detail-section.js`).
  Zonder beweging voor wie minder beweging vraagt. Daarboven het raster van
  altijd.
- **Uitleg** staat in de help-knop van het veld en in de infobalk van een
  kaart, niet meer als alinea onder elk veld.

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
   overneemt, migreer die dan mee in dezelfde migratie. **Woorden krijgen geen
   kolom**: die declareer je in `translatableFields()` (stap 7) en ze staan in
   `block_translations`.
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
   `htmlspecialchars()`. **Een partial rendert alleen:** wat hij toont komt
   binnen als argument. Moet er iets opgezocht worden (een formulier, een
   instelling, een afgeleide lijst), dan doet de `render()` van de definitie
   dat en geeft het door. Alleen zo kan de Contentblokken-bibliotheek dezelfde
   partial met voorbeeldinhoud aanroepen (`partials/section-form.php` is het
   voorbeeld).
5. **Admin-editor** — `admin/<type>.php`, leest `?section=<slug>:<key>`.
6. **Endpoint(s)** — `api/admin/update-<type>.php`, in deze volgorde:
   `AdminAuth::requireLoginForApi()`, `ContentBlockAccess::requireAnyForApi()`,
   POST-check, `Csrf::validate()`, `section` splitsen en valideren (pagina via
   `ContentBlockAccess::pageForKeyForApi()`, dat ook het recht van die
   bloklijst controleert, én inhoudsrij moeten bestaan), repository, cache
   legen, PRG-redirect met session-flash. De editor doet hetzelfde met
   `requireAny()` en `pageForKey()`; zet beide bestanden in de lijsten van
   `AdminAccessControlTest` (*Wie mag welke blokken beheren*).
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
   | `create()` | Maakt een lege inhoudsrij en geeft `[section_id, section_key]` — een herhaalbaar blok haalt zijn key bij `self::newSectionKey()`; startwoorden schrijft het met `BlockLocalization::save()` in de standaardtaal |
   | `deleteContent()` | Ruimt de inhoudsrij op; draait in de transactie van de registry, dus zelf niet committen. De woorden in `block_translations` ruimt de registry in diezelfde transactie zelf op |
   | `render()` | Roept `forSection()` aan, vangt `STATE_HIDDEN` af en roept de partial aan |
   | `editUrl()` | Meestal `$this->sectionEditUrl('<admin-bestand>', $pageSection)` |
   | `clearCache()` | `<Type>Content::clearCache()` |
   | `contentTable()` | De tabelnaam — vertrouwde metadata waarmee tests hun eigen rijen opruimen |
   | `translatableFields()` | De woorden per websitetaal, per eigen tabel, met `TranslatableField::plain()` of `::rich()` — zie *Taal* hierboven. Een blok zonder eigen rijen (`FixedBlockDefinition`) declareert `[]` |
   | `styles()` / `scripts()` / `vendorScripts()` | De eigen frontend van dit blok; standaard leeg — zie stap 8 |

   Optioneel, met een veilige standaard: `childTables()` (de kindtabellen
   waarvan de rijen eigen woorden hebben, zie *Taal* hierboven),
   `preview()` (de vormen waaruit de
   schets op de blokkaart wordt getekend, uit de gesloten lijst in
   `BlockPreview`) en `useCases()` (twee tot vier voorbeeldsituaties: de
   catalogus toont ze, de blokkenkiezer zoekt erop),
   `sampleContent()` en `renderSample()` (het voorbeeld in de
   Contentblokken-bibliotheek: inhoud in de vorm die `render()` aan je partial
   geeft, gemaakt van de woorden in `App\Service\Blocks\BlockSamples`, en
   dezelfde partial-aanroep ermee — zie [`PAGE-EDITOR.md`](PAGE-EDITOR.md);
   `BlockSampleContractTest` faalt zolang een blok er geen heeft en ook niet
   als uitzondering genoemd is),
   `deleteFiles()` (alleen als het blok
   nog *eigen* uploads heeft — vóór de transactie, gescoopt op déze
   instantie; een blok dat de Mediabibliotheek gebruikt laat hem leeg, want
   een gedeeld bestand is niet van dat blok),
   `instanceTitle()` (waaraan de beheerder twee instanties uit elkaar houdt) en
   `tightensFollowingBlock()` (alleen de hero's).

   Eén optionele interface staat daarnaast: `CarriesBreadcrumb`, voor een
   blok dat als eerste op de pagina het kruimelpad van die pagina in zich
   opneemt. Alleen de Paginakop met een beeld doet dat (`HEADER-FOOTER.md`,
   *De plek op de pagina*); een nieuw blok heeft hem niet nodig.

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

   **Meer dan één kaart in de kiezer, zonder tweede bloktype.** Moet één
   blok onder twee namen of categorieën te kiezen zijn, dan implementeert het
   `App\Service\Blocks\OffersPickerPresets`: `pickerPresets()` geeft een
   gesloten lijst kaarten, en `createFromPreset()` is `create()` met één
   instelling gekozen. De galerij doet dit (*Collectiegalerij* onder Shop,
   *Portfoliogalerij* onder Portfolio, `PAGE-EDITOR.md`). Maak hiervoor nooit
   een tweede bloktype met dezelfde tabel, editor of partial.
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
  (positie van de tekst, titel- en tekstgrootte, plaats van het beeld en
  hoogte van de band). Het focuspunt is sinds Responsive Media 2.0 geen woord
  meer maar een punt: twee hele procenten, geklemd, en de enige inline waarde
  (`MEDIA.md`, *Responsive Media*).
- **Bestaande inhoud blijft behouden** bij migraties en refactors.

## Koppen in kaarten

Een blok met kaarten heeft vaak een eigen, optionele titel. Die titel is een
`<h2>`, zoals elke bloktitel, en de titel van een kaart hangt eronder:

- **met een zichtbare bloktitel** is elke kaarttitel een `<h3>`;
- **zonder bloktitel** is elke kaarttitel een `<h2>`, zodat een pagina-`<h1>`
  nooit gevolgd wordt door een `<h3>` zonder `<h2>` ertussen.

Dat beslist één klasse, `App\Service\Blocks\CardHeading`: `under(bool)` geeft
`h3` of `h2`, uit een gesloten lijst van die twee (`LEVELS`). Een partial geeft
hem dezelfde voorwaarde mee waarmee hij de `<h2>` van het blok print, dus die
twee kunnen niet uit elkaar lopen, en een tag komt nooit uit inhoud of uit een
request. Kaarten die de browser tekent (de productkaarten van `shop.js`) krijgen
het niveau van hun raster in `data-card-heading`, en het script accepteert
alleen `h2` en `h3`.

**Alleen de tag verandert, niet het uiterlijk.** Een kaarttitel heeft een eigen
klasse die zijn maat draagt (`.feature-card__title`, `.process-step__title`,
`.orbit-card__title`, `.hover-card__title`, `.collection-tile__name`,
`.contact-card__title`, `.product-card__title`), dus een `<h2>` en een `<h3>` in
dezelfde kaart zien er hetzelfde uit. Stijl een kaarttitel daarom nooit op het
element (`.feature-card h3`). Tekst met afbeelding is de uitzondering die er al
was: een item is geen kaart, en onder een bloktitel neemt het de h3-stap van de
typeschaal.

Er komt geen verborgen of lege `<h2>` bij om een `<h3>` te rechtvaardigen, en een
kaart zonder titel heeft geen kop. Een bloktitel die leeg is, drukt ook geen lege
`<h2>` af (Oproep met knop, Detailsectie en Contactformulier controleren dat
sinds deze regel, zoals Formulier en de galerij al deden).

| Blok | Eigen titel | Kaarttitel |
|---|---|---|
| Kenmerken in kaartjes, Stappenplan, Kaarten-carrousel, Hover kaarten grid | optioneel | `h3` onder de titel, anders `h2` |
| Tekst met afbeelding | optioneel | idem, voor de titel van een item |
| Collectie-tegels, Contactkaart, Productgrid | geen | `h2` |
| Gerelateerde producten | optionele kop | `h3` onder de kop, anders `h2` |
| Collectie- en personaliseerpagina (productkaarten onder de `<h1>`) | — | `h2` |
| Projecten, Portfoliogalerij, gerelateerde projecten | optioneel | geen kop: de titel op een kaart is een onderschrift (`<p>`) |

`Tests\Service\CardHeadingContractTest` rendert het voorbeeld van élk blok met en
zonder zijn titel en faalt op een overgeslagen niveau, een lege kop of een
partial die een kaarttag zelf schrijft; `CardHeadingPageTest` bewijst het op een
echte pagina en in twee talen. Een nieuw blok met kaarten gebruikt `CardHeading`
en een klasse voor de titel.

## Kaarten-carrousel: het label van een kaart

Sinds v0.1.13 kiest elke kaart zijn *Labelweergave*
(`carousel_cards.label_mode`, `App\Service\Blocks\LabelMode`), in de gedeelde
keuze `admin/_label_mode_field.php`:

| Keuze | Wat de kaart boven de titel toont |
|---|---|
| Geen label (`none`) | niets, en geen ruimte |
| Nummering 01, 02, 03… (`padded`) | de plek van de kaart onder de kaarten die de carrousel toont, minstens twee cijfers (10 blijft 10) |
| Nummering 1, 2, 3… (`plain`) | hetzelfde zonder voorloopnul |
| Icoon (`icon`) | een SVG uit de Mediabibliotheek (`label_icon_media_id`, `MediaType::ICON`), als decoratie: lege alt en `aria-hidden`, want de titel draagt de betekenis |
| Eigen tekst (`custom`) | het vertaalde veld `number_label`, per taal |

Een nummer wordt bij elke weergave uitgerekend: verplaats of verberg een kaart
en de nummers volgen. Een andere keuze dan *Icoon* laat het icoon los, zodat
het niet meer als gebruikt telt; de eigen woorden blijven bewaard. Het icoon
staat in `ContentBlockMediaUsage` en achter een `RESTRICT`-sleutel, dus een
gebruikt icoon kan niet uit de bibliotheek. Migratie `20260930140000` maakte
elke kaart met woorden (in welke taal ook) `custom`, met de woorden
ongewijzigd, en elke andere `none`: een opgeslagen "01" blijft tekst, want die
kan bewust getypt zijn. Een nieuwe kaart begint op *Geen label*.

## Tekst met afbeelding: een lijst items

Sinds Tekst met afbeelding 2.0 (`db/migrations/20260924100000`) is één
instantie van `text_image_split` een lijst **items** in
`text_image_split_items`, geen losse blokken. Een item is een tekst naast
hoogstens één afbeelding. Items van één blok staan dichter op elkaar
(`var(--sp-6)`, smal `var(--sp-5)`) dan twee blokken (`var(--sp-7)` boven en
onder elke sectie). Dat is de reden om meerdere items in één blok te zetten.

| Per item | Waar | Waarden |
|---|---|---|
| bovenschrift, titel, tekst (rich), knoptekst, alt-tekst | `block_translations`, eigenaar het item | per websitetaal |
| afbeelding | `media_id` (+ oud `image_path`) | een item uit de mediabibliotheek, optioneel |
| kant van de afbeelding | `image_side` | `left`, `right` |
| breedte van de afbeelding | `image_column` | `25`, `50`, `75`: het deel van de rij in procenten, de tekst krijgt de rest |
| hoogte van de afbeelding | `image_height` | `small`, `medium`, `large`: tokens in `assets/css/blocks/text-image-split.css` |
| weergave van de afbeelding | `image_focus_x`, `image_focus_y`, `image_fit` en de telefoonkolommen `image_mobile_*` | het veld *Afbeeldingsweergave* van elke plek die bijsnijdt (`MEDIA.md`, *Responsive Media*): een focuspunt, *Vullen* of *Hele afbeelding*, en voor een telefoon een eigen afbeelding, punt, weergave en hoogte (*Compact*, *Normaal*, *Groot*) |
| knopdoel | `button_link_type`, `button_link_target_id`, `button_url` | *Geen knop*, een pagina, blogbericht of product op id, of een getypt adres (`LinkChoice`, zie *Taal* hierboven) |

De drie lay-outkeuzes zijn gesloten lijsten in `TextImageSplitContent::layout()`:
een onbekende waarde wordt de standaard. De partial zet er alleen klassen van
neer. De enige inline waarden zijn die van de afbeeldingsweergave
(`object-position`, en `object-fit` bij de hele afbeelding), geprint door
`partials/responsive-image.php`.

- **Een item heeft tekst of een afbeelding nodig.** Tekst is een
  bovenschrift, een titel, een tekst of een hele knop (label en adres). De
  standaardtaal beslist, zoals overal. Een helemaal leeg item weigert de
  editor, met de melding bij het item. Een item met alleen een lay-out toont
  niets.
- **Mobiel (≤ 860px) staat de tekst altijd boven de afbeelding**, allebei
  over de volle breedte. Dat is de regel van de Detailsectie. De breedte en de
  kant gelden dan niet, en de hoogtes worden vaste, lagere waarden. Een
  gemigreerd blok met de afbeelding links toonde op een telefoon eerst de
  afbeelding; nu komt eerst de tekst. Op een telefoon (≤ 640px, het ene
  breekpunt van Responsive Media) kan een item een eigen hoogte kiezen
  (`image_mobile_height`); zonder keuze blijft het die vaste waarde.
- **Een item met maar één helft gaat over de volle breedte.** Alleen tekst:
  de tekst is 100% breed. Alleen een afbeelding: de afbeelding vult het hele
  item, met zijn eigen hoogte en focuspunt. De breedte (`image_column`) en de
  kant blijven opgeslagen en gelden weer zodra de andere helft erbij komt; de
  weergave negeert ze zolang er geen tweede kolom is. Zo geven de losse
  afbeeldingen uit een oude galerij (zie de migratie hieronder) geen lege
  tekstkolom.
- **Een item zonder titel** begint met de grotere lead-alinea, zoals de
  eerste alinea van een blok zonder titel altijd deed.
- **Een kop boven de items.** Het blok zelf heeft een optionele titel en
  introtekst: woorden met `text_image_splits` als eigenaar in
  `block_translations` (255 en 500 tekens), dus zonder migratie. Is er een van
  de twee, dan staat boven het eerste item de gedeelde `.section-head` van
  `core.css` met een `<h2>` en de lead. Onder een bloktitel worden de titels
  van de items `<h3>`, met de h3-stap van de typeschaal, zodat de opbouw van
  de pagina klopt. Zonder bloktitel blijven ze de `<h2>` die ze altijd waren
  (*Koppen in kaarten*).
  De standaardtaal beslist of de kop er is. Een blok zonder items rendert
  niets, ook zijn kop niet. Het endpoint slaat de kop alleen op als het
  formulier de velden meestuurt, zodat een ouder scherm hem nooit wist. In de
  paginabouwer heet het blok naar zijn titel (`instanceTitle()`), anders naar
  zijn eerste item. De velden staan in de kaart *Blok* van de editor.
- **De knop** is het gedeelde linkveld (`admin/_link_target_field.php`). Een
  item van vóór het type heeft alleen `button_url`, en die blijft een adres
  zonder migratie. Kies je een doel, dan moet de knop een tekst hebben in de
  standaardtaal. *Geen knop* wist ook het adres. Een verzoek zonder type (een
  ouder scherm) betekent wat het altijd betekende: een knop als tekst én adres
  er staan, anders geen knop en geen melding.
- **In de editor klapt elk item apart in** (`editor_row_open()` met
  `$collapse`, `PAGE-EDITOR.md`). De kopregel is "Item 2 — Over ons": het
  nummer en de titel in de taal op het scherm. Een los item, een nieuw item en
  een item met een melding staan open. De rest van een langere lijst begint
  dicht en onthoudt per browsertabblad hoe de redacteur hem liet. Inklappen
  verandert niets aan wat er opgeslagen wordt.
- **De migratie** maakt van elk bestaand blok zijn eerste item. De alinea's
  worden `<p>`'s in de rich text; de eerste afbeelding, 50/50, focus midden
  en hoogte `large` (het dichtst bij het oude 4:5-kader). Elke volgende
  afbeelding (de oude mini-galerij) wordt een eigen item met alleen die
  afbeelding. De woorden en rijen verhuizen, er blijft geen kopie achter. De
  oude tabellen `text_image_split_paragraphs` en `text_image_split_images` en
  de kolommen `layout` en `button_url` van het blok blijven leeg of ongelezen
  staan (forward-only). Ze staan nog in `childTables()` omdat ze van het blok
  cascaden.

## Kenmerken in kaartjes: de kop is optioneel

De titel (H2) van *Kenmerken in kaartjes* (`feature_grid`) is niet meer
verplicht (`FeatureGridBlock::translatableFields()` zonder `->required()`).
Een raster zonder bovenlabel, titel en lead stond al zonder kop op de pagina
(het oude raster van de homepage), dus de partial hoefde niet te veranderen.
Wat de redacteur wel invult, verschijnt: alleen een lead of alleen een
bovenlabel geeft een kop zonder `<h2>`. Onder een titel zijn de kaarttitels
`<h3>`, zonder titel `<h2>`, in dezelfde klasse en dus met hetzelfde uiterlijk,
zoals bij de Kaarten-carrousel en de Hover kaarten grid (*Koppen in kaarten*).

In de editor klapt elke kaart apart in, precies zoals de items van Tekst met
afbeelding (`editor_row_open()` met `$collapse`, `PAGE-EDITOR.md`): de kopregel
is "Kaart 2 — Precisie", en de titel volgt het veld terwijl je typt.

## Weergavekeuzes van de eenvoudige blokken

Content Blocks Polish 1 (`db/migrations/20260926100000` en `20260926110000`).
Elke keuze is een woord uit een gesloten lijst in de inhoudsklasse, met als
standaard hoe het blok er al uitzag. Die standaard krijgt geen class. Een
onbekende opgeslagen waarde leest als de standaard. Het endpoint weigert een
onbekende waarde, en een verzoek zonder het veld houdt wat er staat. Geen
van de keuzes hangt aan een taal.

| Blok | Kolom | Waarden (standaard eerst) | Wat het doet |
|---|---|---|---|
| Tekstblok | `rich_text_sections.content_width` | `medium`, `large` (`RichTextContent::WIDTHS`) | `medium` is de smalle leeskolom (`.container--narrow`, `--container-narrow`). `large` is de gewone contentbreedte van de site (`.container`, `--container`), dezelfde als de andere brede blokken |
| Formulier | `form_blocks.header_align` | `left`, `center`, `right` (`FormBlockContent::HEADER_ALIGNMENTS`) | Alleen de kop en de inleiding boven het formulier. Labels en velden blijven zoals ze zijn |
| Kaarten-carrousel | `card_carousels.header_align` | `left`, `center`, `right` (`CardCarouselContent::HEADER_ALIGNMENTS`) | Bovenlabel, titel en lead boven de carrousel. De kaarten niet |
| Kaarten-carrousel | `card_carousels.image_height` | `medium`, `small`, `large` (`CardCarouselContent::IMAGE_HEIGHTS`) | Eén beeldhoogte voor alle kaarten van de carrousel. De kaart groeit of krimpt precies zoveel als het beeld, dus de tekst houdt zijn ruimte. Het beeld wordt bijgesneden (`object-fit: cover`, of heel getoond als de kaart *Hele afbeelding* kiest), nooit uitgerekt. Op een telefoon en bij *naast elkaar* is de kaarthoogte `auto` |
| Kaarten-carrousel | `card_carousels.flat_image_ratio` | `auto`, `1-1`, `4-3`, `3-4`, `16-9` (`ResponsiveImage::FLAT_RATIOS`) | *Beeldverhouding op een rij*: waar de kaarten naast elkaar staan (altijd op een telefoon, op grotere schermen bij *Kaarten naast elkaar*) krijgt elk beeld die vorm in plaats van de vaste hoogte; *Zoals de hoogte* (`auto`) is de standaard en geeft geen class. De draaiende carrousel houdt altijd de hoogte, want zijn podium wordt uit een vaste kaarthoogte berekend. Een kaart zonder beeld (het icoon) krijgt dezelfde vorm, zodat de kaarten van een rij even hoog blijven. Elke kaart houdt haar eigen afbeeldingsweergave (`MEDIA.md`, *Responsive Media*), en de kaders in de kaarteditor volgen de vorm die de kaart echt krijgt. De afbeelding voor een telefoon is de compacte afbeelding van een kaart: de draaiende carrousel toont haar zodra hij plat wordt (onder 700px, `CardCarouselContent::COMPACT_MAX_WIDTH`), *Kaarten naast elkaar* op elke breedte, telkens met haar eigen punt en weergave; zonder eigen afbeelding toont elk scherm de desktopafbeelding |
| Witruimte | `spacers.size` | `medium`, `small`, `large`, `xlarge` (`SpacerContent::SIZES`) | Zie hieronder |

**Koppen in een Tekstblok** (v0.1.12). Een H2 in de tekst is een gewone H2
van de site. `.rich-content h2` en `h3` in `core.css` lezen de tokens van de
typeschaal (`--fs-h2`, `--fs-h3`, die met het scherm meeschalen) en nemen
gewicht, regelhoogte en lettertype van de basisregel voor `h1`–`h4`. Vroeger
was het een vaste 1.4rem in gewicht 400, zichtbaar kleiner dan elke andere
H2. Alleen de ruimte eromheen is van rich content zelf, in rem, zodat die
niet met de kop meegroeit. Dit geldt voor alles wat `.rich-content` deelt:
het Tekstblok, de blogtekst, de Portfolio-teksten en de collectiebeschrijving.
Alleen het uiterlijk veranderde: het blok print nog steeds de koppen die de
redacteur koos, en voegt zelf geen kopniveau toe (`RichContentTypographyTest`).

**Ruimte boven en onder een Tekstblok.** Het Tekstblok heeft nu de ruimte van
elke sectie (`section`, `--sp-7` boven en onder, `core.css`). Vroeger gooide
het zijn bovenruimte altijd weg: een overblijfsel van de informatiepagina,
waar het direct onder de paginakop stond. Dat geval is nu `$tightTop`
(`BlockDefinition::tightensFollowingBlock()`), net als bij Tekst met
afbeelding. Twee Tekstblokken direct na elkaar lezen als één kolom: het
tweede laat zijn bovenruimte weg (`.rich-text-section + .rich-text-section`
in `assets/css/blocks/rich-text.css`).

**Witruimte** (`spacer`, `SpacerBlock`) is een gewoon, herhaalbaar blok met
één instelling: de hoogte, een stap van de spacing-schaal
(`assets/css/blocks/spacer.css`: `--sp-4`, `--sp-6`, `--sp-7`, `--sp-8`, op
een telefoon elk één stap lager). Die ruimte komt bovenop de ruimte die de
blokken eromheen al hebben. Het blok print één leeg element met
`aria-hidden="true"`: geen kop, geen tekst, niets focusbaars. Het heeft geen
woorden, dus geen `block_translations`. Het was het eerste blok met rijen en
zonder woorden (de Mediabanner is het tweede), en staat daarom met naam in
`BlockDefinitionContractTest::WORDLESS_WITH_ROWS` en
`BlockSampleContractTest::WORDLESS`. De editor (`admin/spacer.php`) toont
alleen *Hoogte*. Tonen of verbergen doe je met het oog in de paginabouwer.

**Productgrid en Collectie-tegels** printen geen eigen kop meer. Ze toonden
een vaste "Alle producten" en "Collecties" die niemand had getypt. Die woorden
waren nooit opgeslagen en hadden geen veld. Wil een redacteur een kop, dan
zet hij een Tekstblok erboven.

## Oproep met knop (CTA 2.0)

`db/migrations/20260926120000`. De Oproep met knop is van een vaste kaart een
configureerbaar blok geworden: een eenvoudige tekst-CTA of een beeldvullende
sectie, zonder dat het een vrije paginabouwer wordt. Elke keuze is een woord
uit een gesloten lijst in `CtaBandContent`, de standaard eerst, en de
standaard is hoe elke oproep er al uitzag. Een onbekende opgeslagen waarde
leest als de standaard. Het endpoint weigert een onbekende waarde bij het veld
zelf, en een formulier zonder het veld houdt wat er staat. Geen keuze hangt aan
een taal: alleen de woorden (bovenlabel, titel, lead, twee knopteksten) zijn
per taal.

| Kolom | Waarden (standaard eerst) | Wat het doet |
|---|---|---|
| `content_align` | `center`, `left`, `right` (`ALIGNMENTS`) | Bovenlabel, titel, lead én knoppen. De uitlijning verplaatst ook het vak van de lead zelf (`margin-inline`): een smalle lead staat echt tegen de linkerrand, in het midden of tegen de rechterrand van de inhoudszone, niet alleen zijn tekst. De knoppen volgen over de hele inhoudszone |
| `lead_width` | `narrow`, `medium`, `wide`, `full` (`LEAD_WIDTHS`) | Alleen de maximale breedte van de lead, en van niets anders: niet van de titel, het bovenlabel, de knoppen of het tekstvlak. `narrow` is de `46ch` die elke `.lead` heeft (daarom was de lead smaller dan de titel), `medium` de leeskolom van de site (`--container-narrow`), `wide` `60rem`, `full` de hele tekstbreedte van de oproep. Op een smal scherm is elke keuze gewoon 100% |
| `full_width` | `0`, `1` | *Achtergrond over de volledige paginabreedte*, met en zonder afbeelding. Zie hieronder |
| `background_media_id` | een id uit de Mediabibliotheek of `NULL` | Decoratief: `alt=""` en `aria-hidden`, want de woorden zeggen alles. `ON DELETE RESTRICT`, en een tak in `ContentBlockMediaUsage` |
| `background_focus_x`, `background_focus_y`, `background_mobile_*` | 0–100, `50` eerst; de telefoonkolommen `NULL` | Welk punt van de afbeelding in beeld blijft (`object-position`), en voor een telefoon een eigen afbeelding en punt: het veld *Afbeeldingsweergave* (`MEDIA.md`, *Responsive Media*). Geen keuze voor de hele afbeelding: achter tekst vult het beeld altijd |
| `background_overlay` | `medium`, `none`, `light`, `dark` (`OVERLAYS`) | Alleen over een afbeelding. De scrimkleur van het thema (`--color-media-scrim-rgb`) op `0.35`, `0.62` en `0.8`. `medium` haalt voor de thematekst minstens 5:1, zelfs op wit |
| `text_panel` + `text_panel_opacity` | `0`/`1`; `strong`, `subtle`, `medium`, `solid` (`PANEL_OPACITIES`) | Een vlak achter de woorden in het oppervlak van het thema (`--color-surface-veil-rgb` op `0.55`, `0.72`, `0.88`; `solid` is `--color-surface`). Het vlak omsluit bovenlabel, titel, lead en knoppen en is precies zo breed als de inhoudszone die ze al hebben: de binnenkant van de kaart, of de `.container` van een oproep over de volle breedte. Het neemt zijn breedte nooit van de lead, dus de titel houdt zijn breedte en een smalle lead staat in een breder vlak. Er is geen instelling voor de breedte van het vlak. De dekking blijft bewaard als het vlak uit staat |

**De lagen**, van achter naar voor: de themakleur van de oproep (de kaart of
de sectie), de afbeelding, de overlay, het tekstvlak, de woorden. Laadt de
afbeelding niet, dan blijft de themakleur staan en is de tekst leesbaar. Er
is geen kleurkiezer: kleuren komen uit Thema & huisstijl.

**Volledige breedte zonder truc.** De `<section>` van elk blok loopt al over
de hele pagina, met de inhoud in een `.container`. Een oproep over de volle
breedte schildert zijn lagen daarom op de `<section>` (`.cta-section--full`)
in plaats van op de kaart, en de woorden blijven in de gewone container. Geen
`100vw`, geen negatieve marges, geen horizontale scroll. De Mediabanner doet
hetzelfde (zie hieronder): zijn beeld staat dan in de sectie zelf in plaats
van in de container. De oproep houdt de
gewone sectieruimte en groeit met zijn woorden mee; er is geen hoogte-instelling.

**Knoppen: geen, één of twee.** Beide knoppen gebruiken het gedeelde linkveld
(`LinkChoice`, hierboven). *Geen knop* bij de eerste knop is een oproep zonder
knop; dat is de aan/uit van de knop, er is geen aparte schakelaar. De tweede
knop zit in het editorformulier binnen de groep van de eerste en verdwijnt
met *Geen knop*. Het endpoint slaat dan ook voor de tweede knop *Geen knop*
op, en `CtaBandContent` rendert een tweede knop nooit zonder de eerste, wat er
ook opgeslagen staat. Een knop rendert alleen met een doel **en** zijn
knoptekst in de standaardtaal: een adres alleen zet nooit een knop aan, dus
een achtergebleven adres uit een oude rij brengt geen knop terug. Knop 1 is
de gewone `.btn`, knop 2 `.btn--ghost`, zoals altijd; de vorm komt uit het
thema.

**Bestaande oproepen.** De migratie geeft elke knop met een adres het type
`url`, zodat hij blijft gaan waar hij heen ging; adressen worden niet
herschreven. Een tweede adres zonder tweede knoptekst was geen knop en blijft
dat. Een eerste knop zonder knoptekst rendert nu geen lege link meer. Elke
oproep houdt kaart, midden, `narrow` en geen afbeelding: dezelfde markup en
maten (`CtaBandHttpTest`, `CtaBandRenderTest`, en in de browser gemeten tegen
`main`). Het CSS van de nieuwe keuzes staat in `assets/css/blocks/cta-band.css`
(eigenaar `CtaBandBlock::styles()`; `portfolio-detail.php`, dat een oproep
leent, vraagt het ook); de basiskaart bleef in `core.css`.

**De editor** heeft vijf kaarten: Inhoud, Weergave, Achtergrond, Tekstvlak en
Knoppen. De afbeeldingsweergave en de overlay staan er alleen met een afbeelding, de dekking
alleen met het tekstvlak aan (`admin/assets/cta-band.js`; de server print
dezelfde `hidden`). Er is geen eigen uploadveld: de afbeelding komt uit de
mediakiezer.

**Niet in CTA 2.0**: een video- of diavoorstellingachtergrond, een vrije
hoogte, vrije CSS of dekking, eigen kleuren, blokken binnen de oproep,
animatie-instellingen en paginathema's. Wie beeld of video met een bewust
gekozen hoogte wil, gebruikt de Mediabanner (hieronder).

## Mediabanner

`db/migrations/20260926130000`. Eén afbeelding of één video uit de
Mediabibliotheek als eigen sectie op de pagina: een sfeerfoto, een brede
banner, een productfoto of een korte video tussen andere blokken. Er staat
**geen tekst** op; wie woorden bij een beeld wil, gebruikt de Oproep met knop
of Tekst met afbeelding. Het blok heeft geen woorden en dus niets in
`block_translations`: net als de Witruimte staat het in
`BlockDefinitionContractTest::WORDLESS_WITH_ROWS`.

Sinds `db/migrations/20260928180000` kan één banner ook **meerdere
afbeeldingen en video's na elkaar** tonen, in dezelfde breedte en hoogte: een
mediareeks, het onderdeel dat hij met de Paginakop deelt (zie *Mediareeks*
hieronder en *Meer items na elkaar* aan het eind van dit hoofdstuk). Een
banner met één item is precies wat hij was.

| Bestand | Wat |
|---|---|
| `src/Service/Blocks/MediaBannerBlock.php` | Definitie, categorie *Beeld & media*, voorbeeldvorm `BlockPreview::MEDIA` |
| `src/Service/MediaBannerContent.php` | Het leesmodel en het hele videocontract |
| `src/Repository/MediaBannerRepository.php` | Tabel `media_banners` |
| `partials/section-media-banner.php` | `render_section_media_banner($content, $tightTop)` |
| `admin/media-banner.php` + `admin/assets/media-banner.js` | De editor |
| `api/admin/update-media-banner.php` | Het endpoint |
| `assets/css/blocks/media-banner.css`, `assets/js/blocks/media-banner.js` | Hoogtes en breedte; minder beweging |

**Het gekozen item beslist.** Het blok bewaart een `media_id` en nooit een
type. Is het item een afbeelding (`MediaType::IMAGE`, ook SVG), dan toont de
banner een `<img>`; is het een video (MP4 of WebM), dan een native `<video>`.
Iets anders (een verdwenen item, een bestand zonder soort) is niets om te
tonen, en de banner rendert dan niets, net als zonder keuze
(`MediaBannerContent::usableItem()`). Er is dus geen keuzelijst "afbeelding of
video" die het met het item oneens kan zijn. De kiezer gebruikt het filter
`MediaType::VISUAL` (`MEDIA.md`, *De mediakiezer*).

| Kolom | Waarden (standaard eerst) | Wat het doet |
|---|---|---|
| `media_id` | een id uit de Mediabibliotheek of `NULL` | De afbeelding of video. `ON DELETE RESTRICT` en een tak in `ContentBlockMediaUsage` |
| `width` | `content`, `full` (`WIDTHS`) | Binnen de `.container`, met de afgeronde hoeken van het thema (`--radius-lg`, zoals elk beeld in een blok), of over de volle paginabreedte zonder hoeken |
| `height` | `medium`, `small`, `large`, `xlarge` (`HEIGHTS`) | Zie *Hoogtes* hieronder |
| `image_focus_x`, `image_focus_y`, `image_fit`, `image_mobile_*` | 0–100, `50` eerst; `cover` eerst; de telefoonkolommen `NULL` | Alleen bij een afbeelding: de *Afbeeldingsweergave* (`MEDIA.md`, *Responsive Media*), hetzelfde veld als de Paginakop en de Oproep met knop: een focuspunt, *Vullen* of *Hele afbeelding*, en voor een telefoon een eigen afbeelding, punt, weergave en hoogte. Een video staat altijd in het midden en vult het kader |
| `video_autoplay`, `video_loop`, `video_controls` | `0`, `0`, `1` | Alleen bij een video. Zie *Het videocontract* |
| `poster_media_id` | een afbeelding uit de bibliotheek of `NULL` | Alleen bij een video: het beeld zolang hij nog niet speelt. `ON DELETE RESTRICT` en een eigen tak in `ContentBlockMediaUsage` |
| `slide_transition`, `slide_duration`, `slide_controls` | `fade`/`slide`/`none`; `5` (1–10); `both`/`arrows`/`dots`/`none` | Alleen bij meer dan één item: de overgang, de seconden per afbeelding en de knoppen voor de bezoeker (`MediaSequence`, zie *Mediareeks*) |
| `media_banner_items` (kindtabel) | `media_id` + `sort_order` | De items ná het eerste, in hun volgorde. `ON DELETE CASCADE` van de banner, `ON DELETE RESTRICT` naar de bibliotheek, en een eigen tak in `ContentBlockMediaUsage` (*Mediabanner (reeks)*) |

De videokolommen hebben een voorvoegsel omdat `LOOP` in MySQL een gereserveerd
woord is.

**Hoogtes.** Een vaste lijst, geen getal en geen viewporthoogte om in te
typen. De maten staan als tokens in `assets/css/blocks/media-banner.css` en
groeien met de breedte van het venster tussen een onder- en een bovengrens,
zoals de hoogtes van Tekst met afbeelding. Extra groot loopt nooit verder dan
90% van de vensterhoogte. Op een telefoon (≤ 640px) is elke hoogte een vaste,
lagere stap.

| Hoogte | Breed scherm | Telefoon |
|---|---|---|
| `small` | `clamp(15rem, 22vw, 18.75rem)`: 240–300px | `12rem` (192px) |
| `medium` | `clamp(18rem, 30vw, 25rem)`: 288–400px | `15rem` (240px) |
| `large` | `clamp(22rem, 42vw, 35rem)`: 352–560px | `19rem` (304px) |
| `xlarge` | `min(clamp(26rem, 52vw, 44rem), 90vh)`: 416–704px | `min(24rem, 80vh)` (384px) |

Afbeelding en video vullen het vlak altijd (`object-fit: cover`): ze worden
bijgesneden, nooit uitgerekt en nooit met balken ernaast. `contain` zit er
bewust niet in: dit is een banner, geen afbeeldingsviewer.

**Volle breedte zonder truc**, precies zoals de Oproep met knop: de
`<section>` loopt al over de hele pagina, dus een banner over de volle
breedte zet zijn vlak rechtstreeks in de sectie in plaats van in een
`.container`. Geen `100vw`, geen negatieve marges, geen horizontale scroll.
Er is geen gedeelde CSS-primitive met de oproep: het patroon is hetzelfde,
maar de oproep schildert lagen en de banner zet één vlak neer, en dat is te
weinig om een abstractie te rechtvaardigen.

**Ruimte.** De banner heeft de ruimte van elke sectie (`--sp-7` boven en
onder). Direct onder een paginakop valt de ruimte erboven weg (`$tightTop`,
class `media-banner-section--tight-top`), net als bij het Tekstblok. Twee
banners direct na elkaar houden één sectieruimte tussen zich
(`.media-banner-section + .media-banner-section`). Na een Witruimte komt de
ruimte van de Witruimte erbij, zoals na elk blok. Er is geen eigen
ruimte-instelling: daarvoor is de Witruimte.

**Het videocontract** (`MediaBannerContent::fromRow()`, en nergens anders):

- **Automatisch afspelen is altijd zonder geluid.** Er is geen kolom
  `muted`: `autoplay` komt altijd samen met `muted`, en een browser zou
  automatisch afspelen met geluid ook weigeren.
- **Een video die niet vanzelf speelt, heeft altijd bediening.** Anders kan
  een bezoeker hem nooit starten. Het endpoint weigert *niet automatisch
  afspelen* zonder *bediening*, en het leesmodel en de partial zetten de
  bediening er in dat geval toch bij.
- Altijd `playsinline` (een telefoon springt niet naar volledig scherm) en
  `preload="metadata"`: zonder autoplay laadt de browser alleen het begin van
  het bestand. Met autoplay laadt de browser de video volgens zijn eigen
  regels; twee autoplay-banners op één pagina laden allebei hun video. Er is
  geen eigen JavaScript om dat te sturen.
- Een video zonder bediening is versiering en krijgt `aria-hidden="true"`,
  zoals de video van de Homepage-hero; met bediening niet.
- **Minder beweging.** `assets/js/blocks/media-banner.js` zet een video die
  vanzelf speelt stil en geeft hem zijn bediening, als de bezoeker in het
  systeem om minder beweging heeft gevraagd (`prefers-reduced-motion`).
  Zonder die voorkeur, of zonder JavaScript, doet de video wat zijn
  attributen zeggen.
- Een poster is een afbeelding uit de bibliotheek. Een automatisch gemaakt
  stilstaand beeld is er niet: op gedeelde hosting kan niets een frame uit
  een video snijden, en daarom toont de kiezer bij een video ook een icoon.

**Wat een keuze betekent, beslist het gekozen item.** Bij een afbeelding
slaat het endpoint de afbeeldingsweergave op en laat het de geposte video-opties
liggen: de opgeslagen opties blijven staan, zodat een banner die weer een
video krijgt ze terug heeft, en een afbeelding krijgt nooit video-instellingen
die ze niet kan tonen. Bij een video is het omgekeerd. De poster wordt geleegd
zodra het item geen video is: een poster die niemand ziet, mag een item niet
als gebruikt laten tellen en daardoor onverwijderbaar maken. Een schakelaar is
`1` of afwezig; elke andere waarde weigert het endpoint bij het veld.

**Toegankelijkheid.** De alt-tekst van een afbeelding is die van de
Mediabibliotheek; leeg is een decoratieve afbeelding (`alt=""`). Er is geen
eigen alt-veld in het blok. De alt-tekst van de bibliotheek bestaat in één
taal, dus op `/en/` leest een schermlezer dezelfde tekst
(`docs/multilingual/ARCHITECTURE.md`). **Video heeft in V1 geen ondertitels en
geen toegankelijke naam**: er is geen `<track>` en geen tekstveld. De editor
zegt dat: gesproken tekst hoort als ondertiteling in het beeld zelf. Een video
die vanzelf speelt, zichzelf herhaalt en langer dan vijf seconden duurt, heeft
bediening nodig om hem te kunnen stoppen (WCAG 2.2.2); de helptekst bij
*Bediening tonen* zegt dat, het blok dwingt het niet af.

**De editor** heeft vier kaarten: *Media* (één kiezer voor afbeelding of
video, met het type naast de naam, en daaronder *Meer afbeeldingen en
video's*), *Weergave* (breedte, hoogte, en bij een afbeelding de
afbeeldingsweergave), *Afspelen* (automatisch afspelen en herhalen bij een video of een
reeks, de bediening zodra er een video in zit, de poster alleen als het eerste
item een video is) en *Diavoorstelling* (overgang, tijd per afbeelding en
knoppen, alleen bij meer dan één item). `admin/assets/media-banner.js` toont
wat bij het eerste item en de soorten in de lijst hoort; de server print
dezelfde `hidden` voor wat er opgeslagen is. Een nieuw blok begint leeg (geen
media, inhoudsbreedte, middel, midden, bediening aan) en rendert niets tot er
een afbeelding of video gekozen is. Tonen of verbergen doe je met het oog in
de paginabouwer.

### Meer items na elkaar

Het eerste item blijft `media_id`, met alles wat daar al voor gold. De items
erna staan in `media_banner_items`. `MediaBannerContent::fromRow()` maakt er
één lijst `items` van, het eerste inbegrepen, en daarmee groeit het
videocontract:

- **Automatisch afspelen en herhalen gelden voor de reeks.** Een afbeelding
  blijft `slide_duration` seconden staan, een video speelt tot zijn einde
  (zonder geluid) en dan komt het volgende item. *Herhalen* begint na het
  laatste item weer bij het eerste; een losse video in een reeks herhaalt
  zichzelf nooit.
- **Een reeks die niet vanzelf speelt, heeft pijlen of bolletjes.** Het
  endpoint weigert *niet automatisch afspelen* met knoppen *Geen*, bij het
  veld; het leesmodel maakt er in dat geval toch bolletjes van.
- **Een video in een reeks die niet vanzelf speelt, heeft zijn bediening**,
  dezelfde regel als bij één video. De poster is die van het eerste item als
  dat een video is; het focuspunt en de weergave gelden voor elke
  afbeelding, een eigen telefoonafbeelding niet (die geldt alleen zonder
  reeks, `MEDIA.md`, *Responsive Media*).
- Een verdwenen item, of een item dat geen afbeelding of video is, valt weg
  uit de reeks. Zonder bruikbaar eerste item is er geen banner.
- **Wat het endpoint bewaart** hangt af van wat er gekozen is: de
  afspeelopties bij een video óf een reeks, de keuzes van de diavoorstelling
  alleen bij een reeks. Wordt het eerste item gewist terwijl de lijst nog
  items heeft, dan schuift het eerste daarvan door naar `media_id`; zonder
  eerste item is er ook geen reeks.

In de markup is een reeks een `role="region"` met
`aria-roledescription="carousel"` en de naam *Diavoorstelling*, met de
dia's en knoppen van de gedeelde partial. Speelt hij vanzelf, dan houdt een
muis erop hem vast (`-hover-pause`), en met knoppen kan een bezoeker ook
vegen. Een pauzeknop staat er alleen als hij vanzelf speelt.

**Niet in de Mediabanner**: tekst over het beeld, knoppen in het beeld,
YouTube of Vimeo, transcoderen, automatisch een poster maken, een
ondertiteleditor, een vrije hoogte of vrije CSS, parallax, per item een eigen
tijd of overgang, en paginathema's.

## Mediareeks

Een **mediareeks** is meer dan één afbeelding of video in één kader, na
elkaar. Het is één gedeeld onderdeel, zodat geen blok een eigen slider
schrijft. De Paginakop (alleen afbeeldingen) en de Mediabanner (afbeeldingen
en video's) gebruiken het sinds `db/migrations/20260928180000`.

| Bestand | Wat |
|---|---|
| `src/Service/Media/MediaSequence.php` | De gesloten lijsten en de regels: `TRANSITIONS` (`fade`, `slide`, `none`), `DURATIONS` (1–10 seconden, standaard 5), `CONTROLS` (`both`, `arrows`, `dots`, `none`), `MAX_ITEMS` (12, het eerste item inbegrepen), `idsFromTokens()` en `slide()`. Noemt geen blok, pagina of tabel |
| `partials/media-sequence.php` | De markup, in drie stukken: `media_sequence_attributes()` op de wortel, `render_media_sequence_slides()` waar het beeld hoort, `render_media_sequence_controls()` ergens binnen de wortel |
| `assets/js/media-sequence.js` | Het gedrag, één controller per `[data-media-sequence]` |
| `assets/css/media-sequence.css` | Stapelen, de drie overgangen, de knoppen |
| `admin/_media_sequence_field.php` | Het veld in de editor: de gedeelde afbeeldingenlijst en de keuzes |

Het script en de stylesheet zijn gedeeld zoals `lightbox.js`: ze hebben geen
eigen eigenaar, maar worden gevraagd door de blokken die ze printen
(`PageHeroBlock` en `MediaBannerBlock`, `styles()` en `scripts()`).

**Het eerste item blijft waar het stond.** Een blok met een reeks bewaart zijn
eerste item in de kolom die het al had (`page_heroes.media_id`,
`media_banners.media_id`), met alt-tekst, focuspunt en poster zoals ze waren.
De items daarna staan in een kindtabel (`page_hero_images`,
`media_banner_items`: `media_id` en `sort_order`, `ON DELETE CASCADE` van het
blok, `ON DELETE RESTRICT` naar de bibliotheek, elk een eigen tak in
`ContentBlockMediaUsage`). Zo is een blok met één item byte voor byte de
markup die het altijd was, en had de migratie niets te verhuizen. Die
kindtabellen hebben geen woorden en staan in
`BlockTranslationSchemaTest::WORDLESS_CHILD_TABLES`.

**Wat de bezoeker krijgt:**

- **Zonder JavaScript** staat het eerste item er, precies zoals één beeld,
  en blijven de knoppen `hidden`: een knop die niets kan, wordt niet
  aangeboden.
- **Vanzelf spelen.** Een afbeelding blijft de gekozen tijd staan, een video
  speelt tot zijn einde en dan komt het volgende. Weigert de browser een video
  te starten (een stroombesparingsstand), dan krijgt die zijn bediening en
  telt hij als een afbeelding, zodat de reeks nooit vastloopt.
- **Wat vanzelf beweegt, kan stoppen** (WCAG 2.2.2): een reeks die vanzelf
  speelt, heeft altijd een pauzeknop, welke knoppen er verder ook zijn.
  Pauzeren zet ook een spelende video stil. De reeks wacht verder vanzelf
  zolang het tabblad niet zichtbaar is en zolang het toetsenbord op een pijl of
  bolletje staat.
- **Minder beweging.** Voor een bezoeker die daarom vroeg, begint de reeks
  gepauzeerd (de knop zegt *Afspelen*) en wisselt hij daarna zonder vervagen
  of schuiven.
- **De knoppen**: pijlen aan de zijkanten, bolletjes onderin of allebei; de
  pijltjestoetsen werken als het toetsenbord in de reeks staat. Een bolletje
  zegt welk item het is ("2 van 4"), en `aria-current` wijst het getoonde aan.
- **Toegankelijk.** Alleen het getoonde item is bereikbaar voor hulpmiddelen;
  de andere zijn `aria-hidden` en `inert`. Elk item is een groep met de naam
  "2 van 4". Een reeks die alleen versiering is (de beelden áchter de tekst
  van een Paginakop), heeft `alt=""` en is als geheel verborgen.
- Er komt niets uit de database in de CSS; de enige inline stijl is die van
  de afbeeldingsweergave van het blok (`object-position`, en `object-fit` bij
  de hele afbeelding), voor elke dia dezelfde, geprint door
  `partials/responsive-image.php`. Een dia krijgt nooit de eigen
  telefoonafbeelding van het blok.

**In de editor** is de reeks één lijst onder de gewone kiezer: *Meer
afbeeldingen (en video's)*, met *Toevoegen*, slepen, ← en → en weghalen
(`admin/assets/product-gallery.js`, de gedeelde `[data-picture-gallery]`-lijst
van de productgalerij). Een video krijgt er een icoon. De keuzes (overgang,
tijd per afbeelding en bij de Mediabanner de knoppen) verschijnen zodra de
lijst een item heeft. De lijst post `media:<id>`-tokens
in `sequence[]`, met `sequence_submitted` als teken dat de lijst op het
formulier stond: een formulier zonder dat teken houdt de opgeslagen lijst.
Elk id moet een item van de bibliotheek zijn van de soort die het blok
toelaat, het eerste item mag er niet nog eens in, en er zijn er hoogstens 12;
anders weigert het endpoint de opslag bij het veld en komt de lijst terug
zoals hij getypt was.

**De Paginakop** toont zijn reeks alleen met een eigen afbeelding (achter of
naast de tekst). Achter de tekst is de hele reeks versiering, met de
pauzeknop in de hoek van de band. Naast de tekst is elke afbeelding inhoud:
het eigen beeld met de alt-tekst van de kop, de volgende met die van de
bibliotheek, in een `<figure>` die een *Diavoorstelling* heet, met de
pauzeknop in de hoek. De kop speelt altijd vanzelf en begint na de laatste
weer bij de eerste; hij heeft geen pijlen of bolletjes. *Geen afbeelding*
maakt ook de lijst leeg, en een gewist eigen beeld wordt vervangen door het
eerste uit de lijst (met de alt-tekst van de bibliotheek).

## Hover kaarten grid

`db/migrations/20260928170000`. Een raster van kaarten met een afbeelding,
die tot leven komen zodra een bezoeker ze bereikt: met de muis (alleen waar
het apparaat echt hovert) of met het toetsenbord. Een kaart tilt dan een
beetje op, de foto zoomt in of maakt plaats voor een tweede foto, een
organische kaart verandert van vorm, en de tekst verschijnt. Categorie *Beeld
& media*, voorbeeldvorm kop + tegels.

| Bestand | Wat |
|---|---|
| `src/Service/Blocks/HoverCardGridBlock.php` | Definitie, woorden, voorbeeld (drie kaarten) |
| `src/Service/HoverCardGridContent.php` | Het leesmodel en de gesloten lijsten |
| `src/Repository/HoverCardGridRepository.php` | `hover_card_grids` en `hover_card_grid_items` |
| `partials/section-hover-card-grid.php` | `render_section_hover_card_grid($content, $revealGroup)` |
| `admin/hover-card-grid.php` + `admin/assets/hover-card-grid.js` | De editor |
| `api/admin/update-hover-card-grid.php` | Het endpoint |
| `assets/css/blocks/hover-card-grid.css`, `assets/js/blocks/hover-card-grid.js` | Vormen, sluiers en effecten; een tik op een touchscherm |

**Woorden**, allemaal optioneel en per taal in `block_translations`: van het
raster een bovenlabel, titel en introtekst (150, 255, 500 tekens), van een
kaart een label, titel, tekst en linktekst (60, 255, 500, 150). De
standaardtaal beslist of een woord er is.

**Een kaart** heeft een afbeelding (`media_id`, verplicht, een afbeelding van
de bibliotheek), een optionele tweede afbeelding (`hover_media_id`; twee keer
dezelfde wordt één keer opgeslagen), een link via `LinkChoice`
(`link_type`, `link_target_id`, `link_url`) en een volgorde. Beide afbeeldingen
zijn `ON DELETE RESTRICT` met elk een eigen tak in `ContentBlockMediaUsage`:
*Hover kaarten grid* en *Hover kaarten grid (tweede afbeelding)*. De alt-tekst
is die van de bibliotheek; de tweede afbeelding is een andere blik op
hetzelfde en dus `alt=""` en `aria-hidden`. De hoofdafbeelding heeft de
*Afbeeldingsweergave* van elke plek die bijsnijdt (`image_focus_x`,
`image_focus_y`, `image_fit` en `image_mobile_*`, `MEDIA.md`, *Responsive
Media*); de tweede vult het kader altijd vanuit het midden. De kaders in de
editor volgen de vorm van het grid.

| Kolom | Waarden (standaard eerst) | Wat het doet |
|---|---|---|
| `layout` | `overlay`, `open` | Tekst over de foto, op een sluier onderin, of tekst onder de foto |
| `shape` | `rounded`, `square`, `circle`, `organic` | De vorm van de foto: de afgeronde hoeken van het thema, geen hoeken, een cirkel, of een organische vorm die bij bereiken langzaam van vorm verandert (een `border-radius`-overgang, geen animatiebibliotheek). Rond en organisch zijn altijd vierkant van verhouding |
| `columns` | `3`, `2`, `4` | Kaarten naast elkaar op een breed scherm. Vier wordt drie onder 1100px; onder 900px zijn het er twee, onder 560px één. Nooit horizontaal scrollen |
| `overlay` | `medium`, `light`, `dark` | Alleen bij `overlay`: hoeveel van de foto de sluier bedekt. Achter de woorden zelf blijft hij dicht genoeg voor 6:1 of meer, ook boven een witte foto |
| `effect` | `normal`, `subtle` | Hoeveel een kaart beweegt: optillen, inzoomen, vormverandering |
| `header_align` | `left`, `center`, `right` | Alleen de kop boven de kaarten |

De keuzes worden klassen op de lijst, en een standaard voegt er geen toe. Wat
een woord betekent, staat als custom property in de stylesheet; niets uit de
database wordt CSS.

**Eén link per kaart, over de hele kaart.** Een kaart met een link heeft
precies één echte `<a>`: de linktekst als zichtbare knop onder de tekst (met
de titel erachter, alleen voor een schermlezer, zodat twee keer *Bekijk* op een
pagina twee verschillende links zijn), of anders de titel. De stylesheet
rekt die link over de hele kaart uit, dus een klik waar dan ook volgt hem,
terwijl een schermlezer één korte naam hoort in plaats van de hele kaart. De
focusring staat om de hele kaart, in de vorm van de kaart. Een link heeft een
naam nodig: het endpoint weigert een kaart met een link zonder titel of
linktekst in de standaardtaal, en het leesmodel maakt er in dat geval geen
link van. Een kaart zonder link heeft geen `<a>`, geen handje en niets om op
te focussen.

**Niets is alleen voor de muis.**

- Alles wat hover brengt, brengt `:focus-within` ook, en hover telt alleen op
  een apparaat dat echt hovert (`@media (hover: hover)`).
- Een kaart met tekst over de foto en een link toont zijn tekst pas bij
  bereiken. Een kaart **zonder** link kan het toetsenbord niet bereiken, dus
  daar staat de tekst er altijd.
- Op een **touchscherm** (`hover: none`) staat de tekst er gewoon. Een tik op
  een kaart zonder link maar met een tweede foto wisselt naar die foto en terug
  (`hover-card-grid.js`); een tik op een kaart met een link volgt de link.
- **Minder beweging**: geen optillen, geen zoom, geen vormverandering; wat
  verschijnt, staat er meteen. In *forced colors* (de contrastmodus van
  Windows) bestaat geen sluier; daar staat de tekst onder de foto en altijd
  in beeld.

**Koppen.** De titel van het raster is een `<h2>`; daaronder is een kaarttitel
een `<h3>`, en in een raster zonder titel een `<h2>` (*Koppen in kaarten*). Het raster is een lijst (`<ul role="list">`).
Zonder één kaart om te tonen rendert het blok niets, zijn kop ook niet.

**De editor** heeft drie kaarten: *Kop boven de kaarten*, *Weergave* (de
keuzes; de sluier alleen bij tekst over de foto) en *Kaarten*. Elke kaart
klapt apart in (`PAGE-EDITOR.md`, *Inklapbare rijen*) met "Kaart 2 — titel"
als kopregel, en heeft de mediakiezer, de tweede afbeelding, de
*Afbeeldingsweergave* van de hoofdafbeelding, de woorden en het gedeelde
linkveld. Een nieuw raster begint zonder kaarten en rendert niets tot
er een kaart met een afbeelding is.

**Niet in de Hover kaarten grid**: video, een eigen kleur per kaart, een
carrousel die vanzelf draait, vrije CSS of eigen animatietijden, en meer dan
één link per kaart.

## Projecten 2.0: welke projecten, in welke volgorde

`db/migrations/20260928200000`. Het blok **Projecten** (`project_cards`) en
de **galerij** (`item_gallery`) op portfolio-items delen hun
`item_galleries`-rij, en daarmee de keuze welke projecten ze tonen. Die keuze
hoort bij de bron (`ItemGallerySources`), niet bij het blok: het blok bewaart
woorden, de bron kiest en sorteert, en de partial tekent de kaarten die er al
waren.

| Instelling | Waar | Waarden (standaard eerst) |
|---|---|---|
| Bron | `item_galleries.portfolio_scope` | `all` (alle zichtbare projecten), `category` (de zichtbare projecten van één categorie), `manual` (handmatig gekozen) — `ItemGalleryContent::SCOPES` |
| Categorie | `item_galleries.portfolio_category_id` | een Portfolio-categorie; `ON DELETE SET NULL`, zoals `collection_id` |
| Volgorde | `item_galleries.item_sort` | `source` (de standaard Portfolio-volgorde), `newest`, `oldest`, `title_asc`, `title_desc`, `random` — `ItemGalleryContent::SORTS`; bij `manual` alleen `source` (de gekozen volgorde) en `random` |
| Maximum | `item_galleries.max_items` | leeg (alles), in de editor 3, 4, 6, 8 of 12; een eerder opgeslagen ander getal blijft staan en krijgt een eigen optie |
| Handmatige selectie | `item_gallery_portfolio_items` | galerij, project, volgorde; samengestelde sleutel, beide kanten `ON DELETE CASCADE`; geen woorden (een project brengt de zijne mee uit Portfolio), dus in `BlockTranslationSchemaTest::WORDLESS_CHILD_TABLES` |

- **Alleen zichtbare projecten**, hoe ze ook gekozen worden. Een verborgen
  project in een handmatige selectie wacht; een verwijderd project verdwijnt
  eruit met de rij.
- **Een verwijderde categorie** laat het blok los: de categorie wordt NULL,
  de bron, de volgorde, het maximum en de woorden blijven, het blok toont
  niets (ook geen kop), en de editor zegt *De gekozen categorie bestaat niet
  meer* tot er een andere is gekozen. Opslaan met *Eén categorie* zonder
  categorie weigert het endpoint.
- **De titelvolgorde** leest de titel die de bezoeker ziet, in zijn taal, als
  een mens sorteert (`strnatcasecmp`: 2 vóór 10); een project zonder titel
  staat achteraan. Nieuwste en oudste gaan op `created_at`, bij een gelijke
  tijd op het id.
- **Meerdere blokken op één pagina** hebben elk hun eigen rij, dus elk hun
  eigen bron, categorie, volgorde en selectie: Wolven, D&D en Onderzetters
  onder elkaar.
- **Met Portfolio uit** is de bron niet beschikbaar: het blok toont niets
  (geen lege kop, geen lege categorie), de rij en de selectie blijven, en met
  Portfolio weer aan staat alles terug (`MODULES.md`, "Portfolio").
- **De galerij** (*Portfoliogalerij*) biedt dezelfde keuze in haar eigen
  editor, voor elke bron die hem ondersteunt; een galerij die vroeger de
  *Toon op homepage*-selectie toonde, is sinds `20260928200000` een handmatige
  selectie van dezelfde projecten.

**De editors** tonen de keuze met één gedeeld stuk (`admin/_gallery_selection.php`):
*Bron*, *Categorie* (met per categorie het aantal zichtbare projecten),
*Volgorde*, en bij *Handmatige selectie* de projectkiezer
(`admin/_item_picker.php`) met *In willekeurige volgorde tonen*. De kiezer is
het patroon van de productkiezer van een collectie: een rij per project met
een vinkje, een miniatuur, de titel, de categorieën en *Verborgen* bij een
verborgen project; de gekozen staan bovenaan in hun volgorde, ↑ en ↓ (het
toetsenbord) en slepen verplaatsen ze, en de browser stuurt de vinkjes in
die volgorde mee, dus een project kan maar één keer gekozen zijn. Zoeken is
geen wijziging (de opslagbalk ziet het niet). Een regel zegt hoeveel er
gekozen zijn en hoeveel het blok toont. Alles wordt gecontroleerd door één
klasse voor beide endpoints, `App\Service\ItemGallerySelection`: een
onbekend woord wordt geweigerd, een verouderd id stil weggelaten, en een
formulier zonder een veld houdt wat er staat.

### Willekeurige volgorde

*Willekeurig* trekt bij elke echte paginarequest opnieuw, op de server, en
alleen uit wat er getoond mag worden (`App\Service\RandomOrder`, PHP's eigen
`\Random\Randomizer`):

- **Eerst de ids, dan de kaarten.** De bron haalt de zichtbare rijen één keer
  per request op (`PortfolioGalleryContent::visibleRows()`, zonder woorden),
  filtert op categorie of selectie, trekt of sorteert de ids, en maakt pas
  daarna kaarten van alleen de gekozen projecten, met de woorden, de
  categorieën, de gekoppelde pagina's en de bibliotheekbeelden elk in één
  query. Vier willekeurige uit tweehonderd kosten dus vier kaarten. Geen
  `ORDER BY RAND()` en niets willekeurigs in de browser.
- **Geen cache houdt de trekking vast.** Een publieke pagina stuurt geen
  `Cache-Control` en er is geen opgeslagen uitvoer; de enige caches zijn per
  request (`ItemGalleryContent`, `PortfolioGalleryContent`), dus één request
  tekent een blok met één trekking en de volgende request trekt opnieuw.
  Komt er ooit een paginacache voor, dan houdt die een willekeurige keuze vast
  zolang hij geldt: dat is dan een keuze van die cache, niet van het blok.
- **Testbaar zonder geluk.** `RandomOrder::useEngineForTests()` zet een
  vaste seed en `calls()` telt de trekkingen, zodat een test bewijst dat er
  per render getrokken wordt, uit de juiste pool, zonder dubbelen en binnen
  het maximum — en nooit dat de volgende trekking anders *moet* zijn.

## Uitgelicht product

`db/migrations/20260928160000`. Eén product uit de Shop groot op een gewone
pagina: foto's naast naam, prijs en tekst, de varianten, en naar keuze de
bestelmogelijkheid van de productpagina plus een knop naar die productpagina.
Een blok van de **Shop-module** (`ShopModule::blockDefinitions()`, categorie
*Shop*), herhaalbaar, op elke gewone pagina.

| Bestand | Wat |
|---|---|
| `src/Service/Blocks/FeaturedProductBlock.php` | Definitie, categorie *Shop*, voorbeeldvorm `BlockPreview::IMAGE_LEFT` |
| `src/Service/FeaturedProductContent.php` | Het leesmodel: de keuzes van het blok en het product zoals het nú is |
| `src/Repository/FeaturedProductRepository.php` | Tabel `featured_products` |
| `partials/section-featured-product.php` | `render_section_featured_product($content, $revealGroup)` |
| `admin/featured-product.php` + `admin/assets/featured-product.js` | De editor en zijn productkiezer |
| `api/admin/update-featured-product.php` | Het endpoint |
| `assets/css/shop/featured-product.css` | Alleen de layout rond het product |

**Het blok is geen tweede productpagina.** Alles van het product komt live uit
de Shop, uit dezelfde klassen als op `product.php`, en niets ervan staat in
het blok:

| Wat | Waar het vandaan komt |
|---|---|
| Zichtbaarheid, woorden, foto's, varianten, voorraad en prijzen | `App\Service\ProductDetail::forPublic()` — de payload die `GET /api/product.php` antwoordt. Het blok drukt hem af in `<script type="application/json" data-product-payload>`, de API geeft hem als JSON |
| Of het product in de winkelwagen kan | `App\Service\ProductPurchasePath`: `cart`, `inquiry` (op aanvraag), `personalize` (de enige koopactie zit in de configurator) of `unorderable` (alleen via personalisatie, maar er is niets te personaliseren) |
| Bestelvragen, specificaties, overgang van de galerij, adres van de productpagina | `OrderFields`, `ProductSpecifications`, `ProductGalleryTransition` (product → Shop → `fade`), `ProductSeo::publicPath()` in de taal van het verzoek |
| Het koopgedeelte | `partials/product-purchase.php`: dezelfde markup als op de productpagina (*Uitverkocht* met *Mail mij als dit weer beschikbaar is*, de bestelvragen, het aantal, *Toevoegen aan winkelwagen*, *Prijs en bestellen op aanvraag*) |
| Het gedrag | `assets/js/shop/shop.js` en `product-gallery.js`: dezelfde code draait per `[data-product-detail]`-element, voor de productpagina en voor elk blok apart |

Een nieuwe hoofdfoto, een andere prijs of een variant die uitverkocht raakt,
staat dus meteen in het blok. De tabel `featured_products` heeft alleen de
keuzes van het blok: `product_id`, vijf schakelaars (`show_name`,
`show_price`, `show_description`, `show_specifications`,
`show_product_link`) en vijf woorden uit gesloten lijsten (`image_mode`,
`image_position`, `image_size`, `content_align`, `ordering`). De twee woorden
per taal, een eigen *introtekst* en een eigen *knoptekst*, staan in
`block_translations` en zijn allebei optioneel. De foreign key naar
`products` is `ON DELETE SET NULL`: een verwijderd product laat een leeg blok
achter en houdt het verwijderen nooit tegen.

**Leeg mag.** Een nieuw blok heeft geen product en rendert niets: geen leeg
kader, geen ruimte. Zo kan een redacteur het alvast op de pagina zetten en
later instellen. Hetzelfde geldt voor een verborgen blok, een product op
*Inactief* en een verwijderd product. De editor zegt het: *Nog geen product
gekozen* of *Het gekozen product is niet beschikbaar*.

**Het blok kan alleen minder aanbieden dan de productpagina, nooit meer.**
*Bestelmogelijkheid* is *Direct bestellen* (de standaard) of *Alleen product
bekijken*:

| Het product | Direct bestellen | Alleen product bekijken |
|---|---|---|
| gewoon te koop (`cart`) | bestelvragen, aantal, *Toevoegen aan winkelwagen*; bij een uitverkochte eenheid *Uitverkocht* met het terug-op-voorraadformulier | alleen *Uitverkocht* als de eenheid op is |
| op aanvraag (`inquiry`) | het vak *Prijs en bestellen op aanvraag* van de productpagina | *Op aanvraag* waar de prijs zou staan, als *Prijs tonen* aan staat |
| met configurator (`personalize`) | de wegwijzer naar de configurator op de productpagina (`#personaliseren`), nooit een losse winkelwagenknop | alleen *Uitverkocht* als de eenheid op is |
| niet te bestellen (`unorderable`) | *Dit product is op dit moment niet te bestellen.* | alleen *Uitverkocht* als de eenheid op is |

De server beslist toch opnieuw, wat het blok ook stuurt:
`api/cart-check.php` en `api/checkout.php` weigeren te veel, uitverkocht, op
aanvraag, een ontbrekend of vervalst antwoord en een variant van een ander
product, en `PersonalizationValidator` weigert een gewone regel voor een
product dat gepersonaliseerd moet worden.

**Aantal, voorraad, varianten, bestelvelden.** Het aantal volgt de
productpagina: minstens 1, de knoppen gaan niet hoger dan wat er van de
gekozen eenheid over is (en niet hoger dan 20, de grens van de productpagina),
en vóór er iets in de winkelwagen gaat vraagt `shop.js` het aan
`api/cart-check.php`, met wat er al in de winkelwagen zit. Drie stuks in één
klik is één regel met aantal 3. Een andere variant kiezen wisselt foto,
prijs, tekst (een eigen varianttekst), voorraad en maximum. Het
terug-op-voorraadformulier stuurt naar `api/stock-notification.php` voor
precies de eenheid die op het scherm staat. De antwoorden op bestelvragen
gaan mee op de regel en maken hem tot een eigen regel, net als op de
productpagina.

**Prijs.** Een product op aanvraag heeft nergens een prijs: niet in de
markup, niet in de payload, niet bij een variant. Staat *Prijs tonen* uit,
dan staat ook de prijs van een gewoon product nergens in het blok: niet in de
markup, niet in een `data-*`-attribuut en niet in de payload
(`ProductDetail::withoutPrices()`), ook niet als het blok verkoopt. De
winkelwagenregel heeft een prijs nodig om te tonen; die vraagt `shop.js` pas
als een bezoeker op *Toevoegen aan winkelwagen* drukt, na het ja van
`api/cart-check.php`, aan `GET /api/product.php` (de payload waar de
productpagina zelf uit tekent), voor het product of de gekozen variant
(`linePrice()`). Geeft de server geen prijs, dan gaat er niets in de
winkelwagen en zegt het blok *Dit lukte niet. Probeer het later opnieuw.*
Een pagina die zijn prijs toont, gebruikt zijn eigen payload en doet geen
extra verzoek. De prijs op de regel is alleen weergave: `api/checkout.php`
rekent elke regel opnieuw uit de database.

**Afbeeldingen en layout.** *Galerij* (standaard) is de galerij van de
productpagina: het vierkante vak met `contain`, de thumbnails, vegen, ← en →,
`aria-current` en de overgang van het product; bij één foto is er geen
thumbnailrij. *Alleen hoofdafbeelding* toont steeds de eerste foto van het
product of van de gekozen variant, zonder thumbnails. *Positie* links of
rechts en *Formaat* klein, normaal of groot (40, 50 of 60 procent van de
breedte) gelden vanaf 901 px; smaller staat alles onder elkaar, de foto's
altijd eerst, in een vak van hooguit 34rem breed. *Uitlijning van de tekst*
is links, midden of rechts; bestelvelden, specificaties en het
terug-op-voorraadformulier blijven links. Er is geen instelling voor de
breedte van het blok: het staat in de gewone container, zoals bijna elk blok,
en een breedtesysteem voor alle blokken bestaat niet.

**Productnaam, tekst, specificaties.** De naam is een `h2` (de productpagina
heeft een `h1`), de specificaties een `h3`. *Producttekst tonen* toont de
omschrijving van het product zelf, of van de gekozen variant als die een
eigen tekst heeft; een apart kort-tekstveld heeft een product niet, en voor
een korte eigen zin is er de introtekst. *Specificaties tonen* staat
standaard uit.

**De knop naar de productpagina** heet *Bekijk product* / *View product*
zolang er geen eigen tekst is. Het adres komt altijd van het gekozen product,
in de taal van de pagina. Een schermlezer hoort de productnaam erachter
(`.visually-hidden`), zodat twee van deze blokken op één pagina twee
verschillende links zijn.

**Geen tweede Product-schema.** Het blok voegt geen JSON-LD toe: de Product
JSON-LD hoort bij `product.php` alleen, en drie uitgelichte producten op een
pagina zouden anders drie misleidende hoofdproducten opleveren.

**Met de Shop uit** is het bloktype niet geregistreerd: niet in de kiezer,
geen kopje *Shop*, en een geplaatst blok wordt overgeslagen terwijl zijn rij
en woorden blijven staan (*Blok van een uitgeschakeld onderdeel* in de
paginabouwer). De editor en het endpoint vragen `pages.manage`, een recht van
Core, en hebben daarom een eigen `App\Module\ModuleGuard`: het scherm geeft
de geen-toegangpagina, het endpoint een 404 vóór het iets leest. Staat de
Shop weer aan, dan is het blok terug zoals het was.

**De editor** is één formulier met de opslagbalk en zes inklapbare kaarten
(`admin/_admin_collapse.php`): *Product* (de productrijen van de
collectie-editor, met één keuzerondje per product, *Geen product*, zoeken op
naam en een regel die zegt wat er gekozen is), *Inhoud* (de vier schakelaars
en de introtekst), *Afbeeldingen*, *Bestellen*, *Productknop* (schakelaar en
knoptekst) en *Uitlijning*. Een product-id intypen kan niet. Zoeken filtert
alleen het scherm en telt voor de opslagbalk niet als wijziging.

**Het voorbeeld** in de Contentblokken-bibliotheek is het echte blok met
woorden en beeld uit `BlockSamples`, als *Alleen product bekijken* en zonder
prijs: een voorbeeld noemt geen prijs en heeft geen winkelwagen om iets in te
leggen.

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
| De bestemmingskiezer, `LinkChoice`, `LinkTargets` of `SafeUrl` | `fast` → `blocks` (`DestinationPickerTest`, `Routing\SafeUrlTest`) → `modules` |
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
je definitie iets uit het contract. `tests/Service/BlockSampleContractTest.php`
doet hetzelfde voor het voorbeeld: het rendert je `sampleContent()` door je
eigen partial en faalt op een ontbrekende sleutel, een onge-escapet woord of
een link die de preview uit kan. `tests/Service/CardHeadingContractTest.php`
rendert dat voorbeeld met en zonder titel en faalt op een overgeslagen
kopniveau of een lege kop (*Koppen in kaarten*).

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
