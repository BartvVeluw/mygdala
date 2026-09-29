<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * A heading in rich content — the Tekstblok, and the blog, portfolio and
 * collection texts that share `.rich-content` — is the site's own H2 and H3
 * (v0.1.12). It used to be a fixed 1.4rem at weight 400, visibly smaller
 * than every other H2 on a page. Now it reads the size tokens of the
 * typographic system in assets/css/core.css and inherits weight, line-height
 * and font from the h1–h4 base rule, so the theme's heading font and the
 * responsive clamp() reach it too. Only the look changed: the partial still
 * prints what the editor chose (h2/h3), which the heading-level rules of
 * v0.1.11 leave alone.
 */
final class RichContentTypographyTest extends TestCase
{
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css');

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    public function testRichContentHeadingsUseTheSiteHeadingTokens(): void
    {
        $css = self::css();

        $this->assertMatchesRegularExpression('/\.rich-content h2\{ font-size: var\(--fs-h2\);/', $css);
        $this->assertMatchesRegularExpression('/\.rich-content h3\{ font-size: var\(--fs-h3\);/', $css);
        $this->assertMatchesRegularExpression('/(?<![\w-])h2\{ font-size: var\(--fs-h2\); \}/', $css, 'the same token as every other H2');
        $this->assertMatchesRegularExpression('/--fs-h2: clamp\(/', $css, 'and it still shrinks with the viewport');
    }

    public function testNoRichContentHeadingRuleOverridesTheBaseWeightOrAFixedSize(): void
    {
        preg_match_all('/\.rich-content h[23][^{]*\{([^}]*)\}/', self::css(), $bodies);
        $this->assertNotSame([], $bodies[1]);

        foreach ($bodies[1] as $body) {
            $this->assertStringNotContainsString('font-weight', $body, 'the weight is the base heading rule\'s');
            $this->assertStringNotContainsString('line-height', $body);
            $this->assertStringNotContainsString('font-family', $body, 'the theme\'s heading font reaches it');
            $this->assertDoesNotMatchRegularExpression('/font-size:\s*[\d.]+(rem|px|em)/', $body, 'no pixel or rem correction');
        }
    }

    public function testTheBaseRuleStillGivesEveryHeadingTheDisplayFont(): void
    {
        $this->assertMatchesRegularExpression(
            '/h1, h2, h3, h4\{\s*font-family: var\(--font-display\);\s*font-weight: 500;\s*line-height: 1\.15;/',
            self::css()
        );
    }

    public function testTheTextBlockStillPrintsTheEditorsHeadingsUnchanged(): void
    {
        $partial = (string) file_get_contents(dirname(__DIR__, 2) . '/partials/section-rich-text.php');

        $this->assertStringContainsString('<div class="rich-content"><?= $visible ?></div>', $partial);
        $this->assertStringNotContainsString('<h2', $partial, 'the block adds no heading level of its own');
    }
}
