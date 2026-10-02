<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\Language\AdminTranslator;
use App\Service\PageAssets;

/**
 * "Extra vormgeving" (Contentblock Styling 1.0, CONTENT-BLOCKS.md): the look
 * of ONE block instance — background, lines, room and a decorative effect —
 * shared by every block type that declares it can carry it
 * (BlockDefinition::appearanceSupport(), App\Service\Blocks\AppearanceSupport).
 *
 * STORAGE. Five columns on the instance's own page_sections row
 * (db/migrations/20261008100000), so two Tekstblokken on one page each have
 * their own, and deleting, hiding or reordering a block takes its look along
 * without a second table. A draft (ContentBlockDrafts) has no page_sections
 * row yet and so no look: the panel appears once the block is on the page.
 *
 * ONE CONTRACT, THREE PLACES. Every value is a word from a closed list below,
 * the default first. The endpoint (api/admin/update-block-appearance.php)
 * refuses an unknown word and a word the block does not support; the page
 * (effective()) reads an unknown or unsupported stored word as the default.
 * No colour, length or CSS string ever comes from the database or a request:
 * a word only ever becomes one of the class names in classes(), and
 * assets/css/block-appearance.css maps those to theme tokens.
 *
 * THE DEFAULT IS THE BLOCK AS IT WAS. 'default' (and 'none') means "what this
 * block already does", including a block's own default surface (the
 * carousel's `.surface-subtle`, the figures' `.surface-contrast`; THEMING.md,
 * "Oppervlakken"). A block with nothing but defaults
 * is rendered without this class being involved at all: byte for byte the
 * markup of before.
 *
 * ON THE BLOCK'S OWN ROOT, NOT AROUND IT. apply() adds the classes to the
 * block's root element (its `<section>`) and draws a decorative layer as that
 * element's first child. No wrapper: sibling selectors, anchors, the reveal
 * groups and the stacking of blocks that isolate themselves (a page header
 * over a picture, a full-width CTA) stay exactly as they are, and an effect
 * sits above the block's own background but under its content.
 */
final class BlockAppearance
{
    /**
     * The closed lists, the default first. The words are stored; what the
     * editor reads for each is `appearance.<field>.<word>` in the CMS's
     * messages (label()).
     */
    public const BACKGROUNDS = ['default', 'page', 'subtle', 'primary', 'secondary', 'transparent'];

    public const BORDERS = ['default', 'none', 'top', 'bottom', 'both'];

    /** The colour of a chosen line. */
    public const BORDER_TONES = ['subtle', 'normal', 'accent'];

    /** The vertical room of the block's section. */
    public const SPACINGS = ['default', 'compact', 'normal', 'spacious', 'extra'];

    public const DECORATIONS = ['none', 'sparks', 'glow', 'pattern'];

    /** The stylesheet for background, lines and room. */
    public const STYLESHEET = 'assets/css/block-appearance.css';

    /** The stylesheet of the effects: only on a page that shows one. */
    public const DECORATION_STYLESHEET = 'assets/css/block-decorations.css';

    /**
     * How many sparks one block draws; the stylesheet places each one
     * (`:nth-child`) and shows the first six on a phone, as the homepage
     * hero does (assets/js/blocks/homepage-hero.js).
     */
    public const SPARK_COUNT = 14;

    /** @var list<string> the five stored fields, as page_sections columns without their prefix */
    public const FIELDS = ['background', 'border', 'border_tone', 'spacing', 'decoration'];

    /**
     * The real effects, without 'none'.
     *
     * @return list<string>
     */
    public static function effects(): array
    {
        return array_values(array_diff(self::DECORATIONS, ['none']));
    }

    /**
     * Every field at its default: the look of a block nobody styled.
     *
     * @return array{background: string, border: string, border_tone: string, spacing: string, decoration: string}
     */
    public static function defaults(): array
    {
        return [
            'background' => 'default',
            'border' => 'default',
            'border_tone' => 'subtle',
            'spacing' => 'default',
            'decoration' => 'none',
        ];
    }

    /**
     * The stored look of one page_sections row, each field read against its
     * closed list: a missing column (a row from before the migration) or an
     * unknown word reads as the default.
     *
     * @param array<string, mixed> $pageSection
     *
     * @return array{background: string, border: string, border_tone: string, spacing: string, decoration: string}
     */
    public static function fromRow(array $pageSection): array
    {
        $values = self::defaults();

        foreach (self::FIELDS as $field) {
            $stored = (string) ($pageSection['appearance_' . $field] ?? '');
            if (in_array($stored, self::options($field), true)) {
                $values[$field] = $stored;
            }
        }

        return $values;
    }

