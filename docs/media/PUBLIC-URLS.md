# Publieke media-adressen — audit en voorstel

Status: **voorstel, niet gebouwd.** Media Library 2.0 (`MEDIA.md`) laat het
publieke adres van een media-item ongemoeid. Dit document legt vast waarom, en
hoe een leesbaar adres later wél veilig kan landen.

## De vraag

In de frontend staat nu bijvoorbeeld:

```html
<img src="/assets/media/3d5e293d70b96692160d17d04ba6ba7c.webp" alt="…">
```

Gewenst is een adres dat leest als de naam die de redacteur in het CMS gaf,
conceptueel `/media/842/gegraveerde-houten-snijplank.jpg`, waarbij `842` de
stabiele identiteit is en de naam alleen leesbaar maakt. Een naam wijzigen
mag bij voorkeur niet betekenen dat een bestand verhuist.

## Hoe het nu werkt (audit)

| | Nu |
|---|---|
| Identiteit | `media.id` |
| Opgeslagen bestand | `media.path` = `assets/media/<32 hex>.<ext>`, willekeurig, nooit een getypte naam; thumbnail `assets/media/thumbs/<zelfde>` |
| Publiek adres | `'/' . path` (`MediaItem::publicPath()`), statisch geserveerd: de root-`.htaccess` laat alles onder `/assets/` ongemoeid (`RewriteRule ^ - [L]`), dus Apache levert het bestand zonder PHP |
| Hangt het adres aan de naam? | **Nee.** Het hangt aan het opgeslagen bestand. Een naam wijzigen of een item naar een map verplaatsen verandert geen adres |
| Overgenomen oude beelden | blijven op `assets/images/…` en houden dat adres |
| Wie drukt een `src` af? | ruim veertig lezers. Blokken via `BlockImage::fromOwner()` (het pad van het item, anders de meegeschreven padkolom), `Branding` (logo's, favicon, deel-afbeelding), de Blog, productfoto's en collecties (`product_images.image_path`, `collections.image_path`: **meegeschreven** padkolommen), Portfolio (`portfolio_gallery_items.image_path`, meegeschreven), deel-afbeeldingen (`og_image_path`, meegeschreven), de Homepage Hero, `srcset`-loze `<img>`'s in partials, JSON-LD en Open Graph (absolute URL's), de sitemap van afbeeldingen niet |
| Opgeslagen HTML | rich text kan een `/assets/media/…`-adres bevatten dat een redacteur plakte; dat adres staat dan letterlijk in de database |

