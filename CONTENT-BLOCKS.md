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

### Hoe een knop eruitziet: de knopstijl

Waar een knop heen gaat kiest de bestemmingskiezer; hoe hij eruitziet kiest
het veld **Knopstijl** (Button Styles 2.0, `THEMING.md` "Knopstijlen"). Eén
veld voor elke blokknop: `admin_button_style_field()` uit
`admin/_button_style_field.php`, in de groep van de knop zodat het met
"Geen knop" meeverdwijnt. De opties komen altijd uit de bibliotheek
(Vormgeving → Knoppen); **Standaard** bewaart `NULL` en volgt de
standaardknop, of voor een tweede knop de standaard tweede knop.

- **Opslag**: een nullable `…button_style_id` met een RESTRICT foreign key op
  de rij van het blok of van het item, geschreven door
  `ButtonStyleRepository::saveChoice()` (gesloten lijst `SLOTS`, voor een
  moduleblok `ModuleDefinition::buttonStyleSlots()`) in de transactie van het
  endpoint. Nooit het ontwerp zelf: een wijziging aan de stijl raakt elke
  knop die hem koos.
- **Endpoint**: `ButtonStyles::choiceFromRequest()`. Een formulier zonder het
  veld houdt wat er staat, `''` is Standaard, een vervalst of onbekend id
  wordt aan het veld geweigerd. In een repeater hoort `button_style_id` in de
  `$preset` van `EditorChildList`: een gekozen stijl alleen maakt geen rij.
- **Partial**: `ButtonStyles::classes($keuze, $oudeKlassen, $layoutKlassen)`.
  Zonder keuze exact de oude markup, inclusief de oude pijl
  (`<svg class="btn__arrow">`); met keuze `btn btn-style-<id>` plus de
  layoutklassen (`btn--block`, `text-image__button`) en geen oude pijl. Een
  look-klasse als `btn--ghost` of `btn--sm` wijkt voor de gekozen stijl.
- **Aangesloten**: Oproep met knop en Homepage Hero (elk twee knoppen),
  Tekstblok, Tekst met afbeelding (per item), Kaarten-carrousel (per kaart),
  Hover kaarten (per kaart; de stijl staat op een `<span class="btn">` binnen
  de kaartbrede link, die zijn eigen `::after` over de kaart houdt),
  Detailsectie, Contactkaart, Galerij (voetknop) en Uitgelicht product.
  `Tests\Service\ButtonStyleBlocksTest::CONNECTED` is de lijst; een blok dat
  een eigen knop krijgt, hoort erin.

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
| `pages.owner_type` | NULL voor elke gewone pagina; `product`, `portfolio_project`, `blog_post` (Blog 2.0) of `article` (Artikelen) voor een inhoudspagina |
| `product_content_pages`, `portfolio_content_pages`, `blog_post_content_pages`, `article_content_pages` | eigenaar ↔ inhoudspagina, één op één, echte foreign keys aan beide kanten, `RESTRICT` |
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

Een **artikel** (`article`, recht `articles.manage`, `ARTICLES.md`) heeft alleen
blokken als inhoud. Voor zijn publicatieregel vraagt het
`ContentPages::hasMeaningfulBlocks()`: een zichtbaar blok dat iets zegt, dus
aan in de lijst en in zijn eigen editor, geen paginakop, geen decoratief blok
(`BlockDefinition::isDecorative()`, alleen Witruimte) en geen blok dat zelf
zegt leeg te zijn.

### Een eigenaar die inhoud moet houden

Een eigenaar kan eisen dat zijn bloklijst iets blijft zeggen: hij implementeert
`ContentOwners\RequiresContent` (`requiresContent($id)` en zijn eigen melding).
Vandaag alleen een artikel dat geen concept is. `ContentOwners\OwnerContentGuard`
toetst dat centraal, zodat geen blokeditor en geen endpoint een eigenaar kent:

| Waar | Wat | Hoe |
|---|---|---|
| `ContentBlockDrafts::place()` | elke opslag van een blokeditor (leegmaken, uitzetten, items weghalen) | `assertIntact()` na de laatste schrijfstap, binnen de transactie |
| `SectionRegistry::delete()` | verwijderen uit de lijst | `assertMayRemove()` vooraf, `assertIntact()` in de transactie |
| `SectionRegistry::setActive()` | verbergen in de lijst (`toggle-page-section.php`) | idem |
| `update-carousel-card.php` | een kaart van een carrousel | `assertIntact()` op de lijst van de carrousel |

Een weigering is een `OwnerContentRequired`; de transactie rolt terug en de
foutafhandeling van het endpoint toont `OwnerContentGuard::messageFor($e)` in
plaats van zijn algemene melding. Een nieuw blok hoeft hier niets voor te
doen: zijn editor roept `place()` al aan. Een eigenaar die met zijn hele
lijst verdwijnt (`ContentPages::deleteFor()`/`deleteOwner()`) wordt nooit
geweigerd.

