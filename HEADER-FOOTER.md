# Header en footer

Wat er in de gedeelde header en footer instelbaar is, en waar de grens ligt.
Het **kruimelpad** staat er sinds fase 5B ook in: het is dezelfde soort
gedeelde schil, van Core, met precies één keuze voor de beheerder.
Lees dit samen met `PROJECT-MAP.md` (waar iets staat). Voor kleuren en
lettertypes zie `THEMING.md`; voor moduleslots `MODULES.md`.

## De grens

**De structuur is van Core. De inhoud is van de beheerder.**

| Van Core, niet instelbaar | Van de beheerder |
|---|---|
| Skip-link, merkblok, plaats van de navigatie, mobiele menumechaniek, sticky gedrag, de taalwissel, moduleslots | Menu-items en headerknoppen (Header & navigatie); bedrijfsblok, footerkolommen en -links, social profielen, slotregel en copyright (Footer); bedrijfsgegevens en logo's (Instellingen); kleuren (Vormgeving) |
| Waar de knoppen staan, hoe ze eruitzien, waar de slotregel staat, hoe een social-icoon eruitziet, welke netwerken er zijn | Óf er knoppen zijn, hoeveel, in welke volgorde, wat erop staat, waar ze heen gaan en welke van twee stijlen; welke bedrijfsgegevens de footer toont; óf de slotregel er is en wat er staat; welke social profielen er zijn, in welke volgorde, en of ze zichtbaar zijn |

Er zijn geen headerregio's, geen vrije knopvormgeving, geen megamenu, geen
widgetzones en geen vierde menuniveau: het menu heeft er ten hoogste drie. Dit
is een CMS, geen layoutbouwer.

## Waar het staat

| Onderdeel | Waar |
|---|---|
| Opslag menu en headerknoppen | `nav_items` — één tabel, `presentation` zegt link of knop; het label per taal in `nav_item_translations` |
| Opslag footerkolommen en -links | `footer_columns`, `footer_links`; titel en label per taal in `footer_column_translations` en `footer_link_translations` |
| Opslag social profielen | `footer_social_links` |
| Opslag zichtbaarheid en copyright | `site_settings` — sleutels in `App\Service\SiteSettings::DEFAULTS` |
| Opslag footer-omschrijving en slotregel | `site_setting_translations`, één rij per taal, via `App\Service\LocalizedSiteSettings` |
| Menu en headerknoppen (lezen) | `App\Service\NavigationService::header()` |
| Link of knop, en de knopstijlen | `App\Service\NavigationPresentation` |
| Volgorde, opslag | `App\Repository\NavigationRepository`, `FooterRepository`, `FooterSocialLinkRepository` |
| Footerkolommen, zichtbaarheid, slotregel, copyright (lezen) | `App\Service\FooterService` |
| Social profielen: register, adrescontrole, lezen | `App\Service\SocialProfiles` |
| Een bestemming in woorden, en of hij bereikbaar is | `admin/_link_destination.php` (menu én footer) |
| Schermen | **Header & navigatie** (`admin/navigation.php`, `admin/navigation-item.php`); **Footer** (`admin/footer.php`, `admin/footer-column.php`, `admin/footer-link.php`). `admin/header-footer.php` is alleen nog een doorverwijzing naar Footer |
| Opslaan, header | `api/admin/create-nav-item.php`, `update-nav-item.php` (regels in `_nav_item_input.php`), `move-nav-item.php`, `reorder-nav-items.php`, `toggle-nav-item.php`, `delete-nav-item.php` |
| Opslaan, footer | `update-footer-settings.php` (secties `brand` en `bottom`); de kolommen en links met `create-`, `update-`, `toggle-`, `move-`, `reorder-` en `delete-footer-column.php` en `-footer-link.php` (regels in `_footer_link_input.php`); de social profielen met `create-`, `update-`, `move-` en `delete-footer-social-link.php` (regels in `_footer_social_link_input.php`) |
| Rendering | `partials/header.php` (de menulijst zelf in `partials/main-nav-list.php`), `partials/footer.php` |
| Submenu's openen en sluiten, links of rechts | `initNavDropdowns()` in `assets/js/core.js` |
| Styling | `.main-nav__*`, `.header-buttons` en `.social-row` in `assets/css/core.css` |

`site_settings` en niet `theme_settings`: dit is wie de site *is*, niet hoe hij
er *uitziet*. "Standaardvormgeving herstellen" mag nooit een knoptekst of een
Instagram-adres meenemen — zie de tabel bovenaan `THEMING.md`. Voor de
headerknoppen geldt hetzelfde, en die staan in `nav_items`, waar
Vormgeving niet bij kan.

## De taalwissel

Staat links van de knoppen, en verschijnt **alleen op een site die meer dan één
actieve taal heeft**. Op een eentalige site rendert de header er geen — geen
leeg besturingselement en geen extra tabstop. Welke talen erin staan en in
welke volgorde komt uit het talenregister; de vormgeving en de plaats zijn van
Core.

Sinds Multilingual 2.0 fase 6 is het **een rij links**, geen knoppen: elke taal
heeft eigen URL's, dus wisselen is navigeren naar dezelfde pagina in die taal
(`App\Service\Routing\LanguageSwitch`, `docs/multilingual/ROUTING.md`). Een
taal waarin de huidige pagina niet bestaat blijft zichtbaar maar is niet
aanklikbaar — geen link die 404't en geen stille omweg naar een andere taal.

## Het menu en de knoppen: één itemmodel

Alles wat een beheerder bovenaan elke pagina zet, is een rij in `nav_items`:
een tekst per taal, een bestemming, zichtbaar of niet, en een plek in de
volgorde. Of die rij een **link in het menu** of een **knop in de header** is,
zegt één kolom:

```text
presentation     link    in de lijst van het menu (elk item van vóór fase A)
                 button  rechts in de header, na de taalwissel en de moduleslots
button_variant   primary gevulde knop (.btn), wat de ene headerknop altijd was
                 ghost   knop met alleen een rand (.btn--ghost)
                         alleen gelezen bij presentation = button
```