    /**
     * What the page really draws for this row on a block with this support:
     * the stored look with every field the block cannot carry put back to its
     * default. The frontend half of the contract — a row changed behind the
     * CMS's back, or a block whose support later shrank, still renders only
     * what the block can take.
     *
     * @param array<string, mixed> $pageSection
     *
     * @return array{background: string, border: string, border_tone: string, spacing: string, decoration: string}
     */
    public static function effective(array $pageSection, AppearanceSupport $support): array
    {
        $values = self::fromRow($pageSection);
        $defaults = self::defaults();

        if (!$support->background) {
            $values['background'] = $defaults['background'];
        }
        if (!$support->borders) {
            $values['border'] = $defaults['border'];
            $values['border_tone'] = $defaults['border_tone'];
        }
        if (!$support->spacing) {
            $values['spacing'] = $defaults['spacing'];
        }
        if ($values['decoration'] !== 'none' && !$support->allowsDecoration($values['decoration'])) {
            $values['decoration'] = $defaults['decoration'];
        }

        return $values;
    }

    /**
     * Whether this look changes nothing on the page. The border colour alone
     * changes nothing: it only colours a line that is chosen.
     *
     * @param array{background: string, border: string, border_tone: string, spacing: string, decoration: string} $values
     */
    public static function isDefault(array $values): bool
    {
        return $values['background'] === 'default'
            && $values['border'] === 'default'
            && $values['spacing'] === 'default'
            && $values['decoration'] === 'none';
    }

    /**
     * The closed list of one field.
     *
     * @return list<string>
     */
    public static function options(string $field): array
    {
        return match ($field) {
            'background' => self::BACKGROUNDS,
            'border' => self::BORDERS,
            'border_tone' => self::BORDER_TONES,
            'spacing' => self::SPACINGS,
            'decoration' => self::DECORATIONS,
            default => [],
        };
    }

    /**
     * Checks a submitted look against the closed lists AND against what this
     * block supports. A field that is not submitted becomes its default (the
     * panel always sends all five; a request without them resets). Returns
     * the values to store, or the problems in the editor's language — never
     * both, so nothing half-checked is written.
     *
     * @param array<string, mixed> $input
     *
     * @return array{values: array{background: string, border: string, border_tone: string, spacing: string, decoration: string}|null, errors: list<string>}
     */
    public static function validate(array $input, AppearanceSupport $support): array
    {
        $values = self::defaults();
        $errors = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $word = $input[$field];
            if (!is_string($word) || !in_array($word, self::options($field), true)) {
                $errors[] = AdminTranslator::trans('appearance.invalid', ['field' => self::fieldLabel($field)]);
                continue;
            }

            $values[$field] = $word;
        }

        $defaults = self::defaults();
        $refused = static fn (string $field): string => AdminTranslator::trans('appearance.unsupported', ['field' => self::fieldLabel($field)]);

        if (!$support->background && $values['background'] !== $defaults['background']) {
            $errors[] = $refused('background');
        }
        if (!$support->borders && ($values['border'] !== $defaults['border'] || $values['border_tone'] !== $defaults['border_tone'])) {
            $errors[] = $refused('border');
        }
        if (!$support->spacing && $values['spacing'] !== $defaults['spacing']) {
            $errors[] = $refused('spacing');
        }
        if ($values['decoration'] !== 'none' && !$support->allowsDecoration($values['decoration'])) {
            $errors[] = AdminTranslator::trans('appearance.unsupported_effect', ['effect' => self::label('decoration', $values['decoration'])]);
        }

