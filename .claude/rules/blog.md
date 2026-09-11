---
paths:
  - "src/Service/Blog/**/*.php"
  - "src/Repository/Blog*.php"
  - "src/Module/BlogModule.php"
  - "admin/blog*.php"
  - "api/admin/*blog*.php"
  - "blog*.php"
  - "assets/css/blog/**"
  - "tests/Blog/**"
---

# Je zit in de Blog

Een uitschakelbare module die standaard **uit** staat en nergens van afhangt.
Domeindocument: `BLOG.md`. Voor het volledige overzicht: `/blog`.

- **Noem hier nooit een Shop-klasse of Shop-tabel.**
  `Tests\Blog\BlogModuleTest` faalt daar onmiddellijk op.
- **Hergebruik Core in plaats van het na te bouwen.** Slugwijzigingen lopen
  door de Redirect Manager, metadata door `SeoMetadata`, beeld door de
  Mediabibliotheek.
- **Onderzoek geen Shop-code** tenzij deze wijziging daar aantoonbaar van
  afhangt.
- Testen vraagt een container met `MODULE_BLOG_ENABLED=true`, anders test je
  een 404. Dat is `mygdala_php_test`. Suite: `--testsuite blog`.
