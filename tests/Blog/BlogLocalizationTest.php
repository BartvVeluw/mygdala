<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Service\Blog\BlogLocalization;
use App\Service\Language\SiteText;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Blog's words per website language (Multilingual 2.0 phase 5 wave B,
 * docs/multilingual/ARCHITECTURE.md, BLOG.md), without a database: the three
 * stores are App\Service\Language\EntityTranslations, so this file asks what
 * App\Service\Blog\BlogLocalization adds on top of it — which field belongs
 * to which store, the fallback a reader gets, the one sanitizer on the body,
 * and the fact that no store of the Blog's words knows what a slug is.
 *
 * The real SQL of these three tables is Tests\Blog\BlogTaxonomyTest's and
 * Tests\Blog\BlogPostLifecycleTest's, and the backfill
 * Tests\Install\BlogWordsMigrationTest's.
 */
final class BlogLocalizationTest extends TestCase
{
    private const POST = 71;
    private const CATEGORY = 72;
    private const TAG = 73;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        BlogLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        BlogLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /* ------------------------------------------------------------------ */
    /* Which field lives where                                             */
    /* ------------------------------------------------------------------ */

    public function testEachStoreDeclaresItsOwnTableAndFields(): void
    {
        $posts = BlogLocalization::posts()->table();
        $categories = BlogLocalization::categories()->table();
        $tags = BlogLocalization::tags()->table();

        self::assertSame('blog_post_translations', $posts->name);
        self::assertSame('blog_post_id', $posts->ownerColumn);
        self::assertSame(['slug', 'title', 'excerpt', 'body', 'meta_title', 'meta_description'], $posts->fieldNames());

        self::assertSame('blog_category_translations', $categories->name);
        self::assertSame('blog_category_id', $categories->ownerColumn);
        self::assertSame(['slug', 'name', 'description'], $categories->fieldNames());

        self::assertSame('blog_tag_translations', $tags->name);
        self::assertSame('blog_tag_id', $tags->ownerColumn);
        self::assertSame(['slug', 'name'], $tags->fieldNames());
    }

    /**
     * THE SLUG IS NOT A WORD. One slug per post, category and tag, on the row,
     * the same in every language — so /blog/<slug>, /blog/categorie/<slug> and
     * /blog/tag/<slug> keep answering exactly what they answered before, and a
     * translation can never move an address. Localized URLs are phase 6.
     */
    /**
     * REPLACES "no store of the Blog's words knows what a slug is", which was
     * true until Multilingual 2.0 phase 6 gave every language its own URL
     * (docs/multilingual/ROUTING.md). A slug is stored per language now — and
     * it is still not a WORD, which is the part that still has to be true:
     *
     *   - it is read with slug(), which has NO fallback, so a language
     *     without an address has no public route rather than another
     *     language's URL;
     *   - every reader that DOES fall back refuses it outright, so the
     *     mistake cannot be made by accident in a codebase where almost every
     *     other field does fall back.
     */
    public function testTheBlogsAddressIsStoredPerLanguageAndNeverFallsBack(): void
    {
        foreach ([BlogLocalization::posts(), BlogLocalization::categories(), BlogLocalization::tags()] as $store) {
            $name = $store->table()->name;

            self::assertTrue($store->table()->hasSlug(), $name . ' is routable per language');

            foreach (['value', 'name', 'bilingual'] as $reader) {
                try {
                    $reader === 'value'
                        ? $store->value(1, 'slug', 'nl')
                        : $store->{$reader}(1, 'slug');
                    self::fail($name . ': ' . $reader . '() must refuse the address');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('slug()', $e->getMessage());
                }
            }
        }
    }

    /** A length is the one the editor already validated, so no word can stop fitting. */
    public function testTheLengthsAreTheOnesTheEditorValidates(): void
    {
        $posts = BlogLocalization::posts()->table();

        self::assertSame(200, $posts->maxLength('title'));
        self::assertSame(500, $posts->maxLength('excerpt'));
        self::assertSame(255, $posts->maxLength('meta_title'));
        self::assertSame(500, $posts->maxLength('meta_description'));
        self::assertSame(50000, $posts->maxLength('body'));

        self::assertSame(150, BlogLocalization::categories()->table()->maxLength('name'));
        self::assertSame(500, BlogLocalization::categories()->table()->maxLength('description'));
        self::assertSame(100, BlogLocalization::tags()->table()->maxLength('name'));
    }

    /* ------------------------------------------------------------------ */
    /* The fallback                                                        */
    /* ------------------------------------------------------------------ */

    public function testAReaderFallsBackToTheDefaultLanguageAndThenToNothing(): void
    {
        $this->postWords([
            'nl' => ['title' => 'Over hout', 'excerpt' => 'Een korte inleiding.'],
            'en' => ['title' => 'About wood'],
        ]);

        self::assertSame('Over hout', BlogLocalization::post(self::POST, 'title', 'nl'));
        self::assertSame('About wood', BlogLocalization::post(self::POST, 'title', 'en'));
        self::assertSame('Een korte inleiding.', BlogLocalization::post(self::POST, 'excerpt', 'en'), 'no English excerpt falls back');
        self::assertSame('', BlogLocalization::post(self::POST, 'meta_title', 'en'), 'nothing in either language is nothing');
    }

