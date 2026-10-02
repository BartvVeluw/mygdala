<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\SiteText;

/**
 * The Shop's own words that its scripts show: the cart, the product page,
 * the checkout and the order status build some of their markup in the
 * browser, and every sentence they put in it comes from here.
 *
 * A CLOSED CODE CATALOGUE, keyed by website language code, like every other
 * piece of system copy (App\Service\Language\SiteText). The server resolves
 * it in the language of the request and hands the browser ONE language
 * (partials/header-cart.php prints it as a JSON data block that
 * assets/js/shop/cart.js reads once). A script never picks a language
 * itself and never holds a Dutch/English pair: a language without its own
 * sentence here reads the default language's, and a third language is one
 * more key per entry, not a branch.
 *
 * {count}, {index}, {label} and {max} are filled in by the script; everything else
 * is plain text that a script writes with textContent or escapes.
 */
final class ShopScriptText
{
    /** @var array<string, array<string, string>> */
    private const TEXTS = [
        'no_products' => ['nl' => 'Er zijn op dit moment geen producten beschikbaar.', 'en' => 'No products are available right now.'],
        'cart_empty' => ['nl' => 'Je winkelwagen is leeg.', 'en' => 'Your cart is empty.'],
        'cart_count_one' => ['nl' => '{count} product', 'en' => '{count} item'],
        'cart_count_many' => ['nl' => '{count} producten', 'en' => '{count} items'],
        'remove' => ['nl' => 'Verwijderen', 'en' => 'Remove'],
        'quantity' => ['nl' => 'Aantal', 'en' => 'Quantity'],
        'quantity_decrease' => ['nl' => 'Aantal verlagen', 'en' => 'Decrease quantity'],
        'quantity_increase' => ['nl' => 'Aantal verhogen', 'en' => 'Increase quantity'],
        'added_to_cart' => ['nl' => 'Toegevoegd aan je winkelwagen', 'en' => 'Added to your cart'],
        'personalization' => ['nl' => 'Personalisatie', 'en' => 'Personalisation'],
        'own_image' => ['nl' => 'eigen afbeelding', 'en' => 'own image'],
        'address_lookup_busy' => ['nl' => 'Adres opzoeken…', 'en' => 'Looking up address…'],
        'address_lookup_unavailable' => [
            'nl' => 'We konden dit adres nu niet verifiëren door een tijdelijk probleem. Probeer het straks opnieuw.',
            'en' => 'We could not verify this address right now due to a temporary problem. Please try again shortly.',
        ],
        'address_lookup_not_found' => [
            'nl' => 'We konden dit adres niet verifiëren. Controleer je postcode en huisnummer.',
            'en' => "We couldn't verify this address. Please check your postal code and house number.",
        ],
        'terms_required' => [
            'nl' => 'Je moet akkoord gaan met de algemene voorwaarden voordat je verder kunt.',
            'en' => 'You must agree to the Terms & Conditions before continuing.',
        ],
        'security_check_failed' => [
            'nl' => 'De beveiligingscontrole kon niet worden voltooid. Probeer het opnieuw.',
            'en' => 'The security check could not be completed. Please try again.',
        ],
        'security_check_expired' => [
            'nl' => 'De beveiligingscontrole is verlopen. Probeer het opnieuw.',
            'en' => 'The security check has expired. Please try again.',
        ],
        'no_shipping_method' => [
            'nl' => 'Voor deze bestelling is geen verzendmethode beschikbaar. Neem contact met ons op.',
            'en' => 'No shipping method is available for this order. Please contact us.',
        ],
        'pickup' => ['nl' => 'Afhalen', 'en' => 'Pickup'],
        'free' => ['nl' => 'Gratis', 'en' => 'Free'],
        'order_failed' => [
            'nl' => 'Er ging iets mis bij het plaatsen van je bestelling. Probeer het opnieuw.',
            'en' => 'Something went wrong while placing your order. Please try again.',
        ],
        'processing' => ['nl' => 'Bezig…', 'en' => 'Processing…'],
        'place_order' => ['nl' => 'Bestelling plaatsen', 'en' => 'Place order'],
        'shipping_address_invalid' => [
            'nl' => 'Controleer de postcode en het huisnummer van je verzendadres.',
            'en' => 'Please check the postal code and house number of your shipping address.',
        ],
        'billing_address_required' => [
            'nl' => 'Vul alle verplichte factuuradresvelden in.',
            'en' => 'Please fill in all required billing address fields.',
        ],
        'billing_address_invalid' => [
            'nl' => 'Controleer de postcode en het huisnummer van je factuuradres.',
            'en' => 'Please check the postal code and house number of your billing address.',
        ],
        // Op aanvraag (App\Service\PurchaseMode): what a card shows instead
        // of a price, and what a cart line of such a product says.
        'on_request' => ['nl' => 'Op aanvraag', 'en' => 'On request'],
        // The product gallery (assets/js/shop/product-gallery.js, Product
        // Gallery 2.1): what a screen reader hears when the picture changes,
        // and the name of the big picture when it opens the lightbox.
        'gallery_position' => ['nl' => 'Afbeelding {index} van {count}', 'en' => 'Image {index} of {count}'],
        'gallery_enlarge' => ['nl' => 'Afbeelding vergroten', 'en' => 'Enlarge image'],
        // Bestelvelden (App\Service\OrderFields\OrderFields): what the product
        // page says next to a question, and how a cart line shows an answer.
        'order_field_required' => ['nl' => 'Vul dit in.', 'en' => 'Please fill this in.'],
        'order_field_choose' => ['nl' => 'Maak een keuze.', 'en' => 'Please choose one.'],
        'order_field_tick' => ['nl' => 'Vink dit aan om verder te gaan.', 'en' => 'Please tick this to continue.'],
        'order_field_too_long' => ['nl' => 'Hooguit {max} tekens.', 'en' => 'At most {max} characters.'],
        // An "Afbeelding uploaden" question (api/order-field-upload.php): the
        // page checks type and size first for a quick answer; the server
        // decides, and its own sentence is shown when it refuses.
        'order_field_image_required' => ['nl' => 'Kies een afbeelding.', 'en' => 'Please choose a picture.'],
        'order_field_image_type' => ['nl' => 'Kies een JPG-, PNG- of WebP-afbeelding.', 'en' => 'Please choose a JPG, PNG or WebP picture.'],
        'order_field_image_too_large' => ['nl' => 'Deze afbeelding is te groot. Het maximum is {max}.', 'en' => 'This picture is too large. The maximum is {max}.'],
        'order_field_image_uploading' => ['nl' => 'Afbeelding wordt geüpload…', 'en' => 'Uploading picture…'],
        'order_field_image_received' => ['nl' => 'Afbeelding ontvangen: {name}', 'en' => 'Picture received: {name}'],
        'order_field_image_removed' => ['nl' => 'Afbeelding verwijderd.', 'en' => 'Picture removed.'],
        'order_field_image_failed' => ['nl' => 'Het uploaden is mislukt. Probeer het opnieuw.', 'en' => 'The upload failed. Please try again.'],
        'yes' => ['nl' => 'Ja', 'en' => 'Yes'],
        'no' => ['nl' => 'Nee', 'en' => 'No'],
        'line_order_fields' => [
            'nl' => 'Controleer je bestelgegevens: verwijder deze regel en voeg het product opnieuw toe.',
            'en' => 'Please check your order details: remove this line and add the product again.',
        ],
        'line_inquiry' => ['nl' => 'Alleen op aanvraag te bestellen', 'en' => 'Available on request only'],
        // Stock (App\Service\CartAvailability, api/cart-check.php): what a
        // cart line, the product page and the checkout say when a unit is
        // sold out or has fewer left than asked.
        'sold_out' => ['nl' => 'Uitverkocht', 'en' => 'Out of stock'],
        'stock_left' => ['nl' => 'Nog {max} op voorraad', 'en' => 'Only {max} left in stock'],
        'line_unavailable' => ['nl' => 'Niet meer te bestellen', 'en' => 'No longer available'],
        'cart_has_problems' => [
            'nl' => 'Niet alles in je winkelwagen is nog te bestellen. Pas de gemarkeerde regels aan.',
            'en' => 'Not everything in your cart can still be ordered. Please adjust the marked lines.',
        ],
        'add_sold_out' => [
            'nl' => 'Dit product is uitverkocht.',
            'en' => 'This product is out of stock.',
        ],
        'notify_done' => [
            'nl' => 'Dank je! We mailen je één keer zodra dit weer te bestellen is.',
            'en' => 'Thank you! We will email you once, as soon as this can be ordered again.',
        ],
        'notify_available' => [
            'nl' => 'Goed nieuws: dit is net weer te bestellen. Ververs de pagina.',
            'en' => 'Good news: this can be ordered again. Please refresh the page.',
        ],
        'notify_invalid' => ['nl' => 'Vul een geldig e-mailadres in.', 'en' => 'Please enter a valid email address.'],
        // Also what adding to the cart says when the server could not give
        // the line its price (shop.js, linePrice()): one sentence for both.
        'notify_failed' => ['nl' => 'Dit lukte niet. Probeer het later opnieuw.', 'en' => 'That did not work. Please try again later.'],
        'add_stock_left' => [
            'nl' => 'Er zijn er nog {max} op voorraad, inclusief wat al in je winkelwagen zit.',
            'en' => 'Only {max} left in stock, including what is already in your cart.',
        ],
    ];

    /** @return list<string> every key a script may ask for */
    public static function keys(): array
    {
        return array_keys(self::TEXTS);
    }

    /**
     * One sentence in the language of the request, its placeholders filled —
     * for the markup the server renders BEFORE a script takes over (the
     * mini-cart's first frame), so both say exactly the same words.
     *
     * @param array<string, string|int> $values
     */
    public static function text(string $key, array $values = []): string
    {
        if (!isset(self::TEXTS[$key])) {
            throw new \InvalidArgumentException('Unknown Shop script text: ' . $key);
        }

        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr(SiteText::pick(self::TEXTS[$key]), $replacements);
    }

    /**
     * Every sentence, in the language of the request.
     *
     * @return array<string, string>
     */
    public static function forRequest(): array
    {
        return array_map(static fn (array $byLanguage): string => SiteText::pick($byLanguage), self::TEXTS);
    }

    /**
     * The catalogue as a JSON data block's contents. Every character that
     * could end the surrounding <script> element or start markup is escaped,
     * so the block stays data whatever a sentence says.
     */
    public static function json(): string
    {
        return (string) json_encode(
            self::forRequest(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
