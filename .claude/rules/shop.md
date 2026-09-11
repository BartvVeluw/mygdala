---
paths:
  - "src/Service/{Collection,Dashboard,Invoice,Order,Product,Related}*.php"
  - "src/Service/MollieClientFactory.php"
  - "src/Service/PdfInvoiceRenderer.php"
  - "src/Repository/{Product,Variant,Collection,Order,Customer,Invoice,Shipping,Carrier,Withdrawal,Dashboard}*.php"
  - "src/Module/ShopModule.php"
  - "admin/{product,collection,order,withdrawal-request}*.php"
  - "admin/{shipping,carrier-rates,related-products,_dashboard_shop}.php"
  - "api/admin/*{product,variant,collection,order,shipping,carrier,invoice}*.php"
  - "api/{checkout,shipping-quote,shipping-zones,mollie-webhook,order-status,product,products,withdrawal-request}.php"
  - "{shop,product,collectie,cart,checkout,bestelling-status,herroeping}.php"
  - "assets/{css,js}/shop/**"
---

# Je zit in de Shop

De Shop is een uitschakelbare first-party module. Domeindocument:
`MODULES.md`, hoofdstuk "Shop". Voor het volledige overzicht: `/shop`.

- **Een request bepaalt nooit een prijs of een verzendbedrag.** Regelprijzen
  worden herlezen uit `products`, verzendkosten herberekend door
  `ShippingCalculationService`.
- **Een order is een snapshot.** Een latere prijs- of productwijziging mag een
  bestaande bestelling nooit raken.
- **Onderzoek geen Blog-, Formulier- of Mediacode** tenzij deze wijziging daar
  aantoonbaar van afhangt.
- **Raak geen Core-bestand aan om iets van de Shop te regelen.** Core mag geen
  Shop-klasse en geen Shop-assetpad noemen; `Tests\Module\ShopDisabledTest`
  faalt daarop. Moet Core iets weten, voeg dan een bijdrage toe aan
  `ModuleDefinition`.
- Test met `--testsuite shop`, en bij een koppelpunt ook `--testsuite modules`.

**Twee bestanden die deze patronen meepakken maar niet van de Shop zijn:**
`ProductPersonalizationRepository` en `OrderItemPersonalizationRepository`
horen bij de Personalisatie-module.