Twee gesloten lijsten in `App\Service\NavigationPresentation`. De CSS-klasse
komt uit die klasse, nooit uit de database, en het zijn bestaande klassen uit
`core.css`: geen nieuwe knopvormgeving.

### Waarom één model en geen tweede tabel

De headerknop had al precies de vorm van een menu-item: dezelfde
linkvelden, door dezelfde `App\Service\LinkResolver`. Wat hij miste was een
volgorde, een zichtbaarheidsschakelaar en de mogelijkheid om er meer dan één
te hebben, en `nav_items` heeft die alle drie. Eén model levert daarbij gratis:

- **dezelfde bestemmingen**: een pagina (op id), een vast onderdeel
  (`RouteRegistry`), een ander adres;
- **dezelfde veiligheid**: een pagina op concept of verwijderd, of een route of
  pagina van een uitgeschakelde module, laat de knop verdwijnen in plaats van
  naar een 404 te wijzen;
- **dezelfde paginaverwijzingen**: `PageUsage` toont een knop in de lijst vóór
  een adreswijziging, en `PageService::references()` weigert een pagina te
  verwijderen zolang er een knop naar wijst — precies zoals bij een menu-item.

Wat een knop **niet** kan, bewaken `NavigationPresentation::errors()` en
`api/admin/_nav_item_input.php`: geen knop in een submenu, geen knop zonder
bestemming (*Nergens heen* is alleen een kop boven een submenu), en een link
die nog submenu-items heeft kan geen knop worden.

### De bestemming

```text
link_type   page      target_page_id   een pagina van deze website
            route     target_route     een vast onderdeel (RouteRegistry)
            external  external_url     https://… of een pad dat met / begint
            none      —                alleen een kop boven een submenu
```

Alleen het veld dat bij de gekozen soort hoort wordt opgeslagen; de andere
twee worden leeg. Het scherm noemt deze velden nooit bij hun technische naam:
*Een pagina van deze website*, *Een vast onderdeel van de website*, *Een ander
adres*.

**Verdwijnen is niet vergeten.** Een item waarvan de bestemming nu niet
bestaat, blijft bewaard. Het overzicht zegt *Niet op de website* en de editor
zegt waarom. Een opgeslagen route van een uitgeschakelde module blijft in de
editor geselecteerd, en opslaan houdt haar vast; zet je de module weer aan,
dan staat het item er weer zonder dat iemand iets opnieuw invult.

### Volgorde

Per groep: één ouder (het hoogste niveau of één submenu) **en** één
presentatie. Het menu en de knoppen hebben dus elk hun eigen volgorde, en een
knop schuift nooit tussen twee menulinks door.

- **↑ en ↓** op elke rij (`move-nav-item.php`, `NavigationRepository::move()`):
  werkt met het toetsenbord, op een telefoon en zonder JavaScript.
- **Slepen** blijft voor een muis (`reorder-nav-items.php`, met `presentation`
  erbij). De sleepgreep is `aria-hidden`; ↑ en ↓ zijn de toegankelijke weg.
- Wordt een link een knop, of andersom, dan sluit hij achteraan de nieuwe groep
  aan.

### In de header

- **Geen knoppen**: geen `.header-buttons` in de markup.
- **Eén knop**: dezelfde markup als de oude ene headerknop,
  `<a href="…" class="btn btn--sm">`, op dezelfde plek, met de tekst in de
  taal van de pagina.
- **Meerdere knoppen**: naast elkaar in hun eigen volgorde. Een lange tekst
  breekt binnen zijn eigen knop af (maximaal 16rem breed) in plaats van de
  header breder dan het scherm te duwen.
- **Mobiel**: de knoppen staan in `#main-nav`, het paneel dat de menuknop
  opent, onder de links. De rij met taalwissel, winkelwagen en knoppen loopt
  gecentreerd door naar een volgende regel als hij niet past.

Geen tekst in geen enkele taal = geen knop. Geen werkende bestemming = geen
knop.

### Drie niveaus

Het menu heeft **ten hoogste drie niveaus**: een link op het hoogste niveau,
zijn submenu, en één submenu onder een submenu-item.

```text
Diensten                 niveau 1   link (of een kop zonder bestemming)
└── Graveren             niveau 2   link, eventueel met een eigen submenu
    ├── Hout             niveau 3   link, nooit een submenu
    ├── Metaal
    └── Glas
```

- **Opslag.** Er is niets aan het schema veranderd: `nav_items.parent_id` was
  al een gewone verwijzing naar een andere rij, zonder eigen grens. Een
  item op niveau 3 is een rij waarvan de ouder zelf een ouder heeft. Geen
  migratie, geen gewijzigde id's.
- **De grens zit in de code, op één plek.**
  `NavigationRepository::MAX_DEPTH = 3`. `canBeParent()` accepteert alleen
  een menulink op niveau 1 of 2 als ouder, dus `create-nav-item.php` weigert
  een vierde niveau (en een submenu onder een knop) met *Hier kan geen
  submenu-item onder …*. Het overzicht toont *+ Submenu-item* alleen waar dat
  kan, en de editor neemt een ongeldige `?parent_id=` niet over. Dat is een
  weigering op de server, niet alleen een verborgen knop.
- **Geen cycli.** `parent_id` wordt één keer gezet, bij het aanmaken, naar een
  rij die al bestaat, en daarna nooit meer veranderd (`update-nav-item.php`
  verplaatst niets). Een nieuwe rij is nog niemands voorouder, dus er kan geen
  lus ontstaan. `depthOf()` loopt toch hoogstens drie stappen omhoog, zodat
  een met de hand geschreven lus of een te diepe keten geen geldige ouder is.
- **Volgorde** blijft per groep (ouder + presentatie), dus ook per submenu op
  niveau 3: ↑/↓ en slepen werken daar zonder extra code, en een item schuift
  nooit naar een ander niveau.
- **Wat de publieke header leest.** `NavigationService::buildTree()` bouwt de
  boom recursief en stopt na niveau 3; de partial rendert ook nooit dieper.
  Een verborgen of onbereikbaar item neemt zijn hele submenu mee.
- **Een kop zonder bestemming** (*Nergens heen*) kan alleen op niveau 1, zoals
  voorheen: een submenu-item heeft altijd een eigen link.
