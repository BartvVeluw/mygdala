<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ProductGalleryTransition;
use App\Service\SiteSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Product Gallery 2.0's resolver (App\Service\ProductGalleryTransition): the
 * one place that turns "the product's own choice, else the Shop's default,
 * else the fallback" into one of three closed words. No database: the Shop's
 * default comes from SiteSettings::overrideForTests(), and forProduct() is
 * only asked about no product at all, which never queries.
 */
final class ProductGalleryTransitionTest extends TestCase
{
    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
    }

    public function testTheListIsClosedAndSmall(): void
    {
        $this->assertSame(['none', 'fade', 'slide'], ProductGalleryTransition::ALL);
        $this->assertSame('fade', ProductGalleryTransition::DEFAULT);
        $this->assertContains(ProductGalleryTransition::DEFAULT, ProductGalleryTransition::ALL);
    }

    /** @return iterable<string, array{mixed, mixed, string}> */
    public static function resolutions(): iterable
    {
        yield 'inherit, Shop none' => [null, 'none', 'none'];
        yield 'inherit, Shop fade' => [null, 'fade', 'fade'];
        yield 'inherit, Shop slide' => [null, 'slide', 'slide'];
        yield 'product none over Shop fade' => ['none', 'fade', 'none'];
        yield 'product fade over Shop slide' => ['fade', 'slide', 'fade'];
        yield 'product slide over Shop none' => ['slide', 'none', 'slide'];
        yield 'invalid stored product value follows the Shop' => ['zoom-spin', 'slide', 'slide'];
        yield 'empty stored product value follows the Shop' => ['', 'none', 'none'];
        yield 'invalid Shop value falls back to the default' => [null, 'flip', 'fade'];
        yield 'both invalid fall back to the default' => ['3d', '<b>', 'fade'];
        yield 'a word in another case is not the word' => ['Slide', 'NONE', 'fade'];
    }

    #[DataProvider('resolutions')]
    public function testTheProductWinsThenTheShopThenTheDefault(mixed $product, mixed $shop, string $expected): void
    {
        $this->assertSame($expected, ProductGalleryTransition::resolve($product, $shop));
    }

    public function testNormaliseOnlyEverAnswersOneOfTheWords(): void
    {
        foreach (ProductGalleryTransition::ALL as $word) {
            $this->assertSame($word, ProductGalleryTransition::normalise($word));
        }

        foreach ([null, '', ' fade', 'fade ', 'FADE', 'slide;color:red', '"><script>', 1, true, ['fade']] as $other) {
            $this->assertNull(ProductGalleryTransition::normalise($other), var_export($other, true));
        }
    }

    public function testTheShopsDefaultIsFadeUntilSomethingElseIsStored(): void
    {
        $this->assertSame('fade', SiteSettings::defaults()[ProductGalleryTransition::SETTING_KEY], 'backward compatible: the gallery already faded');

        SiteSettings::overrideForTests([]);
        $this->assertSame('fade', ProductGalleryTransition::shopDefault());

        SiteSettings::overrideForTests([ProductGalleryTransition::SETTING_KEY => 'slide']);
        $this->assertSame('slide', ProductGalleryTransition::shopDefault());

        SiteSettings::overrideForTests([ProductGalleryTransition::SETTING_KEY => 'none']);
        $this->assertSame('none', ProductGalleryTransition::shopDefault());

        SiteSettings::overrideForTests([ProductGalleryTransition::SETTING_KEY => 'kaleidoscope']);
        $this->assertSame('fade', ProductGalleryTransition::shopDefault(), 'an unknown stored value is the default, never itself');
    }

    public function testNoProductFollowsTheShop(): void
    {
        SiteSettings::overrideForTests([ProductGalleryTransition::SETTING_KEY => 'slide']);

        $this->assertSame('slide', ProductGalleryTransition::forProduct(0));
    }

    /** @return iterable<string, array{array<string, mixed>, array{0: bool, 1: ?string, 2: bool}}> */
    public static function editorInput(): iterable
    {
        yield 'not in the request: leave it alone' => [[], [false, null, true]];
        yield 'Standaard van Shop' => [['gallery_transition' => ''], [true, null, true]];
        yield 'Geen' => [['gallery_transition' => 'none'], [true, 'none', true]];
        yield 'Vervagen' => [['gallery_transition' => 'fade'], [true, 'fade', true]];
        yield 'Schuiven' => [['gallery_transition' => 'slide'], [true, 'slide', true]];
        yield 'an unknown word is refused' => [['gallery_transition' => 'zoom'], [true, null, false]];
        yield 'a list is refused' => [['gallery_transition' => ['fade']], [true, null, false]];
    }

    /**
     * @param array<string, mixed> $input
     * @param array{0: bool, 1: ?string, 2: bool} $expected
     */
    #[DataProvider('editorInput')]
    public function testWhatTheEditorPostsIsReadStrictly(array $input, array $expected): void
    {
        $this->assertSame($expected, ProductGalleryTransition::fromProductInput($input));
    }
}
