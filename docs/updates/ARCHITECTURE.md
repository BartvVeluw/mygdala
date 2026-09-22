# De ingebouwde updater

Een release-installatie van Mygdala werkt zichzelf vanuit het CMS bij naar een
gepubliceerde, ondertekende Mygdala-release. Geen Git, geen SSH, geen
handmatige upload, geen cron. De beheerder start de update zelf, onder
**Instellingen → Updates** (`admin/updates.php`).

Drie documenten:

| Document | Waarover |
|---|---|
| dit document | hoe de updater werkt en waarom zo |
| [`RELEASES.md`](RELEASES.md) | versiebeleid, de releasesleutel, een release maken en publiceren |
| [`RECOVERY.md`](RECOVERY.md) | wat er gebeurt als een update misgaat, en hoe je herstelt |

De code staat in `src/Update/` (`CLAUDE.md` daar is de korte versie). Wijkt de
code af van dit document, dan heeft de code gelijk.

## In één oogopslag

```text
Controleren op updates     manifest.json + .sig ophalen, handtekening checken, vereisten tonen
Update installeren         start een update naar precies die release
  download                 pakket naar work/<id>/package.zip, nooit meer bytes dan het manifest noemt
  verify                   grootte en SHA-256 tegen het ondertekende manifest
  extract                  entry voor entry naar staging, ZIP-slip en symlinks geweigerd
  preflight                lokale wijzigingen, conflicten, schrijfrechten, migratiestatus, schijfruimte
  maintenance              .maintenance aan: bezoekers zien een onderhoudspagina
  backup_database          volledige SQL-dump vanuit PHP, rijtellingen geverifieerd
  backup_files             alleen de bestanden die vervangen of verwijderd worden
  apply                    bestand voor bestand: tijdelijk bestand + rename, release.json als laatste
  migrate                  de eigen Phinx-migraties, via de library, één voor één
  health                   versie, elk bestand, migratieniveau, kernservices, HTTP
  finish                   onderhoud uit, werkmap weg, oude back-ups opgeruimd
bij een fout na apply      rollback_database → rollback_files
```

Elke stap is één kort request. De server bewaart waar de update is; het
scherm vraagt alleen om "de volgende stap".

## Het eigendomscontract

`App\Update\Ownership` beslist voor elk relatief pad of het van de **release**,
van de **installatie** of alleen van de **repository** is. De releasebouwer en
de updater vragen allebei deze ene klasse; er is nergens anders een
uitzondering per bestand.

| Onderdeel | Door de updater beheerd? | Overschrijven? | Behouden? | Back-up nodig? |
|---|---|---|---|---|
| root-`*.php`, `.htaccess`, `phinx.php`, `VERSION`, `composer.json`/`.lock`, `.env.example` | ja, release | ja | nee | ja, als hij vervangen of verwijderd wordt |
| `admin/`, `api/`, `partials/`, `src/` | ja | ja | nee | idem |
| `assets/css/`, `assets/js/`, `assets/images/block-preview/`, `assets/fonts/personalization/.htaccess` | ja | ja | nee | idem |
| `db/migrations/`, `db/seeds/` | ja | ja | nee | idem |
| `vendor/` (no-dev, meegeleverd: een gedeelde host heeft geen Composer) | ja | ja | nee | idem |
| `scripts/` (productietools; niet `test-db.php`, `create_fresh_site_copy.php`, `release.php`) | ja | ja | nee | idem |
| `release.json` | ja, als allerlaatste | ja | nee | ja |
| `*.md`, `docs/`, `tests/`, `docker/`, `docker-compose.yml`, `phpunit.xml`, `.claude/`, editor- en tijdelijke bestanden | nee, zit niet in een release | nooit | ja, blijft staan als het er is | nee |
| `.env`, `.env.*` | nee, installatie | **nooit** | ja | nee |
| `assets/media/`, `assets/images/` (behalve `block-preview/`), `assets/videos/`, `assets/fonts/personalization/*` | nee, uploads | **nooit** | ja | nee |
| `storage/` en de privé-opslag naast de site (bijlagen, facturen, personalisatie) | nee | **nooit** | ja | nee |
| `.maintenance`, `.user.ini`, `php.ini`, `error_log` van de host | nee | **nooit** (alleen de updater zet en haalt zijn eigen vlag) | ja | nee |
| de updatemap (`MYGDALA_UPDATE_STORAGE_PATH`) | werkmap van de updater, zelf installatie-eigendom | n.v.t. | ja | n.v.t. |
| de database | inhoud nee; schema alleen via de eigen migraties | alleen via Phinx | ja | **ja, volledig, vóór de migraties** |

