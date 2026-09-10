<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * The plainest useful page: a heading and a body. This is the shape the three
 * former information pages (Algemene voorwaarden, Privacyverklaring,
 * Verzenden & retourneren) already have, and the one most new pages want.
 *
 * The Page Hero is what carries the page's <h1>; a page whose body is only a
 * rich-text block has no heading element of its own, which is why every
 * template except the blank one opens with it.
 */
final class StandardTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'standard';
    }

    public function label(): string
    {
        return 'Standaard contentpagina';
    }

    public function description(): string
    {
        return 'Een kop met introductie en daaronder één tekstblok. Geschikt voor uitleg, voorwaarden en losse informatiepagina\'s.';
    }

    public function blocks(): array
    {
        return ['page_hero', 'rich_text'];
    }

    public function icon(): ?string
    {
        return '<path d="M4 5h16"></path><path d="M4 10h16"></path><path d="M4 14h12"></path><path d="M4 18h9"></path>';
    }
}
