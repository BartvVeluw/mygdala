# src/Service/Language

Meertaligheid. **Drie taalstaten die niets met elkaar te maken hebben**, en
die door elkaar halen is precies de fout die hier eerder is gemaakt:

1. de taal van de **bezoeker** op de publieke site (de taal van de URL,
   op de server gekozen);
2. de taal waarin het **CMS aan de beheerder wordt getoond**;
3. de taalversie van de **inhoud die hij bewerkt**.

- Een lege vertaling betekent voor de bezoeker "gelijk aan de standaardtaal"
  en voor de redacteur een leeg veld. Nooit andersom.
- `LanguageRegistry` is de gesloten lijst van talen die het **CMS zelf**
  spreekt. De websitetalen (`SiteLanguages`, `LanguageCode`) zijn dat niet:
  daar staat geen enkele taal in code, een taal is een rij.
- Een websitetaal valideer je nooit met `AdminLocale`, ook niet als de lijst
  toevallig klopt.
- De standaardtaal van de website staat in het register `site_languages`
  (`SiteLanguages`), niet in `site_settings`. Welke talen gepubliceerd
  worden vraagt `SiteLanguages::active()` aan de module Meertaligheid, op
  capaciteit (`ModuleRegistry::publishesTranslations()`), nooit bij naam. Zie
  `docs/multilingual/ARCHITECTURE.md`.
- `SiteLanguages` en `AdminLocale` noemen elkaar niet: websitetaal is geen
  CMS-taal.
- Van bewerktaal wisselen mag nooit iets weggooien.
- Paginatekst staat per websitetaal in `page_translations` en loopt alleen via
  `App\Service\PageLocalization`.
- **De terugval staat op één plek: `LanguageFallback`** (gevraagde taal,
  standaardtaal, leeg). Blokwoorden lopen via `BlockLocalization`; navigatie,
  footer, formulieren en gelokaliseerde instellingen via hun eigen dunne API op
  `TranslationTable` + `EntityTranslations`. Bouw hier geen tweede terugval en
  geen `LocalizationService` die alle domeinen kent.

Lees `../../../MULTILINGUAL.md`. Meertaligheid is Core en raakt elk domein,
dus het heeft geen eigen suite: de tests zitten in `fast` en `cms`.
