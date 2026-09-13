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
| Adminschermen | `admin/forms.php`, `admin/form.php`, `admin/form-field.php`, `admin/form-submissions.php`, `admin/form-submission.php` |
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
schrijfendpoints voor kosten en niets opleveren.

**Een keuzeveld mag op een van zijn eigen opties beginnen** (`default_value`).
Dat is de enige vorm van vooringevulde inhoud die dit CMS kent, en met opzet:
een vooringevuld tekstveld bevat een antwoord dat de bezoeker nooit heeft
getypt en verstuurt dat gewoon mee, en een voorgevinkt akkoordvinkje is een
akkoord dat niemand heeft gegeven. Bij een handvol zichtbare, elkaar
uitsluitende keuzes is ergens beginnen een dienst in plaats van een verzonnen
antwoord — en de bezoeker kan altijd iets anders kiezen.

De waarde is **altijd een van de opties van dat veld**. Die regel staat op één
plek (`FormField::isUsableDefault()`) en werkt in twee richtingen: de
formuliereditor weigert er een die er niet bij staat, en het leesmodel laat er
een vallen die er ooit toch in kwam. De redacteur kiest hem uit een
keuzelijst, nooit als vrije tekst.

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

| Sleutel | Wat het is |
|---|---|
| `text` | Eén regel tekst |
| `textarea` | Meerdere regels (max. 5000 tekens, houdt regeleindes) |
| `email` | E-mailadres; het enige type dat antwoordadres kan zijn |
| `tel` | Telefoonnummer; **geen landformaat afgedwongen** |
| `select` | Keuzelijst uit een gesloten optielijst |
| `radio` | Dezelfde gesloten lijst als keuzerondjes |
| `checkbox` | Eén vinkje (bewaard als "Ja") |
| `consent` | Akkoordvinkje; **altijd verplicht** |

Elk type is één klasse die vier vragen beantwoordt — hoe het heet, hoe het
rendert, hoe het een waarde schoonmaakt, en welke eigen regel het heeft. Er
staat nergens een `switch` over veldtypes: niet in de renderer, niet in de
validator en niet in het admin.

### Een veldtype toevoegen

1. `src/Service/Forms/FieldTypes/<Naam>FieldType.php`, extends
   `FormFieldType`. Implementeer `key()`, `label()`, `renderControl()` en
   `normalize()`; de rest heeft een veilige standaard.
2. Eén regel in `FormFieldTypes::MAP`.
3. Draai `--testsuite fast`: `Tests\Service\FormFieldTypeTest` loopt
   automatisch over élk geregistreerd type en controleert het hele contract,
   dus je nieuwe type wordt meegenomen zonder dat je die test aanpast.

Meer is er niet. De renderer, de validator, het admin en de e-mail hebben er
geen regel voor nodig.

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

- **de kaart "Direct contact"** ernaast, die het e-mailadres en de
  werkplaatsplaats uit Site-instellingen toont en de tweede kolom van het
  raster vult. Beide zijn daar optioneel; een regel zonder waarde wordt
  weggelaten;
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

- Inzendingen bewaren is **per formulier uit tenzij de eigenaar het aanzet**.
- Staat het uit, dan blijft er ná het verzoek niets van de bezoeker in de
  database staan; de e-mail ís de bezorging.
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
| Veldtype, validatie, optielijsten, spamregels | `fast` |
| Repository, adminscherm, gebruik, verwijderen | `fast` → `cms` |
| Rendering, blok, plaatsing, assets | `fast` → `blocks` (heeft `php_test` nodig) |
| Migratie/backfill | `cms` → `--group migration-backfill` → volledige suite |

`FormFieldTypeTest` loopt automatisch over élk geregistreerd veldtype, dus een
nieuw type wordt daar meegenomen zonder dat je die test aanpast.
`FormBoundaryTest` bewaakt de grenzen: rechten, guards, CSRF, geen
Shop-koppeling, geen bedrijfsnaam in generieke code, en de `prime()`-aanroep
in elk paginatemplate.

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