Een **blogbericht** (Blog 2.0, `blog_post`, recht `blog.manage`) volgt dezelfde
regels als een product: alle gewone blokken, geen Paginakop en geen
Projectinformatie. Het toont zijn blokken alleen in blokmodus; in de klassieke
modus blijft zijn tekst staan, ook als er blokken zijn (`BLOG.md`, "Klassieke
tekst en contentblokken").

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

**Het mediagebruik volgt hetzelfde recht.** Elke plek die
`ContentBlockMediaUsage` in de Mediabibliotheek meldt, vraagt het recht van
haar bloklijst (`ContentBlockAccess::permissionFor()` op haar `pages`-rij):
een Shop-beheerder krijgt de link naar het blok op een product, een
Pagina-beheerder die niet, en wie een recht mist hoort alleen dát het item
ergens gebruikt wordt (`VisibleMediaUsages`). De link zelf gaat naar de
blok-editor, die het recht opnieuw controleert en terugwijst naar de
eigenaar (`ContentBlockMediaUsageOwnerHttpTest`).

**Wat een blok niet doet** op een product of project: de titel, canonical,
structured data of deelafbeelding veranderen. Die blijven van `ProductSeo` en
`PortfolioSeo`. Zoeken (Search 1.0) doorzoekt de blokken nog niet.

**Herbruikbare blokken** zijn in deze codebase bloktypes die op elke pagina
terug kunnen komen, geen gedeelde bibliotheek met verwijzingen; een blok op een
product is een eigen instantie en kan dus niet van betekenis veranderen.

## De levensloop van een nieuw blok

Contentblokken UX & Lifecycle 1.0 (v0.1.14). Een blok kiezen in de
blokkenkiezer zette het vroeger meteen op de pagina: `add-page-section.php`
maakte de inhoudsrij én de `page_sections`-rij, en wie daarna zonder opslaan
terugging, hield een leeg blok tussen de andere over. Nu is een nieuw blok een
**draft** tot zijn eerste geslaagde opslag.

| Stap | Wat er gebeurt | Waar |
|---|---|---|
| Kiezen | De inhoudsrij wordt gemaakt (`SectionRegistry::create()`, met startinhoud), plus één rij in `content_block_drafts`. **Geen** `page_sections`-rij: geen plek in de lijst, geen positie, niets op de website. Bij een product of project wordt de inhoudspagina gemaakt als die er nog niet is (de editor heeft haar nodig) | `api/admin/add-page-section.php` → `ContentBlockDrafts::open()` |
| Bewerken | De gewone editor, met bovenaan één regel "Nieuw blok. Het staat nog niet op de pagina…" en **Annuleren** | `admin/_block_editor.php` (`block_editor_draft_notice()`) |
| Opslaan | Het endpoint valideert zoals altijd. Pas daarna, als laatste schrijfactie **in de transactie van de opslag**, zet `ContentBlockDrafts::place()` het blok onderaan de lijst en haalt de draftrij weg. Faalt iets, dan rolt alles terug en blijft de redacteur met zijn invoer in de editor | elk `api/admin/update-<type>.php` |
| Annuleren | De inhoud gaat zoals bij verwijderen (bestanden, woorden, kindrijen, de rij), een Mediabibliotheek-item nooit. Een inhoudspagina die alleen voor dit blok gemaakt was, gaat mee. De lijst ziet er daarna uit als ervóór | `api/admin/discard-block-draft.php` → `ContentBlockDrafts::discard()` |
| Terugknop of tabblad dicht | Het blok staat nergens; `ContentBlockDrafts::purgeStale()` ruimt een draft na 48 uur op, bij de volgende blokkeuze én via de dagelijkse cronjob | `add-page-section.php`, `scripts/prune-content-block-drafts.php` |

**De opruiming, en de cronjob.** `purgeStale()` gooit een verlopen draft weg
zoals *Annuleren* dat doet: woorden, kindrijen (kaarten, reviews, items),
eigen uploads en de inhoudsrij, en een inhoudspagina die alleen voor dat blok
gemaakt was. Een Mediabibliotheek-item dat de draft koos blijft, en een
geplaatst blok raakt het nooit: dat heeft geen draftrij. De volgende
blokkeuze in het CMS ruimt al op, maar op een site waar wekenlang niemand een
blok toevoegt, doet dat niets. Zet daarom naast de andere onderhoudsscripts
een dagelijkse cronjob:

```
/usr/bin/php /home/<account>/domains/<domain>/public_html/scripts/prune-content-block-drafts.php
```

`--dry-run` telt alleen, `--limit=<n>` begrenst één run (standaard 500).
Tijdens een update wacht het script, zoals elk script onder `scripts/`
(`MaintenanceGuard::cliMayRun()`). Er gebeurt niets ergs als de cronjob
ontbreekt: een draft staat op geen enkele pagina.

**Waarom een draft en geen niet-opgeslagen editor.** Elke editor heeft een rij
nodig: de woorden staan per taal onder het rij-id in `block_translations`, en
kaarten, items, galerij-afbeeldingen en uploads hangen aan die rij. Twintig
editors en endpoints herschrijven naar een "nog-geen-rij"-modus was de grote
verbouwing; de koppeling uitstellen is de kleine. Een CMS-breed concept- en
publicatiesysteem is het nadrukkelijk niet.

**Waarom een tabel.** `content_block_drafts` (migratie 20261006100000) is het
enige bewijs dat een losse inhoudsrij een draft is. `place()` zet alleen zo'n
rij op een pagina, nooit een rij van een andere herkomst; `purgeStale()` vindt
alleen zo'n rij terug. `page_id` is een echte foreign key met CASCADE;
`PageService::delete()` en `ContentPages::deleteFor()` ruimen de inhoud van de
drafts van een pagina eerst zelf op.

**Wat `place()` bewaakt.**

- De draftrij wordt vergrendeld (`FOR UPDATE`): een dubbele klik of een
  vernieuwde POST plaatst het blok één keer; de tweede opslag vindt de
  `page_sections`-rij en werkt die gewoon bij.
- De pagina wordt vergrendeld: twee drafts die tegelijk geplaatst worden,
  krijgen niet dezelfde positie.
- De beschikbaarheid wordt opnieuw gevraagd (`SectionRegistry::availableForPage()`):
  een tweede draft van een type met een maximum van één (het
  Offerte-/contactformulier) wordt geweigerd, en de opslag faalt dan net als
  elke mislukte opslag.
- De positie is de onderkant van de lijst **op het moment van opslaan**.

**Welke blokken.** Elk blok met een eigen editor (`BlockDefinition::opensAsDraft()`,
standaard `true`). Niet: de Paginakop en de Homepage Hero (hun `create()`
schrijft de ene rij van hun pagina, geen nieuwe instantie die Annuleren kan
terugnemen) en een blok zonder editor (Productraster, Collecties), dat meteen
geplaatst wordt zoals vroeger en in de lijst oplicht (`added=`).

**Een bestaand blok** komt hier nooit langs. Annuleren of teruggaan in zijn
editor is weggaan zonder opslaan; `discard-block-draft.php` verwijdert alleen
een draft op die lijst, met dat type en die sleutel, en doet voor een geplaatst
blok niets.

### Na het opslaan: terug naar de lijst

Een geslaagde opslag in een blok-editor landt op de lijst waar het blok in
staat, met het blok genoemd:

| Blok staat op | Landt op |
|---|---|
| een pagina (ook een geneste) | `admin/page.php?id=<pagina>&saved=<id>#blok-<id>` |
| een product | `admin/product-form.php?id=<product>&tab=inhoud&saved=<id>#blok-<id>` |
| een project | `admin/portfolio-item.php?id=<project>&tab=inhoud&saved=<id>#blok-<id>` |

`ContentBlockAccess::afterSaveUrl()` leidt het adres af van de
`page_sections`-rij van het blok en de pagina daarvan: **er is geen
terugkeerparameter**, dus niets om te vervalsen of te misbruiken als open
redirect, en de lijst is precies de lijst waarvoor de opslag het recht al
controleerde. Wie een blok rechtstreeks opent (een link uit het mediagebruik),
landt dus ook op de lijst van de echte eigenaar. De lijst noemt het blok alleen
als `saved` een rij van díe lijst is (`content_blocks_saved_section()`).

Op de lijst: een succesmelding, het blok klapt open, licht één keer op en
draagt het label *Opgeslagen*; `admin-collapse.js` opent het tabblad en scrolt
ernaar (`data-admin-collapse-focus`). `admin/page.php` opent het tabblad
*Inhoud*; een inhoudspagina geeft `saved` door aan haar eigenaar.

Een **geweigerde** opslag (een validatiefout) blijft in de editor, met de
invoer terug, zoals altijd. Een rij die op geen pagina staat en geen draft is,
houdt het oude gedrag: terug naar de editor met `saved=1`.

**Een nieuw endpoint** doet twee dingen: in de transactie, als laatste
schrijfactie, `$placed = ContentBlockDrafts::place('<type>', $id);`, en na de
commit `header('Location: ' . ContentBlockAccess::afterSaveUrl($placed, $redirect));`.
De editor zet de terug-link in `block_editor_draft_notice('<type>', $csrfToken)`.
`Tests\Service\ContentBlockLifecycleContractTest` controleert beide voor elk
blok dat als draft opent.

## Een leeg blok herkennen

Een blok dat niets laat zien, draagt in de bloklijst een rustig label
*Leeg blok*, en opengeklapt de zin "Dit contentblok bevat nog geen inhoud en is
daarom niet te zien op de website." met een link naar de editor. Het is een
hulpmiddel in het CMS: niets wordt verwijderd, en de website verandert niet.

**Leeg is niet "geen tekst".** Elk blok beantwoordt de vraag zelf
(`App\Service\Blocks\InspectsContent::hasContent()`), vanuit zijn eigen
leesmodel en dezelfde regel als zijn partial: wat die partial niet zou tonen, is
leeg. `SectionRegistry::isEmpty()` vraagt het alleen aan een blok dat de
interface heeft, niet aan een blok dat in de paginabouwer verborgen is, en een
fout is nooit een waarschuwing.

| Blok | Heeft inhoud als |
|---|---|
| Tekstblok | een tekst met iets erin (een lege alinea telt niet), of een knop |
| Tekst met afbeelding | minstens één item |
| Kenmerken in kaartjes, FAQ, Stappenplan | een kop of minstens één item |
| Cijferbalk, Woordenband | minstens één item |
| Kaarten-carrousel, Hover kaarten grid | minstens één kaart |
| Contactkaart | een titel of een tekst |
| Oproep met knop | een titel of een knop |
| Mediabanner | een afbeelding of video |
| Detailsectie | een van zijn woorden, zijn afbeelding, een punt of een galerijafbeelding |
| Uitgelicht product | een product om te tonen |
| Formulier | een formulier dat getoond kan worden |
| Galerij, Projecten | items, **of** een bron die ze kan geven (`ItemGalleryContent::isConfigured()`): een dynamisch blok wordt op zijn configuratie beoordeeld, niet op wat de bron vandaag bevat |

Nooit beoordeeld, met reden (`ContentBlockLifecycleContractTest::NEVER_EMPTY`):
Witruimte (decoratief), Productraster, Collecties en Projectinformatie
(dynamisch), het Offerte-/contactformulier (toont altijd de contactkaart), de
Paginakop en de Homepage Hero.

**Een nieuw blok** implementeert `InspectsContent`, of komt met reden in
`NEVER_EMPTY`; de contracttest faalt op een blok dat geen van beide doet.

Nog niet gedaan: startwoorden die niemand aanpaste ("Nieuwe carrousel — pas
deze titel aan", de startkop van een Oproep met knop) tellen als inhoud.

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
  `DetailSectionContent::imageSlot()`: een focuspunt en sinds v0.1.14 een
  zoom (`image_zoom`), geen weergave,
  geen telefoonhoogte, geen telefoonafbeelding) met de gedeelde editor per
  rij, zonder telefoondeel: de strook op een telefoon toont hetzelfde vierkant
  met hetzelfde punt en dezelfde zoom. Opgeslagen op de galerijrij
  (`image_focus_x/y`, `image_zoom`),
  nooit op het bibliotheekitem of op het product, project of bericht: krijgt
  dat item later een andere foto, dan staat die meteen in beeld met het punt
  van hier. Het kader in de editor volgt de bron van de rij meteen, zonder
  opslaan: `admin/assets/gallery-source.js` vraagt de foto van een gekozen
  item aan `api/admin/linked-image-preview.php`, dat dezelfde live
  `LinkedImages::item()` gebruikt als de website (en het recht van de
  bloklijst controleert), en stuurt hem als `rm:picture`-event naar het
  kader. Het script noemt geen module. Een item dat een bezoeker niet ziet
  (inactief, concept, verwijderd, module uit) geeft geen foto en de regel
  *Dit item is nu niet openbaar …*; nooit een foto die alleen de beheerder
  zou zien. Een openbaar item zonder hoofdafbeelding: zie "Detailsectie 2.1".
  Er wordt geen pad opgeslagen.
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

## Detailsectie 2.1: afbeeldingsbron en inklapbare items

v0.1.14. Geen migratie: de opslag van 2.0 (`source_type` + `source_id` op
`detail_section_images`) was al genoeg; wat ontbrak zat in de editor en in de
weergave.

- **Wat een afbeeldingsbron is.** Per galerij-item één keuze,
  *Afbeeldingsbron*: *Mediabibliotheek*, of een soort item van de site
  (*Product*, *Portfolioproject*, en zolang de Blog aan staat *Blogbericht*).
  Alleen het paneel van de gekozen bron staat in beeld. Bij de
  Mediabibliotheek kies je zelf een afbeelding met alt-tekst; een gekoppeld
  item heeft verder niets nodig, er is geen tweede, verplichte afbeelding.
- **Waarom dat eerst misging.** Het formulier van `admin/detail-section.php`
  miste `data-nav-item-form`, de haak van `admin/assets/navigation-item.js`.
  Dat script stopte dus meteen, en álle panelen stonden tegelijk open: de
  mediakiezer ("Afbeelding*") én de lijst van elke soort. Wie een product koos
  maar de bron op *Afbeelding* liet staan, bewaarde een bibliotheekitem: het
  endpoint eiste een afbeelding, en een opgeslagen item linkte nergens heen.
  De architectuur zelf (`LinkedImages`, `LinkTargets`, het endpoint, de
  partial) was in orde. `DetailSectionTwoOneContractTest` bewaakt de haak.
- **Automatisch de hoofdafbeelding.** Een gekoppeld item toont bij elke
  weergave de hoofdafbeelding die zijn module aanlevert
  (`ModuleDefinition::linkedImages()`: de eerste productfoto, de hoofdfoto van
  het project, de uitgelichte afbeelding van het bericht). Er wordt alleen een
  bronreferentie bewaard, nooit een kopie of pad; krijgt het product een
  nieuwe hoofdafbeelding, dan staat die meteen op de website. In de editor
  toont het focuskader bij het kiezen direct de thumbnail uit de
  Mediabibliotheek (`api/admin/linked-image-preview.php`), zonder opslaan of
  herladen.
- **Automatische links.** Het adres komt van de bestemmingskiezer:
  `LinkTargets::href()`, dus de module zelf, in de taal van het verzoek.
  Een product linkt naar `/product.php?id=<id>` (in het Engels
  `/en/product.php?id=<id>`, `ProductSeo::publicPath()`), een project naar
  `/portfolio/<slug>` via `LocalizedUrl`. Niet openbaar (inactief, verborgen,
  concept), verwijderd of een module die uit staat: geen adres, en het item
  blijft weg; de rest van de sectie rendert gewoon. De partial zet één `<a>`
  om beeld en naam; het naamlabel is geen overlay die de klik tegenhoudt, en
  er zijn geen geneste links.
- **Een item zonder hoofdafbeelding.** `LinkedImages::item()` geeft een
  openbaar item ook zonder afbeelding terug (`image_path` `''`);
  `LinkedImages::resolve()` blijft alleen items mét afbeelding geven. De
  website toont zo'n item als hetzelfde vierkant met alleen zijn naam, nog
  steeds klikbaar (`.service-detail__gallery-item--name`), en nooit een
  `<img>` zonder bron. De editor zegt het met een waarschuwing
  (`gallery_source.no_picture`) en vraagt niets extra. Een bibliotheekitem
  waarvan het bestand weg is, laat de website weg.
- **Focus en zoom** zijn presentatie van het galerij-item, niet van de bron:
  opgeslagen op de galerijrij (`image_focus_x/y`, `image_zoom`), dezelfde voor
  een bibliotheekfoto en voor een gekoppeld item, en ze blijven staan als de
  bron een andere hoofdafbeelding krijgt. Geen telefoondeel: de strook op een
  telefoon toont hetzelfde vierkant met hetzelfde punt.
- **Inklapbare items.** Elk galerij-item is een inklapbare rij
  (`editor_row_open()` met `$collapse`, `PAGE-EDITOR.md`, "Inklapbare rijen"):
  "Item 3 — Product: Houten naambordje", "Item 1 — Mediabibliotheek: Foto 03".
  Opgeslagen items beginnen dicht; een nieuw item, een item met een melding en
  een enkel item beginnen open. De open/dicht-stand wordt per sectie
  onthouden op de sleutel van de rij (haar id), dus toevoegen of verschuiven
  opent nooit het verkeerde item. ↑, ↓ en *Verwijderen* staan buiten de
  inklapbare kop. De kopregel volgt de bron en de keuze op het scherm
  (`admin/assets/gallery-source.js`). De kenmerken blijven open rijen: twee
  korte velden, inklappen maakt dat niet overzichtelijker.
- **Een nieuwe bron aansluiten** (bijvoorbeeld Artikelen): de module levert
  een bestemming in `linkTargets()` (label, keuzes, `href`, `title`) en een
  afbeelding in `linkedImages()` (een callable `id => BlockImage`-array of
  null). Dan staat hij vanzelf in *Afbeeldingsbron*, met live afbeelding,
  naam, adres en de module-uit-afhandeling. Niets in de Detailsectie, de
  editor of `LinkedImages` noemt een module.
- **Levenscyclus.** Een nieuwe Detailsectie is een concept tot de eerste
  opslag (`ContentBlockDrafts`), ook met alleen een productitem; *Annuleren*
  laat niets op de pagina achter; na opslaan terug naar de lijst van de
  eigenaar. Geen uitzondering voor dit blok.

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
   `AdminAccessControlTest` (*Wie mag welke blokken beheren*). Een nieuw blok
   opent als draft: het endpoint roept in zijn transactie
   `ContentBlockDrafts::place()` aan en landt via
   `ContentBlockAccess::afterSaveUrl()` op de lijst; de editor zet zijn
   terug-link in `block_editor_draft_notice()` (*De levensloop van een nieuw
   blok*).
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
gewone sectieruimte en groeit met zijn woorden mee; een minimale hoogte kan
erbij (zie "De hoogte van het achtergrondvlak" hieronder).

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

**Niet in CTA 2.0**: een video- of diavoorstellingachtergrond, een vaste
hoogte (een minimale hoogte kwam er later bij, hieronder), vrije CSS of dekking, eigen kleuren, blokken binnen de oproep,
animatie-instellingen en paginathema's. Wie beeld of video met een bewust
gekozen hoogte wil, gebruikt de Mediabanner (hieronder).

### De hoogte van het achtergrondvlak

`db/migrations/20261007100000`. Een korte titel kan in een hoge, beeldvullende
oproep staan: de redacteur kiest een **minimale** hoogte voor het vlak dat de
achtergrond draagt, los van de hoeveelheid tekst. Het is nooit een vaste
hoogte. Staat er meer tekst in dan past, dan wordt het blok gewoon hoger:
niets wordt afgesneden, geen knop overlapt, niets loopt eruit.

| Kolom | Waarden (standaard eerst) | Wat het doet |
|---|---|---|
| `min_height` + `min_height_px` | `auto`, `compact`, `normal`, `tall`, `custom` (`HEIGHTS`); pixels 200–1000 (`MIN_HEIGHT_RANGE`) of `NULL` | Op een groot scherm. `auto` is zo hoog als de woorden, zoals elke oproep was. `compact`, `normal` en `tall` zijn minstens 320, 440 en 560 pixels (`HEIGHT_PX`). `custom` neemt de pixels, en alleen dan staan er pixels |
| `mobile_min_height` + `mobile_min_height_px` | `auto`, `text`, `compact`, `normal`, `tall`, `custom` (`MOBILE_HEIGHTS`); pixels 160–800 (`MOBILE_MIN_HEIGHT_RANGE`) of `NULL` | Op een telefoon (tot 640px, het breekpunt van het blok). `auto` volgt het grote scherm maar lager: `compact`/`normal`/`tall` worden 240/320/420 pixels (`PHONE_HEIGHT_PX`), een eigen hoogte hooguit 420. Zo wordt een hoge desktop-oproep geen onnodig lang telefoonblok. `text` is op een telefoon zo hoog als de woorden, wat het grote scherm ook kiest |

**Het contract.** De hoogte staat op de doos die de lagen draagt: de kaart,
of de `<section>` van een oproep over de volle breedte. Daar krijgt ze
`cta-height`, `cta-height--<keuze>` en `cta-height-phone--<keuze>`, en
`assets/css/blocks/cta-band.css` zet één `min-height` uit een custom property
(`--cta-min-height`, op een telefoon `--cta-min-height-phone`). Geen `height`,
geen `max-height`, geen eigen `overflow` (`CtaBandHeightTest` bewaakt dat). De
doos wordt een kolom die de woorden verticaal centreert in de ruimte die het
minimum laat. Een eigen hoogte is het enige getal uit de database dat CSS
wordt: een geheel getal binnen zijn bereik, gecontroleerd in het endpoint, in
`CtaBandContent::minHeight()` en nog eens in de partial, als één pixellengte in
één custom property. Een ongeldige opgeslagen waarde leest als `auto`.

**Met Responsive Media.** De achtergrond ligt op `inset: 0` met `object-fit:
cover` en vult dus vanzelf elk hoger vlak, zonder uitrekken en zonder lege
randen. Focuspunt en zoom staan op de `<img>` en veranderen niet mee: de
hoogte raakt geen enkele `background_*`-kolom. De focuskaders in de editor
krijgen wel de vorm van de gekozen hoogte (`1152 / max(400, hoogte)` en op een
telefoon `343 / max(480, telefoonhoogte)`), op de server en live in
`admin/assets/cta-band.js`, zodat het punt gekozen wordt op de vorm die de
pagina toont.

**Bestaande oproepen** krijgen `auto` op beide schermen, en `auto` op beide
print geen klasse en geen `style`: byte voor byte de oude markup. Knopstijlen,
overlay, tekstvlak en de draft-levensloop blijven zoals ze waren. De hoogte
staat in de kaart *Achtergrond*, ook zonder afbeelding (het vlak is dan de
themakleur), met de uitleg *Dit bepaalt de minimale hoogte van het blok.*

## Extra vormgeving (Contentblock Styling 1.0)

`db/migrations/20261008100000`. Eén gedeeld systeem waarmee een redacteur de
vormgeving van **één blokinstantie** kiest: achtergrond, randen, ruimte
rondom en een decoratief effect. Vroeger had de galerij twee
achtergronden, de carrousel een vaste zachte achtergrond, de cijferband een
vaste diepe band en de meeste blokken niets. Nu is er één paneel, één
opslagcontract en één stylesheet, en een nieuw blok (straks Reviews) sluit
met één regel aan.

### Opslag: op de instantie, in `page_sections`

Vijf kolommen op de eigen `page_sections`-rij van het blok, geen kolommen in
twintig inhoudstabellen:

| Kolom | Waarden (standaard eerst) | `BlockAppearance::` |
|---|---|---|
| `appearance_background` | `default`, `page`, `subtle`, `primary`, `secondary`, `transparent` | `BACKGROUNDS` |
| `appearance_border` | `default`, `none`, `top`, `bottom`, `both` | `BORDERS` |
| `appearance_border_tone` | `subtle`, `normal`, `accent` | `BORDER_TONES` |
| `appearance_spacing` | `default`, `compact`, `normal`, `spacious`, `extra` | `SPACINGS` |
| `appearance_decoration` | `none`, `sparks`, `glow`, `pattern` | `DECORATIONS` |

Twee Tekstblokken op één pagina hebben dus elk hun eigen vormgeving.
Verbergen, verslepen en verwijderen nemen de vormgeving mee, want het is
dezelfde rij. Kopiëren of dupliceren van een blok bestaat niet in het CMS.

**Eén lezer, drie controles.** `App\Service\Blocks\BlockAppearance` is de
enige plek die de lijsten kent:

- `validate()` in het endpoint weigert een onbekend woord en elk woord dat
  het blok niet ondersteunt, met een melding, en schrijft dan niets.
- `effective()` op de pagina leest een onbekend of niet-ondersteund
  opgeslagen woord als de standaard. Een rij die buiten het CMS om is
  gewijzigd, rendert zo toch alleen wat het blok kan dragen.
- `classes()` maakt van een woord een klassennaam. Uit de database of een
  request komt nooit een kleur, lengte of CSS-string.

### Capabilities: wat een bloktype kan dragen

Elk bloktype zegt het zelf, in `BlockDefinition::appearanceSupport()`, met een
`App\Service\Blocks\AppearanceSupport`. Standaard is dat `none()`: geen
paneel en geen verandering. De meeste blokken gebruiken
`AppearanceSupport::section()` (alles), eventueel met minder effecten. Er is
geen uitzondering op een bestandsnaam of bloktype buiten de definitie zelf.

| Blok | Achtergrond | Randen | Ruimte | Effecten |
|---|---|---|---|---|
| Tekstblok, Oproep met knop, Cijferband, Stappen | ja | ja | ja | bolletjes, gloed, patroon |
| Paginakop | ja | ja | nee (de kop heeft zijn eigen hoogte) | bolletjes, gloed, patroon |
| Tekst met afbeelding, Kenmerken, FAQ, Kaarten-carrousel, Galerij, Projecten, Hover kaarten, Detailsectie, Uitgelicht product, Formulier | ja | ja | ja | gloed, patroon |
| Mediabanner | ja | ja | ja | geen (het beeld ís het blok) |
| Lopende band (marquee) | ja | ja | nee (geen sectie, de hoogte zijn de woorden) | geen |
| Homepage-opening, Witruimte, Snelnavigatie, Contactformulier/-kaart, Projectinfo, Productgrid, Collectie-tegels | — | — | — | — |

**Waarom geen vallende bolletjes op elk blok.** Beweging achter een grid van
kaarten, foto's, vragen die open- en dichtklappen of een formulier concurreert
met wat de bezoeker daar doet: kijken, kiezen, klikken, typen. Een stilstaande
gloed of een patroon doet dat niet. Bolletjes zijn er voor blokken met
woorden waar het oog even op rust. De homepage-opening krijgt geen paneel: zij
heeft haar eigen bolletjes en laserlijnen al. De vaste en dynamische blokken
(contact, snelnavigatie, productgrid) hebben een vaste plek en een vaste
bovenruimte.

`Tests\Service\BlockAppearanceContractTest::SUPPORT` schrijft deze tabel op.
Een verandering is daarmee een besluit, geen toeval.

### Het paneel

In de bloklijst (`admin/_content_blocks.php`) heeft elke rij van een blok dat
iets ondersteunt, onder zijn knoppen, één inklapbaar paneel *Extra
vormgeving* (`admin/_block_appearance.php`). Het staat standaard dicht. De
samenvattingsregel zegt wat er gekozen is (*Standaard*, of bijvoorbeeld
*Subtiele achtergrond · rand alleen boven (subtiel) · Vallende bolletjes*).
Het paneel toont alleen de velden die het blok ondersteunt. Een pagina, een
product en een project delen daarmee één paneel, en geen blok-editor heeft
een eigen stylingkaart. Er is dus nooit een tweede, tegenstrijdig paneel.

Het formulier post naar `api/admin/update-block-appearance.php` met het
`page_sections`-id. Het endpoint volgt de vier guards: login,
`ContentBlockAccess::requireAnyForApi()`, POST en CSRF. Daarna zoekt het de
rij op (404 als die ontbreekt), en laat de pagina van die rij het recht
bepalen (`requirePageForApi()`: van een pagina, product of project). Een blok
zonder ondersteuning geeft 422. Een geweigerde waarde stuurt terug naar
`#blok-<id>` met de melding. Een opslag landt als na een blok-editor:
`?saved=<id>#blok-<id>`. Het paneel heeft een eigen knop *Vormgeving
opslaan*, en de opslagbalk van het scherm bewaakt het als elk ander
formulier: een wijziging maakt het scherm *Niet-opgeslagen*, weggaan
waarschuwt, en *Opslaan* in de balk verstuurt het paneel via hetzelfde
endpoint. Tot v0.1.14-rc stond het erbuiten (`data-no-dirty-track`), en ging
een niet-opgeslagen vormgeving zonder waarschuwing verloren.

**Levensloop.** Een nieuw blok is een draft zonder `page_sections`-rij
("De levensloop van een nieuw blok"). Het staat dus nog niet in de lijst en
heeft geen paneel. Pas na de eerste opslag heeft het een vormgeving, en die
begint op de standaard. Annuleren laat niets achter, want er is nooit iets
geschreven.

### Achtergrond

Alle kleuren zijn theme-tokens (`THEMING.md`). Ze volgen het actieve palet,
en binnen de `<main>` van een pagina met een paginathema dat thema. De header
en de footer liggen buiten de `<main>` en veranderen nooit mee.

| Keuze | Wat het wordt |
|---|---|
| Standaard | Het blok zoals het was, inclusief een eigen vaste achtergrond (`.bg-soft`, `.bg-forest`, de kaart van een oproep) |
| Websiteachtergrond | `--color-bg`, effen: de grondkleur van de pagina |
| Subtiele achtergrond | De zachte achtergrond van de site (het verloop van `.bg-soft`), zonder zijn lijnen (die zijn *Randen*) |
| Primaire themakleur | Een tint van de accentkleur (`--color-primary-rgb` op 0.14) over de grondkleur. Bewust geen volle vulling: tekst, links en de gevulde `.btn` zijn voor de grondkleur ontworpen en blijven zo leesbaar. Een volle vulling vraagt een `--color-on-primary`-tokenset voor tekst en knoppen die er nog niet is |
| Secundaire themakleur | `--color-surface`, het tweede vlak van het palet (de kleur van de kaarten). Het palet heeft geen aparte secundaire kleur, dit is de tweede kleur die het wel heeft |
| Transparant | Geen eigen achtergrond: de ondergrond van de pagina schijnt door |

Een keuze vervangt de achtergrond van de `<section>` van het blok, niet die
van kaarten erin. Een oproep als kaart houdt zijn kaart; bij een oproep over
de volle breedte is de sectie het vlak en wordt dat vervangen. De afbeelding
en overlay liggen daar gewoon overheen.

### Randen, ruimte

**Randen**: *Standaard* houdt de eigen lijnen van het blok (de lijnen van
`.bg-soft`, `.bg-forest` met zijn haarlijn, de lijn boven een Detailsectie of
een oproep over de volle breedte). *Geen*, *Alleen boven*, *Alleen onder* en
*Boven en onder* vervangen ze. *Randkleur*: *Subtiel* (`--color-line-soft`),
*Normaal* (`--color-line`), *Accentkleur* (`--color-primary`), steeds 1px.
Geen numerieke velden.

**Ruimte rondom** is alleen de verticale padding van de `<section>` van het
blok: *Compact* `--sp-5`, *Normaal* `--sp-7` (wat elke sectie al heeft),
*Ruim* `--sp-8`, *Extra ruim* `--sp-8 + --sp-5`, en op een telefoon zijn de
laatste twee één stap kleiner. De gaten tussen kaarten, items en foto's in
het blok veranderen niet. Een blok direct onder een paginakop houdt zijn
aansluitende bovenkant (de inline `padding-top:0` van `$tightTop` wint).

### Decoratieve effecten

Drie effecten, puur CSS (`assets/css/block-decorations.css`), zonder script,
bibliotheek of animatiecontroller per blok:

- **Vallende bolletjes**: het effect van de homepage-opening
  (`homepage-hero.css` `.spark`, `homepage-hero.js` met GSAP) nagebouwd als
  CSS-animatie. Het uiterlijk is hetzelfde: 4px, `--color-primary-bright`
  met gloed, oplichten, 24–54px vallen en uitdoven, rusten. Het zijn 14
  punten, en op een telefoon 6, zoals in de opening. De opening zelf is niet
  aangeraakt.
- **Zachte gloed**: twee stilstaande poelen van de accentkleur.
- **Stippenpatroon**: een fijn raster van accentstippen dat naar onder
  vervaagt.

**De laag.** `BlockAppearance::apply()` zet één element als eerste kind in de
root van het blok (`<div class="block-decor block-decor--…"
aria-hidden="true">`). Dat element ligt `absolute` over de sectie en is
geknipt op de sectie (`overflow: hidden`), dus niets steekt buiten het blok
en er ontstaat geen horizontale scroll. Het heeft `pointer-events: none`. De
sectie wordt een eigen stacking context (`isolation: isolate`). Daarin ligt
het effect op laag 1: boven de eigen achtergrond en afbeelding van het blok
(de foto van een paginakop, het beeld van een oproep), en onder de inhoud,
die via zijn `.container` op laag 2 staat. Links en knoppen blijven dus
klikbaar en liggen bovenop.

**Reduced motion**: niets beweegt, de bolletjes staan stil als vage punten.
**Laden**: `block-decorations.css` wordt alleen gevraagd op een pagina waar
een blok een effect toont, één keer, hoeveel blokken het ook hebben.

### Rendering: op de root, niet eromheen

`SectionRegistry::renderPage()` vraagt per blok `BlockAppearance::forSection()`.
Bij `null` (alles standaard, of niets ondersteund) rendert het blok direct,
zoals altijd: **byte voor byte de oude markup**. Anders buffert het de
uitvoer en zet `apply()` de klassen naast de eigen klassen van het
root-element (`<section class="bg-soft block-appearance
block-appearance--bg-page">`). Een eigen `style` (de hoogte van een oproep)
blijft staan. Er komt geen wrapper, zodat sibling-selectors
(`.rich-text-section + .rich-text-section`), ankers, reveal-groepen en de
stacking van blokken die zichzelf isoleren blijven werken. Rendert een blok
niets (verborgen, leeg), dan blijft het leeg: een onzichtbaar blok wordt
nooit een lege gekleurde band.

`BlockAppearance::collectAssets()`, vanuit `collectPageAssets()`, vraagt
`assets/css/block-appearance.css` alleen als een blok op de pagina een
vormgeving heeft. De regels zijn twee klassen breed
(`.block-appearance.block-appearance--bg-page`), zodat een keuze wint van de
oppervlakken van één klasse die een blok zelf heeft, ongeacht de volgorde van de
stylesheets.

### Voorrang en bestaande instellingen

1. **Standaard is het blok zelf.** Een vaste achtergrond of lijn van een
   blok blijft, tot iemand iets anders kiest.
2. **Een gekozen achtergrond vervangt de achtergrond van de sectie**, niet
   van kaarten, panelen of afbeeldingen erin. **Een gekozen rand** vervangt
   de lijnen boven en onder de sectie, niet de randen van kaarten.
3. **De eigen keuzes van een blok met een eigen betekenis blijven in de
   blok-editor.** Voorbeelden: de overlay en het tekstvlak van een oproep, de
   minimale hoogte, de breedte van een mediabanner, de hoogte en
   afbeeldingsweergave van een paginakop, de vorm van hover-kaarten. Ze gaan
   over de inhoud van het blok, niet over zijn plek op de pagina.
4. **De enige algemene achtergrondkeuze die een blok zelf had, is
   verhuisd.** Dat was de *Achtergrond* van de galerij en Projecten
   (`item_galleries.background`, `default`/`soft`). De migratie geeft een
   geplaatste galerij op `soft` *Subtiele achtergrond* plus *Boven en onder*
   in *Subtiel*: precies `.bg-soft`, dus dezelfde pagina. Haar eigen kolom
   gaat naar `default`. Het veld is uit beide editors weg. Een verzoek zonder
   het veld houdt wat er staat. Een galerij die nog een draft was, houdt haar
   eigen waarde, want zij heeft geen rij voor de nieuwe.

### Waarom de Kaarten-carrousel een andere achtergrond had

Een vaste klasse op de buitenste sectie:
`partials/section-card-carousel.php` print altijd `<section class="bg-soft">`.
Dat is een zacht verloop van de accentkleur rechtsboven, een witte waas en
lijnen boven en onder (`core.css`, `.bg-soft`). Het is geen blokinstelling
en geen afgeleide themakleur, en de binnenste carrouselcontainer heeft geen
achtergrond; alleen de kaarten zelf hebben `--color-surface`. *Standaard*
houdt dat. *Websiteachtergrond* of *Transparant*, met *Randen: Geen*, geeft
de carrousel de achtergrond van de omringende pagina.

### Een nieuw blok aansluiten (zoals Reviews)

1. Laat de partial één root-element printen, een `<section>` met de inhoud
   in een `.container` direct eronder, en niets als het blok leeg is.
2. Zet in de definitie `appearanceSupport()` met
   `AppearanceSupport::section()`, of met minder effecten.
3. Zet het type in `BlockAppearanceContractTest::SUPPORT`. Die test rendert
   het voorbeeld van het blok en controleert de root en de `.container`.

Meer is niet nodig: geen migratie, geen endpoint, geen veld in de editor en
geen CSS.

### Niet in Contentblock Styling 1.0

Vrije kleuren of CSS, numerieke randen, ruimte links en rechts, een volle
primaire vulling met omgekeerde tekstkleuren, eigen animatie-instellingen
(snelheid, aantal), kopiëren van vormgeving tussen blokken, vormgeving op een
draft, en de homepage-opening.

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

## Reviews

`db/migrations/20261009100000`. Ervaringen van klanten die een redacteur zelf
intypt: testimonials, beoordelingen, reacties. Categorie *Inhoud*,
voorbeeldvorm kop + kolommen. Het blok staat op gewone pagina's en op de
inhoudspagina van een product of project, zonder `owners` en zonder
`allowed_pages`.

| Bestand | Wat |
|---|---|
| `src/Service/Blocks/ReviewsBlock.php` | Definitie, woorden, voorbeeld (drie reviews), Extra vormgeving |
| `src/Service/ReviewsContent.php` | Het leesmodel, de gesloten lijsten en de vorm van één review (`review()`) |
| `src/Repository/ReviewsRepository.php` | `review_blocks` en `review_block_items` |
| `partials/section-reviews.php` | `render_section_reviews($content, $revealGroup)` en `reviews_figure()` |
| `admin/reviews.php` + `admin/assets/reviews.js` | De editor |
| `api/admin/update-reviews.php` | Het endpoint |
| `assets/css/blocks/reviews.css`, `assets/js/blocks/reviews.js` | De vier weergaven; de pijlen van de carrousel |

De tabellen heten bewust niet `reviews`: die naam blijft vrij voor een latere
Reviews-module (zie onder).

**Woorden**, per taal in `block_translations`. Van het blok: bovenlabel, titel,
introtekst en knoptekst (150, 255, 500, 150). Van een review: de tekst
(verplicht in de standaardtaal, 1500), de naam, het bedrijf of de
omschrijving, en de brontekst (elk 150, optioneel). Een naam is ook een woord:
een lege vertaling valt terug op de standaardtaal, dus een naam hoeft maar één
keer ingevuld te worden.

**Een review** heeft verder, gelijk in elke taal:

| Kolom | Waarden | Wat |
|---|---|---|
| `rating` | `NULL` (de start), 1 tot en met 5 | Sterren. Geen sterren is geen lege sterrenrij |
| `review_date` | `NULL` of een bestaande datum | Getoond als "12 maart 2026" in de taal van de pagina, in een `<time>` |
| `media_id` | `NULL` of een afbeelding van de bibliotheek | Een portret of representatieve foto, rond; de alt-tekst van de bibliotheek |
| `image_*` | Responsive Media, prefix `image_`, zonder fit en telefoonhoogte | Welk deel van de foto in de cirkel staat, met zoom en een eigen telefoonfoto |
| `source_url` | `NULL` of een webadres (`SafeUrl::SCHEMES_WEB`) of een pad van de site | De link van de bron, `rel="nofollow noopener noreferrer"`; zonder brontekst is de host de linktekst |
| `sort_order` | | De volgorde uit de editor |

Er is **geen label als "Geverifieerde aankoop"**, en het komt er ook niet: dit
CMS kan niet controleren of een review echt is.

**Het blok** kiest:

| Kolom | Waarden (standaard eerst) | Wat |
|---|---|---|
| `layout` | `cards`, `minimal`, `featured`, `carousel` | De weergave (hieronder) |
| `featured_item_id` | `NULL` of een review van dit blok | Wat *Uitgelichte review* toont. `NULL`, of een review die weg is: de eerste. Bewust geen foreign key (de reviews cascaden al van dit blok; een sleutel terug zou de twee tabellen naar elkaar laten wijzen) |
| `header_align` | `left`, `center` | Alleen de kop boven de reviews |
| `link_type`, `link_target_id`, `link_url` | `LinkChoice` | De optionele knop onder de reviews; *Geen knop* bewaart ook geen adres |
| `button_style_id` | `NULL` of een knopstijl | Button Styles 2.0, rol *secondary* |

### De vier weergaven

Eén review is altijd dezelfde `<figure>` (tekst in een `<blockquote>`, de
persoon in een `<figcaption>`). De weergave is een klasse op de `<section>`
(`reviews-section--<layout>`) en geeft die figuur een eigen karakter:

| Weergave | Karakter |
|---|---|
| **Reviewkaarten** (`cards`) | Kaarten in een raster: drie naast elkaar, twee onder 1000px, één onder 640px. Sterren bovenaan, dan de tekst, onderaan onder een haarlijn het portret, de naam en de omschrijving. Een accentstreepje boven op de kaart en een vage quote in de hoek. Een rij kaarten is zo hoog als de langste tekst; niets wordt afgesneden |
| **Minimalistisch** (`minimal`) | Geen kaart. Grote aanhalingstekens in de accentkleur, de tekst groot en gecentreerd in de displayletter, de naam achter een kort accentlijntje. Meerdere reviews onder elkaar, gescheiden door een haarlijn |
| **Uitgelichte review** (`featured`) | Eén review als blikvanger: een zacht verlopend paneel, een heel grote quote als achtergrondaccent, de tekst in de displayletter, het portret groot in een accentring ernaast (onder 760px erboven). Toont de gekozen review, anders de eerste; de andere staan dan niet op de pagina |
| **Reviewcarrousel** (`carousel`) | Kaarten naast elkaar in een strook die horizontaal scrollt en snapt: vegen op een telefoon, de pijlen of de pijltjestoetsen (de strook is focusbaar). De pijlen verschijnen pas als niet alles past en staan uit aan het eind; een regel "1–3 / 5" meldt de plek (`aria-live`). Nooit automatisch |

Alles komt uit het thema: het actieve kleurenpalet of het paginathema
(`--color-*`), de lettertypes van de Font Library (`--font-display` voor de
quotes), en de ruimte, afronding en schaduw van `core.css`. `ReviewsContractTest`
faalt op een eigen kleur of lettertype in `reviews.css`.

**De carrousel en het bestaande carrouselcontract.** De Kaarten-carrousel is
een draaiende 3D-ring met autoplay; die laden voor reviews zou zwaar en verkeerd
zijn. Reviews volgt het contract van haar platte strook (native scroll-snap,
vorige/volgende, `aria-roledescription`, geen bibliotheek) in een eigen klein
script (`assets/js/blocks/reviews.js`, zonder `requestAnimationFrame`). Minder
beweging: geen vloeiend scrollen. Een regio met een naam (de titel, anders
*Reviews*), elke review een groep "2 van 5".

**Toegankelijk.** Sterren zijn één element met `role="img"` en de naam "4 van
de 5 sterren"; de vijf sterren erin zijn decoratie. De quotes zijn
`aria-hidden`. Alles wat een redacteur typt gaat door `htmlspecialchars()`, een
regelovergang blijft (`nl2br()` na het escapen).

**Extra vormgeving**: achtergrond, randen, ruimte en alle drie de effecten.
Vallende bolletjes passen bij een rustige quote en een uitgelichte review, en
de kaarten zijn dicht, dus er beweegt niets achter de woorden. Achter de
carrousel laat `reviews.css` de bolletjes weg: een bewegende strook met
bewegende stipjes erachter is één beweging te veel; de editor zegt dat bij de
weergave. De editor heeft zelf geen achtergrond-, rand- of kleurinstelling.

**Performance.** `reviews.css` en `reviews.js` komen alleen op een pagina met een
Reviews-blok. Het script hoort bij het bloktype, zoals elk blokscript; zonder
carrousel vindt het niets en doet het niets. Een portret gebruikt de thumbnail
van de bibliotheek (480px aan de lange kant), `loading="lazy"` en de
afmetingen van het origineel, in een kader met een vaste maat: geen
layoutverschuiving.

**De editor** heeft vier kaarten: *Weergave* (een select met een schetsje van
elke weergave en één zin over de gekozen), *Kop boven de reviews*, *Reviews* en
*Knop onder de reviews (optioneel)*. Elke review klapt apart in met "Review 2 —
Peter" als kopregel, en "Review 3 — Anoniem" zonder naam
(`data-row-list-title-fallback`, `admin/assets/row-list.js`). Een review heeft
de tekst, naam, omschrijving, sterren (een select: *Geen sterren* tot *5
sterren*), een datum, de mediakiezer met de *Afbeeldingsweergave* in een rond
kader, de brontekst en het bronadres. *Deze review uitlichten* staat er alleen
bij *Uitgelichte review*; een nieuwe review kan de uitgelichte zijn (het
endpoint koppelt haar sleutel aan haar nieuwe id).

**Wat het endpoint controleert**: de vier guards; de weergave en de uitlijning
uit hun lijst; de tekst verplicht in de standaardtaal en de lengte van elk
woord; sterren leeg of 1..5; een bestaande datum; een bronadres zonder
`javascript:`, `data:`, `mailto:` of stuurteken; een foto van de bibliotheek
(geen video, geen onbekend id); de uitgelichte review als sleutel van een rij
op dít formulier (iets anders slaat geen keuze op); de knop via `LinkChoice`
met een knoptekst in de standaardtaal en een bestaande knopstijl. Een review-id
van een ander blok wordt nooit geschreven (`EditorChildList::fromRequest()`).

**Levensloop.** Een nieuw Reviews-blok is een draft tot de eerste opslag
(*De levensloop van een nieuw blok*); *Annuleren* ruimt de rij, de reviews en
hun woorden op (`SectionRegistry::discardContent()`, de reviews via `CASCADE`),
en een foto is daarna weer vrij.

### Later: een Reviews-module

Dit blok is handmatig. Een latere, optionele module kan reviews centraal
beheren (moderatie, een inzendformulier, imports). De route, zonder dat daar
nu iets van bestaat:

1. **Dezelfde vorm, een andere bron.** De partial kent alleen de vorm van
   `ReviewsContent::review()`: tekst, naam, omschrijving, sterren, datum, foto
   en bron. Een module levert die vorm uit haar eigen tabel `reviews`; de vier
   weergaven, de CSS en het script blijven zoals ze zijn.
2. **Eén keuze erbij op het blok.** Een migratie van de module (of van Core,
   als de keuze altijd bestaat) geeft `review_blocks` een kolom `source`
   (`manual`, de standaard, of `module`) plus wat de module nodig heeft om te
   selecteren (een categorie, een aantal, een minimum aan sterren). Staat de
   module uit, dan leest het blok `manual`: de handmatige reviews blijven er
   altijd, niets gaat verloren.
3. **Het leesmodel kiest.** `ReviewsContent::forSection()` vraagt bij `module`
   de reviews aan de module (een methode op haar definitie, gesloten lijst, net
   als `ItemGallerySources`) en valt terug op de handmatige als de module uit
   staat. Geen providerframework vooraf.
4. **De editor** toont bij `module` de selectie in plaats van de reviewlijst,
   met een link naar de module.

**Niet in Reviews 1.0**: automatische imports of externe API's (Google,
Trustpilot), een inzendformulier, moderatie, een knop per review, automatische
vertalingen, een eigen kleur per review, een carrousel die vanzelf draait, en
structured data (`Review`/`AggregateRating`): zelf ingetypte reviews over de
eigen organisatie horen volgens Google niet als rich result.

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

