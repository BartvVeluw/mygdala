<?php

/**
 * POST /api/checkout.php
 *
 * Validates the checkout form + cart sent by the frontend, verifies Terms &
 * Conditions acceptance + a Cloudflare Turnstile token server-side, creates
 * the customer/order/order_items rows (status "pending"), creates a Mollie
 * payment for the order's total, and returns the URL to redirect the
 * customer to. Prices/totals are never trusted from the frontend — every
 * line price is re-read from the products table, and shipping cost/method
 * are always recalculated server-side via
 * App\Service\Shipping\ShippingCalculationService (never accepted from the
 * request) — see MAIN.MD "Shipping calculation system".
 *
 * Personalization (a customer's engraving text and/or uploaded image) is
 * never trusted either: every submitted personalization is re-validated
 * against the product's LIVE CMS configuration here — what is allowed, how
 * long the text may be, whether the upload really belongs to that product —
 * and the resulting order line stores an immutable snapshot of that
 * configuration, so a later product change can never rewrite an existing
 * order. See App\Service\Personalization\PersonalizationValidator and
 * MAIN.MD "Productpersonalisatie".
 *
 * Flow: field validation -> Terms & Conditions acceptance flag present ->
 * cart/product/variant/personalization/shipping revalidation (all
 * server-side, as before) ->
 * shipping (and, if different, billing) address validation — for a
 * Netherlands address this means a real Dutch BAG/PDOK lookup via
 * App\Service\Address\CheckoutAddressResolver, which can never be skipped or
 * spoofed from the frontend (see MAIN.MD "Dutch address validation") ->
 * load the current Terms & Conditions from the CMS + hash it (App\Service\
 * LegalPages) -> verify the Turnstile token server-side (App\Service\
 * TurnstileVerifier, Cloudflare Siteverify) -> only then create the
 * customer/order/order_items rows -> create the Mollie payment. Terms
 * acceptance, address validation and Turnstile are all checked before the
 * database transaction that creates the order, and Turnstile is checked
 * before the Mollie payment is created — see MAIN.MD "Checkout security &
 * compliance".
 *
 * Request body (JSON):
 *   {
 *     "voornaam": "...", "achternaam": "...", "email": "...", "telefoon": "...",
 *     "bedrijf": "..." (optional),
 *     "land": "NL" | "BE" | ... (required when verzendmethode is "verzenden"; forced to "NL" for "afhalen"),
 *     "postcode": "...", "huisnummer": "...", "huisnummer_toevoeging": "..." (optional),
 *     "straat": "...", "plaats": "...",
 *       (straat/plaats are only used as posted for a non-NL "land" — for NL
 *        they are always replaced by the canonical PDOK street/city)
 *     "verzendmethode": "afhalen" | "verzenden",
 *     "betaalmethode": "ideal" | "kaart",
 *     "items": [
 *       { "id": 3, "qty": 2 },
 *       { "id": 4, "qty": 1, "variant_id": 7,
 *         "personalization": {           // optional, only for a personalizable product
 *           "zone_key": "default",
 *           "text": "Bart",
 *           "upload_token": "…32 hex…",  // from POST /api/personalization-upload.php
 *           "transform": { "text": {"x":0.5,"y":0.5,"scale":1,"rotation":0},
 *                          "image": {"x":0.5,"y":0.5,"scale":1,"rotation":0} }
 *         } }
 *     ],
 *     "terms_accepted": true,
 *     "turnstile_token": "...",
 *     "facturatie_zelfde": true | false,
 *       (when false, the same set of fields as above is required again,
 *        prefixed "facturatie_": facturatie_land, facturatie_voornaam,
 *        facturatie_achternaam, facturatie_bedrijf, facturatie_postcode,
 *        facturatie_huisnummer, facturatie_huisnummer_toevoeging,
 *        facturatie_straat, facturatie_plaats)
 *   }
 *
 * Response (200): { "checkoutUrl": "https://www.mollie.com/checkout/..." }
 * Response (4xx/5xx): { "error": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');


use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderItemPersonalizationRepository;
use App\Repository\OrderRepository;
use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Repository\PersonalizationUploadRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Service\Address\AddressValidationException;
use App\Service\Address\CheckoutAddressResolver;
use App\Service\LegalPages;
use App\Service\MollieClientFactory;
use App\Service\OrderItemNameSnapshot;
use App\Service\MolliePaymentData;
use App\Service\Personalization\Money;
use App\Service\Personalization\PersonalizationValidationException;
use App\Service\Personalization\PersonalizationValidator;
use App\Service\Personalization\ProductPersonalizationContent;
use App\Service\Shipping\ShippingCalculationService;
use App\Service\ShopLocalization;
use App\Service\Shipping\ShippingUnavailableException;
use App\Service\TurnstileVerifier;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Types\PaymentMethod;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

const SHIPPING_METHOD_CHOICES = ['afhalen', 'verzenden'];

const PAYMENT_METHODS = [
    'ideal' => PaymentMethod::IDEAL,
    'kaart' => PaymentMethod::CREDITCARD,
];

function fail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function requiredString(mixed $value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > $maxLength) {
        return null;
    }
    return $value;
}

/** Like requiredString(), but null/empty is a valid "not provided" — only an over-length value fails. */
function optionalString(mixed $value, int $maxLength, string $tooLongMessage): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        fail(400, $tooLongMessage);
    }
    return $value;
}

