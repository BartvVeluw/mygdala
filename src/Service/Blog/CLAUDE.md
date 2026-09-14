# src/Service/Blog

De Blog-module. Eigen namespace, eigen tabellen, eigen instellingentabel,
eigen publieke routes (`blog.php`, `blog-post.php`, `blog-feed.php`) en één
eigen stylesheet.

- **Hangt nergens van af.** Werkt identiek met de Shop aan en uit. Noem hier
  nooit een Shop-klasse of Shop-tabel: `Tests\Blog\BlogModuleTest` faalt daarop.
- **Staat standaard UIT** (`enabledByDefault()`), net als Portfolio. De
  testcontainer zet `MODULE_BLOG_ENABLED=true`.
- Slugwijzigingen lopen door de gewone Redirect Manager, metadata door de
  gewone `SeoMetadata`. Bouw daar geen tweede versie van.

Lees `../../../BLOG.md`, of roep `/blog` aan. Test met `--testsuite blog`.
