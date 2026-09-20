# Redirects

Hoe een oude of gewijzigde URL van deze site bij de juiste pagina uitkomt.
Lees dit samen met [`PROJECT-MAP.md`](PROJECT-MAP.md) (waar iets staat) en
[`SEO.md`](SEO.md) (wat er in de `<head>` komt).

Eén regel vooraf: **een redirect komt nooit vóór echte inhoud.** De tabel
wordt alleen geraadpleegd op het moment dat een verzoek toch al een 404 ging
worden. Een bestaande pagina, een applicatieroute, een product, een collectie
of een bestand op schijf wint altijd, en dat is geen regel maar een gevolg van
waar de opzoeking staat.

## De onderdelen

| Onderdeel | Waar |
|---|---|
| Tabel | `redirects` (`db/migrations/20260909240000_create_redirects_table.php`) |
| SQL | `src/Repository/RedirectRepository.php` |
| Woordenlijsten (301/302, manual/slug_change) | `src/Service/Redirects/Redirect.php` |
| Padnormalisatie | `src/Service/Redirects/RedirectPath.php` |
| Bestemmingen | `src/Service/Redirects/RedirectTarget.php` |
| Opslaanregels (conflict + kringetje) | `src/Service/Redirects/RedirectValidator.php` |
| Opzoeken bij een verzoek | `src/Service/Redirects/RedirectResolver.php` |
| Het regeltje in een route | `src/Service/Redirects/RedirectGate.php` |
| Automatisch bij hernoemen | `src/Service/Redirects/SlugChangeRedirects.php` |
| Apache-ErrorDocument | `404.php` + één regel in `.htaccess` |
| Beheer | `admin/redirects.php`, `admin/redirect.php`, `api/admin/*-redirect.php` |

## Waar de opzoeking gebeurt

Deze applicatie heeft **geen front controller**, en dat is sinds Multilingual
2.0 fase 6 nog steeds zo: de templates in de projectroot renderen zichzelf.
Wat wél veranderde is dat er nu één plek is waar elke publieke URL langskomt
die geen bestand op schijf is: `dispatcher.php`
(`docs/multilingual/ROUTING.md`). `.htaccess` doet nog precies één ding —
bestaat het bestand of de map, dan is het van Apache; al het andere gaat naar
de dispatcher.

De poort staat op de momenten waarop een verzoek nog te redden is, en nergens
anders:

```text
verzoek
   │
   ├── bestaand bestand (/shop.php, /assets/..., /admin/...)
   │        → Apache serveert het; de poort komt er niet aan te pas
   │
   ├── al het andere → dispatcher.php
   │        → taalsegment afpellen, normaliseren, route zoeken
   │             ├── geen route met die vorm            → RedirectGate → 404
   │             └── wel een route → het template
   │                      → geen pagina, bericht of archief met die slug
   │                           → RedirectGate (in het template zelf)
   │
   └── Apache kan het niet plaatsen (een ontbrekend bestand onder
       /admin/, /api/, /assets/, ...)
            → ErrorDocument 404 → 404.php → RedirectGate
```

De dispatcher roept de poort aan wanneer geen enkele **routevorm** past
(`/oude/pagina`, `/oud_pad`, `/legacy.html`). Past de vorm wél — één segment is
altijd een mogelijke CMS-pagina, `/blog/<slug>` altijd een mogelijk bericht —
dan beslist het template of er inhoud is, en roept hét de poort aan op het
punt waar het anders 404 zou zeggen: `pagina.php`, `blog.php` en
`blog-post.php`. Een module die publieke URL's van zichzelf toevoegt, voegt
zo'n regel toe op dat punt.

Alle aanroepen staan ná de mislukte contentopzoeking. Daarom kan een redirect
geen werkende URL overschaduwen, ook niet als de opslaancontrole ooit een
geval mist. Vóór fase 6 werd die volgorde bewaakt door de plekken die de poort
aanriepen; nu ook doordat de dispatcher hem structureel pas ná de
routeresolutie bereikt.

**Een bron mag een taalprefix dragen.** `/en/old-address` is een echte,
opslaanbare bron — het is de vorm die een hernoemde Engelse pagina oplevert —
en `RedirectValidator::routeOwner()` pelt het taalsegment af voordat hij iets
vraagt: of een bron botst met levende inhoud wordt beslist op de rest van het
pad, opgezocht in díé taal. Een kale `/en` is de homepage van die taal en is
net zomin door te sturen als `/`.

