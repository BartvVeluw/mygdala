<?php

declare(strict_types=1);

namespace App\Service\Redirects;

use App\Repository\RedirectRepository;

/**
 * Keeps a renamed CMS page's OLD URL working, automatically.
 *
 * Called from api/admin/update-page.php once a page has actually been saved
 * with a different slug. Nothing else calls it, and it deliberately has no
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
     * Both arguments are raw slugs as stored on the `pages` row, not paths.
     * Returns true when a redirect for the old path now exists.
     */
    public function record(string $oldSlug, string $newSlug): bool
    {
        $oldPath = RedirectPath::normalize('/' . ltrim(trim($oldSlug), '/'));
        $newPath = RedirectPath::normalize('/' . ltrim(trim($newSlug), '/'));

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
