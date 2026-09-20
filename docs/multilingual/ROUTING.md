# Meertalige routing en URL's

Wat een publieke URL van deze site betekent, hoe de taal van een verzoek
wordt bepaald, en waar dat allemaal in de code staat. Multilingual 2.0
fase 6.

Hoe de routing er vóór deze fase uitzag — elke route, elke slugbron, elke
URL-bouwer — staat als momentopname in
[`ROUTING-INVENTARIS-FASE-6.md`](ROUTING-INVENTARIS-FASE-6.md).

Dit document gaat over **de website**. De CMS-interfacetaal
(`docs/multilingual/CMS-LANGUAGE.md`) en de bewerktaal van een redacteur
(`docs/multilingual/EDITING-LANGUAGE.md`) staan er los van en komen hier niet
voor.

---

## 1. Het URL-contract

De standaardtaal heeft **geen prefix**. Elke andere taal heeft er één.

```text
standaardtaal nl          /            /over-ons       /blog/mijn-bericht
actieve taal  en          /en/         /en/about-us    /en/blog/my-post
actieve taal  de          /de/         /de/ueber-uns   /de/blog/mein-beitrag
```

**"Zonder prefix" betekent "de huidige standaardtaal", nooit "Nederlands".**
Maak Engels de standaardtaal en de prefixen wisselen om, zonder codewijziging
en zonder ergens een vast paar taalcodes:

```text
standaardtaal en          /            /about-us       /nl/over-ons
```

De prefix wordt op precies één plek gezet: `App\Service\Routing\LocalizedUrl`.
Geen enkel template, geen enkele repository en geen enkele partial bouwt zelf
een `"/en/" . $slug`.

### De taalhome houdt zijn slash

`/` en `/en/`, met slash. `/en` stuurt permanent door naar `/en/`. Elke andere
URL heeft geen sluitende slash; `/over-ons/` stuurt permanent door naar
`/over-ons`.

---

## 2. Route bestaat ≠ veld valt terug

Dit is de belangrijkste regel van deze fase, en de reden dat er een aparte
opslag voor adressen is.

**Een taal-URL bestaat alleen wanneer die taalversie echt routeerbaar is.**
Een pagina met een Nederlandse en een Engelse slug, en zonder Duitse:

```text
/over-ons        200
/en/about-us     200
/de/over-ons     404      — en géén stille terugval naar het Nederlands
```

Een expliciete `/de/…`-URL mag nooit Nederlandse inhoud publiceren onder een
Duitse canonical. Dat is precies het signaal waarvan zoekmachines zeggen dat
ze het niet vertrouwen.

**Binnen een bestaande route valt een VELD wél terug**: gevraagde taal →
standaardtaal → leeg. Een pagina die in het Duits routeerbaar is maar waarvan
de SEO-titel niet vertaald is, krijgt de standaardtaal-titel. Dat is
veldterugval, en die verzint geen route.

In code is het verschil zichtbaar:

| Vraag | Antwoord | Terugval? |
|---|---|---|
| `PageLocalization::slug($id, $lang)` | het adres in die taal, of `null` | **nee** |
| `PageLocalization::value($id, $veld, $lang)` | de woorden in die taal | ja |
| `EntityTranslations::slug($id, $lang)` | idem, voor modules | **nee** |
| `EntityTranslations::value($id, $veld, $lang)` | idem | ja |

`EntityTranslations` weigert het adresveld aan `value()`, `name()` en
`bilingual()` te geven. Dat is afgedwongen en niet alleen opgeschreven: de
fout is onzichtbaar op een site met één taal.

---

## 3. Welke taal een verzoek krijgt

`App\Service\Routing\LanguageResolver`, vier stappen:

1. **het taalsegment in de URL** — expliciet, deelbaar, en daarmee definitief;
2. de **opgeslagen voorkeur** van de bezoeker;
3. **`Accept-Language`**;
4. de **standaardtaal** van de site.

Alleen **actieve** talen doen mee. Een geregistreerde taal die uitstaat wordt
in elke stap overgeslagen.

### Stap 1 wint altijd

Een link die iemand je stuurt opent in de taal waarin hij geschreven is,
wat jouw vorige bezoek ook was.

