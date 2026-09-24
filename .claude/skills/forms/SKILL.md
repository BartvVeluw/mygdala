---
name: forms
description: Werken aan Core Forms in Mygdala - formulierdefinities, velden en veldtypes, de publieke verwerking, validatie, spam-afweer, e-mailmeldingen, bewaarde inzendingen en het formulierblok. Zet de juiste bestandspaden, de grenzen en het testcommando klaar.
---

# Formulieren

Core Forms is **Core, geen module**. Het weet niets van bestellingen,
producten, personalisatie of Mollie, en werkt identiek met de Shop aan en uit.

## Lees dit eerst

`FORMS.md`.

## De paden

| Laag | Paden |
|---|---|
| Logica | `src/Service/Forms/` |
| Opslag | `src/Repository/Form{,Block,Submission}Repository.php` |
| Publieke kant | `partials/form.php`, `api/form-submit.php` |
| Adminschermen | `admin/forms.php`, `form.php`, `form-field.php`, `form-preview.php` (het voorbeeld), `form-block.php`, `form-submissions.php`, `form-submission.php`; gedeeld `admin/_form_fields.php` en `admin/assets/forms-admin.js` |
| Admin-endpoints | `api/admin/*form*.php`; een bestand downloaden: `api/admin/form-submission-attachment.php` |
| Uploadveld | `FieldTypes/FileFieldType.php`, `FormFileTypes`, `FormUploadInspector`, `FormUpload`; opslag `src/Service/ContactAttachmentStorage.php` |
| Blokken | `form_block` en `contact_form` |
| Archief | `ContactRequestRepository`, `admin/contact-requests.php`, `api/contact.php` — historisch, nieuwe inzendingen lopen via Forms |

## De regels die hier gelden

- **Validatie loopt over de definitie, niet over het request.** Een veld dat
  niet in de definitie staat bestaat niet, hoeveel het request er ook
  meestuurt. Dit geldt ook voor de ontvanger van de mail en voor de
  Reply-To.
- **Server-side, altijd.** Client-side validatie is een gemak, geen grens.
- **Het formulier werkt zonder JavaScript.** Houd dat zo.
- **Veldtypes zijn een gesloten lijst.** Een type toevoegen heeft een eigen
  recept in `FORMS.md`. Breedtes ook (`FormFieldWidth`): nooit pixels, een
  percentage of een class uit een request.
- **Het voorbeeld in de editor is de publieke renderer.** Geen tweede
  veldmarkup, in PHP noch in JavaScript (`admin/form-preview.php`).
- **Noem hier nooit een Shop-klasse of Shop-tabel.**
  `Tests\Service\FormBoundaryTest` faalt daarop.
- **Inzendingen zijn persoonsgegevens.** Verwijderen moet echt verwijderen,
  inclusief bestanden.
- **Een bestand is een veld.** Geen blok of scherm drukt een eigen
  bestandskiezer af, en `$_FILES` wordt alleen gelezen via de definitie
  (`FormFieldType::acceptsFile()`). Soorten en groottes komen uit
  `FormFileTypes`, nooit uit een request; een bestand wordt nooit onder de
  naam van de bezoeker opgeslagen en alleen als bijlage gedownload
  (`FORMS.md`, "Bestand uploaden").

## Testen

Formulieren hebben geen eigen suite en zitten in `cms`.

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
docker compose exec php php vendor/bin/phpunit --filter Form
```
