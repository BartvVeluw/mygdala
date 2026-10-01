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
| Catalogus | `src/Repository/Product*.php`, `ProductVariantImageRepository.php`, `src/Service/ProductGallery.php`, `ProductGalleryTransition.php` (de overgang van de productgalerij), `ProductVariantEditor.php`, `ShopOverview.php`, `ShopMediaUsage.php`, `src/Service/ProductSeo.php`, `ProductDeletionService.php`, `ProductImageUploader.php`, `PurchaseMode.php` (direct of op aanvraag), `ProductDetail.php` (de productpayload: `api/product.php` en het blok Uitgelicht product), `ProductPurchasePath.php` (hoe een zichtbaar product te koop is, voor productpagina en blok), `ProductSpecifications.php` + `SpecificationLibraryEditor.php` + `ProductSpecificationEditor.php` |
| Voorraad | `src/Service/Inventory/` (`Inventory`, `ProductStock`, `StockUnit`, `InventoryEditor`, `StockNotifications`), `src/Repository/{Inventory,StockNotification}Repository.php`, `src/Service/CartAvailability.php`, `OrderPaymentStartFailure.php`, `src/Mail/StockNotificationBuilder.php`, `src/Service/ShopLocalizedSettings.php` (de terug-op-voorraadmail per taal) |
| Bestelvelden | `src/Service/OrderFields/` (ook de afbeeldingsvraag: `OrderFieldUploads`, `OrderFieldUpload{Policy,Storage,Validator,Exception}`), `src/Repository/{OrderField,OrderItemField,OrderFieldUpload}Repository.php`, `partials/product-order-fields.php`, `api/order-field-upload.php`, `api/admin/order-field-upload.php`, `scripts/prune-order-field-uploads.php` |
| Collecties | `src/Service/Collection*.php`, `src/Repository/CollectionRepository.php` |
| Bestellingen | `src/Repository/{Order,Customer,Invoice}*.php`, `src/Service/Order*.php`, `InvoiceService.php`, `InvoiceStorage.php`, `PdfInvoiceRenderer.php`, `DocumentNumberPrefix.php` (bestel- en factuurprefix) |
| Betalingen | `src/Service/Payment/` (het contract `PaymentProvider`, `MolliePaymentProvider`, `MollieConfiguration`, `ShopPaymentMethods`, …), `MollieClientFactory.php`, `MolliePaymentData.php`, `admin/payments.php` + `admin/assets/payments.js`, `api/admin/{update-payment-settings,test-payment-connection}.php`; de testnaad `tests/Support/FakeMollie.php` |
| Verzending | `src/Service/Shipping/`, `src/Service/Address/`, `src/Repository/{Shipping,Carrier}*.php` |
| Dashboard | `src/Service/Dashboard*.php`, `src/Repository/DashboardRepository.php`, `admin/_dashboard_shop.php` |
| Adminschermen | `admin/{products,product-form,collections,collection,orders,order,orders-export,shipping,carrier-rates,related-products}.php`, `admin/withdrawal-request*.php`, `admin/_product_{gallery,variants,inventory,specifications,order_fields}.php` + `admin/assets/product-{gallery,variants,inventory,order-fields}.js` (de producteditor: drie tabbladen, plus *Pagina-inhoud* met de gedeelde bloklijst `admin/_content_blocks.php` voor wie `products.manage` heeft: de blokken van een product vragen het recht van het product, `ContentBlockAccess`), `admin/assets/product-overview.js` (raster of lijst op Shop → Producten), `admin/product-specifications.php` (Shop → Specificaties) |
| Admin-endpoints | `api/admin/*{product,variant,collection,shipping,carrier,invoice,fulfilment,withdrawal}*.php` (de producteditor heeft er één: `update-product.php`, plus `create-product.php` voor de eerste stap), `api/admin/{order,resend-order,sync-postnl-rates}*.php`, `api/admin/_shop_share_image.php` (de deel-afbeelding uit de Mediabibliotheek), `api/admin/update-product-specifications.php`, `api/admin/{update-stock-notification-mail,send-stock-notifications}.php` |
| Publieke endpoints | `api/{checkout,cart-check,stock-notification,shipping-quote,shipping-zones,mollie-webhook,order-status,product,products,address-lookup-nl,withdrawal-request}.php` |
| Publieke routes | `shop.php`, `product.php`, `collectie.php`, `cart.php`, `checkout.php`, `bestelling-status.php`, `herroeping.php` |
| Frontend | `assets/css/shop/`, `assets/js/shop/` (de productgalerij: `product-gallery.js`, gevraagd door `product.php` en `featured_product` vóór `shop.js`; de thumbnails lopen door naar een volgende regel en scrollen niet, één regel in `shop.css` voor allebei), `partials/product-purchase.php` (het koopgedeelte van productpagina en blok) |
| Zoeken | `src/Service/ProductSearchProvider.php` via `ShopModule::searchProviders()`: actieve producten op naam en beschrijving (`SEARCH.md`); test `tests/Service/Search/SearchProvidersTest.php` (ook in `shop`) |
| Pagina-inhoud | `src/Service/ProductContentOwner.php` via `ShopModule::contentOwners()`: de contentblokken onder de productdetail, op de inhoudspagina van het product (`CONTENT-BLOCKS.md`, "Blokken op een product of project"); `product.php` rendert ze, `ProductDeletionService` verwijdert ze eerst |
| Blokken | `ShopModule::blockDefinitions()` — `product_grid` en `shop_collections` (gedeelde basis `ShopListingBlock`, rij `shop_listing_blocks`, optionele kop via `BlockHead`, editor `admin/shop-listing.php`), `item_gallery` (de Collectiegalerij, alleen bron `collection`), `featured_product` (Uitgelicht product: `src/Service/Blocks/FeaturedProductBlock.php`, `FeaturedProductContent.php`, `FeaturedProductRepository.php`, `partials/section-featured-product.php`, `admin/featured-product.php`, `api/admin/update-featured-product.php`, `assets/css/shop/featured-product.css`) |

