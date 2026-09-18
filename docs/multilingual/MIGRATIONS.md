# Migraties en bestaande sites

Deel van [`MULTILINGUAL.md`](../../MULTILINGUAL.md). Wat de migraties van de
meertaligheid met een database doen, wat een bestaande site daarvan merkt, en
wat er vóór de correctie van V1 fout was. Nodig bij installatie-, upgrade- en
migratiewerk.

## Multilingual 2.0: paginatekst per taal

Eén uitzondering op "onaangeraakt" hieronder, bewust: de zes tekstkolommen van
`pages` (`title`, `meta_title`, `meta_description` en hun `_en`) zijn
verhuisd naar `page_translations` en daarna verwijderd
(`20260917140000`, `20260917150000`). NL blijft NL, EN blijft EN, leeg blijft
leeg, en ontbreekt een taal in het register, dan stopt de migratie vóór de
drop. Details in [`ARCHITECTURE.md`](ARCHITECTURE.md), *De migratie*; de test
is `tests/Install/PageTranslationMigrationTest.php` (`migration`).

## Multilingual 2.0: blokwoorden per taal (fase 3A)

Een tweede uitzondering, van dezelfde soort: de woorden van Tekstblok
(`rich_text_sections.content_html`/`_en`), Oproep met knop (vijf
`cta_bands`-paren) en Contactkaart (drie `contact_cards`-paren) zijn per veld
verhuisd naar `block_translations` en daarna verwijderd
(`20260917160000`, `20260917170000`). NL blijft NL, EN blijft EN, woorden gaan
byte voor byte mee, leeg of alleen witruimte krijgt geen rij, en ontbreekt een
taal in het register, dan stopt de migratie vóór de drop. Details in
[`ARCHITECTURE.md`](ARCHITECTURE.md), *Contentblokken per taal*; de test is
`tests/Install/BlockTranslationMigrationTest.php` (`migration`).

## Multilingual 2.0: de overige blokwoorden (fase 3B)

Dezelfde uitzondering voor alle andere bloktypes, in drie golven:
`20260917180000` (Paginakop, Formulier, Offerte-/contactformulier en de
gedeelde galerijtabel), `20260917190000` (Openingssectie homepage en de
repeaters met één niveau kindrijen) en `20260917200000` (Tekst met afbeelding,
Detailsectie en Kaarten-carrousel, tot een kleinkind diep). Samen 124 kolommen
uit 23 tabellen. Een kindrij krijgt zijn woorden onder zijn eigen tabel en
`id`; de Detailsectie-body (`content_html`/`_en`) wordt het rich veld `body`.
Dezelfde regels als in 3A: NL blijft NL, EN blijft EN, byte voor byte, leeg of
alleen witruimte krijgt geen rij, opnieuw draaien doet niets, en een taal die
het register mist stopt de migratie vóór de drop. `page_heroes.breadcrumb_label_nl/en`
gaan weg zonder verhuizing: sinds fase 5B las niets ze nog. Daarna heeft geen
bloktabel nog een `_nl`/`_en`-kolom.

De test is `tests/Install/RemainingBlockWordsMigrationTest.php` (`migration`):
per vorm (alleen een ouder, één niveau kinderen, meerdere kindtabellen, drie
niveaus), alleen NL, alleen EN, beide, leeg, alt-teksten, rich text, opnieuw
draaien, een ontbrekende taal, en onaangeroerde id's en taalneutrale velden.

## Multilingual 2.0: navigatie, footer, instellingen en formulieren (fase 4)

Dezelfde uitzondering voor de vier domeinen die geen blok zijn, in drie golven
van elk een schema- en een verhuismigratie:

