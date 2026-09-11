# src/Module

Het moduleregister en de drie first-party modules. Eén repository, één
deploybare applicatie: een **modulair monoliet**.

- Een module uitzetten betekent dat hij **niets bijdraagt**: geen
  adminonderdelen, geen permissies, geen routes, geen blokken, geen
  sitemapregels, geen frontendbestanden. Het verwijdert niets en raakt de
  database nooit aan.
- `ModuleRegistry::MAP` is een expliciete, gesloten lijst.
- De volgorde van `ModuleConfig`: de omgevingsvariabele, dan de opgeslagen
  voorkeur, dan de eigen standaard van de module. De Blog is de enige die
  standaard uit staat.
- `ModuleGuard` is het regeltje bovenaan een **publieke** route of endpoint
  van een module. Adminschermen met een eigen permissie hebben niets nodig:
  een permissie van een uitgeschakelde module wordt door niemand gehouden,
  ook niet door een Super Admin.
- Moet Core iets van een module weten, voeg dan een bijdrage toe aan
  `ModuleDefinition`. Nooit een tweede `if` op een domeinnaam.

Lees `../../MODULES.md`. Test met `--testsuite modules`.
