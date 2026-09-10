<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Blog Module V1: posts, categories, tags, the two link tables between them,
 * and the module's own small settings store.
 *
 * The Blog is an OPTIONAL first-party module (App\Module\BlogModule, see
 * BLOG.md and MODULES.md), so these tables exist on every installation while
 * the module itself defaults to OFF. That is the same separation the rest of
 * this project already makes: the schema is deployment-independent and
 * forward-only, and whether a module contributes anything is configuration.
 * A site that never switches the Blog on carries six empty tables; a site
 * that switches it off again keeps every row it wrote.
 *
 * NAMING. NL is the unsuffixed column and EN carries `_en`, exactly like
 * `collections` (name/name_en, description/description_en, meta_title/
 * meta_title_en). An empty EN value falls back to the NL one everywhere, via
 * App\Service\Seo::pick(), like every other translatable field here.
 *
 * SEO fields are the same four `pages` and `collections` already use, plus
 * `noindex` and a Media Library reference for the social image — so the Blog
 * plugs into App\Service\SeoMetadata without a second SEO vocabulary.
 *
 * FEATURED IMAGES ARE MEDIA LIBRARY ITEMS and nothing else: there is no
 * `image_path` twin here. The `pages`/blocks columns of that name are
 * legacy paths from before the library existed (MEDIA.md); a table created
 * after it has no history to fall back to, so a featured image is a
 * `media_id` with ON DELETE RESTRICT — the library refuses to delete an item
 * a post still uses.
 *
 * PUBLICATION. `status` is one of draft/published/scheduled
 * (App\Service\Blog\BlogPostStatus) and `published_at` is the moment it may
 * become public. Visibility is therefore ONE predicate — not draft, and
 * published_at is set and in the past — which is what makes a scheduled post
 * appear without a cron job. The index on (status, published_at) is what the
 * public listing, the feed and the sitemap all read through.
 *
 * Forward-only, idempotent and MySQL/Vimexx-compatible: plain CREATE TABLE,
 * no CTEs, no window functions, no stored routines. It seeds NOTHING — a
 * fresh installation gets no sample article, no default category and no tag
 * (INSTALL-BOOTSTRAP.md).
 */
final class CreateTheBlogModuleTables extends AbstractMigration
{
    public function up(): void
    {
        $this->createBlogPosts();
        $this->createBlogCategories();
        $this->createBlogTags();
        $this->createBlogPostCategories();
        $this->createBlogPostTags();
        $this->createBlogSettings();
    }

    public function down(): void
    {
        // Reverse creation order: the link tables before the rows they point at.
        foreach ([
            'blog_post_tags',
            'blog_post_categories',
            'blog_settings',
            'blog_tags',
            'blog_categories',
            'blog_posts',
        ] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }
    }

