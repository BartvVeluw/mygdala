---
name: blog
description: Werken aan de Blog-module van Mygdala - berichten, categorieen, tags, publiceren en inplannen, het publieke overzicht met zijn archieven, de RSS-feed en de blog-SEO. Zet de juiste bestandspaden, het testcommando en de grenzen klaar. Gebruik dit voordat je een blogbestand opent.
---

# Blog

Een uitschakelbare module die **standaard uit staat**. Hij hangt nergens van
af en werkt identiek met de Shop aan en uit.

**Blijf binnen de paden hieronder.** Open geen Shop-bestand.

## Lees dit eerst

`BLOG.md`. Dat document is compleet voor dit domein; `MODULES.md` heb je
alleen nodig als je aan de modulegrens zelf werkt.

## De paden

| Laag | Paden |
|---|---|
| Module | `src/Module/BlogModule.php` |
| Leesmodel en logica | `src/Service/Blog/` |
| Opslag | `src/Repository/Blog{Post,Category,Tag,Setting}Repository.php` |
| Adminschermen | `admin/blog.php`, `blog-post.php`, `blog-categories.php`, `blog-tags.php`, `blog-settings.php` |
| Admin-endpoints | `api/admin/*blog*.php` |
| Publieke routes | `blog.php`, `blog-post.php`, `blog-feed.php` |
| Frontend | `assets/css/blog/blog.css` |
| Tests | `tests/Blog/` |

## De regels die hier gelden

- **Noem hier nooit een Shop-klasse of Shop-tabel.**
  `Tests\Blog\BlogModuleTest` bewaakt die grens en faalt er onmiddellijk op.
- **Hergebruik Core in plaats van het na te bouwen.** Slugwijzigingen lopen
  door de Redirect Manager, metadata door `SeoMetadata`, beeld door de
  Mediabibliotheek. De Blog levert daarvoor een `MediaUsageProvider`, zodat
  de bibliotheek weet dat een uitgelichte afbeelding in gebruik is zonder
  ooit een blogtabel te noemen.
- **De publicatiecyclus heeft een klok.** Een ingepland bericht wordt zichtbaar
  door de tijd, niet door een cronjob. Ga daar geen tweede mechanisme naast
  bouwen.
- **Een lege blog op een levende URL is erger dan geen blog.** Daarom staat de
  module standaard uit. Verander die standaard niet.

## Testen

De Blog staat standaard uit, dus de testcontainer zet hem expliciet aan met
`MODULE_BLOG_ENABLED=true`. Zonder die container test je een 404.

```bash
docker compose --profile test up -d
docker compose exec php_test php vendor/bin/phpunit --testsuite blog
```
