# Formulieren

Genoeg om een formulier te bouwen, te plaatsen of uit te breiden **zonder de
code eerst helemaal te lezen**. Wijkt de code af van dit document, dan heeft
de code gelijk — pas het document aan.

Begin bij [`PROJECT-MAP.md`](PROJECT-MAP.md) als je nog niet weet waar iets
staat.

## Wat dit is

Core Forms is de formuliervoorziening van het CMS. Een beheerder maakt één
keer een formulier, en plaatst dat daarna met een contentblok op zoveel
pagina's als hij wil:

```text
Formulierdefinitie
    ↓
Velden
    ↓
Formulierblok op een pagina
    ↓
Publieke renderer
    ↓
Validatie
    ↓
Inzending
    ├── optioneel bewaard in het CMS
    └── melding per e-mail
```

Vóór deze stap bestond er precies één formulier, en dat stond op drie plekken
tegelijk: als markup in `partials/section-contact-form.php`, als validatie in
`api/contact.php` en als e-mailregels in `App\Mail\ContactRequestBuilder`. Een
veld toevoegen betekende drie bestanden bewerken zonder dat iets waarschuwde
als je er één oversloeg. Nu beantwoordt elk onderdeel zijn eigen vraag precies
één keer.

**Core, geen module.** Een CMS zonder formulieren bestaat niet, en Forms weet
niets van bestellingen, afrekenen, producten, personalisatie of Mollie. Het
werkt identiek met de Shop aan en uit (`MODULES.md`).

## Uit welke onderdelen het bestaat

| Onderdeel | Pad |
|---|---|
| Migraties + tabellen | `db/migrations/20260909300000_create_the_core_forms_tables.php`, `…310000_migrate_the_contact_form_into_a_form.php`, `…320000_add_a_default_choice_to_form_fields.php`; de woorden en opties per taal in `20260918140000_create_the_form_translation_and_option_tables.php` en `…150000_move_form_words_and_options_into_translation_tables.php`; de breedte van een veld in `20260924160000_give_form_fields_a_layout_width.php`; het uploadveld in `20260925100000_make_file_upload_an_ordinary_form_field.php` |
| Veldtypes (gesloten register) | `src/Service/Forms/FieldTypes/`, plus `FormFieldTypes` — dé registratielijst |
| Namen van veldtypes | `formfieldtype.<key>.label` en `.description` in `src/Service/Language/messages/` |
| Wat een typewissel kost | `FormFieldTypeChange` |
| Breedte van een veld (gesloten lijst) | `FormFieldWidth` |
| Bestandssoorten en -groottes van een uploadveld (gesloten lijsten) | `FormFileTypes` |
| Een geüpload bestand keuren | `FormUploadInspector`, `FormUpload` |
| Bestanden opslaan (buiten de webroot) | `src/Service/ContactAttachmentStorage.php` |
| Een bestand downloaden | `api/admin/form-submission-attachment.php` |
| Leesmodel | `FormDefinition`, `FormField`, `FormFieldOptions`, `FormOption` |
| Woorden per taal | `FormLocalization` (de drie vertaaltabellen), `src/Repository/FormFieldOptionRepository.php` |
| Opzoeken + cache | `FormCatalog` |
| SQL | `src/Repository/FormRepository.php`, `FormSubmissionRepository.php`, `FormBlockRepository.php`, `FormFieldOptionRepository.php` |
| Validatie | `FormValidator`, `FormValidationResult` |
| Verwerking | `FormSubmissionHandler`, `FormSubmissionContext`, `FormSubmissionOutcome` |
| Spam-afweer | `FormSpamGuard` |
| Ontvanger | `FormRecipient` |
| Gebruik en veilig verwijderen | `FormUsage` |
| Publieke renderer | `partials/form.php` (`render_form()`) |
| Publiek endpoint | `api/form-submit.php` |
| E-mail | `src/Mail/FormSubmissionBuilder.php` |
| Blok "Formulier" | `src/Service/Blocks/FormBlock.php`, `FormBlockContent`, `partials/section-form.php`, `admin/form-block.php` |
| Blok "Offerte-/contactformulier" | `src/Service/Blocks/ContactFormBlock.php`, `ContactFormContent`, `partials/section-contact-form.php`, `admin/contact-form.php` |
| Adminschermen | `admin/forms.php`, `admin/form.php`, `admin/form-field.php`, `admin/form-submissions.php`, `admin/form-submission.php`; het voorbeeld in de formuliereditor `admin/form-preview.php`; gedeeld: `admin/_form_fields.php` (typenamen, typekaarten, wat een wissel kost) en `admin/assets/forms-admin.js` |
| Frontend | `assets/css/blocks/form.css`, `assets/js/blocks/form.js` |
| Tests | `tests/Service/Form*.php` (het uploadveld: `FormUploadTest`, `FormUploadHttpTest`), `tests/Repository/ContactFormMigrationTest.php`, `tests/Install/FormWordsAndOptionMigrationTest.php`, `tests/Install/FormFieldLayoutWidthMigrationTest.php`, `tests/Install/FormFileUploadMigrationTest.php`; helper `tests/Support/FormFixture.php` |

## Het model

### `forms`

Wat het formulier is en wat het doet als iemand het verstuurt: naam (alleen
voor de beheerder), een gegenereerde `internal_key`, aan/uit, het ontvangende
e-mailadres, welk veld het antwoordadres levert, en of inzendingen bewaard
worden. Allemaal taalneutraal.

Wat de bezoeker leest staat ernaast, één rij per websitetaal: de tekst op de
knop (`submit_label`) en het bedankbericht (`success_message`) in
`form_translations`, via `App\Service\Forms\FormLocalization` (Multilingual
2.0 fase 4, `docs/multilingual/ARCHITECTURE.md`). Leeg betekent: de standaard
van het CMS.

De `internal_key` is **geen publieke sleutel**: hij staat in het verborgen
veld waarmee het endpoint weet welk formulier is verstuurd, en verder nergens.
Een formulier wordt geadresseerd door het blok dat het toont.

### `form_fields`

Eén rij per veld: `field_key`, `field_type`, verplicht ja/nee, de breedte
(`layout_width`, zie *Breedte van een veld*), volgorde, bij een keuzeveld
eventueel een standaardwaarde, en bij een uploadveld de toegestane soorten
(`file_types`) en de maximale grootte (`file_max_bytes`, zie *Bestand
uploaden*). Allemaal taalneutraal.

Wat de bezoeker leest staat in `form_field_translations`, één rij per
websitetaal: `label`, `placeholder` en `help_text`.

**Geen EAV.** Een veld is een rij met echte kolommen. De type-specifieke
instellingen zijn de twee kolommen van een uploadveld en de optielijst van een
keuzeveld, en die laatste is sinds Multilingual 2.0 fase 4 een echte
kindtabel:

```text
form_field_options              id, form_field_id, value, sort_order
form_field_option_translations  form_field_option_id, language_code, label
```

Daarvóór stonden de opties in één `TEXT`-kolom, één keuze per regel, `NL|EN`
met de Engelse helft optioneel. Dat kon niet mee naar een derde taal, en
belangrijker: de verstuurde waarde wás de Nederlandse tekst, zodat een
taalwissel de betekenis van een inzending veranderde. Zie *Een optie heeft een
waarde en een label* hieronder.

De redacteur ziet van dit alles niets: de veldeditor toont één rij per optie,
in de taal die hij bewerkt, en `api/admin/update-form-field.php` houdt de rijen
op hun `id` bij elkaar, met dezelfde regels voor lege en dubbele opties en het
maximum van vijftig.

### Een optie heeft een waarde en een label

| | Wat | Waar |
|---|---|---|
| **waarde** | de identiteit: wat het formulier post, wat een inzending bewaart, waar `default_value` naar wijst. In elke taal dezelfde, uniek per veld, byte voor byte vergeleken | `form_field_options.value` |
| **label** | wat de bezoeker leest | `form_field_option_translations.label`, één rij per taal |

Een taalwissel verandert dus wél wat er op het scherm staat en **nooit** wat er
verstuurd wordt. Een optie hernoemen in welke taal dan ook laat haar waarde,
haar plaats en de standaardkeuze die naar haar wijst staan.

De migratie `20260918150000` nam als waarde de Nederlandse helft van de oude
regel, byte voor byte, zodat bestaande defaults en alle bewaarde inzendingen
blijven kloppen. Een nieuwe optie krijgt het label in de standaardtaal als
waarde, zo nodig uniek gemaakt met ` (2)`.

**Een keuzeveld mag op een van zijn eigen opties beginnen** (`default_value`).
Dat is de enige vorm van vooringevulde inhoud die dit CMS kent, en met opzet:
een vooringevuld tekstveld bevat een antwoord dat de bezoeker nooit heeft
getypt en verstuurt dat gewoon mee, en een voorgevinkt akkoordvinkje is een
akkoord dat niemand heeft gegeven. Bij een handvol zichtbare, elkaar
uitsluitende keuzes is ergens beginnen een dienst in plaats van een verzonnen
antwoord — en de bezoeker kan altijd iets anders kiezen.

De waarde is **altijd een van de opties van dat veld**. Die regel staat op één
plek (`FormField::isUsableDefault()`) en werkt in twee richtingen: de
formuliereditor bewaart er nooit een die er niet bij staat, en het leesmodel
laat er een vallen die er ooit toch in kwam. De redacteur markeert hem op een
van de optierijen, nooit als vrije tekst (zie "De veldeditor" hieronder).

**`field_key` ligt vast zodra hij bestaat.** Hij wordt gegenereerd uit het
label (`FormFieldKey`), is uniek binnen het formulier, kan nooit botsen met de
eigen besturingsvelden van het formulier, en is daarna niet meer te wijzigen:
bewaarde antwoorden staan eronder opgeslagen. Het **label** mag wél gewoon
worden hernoemd.

### `form_submissions` + `form_submission_values`

Alleen als het formulier "inzendingen bewaren" aan heeft staan.

**Een inzending bewaart haar eigen kopie van alles.** `form_submissions`
houdt de naam van het formulier vast, en elke rij in `form_submission_values`
houdt de `field_key`, het **label** en het **type** vast zoals ze op het
moment van versturen waren. Er wordt nergens teruggejoined naar `form_fields`,
en dus ook niet naar een vertaling: het label is het label van dat moment, in
de standaardtaal van toen. Multilingual 2.0 fase 4 heeft geen enkele bestaande
inzending aangeraakt en geen taalkolom toegevoegd.
Daarom blijft een aanvraag van vorig voorjaar leesbaar nadat de redactie een
veld hernoemt, verplaatst of weghaalt — en blijft het antwoord op een
verwijderd veld gewoon staan.

`form_submission_attachments` houdt de bestanden van een inzending: één rij
per bestand, met het veld waar het bij hoort (`field_key`), de naam die de
bezoeker het gaf, de willekeurige naam waaronder het is opgeslagen, soort,
grootte en SHA-256. Een bijlage van het contactblok van vóór Forms 2.0 fase 2
heeft geen veld (`field_key` NULL) en blijft gewoon te downloaden. Zie
*Bestand uploaden*.

## Veldtypes

Een gesloten lijst, om dezelfde reden als `BlockDefinitions`: `field_type`
komt uit een adminformulier en uit een databaserij, en het enige wat die
waarde ooit mag doen is een sleutel raken of missen. Missen is missen; het
wordt nooit een klassenaam.

