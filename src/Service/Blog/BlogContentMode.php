<?php

declare(strict_types=1);

namespace App\Service\Blog;

/**
 * Which body a blog post shows: its classic rich-text body, or its content
 * blocks (BLOG.md, "Klassieke tekst en contentblokken").
 *
 * EXPLICIT, never inferred. `blog_posts.content_mode` decides; whether a post
 * happens to have blocks, or a body, decides nothing. So adding one empty
 * block to a classic post can never make its text disappear, and a post in
 * blocks mode never falls back to an old body because its blocks are empty.
 *
 *   legacy  every post from before Blog 2.0 (the migration's column default):
 *           the body per language, rendered exactly as before.
 *   blocks  every new post, and a classic post an editor converted: the
 *           post's content page through the ordinary block engine.
 *
 * Switching never deletes the other half (BlogContentConversion). A value
 * that is not one of these READS as legacy: the body is what every existing
 * row has.
 */
final class BlogContentMode
{
    public const LEGACY = 'legacy';
    public const BLOCKS = 'blocks';

    /** @var list<string> */
    public const ALL = [self::LEGACY, self::BLOCKS];

    public static function of(array $post): string
    {
        return ($post['content_mode'] ?? null) === self::BLOCKS ? self::BLOCKS : self::LEGACY;
    }

    public static function usesBlocks(array $post): bool
    {
        return self::of($post) === self::BLOCKS;
    }
}
