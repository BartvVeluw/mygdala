<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * What one translatable field of a block is to the site search (Search 2.0,
 * SEARCH.md "De tekst van de blokken"), declared by the block itself in
 * BlockDefinition::searchFields(). Search never names a block type or a
 * block table; it reads these roles.
 *
 *   HEADING  a heading a visitor sees: a block title, an item title, a FAQ
 *            question. Found, and weighs more than TEXT.
 *   TEXT     the words a visitor reads: an intro, a body, an answer, a
 *            quote, a note. Found.
 *   NONE     stored, but not something a visitor looks for: an alt text,
 *            a button or link label, an anchor label, a label only one
 *            display mode shows. Never indexed.
 *
 * Rich-text fields are TEXT; the headings inside them (<h1>–<h6>) count as
 * headings on their own (App\Service\Search\BlockTextExtractor).
 */
final class BlockSearchRole
{
    public const HEADING = 'heading';
    public const TEXT = 'text';
    public const NONE = 'none';

    public const ALL = [self::HEADING, self::TEXT, self::NONE];
}
