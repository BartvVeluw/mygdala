<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Media\MediaItem;
use App\Service\Media\MediaSequence;
use App\Service\Media\MediaType;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/media-sequence.php';

/**
 * The shared media sequence (App\Service\Media\MediaSequence,
 * partials/media-sequence.php, assets/js/media-sequence.js,
 * assets/css/media-sequence.css) on its own, without a database:
 *
 *   - the closed lists: a stored value it does not know is the default;
 *   - a posted list is `media:<id>` tokens, in order, each id once, at most
 *     MAX_ITEMS — anything else is refused whole, never half read;
 *   - the markup: the first slide in view, a decorative track hidden as a
 *     whole, content slides named "2 van 3", the first picture eager for a
 *     header, a video that cannot start by itself with its controls, the
 *     controls hidden until the script runs, a pause button for a sequence
 *     that plays by itself;
 *   - the script: server-chosen items only, a pause that also pauses a video,
 *     reduced motion starts paused, no video loops inside a sequence;
 *   - the stylesheet: without the script only the first item shows, and
 *     reduced motion switches every transition off.
 */
final class MediaSequenceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        RequestLanguage::reset();
    }

    // -------------------------------------------------------- closed lists

    public function testAStoredValueItDoesNotKnowIsTheDefault(): void
    {
        self::assertSame('fade', MediaSequence::transition('dissolve'));
        self::assertSame('slide', MediaSequence::transition('slide'));
        self::assertSame(5, MediaSequence::duration(0));
        self::assertSame(5, MediaSequence::duration(11));
        self::assertSame(5, MediaSequence::duration('3.5'));
        self::assertSame(7, MediaSequence::duration('7'));
        self::assertSame(1, MediaSequence::duration(1));
        self::assertSame(10, MediaSequence::duration(10));
        self::assertSame('both', MediaSequence::controls('everything'));
        self::assertSame('none', MediaSequence::controls('none'));

        self::assertSame(['fade', 'slide', 'none'], MediaSequence::TRANSITIONS);
        self::assertSame(range(1, 10), MediaSequence::DURATIONS);
        self::assertSame(['both', 'arrows', 'dots', 'none'], MediaSequence::CONTROLS);
        self::assertTrue(MediaSequence::hasArrows('both') && MediaSequence::hasArrows('arrows') && !MediaSequence::hasArrows('dots'));
        self::assertTrue(MediaSequence::hasDots('both') && MediaSequence::hasDots('dots') && !MediaSequence::hasDots('none'));
    }

    // ------------------------------------------------------- posted tokens

    public function testAPostedListIsReadInOrderEachIdOnce(): void
    {
        self::assertSame([], MediaSequence::idsFromTokens(null));
        self::assertSame([], MediaSequence::idsFromTokens(''));
        self::assertSame([], MediaSequence::idsFromTokens([]));
        self::assertSame([12, 3, 40], MediaSequence::idsFromTokens(['media:12', 'media:3', 'media:12', 'media:40']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedLists(): iterable
    {
        yield 'not a list' => ['media:12'];
        yield 'a row token of another list' => [['image:12']];
        yield 'an id with a sign' => [['media:-1']];
        yield 'a zero' => [['media:0']];
        yield 'a leading zero' => [['media:012']];
        yield 'an array inside' => [[['media:12']]];
        yield 'too many' => [array_map(static fn (int $id): string => 'media:' . $id, range(1, MediaSequence::MAX_ITEMS + 1))];
        yield 'trailing garbage' => [['media:12;DROP']];
    }

    #[DataProvider('refusedLists')]
    public function testAnyOtherListIsRefusedWhole(mixed $posted): void
    {
        self::assertNull(MediaSequence::idsFromTokens($posted));
    }

    public function testASlideIsOneShapeForAPictureAndAVideo(): void
    {
        $picture = MediaItem::fromRow(['id' => 5, 'path' => 'assets/media/a.jpg', 'mime_type' => 'image/jpeg', 'width' => 1600, 'height' => 900, 'alt_text' => 'Bibliotheek']);
        $video = MediaItem::fromRow(['id' => 6, 'path' => 'assets/media/b.mp4', 'mime_type' => 'video/mp4', 'alt_text' => '']);

        self::assertSame(['kind' => MediaType::IMAGE, 'src' => '/assets/media/a.jpg', 'mime' => '', 'alt' => 'Eigen', 'width' => 1600, 'height' => 900], MediaSequence::slide($picture, ' Eigen '));
        self::assertSame(['kind' => MediaType::VIDEO, 'src' => '/assets/media/b.mp4', 'mime' => 'video/mp4', 'alt' => '', 'width' => null, 'height' => null], MediaSequence::slide($video, 'genegeerd'));
    }

    // -------------------------------------------------------------- markup

    public function testTheRootCarriesItsOptionsAsDataAttributes(): void
    {
        self::assertSame(
            ' data-media-sequence data-media-sequence-transition="slide" data-media-sequence-duration="3" data-media-sequence-autoplay data-media-sequence-loop data-media-sequence-hover-pause data-media-sequence-swipe',
            media_sequence_attributes(['transition' => 'slide', 'duration' => 3, 'autoplay' => true, 'loop' => true, 'hover_pause' => true, 'swipe' => true])
        );
        self::assertSame(
            ' data-media-sequence data-media-sequence-transition="fade" data-media-sequence-duration="5"',
            media_sequence_attributes(['transition' => 'wipe', 'duration' => 99, 'autoplay' => false, 'loop' => false])
        );
    }

    public function testTheFirstSlideIsInViewAndContentSlidesAreNamed(): void
    {
        $html = $this->slides([$this->picture('/a.jpg', 'Eerste'), $this->picture('/b.jpg', 'Tweede'), $this->picture('/c.jpg', 'Derde')], ['transition' => 'fade', 'presentation' => new \App\Service\Media\ResponsiveImage(50, 0)]);

        self::assertStringContainsString('<div class="media-sequence media-sequence--fade" data-media-sequence-track>', $html);
        self::assertSame(1, substr_count($html, 'is-active'), 'one slide in view');
        self::assertMatchesRegularExpression('#<div class="media-sequence__slide is-active" data-media-sequence-slide data-kind="image" role="group" aria-roledescription="slide" aria-label="1 van 3">\s*<img src="/a.jpg" alt="Eerste"#', $html);
        self::assertStringContainsString('aria-label="3 van 3"', $html);
        self::assertSame(3, substr_count($html, 'style="object-position: 50% 0%;"'), 'the focus point of the block on every picture');
        self::assertStringNotContainsString('<source', $html, 'a slide never takes a phone picture');
        self::assertSame(3, substr_count($html, 'loading="lazy"'), 'nothing eager unless asked');

        RequestLanguage::set('en', true);
        self::assertStringContainsString('aria-label="2 of 3"', $this->slides([$this->picture('/a.jpg'), $this->picture('/b.jpg'), $this->picture('/c.jpg')], ['transition' => 'fade']));
    }

    public function testADecorativeTrackIsHiddenWholeAndAHeaderLoadsItsFirstPictureAtOnce(): void
    {
        $html = $this->slides([$this->picture('/a.jpg', 'Wordt niet gelezen'), $this->picture('/b.jpg', 'Ook niet')], ['transition' => 'none', 'decorative' => true, 'eager' => true]);

        self::assertStringContainsString('data-media-sequence-track aria-hidden="true">', $html);
        self::assertStringNotContainsString('role="group"', $html);
        self::assertStringNotContainsString('Wordt niet gelezen', $html, 'decoration has alt=""');
        self::assertSame(1, substr_count($html, 'loading="eager" decoding="async" fetchpriority="high"'), 'only the first picture');
    }

    public function testAVideoInASequenceNeverLoopsAndIsNeverWithoutAWayToStart(): void
    {
        $video = ['kind' => MediaType::VIDEO, 'src' => '/v.mp4', 'mime' => 'video/mp4', 'alt' => '', 'width' => null, 'height' => null];

        $still = $this->slides([$video, $this->picture('/a.jpg')], ['transition' => 'fade', 'video_autoplay' => false, 'video_controls' => false, 'poster' => '/p.jpg', 'media_class' => 'media-banner__media']);
        self::assertStringContainsString('<video class="media-banner__media" src="/v.mp4" poster="/p.jpg" playsinline preload="metadata" controls></video>', $still, 'not playing by itself: controls, whatever was stored');

        $playing = $this->slides([$this->picture('/a.jpg'), $video], ['transition' => 'fade', 'video_autoplay' => true, 'video_controls' => false, 'poster' => '/p.jpg']);
        self::assertStringContainsString('<video src="/v.mp4" playsinline preload="metadata" muted aria-hidden="true"></video>', $playing, 'muted, no autoplay attribute on a later item, no poster but the first\'s');
        self::assertStringNotContainsString(' loop', $playing);

        $first = $this->slides([$video, $this->picture('/a.jpg')], ['transition' => 'fade', 'video_autoplay' => true, 'video_controls' => true]);
        self::assertStringContainsString('controls muted autoplay>', $first, 'the first video starts even without the script');
    }

    public function testTheControlsWaitForTheScriptAndAPlayingSequenceCanBePaused(): void
    {
        $both = $this->controls(3, ['controls' => 'both', 'pause' => true]);
        self::assertStringContainsString('<div class="media-sequence__controls" data-media-sequence-controls hidden>', $both);
        self::assertStringContainsString('data-media-sequence-prev aria-label="Vorige"', $both);
        self::assertStringContainsString('data-media-sequence-next aria-label="Volgende"', $both);
        self::assertSame(3, substr_count($both, 'data-media-sequence-dot='));
        self::assertStringContainsString('data-media-sequence-dot="0" aria-label="1 van 3" aria-current="true"', $both);
        self::assertStringContainsString('data-media-sequence-pause data-state="playing"', $both);
        self::assertStringContainsString('data-label-play="Afspelen"', $both);

        $pauseOnly = $this->controls(2, ['controls' => 'none', 'pause' => true, 'class' => 'page-hero__sequence-controls']);
        self::assertStringContainsString('media-sequence__controls page-hero__sequence-controls', $pauseOnly);
        self::assertStringNotContainsString('data-media-sequence-prev', $pauseOnly);
        self::assertStringNotContainsString('data-media-sequence-dot', $pauseOnly);

        self::assertStringNotContainsString('data-media-sequence-pause', $this->controls(2, ['controls' => 'dots', 'pause' => false]), 'nothing to pause');
        self::assertSame('', trim($this->controls(2, ['controls' => 'none', 'pause' => false])), 'no buttons at all');
        self::assertSame('', trim($this->controls(1, ['controls' => 'both', 'pause' => true])), 'one item is no sequence');
    }

    // -------------------------------------------------- script, stylesheet

    public function testTheScriptOnlyEverShowsWhatTheServerChose(): void
    {
        $js = (string) file_get_contents(self::ROOT . '/assets/js/media-sequence.js');

        self::assertStringContainsString('querySelectorAll("[data-media-sequence]")', $js, 'one controller per root');
        self::assertStringContainsString('root.querySelectorAll("[data-media-sequence-slide]")', $js, 'only the slides the server printed');
        self::assertStringNotContainsString('fetch(', $js);
        self::assertStringNotContainsString('Math.random', $js, 'the order is the editor\'s');
        self::assertStringContainsString('prefers-reduced-motion: reduce', $js);
        self::assertStringContainsString('var playing = autoplay && !reducedMotion;', $js, 'less motion starts paused');
        self::assertStringContainsString('video.loop = false;', $js, 'no video loops inside a sequence');
        self::assertStringContainsString('if (video) video.pause();', $js, 'pausing the sequence pauses its video');
        self::assertStringContainsString('slide.inert = !active;', $js, 'what is out of view is out of reach');
        self::assertStringContainsString('"visibilitychange"', $js);
        self::assertStringNotContainsString('innerHTML', $js);
    }

    public function testTheStylesheetShowsTheFirstItemWithoutTheScriptAndMovesNothingForLessMotion(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/assets/css/media-sequence.css');

        self::assertStringContainsString('[data-media-sequence]:not(.is-sequencing) .media-sequence__slide:not(:first-child){ display: none; }', $css);
        self::assertStringContainsString('.media-sequence__controls[hidden]{ display: none; }', $css);
        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\{\s*\.is-sequencing \.media-sequence__slide,\s*\.is-sequencing \.media-sequence__slide\.is-active\{ transition: none; \}/', $css);
        self::assertMatchesRegularExpression('/\.media-sequence__dot\{[^}]*width: 1\.5rem;[^}]*height: 1\.5rem;/s', $css, 'a dot is a 24px target');
    }

    // ------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function picture(string $src, string $alt = ''): array
    {
        return ['kind' => MediaType::IMAGE, 'src' => $src, 'mime' => '', 'alt' => $alt, 'width' => null, 'height' => null];
    }

    /**
     * @param list<array<string, mixed>> $slides
     * @param array<string, mixed>       $options
     */
    private function slides(array $slides, array $options): string
    {
        ob_start();
        render_media_sequence_slides($slides, $options);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $options */
    private function controls(int $count, array $options): string
    {
        ob_start();
        render_media_sequence_controls($count, $options);

        return (string) ob_get_clean();
    }
}
