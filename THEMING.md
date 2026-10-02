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
| Terugzetten | nooit automatisch | één knop (kleuren van het actieve palet, lettertypecombinatie, eigen lettertypen per rol, knopvorm), en die raakt de linkerkolom niet aan; de Font Library zelf blijft staan, en het Global Theme (`active_theme`) ook |

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

- **`core.css` is de Core-basis**, de legacy-compatibele vormgeving. Een
  site die niets heeft gewijzigd slaat geen rijen op, krijgt géén
  `<style id="site-theme">`-blok, en rendert dus letterlijk zoals hij altijd
  deed. Het Global Theme `legacy` voegt daar geen stylesheet aan toe (zie
  "Global Theme"); `core.css` is een basis, geen Global Theme.
- **Ontbrekende rij = standaard.** Een verse installatie is meteen coherent,
  en de publieke site blijft goed staan als de database onbereikbaar is. Voor
  de kleuren geldt hetzelfde op waarde: een actief palet dat gelijk is aan de
  standaard stuurt niets mee.
- **Herstellen is verwijderen** voor lettertype en knopvorm; de kleuren van
  het actieve palet worden de standaard. Andere paletten blijven staan.

Meldingskleuren (fout, gelukt) zijn geen thema-instelling, maar wel tokens
(`--color-danger…`, `--color-success…`, zie "Presentatietokens"). Een palet
of paginathema rekent ze na, zodat ze op een lichte ondergrond leesbaar
blijven.
Het adminpaneel heeft zijn eigen,
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
         combinatie (en die van een paginathema) zolang een rol die nog
         gebruikt (ThemeTypography)
      2. de verzamelde stylesheets, in deze volgorde: core.css, de
         shellstylesheets van ingeschakelde modules, zoeken (als dat aan
         staat), de route, de blokken, Extra vormgeving, kaartweergave
      3. het Global Theme: één <link> op een vaste plek, of niets
         (`legacy`) — ThemeRegistry::active(), zie "Global Theme"
      4. <style id="site-theme">   — alleen wat afwijkt (ThemeCss)
      5. <style id="site-buttons"> — knopstijlen, alleen wat afwijkt
         (ButtonStyleCss)
      6. <style id="page-theme">   — het paginathema, op <main> (PageThemeCss)
      daarna in de pagina zelf: inline stijl op een element (bijvoorbeeld
      de focus of hoogte van een afbeelding)
  → partials/head-branding.php            theme-color + favicon
```

Elke laag wint van de lagen erboven. Het Global Theme wint dus van Core en
van elke blok- of modulestylesheet, en de eigen keuzes van de eigenaar
(palet, lettertypen, knopstijlen, paginathema) winnen van het Global Theme.

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

Eén alfa-afleiding staat óók in het recept: `--color-text-faint` (zie
"Presentatietokens"). `core.css` levert hem als `0.46` op het tekstkanaal; een
palet met een lichte ondergrond krijgt een hogere alfa, omdat dezelfde alfa
daar veel minder leest.

**2. Tint-afleidingen — PHP.** Een lichter accent, een hover-vlak, een
diepere ondergrond. Die zijn in CSS niet te schrijven zonder `color-mix()` of
relatieve kleuren, en dit project wil geen browserondergrens waar het niet op
kan testen. `App\Service\Theme\ThemePalette` rekent ze uit en `ThemeCss` zet
ze in het overrideblok. Voor het standaardthema draaien die formules nooit —
`core.css` heeft de exacte waarden al.

De formules staan **één keer, als data**: `ThemePalette::RECIPE`, per
afgeleide eigenschap een uitdrukking (`lighten`, `mix`, `channels`,
`readable`, `fade` op een gekozen kleur, een eerdere eigenschap of een vaste
kleur). `derive()` voert dat recept uit; `recipe()` geeft het aan de live
preview van de paletteneditor, die het met dezelfde vijf bewerkingen uitvoert
(`MygdalaTheme.tokens()` in `admin/assets/theme-admin.js`). Zo kan de preview
geen tint tonen die de website niet krijgt. `ThemePaletteRecipeTest` pint de
uitkomst op die van v0.1.13 en houdt `dependencies()` gelijk aan wat het
recept leest.

Er is **geen aparte instelling** voor een rand, zachte tekst, een glow of een
hoverkleur, en die moet er ook niet komen. Klein instellingenoppervlak,
afgeleide rest.

## Presentatietokens (Themes 2.0, fase 1A)

Naast de kleuren zijn er tokens voor de vorm en het karakter van de site.
Geen beheerder kiest ze, er is geen scherm en geen opslag: ze staan op
`:root` in `core.css` met de waarden van het standaardthema, zodat een
latere thema-laag (een ThemeRegistry) ze op één plek kan zetten. Een site
die niets verandert rendert exact zoals vóór deze tokens.

| Token | Standaard | Betekenis | Gebruikt door |
|---|---|---|---|
| `--border-width` | `1px` | gewone rand of scheidingslijn | elke `border…: … solid` in de publieke stylesheets |
| `--border-width-strong` | `1.5px` | sterke rand: velden, pillen, een benadrukt paneel | formuliervelden, taalkeuze, filterchips, stepper, CTA-kaart |
| `--radius-pill` | `999px` | volledig ronde uiteinden | tags, chips, badges, taalkeuze, stepper (niet `.btn`: dat is `--button-radius`) |
| `--color-shadow-rgb` | `0, 0, 0` | kleur van elke schaduw | `--shadow-soft`, `--shadow-lift`, header, submenu, kaart-hover, checkout |
| `--color-sheen-rgb` | `255, 255, 255` | het licht dat een vlak vangt | glans van `--surface-subtle` (`.surface-subtle` en *Subtiele achtergrond*), hover en open rij in het submenu |
| `--color-danger`, `-rgb` | `#E2685C` | het merkteken van een fout: rand, outline, was | ongeldige velden, foutmeldingen, verwijderknoppen |
| `--color-danger-text`, `-rgb` | `#F0897E` | foutmelding op de ondergrond of een lichte foutwas | `.form-error`, Shop-meldingen, personalisatie |
| `--color-danger-on-wash` | `#F5B4AC` | tekst op de foutwas | `.form-error-summary`, `.form-status--error` |
| `--color-success`, `-rgb` | `#78B482` | het merkteken van een geslaagde melding: was en rand | `.form-status--ok` |
| `--color-success-on-wash` | `#B7E0C0` | tekst op de succeswas | `.form-status--ok` |
| `--color-primary-text` | `#E4C78E` | het accent als tekst: prijzen, cijfers, links, labels, hovertekst | elke `color:` die het accent draagt (fase 1A.2) |
| `--color-text-faint` | `rgba(tekst, 0.46)` | stille, ondersteunende tekst die nog gelezen wordt: metaregels, notities, de footer | metaregels, hints, footer, winkelwagen, personalisatie |
| `--fw-heading`, `--fw-h1` | `500`, `400` | gewicht van de koppen, en van de h1 | `h1–h4`; `--fw-heading` ook de producttitel en de personalisatietitel, `--fw-h1` ook de titel van een bericht of artikel in een lijst (fase 1A.2) |
| `--tracking-heading` | `0.01em` | letterafstand van de koppen | `h1–h4` |
| `--eyebrow-weight`, `--eyebrow-tracking`, `--eyebrow-case` | `700`, `0.18em`, `uppercase` | karakter van het bovenkopje | `.eyebrow` (hoofdletters ook `.article-eyebrow`, `.personalizer__eyebrow`) |
| `--eyebrow-rule-display` | `inline-block` | het streepje vóór een bovenkopje (`none` laat het weg) | `.eyebrow::before` |
| `--hover-lift` | `1` | factor op elke hover-optilling van een kaart | feature-, product-, collectie-, blog-, hover-kaart, projectbeeld, productminiatuur |
| `--hover-zoom` | `1` | factor op elke zoom van een beeld in zijn kader | galerij, blogkaart, projectbeeld, projectgalerij, hover-kaart, feature-icoon |

Wat daaruit volgt:

- **Schaduwen** zijn opgebouwd uit `--color-shadow-rgb` en staan daarom in
  de regel `:root, main[data-page-theme]` (zie "Paginathema's").
- **Meldingskleuren volgen de ondergrond, alleen waar het moet.** Ze staan
  in het recept als `readable`: de geleverde tint blijft precies zoals hij
  is zolang hij op de ondergrond én op een kaart leesbaar is (een rand of
  outline 3:1, tekst 4.5:1, tekst op een was gemeten tegen die was). Is hij
  dat niet, op een lichte ondergrond, dan schuift alleen zijn lichtheid,
  tint en verzadiging blijven, tot hij het wel is. Geen aparte licht/donker-
  kleuren en geen instelling. Het standaardthema en een donker palet houden
  exact de waarden van `core.css`; een licht paginathema krijgt ze in zijn
  eigen blok, een licht websitepalet in het overrideblok (ze hangen af van
  `background` en `surface`). Vóór Themes 2.0 fase 1A.1 bleven ze op een
  lichte pagina de lichte tinten voor een donkere ondergrond: een fout-
  melding haalde daar 2.3:1, de tekst op de succeswas 1.3:1. Een palet
  waarin ondergrond en kaart tegengesteld zijn (licht en donker) kan niet
  op beide tegelijk voldoen; de paletteneditor waarschuwt daar al voor de
  tekst.
- **Het accent als tekst is `--color-primary-text`, niet
  `--color-primary-bright`** (fase 1A.2). `--color-primary-bright` is de
  highlight: verlopen, glinsteringen, de achtergrond van een accentvlak. Hij is per formule *lichter* dan het accent, en op een lichte
  ondergrond betekent lichter: vager. Een prijs, een StatStrip-cijfer of een
  link in lopende tekst haalde daar 1.6 tot 3.4:1. `--color-primary-text` is
  de highlight zolang die leest (het standaardthema en elk donker palet: exact
  `#E4C78E`, of wat het palet als highlight afleidt) en schuift alleen op een
  lichte ondergrond langs de lichtheid tot hij 4.5:1 haalt, gemeten op de
  ondergrond, een kaart, de footer (`--color-bg-deep`) en de accentwas
  daarover (een actief tabblad, een chip). Zo blijft de highlight vrij te
  kiezen, en is geen tekst afhankelijk van die keuze. Geen `color:` in een
  publieke stylesheet gebruikt nog `--color-primary-bright`.
- **De focusring en het label van een knop zijn ook geen highlight** (fase
  1A.3). De site-brede `:focus-visible`-ring en de eigen ringen van de
  blokken, de zoekfunctie, de lightbox, de Shop en de personalisatie
  tekenden in `--color-primary-bright`; op een licht palet haalde dat 1.9
  tot 3.3:1 tegen de ondergrond, onder de 3:1 die een focusindicator nodig
  heeft. Ze tekenen nu in `--color-primary-text`. Er is geen aparte
  focusrol: die zou de highlight zijn waar die leest en anders opschuiven
  tot hij leest, precies wat `--color-primary-text` al doet, en de 4.5:1
  daarvan dekt de 3:1 van een ring. Op het standaardthema en elk donker
  palet zijn de twee gelijk, dus daar verandert niets. De hovertekst van de
  tweede knop (`.btn--ghost`, en de stijl "Secundair") was op de accentwas
  1.8 tot 2.9:1 en is nu ook `--color-primary-text`; de recepttest meet
  precies die was. In een knopstijl betekent de kleur "Primair, lichter" als
  tekst- of hovertekstkleur daarom `--color-primary-text`
  (`ButtonStyleCss::TEXT_COLORS`); als vlak of rand blijft hij de
  highlight. Opgeslagen stijlen veranderen niet. `.btn--on-dark` houdt de
  highlight bewust: die knop hoort op een donkere band of foto, niet op de
  ondergrond waartegen `--color-primary-text` gemeten is (geen sjabloon
  gebruikt hem nu). Wat níet veranderd is: vier ringen in `--color-primary`
  (de submenuknop, de galerij en pijlen van de Detailsectie, de reviews) en
  de focusrand van een invoerveld. Die halen 3:1 zolang het accent zelf dat
  op de ondergrond doet; een licht palet met een licht accent (goud op
  crème: 2.8:1) haalt het niet, maar daar valt ook de rand van een gevulde
  knop weg. Ze naar `--color-primary-text` zetten verandert het
  standaardthema zichtbaar, dus dat is een eigen beslissing.
- **Zachte tekst houdt zijn contrast** (fase 1A.2). `--color-text-faint` is
  de tekstkleur op alfa `0.46`. Op het standaardthema is dat 4.2:1, op een
  lichte ondergrond met dezelfde alfa maar 2.7 tot 3.0:1. Bijna elk gebruik is
  tekst die gelezen moet worden (metaregels, hints, footerlinks, varianten in
  de winkelwagen), dus het token zelf hield zijn betekenis niet vast, niet de
  componenten. Het recept (`fade`) houdt `0.46` waar dat minstens 4:1 haalt
  op de ondergrond, een kaart en de footer, en verhoogt de alfa per honderdste
  tot het dat wel doet (een licht palet: rond `0.58`). Hij blijft stiller dan
  `--color-text-muted` (`0.70`). Het standaardthema en een donker palet met een
  bijna zwarte ondergrond houden exact `0.46`. Bewust geen 4.5:1: dat zou ook het standaardthema veranderen.
  Kleine tekst die écht AA moet halen hoort op `--color-text-muted`; dat is
  een keuze per component die het standaardthema zichtbaar verandert, en dus
  een eigen beslissing (de breadcrumb ging zo, zie `core.css`).
- **De glans volgt de tekstkleur.** `--color-sheen-rgb` staat in het recept
  (`ThemePalette`, `['channels', 'text']`): een ander palet of een
  paginathema krijgt de kanalen van zijn tekstkleur, dus op een licht thema
  wordt de glans een lichte schaduw in plaats van onzichtbaar wit. Het
  standaardthema houdt het witte van `core.css`.
- **Het submenu** is een paneel en heeft dus `--color-surface`, niet meer
  `--color-on-primary` (dat is de tekst óp een primaire vulling). Met een
  licht palet en een licht accent werd het paneel donker en de tekst erop
  onleesbaar; dat is hiermee opgelost. In het standaardpalet scheelt het één
  stap per kanaal (`#1B140D` → `#1C150E`), niet te zien.
- **Hover** is een factor, geen afstand: elke kaart houdt haar eigen
  ontwerpafstand (8, 6, 4, 3 of 2 px; zoom 1.035 tot 1.07), `0` zet alles
  stil, `1.5` beweegt de helft meer. De knop heeft zijn eigen optilling in
  de knopstijl (`--btn-hover-lift`) en volgt deze factor niet.
- **Titels met een eigen gewicht** (fase 1A.2) nemen dat van een kop-token.
  De producttitel (een `h1` op de productpagina, een `h2` in Uitgelicht
  product) en de personalisatietitel wegen als een kop: `--fw-heading`. De
  titel van een bericht of artikel in een lijst (Blog-kaart, Artikelrij,
  "Meer lezen" boven gerelateerde berichten) weegt als de `h1` van zijn eigen
  pagina: `--fw-h1`. Een derde kopgewicht is niet nodig gebleken. Een thema
  met zware koppen (800/900) of lichte (300) verandert deze titels nu mee;
  lopende tekst, knoppen en bovenkopjes niet.
- **Vier schaduwen met een eigen recept** blijven bewust lokaal (fase 1A.2):
  de haarlijn onder de gescrolde header (`0 1px 0`, 0.3), het submenu
  (`0 12px 30px`, 0.35), de derde laag van de feature-kaart-hover
  (`0 10px 26px -12px`, 0.55) en het venster van de checkout-overlay
  (`0 20px 60px`, 0.45). Hun kleur volgt `--color-shadow-rgb` al; hun vorm en
  sterkte horen bij het component. Geen van de vier is zonder zichtbaar
  verschil te schrijven als `--shadow-soft` of `--shadow-lift`, dus ze
  reageren niet op een thema dat die twee zachter of harder zet. Dat is een
  geaccepteerde beperking, geen open fout: de vier zijn verschillende
  componentfuncties, en één globale dieptefactor zou ze kunstmatig aan
  elkaar koppelen. Een themastylesheet kan ze gericht aanpassen. Pas als een
  tweede echt thema laat zien dat dezelfde diepteregeling structureel nodig
  is, komt er een token.