## Kaartweergave (Card Presentation 2.0)

`App\Service\Blocks\CardPresentation`, `db/migrations/20261010100000`. Hoe de
kaarten **binnen** een blok eruitzien, één keuze voor het hele blok. Het is
een gedeeld contract voor gewone contentkaarten (een beeld, een titel, een
korte tekst, een link of een knop), zodat niet elk blok zelf een
afbeeldingsverhouding, kolommen, padding en mobiel gedrag bedenkt.

| Waarde | In het CMS | Wat de bezoeker ziet |
|---|---|---|
| `default` | Standaard | De kaarten van het blok zoals ze altijd waren. Bij de galerij en Projecten: een 4/5-foto met de woorden bij hover, drie kolommen. |
| `compact` | Compact | Een vierkante, kleinere foto met de woorden er altijd onder, minder ruimte, vier kolommen (3 → 2 → 1 op een telefoon). |
| `wide` | Breed | Foto links, woorden rechts, twee kaarten naast elkaar, één vanaf 1100 px. Onder 600 px staat de foto boven de woorden. |

Er is bewust **geen vierde weergave "Beeldgericht"**: de standaardkaart van
de galerij is al de beeldgerichte kaart (grote foto, woorden bij hover). Een
vierde keuze zou hetzelfde twee keer tekenen.