| Migratie | Van | Naar |
|---|---|---|
| `20260918100000` / `110000` | `nav_items.label_nl/en`, `footer_columns.title_nl/en`, `footer_links.label_nl/en` | `nav_item_translations`, `footer_column_translations`, `footer_link_translations` |
| `20260918120000` / `130000` | `site_settings`: `city_nl/en`, `footer_description_nl/en`, `footer_slogan_nl/en` | `site_setting_translations` |
| `20260918140000` / `150000` | `forms.submit_label_nl/en` en `success_message_nl/en`, `form_fields.label_nl/en`, `placeholder_nl/en`, `help_text_nl/en` en `options` | `form_translations`, `form_field_translations`, `form_field_options` + `form_field_option_translations` |

Dezelfde regels als in 3A en 3B: NL blijft NL, EN blijft EN, byte voor byte,
leeg of alleen witruimte krijgt geen rij, opnieuw draaien doet niets, en een
taal die het register mist stopt de migratie vóór de drop.

Twee dingen die alleen hier spelen. **Optiewaarden**: elke oude optieregel
wordt een rij in `form_field_options` waarvan de waarde de Nederlandse helft
is, byte voor byte, zodat `form_fields.default_value` en elke bestaande
inzending blijven kloppen; de regels die het oude leesmodel weggooide (een
regel zonder Nederlandse helft, een dubbele, alles voorbij vijftig) gooit de
migratie net zo weg. **Dode sleutels**: `130000` verwijdert naast de zes
verhuisde sleutels ook de acht `header_cta_*`-sleutels, waarvan de enige lezer
de migratie was die de headerknoppen naar `nav_items` bracht.

De tests zijn `tests/Install/NavigationFooterLabelMigrationTest.php`,
`LocalizedSiteSettingMigrationTest.php` en
`FormWordsAndOptionMigrationTest.php` (`migration`): vers, bijgewerkt en
kapot naast elkaar, met de neutrale kolommen ervoor en erna vergeleken.

## Multilingual 2.0: de modules (fase 5)

Dezelfde uitzondering voor de vier modules, elk in een schema- en een
verhuismigratie.

| Golf | Migratie | Van | Naar |
|---|---|---|---|
| A | `20260918160000` / `170000` | `portfolio_categories.name_nl/en`; `portfolio_gallery_items.{title,subtitle,alt,intro,description}_nl/en`; `portfolio_item_images.alt_nl/en` — 14 kolommen | `portfolio_category_translations`, `portfolio_item_translations`, `portfolio_item_image_translations` |
| B | `20260918180000` / `190000` | `blog_posts.{title,excerpt,body,meta_title,meta_description}` en hun `_en`; `blog_categories.{name,description}` en hun `_en`; `blog_tags.name` en `name_en` — 16 kolommen | `blog_post_translations`, `blog_category_translations`, `blog_tag_translations` |
| C | `20260918200000` / `210000` | `products.{name,description,meta_title,meta_description}` en hun `_en`; dezelfde vier van `collections` plus `related_heading_nl/en` — 19 kolommen; en de twee rijen `site_settings.related_products_heading_nl/en` | `product_translations`, `collection_translations`; de kop naar `site_setting_translations` |
| C | `20260918220000` / `230000` | `order_items.product_name_en` — 1 kolom | `order_item_translations` |
| D | `20260918240000` / `250000` | `product_personalization_settings.instructions` en `_en`; `_views.label` en `_en`; `_zones.{label,instructions,placeholder}` en hun `_en` — 10 kolommen | `product_personalization_translations`, `product_personalization_view_translations`, `product_personalization_zone_translations` |

Dezelfde regels als in 3A, 3B en 4: NL blijft NL, EN blijft EN, byte voor byte
(rich text inbegrepen), leeg of alleen witruimte krijgt geen rij, opnieuw
draaien doet niets, en een taal die het register mist stopt de migratie vóór
elke drop.

Eén ding dat alleen hier speelt: **meerdere velden in één rij**. De tabellen
van fase 4 hadden één of twee velden per eigenaar; `portfolio_item_translations`
heeft vijf. Per veld per taal doet de migratie daarom twee statements: een
`INSERT … SELECT` voor de eigenaars die voor die taal nog geen rij hebben, en
een `UPDATE … JOIN` die een nog lege kolom van een bestaande rij vult. Een al
gevulde kolom wordt nooit overschreven, dus een tweede run verandert niets.