- **Los van de paginaboom.** Het menu wordt nooit afgeleid uit `pages.parent_id`
  (`docs/pages/NESTING.md`, §10). Een diep geneste pagina kan een link op
  niveau 1 zijn, en een link naar een hoofdpagina kan een submenu van alles
  hebben. Een paginalink krijgt via `LinkResolver` vanzelf het geneste pad.

### Submenu's: link en pijltje

Een item met een submenu bestaat uit **twee aparte bedieningselementen**
(`partials/main-nav-list.php`):

```html
<li class="main-nav__item main-nav__item--has-children">
  <div class="main-nav__row">
    <a class="main-nav__link" href="/diensten">Diensten</a>
    <button type="button" class="main-nav__toggle" aria-expanded="false"
            aria-controls="main-nav-submenu-12" aria-label="Submenu Diensten">⌄</button>
  </div>
  <ul class="main-nav__submenu main-nav__submenu--level-2" id="main-nav-submenu-12">…</ul>
</li>
```

- **De tekst is een gewone link.** Klikken of tikken navigeert, altijd: het
  script vangt geen klik af en roept nergens `preventDefault()` aan. Geen
  `href="#"`.
- **Alleen het pijltje opent en sluit.** Een echte `<button type="button">`
  met `aria-expanded`, `aria-controls` naar het paneel en een naam die het item
  noemt (*Submenu Diensten*, Engels *Diensten submenu*). De naam zegt niet
  *openen*: of het open is, zegt `aria-expanded`, en een schermlezer leest die
  twee samen.
- **Een kop zonder bestemming** heeft geen link om te scheiden. Hij wordt geen
  nep-link: zijn woorden staan in de toggle zelf, die dan het enige
  bedieningselement is, zoals het altijd was.
- Niveau 2 met een eigen submenu is hetzelfde paar; niveau 3 is altijd een
  losse link.
- Het oude `aria-haspopup="true"` is weg: dit is een uitklapmenu (disclosure),
  geen `role="menu"`-widget.

**Eén bron voor open of dicht.** Een submenu is open precies wanneer zijn
`li` de klasse `.is-open` heeft, en `aria-expanded` op de toggle zegt steeds
hetzelfde: `setOpen()` in `assets/js/core.js` zet ze samen, wat het openen of
sluiten ook veroorzaakt. Het paneel en het pijltje in `core.css` lezen niets
anders, dus het pijltje kan het nooit oneens zijn met het submenu. Het script
zet `.is-enhanced` op `.main-nav`; alleen zonder JavaScript opent een
desktopsubmenu op `:hover` en `:focus-within` (terugval, en alle links werken
dan gewoon). `Tests\Service\MainNavMarkupTest` faalt als een `:hover`-regel
buiten die terugval een submenu of pijltje raakt.

| Wat | Desktop | Mobiel (≤ 900px) |
|---|---|---|
| Muis over het item | Opent; de hele tak (item, rij, paneel, flyout) houdt hem open; na verlaten dicht na 180 ms | — |
| Klik op het pijltje | Opent of sluit. Was hij al open door hover, dan zet de klik hem vast; de volgende klik sluit | Opent of sluit, in de lijst eronder |
| Aanraken of pen | Nooit hover: een eerste tik op een link navigeert meteen | Idem |
| Tab | Link, dan pijltje; een dicht submenu is geen focusstop | Idem (een dicht submenu is `display: none`) |
| Focus in de tak | Houdt hem open, ook als de muis weggaat | Idem |
| Focus verlaat de tak, of klik ernaast | Dicht | Dicht |
| Escape | Sluit het binnenste open submenu met de focus erin, focus terug op zijn pijltje; zonder focus erin gaan alle submenu's dicht | Idem; pas de volgende Escape sluit het mobiele menu |
| Openen | Sluit de open broers en zussen op hetzelfde niveau, zoals voorheen | Idem |
| Pijltje niveau 1 | Omlaag, open omhoog | Idem |
| Pijltje niveau 2 | Wijst naar de kant waar de flyout opent; open draait het terug | Omlaag, open omhoog |

**Niveau 3 op desktop** vliegt uit naast het paneel van niveau 2: standaard
naar rechts, en naar links als hij daar niet past en links meer ruimte is
(`.opens-left`). Het script meet de echte kaders: bij het laden, bij elk
openen en 100 ms nadat een resize is uitgewoed. Het leest eerst alle kaders
van een niveau en schrijft daarna de klassen, niveau 1 eerst omdat niveau 3
aan niveau 2 hangt. Er is geen vaste regel zoals "het laatste item opent
links". Een paneel op niveau 1 dat over de rechterrand zou lopen, lijnt op de
rechterkant van zijn item uit. Een smalle onzichtbare strook tussen item en
paneel hoort bij de tak, zodat de muis onderweg niets sluit.

**Mobiel** kent geen flyouts: niveau 2 en 3 klappen verticaal uit onder hun
eigen rij, elk met een eigen pijltje van minstens 44×44 px. Niveau 3 staat een
stap kleiner op een lichte eigen band, zodat de niveaus in de gecentreerde
kolom uit elkaar te houden zijn. Een lang label breekt af; er is geen
horizontale overflow op 320 of 375 px. Het hoofdmenu sluiten zet alle
submenu's dicht, zoals voorheen.

### De actieve link

Een menulink krijgt `aria-current="page"` als hij de pagina is waar de
bezoeker op staat (`NavigationService::isCurrent()`):

- een **route**-link via de sleutel die het sjabloon als `$activeNav` zet,
  zoals altijd;
- een **pagina**-link op zijn eigen adres, vergeleken met het opgevraagde pad.
  `/` en `/index.php` zijn dezelfde pagina. Dit is nodig sinds
  `20260908260000` Diensten, Portfolio, Over mij en Contact paginalinks maakte:
  die kregen daarna nooit meer een markering.

Een extern adres en een submenukop zijn nooit actief. Een submenu-item krijgt
geen markering; dat is ongewijzigd gebleven. Een link op het hoogste niveau
die zelf een submenu heeft, is sinds Navigation 2.0 een gewone link en krijgt
de markering dus net als elke andere link op dat niveau.

