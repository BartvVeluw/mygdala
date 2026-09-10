<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * A story page: heading, a text-and-image introduction, a longer body, and a
 * closing call to action.
 *
 * Named after what an editor is usually making — an "over ons" page — but it
 * produces an ordinary content page like every other template. Nothing about
 * the result is "an About page": the slug is whatever the editor chose, and
 * the four blocks below can be reordered, replaced or removed the moment the
 * page opens.
 */
final class AboutTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'about';
    }

    public function label(): string
    {
        return 'Over ons';
    }

    public function description(): string
    {
        return 'Kop, een introductie met afbeelding, ruimte voor je verhaal en een afsluitende call-to-action.';
    }

    public function blocks(): array
    {
        return ['page_hero', 'text_image_split', 'rich_text', 'cta_band'];
    }

    public function icon(): ?string
    {
        return '<circle cx="12" cy="8" r="4"></circle><path d="M5.5 20a6.5 6.5 0 0 1 13 0"></path>';
    }
}
