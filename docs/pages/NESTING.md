# Pagina's nesten en de beheergroep Service & juridisch

Pagina's 2.0. Een pagina kan onder een andere pagina staan, en die structuur
is een echt deel van de publieke URL:

```text
Metaal graveren                    /metaal-graveren
├── Aluminium visitekaartjes       /metaal-graveren/aluminium-visitekaartjes
├── RVS graveren                   /metaal-graveren/rvs-graveren
└── Messing graveren               /metaal-graveren/messing-graveren

                                   /en/metal-engraving/aluminium-business-cards
```

Daarnaast kan een paginaboom in het CMS onder **Service & juridisch** staan
in plaats van tussen de gewone websitepagina's. Dat is alleen beheerindeling.

Dit document is de wegwijzer voor beide. De URL-regels per taal staan in
[`docs/multilingual/ROUTING.md`](../multilingual/ROUTING.md), de redirects in
[`REDIRECTS.md`](../../REDIRECTS.md), het kruimelpad in
[`HEADER-FOOTER.md`](../../HEADER-FOOTER.md).

---

## 1. Het datamodel

| Kolom | Betekenis |
|---|---|
| `pages.parent_id` | `NULL` = een hoofdpagina; anders de pagina erboven. `int unsigned`, foreign key naar `pages.id` met **ON DELETE RESTRICT**, index `(parent_id, sort_order)` |
| `pages.admin_group` | `website` of `service`. Alleen de waarde van de **hoofdpagina** van een boom telt |

Migratie `20260924140000_give_pages_a_parent_and_an_admin_group`. Elke
bestaande pagina wordt een hoofdpagina in de groep `website`: geen id, slug,
status of `sort_order` verandert, dus geen enkele URL. De migratie classificeert
niets op titel of slug.

**De hiërarchie is structureel, de slugs zijn per taal.** `parent_id` is in
elke taal hetzelfde; `page_translations.slug` blijft per taal. De uniciteit
blijft wat ze was: `UNIQUE(language_code, slug)`, over de hele site. Dezelfde
laatste slug onder twee verschillende ouders kan dus niet, en dat is bewust:
de strengere regel was er al, en de router heeft hem nodig (§4).

## 2. Eén bron voor een paginapad

`App\Service\PagePath` zet het pad van een pagina samen: de slug van elke
voorouder, van de hoofdpagina naar beneden, en dan de eigen slug, elk in de
gevraagde taal. `PageContent::localizedPath()` is erop gebouwd, en alles wat
een pagina-URL afdrukt gaat daardoorheen:

| Waar | Via |
|---|---|
| Menu, footer, knop in de header | `LinkResolver` → `PageContent::publicUrl()` |
| Een Page-link in een blok (Oproep, Kaarten-carrousel, Openingssectie, …) | `LinkChoice` / `LinkTargets` → `LinkResolver` |
| Een getypte link (`/metaal-graveren/rvs-graveren`) | `TypedLink::href()` → `PageContent::forPath()` |
| Kruimelpad | `PageBreadcrumb::forPage()` |
| Canonical, hreflang, x-default, taalwisselaar | `partials/page-head.php` → `PageContent::localizedPaths()` |
| Sitemap | `Sitemap` → `PageContent::localizedPaths()` |
| Shop-overzicht als CMS-pagina | `ShopOverview` → `PageContent::publicUrl()` |
| CMS: overzicht, editor, *Bekijken* | `PageContent::publicUrl()` / `localizedPath()` |
| Juridische links (checkout, herroeping) | `LegalPages::publishedPageUrl()` |

Er staat nergens meer een eigen `'/' . $slug` voor een pagina. De ene
uitzondering is een noodwaarde die alleen spreekt als de database niets
teruggeeft: `LegalPages::termsAndConditionsUrl()` valt dan terug op
`/algemene-voorwaarden`, zoals het altijd deed.

Een link naar een pagina bewaart het **pagina-id**, nooit een pad. Verhuist de
pagina, dan wijst elke link vanzelf naar het nieuwe pad.