/**
 * Parses + resolves one address (shipping, or billing when it differs from
 * shipping) from the request body, prefixing every field name with $prefix
 * (e.g. "" for shipping, "facturatie_" for billing). Delegates the actual
 * Dutch BAG/PDOK verification to CheckoutAddressResolver — this function is
 * purely request-parsing glue around it, never doing the verification
 * itself (see MAIN.MD "Dutch address validation" for why that logic lives
 * in one reusable service instead of here).
 *
 * @return array{first_name:string, last_name:string, company:?string, country:string, postal_code:string, house_number:string, house_number_addition:?string, street:string, city:string}
 */
function parseAndResolveAddress(array $body, string $prefix, string $firstName, string $lastName, string $land): array
{
    $company = optionalString($body[$prefix . 'bedrijf'] ?? null, 150, 'Company name is too long.');
    $postcode = requiredString($body[$prefix . 'postcode'] ?? null, 15);
    $huisnummer = requiredString($body[$prefix . 'huisnummer'] ?? null, 20);
    $toevoeging = optionalString($body[$prefix . 'huisnummer_toevoeging'] ?? null, 20, 'House number addition is too long.');
    $straat = requiredString($body[$prefix . 'straat'] ?? null, 255);
    $plaats = requiredString($body[$prefix . 'plaats'] ?? null, 100);

    if ($postcode === null || $huisnummer === null || $straat === null || $plaats === null) {
        fail(400, 'Please fill in all required address fields.');
    }

    try {
        return (new CheckoutAddressResolver())->resolve(
            $firstName,
            $lastName,
            $company,
            $land,
            $postcode,
            $huisnummer,
            $toevoeging,
            $straat,
            $plaats
        );
    } catch (AddressValidationException $e) {
        fail($e->reason === 'unavailable' ? 503 : 422, $e->getMessage());
    }
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);

if (!is_array($body)) {
    fail(400, 'Invalid request body.');
}

$voornaam = requiredString($body['voornaam'] ?? null, 100);
$achternaam = requiredString($body['achternaam'] ?? null, 100);
$email = is_string($body['email'] ?? null) ? trim($body['email']) : null;
$telefoon = is_string($body['telefoon'] ?? null) ? trim($body['telefoon']) : '';
$verzendmethode = $body['verzendmethode'] ?? null;
$betaalmethode = $body['betaalmethode'] ?? null;
$items = $body['items'] ?? null;
$land = is_string($body['land'] ?? null) ? strtoupper(trim($body['land'])) : '';

