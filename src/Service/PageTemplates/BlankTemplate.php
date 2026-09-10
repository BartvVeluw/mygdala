<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * No blocks at all — the page the CMS created before templates existed, and
 * still the default when the editor picks nothing (PageTemplates::DEFAULT_KEY).
 *
 * It earns its place in the catalogue precisely because it is empty: an
 * editor who knows what they want should not have to delete four blocks
 * first, and the picker should make "start from nothing" a visible choice
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
        return 'Een pagina zonder secties. Je bouwt hem helemaal zelf op met de paginabouwer.';
    }

    public function blocks(): array
    {
        return [];
    }

    public function icon(): ?string
    {
        return '<rect x="4" y="3" width="16" height="18" rx="2"></rect>';
    }
}