        return $errors === [] ? ['values' => $values, 'errors' => []] : ['values' => null, 'errors' => array_values(array_unique($errors))];
    }

    /** The editor's name for a field, in its messages and its panel. */
    public static function fieldLabel(string $field): string
    {
        return in_array($field, self::FIELDS, true) ? AdminTranslator::trans('appearance.field.' . $field) : $field;
    }

    /** The editor's name for one word of a field's closed list. */
    public static function label(string $field, string $word): string
    {
        return in_array($word, self::options($field), true) ? AdminTranslator::trans('appearance.' . $field . '.' . $word) : $word;
    }

    /**
     * The class names for a look, only for what differs from the default:
     * `block-appearance` plus one modifier per chosen field. Every name is
     * built from a word of a closed list, never from free input.
     *
     * @param array{background: string, border: string, border_tone: string, spacing: string, decoration: string} $values
     *
     * @return list<string>
     */
    public static function classes(array $values): array
    {
        if (self::isDefault($values)) {
            return [];
        }

        $classes = ['block-appearance'];

        if ($values['background'] !== 'default') {
            $classes[] = 'block-appearance--bg-' . $values['background'];
        }
        if ($values['border'] !== 'default') {
            $classes[] = 'block-appearance--border-' . $values['border'];
            if ($values['border'] !== 'none') {
                $classes[] = 'block-appearance--line-' . $values['border_tone'];
            }
        }
        if ($values['spacing'] !== 'default') {
            $classes[] = 'block-appearance--space-' . $values['spacing'];
        }
        if ($values['decoration'] !== 'none') {
            $classes[] = 'block-appearance--decor-' . $values['decoration'];
        }

        return $classes;
    }

    /**
     * The decorative layer of an effect: empty for none, else one element,
     * hidden from assistive technology, that the stylesheet keeps inside the
     * block, behind its content and out of reach of the pointer.
     */
    public static function decorationMarkup(string $decoration): string
    {
        if ($decoration === 'none' || !in_array($decoration, self::DECORATIONS, true)) {
            return '';
        }

        $inner = $decoration === 'sparks' ? str_repeat('<span></span>', self::SPARK_COUNT) : '';

        return '<div class="block-decor block-decor--' . $decoration . '" aria-hidden="true">' . $inner . '</div>';
    }

    /**
     * The look the page draws for one visible block, or null when it draws
     * nothing of its own (every field default, or the block supports none of
     * what the row says). What SectionRegistry::renderPage() asks before it
     * renders a block.
     *
     * @param array<string, mixed> $pageSection
     *
     * @return array{background: string, border: string, border_tone: string, spacing: string, decoration: string}|null
     */
    public static function forSection(array $pageSection, BlockDefinition $definition): ?array
    {
        $support = $definition->appearanceSupport();

        if ($support->isEmpty()) {
            return null;
        }

        $values = self::effective($pageSection, $support);

        return self::isDefault($values) ? null : $values;
    }

    /**
     * Puts a look on a block's rendered markup: the classes on its root
     * element and the decorative layer as that element's first child.
     *
     * The root is the first element the partial prints (a `<section>`, or a
     * `<div>`/`<aside>`/`<article>`); what a block renders is escaped
     * (htmlspecialchars()), so a `>` inside an attribute value cannot end the
     * tag early. Markup without such a root — a block that renders nothing
     * because it is hidden or empty — is returned untouched: an invisible
     * block never becomes an empty styled band.
     *
     * @param array{background: string, border: string, border_tone: string, spacing: string, decoration: string} $values
     */
    public static function apply(string $html, array $values): string
    {
        $classes = self::classes($values);

        if ($classes === [] || !preg_match('/^(\s*)<(section|div|aside|article)\b([^>]*)>/', $html, $match)) {
            return $html;
        }

        [$tag, $leading, $element, $attributes] = [$match[0], $match[1], $match[2], $match[3]];
        $added = implode(' ', $classes);

        if (preg_match('/\sclass="([^"]*)"/', $attributes, $classMatch, PREG_OFFSET_CAPTURE)) {
            $joined = trim($classMatch[1][0] . ' ' . $added);
            $attributes = substr_replace($attributes, ' class="' . $joined . '"', $classMatch[0][1], strlen($classMatch[0][0]));
        } else {
            $attributes = ' class="' . $added . '"' . $attributes;
        }

        $opening = $leading . '<' . $element . $attributes . '>' . self::decorationMarkup($values['decoration']);

        return $opening . substr($html, strlen($tag));
    }

    /**
     * Asks App\Service\PageAssets for the stylesheets of the looks on this
     * page, before the page writes its <head> (SectionRegistry::collectPageAssets()):
     * the appearance stylesheet only when a block on the page has a look of
     * its own, the effects only when one of them shows an effect. A page
     * without either downloads nothing new.
     *
     * @param list<array<string, mixed>> $sections the page's visible page_sections rows
     */
    public static function collectAssets(array $sections): void
    {
        $styled = false;
        $decorated = false;

        foreach ($sections as $pageSection) {
            $type = (string) ($pageSection['section_type'] ?? '');
            if (!BlockDefinitions::has($type)) {
                continue;
            }

            $values = self::forSection($pageSection, BlockDefinitions::get($type));
            if ($values === null) {
                continue;
            }

            $styled = true;
            $decorated = $decorated || $values['decoration'] !== 'none';
        }

        if ($styled) {
            PageAssets::requireStyle(self::STYLESHEET);
        }
        if ($decorated) {
            PageAssets::requireStyle(self::DECORATION_STYLESHEET);
        }
    }

    /**
     * The one line the closed panel shows: "Standaard", or what was chosen.
     *
     * @param array{background: string, border: string, border_tone: string, spacing: string, decoration: string} $values
     */
    public static function summary(array $values): string
    {
        $parts = [];

        if ($values['background'] !== 'default') {
            $parts[] = self::label('background', $values['background']);
        }
        if ($values['border'] !== 'default') {
            $parts[] = $values['border'] === 'none'
                ? AdminTranslator::trans('appearance.summary.no_borders')
                : AdminTranslator::trans('appearance.summary.border', [
                    'where' => mb_strtolower(self::label('border', $values['border'])),
                    'tone' => mb_strtolower(self::label('border_tone', $values['border_tone'])),
                ]);
        }
        if ($values['spacing'] !== 'default') {
            $parts[] = AdminTranslator::trans('appearance.summary.spacing', ['spacing' => mb_strtolower(self::label('spacing', $values['spacing']))]);
        }
        if ($values['decoration'] !== 'none') {
            $parts[] = self::label('decoration', $values['decoration']);
        }

        if ($parts === []) {
            return self::label('background', 'default');
        }

        $line = implode(' · ', $parts);

        return mb_strtoupper(mb_substr($line, 0, 1)) . mb_substr($line, 1);
    }
}
