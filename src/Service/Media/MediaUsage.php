<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * One place a media item is used, as the admin shows it: what kind of thing
 * uses it, which one, and — when there is somewhere to go — the admin screen
 * that edits it.
 *
 * Deliberately just enough to answer an editor's question ("where is this
 * used, and can I go fix it?"). It is NOT a foreign key in disguise: nothing
 * reads `ownerId` back to load a record. Deletion is decided on whether this
 * list is empty, not on what is in it.
 */
final class MediaUsage
{
    public function __construct(
        /** The provider that reported it, e.g. 'branding' or 'blocks'. */
        public readonly string $source,
        /** Dutch, human-readable: "Logo", "Tekst + afbeelding op Over mij". */
        public readonly string $label,
        /** Admin URL that edits this usage, or null when there is none. */
        public readonly ?string $editUrl = null,
    ) {
    }
}
