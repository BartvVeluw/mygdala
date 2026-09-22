# Installatie (Setup Wizard)

Wanneer een nieuwe installatie een wizard te zien krijgt, wat hij vraagt, wat
hij ermee doet, en waar je diezelfde dingen daarna beheert. Wijkt de code af
van dit document, dan heeft de code gelijk — pas het document aan.

Begin bij [`PROJECT-MAP.md`](PROJECT-MAP.md) als je nog niet weet waar iets
staat, en bij [`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md) voor wat een
verse database überhaupt aanmaakt.

## Waar dit over gaat

Een verse installatie moest tot nu toe met de hand worden bijgewerkt: rijen in
`site_settings`, een `.env` aanpassen, en dan hopen dat je niets vergat. De
Setup Wizard vervangt dat door één scherm dat de nieuwe eigenaar zelf invult.

```text
verse installatie
   → inloggen
   → Installatie (5 stappen)
   → afronden
   → gewoon dashboard
```

**Hij is onboarding, geen instellingenscherm.** Alles wat hij schrijft heeft
een vaste plek elders in het CMS, en hij schrijft er ook echt naartoe. Na
afronden verdwijnt hij en wordt het scherm een lijstje links naar die plekken.

## Wie hem ziet, en wie nooit

Er zijn twee vragen, en de tweede bouwt op de eerste:

| Vraag | Wie beantwoordt hem | Antwoord |
|---|---|---|
| Wat voor database is dit? | `App\Install\InstallState` | `fresh_generic_install` of `legacy_existing_site` |
| Is de wizard al afgerond? | `App\Install\SetupState` | `setup_completed_at` staat er wel of niet |

Beide wonen in dezelfde tabel `install_state`, die key/value is en **alleen
bestaat op een database die vanaf nul is opgebouwd**. Er is dus geen tweede
tabel, geen tweede markering en geen migratie voor de wizard nodig:

```text
state_key = 'install_kind'        'fresh_generic_install'
state_key = 'setup_completed_at'  afwezig tot de wizard klaar is
```

```text
SetupState::isSetupRequired()
    = InstallState::kind() is fresh   én   setup_completed_at ontbreekt
```

**Een bestaande installatie is per definitie al ingericht.** Zij heeft de
tabel `install_state` niet, dus zij kan ook geen afrondingsstempel dragen.
Dat leest niet als "nog niet ingesteld" maar als **al ingesteld** — dezelfde
veilige richting die `InstallState` zelf kiest. Hetzelfde geldt voor elke
storing: een onbereikbare database, een ontbrekende tabel, een kapotte rij.
`isSetupRequired()` antwoordt in al die gevallen `false`, zodat een
databaseprobleem nooit een werkend CMS achter een wizard kan zetten.

**De omleiding zit op één plek**: `App\Service\AdminAuth::requireLogin()`.
Elke adminpagina komt daar langs (`requirePermission()` roept hem aan), dus er
is één poort in plaats van een regel in veertig schermen. Uitgezonderd zijn
`setup.php` zelf (anders is het een kringetje), `login.php` en `logout.php`.

**Alleen HTML-pagina's worden omgeleid, de admin-API's niet.** Dat is met
opzet: de wizard heeft de endpoints van de Mediabibliotheek nodig terwijl hij
draait, een onafgeronde installatie is geen rechtengrens, en elk van die
endpoints controleert zelf al zijn permissie en CSRF-token.

## De vijf stappen

Eén formulier, één keer verstuurd. Zonder JavaScript staan alle stappen
gewoon onder elkaar en werkt dezelfde knop.

### 1. Website

| Veld | Verplicht | Gaat naar |
|---|---|---|
| Naam van de site | **ja** | `site_settings.site_name` |
| Taal van de website | nee | de standaardtaal in `site_languages` — zie `MULTILINGUAL.md` |
| Publiek webadres | nee | `site_settings.canonical_base_url` — zie hieronder |
| Contact-e-mailadres | nee | `site_settings.email` |
| Korte omschrijving | nee | `site_setting_translations`: `footer_description` in de gekozen standaardtaal |
| Plaats, KVK-nummer | nee | `site_setting_translations`: `city` in de gekozen standaardtaal; `site_settings.kvk_number` |

**Taal van de website** is de standaardtaal: de taal die een bezoeker eerst
krijgt en waarop een ontbrekende vertaling terugvalt. Nederlands en Engels zijn
er daarna allebei; de wizard kiest alleen welke van de twee de standaard is,
en dat kan later nog bij Instellingen → Talen. De taal van het **CMS** is iets
anders — die kiest elke beheerder voor zichzelf bij Mijn account
(`MULTILINGUAL.md`).

De naam van de site is het **enige** verplichte antwoord in de hele wizard, en
Site-instellingen houdt zich aan hetzelfde contract
(`App\Service\SiteSettingsValidator`).
Alles wat leeg blijft, blijft leeg: de footer laat de regel weg,
`App\Mail\EmailIdentity` laat het onderdeel weg in plaats van een losse
scheiding te tonen, en de factuur slaat de regel over. Bedrijfsgegevens
uitvragen die een site misschien niet heeft is precies wat de opruiming van
een verse installatie eruit haalde.

### 2. Merk

Logo, tweede logo, favicon en deel-afbeelding, allemaal optioneel, allemaal
via de gewone mediakiezer (`MEDIA.md`). Wat je hier uploadt komt in de
Mediabibliotheek te staan en is daarna overal herbruikbaar.

Er is **geen standaardlogo**. Zonder logo toont de koptekst de sitenaam als
tekst, zonder favicon komt er geen `<link rel="icon">`, en zonder
deel-afbeelding geen `og:image` (`THEMING.md`). Een verse installatie heeft
dus een lege Mediabibliotheek in plaats van het merk van iemand anders.

### 3. Vormgeving

Dezelfde zeven instellingen als het scherm Vormgeving: vijf kleuren, één
lettertypecombinatie uit de gesloten lijst, en de knopvorm. Ze gaan door
`App\Service\Theme\ThemeSettings::validate()` — er is **geen tweede
thema-engine** en de wizard verzint geen eigen regel over wat een kleur mag
zijn.

Wat je niet aanraakt wordt niet opgeslagen: een ontbrekende rij betekent de
meegeleverde standaard, en dat is wat een verse installatie meteen coherent
maakt (`THEMING.md`).

### 4. Onderdelen

Eén vinkje per first-party module — vandaag Shop, Personalisatie, Blog,
Portfolio en Meertaligheid. Een vinkje staat zoals de installatie het nu wil
(`ModuleConfig::wants()`), dus op een verse installatie staan de Blog,
Portfolio en Meertaligheid uit. Zonder Meertaligheid publiceert de site alleen
zijn standaardtaal; talen toevoegen en aanzetten gaat daarna onder
*Site-instellingen → Talen* (`docs/multilingual/WEBSITE-LANGUAGES.md`). Zie [Modules](#modules-vanuit-het-cms) hieronder voor wat er
opgeslagen wordt en waarom `.env` er nog steeds bovenop gaat.

Personalisatie hangt van de Shop af, en dat wordt hier **gemeld in plaats van
stilletjes toegepast**: `ModuleRegistry` zou Personalisatie vanzelf uitzetten,
maar wie beide vinkjes aanzet verdient te horen waarom er één niet kan.

### 5. Startpagina's

Optioneel, en niets staat voorgevinkt:

| Keuze | Sjabloon | URL |
|---|---|---|
| Over ons | `about` | `/over-ons` |
| Diensten | `services` | `/onze-diensten` |
| Contact | `contact` | `/contact-opnemen` |

Elke gekozen pagina wordt gemaakt door dezelfde
`PageTemplateInstaller` die *Nieuwe pagina* gebruikt, komt als **concept** in
het pagina-overzicht en krijgt een menu-item. Nergens wordt vastgelegd dat hij
uit de wizard komt — precies zoals nergens staat uit welk sjabloon een pagina
komt (`PAGE-TEMPLATES.md`). De homepage bestaat al en wordt niet aangeraakt.

**Waarom `/onze-diensten` en niet `/diensten`.** Deze codebase levert nog
steeds `contact.php`, `diensten.php`, `portfolio.php` en `over-mij.php` in de
projectroot voor de installatie wier URL's dat zijn, dus `App\Service\ReservedRoutes`
reserveert die woorden overal: een CMS-pagina op `/contact` zou voorgoed
achter dat bestand verdwijnen. Elke startpagina heeft daarom een tweede naam
die wél vrij is, en `SetupWizard::plannedSlug()` is de enige plek die kiest —
zodat het vinkje het adres toont dat er echt komt.

**Er komt geen Portfolio-optie** zolang er geen generiek Portfolio-sjabloon
is. Een vinkje aanbieden dat niet waargemaakt kan worden is erger dan het niet
aanbieden.

**Geen footerkolommen.** Een lege footer op een nieuwe site ziet er bedoeld
uit; een verzonnen footer niet.

## Wat afronden precies doet

De volgorde ligt vast, en het stempel is het állerlaatste:

```text
1. alles valideren, en stoppen bij de eerste fout
2. instellingen, merk, vormgeving en modules — in één transactie
3. de gekozen startpagina's aanmaken, elk op zichzelf atomair
4. een menu-item per pagina die deze keer echt is aangemaakt
5. setup_completed_at schrijven
```

Alles wat vóór stap 5 misgaat laat de installatie **onafgerond**, dus de
eigenaar komt terug in de wizard en niet op een site die beweert ingericht te
zijn.

Stap 3 en 4 kunnen niet in de transactie van stap 2 meedoen:
`PageTemplateInstaller` opent zijn eigen transactie en PDO kent geen geneste
transacties (`PAGE-TEMPLATES.md`). In plaats daarvan is elke stap
**idempotent**: een pagina waarvan de slug al bestaat wordt overgeslagen, en
een menu-item komt er alleen voor een pagina die deze run heeft gemaakt.
Opnieuw afronden na een storing maakt het werk dus af in plaats van het te
verdubbelen.

Wat wél kan achterblijven: een afbeelding die je in stap 2 hebt geüpload staat
al in de Mediabibliotheek. Dat is geen half afgeronde installatie maar een
gewoon media-item, en het hoort daar thuis.

De endpoint weigert bovendien een tweede keer te draaien
(`api/admin/complete-setup.php` vraagt `SetupState::isSetupRequired()` vóór hij
naar de inzending kijkt), dus een herhaalde POST kan een ingerichte site niet
opnieuw configureren.

## Modules vanuit het CMS

Tot deze stap was `MODULE_<KEY>_ENABLED` in `.env` het enige antwoord
(`MODULES.md`). Dat blijft het juiste antwoord voor een deployment die een
`.env` heeft die zij kan bewerken — maar iemand die een nieuwe site via het CMS
inricht kan daar niet bij, en dat vragen is precies het handwerk dat deze stap
weghaalt.

De ketting staat op één plek, `App\Module\ModuleConfig`:

```text
1. MODULE_<KEY>_ENABLED in de omgeving, als hij gezet en niet leeg is
2. de voorkeur die in het CMS is opgeslagen (module_settings)
3. de eigen standaard van de module: aan, behalve voor de Blog, Portfolio en
   Meertaligheid
```

**De omgeving wint dus nog steeds.** Een hostingaccount dat zijn modules in
`.env` vastzet kan ze niet vanuit het CMS laten wijzigen, en de
`php_cms`-testcontainer met `MODULE_SHOP_ENABLED=false` beslist onveranderd.
De wizard toont zo'n vinkje uitgeschakeld, met de naam van de variabele erbij.

`module_settings` is een eigen key/value-tabel naast `site_settings` en
`theme_settings`, om dezelfde reden als die twee uit elkaar staan: dit is
deploy-configuratie, geen identiteit en geen vormgeving. Een ontbrekende rij
betekent "niets gekozen", zodat een bestaande installatie niets merkt.

`App\Module\ModuleSettings` is alleen opslag: hij weet niet wat een webshop
is, lost geen afhankelijkheid op en beslist niet of een module draait. Dat
blijft `ModuleRegistry`.

## Het publieke webadres

`App\Service\AppUrl` bepaalt de canonieke links, `og:url` en de sitemap. Er is
één ketting, en die is nu drie stappen lang:

```text
1. APP_URL in .env
2. site_settings.canonical_base_url   ← wat de wizard schrijft
3. https://localhost                  ← "niemand heeft iets gezegd"
```

`.env.example` laat `APP_URL` **leeg**. Hij stond op het productieadres van
deze site, en dat bestand kopieert elke nieuwe installatie naar `.env` — één
`cp` was dus genoeg om canonieke tags, `og:url` en een sitemap te publiceren
die hierheen wijzen. Leeg betekent dat stap 2 en 3 hun werk doen, en stap 3 is
zichtbaar een placeholder in plaats van stilletjes andermans domein.

**Waarom stap 2 erbij is.** `APP_URL` is niet bereikbaar vanuit het CMS op
gedeelde hosting. Voorheen viel `AppUrl` terug op een hardgecodeerd
`https://www.vanveluwlaserdesign.nl`, en `APP_URL` staat in dit project niet
in `.env` — een verse installatie publiceerde dus canonieke tags, `og:url` en
een sitemap die naar Van Veluw Laserdesign wezen. Migratie
`20260910110000` heeft de huidige waarde van deze site als echte rij
vastgezet, precies zoals `20260909210000` dat met de merkbestanden deed, dus
aan de SEO-uitvoer van een bestaande site verandert niets.

**Stap 2 staat met opzet achter stap 1.** Zo blijft er één antwoord op één
vraag: wie `APP_URL` invult beslist, en het CMS toont dat veld dan alleen-lezen
met de uitleg dat het serverconfiguratie is. `AppUrl::source()` zegt hardop
welke stap antwoordde, zodat een scherm niet doet alsof een waarde van de
eigenaar is terwijl iets anders hem overrulet.

**De Host-header wordt nooit geraadpleegd.** Een canoniek adres dat het
verzoek volgt is geen canoniek adres. De wizard toont het adres waarop het
verzoek binnenkwam als *hint* en laat de eigenaar het overtypen.

## Wat het CMS aanmaakt op een verse installatie

Zie [`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md) voor het volledige verhaal.
Sinds deze stap geldt daarbovenop dat `site_settings` op een verse installatie
**leeg blijft** op een handvol generieke schakelaars na: geen naam, geen
e-mailadres, geen KVK, geen plaats, geen logo, geen favicon, geen
deel-afbeelding, geen factuurprefix en geen basis-URL. Elke ontbrekende rij
betekent de generieke code-standaard van `App\Service\SiteSettings`, en de
wizard is waar de eigenaar ze invult.

Omdat er geen merkpaden meer staan, neemt migratie `20260909270000` ook geen
merkbestanden meer over: de Mediabibliotheek van een verse installatie is leeg.

## De eerste beheerder

**De wizard maakt geen account aan, en er zijn geen standaardgegevens.**
De eerste Super Admin komt uit `ADMIN_USERNAME` en `ADMIN_PASSWORD_HASH` in
de `.env` van de server: migratie
`20260908150000_create_admin_users_tables.php` zet die om in de eerste rij
zonder ooit een wachtwoord te verzinnen, en `App\Service\AdminAuth` valt daar
alleen op terug zolang er geen actieve Super Admin ís (break-glass).
Ontbreekt de hash, dan blijft de tabel leeg en kan er niemand inloggen —
dat is de veilige kant. `.env.example` bevat daarom een placeholder die
nadrukkelijk **geen** geldige bcrypt-hash is, en `AdminAuth` weigert een
"hash" die niet met `$` begint.

`Tests\Service\SetupAccessTest` bewaakt dat: geen enkel bestand in het
installatiepad mag een wachtwoord aanmaken, een hash dragen of `admin_users`
aanraken.

## Daarna: waar je wat aanpast

De wizard is er om te beginnen. Er komt géén permanent scherm dat al deze
dingen dubbel doet.

| Wat | Waar |
|---|---|
| Naam, e-mailadres, adres, KVK, logo, favicon, deel-afbeelding | Instellingen → Site-instellingen |
| Talen van de website | Instellingen → Talen (`MULTILINGUAL.md`) |
| Taal van het CMS, per persoon | Mijn account (`MULTILINGUAL.md`) |
| Kleuren, lettertype, knopvorm | Instellingen → Vormgeving (`THEMING.md`) |
| Pagina's maken, bewerken, publiceren | Pagina's (`PAGE-TEMPLATES.md`) |
| Menu, headerknoppen en footer | Header & navigatie en Footer (`HEADER-FOOTER.md`) |
| Afbeeldingen | Mediabibliotheek (`MEDIA.md`) |
| Modules aan/uit | `.env`, of opnieuw via het CMS zodra daar een scherm voor komt |

`admin/setup.php` blijft bereikbaar en toont na afronding precies dat lijstje,
plus wanneer de installatie is afgerond. Hij heeft daarom bewust **geen
menu-item**: een scherm dat je één keer opent hoort niet permanent in de
zijbalk.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite migration
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

| Bestand | Wat het bewaakt | Database nodig |
|---|---|---|
| `tests/Install/SetupWizardValidationTest.php` | Wat de wizard accepteert, weigert en laat vallen: één verplicht veld, lege antwoorden, de basis-URL en wie hem bezit, media-id's, de thema-engine, moduleafhankelijkheden en de gesloten lijst startpagina's | nee |
| `tests/Service/SetupAccessTest.php` | De guards: login, permissie, CSRF, de weigering op een ingerichte installatie, de uitzonderingslijst van de omleiding, en dat er nergens standaardgegevens worden aangemaakt | nee |
| `tests/Module/ModuleConfigurationTest.php` | De ketting omgeving → opgeslagen voorkeur → aan, en dat opslag geen tweede modulestaat wordt | nee |
| `tests/Service/AppUrlTest.php` | De ketting `APP_URL` → instelling → placeholder, wat een basis-URL mag zijn, en dat een niet-ingerichte installatie nooit andermans domein publiceert | nee |
| `tests/Install/SetupCompletionTest.php` | Wat afronden echt bouwt, tegen een wegwerpdatabase vanaf nul: de volgorde, het stempel als laatste, de conceptpagina's, het menu, en dat een geweigerde inzending niets schrijft | ja + MySQL-root |
| `tests/Install/FreshInstallTest.php` | Dat een verse installatie geen enkel bedrijfsgegeven en geen merkbestand krijgt, en weet dat hij nog ingericht moet worden | ja + MySQL-root |
| `tests/Install/LegacyUpgradeTest.php` | Dat een bestaande installatie nooit in de wizard belandt en al haar gegevens, merkbestanden en SEO-uitvoer behoudt | ja + MySQL-root |

Zie verder [`TESTING.md`](TESTING.md).

## Een nieuwe site beginnen

De wizard richt een installatie in. Dit hoofdstuk gaat over de stap dáárvoor:
waar die installatie vandaan komt.

**Een nieuwe site is een clone van deze repository.** Geen fork, geen export
en geen eigen geschiedenis: alle installaties draaien dezelfde code, en wat ze
van elkaar onderscheidt staat in hun eigen `.env`, hun eigen database en hun
eigen uploads.

```text
github.com/BartvVeluw/mygdala
  ├── mygdala/        de ontwikkelomgeving
  ├── mygdala-test/   een verse clone van origin/main: de referentie-installatie
  ├── klant-a/        een clone met een eigen .env, database, uploads en domein
  └── klant-b/
```

Dit is **multi-installatie, geen multi-tenancy**: elke site draait in eigen
containers op een eigen database, en er is geen runtime die twee sites deelt.
Moet een site iets anders doen, dan is dat een instelling, een module of een
blok, nooit een eigen versie van de code.

### Wat elke installatie voor zichzelf heeft

| Wat | Waar | Waarom een andere installatie er niet bij kan |
|---|---|---|
| Containers en netwerk | Docker Compose | Compose noemt alles naar de projectnaam, en dat is de mapnaam: `klant-a-php-1` |
| Database en testdatabase | een eigen `mysql`-service, volume `klant-a_mysql_data` | elke installatie heeft een eigen MySQL-server |
| Bijlagen en personalisatie-uploads | `/var/www/storage`, volume `klant-a_contact_attachments` | een named volume per project |
| Mediabibliotheek, merkbestanden, sectiebeelden, video's, graveerlettertypes | `assets/media/`, `assets/images/branding/`, `assets/images/sections/`, `assets/videos/sections/`, `assets/fonts/personalization/` | gitignored, dus ze horen bij de map en niet bij de commit |
| `vendor/` | in de clone | gitignored |
| Poorten op je machine | `APP_PORT`, `ADMINER_PORT`, `MAILPIT_WEB_PORT` in `.env` | de enige waarden die je zelf uniek maakt |
| Naam, logo, vormgeving, pagina's, modules | de database | de wizard en het CMS schrijven ze; ze horen niet in `.env` en niet in de code |

Welke waarden in `.env` per installatie verschillen:

| Variabele | Per installatie | Waarom |
|---|---|---|
| `APP_PORT`, `ADMINER_PORT`, `MAILPIT_WEB_PORT` | ja, zodra er twee tegelijk draaien | een poort kan maar één keer bezet zijn; bijvoorbeeld 8000/8080/8025 en 8100/8180/8125 |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | ja | MySQL legt ze vast bij de eerste start op een leeg volume |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | ja | de eerste Super Admin, zie "De eerste beheerder" |
| `APP_ENV` | ja | `local` tijdens het bouwen, `production` zodra de site live gaat |
| `APP_URL` | ja, of leeg | leeg laten en het in de wizard invullen mag |
| `MODULE_*_ENABLED` | ja | zie "Welke modules aan" |
| Mail, Mollie, Turnstile, DeepL | ja, zodra de site ze gebruikt | lokaal volstaan de plaatshouders |
| `DB_DATABASE`, `DB_USERNAME`, `TEST_DB_DATABASE` | nee | elke installatie heeft een eigen MySQL-server, dus dezelfde naam botst niet |
| `COMPOSE_PROJECT_NAME` | alleen bij twee clones met dezelfde mapnaam | verder is de mapnaam genoeg |

### Het recept

```bash
# 1. Mygdala clonen, in een map met de naam van de installatie
git clone https://github.com/BartvVeluw/mygdala.git klant-a
cd klant-a

# 2. De omgeving
cp .env.example .env
```

3. **Eigen poorten en geheimen.** Vul in `.env` in wat per installatie
   verschilt (tabel hierboven). Draait er al een installatie op 8000, 8080 en
   8025, geef deze dan andere nummers.
4. **Containers starten.** De eerste keer bouwt dit de PHP-image, en daarna
   doen stap 5 en 6 zichzelf:

   ```bash
   docker compose up -d
   docker compose logs php
   ```

5. **Composer** installeert `vendor/` bij de eerste start van `php`. Alleen
   als dat misging: `docker compose exec php composer install`.
6. **Migraties** draaien bij elke start van `php`. Met de hand:
   `docker compose exec php php vendor/bin/phinx migrate`.
7. **De eerste beheerder en de wizard.** Maak de hash, zet hem als
   `ADMIN_PASSWORD_HASH` in `.env` (elke `$` als `$$`, zie `.env.example`),
   start `php` opnieuw zodat hij `.env` weer inleest, en log in op
   `http://localhost:<APP_PORT>/admin/`. De installatiewizard opent vanzelf.

   ```bash
   docker compose exec php php scripts/generate_admin_hash.php "je-wachtwoord"
   docker compose up -d --force-recreate --no-deps php
   ```

8. **De site inrichten** in het CMS: zie "Daarna: waar je wat aanpast".

Bijwerken naar een nieuwere Mygdala doe je per installatie, en het raakt geen
andere. Een installatie die uit Git komt (zoals deze lokale opzet):

```bash
git pull
docker compose exec php composer install
docker compose exec php php vendor/bin/phinx migrate
```

Een **release-installatie** (uitgepakt uit een releasepakket, met
`release.json` in de root) werk je bij vanuit het CMS: **Instellingen →
Updates**. Zie [`docs/updates/ARCHITECTURE.md`](docs/updates/ARCHITECTURE.md).

Een installatie uit Git wordt geen release-installatie door er alleen een
`release.json` bij te zetten: zolang er een `.git` staat, weigert de updater
haar, en wat afwijkt van de release blokkeert de eerste update. Ook het pakket
over de site heen uitpakken is geen overstap, want alles wat de release niet
heeft blijft dan staan. De overstap gebeurt één keer en gecontroleerd, naar
precies de versie die de installatie al draait: zie
[`docs/updates/RELEASES.md`](docs/updates/RELEASES.md), "Een bestaande
installatie overzetten naar het releasemodel". Daarna doe je op die
installatie geen `git pull` meer.

### Welke modules aan

**De Shop staat standaard AAN, en dat blijft in deze stap zo.** Een generiek
CMS zou beter standaard níet van commercie uitgaan, maar de standaard omzetten
kan hier niet zonder schade: deze deployment heeft geen `MODULE_SHOP_ENABLED`
in zijn `.env` en geen voorkeur in `module_settings`, dus zij draait op precies
die standaard. Hem op `false` zetten haalt bij de eerstvolgende deploy de
winkel uit de lucht. Een site die niets verkoopt zegt dat daarom zelf, in één
regel:

```env
MODULE_SHOP_ENABLED=false
```

Personalisatie hangt van de Shop af en gaat er vanzelf mee uit
(`ModuleRegistry`). De Blog staat al standaard uit (`BLOG.md`).

Portfolio staat op een nieuwe installatie ook uit. Een bestaande installatie
merkt daar niets van: zij draaide Portfolio al voordat het een module werd, en
`20260914170000_pin_the_portfolio_module_where_it_is_in_use` heeft daarom
`module_portfolio_enabled = 1` opgeslagen. Wie het toch uit wil, zet in `.env`:

```env
MODULE_PORTFOLIO_ENABLED=false
```

### Twee installaties in één browser

Een browser bewaart cookies per hostnaam, niet per poort. Open je
`localhost:8000` en `localhost:8100` in dezelfde browser, dan overschrijven de
twee installaties elkaars inlogcookie en word je bij de ene uitgelogd zodra je
bij de andere inlogt. Geef ze elk een eigen hostnaam, bijvoorbeeld
`http://localhost:8000` voor de ene en `http://127.0.0.1:8100` voor de andere.

### Een kopie zonder site-inhoud

**Voor een nieuwe Mygdala-site heb je dit niet nodig**: die clone je, zoals
hierboven. Deze export haalt uit een downstream site zoals Van Veluw
Laserdesign, waar de boom applicatie én site tegelijk is, een kopie van de
applicatie zonder die site. Mygdala zelf draagt geen site-inhoud, dus een
export hieruit is een kopie van de applicatie zoals hij is; de grens
(`App\Install\FreshSiteCopyPolicy`) is voor beide dezelfde.

#### Waarom een kopie en geen opschoning

Een downstream site zoals Van Veluw Laserdesign is twee dingen tegelijk: de
applicatie én de site. In die boom staat de fotografie van dat bedrijf, en
pagina's, producten en portfolio-items wijzen daar met een pad naartoe.
Weggooien om de boom generiek te maken zou dus een draaiende site slopen ten
gunste van een site die nog niet bestaat. De boom blijft daarom heel, en de
export is éénrichtingsverkeer. Mygdala zelf draagt die inhoud niet —
`assets/images/` bevat hier alleen een `.gitkeep` — maar de grens is dezelfde.

`App\Install\FreshSiteCopyPolicy` is de enige plek waar staat wat applicatie
is en wat site is; `scripts/create_fresh_site_copy.php` loopt de boom één keer
door en vraagt die klasse per pad. Wat er níet uit komt:

| Groep | Wat |
|---|---|
| Versiebeheer | `.git` en de machinegebonden delen van `.claude` (worktrees, lokale instellingen, het instructielog) — de nieuwe site krijgt zijn eigen geschiedenis; de skills en gedeelde instellingen gaan wél mee |
| Geheimen en runtime-staat | alles wat `.gitignore` noemt: `.env`, `vendor/`, geüploade media, logs, caches |
| Site-inhoud | `assets/images/**`, `assets/media/**`, `assets/videos/**` — productfoto's, portfolio, de logo's van dit bedrijf, de bronbestanden |
| Site-geschiedenis | `MAIN.MD` en de twee archiefdocumenten onder `docs/` |

De mappen waar de applicatie zelf in schrijft komen leeg terug, met een
`.gitkeep`, want de code verwacht dat ze bestaan — niet dat er iets in staat.
Het script **wijzigt geen enkel bestand**: wat daarna nog naar de bronsite
verwijst komt aan het eind in een lijst te staan voor een mens. Dat zijn de
naam en het domein, en daarnaast het oude bestelnummerprefix `VLD-` en de oude
browseropslagsleutels van het beheer (`FreshSiteCopyPolicy::REVIEW_NEEDLES`
en `REVIEW_NEEDLES_EXACT`). Proza automatisch
herschrijven is gokken, en een half hernoemde site is erger dan een lijst.

Het script loopt over het bestandssysteem en niet over de index van git, zodat
de export getest kan worden waar geen git-client staat — dat is precies waar
de tests van dit project draaien. De prijs: een **untracked** bestand in je
werkkopie gaat gewoon mee. Doe `git status` vóór je exporteert.

#### Het recept van de export

```bash
# 1. Een schone kopie van de applicatie, buiten deze repository. In de
#    container alleen naar een pad in de container: van je machine is daar
#    alleen deze map aangekoppeld. Daarna haal je hem eruit.
docker compose exec php php scripts/create_fresh_site_copy.php /tmp/nieuwe-site
docker compose cp php:/tmp/nieuwe-site ../nieuwe-site
#    (of, met PHP op je eigen machine:)
#    php scripts/create_fresh_site_copy.php ../nieuwe-site

# 2. Eigen versiebeheer
cd ../nieuwe-site && git init && git add -A && git commit -m "chore: applicatie zonder site"
```

Ga daarna verder bij stap 2 van "Het recept" hierboven: de omgeving, eigen
poorten, containers starten en de wizard.

#### Wat je daarna nog met de hand doet

De export noemt aan het eind elk bestand dat deze site nog bij naam noemt. De
meeste daarvan zijn toelichtingen in code die uitleggen waaróm iets zo werkt —
lezen mag, herschrijven hoeft niet. Deze zijn het wél waard:

- `composer.json` — `name` en `description`, als de nieuwe site een eigen
  pakketnaam wil in plaats van `mygdala/cms`;
- `README.md` — het is de ontwikkelaarsdocumentatie van *deze* site;
- de documenten in de root (`PROJECT-MAP.md`, `SETUP.md`, `SEO.md`, …), waar
  deze site als voorbeeld dient.

Migraties staan bewust **niet** in die lijst. Zij zijn historische artefacten
en moeten over jaren nog draaien zoals ze geschreven zijn
([`INSTALL-BOOTSTRAP.md`](INSTALL-BOOTSTRAP.md)); dat ze in een docblock
vertellen wiens geschiedenis ze afspelen is geen lek, en ze op een verse
database niets zaaien is precies wat `Tests\Install\FreshInstallTest` bewaakt.

### Bekende beperking: gereserveerde routewoorden

Deze codebase levert nog steeds `contact.php`, `diensten.php`, `portfolio.php`,
`over-mij.php`, `cookiebeleid.php` en `herroeping.php` in de projectroot, dus
`App\Service\ReservedRoutes` houdt die woorden overal bezet — ook op een site
die er niets mee doet. Een CMS-pagina op `/contact` zou voorgoed achter dat
bestand verdwijnen, dus reserveren is de veilige kant. De wizard werkt daar
al omheen met een tweede naam per startpagina (`/contact-opnemen`).

Dit is een bekende beperking van dit generieke CMS en geen blokkade: de
woorden vrijgeven betekent die bestanden verwijderen, en dat is een
routewijziging die niet vlak vóór de eerste tweede-site-test thuishoort.

## Wat dit bewust niet is

Geen accountaanmaak in de cloud, geen licentieserver, geen provisioning, geen
DNS, geen thema- of pluginmarktplaats, geen voorbeeldproducten, geen
gegenereerde teksten, geen marketing- of analytics-onboarding en geen
instelwizard voor een betaalprovider. Vijf stappen, en daarna het gewone CMS.
