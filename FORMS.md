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
| Migraties + tabellen | `db/migrations/20260909300000_create_the_core_forms_tables.php`, `…310000_migrate_the_contact_form_into_a_form.php`, `…320000_add_a_default_choice_to_form_fields.php` |
| Veldtypes (gesloten register) | `src/Service/Forms/FieldTypes/`, plus `FormFieldTypes` — dé registratielijst |
| Namen van veldtypes | `formfieldtype.<key>.label` en `.description` in `src/Service/Language/messages/` |
| Wat een typewissel kost | `FormFieldTypeChange` |
| Leesmodel | `FormDefinition`, `FormField`, `FormFieldOptions`, `FormText` |
| Opzoeken + cache | `FormCatalog` |
| SQL | `src/Repository/FormRepository.php`, `FormSubmissionRepository.php`, `FormBlockRepository.php` |
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
| Adminschermen | `admin/forms.php`, `admin/form.php`, `admin/form-field.php`, `admin/form-submissions.php`, `admin/form-submission.php`; gedeeld: `admin/_form_fields.php` (typenamen, typekaarten, wat een wissel kost) en `admin/assets/forms-admin.js` |
| Frontend | `assets/css/blocks/form.css`, `assets/js/blocks/form.js` |
| Tests | `tests/Service/Form*.php`, `tests/Repository/ContactFormMigrationTest.php` |

## Het model

### `forms`

Wat het formulier is en wat het doet als iemand het verstuurt: naam (alleen
voor de beheerder), een gegenereerde `internal_key`, aan/uit, de tekst op de
knop, het bedankbericht, het ontvangende e-mailadres, welk veld het
antwoordadres levert, en of inzendingen bewaard worden. Allemaal tweetalig
waar dat zin heeft.

De `internal_key` is **geen publieke sleutel**: hij staat in het verborgen
veld waarmee het endpoint weet welk formulier is verstuurd, en verder nergens.
Een formulier wordt geadresseerd door het blok dat het toont.

### `form_fields`

Eén rij per veld: `field_key`, `field_type`, tweetalig label, placeholder en
uitleg, verplicht ja/nee, volgorde, en bij een keuzeveld de opties plus
eventueel een standaardwaarde.

**Geen EAV.** Een veld is een rij met echte kolommen. De enige
type-specifieke instelling die bestaat is de optielijst van een keuzeveld, en
die staat in één `TEXT`-kolom die `FormFieldOptions` leest — één keuze per
regel, `NL|EN` met de Engelse helft optioneel. Een kindtabel zou daar drie
schrijfendpoints voor kosten en niets opleveren. De redacteur ziet die regels
niet: de veldeditor toont één rij per optie, met een vak per taal, en
`FormFieldOptions::rowsToStored()` maakt daar dezelfde regels van, met
dezelfde regels voor lege en dubbele opties en het maximum van vijftig.

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
moment van versturen waren. Er wordt nergens teruggejoined naar `form_fields`.
Daarom blijft een aanvraag van vorig voorjaar leesbaar nadat de redactie een
veld hernoemt, verplaatst of weghaalt — en blijft het antwoord op een
verwijderd veld gewoon staan.

`form_submission_attachments` hoort bij het contactblok, niet bij de motor —
zie "Het contactformulier" hieronder.

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

| Verklaring | `text`, `textarea`, `tel` | `email` | `select`, `radio` | `checkbox` | `consent` |
|---|---|---|---|---|---|
| `usesPlaceholder()` | ja | ja | nee | nee | nee |
| `usesOptions()` | nee | nee | ja | nee | nee |
| `usesDefaultValue()` | nee | nee | ja | nee | nee |
| `requiredIsFixed()` | nee | nee | nee | nee | ja |
| `holdsEmailAddress()` | nee | ja | nee | nee | nee |

Label, Engels label en uitleg gebruikt elk type.

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

## Tweetaligheid

NL is de inhoud, EN optioneel, leeg EN betekent "gelijk aan NL" — dezelfde
regel als de rest van het CMS. Die terugval wordt op **één** plek toegepast,
in `FormText::of()`, zodat geen template, validator of e-mailbouwer hem hoeft
te onthouden.

De publieke markup schrijft beide talen in `data-nl`/`data-en` (en
`data-nl-placeholder`/`data-en-placeholder`), en `assets/js/core.js` wisselt
ze in de browser. Er wordt niets server-side vertaald.

Een bewaarde inzending legt het **Nederlandse** label vast, zodat historie
niet afhangt van welke taal iemand toevallig aanstond.

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

### Ontvanger

Twee stappen, en geen bedrijf in de code:

1. het adres dat de beheerder op het formulier heeft ingevuld;
2. anders het contactadres van de site (Site-instellingen).

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
noemen ze wat de beheerder kan doen. Het formulierscherm en Site-instellingen
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

Wat het blok zelf houdt zijn de twee dingen die een generiek formulierblok
niet hoort te hebben:

- **de kaart "Direct contact"** ernaast, die het e-mailadres en de plaats
  (of regio) uit Site-instellingen toont en de tweede kolom van het raster
  vult. Beide zijn daar optioneel; een regel zonder waarde wordt weggelaten.
  De kaart zegt verder niets over het bedrijf: een belofte als een
  reactietijd of "ophalen op afspraak" is inhoud zonder veld, en staat er
  dus niet;
- **de optionele bijlage**. Forms V1 heeft geen uploadveld en de
  formulierbouwer kan er geen maken — maar deze site accepteert al jaren een
  foto of pdf bij een offerteaanvraag, en dat weghalen zou een regressie zijn,
  geen vereenvoudiging. Het bestand wordt gevalideerd op zijn magic bytes,
  opgeslagen buiten de webroot, meegestuurd met de melding en is alleen via
  het CMS te downloaden. Een bestand dat wordt gepost naar een formulier
  waarvoor geen enkel contactblok bijlagen aan heeft staan, wordt genegeerd
  (`FormAttachmentPolicy`) — dat is een configuratievraag met een
  server-side antwoord, niet iets wat het request mag zeggen.

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

**De editor** (`admin/form.php`) is één formulier in drie kaarten, in de
volgorde waarin een redacteur erover nadenkt:

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

Onder de kaarten staan de velden, met per veld het label, de naam van het
type en bij een keuzeveld het aantal opties. De technische naam van een veld
staat daar niet (zie "De interne naam" hieronder).

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
| Wat de bezoeker leest | label en uitleg in de taalpanes; de voorbeeldtekst (placeholder) alleen bij een type dat die gebruikt |
| Opties | alleen bij een keuzeveld: een rij per optie met *Standaard*, ↑ en ↓ |
| Invullen | de schakelaar *Verplicht invullen*; bij Toestemming alleen de zin dat het altijd verplicht is |
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

### Opties en standaardkeuze in één keer

Een keuzeveld had eerst een tekstvak met `NL|EN` per regel, en een
standaardkeuze die uit de **opgeslagen** opties werd opgebouwd. Een nieuwe
optie kon dus pas na een tweede keer opslaan standaard worden, en een
hernoemde standaardoptie maakte het opslaan kapot.

Nu is elke optie een rij met een vak per taal en een radio *Standaard*, plus
*Geen standaardkeuze*. De radio wijst naar de **rij**, niet naar een tekst.
Daardoor kan een optie die je net typt of hernoemt in dezelfde opslag de
standaard zijn.

- **Opgeslagen blijft wat er altijd stond**: dezelfde regels in dezelfde
  kolom, en `default_value` bevat het Nederlandse label van die optie.
- **Een geleegde rij is geen optie meer.** Was die rij de standaard, dan
  heeft het veld na opslaan geen standaard, en de editor meldt dat. Nooit een
  standaard die naar niets wijst.
- **Lege en dubbele rijen** vallen weg volgens de regels die er al waren. Een
  `|` in de Nederlandse helft wordt geweigerd, want daar begint in de
  opslag de Engelse helft.
- **Zonder JavaScript** staan er drie lege rijen onder de ingevulde; na
  opslaan komen er weer drie. `admin/assets/forms-admin.js` voegt rijen toe
  en haalt ze weg. Haal je de standaardrij weg, dan springt de keuze terug op
  *Geen standaardkeuze*.

### De volgorde van de opties

De volgorde is **de volgorde van de rijen op het moment van versturen**. De
browser verstuurt de velden in documentvolgorde,
`api/admin/update-form-field.php` leest `option_nl[…]` in die volgorde,
`FormFieldOptions::rowsToStored()` schrijft de regels in die volgorde, en het
publieke formulier toont ze in de opgeslagen volgorde. Er is dus geen
positiekolom en geen nieuw opslagmodel: dezelfde `NL|EN`-regels, dezelfde
regels voor lege en dubbele opties.

Met JavaScript heeft elke rij **↑** en **↓** (naam voor een schermlezer:
*Optie 2 omhoog*). Het script verplaatst de rij in de pagina en doet verder
niets:

- **De index gaat mee.** `option_nl[i]`, `option_en[i]` en de radio
  *Standaard* met waarde `i` zitten in dezelfde rij, dus de twee talen blijven
  één optie en de standaardkeuze blijft bij dezelfde optie, waar die ook
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
| Opslaan, met de balk of met de eigen knop | schoon na `?saved=1`; een geweigerde opslag komt terug zonder die markering |

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
- Verwijderen is definitief — inclusief de antwoorden en een eventuele
  bijlage. Er is geen prullenbak, want een prullenbak is persoonsgegevens die
  je op een minder zichtbare plek bewaart.
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
wat echt van dit blok is.

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

## Bewust niet ondersteund

Niet vergeten, maar met opzet buiten V1 gelaten. Elk hiervan is een feature
met eigen randgevallen, en een formulierbouwer die alles kan is een product op
zichzelf:

**Velden** — uploads in de bouwer, datum- en tijdkiezers, adres-composieten,
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