### De oude ene headerknop

Tot fase A was er precies één knop, als acht instellingen in `site_settings`
(`header_cta_*`) op het scherm *Header & footer*. Migratie `20260916230000`
voegde de twee kolommen toe en zette een ingestelde knop éénmalig over als één
item met `presentation = button`:

- tekst, soort bestemming, het bijbehorende veld en *nieuw tabblad* zoals ze
  waren; stijl `primary`, dus dezelfde knop;
- zichtbaar precies als de oude knop zichtbaar was (aan én een Nederlandse
  tekst); een knop die uit stond komt verborgen over, niet weg;
- een pagina-id die niet meer bestaat wordt `NULL` (de foreign key eist het,
  en het rendert niets, net als vroeger);
- een verse installatie heeft geen knop en krijgt er geen.

**De oude rijen blijven staan.** Niets leest of schrijft ze nog: de header leest
`nav_items`, en het scherm dat ze schreef bestaat sinds Footer fase B niet
meer. Ze echt verwijderen is een aparte, destructieve beslissing.

## De footer

Sinds Footer fase B één beheergebied: het scherm **Footer**
(`admin/footer.php`), met vier kaarten in de volgorde waarin ze in de footer
zelf staan.

| Kaart | Wat | Opslag |
|---|---|---|
| **Bedrijfsblok** | welke bedrijfsgegevens de footer toont, en de footer-omschrijving | `site_settings`: `footer_show_*`; de omschrijving per taal in `site_setting_translations` (`footer_description`) |
| **Kolommen & links** | kolommen met links naast het bedrijfsblok | `footer_columns`, `footer_links` |
| **Social media** | de iconen naar je profielen | `footer_social_links` |
| **Slotregel & copyright** | de onderste regel | `site_settings`: `footer_copyright_template`, `footer_slogan_enabled`; de slotregel per taal in `site_setting_translations` (`footer_slogan`) |

### Wie is eigenaar van wat

**Bedrijfsgegevens zijn van Instellingen. De footer beslist alleen of
hij ze toont.**

| Van Instellingen | Van Footer |
|---|---|
| naam van de website (`site_name`), e-mailadres (`email`), telefoonnummer (`company_phone`), KVK-nummer (`kvk_number`), adres, logo en tweede logo (`Branding`) | óf elk van die gegevens in de footer staat (`footer_show_*`), de footer-omschrijving, de kolommen en links, de social profielen, de copyright-tekst en de slotregel |

Het Bedrijfsblok toont de huidige waarde naast elke schakelaar, alleen-lezen,
met een link naar Instellingen voor wie `settings.manage` heeft. Er is
geen tweede editor en geen tweede opslag: een gegeven uitzetten verandert of
verwijdert de waarde nooit, en een gegeven dat nog niet is ingevuld staat ook
met de schakelaar aan niet in de footer.

**De footer-omschrijving heeft één plek.** Tot fase B stond hij ook op
Instellingen → Algemeen. Die editor is weg, en
`App\Service\SiteSettingsValidator::FIELDS` noemt de sleutel niet meer,
zodat `update-site-settings.php` hem ook niet kan schrijven als een oud
formulier hem nog meestuurt. Instellingen zegt op die plek waar hij nu
staat. Sinds Multilingual 2.0 fase 4 staat de tekst per taal in
`site_setting_translations` onder de sleutel `footer_description`; de migratie
heeft de bestaande Nederlandse en Engelse tekst meegenomen. De installatiewizard vraagt de omschrijving nog één keer bij het
inrichten (`SETUP.md`); dat is geen beheerscherm.

### Zichtbaarheid

| Instelling | Schakelt | Standaard |
|---|---|---|
| `footer_show_logo` | het tweede logo (valt terug op het gewone); zonder logo de naam van de website | aan |
| `footer_show_company_name` | de naam van de website als eigen regel | uit |
| `footer_show_email` | het e-mailadres als `mailto:`-link | aan |
| `footer_show_phone` | het telefoonnummer als `tel:`-link | uit |
| `footer_show_kvk` | *KVK* met het nummer | aan |
| `footer_slogan_enabled` | de slotregel | uit |

Daarnaast heeft elke kolom, link en social link een eigen zichtbaarheid. Voor
al deze schakelaars geldt: **verbergen is niet vergeten.** Wat verborgen is
verdwijnt alleen van de website; de tekst, het adres of de waarde blijft
staan en komt ongewijzigd terug zodra de schakelaar weer aan gaat.

De footer-omschrijving en de copyright-tekst hebben geen schakelaar: een lege
omschrijving rendert geen alinea, en een lege copyright-tekst wordt bij het
opslaan de standaard `© {{year}} {{site_name}}`.

### Slotregel & copyright

```text
footer_copyright_template  tekst met {{year}} en {{site_name}}   site_settings
footer_slogan_enabled      '1' / '0'                            site_settings
footer_slogan              de tekst, één rij per websitetaal     site_setting_translations
```

De slotregel en de footer-omschrijving zijn sinds Multilingual 2.0 fase 4
geen `_nl`/`_en`-sleutels meer maar rijen per taal, met de terugval van
`LanguageFallback` (gevraagde taal, dan de standaardtaal, dan leeg). Is de
tekst in de standaardtaal leeg, dan rendert de footer hem niet, in geen enkele
taal.

Staat onderin naast het copyright en de juridische links. Uit of leeg betekent
dat er geen `<span>` gerenderd wordt — geen lege regel.

### Opslaan: twee formulieren, elk met eigen sleutels

`api/admin/update-footer-settings.php` kent een gesloten lijst van twee
secties: `brand` (de vijf `footer_show_*` en de omschrijving) en `bottom`
(copyright en slotregel). Elke sectie schrijft bij elke opslag precies haar
eigen sleutels, uitgezette schakelaars als `'0'` inbegrepen, en nooit een
sleutel van de andere. Een onbekende sectie schrijft niets (400). Beide
formulieren, en elke social link, vallen onder de opslagbalk.

### Kolommen en links

