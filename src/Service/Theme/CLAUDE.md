# src/Service/Theme

De vormgeving van de **publieke website**: instellingen, kleurenrekenwerk,
lettertypecombinaties en het CSS-overrideblok.

- Hoe het **CMS zelf** eruitziet is iets anders: dat is `App\Service\AdminTheme`,
  vier gesloten skins over één stylesheet. Haal die twee niet door elkaar.
- Kleuren zijn semantische **rollen** (`--color-primary`, `--color-bg`), nooit
  merknamen. Zeven instellingen; al het andere is daaruit afgeleid.
- Alpha-afleidingen zijn platte CSS op de `-rgb`-triplets. Tint-afleidingen
  rekent PHP uit, omdat CSS-kleurfuncties hier geen optie zijn.
- Core, nadrukkelijk geen module. Een module mag de tokens gebruiken maar
  krijgt nooit een eigen instelling in de vormgeving.

Lees `../../../THEMING.md`.
