<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Database;
use App\Module\BlogModule;
use App\Service\Media\MediaUsage;
use App\Service\Media\MediaUsageProvider;

/**
 * How the Blog answers the Media Library's "do you use any of these images,
 * and where?" — the module's own contribution through
 * App\Module\BlogModule::mediaUsageProviders().
 *
 * This is what keeps Core Media from ever naming a blog post: the library
 * knows about media and about providers, and the Blog answers for its own
 * table (MEDIA.md, MODULES.md). It is also what makes a featured image
 * undeletable while a post still shows it — the library refuses to delete an
 * item that any provider reports, and the `RESTRICT` foreign key is the
 * database's own backstop underneath that.
 *
 * ONE QUERY FOR THE WHOLE BATCH, the contract every provider follows: the
 * library's overview asks about a page of items at once and needs a count per
 * tile, so a query per item would be the N+1 the contract exists to prevent.
 *
 * BOTH COLUMNS COUNT. A post's featured image and its separate social image
 * are two different uses of possibly two different items, and either one is a
 * reason not to delete. They are reported with the wording the module's own
 * screens use, so an editor reading "Blogbericht: <titel>" knows exactly
 * which post to open — and the edit link takes them there.
 *
 * WHO READS THE TITLE. Only an administrator who may open the post: the
 * permission admin/blog-post.php demands, blog.manage. Reading the overview
 * (blog.view) does not open a post, so it does not name one here either;
 * anybody else is told the image is used (App\Service\Media\VisibleMediaUsages).
 *
 * A DISABLED MODULE REPORTS NOTHING, because the registry only asks the
 * modules that are running. That is the general rule (MODULES.md): data
 * belonging to a switched-off module is not part of the running site. The
 * rows stay exactly as they were, and switching the Blog back on makes its
 * images protected again.
 */
final class BlogPostMediaUsage extends MediaUsageProvider
{
    public function key(): string
    {
        return 'blog_post';
    }

    public function label(): string
    {
        return 'Blogberichten';
    }

    public function usagesFor(array $mediaIds): array
    {
        $ids = array_values(array_map('intval', $mediaIds));

        if ($ids === []) {
            return [];
        }

        $placeholders = $this->placeholders(count($ids));

        // The id list twice, once per column: PDO runs with emulated
        // prepares off, so the same positional set is passed again rather
        // than a named placeholder being reused.
        $stmt = Database::connection()->prepare(
            'SELECT id, featured_media_id, og_media_id
             FROM blog_posts
             WHERE featured_media_id IN (' . $placeholders . ')
                OR og_media_id IN (' . $placeholders . ')'
        );
        $stmt->execute([...$ids, ...$ids]);

        $rows = $stmt->fetchAll();

        // The title is not a column of this table any more (Multilingual 2.0
        // phase 5 wave B): it is what the CMS calls the post, in the default
        // language, and one lookup covers every row.
        BlogLocalization::preloadPosts(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $usages = [];

        foreach ($rows as $row) {
            $title = BlogLocalization::postName((int) $row['id']);
            $editUrl = '/admin/blog-post.php?id=' . (int) $row['id'];

            foreach (['featured_media_id', 'og_media_id'] as $column) {
                $mediaId = (int) ($row[$column] ?? 0);

                if ($mediaId < 1 || !in_array($mediaId, $ids, true)) {
                    continue;
                }

                $usages[$mediaId][] = new MediaUsage(
                    source: $this->key(),
                    label: $column === 'og_media_id'
                        ? 'Deel-afbeelding van blogbericht: ' . $title
                        : 'Blogbericht: ' . $title,
                    permission: BlogModule::BLOG_MANAGE,
                    editUrl: $editUrl,
                );
            }
        }

        return $usages;
    }
}
