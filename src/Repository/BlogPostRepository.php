<?php

namespace App\Repository;

use App\Service\Blog\BlogPostStatus;

/**
 * All `blog_posts` SQL, plus the two link tables that connect a post to its
 * categories and tags.
 *
 * THE VISIBILITY RULE LIVES HERE ONCE, as PUBLIC_WHERE below: not a draft,
 * with a publication moment that is set and has passed. Every public read —
 * the listing, the detail route, the archives, related posts, the sitemap
 * collector and the RSS feed — goes through a method that applies it, so
 * there is no query in this project that can accidentally show a draft or a
 * post scheduled for next week. Its in-PHP twin is
 * App\Service\Blog\BlogPostStatus::isPublic(), used where a row is already in
 * hand.
 *
 * `now` is always a BOUND PARAMETER from App\Service\Blog\BlogClock, never
 * MySQL's NOW(): the admin writes `published_at` with PHP's clock, so PHP's
 * clock is what may compare against it (see BlogClock).
 *
 * Ordinary repository shape for this project: every statement prepared, no
 * value interpolated into SQL except LIMIT/OFFSET, which MySQL will not take
 * as a placeholder and which are clamped to integers before they go in.
 * Nothing here decides anything — App\Service\Blog\BlogContent asks for rows.
 */
class BlogPostRepository extends Repository
{
    /**
     * The one public-visibility predicate. Uses the named parameter :now,
     * which every caller binds exactly once (PDO runs with emulation off, so
     * a repeated name would be an error rather than a convenience).
     */
    private const PUBLIC_WHERE = "p.status <> '" . BlogPostStatus::DRAFT
        . "' AND p.published_at IS NOT NULL AND p.published_at <= :now";

    /** Newest first, with the id as a stable tie-breaker for equal moments. */
    private const PUBLIC_ORDER = 'p.published_at DESC, p.id DESC';