| Sleutel | In het CMS | Wat het is |
|---|---|---|
| `text` | Kort tekstveld | Eén regel tekst |
| `textarea` | Lang tekstveld | Meerdere regels (max. 5000 tekens, houdt regeleindes) |
| `email` | E-mailadres | E-mailadres; het enige type dat antwoordadres kan zijn |
| `tel` | Telefoonnummer | Telefoonnummer; **geen landformaat afgedwongen** |
| `select` | Keuzelijst | Keuzelijst uit een gesloten optielijst |
| `radio` | Keuzerondjes | Dezelfde gesloten lijst als keuzerondjes |
| `checkbox` | Selectievakje | Eén vinkje (bewaard als "Ja") |
| `consent` | Toestemming | Akkoordvinkje; **altijd verplicht** |
| `file` | Bestand uploaden | Eén bestand, uit een gesloten lijst soorten en groottes (zie *Bestand uploaden*) |

Elk type is één klasse die zegt hoe het rendert, hoe het een waarde
schoonmaakt, welke eigen regel het heeft en welke instellingen het gebruikt.
Er staat nergens een `switch` over veldtypes: niet in de renderer, niet in de
validator en niet in het admin.

### Hoe een type heet

**De sleutel is de identiteit, de catalogus geeft de naam.** `field_type`
bewaart de sleutel en die verandert nooit. Wat de redacteur leest, de naam en
één zin uitleg, staat in de admincatalogus onder `formfieldtype.<key>.label`
en `.description`, in het Nederlands én het Engels, en nergens anders. Een
typeklasse heeft geen `label()` meer.

Dat wijkt bewust af van een contentblok, dat een Nederlandse naam in zijn
eigen klasse houdt en die alleen laat vertalen. Die terugval bestaat omdat een
**module** een blok kan meebrengen zonder te weten dat het CMS twee talen
heeft. Een veldtype komt nooit uit een module: de lijst is Core en gesloten.
Een tweede kopie van dezelfde woorden in PHP zou alleen uit de pas kunnen
lopen. `Tests\Service\FormFieldTypeTest` faalt als een geregistreerd type
geen naam of uitleg heeft, of als twee types dezelfde naam dragen.

### Wat een type gebruikt

Deze verklaringen op de typeklasse sturen zowel de veldeditor als het
typewisselbeleid. Het admin heeft geen eigen lijst:

| Verklaring | `text`, `textarea`, `tel` | `email` | `select`, `radio` | `checkbox` | `consent` | `file` |
|---|---|---|---|---|---|---|
| `usesPlaceholder()` | ja | ja | nee | nee | nee | nee |
| `usesOptions()` | nee | nee | ja | nee | nee | nee |
| `usesDefaultValue()` | nee | nee | ja | nee | nee | nee |
| `requiredIsFixed()` | nee | nee | nee | nee | ja | nee |
| `holdsEmailAddress()` | nee | ja | nee | nee | nee | nee |
| `acceptsFile()` | nee | nee | nee | nee | nee | ja |

Label en uitleg gebruikt elk type, in elke websitetaal.

### Een veldtype toevoegen

1. `src/Service/Forms/FieldTypes/<Naam>FieldType.php`, extends
   `FormFieldType`. Implementeer `key()`, `renderControl()` en
   `normalize()`; de rest heeft een veilige standaard. Overschrijf de
   verklaringen hierboven waar het type afwijkt.
2. Eén regel in `FormFieldTypes::MAP`.
3. `formfieldtype.<key>.label` en `.description` in `nl.php` én `en.php`.
4. Draai `--testsuite fast`: `Tests\Service\FormFieldTypeTest` loopt
   automatisch over élk geregistreerd type en controleert het hele contract,
   de catalogusnamen inbegrepen. `Tests\Service\FormFieldTypeChangeTest`
   schrijft per paar types uit wat een wissel kost, en faalt dus tot je het
   nieuwe type daar een rij geeft: zo is de belofte over dataverlies een
   bewuste keuze.

Meer is er niet. De renderer, de validator, de veldeditor, de typekiezer en
de e-mail hebben er geen regel voor nodig.

## Breedte van een veld

Sinds Forms 2.0 fase 1 kiest een veld hoe breed het in zijn formulier staat.
Geen pixels, geen percentage en geen CSS: een van zes vaste delen van een rij,
op een raster van twaalf kolommen.

| Sleutel (`layout_width`) | In het CMS | Kolommen van 12 | Class |
|---|---|---|---|
| `full` | Volledige breedte | 12 | `.form-field--full` |
| `three_quarters` | Drie kwart (3/4) | 9 | `.form-field--three-quarters` |
| `two_thirds` | Twee derde (2/3) | 8 | `.form-field--two-thirds` |
| `half` | Half (1/2) | 6 | `.form-field--half` |
| `third` | Een derde (1/3) | 4 | `.form-field--third` |
| `quarter` | Een kwart (1/4) | 3 | `.form-field--quarter` |

**Een gesloten lijst**, om dezelfde reden als de veldtypes:
`App\Service\Forms\FormFieldWidth`. De sleutel noemt een *deel* van een rij,
geen aantal kolommen, zodat het raster eronder kan veranderen zonder dat één
opgeslagen rij mee hoeft.

- **Opslaan.** De veldeditor biedt de zes in een keuzelijst.
  `api/admin/update-form-field.php` accepteert alleen een sleutel uit de
  lijst. Elke andere waarde (leeg, `50%`, `6`, een class, een stijl, een
  array) wordt geweigerd zoals elke andere fout: er wordt niets geschreven en
  de editor komt terug met wat er getypt was. Stuurt een verzoek geen
  breedte mee, dan blijft de opgeslagen breedte staan. `FormRepository`
  schrijft bovendien nooit iets anders dan een sleutel.
- **Lezen.** `FormField::$width` is altijd een sleutel. Een rij met iets
  onbekends leest als `full`. De renderer drukt per veld precies één van de
  zes classes af, en nooit een `style`.
- **Standaard.** Een nieuw veld is `full`: de kolom is `NOT NULL DEFAULT
  'full'`.
- **Bestaande formulieren veranderen niet.** Tot deze fase koos
  `partials/form.php` de breedte zelf, uit het type: een tekstveld, een
  e-mailadres en een telefoonnummer stonden op een halve rij, al het andere
  over de hele rij, op een raster van twee kolommen. De migratie
  `20260924160000` schreef precies dat per bestaand veld op (`text`, `email`,
  `tel` → `half`, de rest → `full`). Zes kolommen van twaalf zijn even breed
  als één van twee, want de tussenruimte is dezelfde. Een bestaand formulier
  staat dus op dezelfde pixels; in de browser gemeten op 1280 en 375 pixels
  breed. `FormFieldWidth::formerDefaultFor()` legt die oude regel vast voor
  de test die de migratie ermee vergelijkt. Geen renderer gebruikt hem.
- **Structuur, geen woorden.** De breedte staat op `form_fields` zelf, niet in
  een vertaaltabel. Hij is in elke taal dezelfde, en elke taal kan hem
  wijzigen.
- **Geen gevolgen voor een inzending.** Wat verstuurd, gevalideerd, bewaard
  en gemaild wordt, verandert niet met de breedte.

**Hoe de rij zich vult.** De velden staan in hun eigen volgorde in de markup.
Past het volgende veld nog op de rij, dan komt het ernaast; anders begint een
nieuwe rij. Een later veld schuift nooit terug in een gat eerder in het
formulier. De tabvolgorde is daarom altijd de volgorde van de veldenlijst.

```text
Voornaam  Tussenvoegsel  Achternaam      third  third  third
Naam            E-mail                   half   half
Postcode             Huisnummer          two_thirds  third
```

**Op een telefoon staat elk veld over de volle breedte**, in dezelfde
volgorde. Het breekpunt is 640 pixels: dat is waar het gedeelde
`.form-grid` in `assets/css/core.css` zijn twee kolommen altijd al onder
elkaar zette, dus een formulier stapelt waar het altijd stapelde. Het raster
van twaalf staat in `assets/css/blocks/form.css`, onder `.vvl-form`. Het
afrekenen gebruikt dezelfde `.form-grid` en merkt er niets van.

**Elk type mag elke breedte.** Een lang tekstveld op driekwart, een
keuzelijst op een halve rij, keuzerondjes in een derde: de rondjes en de
tekst van een selectievakje lopen door binnen hun eigen cel. Het CMS dwingt
voor geen enkel type een breedte af. Wat semantisch meestal de hele rij
wil, zoals een lang tekstveld of toestemming, begint daar gewoon, omdat een
nieuw veld `full` is.

## Bestand uploaden

Sinds Forms 2.0 fase 2 is een bestand een gewoon veld: `file`, in het CMS
*Bestand uploaden*. Een redacteur voegt het toe met *Veld toevoegen*, geeft
het een label, uitleg, verplicht ja/nee en een breedte, zet het op zijn plek
en haalt het weer weg, precies zoals elk ander veld. Het wordt **nooit
vanzelf** toegevoegd, ook niet door het offerte-/contactblok.

Het werkt overal waar een veld werkt: in het blok *Formulier*, in het
offerte-/contactblok, in het voorbeeld van de editor, met en zonder
JavaScript, in bewaarde inzendingen en in de melding.

### Instellingen

Twee instellingen van zichzelf, allebei een **gesloten lijst**
(`App\Service\Forms\FormFileTypes`), op de kaart *Bestanden* van de
veldeditor:

| Instelling | Kolom | Keuze | Nieuw veld |
|---|---|---|---|
| Toegestane bestanden | `file_types`, sleutels met komma's | een vinkje per soort: JPG (`.jpg`, `.jpeg`), PNG, WEBP, GIF, PDF | JPG, PNG, PDF |
| Maximale grootte | `file_max_bytes` | 1, 2, 5, 8 of 10 MB, voor zover de installatie dat aankan | 5 MB |

**Geen vrij MIME-veld.** Een soort is een sleutel uit de lijst; de lijst
bepaalt welke extensies de naam mag hebben, wat de inhoud moet zijn en onder
welk MIME-type het bestand bewaard, gemaild en gedownload wordt.
`api/admin/update-form-field.php` weigert geen enkele soort, een onbekende
soort (`svg`, `image/png`, `exe`) en elke grootte die niet in de lijst staat,
net als een onbekende breedte: er wordt niets geschreven en de editor komt
terug met wat er ingevuld was. Een verborgen `file_settings` zorgt dat "niets
aangevinkt" ook echt aankomt.

**Eén bestand per veld (V1).** Wie om twee bestanden vraagt, maakt twee
velden. Meerdere bestanden in één veld zouden een extra instelling, arrays in
`$_FILES`, een maximum aantal en een groter totaal binnen `post_max_size`
betekenen; dat is een vervolgstap, geen onderdeel van V1. Een ingang die PHP
als lijst opbouwt (`naam[]`), wordt geweigerd.

Een typewissel naar of van een uploadveld volgt *Een ander soort veld*: naar
`file` toont de editor eerst de kaart *Bestanden* (zoals de opties van een
nieuwe keuzelijst); van `file` weg verdwijnen de toegestane bestanden en de
maximale grootte, en dat moet eerst bevestigd worden.

### Welke soorten, en waarom niet meer

| Soort | Herkend aan | Opmerking |
|---|---|---|
| JPG, PNG, WEBP, GIF | `getimagesize()` moet precies dat beeldtype teruggeven | WEBP en GIF accepteerde het contactblok al |
| PDF | de eerste vijf bytes zijn `%PDF-` | |
| **SVG** | — | **Niet in V1.** Een SVG is een document dat script kan bevatten. Als download in het CMS is het onschuldig, maar de melding geeft het bestand aan het mailprogramma van de eigenaar, en een SVG van een onbekende bezoeker zo zuiveren dat dat veilig is, is een project op zich. Geen schijnveiligheid |
| **ZIP** | — | **Niet in V1.** Een archief is niet te keuren zonder het uit te pakken — precies wat een decompressiebom wil — en de inhoud is wat de afzender wil |
| HTML, PHP, scripts, Office | — | Nooit |