- **Een paginathema** zet nooit een vorm-, rand-, schaduw- of
  bewegingstoken: alleen kleuren en lettertypen.
- **Wat bewust literal blijft**: kleuren op een foto of productbeeld (het
  bijschrift in een Detailsectie, het graveervoorbeeld van de personalisatie,
  de rand van een kleurstaal), de maskers van de blokeffecten, en de
  donkere achtergrond van de checkout-overlay. Losse lettergroottes (ruim 150)
  zijn niet getokeniseerd; alleen `--fs-*` bestaat.

`Tests\Service\ThemeTokenContractTest` (`contract`, `fast`, `cms`) houdt dit
vast: de standaardwaarden, geen letterlijke kleur buiten de tokens (op een
genoemde uitzonderingslijst na), geen statuskleur, randdikte of pil-radius
uitgeschreven, geen hover die met een vaste afstand beweegt, geen paneel op
`--color-on-primary`, een paginathema zonder vormtokens, geen tekst in
`--color-primary-bright`, geen focusring of knoplabel in de highlight,
leesbare accent- en zachte tekst op een licht palet met de geleverde waarden
op een donker, en de eigen titelgewichten op een kop-token.

## Oppervlakken (Themes 2.0, fase 1B-a)

Een **oppervlak** is de rol die de root van een blok (zijn `<section>`) op
de pagina speelt: gewoon op de ondergrond, zacht afgezet, of als band die
zich duidelijk onderscheidt. Het blok zegt welke rol. Hoe die rol eruitziet
(kleur, verloop, lijnen, textuur) bepaalt het thema. Er staat dus geen kleur
en geen merkstijl in de markup.

### Drie lagen

| Laag | Wie | Waar | Voorbeeld |
|---|---|---|---|
| Standaardoppervlak | het blok zelf, vast in zijn renderer | één klasse op de root: `surface-<woord>` | de Kaarten-carrousel print `surface-subtle` |
| Expliciete keuze | de redacteur, per blok, in *Extra vormgeving* | `block-appearance--bg-<woord>` in plaats van die klasse | *Websiteachtergrond* op de Cijferbalk |
| Uitvoering | het thema | `core.css` (het standaardthema), later een themastylesheet | het verloop en de lijnen van `surface-subtle` |

**Voorrang** (fase 1B-a.1):

1. een expliciete achtergrond in Extra vormgeving;
2. het standaardoppervlak van het blok;
3. de gewone ondergrond van de pagina of site.

Een expliciete achtergrond **vervangt het standaardoppervlak helemaal**, niet
alleen de vulling. `BlockAppearance::apply()` haalt de rolklasse
(`BlockAppearance::SURFACE_ROLES`) van de root zodra de achtergrond iets
anders is dan *Standaard*. Alles wat een thema op de rol tekent, valt dan
weg: lijnen, haarlijn, tekstkleur, `clip-path`, `::before`. Dat is markup,
geen CSS, dus er is geen resetregel per rol en per keuze.

- *Standaard* (opgeslagen `default`; een ontbrekende kolom, `NULL`, een lege
  of onbekende waarde leest ook zo) laat de rol staan.
- *Transparant* is een echte keuze: geen eigen vlak, en dus ook geen rol.
- Dezelfde keuze als de rol (*Subtiele achtergrond* op de Kaarten-carrousel)
  is ook een keuze: de rol gaat eraf, zodat een thema het standaardoppervlak
  van een blok en een keuze van de redacteur uit elkaar kan houden.
- *Randen*, *Ruimte rondom* en een effect laten de rol staan. Die winnen als
  twee klassen (`.block-appearance.block-appearance--…`) van de ene klasse
  van de rol, ongeacht de volgorde van de stylesheets. Een gekozen rand
  vervangt ook de haarlijn van `surface-contrast`.

`BlockAppearance` kent alleen de woorden. Welk blok welke rol heeft, staat in
de renderer van dat blok. Gerelateerde producten (Shop) hebben geen Extra
vormgeving en houden hun rol altijd.

### De woorden

Eén woordenlijst. Extra vormgeving had hem al (`BlockAppearance::BACKGROUNDS`);
fase 1B-a voegde `contrast` toe en fase 1B-c `emphasis`: twee woorden die
alleen een blok als standaard gebruikt, zonder CMS-keuze.

| Woord | Rol | Standaard van | Kies je in Extra vormgeving |
|---|---|---|---|
| `page` | de ondergrond zelf | — | *Websiteachtergrond* |
| `subtle` | zacht afgezet van wat erboven staat | Kaarten-carrousel, Feature grid met een kop, Gerelateerde producten, elke tweede Detailsectie, een galerij met de oude waarde `soft` | *Subtiele achtergrond* |
| `primary` | een was van het accent | — | *Primaire themakleur* |
| `secondary` | het tweede vlak van het palet | — | *Secundaire themakleur* |
| `contrast` | de band die zich het sterkst van de pagina onderscheidt | Cijferbalk | — (geen nieuwe CMS-keuze) |
| `emphasis` | de band die de lezer tot iets oproept: hij trekt de aandacht naar een actie | Oproep met knop over de volle breedte | — (geen nieuwe CMS-keuze) |
| `transparent` | geen eigen vlak | — | *Transparant* |

Een woord noemt de rol, nooit de uitvoering: `contrast` is in het
standaardthema donker, en in een licht thema mag het een rustige lichte band
zijn.

### Hoe het standaardthema ze tekent

- **`.surface-subtle`**: vulling `--surface-subtle` (een zacht verloop van
  het accent rechtsboven plus een glans van `--color-sheen-rgb`) en een lijn
  boven en onder in `--color-line-soft`. *Subtiele achtergrond* in Extra
  vormgeving is dezelfde vulling, zonder de lijnen (die zijn *Randen*). Op
  een blok met deze rol vallen de lijnen dus weg zodra *Subtiele
  achtergrond* gekozen is; *Randen* *Boven en onder* met *Subtiel* tekent
  precies dezelfde lijnen terug.
- **`.surface-contrast`**: vulling `--surface-contrast` (`--color-bg-deep`),
  tekst in `--color-text`, lijnen in `--color-line`, en een haarlijn van het
  accent bovenaan (`::before`). Een gekozen rand vervangt ook die haarlijn;
  een gekozen achtergrond neemt lijnen en haarlijn mee weg.
- **`.surface-emphasis`**: vulling `--surface-emphasis` (de kaartkleur
  `--color-surface` met een poel van het accent bovenin het midden), lijnen
  boven en onder in `--color-line-strong` op `--border-width-strong`, en een
  accentstreep bovenaan (`::before`, van 10% tot 90% van de breedte). Dat is
  precies de kaart van een oproep, op de sectie. Een gekozen rand vervangt
  de lijnen en laat de streep staan, zoals de oproep altijd deed; een gekozen
  achtergrond neemt alles mee weg.

`--surface-subtle`, `--surface-contrast` en `--surface-emphasis` zijn tokens in de regel
`:root, main[data-page-theme]`, omdat ze uit paletkleuren gebouwd zijn: zo
rekenen ze binnen een paginathema opnieuw met zijn kleuren. Een
themastylesheet die de vulling verandert, zet ze in diezelfde regel; alleen
op `:root` zou een paginathema ze weer terugzetten.

### Wat een thema kan, en wat niet

Een thema stijlt `.surface-subtle`, `.surface-contrast` en `.surface-emphasis`
(vulling, lijnen, `::before`, `clip-path`, padding) en kan op de rol de kleurtokens opnieuw
zetten (`--color-text`, `--color-primary-text`, …), zodat de inhoud meekleurt
zonder één componentselector. Bewezen met een tijdelijke stresstest (niet
gecommit): een ruw donker profiel en een rustig licht profiel veranderden de
vier blokken volledig, met alleen deze twee klassen en hun tokens.

