# src/Service/PageTemplates

Paginasjablonen: welke blokken een verse pagina meekrijgt bij *Nieuwe pagina*.

**Alleen op het moment van aanmaken.** Daarna is het een gewone pagina die
niets meer weet van het sjabloon waar hij uit kwam. Een sjabloon is dus geen
paginatype, en er komt nooit een `template`-kolom op `pages`.

- Een sjabloon roept gewoon `create()` van een blokdefinitie aan. Het vult
  zelf geen tekst in: die startinhoud is van het blok.
- Een sjabloon mag alleen een blok noemen dat `manual_add` is, en niet vaker
  dan `max_instances` toestaat.
- Het register is een expliciete, gesloten lijst.

Lees `../../../PAGE-TEMPLATES.md`.