Drie regels die hieruit volgen:

- **Een pakket dat een installatiepad noemt, wordt als geheel geweigerd**
  (`PackageValidator`, `ReleaseDescriptor`). Er wordt niets weggefilterd.
- **Verwijderen gebeurt alleen op basis van twee lijsten:** elk pad dat de
  geïnstalleerde `release.json` noemt en de nieuwe niet meer. Een bestand dat
  geen release ooit heeft genoemd — een uploadje, een `error_log`, een oud
  bestand uit de tijd vóór releases — blijft altijd staan.
- **Een nieuwe uploadmap is één regel in `Ownership`.** `OwnershipTest` houdt
  het contract tegen elke uploader, `.gitignore` en het
  fresh-site-copybeleid, zodat een vergeten map een falende test is en geen
  update die foto's weggooit.

## Versies

- **Eén canonieke versie**: `App\Update\AppVersion::current()`, uit het
  bestand `VERSION` in de root. Nooit uit Git: een live server heeft geen
  `.git`.
- **SemVer, strikt `MAJOR.MINOR.PATCH`** (`SemVer`). Geen pre-release, geen
  buildmetadata: V1 kent geen kanalen, en een versie die maar één ding kan
  betekenen is er een waar twee installaties het nooit over oneens zijn.
- **Build-id en releasedatum** staan in `release.json` en zijn informatief;
  een ontwikkeluitchecking heeft ze niet.
- **Releaseversie en migratieversie zijn onafhankelijk.** Een release hoeft
  geen migratie te hebben; de healthcheck vergelijkt elk met zijn eigen
  verwachting.
- De eerste versie is **0.1.0**; [`RELEASES.md`](RELEASES.md) legt uit waarom.

## Het manifest en `release.json`

Twee documenten beschrijven een release; beide staan in `RELEASES.md`
uitgeschreven.

| | `manifest.json` (de feed) | `release.json` (in het pakket) |
|---|---|---|
| wie leest het | de updater, vóór de download | de updater na het uitpakken, en daarna de installatie zelf |
| wat erin staat | versie, datum, pakket-URL, SHA-256, grootte, vereisten, minimale bronversie, updaterprotocol, migraties, notities | versie, build-id, datum, vereisten, migraties, protocol, en **elk bestand met zijn SHA-256** |
| beveiligd door | een Ed25519-handtekening over de exacte bytes (`manifest.json.sig`) | de SHA-256 van het pakket, die in het ondertekende manifest staat |
| versie van het formaat | `manifest_version: 1` | `format: 1` |

`release.json` is ook het **commitpunt**: het wordt als allerlaatste bestand
vervangen. Welke `release.json` in de root staat, is de release die
geïnstalleerd is. Een Git-uitchecking heeft er geen, en is daarom geen
release-installatie: het Updates-scherm zegt dat, in plaats van te raden welke
bestanden Core zijn.

## Beveiliging

**Authenticiteit.** Een release is pas te vertrouwen als hij van de makers van
Mygdala komt, niet alleen van de host in de URL. Daarom:

- de feed is een `manifest.json` met een losse Ed25519-handtekening
  (`ReleaseSignature`, via `ext-sodium`, sinds PHP 7.2 standaard aanwezig);
- de handtekening wordt gecontroleerd over de **exacte bytes**, vóór er één
  veld uit gelezen wordt — geen canonieke JSON, dus geen tweede lezing om op
  te mikken;
- de installatie kent alleen de **publieke** sleutel (`ReleaseKeys`); de
  geheime sleutel komt nooit op een server en nooit in deze repository;
- het pakket is transitief beveiligd: zijn SHA-256 staat in de ondertekende
  bytes, en wordt gecontroleerd vóór er iets wordt uitgepakt;
