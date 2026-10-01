---
name: articles
description: Werken aan de Artikelen-module van Mygdala - zelfstandige artikelen uit contentblokken, onderwerpen, publiceren via de Publishing Engine, /artikelen en /en/articles, en het verschil met de Blog. Zet de paden, het testcommando en de grenzen klaar. Gebruik dit voordat je een Articles-bestand opent.
---

# Artikelen

Een uitschakelbare module (`articles`) die **standaard uit** staat. De tweede
soort op de Publishing Engine. **Niet de Blog**: lees eerst de tabel "Blog
versus Artikelen" in `ARTICLES.md`.

## Lees dit eerst

`ARTICLES.md`. Voor status, datum en zichtbaarheid:
`docs/publishing/ARCHITECTURE.md`.

## De paden

| Laag | Paden |
|---|---|
| Module | `src/Module/ArticlesModule.php` |
| Logica | `src/Service/Articles/` |
| Opslag | `src/Repository/ArticleRepository.php`, `ArticleTopicRepository.php` |
| Schermen | `admin/articles.php`, `admin/article.php`, `admin/article-topics.php` |
| Endpoints | `api/admin/*article*.php` |
| Publiek | `articles.php`, `article.php`, `assets/css/articles/articles.css` |
| Tests | `tests/Module/ArticlesTest.php`, `tests/Install/ArticlesMigrationTest.php` |

## De regels die hier gelden

- **Noem nooit een Blog-klasse of Blog-tabel**, en geef de Blog niets van
  Articles. Wat gedeeld moet worden, hoort in de Publishing Engine, in
  `ContentOwners` of in Multilingual, niet in de andere module.
- **Woorden en adressen alleen via `ArticleLocalization`.** Geen SQL op
  `article_translations` (`MultilingualBoundaryTest`).
- **Geen terugval naar een andere taal** op de publieke kant: een taal zonder
  adres en titel heeft geen versie.
- **Publieke lezingen via `listedSql()`/`reachableSql()`**, `now` uit
  `PublishingClock`. Nooit `status = 'published'`.
- **Geen blokkenlijst in Articles**: de kiezer leest `meta()['owners']`.
- Een publieke route is pas klaar als hij in `PublicRouteContractTest` en
  `QueryIdentityRoutesTest` geclassificeerd is.

## Testen

De module staat standaard uit, dus de test zet hem zelf aan
(`MODULE_ARTICLES_ENABLED=true`, ook in `php_test`).

```bash
docker compose exec php_test php vendor/bin/phpunit tests/Module/ArticlesTest.php
docker compose exec php_test php vendor/bin/phpunit tests/Install/ArticlesMigrationTest.php
```
