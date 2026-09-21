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
                       per VERZOEK        het taalsegment in de URL
                       RequestLanguage    de taallinks in de header
```

**Alle drie zijn onafhankelijk. Elke combinatie van 1 en 2 moet kunnen, en
kan:** een Nederlands CMS dat de Engelse versie bewerkt is een gewone dinsdag.

En laag 3 beweegt met geen van beide mee. Een beheerder die zijn CMS op Engels
zet verandert niets aan wat een bezoeker op dat moment leest, en een beheerder
die naar de Engelse bewerktaal wisselt ook niet.

Dat is geen belofte maar een eigenschap van de code: het endpoint dat een
CMS-taal schrijft kan het talenregister niet bereiken, het endpoint dat de
bewerktaal schrijft kan noch het register noch de CMS-taalkolom bereiken, en
de publieke taalwissel vraagt geen van beide iets.
`Tests\Service\MultilingualBoundaryTest` laat de build vallen zodra een van de
drie een van de andere noemt, en `Tests\Service\ThreeLanguageStatesTest`
loopt de hele matrix af.

Waarom de bewerktaal eigen staat is, en wat er daarvóór fout was:
[`MIGRATIONS.md`](docs/multilingual/MIGRATIONS.md), "Wat hiervóór fout was".

## Regels die altijd gelden

- **De talen van de website zijn de rijen van `site_languages`**, en niets
  anders. Een taal toevoegen is een rij, geen codewijziging; er is precies één
  standaardtaal en die is actief. →
  [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md)
- **De module Meertaligheid beslist of de andere talen gepubliceerd worden.**
  Uit is alleen de standaardtaal publiek; geen vertaling verdwijnt. →
  [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md)
- **Eén taal per antwoord, op de server beslist.** De browser krijgt alleen de
  woorden van de taal van de URL; niets wisselt een document in de browser. →
  [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md),
  [`ROUTING.md`](docs/multilingual/ROUTING.md)
- **Leeg is voor de bezoeker terugval, voor de redacteur een leeg veld.**
  `LanguageFallback::resolve()` tegenover de opgeslagen woorden; een
  teruggevallen waarde wordt nooit opgeslagen. →
  [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md)
- **De standaardtaal beslist of iets bestaat.** Een blok, knop of item zonder
  woorden in de standaardtaal toont in geen enkele taal.
- **Systeemtekst is een gesloten codecatalogus per taalcode**
  (`SiteText::pick()`), met terugval op de standaardtaal; nooit een
  `if ($taal === 'de')` en nooit een opgeslagen `label_de`.
- **De websitetaal is geen CMS-taal.** `SiteLanguages` en `AdminLocale` lezen
  elkaars opslag nooit. → [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md)
- **Van bewerktaal wisselen gooit niets weg en vertaalt niets.**
  → [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md)
- **Verplicht is alleen de standaardtaal**; een vertaling is per definitie
  optioneel. → [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md)
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
| Een editor of schrijf-endpoint met tekst per taal, de schakelaar *Content bewerken*, `_localized_fields.php` | [`EDITING-LANGUAGE.md`](docs/multilingual/EDITING-LANGUAGE.md) | `fast` → `blocks` |
| Wat een bezoeker ziet: één taal per antwoord, systeemteksten, scriptcatalogi, de terugvalregel, de winkelwagen | [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md) | `fast` |
| Het tabblad *Talen*: talen toevoegen, aan/uit, standaard, volgorde, de module Meertaligheid | [`WEBSITE-LANGUAGES.md`](docs/multilingual/WEBSITE-LANGUAGES.md) | `fast` → `cms` → `modules` |
| URL's per taal, de dispatcher, taalresolutie, slugs per taal, canonical, hreflang, sitemap, door redacteuren getypte URL's | [`ROUTING.md`](docs/multilingual/ROUTING.md) | `fast` → `http` |
| De vertaalknop, een vertaalprovider, DeepL, de vertaalstatus | [`AUTOMATIC-TRANSLATION.md`](docs/multilingual/AUTOMATIC-TRANSLATION.md) | `fast` |
| Een migratie, wat een verse of bestaande installatie krijgt | [`MIGRATIONS.md`](docs/multilingual/MIGRATIONS.md) | `migration` |
| Het talenregister, de standaardtaal, `SiteLanguages`, de domein-API's per fase | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `migration` |
| Paginatekst, blokwoorden, navigatie, footer, formulieren, gelokaliseerde instellingen per taal | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → `cms`/`blocks` |
| Woorden van een module per taal (`PortfolioLocalization`, `BlogLocalization`, `ShopLocalization`), en waarom `OrderItemNameSnapshot` een momentopname is en geen vertaling | [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md) | `fast` → de suite van de module |
| Welke test wat bewaakt, en hoe je ze draait | [`TESTS.md`](docs/multilingual/TESTS.md) | — |

## Raakt je taak meertaligheid?

Een Shop-, Blog- of Forms-taak opent deze documenten alleen als hij een van
deze dingen doet:

- een zin toevoegen die een beheerder leest → `CMS-LANGUAGE.md`;
- een veld toevoegen dat per taal wordt opgeslagen, aan een editor of een
  schrijf-endpoint → `EDITING-LANGUAGE.md` en `ARCHITECTURE.md`;
- tekst publiek afdrukken — inhoud, een systeemzin of iets wat een script
  toont → `WEBSITE-LANGUAGES.md`;
- een publieke route, link of JSON-endpoint toevoegen → `ROUTING.md`.

Doet de taak geen van deze, open dan niets hiervan.

## Automatisch vertalen

Geldt uitsluitend voor website-inhoud, nooit voor de CMS-interface. De lagen
van provider tot endpoint, de vier regels, de vertaalstatus en het instellen
van DeepL staan in
[`docs/multilingual/AUTOMATIC-TRANSLATION.md`](docs/multilingual/AUTOMATIC-TRANSLATION.md).
Sinds de editors per taal werken (fases 2–5) heeft de vertaalknop geen scherm
meer; de service en het endpoint staan klaar voor een nieuwe aanroeper (zie de
backlog in [`ARCHITECTURE.md`](docs/multilingual/ARCHITECTURE.md)).

## Wat bewust niet

- **Geen taaldetectie op IP.** Geen GeoIP en geen externe dienst. Wél leest de
  siteroot de opgeslagen voorkeur en `Accept-Language`, en alleen daar; elke
  andere URL zonder prefix ís de URL van de standaardtaal.
- **Geen regionale codes, schrifttypen of RTL.** Een taalcode is precies twee
  kleine letters (`LanguageCode`); `pt-BR` of `zh-Hans` vallen erbuiten.
- **Geen slugs, e-mailadressen, telefoonnummers, bestandsnamen, mediapaden,
  CSS, code, SKU's, id's, module-instellingen of gebruikersnamen vertalen.**
- **Geen vertaaldashboard** en geen hele-site-bulkvertaling.
- **Geen automatische vertaling van de CMS-interface zelf.**

## Hoofdstukken van vóór de opsplitsing

Dit bestand bevatte eerder de hele uitwerking. Code- en testcommentaar dat
`MULTILINGUAL.md` noemt, bedoelt het onderwerp; zo vind je een oud hoofdstuk
terug. Elk document eindigt met zijn eigen *Waar het staat*.

| Oud hoofdstuk | Staat nu in |
|---|---|
| Drie talen die niets met elkaar te maken hebben; Wat V1 bewust niet doet | dit bestand ("Wat bewust niet") |
| Het talenregister; De talen van de website; De terugvalregel; De publieke website | `docs/multilingual/WEBSITE-LANGUAGES.md` |
| Het CMS in het Nederlands of het Engels | `docs/multilingual/CMS-LANGUAGE.md` |
| De taalwissel in het CMS; Bewerken in één taal tegelijk | `docs/multilingual/EDITING-LANGUAGE.md` |
| Automatisch vertalen; DeepL instellen | `docs/multilingual/AUTOMATIC-TRANSLATION.md` |
| Wat hiervóór fout was; Wat er met een bestaande site gebeurt | `docs/multilingual/MIGRATIONS.md` |
| Testen | `docs/multilingual/TESTS.md` |
| URL's per taal, de dispatcher, taalresolutie, slugs per taal, canonical, hreflang, sitemap | `docs/multilingual/ROUTING.md` |

Twee docblocks noemen een Engelse hoofdstuknaam die nooit bestaan heeft:
"What V1 does not do" en "URLs and hreflang are deferred" bedoelen allebei
*Wat bewust niet*, hierboven (vroeger *Wat V1 bewust niet doet*).
