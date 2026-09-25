# Werkwijze

Hoe je aan Mygdala verder bouwt: wat er is, wanneer je wat gebruikt, en waar
nieuwe kennis thuishoort.

`CLAUDE.md` is de wegwijzer die elke sessie automatisch meekrijgt en die kort
moet blijven. **Dit document is de uitleg eromheen**, en je leest het wanneer
je iets wilt opzoeken of wanneer je iets aan de opzet toevoegt. Bij gewoon
ontwikkelwerk heb je het niet nodig.

## De vier lagen

Instructies zitten in vier lagen. Elke laag heeft een eigen moment waarop hij
in context komt, en dat is precies waarom de opzet werkt: je betaalt alleen
voor wat je nodig hebt.

| Laag | Waar | Wanneer geladen |
|---|---|---|
| Wegwijzer | `CLAUDE.md` | Elke sessie, automatisch |
| Path-regels | `.claude/rules/*.md` | Zodra een bestand wordt geopend dat op de `paths:` matcht |
| Mapregels | `CLAUDE.md` in een submap | Zodra een bestand in die map wordt geopend |
| Skills | `.claude/skills/*/SKILL.md` | Alleen wanneer jij hem aanroept |

**Een regel is reactief, een skill is preventief.** Een path-regel vuurt pas
nadat een bestand al open is. Hij stuurt het gedrag *binnen* een domein, maar
kan niet voorkomen dat een sessie in het verkeerde domein begint. Dat doet de
skill wel, want die roep je vooraf aan. Daarom begin je een taak altijd met de
skill, en zijn de regels het vangnet daarna.

### Nagaan wat er echt geladen is

Achteraf kun je een sessie vragen welke lagen ze gezien heeft, maar dat is
navertellen. `.claude/hooks/log-instructions.py` schrijft het op, in
`.claude/instructions-loaded.log`: tijdstip, sessie, instructiebestand,
waarom, en het bestand dat de aanleiding was. Het logboek is gitignored, want
het is een waarneming van één machine.

Twee hooks in `.claude/settings.json` voeden het. De skill-regels zijn
waargenomen — een aanroep gaat door de tool `Skill` heen. De regels over
path- en mapregels zijn **afgeleid**: het script leest de `paths:` uit de
regelbestanden zelf en matcht die opnieuw. Dat is een benadering van wat de
harness doet, dicht genoeg om een sessie mee na te lopen, en het houdt de
globlijst op één plek. Het script heeft `python` op het pad nodig en eindigt
altijd met 0: gaat er iets mis, dan mist er hooguit een regel.

**Wat het logboek níet ziet.** De hooks hangen aan `Skill` en aan
`Read|Edit|Write|NotebookEdit`. Leest een sessie bestanden met de tool `Bash`
— `cat`, `sed`, `grep` — dan komt er geen regel in het logboek, en vuurt de
path-regel zelf ook niet, want die hangt aan dezelfde tools. Een sessie die
vooral via Bash leest werkt dus met twee van de vier lagen, en het logboek is
daar stil over. Dat is bewust niet gerepareerd: uit een willekeurig
shellcommando afleiden welke bestanden het opende vraagt een parser die bij
elke pipe of `xargs` het verkeerde antwoord geeft, en een verkeerd logboek is
erger dan een leeg logboek. Een tweede stilte: is `.claude/` er niet, dan doet
de hook niets en schrijft hij ook niet op dat hij niets deed — daarvoor is de
controle hieronder.

Kortom: een regel in het logboek is bewijs dat een laag geladen is, het
ontbreken van een regel is geen bewijs van het tegendeel.

### Een verse worktree

`.claude/` en alle `CLAUDE.md`-bestanden zitten in de commit — `.gitignore`
zegt dat met zoveel woorden — dus een uitchecking heeft ze, of hij is stuk.
`git worktree add` zet ze er gewoon bij. Toch is het één keer misgegaan: in een
verse agent-worktree stonden de twaalf bestanden onder `.claude/` als
*staged deletion* in `git status`, en dan is er niets meer dat waarschuwt.
`/content-block` meldt een onbekende skill, geen path-regel vuurt, de hook
zwijgt omdat zijn eigen map weg is, en de sessie werkt door alsof ze de
vangrails heeft.

Controleer daarom als eerste dat de lagen er staan:

```bash
git status --short
```

Zie je regels als `D  .claude/skills/...`, of is `.claude/` er helemaal niet,
haal ze dan terug uit de commit waar ze al in staan:

```bash
git restore --source=HEAD --staged --worktree -- .claude CLAUDE.md
```

`Tests\Architecture\ContextSetupTest` is hetzelfde vangnet in de suite: hij
loopt mee in `fast`, leest alleen bestanden en faalt met het pad dat mist. De
skills die hij verwacht komen uit de routeringstabel van `CLAUDE.md` en de
mapregels uit de lijst hieronder, dus er is geen tweede lijst die kan gaan
afwijken.

Composer-dependencies horen niet bij de commit en komen er dus niet mee. Hoe
je die in een worktree zet, en waarom een symlink naar de `vendor/` van een
andere uitchecking je stilletjes de verkeerde code laat testen, staat in
[`TESTING.md`](TESTING.md), "Vanuit een git worktree".

## Zo begin je een taak

1. **Noem het domein.** Typ de skill en daarachter wat je wilt:
   `/shop Ik wil kortingscodes die per collectie beperkt kunnen worden.`
2. **Laat eerst een impactanalyse maken.** Welke migratie, welke repository,
   welke service, welk adminscherm, welk endpoint, welke frontend, welke
   tests. Nog geen code.
3. **Bouw per verticale plak**: database, dan domein, dan admin en API, dan
   frontend, dan tests. Niet eerst overal de helft.
4. **Draai de suite van je domein**, niet de volle suite.
5. **Laat de diff nakijken met `/style`** als de wijziging groot was.
6. **Commit per onderwerp.**

Wisselt het onderwerp echt van domein, begin dan een nieuwe sessie. Doorstapelen
in dezelfde sessie is precies hoe context dichtslibt.

## De zes skills

Een skill kost niets tot je hem aanroept. Hij zet de paden, het testcommando,
de grenzen en de checklist van zijn domein klaar.

| Skill | Roep aan bij | Wat hij klaarzet |
|---|---|---|
| `/shop` | Producten, varianten, opties, collecties, winkelwagen, afrekenen, bestellingen, Mollie, facturen, retourverzoeken, verzending | De twaalf padgroepen van de Shop, de regel dat een request nooit een prijs bepaalt, en dat een order een snapshot is |
| `/blog` | Berichten, categorieën, tags, publiceren en inplannen, het overzicht, de feed | De Blog-paden, de grens met de Shop, en dat testen een container met `MODULE_BLOG_ENABLED=true` vraagt |
| `/content-block` | Een bloktype toevoegen, wijzigen of verwijderen | De acht onderdelen van één blok, het inhoudscontract met zijn drie toestanden, en de valkuilen |
| `/forms` | Formulierdefinities, velden, veldtypes, inzendingen, spam | De Forms-paden, dat validatie over de definitie loopt en niet over het request, en dat het zonder JavaScript moet werken |
| `/admin-endpoint` | Een bestand onder `api/admin/` | Het skelet met de vier guards in de juiste volgorde, en de valkuil rond `page_slug:section_key` |
| `/style` | Nieuwe code schrijven, CMS-teksten schrijven, een diff nakijken | Vijf kernregels plus een afvinklijst; `CODE-STYLE.md` is het volledige verhaal |

### Wanneer maak je er een bij

Pas wanneer je dezelfde procedure voor de derde keer uitlegt. Kandidaten die
zich waarschijnlijk aandienen: meertaligheid, media, thema, een nieuwe module
opzetten, en een migratie schrijven. Maak ze niet vooruit.

## De vier path-regels

Deze komen vanzelf in beeld. Je hoeft er niets voor te doen.

| Regel | Matcht op | Waarover |
|---|---|---|
| `php-style.md` | `**/*.php` | Taal, `strict_types`, `final`, SQL in de repository, de vier beveiligingsregels |
| `shop.md` | Twaalf globs over `src/Service`, `src/Repository`, `admin`, `api`, de routes en `assets/*/shop` | De Shop-grenzen |
| `blog.md` | `src/Service/Blog`, `src/Repository/Blog*`, `admin/blog*`, `blog*.php` en verder | De Blog-grenzen |
| `frontend-assets.md` | `assets/css`, `assets/js`, `partials` | Eén eigenaar per bestand, gevraagd via `PageAssets` |

De Shop heeft twaalf globs nodig omdat zijn bestanden plat verspreid staan
tussen die van Core. Dat is de beste aanwijzing die we hebben dat de Shop ooit een
eigen map verdient. Zie "Wat er open staat".

## De zestien mapregels

Elke map die al een domein ís, heeft een eigen `CLAUDE.md` van acht tot vijftien
regels. Die laadt zodra je een bestand in die map opent, en staat naast de code
die hij beschrijft.