**Een module hoeft niet aan te staan.** Geen van deze migraties vraagt of de
module actief is: een uitgeschakelde module houdt zijn content, en een verse
installatie met de module uit eindigt op hetzelfde schema als een met hem aan.
De testdatabases van `ScratchInstall` hebben geen enkele `MODULE_*`-variabele
gezet, dus dat wordt ook echt zo getest.

Bij golf B is de Nederlandse helft de **kale** kolomnaam (`title`, niet
`title_nl`) en draagt alleen de Engelse een achtervoegsel — de vorm die de Blog
altijd had. En **geen slug verhuist**: `blog_posts.slug`,
`blog_categories.slug` en `blog_tags.slug` houden hun exacte waarden, zodat
`/blog/<slug>`, `/blog/categorie/<slug>` en `/blog/tag/<slug>` na de upgrade
precies hetzelfde antwoorden en elke opgeslagen redirect van een hernoemd
archief blijft kloppen.

Bij golf C is de Nederlandse helft van een product en een collectie óók de
kale kolomnaam; alleen `related_heading` heeft `_nl` aan beide kanten. Wat er
**niet** verhuist is wat een winkel een besluit mee neemt: id's, slugs,
prijzen, voorraad, verzendinstellingen, verkoopkanalen, afbeeldingspaden,
sorteervolgordes en elke relatie houden hun exacte waarde, zodat een
taalwissel na de migratie net zo min een prijs kan veranderen als ervoor.

**En een bestelling is geen vertaling.** `order_items.product_name_en` verhuist
in een eigen paar migraties (`220000`/`230000`) naar een eigen tabel, met een
eigen leesregel. `order_items.product_name` wordt niet aangeraakt: dat blijft
de ene taalvrije momentopname die de factuur, de bevestigingsmail en het
CMS-besteloverzicht afdrukken. Er wordt niets herschreven uit een actuele
productnaam — de migratie kopieert bytes en dropt één kolom, en joint nooit
naar `products`. Een regel waarvan het product intussen verwijderd is
(`product_id` NULL) houdt zijn naam gewoon.

Bij golf D verhuist alleen tekst en blijft de **configuratie** staan: elke
`view_key` en `zone_key` (waar een bestelregel naar wijst), de geometrie, de
schakelaars, de meerprijs, de voorbeeldafbeelding en elke sorteervolgorde. Wat
een zone heette toen iemand hem kocht staat in de eigen `config_snapshot_json`
van die bestelregel en wordt niet aangeraakt.

De tests zijn `tests/Install/PortfolioWordsMigrationTest.php`,
`BlogWordsMigrationTest.php`, `ShopWordsMigrationTest.php`,
`OrderItemNameSnapshotMigrationTest.php` en
`PersonalizationWordsMigrationTest.php` (`migration`): vers, bijgewerkt en
kapot naast elkaar, met de neutrale kolommen ervoor en erna vergeleken, een rij
in elke toestand waarin zijn kolommen konden staan, bij golf B ook de
taxonomiekoppelingen en elke slug, bij golf C elke prijs, elk
collectielidmaatschap en elke bestelregel, en bij golf D elke sleutel en elke
coördinaat.

**Na golf D staat er geen enkele `_nl`/`_en`-kolom meer in de database.**

**Oude migraties die de gedropte kolommen lezen.** `20260909270000` (media
adopteren) leest de alt-kolommen van Tekst met afbeelding, Detailsectie en
kaarten. Op een echte installatie draait die ruim vóór 3B, en Phinx draait een
migratie nooit twee keer. `MediaAdoptionTest` draait hem wél opnieuw, en stopt
daarom zijn installatie vóór `20260917200000`, net zoals
`ContactFormMigrationTest` stopt vóór `20260917180000` en
`HeaderButtonMigrationTest` vóór `20260918110000`.

## Wat hiervóór fout was

