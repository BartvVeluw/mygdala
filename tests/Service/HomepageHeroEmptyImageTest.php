<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\HomepageHeroRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\HomepageHeroContent;
use App\Service\Language\LocalizedValue;
use PHPUnit\Framework\TestCase;

/**
 * What an EMPTY hero image means, which turns out to be two different things.
 *
 * A missing row — a database that has never been migrated, or one that cannot
 * be reached — is missing data, and there is nothing to render. A row that
 * exists and stores an empty image is an answer: this Hero has no image. Those
 * two used to be the same branch, and both fell back on hardcoded copy, so
 * every brand-new installation of this CMS rendered the hero photograph of the
 * site it grew out of until somebody replaced it.
 *
 * Four cases, and all four matter:
 *
 *   1. no stored row              no Hero at all — no fallback copy, no image.
 *   2. stored, image empty        no image, and no markup for one.
 *   3. stored, image configured   renders exactly as it always did.
 *   4. video, no poster           no empty poster attribute either.
 *
 * Cases 2, 3 and 4 are asked of the RENDERER with a hand-built array, so they
 * need neither a database nor a webserver and they assert on the markup a
 * visitor actually receives rather than on an intermediate value. Cases 1 and
 * 2 are also asked of App\Service\HomepageHeroContent against the real table,
 * inside a transaction that is rolled back afterwards — the Hero is a
 * page-bound singleton, so there is no throwaway row to use instead.
 */
final class HomepageHeroEmptyImageTest extends TestCase
{
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        HomepageHeroContent::clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        HomepageHeroContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* hasMedia(): the one question a renderer asks                        */
    /* ------------------------------------------------------------------ */

    public function testAnEmptyImageMeansThereIsNoMedia(): void
    {
        $this->assertFalse(HomepageHeroContent::hasMedia($this->hero(['image_path' => ''])));
    }

    public function testAConfiguredImageMeansThereIsMedia(): void
    {
        $this->assertTrue(HomepageHeroContent::hasMedia($this->hero(['image_path' => 'assets/images/x.webp'])));
    }

    public function testAVideoHeroWithoutAFileHasNoMediaEvenWithAPoster(): void
    {
        $hero = $this->hero([
            'media_type' => HomepageHeroContent::MEDIA_TYPE_VIDEO,
            'video_path' => '',
            'image_path' => 'assets/images/poster.webp',
        ]);

        $this->assertFalse(HomepageHeroContent::hasMedia($hero));
    }

    public function testAVideoHeroWithAFileHasMediaWithoutAPoster(): void
    {
        $hero = $this->hero([
            'media_type' => HomepageHeroContent::MEDIA_TYPE_VIDEO,
            'video_path' => 'assets/videos/sections/x.mp4',
            'image_path' => '',
        ]);

        $this->assertTrue(HomepageHeroContent::hasMedia($hero));
    }

    /* ------------------------------------------------------------------ */
    /* Case 2 + 4: what an empty hero actually renders                     */
    /* ------------------------------------------------------------------ */

    public function testAnEmptyImageRendersNoImageElementAtAll(): void
    {
        $html = $this->render($this->hero(['image_path' => '', 'image_alt' => self::words('', '')]));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringNotContainsString('hero__media-frame', $html);
    }

    public function testAnEmptyImageNeverReachesTheLegacyDefaultPhotograph(): void
    {
        $html = $this->render($this->hero(['image_path' => '', 'image_alt' => self::words('', '')]));

        $this->assertStringNotContainsString('hero-collage', $html);
        $this->assertStringNotContainsStringIgnoringCase('MOPA', $html);
    }

    public function testAHeroWithoutMediaSaysSoOnTheSectionSoTheGridCanCloseUp(): void
    {
        $html = $this->render($this->hero(['image_path' => '']));

        $this->assertStringContainsString('hero--no-media', $html);
    }