if ($voornaam === null || $achternaam === null) {
    fail(400, 'Please fill in all required fields.');
}

if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'Please enter a valid email address.');
}

if (mb_strlen($telefoon) > 30) {
    fail(400, 'Invalid phone number.');
}

if (!is_string($verzendmethode) || !in_array($verzendmethode, SHIPPING_METHOD_CHOICES, true)) {
    fail(400, 'Invalid shipping method.');
}

// Pickup has no destination, so it's always treated as domestic (matches the
// customer record's previous hardcoded default); a shipped order must name a
// real country — which country codes actually resolve to a shipping zone is
// entirely data-driven (App\Repository\ShippingZoneRepository), never
// hardcoded here.
if ($verzendmethode === 'afhalen') {
    $land = $land !== '' ? $land : 'NL';
} elseif (!preg_match('/^[A-Z]{2}$/', $land)) {
    fail(400, 'Please select a valid destination country.');
}

// Shipping address: for "land" NL this is where the real PDOK verification
// happens (network call, so done once fields are structurally present but
// before the heavier cart/product DB work below) — a bad/unverifiable Dutch
// address stops the request here, well before an order or Mollie payment
// could ever be created.
$shippingAddress = parseAndResolveAddress($body, '', $voornaam, $achternaam, $land);

$billingSameAsShipping = ($body['facturatie_zelfde'] ?? true) !== false;
$billingAddress = null;
if (!$billingSameAsShipping) {
    $facturatieLand = is_string($body['facturatie_land'] ?? null) ? strtoupper(trim($body['facturatie_land'])) : '';
    if (!preg_match('/^[A-Z]{2}$/', $facturatieLand)) {
        fail(400, 'Please select a valid billing country.');
    }
    $facturatieVoornaam = requiredString($body['facturatie_voornaam'] ?? null, 100);
    $facturatieAchternaam = requiredString($body['facturatie_achternaam'] ?? null, 100);
    if ($facturatieVoornaam === null || $facturatieAchternaam === null) {
        fail(400, 'Please fill in all required billing address fields.');
    }
    $billingAddress = parseAndResolveAddress($body, 'facturatie_', $facturatieVoornaam, $facturatieAchternaam, $facturatieLand);
}

if (!is_string($betaalmethode) || !array_key_exists($betaalmethode, PAYMENT_METHODS)) {
    fail(400, 'Invalid payment method.');
}

// Cheap, data-independent check first — no point hitting the database for
// cart/product validation if the customer never accepted the Terms &
// Conditions. This is the authoritative check: the frontend checkbox is
// only a convenience, disabling/bypassing it client-side can never produce
// a value that passes here.
if (($body['terms_accepted'] ?? null) !== true) {
    fail(400, 'You must agree to the Terms & Conditions before continuing.');
}

if (!is_array($items) || $items === []) {
    fail(400, 'Your cart is empty.');
}

