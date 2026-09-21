# De talen van de website

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Laag 3, de taal van de
bezoeker, en wat een site zelf over zijn talen vastlegt: het talenregister,
de standaardtaal, de module Meertaligheid, de terugvalregel en wat de
publieke website afdrukt. De taal van het CMS staat in
[`CMS-LANGUAGE.md`](CMS-LANGUAGE.md), de bewerktaal in
[`EDITING-LANGUAGE.md`](EDITING-LANGUAGE.md), URL's per taal in
[`ROUTING.md`](ROUTING.md).

## Het talenregister

De talen van de website zijn de rijen van **`site_languages`**
(`App\Service\Language\SiteLanguages`, `App\Repository\SiteLanguageRepository`)
en niets anders. Een taal toevoegen is een rij, geen codewijziging: Duits is
`de` met een naam en een eigen naam, net als `nl` en `en`.

```text
code   name      native_name   is_default   is_active   sort_order
nl     Dutch     Nederlands    1            1           0
en     English   English       NULL         1           1
de     German    Deutsch       NULL         0           2
```

De regels staan in de SQL en in `SiteLanguages`, niet in een scherm:

- **Precies één standaardtaal**, en die is actief. Een nieuwe taal wordt nooit
  als standaard aangemaakt; alleen `setDefault()` verplaatst de standaard, en
  alleen naar een actieve taal.
- **De standaardtaal gaat niet uit en niet weg.** `deactivate()` en `delete()`
  matchen de standaardrij nooit.
- **Uitzetten verwijdert niets.** Een taal die uit staat houdt al zijn
  vertalingen en krijgt ze terug zodra hij weer aan gaat.
- **Een taal met woorden kan niet verwijderd worden.** Elke vertaaltabel
  verwijst naar `site_languages.code` met `ON DELETE RESTRICT`
  (21 tabellen, van `page_translations` tot `order_item_translations`).
  Verwijderen kan alleen voor een taal die uit staat, niet de standaard is en
  nog nergens een woord heeft — een verkeerd getypte taal, bijvoorbeeld.
  Voor alles daarna is er uitzetten.

`LanguageRegistry` is iets anders: de talen die het **CMS zelf** spreekt
(de interfacetalen van `AdminLocale`, hun namen, de DeepL-codes). Een
websitetaal wordt daar nooit uit gekozen en hoeft er niet in te staan.

## Talen beheren

*Site-instellingen → Talen* (`admin/settings.php`, permissie
`settings.manage`). Alles hier gaat via `SiteLanguages`, en elke weigering is
een melding, nooit een halve wijziging.

| Handeling | Endpoint | Regel |
|---|---|---|
| Taal toevoegen (code, naam, eigen naam) | `create-website-language.php` | twee letters (`LanguageCode`); start **uit** |
| Namen wijzigen | `update-website-language.php` | beide ingevuld, hooguit 64 tekens, geen `<`/`>` |
| Aan- of uitzetten | `toggle-website-language.php` | nooit de standaardtaal uit |
| Standaard maken | `update-language-settings.php` | alleen een actieve taal |
| Volgorde | `move-website-language.php` | één plek per klik; de volgorde van de taalkeuze en de editors |
| Verwijderen | `delete-website-language.php` | alleen uit, niet standaard, zonder woorden |
| Meertaligheid aan/uit | `update-multilingual-publishing.php` | zie hieronder; een omgevingsvariabele wint |

Een nieuwe taal staat eerst uit, zodat je kunt vertalen voordat een bezoeker
hem ziet. Aanzetten publiceert hem meteen: eigen URL's (`/de/...`), een plek
in de taalkeuze, de sitemap en hreflang. Wat nog niet vertaald is, valt op de
standaardtaal terug.

`Tests\Service\WebsiteLanguageAdminHttpTest` loopt de hele levenscyclus en
de vier guards van elk endpoint af.

## De module Meertaligheid

`App\Module\MultilingualModule`, sleutel `multilingual`
(`MODULE_MULTILINGUAL_ENABLED`, [`MODULES.md`](../../MODULES.md)). Hij beslist
één ding: of de website méér dan zijn standaardtaal publiceert.

