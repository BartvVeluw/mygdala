# Mygdala — wegwijzer

Eigen PHP 8.2 / MySQL-applicatie: één installatie die tegelijk CMS, webshop
en adminpaneel is. Geen framework, geen buildstap, geen JS-framework. Losse
PHP-templates in de root, `App\`-klassen onder `src/` (PSR-4), platte CSS/JS.
Draait op Vimexx gedeelde hosting, dus geen Node.js en geen buildketen.

De projectroot is de siteroot. Alles wat niet publiek mag zijn staat buiten de
webroot of wordt door `.htaccess` geblokt.

## Contextregels (de belangrijkste regels van dit bestand)

Deze repository heeft 21 documenten en bijna 100.000 regels PHP. Alles lezen
is nooit nodig en meestal schadelijk.

1. **Lees precies één domeindocument per taak**, dat uit de tabel hieronder.
   Nooit twee, tenzij de taak aantoonbaar op de grens ligt.
2. **Blijf binnen de paden van je domein.** Werk je aan de Shop, open dan
   geen Blog-bestand. Werk je aan een blok, open dan niet de hele
   `src/Service/`.
3. **Zoek met `Grep`/`Glob`, niet met `Read`.** Lees een bestand pas als je
   weet dat je het nodig hebt. Voor een brede zoektocht over veel mappen:
   gebruik de `Explore`-subagent, zodat de bestandsdumps niet in deze sessie
   belanden.
4. **Lees `PROJECT-MAP.md` alleen als de tabel hieronder je niet verder
   helpt.** Het is de volledige kaart, en die kost ongeveer 6000 tokens.
5. **Eén sessie per domein.** Wisselt het onderwerp echt van domein, begin
   dan een nieuwe sessie in plaats van door te stapelen.
6. **Wijkt de code af van een document, dan heeft de code gelijk.** Pas het
   document aan, nooit de code aan het document.

## Welk document, en welke paden

Bij de meeste taken is er ook een **skill** die de paden, het testcommando en
de checklist al klaarzet. Roep die eerst aan.

| Taak | Skill | Document |
|---|---|---|
| Webshop: producten, varianten, collecties, bestellingen, betalingen, facturen, verzending | `/shop` | `MODULES.md` |
| Een content-blok toevoegen of wijzigen | `/content-block` | `CONTENT-BLOCKS.md` |
| Blog: berichten, categorieën, tags, publiceren, de feed | `/blog` | `BLOG.md` |
| Formulieren: definities, velden, inzendingen, spam | `/forms` | `FORMS.md` |
| Een admin-schrijfendpoint toevoegen of wijzigen | `/admin-endpoint` | — |
| Schrijfstijl van code, commentaar en CMS-teksten | `/style` | `CODE-STYLE.md` |
| Blokkenkiezer, catalogus, opslagbalk, tabbladen, inklapbare rijen | — | `PAGE-EDITOR.md` |
| Uitleg bij velden, help-knop, infobalk, zoekveld, select, checkbox, switch, bestandskiezer in het CMS | — | `ADMIN-UI.md` |
| Domeingrenzen, "waar hoort dit thuis", een module toevoegen of uitzetten | — | `MODULES.md` |
| Talen van de site, CMS-taal, bewerktaal, automatisch vertalen | — | `MULTILINGUAL.md` |
| Kleuren, lettertypes, knopvorm, logo's, dashboard-uiterlijk | — | `THEMING.md` |
| Afbeeldingen uploaden, hergebruiken, alt-teksten, verwijderen | — | `MEDIA.md` |
| Een nieuw paginasjabloon | — | `PAGE-TEMPLATES.md` |
| Wat een verse installatie aanmaakt | — | `INSTALL-BOOTSTRAP.md` |
| De installatiewizard, de basis-URL, een nieuwe site (clone) beginnen | — | `SETUP.md` |
| Header-knop, footer-slotregel, social profielen, het kruimelpad | — | `HEADER-FOOTER.md` |
| Titels, meta description, canonical, sitemap, robots | — | `SEO.md` |
| Een oude URL die moet blijven werken, een pagina hernoemen | — | `REDIRECTS.md` |
| Tests draaien of toevoegen | — | `TESTING.md` |
| Docker, database, lokaal draaien, meerdere installaties naast elkaar | — | `README.md` |
| Iets toevoegen aan deze opzet: een skill, een regel, een document | — | `WORKFLOW.md` |
| Waaróm werkt een blok zo | — | `docs/content-blocks/DECISIONS.md` |

## Hoe de instructies in dit project geladen worden

Vier lagen, elk met een eigen moment. Zet een instructie in de laag die bij
zijn reikwijdte past, en herhaal hem niet in een tweede laag: twee regels die
elkaar tegenspreken maken allebei minder indruk.

| Laag | Wanneer | Waarvoor |
|---|---|---|
| Dit bestand | Elke sessie | De routering en de regels die altijd gelden |
| `.claude/rules/*.md` met `paths:` | Zodra je een bestand opent dat matcht | Grenzen en conventies van een domein dat je aan een glob herkent |
| `CLAUDE.md` in een submap | Zodra je een bestand in die map opent | Domeinen die een eigen map hebben |
| `.claude/skills/*/SKILL.md` | Alleen als je hem aanroept | Recepten, checklists en de volledige padenlijst van een domein |

**Een regel is reactief, een skill is preventief.** Een path-rule vuurt pas
nadat je het bestand al geopend hebt, dus hij stuurt je gedrag *in* een
domein. Hij kan niet voorkomen dat je in het verkeerde domein begint. Dat is
precies wat de skill wel doet, en daarom begin je een taak met de skill.

## Vier verwijzingen die nergens heen gaan

`MAIN.MD`, `docs/CMS_CONTENT_AUDIT.md`, `docs/content-blocks/ROADMAP.md` en
`PHASE-1.md` t/m `PHASE-4.md` bestaan **niet in deze repository**. Ruim 100
docblocks noemen er een. Dat is historie uit de Van Veluw-repository of uit
een afgeronde refactor. **Ga er nooit naar zoeken en vraag er niet om.** Zie
`PROJECT-MAP.md`, laatste hoofdstuk.

## Commando's

Alles draait in Docker, en elke clone van deze repository is een eigen
installatie met eigen containers, database en poorten (`README.md`). Geef
commando's vanuit de map van de installatie en noem de service, niet de
container: `php` (ontwikkeling), `php_test` (tests) en `php_cms` (dezelfde
code met de Shop uit). De laatste twee zitten achter het profiel `test` en
starten niet vanzelf.

```bash
docker compose up -d
docker compose --profile test up -d
docker compose exec php_test php vendor/bin/phpunit --testsuite fast
docker compose exec php php vendor/bin/phinx create MyNewMigration
```

Werk je in een worktree, dan heeft die eerst zijn eigen `vendor/` nodig, en
noem je de compose-file van de hoofduitchecking met `-f`. Zie `TESTING.md`.

Draai de tests in `php_test`. De ontwikkelcontainer heeft modules
uitstaan, en `fast` faalt daar op zestien tests die niets met je wijziging te
maken hebben. `TESTING.md` legt uit welke dat zijn.

Draai na een wijziging **de suite van je domein**, niet de volle suite. De
dertien suites zijn `unit`, `contract`, `fast`, `blocks`, `cms`, `shop`,
`blog`, `modules`, `personalization`, `analytics`, `http`, `migration` en
`full`. Formulieren hebben geen eigen suite en zitten in `cms`. `fast` is
`unit` + `contract`, heeft niets nodig en mag altijd. Zie `TESTING.md`.

## Vaste regels van dit project

- **Migraties zijn forward-only**, idempotent en MySQL-compatibel. Er zijn
  geen uninstall-migraties en een module uitzetten raakt de database nooit.
- **Registers zijn expliciete, gesloten lijsten.** `BlockDefinitions`,
  `ModuleRegistry`, `BlockCategories`, `BlockPreview`, `RouteRegistry`: geen
  mapscan, geen reflectie, geen klassenaam uit een request of een
  databaserij.
- **Elk frontendbestand heeft één eigenaar** die er zelf om vraagt via
  `App\Service\PageAssets`. Geen globale `style.css`, geen handgeschreven
  `<link>` of `<script>` in een template.
- **Elk admin-schrijfendpoint** volgt dezelfde vier guards in deze volgorde:
  login, permissie, POST-check, CSRF. Daarna pas lezen of schrijven.
- **Alle output door `htmlspecialchars()`**, URL's root-relatief (`/assets/…`).
- **Prozadocumentatie is Nederlands, code en commentaar Engels,
  CMS-teksten voor de redacteur Nederlands.** Zie `CODE-STYLE.md`.
