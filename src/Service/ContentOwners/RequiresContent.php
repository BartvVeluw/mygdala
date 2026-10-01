<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

/**
 * A content owner that, in some states, must keep at least one block that
 * says something (ContentPages::hasMeaningfulBlocks()). Optional: an owner
 * that does not implement it may lose all its blocks at any time, as a page,
 * a product and a project always could.
 *
 * The reason is a publish rule. An article may only go out with a meaningful
 * block (ARTICLES.md); without this, an article that was published could lose
 * its last block afterwards and stay published, empty. OwnerContentGuard asks
 * this interface at every block change of the owner's list and refuses the
 * change — it never quietly moves the owner back to a draft.
 */
interface RequiresContent
{
    /**
     * Whether this owner must keep a meaningful block right now: read from
     * the database, inside the transaction of the change being judged. For an
     * article: every status but Concept (the Publishing Engine runs the
     * publish rule for every status but Concept, so scheduled and archived
     * articles were checked too when they got that status).
     */
    public function requiresContent(int $ownerId): bool;

    /** What the CMS says when a change is refused, in the CMS language. */
    public function contentRequiredMessage(): string;
}
