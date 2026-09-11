# src/Service/Media

De Mediabibliotheek: herbruikbaar publiek sitebeeld, met identiteit,
alt-tekst, hergebruik en veilig verwijderen.

- **Core, en dat blijft zo.** Weet van media en van niets anders. Welke
  *feature* een item gebruikt vraagt zij aan de eigenaar van die feature via
  `mediaUsageProviders()` — noem hier nooit een product, collectie of blogpost
  bij naam.
- Niet-publieke uploads (contactbijlagen, personalisatiebestanden) horen hier
  **niet**: die staan in `storage/`, één map boven de projectroot.
- Alt-tekst is gelaagd: het item heeft er een, de gebruiksplek mag hem
  overschrijven.
- Verwijderen is alleen veilig als niemand het item meer gebruikt. Vraag dat
  aan de usage-providers, raad het niet.

Lees `../../../MEDIA.md`.