Een thema hoeft niets af te vangen voor een gekozen achtergrond: die blokken
hebben de rol niet meer (zie *Voorrang*). Tot fase 1B-a.1 bleef de rol
staan, en hield een blok met *Websiteachtergrond* bijvoorbeeld de donkere
tekst en de `clip-path` die een thema op `.surface-contrast` zette. Bewezen
met dezelfde tijdelijke stresstest: na een gekozen achtergrond rekent geen
enkele eigenschap van zo'n blok nog met de rol; alleen *Subtiele
achtergrond* volgt de token `--surface-subtle` van het thema, zoals bedoeld.

Eén grens, bewust:

- **Een paginathema** bepaalt alleen kleur en lettertype. Het kiest geen
  oppervlak en krijgt geen oppervlak-instelling: de rollen volgen zijn
  kleuren vanzelf.

### Lay-out en oppervlak (fase 1B-c)

Een lay-outkeuze en een oppervlak staan los van elkaar. Het blok en zijn
lay-out bepalen de structuur, de rol bepaalt welke presentatierol de root
speelt, en het thema bepaalt hoe die rol eruitziet. Een componentklasse mag
dus blijven bestaan, maar draagt nooit als enige de identiteit van een vlak.

De **Oproep met knop over de volle breedte** was de laatste uitzondering.
*Volledige paginabreedte* verplaatst de kaart van de oproep naar de
`<section>` (CTA 2.0), en `.cta-section--full` droeg daarom zowel de lay-out
als het vlak. Nu print de partial beide, los:

```html
<section class="cta-section cta-section--full surface-emphasis">
```

| Was in `.cta-section--full` | Soort | Staat nu |
|---|---|---|
| `background` (accentpoel + `--color-surface`) | vlak | `.surface-emphasis`, via `--surface-emphasis` |
| `border-block` (sterk, `--color-line-strong`) | vlak | `.surface-emphasis` |
| `::before`: plaats, maat, verloop van de accentstreep | vlak | `.surface-emphasis::before` |
| `overflow: hidden`, `isolation: isolate` | lay-out | `.cta-section--full` (knipt afbeelding en zoom af, eigen stapeling) |
| `::before { z-index: 2 }` | lay-out | `.cta-section--full`: wat het vlak in `::before` tekent, ligt boven de afbeelding en een effect |
| `> .container { position: relative; z-index }` | lay-out | `.cta-section--full`, nu `z-index: 2` (was 1) |
| `.cta-band { padding: 0; border-radius: 0 }` | lay-out | `.cta-section--full`: de binnenste band is geen kaart meer |

**Waarom `emphasis`, en geen bestaand woord.** `contrast` is de band die
zich het sterkst van de pagina onderscheidt; het standaardthema tekent hem
donker (`--color-bg-deep`) en zet de tekstkleuren, en de Cijferbalk gebruikt
hem al. De oproep is iets anders: de kaartkleur met het accent erin, sterke
lijnen en een streep, een vlak dat naar een actie wijst. Op `contrast` zou de
oproep er in het standaardthema anders uitzien, of een combinatieregel
(`.cta-section--full.surface-contrast`) nodig hebben, en dat is precies wat
een thema niet moet hoeven kennen. `secondary` is het tweede vlak van het
palet; *Secundaire themakleur* in Extra vormgeving is alleen die kleur, en
dezelfde naam voor een vlak met poel, lijnen en streep zou de regel breken
dat hetzelfde woord in een rol en in Extra vormgeving hetzelfde betekent.
`emphasis` past ook buiten de oproep (een nieuwsbriefband, een
aanbiedingsband), en de kaart van een oproep heeft dezelfde identiteit.

**De stapeling.** `z-index: 2` op de container (was 1) zet de woorden altijd
boven alles wat een thema in `::before` van de rol tekent. Met 1 bedekte een
thema dat het hele vlak met `::before` vult de woorden (gemeten: 20 van 20
koppen). In het standaardthema overlapt niets van de woorden de streep van
2px bovenaan (gemeten op 1280, 768 en 375), dus het beeld is gelijk; met een
effect uit Extra vormgeving stond de container al op 2.

**Extra vormgeving** volgt de gewone voorrang: `surface-emphasis` staat in
`BlockAppearance::SURFACE_ROLES`, dus een gekozen achtergrond haalt de rol
eraf. Dat is de enige zichtbare wijziging van deze fase: een oproep over de
volle breedte met een gekozen achtergrond verliest nu ook zijn sterke lijnen
en de accentstreep, zoals elk ander blok met een rol sinds fase 1B-a.1
(zijn hoogte wordt 2px kleiner). De ontwikkeldatabases hebben geen enkele
oproep over de volle breedte; productie is niet nagekeken.

**De kaart** (`.cta-band--card` in `core.css`) blijft zoals hij is: een
kaart binnen de container, niet de root van het blok, getekend met tokens
zoals elke andere kaart. Zie *Open punten*.

Bewezen met een tijdelijke stresstest (niet gecommit): een ruw profiel
(gestreepte vulling, lijnen van 6px, `clip-path`, `::before` over het hele
vlak, eigen tekstkleuren) en een rustig licht profiel (lichte vulling, dunne
lijn, geen streep, geen schaduw), allebei alleen op `.surface-emphasis`,
veranderden elke oproep over de volle breedte en geen enkele kaart of
oproep met een gekozen achtergrond. Lay-out en plaats van de woorden bleven
gelijk (behalve dat een dikke rand ruimte neemt binnen een minimale hoogte),
en de woorden bleven bovenop. Op de oude code had hetzelfde profiel geen
enkel effect: daar moest een thema `.cta-section--full` kennen.

### Oude namen

`.bg-soft` en `.bg-forest` zijn de oude namen van `subtle` en `contrast`. Ze
staan nog als **alias** in dezelfde regels in `core.css` (en de haarlijnregel
in `block-appearance.css`), maar geen renderer print ze meer. Ze blijven
voor markup buiten Core die ze nog kan bevatten: opgeslagen HTML of een eigen
stylesheet op een bestaande installatie. De ontwikkeldatabase bevat ze niet;
de productiesites zijn niet nagekeken. Weghalen kan zodra dat op elke
installatie is nagekeken, uiterlijk samen met de eerste echte
themastylesheet. `Tests\Service\SurfaceContractTest` bewaakt dat een alias
nooit een eigen regel krijgt.

### Open punten

- **De Feature grid** krijgt zijn oppervlak alleen met een kop. Dat is
  historie (de twee oorspronkelijke grids op de homepage en "Over mij"),
  geen regel van het blok. Het blijft zo, zodat bestaande pagina's gelijk
  blijven.
- **De kaart van een oproep** (`.cta-band--card`) heeft dezelfde identiteit
  als `surface-emphasis`, maar onder zijn componentnaam, net als de andere
  kaarten van de site. Een thema dat de kaarten van de oproep anders wil,
  stijlt nog die klasse (of de tokens die ze leest). De rol op de kaart
  zetten is een latere, aparte stap: hij heeft rondom een rand en een
  radius, de rol alleen lijnen boven en onder.
- `primary` en `secondary` hebben nog geen eigen `--surface-*`-token: geen
  blok gebruikt ze als standaard. Ze lezen hun paletkleuren direct.

`Tests\Service\SurfaceContractTest` (`contract`, `fast`, `cms`) houdt vast:
de rolklasse op de root van elk blok met een vast oppervlak (ook
`surface-emphasis` naast `cta-section--full`, en geen rol op een kaart),
geen vulling, lijn of kleur in de regels van `.cta-section--full` en geen
combinatie van die klasse met een rol, geen `bg-soft`
of `bg-forest` in een renderer of template, één regel per rol op zijn token,
dezelfde vulling voor *Subtiele achtergrond*, de voorrang (elke gekozen
achtergrond haalt de rol weg, op elk blok met Extra vormgeving en een rol;
*Standaard*, randen, ruimte en effecten laten haar staan), geen resetregel
voor een rol met een gekozen achtergrond, geen bloktype in `BlockAppearance`,
en geen site- of themanaam in deze code.

