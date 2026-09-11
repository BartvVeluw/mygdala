# src/Service/Forms

Core Forms: veldtypes, leesmodel, validatie, spam-afweer, verwerking en veilig
verwijderen.

- **Core, geen module.** Weet niets van bestellingen, producten, Mollie of
  personalisatie, en dat blijft zo. `Tests\Service\FormBoundaryTest` faalt
  zodra een bestand hier een Shop-klasse of Shop-tabel noemt.
- **Validatie is server-side en loopt over de definitie**, nooit over wat het
  request meestuurt. Een veld dat niet in de definitie staat bestaat niet.
- Veldtypes zijn een gesloten lijst. Een type toevoegen is een eigen recept.
- Het formulier werkt ook **zonder JavaScript**. Houd dat zo.

Lees `../../../FORMS.md`, of roep `/forms` aan. De tests zitten in
`--testsuite cms`.
