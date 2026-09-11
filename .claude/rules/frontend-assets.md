---
paths:
  - "assets/css/**"
  - "assets/js/**"
  - "partials/**/*.php"
---

# Frontend-assets hebben één eigenaar

Er is geen globale `style.css` of `main.js`, en geen buildstap.

- **Vraag een bestand nooit met een handgeschreven `<link>` of `<script>` in
  een template.** `App\Service\PageAssets` is het enige mechanisme: een
  blokdefinitie, een route of een module vraagt om een pad.
- **Core is alleen wat élke pagina gebruikt.** Blok-CSS hoort in
  `assets/css/blocks/<type>.css`, Shop-CSS in `assets/css/shop/`, Blog-CSS in
  `assets/css/blog/`.
- **Kleuren zijn semantische rollen** (`--color-primary`), nooit merknamen.
- **Elk bestand opent met een banner** die zegt wie de eigenaar is, wanneer
  het geladen wordt en wat er nadrukkelijk niet in hoort.
- De pagina moet werken **zonder** het script, en
  `prefers-reduced-motion` wordt overal gerespecteerd.

`Tests\Service\FrontendAssetOwnershipTest` bewaakt dit en faalt zodra een
asset geen eigenaar heeft.
