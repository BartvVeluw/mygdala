# De talen van de website

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Laag 3, de taal van de
bezoeker, en wat een site zelf over zijn talen vastlegt: het talenregister, de
hoofdtaal, de terugvalregel en de publieke taalwissel. De taal van het CMS
staat in [`CMS-LANGUAGE.md`](CMS-LANGUAGE.md), de bewerktaal in
[`EDITING-LANGUAGE.md`](EDITING-LANGUAGE.md).

## Het talenregister

Gesloten en in code geschreven, om dezelfde reden als `BlockDefinitions`,
`ModuleRegistry`, `ThemeFonts` en `SocialProfiles`: een taalcode komt uit een
adminformulier, uit een instellingenrij en uit een URL, en het enige wat zo'n
waarde ooit mag doen is een sleutel van die lijst raken of missen.

Dat is hier extra dragend. Een taalcode wordt aan een kolomnaam geplakt
(`title_` . `$code`), dus een code die uit een verzoek kon komen zou een
SQL-injectie zijn. Dat kan niet: elke lezer gaat eerst langs `::has()` of
`::get()`.

V1 registreert **Nederlands en Engels**, want dat is wat de bestaande
`_nl`/`_en`-kolommen kunnen opslaan. Per taal legt het register vast:

```text
code                          nl
nativeLabel                   Nederlands
dutchLabel / englishLabel     Nederlands / Dutch
deeplSource / deeplTarget     NL / NL
availableAsAdminLocale        ja
availableAsContentLanguage    ja
```

Duits toevoegen is later één regel in dit register plus opslag ervoor. Geen
editor, geen renderer en geen provider verandert mee — dat is waar de twee
`availableAs*`-vlaggen voor zijn: een taal mag in stappen binnenkomen, want
"er is een verzorgde CMS-vertaling" en "de site kan er inhoud in opslaan" zijn
twee verschillende vragen met twee verschillende antwoorden.

## De talen van de website

**Dit product is tweetalig. Nederlands en Engels zijn er altijd allebei** —
voor de bezoeker en voor de redacteur. Er is geen instelling die er een
weghaalt, want die instelling was het probleem.

Wat een site wél kiest, is welke van de twee de **standaard** is:

```text
primary_content_language     nl        (of: en)
```

Dat betekent twee dingen, en alleen die twee:

- een bezoeker die nog niets gekozen heeft krijgt deze taal;
- een ontbrekende vertaling valt op deze taal terug.

`site_settings` en niet `theme_settings`, om de reden die bovenaan
[`THEMING.md`](../../THEMING.md) staat: dit is wie de site *is*, en "standaardvormgeving
herstellen" mag nooit de talen van een site meenemen.

De regels, allemaal op één plek (`ContentLanguages`):

- **De hoofdtaal staat altijd vooraan.** "Vooraan" is precies wat de publieke
  wissel, de standaard-bewerktaal en de terugvalregel er alle drie mee
  bedoelen.
- **Een onbekende code valt terug** op Nederlands in plaats van te weigeren.
  Een rij uit een nieuwere versie mag een redacteur nooit buitensluiten.
- **`enabled()` leest het register, niet een instellingenrij.** Elke taal
  waarin dit build inhoud kán opslaan is een taal die deze site publiceert.

### `enabled_content_languages` is deprecated

De rij bestaat nog, wordt nog geschreven en wordt nooit meer gelezen om iets
te beslissen.

| | |
|---|---|
| Gelezen door | `ContentLanguages::storedEnabled()`, alleen voor diagnose |
| Geschreven door | `ContentLanguages::normalise()`, altijd de volledige set; en eenmalig door migratie `20260911200000`, ook met de volledige set ([`MIGRATIONS.md`](MIGRATIONS.md)) |
| Beslist over de publieke taalwissel | **nee** |
| Beslist over de velden van een redacteur | **nee** |
| Beslist over automatisch vertalen | **nee** |

**Er wordt niets verwijderd.** Geen rij gewist, geen `_nl`/`_en`-kolom
aangeraakt, geen vertaling weggegooid. Een bestaande site met
`enabled_content_languages = nl` krijgt gewoon zijn taalwissel terug en zijn
redacteuren hun Engelse velden;
`Tests\Service\LanguageRegistryTest::testAStoredSingleLanguageRowNoLongerTakesEnglishAway`
is de test die dat vasthoudt, inclusief dat het lezen de rij zelf onveranderd
laat. Dat de opgeslagen waarde toch wordt rechtgezet, is het werk van één
migratie en niet van de code die hem leest: zie
[`MIGRATIONS.md`](MIGRATIONS.md).

`MAX_ENABLED` blijft 2, want dat is wat de `_nl`/`_en`-kolommen kunnen
opslaan. Een derde taal is een register-entry plus opslag ervoor, niet een
instelling.

## De terugvalregel

Vroeger stond die zo in de code: "Nederlands is de inhoud, Engels optioneel,
leeg Engels betekent gelijk aan Nederlands" — en hij werd op ongeveer 280
plekken in `src/` met de hand toegepast. Dat klopte zolang Nederlands de enige
mogelijke hoofdtaal was.

