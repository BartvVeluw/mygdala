# Migraties en bestaande sites

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Wat de migraties van de
meertaligheid met een database doen, wat een bestaande site daarvan merkt, en
wat er vóór de correctie van V1 fout was. Nodig bij installatie-, upgrade- en
migratiewerk.

## Wat hiervóór fout was

De eerste versie had laag 2 niet. "Welke taal bewerk ik" werd afgeleid uit de
**instellingen van de site** — `enabled_content_languages` — en die ene
instelling hing aan drie ongerelateerde dingen tegelijk:

- of de bezoeker in de header een taalwissel kreeg;
- of een redacteur überhaupt Engelse velden zag;
- of automatisch vertalen werd aangeboden.

Op een site waar die rij `nl` zei — de standaard — betekende dat: geen
taalwissel voor de bezoeker, en geen enkele manier om Engelse inhoud te
schrijven behalve de configuratie van de wébsite omzetten, die je met al je
collega's deelt. Dat is niet wat een redacteur bedoelde met "ik wil de Engelse
versie van deze pagina bewerken".

De correctie is niet een hernoeming: **de bewerktaal is nieuwe, eigen staat**
(een voorkeur van een persoon), en `enabled_content_languages` beslist
nergens meer iets.

## Wat er met een bestaande site gebeurt

**De inhoud van een site wordt niet weggegooid en niet herschreven.** Eén
instellingenrij is de uitzondering, en die draagt geen inhoud.

| | |
|---|---|
| `_nl` / `_en`-kolommen | onaangeraakt, niet hernoemd, niet verwijderd |
| Bestaande NL-waarden | onaangeraakt |
| Bestaande EN-waarden | onaangeraakt |
| Kale Nederlandse kolommen | onaangeraakt |
| `content_translation_state` | onaangeraakt |
| `enabled_content_languages` | rij blijft staan en beslist nergens meer iets; de **waarde** wordt door migratie `20260911200000` bewust overschreven met de volledige set, hoofdtaal eerst |

Die rij is de uitzondering omdat hij sinds de correctie nergens meer over
beslist. Het enige wat hij nog moet doen, is kloppen: de talen noemen die deze
site publiceert. Migratie `20260910140000` kon er `nl` in achterlaten op een
site die wél Engels publiceert (zie hieronder), en die foute waarde is precies
wat `20260911200000` herstelt. Daarbij gaat geen `_nl`/`_en`-kolom en geen
inhoud mee, en verandert er niets wat een bezoeker of redacteur ziet: niets
vertakt op die waarde.

Migratie `20260910140000` zette destijds de talen van bestaande sites vast.
Migratie `20260911100000` voegt één nullable kolom toe,
`admin_users.content_editing_language`, en verder niets. Forward-only en
additief, net als de vorige.

`NULL` betekent "deze persoon heeft niets gekozen", zodat een bestaande
beheerder geen voorkeur krijgt toegewezen die hij nooit heeft uitgesproken —
dezelfde afspraak als bij `interface_language`. Zonder keuze bewerk je de
standaardtaal van de website.

**Wat een bestaande site wél merkt**, en dat is de correctie zelf:

- de publieke `NL | EN`-wissel verschijnt weer, ook als
  `enabled_content_languages` `nl` zei;
- redacteuren kunnen weer Engelse inhoud schrijven, zonder de configuratie van
  de website om te zetten;
- de taaltabbladen in de editors zijn weg, vervangen door de ene schakelaar in
  de schil.

### De tabelnamen van `20260910140000` klopten niet

Die migratie besliste per database of zij `nl` of `nl,en` opsloeg, door een
lijstje tabellen af te tasten op Engelse inhoud. Twee van de negen namen in
dat lijstje bestaan niet in dit schema:

| Wat de migratie zei | Hoe de tabel heet |
|---|---|
| `navigation_items` | `nav_items` |
| `homepage_heroes` | `homepage_hero` |

De lus slaat een tabel over die `hasTable()` niet kent, dus beide missers
waren stil. En juist daar zet de generieke bootstrap zijn Engels neer, dus
een verse installatie sloeg `nl` op en beweerde eentalig te zijn.

Migratie `20260911200000_correct_the_stored_content_languages` herstelt dat.
Zij tast niets af: zij schrijft de volledige set die dit product publiceert,
hoofdtaal eerst — precies wat `ContentLanguages::normalise()` schrijft zodra
een eigenaar zelf iets opslaat. Daarmee kan dezelfde fout niet terugkomen,
want er staat geen tabelnaam meer in.

`20260910140000` blijft staan zoals zij gedraaid heeft. Zij is al toegepast
op echte installaties, dus haar geschiedenis blijft eerlijk en de nieuwe
migratie corrigeert de staat die zij achterliet. Vers of bijgewerkt: beide
eindigen op `nl,en`.

Twee tests houden dit vast.
`Tests\Install\MigrationTableNamesTest` vergelijkt elke tabelnaam die een
migratie uitspreekt met de namen die de migraties aanmaken — een nieuwe
typefout in een `hasTable()` of in een `'tabel' => 'kolom_en'`-lijstje faalt
daar, en de twee bestaande missers staan er met naam en toenaam in.
`Tests\Install\ContentLanguageSettingRepairTest` draait de kapotte migratie
echt, tegen een database vanaf nul, en controleert daarna de uitkomst voor
een verse installatie, voor een bijgewerkte, voor Engels dat alleen in het
menu staat, voor Engels dat alleen in de hero staat, voor een site zonder
Engels en voor een database zonder inhoud.

## Waar het staat

| Migratie | Wat zij doet |
|---|---|
| `db/migrations/20260910140000_add_multilingual_language_settings.php` | zette destijds de talen van bestaande sites vast |
| `db/migrations/20260910150000_create_the_translation_state_table.php` | maakt de tabel `content_translation_state` |
| `db/migrations/20260911100000_add_the_content_editing_language_column.php` | voegt `admin_users.content_editing_language` toe |
| `db/migrations/20260911200000_correct_the_stored_content_languages.php` | zet `enabled_content_languages` recht |
