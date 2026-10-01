# Vormgeving (theming)

Hoe de site eruitziet, en hoe je daar veilig iets aan toevoegt. Lees dit
samen met `PROJECT-MAP.md` (waar iets staat). Voor het modulesysteem zie
`MODULES.md`; vormgeving is **Core**, geen module.

## Twee dingen die je uit elkaar moet houden

| | Wie de site *is* | Hoe de site er *uitziet* |
|---|---|---|
| Klasse | `App\Service\SiteSettings` | `App\Service\Theme\ThemeSettings` |
| Tabel | `site_settings` | `color_palettes` (kleuren), `theme_settings` en `theme_font_roles` (eigen lettertype per rol, uit de Font Library), `button_styles` en `button_style_defaults` (knopstijlen) |
| Scherm | Instellingen | Instellingen → Vormgeving |
| Inhoud | naam, logo, tweede logo, favicon, deel-afbeelding, adres, KVK, e-mail-, factuurteksten, de footer-slotregel (de headerknoppen staan als navigatie-items in `nav_items`, de social profielen in `footer_social_links`) | kleurenpaletten (elk vijf kleuren, één actief), lettertypecombinatie, een eigen lettertype voor koppen en voor lopende tekst, knopstijlen (twee daarvan de standaard) |
| Terugzetten | nooit automatisch | één knop (kleuren van het actieve palet, lettertypecombinatie, eigen lettertypen per rol, knopvorm), en die raakt de linkerkolom niet aan; de Font Library zelf blijft staan |

Twee tabellen en niet één met een prefix, precies omdat "standaardvormgeving
herstellen" nooit een bedrijfsadres, een logo of een knoptekst mag meenemen.
`reset-theme-settings.php` kan `site_settings` niet eens bereiken. De
headerknoppen, de footer-slotregel en de social profielen horen daarom ook in
de linkerkolom, met een eigen scherm — zie `HEADER-FOOTER.md`. Een knop kiest
alleen tussen twee bestaande stijlen; hoe die eruitzien blijft van Vormgeving.

Er wordt niets gedupliceerd. Het themascherm toont de sitenaam omdat een
eigenaar hem daar zoekt, maar linkt door naar Instellingen; opslaan doet
het niet.

## Er is een derde: hoe het CMS zélf eruitziet

Alles hierboven gaat over de **website**. Hoe het **adminpaneel** eruitziet is
een eigen keuze, met een eigen klasse (`App\Service\AdminTheme`), een eigen
tabel (`admin_settings`) en een eigen kaart *Dashboard uiterlijk* op het
tabblad **Dashboard** van Instellingen. Een bezoeker ziet er niets van, en
"standaardvormgeving herstellen" op het themascherm raakt het niet aan.

Vier eerste-partij-skins en één thema met eigen kleuren, meer niet:
`default`, `classic`, `ocean`, `black` en `custom` (**Eigen kleuren**, zie
hieronder). Die lijst is gesloten. Een opgeslagen waarde die er niet in staat — een oude
rij, een handmatig bewerkte database, een verzonnen POST — wordt `default`,
niet een fout.

**`default` is het huidige adminpaneel, ongewijzigd.** Dat is de hele
compatibiliteitsafspraak: een installatie die niets kiest, en een installatie
van vóór deze stap, zien exact wat ze zagen. `:root` in
`admin/assets/admin.css` ís `default`; `[data-admin-theme="default"]` deelt
datzelfde blok in plaats van het te herhalen, zodat er niets uiteen kan lopen.
Die tweede selector is wél nodig: zonder hem zou een element dat zichzelf
`default` noemt binnen een pagina die Ocean draait alsnog blauw worden — precies
het geval van de vier voorbeeldschetsen op het instellingenscherm.

**Eén stylesheet, één set schermen.** Een thema verzet alleen de semantische
tokens bovenaan `admin.css`; het voegt geen selector, component, layout of
template toe. Zelfde sidebar, zelfde kaarten, tabellen, formulieren,
pagina-editor, blokkenkiezer, opslagbalk en modals in alle vier.

```css
:root{ --admin-bg: …; --admin-surface: …; --admin-accent: …; }  /* Default */
[data-admin-theme="ocean"]{ --admin-bg: …; --admin-surface: …; }
```

**Hoe het op de pagina komt:** elke adminpagina drukt
`\App\Service\AdminTheme::bodyAttribute()` af in zijn `<body>`, en verder
niets. Detectie gebeurt één keer, in die klasse.
`Tests\Service\AdminThemeContractTest` laat de suite vallen zodra een
adminscherm dat niet doet, zodra `admin.css` en de registratielijst het
oneens zijn over welke thema's bestaan, of zodra een thema een kleurtoken
vergeet en dus in Default zou terugvallen.

Statuskleuren blijven in élk thema rood, amber en groen: een fout die er niet
meer uitziet als een fout is een stijlfout, geen skin. Amber
(`--admin-warning`) betekent "nog niet af", zoals een pagina in Concept; dat
is geen fout en ook geen succes. Selectie, actieve staat en status leunen
nergens op kleur alléén: een statusbadge draagt altijd ook het woord.

De bouwstenen die op die tokens draaien — uitleg bij een veld, de help-knop,
de infobalk, zoekveld, select, checkbox, switch en bestandskiezer — hebben een
eigen handleiding: `ADMIN-UI.md`.

Een thema toevoegen is dus twee plaatsen: een sleutel in `AdminTheme::THEMES`
en één `[data-admin-theme="…"]`-blok in `admin.css` dat élk kleurtoken van
`:root` opnieuw zet.

### Eigen kleuren

`custom` is het enige dashboardthema waarvan de kleuren niet in `admin.css`
staan. De beheerder kiest er vijf: achtergrond, zijbalk, kaarten en vlakken,
tekst en accent (`AdminTheme::COLORS`). Ze komen als vijf rijen
`admin_theme_color_*` in `admin_settings`, en `bodyAttribute()` drukt ze af
als `--admin-custom-*` in een `style`-attribuut naast de themasleutel.

Het `custom`-blok in `admin.css` leidt **elk ander token** uit die vijf af met
`color-mix()`: randen en hover mengen vlak en tekst, gedempte tekst mengt
tekst en vlak, en de zachte accentvlakken zijn het accent met transparantie.
Twee vaste afspraken houden een vrije keuze leesbaar zonder te weten of hij
licht of donker is: tekst op een gevulde knop krijgt de **achtergrondkleur**,
en fout, nog-niet-af en gelukt zijn een vast rood, amber en groen dat naar de
tekstkleur toe getrokken wordt, dus lichter op een donkere ondergrond en
donkerder op een lichte. De afleiding staat één keer in CSS, en daarom kan de live preview
niet afwijken van de opgeslagen pagina.

- **Een kleur is zes hexcijfers**, in dezelfde vormen als de websitekleuren
  (`#` optioneel, drie cijfers worden zes). Opslaan met één ongeldige kleur
  wordt geweigerd en slaat niets op. Een kapotte opgeslagen rij valt terug op
  de Default-waarde, en een ontbrekende eigenschap op de `var()`-terugval in
  `admin.css`: dezelfde waarde. `AdminThemeContractTest` houdt die
  terugvalwaarden, `AdminTheme::COLORS` en `:root` gelijk.
- **Een vast thema kiezen laat de kleuren staan**, zodat terugschakelen naar
  Eigen kleuren de eerder gekozen kleuren terugbrengt. `reset()` wist ze wel.
- `color-mix()` is de enige browserfunctie die dit blok extra vraagt. Het is
  geen nieuwe ondergrens: `admin.css` gebruikt `:has()` al, en dat kwam later.

### Live preview

Op het tabblad Dashboard ziet de beheerder een keuze meteen
(`admin/assets/admin-theme-preview.js`). Een thema aanvinken zet
`data-admin-theme` op `<body>`; een kleur zet één `--admin-custom-*` op
`<body>` en op de schets van Eigen kleuren. Geen request, geen reload en geen
opslag in de browser: pas **Uiterlijk opslaan**, of de opslagbalk, schrijft.
Herladen of weggaan zonder opslaan toont vanzelf weer het opgeslagen thema,
want dat is wat de server afdrukt. Het formulier heeft `autocomplete="off"` en
het script zet bij het laden elk veld terug op de serverwaarde, zodat een
browser die formulierwaarden terugzet geen niet-opgeslagen keuze toont.

Niets hiervan is nieuw gereedschap. "Niet opgeslagen" is de bestaande
opslagbalk (`admin/_save_bar.php`, zie `PAGE-EDITOR.md`), en de kleurvelden
zijn de kleurcomponent van het themascherm van de website
(`.admin-theme-color`, `admin/assets/theme-admin.js`): het hexveld wordt
verstuurd, de native kleurkiezer ernaast houdt het bij.

## De negen instellingen

De vijf kleuren zijn die van het **actieve kleurenpalet** (`color_palettes`,
zie "Kleurenpaletten"); de lettertypecombinatie is een rij in
`theme_settings`; het eigen lettertype per rol staat in `theme_font_roles`
(zie "Font Library"); `button_shape` is de vorm van de twee standaard
knopstijlen (zie "Knopstijlen"), geen rij meer.