Het bestaande twee-niveaumodel: een kolom (titel per taal, volgorde,
zichtbaar) met links. Kolom verwijderen neemt zijn links mee (`ON DELETE
CASCADE`), en de dialoog van het CMS zegt dat eerst. Een kolom zonder
zichtbare werkende link rendert geen kop; het scherm zegt dan *Niet op de
website*.

**Een footerlink volgt het linkcontract van Header & navigatie.** Dezelfde
`App\Service\LinkResolver`, dezelfde bestemmingen in dezelfde woorden
(`admin/_link_destination.php`, gedeeld met `admin/navigation.php`) en
dezelfde invoerregels (`api/admin/_footer_link_input.php`, de footer-kopie van
`_nav_item_input.php`):

```text
link_type   page      target_page_id   een pagina van deze website (op id)
            route     target_route     een vast onderdeel (RouteRegistry)
            external  external_url     https://… of een pad dat met / begint
            action    action_key       een handeling op de pagina zelf; alleen
                                       cookie_preferences
```

- Een interne pagina wordt op **id** opgeslagen, nooit als adres; het adres
  komt per render uit `PageContent::publicUrl()`, dus een nieuwe slug loopt
  vanzelf mee.
- Alleen het veld van de gekozen soort wordt opgeslagen.
- **Module uit:** een route of modulepagina die nu niet bestaat, rendert niets.
  De editor houdt de opgeslagen route geselecteerd (*Een onderdeel dat nu uit
  staat*), waarschuwt waarom de link niet op de website staat, en opslaan
  houdt haar vast. Tot fase B selecteerde de editor stil de eerste route uit
  de lijst, waarmee een labelcorrectie de bestemming wijzigde.
- `PageUsage` noemt een footerlink naar een pagina vóór een adreswijziging, en
  `PageService::references()` weigert een pagina te verwijderen zolang er een
  footerlink naar wijst. Social links en kolommen doen daar niet aan mee: ze
  wijzen nooit naar een pagina.

**Volgorde.** ↑ en ↓ op elke kolom en elke link
(`move-footer-column.php`, `move-footer-link.php`,
`FooterRepository::moveColumn()`/`moveLink()`): toetsenbord, telefoon, geen
JavaScript. Een link beweegt alleen binnen zijn eigen kolom; die komt uit de
rij, nooit uit het verzoek. Slepen blijft voor een muis
(`reorder-footer-columns.php`, `reorder-footer-links.php`); een kolom sleept
samen met zijn links, en de sleepgreep is `aria-hidden`. `sort_order` op de
server beslist.

## Social profielen

Herhaalbare rijen in `footer_social_links`, sinds migratie `20260917100000`:

```text
id          int unsigned
network     varchar(30)    sleutel uit SocialProfiles::NETWORKS
url         varchar(2048)  het adres, getrimd en verder zoals ingevoerd
sort_order  int            één lijst, één volgorde
is_visible  boolean        verborgen = niet op de website, wel bewaard
created_at, updated_at
```

Taalneutraal: een netwerk en een adres hebben geen vertaling. Geen unieke
index op `network`: **twee accounts op hetzelfde netwerk mogen**. De footer
geeft ze dan een volgnummer in hun toegankelijke naam (*Mygdala op Instagram
(1)*, *(2)*), zodat een schermlezer de twee links uit elkaar houdt. Het icoon
blijft hetzelfde.

Het netwerk komt uit een **gesloten lijst** in `SocialProfiles::NETWORKS`:
Instagram, Facebook, Pinterest, LinkedIn, YouTube, TikTok, Etsy. Gesloten om
dezelfde reden als `ThemeFonts` en `ModuleRegistry`: een beheerder kiest,
niemand typt ooit een netwerknaam, een iconklasse of een stuk SVG. Het label,
het icoon en de domeincontrole komen alle drie uit dat register.

### Het adres

Eén methode, `SocialProfiles::isValidProfileUrl()`, voor het scherm én de
site: `api/admin/_footer_social_link_input.php` weigert er een adres mee, en
`SocialProfiles::forFooter()` slaat een opgeslagen rij die niet slaagt over.
Er is geen tweede regel die kan afwijken.

- het netwerk is een sleutel uit het register;
- `https://` en niets anders — dat sluit `javascript:`, `data:`, `http:` en
  een relatief pad in één regel uit;
- een parseerbare URL met een host, zonder inloggegevens erin, hoogstens 2048
  tekens; letters als é of ü in het pad mogen;
- de host hoort bij het netwerk: de **merknaam** op `.com`, op een
  tweeletterig landdomein of op de `co.`/`com.`-vorm daarvan, of een van de
  **eigen korte domeinen**, telkens met elk subdomein.

```text
instagram  instagram.*   instagr.am
facebook   facebook.*    fb.com, fb.me
pinterest  pinterest.*   pin.it
linkedin   linkedin.*    lnkd.in
youtube    youtube.*     youtu.be
tiktok     tiktok.*
etsy       etsy.*
```

Dus `www.pinterest.de`, `nl.pinterest.com`, `pinterest.co.uk` en
`pinterest.com.au` mogen, en `facebook.evil.example`, `instagram.com.evil.com`,
`facebook.xyz` en `pin.nl` niet.

**Wat er tot fase B mis was.** De oude controle vergeleek alleen het label
vóór de laatste punt, met een paar korte namen erbij. Dat weigerde elk
landdomein met twee delen (bij `pinterest.co.uk` is dat label `co`) en
accepteerde elk domein waarvan dat label toevallig een korte naam was:
`pin.nl` als Pinterest, `linked.com` als LinkedIn, `fb.org` als Facebook, en
de merknaam op elk willekeurig topleveldomein. Ook een pad met een é werd
geweigerd. `HeaderFooterSettingsTest` houdt beide kanten vast.

### Beheer

Op de kaart *Social media* is elke rij een klein eigen formulier: netwerk
(`.admin-select`), adres, *Tonen op de website* (switch) en *Opslaan*, met ↑/↓
(`move-footer-social-link.php`) en *Verwijderen* in de dialoog van het CMS
ernaast. Een geweigerd adres komt terug in dezelfde rij, met de melding, het
ingevoerde adres en `aria-invalid`; de rij begint dan als niet opgeslagen. Een
nieuw profiel voeg je onderaan toe; het is meteen zichtbaar en sluit achteraan
aan. Een opgeslagen rij waarvan het adres niet (meer) door de controle komt,
zegt *Niet op de website*.