- de versie in het pakket (`release.json`, `VERSION`) moet de versie uit het
  manifest zijn, en de vereisten en migraties moeten kloppen
  (`PackageValidator`).

Een ongetekend of verkeerd getekend manifest wordt nooit geïnstalleerd. Een
teruggedraaid (ouder) manifest ook niet: de doelversie moet nieuwer zijn.

**Transport.** Alleen HTTPS, met geverifieerd TLS (`HttpFetcher`; de CA-bundel
van Composer als die er is). HTTP is alleen toegestaan op een installatie die
expliciet zegt geen productie te zijn (`APP_ENV`, `AppEnvironment`); een
ontbrekende of verkeerd getypte `APP_ENV` is productie. Redirects worden met
de hand gevolgd, maximaal vijf, en elke stap moet weer HTTPS zijn. Elke
download heeft een bytelimiet die tijdens het lezen wordt afgedwongen.

**Geen SSRF.** De manifest-URL komt uit de omgeving (`UpdateConfig`), de
pakket-URL alleen uit het geverifieerde manifest. Geen enkel request levert
een URL, een pad of een versie aan.

**Toegang.** Nieuwe permissie `updates.manage` ("Updates installeren"). Een
Super Admin heeft hem vanzelf; alleen een Super Admin kan hem aan een ander
geven (`AdminPermissions::SUPER_ADMIN_GRANTABLE_ONLY`, net als
`users.manage`). Elk schrijfendpoint doet de vier vaste guards: login,
permissie, POST, CSRF.

**Elke stap** moet bovendien het update-id en de verwachte stap noemen, en
beide moeten met de opgeslagen state kloppen (anders 409). Een tweede
tegelijkertijd lopende stap krijgt 423.

