# De ingebouwde updater

Een release-installatie van Mygdala werkt zichzelf vanuit het CMS bij naar een
gepubliceerde, ondertekende Mygdala-release. Geen Git, geen SSH, geen
handmatige upload, geen cron. De beheerder start de update zelf, onder
**Instellingen → Updates** (`admin/updates.php`).

Drie documenten:

| Document | Waarover |
|---|---|
| dit document | hoe de updater werkt en waarom zo |
| [`RELEASES.md`](RELEASES.md) | versiebeleid, de releasesleutel, een release maken en publiceren, een Git-installatie overzetten |
| [`RECOVERY.md`](RECOVERY.md) | wat er gebeurt als een update misgaat, en hoe je herstelt |

De code staat in `src/Update/` (`CLAUDE.md` daar is de korte versie). Wijkt de
code af van dit document, dan heeft de code gelijk.

## In één oogopslag

```text
Controleren op updates     manifest.json + .sig ophalen, handtekening checken, vereisten tonen
Update installeren         start een update naar precies die release
  download                 pakket naar work/<id>/package.zip, over zoveel requests als nodig, hervat met HTTP Range
  verify                   grootte en SHA-256 tegen het ondertekende manifest
  extract                  entry voor entry naar staging, ZIP-slip en symlinks geweigerd
  preflight                lokale wijzigingen, conflicten, schrijfrechten, migratiestatus, schijfruimte
  maintenance              .maintenance aan: bezoekers zien een onderhoudspagina
  backup_database          volledige SQL-dump vanuit PHP, rijtellingen geverifieerd
  backup_files             alleen de bestanden die vervangen of verwijderd worden
  apply                    in batches: tijdelijk bestand + rename, de codewissel en release.json als laatste
  migrate                  de eigen Phinx-migraties, via de library, één voor één
  health                   versie, elk bestand, migratieniveau, kernservices, HTTP
  finish                   onderhoud uit, werkmap weg, oude back-ups opgeruimd
bij een fout na apply      rollback_database → rollback_files
```

Elk request doet één begrensd stuk werk: een stap, of een deel van een lange
stap. De server bewaart waar de update is, tot op de byte en het bestand; het
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
| `*.md`, `docs/`, `tests/`, `docker/`, `docker-compose.yml`, `phpunit.xml`, `.claude/`, editor- en tijdelijke bestanden, een verdwaalde `mygdala-release.key` | nee, zit niet in een release | nooit | ja, blijft staan als het er is | nee |
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

**Wat er tijdens een update in de webroot staat.** `.htaccess` weigert,
naast `VERSION`, `release.json` en `.maintenance`, ook de vlag terwijl die
geschreven wordt (`.maintenance.<x>.tmp`) en de kopie die de updater vlak vóór
een rename naast zijn doel zet (`<naam>.mygdala-<id>.tmp`): PHP-broncode als
tekst, als een request stierf tussen kopie en rename. Een rollback ruimt zo'n
kopie op. `ApacheAcceptanceTest` bewijst dit op de echte Apache.

**Logs** bevatten geen geheimen: de manifest-URL wordt teruggebracht tot host
en pad (`UpdateConfig::describeUrl`), het health-token staat alleen in de
state.

**Geen telemetrie.** De User-Agent noemt product en versie en verder niets;
de updateserver leert niet welke sites er bestaan.

## Configuratie

Op distributieniveau, in de omgeving, nooit als veld in het CMS:

| Variabele | Betekenis | Standaard |
|---|---|---|
| `MYGDALA_UPDATE_MANIFEST_URL` | de feed | de projectfeed op GitHub Releases (`UpdateConfig::DEFAULT_MANIFEST_URL`, `RELEASES.md` "De projectfeed") |
| `MYGDALA_UPDATE_PUBLIC_KEY` | de Ed25519-publieke sleutel, base64; **vervangt** de ingebouwde sleutel | leeg; ingebouwd: `ReleaseKeys::BUILT_IN`, de projectsleutel `da6306e43b2bc085` |
| `MYGDALA_UPDATE_STORAGE_PATH` | de werkmap van de updater | `<map boven de site>/storage/updates` |
| `MYGDALA_UPDATE_STEP_SECONDS` | het tijdsbudget van één updaterequest (1 tot 15 seconden) | een derde van `max_execution_time`, hoogstens 15 seconden |

