<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\HomepageHeroRepository;
use App\Service\HomepageHeroContent;
use PHPUnit\Framework\TestCase;

/**
 * What an EMPTY hero image means, which turns out to be two different things.
 *
 * A missing row — a database that has never been migrated, or one that cannot
 * be reached — is missing data, and the DEFAULTS are the right answer to
 * that. A row that exists and stores an empty image is an answer: this Hero
 * has no image. Those two used to be the same branch, and the generic
 * bootstrap row deliberately stores an empty image, so every brand-new
 * installation of this CMS rendered Van Veluw Laserdesign's hero photograph
 * and its alt text on its homepage until somebody replaced it.
 *
 * Four cases, and all four matter:
 *
 *   1. no stored row              DEFAULTS, unchanged — the legacy and
 *                                 database-unavailable safety net.
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
        $html = $this->render($this->hero(['image_path' => '', 'image_alt_nl' => '', 'image_alt_en' => '']));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringNotContainsString('hero__media-frame', $html);
    }

    public function testAnEmptyImageNeverReachesTheLegacyDefaultPhotograph(): void
    {
        $html = $this->render($this->hero(['image_path' => '', 'image_alt_nl' => '', 'image_alt_en' => '']));

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
            'title_nl' => 'Nieuwe website',
            'primary_label_nl' => 'Meer informatie',
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
            'badge_title_nl' => 'Nu open',
            'badge_title_en' => 'Now open',
            'badge_text_nl' => 'Kom langs.',
            'badge_text_en' => 'Drop by.',
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
            'image_alt_nl' => 'Een laser graveert een naam',
            'image_alt_en' => 'A laser engraves a name',
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

        // Three separate placeholders, not one reused three times: PDO in
        // emulation-off mode binds a named parameter exactly once.
        Database::connection()
            ->prepare(
                'UPDATE homepage_hero SET image_path = :path, image_alt_nl = :alt_nl, image_alt_en = :alt_en '
                . 'WHERE page_slug = :slug'
            )
            ->execute([
                'path' => '',
                'alt_nl' => '',
                'alt_en' => '',
                'slug' => HomepageHeroContent::PAGE_SLUG,
            ]);

        HomepageHeroContent::clearCache();
        $hero = HomepageHeroContent::current();

        $this->assertSame(HomepageHeroContent::STATE_ACTIVE, $hero['state']);
        $this->assertSame('', $hero['image_path'], 'An explicitly empty image must not fall back to the default.');
        $this->assertSame('', $hero['image_alt_nl']);
        $this->assertSame('', $hero['image_alt_en']);
    }

    public function testNoStoredRowStillFallsBackToTheDefaults(): void
    {
        $this->beginTransaction();

        Database::connection()
            ->prepare('DELETE FROM homepage_hero WHERE page_slug = :slug')
            ->execute(['slug' => HomepageHeroContent::PAGE_SLUG]);

        HomepageHeroContent::clearCache();
        $hero = HomepageHeroContent::current();

        // Unchanged behaviour, and deliberately so: a database that has never
        // been migrated, or one that is down, must still render a page.
        $this->assertSame(HomepageHeroContent::STATE_FALLBACK, $hero['state']);
        $this->assertSame(HomepageHeroContent::defaults()['image_path'], $hero['image_path']);
        $this->assertSame(HomepageHeroContent::defaults()['image_alt_nl'], $hero['image_alt_nl']);
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
        $base = HomepageHeroContent::defaults();
        $base['image_path'] = '';
        $base['image_alt_nl'] = '';
        $base['image_alt_en'] = '';
        $base['secondary_label_nl'] = '';
        $base['secondary_label_en'] = '';
        $base['secondary_url'] = '';
        $base['badge_title_nl'] = '';
        $base['badge_title_en'] = '';
        $base['badge_text_nl'] = '';
        $base['badge_text_en'] = '';
        $base['stats'] = [];
        $base['state'] = HomepageHeroContent::STATE_ACTIVE;

        return array_merge($base, $overrides);
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