    private function createBlogPosts(): void
    {
        if ($this->hasTable('blog_posts')) {
            return;
        }

        $this->table('blog_posts', ['id' => true])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('title_en', 'string', ['limit' => 200, 'null' => true])
            // The last path segment of /blog/<slug>. Unique across posts, and
            // sanitised with the same rules a CMS page slug gets
            // (App\Service\Blog\BlogSlug). It cannot collide with an
            // application route the way a page slug can, because every post
            // URL is namespaced under /blog/.
            ->addColumn('slug', 'string', ['limit' => 170])
            // Plain text, shown in the listing and used as the meta
            // description / feed description fallback. Not rich text: an
            // excerpt is a sentence, and a listing card is not the place for
            // headings and lists.
            ->addColumn('excerpt', 'text', ['null' => true])
            ->addColumn('excerpt_en', 'text', ['null' => true])
            // Sanitised rich-text HTML (App\Service\RichTextSanitizer), the
            // same storage Portfolio project descriptions and the Rich text
            // block already use.
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('body_en', 'text', ['null' => true])
            ->addColumn('featured_media_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'draft'])
            // When this post may become public. NULL for a draft that has
            // never been scheduled; a past moment for a published post; a
            // future moment for a scheduled one.
            ->addColumn('published_at', 'datetime', ['null' => true])
            // The display author, typed by the editor. Deliberately NOT a
            // foreign key to admin_users: who WROTE a post and whose CMS
            // account saved it are different questions, and a byline must
            // not change because a colleague fixed a typo.
            ->addColumn('author_name', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('meta_title', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('meta_title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('meta_description', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('meta_description_en', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('noindex', 'boolean', ['default' => false])
            // The post's own social sharing image, when it should differ from
            // the featured one. Empty means: the featured image, then the
            // site-wide default (SEO.md).
            ->addColumn('og_media_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            // The one index every public read goes through: "not a draft, and
            // published_at is in the past", newest first.
            ->addIndex(['status', 'published_at'])
            ->addIndex(['published_at'])
            ->addForeignKey('featured_media_id', 'media', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->addForeignKey('og_media_id', 'media', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->create();
    }

    private function createBlogCategories(): void
    {
        if ($this->hasTable('blog_categories')) {
            return;
        }

        $this->table('blog_categories', ['id' => true])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('name_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('slug', 'string', ['limit' => 170])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('description_en', 'text', ['null' => true])
            // An inactive category keeps its posts and its rows; its archive
            // simply stops answering and it is not offered on the editor.
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['is_active', 'sort_order'])
            ->create();
    }

    private function createBlogTags(): void
    {
        if ($this->hasTable('blog_tags')) {
            return;
        }

        $this->table('blog_tags', ['id' => true])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('name_en', 'string', ['limit' => 100, 'null' => true])
            // The normalised form is what makes a tag unique: "Laser Cutting"
            // and "laser-cutting" are the same tag, so the second one is
            // reused rather than created (App\Service\Blog\BlogTagService).
            ->addColumn('slug', 'string', ['limit' => 120])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true])
            ->create();
    }

    /**
     * A post belongs to zero or more categories. The link table carries the
     * relationship and nothing else — no per-link ordering, no "is primary"
     * flag: the primary category of a post is derived from the categories'
     * own sort order, so there is one place that decides it and no second
     * column that can disagree with it.
     */
    private function createBlogPostCategories(): void
    {
        if ($this->hasTable('blog_post_categories')) {
            return;
        }

        // A composite primary key, so the pair itself is the identity and a
        // post can never be linked to the same category twice. Both columns
        // must be explicitly NOT NULL for Phinx to accept them as the key.
        $this->table('blog_post_categories', [
            'id' => false,
            'primary_key' => ['post_id', 'category_id'],
        ])
            ->addColumn('post_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('category_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['category_id'])
            ->addForeignKey('post_id', 'blog_posts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('category_id', 'blog_categories', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    private function createBlogPostTags(): void
    {
        if ($this->hasTable('blog_post_tags')) {
            return;
        }

        $this->table('blog_post_tags', [
            'id' => false,
            'primary_key' => ['post_id', 'tag_id'],
        ])
            ->addColumn('post_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('tag_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['tag_id'])
            ->addForeignKey('post_id', 'blog_posts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('tag_id', 'blog_tags', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }

    /**
     * The Blog's own key/value settings, and the fifth settings table in this
     * project for the same reason there are already four: nothing that resets
     * one may reach the others.
     *
     *   site_settings    who the site IS
     *   theme_settings   what the PUBLIC site looks like
     *   module_settings  which modules this deployment runs
     *   admin_settings   what the CMS looks like to its editors
     *   blog_settings    how the Blog presents itself
     *
     * A module owns its own tables (MODULES.md), so putting "posts per page"
     * in `site_settings` would be Core carrying a key only a module
     * understands. Seeded EMPTY: a missing row means the code default
     * (App\Service\Blog\BlogSettings), so switching the module on is already
     * coherent before anybody opens the settings screen.
     */
    private function createBlogSettings(): void
    {
        if ($this->hasTable('blog_settings')) {
            return;
        }

        $this->table('blog_settings', ['id' => true])
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();
    }
}