## 3. Meertaligheid: geen nieuwe regel

Elk segment is `PageContent::localizedSlug()`: het adres van die pagina in
die taal, de neutrale kolom alleen voor de standaardtaal, nooit een andere
taal (`ROUTING.md` §2). Daaruit volgt, zonder nieuwe regel:

**Een pad bestaat in een taal alleen als élke pagina op dat pad daar een adres
heeft.** `/en/<ouder>/kind` kan niet bestaan zolang `/en/<ouder>` niet
bestaat. Heeft de ouder geen Engels adres, dan heeft het kind er ook geen,
ook als zijn eigen Engelse slug is ingevuld. Wat dan gebeurt is precies wat er
gebeurt met een pagina zonder eigen Engelse slug:

- een gewone link gaat naar de versie in de standaardtaal (`ROUTING.md` §9);
- de taalwisselaar toont Engels als niet beschikbaar;
- hreflang en de sitemap noemen geen Engelse versie;
- `/en/<nl-ouder>/child` antwoordt 404.

## 4. De router

`RouteTable` heeft één paginaroute, als laatste: `{slug+}`, één tot
`RouteTable::MAX_PAGE_SEGMENTS` (8) segmenten, elk in de tekenset
`[a-z0-9-]`. Alle vaste routes en modulenaamruimtes komen eerst. Het eerste
segment van een paginapad is altijd de slug van een hoofdpagina, en die kan
nooit een gereserveerd woord zijn (`ReservedPaths`), dus een paginapad botst
nooit met Shop, Blog, Portfolio, admin, assets of een taalprefix.

`pagina.php` zoekt de pagina met `PageContent::forPath()`:

1. de laatste slug wijst de enige kandidaat aan (slugs zijn uniek per taal),
   alleen als die gepubliceerd is;
2. die pagina hoort alleen bij deze URL als haar **eigen pad in deze taal
   exact deze segmenten** is.

Zo wordt elk segment binnen de context van zijn ouder gecontroleerd:

```text
/metaal-graveren/aluminium-visitekaartjes     200
/hout-graveren/aluminium-visitekaartjes       404 — verkeerde ouder
/aluminium-visitekaartjes                     404 — geen hoofdpagina (of 301, als hij verhuisd is)
```

Een 404 hier vraagt daarna de Redirect Manager, zoals elke 404 van een
paginapad al deed. Een voorouder die zelf een concept is, maakt een
gepubliceerd kind niet onbereikbaar: publiceren is per pagina. Het kruimelpad
noemt zo'n voorouder wel, zonder link.

Trailing slash en taalprefix gaan zoals altijd: `/metaal-graveren/rvs/` →
301 → `/metaal-graveren/rvs`.

## 5. Verplaatsen en redirects

Een pad verandert bij een nieuwe ouder, een nieuwe eigen slug en een nieuwe
slug van een voorouder. `api/admin/update-page.php`:

1. **Vraagt eerst.** Een nieuwe ouder moet bevestigd worden, net als een
   nieuwe slug: de kaart toont het huidige en het nieuwe **volledige** pad in
   de bewerkte taal, en hoeveel onderliggende pagina's meeverhuizen. De
   bevestiging draagt `confirmed_parent` (en `confirmed_slug`).
2. **Legt vóór de opslag alle paden vast**: van de pagina en van elke pagina
   eronder, in elke actieve taal (`PagePath::snapshot()`).
3. **Slaat op in één transactie**: de pagina, haar tekst, haar plek
   (`PageRepository::updatePlacement()`), en de beheergroep van de hele
   subboom.
4. **Berekent de nieuwe paden** en maakt per pagina en taal waar oud ≠ nieuw
   één 301 (`PageService::pathMoves()`,
   `SlugChangeRedirects::recordMoves()`), alle in één transactie.

