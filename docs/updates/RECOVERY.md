# Als een update misgaat

Wat de updater doet bij elke soort fout, wat de beheerder dan ziet, en hoe je
een installatie met de hand herstelt als ook het automatisch terugzetten
mislukt. Hoe de updater werkt: [`ARCHITECTURE.md`](ARCHITECTURE.md).

**De regel:** de updater meldt een mislukte update nooit als onschuldig als
de site misschien inconsistent is. Dan blijft de onderhoudsmodus aan, en staat
er `recovery_required`.

## Per soort fout

### Controleren, downloaden of verifiëren mislukt

Geen feed bereikbaar, een ongeldige handtekening, een pakket met de verkeerde
grootte of SHA-256.

- **Wat er veranderd is:** niets. Wat al gedownload was, wordt weggegooid.
  Een verbinding die wegvalt of een request dat de host afbreekt, is nog geen
  fout: de volgende stap downloadt verder vanaf het laatste checkpoint. Een
  bron die niet veilig kan hervatten, begint opnieuw vanaf byte 0; na drie
  keer stopt de update met "De download is 3 keer opnieuw begonnen".
- **Eindstatus:** `failed` (of, bij controleren, alleen een foutmelding).
- **Wat te doen:** de melding lezen. Een ongeldige handtekening of een
  verkeerde hash is een reden om de releasehost te wantrouwen, niet om het
  opnieuw te proberen.

### Uitpakken of de voorcontrole weigert

Een corrupt of onveilig pakket, een met de hand gewijzigd Core-bestand, een
bestand dat PHP niet mag vervangen, een onverwachte migratiestand, te weinig
schijfruimte, of een OPcache-instelling waarbij gewijzigde PHP-bestanden
nooit opnieuw worden ingelezen.

- **Wat er veranderd is:** niets aan de site.
- **Eindstatus:** `failed`; het scherm noemt elke reden, met de bestanden.
- **Wat te doen:** de genoemde oorzaak oplossen (bestand terugzetten, rechten
  aanpassen via FTP, ruimte maken) en opnieuw **Controleren op updates**.

### De back-up mislukt

- **Wat er veranderd is:** alleen de onderhoudsvlag stond even aan. Die gaat
  weer uit, de halve back-up wordt weggegooid.
- **Eindstatus:** `failed`.

### Het bijwerken van de bestanden mislukt

Een bestand kan niet worden geschreven of hernoemd.

- **Wat de updater doet:** eerst de weg terug opslaan (de stap wordt
  `rollback_files`, het journaal gaat weg), dan in hetzelfde request elk
  bestand terugzetten uit de back-up, toegevoegde bestanden en achtergebleven
  tijdelijke kopieën weghalen,
  `release.json` als laatste terug, en controleren dat elk bestand weer de
  oude release is. Dat geldt ook als de apply al een aantal batches verder
  was: het plan zegt welke bestanden er terug moeten, niet het journaal. Er
  is dan nog geen migratie gedraaid; die begint pas na de laatste bewerking.
  Sterft dat request halverwege het terugzetten, dan zet Doorgaan het
  terugzetten voort.
- **Eindstatus:** `rolled_back`; de site draait de oude versie, onderhoud uit.
- Lukt dat terugzetten niet: `recovery_required` (zie onder).

### Een migratie of de controle na de update mislukt

MySQL voert geen schemawijziging in een transactie uit: een migratie die na
haar eerste `ALTER` faalt, laat het schema half gewijzigd achter. Alleen de
oude bestanden terugzetten zou oude code op een onbekend schema zetten.

- **Wat de updater doet:**
  1. `rollback_database`: de dump controleren tegen zijn SHA-256, alles wat de
     mislukte update heeft toegevoegd weggooien (nieuwe tabellen, views), de
     dump terugzetten, en controleren dat precies de oude tabellen er zijn met
     precies het oude aantal rijen;
  2. `rollback_files`: de bestanden terug, zoals hierboven.
