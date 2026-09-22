<?php

declare(strict_types=1);

namespace App\Update;

/**
 * Every refusal and failure the updater reports, in one shape.
 *
 * Two audiences read it, so it carries two things:
 *
 *   messageKey + params   what the administrator is told, as a key in the
 *                         CMS catalogs (src/Service/Language/messages/). The
 *                         KEY is what the state file and the update log
 *                         store, not the Dutch sentence, so a log written
 *                         while one administrator had the CMS in Dutch still
 *                         reads in English for the next one.
 *   getMessage()          the technical detail, in English, for support: the
 *                         path, the HTTP status, the SQL error. Never a
 *                         secret — a manifest URL is reduced to its host and
 *                         path before it gets here (UpdateConfig::describeUrl).
 *
 * Same idea as the validation errors SiteSettingsValidator returns, except
 * that an update error has to survive a request boundary: the step that
 * failed is often not the request that shows the failure.
 */
final class UpdateException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $params placeholders for the catalog text
     */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $params = [],
        string $detail = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($detail !== '' ? $detail : $messageKey, 0, $previous);
    }
}
