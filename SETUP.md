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
| Taal van de website | nee | `site_settings.primary_content_language` — zie `MULTILINGUAL.md` |
| Publiek webadres | nee | `site_settings.canonical_base_url` — zie hieronder |
| Contact-e-mailadres | nee | `site_settings.email` |
| Korte omschrijving | nee | `site_settings.footer_description_nl` |
| Plaats, KVK-nummer | nee | `site_settings.city_nl`, `kvk_number` |

**Taal van de website** is de hoofdtaal waarin je de inhoud schrijft. Een
tweede taal begint **uit**, en dat is met opzet: een nieuwe site krijgt zo een
eentalige bewerkervaring zonder dubbele velden, en wie wél een vertaling wil
zet die later aan bij Instellingen → Talen. De taal van het **CMS** is iets
anders — die kiest elke beheerder voor zichzelf bij Mijn account
(`MULTILINGUAL.md`).

De naam van de site is het **enige** verplichte antwoord in de hele wizard.
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

Eén vinkje per first-party module — vandaag Shop en Personalisatie. Zie
[Modules](#modules-vanuit-het-cms) hieronder voor wat er opgeslagen wordt en
waarom `.env` er nog steeds bovenop gaat.

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
3. aan
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
| Menu en footer | Navigatie en Footer (`HEADER-FOOTER.md`) |
| Afbeeldingen | Mediabibliotheek (`MEDIA.md`) |
| Modules aan/uit | `.env`, of opnieuw via het CMS zodra daar een scherm voor komt |

`admin/setup.php` blijft bereikbaar en toont na afronding precies dat lijstje,
plus wanneer de installatie is afgerond. Hij heeft daarom bewust **geen
menu-item**: een scherm dat je één keer opent hoort niet permanent in de
zijbalk.

## Testen

```bash
docker exec mygdala_php      php vendor/bin/phpunit --testsuite fast
docker exec mygdala_php_test php vendor/bin/phpunit --testsuite migration
docker exec mygdala_php_test php vendor/bin/phpunit --testsuite cms
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

## Een tweede site beginnen

De wizard richt een installatie in. Dit hoofdstuk gaat over de stap dáárvoor:
hoe je uit deze repository een **kopie zonder deze site** haalt.

> **In Mygdala is die boom al generiek.** Mygdala is de canonieke CMS-bron
> en draagt geen site-inhoud, dus een export hieruit is een kopie van de
> applicatie zoals hij is. De uitleg hieronder beschrijft waaróm de grens
> bestaat en waar hij ligt — die grens (`App\Install\FreshSiteCopyPolicy`)
> geldt onveranderd, ook voor een downstream site als Van Veluw
> Laserdesign, waar de boom wél applicatie én site tegelijk is.

### Waarom een kopie en geen opschoning

Deze repository is twee dingen tegelijk: de applicatie én de site van Van
Veluw Laserdesign. Ongeveer 40 MB ervan is fotografie van dit bedrijf, en
pagina's, producten en portfolio-items wijzen daar met een pad naartoe.
Weggooien om de boom generiek te maken zou dus een draaiende site slopen ten
gunste van een site die nog niet bestaat. De boom blijft daarom heel, en de
export is éénrichtingsverkeer.

`App\Install\FreshSiteCopyPolicy` is de enige plek waar staat wat applicatie
is en wat site is; `scripts/create_fresh_site_copy.php` loopt de boom één keer
door en vraagt die klasse per pad. Wat er níet uit komt:

| Groep | Wat |
|---|---|
| Versiebeheer | `.git` en `.claude` — de nieuwe site krijgt zijn eigen geschiedenis |
| Geheimen en runtime-staat | alles wat `.gitignore` noemt: `.env`, `vendor/`, geüploade media, logs, caches |
| Site-inhoud | `assets/images/**`, `assets/media/**`, `assets/videos/**` — productfoto's, portfolio, de logo's van dit bedrijf, de bronbestanden |
| Site-geschiedenis | `MAIN.MD` en de twee archiefdocumenten onder `docs/` |

De mappen waar de applicatie zelf in schrijft komen leeg terug, met een
`.gitkeep`, want de code verwacht dat ze bestaan — niet dat er iets in staat.
Het script **wijzigt geen enkel bestand**: wat daarna nog "Van Veluw" zegt
komt aan het eind in een lijst te staan voor een mens. Proza automatisch
herschrijven is gokken, en een half hernoemde site is erger dan een lijst.

Het script loopt over het bestandssysteem en niet over de index van git, zodat
de export getest kan worden waar geen git-client staat — dat is precies waar
de tests van dit project draaien. De prijs: een **untracked** bestand in je
werkkopie gaat gewoon mee. Doe `git status` vóór je exporteert.

### Het recept

```bash
# 1. Een schone kopie van de applicatie, buiten deze repository
docker exec mygdala_php php scripts/create_fresh_site_copy.php /var/www/html/../nieuwe-site
#    (of, met PHP op je eigen machine:)
#    php scripts/create_fresh_site_copy.php ../nieuwe-site

# 2. Eigen versiebeheer
cd ../nieuwe-site && git init && git add -A && git commit -m "chore: applicatie zonder site"

# 3. De omgeving
cp .env.example .env
```

Zet daarna in `.env`, in deze volgorde:

| Stap | Variabele | Waarde |
|---|---|---|
| 4 | `APP_ENV` | `local` tijdens het bouwen, `production` zodra de site live gaat |
| 5 | `APP_URL` | het echte publieke webadres, of leeg laten en het in de wizard invullen |
| 6 | `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | een eigen database. **Geef hem een eigen naam**: `.env.example` staat nog op `mygdala`, en `docker-compose.yml` gebruikt diezelfde naam als standaard voor `TEST_DB_DATABASE` — hernoem ze samen, of geen van beide |
| 7 | `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` | de eerste Super Admin; de hash maak je met `scripts/generate_admin_hash.php`. Er worden nergens standaardgegevens meegeleverd |
| 8 | `MODULE_*_ENABLED` | zie hieronder |

```bash
# 9.  De database opbouwen
docker compose up -d
# 10. De site draait
# 11. Inloggen op /admin/
# 12. De Installatiewizard afronden (dit document, hierboven)
```

### Stap 8: welke modules aan

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

### Wat je daarna nog met de hand doet

De export noemt aan het eind elk bestand dat deze site nog bij naam noemt. De
meeste daarvan zijn toelichtingen in code die uitleggen waaróm iets zo werkt —
lezen mag, herschrijven hoeft niet. Deze zijn het wél waard:

- `composer.json` — `name` en `description`;
- `README.md` — het is de ontwikkelaarsdocumentatie van *deze* site;
- de koppen van `assets/css/core.css`, `assets/js/core.js` en
  `assets/js/cookie-consent.js`;
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