**Niet van de Shop**, ook al lijkt het erop: `ProductPersonalizationRepository`
en `OrderItemPersonalizationRepository` horen bij de Personalisatie-module, en
`src/Service/Secrets/` (waar de Mollie-sleutels versleuteld staan) is Core
(`SETUP.md`, "Geheimen in het CMS").

## De regels die hier gelden

- **Een request bepaalt nooit een prijs of een verzendbedrag.** Regelprijzen
  worden herlezen uit `products`, verzendkosten herberekend door
  `ShippingCalculationService`. Dit is de belangrijkste regel van dit domein.
- **Een order is een snapshot.** `unit_price`, de personalisatiegegevens en
  de antwoorden op de bestelvelden (`order_item_fields`) staan vast op de
  orderregel, zodat een latere prijs- of productwijziging historische
  bestellingen nooit raakt.
- **Voorraad gaat alleen via `Inventory`.** Reserveren is een voorwaardelijke
  `UPDATE … WHERE stock >= :q` in de ordertransactie, teruggeven claimt eerst
  `orders.stock_released_at`; lees nooit eerst en schrijf dan. Geen teller
  buiten `InventoryRepository` (`Tests\Service\InventoryContractTest`), en
  geen voorraadgetal in een publiek antwoord: `sold_out` en `max_quantity`
  wel. Een beheerder schrijft voorraad alleen over de waarde die zijn scherm
  toonde (`stock_seen`).
- **Op aanvraag heeft nergens een prijs**: niet in HTML, JSON, JSON-LD of een
  kaart, en de server weigert het product in winkelwagencheck en checkout.
