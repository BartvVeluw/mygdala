# De bewerktaal

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Laag 2, welke taalversie
van de website-inhoud een beheerder bewerkt: de schakelaar in de schil, de
velden per taal in elke editor en wat een schrijf-endpoint met een vertaling
mag. Wat een bezoeker bij een lege vertaling ziet, staat in
[`WEBSITE-LANGUAGES.md`](WEBSITE-LANGUAGES.md); de opslag per domein in
[`ARCHITECTURE.md`](ARCHITECTURE.md).

## De taalwissel in het CMS

Eén schakelaar, in de schil, op élk adminscherm:

```text
CONTENT BEWERKEN
[ NL ] [ EN ] [ DE ]
```

Hij staat in `admin/_header.php`, direct onder de sitenaam, en hij POST't naar
`api/admin/update-content-language.php` met een CSRF-token zoals elke andere
schrijfactie hier. De keuze is per beheerder
(`admin_users.content_editing_language`, `ContentEditingLanguage`).

Hij toont de **gepubliceerde** talen van het talenregister
(`ContentEditingLanguage::choices()`, uit `SiteLanguages::active()`), de
standaardtaal eerst en gemarkeerd met een stip en *standaardtaal* in de naam
van de knop. Een derde taal is één rij in `site_languages`. Staat de module
Meertaligheid uit, of publiceert de site maar één taal, dan is er niets te
kiezen en verschijnt de schakelaar niet: elke editor staat dan op de
standaardtaal. De vertalingen van een uitgezette taal blijven bewaard.

Na een wissel keert hij terug naar het scherm waar je was: welke taal een
formulier toont wordt op de **server** beslist, dus de pagina wordt opnieuw
gerenderd. Staan er onopgeslagen wijzigingen in een formulier, dan vraagt de
opslagbalk eerst of je die wilt laten staan (`admin/assets/save-bar.js`).

**Geen tweede taalkiezer.** Bewerkschermen tónen alleen in welke taal je zit:

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.
```

Er stond vroeger een tabbladenrij per formulier, en dat was precies de fout:
de taalkeuze zat per scherm en was vanuit de rest van het CMS onzichtbaar.
Twee knoppen die het over dezelfde staat oneens kunnen zijn is erger dan één.

## Bewerken in één taal tegelijk

Een bewerkscherm toont de velden van **één** taal: die van de schakelaar in de
schil, als de website die taal publiceert, anders de standaardtaal
(`admin_localized_language()`). Nooit twee kolommen, nooit een
`(NL)`-achtervoegsel.

```text
Je bewerkt: English
Leeg betekent nog niet vertaald. Bezoekers zien dan de tekst in het Nederlands.

