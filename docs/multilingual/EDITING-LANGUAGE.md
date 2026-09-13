# De bewerktaal

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Laag 2, welke taalversie
van de website-inhoud een beheerder bewerkt: de schakelaar in de schil, de
taalvelden in elke editor en wat een schrijf-endpoint met een vertaling mag.
Wat een bezoeker bij een lege vertaling ziet, staat in
[`WEBSITE-LANGUAGES.md`](WEBSITE-LANGUAGES.md); de vertaalknop in
[`AUTOMATIC-TRANSLATION.md`](AUTOMATIC-TRANSLATION.md).

## De taalwissel in het CMS

Eén schakelaar, in de schil, op élk adminscherm:

```text
CONTENT BEWERKEN
[ NL ] [ EN ]
```

Hij staat in `admin/_header.php`, direct onder de sitenaam, en hij POST't naar
`api/admin/update-content-language.php` met een CSRF-token zoals elke andere
schrijfactie hier. Daarna keert hij terug naar het scherm waar je was: welke
taal een formulier toont wordt op de **server** beslist, dus de pagina moet
opnieuw gerenderd worden — maar het is een taalwissel, geen navigatie.

**Geen tweede taalkiezer.** Bewerkschermen tónen alleen in welke taal je zit:

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.
```

Passief, één keer per scherm. Er stond vroeger een tabbladenrij per formulier,
en dat was precies de fout: de taalkeuze zat per scherm (dus opnieuw kiezen op
elke pagina die je opende) en was vanuit de rest van het CMS onzichtbaar. Twee
knoppen die het over dezelfde staat oneens kunnen zijn is erger dan één.

## Bewerken in één taal tegelijk

Dit is waar de hele stap om begonnen is.

Een bewerkscherm toont de velden van **één** taal: die van de schakelaar in de
schil. Nooit twee kolommen, nooit een `(NL)`-achtervoegsel, nooit een tweede
taalkiezer op het scherm zelf.

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.

Titel        [ ............... ]
Introtekst   [ ............... ]
Knoptekst    [ ............... ]
```

De indicator staat **één keer per scherm**; de vertaalknop staat één keer per
**formulier**. Een detailsectie is zes kleine formulieren onder elkaar en elk
daarvan heeft zijn eigen vertaalknop nodig, want vertalen leest de velden van
het formulier waar de knop in staat. Zes keer "Je bewerkt: English" onder
elkaar is ruis, en de schil zegt het toch al.

### Hoe van taal wisselen niets weggooit

Dit is de belangrijkste eigenschap van het onderdeel. De schrijf-endpoints van
dit project schrijven **elke kolom van hun formulier bij elke opslag** — dat is
precies waarom een gedeeltelijke POST hier gevaarlijk is ([`PAGE-EDITOR.md`](../../PAGE-EDITOR.md)).
Een veld dat gewoon uit de markup wordt weggelaten zou dus als leeg worden
opgeslagen, en de vertaling zou weg zijn.

Daarom staat het veld van de andere taal er nog steeds, met zijn opgeslagen
waarde, en het wordt nog steeds meegestuurd — het draagt alleen `hidden`, wat
het uit de weergave, uit de tabvolgorde én uit de toegankelijkheidsboom haalt.
**Geen van de ±77 schrijf-endpoints hoefde te veranderen**, en geen redacteur
kan een vertaling kwijtraken door van taal te wisselen.

Datzelfde verborgen paneel is ook waaróm automatisch vertalen werkt zonder
extra verzoek: de woorden om *uit* te vertalen staan al in hetzelfde
formulier.

`required` staat daarom alleen op de hoofdtaal **én alleen zolang de hoofdtaal
de taal op het scherm is**. Een verplicht en leeg veld binnen een `hidden`
element laat de browser weigeren te versturen zonder te kunnen aanwijzen wat
er mis is. Dat wordt nu op de server beslist bij het renderen, niet achteraf
door JavaScript weggehaald. De servervalidatie is ongewijzigd en blijft de
echte grens.

Een editor schrijft dat attribuut dus **nooit met de hand** in een taalveld;
hij vraagt het aan `admin_lang_required()`.
`Tests\Service\MultilingualBoundaryTest::testNoLocalizedFieldSpellsRequiredByHand`
loopt over elk scherm en valt op een letterlijke `required` naast een
`_nl`/`_en`-naam.

#### Dat verborgen paneel moet ook echt verborgen zijn

Dit is de regel waar Navigatie en Footer een tijd lang op stuk zijn gegaan, en
hij is de moeite van het opschrijven waard omdat hij in CSS woont en niet in
PHP.

`.admin-lang-pane` geeft het paneel een `display`, en een klasse die `display`
zet wint van de eigen `[hidden]{display:none}` van de browser. Zonder een
eigen `[hidden]`-regel stond het paneel van de taal die je *niet* bewerkt dus
gewoon naast dat van de taal die je wél bewerkt — twee tekstvelden onder
labels die met opzet niet meer zeggen welke taal ze zijn. Een redacteur die
net op **EN** had geklikt typte zijn vertaling in het Nederlandse veld, en de
opslag schreef precies wat het formulier meestuurde.