**Pakketinhoud.** `RelativePath` is een whitelist (`[A-Za-z0-9._@+~-]` per
segment, geen lege of `..`-segmenten, geen segment dat op een punt eindigt),
dus `../`, `\`, `C:`, een NUL-byte of een NTFS-stream kunnen niet eens
gespeld worden. Symlinks en andere niet-gewone entries worden geweigerd, net
als twee entries die op een hoofdletterongevoelig bestandssysteem één bestand
zijn. Aantal entries en uitgepakte grootte zijn begrensd (zip-bom).

**Logs** bevatten geen geheimen: de manifest-URL wordt teruggebracht tot host
en pad (`UpdateConfig::describeUrl`), het health-token staat alleen in de
state.

**Geen telemetrie.** De User-Agent noemt product en versie en verder niets;
de updateserver leert niet welke sites er bestaan.

## Configuratie

Op distributieniveau, in de omgeving, nooit als veld in het CMS:

| Variabele | Betekenis | Standaard |
|---|---|---|
| `MYGDALA_UPDATE_MANIFEST_URL` | de feed | leeg tot de releasehosting gekozen is (`UpdateConfig::DEFAULT_MANIFEST_URL`) |
| `MYGDALA_UPDATE_PUBLIC_KEY` | de Ed25519-publieke sleutel, base64; **vervangt** de ingebouwde sleutel | leeg; ingebouwd: `ReleaseKeys::BUILT_IN`, leeg tot de releasesleutel bestaat |
| `MYGDALA_UPDATE_STORAGE_PATH` | de werkmap van de updater | `<map boven de site>/storage/updates` |

Zolang feed en sleutel leeg zijn, zegt het Updates-scherm dat er geen
updatebron is ingesteld; er wordt dan niets gecontroleerd en niets
geïnstalleerd.

## De werkmap

Buiten de webroot, naast de andere privé-opslag van de installatie
(`ContactAttachmentStorage` gebruikt dezelfde plek): er komt een volledige
databasedump in, met elk klantadres.

```text
<storage>/state.json            de lopende update (UpdateState), atomisch geschreven
<storage>/update.lock           flock, vastgehouden zolang een stap loopt
<storage>/last-check.json       het laatst gecontroleerde, geverifieerde manifest + vereisten
<storage>/history.json          de laatste twintig afgeronde updates
<storage>/logs/<id>.log         JSON-regels per update
<storage>/work/<id>/            package.zip, staging/, plan.json, apply.journal
<storage>/backups/<id>/         database.sql.gz, database.json, files/, release.json, backup.json
```

De updater schrijft er een `.htaccess` met `Require all denied` in, voor het
geval iemand de map toch in de webroot zet. De state onthoudt bij welke
installatie hij hoort; een map die per ongeluk door twee installaties gedeeld
wordt, wordt geweigerd.

## Hervatbaar: één stap per request

Een gedeelde host breekt een request na 30 tot 60 seconden af. Daarom:

- **`api/admin/updates-step.php` voert precies één stap uit** en slaat de state
  op. `admin/assets/updates.js` vraagt de volgende, tot de update niet meer
  loopt. Zonder JavaScript doet de knop Doorgaan hetzelfde met één klik per
  stap.
- **Lange stappen hebben een tijdsbudget** (een derde van
  `max_execution_time`, hoogstens 15 seconden) en een cursor: uitpakken,
  database-back-up, bestandsback-up, migraties en de database-restore gaan
  over meerdere requests.
- **Elke stap is herhaalbaar.** Een stap die zijn eigen naam al in `in_step`
  vindt, weet dat de vorige poging halverwege stierf, en herstelt zijn cursor:
  de back-up kapt zijn deelbestand af tot de laatst opgeslagen lengte, de
  restore begint de tabel opnieuw bij zijn `DROP TABLE`, apply slaat over wat
  het journaal al noemt.
- **De server beslist.** Het scherm draait nooit een stap bij het laden. Kom
  je terug na een gesloten tab, dan zegt het "De update is onderbroken bij de
  stap X" met een knop Doorgaan.
- **`status` en `step` zijn twee velden.** `status` is de levensloop (`idle`,
  `running`, `completed`, `failed`, `rolled_back`, `recovery_required`), `step`
  is waar hij is of eindigde. De woorden uit de opdracht vallen daarop terug:
  *downloading* is stap `download`, *verified* is na `verify`, *backed_up* na
  `backup_files`, *applying* `apply`, *migrating* `migrate`, *verifying*
  `health`.
- **Eén update tegelijk**: een niet-blokkerende `flock` per stap. Een lock kan
  niet blijven hangen (het besturingssysteem geeft hem vrij als het proces
  sterft); wat wél kan blijven hangen is een `running`-state met een oude
  heartbeat, en die toont het scherm als onderbroken.

### Het formaat overleeft de code

Elke stap na `apply` draait op de code van de **nieuwe** release, die een
state leest die de oude release schreef. `UpdateState::FORMAT` is dat
contract, en het manifest noemt welk formaat de updater van de release kan
afmaken (`updater_protocol`). Preflight weigert een release die de update die
deze installatie zou starten niet kan afronden. Velden toevoegen mag;
hernoemen of weghalen is een nieuw formaat. Een release moet bovendien de
bestanden bevatten waarmee hij zijn eigen update afmaakt
(`PackageValidator::REQUIRED_PATHS`).

## Preflight

Twee rondes (`Preflight`). Een **fout** stopt de update met de redenen; een
**waarschuwing** wordt getoond en gelogd. Een fout is iets dat de update zeker
laat mislukken of data kost; een waarschuwing is iets wat de updater op deze
host niet kan meten.

| Ronde | Controle |
|---|---|
| vóór de download (ook bij "Controleren") | release-installatie (geen Git, geldige `release.json`), `VERSION` gelijk aan `release.json`, doelversie nieuwer, minimale bronversie, updaterprotocol, PHP-versie, MySQL- of MariaDB-versie, extensies (van de release én van de updater zelf: zip, sodium, json, zlib, mbstring, pdo_mysql), de werkmap, schijfruimte voor de download |
| na het uitpakken | geen met de hand gewijzigde of ontbrekende Core-bestanden (`LocalChanges`), geen nieuw bestand dat op een vreemd bestand zou landen, elk te vervangen of te verwijderen bestand én zijn map schrijfbaar, migratielog precies op het niveau van de geïnstalleerde release (niets open, niets onbekends), geen triggers/routines/events (die kan de back-up niet meenemen), geen onverwachte `.maintenance`, schijfruimte voor back-up en apply |

Een lokaal gewijzigd Core-bestand **blokkeert** in V1: het scherm noemt de
bestanden. Terugzetten, of de wijziging in Mygdala zelf laten opnemen.

## Het updateplan

`UpdatePlanner` berekent vóór er iets geschreven wordt:

- **add**: nieuw in deze release, nog niet op schijf;
- **replace**: Core-bestand waarvan de inhoud verandert;
- **delete**: Core-bestand van de geïnstalleerde release dat de nieuwe niet
  meer levert — zo blijven verouderde PHP-bestanden niet eeuwig staan;
- **preserve**: wat bewust blijft staan (installatiemappen en -bestanden,
  alles wat geen release ooit noemde), als samenvatting;
- **conflicts**: een pad dat de release wil toevoegen terwijl er al een
  vreemd bestand staat. Dat blokkeert.

Ongewijzigde bestanden worden geteld, niet herschreven. Mappen die door de
verwijderingen leeg raken worden opgeruimd, maar alleen als ze echt leeg
zijn.

## Onderhoudsmodus

`.maintenance` in de siteroot (`MaintenanceMode`), dezelfde naam als bij
WordPress: wie alleen FTP heeft, weet wat hij moet verwijderen.

**De guard** (`MaintenanceGuard`) hangt aan het enige dat alle entrypoints van
dit project delen: Composers autoload-`files`-lijst (`composer.json`), die
`src/Update/maintenance-guard.php` bij elke `require vendor/autoload.php`
uitvoert — vóór `.env`, vóór de database, vóór een sessie. Zonder vlag kost
dat één `is_file()`. Met vlag:

- de publieke site krijgt een kleine, zelfstandige 503-pagina (NL en EN,
  `Retry-After`, geen database, geen thema);
- een adminscherm wordt naar het Updates-scherm gestuurd;
- een admin-API krijgt een kale 503;
- door mogen alleen: het Updates-scherm, `updates-step`, `updates-abort`,
  `updates-resolve`, login, logout, de command line, en een request met het
  health-token van de lopende update.

Of een script vrijgesteld is, bepaalt het **uitgevoerde bestand**
(`SCRIPT_FILENAME`, realpath), niet de URL.

**De volgorde wijkt bewust af van "back-up → onderhoud"**: onderhoud gaat aan
vóór de database-back-up. Een dump die gemaakt is terwijl bezoekers nog
bestelden, zou bij een restore die bestellingen weggooien.

**Een crash maakt de vlag niet onherstelbaar.** Het Updates-scherm en de
login blijven bereikbaar en zeggen waarom de vlag er staat; na een mislukte
rollback haal je hem daar weg (knop "Herstel is afgerond"), of met FTP
(`RECOVERY.md`).

Na `composer install` of `composer dump-autoload` is de guard actief. Een
release bevat altijd een `vendor/` waarin hij zit (de bouwer weigert anders);
een bestaande ontwikkeluitchecking heeft na deze wijziging één keer
`composer dump-autoload` nodig.

## Bestanden toepassen, en wat niet atomair kan

`FileApplier` schrijft elk bestand eerst **naast** zijn doel
(`<naam>.mygdala-<id>.tmp`, in dezelfde map) en hernoemt het dan erover. Een
rename binnen één map is atomair; een bezoeker of het volgende request ziet
het oude of het nieuwe bestand, nooit een half. De staging kan op een ander
bestandssysteem staan (in Docker is dat zo), daarom gebeurt de kopie naast
het doel.

**Een hele release in één keer wisselen kan op gedeelde hosting niet**: er is
geen symlink-`current` om om te zetten, de siteroot is de documentroot. Een
paar seconden lang zijn sommige bestanden dus nieuw en andere oud. Drie dingen
maken dat onschadelijk:

1. de site staat in onderhoud, dus geen bezoekersrequest draait code in dat
   venster;
2. de hele apply is één request, met elke klasse die dat request nodig heeft
   vooraf geladen, en de bestanden waar dit request zelf uit bestaat
   (`get_included_files()`, de updater, `vendor/composer/`) als laatste;
3. elke operatie wordt gejournaliseerd, de oude inhoud staat in de back-up,
   en `release.json` gaat als allerlaatste. Een onderbroken apply wordt bij
   Doorgaan afgemaakt; een mislukte wordt in hetzelfde request teruggedraaid.

Elk geschreven bestand wordt uit OPcache gehaald (`opcache_invalidate`); waar
dat niet mag, wacht het scherm na `apply` even, zodat de tijdstempelcontrole
van OPcache de nieuwe bestanden ziet.

## Migraties

Alleen de eigen Phinx-migraties, via de library (`MigrationRunner`): dezelfde
`phinx.php`, dezelfde `phinx_migration_log`, dezelfde migratieklassen, via
Phinx' `Manager`. Geen tweede migratiesysteem en geen `exec()`.

- **Eén voor één, binnen het tijdsbudget.** Een migratie wordt nooit
  gesplitst; Phinx noteert hem pas als hij klaar is.
- **Alle uitvoer** van Phinx gaat het updatelog in.
- **Fout** = exception → de update gaat naar `rollback_database`.
- **Succes** pas als er niets meer open staat, niets onbekends in de log
  staat, en de nieuwste migratie de nieuwste uit het manifest is (healthcheck).

## Back-up

**Database** (`DatabaseBackup`): een volledige SQL-dump, geschreven door PHP
zelf, want `mysqldump` via `exec()` bestaat op gedeelde hosting meestal niet.
Standaard-SQL, één INSERT per regel, utf8mb4, UTC, gzip als één stream: een
supportmedewerker importeert hem met phpMyAdmin. `DROP` + `CREATE TABLE` uit
`SHOW CREATE TABLE` (sleutels, foreign keys en `AUTO_INCREMENT` komen exact
terug), elke rij (binair als hex, BIT als getal, gegenereerde kolommen
overgeslagen), en views. Hervatbaar, per primaire sleutel gepagineerd. Aan
het eind wordt elke tabel geteld tegen de dump; klopt het niet, dan is de
back-up mislukt. Triggers, routines en events worden niet gedumpt; een
database die ze heeft, wordt door preflight geweigerd.

**Bestanden** (`FileBackup`): alleen de Core-bestanden die vervangen of
verwijderd gaan worden, elk gecontroleerd tegen de geïnstalleerde
`release.json`, plus die `release.json` zelf. Uploads en `.env` worden niet
gedupliceerd, want een update raakt ze niet.

**Metadata** (`backup.json`): bron- en doelversie, tijdstip, vervangen,
verwijderde en toegevoegde bestanden, pakket-SHA-256, en de verwijzing naar
de databasedump (met zijn eigen SHA-256 in `database.json`).

De back-ups van de laatste drie updates blijven bewaard.

## Healthcheck

Na de migraties, en pas daarna gaat de onderhoudsmodus uit (`HealthCheck`):

- `VERSION` en `release.json` noemen de doelversie;
- elk bestand uit de nieuwe `release.json` staat er met de juiste hash;
- de database is bereikbaar en op het verwachte migratieniveau;
- de nieuwe code start: deze stap draait zelf al in een vers request op de
  nieuwe code, en vraagt daarbovenop het moduleregister en de
  site-instellingen op;
- **HTTP**: de homepage en de loginpagina, opgevraagd met het health-token,
  antwoorden niet slechter dan vóór de update (de basislijn uit preflight).
  Kan de server zijn eigen site niet bereiken — op gedeelde hosting en in
  Docker gewoon — dan wordt dit met een waarschuwing overgeslagen.

Faalt er iets, dan gaat de update terug (database indien gewijzigd, daarna
bestanden).

## Faalmodel

Kort; [`RECOVERY.md`](RECOVERY.md) is de volledige versie.

| Waar het misgaat | Wat er gebeurt | Eindstatus | Site |
|---|---|---|---|
| controleren, download, verify, extract, preflight | niets live veranderd; werk weggegooid | `failed` | open |
| onderhoud of back-up | vlag weer uit, halve back-up weg | `failed` | open |
| apply | in hetzelfde request alle bestanden terug uit de back-up | `rolled_back` | open |
| migrate of health | database terug uit de dump, dan de bestanden | `rolled_back` | open |
| tijdens een rollback, of bij finish | niets wordt verder geraden | `recovery_required` | **blijft in onderhoud** |

Er is geen groen "update mislukt" als de site misschien inconsistent is:
dat is precies `recovery_required`, en dan blijft de vlag staan.

## Het Updates-scherm

`admin/updates.php`, in het menu bij de instellingen, met de permissie
`updates.manage`.

- **Mygdala-versie**: huidige versie, build, releasedatum, en wat voor
  installatie dit is (release, Git, geen `release.json`).
- **Laatste release**: versie, datum, grootte, vereisten, release notes,
  status *Up-to-date* of *Update beschikbaar*, de uitkomst van de vereisten,
  en de knoppen **Controleren op updates** en **Update installeren** (met
  bevestiging). Geen feed of sleutel ingesteld: dat staat er.
- **Tijdens een update**: de stappen met hun stand, de laatste melding,
  Doorgaan en (zolang er nog niets veranderd is) Afbreken.
- **Na een update**: hoe hij afliep, met de reden, en bij `recovery_required`
  het update-nummer, de back-upmap, het logboek en de knop om na handmatig
  herstel de onderhoudsmodus uit te zetten.
- **Eerdere updates** en het logboek van de laatste, in de CMS-taal van de
  beheerder (het log slaat catalogussleutels op, geen zinnen).

Tijdens het onderhoudsvenster rendert het scherm een kale kop in plaats van de
normale schil: die leest instellingen, modules en talen uit een database die
misschien halverwege een migratie is.

## Bewijs

De suite `updater` (`tests/Update/`, `TESTING.md`):

| Test | Wat hij bewijst |
|---|---|
| `VersionAndPathTest`, `OwnershipTest`, `ReleaseManifestTest` | versie, paden, eigendom, manifest, handtekening, configuratie |
| `FeedAndPackageTest` | feed over echte HTTP: handtekening, redirects, bytelimieten, hash en grootte |
| `PreflightEnvironmentTest` | vereisten tegen de echte databaseserver (scenario E op unitniveau) |
| `PackageExtractionTest` | ZIP-slip, symlinks, dubbele namen, corrupte archieven, pakketvalidatie |
| `UpdatePlannerTest` | het plan en lokale wijzigingen (scenario H op unitniveau) |
| `DatabaseBackupTest` | dump en restore tot op de byte, met lastige data, en wat een mislukte migratie achterlaat |
| `ApplyAndMaintenanceTest` | apply, hervatting, rollback, de onderhoudsguard |
| `ReleaseBuilderTest` | wat er in een release zit, deterministisch, en dat de installatie het accepteert |
| `UpgradeEndToEndTest` | A (0.1.0 → 0.2.0), B (0.1.0 → 0.3.0 over twee migratiegeneraties), H, I, de guards, en een verse installatie (lege database, migraties vanaf nul: versie, Updates-scherm, feed, update), op wegwerpinstallaties van echte releases via HTTP |
| `UpgradeFailureTest` | C, D, E, F, G, een mislukte rollback met `recovery_required` en afhandelen, afbreken |
| `ExistingInstallAcceptanceTest` | een kopie van een bestaande installatie: inhoud, media, privé-opslag, `.env`, modules, routing en beide talen ongewijzigd |

De end-to-end-tests bouwen de releases met de echte `ReleaseBuilder` uit deze
checkout, pakken 0.1.0 uit in `/tmp`, geven hem een eigen database (een kopie
van de testdatabase), een eigen `.env` en een webserver als `www-data`, en
werken hem alleen via de endpoints van het Updates-scherm bij
(`Tests\Support\UpdaterSandbox`). Ze raken `mygdala-test` en de
ontwikkeldatabase nooit.

## Bewust niet in V1

Geen automatische of geplande updates, geen kanalen (beta/stable), geen
pluginmarkt, geen licentieserver, geen telemetrie, geen orkestratie van
meerdere sites, geen deltapatches, geen rollback naar willekeurige oudere
versies, geen publicatiescherm voor releases.