### Stap 2 en 3 verplaatsen een bezoeker alleen op de siteroot

Een **URL zonder prefix ís de URL van de standaardtaal** en wordt ook zo
beantwoord. Zou een cookie of een browserinstelling een bezoeker van
`/over-ons` kunnen wegsturen, dan zou elke canonieke URL van de site per
bezoeker iets anders antwoorden — precies wat een canonieke URL niet mag.

Op `/` is er nog geen belofte te breken: daar wordt de vraag één keer gesteld,
met een **tijdelijke** doorverwijzing (302) en een `Vary`-header die noemt
waar het antwoord van afhing. `dispatcher.php` doet dat en niets anders doet
het.

### De opgeslagen voorkeur

Eén first-party cookie (`site_language`, `App\Service\Routing\LanguagePreference`)
met een taalcode en verder niets: geen identifier, geen sessiehandvat, geen
persoonsgegeven. Een waarde die geen actieve taal is, telt als "geen
voorkeur".

Hij wordt geschreven voor **de taal die een bezoeker daadwerkelijk leest**,
door de dispatcher en door `partials/public-request.php` — en alleen wanneer
hij zou veranderen, dus een gewone paginaweergave stuurt helemaal geen
`Set-Cookie`. Dat is wat de taalwisselaar gewone links laat zijn: er is geen
keuze-endpoint, geen return-URL, en dus nergens in de buurt een open redirect.

### De ene uitzondering: kiezen voor de standaardtaal op de home

`/` is de enige URL waar de opgeslagen voorkeur **beslist**, en daarmee ook de
enige URL die niet kan zeggen welke taal iemand koos. Een bezoeker met de
voorkeur Engels die op `/en/` op "NL" klikt, vraagt om `/`, wordt gelezen als
"noemde geen taal" en stond zonder deze regel meteen weer op `/en/` — in de
browser gevonden, niet op papier.

Daarom draagt precies die ene wisselaarlink zijn keuze mee:
`/?lang=nl` (`App\Service\Routing\LanguageSwitch::CHOICE_PARAMETER`).
`dispatcher.php` leest de parameter **alleen op de siteroot zonder prefix**,
accepteert alleen een **actieve** websitetaal, legt de voorkeur vast en
antwoordt met een 302 naar de **schone** home van die taal, gebouwd door
`LocalizedUrl::home()`. De waarde komt dus nooit in een `Location`-header
terecht; er valt niets anders mee aan te wijzen dan een taalhome van deze site.

Wat de parameter nadrukkelijk **niet** is:

- geen algemene taalparameter. Op elke andere URL, ook op `/en/?lang=nl`,
  wordt hij genegeerd: de URL is het antwoord;
- geen onderdeel van een canonical, een `hreflang`, de sitemap of een interne
  link. `LanguageAlternates` blijft de schone `/` noemen; alleen de
  wisselaarlink draagt hem;
- een onbekende of inactieve waarde (`?lang=zz`) telt als "niet meegegeven".
  Het verzoek wordt dan gewoon onderhandeld, en de parameter reist mee zoals
  elke andere querystring.

### `Accept-Language`

`App\Service\Routing\AcceptLanguage` leest q-waarden, behandelt `q=0` als een
weigering, versmalt `de-DE`/`pt-BR`/`zh-Hans` tot hun basistaal en negeert
`*`. Geen GeoIP, geen externe dienst, geen detectie in de browser.

---

## 4. De dispatcher

`.htaccess` doet nog precies één ding: bestaat het bestand of de map, dan is
het van Apache; al het andere gaat naar `dispatcher.php`. De zeven smalle
rewrites en de catch-all van één segment zijn weg, omdat elk van die regels
per taal gedupliceerd had moeten worden — in een statisch bestand, voor een
lijst talen die in een databasetabel staat.

`dispatcher.php` rendert zelf niets. Vijf stappen, in deze volgorde:

1. het verzoekpad veilig uit elkaar halen (`RequestPath`);
2. een taalsegment afpellen en `RequestLanguage` vastzetten;
3. **normaliseren** naar precies één canonieke schrijfwijze, in hoogstens één
   permanente doorverwijzing;