```text
primary_color           #C9A063   accent: knoppen, links, iconen, lijnen
on_primary_color        #1B140D   tekst óp een gevulde knop
background_color        #120D09   de ondergrond van elke pagina
surface_color           #1C150E   kaarten, één tint boven de ondergrond
text_color              #F5EFE4   lopende tekst en koppen
font_pairing            trirong-quattrocento
heading_font_family_id  ''        '' = het koplettertype van de combinatie
body_font_family_id     ''        '' = het tekstlettertype van de combinatie
button_shape            pill
```

Dat zijn exact de waarden die `assets/css/core.css` zelf declareert. Daar
draait het hele mechanisme op:

- **`core.css` is het standaardthema.** Een site die niets heeft gewijzigd
  slaat geen rijen op, krijgt géén `<style id="site-theme">`-blok, en rendert
  dus letterlijk zoals hij altijd deed.
- **Ontbrekende rij = standaard.** Een verse installatie is meteen coherent,
  en de publieke site blijft goed staan als de database onbereikbaar is. Voor
  de kleuren geldt hetzelfde op waarde: een actief palet dat gelijk is aan de
  standaard stuurt niets mee.
- **Herstellen is verwijderen** voor lettertype en knopvorm; de kleuren van
  het actieve palet worden de standaard. Andere paletten blijven staan.

Meldingskleuren (fout, gelukt, waarschuwing) zijn geen thema-instelling en
staan als vaste waarden in de stylesheets. Het adminpaneel heeft zijn eigen,
volledig losstaande tokens (`--admin-*` in `admin/assets/admin.css`) en
verandert nooit mee.

## Van instelling naar pixel

```text
admin/color-palette.php (kleuren)
  → api/admin/save-color-palette.php      ColorPaletteService::validate()
  → color_palettes                        één palet actief (activate-color-palette.php)
admin/theme.php (lettertypen, knopvorm)
  → api/admin/update-theme-settings.php   ThemeSettings::validate() + ::save()
  → theme_settings                        alleen wat iemand echt koos
  → theme_font_roles                      eigen lettertype per rol (FontLibrary::setSiteRole())

ThemeSettings::all()                      kleuren van het actieve palet + theme_settings + rollen

publieke pagina
  → App\Service\PageAssets::renderStyles()
      1. <style id="site-fonts">: @font-face van de Font Library-families die
         deze pagina gebruikt (anders niets), en de stylesheet van de
         combinatie zolang een rol die nog gebruikt (ThemeTypography)
      2. core.css + blok- en modulestylesheets
      3. <style id="site-theme">  — alleen wat afwijkt (ThemeCss)
  → partials/head-branding.php            theme-color + favicon
```

`ThemeCss::declarations()` levert per gewijzigde instelling het bijbehorende
token én de tokens die daarvan zijn afgeleid (`ThemePalette::dependencies()`).
Verander je alleen de achtergrond, dan komt de accentkleur niet mee.

## Afgeleide kleuren

Een beheerder kiest vijf kleuren; de stylesheets gebruiken er veel meer. Er
zijn twee soorten afleiding, en het verschil is waarom ze allebei bestaan.

**1. Alfa-afleidingen — puur CSS.** Randen, washes, zachte tekst en de glow
zijn `rgba()` bovenop een kanalen-triplet (`--color-primary-rgb`,
`--color-text-rgb`, …). Ze volgen een nieuwe kleur vanzelf, zonder server en
zonder buildstap, en `rgba()` werkt in elke browser die dit project bedient.

```css
--color-line:       rgba(var(--color-primary-rgb), 0.16);
--color-text-muted: rgba(var(--color-text-rgb), 0.70);
```

**2. Tint-afleidingen — PHP.** Een lichter accent, een hover-vlak, een
diepere ondergrond. Die zijn in CSS niet te schrijven zonder `color-mix()` of
relatieve kleuren, en dit project wil geen browserondergrens waar het niet op
kan testen. `App\Service\Theme\ThemePalette` rekent ze uit en `ThemeCss` zet
ze in het overrideblok. Voor het standaardthema draaien die formules nooit —
`core.css` heeft de exacte waarden al.

De formules staan **één keer, als data**: `ThemePalette::RECIPE`, per
afgeleide eigenschap een uitdrukking (`lighten`, `mix`, `channels` op een
gekozen kleur, een eerdere eigenschap of een vaste kleur). `derive()` voert
dat recept uit; `recipe()` geeft het aan de live preview van de
paletteneditor, die het met dezelfde drie bewerkingen uitvoert
(`MygdalaTheme.tokens()` in `admin/assets/theme-admin.js`). Zo kan de preview
geen tint tonen die de website niet krijgt. `ThemePaletteRecipeTest` pint de
uitkomst op die van v0.1.13 en houdt `dependencies()` gelijk aan wat het
recept leest.

Er is **geen aparte instelling** voor een rand, zachte tekst, een glow of een
hoverkleur, en die moet er ook niet komen. Klein instellingenoppervlak,
afgeleide rest.

## Nieuwe thema-instelling toevoegen

Dit recept geldt voor een instelling die geen kleur is (zoals lettertype en
knopvorm). Een nieuwe kleurrol staat in een palet en een paginathema en
vraagt dus wél een migratie: zie "Kleurenpaletten", laatste deel.

1. Zet de sleutel + standaardwaarde in `ThemeSettings::DEFAULTS`.
2. Voeg validatie toe in `ThemeSettings::normalise()` — een gesloten lijst of
   een strikt patroon, nooit vrije CSS.
3. Zet het token met diezelfde standaardwaarde in het `:root`-blok van
   `assets/css/core.css`. Beide moeten gelijk zijn, anders faalt
   `Tests\Service\ThemeSettingsTest`.
4. Koppel sleutel aan token in `ThemeCss::DIRECT` (of, als er tinten van
   afgeleid worden, in `ThemePalette::derive()` + `::dependencies()`).
5. Voeg het veld toe aan `admin/theme.php`.
6. Test in `tests/Service/ThemeSettingsTest.php`; is er opslag bij betrokken,
   ook `ThemePersistenceTest`.

Een migratie is er niet voor nodig: `theme_settings` is een key/value-tabel en
een ontbrekende rij betekent de standaard.

## Kleurenpaletten

De vijf kleuren van de website staan sinds Branding & Design 2.0 niet meer
los in `theme_settings`, maar in **kleurenpaletten**: benoemde sets van de
vijf kleuren, waarvan er **precies één actief** is. Het actieve palet ís de
kleur van de website. Elk ander palet is een ontwerp in wording dat geen
bezoeker ziet tot het wordt geactiveerd. Core, geen module.

### Globaal actief palet en paginathema

Twee begrippen die op elkaar lijken en het niet zijn:

| | Globaal actief palet | Paginathema |
|---|---|---|
| Wat | De kleuren van de hele website | De kleuren (en het lettertype) van de inhoud van één pagina |
| Hoeveel | Precies één actief, andere paletten bewaard | Nul of één per pagina |
| Geldt voor | Elke pagina zonder eigen thema, én de header, het menu, de footer en de cookiebanner van elke pagina | Alleen de `<main>` van die pagina: paginakop en blokken |
| Waar in CSS | `:root` (`<style id="site-theme">`, alleen wat afwijkt) | `main[data-page-theme="…"]` (`<style id="page-theme">`, de volledige set) |
| Opslag | `color_palettes` | `page_themes` + `pages.page_theme_id` |
| Klasse | `App\Service\Theme\ColorPaletteService` (Core) | `App\Service\PageThemes\PageThemeService` (module `page_themes`) |
| Scherm | Vormgeving, kaart *Kleurenpaletten*; `admin/color-palette.php` | *Paginathema's*; `admin/page-theme.php` |

De regels die daaruit volgen:

1. Er is één globaal actief palet.
2. Een gewone pagina zonder paginathema gebruikt het actieve palet.
3. Een pagina met een paginathema houdt haar eigen kleuren, welk palet ook
   actief is. Een ander palet activeren verandert geen enkel paginathema:
   een paginathema heeft zijn eigen vijf kleuren, geen verwijzing naar een
   palet.
4. Staat de module Paginathema's uit, dan valt zo'n pagina terug op het
   actieve palet. Weer aan: haar thema is terug (de keuze is bewaard).
5. Header en footer volgen nooit het paginathema van de huidige pagina: ze
   staan buiten `<main>`, op `:root`.

Een relatie tussen paginathema's en opgeslagen paletten ("maak een
paginathema van dit palet") is bewust niet gebouwd. Het kan later, zonder
de twee opslagen te vermengen.

### Het model

```text
color_palettes    id, name (uniek, max. 80), primary_color, on_primary_color,
                  background_color, surface_color, text_color (CHAR(7), #RRGGBB),
                  is_active (1 of NULL, nooit 0, UNIQUE-index), timestamps
theme_settings    font_pairing, button_shape   (kleuren staan er niet meer in)
```

Migratie `20261003100000_create_color_palettes`. `is_active` volgt het
patroon van `site_languages.is_default`: MySQL staat in een unieke index
willekeurig veel NULL's toe maar maar één 1, dus "hooguit één actief palet"
dwingt de database zelf af. "Minstens één" is de regel van het CMS: het
actieve en het laatste palet zijn niet te verwijderen. Een palet heeft geen
slug: het wordt nooit een selector of een URL, en de naam wordt alleen
ge-escaped getoond.

