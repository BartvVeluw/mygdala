# Mygdala

Een eigen PHP/MySQL-CMS met webshop. Geen framework, geen buildstap, geen
JS-framework: losse PHP-templates, `App\`-klassen onder `src/` (PSR-4,
Composer) en platte CSS/JS die de browser rechtstreeks laadt.

**Mygdala is de canonieke bron van dit CMS.** Alle generieke
CMS-ontwikkeling gebeurt hier. Van Veluw Laserdesign is een downstream site
die dit CMS draait: een stabiele productie-installatie en referentie, geen
ontwikkelomgeving. Een generieke wijziging wordt eerst hier bewezen.

> **Wil je weten hoe de applicatie in elkaar zit?** Lees
> [`CLAUDE.md`](CLAUDE.md) voor de wegwijzer en
> [`PROJECT-MAP.md`](PROJECT-MAP.md) voor de volledige kaart.
>
> **Wil je weten hoe je eraan verder bouwt?** [`WORKFLOW.md`](WORKFLOW.md):
> de skills, de instructielagen, welk document je wanneer leest, en waar
> nieuwe kennis thuishoort.
>
> **Dit bestand gaat alleen over de ontwikkelomgeving:** Docker, de database
> en de tests.

## De stack

| Onderdeel | Keuze |
|---|---|
| Taal | PHP 8.2 |
| Database | MySQL 8, benaderd via plain PDO (`src/Database.php`), geen ORM |
| Schema | [Phinx](https://book.cakephp.org/phinx/0/en/index.html)-migraties in `db/migrations/`, forward-only |
| Autoloading | Composer, PSR-4, namespace `App\` op `src/` |
| Frontend | Platte CSS en JS, geen bundler, geen transpiler |
| Tests | PHPUnit 10, zie [`TESTING.md`](TESTING.md) |
| Hosting | Vimexx gedeelde hosting: PHP + MySQL, geen Node.js |

De hosting is de belangrijkste beperking. De projectroot is de siteroot, dus
alles wat niet publiek mag zijn staat buiten de webroot of wordt door
`.htaccess` geblokt. Lokaal draait dezelfde stack in Docker.

## Lokaal draaien

Je hebt alleen Docker Desktop nodig. PHP, Composer en MySQL hoeven niet op
Windows te staan.

```bash
cp .env.example .env
docker compose up -d
```

De eerste keer bouwt dit de PHP-image en installeert het de
Composer-pakketten, dus dat duurt een minuut of twee. Daarna is het seconden.
Je krijgt:

| Service | Poort op je machine | Wat het is |
|---|---|---|
| `php` | `APP_PORT`, standaard 8000 | De website. PHP 8.2 + Apache, deze map als volume |
| `mysql` | geen | MySQL 8, migraties draaien automatisch bij elke start |
| `adminer` | `ADMINER_PORT`, standaard 8080 | Webinterface op de database, geen SQL-kennis nodig |
| `mailpit` | `MAILPIT_WEB_PORT`, standaard 8025 | Vangt uitgaande mail op, zodat er lokaal niets echt verstuurd wordt |

Een commando geef je vanuit deze map, aan de service:
`docker compose exec php …`. De containers zelf heten naar de map waarin deze
checkout staat (`mygdala-php-1`), en die naam heb je nergens voor nodig.

Er is geen buildstap: bewerk een `.php`, `.css` of `.js` en herlaad de
browser. De container mount deze map, dus wijzigingen zijn meteen zichtbaar.

Wil je een paar testproducten, draai dan één keer de seed. Nog een keer
draaien voegt gewoon dubbele rijen toe.

```bash
docker compose exec php php vendor/bin/phinx seed:run
```

Stoppen zonder iets kwijt te raken:

```bash
docker compose stop
```

### De database opnieuw beginnen

`-v` verwijdert de volumes van déze installatie: de database en de opgeslagen
bijlagen. Andere installaties merken er niets van. De migraties draaien bij de
volgende `up -d` vanzelf opnieuw. Alleen lokaal doen.

```bash
docker compose down -v
docker compose up -d
docker compose exec php php vendor/bin/phinx seed:run
```

### Adminer

Open `http://localhost:<ADMINER_PORT>` (standaard 8080) en log in met System
**MySQL**, Server `mysql`, en de `DB_USERNAME` / `DB_PASSWORD` /
`DB_DATABASE` uit je `.env`. MySQL heeft geen poort op je machine; wil je er
zonder Adminer in, dan kan dat in de container:
`docker compose exec mysql mysql -u root -p`.