## Decoratie van de homepage-opening (Themes 2.0, fase 1B-b)

De homepage-opening (`partials/section-homepage-hero.php`) heeft decoratie
die alleen karakter geeft: in het standaardthema twee gloeiende lijnen en
vallende puntjes. Tot deze fase stond die uitvoering in de markup
(`spark-field`, twee `laser-line`s met hun positie in een `style`-attribuut)
en in het script. Nu levert de renderer alleen neutrale haakjes, en bepaalt
de stylesheet of en hoe ze getekend worden.

### Drie dingen in de opening

| Soort | Wat | Wie beslist |
|---|---|---|
| Inhoud | eyebrow, titel met accent, inleiding, twee knoppen, kengetallen, afbeelding of video met alt-tekst, badge | de redacteur |
| Lay-out | media rechts, media links, media als achtergrond (met donkere sluier), zonder media; grootte van het accent | de redacteur |
| Decoratie | het canvas, de twee lagen, de deeltjes, hun beweging | het thema |

### De haakjes

```html
<div class="hero-decoration" data-hero-decoration aria-hidden="true">
  <div class="hero-decoration__layer" data-decoration-layer="1"></div>
  <div class="hero-decoration__layer" data-decoration-layer="2"></div>
</div>
```

- **Het canvas** `.hero-decoration` ligt over de hele opening (`inset: 0`,
  `overflow: hidden`, `z-index: 0`) en neemt nooit een klik
  (`pointer-events: none`, ook voor alles erin).
- **Twee lagen** `.hero-decoration__layer`: lege elementen zonder betekenis.
  Een thema stijlt ze met `:nth-child(1)` en `:nth-child(2)`, plus hun
  `::before` en `::after` en die van het canvas. Dat zijn tot negen vlakken.
- **Deeltjes** `.hero-decoration__particle`: `homepage-hero.js` voegt ze na
  de lagen aan het canvas toe (14, op een scherm smaller dan 640px 6) en laat
  ze met GSAP oplichten en vallen. De lagen staan erboven (`z-index: 1`).

Waarom twee lagen en niet alleen `::before`/`::after`: het script tekent de
twee lijnen elk apart in (`scaleX` van 0 naar 1, kort na elkaar). Een
pseudo-element kan GSAP niet bewegen, en het canvas moet ook de deeltjes
kunnen bevatten. Meer lagen zijn er niet zonder bewijs dat een thema ze
nodig heeft.

### Wat een thema kan

Alles wat zichtbaar is: vorm, kleur, verloop, masker, rand, textuur,
transform, opacity, en eigen CSS-animaties op de lagen en de
pseudo-elementen. Zonder PHP, zonder voorwaarde op een themanaam, zonder
CMS-instelling.

- **Geen decoratie**: `.hero-decoration{ display: none; }`. Het canvas neemt
  dan geen ruimte in, en het script voegt geen deeltjes toe.
- **Geen deeltjes**: `.hero-decoration__particle{ display: none; }`. Het
  script meet het eerste deeltje, haalt het weer weg en start geen enkele
  animatie.
- **Andere beweging**: een CSS-animatie op een laag wint van de inline
  transform die GSAP na het intekenen laat staan.

Wat een thema niet kan: het aantal deeltjes of hun val veranderen (dat is de
GSAP-code van het standaardthema), en de lagen buiten het canvas leggen. In
de lay-out *media als achtergrond* ligt de media boven het canvas, zoals de
lijnen en puntjes daar altijd al onder de foto lagen.

Een thema bezit alleen de uitvoering. Inhoud, markup, blokregistratie en
logica blijven van Core. Er is bewust geen CMS-keuze *Decoratie*: de
redacteur kiest inhoud en lay-out, het thema de identiteit. De opening heeft
ook geen Extra vormgeving (zie *Extra vormgeving van een blok*), dus er is
geen voorrang tussen een gekozen effect en de decoratie van het thema.

### Beweging en toegankelijkheid

- Het canvas staat vóór de inhoud in de DOM, is `aria-hidden`, heeft geen
  tekst, alt of kop en kan geen focus krijgen.
- Met `prefers-reduced-motion: reduce` (of zonder GSAP) beweegt niets: de
  lagen staan meteen getekend, er komen geen deeltjes.
- Er is geen bewegings-API en geen nieuwe token. De bestaande `--dur-*` en
  `--ease-*` zijn voor overgangen van de interface; de decoratie van het
  standaardthema heeft eigen vaste tijden in het script. Een thema dat
  rustiger wil, zet de deeltjes uit (zie hierboven).
- Het script start per `.hero` op de pagina en zoekt binnen die opening,
  niet in het hele document.

### Oude namen

`spark-field`, `laser-line` en `spark` zijn weg, zonder alias. Ze bestonden
alleen in deze renderer, deze stylesheet en dit script, die samen met één
cachebuster (`?v=<mtime>`) worden uitgeleverd. Opgeslagen inhoud kan ze niet
bevatten: rich text verliest elke `class` (`RichTextSanitizer`), en er is geen
blok met vrije HTML. De ontwikkel- en testdatabases bevatten ze niet.

Het effect *Vallende bolletjes* van Extra vormgeving (`sparks`,
`.block-decor--sparks` in `block-decorations.css`) is iets anders: een
opgeslagen keuze van de redacteur. Die naam blijft.

`Tests\Service\HeroDecorationContractTest` (`contract`, `fast`, `cms`) houdt
dit vast.

## Global Theme (Themes 2.0, fase 2B)

Een **Global Theme** geeft de hele website een presentatie: vorm, diepte,
oppervlakken, ornament, beweging. Eén actief thema voor de site. Het is geen
instelling uit "De negen instellingen" en geen stijlset: het kiest geen
kleur, geen lettertype en geen knopstijl, die blijven van de eigenaar.

Fase 2B bouwde de runtime, fase 2C het eerste echte thema, `minimal` (zie
*First-party themes* hieronder). Er is nog geen keuze in het CMS, geen
preview en geen schrijver (zie *Nog niet*).

### Het model

| | |
|---|---|
| `App\Service\Theme\ThemeDefinition` | één thema: `key`, `label`, `stylesheet` (of `null`). Alleen gegevens, geen PHP die iets tekent |
| `App\Service\Theme\ThemeRegistry` | de **gesloten** lijst in code: `all()`, `find()`, `fallback()`, `active()`. Geen mapscan, geen reflectie, geen thema uit de database |
| `theme_settings.active_theme` | de sleutel van het actieve thema. Geen migratie: `theme_settings` is al key/value. Geen rij = `legacy` |
| `ThemeSettings::activeThemeKey()` | leest die rij, ruw en gecachet met de rest; resolveert niets |
| `PageAssets::renderStyles()` | één vaste plek voor de `<link>` van het actieve thema |

Een sleutel is een technisch woord: `^[a-z][a-z0-9-]{1,39}$`. Geen punt,
slash, backslash, spatie of hoofdletter, dus nooit iets dat op een pad lijkt.
Een stylesheet is `null` of een lokaal `.css`-pad onder `assets/` in de vorm
die `App\Service\AssetPath::isValid()` toestaat: geen `..`, geen slash
vooraan, geen backslash, geen protocol, geen query of fragment. Of het
bestand er echt staat controleert `PageAssets` bij het printen, niet de
definitie. Het pad staat altijd
uitgeschreven in de definitie en wordt nooit uit de sleutel afgeleid. Een
first-party thema staat volgens afspraak in `assets/css/themes/`; die
afspraak zit bewust niet in `ThemeDefinition`, zodat een latere Client
Extension een stylesheet in zijn eigen map kan meebrengen.

De afhankelijkheden lopen één kant op:

```text
PageAssets → ThemeRegistry → ThemeDefinition → AssetPath
PageAssets ──────────────────────────────────→ AssetPath
```

`ThemeDefinition` is declaratieve data en kent `PageAssets` niet, net zo
min als de registry of de opgeslagen instelling. Hij controleert alleen of
vertrouwde code een goedgevormd pad schreef. `AssetPath` is de pure
vormregel die beide delen: geen bestandssysteem, geen andere klasse.
`PageAssets` blijft eigenaar van alles wat met laden te maken heeft:
`isLoadable()` (vorm plus "staat op schijf"), cachebusting, de `<link>`,
escaping en de plek in de cascade. `ThemeDefinitionTest` en
`AssetPathTest` bewaken die richting.