`ErrorDocument 404 /404.php` vangt wat Apache zelf niet kan plaatsen. Onder
`/api/`, `/admin/`, `/assets/` en `/vendor/` — en bij een ander verzoek dan
GET of HEAD — blijft het antwoord kale tekst, want daar leest niemand een
pagina. Die naamruimtes bereiken de dispatcher nooit: `.htaccess` houdt ze
tegen, en de dispatcher weigert ze daarnaast zelf.

## Matchen en normaliseren

Er is één vorm, en alles wordt daarin opgeslagen:

```text
/foo    /foo/    /foo//    foo    /foo?x=1    →   /foo
```

- altijd een leidende slash;
- dubbele slashes vallen weg;
- geen sluitende slash (behalve de root), want `.htaccess` behandelt
  `^([a-z0-9-]+)/?$` toch al als één route;
- percent-codering wordt gedecodeerd, zodat een opgeslagen pad en een
  binnenkomend verzoek elkaar vinden;
- **geen kleine letters**. De routing van deze site is hoofdlettergevoelig —
  `/Diensten.php` geeft 404 waar `/diensten.php` 200 geeft — dus vouwen zou
  een redirect laten afgaan op URL's die deze site nooit had. De kolom
  `source_path` is om dezelfde reden `utf8mb4_bin` en niet de gebruikelijke
  `utf8mb4_unicode_ci`, die hoofdletterongevoelig vergelijkt;
- geweigerd: een absolute URL, een pad dat met `//` begint, een `.`- of
  `..`-segment, een backslash, een stuurteken, en alles boven 255 tekens.

## Querystrings

- **Matchen gebeurt op het pad**, nooit op parameters. `?utm_source=...` mag
  een redirect niet tegenhouden.
- Komt de bezoeker met parameters en heeft de bestemming er zelf geen, dan
  gaan ze mee. Campagneparameters overleven de verhuizing.
- Heeft de bestemming wél een eigen querystring, dan wint die volledig. Twee
  querystrings aan elkaar plakken levert een URL op die geen van beide
  bedoelde.
- Naar een **externe** bestemming gaat nooit iets mee: deze site beslist niet
  welke parameters een andere site krijgt.

Er zijn geen regex-redirects, geen wildcards en geen
parametertransformaties.

## Handmatig en automatisch

Elke rij draagt een `origin`, en beide soorten staan in dezelfde lijst — een
automatische redirect die een redacteur niet ziet, is een automatische
redirect die niemand kan begrijpen.

| origin | Ontstaat door | Mag een hernoeming hem wijzigen? |
|---|---|---|
| `manual` | een redacteur die hem intypt | nee, nooit |
| `slug_change` | het hernoemen van een gepubliceerde CMS-pagina, een publiek blogbericht of een bereikbaar blogarchief | ja |

`origin` is niet uit een formulier te zetten: het bepaalt of een latere
hernoeming een rij mag overschrijven, dus die vlag is van de applicatie.

## Wat een hernoeming doet

**Eerst bevestigen.** Een redacteur verandert het webadres van een bestaande
pagina niet per ongeluk. Op het tabblad Pagina staat het adres als link, en
het veld zit achter *Webadres wijzigen*, met erboven waar de pagina gebruikt
wordt. De eerste opslag die het adres echt verandert, schrijft nog niets:
`api/admin/update-page.php` stuurt terug naar `admin/page.php`, dat het
huidige en het nieuwe adres toont en zegt wat er met het oude gebeurt. Pas de
bevestiging slaat op. Die stuurt hetzelfde formulier opnieuw, met het
bevestigde adres in `confirmed_slug`, en
`PageService::urlChangeNeedsConfirmation()` laat precies dat adres door. Wie
daarna nog iets anders typt, krijgt de vraag opnieuw. Een pagina op een vaste
URL heeft geen adresveld.

**Het adres dat verhuist is dat van de bewerkte taal** (Multilingual 2.0
fase 6, `docs/multilingual/ROUTING.md`). De Engelse versie hernoemen vraagt
naar `/en/about-us`, legt `/en/oud` → `/en/nieuw` vast en laat elk Nederlands
adres met rust; `SlugChangeRedirects::record()` krijgt de taal mee en bouwt
beide paden in de URL-ruimte van die taal. Een taal die nog géén adres had,
verhuist niets — die wordt voor het eerst gepubliceerd — en vraagt dus ook
niets. Voor de standaardtaal is er niets veranderd: die heeft geen prefix, en
elke redirect van vóór fase 6 houdt precies de vorm die hij had.