### Wat een weergave wel en niet bepaalt

Een weergave bepaalt de kolommen die bij die weergave horen, de opbouw van de
kaart, de vorm en de uitsnede van het beeld, de ruimte rond de woorden en dat
de woorden altijd zichtbaar zijn. Bij Compact en Breed wordt de kaarttitel een
echte kop (`CardHeading::under()`), want de woorden staan dan vast in beeld en
verschijnen niet pas bij hover.

Een weergave bepaalt **niet** welke items er staan, hun woorden, waar een
kaart naartoe linkt, of een foto inzoomt, een publicatiestatus of een prijs.
Dat blijft van het blok en zijn bron. Een Portfoliokaart blijft dus in elke
weergave een zoombare foto met een aparte knop "Bekijk project"; een
collectiekaart blijft één link naar het product. Er komt nooit een link in een
link.

### Kaartweergave en Extra vormgeving

Twee lagen die elkaar niet raken:

- **Kaartweergave** gaat over de kaarten *binnen* het blok. Ze staat in de
  rij van het blok (`item_galleries.card_presentation`), in de editor van het
  blok, en de klassen staan op het grid en de kaarten.
- **Extra vormgeving** gaat over het blok *als geheel*: achtergrond, lijnen,
  ruimte en effecten, in `page_sections`, in het paneel in de bloklijst, en de
  klassen staan op de root-`<section>`.

