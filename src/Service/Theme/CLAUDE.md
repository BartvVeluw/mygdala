# src/Service/Theme

De vormgeving van de **publieke website**: instellingen, kleurenrekenwerk,
lettertypecombinaties, de Font Library (eigen lettertypen) en het
CSS-overrideblok.

- Hoe het **CMS zelf** eruitziet is iets anders: dat is `App\Service\AdminTheme`,
  vier gesloten skins over één stylesheet. Haal die twee niet door elkaar.
- Kleuren zijn semantische **rollen** (`--color-primary`, `--color-bg`), nooit
  merknamen. Negen instellingen (vijf kleuren, combinatie, eigen lettertype
  voor koppen en voor tekst, knopvorm); al het andere is daaruit afgeleid.
- De vijf kleuren zijn die van het **actieve kleurenpalet**
  (`ColorPaletteService`, tabel `color_palettes`). `ThemeSettings::all()`
  blijft de enige lezer: lees nooit zelf `color_palettes` om iets te kleuren.
- Een palet is iets anders dan een **paginathema** (module `page_themes`):
  een paginathema houdt zijn eigen kleuren, welk palet ook actief is.
- De tintformules staan één keer, als recept in `ThemePalette`; de live
  preview voert datzelfde recept uit. Schrijf nergens een tweede formule.
- Alpha-afleidingen zijn platte CSS op de `-rgb`-triplets. Tint-afleidingen
  rekent PHP uit, omdat CSS-kleurfuncties hier geen optie zijn (de preview
  in het CMS rekent hetzelfde recept na in JS).
- **Lettertypen**: een combinatie uit `ThemeFonts` is de basis, een
  Font Library-familie vervangt per rol (`ThemeTypography`). In CSS heet een
  familie `mygdala-font-<id>`, nooit haar naam; alleen gebruikte families
  krijgen `@font-face`. Een palet raakt nooit een lettertype.
- **Knopstijlen** (`ButtonStyles`, `ButtonStyleCss`, `ButtonIcons`): het
  ontwerp van een knop, twee daarvan de standaard. `core.css` tekent elke
  `.btn` uit `--btn-*`-properties; dit schrijft alleen het verschil van de
  standaarden en één regel per gekozen stijl. Een kleur is een themakleurwoord
  (`var(--color-…)`, volgt palet en paginathema) of een vaste `#RRGGBB`. Een
  partial vraagt `ButtonStyles::classes()`, nooit eigen knop-CSS.
- **Global Theme** (`ThemeDefinition`, `ThemeRegistry`): declaratief, een
  gesloten lijst in code. De sleutel in `theme_settings.active_theme` wordt
  alleen met die lijst vergeleken, nooit een pad, klasse of attribuut;
  onbekend = `legacy` (geen stylesheet), en de rij blijft staan. Een thema
  zet geen paletkleur of afgeleide, geen `--font-display`/`--font-body`,
  geen `--btn-*`/`--button-radius` en geen `!important`: `site-theme` en
  `site-buttons` schrijven alleen verschillen. Paginathema's blijven kleur
  en lettertype. Wisselen verandert alleen `active_theme`, en
  `active_theme` staat nooit in `ThemeSettings::DEFAULTS`. Kiezen gebeurt op
  Vormgeving → Thema: het endpoint toetst lidmaatschap
  (`ThemeRegistry::find()`), `ThemeSettings::saveActiveThemeKey()` schrijft
  (ook `legacy`, nooit een verwijderde rij) zonder de registry te kennen.
  De preview geeft de definitie mee aan `PageAssets::renderStyles($theme)`:
  request-lokaal, alleen in `/admin/`, nooit een statische override.
- **Een themastylesheet** (first-party: `assets/css/themes/<sleutel>.css`,
  nu `minimal`) volgt `ThemeStylesheetContractTest`: geen `--color-*` behalve
  `--color-shadow-rgb`, geen token waar een knopstijl naar verwijst
  (`--radius-sm/-md/-lg`, `--shadow-soft/-lift`, `--color-glow`: vorm en
  diepte dus per component), geen transition/animation, alleen tokens die
  `core.css` al heeft, een rol op zijn ene klasse, en een `var()`-token van
  `:root, main[data-page-theme]` op datzelfde paar. Geen `*` gevolgd door
  `/` in een commentaar: dat sluit het af en slikt de volgende regel.
- Core, nadrukkelijk geen module. Een module mag de tokens gebruiken maar
  krijgt nooit een eigen instelling in de vormgeving.

Lees `../../../THEMING.md`.