4. de route zoeken in de gesloten tabel (`RouteTable`, `RouteResolver`) en het
   bestaande template `require`n, met de parameters die Apache's `[QSA]`
   eerder meegaf;
5. geen route? Dan pas de Redirect Manager (`RedirectGate`), en anders de
   eigen 404 van de site, in de taal van het verzoek.

Stap 5 bewaart de invariant die dit project al had: een redirect mag pas
spreken als contentresolutie is mislukt. Dat werd eerder bewaakt door de twee
plekken die de gate aanriepen; nu doordat er één plek is.

**Wat het niet is**: geen front controller, geen controllerklassen, geen
middleware, geen container, geen view-laag. De twintig templates in de root
blijven wat ze waren.

### Taalloze paden

`/admin/**`, `/api/**`, `/assets/**`, `/uploads/**`, `/sitemap.xml` en
`/robots.txt` bereiken de dispatcher nooit — `.htaccess` houdt ze tegen en de
dispatcher weigert ze daarnaast zelf, zodat een router die de hele siteshell
zou bouwen voor een verdwaald `/assets/…`-verzoek op eigen kracht onmogelijk
is.

### Alleen GET en HEAD worden doorgestuurd

Een POST wordt beantwoord waar hij naartoe is gestuurd. Een Location-header
verliest zijn body, en taaldetectie mag nooit bepalen waar iemands
formuliergegevens terechtkomen.

---

## 5. Vaste padsegmenten

De meeste vaste segmenten zijn technisch en in elke taal hetzelfde: `blog`,
`tag`, `portfolio`, `feed.xml`, elk `<naam>.php`. Twee zijn Nederlandse
woorden die een bezoeker als taal leest, en die staan in
`App\Service\Routing\RouteSegments`:

| Sleutel | standaard | `en` |
|---|---|---|
| `blog.category` | `categorie` | `category` |
| `shop.collections` | `collecties` | `collections` |

Een gesloten lijst in code, per release vast, aangevuld door elke
geregistreerde module. Een taal zonder eigen woord gebruikt het standaardwoord
— een site die Duits toevoegt krijgt `/de/blog/categorie/…` en een werkende
URL, geen 404.

Een URL die het woord van een **andere** taal spelt, resolvt nog steeds en
krijgt één permanente doorverwijzing naar zijn canonieke vorm:
`/en/blog/categorie/hout` → `/en/blog/category/hout`.

`personaliseren` is géén catalogussegment: het is een bestandsnaam
(`personaliseren.php`), en die omzetten naar een slug-route zou een bestaande
canonieke URL verplaatsen.

---

## 6. Gereserveerde woorden

`App\Service\Routing\ReservedPaths` is de enige vraag "mag een slug dit woord
claimen". Het is `App\Service\ReservedRoutes` plus twee dingen die pas op
runtime bekend zijn:

1. **elke geregistreerde taalcode**, actief of niet. Een pagina met slug `en`
   zou niet te onderscheiden zijn van de Engelse prefix, en de dispatcher pelt
   de prefix eerst af — de pagina zou dus onbereikbaar zijn. Ook een
   uitgeschakelde taal telt mee: een woord reserveren kost niets, een adres
   uitdelen dat stopt met werken kost een redacteur zijn pagina.
2. **elk woord dat een vast routesegment kan spellen**, in welke taal dan ook.
   `collecties` was al gereserveerd; `collections` is in het Engels dezelfde
   naamruimte.

---

## 7. Waar een adres staat

Per taal, in de typed translationtabel die de entiteit al had:

| Entiteit | Tabel | Kolom |
|---|---|---|
| Pagina | `page_translations` | `slug` |
| Blogbericht | `blog_post_translations` | `slug` |
| Blogcategorie | `blog_category_translations` | `slug` |
| Blogtag | `blog_tag_translations` | `slug` |
| Collectie | `collection_translations` | `slug` |

`UNIQUE(language_code, slug)` per tabel: `/over-ons` en `/en/over-ons` zijn
verschillende URL's en mogen allebei bestaan. `NULL` mag vaak voorkomen, en
dat is wat "geen versie in deze taal" opslaanbaar maakt.