Het viel niet meteen op omdat de blok-editors hun `<form>` in
`.admin-product-form` zetten, en die component herstelt het attribuut voor zijn
eigen subtree. Navigatie, Footer, het portfolio en de personalisatiebouwer doen
dat niet — en dat was exact de lijst schermen waar de fout zichtbaar was.

```css
.admin-lang-pane{ display: block; }
.admin-lang-pane[hidden]{ display: none !important; }   /* hoort bij het paneel */
```

`Tests\Service\MultilingualBoundaryTest::testAHiddenLanguagePaneIsActuallyHidden`
laat de build vallen zodra die tweede regel verdwijnt.

#### Lezen, schrijven en terugvallen: de drie richtingen

Eén tabel, want de fout hierboven is precies wat er gebeurt als iemand ze door
elkaar haalt.

| | Wat er gebeurt |
|---|---|
| **Lezen voor een bezoeker** | gevraagde taal → leeg? dan de hoofdtaal. `LocalizedValue::in()`, via `SiteText` |
| **Lezen voor een redacteur** | de bewerktaal, **ruw**. Leeg is zichtbaar leeg. `LocalizedValue::raw()` |
| **Schrijven vanuit een editor** | alleen de bewerktaal krijgt een nieuwe waarde; de andere kolom gaat ongewijzigd mee terug naar de database |

**Een teruggevallen waarde wordt nooit opgeslagen.** Terugvallen is een
rendering-regel; zou een editorveld hem tonen, dan schreef de eerstvolgende
Opslaan hem weg als échte vertaling en was het verschil tussen "vertaald" en
"nog niet vertaald" weg.

Daaruit volgt ook wat een schrijf-endpoint mag eisen: **verplicht is alleen de
hoofdtaal**. Een vertaling is per definitie optioneel — de site valt terug —
en een endpoint dat beide talen eist maakt een scherm onopslaanbaar zodra de
helft ervan buiten beeld staat. De vraag wordt overal hetzelfde gesteld:

```php
if (LocalizedValue::ofDutchEnglish($nl, $en)->primaryValue() === '') {
    // pas hier is het veld echt leeg
}
```

### Een editor aansluiten

```php
require_once __DIR__ . '/_language_fields.php';   // bovenaan

admin_lang_bar();                                  // één keer per <form>

<?php admin_lang_pane_start('nl'); ?>
  <label>Titel<?= admin_lang_required('nl') ?> … </label>
<?php admin_lang_pane_end(); ?>
<?php admin_lang_pane_start('en'); ?>
  <label>Titel <input … <?= admin_lang_placeholder_attr('en') ?>></label>
<?php admin_lang_pane_end(); ?>

<?php admin_lang_script(); ?>                      // vóór </body>
```

`admin/_language_fields.php` wordt door de schermen zelf ingesloten, niet door
`admin/_header.php`. Het bestand sluit zelf `admin/_translate.php` in, want een
scherm met taalpanelen drukt er ook vertaalde labels omheen.

`Tests\Service\MultilingualBoundaryTest` faalt als een editor met panelen de
component niet insluit, geen bar rendert of het script vergeet — en ook
andersom: als er ergens in `admin/` een `_nl`- of `_en`-veld buiten een paneel
staat.

Twee dingen die `php -l` niet ziet en de suite wel:

- een scherm dat `admin_lang_bar()` aanroept zonder `_language_fields.php`
  in te sluiten. Dat is een fatal op de eerste regel van zijn `<form>`.
- een label dat nog `(NL)` of `(EN)` achter zich draagt. Binnen een paneel
  zegt de indicator al in welke taal je zit.

### Meer dan één formulier op een scherm

Een scherm is niet altijd één formulier. Een portfolio-item heeft een klein
formulier per afbeelding, de footer heeft een "kolom toevoegen"-formulier
naast de lijst, en de blogtags zijn een tabel met een formulier per rij.

Dat is geen probleem meer, en dat is het verschil met de tabbladenversie: elk
paneel op elk formulier op elk scherm staat op dezelfde taal, omdat die taal
niet uit het scherm komt maar uit de beheerder. Er is niets te synchroniseren.

### En in de overzichten

`admin_lang_summary($rij, 'label')` geeft de naam in de hoofdtaal, met
terugval op wat er wél is ingevuld. De lijstschermen printten allebei de talen
naast elkaar — "Contact / Contact" — een naam plus een vertaling die niemand
in een lijst vroeg te zien.

Een overzicht volgt dus de **hoofdtaal van de site**, niet de bewerktaal: het
is een lijst van dingen, niet een bewerkscherm, en een rij die van naam
verspringt als je van bewerktaal wisselt is moeilijker terug te vinden.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Bewerktaal, per persoon | `src/Service/Language/ContentEditingLanguage.php`, kolom `admin_users.content_editing_language` |
| De schakelaar *Content bewerken* | `admin/_header.php`, `api/admin/update-content-language.php` |
| Taalvelden in het CMS | `admin/_language_fields.php`, `admin/assets/admin-language-translate.js`, `.admin-lang-*` en `.admin-sidebar__contentlang*` in `admin/assets/admin.css` |
