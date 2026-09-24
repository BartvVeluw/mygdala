<?php

declare(strict_types=1);

namespace App\Service\Redirects;

use App\Repository\RedirectRepository;
use App\Service\Routing\LocalizedUrl;

/**
 * Keeps a renamed or moved CMS page's OLD URL working, automatically.
 *
 * Called from api/admin/update-page.php once a page has actually been saved
 * with a different path — its own slug, its parent, or an ancestor's slug
 * (recordMoves(), docs/pages/NESTING.md) — and by the Blog for its renames
 * (record()). Nothing else calls it, and it deliberately has no
 * hook for deleting or unpublishing a page: inventing a destination for
 * content that is simply gone — "everything that was here now goes to the
 * homepage" — is a well-known way to turn a clean 404 into a soft 404, and
 * this project would rather an editor decide where /oude-pagina should point,
 * if anywhere. See REDIRECTS.md.
 *
 * WHAT ONE RENAME DOES, in order:
 *
 *   1. Any redirect whose source is the NEW path is removed. That URL now
 *      serves real content, so the row can never fire again; leaving it would
 *      be a row that looks active and is not.
 *   2. The old path gets a permanent (301) redirect to the new one — created,
 *      or repointed if this page has been renamed before.
 *   3. Every redirect that pointed at the OLD path is repointed at the new
 *      one. This is what makes a second rename keep the first URL alive:
 *
 *          rename 1:  /diensten  ->  /services        gives  /diensten -> /services
 *          rename 2:  /services  ->  /services-new    gives  /services -> /services-new
 *                                                     and    /diensten -> /services-new
 *
 *      One statement, bounded by the target index, and it means the runtime
 *      never has to follow a chain that grows with the number of renames.
 *
 * WHAT IT WILL NOT DO. A row an editor wrote by hand (origin 'manual') is
 * never repointed and never overwritten in step 2. Someone decided where that
 * URL goes, and a rename elsewhere in the CMS is not a reason to overrule
 * them; the rename is logged instead. Step 1 is the one exception, and only
 * because a source path that now serves a live page is dead either way.
 *
 * FAILING SAFE. Everything here is wrapped: a redirect that could not be
 * written must never make the page save that triggered it fail. The rename is
 * the thing the editor asked for; the redirect is care taken on their behalf.
 */
final class SlugChangeRedirects
{
    private RedirectRepository $repository;

    public function __construct(?RedirectRepository $repository = null)
    {
        $this->repository = $repository ?? new RedirectRepository();
    }