// Keyed by "productId|variantId|personalizationFingerprint" ("" for no
// variant / no personalization) so two different variants of the same
// product are always separate order lines — and so are two units of the same
// product personalized differently ("Bart" and "Inge" are never one line of
// two). The same product+variant+personalization twice in the cart just adds
// up (mirrors cartAdd() / cartLineKey() in main.js).
//
// Personalization is validated HERE, against the product's live CMS
// configuration, before anything is priced or ordered — see
// App\Service\Personalization\PersonalizationValidator. A cart line for a
// product without personalization sends nothing and validates to null, so
// every existing cart keeps behaving exactly as before.
$personalizationValidator = new PersonalizationValidator();
$productRepository = new ProductRepository();
$requestedLines = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        fail(400, 'Invalid cart contents.');
    }
    $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
    $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
    $variantId = null;
    if (($item['variant_id'] ?? null) !== null) {
        $variantId = filter_var($item['variant_id'], FILTER_VALIDATE_INT);
        if ($variantId === false || $variantId < 1) {
            fail(400, 'Invalid cart contents.');
        }
    }
    if ($id === false || $id < 1 || $qty === false || $qty < 1 || $qty > 50) {
        fail(400, 'Invalid cart contents.');
    }

    try {
        $personalization = $personalizationValidator->validate($id, $item['personalization'] ?? null);
    } catch (PersonalizationValidationException $e) {
        // Already written for a customer, and free of any technical detail.
        fail(422, $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[api/checkout.php] personalization: ' . $e->getMessage());
        fail(500, 'Could not verify your personalization right now. Please try again.');
    }

    /**
     * A PERSONALIZATION-ONLY product (`products.in_shop = 0`) has no ordinary
     * purchase path at all, so a line for one that carries no personalization
     * cannot be honoured.
     *
     * The validator already refuses this for every product whose
     * configuration is renderable, because such a product resolves to
     * `is_required` regardless of its own mode. This guard covers the one
     * case it cannot see: a personalization-only product whose configuration
     * is switched off or unfinished resolves to NO configuration at all, and
     * would otherwise fall through as an ordinary product and be sold blank —
     * exactly the thing it must never be. Refusing here is also the honest
     * answer: there is genuinely no way to order that product right now.
     */
    if ($personalization === null && !$productRepository->isShopPurchasable($id)) {
        fail(422, 'One of your items can only be ordered with personalization. Please open the product and complete it.');
    }

    /**
     * The COMPOSED preview snapshots this line submitted — one PNG per view,
     * exactly as the customer saw it. Supplementary throughout: an unusable
     * token is dropped rather than failing the order, and a line with none is
     * complete. Resolved here, next to the personalization it belongs to, so
     * a token can only ever be claimed for the product it was posted for.
     */
    $previewSnapshots = [];
    if ($personalization !== null) {
        try {
            $config = ProductPersonalizationContent::forProduct($id);
            if ($config !== null) {
                $previewSnapshots = $personalizationValidator->validatePreviewTokens(
                    $config,
                    $item['preview_tokens'] ?? null
                );
            }
        } catch (\Throwable $e) {
            // A snapshot is a convenience for the workshop; losing one must
            // never cost the customer their order.
            error_log('[api/checkout.php] preview snapshots: ' . $e->getMessage());
        }
    }

    $key = $id . '|' . ($variantId ?? '') . '|' . PersonalizationValidator::fingerprint($personalization);
    if (isset($requestedLines[$key])) {
        $requestedLines[$key]['qty'] += $qty;
        // Two identical personalizations merge into one line, and so do their
        // snapshots: they are pictures of the same thing.
        $requestedLines[$key]['preview_snapshots'] += $previewSnapshots;
    } else {
        $requestedLines[$key] = [
            'product_id' => $id,
            'variant_id' => $variantId,
            'qty' => $qty,
            'personalization' => $personalization,
            'preview_snapshots' => $previewSnapshots,
        ];
    }
}

$productIds = array_values(array_unique(array_map(
    static fn (array $line): int => $line['product_id'],
    $requestedLines
)));

try {
    $products = $productRepository->findActiveByIds($productIds);
} catch (\Throwable $e) {
    error_log('[api/checkout.php] ' . $e->getMessage());
    fail(500, 'Could not load product data right now.');
}

if (count($products) !== count($productIds)) {
    fail(400, 'Your cart contains a product that is no longer available.');
}

$variantRepository = new ProductVariantRepository();

/**
 * Line pricing is done in whole CENTS, never in floats. A personalization
 * surcharge is added to a product price and then summed across lines, which
 * is exactly the repeated addition that turns a float total into a one-cent
 * discrepancy with the invoice. See App\Service\Personalization\Money.
 *
 * The surcharge itself is read from the PRODUCT's own zone configuration by
 * PersonalizationValidator (above) — the request is never even consulted for
 * an amount, so there is nothing for a client to tamper with.
 */