### `legacy`

`legacy` (label *Klassiek*) is een echt geregistreerd thema **zonder
stylesheet**: Core en de eigen instellingen alleen, byte voor byte wat de
site voor `ThemeRegistry` printte. Er is geen leeg `legacy.css`. Het is de
blijvende terugval: een ontbrekende, lege of onbekende sleutel wordt
`legacy`, nu en later. Het is geen tweede naam voor "de standaard": een
echte first-party standaard krijgt later een eigen sleutel, en pas dan
schrijft de installatiewizard die sleutel bij een verse installatie. Tot die
tijd schrijft niets `active_theme`, ook de wizard niet.

### Van sleutel naar stylesheet

```text
ThemeSettings::activeThemeKey()     ruwe rij, '' als die er niet is
  → ThemeRegistry::active()         vergelijkt alleen met de lijst
      bekend          → die definitie
      leeg/onbekend   → ThemeRegistry::fallback() = legacy
  → PageAssets::themeStylesheet()   pad uit de definitie, PageAssets::isLoadable(),
                                    AssetVersion::url(), of '' voor legacy
```

De databasewaarde wordt nooit een pad, klassenaam, include, CSS-selector of
attribuut. Er komt ook geen `body.theme-…`, `data-theme` of klasse op
`<html>`: de stylesheet is genoeg, en zo kan geen CSS stiekem van een
databasewaarde afhangen. Een onbekende sleutel (`kobold`, `../../evil.css`)
geeft `legacy` en **blijft opgeslagen**: een extensie die even ontbreekt komt
vanzelf terug, en een automatische reparatie zou de keuze van de eigenaar
weggooien. Een waarschuwing daarover in het CMS komt met de keuze (fase 2D).

Een geregistreerd thema waarvan de stylesheet ontbreekt is een kapotte
deploy: geen `<link>`, een regel in de errorlog, de pagina rendert op de
basis. Geen ander thema, geen databasewijziging.

### Foutafhandeling

Twee soorten, bewust uit elkaar gehouden:

- **Geen geldige themakeuze** (geen rij, lege rij, onbekende of verwijderde
  sleutel): een gewone toestand, geen fout. Wordt `legacy`, zonder log.
- **Een kapotte lijst** (dubbele sleutel, ongeldige definitie, geen
  `legacy`): een programmeerfout waar `ThemeRegistry` eigenaar van is. Buiten
  productie gooit `active()` die door, zodat ontwikkeling en tests het zien;
  in productie logt het en rendert `legacy`, zodat de publieke site niet wit
  wordt. Alleen een `\LogicException` wordt gevangen, nooit `\Throwable`.

Een onbereikbare database vangt `ThemeRegistry` niet. Dat doet
`ThemeSettings::all()` al, met de regel die voor de hele vormgeving geldt:
loggen en de meegeleverde vormgeving tonen. `activeThemeKey()` volgt die
regel (geen rij gelezen = `''` = `legacy`), en de log maakt de storing
zichtbaar. Al het andere dat de settings-laag gooit gaat door: dat is een
echte fout en mag niet doorgaan voor "geen thema gekozen".

### Wat een Global Theme wel en niet mag

Wel, met `var(--color-…)` voor elke kleur:

- niet-paletgebonden tokens: randdikte, de schaduwtint, glans, ruimte, de
  vullingen van de oppervlakken;
- vorm en diepte per component: radius en schaduw op de componentselector
  (niet door de gedeelde schaal `--radius-*`/`--shadow-*` te verschalen, zie
  hieronder);
- koppen: gewicht en letterspatiëring;
- hover en beweging;
- de semantische oppervlakken (`.surface-*`, zie "Oppervlakken"), de
  decoratie van de homepage-opening, sectiekoppen;
- generieke componentselectors, header en footer.

Niet:

- de vijf paletkleuren (`ThemeCss::DIRECT`) en wat daaruit is afgeleid
  (`ThemePalette::dependencies()`);
- `--font-display` en `--font-body`;
- `--btn-*` op `.btn`/`.btn--ghost`, en `--button-radius`;
- elke token waar een knopstijl naar verwijst: `--radius-sm`, `--radius-md`,
  `--radius-lg`, `--shadow-soft`, `--shadow-lift` en `--color-glow` (zie
  *Het CSS-contract*);
- vaste merkkleuren binnen een component;
- `!important`.

Waarom zo streng: `site-theme` en `site-buttons` printen alleen wat
**afwijkt** van de meegeleverde standaard. Een eigenaar die bewust de
standaardwaarde gebruikt print dus niets, en zou van een thema verliezen
dat diezelfde token zet. `ThemeStylesheetContractTest` bewaakt dit (zie
*First-party themes*, *Het CSS-contract*).

Tokens die met `var()` zijn opgebouwd (oppervlakken, schaduwen, lijnen) en
de ondergrond staan in `core.css` op `:root, main[data-page-theme]`. Een
thema dat ze herdefinieert doet dat op datzelfde paar, anders valt een
`<main>` met paginathema terug op de Core-waarden.

### Paginathema, eigen instellingen, wisselen

- **Paginathema**: blijft kleur en lettertype voor één pagina. Het Global
  Theme blijft vorm, diepte, oppervlak, ornament en beweging. Ze kiezen
  niets voor elkaar; het paginathema staat in de cascade na het Global
  Theme.
- **Eigen instellingen**: palet, lettertypen en knopstijlen staan na het
  Global Theme en winnen dus altijd.
- **Wisselen is niet-destructief**: een wissel verandert straks uitsluitend
  `theme_settings.active_theme`. Paletten, lettertypen, knopstijlen,
  paginathema's, blokken, inhoud, navigatie, Shop en moduledata blijven
  onaangeraakt.
- **"Standaardvormgeving herstellen"** raakt `active_theme` niet:
  `ThemeSettings::reset()` verwijdert alleen de sleutels uit `DEFAULTS`, en
  `active_theme` staat daar bewust niet in. Daardoor zien ook `isDefault()`,
  `changedKeys()`, `keys()` en de installatiewizard het thema niet.
- **`<meta name="theme-color">`** blijft de achtergrondkleur van het palet
  (`partials/head-branding.php`); een thema geeft de browserbalk geen eigen
  kleur.

### Nog niet

- Fase 2D: kiezen in het CMS (de schrijver, het endpoint) met een preview en
  een waarschuwing bij een onbekende sleutel.
- Fase 2E: expliciete suggesties van een thema voor palet, lettertypen en
  knoppen.
- Een first-party standaardthema (en de wizard die het schrijft) en Client
  Extension-thema's.

### Testen

`ThemeDefinitionTest`, `ThemeRegistryTest` en `AssetPathTest` (`unit`,
`fast`, `cms`): de vorm van sleutel en stylesheet, dat `ThemeDefinition`
niets van `PageAssets` weet en `AssetPath` puur is, de gesloten lijst, `legacy` als terugval,
elke onbekende of padachtige opgeslagen waarde, de dubbele-sleutelbewaking,
en dat `active_theme` geen vormgevingsinstelling is. `ThemeRenderingTest`
(`contract`, `fast`, `cms`): niets in de themaplek bij `legacy`,
`collected()` ongewijzigd, de volgorde van de lagen in `renderStyles()`, een
stylesheet uit een expliciete definitie met cache-buster, een ontbrekend
bestand gelogd en niet gelinkt, en het positieve pad met `minimal`: zijn
link direct na de verzamelde stylesheets en vóór `site-theme` en
`page-theme`, en verder byte voor byte de head van `legacy`.
`ThemePersistenceTest` (`cms`): de echte rij, ruw gelezen, onbekend niet
gerepareerd, herstellen laat hem staan, en de echte rij `minimal` tot de
`<link>` vóór `site-theme`, `site-buttons` en `page-theme`.
`ThemeStylesheetContractTest` (`contract`, `fast`, `cms`): zie *Het
CSS-contract*.

