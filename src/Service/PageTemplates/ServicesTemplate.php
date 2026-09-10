<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * An offering page: heading, a carousel of cards for the individual
 * services, room for the detail underneath, and a closing call to action.
 *
 * The card carousel is the generic "a row of cards you fill yourself" block
 * — the editor decides how many cards there are and what is on them. It is
 * not tied to the catalogue, so this template works exactly the same on a
 * site with the Shop switched off.
 */
final class ServicesTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'services';
    }

    public function label(): string
    {
        return 'Diensten';
    }

    public function description(): string
    {
        return 'Kop, een carrousel met kaarten voor je diensten, een tekstblok voor de details en een afsluitende call-to-action.';
    }

    public function blocks(): array
    {
        return ['page_hero', 'card_carousel', 'rich_text', 'cta_band'];
    }

    public function icon(): ?string
    {
        return '<rect x="3" y="6" width="6" height="12" rx="1.5"></rect><rect x="11" y="6" width="6" height="12" rx="1.5"></rect><path d="M20 8v8"></path>';
    }
}