De eerste versie had laag 2 niet. "Welke taal bewerk ik" werd afgeleid uit de
**instellingen van de site** — `enabled_content_languages` — en die ene
instelling hing aan drie ongerelateerde dingen tegelijk:

- of de bezoeker in de header een taalwissel kreeg;
- of een redacteur überhaupt Engelse velden zag;
- of automatisch vertalen werd aangeboden.

Op een site waar die rij `nl` zei — de standaard — betekende dat: geen
taalwissel voor de bezoeker, en geen enkele manier om Engelse inhoud te
schrijven behalve de configuratie van de wébsite omzetten, die je met al je
collega's deelt. Dat is niet wat een redacteur bedoelde met "ik wil de Engelse
versie van deze pagina bewerken".

De correctie is niet een hernoeming: **de bewerktaal is nieuwe, eigen staat**
(een voorkeur van een persoon), en `enabled_content_languages` beslist
nergens meer iets.

## Wat er met een bestaande site gebeurt

**De inhoud van een site wordt niet weggegooid en niet herschreven.** Eén
instellingenrij is de uitzondering, en die draagt geen inhoud.

| | |
|---|---|
| `_nl` / `_en`-kolommen | onaangeraakt, niet hernoemd, niet verwijderd |
| Bestaande NL-waarden | onaangeraakt |
| Bestaande EN-waarden | onaangeraakt |
| Kale Nederlandse kolommen | onaangeraakt |
| `content_translation_state` | onaangeraakt |
| `enabled_content_languages` | beslist nergens meer iets; de **waarde** wordt door migratie `20260911200000` bewust overschreven met de volledige set, hoofdtaal eerst. Sinds `20260917120000` is de rij weg (zie hieronder) |

Die rij is de uitzondering omdat hij sinds de correctie nergens meer over
beslist. Het enige wat hij nog moet doen, is kloppen: de talen noemen die deze
site publiceert. Migratie `20260910140000` kon er `nl` in achterlaten op een
site die wél Engels publiceert (zie hieronder), en die foute waarde is precies
wat `20260911200000` herstelt. Daarbij gaat geen `_nl`/`_en`-kolom en geen
inhoud mee, en verandert er niets wat een bezoeker of redacteur ziet: niets
vertakt op die waarde.

Migratie `20260910140000` zette destijds de talen van bestaande sites vast.
Migratie `20260911100000` voegt één nullable kolom toe,
`admin_users.content_editing_language`, en verder niets. Forward-only en
additief, net als de vorige.

`NULL` betekent "deze persoon heeft niets gekozen", zodat een bestaande
beheerder geen voorkeur krijgt toegewezen die hij nooit heeft uitgesproken —
dezelfde afspraak als bij `interface_language`. Zonder keuze bewerk je de
standaardtaal van de website.

**Wat een bestaande site wél merkt**, en dat is de correctie zelf:

- de publieke `NL | EN`-wissel verschijnt weer, ook als
  `enabled_content_languages` `nl` zei;
- redacteuren kunnen weer Engelse inhoud schrijven, zonder de configuratie van
  de website om te zetten;
- de taaltabbladen in de editors zijn weg, vervangen door de ene schakelaar in
  de schil.

### De tabelnamen van `20260910140000` klopten niet

Die migratie besliste per database of zij `nl` of `nl,en` opsloeg, door een
lijstje tabellen af te tasten op Engelse inhoud. Twee van de negen namen in
dat lijstje bestaan niet in dit schema:

| Wat de migratie zei | Hoe de tabel heet |
|---|---|
| `navigation_items` | `nav_items` |
| `homepage_heroes` | `homepage_hero` |

De lus slaat een tabel over die `hasTable()` niet kent, dus beide missers
waren stil. En juist daar zet de generieke bootstrap zijn Engels neer, dus
een verse installatie sloeg `nl` op en beweerde eentalig te zijn.

