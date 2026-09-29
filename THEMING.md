# Vormgeving (theming)

Hoe de site eruitziet, en hoe je daar veilig iets aan toevoegt. Lees dit
samen met `PROJECT-MAP.md` (waar iets staat). Voor het modulesysteem zie
`MODULES.md`; vormgeving is **Core**, geen module.

## Twee dingen die je uit elkaar moet houden

| | Wie de site *is* | Hoe de site er *uitziet* |
|---|---|---|
| Klasse | `App\Service\SiteSettings` | `App\Service\Theme\ThemeSettings` |
| Tabel | `site_settings` | `theme_settings` |
| Scherm | Instellingen | Instellingen → Vormgeving |
| Inhoud | naam, logo, tweede logo, favicon, deel-afbeelding, adres, KVK, e-mail-, factuurteksten, de footer-slotregel (de headerknoppen staan als navigatie-items in `nav_items`, de social profielen in `footer_social_links`) | vijf kleuren, lettertypecombinatie, knopvorm |
| Terugzetten | nooit automatisch | één knop, en die raakt de linkerkolom niet aan |

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
                       font_pairing (een ThemeFonts-sleutel)
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
| De afgeleide tinten | `ThemePalette::derive()` |
| De lettertypes | `ThemeFonts::isValidKey()` / `::pairing()` |
| De laatste controle op elke waarde | `ThemeCss::isSafeValue()` |
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
is en niet `system`. De twee preconnects komen één keer, ook als alleen het
thema een webfont heeft.

### Beheren

*Paginathema's* in de zijbalk, direct onder Vormgeving, met een eigen
permissie `page_themes.manage`. Het overzicht toont naam, kleurstalen,
lettertype, hoeveel pagina's het thema gebruiken, en Bewerken, Dupliceren en
Verwijderen.

- **Een nieuw thema begint als de vormgeving van de website**
  (`PageThemeService::defaults()`), nooit met een palet uit de code.
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
  primair op achtergrond). Berekend in PHP (`ThemeColor::contrastRatio()`) en
  live bijgewerkt door `admin/assets/page-theme-admin.js`. Het is een
  waarschuwing: opslaan kan altijd.
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

- tonen alle pagina's de vormgeving van de website — geen attribuut, geen
  `page-theme`-blok, geen extra lettertype;
- verdwijnen de zijbalkregel, de schermen (niemand houdt
  `page_themes.manage`, ook geen Super Admin) en het veld in de pagina-editor;
- blijft alles bewaard: de thema's en de keuze van elke pagina. Weer aanzetten
  brengt ze terug.

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

Raak je de stylesheets aan, controleer dan of de standaardvormgeving
onveranderd rendert: vergelijk `getComputedStyle` van élk element vóór en ná,
in dezelfde pagina, door de oude stylesheets als `<style>` in te voegen en de
`<link>`-elementen uit te zetten. Zet animaties en transitions eerst uit en
wacht op `document.fonts.ready`, anders meet je de homepage-animaties in
plaats van je wijziging.