Nu staat hij één keer, in `LocalizedValue`, en in termen van de **hoofdtaal**
in plaats van van het Nederlands:

```text
gevraagde taal   →  eigen waarde
leeg             →  de waarde van de hoofdtaal
```

Een ontbrekende vertaling toont dus de woorden van de hoofdtaal, nooit een
lege pagina. Voor een bezoeker verandert er niets; alleen de náám van de taal
waarop wordt teruggevallen kan nu anders zijn.

`LocalizedValue::raw()` bestaat naast `::in()` en is wat een **editor**-formulier
toont: een vertaalveld dat stilletjes de woorden van de hoofdtaal laat zien,
zou bij de eerstvolgende Opslaan als echte vertaling worden weggeschreven.

`App\Service\Forms\FormText` doet hetzelfde, één domein eerder, en blijft zoals
hij is: dat is een vast NL/EN-paar binnen Core Forms en hem omzetten zou een
Forms-refactor zijn, geen meertaligheidswijziging.

## De publieke website

**De taalwissel staat er altijd.** `NL | EN`, in de gedeelde header
(`partials/header.php`), op élke publieke route: de homepage, elke CMS-pagina,
de Blog en zijn berichten, de Shop, een product, een collectie, het
portfolio en de portfolio-detailpagina. Eén partial, geen enkele module die
zijn eigen wissel meebrengt.

Hij verscheen vroeger alleen op "een site met meer dan één taal", en dat was
precies de fout: een bezoeker van een site waar niemand Engels had
*aangezet* kreeg geen enkele manier om erom te vragen — ook niet als er
Engelse inhoud in de `_en`-kolommen stond.

- `partials/section-*.php` schrijven `data-nl`/`data-en` en
  `assets/js/core.js` wisselt ze in de browser. De attribuutnamen en de
  server-side afdruk zijn ongewijzigd; alleen hoe `core.js` de waarde
  terugschrijft is aangescherpt.
- **`data-nl`/`data-en` zijn platte tekst**, en `core.js` zet ze met
  `textContent`. De waarde komt van de redacteur en wordt door de server in
  het attribuut geëscaped, maar de browser decodeert hem bij het lezen weer,
  dus hem aan `innerHTML` toekennen zou een label als `<img onerror=…>` als
  echte markup uitvoeren — een opgeslagen-XSS-route. Alleen een element dat
  écht HTML draagt krijgt `data-lang-html`, en alléén dat element gaat via
  `innerHTML`. Die waarde is altijd `RichTextSanitizer`-uitvoer of een
  door de server gebouwd fragment van vaste tags met geëscapete tekst
  (rijke tekst, de Hero-titel, de cookie- en afrekenlinkjes). Een gewoon
  label, een titel, een navigatie- of Footertekst krijgt de marker nooit;
  `Tests\Service\MultilingualBoundaryTest` bewaakt dat.
- Welke van de twee bij de eerste paint zichtbaar is, is de **standaardtaal**
  van de site, via `SiteText::visible()`.
- `<html lang>` volgt de standaardtaal en draagt daarnaast
  `data-primary-lang`, zodat `core.js` weet in welke taal de server de pagina
  al heeft afgedrukt en hem niet nodeloos overschrijft.
- De keuze van de bezoeker staat in `localStorage` onder `vvl-lang` en blijft
  staan terwijl hij doorklikt.

**Geen adminvoorkeur raakt hier iets.** Niet de CMS-taal van een beheerder,
niet zijn bewerktaal. Die staan op een rij in `admin_users`; dit leest
`site_settings` en de browser van de bezoeker.

**Applicatieteksten in de schil** — de winkelwagen, de 404, de foutmeldingen
van een formulier — dragen nog steeds een vast NL/EN-paar in de markup en
worden door `core.js` gewisseld. Een frontend-tekstcatalogus zoals het CMS die
nu heeft is bewust geen onderdeel van V1.

### Een ontbrekende vertaling: terugval voor de bezoeker, leeg voor de redacteur

Dit onderscheid draagt het hele model.

```text
                        Nederlands (hoofdtaal)   Engels
opgeslagen              "Neem contact op"        ""

bezoeker kiest EN   ->  "Neem contact op"        via LocalizedValue::in()
redacteur bewerkt EN -> leeg veld                via LocalizedValue::raw()
```

Een bezoeker krijgt nooit een lege knop of een lege kop. Een redacteur ziet
nooit woorden die hij niet geschreven heeft in een vertaalveld — want de
eerstvolgende Opslaan zou die als échte vertaling wegschrijven, en de site
zou het verschil tussen "vertaald" en "nog niet vertaald" kwijt zijn.

`LocalizedValue::isTranslated()` is dezelfde vraag, expliciet.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Talenregister (gesloten) | `src/Service/Language/LanguageRegistry.php` + `LanguageDefinition.php` |
| Talen van de website | `src/Service/Language/ContentLanguages.php` |
| Terugvalregel op één plek | `src/Service/Language/LocalizedValue.php` |
| Wat een publieke partial afdrukt | `src/Service/Language/SiteText.php` |
| Tabblad *Talen* | `admin/settings.php`, `api/admin/update-language-settings.php` |