    /**
     * Records that a published page moved from one slug to another.
     *
     * Both slug arguments are raw slugs, not paths. $languageCode says which
     * language's URL space they live in; null means the request's, which for
     * the default language is the unprefixed one this method always used.
     *
     * Returns true when a redirect for the old path now exists.
     */
    public function record(string $oldSlug, string $newSlug, ?string $languageCode = null): bool
    {
        // An EMPTY new slug is not a move but an address taken away: a
        // translation whose slug was cleared has no public URL in that
        // language any more (docs/multilingual/ROUTING.md). Built into a path
        // it would be the language's homepage, and pointing the old URL there
        // is the soft 404 this class already refuses to create for a deleted
        // page. The Blog's callers stop before this; now every caller does.
        if (trim(trim($newSlug), '/') === '') {
            return false;
        }

        // The slugs belong to ONE language, and so do the paths built from
        // them (Multilingual 2.0 phase 6): renaming the English version of a
        // page records /en/old -> /en/new. The default language has no
        // prefix, so every redirect written before phase 6 keeps exactly the
        // shape it already had.
        $oldPath = RedirectPath::normalize(LocalizedUrl::path('/' . ltrim(trim($oldSlug), '/'), $languageCode));
        $newPath = RedirectPath::normalize(LocalizedUrl::path('/' . ltrim(trim($newSlug), '/'), $languageCode));

        if ($oldPath === null || $newPath === null || $oldPath === $newPath || $oldPath === '/') {
            return false;
        }

        try {
            $this->removeRedirectsOn($newPath);
            $id = $this->pointOldAtNew($oldPath, $newPath);
            $this->collapseChainsInto($oldPath, $newPath);

            return $id !== null;
        } catch (\Throwable $e) {
            error_log('[SlugChangeRedirects] could not record ' . $oldPath . ' -> ' . $newPath . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Records that one or more pages MOVED, as whole paths rather than slugs —
     * what a page gets when it is nested, moved to another parent, or when a
     * page above it is renamed (Pagina's 2.0, docs/pages/NESTING.md).
     *
     * One move of a page with a subtree is many moves at once:
     *
     *     /materiaal/metaal/aluminium   ->   /materialen/metaal/aluminium
     *     /materiaal/metaal             ->   /materialen/metaal
     *     /materiaal                    ->   /materialen
     *
     * and every one of them is recorded exactly like a rename: the same three
     * steps, the same respect for a redirect an editor wrote by hand, the same
     * guard against a row that would redirect to itself. The caller computes
     * the paths — every page's path in every language before the save and
     * after it (App\Service\PagePath::snapshot()) — and hands over only the
     * pairs that really changed and whose page was and stays published.
     *
     * ALL OR NOTHING, and still failing safe. The moves are written in one
     * transaction, so a subtree never ends up half redirected; a failure rolls
     * them all back, is logged, and returns 0 — the save that caused it has
     * already happened and stays done, like a rename's.
     *
     * @param list<array{from: string, to: string}> $moves site-relative paths,
     *        with their language prefix already on (App\Service\Routing\LocalizedUrl)
     * @return int how many old paths now redirect
     */
    public function recordMoves(array $moves): int
    {
        $pairs = [];

        foreach ($moves as $move) {
            $oldPath = RedirectPath::normalize((string) ($move['from'] ?? ''));
            $newPath = RedirectPath::normalize((string) ($move['to'] ?? ''));

            if ($oldPath === null || $newPath === null || $oldPath === $newPath || $oldPath === '/' || $newPath === '/') {
                continue;
            }

            $pairs[$oldPath] = $newPath;
        }

        if ($pairs === []) {
            return 0;
        }

        $db = \App\Database::connection();
        $ownTransaction = !$db->inTransaction();
        $recorded = 0;

        try {
            if ($ownTransaction) {
                $db->beginTransaction();
            }

            foreach ($pairs as $oldPath => $newPath) {
                $this->removeRedirectsOn($newPath);
                if ($this->pointOldAtNew($oldPath, $newPath) !== null) {
                    $recorded++;
                }
                $this->collapseChainsInto($oldPath, $newPath);
            }

            if ($ownTransaction) {
                $db->commit();
            }

            return $recorded;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }

            error_log('[SlugChangeRedirects] could not record ' . count($pairs) . ' moved page path(s): ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * Step 1: the new path is a live page now, so nothing may claim to
     * redirect away from it.
     */
    private function removeRedirectsOn(string $newPath): void
    {
        $blocking = $this->repository->findBySourcePath($newPath);

        if ($blocking === null) {
            return;
        }

        error_log(sprintf(
            '[SlugChangeRedirects] removing redirect %s -> %s: that path now serves a renamed page',
            $newPath,
            (string) $blocking['target_value']
        ));

        $this->repository->delete((int) $blocking['id']);
    }

    /** Step 2. Returns the row id, or null when an editor's row was left alone. */
    private function pointOldAtNew(string $oldPath, string $newPath): ?int
    {
        $existing = $this->repository->findBySourcePath($oldPath);

        if ($existing === null) {
            return $this->repository->create([
                'source_path' => $oldPath,
                'target_type' => RedirectTarget::TYPE_INTERNAL,
                'target_value' => $newPath,
                'status_code' => Redirect::STATUS_PERMANENT,
                'is_active' => true,
                'origin' => Redirect::ORIGIN_SLUG_CHANGE,
            ]);
        }

        if ((string) $existing['origin'] !== Redirect::ORIGIN_SLUG_CHANGE) {
            error_log(sprintf(
                '[SlugChangeRedirects] leaving the manual redirect on %s alone; the page moved to %s',
                $oldPath,
                $newPath
            ));

            return null;
        }

        $this->repository->updateTarget((int) $existing['id'], RedirectTarget::TYPE_INTERNAL, $newPath);

        return (int) $existing['id'];
    }

    /** Step 3: older URLs of the same page follow it to its new home. */
    private function collapseChainsInto(string $oldPath, string $newPath): void
    {
        foreach ($this->repository->findByInternalTarget($oldPath) as $row) {
            if ((string) $row['origin'] !== Redirect::ORIGIN_SLUG_CHANGE) {
                continue;
            }

            if ((string) $row['source_path'] === $newPath) {
                // Renaming a page back to a slug it used to have: repointing
                // this row would make it redirect to itself.
                continue;
            }

            $this->repository->updateTarget((int) $row['id'], RedirectTarget::TYPE_INTERNAL, $newPath);
        }
    }
}