### In de footer

Alleen zichtbare rijen die door de controle komen, in `sort_order`. Zonder
zulke rijen rendert de footer géén rij en géén kop. Elke link opent in een
nieuw tabblad met `rel="noopener noreferrer me"`, draagt een eigen
`aria-label` in de taal van de pagina, en de `<svg>`
staat op `aria-hidden`. `PageSeo` claimt dezelfde profielen als `sameAs`, elk
adres één keer.

De iconen zijn van dit project: 24x24 stroke-glyphs in dezelfde stijl als de
adminzijbalk, in `SocialProfiles::NETWORKS`. Geen iconfont, geen stylesheet van
derden, geen buildstap, geen runtime-download — de footer moet het doen op
gedeelde hosting met alleen PHP.

### Een netwerk toevoegen

1. Eén regel in `SocialProfiles::NETWORKS`: sleutel, label, de merknamen en
   eigen korte domeinen die de host mag hebben, en het icoon.
2. Klaar — het scherm, de controle en de footer lezen allemaal het register.
   Een migratie is niet nodig, en er komt geen `social_*_url`-instelling bij.
   Houd de lijst klein.

### De migratie van de oude instellingen

Tot fase B was er één optionele URL per netwerk, als zeven instellingen
`social_<netwerk>_url` in `site_settings`. Migratie `20260917100000`:

- maakt `footer_social_links` aan als hij nog niet bestaat (schema eerst en
  altijd);
- zet elke ingevulde instelling om in **één zichtbare rij**: hetzelfde
  netwerk, het adres getrimd (wat het oude endpoint opsloeg en de footer
  toonde), in de volgorde van het register, want dat was de enige volgorde
  die een site had;
- slaat een lege waarde of alleen spaties over, en een waarde langer dan de
  kolom (geen echt profieladres) — die instelling blijft gewoon staan;
- neemt een adres dat de strengere controle nu weigert tóch over, zodat het
  op het scherm als *Niet op de website* verschijnt in plaats van stil te
  verdwijnen;
- kopieert niets zodra de tabel al een rij heeft, dus een tweede run voegt
  niets toe; alles in één INSERT, dus een kopie is heel of afwezig;
- schrijft de zeven sleutels uit in plaats van het register te lezen, zodat
  een later toegevoegd netwerk nooit verandert wat de migratie deed;
- laat de oude instellingen staan.

Een verse installatie heeft geen social-instellingen en krijgt dus geen rij.

**Legacy.** De zeven `social_*_url`-rijen blijven fysiek in `site_settings` en
staan nog in `SiteSettings::DEFAULTS`. Niets leest of schrijft ze: de footer en
`PageSeo` lezen `footer_social_links`, het scherm schrijft daar ook. Ze echt
verwijderen is een aparte, destructieve beslissing. De acht
`header_cta_*`-sleutels zijn wél verdwenen — fase 4 van Multilingual 2.0
(`20260918130000`) heeft ze weggehaald, omdat hun enige lezer de migratie was
die de headerknoppen naar `nav_items` bracht.

## Standaarden: bestaande site versus verse installatie

Dezelfde afspraak als bij branding (`THEMING.md`): de **codestandaard is
generiek** en een **migratie heeft de huidige waarden vastgezet**.

```text
code    geen headerknoppen (een verse installatie krijgt er geen)
        slotregel uit, leeg
        geen social profielen (footer_social_links leeg)

rij     migratie 20260909220000 schreef "Vraag offerte aan" /
        "Request a quote" naar de Contact-pagina, en de slotregel,
        als echte rijen — INSERT IGNORE, dus een bestaande rij
        wint altijd; migratie 20260916230000 zette die knop over
        naar nav_items; migratie 20260917100000 zette ingevulde
        social-URL's over naar footer_social_links
```

Social profielen worden nooit verzonnen: een verse installatie heeft er geen en
krijgt er geen, en een bestaande site houdt precies de profielen die hij had.

Let op één eigenaardigheid van `SiteSettings::all()`: een opgeslagen **lege**
waarde valt terug op de standaard. Dat werkt alleen omdat elke standaard hier
leeg of `'0'` is. Geef een nieuwe sleutel in deze familie dus nooit een
niet-lege codestandaard, anders kan een beheerder hem niet leegmaken.

## Footer fase B

Tot fase B stond de footer verspreid over drie schermen: *Footer* (kolommen,
links, het bedrijfsblok en de copyrighttekst), *Slotregel & social media* en
*Instellingen* (de footer-omschrijving, die daar én op *Footer* te
bewerken was). Social profielen waren zeven vaste instellingen zonder eigen
volgorde of zichtbaarheid, en kolommen en links waren alleen met een muis te
ordenen. Wat fase B veranderde:

- **Eén scherm Footer**, met de kaarten hierboven. De zijbalk heeft één
  footer-item; `admin/header-footer.php` verwijst met een 302 door naar
  `admin/footer.php#footer-bottom`, en `update-header-footer-settings.php`
  bestaat niet meer.
- **De footer-omschrijving op één plek**, zonder schemawijziging.
- **Social profielen als rijen** in `footer_social_links`, met migratie van de
  oude instellingen en een gerepareerde adrescontrole.
- **↑/↓** voor kolommen, links en social profielen; de CMS-dialoog in plaats
  van `confirm()`; switches, `.admin-select` en de opslagbalk op alle drie de
  schermen.
- **Footerlinks houden een route van een uitgeschakelde module vast**, net als
  menu-items.

Bewust niet gedaan: een nieuw taalmodel (de `*_nl`/`*_en`-velden blijven zoals
ze zijn, en de nieuwe tabel is taalneutraal), extra netwerken, een footer-
vormgeving en het verwijderen van de legacy-instellingen.

## Het kruimelpad

De kleine regel bovenaan een pagina die laat zien waar een bezoeker is:
`Home / Contact`.

### Waar het vandaan komt