    public function testAHeroWithoutMediaStillRendersItsWordsAndItsButton(): void
    {
        $html = $this->render($this->hero([
            'image_path' => '',
            'title' => self::words('Nieuwe website', ''),
            'primary_label' => self::words('Meer informatie', ''),
        ]));

        $this->assertStringContainsString('Nieuwe website', $html);
        $this->assertStringContainsString('Meer informatie', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function testAVideoHeroWithoutAPosterRendersNoEmptyPosterAttribute(): void
    {
        $html = $this->render($this->hero([
            'media_type' => HomepageHeroContent::MEDIA_TYPE_VIDEO,
            'video_path' => 'assets/videos/sections/x.mp4',
            'image_path' => '',
        ]));

        $this->assertStringContainsString('<video', $html);
        $this->assertStringNotContainsString('poster=""', $html);
    }

    public function testABadgeStillRendersOnAHeroWithoutMedia(): void
    {
        // The badge lives in the media column, so removing that column must
        // not take a configured badge with it.
        $html = $this->render($this->hero([
            'image_path' => '',
            'badge_title' => self::words('Nu open', 'Now open'),
            'badge_text' => self::words('Kom langs.', 'Drop by.'),
        ]));

        $this->assertStringContainsString('hero__badge', $html);
        $this->assertStringContainsString('Nu open', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Case 3: the configured hero is untouched                            */
    /* ------------------------------------------------------------------ */

    public function testAConfiguredImageRendersExactlyAsBefore(): void
    {
        $html = $this->render($this->hero([
            'image_path' => 'assets/images/hero-collage-a.webp',
            'image_alt' => self::words('Een laser graveert een naam', 'A laser engraves a name'),
        ]));

        $this->assertStringContainsString('hero__media-frame', $html);
        $this->assertStringContainsString('src="assets/images/hero-collage-a.webp"', $html);
        $this->assertStringContainsString('alt="Een laser graveert een naam"', $html);
        $this->assertStringContainsString('data-en-alt="A laser engraves a name"', $html);
        $this->assertStringNotContainsString('hero--no-media', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Against the real table: cases 1 and 2 through the content class      */
    /* ------------------------------------------------------------------ */

    public function testAStoredEmptyImageStaysEmpty(): void
    {
        $this->beginTransaction();

        Database::connection()
            ->prepare('UPDATE homepage_hero SET image_path = :path WHERE page_slug = :slug')
            ->execute(['path' => '', 'slug' => HomepageHeroContent::PAGE_SLUG]);

        // The alt text is words, per language (block_translations): none left
        // in any language.
        $row = (new HomepageHeroRepository())->findBySlug(HomepageHeroContent::PAGE_SLUG);
        $this->assertNotNull($row);
        Database::connection()
            ->prepare("DELETE FROM block_translations WHERE owner_table = 'homepage_hero' AND owner_id = :id AND field = 'image_alt'")
            ->execute(['id' => (int) $row['id']]);

        HomepageHeroContent::clearCache();
        $hero = HomepageHeroContent::current();

        $this->assertSame(HomepageHeroContent::STATE_ACTIVE, $hero['state']);
        $this->assertSame('', $hero['image_path'], 'An explicitly empty image must not fall back to the default.');
        $this->assertSame('', $hero['image_alt']->in('nl'));
        $this->assertSame('', $hero['image_alt']->in('en'));
    }

    public function testNoStoredRowRendersNoHeroAtAll(): void
    {
        $this->beginTransaction();

        Database::connection()
            ->prepare('DELETE FROM homepage_hero WHERE page_slug = :slug')
            ->execute(['slug' => HomepageHeroContent::PAGE_SLUG]);

        HomepageHeroContent::clearCache();
        $hero = HomepageHeroContent::current();

        $this->assertSame(HomepageHeroContent::STATE_FALLBACK, $hero['state']);
        $this->assertSame('', $hero['image_path'], 'A missing row must not invent an image.');
        $this->assertSame('', $hero['title']->primaryValue(), 'A missing row must not invent a headline.');

        $definition = BlockDefinitions::get('homepage_hero');
        $this->assertNotNull($definition);

        ob_start();
        try {
            $definition->render(['id' => 0, 'section_type' => 'homepage_hero', 'page_slug' => HomepageHeroContent::PAGE_SLUG, 'section_key' => null, 'section_id' => 0], false, 'homepage_hero-0');
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertSame('', trim($html), 'A missing Hero row must leave no hero section behind.');
    }

    public function testTheStoredHeroOfThisSiteStillHasItsImage(): void
    {
        // Not a fixture: the real row, read as it is. The fix must not have
        // emptied a configured Hero.
        $row = (new HomepageHeroRepository())->findBySlug(HomepageHeroContent::PAGE_SLUG);

        if ($row === null) {
            $this->markTestSkipped('This database has no Homepage Hero row.');
        }

        $stored = trim((string) ($row['image_path'] ?? ''));
        $hero = HomepageHeroContent::current();

        $this->assertSame($stored, $hero['image_path'], 'The rendered image must be the one that is stored.');
    }

    /* ------------------------------------------------------------------ */

    private function beginTransaction(): void
    {
        Database::connection()->beginTransaction();
        $this->inTransaction = true;
    }

    /**
     * A hero array shaped exactly like HomepageHeroContent::current()
     * returns, with the given overrides applied.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function hero(array $overrides = []): array
    {
        $base = [
            'eyebrow' => self::words('Welkom', 'Welcome'),
            'title' => self::words('Nieuwe website', 'New website'),
            'title_highlight' => self::words('', ''),
            'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT,
            'lead' => self::words('', ''),
            'primary_label' => self::words('Meer informatie', 'Learn more'), 'primary_url' => '/',
            'secondary_label' => self::words('', ''), 'secondary_url' => '',
            'image_path' => '', 'image_alt' => self::words('', ''),
            'badge_title' => self::words('', ''), 'badge_text' => self::words('', ''),
            'media_type' => HomepageHeroContent::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => HomepageHeroContent::LAYOUT_MEDIA_RIGHT,
            'stats' => [],
            'state' => HomepageHeroContent::STATE_ACTIVE,
        ];

        return array_merge($base, $overrides);
    }

    /** One field's words as HomepageHeroContent hands them to the partial: one value in every language. */
    private static function words(string $dutch, string $english): LocalizedValue
    {
        return LocalizedValue::of(['nl' => $dutch, 'en' => $english]);
    }

    /** @param array<string, mixed> $hero */
    private function render(array $hero): string
    {
        require_once dirname(__DIR__, 2) . '/partials/section-homepage-hero.php';

        ob_start();
        render_section_homepage_hero($hero);

        return (string) ob_get_clean();
    }
}