Voorwaarden per pagina: de pagina zelf volgt `oldAddressWillRedirect()`
(was en blijft gepubliceerd, geen vaste URL); een pagina eronder krijgt een
redirect als ze gepubliceerd is. Een taal waarin het pad verdween, verhuist
niets: de oude URL wordt een gewone 404.

Elke redirect gaat door dezelfde drie stappen als een hernoeming
(`REDIRECTS.md`): een redirect ván het nieuwe pad verdwijnt, het oude pad
krijgt een 301, en oudere redirects naar het oude pad schuiven door. Een
redirect die een redacteur met de hand maakte, wordt nooit overschreven.
Terugverhuizen laat daardoor geen kringetje achter.

```text
/materiaal                 →  /materialen
/materiaal/metaal          →  /materialen/metaal
/materiaal/metaal/aluminium →  /materialen/metaal/aluminium
```

## 6. Wat niet mag

`PageService::validateParent()`, ook voor een zelfgemaakte POST:

- een pagina onder zichzelf;
- een pagina onder een van haar eigen onderliggende pagina's
  (`A → B → C`: C wordt nooit de ouder van A);
- een pagina met een vaste URL (homepage, Shop, Diensten, …) als ouder, of
  zelf genest: die hebben een route in plaats van een slug;
- dieper dan 8 niveaus, de subboom die meeverhuist meegeteld.

De keuzelijst in de editor biedt precies de toegestane ouders
(`PageService::parentCandidates()`): de pagina zelf en alles eronder staan er
niet in.

**Verwijderen** van een pagina met onderliggende pagina's wordt geweigerd,
met de melding *verplaats of verwijder eerst de onderliggende pagina's*.
`PageService::delete()` zegt het, de foreign key dwingt het af. Geen cascade,
en nooit kinderen die stil hoofdpagina worden.

`PagePath` is daarnaast defensief: een lus of een verdwenen ouder die toch in
de database kwam, geeft *geen pad* en een logregel, nooit een verzoek dat
blijft hangen. Het overzicht toont zo'n pagina gewoon, zodat een redacteur
haar kan repareren.

## 7. Volgorde

- Hoofdpagina's houden hun volgorde: eerst de pagina's met een eigen sjabloon,
  dan op `sort_order` (`PageTree`).
- Kinderen staan op `sort_order`, dan id.
- Een nieuwe pagina krijgt de volgende `sort_order` van de hele tabel en komt
  dus achteraan bij haar broers en zussen; een pagina die van ouder wisselt
  ook.
- Er is geen slepen in de boom. Dat was er voor pagina's ook niet.

## 8. Het CMS

### De editor

Op het tabblad *Pagina* (`admin/_page_placement.php`, ook op *Nieuwe
pagina*):

- **Bovenliggende pagina**: *Geen — hoofdpagina* of een pagina uit de boom,
  ingesprongen per niveau, een concept gemarkeerd;
- **Beheergroep** (*Websitepagina* / *Service / juridisch*), alleen voor een
  hoofdpagina; onder een ouder staat er welke groep gevolgd wordt;
- **Webadres**: het pad dat een opslag oplevert, in de bewerkte taal.
  `admin/assets/page-placement.js` werkt het bij terwijl de redacteur een
  ouder kiest of de slug typt, zonder op te slaan. Heeft de ouder in die taal
  geen adres, dan zegt de regel dat.

In het overzicht maakt *Subpagina toevoegen* een nieuwe pagina met die ouder
al gekozen (`page-new.php?parent=`).

### Het overzicht

`admin/pages.php` is een boom in twee groepen:

```text
▾ Websitepagina's (12)
  ▾ Metaal graveren
      Aluminium visitekaartjes
  ▸ Over ons
▸ Service & juridisch (4)
```

- een pagina met onderliggende pagina's heeft een knop met `aria-expanded`
  en `aria-controls`; elk niveau springt in;
- elke groep heeft er ook een; *Websitepagina's* staat open, *Service &
  juridisch* dicht, met het aantal;
- in- en uitklappen is `admin/assets/page-tree.js`, zonder herladen, en wordt
  per browser onthouden in `localStorage` (`mygdalaPageTree`) — nooit op de
  server;
