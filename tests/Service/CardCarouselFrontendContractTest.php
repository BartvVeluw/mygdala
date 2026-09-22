<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The Kaarten-carrousel's frontend rules, read from its own files
 * (assets/js/blocks/card-carousel.js, assets/css/blocks/card-carousel.css) —
 * there is no JavaScript runner in this project, so the three regressions
 * that were found in the browser are pinned on the source:
 *
 *  - A CLICKED CARD ENDS CENTERED. Next and previous go to a card's own
 *    resting angle (ringGoTo on commandedIndex() ± 1) and stop the drift
 *    there. Stepping one angle from wherever the drifting ring happened to be
 *    left the card off-center — with two cards, all the way to the side.
 *    Autoplay itself keeps drifting continuously.
 *  - THE GLOW FOLLOWS THE POINTER. The highlight is a :hover rule (under
 *    `hover: hover`, so touch never needs one) and keyboard focus, never the
 *    card that is in front.
 *  - THE STAGE CONTAINS THE FRONT CARD. Its height is worked out from the
 *    perspective and the front scale, so the card cannot cover the arrows.
 *
 * They were measured in a browser (1, 2, 3 and 5 cards, next and previous,
 * during autoplay, on desktop, tablet and phone); this test keeps the code
 * from sliding back.
 */
final class CardCarouselFrontendContractTest extends TestCase
{
    private static function read(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    }

    public function testNextAndPreviousGoToACardsOwnRestingAngle(): void
    {
        $js = self::read('assets/js/blocks/card-carousel.js');

        self::assertStringContainsString('ringGoTo(mod(commandedIndex() + 1)', $js);
        self::assertStringContainsString('ringGoTo(mod(commandedIndex() - 1)', $js);
        self::assertStringNotContainsString('targetAngle -= angleStep', $js, 'a step from a drifted angle is what left the card off-center');
        self::assertStringNotContainsString('targetAngle += angleStep', $js);
        // Autoplay keeps drifting; a manual move stops the drift where it landed.
        self::assertStringContainsString('angle -= speedDegPerSec * speedFactor', $js);
        self::assertMatchesRegularExpression('/function ringGoTo\(index, opts\) \{.*?speedFactor = 0;/s', $js);
    }

    public function testTheGlowBelongsToTheHoveredCardNotToTheFrontCard(): void
    {
        $css = self::read('assets/css/blocks/card-carousel.css');

        self::assertDoesNotMatchRegularExpression('/\[data-orbit-active="true"\][^{]*\{[^}]*box-shadow/', $css);
        self::assertMatchesRegularExpression('/@media \(hover: hover\)\{\s*\.orbit-card:hover \.orbit-card__inner\{[^}]*box-shadow/', $css);
        self::assertStringContainsString('.orbit-card:focus-within .orbit-card__inner{', $css);
    }

    public function testTheStageIsAsTallAsTheFrontCardReallyLooks(): void
    {
        $css = self::read('assets/css/blocks/card-carousel.css');

        self::assertStringContainsString(
            '--orbit-stage-h: calc(var(--orbit-card-h) * var(--orbit-front-scale) * var(--orbit-perspective) / (var(--orbit-perspective) - var(--orbit-rz)));',
            $css
        );
        self::assertStringContainsString('perspective: calc(var(--orbit-perspective) * 1px);', $css);
        self::assertDoesNotMatchRegularExpression('/--orbit-stage-h:\s*\d+px/', $css, 'no fixed stage height for one viewport');
    }

    public function testTheRowLayoutHasNoRingAndShowsControlsOnlyWhenTheCardsOverflow(): void
    {
        $css = self::read('assets/css/blocks/card-carousel.css');
        $js = self::read('assets/js/blocks/card-carousel.js');

        self::assertStringContainsString('.orbit-carousel--row:not([data-orbit-overflow="true"]) .orbit-carousel__controls{ display: none; }', $css);
        self::assertStringContainsString('var isFlat = isRowLayout || mq.matches;', $js);
        self::assertStringContainsString('data-orbit-overflow', $js);
    }
}
