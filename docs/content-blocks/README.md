# CMS content-blokken

De refactor naar herbruikbare CMS content-blokken is **afgerond** (2026-09-08,
vier fases). Deze map beschrijft de architectuur die daaruit is gekomen en de
keuzes erachter — geen lopend project meer.

## Deze map is achtergrond, niet het startpunt

Voor werk aan een content-blok:

1. `../../CLAUDE.md` — de wegwijzer;
2. `../../CONTENT-BLOCKS.md` — de onderdelen van een blok, het
   inhoudscontract en het recept om er een toe te voegen;
3. de code en tests van het blok zelf;
4. `ARCHITECTURE.md` en `DECISIONS.md` hier, zodra je wilt weten waaróm het zo
   werkt of een architecturale keuze raakt.

Lees `ARCHITECTURE.md` en `DECISIONS.md` dus **niet** standaard. Ze
beantwoorden "waarom", niet "hoe", en de meeste taken hebben dat antwoord niet
nodig.

`ROADMAP.md`, `PHASE-1.md` t/m `PHASE-4.md` en `../CMS_CONTENT_AUDIT.md` zijn
verwijderd. Hun blijvende inhoud staat in `ARCHITECTURE.md` en `DECISIONS.md`;
oudere code- en testcommentaren die ernaar verwijzen bedoelen die twee
bestanden. Zie `../../PROJECT-MAP.md`, "Verwijzingen naar bestanden die hier
niet bestaan".

Inspecteer de echte code voordat je iets wijzigt. `SectionRegistry` en de
blokdefinities zijn de bron van waarheid voor bloktypes, niet dit document:
wijkt de code af, pas dan het document aan (of noteer de afwijking in
`DECISIONS.md`), niet de code aan het document.