Projecten met kaarten Breed en vormgeving Subtiel met een accentlijn werkt dus
zonder conflict. Kaartweergave heeft geen eigen blokachtergrond. Een kaart
heeft wel een eigen vlak (`--color-surface`), omdat dat bij een kaart hoort.

### De standaard blijft byte voor byte gelijk

Een blok zonder keuze (de kolom is `default`, ook na de migratie) krijgt geen
enkele extra klasse, geen extra element en geen stylesheet. Een onbekende of
lege waarde leest `CardPresentation::stored()` als de standaard.
`CardPresentationContractTest` vergelijkt de standaardweergave met de render
zonder waarde en met een onbekende waarde. Bij de bouw is de partial ook
byte voor byte vergeleken met die van v0.1.14, in zeven gevallen.

### Opslag, beveiliging en assets

- **Gesloten lijst.** `CardPresentation::ALL`. Het endpoint neemt alleen een
  waarde die het blok aanbiedt (`choiceFromRequest()`), houdt de opgeslagen
  waarde als het veld ontbreekt, en weigert al het andere met "Kies een
  kaartweergave uit de lijst.". Er komt nooit een klasse of stijl uit een
  request of de database: `CardPresentation::classes()` is de enige plek die
  de klassennamen maakt.
- **In dezelfde transactie** als de rest van de opslag
  (`ItemGalleryRepository::saveCardPresentation()`), dus een nieuw blok krijgt
  zijn weergave bij de eerste opslag mee, en een geannuleerde draft laat niets
  achter.
