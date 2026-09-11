---
name: style
description: De schrijfstijl van Mygdala - PHP-, CSS-, JS- en SQL-conventies, welke taal waar hoort (Nederlands proza, Engelse code, Nederlandse CMS-teksten), hoe commentaar geschreven wordt, en de vier beveiligingsregels. Gebruik dit bij het schrijven of reviewen van nieuwe code, bij het schrijven van teksten die een beheerder in het CMS ziet, bij twijfel over naamgeving of taal, en voordat je een bestaand bestand herschrijft.
---

# Stijl van Mygdala

Lees `CODE-STYLE.md` in de projectroot. Dat is het volledige document.

Houd tijdens het werk deze vijf in je hoofd:

1. **Taal.** Proza-documentatie Nederlands, code en commentaar Engels,
   CMS-teksten voor de beheerder Nederlands. Een `'label' => 'Veelgestelde
   vragen'` met een Engelse docblock erboven is correct.
2. **Commentaar legt uit waaróm.** Noem de klasse waar hetzelfde patroon al
   staat, noem het document, en schrijf op wat er nadrukkelijk *niet* in dit
   bestand hoort.
3. **De vier beveiligingsregels.** Alles door `htmlspecialchars()`; elk
   schrijfendpoint doet login, permissie, POST-check, CSRF in die volgorde;
   een request bepaalt nooit een prijs of een klassenaam; niet-publieke
   uploads staan in `storage/`.
4. **Eén eigenaar per frontendbestand**, gevraagd via `App\Service\PageAssets`.
   Geen handgeschreven `<link>` of `<script>` in een template.
5. **Gesloten lijsten blijven gesloten.** Geen mapscan, geen reflectie, geen
   klassenaam uit een request of een databaserij.

## Voordat je klaar bent

Loop dit na op wat je geschreven hebt:

- Staat er een `declare(strict_types=1);` bovenaan? Is de klasse `final`?
- Zit alle SQL in een repository, in een prepared statement?
- Heeft elke nieuwe tekst voor de beheerder een Nederlandse formulering
  zonder jargon en zonder type-key?
- Heeft een nieuw CSS-bestand een banner die zegt wie de eigenaar is en wat
  er niet in hoort?
- Als je iets bewust anders deed dan de buurman: staat er een regel
  commentaar die uitlegt waarom?