De kern: **een beeldadres wordt op veel plekken uit een padkolom afgedrukt,
niet uit het media-item.** Die meegeschreven kolommen (`image_path`,
`og_image_path`, …) zijn een bewuste terugval (`MEDIA.md`, "Hoe een feature
naar media verwijst") en bevatten het opgeslagen pad.

## De opties

| | A. Bestand hernoemen bij een nieuwe naam | B. Stabiel id + leesbare slug, geleverd door PHP | C. Huidig adres houden | D. Leesbaar achtervoegsel, geleverd door Apache |
|---|---|---|---|---|
| Voorbeeld | `/assets/media/gegraveerde-houten-snijplank.jpg` | `/media/842/gegraveerde-houten-snijplank.jpg` | `/assets/media/3d5e…7c.webp` | `/assets/media/3d5e…7c/gegraveerde-houten-snijplank.webp` |
| Terugwaartse compatibiliteit | slecht: elk oud adres breekt, of een redirecttabel per hernoeming | goed als het oude adres blijft werken (het bestand staat er nog) | volledig | volledig: het oude adres ís het bestand |
| Bestaande HTML/links | breken (rich text, gedeelde links, e-mails) | blijven werken; nieuwe HTML krijgt het nieuwe adres | ongewijzigd | blijven werken |
| Browser-/CDN-cache | nieuw adres per hernoeming; oude wordt 404 | nieuw adres per hernoeming, oude kan 301 | ongewijzigd, lang cachebaar | nieuw adres per hernoeming, oude blijft geldig |
| SEO (beeldzoeken) | leesbare naam | leesbare naam, 301 naar de actuele slug is mogelijk | alleen alt-tekst en context | leesbare naam; een verouderde slug blijft gewoon werken (geen 301) |
| Redirects | per hernoeming nodig | één regel: verkeerde slug → 301 naar de juiste | geen | geen nodig |
| Performance | statisch | **elk beeld door PHP** (readfile, headers, Range voor video): op gedeelde hosting merkbaar | statisch | statisch (één RewriteRule) |
| Opslag | bestand verhuist: database en schijf moeten tegelijk goed gaan | ongewijzigd | ongewijzigd | ongewijzigd |
| Security | een getypte naam wordt een bestandsnaam: precies wat `MEDIA.md` uitsluit | slug wordt nooit een pad; id is een getal | niets nieuws | slug wordt nooit een pad; de regel accepteert alleen `[a-z0-9-]` en de hex-naam |
| Thumbnails/varianten | elke thumbnail meeverhuizen | route per variant | ongewijzigd | zelfde regel voor `thumbs/` |
| Export/import | paden in exports veranderen | stabiel id | stabiel | stabiel |
| Migratie bestaande media | alles hernoemen | niets verhuist; alle lezers moeten het nieuwe adres gaan afdrukken | geen | niets verhuist; alle lezers moeten het nieuwe adres gaan afdrukken |

A valt af: het maakt van een label weer een bestandsnaam, breekt bestaande
adressen en vraagt om een verhuizing die de bibliotheek juist nooit doet.
C is wat er nu is, en is veilig.

B en D zijn allebei verantwoord. Het verschil is wie het beeld levert:

- **B** geeft precies het gewenste adres met het `id` erin en een nette 301
  bij een verouderde slug, maar dan levert PHP elk beeld van de site. Dat
  vraagt een eigen, gecachete leverroute (ETag, `Cache-Control`, Range voor
  video, `X-Content-Type-Options`, de SVG-CSP die nu in `.htaccess` staat), en
  kost op gedeelde hosting bij elke pagina met tien beelden tien
  PHP-processen.
- **D** laat Apache het bestand leveren. Eén `RewriteRule` onder `/assets/media/`
  zet `<hex>/<slug>.<ext>` om naar `<hex>.<ext>` en negeert de slug: de
  identiteit is het opgeslagen bestand (stabiel), de slug is leesbaar en
  mag verouderen zonder dat iets breekt. Geen database, geen PHP, geen
  nieuwe cacheregels. Nadeel: geen `id` in het adres, en een oude slug wordt
  niet omgeleid (hij blijft gewoon werken).

## Waarom niet in deze fase

Het lastige deel is bij B en D hetzelfde, en het is niet de route: **ruim
veertig lezers drukken een adres af uit een padkolom**, waarvan een aantal
meegeschreven kolommen van andere domeinen (Shop, Portfolio, Blog,
Instellingen). Een leesbaar adres betekent dat elk van die lezers het adres
van het media-item moet gaan gebruiken (of dat elke schrijver het leesbare
adres in de padkolom meeschrijft en het bij elke hernoeming overal bijwerkt —
een tweede boekhouding die `MEDIA.md` juist vermijdt). Dat is een refactor
over de hele frontend, met een eigen testronde per blok en per module. Half
bouwen — een route zonder dat de lezers hem gebruiken, of de helft van de
lezers — levert twee adressen per beeld op zonder winst. Volgens de stopregel
van de opdracht is het daarom hier niet gebouwd.

## Voorstel voor een eigen fase ("Media-adressen 1.0")

1. **Kies D**, tenzij het `id` in het adres een harde eis is (dan B, met een
   eigen leverroute en een meetronde op de hosting).
2. `MediaItem::publicUrl()` en `thumbnailUrl()`: `/assets/media/<hex>/<slug>.<ext>`,
   met de slug uit `display_name` (kleine letters, `[a-z0-9-]`, begrensd,
   leeg → alleen de hex-naam). `publicPath()` blijft het opgeslagen pad.
3. `.htaccess`: vóór de regel die `/assets/` doorlaat
   `RewriteRule ^assets/media/(thumbs/)?([0-9a-f]{32})/[a-z0-9-]{1,120}\.(webp|jpe?g|png|gif|svg|mp4|m4v|webm)$ assets/media/$1$2.$3 [L]`,
   plus dezelfde regel in `tests/Support/dispatcher-router.php` voor `php -S`.
   De SVG-CSP blijft gelden, want het doel is het echte `.svg`-bestand.
4. **Lezers omzetten, één domein per stap**, elk met een test die het
   afgedrukte `src` controleert: `BlockImage` (alle geïntegreerde blokken),
   `Branding`, Blog, Shop (productfoto's, collecties, deel-afbeeldingen),
   Portfolio, Homepage Hero, Open Graph/JSON-LD. Een lezer die alleen een
   padkolom heeft (oude rijen zonder `media_id`) houdt het oude adres.
5. Geen migratie, geen verhuizing, geen redirecttabel: elk oud adres blijft
   het bestand zelf.
6. Acceptatie: een hernoemd item krijgt overal het nieuwe adres, het oude
   adres en de hex-naam geven 200 met hetzelfde bestand, een slug met `../`,
   `%2e`, hoofdletters of een andere extensie geeft 404, en een SVG houdt zijn
   CSP-header.