- **Eén stylesheet**, `assets/css/card-presentation.css`, eigenaar
  `CardPresentation`. `CardPresentation::collectAssets()` vraagt hem alleen op
  een pagina waar een blok Compact of Breed gebruikt, net als
  `BlockAppearance::collectAssets()`. Geen JavaScript, geen extra request per
  kaart. Alleen themavariabelen, zodat kleurenpalet, paginathema en Font
  Library gewoon doorwerken.

### Aangesloten blokken

| Blok | Waarom |
|---|---|
| Projecten (`project_cards`) | De eerste gebruiker. |
| Galerij, als Portfoliogalerij en Collectiegalerij (`item_gallery`) | Dezelfde rij, dezelfde partial en dezelfde kaart als Projecten. |

Beide zijn `PresentsCards` en bieden alle drie de weergaven aan. De
gerelateerde projecten op een projectpagina gebruiken dezelfde partial, maar
zijn geen blok: daar blijft de eigen gridkeuze (`PortfolioRelatedProjects::LAYOUTS`)
en de standaardkaart.

### Bewust niet aangesloten

| Onderdeel | Waarom niet |
|---|---|
| Hover-kaarten | De interactie (hover, beeldwissel) is het bloktype zelf, en het blok heeft al een eigen layoutmodel (layout, vorm, kolommen). |
| Kaarten-carrousel | 3D-orbit of rij met vaste kaartbreedte: een eigen interactie en een eigen beeldhoogte. |
| Reviews | Een testimonial zonder kaartbeeld, met vier eigen weergaven. |
| Productgrid, gerelateerde producten, Uitgelicht product | Commerce: prijs, varianten, de kaarten worden in de browser gebouwd. |
| Kenmerken in kaartjes (Feature grid) | Geen beeld en geen link. |
| Zoekresultaten | Een lijst, geen kaartgrid. |
| Collectietegels, het blogoverzicht | Wel contentkaarten, maar een eigen Shop-blok en een route. Kandidaten voor later. |
| De gewone fotolightbox van een projectpagina | Alleen foto's, geen kaarten. |