## First-party themes (Themes 2.0, fase 2C)

Core levert twee Global Themes, in deze volgorde:

| Sleutel | Label | Stylesheet | Wat het is |
|---|---|---|---|
| `legacy` | *Klassiek* | geen | de blijvende terugval: `core.css` en de eigen instellingen alleen |
| `minimal` | *Minimal* | `assets/css/themes/minimal.css` | een rustige presentatie, het eerste echte thema |

Een thema registreren activeert niets. Alleen de rij
`theme_settings.active_theme` doet dat, en niets schrijft die rij nog (geen
scherm, geen wizard). Een bestaande of verse installatie zonder rij blijft
dus op `legacy`, byte voor byte.

### Waarom "Minimal", en geen "Licht" of "Standaard"

Een Global Theme kiest het palet niet, dus het kan niet beloven dat een site
licht wordt. `minimal` beschrijft het karakter: rustig, weinig diepte, dunne
lijnen, weinig beweging, sobere decoratie. Het werkt op het donkere
standaardpalet, op een licht palet en onder een licht of donker paginathema
(gemeten, zie *Bewezen*). Het is geen standaardthema: of het later de basis
van een Mygdala Default wordt, is een eigen beslissing.

### Wat Minimal stijlt

| Haak | Wat Minimal doet |
|---|---|
| `--fw-heading`, `--fw-h1`, `--tracking-heading` | koppen 400, de h1 300, geen extra letterspatiëring |
| `--eyebrow-*` | bovenkopje 600, kleine letters, smalle spatiëring, geen streepje |
| `--border-width-strong` | 1px: velden, pillen en de oproepkaart even licht als een gewone lijn |
| `--hover-lift`, `--hover-zoom` | 0 en 0.4: kaarten blijven staan, een beeld zoomt nauwelijks |
| `--dur-base`, `--dur-slow`, `--dur-premium` | korter en rustiger (260 tot 420 ms) |
| `--surface-subtle`, `--surface-contrast`, `--surface-emphasis` | vlak: de tweede vlakkleur, de kaartkleur, en de kaartkleur met de accentwas (op `:root, main[data-page-theme]`) |
| `body`, `main[data-page-theme]` | de ondergrond is de paletkleur zelf, zonder accentpoelen |
| `.surface-contrast`, `.surface-emphasis` | zachte lijnen; de band van een oproep krijgt één accentlijn bovenaan in plaats van de streep |
| `.hero-decoration` | `display: none`: geen lijnen, geen deeltjes |
| `.hero__media-frame`, `.hero__badge` | kleine hoeken, geen schaduw, geen blur |
| kaarten: feature-, product-, collectie-, blog-, artikel-, contact- en reviewkaart, de carrouselkaart, het beeld van Tekst met afbeelding | `--radius-sm` en geen schaduw; bij hover alleen de sterkere lijn |
| `.cta-band--card` | dezelfde identiteit als `surface-emphasis`, als kaart |
| `.site-header`, `.main-nav__*`, `.site-footer` | geen schaduw onder de gescrolde header, een dunne navigatielijn, een licht submenu, een footer op de ondergrond met één lijn |
| `.stat strong` | de cijfers van de Cijferbalk in het kopgewicht |

### Wat Minimal bewust niet bezit

Het palet (de vijf kleuren, alles wat `ThemePalette` afleidt en elke andere
kleurtoken), de lettertypen (`--font-display`, `--font-body`, elke
`font-family`), de knopstijlen (`--btn-*`, `--button-radius` en de schaal
waar een knopstijl naar verwijst), de markup, de inhoud, de lay-out en de
responsive structuur. Een paginathema blijft kleur en lettertype van zijn
`<main>`; *Extra vormgeving* blijft de keuze van de redacteur.

### Het CSS-contract

`Tests\Service\ThemeStylesheetContractTest` (`contract`, `fast`, `cms`) houdt
elke stylesheet in de registry eraan. De verboden lijsten worden berekend uit
de klassen die de waarden bezitten, niet uitgeschreven: een nieuwe paletrol of
een nieuw knopwoord is meteen verboden.

- **Eigenaarschap**: elke first-party stylesheet is
  `assets/css/themes/<sleutel>.css`, bestaat, en hoort bij precies één thema;
  er staat geen wees in de map; geen sjabloon, partial, scherm, script of
  andere klasse noemt het pad in code (alleen `ThemeRegistry`).
- **Meegeleverd**: `Ownership::classify()` is `RELEASE`, het bestand wordt
  meegeleverd en door de updater vervangen, de verse kopie neemt het mee
  (`FreshSiteCopyPolicy`), het is tekst met LF (`LineEndings`). Dat volgt al uit
  `assets/css/**`; de test pint het voor de themamap. De updater is niet
  aangepast.
- **Geen palettoken**: niets uit `ThemeCss::DIRECT`, `ThemePalette::derive()`
  of `ThemePalette::dependencies()`, en geen enkele `--color-*` behalve
  `--color-shadow-rgb`. Elke kleur is `var(--color-…)`: geen hex, geen
  letterlijke `rgb()`/`hsl()`, geen kleurnaam.
- **Geen lettertype**: geen `--font-display`/`--font-body`, geen `@font-face`,
  een `font-family` alleen als `var(--font-display|body)` of `inherit`.
- **Geen knop**: geen `--btn-*`, geen `--button-radius`, geen regel op `.btn`,
  en geen token waar een knopstijl naar verwijst. Die lijst komt uit
  `ButtonStyleCss` (de woorden en de meegeleverde knoppen): `--radius-sm`,
  `--radius-md`, `--radius-lg`, `--shadow-soft`, `--shadow-lift`,
  `--color-glow` en de kleur- en lettertokens. Waarom: de standaardknop
  hovert met `var(--color-glow)`, en een knopstijl met de vorm *Zacht* is
  `var(--radius-sm)`. Gemeten: een thema dat `--radius-sm` op 2px zet, maakt
  de tweede knop van de eigenaar van 6 naar 2px. Een thema verandert vorm en
  diepte daarom per component, op de componentselector.
- **Geen escalatie, geen import, geen vreemde bron**: geen `!important`, geen
  `@import`; een `url()` alleen relatief, in de eigen map
  `assets/css/themes/<sleutel>/`, en het bestand moet er staan. Minimal heeft
  er geen.
- **Geen eigen beweging**: geen `transition`, `animation` of `@keyframes`.
  Beweging gaat via de tokens, zodat de `prefers-reduced-motion`-regels van
  Core blijven gelden.
- **Geen site en geen instantie**: geen naam uit de reviewlijst van de verse
  kopie, geen id, geen paginathema bij naam (`data-page-theme="…"`), geen
  attribuut met `id`, geen genummerde klasse, geen `block-appearance`, geen
  oude naam (`spark`, `laser`, `bg-soft`, `bg-forest`).
- **Geen nieuwe token**: een thema zet alleen tokens die `core.css` al
  declareert.
- **Oppervlakken op één klasse**: een selector met een rol als onderwerp is
  precies `.surface-<woord>` (een pseudo-element daargelaten), en een selector
  in een rol begint bij die ene klasse. Zo wint *Extra vormgeving* (twee
  klassen) altijd.
- **De paginathema-regel**: een token die `core.css` op
  `:root, main[data-page-theme]` declareert, zet een thema op datzelfde paar;
  een thema dat `body` overschildert, neemt `main[data-page-theme]` mee.
- **Echte regels**: commentaartekens zijn in evenwicht en elke selector bestaat
  uit elementnamen, klassen, attributen en pseudoklassen. Een `*` met een `/`
  erachter in een commentaar sluit het te vroeg af, en de tekst erna slikt de
  volgende regel zonder foutmelding. Dat gebeurde tijdens de bouw van Minimal
  (de hele `:root`-regel viel weg); deze controle vangt het.

`ThemeTokenContractTest` leest de themamap ook (`assets/css/*/*.css`) en houdt
Minimal dus aan dezelfde regels als elke publieke stylesheet: geen letterlijke
kleur, randdikte of pilradius, geen hover over een vaste afstand.

### Runtime

