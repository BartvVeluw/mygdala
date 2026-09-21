<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Service\Language\SiteText;

/**
 * The sentences assets/js/personalization.js shows while a customer
 * personalizes a product: the upload status, the upload errors and the
 * "not finished yet" reasons.
 *
 * A CLOSED CODE CATALOGUE keyed by website language code, like every other
 * piece of system copy (App\Service\Language\SiteText, and the Shop's own
 * App\Service\ShopScriptText). partials/product-personalization.php resolves
 * it in the language of the request and puts it in the panel's configuration
 * under `text`, so the script receives ONE language and never picks one
 * itself. A language without its own sentence here reads the default
 * language's.
 *
 * {label} (a zone's name) and {max} (a character count) are filled in by the
 * script; the result is plain text, written with textContent.
 */
final class PersonalizationScriptText
{
    /** @var array<string, array<string, string>> */
    private const TEXTS = [
        'upload_busy' => ['nl' => 'Afbeelding uploaden…', 'en' => 'Uploading image…'],
        'upload_failed' => [
            'nl' => 'De afbeelding kon niet worden geüpload. Probeer een andere PNG of JPG.',
            'en' => 'The image could not be uploaded. Please try another PNG or JPG.',
        ],
        'upload_connection_failed' => [
            'nl' => 'De afbeelding kon niet worden geüpload. Controleer je verbinding en probeer het opnieuw.',
            'en' => 'The image could not be uploaded. Please check your connection and try again.',
        ],
        'wait_for_upload' => [
            'nl' => 'Wacht even tot de afbeelding is geüpload.',
            'en' => 'Please wait until the image has finished uploading.',
        ],
        'fill_in_first' => [
            'nl' => 'Vul eerst je personalisatie in — dit product wordt speciaal voor jou gemaakt.',
            'en' => 'Please fill in your personalisation first — this product is made especially for you.',
        ],
        'text_too_long' => [
            'nl' => 'De tekst bij “{label}” is te lang (maximaal {max} tekens).',
            'en' => 'The text for “{label}” is too long (maximum {max} characters).',
        ],
        'zone_required' => [
            'nl' => 'Vul “{label}” nog in — dat is verplicht voor dit product.',
            'en' => 'Please complete “{label}” — it is required for this product.',
        ],
    ];

    /** @return list<string> every key the script may ask for */
    public static function keys(): array
    {
        return array_keys(self::TEXTS);
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
}
