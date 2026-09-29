<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

/**
 * Something other than a CMS page that carries content blocks: a Shop
 * product, a Portfolio project (Product & Portfolio Content Pages 1.0,
 * CONTENT-BLOCKS.md "Blokken op een product of project").
 *
 * An owner never gets a block engine of its own. It gets a CONTENT PAGE: a
 * `pages` row with `owner_type` = kind(), linked one-to-one through the
 * owner's own link table (real foreign keys on both sides), which holds its
 * blocks exactly as an ordinary page holds them. App\Service\ContentOwners\ContentPages
 * makes, finds and deletes that page; this interface is only what the owner
 * itself must answer.
 *
 * A module contributes its owners through ModuleDefinition::contentOwners(),
 * so Core never names a product or a project (ShopDisabledTest). Every value
 * here is a code constant or read from the owner's own tables, never taken
 * from a request.
 */
interface ContentOwner
{
    /** The `pages.owner_type` value, lowercase a-z and _ (e.g. 'product'). */
    public function kind(): string;

    /** What the CMS calls one, Dutch (e.g. 'Product'). */
    public function label(): string;

    /** The key of the module that contributes this owner (e.g. 'shop'). */
    public function moduleKey(): string;

    /** The link table, one row per owner: owner id -> content page id. */
    public function linkTable(): string;

    /** The owner's column in linkTable(); page_id is the other one. */
    public function linkColumn(): string;

    /** Whether an owner with this id exists (any status). */
    public function exists(int $ownerId): bool;

    /** The owner's name for the CMS, in the default language; '' when it has none. */
    public function name(int $ownerId): string;

    /** The owner's editor, opened on its Pagina-inhoud tab. */
    public function editUrl(int $ownerId): string;

    /**
     * The permission that manages this owner, and with it its content blocks
     * (App\Service\ContentOwners\ContentBlockAccess): the module's own
     * existing right, never pages.manage. A code constant.
     */
    public function permission(): string;
}