```text
api/admin/                     de vier guards, het PRG-patroon, de sectievalkuil
db/migrations/                 forward-only, signed/unsigned, de bootstrap-migratie
src/Install/                   InstallState vs SetupWizard vs FreshSiteCopyPolicy
src/Module/                    het register, de configuratieketen, ModuleGuard
src/Service/Blocks/            de blokdefinitie als integratiecontract
src/Service/Blog/              de modulegrens
src/Service/Forms/             Core, validatie over de definitie
src/Service/Language/          de drie onafhankelijke taalstaten
src/Service/Media/             Core, usage-providers, waar bestanden staan
src/Service/PageTemplates/     alleen bij aanmaken, nooit een paginatype
src/Service/Personalization/   hangt van de Shop af, snapshot op de orderregel
src/Service/Redirects/         één opzoekpunt, uitgeschakelde modules
src/Service/Shipping/          deelgebied binnen de Shop
src/Service/Theme/             publieke site versus AdminTheme
src/Service/Translation/       het providercontract, niet DeepL
src/Update/                    het eigendomscontract, één versie, release.json als commitpunt
```

## Alle documenten

Lees er **één** per taak. De wegwijzer in `CLAUDE.md` vertelt welke.

### Beginnen en overzicht

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `CLAUDE.md` | 132 | Nooit handmatig. Hij laadt vanzelf |
| `WORKFLOW.md` | dit bestand | Je wilt iets opzoeken over de opzet, of er iets aan toevoegen |
| `PROJECT-MAP.md` | 274 | De wegwijzer helpt je niet verder en je wilt de volledige kaart |
| `README.md` | 199 | Docker, database, lokaal draaien, meerdere installaties naast elkaar, deployen |
| `CODE-STYLE.md` | 147 | Je schrijft nieuwe code of teksten voor de beheerder |
| `TESTING.md` | 702 | Tests draaien of toevoegen |

### Domeinen

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `MODULES.md` | 437 | Domeingrenzen, "waar hoort dit thuis", een module toevoegen of uitzetten, of werk aan de Shop |
| `CONTENT-BLOCKS.md` | 394 | Een content-blok toevoegen of wijzigen |
| `BLOG.md` | 386 | Alles rond de Blog |
| `FORMS.md` | 532 | Formulieren en inzendingen |
| `MULTILINGUAL.md` | 149 | Router voor talen van de site, CMS-taal, bewerktaal en automatisch vertalen; hij wijst het document in `docs/multilingual/` aan |
| `MEDIA.md` | 346 | De Mediabibliotheek |
| `PAGE-EDITOR.md` | 369 | Blokkenkiezer, catalogus, opslagbalk, tabbladen, inklapbare rijen |
| `ADMIN-UI.md` | 245 | Uitleg bij velden, de help-knop, infobalk, en zoekveld, select, checkbox, switch, bestandskiezer en knoppen in het CMS |
| `PAGE-TEMPLATES.md` | 272 | Een nieuw paginasjabloon |
| `THEMING.md` | 227 | Kleuren, lettertypes, knopvorm, logo's, dashboard-uiterlijk |
| `SEO.md` | 387 | Titels, meta description, canonical, sitemap, robots |
| `REDIRECTS.md` | 327 | Een oude URL die moet blijven werken |
| `HEADER-FOOTER.md` | 302 | Header-knop, footer-slotregel, social profielen, het kruimelpad |

### Meertaligheid in detail

Open deze via `MULTILINGUAL.md`, en dan alleen het document dat je taak raakt.

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `docs/multilingual/CMS-LANGUAGE.md` | 136 | Tekst die een beheerder leest: catalogi, statuswoorden, zijbalk- en registerlabels, *Mijn account* |
| `docs/multilingual/EDITING-LANGUAGE.md` | 205 | De bewerktaal: de schakelaar in de schil en `_nl`/`_en`-velden in een editor of schrijf-endpoint |
| `docs/multilingual/WEBSITE-LANGUAGES.md` | 183 | Wat een bezoeker ziet: talenregister, hoofdtaal, terugvalregel, publieke taalwissel |
| `docs/multilingual/AUTOMATIC-TRANSLATION.md` | 147 | Automatisch vertalen: providercontract, DeepL, vertaalstatus |
| `docs/multilingual/MIGRATIONS.md` | 113 | De migraties van de meertaligheid en wat een bestaande site daarvan merkt |
| `docs/multilingual/TESTS.md` | 61 | Welke test de meertaligheid bewaakt en welke suite je draait |