**Op een nieuwe installatie staat hij uit**: een nieuwe site publiceert zijn
standaardtaal tot iemand om meer vraagt — in de installatiewizard, onder
*Talen* of met de omgevingsvariabele. **Elke bestaande installatie houdt hem
aan**: migratie `20260921100000_pin_the_multilingual_module_where_it_is_in_use`
slaat "aan" op voor een installatie van vóór de installatiemarker en voor een
verse installatie waarvan de wizard al klaar was, want die publiceerden
allemaal Nederlands en Engels. Een nieuwe standaard geldt nooit met
terugwerkende kracht (hetzelfde patroon als de Portfolio-pin).

**Eén vraag, op één plek.** `SiteLanguages::active()` is "de talen die de
website nu publiceert": de actieve rijen zolang de module aan staat, alleen de
standaardtaal als hij uit staat. `SiteLanguages` vraagt dat aan het
moduleregister op capaciteit
(`ModuleRegistry::publishesTranslations()` →
`ModuleDefinition::publishesTranslations()`), nooit op sleutel. Elke route,
taalkeuze, editor en elk endpoint vraagt `SiteLanguages`, dus uitzetten raakt
ze allemaal tegelijk zonder dat één ervan de module kent.

Met de module **uit**:

- zijn alleen de URL's van de standaardtaal er; `/en/...` is een gewoon pad
  (en dus meestal een 404);
- is er geen taalkeuze, geen hreflang en geen `x-default`, en is de sitemap
  eentalig;
- stuurt `Accept-Language` of een opgeslagen voorkeur niemand naar een andere
  taal;
- bewerken redacteuren alleen de standaardtaal (geen schakelaar in de schil),
  en weigeren de schrijf-endpoints een andere taal;
- blijven het register, de eigen aan/uit-vlag van elke taal en **elke
  vertaling** staan. Aanzetten brengt dezelfde talen terug op dezelfde
  adressen, met dezelfde woorden.

Het register zelf (welke talen er zijn, welke de standaard is) blijft beheerd
met de module aan of uit: een website heeft altijd een standaardtaal.

`Tests\Module\MultilingualModuleTest` en `MultilingualModuleHttpTest`
bewaken uit → aan → uit → aan, over echte HTTP met de dispatcher ervoor.

## De terugvalregel

Eén regel, voor elk domein:

```text
gevraagde taal   →  eigen woorden
leeg             →  de woorden van de standaardtaal
leeg             →  ''
```

Hij staat één keer, in `App\Service\Language\LanguageFallback::resolve()`,
en elke domein-API past hem toe: `PageLocalization`, `BlockLocalization`,
`NavigationLocalization`, `FooterLocalization`, `LocalizedSiteSettings`,
`FormLocalization`, `PortfolioLocalization`, `BlogLocalization`,
`ShopLocalization`, `PersonalizationLocalization`. Rich text wordt per taal
gesaneerd vóór de terugval, zodat markup die tot niets saneert geen vertaling
telt.

**Terugval is voor de bezoeker; een redacteur ziet een leeg veld.** De
editors lezen de opgeslagen woorden (`raw()`), nooit de teruggevallen waarde,
want de eerstvolgende Opslaan zou die als echte vertaling wegschrijven.

```text
                        Nederlands (standaard)   Engels
opgeslagen              "Neem contact op"        —
bezoeker leest EN   ->  "Neem contact op"        resolve()
redacteur bewerkt EN -> leeg veld                raw()
```

**De standaardtaal beslist of iets bestaat.** Een blok, een knop of een item
zonder woorden in de standaardtaal toont in géén taal, ook niet op `/en/` —
een vertaling zonder de woorden van de standaardtaal zou voor iedereen die de
standaardtaal leest een lege versiering zijn (`BlockLocalization::hasDefaultWords()`).

## Wat de publieke website afdrukt