Daarna roept `api/admin/update-page.php` `SlugChangeRedirects` aan, en alleen
wanneer alle vier waar zijn. De laatste drie staan samen in
`PageService::oldAddressWillRedirect()`, zodat het bevestigingsscherm precies
zegt wat het endpoint doet:

1. de slug is écht veranderd (een gewone opslag schrijft niets);
2. de pagina heeft geen vaste URL (`route_path`);
3. de pagina wás gepubliceerd (een concept-slug was nooit een werkende URL —
   en daarom levert een gloednieuwe pagina er ook geen op);
4. de pagina ís nog gepubliceerd (hernoemen én offline halen in één keer zou
   de oude URL naar een nieuwe 404 wijzen).

Daarna, in deze volgorde:

1. een redirect ván het nieuwe pad wordt verwijderd — dat pad serveert nu
   echte inhoud;
2. het oude pad krijgt een 301 naar het nieuwe (of wordt omgezet als deze
   pagina al eerder hernoemd is);
3. elke `slug_change`-redirect die naar het oude pad wees, wijst voortaan naar
   het nieuwe.

Twee hernoemingen achter elkaar geven daarmee:

```text
/diensten   →  /services-new
/services   →  /services-new
```

Elke historische URL komt in één stap uit bij de huidige, en de keten groeit
niet mee met het aantal hernoemingen.

**Verwijderen of depubliceren schrijft niets.** Verzinnen dat alles wat op een
verwijderde URL stond nu op de homepage hoort, is precies hoe een eerlijke 404
een soft 404 wordt. Wie daar een bestemming wil, maakt er zelf een.

Een automatische redirect mag gewoon verwijderd worden, en komt niet terug bij
de eerstvolgende gewone opslag — alleen bij een nieuwe slugwijziging.

### Wat er met links naar de pagina gebeurt

Een menu-item, een footerlink en de knop in de header kunnen naar een pagina
wijzen via haar id. `LinkResolver` bouwt daar bij elke weergave het huidige
adres van, dus die links gaan vanzelf mee en worden nergens herschreven.
`App\Service\PageUsage` somt ze op, zodat de redacteur ziet waar de pagina
gebruikt wordt.

Een getypt adres is tekst: `/contact` in een knop van een contentblok, een
link in rich text, een menu-item van het type externe URL. Het CMS kan niet
weten dat die tekst déze pagina bedoelt. Het telt zulke links daarom niet, en
het vervangt ze ook niet: er is geen zoek-en-vervang door inhoud. Bij een
gepubliceerde pagina blijven ze werken via de automatische 301. Bij een
concept waren ze nooit een werkende link.

### En hetzelfde voor de Blog

`App\Service\Blog\BlogPostService::recordSlugChange()` en
`App\Service\Blog\BlogTaxonomy` roepen **dezelfde** `SlugChangeRedirects` aan,
met dezelfde vier voorwaarden — "gepubliceerd" gelezen als "was echt publiek",
dus een concept en een bericht dat pas volgende maand verschijnt leveren niets
op. Eén redirecttabel, één set regels, en een redacteur ziet de rij op
hetzelfde scherm als alle andere.

Verwijderen schrijft ook daar niets: dat geldt voor een bericht, een categorie
en een tag net zo goed als voor een pagina.

## Conflicten

Een vanaf-pad mag niet op een URL zitten die deze site al serveert. Dat wordt
gecontroleerd tegen wat er al is, niet tegen een tweede handgeschreven lijst:

- `App\Service\ReservedRoutes` — dezelfde lijst die bepaalt welk woord een
  CMS-pagina niet als slug mag claimen. Daar staan alle root-PHP-bestanden,
  alle top-level mappen en alle naamruimtes van een module in, dus `/shop`,
  `/shop.php`, `/cart.php`, `/admin/...`, `/api/...`, `/assets/...`,
  `/collecties/...` en `/portfolio/...` vallen er in één keer onder. De
  controle kijkt naar het **eerste padsegment**, met een eventuele `.php`
  eraf;
- een bestand of map die fysiek in de projectroot staat;
- een gepubliceerde CMS-pagina met die slug;
- de root `/` zelf.

**Een uitgeschakelde module houdt zijn routes gereserveerd.** Dat is
`MODULES.md`'s bestaande ontwerp en het geldt hier onverkort: `shop.php` en
`collectie.php` staan nog op schijf en zouden een redirect toch afschermen.