### Installatie

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `INSTALL-BOOTSTRAP.md` | 244 | Wat een verse installatie aanmaakt en wat een bestaande behoudt |
| `SETUP.md` | 555 | De installatiewizard, de basis-URL, een nieuwe site (clone) beginnen |

### Updates en releases

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `docs/updates/ARCHITECTURE.md` | 483 | De ingebouwde updater: eigendomsgrens, versies, beveiliging, stappen, onderhoud, migraties, back-up |
| `docs/updates/RELEASES.md` | 236 | Een release maken en publiceren, de releasesleutel, een installatie overzetten naar het releasemodel |
| `docs/updates/RECOVERY.md` | 169 | Een update is mislukt of onderbroken, of een site moet met de hand hersteld worden |

### Achtergrond

Lees deze **niet** standaard. Ze beantwoorden "waarom", niet "hoe".

| Document | Regels | Lees dit wanneer |
|---|---|---|
| `docs/content-blocks/DECISIONS.md` | 186 | Je raakt een architecturale keuze rond blokken |
| `docs/content-blocks/ARCHITECTURE.md` | 158 | Je wilt weten waarom het blokkenmodel zo is |
| `docs/content-blocks/README.md` | 31 | De leesroute voor die map |
| `docs/media/PUBLIC-URLS.md` | 108 | Je overweegt een leesbaar publiek adres voor media: de audit, de opties en het voorstel |

### Vier verwijzingen die nergens heen gaan

`MAIN.MD`, `docs/CMS_CONTENT_AUDIT.md`, `docs/content-blocks/ROADMAP.md` en
`PHASE-1.md` tot en met `PHASE-4.md` bestaan niet in deze repository. Ruim
honderd docblocks noemen er een, en dat is bewust zo gelaten: die zinnen leggen
uit waarom iets werkt zoals het werkt, en de historische bron erbij noemen kost
niets zolang je weet dat je hem niet hoeft te openen. Ga er nooit naar zoeken.

## Waar zet ik nieuwe kennis neer

Dit is de vraag die de opzet gezond houdt. Zet iets in één laag, nooit in twee:
twee regels die elkaar tegenspreken maken allebei minder indruk.

| Wat je hebt geleerd | Waar het hoort |
|---|---|
| Geldt altijd, in elk domein, in één zin | `CLAUDE.md` |
| Geldt voor bestanden die je aan een glob herkent | Een path-regel |
| Geldt voor één map | De `CLAUDE.md` van die map |
| Is een procedure van meerdere stappen | Een skill |
| Is de volledige uitleg met redenering | Het domeindocument |
| Is waarom een keuze ooit zo gemaakt is | `docs/content-blocks/DECISIONS.md` of het domeindocument |

Twee vuistregels. `CLAUDE.md` blijft onder de tweehonderd regels, want langer
betekent dat er minder van wordt opgevolgd. En wijkt de code af van een
document, dan heeft de code gelijk: pas het document aan.

## Testen

