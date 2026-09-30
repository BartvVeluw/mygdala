<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * A block that can say whether an instance has content yet — what the page
 * builder's "Leeg blok" warning asks (SectionRegistry::isEmpty(),
 * CONTENT-BLOCKS.md, "Een leeg blok herkennen"). The page builder never names
 * a type and never checks a field itself: each block answers from its own
 * read model, so "content" means what that block shows, not "there is text".
 * A gallery with pictures and no words has content; a Detailsectie with only
 * a picture has content; a text block without a body does not.
 *
 * NOT IMPLEMENTING THIS IS A CHOICE, made on purpose and listed in
 * Tests\Service\ContentBlockLifecycleContractTest::NEVER_EMPTY: a decorative
 * block (Witruimte is its size) and a dynamic block whose content is its
 * source (Productraster, Projectinformatie) are never "empty".
 */
interface InspectsContent
{
    /**
     * Whether this instance shows anything on the public page in the
     * website's default language (the language that decides whether a block
     * has words at all). Called only for a block the page builder shows; a
     * block hidden in its own editor answers true, because hidden is not
     * empty. A lookup that fails answers true as well: the warning is a
     * hint and must never cry wolf.
     *
     * @param array<string, mixed> $pageSection the full page_sections row
     */
    public function hasContent(array $pageSection): bool;
}