$orderItems = [];
$subtotalCents = 0;

// The languages this order's name snapshots are taken in: the default one
// for the neutral name every document prints, and every other active
// website language beside it — but only where the product has words of
// its own, so "no English name" stays "no English name"
// (App\Service\OrderItemNameSnapshot).
$snapshotLanguage = ShopLocalization::defaultLanguage();
$snapshotNames = static function (int $productId): array {
    $names = [];
    foreach (OrderItemNameSnapshot::extraLanguages() as $code) {
        $own = trim(ShopLocalization::rawProduct($productId, ShopLocalization::NAME, $code));
        if ($own !== '') {
            $names[$code] = $own;
        }
    }

    return $names;
};

foreach ($requestedLines as $line) {
    $productId = $line['product_id'];
    $qty = $line['qty'];
    $basePriceCents = Money::toCents($products[$productId]['price']);
    $variantLabel = null;

    if ($line['variant_id'] !== null) {
        // Never trust a variant id/price sent by the frontend beyond this
        // lookup: it must exist, belong to this exact product, and be active.
        $variant = $variantRepository->findActiveForProduct($line['variant_id'], $productId);
        if ($variant === null) {
            fail(400, 'Your cart contains a variant that is no longer available.');
        }
        if ($variant['price'] !== null) {
            $basePriceCents = Money::toCents($variant['price']);
        }
        $variantLabel = $variantRepository->buildLabel($line['variant_id']);
    }

    // A surcharge is charged once per UNIT of this line, on top of whatever
    // the product or its variant costs.
    $surchargeCents = $line['personalization']['surcharge_cents'] ?? 0;
    $unitPriceCents = $basePriceCents + $surchargeCents;

    $subtotalCents += $unitPriceCents * $qty;
    $orderItems[] = [
        'product_id' => $productId,
        'variant_id' => $line['variant_id'],
        'variant_label' => $variantLabel,
        'quantity' => $qty,
        'unit_price' => Money::format($unitPriceCents),
        // What that unit price is made of, so an order can always explain
        // itself without re-reading a product that may have changed since.
        'base_unit_price' => Money::format($basePriceCents),
        'personalization_surcharge' => Money::format($surchargeCents),
        // Snapshot of the product title at the moment of purchase — see
        // db/migrations/20260907170000_add_product_snapshot_to_order_items.php.
        //
        // Since Multilingual 2.0 phase 5 wave C a product's name is words
        // rather than a column, so the neutral snapshot is its name in the
        // DEFAULT language and the other active languages are recorded beside
        // it once the line has an id (App\Service\OrderItemNameSnapshot).
        // Read here, inside the transaction, so a rename a second later cannot
        // change what this order says.
        'product_name' => ShopLocalization::product($productId, ShopLocalization::NAME, $snapshotLanguage),
        'product_name_words' => $snapshotNames($productId),
        // Never written to order_items — kept alongside it so the order-line
        // personalization rows can be created once the line has an id. Null
        // for every unpersonalized line.
        'personalization' => $line['personalization'],
        'preview_snapshots' => $line['preview_snapshots'],
    ];
}