### Een blok aansluiten

1. Implementeer `PresentsCards` op de definitie: `cardPresentations()` (wat
   het blok aanbiedt, de standaard eerst) en `cardPresentation()` (de waarde
   van één geplaatste instantie, voor `collectAssets()`).
2. Bewaar de keuze in de rij van het blok, met `default` als standaard, en lees
   haar met `CardPresentation::stored()`.
3. Zet `admin_card_presentation_field()` (`admin/_card_presentation_field.php`)
   in de editor en `CardPresentation::choiceFromRequest()` in het endpoint.
4. Laat de partial `CardPresentation::classes($presentation, <deel>)` printen
   op het grid, de kaart, het beeldkader, de woorden, de titel en de tekst, en
   alleen als de weergave niet de standaard is.
5. Zet het type in `CardPresentationContractTest::CONNECTED` en schrijf een
   regressietest voor de standaardweergave van het blok.

Geen eigen kopie van de compact- of breed-CSS in de stylesheet van het blok.
Blokspecifieke details mogen daar wel blijven.

### Responsive Media

De kaarten van de galerij en Projecten tonen het beeld van hun bron met een
gewone `<img loading="lazy">`. Focus en zoom van Responsive Media horen bij
een plek op een blokrij (`ResponsiveImageSlot`), niet bij een item uit een
bron, dus deze kaarten hebben geen focusdata. Alle weergaven gebruiken dezelfde
bron met `object-fit: cover`, een vaste verhouding van het kader (geen
verspringende layout) en kopiëren of veranderen nooit een afbeelding. Een
toekomstig blok dat wel een `ResponsiveImageSlot` per kaart heeft, print
`render_responsive_image()` binnen het beeldkader
`card-presentation__media`.

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
| Blok-editor of endpoint | `fast` → `blocks` (`PageBuilderSecurityTest` bewaakt de guards, `ContentBlockLifecycleContractTest` de draft en de terugweg) |
| Kiezen, annuleren, opslaan en terugkeren; de lege-blokwaarschuwing | `fast` → `ContentBlockLifecycleTest` en `ContentBlockLifecycleHttpTest` → `blocks` |
| De bestemmingskiezer, `LinkChoice`, `LinkTargets` of `SafeUrl` | `fast` → `blocks` (`DestinationPickerTest`, `Routing\SafeUrlTest`) → `modules` |
| Een knop, of de knopstijl van een blok | `fast` (`ButtonStyleBlocksTest`, `ButtonStyleCssTest`) → `blocks` (`ButtonStylesHttpTest`) |
| De kaartweergave van een blok (`CardPresentation`) | `fast` (`CardPresentationContractTest`) → `CardPresentationHttpTest` → `blocks` |
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