**De migratie neemt de huidige website over.** "Standaard" krijgt de kleuren
die de site op dat moment toont: elke opgeslagen kleurrij, genormaliseerd
zoals `ThemeSettings` dat doet (`#abc`, kleine letters en zonder `#` worden
`#AABBCC`), en de standaardwaarde voor een kleur die nooit gekozen is of niet
meer valideert. Dat palet wordt actief. Daarna gaan de vijf kleurrijen uit
`theme_settings`, pas nádat het palet bestaat, zodat een halve run de kleuren
nooit kwijt is. Een verse installatie krijgt "Standaard" met de
standaardwaarden. Het bewijs dat de site na de upgrade identiek rendert: op
een kopie met eigen kleuren gaf de homepage vóór (code van main) en ná
(nieuwe code, gemigreerd) hetzelfde document.

### Eén bron, één lezer

`ThemeSettings::all()` blijft de enige lezer voor alles wat een pagina
kleurt. De vijf kleuren komen van `ColorPaletteService::activeColors()`, per
kleur gevalideerd. Daardoor volgt elke bestaande afnemer het actieve palet
zonder van paletten te weten: `ThemeCss` (het `site-theme`-blok),
`ThemeCss::backgroundColor()` (de `theme-color`-meta), de standaardwaarden
van een nieuw paginathema, de installatiewizard en de preview van een
paginathema. Niemand leest `color_palettes` om een pagina te kleuren.

| Situatie | Wat de website toont |
|---|---|
| Actief palet gelijk aan de standaard | Niets extra: geen `<style id="site-theme">`, precies zoals vóór paletten |
| Actief palet met een eigen kleur | Die kleur en de tinten die ervan afhangen, in `site-theme` |
| Eén opgeslagen kleur kapot (met de hand bewerkt) | Voor díe kleur de standaard; de rest van het palet blijft |
| Geen actief palet (alleen door handwerk in de database) | De standaardvormgeving (`core.css`); Vormgeving meldt het |
| Tabel onleesbaar (nieuwe code vóór de migratie, bij een update) | De oude kleurrijen uit `theme_settings`, zodat de site tijdens een update zijn kleuren houdt |
| Database onbereikbaar | De standaardvormgeving, zoals altijd |

**Schrijven** loopt ook via dezelfde deur: `ThemeSettings::save()` met een
kleur schrijft in het actieve palet (dat doet de installatiewizard), een
lettertype of knopvorm in `theme_settings`. Is er geen actief palet, dan
wordt het oudste geactiveerd, of "Standaard" aangemaakt. `ThemeSettings::reset()`
("Standaardvormgeving herstellen") zet de kleuren van het actieve palet terug
en laat de andere paletten staan.

### Beheren

Vormgeving, kaart **Kleurenpaletten**, achter `settings.manage` zoals de rest
van Vormgeving. Per palet: kleurstalen, de naam, een badge *Actief* of
*Inactief*, en Bewerken, Activeren, Dupliceren en Verwijderen.

- **Nieuw palet** (`admin/color-palette.php` zonder id) begint met de kleuren
  van de website zoals die nu is, nooit met een palet uit de code.
- **Opslaan activeert nooit.** Een nieuw of inactief palet opslaan verandert
  niets wat een bezoeker ziet; de editor zegt dat bovenaan, en de melding na
  opslaan ook. Het actieve palet opslaan verandert de website direct, en ook
  dat staat er vooraf.
- **Activeren** (`api/admin/activate-color-palette.php`) vraagt om
  bevestiging en is atomair: in één transactie wordt het oude palet gewist en
  het nieuwe gezet (eerst wissen, want de unieke index staat maar één 1 toe),
  met de doelrij vergrendeld. Een onbekend id verandert niets.
- **Dupliceren** geeft "*naam* (kopie)", of "(kopie 2)", met dezelfde kleuren,
  niet actief, en opent de kopie om een eigen naam te geven.
- **Hernoemen** gebeurt in de editor. De naam is uniek (hoofdletters tellen
  niet mee).
- **Verwijderen** kan alleen voor een inactief palet dat niet het laatste is.
  Het actieve palet heeft geen verwijderknop en zegt waarom; een verzonnen
  POST krijgt de melding "maak eerst een ander palet actief", en ook de SQL
  weigert de actieve rij (`deleteInactive()`).

### De live preview

Links de kleuren, rechts een voorbeeld van de website
(`admin/color-palette-preview.php` in een frame): koppen, tekst met een link,
een primaire en een secundaire knop, een kaart, een formulierveld en een band
op de diepere ondergrond van de footer. Getekend met de echte `core.css`, het
lettertype en de knopvorm van de site, en het palet als volledige tokenset op
een `<main data-page-theme>`: hetzelfde pad als een paginathema. Eén
tokenmodel voor de website, een paginathema en deze preview.

- **Direct, zonder request.** Het frame heeft `sandbox="allow-same-origin"`
  en verder niets. `admin/assets/color-palette-admin.js` zet bij elke
  wijziging alle tokens rechtstreeks op die `<main>`: geen herladen en geen
  serververzoek per kleur. Het document zelf voert geen script uit (zijn
  Content-Security-Policy weigert elk script en elk formulier).