Titel        [ ............... ]
Introtekst   [ ............... ]
Knoptekst    [ ............... ]
```

Taalneutrale velden (adres, status, schakelaars, afbeeldingen) staan in elke
taal op het scherm.

### Niets verborgens wordt meegestuurd

Het formulier draagt alleen de velden van de taal op het scherm, plus één
verborgen `language_code` (`admin_localized_input()`). Het endpoint controleert
die code tegen het register en schrijft precies die taal. De woorden van elke
andere taal blijven in de opslag staan, dus een taalwissel kan nooit een
vertaling overschrijven met een verouderde kopie, en een site met vijf talen
stuurt de velden van één taal, niet van vijf.

Tot Multilingual 2.0 fase 7 bestond hiernaast het V1-model: een verborgen
Nederlands én Engels paneel per veld (`admin/_language_fields.php`), dat geen
derde taal kon houden. Fase 7 heeft het verwijderd;
`MultilingualBoundaryTest` houdt vast dat het niet terugkomt.

### Verplicht is alleen de standaardtaal

`required` staat alleen op een veld van de standaardtaal
(`admin_localized_required()`). Een vertaling is per definitie optioneel,
want elk veld valt terug op de standaardtaal, en een endpoint dat een
vertaling eist maakt een scherm onopslaanbaar. De servervalidatie is de echte
grens: `BlockLocalization::problems()` en de `problems()` van elke
`*Localization`-klasse melden `missing` alleen voor de standaardtaal.

Een nieuw kindrij-item (een vraag, een kaart, een stap) wordt in de
standaardtaal geschreven, zoals een nieuwe pagina, en daarna op het item zelf
vertaald; `admin_localized_new_item_note()` zegt dat op een scherm in een
andere taal.

### Lezen, schrijven en terugvallen: de drie richtingen

| | Wat er gebeurt |
|---|---|
| **Lezen voor een bezoeker** | gevraagde taal → leeg? dan de standaardtaal. `value()` van de `*Localization`-klasse, of `LanguageFallback::resolve()` |
| **Lezen voor een redacteur** | de bewerktaal, **ruw**. Leeg is zichtbaar leeg. `raw()` |
| **Schrijven vanuit een editor** | alleen de taal van het formulier krijgt een nieuwe waarde; de andere talen worden niet aangeraakt |

**Een teruggevallen waarde wordt nooit opgeslagen.** Terugvallen is een
rendering-regel; zou een editorveld hem tonen, dan schreef de eerstvolgende
Opslaan hem weg als échte vertaling en was het verschil tussen "vertaald" en
"nog niet vertaald" weg. Een leeg vertaalveld zegt daarom in zijn placeholder
dat een bezoeker de standaardtaal krijgt zolang het leeg is
(`admin_localized_placeholder_attr()`); de woorden van de standaardtaal worden
nooit als waarde voorgevuld.

### Een editor aansluiten

```php
require_once __DIR__ . '/_localized_fields.php';   // bovenaan

$language = admin_localized_language();

<form …>
  <?= admin_localized_input($language) ?>
  <?php admin_localized_bar($language); ?>
  <label>Titel<?= admin_localized_required($language) ?>
    <input name="title" … <?= admin_localized_placeholder_attr($language) ?>>
  </label>
</form>
```

Het endpoint leest `language_code`, normaliseert hem met `LanguageCode`,
weigert een taal die de website niet publiceert en geeft de velden aan de
`save()` van de klasse van het domein (`PageLocalization`,
`BlockLocalization`, `EntityTranslations` via de domeinklasse). Contract,
terugval en de lijst functies staan in [`ARCHITECTURE.md`](ARCHITECTURE.md),
*De editorcomponent*.

### Meer dan één formulier op een scherm

Een portfolio-item heeft een klein formulier per afbeelding, de footer een
"kolom toevoegen"-formulier naast de lijst, de blogtags een formulier per rij.
Elk formulier draagt zijn eigen `admin_localized_input()` en staat op dezelfde
taal, omdat die taal niet uit het scherm komt maar uit de beheerder. Er is
niets te synchroniseren.

### En in de overzichten

Een overzicht noemt een rij in de **standaardtaal**, met terugval op de eerste
taal die woorden heeft (de `name()` van de `*Localization`-klasse, of
`LanguageFallback::name()`). Het is een lijst van dingen, geen bewerkscherm,
en een rij die van naam verspringt als je van bewerktaal wisselt is moeilijker
terug te vinden.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Bewerktaal, per persoon | `src/Service/Language/ContentEditingLanguage.php`, kolom `admin_users.content_editing_language` |
| De schakelaar *Content bewerken* | `admin/_header.php`, `api/admin/update-content-language.php`, `.admin-sidebar__contentlang*` in `admin/assets/admin.css` |
| Velden per websitetaal | `admin/_localized_fields.php`, in elke editor met tekst per taal; zie [`ARCHITECTURE.md`](ARCHITECTURE.md) |
| Bewakers | `MultilingualBoundaryTest` (geen V1-panelen, `required` alleen via de component), `ThreeLanguageStatesTest` |