### Hoe een bestand gekeurd wordt

`App\Service\Forms\FormUploadInspector` beoordeelt één ingang van `$_FILES`
en schrijft niets. Het antwoord is *geen bestand*, *geaccepteerd*
(`FormUpload`) of een zin voor naast het veld, in de taal van het verzoek:

1. De ingang moet één bestand zijn; lijsten en rare vormen worden geweigerd.
2. PHP's eigen foutcode: `UPLOAD_ERR_NO_FILE` is "geen bestand",
   `INI_SIZE`/`FORM_SIZE` wordt "te groot, maximaal …", `PARTIAL` "kwam niet
   helemaal aan", de rest "kon niet worden ontvangen" (en een regel in het
   serverlog). **Nooit een stil ontbrekend bestand.**
3. `is_uploaded_file()` moet waar zijn.
4. De grootte komt van het bestand op schijf, niet uit het verzoek. Leeg en
   groter dan de limiet worden geweigerd.
5. De extensie van de naam moet bij een aangevinkte soort horen.
6. De inhoud moet precies die soort zijn (zie de tabel). Een PNG die
   `scan.pdf` heet en een script dat `foto.jpg` heet, worden allebei
   geweigerd. Het MIME-type dat de browser meestuurt, wordt niet eens gelezen.
7. Dan pas: SHA-256 van de inhoud.

Verplicht en "niet ingevuld" is de gedeelde regel van `FormValidator`, met de
eigen zin van het type (*Kies een bestand bij …*).

### Maximale grootte

Vier grenzen, de kleinste wint:

| Grens | Waar |
|---|---|
| Wat het veld zegt | `file_max_bytes` |
| Het plafond van de applicatie: 10 MB | `FormFileTypes::MAX_BYTES` |
| `upload_max_filesize` | php.ini / `.user.ini` (Docker: 35M) |
| `post_max_size`, min 64 kB voor de rest van het verzoek | php.ini / `.user.ini` (Docker: 40M) |

`FormFileTypes::systemMaxBytes()` rekent dat uit. De editor biedt alleen de
groottes aan die daaronder blijven en zegt wat het plafond is; het endpoint
weigert elke andere; de repository schrijft nooit meer dan 10 MB; en het
leesmodel kapt een opgeslagen waarde af op wat de installatie vandaag
aankan. De bezoeker ziet de limiet die echt geldt, onder het veld.

**Een verzoek groter dan `post_max_size`** komt bij PHP binnen met een lege
`$_POST` én `$_FILES`: de formuliersleutel is dan ook weg.
`api/form-submit.php` herkent dat en antwoordt *Wat je verstuurde is te groot
om te ontvangen* — `413` voor de `fetch()`, en zonder JavaScript een 303 naar
de pagina uit de `Referer` (alleen een pad op deze site, anders `/`), met de
melding boven het formulier. Welk formulier dat is, staat daarom ook in het
actie-adres (`/api/form-submit.php?instance=…`): een querystring overleeft
wat PHP weggooit. Dit vraagt `display_errors` uit, zoals op een live site:
PHP drukt zijn waarschuwing over zo'n verzoek af vóór het script begint, en
daarna kan het endpoint zijn status niet meer zetten.

### Opslag

- **Pas na de volledige validatie.** Faalt er één veld, dan wordt niets
  verplaatst; PHP gooit zijn tijdelijke bestanden na het verzoek zelf weg.
- **Buiten de webroot**, in dezelfde map als altijd:
  `App\Service\ContactAttachmentStorage` (standaard `../storage/contact-attachments/`,
  of `CONTACT_ATTACHMENTS_PATH`). Er bestaat geen publiek adres voor een
  bestand.
- **Onder een willekeurige naam**: 32 hex-tekens plus de extensie van de
  soort die de inhoud bleek te zijn (`.pdf`), nooit de naam van de bezoeker.
  Die naam wordt alleen als label bewaard, ontdaan van mappen (beide
  schuine strepen), stuurtekens (ook NUL), richtingstekens (die `gpj.exe`
  als `exe.jpg` laten lezen), aanhalingstekens en voorloopspunten.
- **Gekoppeld aan inzending én veld**, in dezelfde transactie als de
  inzending: `form_submission_attachments` met `submission_id`, `field_key`,
  `stored_filename` (een naam, geen machinepad), `original_filename`,
  `mime_type`, `file_size`, `sha256`, `created_at`. Een unieke index op
  `(submission_id, field_key)`: één bestand per veld per inzending.
- **Geen wees.** Lukt een bestand verplaatsen niet, dan gaan de al
  verplaatste weer weg en krijgt de bezoeker de algemene fout. Kan de
  inzending niet geschreven worden, of bewaart het formulier niets, dan
  wordt elk opgeslagen bestand verwijderd voordat het antwoord vertrekt.

Het antwoord van het veld in de inzending (`form_submission_values.value`) is
de naam en de grootte: *offerte.pdf (240 kB)*. Zo leest een oude inzending
nog goed als het veld later weg is.

### Een bestand downloaden

Alleen via `GET /api/admin/form-submission-attachment.php?submission=<id>&file=<id>`:

- ingelogd en `forms.submissions` (zonder login naar het inlogscherm,
  zonder recht `403`); formulieren bouwen geeft geen toegang;
- **beide id's moeten bij elkaar horen** (`FormSubmissionRepository::attachment()`).
  Het id van een bestand van een andere inzending, een onbekend id, een pad
  of een opgeslagen naam in het verzoek: allemaal `404`, hetzelfde antwoord
  als een bestand dat niet bestaat. Er wordt nooit een pad uit het verzoek
  geopend;
- altijd `Content-Disposition: attachment`, met een ASCII-naam en de volledige
  naam als `filename*`; het MIME-type alleen als het een soort uit de lijst
  is (anders `application/octet-stream`); `X-Content-Type-Options: nosniff`,
  `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control:
  private, no-store`.

Het is een GET die niets wijzigt en heeft daarom geen CSRF-token, zoals elke
download in het admin. De inzending noemt elk bestand bij zijn veld met een
downloadlink; een bestand dat van schijf verdwenen is, heet daar
*Bestand ontbreekt* in plaats van een link.

### Verwijderen en bewaren

- **Inzending verwijderen** (`api/admin/delete-form-submission.php`, met
  login, recht, POST en CSRF) leest eerst alle bestanden van díe inzending,
  verwijdert de rijen, en daarna de bestanden. Een bestand dat al weg is, is
  geen fout. Een ander bestand dan die van deze inzending is niet te
  bereiken.
- **Veld verwijderen** laat bewaarde inzendingen en hun bestanden staan, net
  als hun antwoorden.
- **Formulier verwijderen** kan niet zolang er inzendingen zijn (zie
  *Gebruik en veilig verwijderen*), dus ook geen bestanden als bijvangst.
- **Geen automatische bewaartermijn**, zoals voor de rest van een inzending
  (zie *Privacy*).

### E-mail

Elk bestand gaat als **echte bijlage** mee met de melding, in de volgorde van
de velden, onder een generieke naam (`<veldsleutel>.<extensie>`), zolang de
bijlagen samen binnen 15 MB blijven
(`FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET`; base64 maakt daar ongeveer
20 MB van, en veel mailservers weigeren 25). Er gaat geen link naar het CMS
mee in de melding.

Wat er gebeurt met bestanden die samen meer zijn, hangt af van of het
formulier zijn inzendingen bewaart:

| Formulier | Bestanden samen boven de 15 MB |
|---|---|
| **Bewaart inzendingen** | Toegestaan, zolang elk bestand binnen zijn veld- en systeemlimiet blijft. De melding neemt bijlagen mee tot de 15 MB; een bestand dat niet meer past, wordt bij zijn antwoord genoemd: *niet bijgevoegd, te groot voor deze e-mail; te downloaden bij de inzending in het CMS*. Alle bestanden blijven bij de bewaarde inzending |
| **Bewaart niets** | **Geweigerd**, vóór er iets gebeurt. Daar ís de melding de enige bezorging, dus een bestand dat niet mee kan, zou na het bedankje nergens meer zijn. `FormValidator` telt de geaccepteerde bestanden op en zet naast elk bestand *De bestanden zijn samen te groot om te versturen: samen mogen ze maximaal 15 MB zijn*. Geen succesmelding, geen inzending, geen bestand en geen rij blijven achter; de bezoeker kiest kleinere bestanden (opnieuw, zie *Na een geweigerde inzending*). Precies 15 MB mag |

Beide lezen dezelfde ene grens, `FormSubmissionHandler::MAIL_ATTACHMENT_BUDGET`;
het getal staat nergens anders.

Dat is het gedrag van het oude contactformulier: de bijlage ging mee, als
`bijlage.<extensie>`. Het omgezette veld heet `bijlage`, dus ook de naam in
de mail is dezelfde gebleven.

### Na een geweigerde inzending

Een browser kan een bestandskiezer niet opnieuw vullen, en dit CMS houdt geen
bestand vast tussen twee verzoeken (geen tijdelijke uploadcache). **Bewuste
V1-beperking.**

- **Met JavaScript** verlaat de bezoeker de pagina niet: het gekozen bestand
  staat er nog, alleen de meldingen verschijnen.
- **Zonder JavaScript** komt het formulier terug met de ingevulde tekst, en
  naast een bestand dat wél goed was: *Kies het bestand bij … opnieuw: een
  bestand wordt niet bewaard als het formulier terugkomt*
  (`FormValidationResult::errorsAfterRedirect()`). Een bestand dat zelf fout
  was, krijgt zijn eigen melding.

### Voorbeeld en breedte

Het voorbeeld in de formuliereditor is `render_form()`, dus het uploadveld
staat er precies zoals op de site, met zijn regel *JPG, PNG of PDF, max.
5 MB.* Een bestand kiezen kan daar, maar versturen niet: het frame heeft geen
`allow-forms`, en de browser weigert het verzoek (in de browser nagegaan).

Een uploadveld neemt elke breedte van *Breedte van een veld*, en staat op
een telefoon over de hele rij. De bestandskiezer krimpt mee met zijn cel en
de regel eronder breekt overal af, dus ook een zeer lange bestandsnaam geeft
geen horizontale scrollbalk (gemeten op 1280 en 375 pixels, in een cel van
een derde).

### Dreigingen en antwoorden

