<?php

namespace App\Service\Shipping\PostNl;

/**
 * Thrown by PostNlRateFetcher for any network/parse failure while retrieving
 * the current PostNL tariff document (landing page unreachable, no PDF link
 * found, PDF download failed, PDF text extraction failed). Always caught by
 * PostNlRateSyncService, which treats it as "source unreachable" — see
 * MAIN.MD "Veiligheid bij synchronisatie": every existing carrier_rates price
 * is left completely untouched, the run is logged as failed, never as a €0
 * or guessed price.
 */
final class PostNlFetchException extends \RuntimeException
{
}