- **Eindstatus:** `rolled_back`, onderhoud uit, met de oorspronkelijke fout op
  het scherm (bijvoorbeeld "De databasewijziging 20261001120000 (…) is
  mislukt.").
- Faalde de controle na de update vóór er een migratie liep, dan is alleen
  stap 2 nodig.

### Het terugzetten zelf mislukt: `recovery_required`

Een beschadigde dump, een bestand dat niet terug kan, een onderhoudsvlag die
niet weg wil.

- **Wat de updater doet:** niets meer raden. De onderhoudsvlag **blijft
  staan**: bezoekers zien de onderhoudspagina, niet een half bijgewerkte site.
- **Wat de beheerder ziet:** "Herstel nodig", met de stap waar het misging, de
  melding, de technische details, het update-nummer, de back-upmap en het
  logboek.
- **Wat te doen:** handmatig herstellen (hieronder), en daarna op het
  Updates-scherm **Herstel is afgerond: onderhoudsmodus uitzetten**. Pas dan
  kan er weer een update starten.

## Een onderbroken update

De tab is gesloten, de verbinding viel weg, of een request werd door de host
afgebroken.

- De update staat nog op `running`, bij de stap die aan de beurt was. Het
  scherm zegt "De update is onderbroken bij de stap X" en biedt **Doorgaan**.
- Doorgaan voert die stap opnieuw uit. Elke stap is daarop gebouwd: een
  onderbroken download of back-up gaat verder vanaf de laatst opgeslagen
  positie, een onderbroken restore begint de tabel opnieuw, een onderbroken
  apply gaat verder vanaf zijn cursor en maakt af wat zijn journaal nog niet
  noemt. Het scherm zegt hoe ver de stap was ("De stap was voor 32% klaar").
- Een apply die halverwege stilstaat, laat de site in onderhoud met een deel
  van de nieuwe bestanden. Het Updates-scherm en de login draaien dan nog
  helemaal op de oude code (de codewissel komt als laatste), dus Doorgaan
  werkt ook uren later.
- Zolang er nog niets aan de site veranderd is (tot en met de back-up), kan
  de update ook worden **afgebroken**: de vlag gaat uit, werk en halve
  back-up verdwijnen.
- "Deze stap wordt op dit moment uitgevoerd": een ander venster of request
  heeft de lock. Even wachten en de pagina verversen. Een lock kan niet blijven
  hangen; het besturingssysteem geeft hem vrij zodra het proces stopt.

## Handmatig herstellen

Nodig bij `recovery_required`, of als het CMS zelf niet meer laadt. Je hebt
FTP of het bestandsbeheer van de host nodig, en phpMyAdmin of een andere
MySQL-client.

**In elke back-upmap staat `HERSTEL.txt`**, geschreven door die update zelf,
met de paden, versies en bestandslijsten van precies die update. Begin daar.

De back-upmap is `<updatemap>/backups/<update-nummer>/`, standaard
`<map boven de site>/storage/updates/backups/…`:

```text
database.sql.gz     de volledige database van vlak voor de migraties (database.sql bij meer dan 100 MB)
database.json       tabellen, rijtellingen en de SHA-256 van de dump
files/              de oude versie van elk bestand dat vervangen of verwijderd werd
release.json        de release.json van de oude versie
backup.json         versies, tijdstip, vervangen, verwijderde en toegevoegde bestanden
HERSTEL.txt         deze stappen, ingevuld voor deze update
```

De volgorde:

1. **Laat de onderhoudsvlag staan** (`.maintenance` in de siteroot) tot je
   klaar bent.
2. **Database.** Importeer `database.sql.gz` in de database van de site, met
   phpMyAdmin (tabblad *Importeren*, het `.gz`-bestand mag direct) of
   `gunzip -c database.sql.gz | mysql …`. De dump begint elke tabel met
   `DROP TABLE IF EXISTS` en `CREATE TABLE`. Tabellen die de mislukte update
   **nieuw** had aangemaakt, staan niet in de dump: verwijder ze met de hand
   (welke tabellen er horen, staat in `database.json`).
3. **Bestanden.** Kopieer de inhoud van `files/` over de siteroot (de mappen
   komen overeen). Verwijder elk bestand uit `files_added` in `backup.json`.
   Zet `release.json` uit de back-upmap terug in de siteroot, als laatste.
4. **Controleren.** `VERSION` in de siteroot zegt de oude versie; de site
   laadt als je de vlag even wegzet en weer terug.
5. **De vlag weg.** Log in, open **Instellingen → Updates** en klik op
   **Herstel is afgerond: onderhoudsmodus uitzetten**. Lukt inloggen niet,
   verwijder dan `.maintenance` uit de siteroot met FTP.

`.env`, uploads (`assets/media/` en de andere uploadmappen) en de privé-opslag
hoef je nooit terug te zetten: een update raakt ze niet.

## Het logboek lezen

`<updatemap>/logs/<update-nummer>.log`, één JSON-object per regel:

```json
{"at":"2026-10-01T12:00:03Z","step":"migrate","event":"failed","message":"update.error.migration_failed","params":{"version":"20261001120000","name":"…"},"detail":"…Phinx-uitvoer…"}
```

`message` is een sleutel uit de CMS-catalogus
(`src/Service/Language/messages/nl.php`), `detail` de technische reden in het
Engels, met de uitvoer van Phinx bij migraties. Er staan geen wachtwoorden,
tokens of querystrings in. Het Updates-scherm toont het logboek van de
laatste update onder *Eerdere updates*.

## Veelgestelde situaties

- **"De map voor updates hoort bij een andere installatie".** Twee sites delen
  dezelfde `MYGDALA_UPDATE_STORAGE_PATH`. Geef elke site een eigen map.
- **"Er is geen updatebron ingesteld".** `MYGDALA_UPDATE_MANIFEST_URL` of de
  releasesleutel ontbreekt (`ARCHITECTURE.md`, "Configuratie").
- **"Deze installatie is een ontwikkelversie (Git)".** Werk hem bij met
  `git pull`, `composer install` en `phinx migrate` (`SETUP.md`), of zet hem
  één keer over naar het releasemodel (`RELEASES.md`). Alleen `.git`
  weghalen is die overstap niet.
- **De site toont de onderhoudspagina, maar er loopt geen update.** Kijk
  eerst op het Updates-scherm; staat daar niets, verwijder dan `.maintenance`
  uit de siteroot.