Zonder variabelen gebruikt een installatie de projectfeed en de
projectsleutel. Een distributie die feed en sleutel zelf leegmaakt, of een
`MYGDALA_UPDATE_PUBLIC_KEY` die geen bruikbare sleutel is, krijgt op het
Updates-scherm te zien dat er geen updatebron of geen sleutel is ingesteld;
er wordt dan niets gecontroleerd en niets geïnstalleerd.

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
<storage>/work/<id>/            package.zip(.part), staging/, plan.json, apply.journal
<storage>/backups/<id>/         database.sql.gz, database.json, files/, release.json, backup.json, HERSTEL.txt
```

De werkmap van een update verdwijnt zodra hij klaar is: bij `completed`,
`failed` en `rolled_back`, en uiterlijk bij de start van de volgende. Bij
`recovery_required` blijft hij staan, voor wie met de hand herstelt.

De updater schrijft er een `.htaccess` met `Require all denied` in, voor het
geval iemand de map toch in de webroot zet — ook als de map met de hand is
aangemaakt voordat de updater er voor het eerst in kwam, maar nooit in een map
waar de site zelf in staat (daar zou hij de hele site dichtzetten). De state onthoudt bij welke
installatie hij hoort; een map die per ongeluk door twee installaties gedeeld
wordt, wordt geweigerd.

## Hervatbaar: één stap per request

Een gedeelde host breekt een request na 30 tot 60 seconden af. Daarom:

- **`api/admin/updates-step.php` voert precies één stap uit** en slaat de state
  op. `admin/assets/updates.js` vraagt de volgende, tot de update niet meer
  loopt. Zonder JavaScript doet de knop Doorgaan hetzelfde met één klik per
  stap.
- **Elk request heeft een tijdsbudget** (`UpdateConfig::stepSeconds()`: een
  derde van `max_execution_time`, hoogstens 15 seconden, of
  `MYGDALA_UPDATE_STEP_SECONDS`) en elke lange stap een cursor in de state:
  **downloaden**, uitpakken, database-back-up, bestandsback-up, **apply**,
  migraties en de database-restore gaan over zoveel requests als nodig. Geen
  request hangt af van een browser die tientallen seconden wacht.
- **Elke stap is herhaalbaar.** Een stap die zijn eigen naam al in `in_step`
  vindt, weet dat de vorige poging halverwege stierf, en herstelt zijn cursor:
  de download en de back-up kappen hun deelbestand af tot de laatst
  opgeslagen lengte (en beginnen opnieuw als dat bestand korter is, ontbreekt
  of niet meer de opgeslagen hash heeft), de restore begint de tabel opnieuw
  bij zijn `DROP TABLE`, apply slaat over wat het journaal al noemt.
- **De server beslist.** Cursor, offset en voortgang staan in de state; een
  request noemt alleen het update-id en de stap. Het antwoord zegt hoe ver
  een lange stap is ("Downloaden (45%)"), het script toont dat alleen.
  Het scherm draait alleen vanzelf stappen direct na
  **Update installeren** — een eenmalige vlag in de sessie, geen
  URL-parameter, zodat een link het niet kan. Kom je terug na een gesloten
  tab, dan zegt het "De update is onderbroken bij de stap X" met een knop
  Doorgaan.
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

### Downloaden in delen

`PackageDownload` en `HttpFetcher::resume()`. Het pakket groeit in
`work/<id>/package.zip.part`; pas als het compleet is, wordt het
`package.zip`.

- **Checkpoints.** Na elke MiB wordt de `.part` geflusht en slaat de state op
  hoeveel bytes er vertrouwd zijn en wat hun SHA-256 is. Een request dat de
  host halverwege afschiet, verliest alleen wat na het laatste checkpoint
  kwam.
- **Hervatten.** Het volgende request vraagt `Range: bytes=N-`, met
  `If-Range` op de sterke ETag (of `Last-Modified`) van het eerste antwoord.
  Alleen een 206 met `Content-Range: bytes N-M/T`, waarin N precies de offset
  is en T precies de grootte uit het ondertekende manifest, wordt aan het
  bestand vastgeplakt.
- **Geen veilige hervatting, dan opnieuw vanaf byte 0.** Een 200 op een
  range-verzoek (de bron negeert ranges, of het bestand is veranderd), een 206
  op de verkeerde plek of zonder `Content-Range`, een 416: het deelbestand
  gaat weg en de download begint opnieuw. Een bron die een range één keer
  fout beantwoordde, krijgt er geen meer. Na drie keer opnieuw beginnen stopt
  de update (`failed`); er is dan nog niets aan de site veranderd.
- **Een beschadigd deelbestand** — korter dan het checkpoint, of met een
  andere hash — begint opnieuw; langer wordt afgekapt tot het checkpoint.
- **Grenzen blijven exact.** Nooit meer bytes dan het manifest noemt (ook
  niet in een 206), een `Content-Length` of totaal dat niet klopt wordt
  geweigerd, en de SHA-256 wordt daarna in `verify` over het hele bestand
  gecontroleerd, welke weg de bytes ook namen. Offset en URL komen nooit uit
  het request: de offset uit de state en het bestand, de URL uit het
  manifest.
- **Begrensd.** Verbinden, elke redirect, de headers en elke read wachten
  hoogstens tot het einde van het budget, met minimaal één seconde per
  wachtmoment. Een request dat helemaal niets ontving is een fout, geen
  voortgang.

### Bestanden toepassen in batches

`FileApplier` rekent het plan om tot een vaste lijst bewerkingen (een pure
functie van het plan en van de bestanden van het request zelf) en de state
bewaart de cursor: welke bewerking de volgende is, van hoeveel.

- **Per request** hoogstens `FileApplier::BATCH` (200) bewerkingen of het
  tijdsbudget, wat het eerst komt.
- **Het journaal eerst.** Elke bewerking komt in `apply.journal` (nummer,
  soort, pad) zodra hij gelukt is, en pas daarna kan de cursor erlangs. Het
  journaal loopt dus nooit achter op de cursor; een journaal dat andere
  bewerkingen nummert of achterloopt, wordt geweigerd (en de update
  teruggedraaid). Een half weggeschreven regel van een afgeschoten request
  wordt weggelaten.
- **Hervatten** gaat precies vanaf de cursor, en slaat over wat het journaal
  al noemt. Een bewerking die wel gebeurde maar het journaal niet haalde,
  gebeurt nog een keer, en dat is onschadelijk: een write zet dezelfde
  release-bytes neer (hash gecontroleerd), een delete vindt niets meer.
- **De codewissel.** Wat een request tijdens het onderhoud kan laden of
  krijgen, en de regels waaronder het geserveerd wordt — `src/`, `admin/`,
  de update-endpoints, Composers autoloader, elk bestand waaruit het
  updatende request zelf bestaat en elke `.htaccess`
  (`FileApplier::isRuntime()`) — verandert pas in het laatste request, samen
  met de verwijderingen daarvan, `VERSION` en als allerlaatste
  `release.json`. Een nieuwe `.htaccess` met een regel die de host weigert,
  kan het Updates-scherm dus niet halverwege de apply onbereikbaar maken. Dat request wordt nooit op zijn budget afgebroken en begint
  altijd vers. Zo draaien het Updates-scherm, de login en de volgende stap
  tussen twee batches op één consistente (oude) release, ook als de beheerder
  pas uren later terugkomt.
- **Pas daarna migreren.** `migrate` begint pas als de laatste bewerking,
  `release.json`, gedaan is; `health` en `finish` daarna.
- **Rollback** werkt vanaf elk punt: na een willekeurig aantal batches, of na
  een request dat halverwege een batch stierf. Alle bestanden uit het plan
  gaan terug uit de back-up, toegevoegde bestanden en achtergebleven
  tijdelijke kopieën weg, `release.json` als laatste. De weg terug wordt
  eerst als eigen stap opgeslagen (`rollback_files`) en het journaal gaat als
  eerste weg: een request dat halverwege het terugzetten sterft, wordt bij
  Doorgaan gevolgd door verder terugzetten, nooit door een apply die vooruit
  gaat over half teruggezette bestanden.

### Het formaat overleeft de code

Elke stap na `apply` draait op de code van de **nieuwe** release, die een
state leest die de oude release schreef. `UpdateState::FORMAT` is dat
contract, en het manifest noemt welk formaat de updater van de release kan
afmaken (`updater_protocol`). Preflight weigert een release die de update die
deze installatie zou starten niet kan afronden. Velden toevoegen mag;
hernoemen of weghalen is een nieuw formaat. Een release moet bovendien de
bestanden bevatten waarmee hij zijn eigen update afmaakt
(`PackageValidator::REQUIRED_PATHS`).

Hetzelfde geldt voor de volgorde van de bewerkingen (`FileApplier::
operations()`, `isRuntime()`) en de regels van `apply.journal` (`nummer soort
pad`): wordt een codewissel halverwege afgebroken, dan maakt de deels nieuwe
code het journaal van de oude af. Een release die daar iets aan verandert,
laat zo'n journaal weigeren — de apply wordt dan teruggedraaid, wat veilig is
maar een voltooide update kan kosten — en hoort dat dus samen met een nieuw
`updater_protocol` te doen.

Om dezelfde reden leest een rollback het plan en de oude `release.json`
**zonder** het eigendomscontract van de nieuwe code: welke paden de oude
release bezat, besliste de code die ze schreef. Een pad dat de nieuwe release
tot installatie- of ontwikkeldomein rekent, moet toch terug kunnen. De paden
worden wel op veiligheid gecontroleerd.

## Preflight

Twee rondes (`Preflight`). Een **fout** stopt de update met de redenen; een
**waarschuwing** wordt getoond en gelogd. Een fout is iets dat de update zeker
laat mislukken of data kost; een waarschuwing is iets wat de updater op deze
host niet kan meten.

| Ronde | Controle |
|---|---|
| vóór de download (ook bij "Controleren") | release-installatie (geen Git, geldige `release.json`), `VERSION` gelijk aan `release.json`, doelversie nieuwer, minimale bronversie, updaterprotocol, PHP-versie, MySQL- of MariaDB-versie, extensies (van de release én van de updater zelf: zip, sodium, json, zlib, mbstring, pdo_mysql), de werkmap, schijfruimte voor de download, OPcache (een host die vervangen PHP-bestanden nooit opnieuw compileert — geen tijdstempelcontrole én geen `opcache_invalidate` — wordt geweigerd) |
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
  `updates-resolve`, login, logout, en een request met het health-token van
  de lopende update;
- op de command line mogen Phinx, Composer en de tests door, maar de eigen
  cronscripts onder `scripts/` niet (analytics opruimen, PostNL-tarieven,
  vertaalwezen): die zouden schrijven tussen de back-up en een restore, of
  draaien op half vervangen code. Ze stoppen met exitcode 75.

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
geen symlink-`current` om om te zetten, de siteroot is de documentroot.
Zolang de apply loopt — over meerdere requests, en na een gesloten tab
misschien uren — zijn sommige bestanden dus nieuw en andere oud. Drie dingen
maken dat onschadelijk:

1. de site staat in onderhoud: een bezoekersrequest stopt in de guard, in
   `vendor/autoload.php`, vóór er code van de pagina draait;
2. de batches veranderen alleen wat tijdens het onderhoud niemand laadt:
   templates, publieke assets, migraties, scripts, bibliotheken die de
   updater niet gebruikt. Alles wat de updater en de schermen die open
   blijven wél laden, en de `.htaccess`-regels waaronder ze geserveerd
   worden, wisselt in één laatste request, de codewissel
   ("Bestanden toepassen in batches"), met elke klasse die dat request nodig
   heeft vooraf geladen en de bestanden waar het zelf uit bestaat
   (`get_included_files()`) als laatste;
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
Standaard-SQL, één INSERT per regel, utf8mb4, UTC, gzip als één stream (boven
100 MB blijft hij platte SQL, zodat comprimeren nooit groter wordt dan één
request): een supportmedewerker importeert hem met phpMyAdmin. De dump draait
op een eigen verbinding met een vaste `sql_mode`, zodat `NO_BACKSLASH_ESCAPES`
of `ANSI_QUOTES` van de server hem niet kan verbuigen. `DROP` + `CREATE TABLE` uit
`SHOW CREATE TABLE` (sleutels, foreign keys en `AUTO_INCREMENT` komen exact
terug), elke rij (binair als hex, BIT als getal, echte gegenereerde kolommen
overgeslagen — een kolom met een expressie-default zoals `CURRENT_TIMESTAMP`
niet), en views. Hervatbaar, per primaire sleutel gepagineerd. Aan het eind
moet het dumpbestand precies de lengte hebben die de cursor bijhield, en wordt
elke tabel geteld tegen de dump; klopt het niet, dan is de back-up mislukt. Triggers, routines en events worden niet gedumpt; een
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
| `ResumableDownloadTest` | de download over meerdere requests tegen een echte server: hervatten met Range vanaf de opgeslagen offset, een bron die ranges negeert of fout beantwoordt (opnieuw vanaf 0, na drie keer stop), een veranderd bestand, een beschadigd of te kort deelbestand, een afgeschoten request dat zijn checkpoint houdt, het budget |
| `PreflightEnvironmentTest` | vereisten tegen de echte databaseserver (scenario E op unitniveau) |
| `PackageExtractionTest` | ZIP-slip, symlinks, dubbele namen, corrupte archieven, pakketvalidatie |
| `UpdatePlannerTest` | het plan en lokale wijzigingen (scenario H op unitniveau) |
| `DatabaseBackupTest` | dump en restore tot op de byte, met lastige data, en wat een mislukte migratie achterlaat |
| `ApplyAndMaintenanceTest` | apply in batches met cursor en journaal, de codewissel als laatste request, hervatten na 30%, een request dat echt wordt afgeschoten (SIGKILL) midden in een batch en tussen een write en zijn journaalregel, rollback na meerdere batches, de onderhoudsguard |
| `LineEndingsTest` | het regeleindecontract: `.gitattributes` en `LineEndings` kennen dezelfde bestandstypes, en elk release-bestand van deze repository heeft een regel (ook in `contract` en `fast`) |
| `ReleaseBuilderTest` | wat er in een release zit, deterministisch, en dat de installatie het accepteert; een LF- en een CRLF-kopie van deze repository geven byte voor byte hetzelfde pakket, binaire bestanden en `vendor/` ongewijzigd; een onbekend bestandstype of een losse CR wordt geweigerd |
| `UpgradeEndToEndTest` | A (0.1.0 → 0.2.0, een apply van meerdere requests, stappen strikt op volgorde), B (0.1.0 → 0.3.0 over twee migratiegeneraties), H, I (onderbroken binnen de download en binnen de apply: gesloten tab, tweede tab met 423, een afgeschoten request, 409 op een oude stap), de guards, en een verse installatie (lege database, migraties vanaf nul: versie, Updates-scherm, feed, update), op wegwerpinstallaties van echte releases via HTTP |
| `UpgradeFailureTest` | C, D, E, F, G, een apply die na meerdere batches faalt (teruggedraaid, geen migratie), een mislukte rollback met `recovery_required` en afhandelen, afbreken |
| `ExistingInstallAcceptanceTest` | een kopie van een bestaande installatie: inhoud, media, privé-opslag, `.env`, modules, routing en beide talen ongewijzigd |
| `ApacheAcceptanceTest` | op de echte Apache van het image, met `.htaccess` actief (opt-in, `TESTING.md`): metadata, werkmap en een `docker-compose.yml` van de installatie nooit leesbaar, ook met de werkmap ín de webroot, bij elke stap, en een weigering noemt geen pad; het onderhoudsvenster (503, doorsturen, login/logout, health-token, een vreemde komt nergens); een update die `.htaccess` vervangt; hervatten tegen Apache's eigen Range-ondersteuning |

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
