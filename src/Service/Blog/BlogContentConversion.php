<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Database;
use App\Repository\BlogPostRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\ContentOwners\ContentPages;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\RichTextContent;
use App\Service\SectionRegistry;

/**
 * An editor's deliberate switch between a post's classic body and its
 * content blocks (BLOG.md, "Klassieke tekst en contentblokken"). Never run
 * by itself, never in bulk.
 *
 *   toBlocks()  For a post WITHOUT blocks yet: its body in every language it
 *               has one becomes ONE Tekst block (`rich_text`), the same HTML
 *               (both are RichTextSanitizer's output, and sanitising clean
 *               HTML changes nothing — BlogContentModeTest proves it per
 *               language), then the post switches to blocks. A post that
 *               already has blocks — it was switched back once — just
 *               switches: its blocks come back as they were, and no second
 *               copy of the text is made.
 *   toLegacy()  Only the switch. The blocks stay on the content page,
 *               unrendered, for a later switch back.
 *
 * NOTHING IS DELETED EITHER WAY. The classic body stays in
 * blog_post_translations after a conversion, so going back is exact. The
 * block, its words and the mode switch are one transaction (the content page
 * itself is made just before, as ContentPages::ensure() does for a first
 * block); a failure leaves the post in its classic mode.
 *
 * What changes on the public page after toBlocks() is the frame, not the
 * text: the text block's own section and reading column instead of the
 * classic article body. That is the visible, deliberate part of converting.
 */
final class BlogContentConversion
{
    public static function toBlocks(int $postId): void
    {
        $posts = new BlogPostRepository();
        $post = $posts->find($postId) ?? throw new \InvalidArgumentException("No blog post #{$postId}.");

        if (BlogContentMode::usesBlocks($post)) {
            return;
        }

        $bodies = [];
        foreach (SiteLanguages::all() as $language) {
            $body = BlogLocalization::rawPost($postId, BlogLocalization::BODY, $language->code);
            if (trim($body) !== '') {
                $bodies[$language->code] = $body;
            }
        }

        $existing = ContentPages::pageFor(BlogPostContentOwner::KIND, $postId);
        $hasBlocks = $existing !== null && (new PageSectionRepository())->findForPage((int) $existing['id']) !== [];
        $page = ($bodies !== [] && !$hasBlocks) ? ContentPages::ensure(BlogPostContentOwner::KIND, $postId) : null;

        $db = Database::connection();
        $db->beginTransaction();

        try {
            if ($page !== null) {
                [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', (string) $page['content_key']);
                (new PageSectionRepository($db))->create((int) $page['id'], (string) $page['content_key'], 'rich_text', $sectionKey, $sectionId);

                foreach ($bodies as $code => $body) {
                    BlockLocalization::save('rich_text_sections', $sectionId, $code, [RichTextContent::BODY => $body]);
                }
            }

            (new BlogPostRepository($db))->update($postId, ['content_mode' => BlogContentMode::BLOCKS]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        RichTextContent::clearCache();
        PageContent::clearCache();
    }

    public static function toLegacy(int $postId): void
    {
        $posts = new BlogPostRepository();

        if ($posts->find($postId) === null) {
            throw new \InvalidArgumentException("No blog post #{$postId}.");
        }

        $posts->update($postId, ['content_mode' => BlogContentMode::LEGACY]);
    }
}
