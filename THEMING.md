# Vormgeving (theming)

Hoe de site eruitziet, en hoe je daar veilig iets aan toevoegt. Lees dit
samen met `PROJECT-MAP.md` (waar iets staat). Voor het modulesysteem zie
`MODULES.md`; vormgeving is **Core**, geen module.

## Twee dingen die je uit elkaar moet houden

| | Wie de site *is* | Hoe de site er *uitziet* |
|---|---|---|
| Klasse | `App\Service\SiteSettings` | `App\Service\Theme\ThemeSettings` |
| Tabel | `site_settings` | `theme_settings` |
| Scherm | Instellingen → Site-instellingen | Instellingen → Vormgeving |
| Inhoud | naam, logo, tweede logo, favicon, deel-afbeelding, adres, KVK, e-mail-, factuurteksten, de header-knop, de footer-slotregel, social profielen | vijf kleuren, lettertypecombinatie, knopvorm |
| Terugzetten | nooit automatisch | één knop, en die raakt de linkerkolom niet aan |

Twee tabellen en niet één met een prefix, precies omdat "standaardvormgeving
herstellen" nooit een bedrijfsadres, een logo of een knoptekst mag meenemen.
`reset-theme-settings.php` kan `site_settings` niet eens bereiken. De
header-knop, de footer-slotregel en de social profielen horen daarom ook in de
linkerkolom, met een eigen scherm — zie `HEADER-FOOTER.md`.

Er wordt niets gedupliceerd. Het themascherm toont de sitenaam omdat een
eigenaar hem daar zoekt, maar linkt door naar Site-instellingen; opslaan doet
het niet.

## Er is een derde: hoe het CMS zélf eruitziet

Alles hierboven gaat over de **website**. Hoe het **adminpaneel** eruitziet is
een eigen keuze, met een eigen klasse (`App\Service\AdminTheme`), een eigen
tabel (`admin_settings`) en een eigen kaart *Dashboard uiterlijk* op het
tabblad **Dashboard** van Instellingen → Site-instellingen. Een bezoeker ziet er niets van, en
"standaardvormgeving herstellen" op het themascherm raakt het niet aan.

Vier eerste-partij-skins, meer niet: `default`, `classic`, `ocean`, `black`.
Die lijst is gesloten. Een opgeslagen waarde die er niet in staat — een oude
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

Statuskleuren blijven in élk thema rood en groen: een fout die er niet meer
uitziet als een fout is een stijlfout, geen skin. Selectie en actieve staat
leunen nergens op kleur alléén.

De bouwstenen die op die tokens draaien — uitleg bij een veld, de help-knop,
de infobalk, zoekveld, select, checkbox, switch en bestandskiezer — hebben een
eigen handleiding: `ADMIN-UI.md`.

Een thema toevoegen is dus twee plaatsen: een sleutel in `AdminTheme::THEMES`
en één `[data-admin-theme="…"]`-blok in `admin.css` dat élk kleurtoken van
`:root` opnieuw zet.

## De zeven instellingen

```text
primary_color        #C9A063   accent: knoppen, links, iconen, lijnen
on_primary_color     #1B140D   tekst óp een gevulde knop
background_color     #120D09   de ondergrond van elke pagina
surface_color        #1C150E   kaarten, één tint boven de ondergrond
text_color           #F5EFE4   lopende tekst en koppen
font_pairing         trirong-quattrocento
button_shape         pill
```

Dat zijn exact de waarden die `assets/css/core.css` zelf declareert. Daar
draait het hele mechanisme op:

- **`core.css` is het standaardthema.** Een site die niets heeft gewijzigd
  slaat geen rijen op, krijgt géén `<style id="site-theme">`-blok, en rendert
  dus letterlijk zoals hij altijd deed.
- **Ontbrekende rij = standaard.** Een verse installatie is meteen coherent,
  en de publieke site blijft goed staan als de database onbereikbaar is.
- **Herstellen is verwijderen**, geen standaardwaarden terugschrijven.

Meldingskleuren (fout, gelukt, waarschuwing) zijn geen thema-instelling en
staan als vaste waarden in de stylesheets. Het adminpaneel heeft zijn eigen,
volledig losstaande tokens (`--admin-*` in `admin/assets/admin.css`) en
verandert nooit mee.

## Van instelling naar pixel

```text
admin/theme.php
  → api/admin/update-theme-settings.php   ThemeSettings::validate() + ::save()
  → theme_settings                        alleen wat iemand echt koos

publieke pagina
  → App\Service\PageAssets::renderStyles()
      1. het lettertype van de gekozen combinatie (ThemeFonts)
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

Er is **geen aparte instelling** voor een rand, zachte tekst, een glow of een
hoverkleur, en die moet er ook niet komen. Klein instellingenoppervlak,
afgeleide rest.

## Nieuwe thema-instelling toevoegen

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

## Lettertypecombinaties

`App\Service\Theme\ThemeFonts` is een **gesloten lijst**. Per combinatie: een
sleutel, een label, de twee volledige font-stacks en één stylesheet-URL. Een
beheerder kiest een sleutel; niemand typt ooit een URL of een `font-family`.

Alleen de gekozen combinatie wordt geladen. De `@import` die vroeger bovenaan
`core.css` stond is hierheen verhuisd, dus een site op een andere combinatie
downloadt Trirong niet meer. De combinatie `system` downloadt helemaal niets.

Een combinatie toevoegen is één regel in `PAIRINGS`. Houd de lijst klein.

## Knopvorm

`--button-radius`, met twee waarden: `pill` (999px, de standaard) en
`rounded` (`var(--radius-md)`). Alleen `.btn` leest het token. Ronde
icoonknoppen, labels, stappentellers, filterchips en kleurstalen houden hun
eigen vorm, want die vorm betekent iets — vervang die niet mee.

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
`default`, en dat de twee vormgevingen elkaar niet raken). Zie verder
`TESTING.md`.

Raak je de stylesheets aan, controleer dan of de standaardvormgeving
onveranderd rendert: vergelijk `getComputedStyle` van élk element vóór en ná,
in dezelfde pagina, door de oude stylesheets als `<style>` in te voegen en de
`<link>`-elementen uit te zetten. Zet animaties en transitions eerst uit en
wacht op `document.fonts.ready`, anders meet je de homepage-animaties in
plaats van je wijziging.