Migratie `20260911200000_correct_the_stored_content_languages` herstelt dat.
Zij tast niets af: zij schrijft de volledige set die dit product publiceert,
hoofdtaal eerst — precies wat het tabblad *Talen* toen schreef zodra een
eigenaar zelf iets opsloeg. Daarmee kan dezelfde fout niet terugkomen,
want er staat geen tabelnaam meer in.

`20260910140000` blijft staan zoals zij gedraaid heeft. Zij is al toegepast
op echte installaties, dus haar geschiedenis blijft eerlijk en de nieuwe
migratie corrigeert de staat die zij achterliet. Vers of bijgewerkt: beide
eindigen op `nl,en`.

Twee tests houden dit vast.
`Tests\Install\MigrationTableNamesTest` vergelijkt elke tabelnaam die een
migratie uitspreekt met de namen die de migraties aanmaken — een nieuwe
typefout in een `hasTable()` of in een `'tabel' => 'kolom_en'`-lijstje faalt
daar, en de twee bestaande missers staan er met naam en toenaam in.
`Tests\Install\ContentLanguageSettingRepairTest` draait de kapotte migratie
echt, tegen een database vanaf nul, en controleert daarna de uitkomst voor
een verse installatie, voor een bijgewerkte, voor Engels dat alleen in het
menu staat, voor Engels dat alleen in de hero staat, voor een site zonder
Engels en voor een database zonder inhoud.

## Multilingual 2.0 fase 1: het talenregister

Migratie `20260917120000_create_the_site_language_registry` maakt
`site_languages` en zet er per database precies de talen in die de site nu
publiceert. Het schema, de invarianten en de API staan in
[`ARCHITECTURE.md`](ARCHITECTURE.md).

| Database | Wat het register krijgt |
|---|---|
| bestaand, `primary_content_language = nl` | `nl` standaard (volgorde 0), `en` (1), beide actief |
| bestaand, `primary_content_language = en` | `en` standaard (volgorde 0), `nl` (1), beide actief |
| bestaand, rij leeg, weg of onbekend (`de`) | als `nl` |
| vers | als `nl`; de installatiewizard verplaatst de standaard daarna naar de gekozen taal |

Geen Duits, Frans of Italiaans, en geen `_nl`/`_en`-kolom wordt aangeraakt.

**Twee instellingenrijen verdwijnen**, `primary_content_language` en
`enabled_content_languages`, maar alleen als het register daarna een actieve
standaardtaal heeft. Dat is de uitzondering op "een migratie verwijdert
niets", en de enige reden is één bron van waarheid: bleven ze staan, dan zou de
eerste keer opslaan op het tabblad *Talen* ze laten verouderen. Hun informatie
staat in het register.

**Idempotent.** De tabel wordt alleen gemaakt als hij ontbreekt, de rijen alleen
als hij leeg is. Een tweede run voegt dus geen taal toe en zet een later
verplaatste standaard niet terug.

`Tests\Install\SiteLanguageRegistryMigrationTest` bouwt drie databases vanaf
nul (vers, bestaand NL, bestaand EN), controleert schema, rijen, volgorde en
de verdwenen instellingen, draait de migratie opnieuw, en loopt de
installatiewizard door. `ContentLanguageSettingRepairTest` stopt sindsdien
bij `20260911200000`, want wat daarna met de rijen gebeurt is het onderwerp
van deze test.

## Waar het staat

| Migratie | Wat zij doet |
|---|---|
| `db/migrations/20260910140000_add_multilingual_language_settings.php` | zette destijds de talen van bestaande sites vast |
| `db/migrations/20260910150000_create_the_translation_state_table.php` | maakt de tabel `content_translation_state` |
| `db/migrations/20260911100000_add_the_content_editing_language_column.php` | voegt `admin_users.content_editing_language` toe |
| `db/migrations/20260911200000_correct_the_stored_content_languages.php` | zet `enabled_content_languages` recht |
| `db/migrations/20260917120000_create_the_site_language_registry.php` | maakt `site_languages`, zet de standaardtaal erin en verwijdert de twee oude instellingenrijen |