**Eén taal per antwoord, op de server beslist.** Sinds Multilingual 2.0 fase 7
krijgt de browser uitsluitend de woorden van de taal van de URL
(`App\Service\Routing\RequestLanguage`). Er is geen tekstwissel in de browser
meer: geen `data-nl`/`data-en`/`data-lang-html`, geen `applyLang()` in
`core.js`, geen `localStorage`-taal. De taalkeuze is een rij links naar de
versie van dezelfde pagina in een andere taal (`LanguageSwitch`,
[`ROUTING.md`](ROUTING.md)).

- **Inhoud** komt uit de domein-API's hierboven, in de taal van het verzoek,
  één string per veld. Platte tekst gaat door `htmlspecialchars()`, attributen
  door dezelfde escaping, rich text alleen als gesaneerde markup.
- **Systeemteksten** — "Winkelwagen", "Kruimelpad", de cookiebanner, de
  foutmeldingen van een formulier, een maandnaam — staan in kleine, gesloten
  **codecatalogi** naast de code die ze gebruikt, per taalcode:
  `SiteText::pick(['nl' => '…', 'en' => '…'])` of `::escaped()`. Een taal
  zonder eigen zin leest die van de standaardtaal; een derde taal is een
  sleutel erbij, nooit een `if`. Voorbeelden: `RouteRegistry` (routelabels),
  `CookieConsentConfig`, `PersonalizationColors`, `ShippingProfile`,
  `ShippingCountries`, `PickupLocation`, `BlogContent` (maanden).
- **Scripts** kiezen geen taal. Wat `cart.js`, `shop.js` en
  `personalization.js` in hun eigen markup zetten, komt uit een catalogus die
  de server voor het verzoek oplost (`ShopScriptText` als JSON-datablok in de
  mini-winkelwagen, `PersonalizationScriptText` in de configuratie van het
  paneel). JSON-API's antwoorden in de taal die de pagina meestuurt (`?lang=`,
  `App\Service\Routing\ApiLanguage`: alleen een gepubliceerde taal, anders de
  standaardtaal) met één waarde per veld.
- **De winkelwagen** in `localStorage` is taalneutraal waar het telt: een
  regel is zijn product, variant en personalisatie. `name` + `lang` zijn
  weergave, en een pagina in een andere taal leest de namen één keer opnieuw
  per id. Een wagen van vóór fase 7 (`name` + `name_en`) wordt gelezen en in
  de nieuwe vorm teruggeschreven.
- `<html lang>` en `data-url-prefix` volgen de taal van het verzoek. De
  laatste is er voor de scripts die zelf links bouwen.
- **De voorkeur van de bezoeker** staat in één first-party cookie
  (`site_language`, `LanguagePreference`) met een taalcode en verder niets, en
  beslist alleen iets op de siteroot.

**Geen adminvoorkeur raakt hier iets.** Niet de CMS-taal van een beheerder,
niet zijn bewerktaal.

`Tests\Service\MultilingualBoundaryTest` bewaakt dat geen publiek sjabloon
een taalpaar afdrukt en geen publiek script een taal wisselt;
`Tests\Service\ShopScriptTextContractTest` doet hetzelfde voor de
Shop-scripts en de winkelwagen.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Talenregister van de website | `src/Service/Language/SiteLanguages.php`, `src/Repository/SiteLanguageRepository.php` |
| De module Meertaligheid | `src/Module/MultilingualModule.php` (`ModuleDefinition::publishesTranslations()`) |
| Tabblad *Talen* | `admin/settings.php`, `api/admin/*-website-language.php`, `update-language-settings.php`, `update-multilingual-publishing.php` |
| Terugvalregel | `src/Service/Language/LanguageFallback.php` |
| Systeemteksten | `src/Service/Language/SiteText.php` (`pick()`, `escaped()`) |
| Scriptcatalogi | `src/Service/ShopScriptText.php`, `src/Service/Personalization/PersonalizationScriptText.php` |
| Taal van een JSON-verzoek | `src/Service/Routing/ApiLanguage.php` |
| De talen van het CMS zelf | `src/Service/Language/LanguageRegistry.php` + `LanguageDefinition.php` |
