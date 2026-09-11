# Header en footer

Wat er in de gedeelde header en footer instelbaar is, en waar de grens ligt.
Lees dit samen met `PROJECT-MAP.md` (waar iets staat). Voor kleuren en
lettertypes zie `THEMING.md`; voor moduleslots `MODULES.md`.

## De grens

**De structuur is van Core. De inhoud is van de beheerder.**

| Van Core, niet instelbaar | Van de beheerder |
|---|---|
| Skip-link, merkblok, plaats van de navigatie, mobiele menumechaniek, sticky gedrag, de taalwissel, moduleslots | Menu-items (Navigatie), footerkolommen en -links (Footer), logo's (Site-instellingen), kleuren (Vormgeving) |
| Waar de knop staat, waar de slotregel staat, hoe een social-icoon eruitziet | Óf de knop er is, wat erop staat en waar hij heen gaat; óf de slotregel er is en wat er staat; welke social profielen bestaan |

Er zijn geen headerregio's, geen tweede knop, geen widgetzones en geen
slepen-en-neerzetten. Dit is een CMS, geen layoutbouwer.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Opslag | `site_settings` — sleutels in `App\Service\SiteSettings::DEFAULTS` |
| Header-knop | `App\Service\HeaderCta` |
| Slotregel | `App\Service\FooterService::slogan()` |
| Social profielen | `App\Service\SocialProfiles` |
| Scherm | Instellingen → **Header & footer** (`admin/header-footer.php`) |
| Opslaan | `api/admin/update-header-footer-settings.php` |
| Rendering | `partials/header.php`, `partials/footer.php` |
| Styling | `.social-row` in `assets/css/core.css` |

`site_settings` en niet `theme_settings`: dit is wie de site *is*, niet hoe hij
er *uitziet*. "Standaardvormgeving herstellen" mag nooit een knoptekst of een
Instagram-adres meenemen — zie de tabel bovenaan `THEMING.md`.

## De taalwissel

Staat links van de knop, en verschijnt **alleen op een site die meer dan één
taal publiceert** (`MULTILINGUAL.md`). Op een eentalige site waren het twee
knoppen die allebei dezelfde pagina in dezelfde woorden toonden, dus daar
rendert de header er geen — geen leeg besturingselement en geen extra
tabstop. Welke talen erin staan en welke voorop staat komt uit
Instellingen → Talen; de vormgeving en de plaats zijn van Core.

## De knop in de header

Precies één, rechts naast de taalwissel en de moduleslots.

```text
header_cta_enabled          '1' / '0'
header_cta_label_nl         de tekst
header_cta_label_en         leeg = gelijk aan NL
header_cta_link_type        page | route | external
header_cta_target_page_id   bij 'page'
header_cta_target_route     bij 'route'
header_cta_external_url     bij 'external'
header_cta_open_in_new_tab  '1' / '0'
```

Dat zijn **dezelfde velden als een menu-item en een footerlink**, en ze gaan
door dezelfde `App\Service\LinkResolver`. Er is met opzet geen tweede
linkmodel. Dat levert direct op:

- een pagina die op concept wordt gezet of verdwijnt, laat de knop verdwijnen;
- een pagina die van slug verandert, neemt de knop mee;
- een route van een **uitgeschakelde module** bestaat niet meer in
  `RouteRegistry`, dus de knop verdwijnt in plaats van naar een 404 te wijzen;
- een pagina die door een uitgeschakelde module wordt geserveerd (`/shop.php`)
  telt hetzelfde: `LinkResolver` laat hem vallen.

**Verdwijnen is niet vergeten.** De instelling blijft staan; zet je de module
weer aan, dan staat de knop er weer zonder dat iemand iets opnieuw invult. Het
adminscherm zegt intussen met een waarschuwing waaróm de knop niet te zien is
(`HeaderCta::adminWarning()`).

Geen tekst = geen knop. Geen werkende bestemming = geen knop. De header blijft
zonder knop coherent, op desktop en op mobiel.

## De slotregel in de footer

```text
footer_slogan_enabled  '1' / '0'
footer_slogan_nl       de tekst
footer_slogan_en       leeg = gelijk aan NL
```

Staat onderin naast het copyright en de juridische links. Uit of leeg betekent
dat er geen `<span>` gerenderd wordt — geen lege regel.

## Social profielen