```text
theme_settings.active_theme = 'minimal'
  → ThemeSettings::activeThemeKey()
  → ThemeRegistry::active()           de definitie `minimal`
  → PageAssets::themeStylesheet()     isLoadable(), AssetVersion::url()
  → <link rel="stylesheet" href="/assets/css/themes/minimal.css?v=…">
```

De `<link>` staat direct na de laatste verzamelde stylesheet (Core,
modulehuls, zoeken, route, blokken, Extra vormgeving, kaartweergave) en vóór
`site-theme`, `site-buttons` en `page-theme`. Getest op de echte rij
(`ThemePersistenceTest`) en in het geheugen (`ThemeRenderingTest`). Verder is
de head byte voor byte die van `legacy`: geen klasse, geen attribuut, geen
tweede vermelding van de sleutel.

### Bewezen (fase 2C)

Gemeten in een wegwerpkopie van de ontwikkeldatabase, met `php -S` op de
worktree. Niets daarvan staat in de repository.

- **De rij**: geen rij, `minimal` en een onbekende sleutel op vier echte
  pagina's. Geen rij en een onbekende sleutel geven dezelfde bytes; `minimal`
  voegt precies één `<link>` toe; de onbekende rij blijft staan.
- **Markup**: de echte blokvoorbeelden (BlockSamples: 15 bloktypen met een
  voorbeeld en een oproep over de volle breedte), Extra vormgeving op drie blokken, en Blog-,
  Artikel- en productkaarten, met header en footer: dezelfde HTML voor
  `legacy` en `minimal` op de `<link>` na, in zes kleurcontexten.
- **Berekende stijl**: elk element van die pagina (1591) vergeleken tussen
  `legacy` en `minimal`, in rust en bij hover, op 1280, 768 en 375 pixels, in
  zes contexten: het standaardpalet, een licht websitepalet, een andere
  lettertypecombinatie, een licht en een donker paginathema, en een licht
  websitepalet met een donker paginathema. Verwachte verschillen: radius,
  randkleur, schaduw, vulling van oppervlak, ondergrond en footer,
  kopgewicht, letterspatiëring en hoofdletters van het bovenkopje,
  `::before` van rol en kaart, hover zonder optilling, de decoratie weg.
  Verboden verschillen: **geen**. Geen `color`, `font-family`, `font-size` of
  `line-height` anders, en geen enkele eigenschap van een knop anders, in rust
  of bij hover, ook niet met knopstijlen van de eigenaar die
  `--radius-sm`, `--shadow-soft`, `--shadow-lift` en `--color-glow` lezen.
  Breedtes veranderen alleen bij het bovenkopje (zonder hoofdletters), het
  accent in de openingstitel (lichter gewicht) en de verborgen decoratie.
  Geen horizontale scroll.
- **Paginathema**: binnen `<main>` blijven kleur en lettertype van het
  paginathema; Minimal's vlakke vullingen rekenen met zijn kleuren (de subtiele
  band is de tweede vlakkleur van het paginathema, niet die van de site).
- **Extra vormgeving**: een gekozen *Websiteachtergrond*, *Primaire* en
  *Secundaire themakleur* en *Transparant* zijn onder beide thema's identiek;
  een gekozen rand en ruimte ook. Alleen *Subtiele achtergrond* volgt de
  subtiele vulling van het thema, zoals de gedeelde woordenlijst bedoelt.
- **Opening**: canvas `display: none`, geen ruimte, geen deeltjes (14 → 0, op
  een telefoon 6 → 0), inhoud en media op dezelfde plek. Eén kanttekening:
  de eenmalige intekenbeweging van de twee lagen (`scaleX`, 0.9 s, in de
  openingstijdlijn van `homepage-hero.js`) loopt nog op de onzichtbare lagen.
  Niets daarvan wordt getekend, en er loopt niets door; zie *Open punten*.
- **Beweging**: kaarten tillen niet meer op (feature −8px → 0, blog −3px →
  0), duur korter. Met `prefers-reduced-motion` blijven de regels van Core
  gelden; het thema voegt nergens een overgang toe.

### Open punten (fase 2C)

- **De schaal is gedeeld met de knopstijlen.** `--radius-*`, `--shadow-*` en
  `--color-glow` zijn tegelijk de vormschaal van de site en de waarden van
  knopwoorden. Een thema kan ze daarom niet verschalen zonder knoppen van de
  eigenaar te veranderen, en zet vorm en diepte per component. Dat werkt (zie
  de tabel), maar een thema wordt er langer van. Of een knopstijl later eigen
  waarden krijgt, of "Afgerond" bewust "de afronding van het thema" betekent,
  is een eigen beslissing. Geen blokkade.
- **De intekenbeweging van de decoratie** (zie *Bewezen*): onzichtbaar, maar
  een thema dat de decoratie verbergt start hem nog. Klein en generiek op te
  lossen in `homepage-hero.js` (de stap overslaan met dezelfde tijdlijn), als
  een aparte stap.
- **De oproepkaart** (`.cta-band--card`) liet zich zonder hogere specificiteit
  overschrijven: één klasse, na Core. De rol op de kaart blijft een latere
  keuze.
- **De vier lokale schaduwen**: het submenu (gemeten) en de gescrolde header
  (dezelfde selectorlijst als Core) zwakt Minimal gericht af op hun
  componentselector; de feature-kaart verloor haar hover-gloed
  op `.feature-card:hover:hover` (dezelfde specificiteit als Core). Geen
  dieptetoken nodig gebleken. De checkout-overlay is niet gestijld.
- **De edelsteen** in een kaart zonder beeld (Kaarten-carrousel) is onder
  Minimal een dunne lijnpictogram in het accent op een vlak kaartvlak: niet
  storend, geen thema-blokkade. Een neutralere vorm blijft backlog.
- **Al bestaand, niet door het thema**: in `legacy` tillen productkaarten bij
  hover nog 6px op met `prefers-reduced-motion` (geen reduced-motionregel in
  `shop.css`). De vier focusringen in `--color-primary` halen op het geteste
  lichte palet (`#B07A3A` op `#FAF7F2`) 3.45:1 op de ondergrond en 3.69:1 op
  een kaart: genoeg voor een focusring; een lichter accent haalt het niet, in
  elk thema.

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
  een ander palet activeren verandert nooit een lettertype. Een benoemde
  "stijlset" (kleur + letter + knop) komt, als die er ooit komt, als eigen
  record naast `color_palettes`, niet als extra kolommen erin. Een **Global
  Theme** is iets anders: dat schrijft nooit een palet, lettertype of
  knopstijl (zie "Global Theme").
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

### Knopstijlen en het Global Theme

Een **Global Theme** (zie "Global Theme") raakt de knopstijlen niet aan. Een
thema activeren schrijft geen `button_style_defaults`, geen
`button_styles`, geen palet en geen `theme_font_roles`: wisselen verandert
alleen `theme_settings.active_theme`. Een thema declareert ook geen
`--btn-*` op `.btn`/`.btn--ghost` en geen `--button-radius`: `site-buttons`
schrijft alleen het verschil met de standaard, dus een eigenaar die bewust
de standaard koos zou anders van het thema verliezen. Het zet ook geen token
waar een knopwoord naar verwijst (`--radius-sm/-md/-lg`, `--shadow-soft`,
`--shadow-lift`, `--color-glow`): die zou een gekozen knopstijl net zo goed
veranderen (zie "First-party themes", *Het CSS-contract*).

Een *suggestie* van een thema voor knopstijlen (fase 2E) wordt een
expliciete actie van de eigenaar, nooit een bijwerking van wisselen. Meer
standaardknoppen (bijvoorbeeld een derde rol voor "op een donkere foto")
blijven een extra rolwoord in `ButtonStyleRepository::ROLES` met een eigen
`.btn--…`-klasse, nooit een tweede CSS-generator.

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
| Subtiele achtergrond | `--surface-subtle`, de vulling van `.surface-subtle` ("Oppervlakken") |
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
| Toetsenbordfocus | `--color-primary-text` (een ring die op elke ondergrond 3:1 haalt) |
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

Het Global Theme: zie "Global Theme", *Testen*.

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