De lijst controleert ook wat er ná het opslaan gebeurt: op het overzicht
krijgt een rij een waarschuwing zodra zijn vanaf-pad alsnog door echte inhoud
is overgenomen.

## Kringetjes en ketens

Bij het opslaan geweigerd:

```text
/a → /a                     (ook /a → /a/)
/a → /b  terwijl  /b → /a
/a → /b → /c → /a           (tot MAX_CHAIN_DEPTH stappen diep)
```

en een keten die langer is dan de resolver ooit zal volgen.

Bij een verzoek volgt de resolver een keten maximaal
`Redirect::MAX_CHAIN_DEPTH` stappen en onthoudt elk pad dat hij al zag. Een
kringetje dat tóch in de database staat — iemand die rechtstreeks SQL draaide
— eindigt als een 404 met een regel in het serverlog, nooit als een verzoek
dat niet terugkomt.

## Bestemmingen

| Soort | Waarde | Wordt |
|---|---|---|
| `internal` | een pad op deze site, eventueel met eigen querystring | absoluut gemaakt met `AppUrl`, net als elke canonical |
| `external` | een volledige `http(s)://`-URL | letterlijk gebruikt |

Geweigerd bij een externe bestemming: alles wat geen http(s) is (waarmee
`javascript:`, `data:` en `mailto:` als soort afvallen), een URL met
inloggegevens erin, en een URL zonder host. Er is geen wildcard- of
domeinniveau-doorsturen.

Er is bewust **geen** bestemmingstype "CMS-pagina" met een `pages.id`: het
hernoemen van een pagina onderhoudt zijn eigen redirects al, dus dat zou
alleen een tweede linkresolutie opleveren om naast `LinkResolver` bij te
houden.

### Een bestemming van een uitgeschakelde module

Wijst een redirect naar `/shop.php` terwijl de Shop uit staat, dan geeft die
URL zelf 404. De bezoeker daarheen sturen zou één dode URL inruilen voor een
andere en de oorspronkelijke onderweg weggooien. Dus:

- de rij blijft precies zoals hij is (uitzetten is geen deïnstallatie);
- de redirect wordt niet uitgevoerd; de bezoeker krijgt de 404 die hij toch al
  kreeg, met een regel in het serverlog;
- het beheerscherm zegt het erbij;
- zodra de module weer aan staat, werkt hij weer.

Dat is hetzelfde gedrag als de knop in de header en een menu-item die naar een
uitgeschakelde module wijzen (`HEADER-FOOTER.md`, `MODULES.md`).

## SEO

- Een redirectantwoord heeft **geen body**: geen canonical, geen Open Graph,
  niets om te indexeren. De redirect zelf is het signaal.
- De sitemap kan een vanaf-pad niet bevatten: elke `<loc>` komt uit de eigen
  `canonicalUrl()` van een contenttype, en deze tabel is geen contenttype
  (`SEO.md`).
- Een automatische slugredirect wijst naar exact dezelfde URL die
  `PageContent::canonicalUrl()` voor die pagina bouwt, dus de bestemming en de
  canonical van de pagina kunnen niet uit elkaar lopen.
- 301 haalt de oude URL uit de index; 302 laat hem staan. Dat is het hele
  verschil tussen de twee keuzes in het formulier.

## Beveiliging

- De publieke kant is alleen-lezen: één `SELECT` op een geïndexeerde kolom.
- Geen enkele bestemming komt uit request-invoer. `404.php`,
  `RedirectResolver` en `RedirectGate` lezen `$_GET`/`$_POST`/`$_REQUEST` niet,
  en een testcontrole bewaakt dat.
- Schrijven vereist login, `settings.manage` en een geldig CSRF-token, precies
  als elk ander adminendpoint.
- Een externe bestemming bestaat alleen omdat een ingelogde redacteur hem
  heeft ingetypt.

## Beheerscherm

**Beheer → Redirects** (`admin/redirects.php`), naast Site-instellingen en
Vormgeving, achter `settings.manage`. Toevoegen, bewerken, aan/uitzetten,
verwijderen, en zoeken op vanaf-pad of bestemming. Elke rij toont het pad, de
bestemming, de statuscode, of hij aan staat en waar hij vandaan komt, plus een
waarschuwing als de bestemming onbereikbaar is of het vanaf-pad inmiddels
bezet.

Uitzetten laat de rij staan en zet de URL terug op 404 — de manier om te
proberen of een redirect nog nodig is zonder kwijt te raken wat hij zei.

## Verse installatie