- **Geen tweede set formules.** De tinten komen uit
  `MygdalaTheme.tokens()` in `admin/assets/theme-admin.js`, die het recept
  uitvoert dat de pagina meekrijgt (`ThemePalette::recipe()`, zie "Afgeleide
  kleuren"). `ThemePaletteRecipeTest` bewaakt dat het script elke bewerking
  van het recept kent.
- **Niet opgeslagen blijft niet opgeslagen.** De opslagbalk
  (`admin/_save_bar.php`) toont *Niet opgeslagen* en waarschuwt bij
  weggaan; *Annuleren* gaat terug naar Vormgeving zonder op te slaan. Bij het
  laden zet het script elk veld terug op de opgeslagen waarde, zodat een
  browser die formulierwaarden herstelt geen niet-opgeslagen kleur toont.
- **Breed en smal.** Vanaf 1100 px staan instellingen en preview naast
  elkaar en blijft de preview in beeld tijdens het scrollen. Smaller komt de
  preview eerst, als een lage strook die bovenaan blijft plakken terwijl je
  door de kleuren scrollt.
- **Contrast**: dezelfde waarschuwing als bij een paginathema, uit Core
  (`ThemeColor::CONTRAST_PAIRS` en `::contrastWarnings()`), live bijgewerkt
  met dezelfde WCAG-formule. Een waarschuwing, geen weigering.

### Later uitbreiden: Font Library, Button Styles, Global Theme Engine

Nog niet gebouwd, wel voorbereid:

- **Font Library 1.0** (gebouwd, zie "Font Library") en **Button Styles 2.0**
  (gebouwd, zie "Knopstijlen") horen niet in een palet. Een palet is kleur, en `theme_settings` en
  `theme_font_roles` houden lettertype en knopvorm juist apart van het palet:
  een ander palet activeren verandert nooit een lettertype. Wordt het later
  een benoemde "stijlset" (kleur + letter + knop), dan komt die als eigen
  record naast `color_palettes`, niet als extra kolommen erin.
- De preview drukt al het lettertype en de knopvorm van de site af, en
  `ThemeSettings::all()` is al de enige lezer, dus een nieuwe bron hoeft maar
  op één plek aan te haken.
- Een nieuwe **kleurrol** is een migratie (een kolom op `color_palettes` én
  op `page_themes`), een sleutel in `ThemeSettings::COLOR_KEYS`/`DEFAULTS`,
  een token in `core.css`, `ThemeCss::DIRECT` en eventueel een regel in het
  recept. Het recept, de preview en een paginathema volgen dan vanzelf.


## Lettertypecombinaties

`App\Service\Theme\ThemeFonts` is een **gesloten lijst**. Per combinatie: een
sleutel, een label, de twee volledige font-stacks en één stylesheet-URL. Een
beheerder kiest een sleutel; niemand typt ooit een URL of een `font-family`.

Alleen de gekozen combinatie wordt geladen. De `@import` die vroeger bovenaan
`core.css` stond is hierheen verhuisd, dus een site op een andere combinatie
downloadt Trirong niet meer. De combinatie `system` downloadt helemaal niets.

Een combinatie toevoegen is één regel in `PAIRINGS`. Houd de lijst klein.

De combinaties blijven altijd bestaan, ook met een Font Library vol eigen
lettertypen: een combinatie is de **basis**, een eigen lettertype vervangt
per rol alleen wat de beheerder kiest (hieronder).

## Font Library (eigen lettertypen)

Een beheerder kan eigen lettertypen uploaden en ze gebruiken voor de koppen
en de lopende tekst van de website en van een paginathema. **Core**, geen
module: Vormgeving, tabblad **Lettertypen**, achter `settings.manage`. Er is
één bibliotheek; de website en de paginathema's kiezen er allebei uit, en
geen van beide heeft een eigen upload.

### Het model

```text
font_families      id, name (uniek, max. 80, alleen voor het CMS),
                   category (sans|serif: de terugvalstack),
                   source_url (optioneel, http/https: bron of licentie), timestamps
font_files         id, font_family_id (FK CASCADE), weight (100-900),
                   style (normal|italic), UNIQUE(family, weight, style),
                   format (woff2|woff|ttf|otf, uit de bytes),
                   file_name (gegenereerd, uniek), original_filename (alleen tonen),
                   byte_size, timestamps
theme_font_roles   role (PK: heading|body), font_family_id (FK RESTRICT)
page_themes        heading_font_family_id, body_font_family_id (NULL of FK RESTRICT)
```

Migratie `20261004100000_create_font_library`: nieuwe tabellen en twee
lege kolommen, **er wordt geen rij geschreven**. Elke site en elk
paginathema houdt dus precies zijn lettertypen, en een upgrade zet nooit een
eigen lettertype aan. Een verse installatie heeft een lege bibliotheek en
werkt daarmee volledig: dan is Lettertypen alleen de uitleg en de knop om er
een toe te voegen, en de keuze per rol staat niet eens op het scherm.

### Combinatie plus rol

Het oude model was één sleutel voor twee stacks. Het nieuwe voegt daar per
rol één optionele familie aan toe, zonder dat een bestaande sleutel iets
anders gaat betekenen:

| Rol | Token | Eigen familie gekozen | Niet gekozen (`''`/NULL) |
|---|---|---|---|
| Koppen | `--font-display` | `'mygdala-font-<id>', <terugval van de soort>` | het koplettertype van de combinatie |
| Lopende tekst | `--font-body` | idem | het tekstlettertype van de combinatie |

`App\Service\Theme\ThemeTypography` is die ene regel, voor de website
(`ThemeCss`) en voor een paginathema (`PageAppearance`). Er is geen
`if` per lettertype: een familie is data.

- **De website**: `ThemeSettings` kent twee extra sleutels,
  `heading_font_family_id` en `body_font_family_id` (`''` = de combinatie).
  Ze worden gevalideerd zoals elke thema-instelling (een getal van een
  familie die een bestand heeft, anders een fout), opgeslagen in
  `theme_font_roles` en gelezen door `ThemeSettings::all()`, dat de enige
  lezer blijft. "Standaardvormgeving herstellen" leegt de rollen; de
  bibliotheek blijft staan.
- **Een paginathema**: dezelfde twee velden, dezelfde validatie
  (`PageThemeService` gebruikt `ThemeSettings::validate()`), opgeslagen als
  kolommen van `page_themes`. `PageAppearance::fromTheme()` weigert een thema
  waarvan een familie niet bruikbaar is als geheel, zoals een kapotte kleur:
  nooit stilletjes een ander lettertype.
- **Een kleurenpalet** raakt geen lettertype: activeren, bewerken of
  terugzetten van een palet verandert niets aan de rollen.

### Laden

- `PageAssets::renderFontStylesheet()` drukt één `<style id="site-fonts">` af
  met de `@font-face`-regels van **precies de families die deze pagina
  gebruikt**: de twee rollen van de site en die van het paginathema, elke
  familie één keer. Twintig bewaarde families kosten een bezoeker niets
  zolang ze niet gekozen zijn (gemeten: tien ongebruikte families, nul extra
  verzoeken).
- Per familie staat elke variant erin; de browser downloadt alleen de faces
  die de tekst echt nodig heeft (een cursief bestand alleen voor cursieve
  tekst).
- `font-display: swap`: de tekst staat er meteen, in de terugvalstack van de
  soort, en wisselt als het bestand er is. Geen fontloader, geen script.
- De Google-stylesheet van de combinatie komt alleen zolang een rol die nog
  gebruikt. Gebruiken beide rollen een eigen familie, dan gaat er **geen
  enkel verzoek naar Google**.
- Ontbreekt een bestand op de schijf, dan geeft de browser een 404 en valt
  terug op de rest van de stack (een lettertype dat elk apparaat heeft). Het
  CMS toont bij die familie *Bestand ontbreekt*.

### Veilig tot in de CSS

Niets wat een beheerder typt komt in CSS. De familienaam in CSS is
`mygdala-font-<id>` (`FontLibrary::cssFamilyName()`), gewicht en stijl komen
uit de gesloten lijst `FontVariant`, de URL uit een gegenereerde naam die
`FontStorage::NAME_PATTERN` moet halen, het formaat uit de bytes.
`FontLibrary::fontFaceRule()` laat een met de hand bewerkte rij weg in plaats
van hem te repareren. De naam, de oorspronkelijke bestandsnaam en de
bronlink worden alleen ge-escaped getoond.

### Bestanden: welke, en hoe gecontroleerd

WOFF2 (aanbevolen), WOFF, TTF en OTF. Alle vier gebruikt een browser direct,
dus er wordt **niets geconverteerd** (geen executable, geen extensie die
Vimexx niet heeft). TTF is de reden dat het werkt voor een beginner: de ZIP
van Google Fonts bevat TTF-bestanden, geen WOFF2. Geweigerd: TrueType-
verzamelingen (`.ttc`), EOT, SVG-fonts en elk archief; een ZIP wordt nooit
uitgepakt.

`App\Service\Theme\FontFileInspector` controleert, in deze volgorde: een
echte upload, niet leeg, hooguit 5 MB; de extensie; het type dat de
**browser** stuurde mag niet iets anders zeggen (tekst, afbeelding, script,
archief: alleen een weigerlijst, want browsers sturen voor fonts van alles);
het type dat de **server** ziet (finfo) moet een font of onbekend binair
zijn; de container uit de bytes (`wOF2`, `wOFF`, een sfnt-versie) moet bij de
extensie passen; en de structuur: de eigen lengte en tabeltelling van de
header, elk tabelrecord binnen het bestand, de tabellen zonder welke een
font niet rendert (cmap, head, hhea, hmtx, maxp, name en outlines) en het
magische getal van `head`. Verder kan een server niet betrouwbaar zonder
Brotli; een font dat dit haalt en van binnen toch kapot is, weigert de
browser zelf (OTS) en de pagina valt terug.

Limieten: 5 MB per bestand, 50 MB voor de hele bibliotheek
(`FontLibrary::MAX_LIBRARY_BYTES`). Een familie opslaan is alles of niets:
één geweigerd bestand slaat niets op en elke reden komt terug, met de
bestandsnaam erin.

### Opslag en de updater

`assets/fonts/library/`, onder een gegenereerde naam (32 hex + het echte
formaat, `App\Service\Theme\FontStorage`). Installatiegegevens:
`App\Update\Ownership` noemt de map, dus de updater overschrijft, verwijdert
of verpakt hem nooit; `.gitignore` en `FreshSiteCopyPolicy` houden de fonts
van de ene site buiten git en buiten een nieuwe site. Het enige
releasebestand erin is `.htaccess`:

- `Require all denied`, behalve voor een gegenereerde fontnaam: een ander
  bestand in de map (een script) wordt geweigerd voordat een handler het
  ziet;
- de MIME-types expliciet (`font/woff2`, `font/woff`, `font/ttf`, `font/otf`);
- met `mod_headers`: `X-Content-Type-Options: nosniff` en
  `Cache-Control: public, max-age=31536000, immutable`. Dat kan veilig: een
  vervangen variant krijgt een **nieuwe** naam, dus geen cache houdt ooit
  het oude bestand vast.

Zelf gehost van de eigen site: geen verplicht verzoek naar Google, goed voor
privacy, beschikbaarheid en onafhankelijkheid. De website heeft geen eigen
Content-Security-Policy; de previewdocumenten beperken `font-src` niet.

### Beheren

Vormgeving heeft twee tabbladen, **Kleuren en stijl** en **Lettertypen**.

- **Lettertypen**: de licentiewaarschuwing, elke familie met haar naam in
  haar eigen letter, haar varianten, *In gebruik* of *Niet in gebruik* met
  wie haar gebruikt, *Bestand ontbreekt* waar nodig, en de ruimte die de
  bibliotheek inneemt. Daaronder de handleiding in vier inklapbare kaarten
  (`admin_font_help()`, zie hieronder).
- **Een familie** (`admin/font-family.php`): naam, soort (met of zonder
  schreef), bron of licentie, en de bestanden. Kies je meerdere bestanden,
  dan krijgt elk een eigen regel met een variantkeuze (voorgesteld uit de
  bestandsnaam: `Roboto-SemiBoldItalic.ttf` is *Halfvet cursief*) en een
  regel tekst in dat bestand, rechtstreeks uit de computer, vóór er iets is
  geüpload (`admin/assets/font-library-admin.js`, `FontFace` uit de bytes).
  Zonder script: één bestand, één variant.
- De varianten heten gewoon: Dun, Extra licht, Licht, Normaal, Medium,
  Halfvet, Vet, Extra vet, Zwart, en elk ook cursief (*Cursief* voor
  Normaal). Het gewicht staat er tussen haakjes achter voor wie het zoekt.
- Per variant **Vervangen** (nieuwe naam, oude weg) en **Verwijderen**. Een
  variant die er al is toevoegen wordt geweigerd met het advies Vervangen te
  gebruiken; twee bestanden voor één variant in één keer ook.
- **Voorbeeld**: lopende tekst, H1, H2, vet, cursief, cijfers en Nederlandse
  tekens, uit de opgeslagen bestanden via dezelfde `@font-face` als een
  pagina.
- **Kiezen**: op *Kleuren en stijl* → Typografie, onder de combinatie,
  *Lettertype voor koppen* en *Lettertype voor lopende tekst*
  (*Uit de lettertypecombinatie* of een familie), en in een paginathema
  dezelfde twee velden. Het voorbeeld ernaast is het previewdocument van de
  kleurenpaletten (`admin/color-palette-preview.php`), dat nu ook de
  lettertypen uit de query leest (`ThemeSettings::previewFonts()`, elke
  waarde gevalideerd zoals bij opslaan): geen derde preview-engine.
- **Verwijderen** kan alleen voor een familie die nergens gebruikt wordt.
  Anders zegt het CMS wie: "Dit lettertype wordt gebruikt door het
  website-thema (koppen); paginathema "Actie" (lopende tekst). Kies daar
  eerst een ander lettertype." De foreign keys (RESTRICT) weigeren ook.
  Het laatste bestand van een familie in gebruik kan niet weg. Wie een
  familie gebruikt vraagt Core aan elke geregistreerde module, aan of uit
  (`ModuleDefinition::fontFamilyUsage()`, alleen Paginathema's antwoordt),
  zodat Core geen module noemt. Verwijderen ruimt precies de bestanden van
  die familie op.

### De handleiding in het CMS

"Eigen lettertypen toevoegen" staat niet in een los document maar ís de
hulp op het scherm (`admin_font_help()` in `admin/_font_library.php`,
teksten `help.fonts.*` in de CMS-catalogus): *Hoe voeg ik een eigen
lettertype toe?* (Google Fonts in tien stappen, met de map `static`), *Wat
betekenen die bestanden?* (Regular, Bold, Italic, Variable, WOFF2, TTF/OTF,
niet elke familie heeft alles), *Kiezen en verwijderen* en *Font Awesome en
andere icoontjes*. Eén bron, dus hulp en handleiding lopen niet uiteen.

De licentiewaarschuwing staat altijd zichtbaar waar een bestand gekozen
wordt, in gewone woorden, zonder juridische claim: gratis downloaden is niet
automatisch vrij gebruik, een desktoplicentie is geen webfontlicentie, de
beheerder is verantwoordelijk. De optionele bronlink is alleen een geheugen.

### Font Awesome en een latere Icon Library

Font Awesome is een iconenset in de vorm van een font, geen tekstlettertype.
Uploaden levert geen icoontjes op (daarvoor zijn een tekenkaart en eigen
stijlregels nodig) en als kop- of tekstletter wordt tekst onleesbaar; de
handleiding zegt dat. In deze versie: geen iconenkiezer, geen
Font Awesome-bestanden meegeleverd, geen externe Font Awesome-CSS.

Een latere **Icon Library** hoort niet in `font_families`: een icoon is een
losse afbeelding met een naam, geen tekstrol. De nette route is een eigen
Core-onderdeel naast de Mediabibliotheek: SVG-iconen als media (door
`SvgSanitizer`), een gesloten set per bibliotheek met een sleutel per
icoon, en een iconenveld dat een blok via een sleutel kiest (nooit een
klassenaam of glyph-code uit een request). Een iconfont zou daarna hooguit
een tweede bron van zo'n set zijn, met een eigen tekenkaart, en nooit een
rol in de typografie.

## Knopvorm

`--button-radius`: de vorm van de standaardknop (zie "Knopstijlen"), een van
vijf waarden van `pill` (999px, de standaard) tot `square` (0). Alleen `.btn`
leest het token. Ronde icoonknoppen, labels, stappentellers, filterchips en
kleurstalen houden hun eigen vorm, want die vorm betekent iets — vervang die
niet mee.

## Knopstijlen (Button Styles 2.0)

Een **knopstijl** is een benoemd ontwerp voor knoppen: weergave, vorm,
grootte, kleuren, rand, schaduw, tekst, een pijl of icoon en wat er gebeurt
als je de muis erop zet. De website heeft er zoveel als de beheerder wil
(Vormgeving → **Knoppen**, `admin/theme.php`, tabblad `knoppen`), en een
knop in een contentblok kiest er één. Pas je een stijl aan, dan veranderen
alle knoppen met die stijl tegelijk: de keuze is een verwijzing, nooit een
kopie van het ontwerp.

### Vier dingen die je uit elkaar houdt

| | Wat | Waar | Geldt voor |
|---|---|---|---|
| Kleurenpalet | vijf kleuren, één actief | `color_palettes` | de hele website |
| Font Library | eigen lettertypen per rol | `font_families`, `theme_font_roles` | de hele website, of een paginathema |
| Knopstijl | het ontwerp van een knop | `button_styles`, `button_style_defaults` | een knop die hem kiest, en de standaardknoppen |
| Paginathema | vijf kleuren + lettertypen voor één pagina | `page_themes` (module) | alleen die pagina's `<main>` |

Een knopstijl bevat **geen kleuren van zichzelf** als hij themakleuren kiest:
hij zegt "primair", en welke kleur dat is beslist het actieve palet, of het
paginathema van de pagina waar de knop staat. Een **vaste kleur** (`#RRGGBB`)
blijft wat hij is, welk palet of paginathema er ook is. Het lettertype is
altijd `var(--font-body)` of `var(--font-display)` en volgt dus de Font
Library en een paginathema.

### Het model

```text
button_styles            id, name (uniek), appearance, shape, size, fill_color,
                         fill_gradient, text_color, border_width, border_color,
                         shadow, font_weight, font_role, uppercase, underline,
                         icon, icon_position, icon_gap, icon_motion,
                         hover_effect, hover_fill_color, hover_text_color,
                         hover_border_color
button_style_defaults    role (primary|secondary) → button_style_id, FK RESTRICT
<bloktabel>.…button_style_id   NULL = "Standaard", anders button_styles.id, FK RESTRICT
```

Elke waarde is een woord uit een gesloten lijst (`ButtonStyles::choices()`,
de kaarten in `ButtonStyleCss`). Een kleur is een themakleurwoord
(`ButtonStyleCss::COLORS`: `primary`, `primary_bright`, `primary_deep`,
`primary_wash`, `on_primary`, `text`, `text_muted`, `background`, `surface`,
`line_strong`) of een `#RRGGBB` na `ThemeColor::normalise()`. Een icoon is een
sleutel uit `ButtonIcons` (pijl rechts/links, punthaak, externe link, plus,
download, winkelwagen). Er is geen vrij CSS-, HTML- of SVG-veld, en een
klassenaam bevat alleen het id (`btn-style-<id>`).

De vorm is een gecontroleerde schaal van vijf: rechthoekig (0), licht
afgerond (`--radius-sm`), afgerond (`--radius-md`, de oude "Afgerond"),
sterk afgerond (`--radius-lg`) en volledig rond (999px, de oude "Pill"). Een
vrije pixelwaarde is er bewust niet: die breekt een knop sneller dan hij
helpt.

### Twee standaarden

- **Standaardknop** (`primary`): elke `.btn` zonder eigen keuze. Dat is een
  contentknop op "Standaard", én elke functionele knop: in winkelwagen,
  afrekenen, formulier verzenden, de cookiemelding, paginering, de
  headerknoppen.
- **Standaard tweede knop** (`secondary`): elke `.btn--ghost`, de rustigere
  knop naast een eerste (de tweede knop van de CTA en de Hero, de knop van
  kaarten, Contactkaart, Galerij, Uitgelicht product).

Twee rollen en niet één, omdat de website er vóór deze fase al twee had:
met één standaard zouden de twee knoppen van een CTA na de migratie gelijk
worden. Precies één stijl per rol, nooit geen: een standaard kun je niet
verwijderen, alleen vervangen.

### Van keuze naar pixel

```text
core.css  .btn{ --btn-bg … --btn-icon-shift }   = het ontwerp van vóór 2.0
          .btn--ghost{ --btn-… }                 = de tweede knop van vóór 2.0
          .btn{ background: var(--btn-bg); … }   leest alléén die properties

<style id="site-buttons">   (ButtonStyles::styleBlock(), door PageAssets na site-theme)
  .btn:where(:not(.btn--ghost, .btn--on-dark)){ verschil van de standaardknop }
  .btn--ghost{ verschil van de standaard tweede knop }
  .btn.btn-style-7{ alle properties }         één regel per gekozen stijl
```

- **Het verschil, zoals `ThemeCss`.** Een site waarvan de twee standaarden
  nog het meegeleverde ontwerp zijn en waar geen blok een stijl koos, krijgt
  geen `site-buttons`-blok: hij rendert zoals vóór 2.0.
  `ButtonStyleCss::LEGACY_PRIMARY` / `LEGACY_SECONDARY_OVERRIDES` en de
  regels in `core.css` zijn één model; `ButtonStyleCssTest` leest `core.css`
  en houdt ze gelijk.
- **Alleen wat gebruikt wordt.** Een stijl krijgt een regel als een knop hem
  koos (`ButtonStyleRepository::usageCounts()`, één UNION-query over alle
  knopkolommen). Twintig knoppen met dezelfde stijl delen één regel; een stijl
  die niemand kiest kost niets.
- **Declaratie op de knop zelf.** Een `var(--color-primary)` wordt opgelost
  op de knop, dus binnen `main[data-page-theme]` in de kleuren van het
  paginathema en in header en footer in die van het actieve palet. Geen
  eigen kleurlogica per blok.
- **De vorm van de standaardknop** blijft het token `--button-radius`
  (`ThemeCss`); `ThemeSettings`' sleutel `button_shape` leest en schrijft
  voortaan de vorm van beide standaarden (zie hieronder). Zo zijn er geen
  twee bronnen.
- **Iconen zonder markup.** Een icoon is een mask op `::before`/`::after`
  in de tekstkleur, met lege `content`: decoratief, nooit voorgelezen, en een
  centrale wijziging raakt geen HTML. De oude inline pijl
  (`<svg class="btn__arrow">`) staat alleen nog op knoppen zonder keuze, en
  een standaard met een eigen icoon verbergt hem, zodat een knop nooit twee
  pijlen toont.
- **Toestanden.** Hover is `--btn-hover-*` (omhoog, gloed, schaduw, lichter,
  en optioneel een eigen vlak-, tekst- en randkleur). Focus is de
  site-brede `:focus-visible`-ring: het model heeft geen `outline`, dus geen
  stijl kan hem uitzetten. `:disabled` en `.is-disabled` houden hun eigen
  regel. Onder `prefers-reduced-motion: reduce` beweegt er niets meer (ook de
  meegeleverde knop tilt dan niet meer op; alleen de kleur verandert).

### Een blok aansluiten

Een blok met een knop die een redacteur instelt krijgt:

1. een nullable `…button_style_id` op de rij (of de rij van het item) met
   `FOREIGN KEY … REFERENCES button_styles(id) ON DELETE RESTRICT`, in een
   migratie;
2. de kolom in `ButtonStyleRepository::SLOTS`, of voor een moduleblok in
   `ModuleDefinition::buttonStyleSlots()` (de Shop: `featured_products`);
3. in de editor `admin_button_style_field()` (`admin/_button_style_field.php`),
   in de groep van de knop zodat hij met "Geen knop" meeverdwijnt; de opties
   komen altijd uit de bibliotheek;
4. in het endpoint `ButtonStyles::choiceFromRequest()` (een formulier zonder
   het veld houdt wat er staat, `''` is Standaard, een vervalst id wordt
   geweigerd) en `ButtonStyleRepository::saveChoice()` in dezelfde transactie;
5. in de `*Content`-klasse de sleutel `button_style`
   (`ButtonStyles::storedChoice()`), ook in de lege structuur;
6. in de partial `ButtonStyles::classes($keuze, $oudeKlassen, $layoutKlassen)`:
   zonder keuze de oude klassen en de oude pijl, met keuze
   `btn btn-style-<id>` plus de layoutklassen;
7. het blok in `ButtonStyleBlocksTest::CONNECTED`.

Aangesloten: Oproep met knop (twee), Homepage Hero (twee), Tekstblok, Tekst
met afbeelding (per rij), Kaarten-carrousel (per kaart), Hover Cards (per
kaart), Detailsectie, Contactkaart, Galerij/Projecten (voetknop),
Uitgelicht product en Reviews (de optionele knop onder de reviews, rol
*secondary*; nooit een knop per review). Hover Cards zet de stijl op een `<span class="btn">`
binnen de kaartbrede link: die link heeft zijn eigen `::after` over de hele
kaart, en de kaart is wat aangewezen en gefocust wordt
(`hover-card-grid.css`). Geen knop van zichzelf en dus geen keuze: Page
Header, Mediabanner, Feature Grid, Stappen, Cijfers, Marquee, FAQ.

### Wat bewust de standaard volgt, zonder eigen keuze

Functionele knoppen (winkelwagen, afrekenen, formulier, cookies,
paginering, "terug"-links) zijn `.btn` of `.btn--ghost` en volgen dus de
twee standaarden, met hun eigen gedrag, `disabled`-toestand,
`aria`-labels en `.btn--sm`/`.btn--block`-maat. De headerknoppen houden
hun keuze eerste/tweede knop (`nav_items.button_variant`) en volgen zo de
standaarden. Met een eigen, betekenisvolle vorm en dus buiten het systeem:
de ronde icoonknoppen (zoeken, menu, winkelwagen-icoon, carrouselpijlen,
lightbox, aantal-stepper), filterchips, `.site-search__submit` en de
vaste "Bekijk project"-overlay van het blok Projecten.

### Beheren

Vormgeving → Knoppen toont elke stijl met zijn rol (Standaardknop,
Standaard tweede knop, Beschikbaar) en het aantal knoppen dat hem koos.
Nieuwe stijl, bewerken, dupliceren ("(kopie)", nooit standaard, door niemand
gekozen), als standaard instellen (met bevestiging: dat verandert de hele
website) en verwijderen. Verwijderen weigert een standaard ("maak eerst een
andere stijl de standaard") en een stijl in gebruik ("wordt gebruikt door N
knop(pen) … kies daar eerst een andere knopstijl"); de foreign keys weigeren
beide ook, voor een keuze tussen controle en delete. Alles achter
`settings.manage`, met de vier guards; een blokkeuze valt onder het recht
van het blok (`ContentBlockAccess`).

De editor (`admin/button-style.php`) zegt vooraf wat opslaan doet (een
standaard: de hele website; in gebruik: N knoppen; anders niets) en toont
alleen de velden die bij de gekozen weergave horen. Een kleur is
"Themakleur (volgt het palet)" of "Vaste kleur", met de kleurkiezer.

### De live preview

Rechts naast de instellingen, sticky op desktop, erboven op een telefoon:
`admin/button-style-preview.php` in een frame met `sandbox="allow-same-origin"`
en een CSP zonder scripts, getekend met de echte `core.css`, het actieve
palet, de lettertypen en het knopblok van de site. Normaal, muis erop,
toetsenbordfocus, uitgeschakeld, een lange tekst, en op een lichte en een
donkere ondergrond. `admin/assets/button-style-admin.js` zet bij elke
wijziging de `--btn-*`-properties op de voorbeeldknoppen, samengesteld uit
`ButtonStyleCss::recipe()` (dezelfde kaarten als de website): geen verzoek
per wijziging. "Muis erop" en "focus" zijn in
`assets/css/button-style-preview.css` getekend uit dezelfde properties die
`.btn:hover` en `:focus-visible` gebruiken.

### Knopvorm (de oude instelling)

De keuze "Stijl → Knopvorm" is weg; vorm en uiterlijk staan op Knoppen.
`ThemeSettings::get('button_shape')` bestaat nog als **façade**: hij leest de
vorm van de standaardknop, en `save(['button_shape' => …])` (de
installatiewizard) zet beide standaarden op die vorm, zoals een kleur via
`ThemeSettings` in het actieve palet landt. "Standaardvormgeving herstellen"
zet de vorm van beide standaarden terug op volledig rond. De migratie
`20261005100000` gaf "Primair" en "Secundair" de vorm die de site had en
verwijderde de rij uit `theme_settings`.

### Later: een Global Theme

Een toekomstig **Global Theme** ("stijlset": palet + lettertypen + knoppen)
hoeft dit model niet te dupliceren. Het is een record dat naar bestaande
dingen verwijst: een `color_palettes.id`, twee Font Library-families en twee
`button_styles.id`'s voor de rollen. Een thema activeren schrijft
`button_style_defaults` (en het actieve palet, en `theme_font_roles`); meer
standaardknoppen per thema (bijvoorbeeld een derde rol voor
"op een donkere foto") zijn een extra rolwoord in
`ButtonStyleRepository::ROLES` met een eigen `.btn--…`-klasse, nooit een
tweede CSS-generator. Nog niet gebouwd.

## Paginathema's

Een **paginathema** is een benoemde set van de vijf kleuren plus een
lettertypecombinatie die één gewone CMS-pagina mag gebruiken in plaats van
de vormgeving van de website: een actiepagina, een seizoenspagina. Het is
data, gemaakt op een eigen scherm, nooit een `if` op een paginanaam in code.

Het is een **module**: `page_themes` (*Paginathema's*,
`App\Module\PageThemesModule`), standaard uit (`MODULES.md`). De vormgeving
zelf blijft Core; de module bezit alleen de opslag, de schermen en de keuze
per pagina. Core drukt het resultaat af zonder de module te noemen.

### Het model

```text
page_themes            id, name (uniek), slug (uniek, [a-z0-9-]),
                       primary_color, on_primary_color, background_color,
                       surface_color, text_color (CHAR(7), #RRGGBB),
                       font_pairing (een ThemeFonts-sleutel),
                       heading_font_family_id, body_font_family_id
                       (NULL = de combinatie; anders een Font Library-
                       familie, FK RESTRICT; zie "Font Library")
pages.page_theme_id    NULL = de vormgeving van de website
                       FK naar page_themes, ON DELETE RESTRICT
```

Migratie `20261001100000_create_page_themes`. Elke bestaande pagina houdt
`NULL`. Nederlands en Engels delen één `pages`-rij, dus elke taal van een
pagina heeft hetzelfde thema. **Er is geen overerving**: een pagina onder
een pagina met thema krijgt de vormgeving van de website, tenzij ze zelf een
thema kiest. Alleen de eigen `page_theme_id` telt.

De knopvorm hoort er niet bij: dat blijft een keuze voor de hele site.

### Eén set regels, niet twee

Een paginathema kan niets uitdrukken wat de vormgeving van de website niet
kan, omdat het dezelfde code gebruikt:

| Wat | Waar |
|---|---|
| Een kleur valideren | `App\Service\Theme\ThemeColor::normalise()` — ook wat `ThemeSettings` gebruikt |
| De afgeleide tinten | `ThemePalette::derive()`, en de volledige kleurtokenset `ThemeCss::paletteDeclarations()` (ook die van een kleurenpalet) |
| De lettertypes | `ThemeFonts::isValidKey()` / `::pairing()` |
| De laatste controle op elke waarde | `ThemeCss::isSafeValue()` |
| Contrast | `ThemeColor::CONTRAST_PAIRS` / `::contrastWarnings()` (ook voor kleurenpaletten) |
| Een thema als geheel | `App\Service\Theme\PageAppearance::fromTheme()`: alles of niets |

Een opgeslagen rij wordt bij elke weergave opnieuw gevalideerd. Een rij met
één kapotte waarde (met de hand bewerkt: `r;}a{b:`) wordt **niet** half
toegepast: de pagina krijgt dan gewoon de vormgeving van de website.

### Van keuze naar pixel

```text
partials/page-head.php          PageThemeCss::declareForPage($page)
  → ModuleRegistry::pageAppearance($page)   alleen ingeschakelde modules
  → PageThemesModule::pageAppearance()      PageThemeService::appearanceFor()
PageAssets::renderStyles()
  1. lettertypes: die van de site + die van het thema, ontdubbeld
  2. core.css + blok- en modulestylesheets
  3. <style id="site-theme">   :root{…}, alleen wat afwijkt
  4. <style id="page-theme">   main[data-page-theme="<slug>"]{…}, de volledige set
template                        <main id="main" data-page-theme="<slug>">
```

Het blok van een paginathema bevat **de volledige set**: de vijf kleuren,
elke tint van `ThemePalette::derive()` en `--font-display`/`--font-body`.
Niet alleen het verschil, zoals het site-thema: binnen de `<main>` vervangt
het thema het palet, dus elke token moet daar opnieuw staan.

Alleen de templates die `partials/page-head.php` gebruiken (de zeven
paginatemplates en `admin/page-preview.php`) kunnen een thema krijgen.
`product.php` en `portfolio-detail.php` gebruiken die partial niet, en
`PageThemeCss::declareForPage()` weigert bovendien een rij met `owner_type`:
de inhoudspagina van een product of project krijgt nooit een thema. De
product- en projecteditors hebben ook geen keuze.

### Wat het thema kleurt, en wat niet

Het thema geldt voor de **inhoud van de pagina**: de paginakop en alle
blokken (achtergrond, tekst, koppen, links, knoppen, kaarten, formulieren).
De header, het menu, de footer en de cookiebanner blijven in de vormgeving
van de website: die zijn van de site, niet van één pagina, en een bezoeker
herkent de site eraan.

Drie regels in `core.css` maken dat mogelijk:

- **De alfa-afleidingen** (`--color-line`, `--color-text-muted`, …) staan in
  een eigen regel `:root, main[data-page-theme]{…}`. Een eigenschap die met
  `var()` uit andere tokens is opgebouwd, wordt één keer berekend op het
  element dat haar declareert; op `:root` alleen zou ze binnen een thema de
  kleuren van de site houden. `Tests\Service\PageThemeCssContractTest` faalt
  zodra een `var()`-token alleen op `:root` staat.
- **De ondergrond**: `body, main[data-page-theme]` delen de verlopen, de
  vaste achtergrond, de tekstkleur en het lettertype, net als de regel voor
  schermen tot 900 px.
- **De header krijgt zijn sluier vanaf de eerste pixel**:
  `body:has(> main[data-page-theme]) .site-header` deelt de achtergrond van
  `.is-scrolled`. De header houdt de kleuren van de site en zou anders
  transparant boven een ondergrond en een paginakop zweven waarvoor hij niet
  ontworpen is. Alleen de sluier: hij wordt bij scrollen nog steeds compact.

Een pagina zonder thema rendert **byte voor byte** zoals zonder de module:
geen attribuut, geen extra `<style>`, geen extra lettertype
(`PageThemesRenderingHttpTest` vergelijkt de hele pagina met de module aan en
uit). SEO verandert niet: titel, beschrijving, canonical, hreflang, sitemap
en robots weten niets van een thema. De `theme-color`-meta blijft die van de
site.

### Lettertypes

`PageAssets::renderFontStylesheet()` drukt één ontdubbelde set af: de
stylesheet van de combinatie van de site, en die van het thema als die anders
is en niet `system` (en zolang een rol hem nog gebruikt). De twee
preconnects komen één keer, ook als alleen het thema een webfont heeft. De
Font Library-families van site en thema staan samen in één
`<style id="site-fonts">`, elke familie één keer.

### Beheren

*Paginathema's* in de zijbalk, direct onder Vormgeving, met een eigen
permissie `page_themes.manage`. Het overzicht toont naam, kleurstalen,
lettertype, hoeveel pagina's het thema gebruiken, en Bewerken, Dupliceren en
Verwijderen.

- **Een nieuw thema begint als de vormgeving van de website**
  (`PageThemeService::defaults()`, dus het actieve kleurenpalet), nooit met
  een palet uit de code. Daarna staat het los: een ander palet activeren
  verandert het thema niet.
- **De slug** wordt van de naam gemaakt en uniek gemaakt (`-2`, `-3`); een
  beheerder typt hem nooit.
- **Het kleurveld** is het kleurveld van Vormgeving (`admin/_theme_color_field.php`,
  ook gebruikt door de installatiewizard).
- **Het voorbeeld** is een echte pagina: `admin/page-theme-preview.php` in een
  frame met `sandbox=""`, met `core.css`, de vormgeving van de site en het
  thema via dezelfde `PageThemeCss`. De editor herlaadt het met de waarden die
  nog niet zijn opgeslagen; elke waarde wordt gevalideerd zoals bij opslaan, en
  een waarde die zou worden geweigerd toont die van de site. De
  Content-Security-Policy van dat document staat geen script en geen formulier
  toe.
- **Contrast**: onder de kleuren staat een waarschuwing voor elk paar onder
  WCAG 4,5:1 (tekst op achtergrond, tekst op kaartvlak, tekst op primair,
  primair op achtergrond: `ThemeColor::CONTRAST_PAIRS`, gedeeld met de
  kleurenpaletten). Berekend in PHP (`ThemeColor::contrastWarnings()`) en
  live bijgewerkt door `admin/assets/page-theme-admin.js` met de formule van
  `MygdalaTheme.contrastRatio()`. Het is een waarschuwing: opslaan kan altijd.
- **Dupliceren** maakt "*naam* (kopie)", of "(kopie 2)", met een eigen slug.
- **Verwijderen van een thema in gebruik wordt geweigerd**, met de lijst van
  pagina's die het gebruiken en een link naar elke pagina. De database
  weigert het ook (RESTRICT). Er is geen "terug naar de site-vormgeving"
  als bijwerking van opruimen.

Een pagina kiest haar thema op het tabblad **Pagina** van de pagina-editor,
in de kaart Algemeen: *Standaard website-thema* of een thema, met een
kleurstaal. Dat veld is de bijdrage van de module
(`ModuleDefinition::pageSettingsSections()`, `App\Service\PageSettingsSection`)
en wordt alleen opgeslagen als de module aan staat én het veld is
meegestuurd. Een thema kiezen valt onder `pages.manage`.

### Module uit

Uitzetten kan in `.env` (`MODULE_PAGE_THEMES_ENABLED`) of op het scherm
Vormgeving, kaart *Onderdelen van de vormgeving* (voor elke module met
`switchableFromAppearance()`; een door de omgeving vastgezette module staat
daar vast en het endpoint weigert). Met de module uit:

- tonen alle pagina's de vormgeving van de website, dus het actieve
  kleurenpalet — geen attribuut, geen `page-theme`-blok, geen extra lettertype;
- verdwijnen de zijbalkregel, de schermen (niemand houdt
  `page_themes.manage`, ook geen Super Admin) en het veld in de pagina-editor;
- blijft alles bewaard: de thema's en de keuze van elke pagina. Weer aanzetten
  brengt ze terug.

## Extra vormgeving van een blok

Per blokinstantie kan een redacteur een achtergrond, randen en een
decoratief effect kiezen (`CONTENT-BLOCKS.md`, "Extra vormgeving"). Er is geen
kleurkiezer: elke keuze is een theme-token.

| Keuze | Token |
|---|---|
| Websiteachtergrond | `--color-bg` |
| Subtiele achtergrond | het verloop van `.bg-soft` op `--color-primary-rgb` |
| Primaire themakleur | `--color-primary-rgb` op 0.14 over `--color-bg` |
| Secundaire themakleur | `--color-surface` |
| Randkleur subtiel / normaal / accent | `--color-line-soft` / `--color-line` / `--color-primary` |
| Effecten | `--color-primary-rgb`, `--color-primary-bright`, `--color-primary-bright-rgb` |

De regels staan in `assets/css/block-appearance.css` en
`block-decorations.css`, nooit op `:root`. Ze lezen de tokens dus op het blok
zelf, binnen de `<main>`. Een actief palet kleurt ze mee. Een paginathema
kleurt ze op zijn eigen pagina, omdat `main[data-page-theme]` dezelfde tokens
opnieuw declareert. De header en de footer blijven erbuiten. *Primaire
themakleur* is bewust een tint: een volle vulling vraagt een tekst- en
knopset op `--color-on-primary`, en die bestaat nog niet.

## Reviews: vier weergaven, alleen tokens

Het blok Reviews (`CONTENT-BLOCKS.md`, "Reviews") heeft vier weergaven met
elk een eigen karakter, en geen enkele eigen kleur of eigen letter
(`assets/css/blocks/reviews.css`):

| Wat | Token |
|---|---|
| Sterren, aanhalingstekens, accentlijnen, de ring om een portret | `--color-primary` (via `--review-accent` en `--review-star` op de sectie) |
| Lege sterren | `--color-text-rgb` op 0.16 |
| Kaarten en het uitgelichte paneel | `--color-surface`, met een verloop op `--color-primary-rgb` |
| Randen en haarlijnen | `--color-line`, `--color-line-soft` |
| De quote bij Minimalistisch en Uitgelicht | `--font-display` (Font Library) |
| Namen, omschrijvingen, knoppen | `--font-body`, `--color-text`, `--color-text-muted`, `--color-text-faint` |
| Ruimte, afronding, schaduw | `--sp-*`, `--radius-*`, `--shadow-soft` |

Een actief palet en een paginathema kleuren het blok dus vanzelf mee
(`main[data-page-theme]` declareert dezelfde tokens). De achtergrond, de
randen en de effecten achter het blok zijn Extra vormgeving, niet van
Reviews. `ReviewsContractTest` faalt op een hexkleur, een `rgb()` met een
getal of een `font-family` zonder token in `reviews.css`.

## Kaartweergave: alleen tokens

De kaartweergaven Compact en Breed (`CONTENT-BLOCKS.md`, "Kaartweergave",
`assets/css/card-presentation.css`) hebben geen eigen kleur en geen eigen
letter:

| Wat | Token |
|---|---|
| Het vlak van een kaart, en bij hover | `--color-surface`, `--color-surface-hover` |
| Rand, en bij hover | `--color-line`, `--color-line-strong` |
| Kaarttitel | `--color-text`, de letter van de kop zelf (`--font-display`, Font Library) |
| Korte tekst | `--color-text-muted` |
| Toetsenbordfocus | `--color-primary-bright` |
| Een kaart zonder beeld | een verloop van `--color-surface-hover` naar `--color-surface-2` |
| Ruimte en afronding | `--sp-*`, `--radius-md` |

Een actief palet en een paginathema kleuren de kaarten dus vanzelf mee. Let
op bij het maken van een paginathema: kaarten staan op het *vlak*, dus een
thema met dezelfde kleur voor vlak en tekst maakt elke kaart onleesbaar, ook
de productkaarten. De achtergrond van het blok zelf is Extra vormgeving.
`CardPresentationContractTest` faalt op een hexkleur, een `rgb()` met een
getal of een `font-family` in `card-presentation.css`.

## Branding-afbeeldingen

Logo, tweede logo, favicon en deel-afbeelding blijven `site_settings`, en
worden sinds de Mediabibliotheek **gekozen** in plaats van geüpload: naast
elke `*_path` staat een `*_media_id` die naar een item in de bibliotheek
wijst (`MEDIA.md`). De Mediabibliotheek bezit de identiteit van het bestand;
`site_settings` bezit welk item het logo van déze site is. Ze staan
nadrukkelijk niet in `theme_settings`, om dezelfde reden als hierboven: de
standaardvormgeving herstellen mag het logo nooit meenemen.

`App\Service\Branding` bezit de kleine beslissingen eromheen: één
root-relatieve vorm, de tweede logo valt terug op de eerste, en het
`type`-attribuut van de favicon komt uit het bestand in plaats van het
hardgecodeerde `image/png` dat zestien templates droegen. Het is ook de
enige plek waar de overgangsregel staat: is er een media-item, dan wint dat;
anders het opgeslagen pad. Die terugval is dragend — een verwijzing naar een
item dat verdwenen is mag een logo niet leegmaken.

**Standaarden zijn leeg.** De code kent geen Van Veluw-logo meer als
fallback; migratie `20260909210000` heeft de huidige waarden van deze site als
echte rijen vastgezet vóórdat dat veranderde. Leeg betekent "deze installatie
heeft nog niets gekozen": de header toont dan de sitenaam als tekst, er komt
geen favicon-link en geen `og:image`.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

`fast` bevat `ThemeSettingsTest`, `ThemeRenderingTest`, `BrandingTest`,
`SiteIdentityTest`, `AdminThemeTest` en `AdminThemeContractTest` en heeft
database noch webserver nodig. `cms` voegt `ThemePersistenceTest` toe
(opslaan, gedeeltelijk opslaan, herstellen, en dat herstellen `site_settings`
niet aanraakt) plus `AdminThemePersistenceTest` (opslaan, terugvallen op
`default`, de eigen kleuren, en dat de twee vormgevingen elkaar niet raken). Zie verder
`TESTING.md`.

De paginathema's hebben hun eigen testklassen: `ThemeColorTest` (`unit`,
`fast`, `cms`), `PageThemeCssContractTest` (`contract`, `fast`, `cms`),
`PageThemesModuleTest` (`contract`, `fast`, `modules`),
`PageThemesAdminHttpTest` en `PageThemesRenderingHttpTest` (`modules`, eigen
`php -S`), `PageThemesApacheHttpTest` (`http`, `modules`) en
`PageThemesMigrationTest` (`migration`, `modules`). Zie `TESTING.md`.

De Font Library: `FontFileInspectorTest` (`unit`, `fast`, `cms`: elk
formaat, elke weigering, elke structuurregel, de bestandsnaam),
`FontLibraryCssTest` (`unit`, `fast`, `cms`: de `@font-face`-regel, de
stacks per rol, de combinaties ongewijzigd, injectie, een paginathema),
`FontLibraryTest` (`cms`: families, varianten, vervangen, opruimen, gebruik
en weigering, de foreign keys, de website, herstellen, paletten, een
paginathema, een ontbrekend bestand), `FontLibraryHttpTest` (`cms`,
`modules`: upload via het echte endpoint, guards, de pagina's, de previews,
Paginathema's aan en uit), `FontLibraryApacheHttpTest` (`http`, `cms`: MIME,
`nosniff`, cache, weigering in de map, 404) en `FontLibraryMigrationTest`
(`migration`, `cms`).

De kleurenpaletten: `ThemePaletteRecipeTest` (`unit`, `fast`, `cms`: het
recept, gepind op de uitkomst van v0.1.13), `ColorPaletteTest` (`cms`:
beheer, één actief, de website volgt, terugval, onveilige invoer),
`ColorPalettesHttpTest` (`cms`, `modules`: schermen, endpoints, guards, de
preview en Paginathema's aan en uit op echte pagina's) en
`ColorPalettesMigrationTest` (`migration`, `cms`). `ThemePersistenceTest` en
`SetupCompletionTest` bewaken dat een kleur via `ThemeSettings` in het
actieve palet landt en nooit meer in `theme_settings`.

De knopstijlen: `ButtonStyleCssTest` (`unit`, `fast`, `cms`: `core.css`
gelijk aan het meegeleverde model, het verschil van de standaarden, elke
weergave, vorm, rand, schaduw, hover en icoon, themakleur tegenover vaste
kleur, injectie, de resolver), `ButtonStyleBlocksTest` (`contract`, `fast`,
`blocks`: elk aangesloten blok met en zonder keuze, twee knoppen, een
repeater), `ButtonStylesTest` (`cms`: beheer, standaarden, weigering en de
foreign keys, validatie, de blokkeuze, gebruik, de knopvorm-façade, de CSS
van een pagina), `ButtonStylesHttpTest` (`cms`, `modules`, `blocks`: het
tabblad, de endpoints, guards, de preview, een CTA, een Tekstblok en een rij
van Tekst met afbeelding die kiezen, een centrale wijziging) en
`ButtonStylesMigrationTest` (`migration`, `cms`). `SetupCompletionTest`
bewaakt dat de knopvorm van de installatiewizard in de standaardknoppen
landt.

Raak je de stylesheets aan, controleer dan of de standaardvormgeving
onveranderd rendert: vergelijk `getComputedStyle` van élk element vóór en ná,
in dezelfde pagina, door de oude stylesheets als `<style>` in te voegen en de
`<link>`-elementen uit te zetten. Zet animaties en transitions eerst uit en
wacht op `document.fonts.ready`, anders meet je de homepage-animaties in
plaats van je wijziging.
