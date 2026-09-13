# Automatisch vertalen

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Geldt uitsluitend voor
website-inhoud; de CMS-interface wordt nooit machinaal vertaald. Van de
bewerktaal ([`EDITING-LANGUAGE.md`](EDITING-LANGUAGE.md)) is hier alleen nodig
dat de vertaalknop de taal vult die je op dat moment bewerkt.

## De lagen

```text
editor (blok, pagina, formulier)
   → api/admin/translate-fields.php     login, permissie, methode, CSRF
   → TranslationService                 de vier regels
   → TranslationProvider                het contract
   → DeepLProvider | NullTranslation…   de implementatie
```

Geen enkele editor noemt DeepL. Een tweede provider — een andere API, een
LLM — is één klasse plus één regel in `TranslationProviderFactory::MAP`.

## Welke richting, en wanneer

De vertaalknop vult **de taal die je op dat moment bewerkt**, uit de andere.

```text
bewerktaal = EN   ->   [ Vertalen vanuit het Nederlands ]
bewerktaal = NL   ->   [ Vertalen vanuit het Engels ]
```

Nederlands → Engels is het geval waar V1 om draait; de omgekeerde richting
werkt omdat `TranslationService` een bróntaal aanneemt in plaats van altijd de
hoofdtaal te veronderstellen, en omdat de provider het paar aankan.

**Van taal wisselen vertaalt niets.** De schakelaar in de schil schrijft één
kolom en herrendert het scherm; er gaat geen enkel woord naar buiten. Vertalen
is altijd een expliciete klik.
`Tests\Service\ThreeLanguageStatesTest::testSwitchingEditingLanguageTranslatesNothing`
is de test die dat vasthoudt.

## De vier regels

1. **Handmatige bewerkingen winnen.** Een vertaling die een mens heeft
   geschreven wordt niet vervangen zonder een expliciete bevestiging. De
   beslissing staat op één plek (`TranslationState::mayOverwrite()`), dus de
   bevestiging die het scherm vraagt en de weigering die de server uitvoert
   kunnen niet uit elkaar lopen.
2. **Alleen de eigen velden van dit item gaan naar buiten.** Geen id, geen
   CSRF-token, geen instelling, geen andere pagina, geen gebruikersgegevens.
   Ook de veldnamen niet: DeepL krijgt de wóórden, niet de kolomnamen van dit
   CMS.
3. **Wat terugkomt is onbetrouwbaar.** De HTML van een provider gaat door
   dezelfde `RichTextSanitizer` als HTML die een redacteur zelf typt. Een
   vertaaldienst is een derde partij, geen autoriteit.
4. **Er wordt niets opgeslagen.** Het endpoint geeft de vertaling terug aan het
   scherm; de redacteur slaat op met de gewone Opslaan-knop van het formulier,
   via het gewone endpoint met de gewone validatie en het gewone CSRF-token.
   Een vertaalknop die rechtstreeks naar de database schreef zou een tweede,
   zwakkere schrijfweg zijn naar élke contenttabel.

## Vertaalstatus

Vier toestanden, en de tabel `content_translation_state` bewaart **geen
vertaalde tekst**. De vertaling blijft staan waar hij altijd stond: in de
`_en`-kolom van de rij zelf.

| Toestand | Wat het betekent |
|---|---|
| ontbreekt | niets vertaald |
| automatisch | een provider schreef het en niemand raakte het daarna aan |
| handmatig | een mens schreef of corrigeerde het |
| mogelijk verouderd | de brontekst is gewijzigd ná de vertaling |

*Verouderd* wordt **berekend, nooit opgeslagen**: het is "de bewaarde hash komt
niet meer overeen met de brontekst die ik nu zie". Opslaan zou betekenen dat
elke bewerking van een bronveld eraan moest denken zijn vertalingen ongeldig
te maken, en degene die dat vergat zou een verouderde vertaling als actueel
tonen.

De rij bewaart twee hashes:

```text
source_hash        de brontekst op het moment van vertalen  →  verouderd?
translation_hash   wat de provider terúggaf                 →  met de hand aangepast?
```

Die tweede is waarom "handmatige bewerkingen winnen" werkt **zonder dat één
van de ±77 schrijf-endpoints iets van vertalen hoeft te weten**: staat er in de
kolom niet meer de tekst waar die hash bij hoort, dan heeft iemand hem
herschreven, en automatisch vertalen blijft er vanaf. De tekst zelf meldt de
bewerking.

Een hash is van de **brontekst alleen** en normaliseert witruimte, zodat een
alinea opnieuw laten lopen niet elke vertaling verouderd maakt. `updated_at`
vergelijken zou dat wél doen: die verspringt ook als iemand een blok versleept.

## Rich text

Bij een rich-text-veld gaat `tag_handling=html` mee, zodat DeepL de markup
zelf ontleedt, de tekstknopen vertaalt en dezelfde structuur teruggeeft. Tags,
attributen en links blijven staan. Er wordt **nooit** markup gestript, platte
tekst vertaald en de opmaak daarna opnieuw opgebouwd — dat verliest elke link
en elke opmaakkeuze van de redacteur. Wat terugkomt gaat alsnog door de
sanitizer (regel 3).

Platte en opgemaakte velden gaan in **aparte verzoeken**: door elkaar zou de
provider iemands `<` als een tag lezen, of hun tags als woorden.

## DeepL instellen

Optioneel. Een verse Mygdala start zonder vertaalcredentials, elke editor
werkt, handmatige vertalingen werken, en het enige verschil is dat de
vertaalknoppen niet worden aangeboden.

```env
TRANSLATION_PROVIDER=deepl
DEEPL_API_KEY=
DEEPL_API_PLAN=free
```

- **De sleutel blijft server-side.** Hij wordt door PHP uit de omgeving
  gelezen; niets in `admin/`, `partials/` of `assets/` mag hem noemen, en
  `MultilingualBoundaryTest` faalt als dat verandert.
- **Free en Pro zijn verschillende hosts**, niet een parameter:
  `api-free.deepl.com` tegenover `api.deepl.com`. Een sleutel op de verkeerde
  host wordt geweigerd. DeepL merkt zijn eigen gratis sleutels met `:fx`, en
  zo'n sleutel wint van een `DEEPL_API_PLAN` die iemand vergeten is bij te
  werken.
- **Authenticatie** is de header die DeepL vandaag documenteert:
  `Authorization: DeepL-Auth-Key <sleutel>`. Nooit in de URL — een querystring
  belandt in proxylogs en in browsergeschiedenis.
- **Een mislukking logt de statusregel en het endpoint**, nooit de sleutel en
  nooit de tekst die vertaald werd. Het concept van een redacteur is geen
  logmateriaal.

De suite praat **nooit** met een echte vertaal-API: `tests/Support/FakeTranslationProvider.php`
en twee subklassen die de ene HTTP-methode vervangen dekken elke tak.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Vertaalcontract | `src/Service/Translation/TranslationProvider.php` |
| Providers | `DeepLProvider.php`, `NullTranslationProvider.php`, `TranslationProviderFactory.php` |
| Vertaaldienst | `src/Service/Translation/TranslationService.php` |
| Vertaalstatus | `src/Service/Translation/TranslationState.php`, `src/Repository/TranslationStateRepository.php`, tabel `content_translation_state` |
| Vertaalendpoint | `api/admin/translate-fields.php` |
| Vertaalknop in een editor | `admin_lang_translate_bar()` in `admin/_language_fields.php`, `admin/assets/admin-language-translate.js` |