| Dreiging | Antwoord |
|---|---|
| MIME-spoofing | Het meegestuurde MIME-type wordt niet gelezen; de inhoud beslist |
| Extensie-spoofing | Extensie én inhoud moeten dezelfde soort zijn |
| Dubbele extensie (`x.php.jpg`) | Alleen de laatste telt, de inhoud moet dan een JPEG zijn, en opgeslagen wordt `<willekeurig>.jpg` |
| Path traversal, NUL, rare Unicode | De naam is alleen een label en wordt schoongemaakt; de opslagnaam is willekeurig; `path()` en `delete()` nemen alleen een kale naam |
| Willekeurig bestand schrijven | Alleen `move_uploaded_file()` van een echte upload, naar de eigen map |
| Uitvoerbare upload, PHP, HTML | Niet in de lijst; en de map ligt buiten de webroot |
| SVG/XSS | Geen SVG; download altijd als bijlage met `nosniff` en sandbox-CSP |
| ZIP en decompressiebommen | Geen ZIP; beelden worden niet gedecodeerd, `getimagesize()` leest alleen de kop |
| Te groot verzoek | Vier grenzen; `post_max_size`-overschrijding krijgt een eigen antwoord |
| Header-injectie via de naam | Aanhalingstekens, backslashes en stuurtekens eruit; ASCII-naam plus `filename*`; in de mail een generieke naam |
| IDOR bij downloaden | Inzending én bestand moeten bij elkaar horen; anders `404` |
| Rechtstreeks via het web | Geen publiek adres; de map ligt buiten de webroot |
| CSRF | Downloaden is een GET zonder effect; verwijderen heeft de vier guards |
| Weesbestanden | Opslaan pas na validatie; opruimen bij elk pad zonder inzendingsrij |
| Stil verlies bij een formulier dat niets bewaart | Bestanden samen boven het mailbudget worden geweigerd voordat er iets wordt verstuurd |
| Verwijderen terwijl er gedownload wordt | Rijen eerst, bestanden daarna: een download vindt daarna geen rij meer en geeft `404` |
| Dubbel versturen | Twee inzendingen met elk hun eigen bestanden; de rate limit begrenst |
| Kapotte of lijst-vormige `$_FILES` | Geweigerd met *Kies één bestand* |

## Talen

Een formulier heeft zoveel talen als de site actief heeft (`site_languages`).
De woorden staan per taal in `form_translations`,
`form_field_translations` en `form_field_option_translations`, en
`FormLocalization` haalt ze op. De terugval — gevraagde taal, dan de
standaardtaal, dan leeg — staat op **één** plek,
`App\Service\Language\LanguageFallback`, zodat geen template, validator of
e-mailbouwer hem hoeft te onthouden. Zinnen die het CMS zelf bezit (de
standaardknop *Versturen*, *Bedankt…*, de meldingen) zijn een codecatalogus
per taalcode (`SiteText::pick()`), met terugval op de standaardtaal.

Sinds Multilingual 2.0 fase 7 drukt de publieke markup **één** taal af: die
van de URL. Geen `data-nl`/`data-en` meer, geen wissel in de browser. Een
label en een placeholder zijn altijd platte tekst en worden ge-escaped. Een
foutmelding na een geweigerde inzending komt terug in de taal waarin het
formulier werd verstuurd.

De **waarde** van een keuzeoptie wisselt niet mee: die is in elke taal
dezelfde, zodat wat de bezoeker verstuurt niet afhangt van de taal die
aanstond. Zie *Een optie heeft een waarde en een label*.

Een bewaarde inzending legt het label in de **standaardtaal** vast, zodat
historie niet afhangt van welke taal iemand toevallig aanstond.

## Actief en uit

Eén schakelaar per formulier (`forms.is_active`), met overal dezelfde
betekenis:

| Waar | Actief | Uit |
|---|---|---|
| Blok "Formulier" | toont het formulier | toont niets, ook geen kop of inleiding |
| Blok "Offerte-/contactformulier" | het formulier naast "Direct contact" | alleen de kop en de kaart "Direct contact" |
| `api/form-submit.php` | neemt inzendingen aan | weigert met hetzelfde algemene antwoord als een onbekend formulier: `400` voor de `fetch()`, een `303` met `form-status=error` voor een browser zonder JavaScript |
| Paginabouwer | de naam van het formulier | de naam met "(staat uit)" |

**Het besluit valt op één plek**: `FormDefinition::isRenderable()`, dus actief
én minstens één bruikbaar veld. De blokken vragen het via
`FormCatalog::renderable()`; het endpoint en
`FormSubmissionHandler::handle()` vragen het allebei zelf. Het formulier uit
de pagina halen is dus nooit het enige wat een inzending tegenhoudt: een POST
die rechtstreeks naar het endpoint gaat, van een pagina die nog openstond of
van een script, wordt net zo geweigerd. Er wordt dan niets bewaard, niets
gemaild, en de rate limit telt de poging niet mee. De bezoeker krijgt geen
technische melding: het endpoint bevestigt niet eens dat het formulier
bestaat.

**Uitzetten raakt verder niets.** Velden, opties, standaardwaarden,
plaatsingen en bewaarde inzendingen blijven staan, en aanzetten herstelt het
formulier precies zoals het was. Alleen een actief formulier kan
inzendingen verliezen, dus de weigering uit "Ontvanger" hieronder geldt
alleen voor een formulier dat actief wordt opgeslagen.

`Tests\Service\FormAdminHttpTest` bewijst dit over echt HTTP, met PHP's eigen
webserver op deze uitchecking. Het draait dus ook waar `php_test` niet
draait.

## De publieke pijplijn

```text
spamcontrole  →  validatie  →  bewaren  →  mailen  →  uitkomst vastleggen
```

Alles gaat door `FormSubmissionHandler::handle()`. Er is één plek waar een
formulier gevalideerd, bewaard en gemaild wordt.

**De volgorde is met opzet zo.** Een formulier dat bewaart, schrijft de rij
*voordat* het probeert te mailen: een geldige aanvraag mag niet verloren gaan
omdat een mailserver dertig seconden onbereikbaar was. Het mailen is daarna
een best-effort neveneffect, en de uitkomst komt op de rij te staan
(`notification_sent_at`), zodat het admin een aanvraag kan tonen die wél
binnenkwam maar niet gemaild is.

Een formulier dat **niet** bewaart heeft geen vangnet: daar ís de e-mail de
bezorging, en een mislukte verzending is dan een echte fout die de bezoeker te
horen krijgt in plaats van een bedankje voor iets dat niemand ontving.

**Geen wachtrij, geen retry, geen achtergrondproces.** Dit draait op Vimexx
gedeelde hosting, waar geen worker bestaat om er een te draaien.

### Validatie is server-side en loopt over de definitie

`FormValidator` loopt over de **velden van het formulier**, nooit over het
request. Per veld leest hij wat het request onder díé sleutel meestuurt, laat
het veldtype het schoonmaken, past de twee gedeelde regels toe (verplicht, en
niet langer dan het type toestaat) en vraagt daarna het type om zijn eigen
regel.

Een uploadveld (`acceptsFile()`) leest op dezelfde manier zijn **eigen**
ingang van `$_FILES`, onder dezelfde sleutel uit dezelfde definitie, en nooit
`$_POST`. Een bestand onder een naam waar het formulier geen uploadveld voor
heeft, wordt niet eens bekeken. Dat is de enige plek waar de validator een
declaratie van een type leest in plaats van alleen `normalize()`: er is geen
`if ($type === 'file')`.

Die richting is het hele punt: **een veld dat het formulier niet heeft wordt
nooit gelezen, nooit gevalideerd en nooit opgeslagen**, wat een POST ook
meestuurt. `required`, `maxlength` en een `<select>` in de browser zijn
gemakken; `assets/js/blocks/form.js` hoeft niet eens te draaien.

Wat elk type schoonmaakt: alle stuurtekens eruit (bij een textarea alles
behalve de regeleindes), afkappen op de maximale lengte. Daardoor kan geen
antwoord ooit een extra header in de uitgaande e-mail zetten.

## E-mail

Eén generieke bouwer (`App\Mail\FormSubmissionBuilder`) voor élk formulier:
naam van het formulier, tijdstip, de pagina waarvandaan het kwam, en daarna
label + antwoord per veld. HTML en platte tekst, in dezelfde stijl als de
orderbevestiging. **Alle ingevulde tekst wordt geëscaped en nooit als HTML
behandeld.**

Er is geen e-mailsjabloon per formulier en geen template-editor: een
melding wordt één keer gelezen door één persoon die wil weten wat iemand
vroeg.

**Bestanden gaan als echte bijlage mee**, onder een generieke naam (de
sleutel van het veld plus de extensie van wat het bestand is, bijvoorbeeld
`bijlage.pdf`), nooit onder de naam die de bezoeker het gaf. Het antwoord van
het veld noemt die naam en de grootte. Zie *Bestand uploaden*, "E-mail", voor
wat er gebeurt als de bestanden samen te groot zijn.

### Ontvanger

Twee stappen, en geen bedrijf in de code:

1. het adres dat de beheerder op het formulier heeft ingevuld;
2. anders het contactadres van de site (Instellingen).

Beide worden gevalideerd; een typefout valt door naar de volgende stap in
plaats van PHPMailer een adres te geven dat het weigert. Staat er in geen van
beide iets bruikbaars, dan wordt de inzending nog steeds geaccepteerd en
bewaard, en komt de ontbrekende configuratie in het serverlog.

Het contactadres van de site is optioneel, dus die toestand kan bestaan. Eén
variant ervan laat het CMS niet toe, omdat er dan echt iets verloren gaat: een
**actief formulier zonder eigen adres dat zijn inzendingen niet bewaart**,
terwijl de site ook geen adres heeft
(`FormRecipient::losesSubmissions()`). `api/admin/update-form.php` weigert zo'n
formulier op te slaan, en `api/admin/update-site-settings.php` weigert het
contactadres leeg te maken zolang een formulier daarvan afhangt. Allebei
noemen ze wat de beheerder kan doen. Het formulierscherm en Instellingen
waarschuwen ook als de toestand al bestaat, want dan is er niets meer om te
weigeren.

**Eén ontvanger in V1.** Geen CC, geen BCC, geen routering op antwoorden,
geen autoresponder.

### Reply-To

Wijst het formulier een e-mailveld aan als antwoordadres, dan komt de waarde
daarvan in `Reply-To` en kan de eigenaar direct terugmailen.

**De afzender is nooit van de bezoeker.** `From` blijft de identiteit van de
site (`App\Mail\EmailIdentity`) — dat is het adres waarmee de provider mag
verzenden en waar SPF/DKIM op staan. Een ongeldig of ontbrekend bezoekersadres
betekent simpelweg géén `Reply-To`, nooit een kapotte header.

## Spam en beveiliging

Drie goedkope lagen, en geen betaalde dienst:

| Laag | Wat het doet |
|---|---|
| Honeypot | Een veld dat een mens nooit invult (buiten beeld, `tabindex="-1"`, `aria-hidden`, dus ook een schermlezer slaat het over) |
| Minimale insturtijd | Een POST binnen drie seconden na het renderen is niet met de hand ingevuld |
| Rate limit | Vijf pogingen per tien minuten per bezoeker, op een **gezouten hash** van het IP — het ruwe adres wordt nooit opgeslagen |

De eerste twee **falen stil met een nep-succes**: een bot die te horen krijgt
dat de honeypot hem verraadde, leert dat veld voortaan over te slaan. Er wordt
niets bewaard, niets gemaild en geen rate-limitplek verbruikt. Alleen de rate
limit antwoordt eerlijk, want daar kan een echt mens tegenaan lopen.

**Geen reCAPTCHA, hCaptcha, Turnstile of Akismet op een publiek formulier.**
Deze site gebruikt Cloudflare Turnstile wél — op het afrekenen, waar een
misbruikt verzoek een order en een betaling aanmaakt. Dat is bewust een andere
afweging: een contactformulier achter een puzzel kost elke eerlijke bezoeker
moeite en stuurt zijn IP naar een derde partij, om een e-mail te beschermen.

### CSRF