Dertien suites: `unit`, `contract`, `fast`, `blocks`, `cms`, `shop`, `blog`,
`modules`, `personalization`, `analytics`, `http`, `migration` en `full`.
Formulieren hebben geen eigen suite en zitten in `cms`. Meertaligheid ook niet,
want het is Core en raakt elk domein; die tests zitten in `fast` en `cms`.

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite shop
```

Draai de suite van je domein, en daarna `fast`. De volle suite alleen bij een
grote wijziging.

### Vier valkuilen die je een half uur kosten

**Draai in `php_test`, niet in `php`.** De ontwikkelcontainer
heeft modules uitstaan. `unit` en `contract` hebben geen database nodig maar
lezen wél het moduleregister, dus een uitgeschakelde Shop of Blog neemt zestien
tests mee die niets met je wijziging te maken hebben. `TESTING.md` noemt ze bij
naam.

**De volle suite is nu niet groen, en dat lag er al.** Op de huidige
testdatabase geeft `full` 7 failures, en die komen alle zeven uit de omgeving
waarin je draait: `APP_ENV`, `APP_URL`, de `MODULE_*`-vlaggen,
`SHOP_NOTIFICATION_EMAIL` en de beheerdershash uit `.env`. Geen ervan leunt op
inhoud van de testdatabase. Vergelijk bij twijfel met
`main` voordat je denkt dat jij iets kapot hebt gemaakt.

**De HTTP-tests hebben een draaiende webcontainer nodig.** Zonder
`docker compose --profile test up -d` slaan ze zichzelf over in plaats van te
falen, dus een groene run zegt dan minder dan je denkt. Datzelfde profiel
levert `php_test`: een kale `docker compose up -d` start hem niet.

**Een worktree heeft geen `vendor/`.** Hij is gitignored, dus een verse
uitchecking mist hem en PHPUnit start er niet. Zet er zijn eigen
dependencies naast en maak er vooral geen symlink naar een andere worktree
van; `TESTING.md` legt uit waarom dat je stilzwijgend de verkeerde code laat
testen.

## Veelvoorkomende taken

**Een content-blok toevoegen.** `/content-block`. Acht bestanden plus één
regel in `BlockDefinitions`, of in `blockDefinitions()` van de module die het
blok bezit. Alle methodes van `BlockDefinition` zijn `abstract`, dus je kunt er
geen vergeten. Test met `--testsuite blocks`.

**Een admin-endpoint toevoegen.** `/admin-endpoint`. Kopieer er een uit
dezelfde familie, schrijf er nooit een vanaf nul. Vier guards in volgorde:
login, permissie, POST, CSRF. Daarna pas lezen of schrijven.

**Een migratie schrijven.** `docker compose exec php php vendor/bin/phinx
create MyNewMigration`. Forward-only, idempotent, MySQL-compatibel. Let op
signed en unsigned bij foreign keys, daar is het één keer op misgegaan.

**Een module toevoegen.** `MODULES.md`, hoofdstuk "Een module toevoegen". Vijf
stappen, waarvan één regel in `ModuleRegistry::MAP`. Vergeet de variabele in
`.env.example` niet.

**Een nieuwe site beginnen met deze codebase.** `SETUP.md`, "Een nieuwe site
beginnen". Een nieuwe site is een clone van deze repository met een eigen
`.env`, database, uploads en poorten, geen fork.
`scripts/create_fresh_site_copy.php` en `FreshSiteCopyPolicy` heb je alleen
nodig voor een kopie zonder site-inhoud.

## Wat er open staat

Eén punt dat bewust is blijven liggen. Het is niet urgent, maar verdient een
eigen sessie.

**Er is geen `.env` in de checkout.** Alleen `.env.example`. Het
compose-bestand heeft op drie plekken een verplichte `env_file: .env`, dus een
verse `docker compose up -d` faalt. De draaiende containers werken nog op
instellingen uit een `.env` die er ooit was. De omgeving is dus niet opnieuw op
te bouwen uit de repository. Wie hem terugzet, neemt ook de drie poorten uit
`.env.example` over (`APP_PORT`, `ADMINER_PORT`, `MAILPIT_WEB_PORT`); zonder
die drie vraagt deze installatie 8000, 8080 en 8025, en botst hij met elke
andere installatie die daar al draait.

## De praktijktest is gedaan

Twee `/shop`-sessies: eerst een leesonderzoek naar openstaande punten, daarna
één echte wijziging — `OrderRepository::findForExport()` kapte de
artikelomschrijving van de boekhoudexport af op de 1024 bytes van
`GROUP_CONCAT`.

Beide bleven binnen het domein. De enige bestanden buiten de Shop die nodig
waren, waren er om een afhankelijkheid te controleren: `Repository.php` en
`Database.php` voor de vorm van de query, `MediaUsageRegistry` en
`AdminNavigation` om te toetsen of een vermoeden wel over de Shop ging.

Wat er niet klopte, was steeds één pad of één alinea, en nooit iets groters:

- drie admin-endpoints vielen buiten de globs van `shop.md` en `/shop`:
  `update-fulfilment-status.php`, `update-withdrawal-request-status.php` en
  `sync-postnl-rates.php`;
- de retourverzoeken stonden niet in het Shop-hoofdstuk van `MODULES.md`,
  terwijl `ShopModule` er een adminsectie voor bijdraagt;
- het testcommando noemde een container die achter een compose-profiel zit;
- een worktree heeft geen `vendor/`, en dat stond nergens.

Alle vier zijn verwerkt. Eén vraag bleef lastig: welke instructielagen er nu
echt geladen waren, viel alleen te beantwoorden door het de sessie zelf te
vragen. Daarom schrijft de hook het sindsdien op. Zie "Nagaan wat er echt
geladen is".

Nu is de grotere stap aan de beurt: de vraag of de Shop een eigen map
verdient. Die doe je omdat de software er begrijpelijker van wordt, niet omdat
een glob lelijk is.