Het stond tot fase 5B in de partial van de **Paginakop**. Dat betekende dat
een pagina zonder dat blok, of met dat blok verborgen, ook geen kruimelpad
had — terwijl het niets met een kop te maken heeft. Veertien sjablonen
schreven bovendien hun eigen kopie, met drie verschillende spellingen van de
link naar de homepage, zonder `<nav>`, zonder lijst, met een scheidingsteken
dat werd voorgelezen en met een huidige pagina die soms naar zichzelf linkte.

### De grens

| Van Core, niet instelbaar | Van de beheerder |
|---|---|
| De markup, de plek op de pagina, de vormgeving, het woord *Home*, welke niveaus een route heeft | Óf een pagina zijn kruimelpad toont |

De **naam** in het kruimelpad is de titel van de pagina zelf, per render
gelezen, in de taal van de pagina. Er wordt niets gekopieerd: hernoem je een pagina, dan
verandert het kruimelpad mee, en vertaal je hem, dan vertaalt het kruimelpad
mee. De titel staat per websitetaal in `page_translations` en wordt gelezen
via `App\Service\PageLocalization::value()`, de enige plek die de opslag
en de terugval kent (`docs/multilingual/ARCHITECTURE.md`). Een lege vertaling
betekent "hetzelfde als de standaardtaal", nooit een lege naam.

De **slug hoort bij de taal** sinds Multilingual 2.0 fase 6: elke taalversie
van een pagina heeft haar eigen adres, en het kruimelpad linkt naar het adres
van de taal waarin de pagina gelezen wordt (`docs/multilingual/ROUTING.md`).
Een adres wordt alleen automatisch gemaakt wanneer het veld leeg is op het
moment dat die taalversie voor het eerst wordt opgeslagen — uit de titel van
díé taal — en daarna nooit meer uit een gewijzigde titel.

### Waar het staat

| Onderdeel | Waar |
|---|---|
| Eén niveau | `App\Service\Breadcrumbs\BreadcrumbItem` — label NL/EN en een adres, of geen adres |
| Het hele pad | `App\Service\Breadcrumbs\BreadcrumbTrail` — `home()`, `to()`, `toPage()`, `toRoute()` |
| Een gewone CMS-pagina | `App\Service\Breadcrumbs\PageBreadcrumb::forPage()` |
| Opslag van de naam | `page_translations.title`, één rij per taal (vertaling optioneel; geen rij = niet vertaald) |
| Opslag van de keuze | `pages.show_breadcrumb` (`NOT NULL DEFAULT 1`) |
| Scherm | Pagina bewerken → tabblad **Pagina** (`admin/page.php`) |
| Opslaan | `api/admin/update-page.php` |
| Rendering | `partials/breadcrumb.php`, functie `render_breadcrumb()` |
| Styling | `.breadcrumb-bar` en `.breadcrumb` in `assets/css/core.css` |

### Wie stelt het pad samen

**De route, nooit Core.** Een domeinroute weet welke niveaus hij heeft; Core
weet hoe je die opschrijft. `BreadcrumbTrail` kent daarom geen enkele
repository van de Shop, de Blog of het Portfolio, en dat mag zo blijven
(`MODULES.md`).

```php
render_breadcrumb(
    BreadcrumbTrail::home()
        ->toRoute('shop')
        ->to(BreadcrumbItem::current($naamNl, $naamEn))
);
```

- `toPage('portfolio')` hangt er een **CMS-pagina** onder: haar eigen titel,
  haar eigen adres. Een pagina in concept of van een uitgezette module houdt
  haar naam maar verliest haar link.
- `toRoute('cart')` hangt er een **applicatieroute** onder, met het label en
  het adres uit `App\Service\RouteRegistry`. Een sleutel die het register
  niet kent voegt niets toe, zodat een pad nooit naar een 404 wijst.
- `toPage('shop', 'shop')` combineert de twee: de Shop-pagina als die bestaat,
  anders de gelijknamige route. Een installatie waarvan de winkel de
  moduleoverzichtspagina is en geen `pages`-rij heeft
  (`INSTALL-BOOTSTRAP.md`) houdt zo hetzelfde niveau.
- De **homepagelink** komt uit `PageContent::publicUrl()` van de siteroot:
  één spelling, dezelfde resolutie als het menu, de canonical en de sitemap.
- Een niveau dat een bezoeker leeg zou zien valt weg, en een pad met alleen
  *Home* erin rendert niets.

### Per routegroep

| Groep | Kruimelpad | Schakelaar |
|---|---|---|
| Siteroot (`/`) | Nooit — je begint er | — |
| Gewone CMS-pagina, en de sjablonen Diensten, Portfolio, Over mij, Contact, Shop | `Home / paginatitel`; een geneste pagina `Home / elke bovenliggende pagina / paginatitel`, uit dezelfde `parent_id`-keten als haar URL (`docs/pages/NESTING.md`) | `pages.show_breadcrumb` |
| Shop-routes: product, collectie, winkelwagen, afrekenen, bestelstatus, personaliseren | Vast, met hun eigen niveaus; het Shop-niveau is de Shop-pagina zelf | Geen — geen `pages`-rij |
| Blog: overzicht, archief, bericht | Vast, met de blogtitel uit `BlogSettings` | Geen |
| Cookiebeleid, Herroepingsrecht | Vast, uit `RouteRegistry` | Geen |
| 404 | `Home / Pagina niet gevonden` | Geen |
| Portfolio legacy-detail | Alleen op de niet-gevonden-tak; een gevonden project houdt zijn eigen terugkoppeling | Geen |

Een vaste applicatieroute is geen pagina die een beheerder beheert, dus er is
ook niets om daar uit te zetten.

### De plek op de pagina

Het kruimelpad is het **eerste** in `<main>`, vóór de inhoud, in een eigen
`.breadcrumb-bar`. Die balk draagt de ruimte die de vaste siteheader nodig
heeft — dezelfde `clamp()` die `.page-hero` had — en een Paginakop die er
direct op volgt laat zijn eigen bovenruimte weg. Op een gewone pagina staat
de titel daardoor op dezelfde hoogte als voorheen.

