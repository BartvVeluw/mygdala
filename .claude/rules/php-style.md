---
paths:
  - "**/*.php"
---

# PHP in dit project

- **Taal.** Docblocks en commentaar Engels. Teksten die een beheerder in het
  CMS ziet Nederlands. Prozadocumentatie Nederlands.
- **`declare(strict_types=1);`** in nieuwe klassen en in elk endpoint.
  `final class` tenzij er een reden is om overerving toe te staan.
- **Alle SQL in een repository**, in een prepared statement. Een `*Content`-klasse
  leest, een repository query't, een partial rendert.
- **Alle output door `htmlspecialchars()`.** Rich text door
  `RichTextSanitizer`, nooit rechtstreeks naar de pagina.
- **Een request bepaalt nooit een prijs, een bestemming of een klassenaam.**
  Prijzen worden herberekend uit de database. Registers zijn gesloten lijsten:
  geen mapscan, geen reflectie.
- **Voeg geen abstractie toe zonder concrete aanleiding.** Volg het patroon
  van de buurman en noem hem in je docblock.
- Commentaar legt uit **waaróm**, en schrijft op wat er nadrukkelijk *niet*
  in dit bestand hoort.

Volledig: `CODE-STYLE.md`. Uitgebreider nakijken: roep `/style` aan.
