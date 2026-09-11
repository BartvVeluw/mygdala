# src/Service/Blocks

Eén blokdefinitie per bloktype, plus `BlockDefinitions` — dé registratielijst.
Een blokdefinitie is het **integratiecontract**, niet de logica: ze koppelt de
repository, de `*Content`-klasse, de partial, de admin-editor en de assets aan
het CMS.

- Alle methodes van `BlockDefinition` zijn `abstract`, dus je kunt er geen
  vergeten. Laat je er één weg, dan laadt de klasse niet.
- `BlockDefinitions` is een **expliciete, gesloten lijst**. Geen mapscan, geen
  reflectie. Shop- en Blog-blokken staan niet hier maar in hun module.
- `BlockCategories` en `BlockPreview` zijn gesloten lijstjes. Verzin er geen
  waarde bij zonder ze uit te breiden.

Lees `../../../CONTENT-BLOCKS.md` voor het volledige recept, of roep
`/content-block` aan. Test met `--testsuite blocks`.
