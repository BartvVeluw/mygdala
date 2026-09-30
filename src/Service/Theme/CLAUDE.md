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
- Core, nadrukkelijk geen module. Een module mag de tokens gebruiken maar
  krijgt nooit een eigen instelling in de vormgeving.

Lees `../../../THEMING.md`.