    /** raw() is for an editor: what is stored in THIS language, with no fallback. */
    public function testAnEditorSeesOnlyWhatIsStoredInTheLanguageOnScreen(): void
    {
        $this->postWords(['nl' => ['title' => 'Over hout']]);

        self::assertSame('Over hout', BlogLocalization::rawPost(self::POST, 'title', 'nl'));
        self::assertSame('', BlogLocalization::rawPost(self::POST, 'title', 'en'));
    }

    public function testACategoryAndATagHaveTheirOwnWords(): void
    {
        BlogLocalization::categories()->overrideForTests(self::CATEGORY, [
            'nl' => ['name' => 'Materialen', 'description' => 'Waar we mee werken.'],
            'en' => ['name' => 'Materials'],
        ]);
        BlogLocalization::tags()->overrideForTests(self::TAG, ['nl' => ['name' => 'lasersnijden']]);

        self::assertSame('Materials', BlogLocalization::categoryName(self::CATEGORY, 'en'));
        self::assertSame(
            'Waar we mee werken.',
            BlogLocalization::categoryDescription(self::CATEGORY, 'en'),
            'an untranslated description falls back'
        );
        self::assertSame('lasersnijden', BlogLocalization::tagName(self::TAG, 'en'));
        self::assertSame(' data-nl="Materialen" data-en="Materials"', SiteText::attrsOf(BlogLocalization::categoryNameValue(self::CATEGORY)));
    }

    /* ------------------------------------------------------------------ */
    /* Rich text                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The body keeps the sanitizer the Blog always had, and it runs PER
     * LANGUAGE before the fallback: a language whose markup sanitizes away to
     * nothing has no body, so the fallback takes over rather than a visitor
     * getting an empty article.
     */
    public function testTheBodyIsSanitizedInEveryLanguageBeforeTheFallbackRuns(): void
    {
        $this->postWords([
            'nl' => ['body' => '<p>Van eiken<script>alert(1)</script></p>'],
            'en' => ['body' => '<script>alert(2)</script>'],
        ]);

        self::assertSame('<p>Van eiken</p>', BlogLocalization::body(self::POST, 'nl'));

        $pair = BlogLocalization::bodyValue(self::POST);
        self::assertSame('<p>Van eiken</p>', SiteText::visibleOf($pair));
        self::assertStringNotContainsString('script', $pair->in('en'));
        self::assertSame('<p>Van eiken</p>', $pair->in('en'), 'markup that sanitizes away to nothing is no translation');
    }

    /** A body with no translation carries no data-lang-html at all. */
    public function testABodyWithoutATranslationIsNotMarkedAsHtml(): void
    {
        $this->postWords(['nl' => ['body' => '<p>Eén taal</p>']]);

        self::assertSame('', SiteText::htmlAttrsOf(BlogLocalization::bodyValue(self::POST)));
    }

    /* ------------------------------------------------------------------ */
    /* A third language                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * German is a row in site_languages and nothing else: no column, no
     * declaration, no line of PHP.
     */
    public function testAThirdLanguageNeedsNoSchemaAndNoCode(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true, sortOrder: 0),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        BlogLocalization::clearCache();

        $this->postWords([
            'nl' => ['title' => 'Over hout', 'excerpt' => 'Kort.'],
            'de' => ['title' => 'Über Holz'],
        ]);

        self::assertSame('Über Holz', BlogLocalization::post(self::POST, 'title', 'de'));
        self::assertSame('Kort.', BlogLocalization::post(self::POST, 'excerpt', 'de'), 'German falls back to the default language');
    }

    /**
     * THE DEFAULT LANGUAGE DECIDES whether a post has words at all, the same
     * contract the blocks got in phase 3A and the Portfolio in wave A: it is
     * the end of the chain and falls back to nothing. On every existing
     * installation the default is Dutch, and the output is unchanged.
     */
    public function testOnlyTheDefaultLanguageDecidesWhetherAPostHasWords(): void
    {
        SiteLanguageFixture::useBilingual('en');
        BlogLocalization::clearCache();
        $this->postWords(['nl' => ['title' => 'Alleen Nederlands']]);

        self::assertSame('en', BlogLocalization::defaultLanguage());
        self::assertSame('', BlogLocalization::post(self::POST, 'title', 'en'));
        self::assertSame('Alleen Nederlands', BlogLocalization::post(self::POST, 'title', 'nl'));
    }

    /** The CMS names a post by its title, and a nameless one by the first language that has words. */
    public function testTheCmsNameTakesOneStepMoreThanAReaderEverDoes(): void
    {
        $this->postWords(['en' => ['title' => 'Only English']]);

        self::assertSame('', BlogLocalization::post(self::POST, 'title', 'nl'), 'a reader never gets this step');
        self::assertSame('Only English', BlogLocalization::postName(self::POST));
    }

    /** @param array<string, array<string, string>> $words */
    private function postWords(array $words): void
    {
        BlogLocalization::posts()->overrideForTests(self::POST, $words);
    }
}
