<?php

declare(strict_types=1);

namespace App\Service\PageTemplates;

/**
 * ONE page template, in ONE file: the starting point an editor picks in
 * "Nieuwe pagina", and nothing else.
 *
 * A TEMPLATE IS A CREATION-TIME HELPER. It decides which content blocks a
 * brand-new page starts with, and then it is done. Nothing records which
 * template a page came from, no rendering path consults one, and the page
 * that comes out is an ordinary CMS content page in every respect — the
 * editor may reorder, edit, hide or delete every block the template put
 * there, including all of them. See PAGE-TEMPLATES.md.
 *
 * That is why this class has no render(), no update() and no delete(): there
 * is no such thing as "a page of template X" after the moment of creation,
 * so there is nothing for a template to do later. Adding a method that runs
 * after creation would be the first step towards a page type, which this
 * design deliberately does not have.
 *
 * A definition is stateless and instantiated once per request by
 * PageTemplates; it must never be constructed from request data.
 */
abstract class PageTemplateDefinition
{
    /** The registry key — must match the key this definition is registered under in PageTemplates. */
    abstract public function key(): string;

    /** The admin-facing name on the template card (Dutch, like the rest of the admin). */
    abstract public function label(): string;

    /**
     * One sentence telling the editor what they get. This is the whole
     * explanation of the template: there is no separate help screen, and the
     * card has room for a sentence, not a paragraph.
     */
    abstract public function description(): string;

    /**
     * The block types this template starts a page with, in the order they
     * should appear on the page — the same `section_type` keys
     * App\Service\Blocks\BlockDefinitions registers.
     *
     * Types only. A template deliberately does NOT supply block content: the
     * starter text of a block belongs to the block that owns the fields
     * (every BlockDefinition::create() already writes clearly generic,
     * editable placeholders), and a second copy of it here would be a second
     * owner for the same text — guaranteed to drift the first time a block
     * gains, renames or drops a field. What a template contributes is
     * STRUCTURE.
     *
     * Every type named here must be a Core block that an editor could also
     * have added by hand. Tests\Service\PageTemplateRegistryTest enforces
     * both halves: a module's block may not appear (a template must behave
     * identically with the Shop on or off), and neither may a fixed block
     * nobody can add manually.
     *
     * @return list<string>
     */
    abstract public function blocks(): array;

    /**
     * The card's icon: the INNER markup of a 24x24 stroke <svg>, matching
     * the sidebar icons in admin/_header.php. The view supplies the <svg>
     * wrapper, so a definition contributes shape data and never a whole
     * element.
     *
     * This is trusted, hardcoded, first-party constant markup — it is echoed
     * unescaped by admin/page-new.php, and must therefore never be built
     * from request data, database content or anything an administrator can
     * type. Returning null renders no icon at all.
     */
    public function icon(): ?string
    {
        return null;
    }
}
