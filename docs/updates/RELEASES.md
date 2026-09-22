# Releases maken

Hoe een Mygdala-release ontstaat, wat erin zit en hoe hij bij installaties
komt. Hoe de updater hem installeert: [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Versiebeleid

- **`VERSION` in de root is de versie.** Strikt `MAJOR.MINOR.PATCH`
  (`App\Update\SemVer`), zonder `v`, zonder achtervoegsel.
- **Een release maken is `VERSION` ophogen in een commit** en precies díe
  commit bouwen. De bouwer weigert als `VERSION` in de bron iets anders zegt
  dan de versie die je vraagt.
- **Geen versie uit Git.** Een live server heeft geen `.git`; de build-id
  (`0.2.0+3f2a1c9d0e4b`) komt uit de commit van `git archive` en is alleen
  informatief.
- **Releaseversie en migratieversie zijn onafhankelijk.** Een PATCH mag een
  migratie hebben; een MAJOR hoeft er geen te hebben.
- Richtlijn zolang we onder 1.0 zitten: **MINOR** voor nieuwe functionaliteit
  of een migratie die iets van de beheerder vraagt, **PATCH** voor
  herstelwerk. 1.0.0 volgt pas als het release- en updatecontract zelf niet
  meer verschuift.

### Waarom de eerste versie 0.1.0 is

Er was nog geen releasebeleid, geen tag en geen versienummer. "Multilingual
2.0" is de naam van een featuregeneratie, geen productversie, en 160
migraties zeggen niets over compatibiliteit voor een installatie. 0.1.0 zegt
wat waar is: dit is de eerste release, en het contract tussen releases
(manifestformaat, `release.json`, updaterprotocol) is nieuw en mag nog
bewegen. Dat is ook de reden dat `minimum_source_version` standaard `0.1.0` is:
dat is de eerste versie die zichzelf kan bijwerken.

## Wat er in een release zit

Alles wat de repository bevat en wat `App\Update\Ownership` release-eigendom
noemt, plus een `vendor/` van `composer install --no-dev`:

```text
mygdala-0.2.0.zip
├── VERSION, release.json, .htaccess, .env.example, *.php, phinx.php, composer.json, composer.lock
├── admin/  api/  partials/  src/  db/  scripts/ (productietools)
├── assets/css/  assets/js/  assets/images/block-preview/  assets/fonts/personalization/.htaccess
└── vendor/
```

Niet: `.env`, uploads, `storage/`, `tests/`, `docs/` en alle andere `*.md`,
`docker/`, `docker-compose.yml`, `phpunit.xml`, `.claude/`, `.git*`,
`scripts/test-db.php`, `scripts/create_fresh_site_copy.php`,
`scripts/release.php`, editor- en tijdelijke bestanden.

De bestanden staan in de **root van het zip-bestand**: uitpakken in een lege
webroot geeft een werkende installatie.

## De documenten

### `release.json` (in het pakket)

```json
{
    "format": 1,
    "product": "mygdala",
    "version": "0.2.0",
    "build_id": "0.2.0+3f2a1c9d0e4b",
    "released_at": "2026-10-01T12:00:00Z",
    "requirements": {"php": "8.2", "mysql": "5.7", "mariadb": "10.3", "extensions": ["pdo_mysql", "…"]},
    "migrations": {"count": 162, "latest": "20261001120000"},
    "updater": {"protocol": 1},
    "files": {"admin/index.php": "<sha256>", "…": "…"}
}
```

`files` is de volledige lijst van wat deze release bezit, gesorteerd, zonder
`release.json` zelf. Het is de basis voor de controle op lokale wijzigingen,
voor het updateplan (wat de vorige release had en deze niet meer, wordt
verwijderd) en voor de healthcheck.

### `manifest.json` (de feed)

```json
{
    "manifest_version": 1,
    "product": "mygdala",
    "version": "0.2.0",
    "build_id": "0.2.0+3f2a1c9d0e4b",
    "released_at": "2026-10-01T12:00:00Z",
    "package_url": "mygdala-0.2.0.zip",
    "sha256": "<sha256 van het zip-bestand>",
    "size": 9890348,
    "minimum_php": "8.2",
    "minimum_mysql": "5.7",
    "minimum_mariadb": "10.3",
    "required_extensions": ["pdo_mysql", "mbstring", "json", "zlib", "zip", "sodium", "openssl", "curl", "dom", "iconv"],
    "minimum_source_version": "0.1.0",
    "updater_protocol": 1,
    "migrations": {"count": 162, "latest": "20261001120000"},
    "notes": "Wat er nieuw is, platte tekst met regeleinden."
}
```

- Een relatieve `package_url` betekent "naast het manifest": de hele feed is
  één map die van host kan verhuizen zonder opnieuw te ondertekenen. Een
  absolute URL mag ook (een CDN), mits HTTPS.
- De releasehost serveert het pakket als gewoon statisch bestand, met HTTP
  Range en een ETag of `Last-Modified` — elke gewone webserver of CDN doet
  dat. Dan hervat een installatie een download die niet in één request past.
  Zonder Range begint elke poging opnieuw bij byte 0 en moet het pakket
  binnen één updaterequest binnenkomen (ARCHITECTURE.md, "Downloaden in
  delen"). Vervang een gepubliceerd pakket nooit door andere bytes onder
  dezelfde naam.
- `minimum_source_version`: de oudste versie die rechtstreeks naar deze
  release mag. Een installatie die ouder is, krijgt het advies eerst een
  tussenliggende release te installeren.
- `updater_protocol`: welk state-formaat de updater van deze release kan
  afmaken (`UpdateState::FORMAT`).
- Vereisten en migraties moeten precies gelijk zijn aan die in
  `release.json`; de updater controleert dat.
- Een nieuw formaat is een nieuwe `manifest_version`. Een installatie die hem
  niet kent, zegt dat, in plaats van hem half te begrijpen.

### `manifest.json.sig`

```json
{"algorithm": "ed25519", "key_id": "<eerste 16 hex van sha256(publieke sleutel)>", "signature": "<base64>"}
```

Een Ed25519-handtekening over de **exacte bytes** van `manifest.json`. Wie het
manifest na het ondertekenen ook maar met één spatie wijzigt, maakt het
ongeldig.

## De releasesleutel

Eén keer, door degene die releases gaat maken:

```bash
docker compose exec php php scripts/release.php keygen --out=/pad/buiten/de/repository
```

- Schrijft `mygdala-release.key` (de **geheime** sleutel, base64) en toont de
  **publieke** sleutel en zijn key-id. Het script weigert een map binnen de
  repository.
- **De geheime sleutel komt nooit op een server en nooit in Git.** Bewaar hem
  offline, met een back-up. Wie hem heeft, kan releases ondertekenen die elke
  installatie accepteert.
- Het vangnet als hij toch in de repository belandt: `.gitignore` negeert
  `mygdala-release.key` in elke map, en de bouwer neemt een bestand met die
  naam nooit in een pakket op (`Ownership`), ook niet bij `--source-dir`.
  `OwnershipTest` leest de bestandsnaam uit `keygen` zelf. Het is het enige
  bestand dat `keygen` schrijft: de publieke sleutel wordt alleen getoond.
- De publieke sleutel gaat in `App\Update\ReleaseKeys::BUILT_IN`, in een
  gewone commit. Vanaf de release die hem bevat, vertrouwen installaties hem.
  Een distributie met een eigen feed zet in plaats daarvan
  `MYGDALA_UPDATE_PUBLIC_KEY` in `.env`; die vervangt de ingebouwde sleutel.
- **Sleutel wisselen**: nieuwe sleutel maken, de nieuwe publieke sleutel
  naast de oude in `BUILT_IN` zetten en die release nog met de oude sleutel
  ondertekenen; vanaf de volgende release met de nieuwe tekenen, en de oude
  later uit `BUILT_IN` halen.

## De projectfeed

Sinds 0.1.0 heeft Mygdala een eigen feed en een eigen sleutel, allebei
ingebouwd. Een installatie hoeft voor updates dus niets in te stellen.

| Wat | Waarde |
|---|---|
| Feed (`UpdateConfig::DEFAULT_MANIFEST_URL`) | `https://github.com/BartvVeluw/mygdala/releases/latest/download/manifest.json` |
| Handtekening | dezelfde URL met `.sig` erachter |
| Pakket (`package_url`, absoluut) | `https://github.com/BartvVeluw/mygdala/releases/download/v<versie>/mygdala-<versie>.zip` |
| Sleutel (`ReleaseKeys::BUILT_IN`) | `kDLitDRCZ/HUz7n3VC8UX6BoPFPkaPlnoiX+09G/RDY=`, key-id `da6306e43b2bc085` |

- **De host is GitHub Releases** van deze (publieke) repository. Elke release
  is een GitHub-release op zijn eigen tag `v<versie>`, met drie assets:
  `mygdala-<versie>.zip`, `manifest.json.sig` en `manifest.json`.
- **`/releases/latest/download/` wijst altijd naar de nieuwste gepubliceerde
  release** (geen draft, geen pre-release). Daarom staat er in de feed-URL
  nooit een versie, en blijft hij voor elke volgende release gelijk.
- **Het pakket staat met een absolute URL onder zijn eigen tag in het
  manifest**, niet relatief. Relatief zou "naast het manifest" betekenen,
  en dat is hier `latest/download/`. Een download die over meerdere requests
  loopt, zou dan halverwege op een nieuwere release uitkomen zodra die
  verschijnt.
- GitHub stuurt `latest/download/…` in twee redirects door naar zijn
  CDN (`release-assets.githubusercontent.com`), allebei HTTPS. Dat CDN geeft
  206 op een Range, een sterke ETag en `Last-Modified`, zonder compressie.
  Het negeert `If-Range`, maar dat is veilig: de updater vergelijkt de ETag
  van elk antwoord zelf, controleert aan het eind de SHA-256 en een asset
  wordt nooit vervangen.
- De geheime sleutel staat bij de releasemaker, buiten elke repository, met
  een tweede kopie op een andere schijf en een offline kopie.

## Een release bouwen

Uit precies één revisie van de repository, met een verse `vendor/`:

```bash
git tag v0.2.0
git archive --format=zip -o /tmp/mygdala-src.zip v0.2.0
docker compose exec php php scripts/release.php build --version=0.2.0 --source-zip=/tmp/mygdala-src.zip --key-file=/pad/naar/mygdala-release.key --notes-file=/pad/naar/notities.txt
```

(Het zip-bestand moet een pad zijn dat de container ziet; binnen de map van
de installatie kan dat.) Waar Git naast PHP draait, doet `--ref=v0.2.0` de
eerste twee stappen zelf.

- `git archive` levert precies de bestanden van die revisie, zonder wat er
  verder in een werkmap staat, en zet de commit in het zip-commentaar; daar
  komt de build-id vandaan. De regeleinden bewaken `.gitattributes` en de
  bouwer samen ("Regeleinden", hieronder), niet de instellingen van de
  machine waarop je bouwt.
- `vendor/` komt uit `composer install --no-dev --optimize-autoloader` op de
  `composer.lock` van die revisie. `--vendor-dir=<map>` gebruikt een
  bestaande map (alleen voor tests of offline).
- De bouwer weigert als `VERSION` niet de gevraagde versie is, als de
  updaterbestanden ontbreken die de volgende update nodig heeft, als
  `vendor/` de onderhoudsguard niet laadt, of als twee paden op een
  hoofdletterongevoelig bestandssysteem hetzelfde bestand zouden zijn.
- De uitvoer is **deterministisch**: dezelfde bron en dezelfde opties geven
  dezelfde bytes, dus dezelfde SHA-256 (gesorteerde entries, één vaste tijd,
  één vaste modus).

Opties: `--minimum-source=0.1.0`, `--package-url=<url>` (absolute pakket-URL
in plaats van "naast het manifest"), `--released-at=<ISO 8601>`,
`--build-id=<id>`, `--out=<map>` (standaard `dist/`).

Voor de projectfeed is `--package-url` verplicht, met de tag van deze release
("De projectfeed"):
`--package-url=https://github.com/BartvVeluw/mygdala/releases/download/v0.2.0/mygdala-0.2.0.zip`.

Uitvoer in `dist/` (gitignored):

```text
dist/mygdala-0.2.0.zip
dist/manifest.json
dist/manifest.json.sig
dist/release.json
```

Zonder `--key-file` is de build **ongetekend**, en accepteert geen enkele
installatie hem. Dat is bedoeld voor een proefbuild.

### Regeleinden

Elk tekstbestand van de repository staat in een release met LF, elk binair
bestand byte voor byte. Dezelfde commit, dezelfde sleutel en dezelfde bouwer
geven zo op Windows en op Linux hetzelfde pakket, met dezelfde SHA-256.

- **Git bewaakt het in de repository.** `.gitattributes` noemt elk teksttype
  `text eol=lf` en elk binair type `binary`. Wat er niet in staat, krijgt
  `text=auto eol=lf`. Een uitchecking en een `git archive` geven daardoor LF,
  ook onder `core.autocrlf=true`. De blobs waren al LF, op één na:
  `admin/index.php` had CRLF (en één losse CR), en is bij de invoering
  omgezet.
- **De bouwer bewaakt het in het pakket.** `App\Update\Build\LineEndings`
  heeft dezelfde lijsten; `LineEndingsTest` houdt ze gelijk aan
  `.gitattributes`. In een tekstbestand wordt elke CRLF een LF, een binair
  bestand gaat ongewijzigd mee. Ook een build uit een Windows-werkkopie
  (`--source-dir`) of uit een archief van een Git die deze attributen niet
  las, levert dus de bytes van de repository.
- **De bouwer weigert wat hij niet kent:** een release-bestand waarvan het
  type in geen van beide lijsten staat, en een tekstbestand met een CR die
  geen regel afsluit. Een nieuw bestandstype is één regel in `.gitattributes`
  en één in `LineEndings`; `LineEndingsTest` (in `contract` en `fast`) meldt
  een release-bestand zonder regel al vóór de build.
- **`vendor/` blijft zoals Composer hem neerzet.** Upstreampakketten hebben
  hun eigen bytes, soms met CRLF (de lettertypemetrieken van dompdf) of
  binair (de lettertypes zelf). Daar komt de bouwer niet aan.
- **Niets wordt tijdens runtime genormaliseerd.** De updater vergelijkt hashes
  van bytes. Dat die bytes overal gelijk zijn, regelt de bouwer.

**v0.1.0 is met CRLF gepubliceerd.** De bron van die build was een
`git archive` op Windows onder `core.autocrlf=true`, van vóór dit contract.
Alle 1.029 bestanden buiten `vendor/` staan er met CRLF in: 1.028 omdat
`git archive` ze omzette, en `admin/index.php` omdat zijn blob CRLF had. Dat
is functioneel geldig (PHP, Apache en `AppVersion` lezen het net zo), en
0.1.0 wordt niet opnieuw gebouwd, getagd of vervangen. Vanaf de eerstvolgende
release is LF het formaat. De eerste update vanaf 0.1.0 vervangt daardoor ook
elk tekstbestand, op zijn hash; dat is verwacht en geen lokale wijziging.

## Controleren en publiceren

```bash
docker compose exec php php scripts/release.php verify --dir=dist --public-key=<base64 publieke sleutel>
```

Controleert de build zoals een installatie dat zal doen: de handtekening over
`manifest.json`, en het pakket tegen zijn SHA-256 en grootte.

Publiceren is: de bestanden uit `dist/` uploaden naar de feedmap op de
releasehost, **het pakket eerst en het manifest met zijn handtekening als
laatste**, zodat een installatie nooit een manifest ziet waarvan het pakket
er nog niet is. De vorige pakketten mogen blijven staan. Er is in V1 geen
publicatiescherm en niets publiceert automatisch.

Op de projectfeed gaat dat met een draft: maak de GitHub-release op de tag
als **draft**, upload `mygdala-<versie>.zip`, dan `manifest.json.sig`, dan
`manifest.json`, en publiceer de draft pas daarna. Een draft is niet
openbaar en telt niet als `latest`, dus alle drie de bestanden worden in één
keer zichtbaar. `release.json` zit al in het pakket en hoeft er niet als
los asset bij. Download na het publiceren de drie openbare bestanden terug en
draai `verify` op precies die bytes.

## Een bestaande installatie overzetten naar het releasemodel

Een installatie die uit een Git-uitchecking of een handmatige upload is
ontstaan, kan zichzelf niet bijwerken. **Er alleen een `release.json` bij
zetten is niet genoeg:**

- **Preflight weigert zolang er een `.git` staat**, als map of als bestand
  (een worktree). Zo'n installatie hoort bij Git; het Updates-scherm noemt
  haar een ontwikkelversie en de updater raakt haar niet aan.
- **Wat afwijkt van `release.json`, blokkeert de eerste update.** Preflight
  vergelijkt elk Core-bestand met zijn hash (`LocalChanges`). Een uitchecking
  wijkt altijd af: haar `vendor/` komt van `composer install` mét
  dev-pakketten, en de regeleinden van een werkkopie hoeven niet die van het
  pakket te zijn (Windows, `core.autocrlf`, "Regeleinden"), ook als Git zegt
  dat er niets gewijzigd is.
- **Wat geen release noemt, ruimt de updater nooit op** (`ARCHITECTURE.md`,
  "Het eigendomscontract"). `tests/`, `docs/`, `.claude/`, de dev-pakketten in
  `vendor/` of een oud template blijven dan voorgoed staan, en Apache serveert
  ze.

Daarom ook **niet het zip-bestand over de site heen uitpakken.** Dat
vervangt de releasebestanden, maar laat alles staan wat de release niet heeft,
`.git` incluis. De overstap gebeurt één keer, met de hand, naar precies de
versie die de installatie al draait. Voor de bestaande installaties is dat
**0.1.0**. `mygdala-test` is zo op 22 september 2026 overgezet, vanaf
Git-commit `d45e67e`; wat daarbij bleek, staat hieronder verwerkt.

### Vóór en na de releasecode

De controles van deze procedure gebruiken de tooling van de updater zelf:
`release.php verify`, het eigendomscontract (`App\Update\Ownership`),
`LocalChanges` en de onderhoudsguard. **Een installatie van vóór 0.1.0 heeft
die nog niet**, en geen pakket brengt `scripts/release.php` mee: dat is
gereedschap van de releasemaker. Welke code een stap draait, hangt er dus
van af of de releasecode al in de site staat:

| Moment | Welke code | Waarvoor |
|---|---|---|
| vóór de kopie (stap 1) | een vertrouwde uitchecking van `v0.1.0` of later: de installatie zelf als ze met Git op `v0.1.0` staat, anders een uitchecking buiten de installatie (die van de releasemaker) | `release.php verify` op de vier bestanden |
| vóór de kopie (stap 4) | het uitgepakte pakket naast de site (`../mygdala-0.1.0/vendor/autoload.php`), pas na `verify` | het eigendomscontract voor de verschil- en de opruimlijst |
| na de kopie (stap 4–9) | de site zelf (`vendor/autoload.php`, `release.json`) | de onderhoudsguard, `LocalChanges`, het Updates-scherm |

Controleer een pakket nooit met code uit datzelfde pakket: dan controleert
het zichzelf.

### De stappen

Nodig: de vier bestanden van die release (`dist/` of de feed), een shell met
PHP-CLI in de map van de installatie (in Docker: `docker compose exec php`
ervoor), en een rustig moment. Zo:

1. **Bewijs welke revisie de installatie draait**, nog met Git:

   ```bash
   php <uitchecking>/scripts/release.php verify --dir=/pad/naar/de/vier/bestanden --public-key=<base64 publieke sleutel>
   git fetch --tags
   git describe --exact-match --tags HEAD
   git rev-parse HEAD
   git status --porcelain
   git status --porcelain --ignored
   php vendor/bin/phinx status
   ```

   `verify` zegt OK. `git status --porcelain` is leeg: een lokale wijziging
   aan Core neem je eerst in Mygdala op of draai je terug. `--ignored` toont
   wat er staat zonder dat Git het bijhoudt (`.env`, uploads, `vendor/`, logs,
   misschien een `dist/`); je komt het in stap 4 weer tegen. Phinx meldt niets
   openstaands, en de nieuwste migratie is `migrations.latest` uit
   `release.json`. Dan:

   - **`describe` zegt `v0.1.0`**, en de build-id in de `release.json` van
     het pakket (`0.1.0+<commit>`) begint met dezelfde twaalf tekens als
     `git rev-parse HEAD`. Dit is de gewone weg.
   - **De installatie staat op een eerdere commit.** Breng haar bij voorkeur
     eerst met Git en Phinx naar `v0.1.0` en begin hier opnieuw. Blijven op
     die commit mag alleen als ze een voorouder is van de releasecommit en er
     tussen die twee niets in `db/` verandert, zodat de database al precies
     op het niveau van de release zit:

     ```bash
     git merge-base --is-ancestor HEAD v0.1.0 && echo voorouder
     git diff --name-only HEAD v0.1.0 -- db/
     git diff --name-status HEAD v0.1.0 > ../git-bereik.txt
     ```

     De tweede regel geeft niets. Bewaar `git-bereik.txt`: in stap 4 moet de
     verschillijst precies de `M`- en `A`-bestanden daaruit tonen die de
     release bevat, en niets anders. Zo is `mygdala-test` vanaf `d45e67e`
     overgezet: 10 gewijzigde en 49 ontbrekende release-bestanden, precies
     Git's lijst.
2. **Maak een volledige back-up:** een dump van de database, de hele map van
   de site met `.git` en `.env` erin, en de privé-opslag naast de site
   (bijlagen, facturen, personalisatie-uploads). Controleer dat de dump te
   lezen is. De updater heeft nog geen eigen back-up; dit is de weg terug.
3. **Haal `.git` uit de installatie.** Verplaats de map (of het bestand) naar
   buiten de webroot, naast de back-up, of verwijder hem als de back-up hem
   bevat. Vanaf hier is dit geen Git-uitchecking meer.
4. **Maak de release-eigen bestanden gelijk aan 0.1.0**, in twee delen.

   **Voorbereiden, buiten de webroot.** De site draait gewoon door.

   - Pak het pakket uit in een **lege map naast de site**, niet erin en niet
     eroverheen, en zet `release.json` daar meteen apart (die komt pas in
     stap 6):

     ```bash
     mkdir ../mygdala-0.1.0
     unzip -q mygdala-0.1.0.zip -d ../mygdala-0.1.0
     mv ../mygdala-0.1.0/release.json ../release-0.1.0.json
     ```

   - Maak de **verschillijst**: elk release-bestand dat in de site ontbreekt
     of andere bytes heeft, en of het verschil alleen in de regeleinden zit:

     ```bash
     php -r '$release = json_decode(file_get_contents("../release-0.1.0.json"), true)["files"];
         $lf = fn ($file) => str_replace("\r\n", "\n", (string) file_get_contents($file));
         foreach ($release as $path => $hash) {
             if (!is_file($path)) echo "ontbreekt\t$path\n";
             elseif (hash_file("sha256", $path) !== $hash) echo ($lf($path) === $lf("../mygdala-0.1.0/$path") ? "regeleinden" : "anders"), "\t$path\n";
         }' > ../verschil.txt
     ```

   - Maak de **opruimlijst**: elk bestand in de site dat de release niet
     heeft en dat geen installatiedata is. Wat installatiedata is, beslist
     het eigendomscontract van de release zelf:

     ```bash
     php -r 'require "../mygdala-0.1.0/vendor/autoload.php";
         $release = json_decode(file_get_contents("../release-0.1.0.json"), true)["files"];
         $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS));
         foreach ($walk as $file) {
             $path = substr(strtr($file->getPathname(), "\\", "/"), 2);
             if (!isset($release[$path]) && $path !== "release.json" && !App\Update\Ownership::isInstallationOwned($path)) echo $path, "\n";
         }' > ../overbodig.txt
     ```

   - **Lees beide lijsten.** In de verschillijst hoort `anders` en
     `ontbreekt` alleen bij bestanden die tussen de revisie van de
     installatie en de release veranderd zijn (bij `v0.1.0` zelf: geen), plus
     `vendor/`; `regeleinden` is verwacht op een Windows-werkkopie. Op de
     opruimlijst zijn verwacht: `.gitignore`, `.gitattributes`, `tests/`,
     `docs/`, `docker/`, `.claude/`, de `*.md`-bestanden, `phpunit.xml`,
     `docker-compose.yml`, `scripts/test-db.php`,
     `scripts/create_fresh_site_copy.php`, `scripts/release.php`, een
     eventuele `dist/`, logs, en de dev-pakketten in `vendor/` (PHPUnit en wat
     het meebrengt). Een bestand dat je niet verwacht, zoals een oud template
     of iets wat ooit met de hand is neergezet, is precies wat deze stap moet
     vinden: het gaat weg, tenzij het installatiedata is die het contract niet
     kent. Stop dan en laat het contract aanvullen (`Ownership`), in plaats van
     iemands upload weg te gooien.
   - **Hostinginfrastructuur mag blijven.** Wat nodig is om déze installatie
     te laten draaien en geen release-bestand is, hoort bij de installatie,
     ook als het contract het een ontwikkelbestand noemt. Een
     Docker-installatie waarvan de projectmap de webroot is, zoals
     `mygdala-test`, heeft `docker-compose.yml` en `docker/` nodig: die blijven
     staan en je noteert ze als bewust behouden. Geen release bevat ze, de
     updater vervangt of verwijdert ze nooit, en `.htaccess` weigert ze aan
     bezoekers (`docker/` als map, `docker-compose.yml` vanaf de eerstvolgende
     release na 0.1.0; onder 0.1.0 serveert Apache dat bestand nog).
   - **Controleer de rechten.** De kopie hieronder en elke latere update
     schrijven als **de gebruiker waaronder de webserver PHP uitvoert**: niet
     als jij en niet als root. Welke gebruiker dat is, verschilt per host: op
     gedeelde hosting meestal je eigen account, op een eigen server wat de
     configuratie van de webserver of de PHP-FPM-pool zegt, in het Docker-image
     van deze repository `www-data`. De eigenaar van een bestand dat de site
     zelf schreef (een upload onder `assets/media/`) vertelt het ook. Als die
     gebruiker (`sudo -u <php-gebruiker>`, in Docker
     `docker compose exec -u <php-gebruiker> php`):

     ```bash
     find . ! -writable
     ```

     Dit moet niets geven, of alleen installatiebestanden die je bewust zo
     laat. Bij `mygdala-test` stond `vendor/` op `root:root`, omdat het
     Docker-image bij het starten `composer install` als root draait: de
     PHP-gebruiker kon `vendor/` niet overschrijven. Herstel het als root, en
     alleen wat `find` noemde: `chown -R <php-gebruiker>:<groep> vendor`.
     Nooit `chmod -R 777`.

   **Het onderhoudsvenster.** `.maintenance` werkt alleen als de code de
   onderhoudsguard laadt, en die zit in `vendor/autoload.php` vanaf 0.1.0.
   **Een installatie van vóór 0.1.0 is vóór de kopie niet betrouwbaar in
   onderhoud te zetten:** de vlag doet niets tot de kopie `vendor/` en
   `src/Update/` van de release heeft neergezet, en tijdens de kopie antwoordt
   de site met een mengsel van oude en nieuwe bestanden. De volgorde die bij
   `mygdala-test` gebruikt is:

   1. vlag neerzetten, direct voor de kopie: `touch .maintenance`. Een
      installatie die al 0.1.0-code draait, geeft vanaf nu een 503; een oudere
      nog niet;
   2. de release over de site kopiëren, als de PHP-gebruiker, zodat elk
      release-bestand precies de bytes van het pakket heeft:
      `cp -R ../mygdala-0.1.0/. .` Uiterlijk als de kopie klaar is, krijgen
      bezoekers de 503 (bij `mygdala-test` duurde de kopie 16 seconden);
   3. verwijderen wat op de opruimlijst staat, behalve wat je bewust laat
      staan, en de mappen die daardoor leeg raken. Lege installatiemappen
      (`storage/`, `assets/media/`) laat je staan. De lijst en de kopie raken
      elkaar niet: op de lijst staat alleen wat de release niet heeft;
   4. `release.json` als laatste (stap 6);
   5. de vlag weg en controleren (stap 9).

   Mag een drukke productiesite die paar seconden van de kopie niet
   onbeschermd zijn, sluit haar dan bij de host af (de webserver stoppen, of
   de onderhoudsfunctie van het hostingpanel) en open haar na stap 6.
   `.maintenance` is dan het tweede slot.
5. **Installatiedata blijft staan:** `.env`, de uploads onder `assets/`,
   `storage/`, de privé-opslag naast de site, `.user.ini` of `php.ini` van de
   host, en de hostinginfrastructuur uit stap 4. De opruimlijst slaat ze
   over omdat `Ownership` ze installatie-eigendom noemt (de
   hostinginfrastructuur noteer je zelf als bewust behouden), en het pakket
   bevat geen van alle, dus de kopie raakt ze niet. De database blijft zoals
   hij is: de migraties van 0.1.0 draaiden al (stap 1).
6. **Zet `release.json` als laatste neer:** `cp ../release-0.1.0.json
   release.json`. Ook de updater schrijft hem als laatste; het is het
   commitpunt. Vanaf nu zegt de installatie dat ze precies 0.1.0 is.
7. **De productie-dependencies zijn de `vendor/` van het pakket**:
   `composer install --no-dev --optimize-autoloader` op de `composer.lock` van
   0.1.0. Na stap 4 is `vendor/` precies die map: de dev-pakketten stonden op
   de lijst, de rest is overschreven. Draai op een release-installatie nooit
   `composer install`, ook later niet: `release.json` kent de hash van elk
   bestand in `vendor/`, dus elke wijziging daar blokkeert de volgende update.
   Let op een Docker-image dat bij het starten `composer install` draait als
   `vendor/autoload.php` ontbreekt: laat die map dus nooit leeg.
8. **Stel de updater in**, in `.env` (`ARCHITECTURE.md`, "Configuratie"):
   voor de projectfeed hoeft er niets; `MYGDALA_UPDATE_MANIFEST_URL` en
   `MYGDALA_UPDATE_PUBLIC_KEY` alleen voor een distributie met een eigen feed
   en sleutel; `MYGDALA_UPDATE_STORAGE_PATH` als de standaard
   (`<map boven de site>/storage/updates`) niet schrijfbaar is of door een
   tweede site gedeeld zou worden: buiten de webroot, één per site.
9. **Controleer.** Haal `.maintenance` weg: zolang hij staat, krijgen
   bezoekers een 503, en Preflight weigert een vlag waarvoor geen update
   loopt. Daarna, nu met de code van de site zelf:

   - de opruimlijst uit stap 4 opnieuw, met `vendor/autoload.php` en
     `release.json` van de site: die is leeg, op wat je bewust liet staan na;
   - de bestanden tegen `release.json`, zoals Preflight dat bij de eerste
     update doet. Dit moet `OK` zeggen:

     ```bash
     php -r 'require "vendor/autoload.php"; $changes = App\Update\LocalChanges::detect(getcwd(), App\Update\ReleaseDescriptor::installed(getcwd())); echo $changes->isClean() ? "OK\n" : implode("\n", [...$changes->modified, ...$changes->missing]) . "\n";'
     ```

   - `find . ! -writable` als de PHP-gebruiker: leeg, op bewust behouden
     installatiebestanden na;
   - `php vendor/bin/phinx status`: niets openstaands, de nieuwste is
     `migrations.latest`;
   - **Instellingen → Updates**: "Geïnstalleerd vanuit een release", versie
     0.1.0 met zijn build-id. **Controleren op updates** geeft de eerste
     preflightronde zonder fouten;
   - de site en het CMS openen, inloggen, een pagina bekijken.

   De tweede preflightronde (lokale wijzigingen, migratieniveau,
   schrijfbaarheid) draait pas bij de eerste echte update. De controles
   hierboven doen die nu al, zodat die update geen verrassing vindt. Ruim
   daarna `../mygdala-0.1.0/` op; bewaar de back-up tot de eerste update
   gelukt is.
10. **Geen `git pull` meer.** Deze installatie werkt zichzelf voortaan bij via
    **Instellingen → Updates**. `git pull`, `composer install`, een handmatige
    upload of een met de hand aangepast Core-bestand laat de bestanden afwijken
    van `release.json` en blokkeert de volgende update; een teruggezette
    `.git` blokkeert de updater helemaal. Terug naar Git is een bewuste keuze,
    vanuit de back-up van stap 2.

## Checklist

- [ ] `VERSION` opgehoogd en gecommit; tag gezet
- [ ] suites groen op die commit (`TESTING.md`)
- [ ] `git archive` van de tag, build met sleutel en notities
- [ ] `release.php verify` zegt OK
- [ ] draft-release op de tag: pakket, handtekening, manifest; dan publiceren
- [ ] de openbare bestanden teruggedownload, `verify` op die bytes zegt OK
- [ ] op een testinstallatie via **Controleren op updates** gezien dat hij verschijnt