Heeft de **eerste** Paginakop van de pagina een **afbeelding**, dan neemt die
het kruimelpad in zich op (Paginakop 2.0). De route geeft het pad daarvoor
mee aan `SectionRegistry::renderPage($contentKey, PageBreadcrumb::forPage($page))`
in plaats van het zelf te printen, en `renderPage()` vraagt het eerste blok
dat rendert of het het pad draagt (`App\Service\Blocks\CarriesBreadcrumb`):

| Eerste blok | Kruimelpad |
|---|---|
| Paginakop met een foto **achter** de tekst | Bovenin de fotoband, óver de foto, direct onder de vaste siteheader. De band begint bovenaan de pagina; de lege balk erboven is weg. Over de foto krijgen de links de tekstkleur in plaats van de gedempte kleur, want de waas is lichter dan de grond waarvoor die gedempte kleur gemeten is (gemeten op een witte foto: 7,5:1 of meer) |
| Paginakop met een foto **naast** de tekst | Bovenaan de tekstkolom, zonder eigen container, want de kolom lijnt het al uit |
| Paginakop zonder foto, een verborgen Paginakop, of een ander blok | Ongewijzigd: een eigen `.breadcrumb-bar` vóór het blok, zoals hierboven |
| Geen enkel blok | De balk op zichzelf |

Het pad staat dus altijd precies één keer op de pagina, en of het er staat
blijft de keuze van de pagina (`pages.show_breadcrumb`). Alleen het eerste
blok wordt gevraagd: een Paginakop verderop op de pagina neemt het pad niet
naar beneden mee. Het pad komt ongewijzigd mee, hoe lang het ook is: een
geneste pagina met haar bovenliggende pagina's staat in de band met elk
niveau, precies zoals in de losse balk.

### Legacy

`page_heroes.breadcrumb_label_nl` en `breadcrumb_label_en` bestaan niet meer.
Wat een redacteur ooit in het **Engelse** label typte, is éénmalig overgenomen
als `pages.title_en` (migratie `20260916140000`; sinds `20260917150000` de rij
`en` in `page_translations`), want dat was de enige plek waar de Engelse naam
van een pagina kon staan; alleen waar de pagina nog bestaat, `title_en` nog
leeg was en het label iets anders zei dan de Nederlandse titel. Daarna las en
schreef niets de kolommen nog, en Multilingual 2.0 fase 3B heeft ze gedropt
(`20260917180000`) zonder ze te verhuizen. Het veld *Naam in het kruimelpad*
is uit de Paginakop-editor verdwenen.

## Testen

```bash
docker compose exec php      php vendor/bin/phpunit --testsuite fast
docker compose exec php_test php vendor/bin/phpunit --testsuite cms
```

Het kruimelpad zit in `cms` (en in `blocks`, omdat de Paginakop meeveranderde):
`Tests\Service\BreadcrumbTest` houdt de markup, de niveaus, de schakelaar en de
onafhankelijkheid van de Paginakop vast, `Tests\Service\ProductBreadcrumbTest`
(suite `shop`) dat het productpad server-side staat en `shop.js` het niet meer
schrijft.

`fast` bevat `NavigationServiceTest` (menu tot drie niveaus, knoppen en de
actieve link, zonder database), `MainNavMarkupTest` (de menulijst uit een
verzonnen boom: link en pijltje apart, de ARIA, geen vierde niveau, en de
regels in `core.js` en `core.css` die de ene open-toestand bewaken),
`NavigationPresentationTest` (de twee gesloten lijsten en wat een
knop niet mag), `HeaderFooterSettingsTest` (slotregel, het register van
netwerken, de adrescontrole met de regressies van fase B, en wat
`SocialProfiles::forFooter()` van rijen maakt, zonder database) en
`HeaderFooterContractTest` (geen sitespecifieke tekst of bestemming in de
gedeelde schil, de knoppen uit de navigatie, het oude scherm alleen nog een
doorverwijzing, en niets dat de oude social-instellingen leest of schrijft).
`cms` voegt toe:

- `NavigationRepositoryTest` — opslaan, de volgorde per groep (ook op niveau
  3), ↑ en ↓, de diepte en geen ouder op niveau 3 of in een lus;
- `NavigationAdminHttpTest` — het scherm en zijn endpoints over echt HTTP, en
  wat de publieke header daarvan maakt, ook met de Shop uit; niveau 3 opslaan,
  niveau 4 geweigerd, en link plus pijltje op elk niveau met een geneste
  pagina als bestemming;
- `FooterRepositoryTest` — kolommen en links, ↑ en ↓ binnen de eigen kolom;
- `FooterSocialLinkRepositoryTest` — social rijen: opslaan, één volgorde, ↑ en
  ↓, en wat de footer van echte rijen maakt;
- `FooterAdminHttpTest` — het Footer-scherm en zijn endpoints over echt HTTP:
  de vier kaarten, de omschrijving op één plek, social toevoegen, bewerken,
  verbergen, ordenen, verwijderen en weigeren, twee keer hetzelfde netwerk,
  interne en externe links, een link van een uitgeschakelde module, de
  slotregel en schakelaars die niets kwijtraken, en de doorverwijzing;
- `HeaderFooterRenderingTest` — een knop naar een CMS-pagina tegen echte rijen,
  en wat een pagina echt rendert, ook op de CMS-only deployment;
- `HeaderButtonMigrationTest` en `FooterSocialLinkMigrationTest` (ook in
  `migration`) — de overzetting van de oude knop en van de oude
  social-instellingen op wegwerpdatabases.

`LegacyUpgradeTest` (suite `migration`) laat zien dat een bestaande site na
alle migraties precies één zichtbare knop naar de Contact-pagina heeft. Zie
verder `TESTING.md`.

Raakte je een **taalveld** van de navigatie of de footer aan — een menulabel,
een kolomtitel, een footerlink of de footertekst — dan houden
`Tests\Repository\NavigationFooterTranslationTest` en
`Tests\Repository\LocalizedSiteSettingsTest` (suite `cms`) vast dat het opslaan
van de ene taal de andere niet overschrijft, en `MULTILINGUAL.md` en
`docs/multilingual/ARCHITECTURE.md` beschrijven de regel erachter.