- **Een product heeft één implementatie.** Waar een product in zijn geheel
  staat (`product.php`, het blok Uitgelicht product) komen de gegevens uit
  `ProductDetail`, de koopbeslissing uit `ProductPurchasePath`, het
  koopgedeelte uit `partials/product-purchase.php` en het gedrag uit
  `shop.js` per `[data-product-detail]`. Bouw er geen tweede naast; een plek
  mag minder aanbieden, nooit meer.
- **De winkelwagen is volledig client-side**, `vvl-cart` in `localStorage`.
  De server kent hem pas bij het afrekenen.
- **De mini-winkelwagen zit in de gedeelde header** en wordt gevraagd door
  `ShopModule::shellStyles()`, niet door Core. Dat is het enige Shop-bestand
  dat overal laadt.
- **De producteditor is één formulier met één opslag** (`ADMIN-UI.md`, "Een
  editor die opslaat zonder te herladen"). Een optie, waarde of variant is
  een rij op het scherm. `update-product.php` controleert alles en schrijft
  alles in één transactie (`ProductVariantEditor`). Voeg geen endpoint per
  rij toe. Een nieuw veld gaat in hetzelfde formulier, met een melding op
  zijn veldnaam. De Extra vormgeving van zijn blokken is een begeleidend
  formulier dat dezelfde *Opslaan* meeneemt. Een lange sectie klapt in met
  `admin/_admin_collapse.php`, onthouden per product (scope = id); bouw geen
  eigen accordeon. Een variant kiest afbeeldingen uit de pool van het
  product (`product_variant_images`): er is geen afbeelding die alleen bij
  een variant hoort (`MODULES.md`, "Shop").
- **Een klantafbeelding bij een bestelvraag is privé orderdata**
  (`MODULES.md`, "Bestelvelden" → *Afbeelding uploaden*): buiten de webroot,
  nooit in de Mediabibliotheek, `assets/`, een mail of de factuur; de browser
  kent alleen een token, de database alleen zijn hash; claimen gebeurt één
  keer, in de ordertransactie. Serveer hem alleen via
  `api/admin/order-field-upload.php` (`orders.view`, op upload-id).
- **Een factuur bekijken is alleen-lezen.** *Factuur bekijken* op het
  besteloverzicht streamt via `InvoiceService::issuedPdfForOrder()` het
  bestand dat de klant kreeg. Laat het nooit uitgeven, een nummer
  reserveren, een bestand schrijven of mailen, en bouw geen tweede
  factuursjabloon: `PdfInvoiceRenderer` is het enige
  (`Tests\Service\InvoicePreviewTest`).
- **Betalen loopt via het contract.** Checkout, webhook en bestelstatus
  vragen `PaymentProviders::active()`; alleen `MolliePaymentProvider` en het
  scherm Betalingen raken de Mollie-SDK. Voeg geen methode aan
  `PaymentProvider` toe zonder aanroeper, en geen `refund()`
  (`MODULES.md`, "Betalingen").
- **Een sleutel komt nooit terug.** Niet in HTML, JSON, flash, log of
  redirect: alleen de gemaskeerde vorm uit `MollieConfiguration::mask()`.
  Een leeg sleutelveld houdt de opgeslagen sleutel, en een sleutel in de
  serveromgeving is door niets in het CMS te vervangen.
- **Een testbestelling blijft een testbestelling.** `orders.payment_mode`
  wordt één keer geschreven, bij het aanmaken van de betaling, en nooit
  afgeleid van de huidige modus of sleutel. Een betaling wordt opgevraagd
  met de sleutel van díe modus. Een testbestelling telt niet als omzet
  (`OrderRepository::REAL_SALE_CONDITION` in elke query die geld optelt) en
  krijgt nooit een factuur uit de echte reeks
  (`Tests\Service\TestOrderTest`, `MollieWebhookHttpTest`).
- **Betalingen is `payments.manage`**, niet `settings.manage`, en alleen een
  Super Admin kent het toe.
- **Geen test praat met Mollie.** Gebruik `Tests\Support\FakeMollie`
  (`TESTING.md`).
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