**De neutrale `slug`-kolommen blijven staan** (`pages.slug`, `blog_posts.slug`,
…). Ze zijn de sleutel waar elke bestaande link en elke geïndexeerde URL naar
wijst, waar de opgeslagen redirects tegen geschreven zijn en waar
`content_key` van is afgeleid. De **standaardtaal-slug wordt er byte-identiek
aan gehouden**, en daarom verhuist geen enkele bestaande URL.

### Opzoeken volgt dezelfde regel als opbouwen

`App\Service\Routing\LocalizedSlug` zegt wat het adres van een rij in een taal
is: de slug van die taal, anders de neutrale kolom **alleen voor de
standaardtaal**, anders geen adres. Het opzoeken van een rij bij een slug
(`PageContent::forSlug()`, `BlogContent`, `CollectionContent`) vraagt eerst de
vertaaltabel en daarna, voor de standaardtaal, de neutrale kolom — maar een
neutrale treffer telt alleen wanneer `LocalizedSlug::answersTo()` hem
bevestigt. Een rij met een eigen adres in die taal is dus **alleen** op dat
adres bereikbaar.

### De standaardtaal wisselen

Er is nog geen beheerscherm voor; `SiteLanguages::setDefault()` bestaat wel, en
het contract houdt er rekening mee. Na een wissel van NL naar EN:

- verhuizen de **prefixen**, niet de slugs: `/about-us` en `/nl/over-ons`;
  `/en/about-us` stuurt met één 301 naar `/about-us`;
- volgen canonical, `hreflang`, `x-default` en de sitemap vanzelf;
- antwoordt de **oude** standaardtaal-URL `/over-ons` met 404, want de neutrale
  kolom is geen tweede adres. Er worden **geen redirects aangemaakt**: wie een
  live site wisselt, zet de oude URL's zelf in de Redirect Manager;
- is een pagina zonder woorden in de nieuwe standaardtaal nog steeds bereikbaar
  op zijn neutrale slug. Dat is de compatibiliteitsregel van hierboven, en een
  reden om eerst te vertalen en dan pas te wisselen.

Terugwisselen herstelt exact de oude toestand; op de speelinstallatie was de
URL-tabel na NL → EN → NL identiek aan die ervoor.

Geen adres per taal krijgen:

- **producten** — die hebben helemaal geen slug-URL: één pagina op
  `/product.php?id=…`, hoeveel collecties hij ook in zit;
- **portfolio-items** — `/portfolio/<slug>` is sinds fase 4B een
  compatibiliteitsroute die doorstuurt naar de CMS-pagina van het item, en die
  pagina heeft zijn adres per taal al;
- **portfoliocategorieën** — een filterwaarde in een blok, nooit een URL.

---

## 8. Slugs schrijven

- een slug hoort bij **de taal die de redacteur op dat moment bewerkt**;
- taal A wijzigen raakt het adres van taal B niet;
- de slug blijft staan als alleen de titel of de naam verandert — hij wordt
  alleen automatisch gemaakt wanneer het veld leeg is **én die taal nog geen
  adres heeft**;
- een botsing wordt binnen **één** taal gecontroleerd, plus — voor de
  standaardtaal — tegen de neutrale kolom;
- een geweigerde opslag houdt de invoer én de "niet opgeslagen"-staat vast;
- een pagina die in een taal nog geen adres heeft, heeft in die taal **geen
  publieke URL**, en het scherm zegt dat met zoveel woorden.

Alleen de **standaardtaal** móét een adres hebben; in elke andere taal is het
veld leeg toegestaan, en het leegmaken van een bestaand adres betekent "deze
taal heeft hier geen publieke URL meer". Die ene regel staat op één plek in
code: `App\Service\Routing\LocalizedSlugInput`. Sanitizen, uniciteit en de
gereserveerde woorden blijven van het domein zelf (`PageService`,
`Blog\BlogSlug`, `CollectionService`), want alleen dat kent zijn eigen
tekenset, lengte en naamruimte.

Een naamswijziging legt een 301 aan **in de URL-ruimte van die taal**: de
Engelse versie hernoemen geeft `/en/oud` → `/en/nieuw` en laat de Nederlandse
adressen met rust.

