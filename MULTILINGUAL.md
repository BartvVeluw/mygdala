# Meertaligheid

Welke talen dit CMS kent, wie ze kiest, hoe ze worden opgeslagen en hoe
automatisch vertalen werkt. Dit bestand is het overzicht: het model, de regels
die altijd gelden en welk document je voor je taak opent. De uitwerking staat
per onderwerp in `docs/multilingual/`; open alleen het document dat je taak
raakt. Wijkt de code af van deze documenten, dan heeft de code gelijk — pas
het document aan.

## Drie talen die niets met elkaar te maken hebben

Dit is het model. Alles in `docs/multilingual/` is uitwerking.

```text
1. CMS-taal            waarin het beheerpaneel aan JOU wordt getoond
                       per beheerder      admin_users.interface_language
                       AdminLocale        Mijn account

2. Bewerktaal          WELKE TAALVERSIE VAN DE WEBSITE-INHOUD je bewerkt
                       per beheerder      admin_users.content_editing_language
                       ContentEditingLanguage   de schakelaar in de CMS-schil

3. Bezoekerstaal       waarin een BEZOEKER de website leest
                       per bezoeker       localStorage `vvl-lang`
                       assets/js/core.js  de NL|EN-knoppen in de header
```

**Alle drie zijn onafhankelijk. Alle vier de combinaties van 1 en 2 moeten
kunnen, en kunnen:**

```text
CMS-taal = Nederlands   +   Bewerktaal = NL     Nederlands CMS, Nederlandse velden
CMS-taal = Nederlands   +   Bewerktaal = EN     Nederlands CMS, Engelse velden
CMS-taal = English      +   Bewerktaal = NL     Engels CMS, Nederlandse velden
CMS-taal = English      +   Bewerktaal = EN     Engels CMS, Engelse velden
```

En laag 3 beweegt met geen van beide mee. Een beheerder die zijn CMS op Engels
zet verandert niets aan wat een bezoeker op dat moment leest, en een beheerder
die naar de Engelse bewerktaal wisselt ook niet.

Dat is geen belofte maar een eigenschap van de code: het endpoint dat een
CMS-taal schrijft kan `site_settings` niet bereiken, het endpoint dat de
bewerktaal schrijft kan noch `site_settings` noch de CMS-taalkolom bereiken,
en de publieke taalwissel vraagt geen van beide iets.
`Tests\Service\MultilingualBoundaryTest` laat de build vallen zodra een van de
drie een van de andere noemt, en
`Tests\Service\ThreeLanguageStatesTest` loopt de hele matrix af.

Waarom de bewerktaal eigen staat is, en wat er daarvóór fout was:
[`MIGRATIONS.md`](docs/multilingual/MIGRATIONS.md), "Wat hiervóór fout was".

## Regels die altijd gelden

- **Leeg is voor de bezoeker terugval, voor de redacteur een leeg veld.**
  `LocalizedValue::in()` tegenover `::raw()`; een teruggevallen waarde wordt
  nooit opgeslagen. → [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md)
- **Het talenregister is gesloten.** Een taalcode raakt pas een kolomnaam als
  `LanguageRegistry::has()` of `::get()` hem kent. → [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md)
- **Nederlands en Engels zijn er altijd allebei.** Een site kiest alleen zijn
  standaardtaal, en die staat sinds Multilingual 2.0 fase 1 in het
  talenregister `site_languages`, niet in een instelling.
  → [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md),
  [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md)
- **De websitetaal is geen CMS-taal.** `SiteLanguages` en `AdminLocale` lezen
  elkaars opslag nooit. → [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md)
- **Van bewerktaal wisselen gooit niets weg en vertaalt niets.**
  → [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md)
- **Verplicht is alleen de hoofdtaal**, en `required` komt in een taalveld
  altijd uit `admin_lang_required()`. → [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md)
- **Een adminscherm of endpoint schrijft geen eigen Nederlandse zin uit**; het
  vraagt een sleutel uit de catalogus. → [`CMS-LANGUAGE.md`](docs/multilingual/CMS-LANGUAGE.md)
- **De CMS-interface wordt nooit machinaal vertaald**, automatisch vertalen
  slaat zelf niets op en de API-sleutel blijft server-side.
  → [`AUTOMATIC-TRANSLATION.md`](docs/multilingual/AUTOMATIC-TRANSLATION.md)

## Welk document

