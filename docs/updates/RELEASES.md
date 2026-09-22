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
- De publieke sleutel gaat in `App\Update\ReleaseKeys::BUILT_IN`, in een
  gewone commit. Vanaf de release die hem bevat, vertrouwen installaties hem.
  Een distributie met een eigen feed zet in plaats daarvan
  `MYGDALA_UPDATE_PUBLIC_KEY` in `.env`; die vervangt de ingebouwde sleutel.
- **Sleutel wisselen**: nieuwe sleutel maken, de nieuwe publieke sleutel
  naast de oude in `BUILT_IN` zetten en die release nog met de oude sleutel
  ondertekenen; vanaf de volgende release met de nieuwe tekenen, en de oude
  later uit `BUILT_IN` halen.

**Stand op het moment van schrijven:** er is nog geen releasesleutel en geen
releasehosting gekozen. `ReleaseKeys::BUILT_IN` en
`UpdateConfig::DEFAULT_MANIFEST_URL` zijn leeg; het Updates-scherm zegt dan dat
er geen updatebron is ingesteld.

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

- `git archive` levert de bestanden zoals ze in de repository staan, met de
  regeleinden van de repository in plaats van die van een Windows-werkkopie,
  en zet de commit in het zip-commentaar; daar komt de build-id vandaan.
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

Uitvoer in `dist/` (gitignored):

```text
dist/mygdala-0.2.0.zip
dist/manifest.json
dist/manifest.json.sig
dist/release.json
```

Zonder `--key-file` is de build **ongetekend**, en accepteert geen enkele
installatie hem. Dat is bedoeld voor een proefbuild.

## Controleren en publiceren

```bash
docker compose exec php php scripts/release.php verify --dir=dist --public-key=<base64 publieke sleutel>
```

Controleert de build zoals een installatie dat zal doen: de handtekening over
`manifest.json`, en het pakket tegen zijn SHA-256 en grootte.

Publiceren is: de vier bestanden uit `dist/` uploaden naar de feedmap op de
releasehost, **het pakket eerst en het manifest met zijn handtekening als
laatste**, zodat een installatie nooit een manifest ziet waarvan het pakket
er nog niet is. De vorige pakketten mogen blijven staan. Er is in V1 geen
publicatiescherm en niets publiceert automatisch.

## Een bestaande installatie overzetten naar het releasemodel

Een installatie die uit een Git-uitchecking of een handmatige upload is
ontstaan, heeft geen `release.json` en kan zichzelf dus niet bijwerken. Eén
keer, met de hand:

1. Maak een back-up van de database en van de site.
2. Pak het releasepakket van de versie die je wilt draaien uit **over** de
   bestaande site (FTP of het bestandsbeheer van de host). `.env`, uploads en
   privé-opslag zitten niet in het pakket en blijven dus staan.
3. Draai de migraties van die versie (`vendor/bin/phinx migrate`, of via de
   hostingomgeving).
4. Controleer onder **Instellingen → Updates** dat de installatie als
   release-installatie op die versie wordt getoond.

Bestanden die de oude opzet wel had en de release niet (een `tests/`-map,
documentatie, oude templates) blijven daarbij staan; de updater raakt ze nooit
aan, want geen release noemt ze. Ze mogen met de hand weg.

## Checklist

- [ ] `VERSION` opgehoogd en gecommit; tag gezet
- [ ] suites groen op die commit (`TESTING.md`)
- [ ] `git archive` van de tag, build met sleutel en notities
- [ ] `release.php verify` zegt OK
- [ ] pakket geüpload, daarna manifest en handtekening
- [ ] op een testinstallatie via **Controleren op updates** gezien dat hij verschijnt