### Commando's die je verder nodig hebt

```bash
docker compose exec php composer require some/package
docker compose exec php php vendor/bin/phinx migrate
docker compose exec php php vendor/bin/phinx create MyNewMigration
docker compose logs php
```

### Meerdere installaties naast elkaar

Elke clone van deze repository is een eigen installatie, en ze kunnen tegelijk
draaien. Docker Compose houdt ze uit elkaar met de projectnaam, en dat is de
naam van de map: een clone in `mygdala-test/` krijgt `mygdala-test-php-1`, een
eigen netwerk, een eigen MySQL-server en eigen volumes
(`mygdala-test_mysql_data`). Geen installatie ziet de database, de uploads of
de containers van een andere.

Het enige wat je zelf regelt zijn de poorten, want een poort op je machine kan
maar één keer bezet zijn. Geef elke installatie in zijn `.env` drie eigen
nummers:

```env
# mygdala
APP_PORT=8000
ADMINER_PORT=8080
MAILPIT_WEB_PORT=8025

# mygdala-test
APP_PORT=8100
ADMINER_PORT=8180
MAILPIT_WEB_PORT=8125
```

MySQL, de SMTP-poort van Mailpit en de testwebservers krijgen geen vaste poort
op je machine, dus die botsen nooit. Twee clones met **dezelfde mapnaam** zijn
het enige geval dat meer vraagt: zet dan `COMPOSE_PROJECT_NAME` in `.env`.
Open twee installaties in één browser op verschillende hostnamen
(`localhost` en `127.0.0.1`), anders loggen ze elkaar uit.

Een nieuwe site beginnen, van clone tot installatiewizard, staat in
[`SETUP.md`](SETUP.md), "Een nieuwe site beginnen".

## De tests

De suite draait tegen een eigen database en een eigen webcontainer, nooit
tegen je ontwikkeldata. Eén keer inrichten, daarna het gewone commando:

```bash
docker compose exec php php scripts/test-db.php
docker compose --profile test up -d
docker compose exec php_test php vendor/bin/phpunit
```

Draai de suite in `php_test`, niet in `php`. De snelle tiers hebben
niets nodig en mogen overal draaien:

```bash
docker compose exec php php vendor/bin/phpunit --testsuite fast
```

[`TESTING.md`](TESTING.md) beschrijft de suites (`blocks`, `shop`, `blog`,
`cms`, `modules`, ...) en wanneer je welke draait.

## De omgeving

Kopieer `.env.example` naar `.env` en vul lokale waarden in, met eigen poorten
als er op deze machine al een andere installatie draait. `.env` en `vendor/`
zijn gitignored en worden nooit gecommit. `.env.example` beschrijft elke
variabele, inclusief de `MODULE_*_ENABLED`-schakelaars uit
[`MODULES.md`](MODULES.md). Voor de tests volstaan de plaatshouders; welke
waarden echt nodig zijn, staat in [`TESTING.md`](TESTING.md).

## Naar Vimexx deployen

Dezelfde stack, dus dit sluit direct aan: upload de map via SSH of FTP, maak
een MySQL-database in het Vimexx-paneel, zet echte gegevens in een `.env` op
de server, en draai `composer install --no-dev` en `vendor/bin/phinx migrate`
over SSH. De root-`.htaccess` blokkeert webtoegang tot `.env`, `vendor/`,
`db/`, `src/` en `docker/`, dus die mogen blijven staan.

Een nieuwe site opzetten met deze codebase is een eigen recept, inclusief de
installatiewizard: zie [`SETUP.md`](SETUP.md).

## Versiebeheer

Deze repository is [BartvVeluw/mygdala](https://github.com/BartvVeluw/mygdala).

```bash
git clone https://github.com/BartvVeluw/mygdala.git
```

## Een bekende valkuil

Als `phinx migrate` op een verse database faalt met "table already exists":
dat is ons één keer overkomen (2026-09-03). `orders` en `order_items` hadden
signed foreign-keykolommen die naar Phinx' unsigned `id`-kolommen wezen, en
dat weigert MySQL, dus die twee migraties kwamen nooit af. Allang gerepareerd
in `db/migrations/`, hier genoemd voor als een migratie ooit dezelfde vorm
krijgt.
