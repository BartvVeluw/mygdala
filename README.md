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
docker compose up -d
```

De eerste keer bouwt dit de PHP-image en installeert het de
Composer-pakketten, dus dat duurt een minuut of twee. Daarna is het seconden.
Je krijgt:

| Container | Poort | Wat het is |
|---|---|---|
| `mygdala_php` | 8000 | De website. PHP 8.2 + Apache, deze map als volume |
| `mygdala_mysql` | — | MySQL 8, migraties draaien automatisch bij elke start |
| `mygdala_adminer` | 8080 | Webinterface op de database, geen SQL-kennis nodig |
| `mygdala_mailpit` | 8025 | Vangt uitgaande mail op, zodat er lokaal niets echt verstuurd wordt |

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

`-v` verwijdert het MySQL-volume. De migraties draaien bij de volgende
`up -d` vanzelf opnieuw. Alleen lokaal doen.

```bash
docker compose down -v
docker compose up -d
docker compose exec php php vendor/bin/phinx seed:run
```

### Adminer

Open `http://localhost:8080` en log in met System **MySQL**, Server `mysql`,
en de `DB_USERNAME` / `DB_PASSWORD` / `DB_DATABASE` uit je `.env`.

### Commando's die je verder nodig hebt

```bash
docker compose exec php composer require some/package
docker compose exec php php vendor/bin/phinx migrate
docker compose exec php php vendor/bin/phinx create MyNewMigration
docker compose logs php
```

## De tests

De suite draait tegen een eigen database en een eigen webcontainer, nooit
tegen je ontwikkeldata. Eén keer inrichten, daarna het gewone commando:

```bash
docker exec mygdala_php php scripts/test-db.php
docker compose --profile test up -d
docker exec mygdala_php_test php vendor/bin/phpunit
```

Draai de suite in `mygdala_php_test`, niet in `mygdala_php`. De snelle tiers hebben
niets nodig en mogen overal draaien:

```bash
docker exec mygdala_php php vendor/bin/phpunit --testsuite fast
```

[`TESTING.md`](TESTING.md) beschrijft de suites (`blocks`, `shop`, `blog`,
`cms`, `modules`, ...) en wanneer je welke draait.

## De omgeving

Kopieer `.env.example` naar `.env` en vul lokale waarden in. `.env` en
`vendor/` zijn gitignored en worden nooit gecommit. `.env.example` beschrijft
elke variabele, inclusief de `MODULE_*_ENABLED`-schakelaars uit
[`MODULES.md`](MODULES.md).

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