- **zoeken** (`?q=`, zoals voorheen op de server) toont een treffer mét elke
  pagina erboven, gemarkeerd als *bovenliggend*, en klapt zolang de zoekopdracht
  staat alles open, zonder de opgeslagen toestand te wijzigen;
- er is geen paginering, dus de boom is altijd heel;
- zonder JavaScript staat alles open.

## 9. Service & juridisch

Voor pagina's als algemene voorwaarden, privacyverklaring, cookiebeleid en
verzenden & retourneren: gewoon te beheren, maar niet tussen de dagelijkse
pagina's.

**Alleen beheerindeling.** De groep verandert niets aan rendering, status,
SEO, sitemap, navigatie, blokken of rechten. Niets publieks leest
`admin_group`.

**Eén effectieve groep per boom** (`PagePath::effectiveGroup()`): die van de
hoofdpagina. Een kind staat nooit los in de andere lijst.

- Een hoofdpagina kiest haar groep in de editor.
- Een pagina onder een ouder volgt die boom; wat het formulier stuurt wordt
  genegeerd (`PageService::resolveAdminGroup()`).
- Verandert de groep van een hoofdpagina, of verhuist een subboom naar een
  boom van de andere groep, dan krijgt de hele subboom de nieuwe groep, in
  dezelfde transactie. `admin_group` op een kind is daardoor altijd gelijk
  aan die van zijn hoofdpagina.

**Bestaande pagina's zijn *Websitepagina*.** De migratie kijkt niet naar
titels of slugs. `App\Service\LegalPages` kent wel vaste content-keys
(`algemene-voorwaarden`, `verzenden-retourneren`, `privacyverklaring`), maar
geen installatiewizard of bootstrap maakt die pagina's aan; ze bestaan alleen
op installaties die ze zelf hebben. Een redacteur zet ze met één keuze in de
juiste groep.

## 10. Navigatie

Nesten in Pagina's is **niet** automatisch nesten in het menu. Het menu blijft
wat Header & navigatie zegt (`HEADER-FOOTER.md`); een kind kan er wel of niet
in, als eigen item of als submenu-item, zoals elke pagina. Een menu-item naar
een pagina-id krijgt vanzelf het geneste pad. Het menu heeft zelf drie niveaus
(`HEADER-FOOTER.md`, "Drie niveaus"), los van hoe diep een pagina staat.

## 11. Querygedrag

`PagePath` laadt de hele boom in **één** query
(`PageRepository::findStructure()`) en alle slugs in **één** meer, de eerste
keer dat een genest pad nodig is, en houdt beide vast voor de rest van het
verzoek. Een hoofdpagina alleen kost niets extra: haar pad is haar eigen slug.
Twintig geneste pagina's kosten twee queries, net als één
(`Tests\Service\PageNestingTest`); zonder die voorlading waren het er 22.

## 12. Tests

| Test | Suite | Wat |
|---|---|---|
| `Tests\Service\PagePathTest` | `unit`, `fast`, `cms` | paden per taal, onvertaalde voorouder, lus, diepte, vaste URL, boomvolgorde, groep, geldige ouders, `pathMoves()` |
| `Tests\Service\PageNestingTest` | `cms` | schema, foreign key, weigeren verwijderen, cyclus, volgorde, `forPath()`, kruimelpad, sitemap, subboom-redirects in twee talen, handmatige redirect, querytelling |
| `Tests\Service\PageNestingHttpTest` | `cms` | via de dispatcher: 200/404 per pad en taal, canonical/hreflang/x-default, taalwisselaar, kruimelpad, sitemap; editor, bevestiging, verplaatsen met 301's, lus, verwijderen, groepen, overzicht en zoeken, nieuwe subpagina |
| `Tests\Install\PageNestingMigrationTest` | `migration`, `cms` | verse en bijgewerkte installatie gelijk, tweede run verandert niets, bestaande pagina's ongewijzigd |