**Het publieke endpoint heeft geen CSRF-token, en dat is een keuze.** Het
CSRF-model van dit project (`App\Service\Csrf`) hangt aan de **adminsessie**;
er is geen anonieme sessie waarin een bezoeker een token kan bewaren, en er
één invoeren zou betekenen dat élke bezoeker die een formulier ziét een cookie
krijgt. Wat een token hier zou opleveren is bovendien klein: het endpoint doet
niets namens een ingelogde gebruiker, verandert niets wat van iemand is, en
het ergste geval — een externe pagina laat een bezoeker de eigenaar een
e-mail sturen — is precies waar het formulier voor is, en wordt begrensd door
de rate limit.

**De adminkant is een heel ander verhaal.** Elk schrijfendpoint van Forms
controleert login, permissie, methode én CSRF-token, in die volgorde.

### Nog twee dingen die een request niet mag bepalen

- **De ontvanger komt nooit uit het request** — altijd uit het opgeslagen
  formulier en de site-instellingen.
- **Het terugkeerpad wordt streng gecontroleerd** (`FormSourcePath`): alleen
  een pad op deze site, beginnend met één `/`, zonder schema, `@`,
  backslash of stuurtekens. Alles daarbuiten wordt de site-root. Anders zou
  het verborgen bronveld een open redirect zijn.

## Zonder JavaScript

Een formulier is een gewone POST naar `/api/form-submit.php`, dat een browser
antwoordt met een 303 terug naar de pagina (post/redirect/get, dus verversen
verstuurt nooit opnieuw). `assets/js/blocks/form.js` maakt daar alleen een
`fetch()` van zodat de pagina niet herlaadt, en toont dezelfde berichten.

Bij een fout gaan de foutmeldingen én **wat de bezoeker had ingevuld** mee
terug. Ze staan niet in de URL — dat zouden persoonsgegevens in de
browsergeschiedenis en in elk logbestand zijn — maar in een aparte publieke
sessie (`PublicFormSession`), met een eigen sessienaam die niets met de
adminsessie te maken heeft.

**Een cookie krijgt alleen wie echt iets heeft verstuurd.** Bij het bekijken
van een pagina start er niets. Omdat `session_start()` weigert zodra er
uitvoer is, en een blok pas ver ná de `<head>` rendert, roept élk
paginatemplate `PublicFormSession::prime()` aan op zijn eerste regels —
dezelfde vorm en dezelfde reden als
`SectionRegistry::collectPageAssets()`. `Tests\Service\FormBoundaryTest`
laat de build falen als een template dat vergeet.

## Toegankelijkheid

Elk veld heeft een echt `<label for>`; een groep keuzerondjes is een
`<fieldset>` met een `<legend>` en draagt zelf de id waar de foutsamenvatting
naartoe linkt; verplichte velden dragen `required` en `aria-required`; een
afgekeurd veld draagt `aria-invalid` en wijst met `aria-describedby` naar zijn
uitleg én zijn foutmelding; de foutsamenvatting is een focusbare
`role="alert"` met een link per veld. De honeypot is uit de tabvolgorde én uit
de toegankelijkheidsboom gehaald.

## Het formulierblok

```text
Formulier #3
    ├── Contactpagina
    └── Landingspagina
```

Eén definitie, meerdere plaatsingen. Het **blok** bepaalt wáár een formulier
staat en welke kop en inleiding erboven horen; de **definitie** bepaalt wat
het vraagt en wat er daarna gebeurt. Velden worden nooit per plaatsing
gedupliceerd.

Hetzelfde formulier mag twee keer op één pagina. Elke DOM-id die de renderer
print begint met een token dat is afgeleid van `(page_slug, section_key)`, de
identiteit waarop elk blok in dit CMS wordt geadresseerd — twee plaatsingen
delen dus geen enkele id, geen `<label for>` en geen foutanker.

### Wat een veld in het begin toont

```text
ingestuurde waarde  →  ingestelde standaardwaarde  →  leeg
```

**De bezoeker wint altijd.** "Ingestuurd" betekent daarbij *aanwezig*, niet
*niet-leeg*: na een mislukte inzending heeft de validator voor élk veld van
het formulier een waarde vastgelegd, ook voor de leeggelaten velden. Iemand
die bewust van de standaardwaarde afweek — of een keuzelijst leeghaalde —
krijgt dus zijn eigen antwoord terug, in plaats van een standaardwaarde die
zichzelf stilletjes weer opdringt. Een vers formulier heeft niets vastgelegd,
en begint daarom wél op de standaardwaarde.

Een standaardwaarde is een **weergave**-gemak en nooit een antwoord: hij
maakt een verplicht veld niet vanzelf ingevuld.

### Een formulier dat niet getoond kan worden

Nog geen formulier gekozen, het gekozen formulier verwijderd, uitgezet, of
zonder bruikbaar veld: het blok rendert **niets**. Geen leeg kaartje met een
knop die niet kan werken, en de rest van de pagina rendert gewoon. De page
builder zegt wél wat er aan de hand is, naast de bloknaam
(`FormBlock::instanceTitle()`).

Een keuzeveld zonder opties wordt om dezelfde reden overgeslagen: een lege
keuzelijst is niet in te vullen. De formuliereditor zegt dat er met zoveel
woorden bij.

## Het contactformulier

Het blok `contact_form` — het offerteformulier met daarnaast de kaart "Direct
contact" — bestond ruim vóór Core Forms. Het houdt zijn type-sleutel en al
zijn `page_sections`-rijen (een bloktype opruimen is een datamigratie, en daar
is hier geen reden voor), maar is nu een **wikkel**: het formulier dat het
toont is een gewone definitie, gerenderd door dezelfde
`partials/form.php` en verwerkt door dezelfde pijplijn.

Zijn keuzerondje "Voor wie is de aanvraag?" begint weer op "Particulier",
zoals de oude markup met een `checked` deed — maar nu als eigenschap van dat
veld, gezet door
`db/migrations/20260909320000_add_a_default_choice_to_form_fields.php`. Dat
woord staat nergens in een renderer of een controller, en
`Tests\Service\FormBoundaryTest` laat de build falen als het er terugkomt.

Wat het blok zelf houdt, is het ene ding dat een generiek formulierblok
niet hoort te hebben: **de kaart "Direct contact"** ernaast, die het
e-mailadres en de plaats (of regio) uit Instellingen toont en de tweede kolom
van het raster vult. Beide zijn daar optioneel; een regel zonder waarde wordt
weggelaten. De kaart zegt verder niets over het bedrijf: een belofte als een
reactietijd of "ophalen op afspraak" is inhoud zonder veld, en staat er dus
niet.

**Een bijlage is een veld, geen blokinstelling.** Tot Forms 2.0 fase 2 had
dit blok een schakelaar `allow_attachment` en drukte het na de velden een
vaste bestandskiezer `bestand` af, met een eigen validator
(`ContactAttachmentValidator`) en een eigen beleid (`FormAttachmentPolicy`).
Dat is allemaal weg. Wil een offerteformulier een foto of pdf, dan voegt de
redacteur het veld *Bestand uploaden* toe, zoals op elk ander formulier (zie
*Bestand uploaden*). Het blok voegt niets meer toe en dwingt niets af; de
blokeditor zegt alleen waar je zo'n veld maakt.

**Geen site verloor zijn bijlage.** De migratie `20260925100000` gaf elk
formulier waarvoor een contactblok de schakelaar aan had staan precies één
gewoon, optioneel uploadveld met wat de oude bestandskiezer accepteerde:

| | |
|---|---|
| Label | *Bijlage*, en *Attachment* als Engels een websitetaal is |
| Soort | `file`, niet verplicht, volle breedte, onderaan het formulier |
| Toegestaan | JPG, PNG, WEBP, GIF en PDF, maximaal 8 MB |
| Sleutel | `bijlage`, of `bijlage-2` … als het formulier die al had |

Een formulier met al een uploadveld kreeg er geen tweede bij. Daarna ging elke
schakelaar naar 0, en de standaard van de kolom ook. De kolom blijft bestaan
(niets leest hem nog; weghalen zou alleen een terugrol van de code lastiger
maken). Een verse installatie heeft geen contactblok (`20260909310000`) en
krijgt dus nergens een uploadveld. Bestaande inzendingen zijn niet aangeraakt:
hun bijlage heeft geen veld en blijft bij de inzending te downloaden.

Hetzelfde formulier op meerdere plekken toont nu overal het uploadveld, ook
in een gewoon blok *Formulier*: het veld hoort bij de definitie, niet bij
een plaatsing. Een pagina die nog openstond van vóór de update verstuurt zijn
bestand onder de oude naam `bestand`; die bestaat voor geen enkel formulier,
dus dat ene bestand wordt genegeerd (`bestand` blijft een gereserveerde
sleutel in `FormFieldKey`).

`api/contact.php` is nog slechts een **compatibiliteitsschil** voor een
pagina die iemand nog open heeft staan van vóór de wijziging: het hernoemt drie
besturingsvelden en geeft het verzoek door aan `api/form-submit.php`. Er zit
geen validatie, opslag of verzending meer in, en dat moet zo blijven.

De oude tabellen `contact_requests` en `contact_request_attachments` blijven
staan met wat erin zit, en het scherm Contactaanvragen blijft bestaan om die
historie te lezen. **Nieuwe** inzendingen komen binnen bij Formulieren →
Inzendingen.

## Beheer

**Het overzicht** (`admin/forms.php`) toont per formulier de status in woord
en kleur, het aantal velden, het aantal bewaarde inzendingen en de pagina's
waar het staat. Het aantal inzendingen staat er ook als bewaren inmiddels uit
staat: uitzetten verwijdert niets, en een streepje zou persoonsgegevens
verbergen die nog in het CMS staan. Wie `forms.submissions` heeft, klikt door
naar die inzendingen. Velden en inzendingen worden voor alle formulieren
samen geteld (`fieldsForMany()`, `countsForForms()`); alleen de plaatsingen
worden per rij opgevraagd.

**De editor** (`admin/form.php`) is links één formulier in drie kaarten, in
de volgorde waarin een redacteur erover nadenkt, met daaronder de velden, en
rechts het voorbeeld (zie *Voorbeeld in de formuliereditor*):

| Kaart | Wat erin staat |
|---|---|
| Algemeen | *Actief* (bovenaan), de naam, de tekst op de verstuurknop |
| Na het versturen | het bedankbericht, het e-mailadres dat de melding krijgt, en de waarschuwing uit "Ontvanger" |
| Geavanceerd | *Inzendingen bewaren in het CMS* en het antwoordadres van de melding (Reply-To) |

Geavanceerd is ingeklapt, met in de kop of inzendingen bewaard worden. Hij
gaat open na een geweigerde opslag, of zolang het formulier inzendingen zou
verliezen: dan kan staan wat er moet veranderen. Ingeklapt of open, hij
verstuurt dezelfde velden, en `api/admin/update-form.php` leest ze zoals
altijd.