De migratie maakt alleen de structuur. Er wordt niets voorgeprogrammeerd, ook
niet voor deze site: een legacy-URL verzinnen zonder bewijs dat hij ooit
publiek was, is hoe zo'n tabel begint te liegen. Een nieuwe installatie start
met nul redirects, en alle bestaande publieke URL's van deze site zijn
onveranderd.

## Wat bewust niet meedoet

- **Producten.** Een product-URL is `/product.php?id=<getal>` en bevat geen
  slug, dus er is niets dat kan verhuizen.
- **Collecties.** Ze wonen onder de gereserveerde naamruimte `/collecties/`,
  die een vanaf-pad met opzet weigert. En hun levenscyclus is een andere: laat
  een redacteur het slugveld van een collectie leeg, dan wordt de slug bij
  élke opslag opnieuw uit de naam afgeleid (`api/admin/update-collection.php`),
  waar de slug van een CMS-pagina nooit stilzwijgend uit de titel wordt
  geregenereerd (`PageService`). Een automatische redirect zou daar afgaan op
  opslagacties die niemand als hernoeming bedoelde. Uitgesteld tot die
  levenscyclus gelijkgetrokken is, niet om de symmetrie.
- **Oude portfolio-projectadressen.** Een projectpagina is een gewone
  CMS-pagina geworden, waar een portfolio-item met `page_id` naar linkt
  (`MODULES.md`). Een oud adres `/portfolio/<slug>` stuurt daarom door, maar
  niet via deze tabel: `/portfolio/` blijft een gereserveerde naamruimte,
  Apache routeert zo'n adres naar `portfolio-detail.php` zodat `404.php` het
  nooit ziet, en een opgeslagen bestemming zou bij elke hernoeming,
  ontkoppeling of depublicatie mee moeten veranderen. `portfolio-detail.php`
  bepaalt de bestemming daarom per verzoek
  (`PortfolioGalleryContent::legacyProjectRedirectUrl()`): een tijdelijke
  redirect (302) naar de canonical van de gekoppelde, gepubliceerde pagina, en
  anders de oude projectpagina. Tijdelijk, want het adres is tijdens de
  overgang een compatibiliteitsroute en de koppeling erachter kan nog
  veranderen of verdwijnen: een 301 zou een browser het vorige doel laten
  onthouden. Eén opzoeking voor één soort adres, geen tweede
  redirectsysteem. Het doel is altijd het huidige adres van de pagina, dus
  na een hernoeming ontstaat er geen keten via het vorige adres.
- Regex- en wildcardredirects, hostnaamredirects, CSV-import/-export,
  404-suggesties, hitteltellers, vervaldatums, prioriteitsregels, geo- of
  apparaatafhankelijke redirects, A/B-redirects.
- **307/308.** Ze verschillen van 301/302 alleen in of een POST een GET mag
  worden, en achter een oud pad zit op deze site geen POST — elk formulier
  post naar `/api/...`, een gereserveerde naamruimte die een vanaf-pad nooit
  kan claimen.

## Tests

| Bestand | Wat |
|---|---|
| `tests/Service/RedirectPathTest.php` | normalisatie, bestemmingen, de gesloten woordenlijsten. Geen database, geen webserver |
| `tests/Service/RedirectAdminSecurityTest.php` | login/permissie/CSRF/POST per endpoint, en dat geen publieke kant een bestemming uit een request leest |
| `tests/Service/RedirectValidationTest.php` | conflicten, dubbele rijen, zelfkringetjes, tweewegkringetjes, te lange ketens |
| `tests/Service/RedirectRoutingTest.php` | echte verzoeken: 301, 302, uit, geen redirect, beide integratiepunten, querystrings, ketens, lege body |
| `tests/Service/RedirectSlugChangeTest.php` | hernoemen, twee keer hernoemen, terug hernoemen, handmatige rij met rust laten, sitemap en canonical |
| `tests/Service/PageUrlChangeTest.php` | eerst bevestigen, opnieuw vragen na een ander adres, de drie redirectvoorwaarden, en dat niets inhoud herschrijft. Geen database, geen webserver |
| `tests/Service/PageUsageTest.php` | waar een pagina gelinkt wordt: menu, footer en headerknop tellen, een getypt adres niet |
| `tests/Module/RedirectModuleTest.php` | bestemming in een uitgeschakelde module: niet uitvoeren, wel bewaren, weer laten werken |

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
docker compose exec php_test php vendor/bin/phpunit --testsuite modules
```