// Never trust a shipping price/method sent by the frontend: pickup is always
// exactly €0, and a shipped order's price/method is always recalculated here
// from the database (destination zone, current product shipping settings,
// current admin-configured rates) via the one central shipping calculator.
if ($verzendmethode === 'afhalen') {
    $shippingCost = 0.00;
    $shippingMethod = 'afhalen';
} else {
    $shippingLines = array_map(
        static fn (array $item): array => ['product_id' => $item['product_id'], 'quantity' => $item['quantity']],
        $orderItems
    );

    try {
        $shippingResult = (new ShippingCalculationService())->calculateForShipping($shippingLines, $land);
    } catch (ShippingUnavailableException $e) {
        fail(422, $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[api/checkout.php] ' . $e->getMessage());
        fail(500, 'Could not calculate shipping costs right now.');
    }

    $shippingCost = $shippingResult->price;
    $shippingMethod = $shippingResult->method;
}

// Shipping is the one amount that still arrives as a float (the calculator's
// own return type), so it crosses into cents exactly once, here, and the
// total is then summed and formatted without a float ever touching it again.
$totalCents = $subtotalCents + Money::toCents($shippingCost);
$total = Money::format($totalCents);

// Load the current, published Terms & Conditions from the CMS and hash it
// now, right before creating anything — never earlier, since the content
// (and therefore the hash) must reflect what's live at the moment this
// order is placed. If the page can't be loaded for any reason (missing,
// unpublished, or a lookup failure), fail safely: log the technical detail,
// never create an order without a hash, never a fabricated/placeholder one.
$termsAcceptedAt = new \DateTimeImmutable();
$termsContentHash = LegalPages::hashCurrentTerms();
if ($termsContentHash === null) {
    error_log('[api/checkout.php] Could not load/hash the Terms & Conditions CMS page (slug "' . LegalPages::TERMS_SLUG . '").');
    fail(500, 'Could not verify the Terms & Conditions right now. Please try again shortly.');
}

// Verify the Turnstile token server-side (Cloudflare Siteverify) before the
// order/payment can be created — see App\Service\TurnstileVerifier, which
// fails closed on every ambiguous case (missing/invalid/expired token,
// success=false, network/API failure). Never expose Cloudflare's raw
// response or the secret key to the client; only a generic error.
$turnstileToken = $body['turnstile_token'] ?? null;
$turnstileVerified = (new TurnstileVerifier())->verify($turnstileToken, $_SERVER['REMOTE_ADDR'] ?? null);
if (!$turnstileVerified) {
    fail(400, 'The security check could not be completed. Please try again.');
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $shippingAddition = $shippingAddress['house_number_addition'] ?? '';
    $shippingAdditionSeparator = ($shippingAddition !== '' && ctype_digit($shippingAddition[0])) ? '-' : '';
    $shippingStreetLine = trim(
        $shippingAddress['street'] . ' ' . $shippingAddress['house_number']
        . $shippingAdditionSeparator . $shippingAddition
    );

    $customerId = (new CustomerRepository($db))->findOrCreateByEmail([
        'name' => trim($voornaam . ' ' . $achternaam),
        'email' => $email,
        'phone' => $telefoon !== '' ? $telefoon : null,
        'address_line' => $shippingStreetLine,
        'postal_code' => $shippingAddress['postal_code'],
        'city' => $shippingAddress['city'],
        'country' => $shippingAddress['country'],
    ]);

    $orderRepository = new OrderRepository($db);
    $orderId = $orderRepository->create(
        $customerId,
        $total,
        $shippingCost,
        $shippingMethod,
        'EUR',
        true,
        $termsAcceptedAt,
        $termsContentHash,
        $shippingAddress,
        $billingAddress
    );
    $orderItemIds = $orderRepository->addItems($orderId, $orderItems);

    /**
     * What each product was called in the OTHER website languages, recorded
     * once, in this same transaction. The neutral name already sits on the
     * line; these are the versions the order-status page offers a visitor
     * (App\Service\OrderItemNameSnapshot). A language a product has no own
     * name in gets no row, which is exactly what the two fixed columns did.
     */
    foreach ($orderItems as $index => $orderItem) {
        if (!isset($orderItemIds[$index])) {
            continue;
        }

        foreach ($orderItem['product_name_words'] as $code => $name) {
            OrderItemNameSnapshot::record((int) $orderItemIds[$index], (string) $code, (string) $name);
        }
    }

    /**
     * Personalization becomes ORDER DATA here, inside the same transaction
     * as the order itself: the customer's exact text, the retained upload,
     * where they placed both, and a snapshot of the product's personalization
     * configuration as it is right now. From this moment nothing an
     * administrator later changes about the product — a different engraving
     * area, a shorter maximum text, personalization switched off entirely —
     * can alter what this customer submitted.
     *
     * Claiming the upload in the same transaction is what makes the file
     * permanent: an unclaimed upload is disposable and gets swept away, a
     * claimed one never is.
     */
    $itemPersonalizations = new OrderItemPersonalizationRepository($db);
    $uploadRepository = new PersonalizationUploadRepository($db);
    $snapshotRepository = new PersonalizationPreviewSnapshotRepository($db);

    foreach ($orderItems as $index => $orderItem) {
        if ($orderItem['personalization'] === null || !isset($orderItemIds[$index])) {
            continue;
        }

        /**
         * Bind this line's composed previews to it, so they stop being
         * disposable drafts and become order evidence. claim() only ever
         * binds an UNCLAIMED row, so a token that already belongs to another
         * order cannot be re-pointed here.
         */
        foreach ($orderItem['preview_snapshots'] as $snapshotViewKey => $snapshotId) {
            $snapshotRepository->claim($snapshotId, $orderItemIds[$index], (string) $snapshotViewKey);
        }

        // One row per personalized ZONE, so an order line with a name on the
        // front and a message on the back keeps those as two distinct,
        // separately labelled records rather than one merged blob.
        foreach ($orderItem['personalization']['zones'] as $zone) {
            $itemPersonalizations->create($orderItemIds[$index], [
                'zone_key' => $zone['zone_key'],
                'view_key' => $zone['view_key'],
                'upload_id' => $zone['upload_id'],
                'text_value' => $zone['text_value'],
                // The font, plus the order's OWN copy of what that key
                // rendered as. Copying it here is what lets the owner
                // rename, deactivate or delete a library font later without
                // changing — or blanking — what this order says it was
                // engraved in.
                'font_key' => $zone['font_key'],
                'font_label' => $zone['font_label'],
                'font_stack' => $zone['font_stack'],
                'font_file_path' => $zone['font_file_path'],
                'text_color' => $zone['text_color'],
                'surcharge_cents' => $zone['surcharge_cents'],
                'transform' => $zone['transform'],
                'config_snapshot' => $zone['config_snapshot'],
            ]);

            if ($zone['upload_id'] !== null) {
                $uploadRepository->markClaimed($zone['upload_id']);
            }
        }
    }

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    error_log('[api/checkout.php] ' . $e->getMessage());
    fail(500, 'Could not create your order right now.');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;
$hostname = explode(':', $host)[0];
$isLocalHost = in_array($hostname, ['localhost', '127.0.0.1'], true);

try {
    // The order as it was just stored, with the number OrderRepository::create()
    // gave it inside the transaction above. The payment carries exactly the
    // number the e-mails, the admin and the invoice show, never one built here.
    $order = (new OrderRepository($db))->findById($orderId);
    if ($order === null) {
        throw new \RuntimeException('Order ' . $orderId . ' could not be read back after it was created.');
    }

    $payment = MollieClientFactory::client()->payments->create(MolliePaymentData::forOrder(
        $order,
        \App\Service\SiteSettings::get('site_name'),
        $baseUrl,
        PAYMENT_METHODS[$betaalmethode],
        // Mollie rejects webhook URLs that point at localhost/private hosts — skip it there
        // (local testing falls back to the return page re-checking the payment status live).
        !$isLocalHost
    ));

    (new OrderRepository($db))->setMolliePaymentId($orderId, $payment->id);

    echo json_encode(['checkoutUrl' => $payment->getCheckoutUrl()]);
} catch (ApiException $e) {
    error_log('[api/checkout.php] Mollie error: ' . $e->getMessage());
    fail(502, 'Could not start the payment right now. Please try again.');
} catch (\Throwable $e) {
    error_log('[api/checkout.php] ' . $e->getMessage());
    fail(500, 'Could not start the payment right now. Please try again.');
}
