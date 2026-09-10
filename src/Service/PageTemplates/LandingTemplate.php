<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * A campaign page: a prominent opening, one text-and-image section to make
 * the case, a grid of highlights, and a call to action.
 *
 * It opens with the ordinary Page Hero rather than the Homepage Hero: the
 * latter is registered for the site root only (allowed_pages: ['index']), by
 * design, so no template can put a second one anywhere.
 */
final class LandingTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'landing';
    }

    public function label(): string
    {
        return 'Landingspagina';
    }

    public function description(): string
    {
        return 'Een opvallende kop, een sectie met afbeelding, een raster met pluspunten en een duidelijke call-to-action.';
    }

    public function blocks(): array
    {
        return ['page_hero', 'text_image_split', 'feature_grid', 'cta_band'];
    }

    public function icon(): ?string
    {
        return '<path d="M4 4h16v6H4z"></path><path d="M4 14h7v6H4z"></path><path d="M13 14h7v6h-7z"></path>';
    }
}