Eén optionele URL per netwerk, uit een **gesloten lijst** in
`SocialProfiles::NETWORKS`: Instagram, Facebook, Pinterest, LinkedIn, YouTube,
TikTok, Etsy. Sleutels heten `social_<netwerk>_url`.

Gesloten om dezelfde reden als `ThemeFonts` en `ModuleRegistry`: een beheerder
kiest, niemand typt ooit een netwerknaam, een iconklasse of een stuk SVG. Het
enige dat uit de database in de pagina terechtkomt is een `href`, en die moet
langs `SocialProfiles::isValidProfileUrl()`:

- `https://` en niets anders — dat sluit `javascript:`, `data:` en een
  relatief pad in één regel uit;
- een parseerbare URL met een host en zonder inloggegevens erin;
- het **registreerbare label** van de host is de naam van het netwerk zelf.
  Dus `www.pinterest.de`, `nl.pinterest.com` en `pinterest.nl` mogen allemaal,
  en `facebook.evil.example` niet. Een naam matchen in plaats van een lijst
  domeinen is wat dit weghoudt van broze aannames over landdomeinen.

**Geen aparte aan/uit-vlag.** Ingevuld en geldig = zichtbaar, leeg = weg —
dezelfde regel als een logo of een og:image. Zonder profielen rendert de
footer géén rij en géén kop.

De iconen zijn van dit project: 24x24 stroke-glyphs in dezelfde stijl als de
adminzijbalk, in `SocialProfiles::NETWORKS`. Geen iconfont, geen stylesheet van
derden, geen buildstap, geen runtime-download — de footer moet het doen op
gedeelde hosting met alleen PHP. Elke link draagt zijn eigen `aria-label`
(tweetalig, via `data-nl-aria`/`data-en-aria`) en de `<svg>` staat op
`aria-hidden`, dus het glyph hoeft de betekenis niet alleen te dragen.

### Een netwerk toevoegen

1. Eén regel in `SocialProfiles::NETWORKS`: sleutel, label, settings-sleutel,
   de domeinlabels die de host mag hebben, en het icoon.
2. Diezelfde settings-sleutel met `''` in `SiteSettings::DEFAULTS`.
3. Klaar — het adminscherm en de footer lezen allebei het register. Houd de
   lijst klein.

Een migratie is niet nodig: `site_settings` is key/value en een ontbrekende
rij betekent de standaard.

## Standaarden: bestaande site versus verse installatie

Dezelfde afspraak als bij branding (`THEMING.md`): de **codestandaard is
generiek** en een **migratie heeft de huidige waarden vastgezet**.

```text
code    knop uit, geen tekst, geen bestemming
        slotregel uit, leeg
        alle social-URL's leeg

rij     migratie 20260909220000 schreef "Vraag offerte aan" /
        "Request a quote" naar de Contact-pagina, en de slotregel,
        als echte rijen — INSERT IGNORE, dus een bestaande rij
        wint altijd
```

Voor social profielen is niets gemigreerd: deze site had er geen, en er
worden er geen verzonnen.

Let op één eigenaardigheid van `SiteSettings::all()`: een opgeslagen **lege**
waarde valt terug op de standaard. Dat werkt alleen omdat elke standaard hier
leeg of `'0'` is. Geef een nieuwe sleutel in deze familie dus nooit een
niet-lege codestandaard, anders kan een beheerder hem niet leegmaken.

## Testen

```bash
docker exec vvld_php      php vendor/bin/phpunit --testsuite fast
docker exec vvld_php_test php vendor/bin/phpunit --testsuite cms
```

`fast` bevat `HeaderFooterSettingsTest` (de instellingen zelf, zonder database)
en `HeaderFooterContractTest` (geen sitespecifieke tekst of bestemming meer in
de gedeelde schil). `cms` voegt `HeaderFooterRenderingTest` toe: de
CMS-paginabestemming tegen echte rijen, en wat een pagina echt rendert — ook op
de CMS-only deployment. Zie verder `TESTING.md`.

Raakte je een **taalveld** van de navigatie of de footer aan — een menulabel,
een kolomtitel, een footerlink of de footertekst — dan is
`Tests\Repository\LocalizedNavigationFooterPersistenceTest` (suite `cms`) de
test die vasthoudt dat het opslaan van de ene taal de andere niet
overschrijft, en `MULTILINGUAL.md` de regel erachter.
