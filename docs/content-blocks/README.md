# CMS content-blokken

De refactor naar herbruikbare CMS content-blokken is **afgerond** (2026-09-08,
vier fases). Deze map beschrijft de architectuur die daaruit is gekomen en de
keuzes erachter — geen lopend project meer.

## Voor een volgende Claude-chat

Deze map is de **achtergrond**, niet het startpunt. Voor werk aan een
content-blok:

1. `../../PROJECT-MAP.md` — wat de applicatie is en waar iets staat;
2. `../../CONTENT-BLOCKS.md` — de onderdelen van een blok, het inhoudscontract
   en het recept om er een toe te voegen;
3. de code en tests van het blok zelf;
4. `ARCHITECTURE.md` en `DECISIONS.md` hier, zodra je wilt weten waaróm het zo
   werkt of een architecturale keuze raakt;
5. `MAIN.MD` alléén als je historische implementatiecontext nodig hebt — dat
   bestand is honderden kilobytes projecthistorie en hoort niet bij de
   standaard leesroute.

Historie, dus alleen bij een gerichte vraag: `ROADMAP.md` (de vier fases) en
`../CMS_CONTENT_AUDIT.md` (de inventarisatie van vóór het CMS).

Inspecteer de echte code voordat je iets wijzigt. `SectionRegistry` is
de bron van waarheid voor bloktypes, niet dit document: wijkt de code af, pas
dan het document aan (of noteer de afwijking in `DECISIONS.md`), niet de code
aan het document.

De losse `PHASE-1.md` t/m `PHASE-4.md` (implementatie-instructies per fase)
zijn verwijderd nadat hun blijvende inhoud in `ARCHITECTURE.md` en
`DECISIONS.md` was opgenomen. Oudere code- en testcommentaren verwijzen er nog
naar; die verwijzingen bedoelen wat nu in die twee bestanden staat.

Werk standaard direct op `main` (zie `MAIN.MD`, "Git workflow").
