# src/Service/Language

Meertaligheid. **Drie taalstaten die niets met elkaar te maken hebben**, en
die door elkaar halen is precies de fout die hier eerder is gemaakt:

1. de taal van de **bezoeker** op de publieke site (`data-nl` / `data-en`);
2. de taal waarin het **CMS aan de beheerder wordt getoond**;
3. de taalversie van de **inhoud die hij bewerkt**.

- Een lege vertaling betekent voor de bezoeker "gelijk aan de standaardtaal"
  en voor de redacteur een leeg veld. Nooit andersom.
- Het talenregister is een gesloten lijst.
- De standaardtaal van de website staat in het register `site_languages`
  (`SiteLanguages`), niet in `site_settings`. `ContentLanguages` is de
  V1-adapter erop. Zie `docs/multilingual/ARCHITECTURE.md`.
- `SiteLanguages` en `AdminLocale` noemen elkaar niet: websitetaal is geen
  CMS-taal.
- Van bewerktaal wisselen mag nooit iets weggooien.

Lees `../../../MULTILINGUAL.md`. Meertaligheid is Core en raakt elk domein,
dus het heeft geen eigen suite: de tests zitten in `fast` en `cms`.