    /* ------------------------------------------------------------------ */
    /* Single posts                                                        */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_posts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Any post with this slug, whatever its status — the admin's lookup. */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM blog_posts WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The post at /blog/<slug>, or null. A draft, a future-scheduled post and
     * a slug nobody owns are deliberately the same answer, so the public
     * route cannot tell them apart either.
     *
     * @return array<string, mixed>|null
     */
    /**
     * findPublicBySlug() by id, for the localized address lookup: the address
     * of a language resolves to a post id (App\Service\Language\EntityTranslations),
     * and whether that post may be SHOWN is still this one visibility rule
     * and not a second copy of it.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicById(int $id, string $now): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.* FROM blog_posts p WHERE p.id = :id AND ' . self::PUBLIC_WHERE . ' LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'now' => $now]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findPublicBySlug(string $slug, string $now): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.* FROM blog_posts p WHERE p.slug = :slug AND ' . self::PUBLIC_WHERE . ' LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'now' => $now]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM blog_posts WHERE slug = :slug AND id <> :exclude LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'exclude' => $excludeId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Public listings                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * One page of the public listing, optionally narrowed to a category or a
     * tag. The two filters are exclusive — a URL is either an archive of one
     * or of the other — and both are joined rather than sub-queried so the
     * index on the link table does the work.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPublic(string $now, int $limit, int $offset, ?int $categoryId = null, ?int $tagId = null): array
    {
        [$join, $where, $params] = $this->publicFilter($now, $categoryId, $tagId);

        $sql = 'SELECT p.* FROM blog_posts p' . $join
            . ' WHERE ' . $where
            . ' ORDER BY ' . self::PUBLIC_ORDER
            . ' LIMIT ' . max(1, min(100, $limit))
            . ' OFFSET ' . max(0, $offset);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** How many posts that same listing has in total, for the pager. */
    public function countPublic(string $now, ?int $categoryId = null, ?int $tagId = null): int
    {
        [$join, $where, $params] = $this->publicFilter($now, $categoryId, $tagId);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM blog_posts p' . $join . ' WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every public post, for the sitemap collector — the four columns
     * App\Service\Blog\BlogSeo::isIndexable() needs to make exactly the same
     * decision the post's own robots tag makes, plus the slug and the
     * timestamp the entry is built from.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPublicForSitemap(string $now): array
    {
        $stmt = $this->db->prepare(
            // `id` travels along since Multilingual 2.0 phase 6: a post's
            // addresses per language hang off its id, and without it the
            // sitemap could only ever list the default language's URL.
            'SELECT p.id, p.slug, p.updated_at, p.noindex, p.status, p.published_at FROM blog_posts p WHERE ' . self::PUBLIC_WHERE
            . ' ORDER BY ' . self::PUBLIC_ORDER
        );
        $stmt->execute(['now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * The newest public posts, for the RSS feed. Capped by the caller; a feed
     * is a window on recent writing, not an archive.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findPublicForFeed(string $now, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.* FROM blog_posts p WHERE ' . self::PUBLIC_WHERE
            . ' ORDER BY ' . self::PUBLIC_ORDER
            . ' LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * The public post immediately before or after this one in publication
     * order — the "vorige / volgende" links on a detail page.
     *
     * @return array<string, mixed>|null
     */
    public function findNeighbour(array $post, string $now, string $direction): ?array
    {
        $publishedAt = (string) ($post['published_at'] ?? '');

        if ($publishedAt === '') {
            return null;
        }

        // Strictly older ("prev") or strictly newer ("next"), with the id
        // breaking a tie between two posts published at the same moment, so
        // the two directions can never both skip or both return the same row.
        $comparison = $direction === 'next'
            ? '(p.published_at > :pivot OR (p.published_at = :pivot_tie AND p.id > :pivot_id))'
            : '(p.published_at < :pivot OR (p.published_at = :pivot_tie AND p.id < :pivot_id))';

        $order = $direction === 'next' ? 'p.published_at ASC, p.id ASC' : self::PUBLIC_ORDER;

        $stmt = $this->db->prepare(
            'SELECT p.* FROM blog_posts p WHERE ' . self::PUBLIC_WHERE . ' AND ' . $comparison
            . ' ORDER BY ' . $order . ' LIMIT 1'
        );
        $stmt->execute([
            'now' => $now,
            // The same value three times under three names: PDO runs with
            // emulated prepares off, so one name may be bound only once.
            'pivot' => $publishedAt,
            'pivot_tie' => $publishedAt,
            'pivot_id' => (int) $post['id'],
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Posts that share a category or a tag with this one, most overlap first.
     *
     * Deterministic and simple, on purpose: the score is the number of shared
     * taxonomy rows, ties are broken by publication date, and nothing here
     * looks at what anybody read or clicked. A post with no taxonomy at all
     * gets no related posts rather than a random selection.
     *
     * @param list<int> $categoryIds
     * @param list<int> $tagIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRelated(int $postId, array $categoryIds, array $tagIds, string $now, int $limit): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));

        if ($categoryIds === [] && $tagIds === []) {
            return [];
        }

        $params = ['now' => $now, 'self' => $postId];
        $unions = [];

        if ($categoryIds !== []) {
            $names = [];
            foreach ($categoryIds as $index => $id) {
                $name = 'rc' . $index;
                $names[] = ':' . $name;
                $params[$name] = $id;
            }
            $unions[] = 'SELECT post_id FROM blog_post_categories WHERE category_id IN (' . implode(',', $names) . ')';
        }

        if ($tagIds !== []) {
            $names = [];
            foreach ($tagIds as $index => $id) {
                $name = 'rt' . $index;
                $names[] = ':' . $name;
                $params[$name] = $id;
            }
            $unions[] = 'SELECT post_id FROM blog_post_tags WHERE tag_id IN (' . implode(',', $names) . ')';
        }

        // UNION ALL rather than UNION: a duplicate row is exactly the signal
        // "this post shares more than one category/tag", and COUNT(*) turns
        // that into the overlap score.
        $sql = 'SELECT p.*, COUNT(*) AS overlap'
            . ' FROM blog_posts p'
            . ' INNER JOIN (' . implode(' UNION ALL ', $unions) . ') AS shared ON shared.post_id = p.id'
            . ' WHERE ' . self::PUBLIC_WHERE . ' AND p.id <> :self'
            . ' GROUP BY p.id'
            . ' ORDER BY overlap DESC, ' . self::PUBLIC_ORDER
            . ' LIMIT ' . max(1, min(12, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /* ------------------------------------------------------------------ */
    /* The admin overview                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The Berichten overview, with its three optional filters. Every post is
     * listed whatever its status — this is the editorial view, not a public
     * one — newest publication date first, then newest created.
     *
     * `title_ids` is how the title search arrives since Multilingual 2.0
     * phase 5 wave B: the caller asks App\Service\Blog\BlogLocalization which
     * posts have a matching title in any website language and passes the ids,
     * because a post's words are not columns of this table any more and a
     * domain repository does not name a translation table in its own SQL. An
     * empty list means "nothing matched", which is not the same as no search
     * at all — hence a separate key rather than an empty `search`.
     *
     * @param array{status?: string, category_id?: int, title_ids?: list<int>} $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForAdmin(array $filters = [], int $limit = 200): array
    {
        $where = ['1 = 1'];
        $params = [];
        $join = '';

        $status = (string) ($filters['status'] ?? '');
        if (BlogPostStatus::isValid($status)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $join = ' INNER JOIN blog_post_categories pc ON pc.post_id = p.id AND pc.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        // Title only. A full-text search over the body is a different feature
        // with different costs; an editor looking for a post looks for its
        // title. The matching is done by the words store (see the docblock);
        // ids that came back from nowhere match nothing at all.
        if (array_key_exists('title_ids', $filters)) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', (array) $filters['title_ids']),
                static fn (int $id): bool => $id > 0
            )));

            if ($ids === []) {
                return [];
            }

            $where[] = 'p.id IN (' . implode(', ', $ids) . ')';
        }

        $sql = 'SELECT p.* FROM blog_posts p' . $join
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC'
            . ' LIMIT ' . max(1, min(500, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * How many posts sit in each status, for the filter chips.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach ($this->db->query('SELECT status, COUNT(*) AS total FROM blog_posts GROUP BY status')->fetchAll() as $row) {
            $counts[BlogPostStatus::normalize($row['status'])] = (int) $row['total'];
        }

        return $counts;
    }

    /* ------------------------------------------------------------------ */
    /* Writing                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $values
     */
    public function create(array $values): int
    {
        $parameters = $this->parameters($values);
        $columns = array_keys($parameters);

        $stmt = $this->db->prepare(
            'INSERT INTO blog_posts (' . implode(', ', $columns) . ', created_at, updated_at)'
            . ' VALUES (:' . implode(', :', $columns) . ', NOW(), NOW())'
        );
        $stmt->execute($parameters);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $parameters = $this->parameters($values);

        $assignments = [];
        foreach (array_keys($parameters) as $column) {
            $assignments[] = $column . ' = :' . $column;
        }

        $parameters['id'] = $id;

        $stmt = $this->db->prepare(
            'UPDATE blog_posts SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute($parameters);
    }

    /**
     * Removes the post and, through the link tables' ON DELETE CASCADE, its
     * category and tag relationships. Media items it referenced are NOT
     * touched: a featured image belongs to the Media Library and may be on
     * three other posts (MEDIA.md).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM blog_posts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Taxonomy links                                                      */
    /* ------------------------------------------------------------------ */

    /** @return list<int> */
    public function categoryIdsFor(int $postId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.category_id FROM blog_post_categories pc
             INNER JOIN blog_categories c ON c.id = pc.category_id
             WHERE pc.post_id = :post_id
             ORDER BY c.sort_order ASC, c.id ASC'
        );
        $stmt->execute(['post_id' => $postId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public function tagIdsFor(int $postId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pt.tag_id FROM blog_post_tags pt
             INNER JOIN blog_tags t ON t.id = pt.tag_id
             WHERE pt.post_id = :post_id
             ORDER BY t.id ASC'
        );
        $stmt->execute(['post_id' => $postId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The categories of several posts at once, keyed by post id — one query,
     * so a listing of nine posts does not run nine.
     *
     * @param list<int> $postIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function categoriesForPosts(array $postIds): array
    {
        return $this->taxonomyForPosts(
            $postIds,
            'SELECT pc.post_id, c.* FROM blog_post_categories pc
             INNER JOIN blog_categories c ON c.id = pc.category_id
             WHERE pc.post_id IN (%s)
             ORDER BY c.sort_order ASC, c.id ASC'
        );
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function tagsForPosts(array $postIds): array
    {
        return $this->taxonomyForPosts(
            $postIds,
            'SELECT pt.post_id, t.* FROM blog_post_tags pt
             INNER JOIN blog_tags t ON t.id = pt.tag_id
             WHERE pt.post_id IN (%s)
             ORDER BY t.id ASC'
        );
    }

    /**
     * Replaces a post's categories with exactly this set. Delete-then-insert
     * rather than a diff: the set is small, the composite primary key makes a
     * duplicate impossible, and one obvious statement pair beats a clever one.
     *
     * @param list<int> $categoryIds
     */
    public function setCategories(int $postId, array $categoryIds): void
    {
        $this->replaceLinks('blog_post_categories', 'category_id', $postId, $categoryIds);
    }

    /** @param list<int> $tagIds */
    public function setTags(int $postId, array $tagIds): void
    {
        $this->replaceLinks('blog_post_tags', 'tag_id', $postId, $tagIds);
    }

    /**
     * How many PUBLIC posts each category has right now, keyed by category
     * id. What the archive listing and the sitemap use to leave an empty
     * category out.
     *
     * @return array<int, int>
     */
    public function publicCountsByCategory(string $now): array
    {
        $stmt = $this->db->prepare(
            'SELECT pc.category_id, COUNT(*) AS total
             FROM blog_post_categories pc
             INNER JOIN blog_posts p ON p.id = pc.post_id
             WHERE ' . self::PUBLIC_WHERE . '
             GROUP BY pc.category_id'
        );
        $stmt->execute(['now' => $now]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['category_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The same, per tag.
     *
     * @return array<int, int>
     */
    public function publicCountsByTag(string $now): array
    {
        $stmt = $this->db->prepare(
            'SELECT pt.tag_id, COUNT(*) AS total
             FROM blog_post_tags pt
             INNER JOIN blog_posts p ON p.id = pt.post_id
             WHERE ' . self::PUBLIC_WHERE . '
             GROUP BY pt.tag_id'
        );
        $stmt->execute(['now' => $now]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['tag_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** How many posts (any status) a category holds — the deletion warning. */
    public function countByCategory(int $categoryId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM blog_post_categories WHERE category_id = :id');
        $stmt->execute(['id' => $categoryId]);

        return (int) $stmt->fetchColumn();
    }

    public function countByTag(int $tagId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM blog_post_tags WHERE tag_id = :id');
        $stmt->execute(['id' => $tagId]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function publicFilter(string $now, ?int $categoryId, ?int $tagId): array
    {
        $join = '';
        $where = self::PUBLIC_WHERE;
        $params = ['now' => $now];

        if ($categoryId !== null && $categoryId > 0) {
            $join = ' INNER JOIN blog_post_categories pc ON pc.post_id = p.id AND pc.category_id = :category_id';
            $params['category_id'] = $categoryId;
        } elseif ($tagId !== null && $tagId > 0) {
            $join = ' INNER JOIN blog_post_tags pt ON pt.post_id = p.id AND pt.tag_id = :tag_id';
            $params['tag_id'] = $tagId;
        }

        return [$join, $where, $params];
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function taxonomyForPosts(array $postIds, string $sqlTemplate): array
    {
        $ids = array_values(array_unique(array_map('intval', $postIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(sprintf($sqlTemplate, $placeholders));
        $stmt->execute($ids);

        $byPost = [];
        foreach ($stmt->fetchAll() as $row) {
            $postId = (int) $row['post_id'];
            unset($row['post_id']);
            $byPost[$postId][] = $row;
        }

        return $byPost;
    }

    /**
     * @param list<int> $ids
     */
    private function replaceLinks(string $table, string $column, int $postId, array $ids): void
    {
        $delete = $this->db->prepare('DELETE FROM ' . $table . ' WHERE post_id = :post_id');
        $delete->execute(['post_id' => $postId]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO ' . $table . ' (post_id, ' . $column . ') VALUES (:post_id, :linked_id)'
        );

        foreach ($ids as $id) {
            $insert->execute(['post_id' => $postId, 'linked_id' => $id]);
        }
    }

    /**
     * The writable columns, with everything the caller left out omitted
     * rather than blanked — an update writes what it was given.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function parameters(array $values): array
    {
        // The WORDS are not here: since Multilingual 2.0 phase 5 wave B
        // title, excerpt, body, meta_title and meta_description live per
        // website language in blog_post_translations and are written through
        // App\Service\Blog\BlogLocalization, in the same transaction as this
        // row. What is left is the slug and everything that is the same in
        // every language.
        $columns = [
            'slug', 'featured_media_id', 'status', 'published_at', 'author_name',
            'noindex', 'og_media_id',
        ];

        $parameters = [];

        foreach ($columns as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }

            $value = $values[$column];

            $parameters[$column] = match ($column) {
                'noindex' => (int) (bool) $value,
                'featured_media_id', 'og_media_id' => ($value === null || (int) $value <= 0) ? null : (int) $value,
                'status' => BlogPostStatus::normalize($value),
                // The one NOT NULL column is stored as given: it is validated
                // before it gets here, and turning an empty one into NULL
                // would swap a rejected save for a fatal.
                'slug' => (string) $value,
                // Everywhere else an empty field IS "nothing", so it is
                // stored as NULL rather than as an empty string — the
                // language fallback and the SEO hierarchy both test for it.
                default => is_string($value) && trim($value) === '' ? null : $value,
            };
        }

        return $parameters;
    }
}