### Welke schermen een adres per taal bewerken

| Scherm | Endpoint | Adres van |
|---|---|---|
| `admin/page.php` | `update-page.php` | de pagina |
| `admin/blog-post.php` | `update-blog-post.php` | het bericht |
| `admin/blog-categories.php` | `update-blog-category.php` | het categoriearchief |
| `admin/blog-tags.php` | `update-blog-tag.php` | het tagarchief |
| `admin/collection.php` | `update-collection.php` | de collectiepagina |

Een **nieuw** item wordt in de standaardtaal aangemaakt en krijgt daar zijn
adres; elke andere taal blijft zonder, en dus zonder publieke URL, tot een
redacteur er een schrijft.

Bij een collectie verandert een vertaling **alleen** het adres van die taal.
Het id, de neutrale slug, de gekoppelde producten met hun volgorde, de
gerelateerde-producteninstelling en de zichtbaarheid zijn taalneutraal en
blijven staan.

De blognaamruimte is per taal gespeld, dus `categorie` **en** `category` zijn
allebei gereserveerd voor een blogslug — `BlogSlug::reservedSegments()` haalt
die woorden uit dezelfde catalogus als de router (`RouteSegments`).

---

## 9. Interne links versus de taalwisselaar

Twee regels, en ze zijn expres verschillend.

**Een gewone interne link** (menu, footer, CTA, kaart, kruimelpad) wijst naar
de versie in de gevraagde taal, en anders naar de **canonieke URL van de
standaardtaal**. De bezoeker vroeg om dáárheen te gaan: op een echte pagina
landen is beter dan op niets, en die pagina zegt in zijn eigen canonical en
`<html lang>` welke taal hij is.

**De taalwisselaar** is strenger: bestaat de doelversie niet, dan is die optie
**niet beschikbaar** — zichtbaar, maar zonder link. "Lees deze pagina in het
Duits" heeft geen eerlijk antwoord als er geen Duitse pagina is, en er een
verzinnen is precies het SEO-probleem waarvoor deze fase bestaat.

`App\Service\Routing\LanguageAlternates` houdt die twee uit elkaar: een route
**verklaart** welke versies bestaan, en wat niet verklaard is, wordt niet
geadverteerd.

De wisselaar drukt de verklaarde URL ongewijzigd af, op één link na: die naar
de **home van de standaardtaal** vanaf een andere taal wordt `/?lang=<code>`.
Waarom staat in §3, "De ene uitzondering".

---

## 10. Canonical, hreflang en x-default

Elke routeerbare pagina geeft **precies één** canonical: zijn eigen taalversie,
absoluut via `App\Service\AppUrl`, standaardtaal zonder prefix en elke andere
met. Nooit taaloverschrijdend, ook niet wanneer een veld is teruggevallen.

`hreflang` wordt in één partial gerenderd (`partials/seo-head.php`), uit de
versies die de route heeft verklaard, plus `x-default` naar de versie in de
standaardtaal. Een route die niets verklaart adverteert niets — de toestand
waarin elke pagina van dit project vóór fase 6 verkeerde. Daardoor kan een
alternate nooit een URL noemen die 404't of die alleen maar terugvalt.

De testregel die hreflang vroeger **verbood**
(`Tests\Service\MultilingualBoundaryTest`) is vervangen door de regel dat het
maar op één plek gerenderd wordt en alleen uit verklaarde versies.

---

## 11. Wat fase 7 nog opruimt

De `data-nl`/`data-en`-attributen staan nog in de opmaak en worden nog
gevuld, maar **er handelt niets meer op**: de wisselaar is een rij links
geworden, en `assets/js/core.js` bindt alleen aan `.lang-switch button`. De
V1-paren in `SeoMetadata`, de bilinguale adapters en de JSON-helften gaan
samen met die attributen weg in fase 7.

---

## 12. De sitemap

Eén `sitemap.xml` voor de hele site. Elke taalversie van één ding is een
eigen `<url>`, en **elk** van die `<url>`'s draagt de volledige set
`<xhtml:link rel="alternate">` plus `x-default`. Die wederkerigheid ís het
signaal; een eenzijdige alternate is slechter dan geen.

