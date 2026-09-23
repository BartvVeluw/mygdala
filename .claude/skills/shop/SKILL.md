---
name: shop
description: Werken aan de webshop van Mygdala - producten, varianten, opties, collecties, gerelateerde producten, winkelwagen, afrekenen, bestellingen, Mollie-betalingen, facturen, retourverzoeken, verzendzones en tarieven. Zet de juiste bestandspaden, het testcommando en de grenzen klaar. Gebruik dit voordat je een shopbestand opent.
---

# Shop

De Shop is een **uitschakelbare first-party module**. Voor Van Veluw
Laserdesign staat hij aan, en dat is de standaard.

**Blijf binnen de paden hieronder.** Open geen Blog-, Formulier- of
Mediabestand tenzij de taak daar aantoonbaar overheen gaat.

## Lees dit eerst

`MODULES.md`, hoofdstuk "Shop (module `shop`)". Niet het hele document tenzij
je aan de modulegrens zelf werkt.

## De paden

| Laag | Paden |
|---|---|
| Module | `src/Module/ShopModule.php` |
| Catalogus | `src/Repository/Product*.php`, `ProductVariantImageRepository.php`, `src/Service/ProductGallery.php`, `ShopOverview.php`, `ShopMediaUsage.php`, `src/Service/ProductSeo.php`, `ProductDeletionService.php`, `ProductImageUploader.php` |
| Collecties | `src/Service/Collection*.php`, `src/Repository/CollectionRepository.php` |
| Bestellingen | `src/Repository/{Order,Customer,Invoice}*.php`, `src/Service/Order*.php`, `InvoiceService.php`, `InvoiceStorage.php`, `PdfInvoiceRenderer.php`, `MollieClientFactory.php`, `MolliePaymentData.php` |
| Verzending | `src/Service/Shipping/`, `src/Service/Address/`, `src/Repository/{Shipping,Carrier}*.php` |
| Dashboard | `src/Service/Dashboard*.php`, `src/Repository/DashboardRepository.php`, `admin/_dashboard_shop.php` |
| Adminschermen | `admin/{products,product-form,collections,collection,orders,order,orders-export,shipping,carrier-rates,related-products}.php`, `admin/withdrawal-request*.php` |
| Admin-endpoints | `api/admin/*{product,variant,collection,shipping,carrier,invoice,fulfilment,withdrawal}*.php`, `api/admin/{order,resend-order,sync-postnl-rates}*.php` |
| Publieke endpoints | `api/{checkout,shipping-quote,shipping-zones,mollie-webhook,order-status,product,products,address-lookup-nl,withdrawal-request}.php` |
| Publieke routes | `shop.php`, `product.php`, `collectie.php`, `cart.php`, `checkout.php`, `bestelling-status.php`, `herroeping.php` |
| Frontend | `assets/css/shop/`, `assets/js/shop/` |
| Blokken | `ShopModule::blockDefinitions()` — `product_grid`, `shop_collections` |

**Niet van de Shop**, ook al lijkt het erop: `ProductPersonalizationRepository`
en `OrderItemPersonalizationRepository` horen bij de Personalisatie-module.

## De regels die hier gelden

- **Een request bepaalt nooit een prijs of een verzendbedrag.** Regelprijzen
  worden herlezen uit `products`, verzendkosten herberekend door
  `ShippingCalculationService`. Dit is de belangrijkste regel van dit domein.
- **Een order is een snapshot.** `unit_price` en de personalisatiegegevens
  staan vast op de orderregel, zodat een latere prijs- of productwijziging
  historische bestellingen nooit raakt.
- **De winkelwagen is volledig client-side**, `vvl-cart` in `localStorage`.
  De server kent hem pas bij het afrekenen.
- **De mini-winkelwagen zit in de gedeelde header** en wordt gevraagd door
  `ShopModule::shellStyles()`, niet door Core. Dat is het enige Shop-bestand
  dat overal laadt.
- **Raak geen Core-bestand aan om iets van de Shop te regelen.** Core mag
  geen Shop-klasse en geen Shop-assetpad noemen:
  `Tests\Module\ShopDisabledTest` faalt daarop. Moet Core iets weten, voeg
  dan een bijdrage toe aan `ModuleDefinition`.

## Testen

```bash
docker compose exec php_test php vendor/bin/phpunit --testsuite shop
```

Raakte je een koppelpunt met Core, draai dan ook `--testsuite modules`. Dat
controleert of de site nog klopt met de Shop uit.

Draait `php_test` niet, dan zit hij achter het profiel `test`:
`docker compose --profile test up -d`. Werk je in een worktree, dan heeft die
eerst zijn eigen `vendor/` nodig. Zie `TESTING.md`.
