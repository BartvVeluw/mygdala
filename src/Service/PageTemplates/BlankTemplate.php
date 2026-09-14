<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * A page with its heading and nothing under it — the default when the editor
 * picks nothing (PageTemplates::DEFAULT_KEY).
 *
 * The Paginakop is there because it carries the page's <h1>; a page without
 * one has no heading element at all (see StandardTemplate). Every block below
 * it is the editor's own choice, made in the page builder, which greets a
 * page like this with an invitation to add the first one. A text block is
 * deliberately NOT added: an empty body nobody asked for is only a block to
 * delete first.
 *
 * It earns its place in the catalogue by being the least an editor can start
 * from: somebody who knows what they want should not have to delete four
 * blocks first, and the picker makes "start from a heading" a visible choice
 * rather than something you get by leaving a field alone.
 */
final class BlankTemplate extends PageTemplateDefinition
{
    public function key(): string
    {
        return 'blank';
    }

    public function label(): string
    {
        return 'Lege pagina';
    }

    public function description(): string
    {
        return 'Alleen een paginakop met de titel. Daaronder kies je zelf je eerste contentblok.';
    }

    public function blocks(): array
    {
        return ['page_hero'];
    }

    public function icon(): ?string
    {
        return '<rect x="4" y="3" width="16" height="18" rx="2"></rect>';
    }
}