Een versie komt er alleen in als zijn adres echt bestaat. Een pagina zonder
Duitse slug heeft dus geen Duitse regel, en een collectie die alleen
Nederlands is verschijnt één keer.

De `xhtml`-naamruimte wordt alleen gedeclareerd wanneer er iets gebruikmaakt
van alternates, zodat de sitemap van een eentalige site byte-voor-byte is wat
hij vóór fase 6 was.

Een **storefront, een blogindex of een product** bestaat in elke actieve taal:
dat zijn vaste paden of id-routes, er is geen adres dat kan ontbreken. Een
**post, categorie, pagina of collectie** staat er alleen in voor de talen
waarin hij een adres heeft.

---

## 13. De browser en de taalprefix

`assets/js/shop/shop.js` en `cart.js` bouwen zelf links: de productkaart in
het raster, de regel in de winkelwagen, de toast na "in winkelwagen". Die
moeten dezelfde prefix dragen als de server eraan gegeven zou hebben, anders
valt een klant op `/en/shop.php` bij de eerste klik terug in het Nederlands.

PHP stempelt hem op `<html data-url-prefix>`: `""` voor de standaardtaal,
`/en` voor elke andere. `cart.js` leest hem één keer en biedt één
`localeUrl()`-helper aan, ook aan `shop.js`.

Hij wordt **nooit uit het huidige pad afgeleid** — een eerste segment van twee
letters kan net zo goed een paginaslug zijn — en een waarde die geen kale
`/xx` is wordt genegeerd, zodat een herschreven attribuut links niet naar een
ander origin kan laten wijzen.

---

## 14. Formulieren, POST en PRG

De identiteit van een POST is taalloos: `/api/form-submit.php`,
`/api/withdrawal-request.php` en `/api/checkout.php` hebben geen taal in hun
pad en krijgen er ook geen.

Waar de bezoeker daarna terechtkomt is wél per taal:

- **Core Forms** stuurt terug naar `form-source`, het pad waar het formulier
  stond (`App\Service\Forms\FormSourcePath`). Dat pad komt uit `REQUEST_URI`
  en draagt de prefix dus vanzelf — een formulier op `/en/contact` keert terug
  op `/en/contact?form-status=…`. De open-redirect-guard die er al zat
  (één leidende slash, geen `//`, geen `\`, geen `@`) verandert niet.
- **Afrekenen** is een `fetch()` zonder eigen URL, dus de taal reist mee in de
  payload en de server toetst hem aan het register vóór hij er iets mee doet.
  Het enige dat hij kan bepalen is naar welke bestelpagina van deze site de
  klant terugkeert; de URL zelf wordt door `LocalizedUrl` uit de geconfigureerde
  basis-URL gebouwd, dus een vervalste waarde kan geen andere host noemen.

Een **omleiding om taalredenen gebeurt alleen bij GET en HEAD**. Een POST
wordt beantwoord waar hij naartoe gestuurd is: een `Location`-header verliest
zijn body, en taaldetectie mag nooit bepalen waar iemands formuliergegevens
terechtkomen.

---

## 15. Wat fase 6 bewust NIET doet

- **Een unprefixed pad wordt niet tegen een andere taal gematcht.** `/about-us`
  is geen Nederlandse URL en wordt een 404 (of een rij in de Redirect Manager),
  niet stilletjes een 301 naar `/en/about-us`. De opdracht laat die redirect
  toe "wanneer ondubbelzinnig", maar ondubbelzinnig is het pas ná een
  database-vraag per taal bij élke 404, en er bestond vóór fase 6 geen enkele
  URL die erdoor gered zou worden.
- **Systeempagina's houden hun `.php`-adres.** `/shop.php` blijft `/shop.php`
  en krijgt `/en/shop.php` ernaast. Ze omzetten naar slug-routes verplaatst
  bestaande canonieke URL's, en dat is precies wat deze fase niet doet.
- **Producten krijgen geen slug-URL.** Eén pagina op `/product.php?id=…`,
  hoeveel collecties hij ook in zit. Een slug-URL is een URL-beslissing die
  niets met taal te maken heeft.
- **`personaliseren` blijft een bestandsnaam.** Zie §5.