De editor heeft de opslagbalk, net als de veldeditor (zie "Niet-opgeslagen
wijzigingen" hieronder). Die bewaakt alleen het formulier met de
instellingen; opslaan blijft dat ene formulier naar `update-form.php`.

| Handeling | Opslagbalk |
|---|---|
| *Actief*, de naam, de tekst op de verstuurknop, het bedankbericht, het e-mailadres voor de melding, *Inzendingen bewaren*, het antwoordadres | niet-opgeslagen (`input` of `change`), ook als *Geavanceerd* daarna weer dichtgaat |
| *Geavanceerd* of een `?` open- of dichtklappen | niets: geen formulierveld |
| *Veld toevoegen* | niets: die dialoog heeft `data-no-dirty-track`, zoals de blokkenkiezer, dus *Opslaan* in de balk maakt nooit een veld aan |
| Een veld verplaatsen of verwijderen, *Formulier verwijderen* | niets: formulieren met één knop of alleen verborgen velden. Verwijderen vraagt zoals altijd in de dialoog van het CMS |
| Opslaan, met de balk of met Enter in een veld | schoon na `?saved=1`; een geweigerde opslag komt terug met `data-save-bar-unsaved` |

Is er iets niet opgeslagen, dan waarschuwt de browser bij elke handeling die
de pagina verlaat, ook bij een veld toevoegen, verplaatsen of verwijderen.
`data-save-bar-discard` is hier niet nodig: niets op dit scherm gooit invoer
met opzet weg.

**Eén Opslaan.** Het formulier had naast de balk een eigen knop *Opslaan*,
onderaan de kaarten. Die is er alleen nog voor een browser zonder het script
van de balk: hij draagt `data-save-bar-fallback`, en `save-bar.js` verbergt
hem zodra de balk verschijnt ([`PAGE-EDITOR.md`](PAGE-EDITOR.md), "De
opslagbalk"). Verborgen, niet weggehaald: hij blijft de standaardknop van
het formulier, dus Enter in een veld slaat nog steeds op, via hetzelfde
verzoek. De veldeditor doet hetzelfde, behalve als een typewissel op
bevestiging wacht: dan zegt zijn knop wat hij gaat doen.

**De velden** staan onder de kaarten, één compacte rij per veld, in de
volgorde van het formulier. Dit zijn de rijen van *Header & navigatie* en de
footer (`.admin-section-row`):

```text
↑ ↓  Voornaam                                   Bewerken  Verwijderen
     Kort tekstveld · verplicht · 1/2
```

↑ en ↓ staan waar een sleepgreep zou staan. Sleep-en-neerzetten is er niet:
de pijlen werken met toetsenbord, muis en touch. De tweede regel zegt wat het
veld is: het type, *verplicht* of *niet verplicht*, de breedte, en bij een
keuzeveld het aantal opties. Elke knop is naar het veld genoemd voor een
schermlezer (*Voornaam een plek omhoog*, *Bewerken: Voornaam*). Op een
telefoon krijgen de knoppen een eigen regel. Alle andere instellingen staan op
het scherm van het veld zelf. De technische naam van een veld staat hier ook
niet (zie "De interne naam" hieronder). Elke rij is het anker `#form-field-<id>`
waar een opgeslagen veld naar terugkeert.

### Voorbeeld in de formuliereditor

Naast de instellingen en de velden staat een kaart *Voorbeeld*: het formulier
zoals een bezoeker het ziet. Waar het scherm breed genoeg is voor allebei
staan ze naast elkaar, en blijft het voorbeeld in beeld terwijl de velden
voorbij scrollen. Is het scherm smaller, dan volgt het voorbeeld onder de
velden. Het is een flexrij die omslaat, dus zonder eigen breekpunt.

**Het contract: het voorbeeld is de publieke renderer.** Het frame laadt
`admin/form-preview.php`, en dat document haalt het formulier op met
`FormCatalog::find()` en drukt het af met `render_form()` uit
`partials/form.php`, precies zoals het blok *Formulier* dat doet. Het zit in
de kaart van dat blok, met de stylesheets en het thema van de site en
`assets/css/blocks/form.css`. Er staat geen veldmarkup in het voorbeeld en er
is geen tweede renderer in JavaScript. Labels, types, verplichte velden,
breedtes en het raster kunnen dus niet uit elkaar lopen met de website:
`Tests\Service\FormAdminHttpTest` vergelijkt de velden van het voorbeeld byte
voor byte met wat `render_form()` voor hetzelfde formulier afdrukt.

| Vraag | Antwoord |
|---|---|
| Wat laat het zien? | Het **opgeslagen** formulier, niet wat er op dat moment getypt wordt. Elke opslag laadt het scherm, en dus het voorbeeld, opnieuw. Er is geen live-update |
| In welke taal? | De websitetaal die je bewerkt (de schakelaar in de zijbalk, `ContentEditingLanguage`), met de terugval die een bezoeker ook krijgt |
| Een formulier dat uit staat? | Wordt getoond, met erboven dat bezoekers het nu nergens zien |
| Zonder bruikbaar veld? | Geen frame, wel de zin dat het voorbeeld verschijnt zodra er een veld is |
| Kan het verstuurd worden? | Nee. Het frame heeft `sandbox="allow-same-origin"` en verder niets, dus geen scripts en geen formulieren. Het document stuurt `Content-Security-Policy: script-src 'none'; form-action 'none'`, en `assets/js/blocks/form.js` wordt niet geladen |
| Dubbele id's? | Kan niet: het voorbeeld is een eigen document, met het token `form-preview-<id>`. De publieke id's veranderen niet |
| Wie mag het zien? | Dezelfde bewaking als de editor: ingelogd en `forms.manage`. Er is geen publiek adres |

**Desktop en Mobiel.** De site stapelt velden op de breedte van *zijn*
venster, en het frame is een venster van zichzelf. `admin/assets/forms-admin.js`
tekent het frame daarom op een vaste breedte en schaalt het in de kolom:

- **Desktop** op minstens 680 pixels, net voorbij de 640 waar de site
  stapelt. Dat is het raster dat elk breder scherm ook toont, met dezelfde
  verdeling, alleen kleiner.
- **Mobiel** op 375 pixels, waar elk veld een hele rij is.

Op een scherm zo smal als een telefoon begint het voorbeeld op *Mobiel*. Het
script leest de hoogte van het formulier in het frame, wat
`allow-same-origin` toestaat terwijl er in het frame zelf niets draait.
Zonder script heeft het frame een vaste hoogte met een eigen schuifbalk, op
de breedte van de kolom.

Het voorbeeld toont de kaart van het blok *Formulier*. Het
offerte-/contactformulier zet hetzelfde formulier in een smallere kolom naast
*Direct contact*: dezelfde velden, dezelfde verdeling, op minder pixels.


## Velden toevoegen en bewerken

### Veld toevoegen: eerst het soort veld

*Veld toevoegen* opent een dialoog met een kaart per type: de naam uit de
catalogus en één zin over waarvoor het is. Daaronder vraagt de dialoog het
label. Eén POST naar `api/admin/create-form-field.php` maakt het veld aan en
opent de veldeditor.

Het label hoort nog bij het toevoegen, omdat de **interne naam** van het veld
eruit wordt gemaakt en daarna vastligt. Een veld eerst aanmaken met een
tijdelijk label zou de sleutel `kort-tekstveld-2` opleveren, voor altijd.

De kaarten zijn radio's, de radiokaarten waarmee *Nieuwe pagina* een sjabloon
kiest. Het endpoint accepteert alleen een geregistreerde sleutel; alles
anders maakt niets aan en brengt de dialoog terug met de fout, het gekozen
type en het getypte label.

**Een schermlezer hoort per kaart de naam van het type, en de rest als
beschrijving.** Het `<label>` om de hele kaart maakt de kaart één klikvlak,
maar zou ook élk woord erop tot de naam van de radio maken: "Kort tekstveld
Eén regel tekst, zoals een naam of een onderwerp. Alle instellingen blijven
behouden", acht keer. Daarom wijst de radio met `aria-labelledby` naar de naam
en met `aria-describedby` naar de uitleg en, in de veldeditor, de regel over
wat een wissel kost. Het zijn dezelfde woorden op dezelfde plek; de CSS en wat
er verstuurd wordt, zijn niet veranderd. De acht radio's houden één naam, dus
de pijltjestoetsen gaan van type naar type en wat gekozen is, blijft de
toestand van de radio zelf. `admin/_form_fields.php` maakt de id's met
`admin_ui_id()`, zodat ze uniek blijven als de kaarten twee keer op een
scherm staan.

### De veldeditor

`admin/form-field.php` toont alleen wat het type gebruikt (de tabel "Wat een
type gebruikt"):

| Kaart | Wat erin staat |
|---|---|
| Soort veld | het huidige type met zijn uitleg, en ingeklapt *Ander soort veld kiezen* |
| Wat de bezoeker leest | label en uitleg in de taal die je bewerkt; de voorbeeldtekst (placeholder) alleen bij een type dat die gebruikt |
| Opties | alleen bij een keuzeveld: een rij per optie met *Standaard*, ↑ en ↓ |
| Invullen | de schakelaar *Verplicht invullen*; bij Toestemming alleen de zin dat het altijd verplicht is |
| Bestanden | alleen bij *Bestand uploaden*: een vinkje per toegestane soort en de maximale grootte (zie *Bestand uploaden*) |
| Breedte | *Breedte in het formulier*: de zes breedtes uit *Breedte van een veld*, bij elk type |
| Technische gegevens | ingeklapt, buiten het formulier: de interne naam |

Een instelling die het type niet gebruikt, staat niet op het scherm met een
opmerking dat hij genegeerd wordt. Hij staat er gewoon niet.

**Wat niet op het scherm staat, blijft zoals het is.**
`api/admin/update-form-field.php` schrijft alleen de instellingen die het
formulier meestuurde. De rest blijft zoals hij opgeslagen is, zoals
`api/admin/update-page.php` het met de deelafbeelding doet. Een keuzelijst die
ooit een tekstveld was, houdt dus zijn oude placeholder, ongebruikt en
onaangeroerd. Een veld dat ongewijzigd wordt opgeslagen, komt byte voor byte
hetzelfde terug. `is_required` heeft daarom een verborgen `0` vóór zijn
schakelaar: anders zou "uit" niet eens aankomen.

### Na opslaan terug naar het formulier

Een veld opslaan is post/redirect/get, en een opslag die doorgaat eindigt
**op het formulier waar het veld bij hoort**, niet op de veldeditor:

```text
POST /api/admin/update-form-field.php
  → 302 /admin/form.php?id=<formulier>&saved=1#form-field-<veld>
```

Het adres komt uit het opgeslagen formulier-id en veld-id, en uit niets wat
het verzoek meestuurt. Een verborgen `form_id`, `return` of `redirect` in een
POST verandert er niets aan, dus er is geen open redirect. Welk veld er
opgeslagen is, en of zijn standaardkeuze moest vervallen, gaat via de sessie
mee naar dat ene scherm. Het formulier noemt het veld dan één keer
(*Veld ‘Voornaam’ opgeslagen.*) en de rij van het veld is het anker, met een
accentrand. Het voorbeeld ernaast toont de wijziging al. `saved=1` blijft de
markering die de opslagbalk als geslaagd leest; het anker telt daarvoor niet
mee, want `Response.url` draagt nooit een fragment.

**Een opslag die niets schreef, blijft op de veldeditor**, zoals altijd: een
fout (een leeg label, een onbekende breedte, een keuzeveld zonder opties)
komt terug met de melding en met alles wat er getypt was, en een typewissel
die op bevestiging wacht ook. Er wordt dan niet naar het formulier geleid.

Een nieuw veld (*Veld toevoegen*) opent na het aanmaken nog steeds zijn
eigen editor: daar staan de instellingen die het label in de dialoog niet
vraagt. Pas de opslag daar brengt je terug.

### Opties en standaardkeuze in één keer

Een keuzeveld had eerst een tekstvak met `NL|EN` per regel, en een
standaardkeuze die uit de **opgeslagen** opties werd opgebouwd. Een nieuwe
optie kon dus pas na een tweede keer opslaan standaard worden, en een
hernoemde standaardoptie maakte het opslaan kapot.

Nu is elke optie een rij met één vak — het label in de taal die je bewerkt —
en een radio *Standaard*, plus *Geen standaardkeuze*. De radio wijst naar de
**rij**, niet naar een tekst. Daardoor kan een optie die je net typt of
hernoemt in dezelfde opslag de standaard zijn.

- **De rij houdt haar identiteit vast.** Elke bestaande optie stuurt haar `id`
  mee, dus hernoemen in welke taal dan ook laat haar waarde, haar plaats en de
  standaardkeuze staan. `default_value` bevat die waarde, niet het label van
  het moment.
- **Bewerk je een andere taal dan de standaardtaal**, dan staat het label uit
  de standaardtaal als voorbeeldtekst in het vak: laat je het leeg, dan leest
  de bezoeker dat label.
- **Een geleegde rij is geen optie meer.** Was die rij de standaard, dan
  heeft het veld na opslaan geen standaard, en het formulier waar je daarna
  op terugkomt meldt dat. Nooit een standaard die naar niets wijst.
- **Lege en dubbele rijen** vallen weg volgens de regels die er al waren. Een
  `|` is sinds de opties echte rijen zijn gewoon een teken in een label.
- **Zonder JavaScript** staan er drie lege rijen onder de ingevulde; na
  opslaan komen er weer drie. `admin/assets/forms-admin.js` voegt rijen toe
  en haalt ze weg. Haal je de standaardrij weg, dan springt de keuze terug op
  *Geen standaardkeuze*.

### De volgorde van de opties

De volgorde is **de volgorde van de rijen op het moment van versturen**. De
browser verstuurt de velden in documentvolgorde,
`api/admin/update-form-field.php` leest `option_label[…]` in die volgorde en
schrijft `form_field_options.sort_order` in diezelfde volgorde, en het publieke
formulier toont ze zo. Dezelfde regels voor lege en dubbele opties als altijd.

Met JavaScript heeft elke rij **↑** en **↓** (naam voor een schermlezer:
*Optie 2 omhoog*). Het script verplaatst de rij in de pagina en doet verder
niets:

- **De index gaat mee.** `option_id[i]`, `option_label[i]` en de radio
  *Standaard* met waarde `i` zitten in dezelfde rij, dus de optie houdt haar
  identiteit en de standaardkeuze blijft bij dezelfde optie, waar die ook
  heen gaat.
- **De eerste rij kan niet omhoog, de laatste niet omlaag.** Een lege rij is
  ook een rij; een lege rij valt bij opslaan weg zoals altijd.
- **De focus blijft in de rij die verplaatst.** Het script verplaatst de
  buurrij, zodat de knop in de pagina blijft staan. Wordt die knop
  uitgeschakeld (bovenaan of onderaan beland), dan gaat de focus naar de
  andere pijl van dezelfde rij. Een `role="status"`-regel zegt *Verplaatst
  naar plek 2.*, in de woorden van de catalogus.

Zonder JavaScript zijn de pijlen verborgen en verander je de volgorde door de
rijen anders in te vullen. Opslaan werkt daar precies zoals altijd.
Drag-and-drop is er bewust niet: de pijlen werken met toetsenbord, muis en
touch, zonder bibliotheek.

### Een ander soort veld

**Het type blijft wijzigbaar, maar nooit met stil verlies.** De andere optie
was het type na aanmaken vastzetten. Dat is eenvoudiger code, maar het
enige alternatief voor de redacteur is dan verwijderen en opnieuw maken, en
dat verliest méér: de Engelse teksten, de uitleg, de plek in het formulier,
het antwoordadres, en bij een ander label ook de interne naam waaronder oude
inzendingen staan. De gewone wissels, van keuzelijst naar keuzerondjes of van
kort naar lang tekstveld, verliezen juist niets.

`FormFieldTypeChange::losses()` bepaalt uit de opgeslagen rij wat een nieuw
type zou verliezen. Alleen een instelling die het oude type gebruikte én die
iets bevat, telt:

| Verlies | Wanneer |
|---|---|
| de voorbeeldtekst | tekst-achtig → keuzeveld, selectievakje of toestemming, met een ingevulde placeholder |
| de opties | keuzeveld → elk ander soort, met opties |
| de standaardkeuze | keuzeveld → elk ander soort, met een bruikbare standaard |
| het antwoordadres | e-mailadres → ander soort, als dít veld het antwoordadres van het formulier is |
| de toegestane bestanden en de maximale grootte | bestand uploaden → elk ander soort |

Verliesvrij zijn dus onder meer keuzelijst ↔ keuzerondjes, tussen de vier
tekst-achtige types (behalve het antwoordadres), en alles vanaf selectievakje
of toestemming. Andere validatie (een e-mailadres, een telefoonnummer, 5000
in plaats van 255 tekens) is geen verlies: een bewaarde inzending houdt haar
eigen kopie.

Elke kaart onder *Ander soort veld kiezen* zegt dit vooraf: *Alle
instellingen blijven behouden*, of *Verdwijnt bij opslaan: de opties, de
standaardkeuze*. Bij opslaan:

1. **Verliest de wissel niets en toont het scherm al alles wat het nieuwe
   type nodig heeft**, dan wordt meteen opgeslagen.
2. **Anders wordt niets geschreven.** Dat geldt ook als het nieuwe type een
   instelling heeft die nog niet op het scherm stond, zoals de opties van een
   tekstveld dat een keuzelijst wordt. De invoer gaat terug naar de editor, en
   die is dan al de editor van het nieuwe type. Bovenaan staat een kaart met
   *van … naar …*, wat er verdwijnt met de inhoud erbij ("de 2 opties: Ja,
   Nee", "de standaardkeuze ‘Ja’"), en `confirmed_type`.
3. **Pas een opslag met `confirmed_type` voor precies dat type gaat door**,
   en wist precies wat de kaart noemde. Kies je intussen weer een ander type,
   dan wordt opnieuw gevraagd. Dit is de flow van een nieuw webadres
   (`confirmed_slug`), en het endpoint dwingt hem af. Een script dat de kaart
   overslaat, verliest dus ook niets.

Toestemming blijft verplicht als hij een selectievakje wordt: het
selectievakje heeft een schakelaar die het toestemmingsscherm niet had, dus
de editor toont die eerst, aan.

### Niet-opgeslagen wijzigingen

De veldeditor heeft de opslagbalk van het CMS (`admin/_save_bar.php`,
[`PAGE-EDITOR.md`](PAGE-EDITOR.md), "De opslagbalk"). Die bewaakt elk
POST-formulier in `<main>` met iets om in te vullen, en dat is hier alleen het
formulier met de instellingen. De daadwerkelijke opslag blijft dat ene
gewone formulier naar `api/admin/update-form-field.php`.

| Handeling | Opslagbalk |
|---|---|
| Label, uitleg, voorbeeldtekst, *Verplicht invullen*, een optie typen, *Standaard* kiezen, een ander soort veld kiezen | niet-opgeslagen (`input` of `change`) |
| Een optierij toevoegen, weghalen of verplaatsen | niet-opgeslagen: `forms-admin.js` stuurt een `change`, want er wordt niets getypt |
| *Technische gegevens* of *Ander soort veld kiezen* open- of dichtklappen | niets: geen formulierveld |
| De bewerktaal wisselen | niets: dat formulier staat in de zijbalk, buiten `<main>`. Is er iets niet opgeslagen, dan waarschuwt de browser zoals op elk scherm |
| *Veld verwijderen* | niets: dat formulier heeft alleen verborgen velden |
| Opslaan, met de balk of met Enter in een veld | terug op het formulier met `?saved=1` (zie *Na opslaan terug naar het formulier*); een geweigerde opslag komt terug op de veldeditor, zonder die markering |

**Wat terugkomt zonder geschreven te zijn, is niet opgeslagen.** Na een
geweigerde opslag, en terwijl een typewissel op bevestiging wacht, staat er
invoer op het scherm die niet in de database staat. Het formulier draagt dan
`data-save-bar-unsaved`: de balk zegt *Niet-opgeslagen wijzigingen* en weggaan
waarschuwt. Anders zou de balk *Alles opgeslagen* zeggen onder een kaart met
*Er is nog niets opgeslagen*.

Op dat bevestigscherm doet *Opslaan* in de balk wat de knoppen in het
formulier doen: bevestigen. Dat is dezelfde vraag die al gesteld is ("Verdwijnt
bij opslaan: …"), en de endpoint dwingt `confirmed_type` nog steeds zelf af.
*Annuleren, soort niet wijzigen* draagt `data-save-bar-discard`: dat is het
antwoord al, dus de browser vraagt niet nog een keer of je de pagina wilt
verlaten.

### Zonder JavaScript

Alles hierboven werkt zonder script. De server rendert de juiste editor; het
script maakt het alleen prettiger.

| Handeling | Zonder JavaScript | Met JavaScript |
|---|---|---|
| Veld toevoegen | de knop is een link naar het formulier met `add_field=1`, dat de dialoog open en als gewone kaart in de pagina rendert | dezelfde dialoog als modal; Escape, *Annuleren* en een klik ernaast sluiten hem en de focus gaat terug |
| Type kiezen | radiokaarten | idem |
| Opties toevoegen of weghalen | drie lege rijen per opslag, of een rij leegmaken | *Optie toevoegen* en *Verwijderen* per rij |
| Opties ordenen | de rijen anders invullen en opslaan | ↑ en ↓ per rij |
| Type wisselen | kaart kiezen, opslaan, bevestigen op de editor van het nieuwe type | idem |
| Iets verwijderen | het formulier gaat direct; de endpoint bewaakt | eerst de dialoog van het CMS |
| Niet-opgeslagen wijzigingen | geen balk en geen waarschuwing | de opslagbalk |

### De interne naam

De `field_key` is de naam waaronder het formulier een antwoord verstuurt en
een inzending het bewaart. Hij wordt bij het toevoegen uit het label gemaakt
en verandert daarna nooit, ook niet bij een typewissel of een nieuw label.
Een redacteur heeft hem nergens voor nodig, dus hij staat niet meer in de
veldlijst. Wie het formulier technisch koppelt, vindt hem in de veldeditor
onder *Technische gegevens*, met die uitleg erbij.

## Rechten

| Permissie | Waarvoor |
|---|---|
| `forms.manage` | Formulieren en velden maken en wijzigen |
| `forms.submissions` | Bewaarde inzendingen inzien en verwijderen |
| `pages.manage` | Een formulier op een pagina plaatsen (het blok) |

**Formulieren bouwen geeft geen toegang tot wat mensen hebben gestuurd**, en
paginabeheer ook niet. Niets impliceert `forms.submissions`: dat is een recht
dat iemand met opzet uitdeelt, want daar zitten de namen, adressen en vragen
van bezoekers achter. Een Super Admin houdt automatisch alles.

## Privacy

- Inzendingen bewaren is **per formulier uit tenzij de eigenaar het aanzet**
  (in de editor onder Geavanceerd).
- Staat het uit, dan blijft er ná het verzoek niets van de bezoeker in de
  database staan; de e-mail ís de bezorging.
- Bewaren uitzetten verwijdert niets. Wat al bewaard is, blijft staan tot
  iemand het bij Inzendingen verwijdert; de editor en het overzicht zeggen
  dat er dan nog inzendingen zijn.
- Een inzending is nergens publiek op te vragen, staat niet in de zoekfunctie
  en niet in de sitemap.
- Verwijderen is definitief — inclusief de antwoorden en **elk bestand**
  van de inzending. Er is geen prullenbak, want een prullenbak is
  persoonsgegevens die je op een minder zichtbare plek bewaart.
- Een formulier dat niets bewaart, neemt nooit meer bestanden aan dan de
  melding kan meenemen (zie *Bestand uploaden*, "E-mail"), zodat er niets
  verloren gaat.
- Een formulier dat niets bewaart, bewaart ook geen bestand: dat wordt na het
  mailen direct weer verwijderd.
- **Er komt nooit een ingevuld antwoord in het serverlog.** Een mislukking
  logt het formulier, het inzendingsnummer en de technische reden.
- Het IP van een bezoeker wordt alleen als gezouten hash gebruikt, voor de
  rate limit, en die rijen worden na een dag opgeruimd.

Hoe lang inzendingen bewaard blijven bepaalt de eigenaar van de site: er is
geen automatische bewaartermijn. Dat is bewust — een opruimschema hoort bij
een cron-voorziening die dit project niet heeft.

## Gebruik en veilig verwijderen

`FormUsage` beantwoordt "waar staat dit formulier" door de twee bloktabellen
te bevragen die formulieren kunnen plaatsen. Geen generieke referentiemotor en
geen event bus; komt er ooit een module bij die formulieren plaatst, dan komt
haar query hierbij.

Een formulier wordt **niet verwijderd** zolang:

- het nog op een pagina staat (het blok zou dan stilletjes niets meer tonen),
  of
- het nog bewaarde inzendingen heeft (persoonsgegevens verdwijnen niet als
  bijvangst van opruimen).

De reden staat erbij, met een link naar elke plek waar het staat — net als bij
de Mediabibliotheek. De controle zit in het **endpoint**, niet alleen in de
knop: een verwijdering die niet terug te draaien is mag nooit afhangen van een
verborgen knop.

Een veld verwijderen mag altijd, en raakt bewaarde inzendingen niet aan.

### Eerst vragen, in de dialoog van het CMS

Elke verwijdering in Forms vraagt eerst, en niet meer met de `confirm()` van
de browser. Het gaat om een veld (in de veldlijst en in de veldeditor), een
formulier (in het overzicht en in de editor) en een bewaarde inzending. Het
is de gedeelde dialoog uit [`ADMIN-UI.md`](ADMIN-UI.md), "Bevestigen voordat
iets weg is": `admin_confirm_attributes()` op het formulier en
`admin_confirm_dialog()` één keer per scherm.

| Wat | Titel | Wat de dialoog noemt |
|---|---|---|
| Veld | *Veld verwijderen?* | het label en het formulier, en dat bewaarde inzendingen leesbaar blijven |
| Formulier | *Formulier verwijderen?* | de naam, en dat het met al zijn velden definitief weg is |
| Inzending | *Inzending verwijderen?* | wanneer en op welk formulier ze binnenkwam, en dat antwoorden en bijlage mee gaan |

De knop die doorgaat heet *Verwijderen* en heeft de destructieve stijl.
*Annuleren* staat vooraan en heeft de focus. Annuleren, Escape en een klik
naast de dialoog versturen niets en zetten de focus terug op de knop die
vroeg. Bij *Verwijderen* verstuurt de browser hetzelfde formulier met
hetzelfde token naar dezelfde endpoint: **de dialoog beslist niets.** Login,
permissie, POST, CSRF en de controle of een formulier weg mag, blijven in de
endpoints. Zonder JavaScript gaat het formulier direct, zoals met de inline
`confirm()` vroeger. Een formulier dat niet weg mag, krijgt nog steeds geen
verwijderknop.

## Frontend

`assets/css/blocks/form.css` en `assets/js/blocks/form.js` worden opgeëist
door de twee formulierblokken en door niets anders. Een pagina zonder
formulier laadt ze dus niet, en twee formulieren op één pagina laden ze één
keer. Er staat geen formulierinitialisatie in `assets/js/core.js`.

De besturingselementen zelf (`.form-grid`, `.form-field`, `.form-error`,
`.form-status`, `.check-pill`) worden gedeeld met het afrekenen en staan in
`assets/css/core.css`; het blokbestand herhaalt ze niet en voegt alleen toe
wat echt van dit blok is. Het raster van twaalf kolommen en de zes breedtes
zijn zo'n toevoeging: ze gelden onder `.vvl-form`, dus alleen voor een
formulier van Forms, en niet voor het afrekenen (zie *Breedte van een veld*).
Het voorbeeld in de formuliereditor vraagt `form.css` op dezelfde manier op,
via `FormBlock::styles()`, en `form.js` niet.

## Testen

Commando's en tiers staan in [`TESTING.md`](TESTING.md).

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
docker compose exec php_test php vendor/bin/phpunit --testsuite blocks
```

| Wijziging | Draai |
|---|---|
| Veldtype, validatie, optielijsten, spamregels, typewissel | `fast` |
| Repository, adminscherm, veldeditor, gebruik, verwijderen | `fast` → `cms` |
| Rendering, blok, plaatsing, assets | `fast` → `blocks` (heeft `php_test` nodig) |
| Migratie/backfill | `cms` → `--group migration-backfill` → volledige suite |

`FormFieldTypeTest` loopt automatisch over élk geregistreerd veldtype, dus een
nieuw type wordt daar meegenomen zonder dat je die test aanpast.
`FormAdminHttpTest` (suite `cms`) start zijn eigen webserver en controleert
"Actief en uit" van begin tot eind: het endpoint, de pagina met beide
formulierblokken, en de schakelaar in de formuliereditor met zijn guards.
Hij controleert ook welk formulier de opslagbalk op de formuliereditor
bewaakt (elke instelling, ook onder Geavanceerd, en verder niets), en wanneer
dat scherm als niet-opgeslagen begint.
Hij bewijst ook "Eerst vragen": elke verwijdering vraagt in de dialoog van het
CMS en noemt wat weggaat, een bevestigd verzoek verwijdert precies dat ene
veld, formulier of die ene inzending, en zonder login, permissie, POST of
token wordt niets verwijderd.
`FormFieldEditorHttpTest` (suite `cms`) doet hetzelfde voor "Velden toevoegen
en bewerken". Hij controleert de typekiezer met zijn catalogusnamen, toevoegen
zonder script en de editor per type. Ook bewaakt hij opties en standaard in
één opslag, de byte-gelijke opslag van een onaangeroerd veld, en elke soort
typewissel met zijn bevestiging. Verder: de naam en beschrijving van elke
typekaart, welk formulier de opslagbalk bewaakt en wanneer het scherm als
niet-opgeslagen begint, en verplaatste opties tot in het publieke formulier.
`FormFieldTypeChangeTest` (`fast`) schrijft voor elk paar types uit wat een
wissel kost.
`FormBoundaryTest` bewaakt de grenzen: rechten, guards, CSRF, geen
Shop-koppeling, geen bedrijfsnaam in generieke code, en de `prime()`-aanroep
in elk paginatemplate. Hij bewaakt ook dat geen Forms-scherm nog `confirm()`
gebruikt, en wat het script van de optierijen doet bij verplaatsen.
`MultilingualBoundaryTest` bewaakt de talenkant: dat niets de gedropte
woordkolommen nog leest, dat de terugval alleen van `LanguageFallback` komt,
dat een optie op haar waarde gepost wordt en alleen haar label vertaald is, en
dat de twee editors één taal tegelijk schrijven.
`FormWordsAndOptionMigrationTest` (`migration`) draait de verhuizing op een
verse, een bijgewerkte en een kapotte wegwerpdatabase.
`FormUploadTest` (`fast`) bewaakt *Bestand uploaden* zonder database: de
gesloten lijsten en het plafond, de keuring van een `$_FILES`-ingang met echte
bestanden (elke soort, PHP's foutcodes, leeg, te groot, vervalst, verboden,
lijst-vormig, de schoongemaakte naam), dat de validator `$_FILES` op de
sleutel van de definitie leest, het besturingselement en het mailbudget.
`FormUploadHttpTest` (`cms`) doet het over echt HTTP met echte
multipart-uploads en een eigen opslagmap: opslag buiten de webroot met
inzending, veld en hash; elke weigering zonder iets te schrijven; geen wees
bij een formulier dat niets bewaart; PHP's eigen limieten en een verzoek
boven `post_max_size` (op een tweede server met 1 MB); downloaden met de
headers, en elke weigering (niet ingelogd, geen recht, IDOR, pad); de oude
bijlage; verwijderen met en zonder ontbrekend bestand; de tekst van de mail.
`FormFieldEditorHttpTest` bewijst de kaart *Bestanden*, de weigering van alles
buiten de lijsten, en beide typewissels. `FormFileUploadMigrationTest`
(`migration` en `cms`) draait `20260925100000` op een verse, een bijgewerkte
en een halverwege gestopte wegwerpdatabase.
`FormFieldWidthTest` (`fast`) bewaakt *Breedte van een veld* zonder database:
de lijst en de classes, dat elke andere waarde als `full` leest, elk type op
elke breedte met precies één class en zonder `style`, dat de volgorde van de
velden nooit verandert, dat `form.css` elke breedte over zijn kolommen legt en
op 640 pixels stapelt, en dat de migratie de oude regel van de renderer
opschrijft. `FormFieldLayoutWidthMigrationTest` (`migration`) draait
`20260924160000` op een verse, een bijgewerkte en een halverwege gestopte
wegwerpdatabase. `FormFieldEditorHttpTest` bewijst de breedte in de
veldeditor (elk type, weigeren, per taal, niet meegestuurd blijft staan) en
de terugweg naar het formulier. `FormAdminHttpTest` bewijst de compacte
rijen, de ene knop *Opslaan*, het frame en het voorbeeld zelf: dezelfde
velden als `render_form()`, in de bewerktaal, zonder script, zonder
verzenden, zonder gedeelde id's en achter de bewaking van de editor.

## Bewust niet ondersteund

Niet vergeten, maar met opzet buiten V1 gelaten. Elk hiervan is een feature
met eigen randgevallen, en een formulierbouwer die alles kan is een product op
zichzelf:

**Velden** — meerdere bestanden in één uploadveld, SVG- en ZIP-uploads, een
tijdelijke uploadcache na een geweigerde inzending, datum- en tijdkiezers, adres-composieten,
repeaters, handtekeningen, rich text, verborgen waarden, betaalvelden,
productvelden, berekende velden, vooringevulde tekstvelden.

**Logica** — conditionele velden, meerstapsformulieren, vertakking,
berekeningen, opslaan-en-later-verdergaan, gedeeltelijke inzendingen.

**Bezorging** — meerdere ontvangers, CC/BCC, routering op antwoorden,
autoresponders, mailinglijst-integraties, een sjablooneditor, wachtrijen en
retries.

**Beheer** — CRM-fasen, notities, toewijzing, labels, CSV-export,
spreadsheet-integraties, conversiedashboards, automatische bewaartermijnen.

**Vormgeving** — formulierthema's, CSS per veld, eigen HTML, eigen
JavaScript.

**Beveiliging** — CAPTCHA-diensten van derden op publieke formulieren.

V1 lost gewone contact- en aanvraagformulieren goed op. Dat is de hele opzet.

### Forms 2.0: fase 1 en fase 2

| | Fase 1 | Fase 2 |
|---|---|---|
| Wat | De formulierbouwer: compacte veldrijen, één *Opslaan*, terug naar het formulier na een veld, een breedte per veld, het voorbeeld | Het uploadveld `file` in de bouwer (*Bestand uploaden*) |
| Uploads | Niets veranderd | Een gewoon veldtype; de eigen bijlage van het contactblok is omgezet naar zo'n veld en verdwenen |
| Breedte | Voor elk veld in de lijst | Ook voor een uploadveld; het raster hoefde niet te veranderen |

