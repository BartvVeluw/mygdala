<?php

namespace App\Service\Blocks;

/**
 * A FIXED block: content a page template used to hardcode between its
 * page-builder sections (the storefront's collection tiles and product grid,
 * the Diensten quicknav). It is positioned and reordered like any other
 * block, but it has no content row of its own — whatever already owned the
 * content still owns it (the product catalogue, Instellingen, the
 * theme).
 *
 * That is the whole difference, so this class answers the content-row half of
 * BlockDefinition once: nothing to create, nothing to delete, no cache of its
 * own and no table. A fixed definition only has to declare its type, its
 * metadata (manual_add = false, deletable = false, max_instances = 1, plus
 * `kind` and usually `edit_links` pointing at the admin domain that does own
 * the content) and how it renders.
 */
abstract class FixedBlockDefinition extends BlockDefinition
{
    public function create(string $pageSlug): array
    {
        throw new \RuntimeException("Section type \"{$this->type()}\" cannot be created from the page builder.");
    }

    public function deleteContent(array $pageSection): void
    {
        throw new \RuntimeException("Section type \"{$this->type()}\" cannot be deleted via the page builder.");
    }

    /**
     * Fixed blocks point at the admin domain that owns their content through
     * their metadata's `edit_links` (SectionRegistry::editLinks() reads it),
     * so there is no per-instance editor URL to build.
     */
    public function editUrl(array $pageSection): ?string
    {
        return null;
    }

    public function clearCache(): void
    {
    }

    public function contentTable(): ?string
    {
        return null;
    }

    /**
     * No rows of its own, so no words of its own: what a fixed block shows is
     * worded where its content lives (the quicknav's labels are the
     * Detailsecties' own, in block_translations).
     */
    public function translatableFields(): array
    {
        return [];
    }
}