| Je taak | Lees | Draai |
|---|---|---|
| Een zin op een adminscherm of in de melding van een endpoint, een statuswoord, een zijbalklabel, het label van een blok, permissie of sjabloon | [`CMS-LANGUAGE.md`](docs/multilingual/CMS-LANGUAGE.md) | `fast` |
| Het scherm *Mijn account* | [`CMS-LANGUAGE.md`](docs/multilingual/CMS-LANGUAGE.md) | `fast` → `cms` |
| Een editor of schrijf-endpoint met `_nl`/`_en`-velden, de schakelaar *Content bewerken*, een overzicht van zulke rijen | [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md) | `fast` → `blocks` |
| Wat een bezoeker ziet: de publieke taalwissel, `data-nl`/`data-en`, `SiteText`, de hoofdtaal, de terugvalregel, het talenregister | [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md) | `fast` |
| Het tabblad *Talen* in de instellingen | [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md) | `fast` → `cms` |
| De vertaalknop, een vertaalprovider, DeepL, de vertaalstatus | [`AUTOMATIC-TRANSLATION.md`](docs/multilingual/AUTOMATIC-TRANSLATION.md) | `fast` |
| Een migratie, wat een verse of bestaande installatie krijgt | [`MIGRATIONS.md`](docs/multilingual/MIGRATIONS.md) | `migration` |
| Multilingual 2.0: het talenregister, de standaardtaal, `SiteLanguages`, en wat de volgende fases moeten volgen | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `migration` |
| Paginatekst per taal: `page_translations`, `PageLocalization`, de terugval, de editorcomponent `_localized_fields.php` | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `cms` |
| Blokwoorden per taal: `block_translations`, `BlockLocalization`, `translatableFields()` en `childTables()`, de wezen-guards, alle contentblokken en hun kindrijen | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `blocks` |
| Navigatie, footer, formulieren en gelokaliseerde site-instellingen per taal: de getypeerde `*_translations`-tabellen, `LocalizedSiteSettings`, de waarde en het label van een keuze-optie | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `cms` |
| Woorden van een module per taal: `PortfolioLocalization`, `BlogLocalization`, `ShopLocalization`, en waarom `OrderItemNameSnapshot` een momentopname is en geen vertaling | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → de suite van de module |
| Welke test wat bewaakt, en hoe je ze draait | [`TESTS.md`](docs/multilingual/TESTS.md) | — |

Een derde taal toevoegen ligt op de grens: het register in
[`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md) plus opslag
ervoor, zie [`MIGRATIONS.md`](docs/multilingual/MIGRATIONS.md).

## Raakt je taak meertaligheid?

Een Shop-, Blog- of Forms-taak opent deze documenten alleen als hij een van
deze drie dingen doet:

- een zin toevoegen die een beheerder leest — op een scherm, in de melding van
  een endpoint of als zijbalkentry → `CMS-LANGUAGE.md`;
- een `_nl`/`_en`-veld toevoegen aan een editor of een schrijf-endpoint →
  `EDITING-LANGUAGE.md`;
- tekst met een `_nl`/`_en`-paar publiek afdrukken → `WEBSITE-LANGUAGES.md`.

Doet de taak geen van drieën, open dan niets hiervan.

## Automatisch vertalen

Geldt uitsluitend voor website-inhoud, nooit voor de CMS-interface. De lagen
van editor tot provider, de vier regels, de vertaalstatus, rich text en het
instellen van DeepL staan in
[`docs/multilingual/AUTOMATIC-TRANSLATION.md`](docs/multilingual/AUTOMATIC-TRANSLATION.md).

## Wat V1 bewust niet doet

- **Geen `/en/`- of `/nl/`-URL's en geen hreflang.** Beide talen wonen op één
  URL en wisselen in de browser, precies zoals eerder (`SEO.md`). Een
  Multilingual V2 kan gelokaliseerde URL's introduceren zodra het
  inhoudsmodel zich bewezen heeft; dit is uitgesteld en niet vergeten.
- **Geen taaldetectie op IP of browser.** Een bezoeker krijgt de hoofdtaal van
  de site, tenzij hij zelf wisselt.
- **Geen generieke vertaaltabellen.** De bestaande kolommen blijven de opslag.
  Hoe Multilingual 2.0 dat per fase vervangt, staat in
  [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md).
- **Geen slugs, e-mailadressen, telefoonnummers, bestandsnamen, mediapaden,
  CSS, code, SKU's, id's, module-instellingen of gebruikersnamen vertalen.**
- **Geen vertaaldashboard** en geen hele-site-bulkvertaling. De provider-laag
  is er wel op gebouwd: een toekomstige "vertaal alles wat nog ontbreekt" is
  een nieuwe aanroeper van dezelfde `TranslationService`.
- **Geen tien talen in de editors.** Twee, met een register dat er meer aankan.
- **Geen instelling die een taal uitzet.** Dit product is NL + EN. Het
  talenregister kent wel `is_active`, maar tot de frontend-flip van
  Multilingual 2.0 verbergt dat geen wissel en geen veld.
- **Geen automatische vertaling van de CMS-interface zelf.**

## Hoofdstukken van vóór de opsplitsing

Dit bestand bevatte eerder de hele uitwerking. Code- en testcommentaar dat
`MULTILINGUAL.md` noemt, bedoelt het onderwerp; zo vind je een oud hoofdstuk
terug. Elk document eindigt met zijn eigen *Waar het staat*.

| Oud hoofdstuk | Staat nu in |
|---|---|
| Drie talen die niets met elkaar te maken hebben; Wat V1 bewust niet doet | dit bestand |
| Het talenregister; De talen van de website; De terugvalregel; De publieke website | `docs/multilingual/WEBSITE-LANGUAGES.md` |
| Het CMS in het Nederlands of het Engels | `docs/multilingual/CMS-LANGUAGE.md` |
| De taalwissel in het CMS; Bewerken in één taal tegelijk | `docs/multilingual/EDITING-LANGUAGE.md` |
| Automatisch vertalen; DeepL instellen | `docs/multilingual/AUTOMATIC-TRANSLATION.md` |
| Wat hiervóór fout was; Wat er met een bestaande site gebeurt | `docs/multilingual/MIGRATIONS.md` |
| Testen | `docs/multilingual/TESTS.md` |

Twee docblocks noemen een Engelse hoofdstuknaam die nooit bestaan heeft:
"What V1 does not do" en "URLs and hreflang are deferred" bedoelen allebei
*Wat V1 bewust niet doet*, hierboven.
