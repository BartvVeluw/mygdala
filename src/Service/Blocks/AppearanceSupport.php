<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * Which parts of "Extra vormgeving" (App\Service\Blocks\BlockAppearance) one
 * block type can carry safely: the ONE capability contract, declared by the
 * block itself in BlockDefinition::appearanceSupport(). The editor shows only
 * what is declared here, the endpoint refuses anything else, and the page
 * renders nothing else, whatever a row says.
 *
 * Four capabilities:
 *
 *   background   the block's own surface may be replaced by a theme surface
 *   borders      the lines above and below the block may be chosen
 *   spacing      the vertical room of the block's <section> may be chosen
 *   decorations  which effects (a subset of BlockAppearance::DECORATIONS
 *                without 'none') may be drawn behind the block's content
 *
 * A block that declares nothing (none(), the default) gets no panel and
 * renders exactly as it always did. A new block — a future Reviews block —
 * opts in with one line: `return AppearanceSupport::section();`.
 */
final class AppearanceSupport
{
    public const BACKGROUND = 'background';
    public const BORDERS = 'borders';
    public const SPACING = 'spacing';
    public const DECORATIONS = 'decorations';

    /** @param list<string> $decorations */
    private function __construct(
        public readonly bool $background,
        public readonly bool $borders,
        public readonly bool $spacing,
        public readonly array $decorations,
    ) {
    }

    /** A block without "Extra vormgeving": the panel is not shown. */
    public static function none(): self
    {
        return new self(false, false, false, []);
    }

    /**
     * An ordinary block whose root is a `<section>` with its content in a
     * `.container`: background, borders and spacing, plus the effects named
     * (all of them by default). Pass fewer effects where one would hurt the
     * block, e.g. falling sparks over an interactive grid.
     *
     * @param list<string>|null $decorations null = every effect
     */
    public static function section(?array $decorations = null): self
    {
        return new self(true, true, true, self::decorationList($decorations ?? BlockAppearance::effects()));
    }

    /**
     * Only what is named: for a block whose layout owns some of it (a page
     * header has its own height, so no spacing).
     *
     * @param list<string> $decorations
     */
    public static function only(bool $background, bool $borders, bool $spacing, array $decorations = []): self
    {
        return new self($background, $borders, $spacing, self::decorationList($decorations));
    }

    public function isEmpty(): bool
    {
        return !$this->background && !$this->borders && !$this->spacing && $this->decorations === [];
    }

    public function allowsDecoration(string $decoration): bool
    {
        return in_array($decoration, $this->decorations, true);
    }

    /**
     * The capabilities by name, for the editor and the documentation.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return array_values(array_filter([
            $this->background ? self::BACKGROUND : null,
            $this->borders ? self::BORDERS : null,
            $this->spacing ? self::SPACING : null,
            $this->decorations !== [] ? self::DECORATIONS : null,
        ]));
    }

    /**
     * Only real effects, in the order of BlockAppearance::DECORATIONS, never
     * 'none' (always allowed) and never a word outside the closed list.
     *
     * @param list<string> $decorations
     *
     * @return list<string>
     */
    private static function decorationList(array $decorations): array
    {
        return array_values(array_filter(
            BlockAppearance::effects(),
            static fn (string $effect): bool => in_array($effect, $decorations, true)
        ));
    }
}
