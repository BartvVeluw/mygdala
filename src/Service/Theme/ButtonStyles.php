<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Repository\ButtonStyleRepository;
use App\Service\Language\AdminTranslator;

/**
 * The website's button style library (Button Styles 2.0): named button
 * designs, two of which are the defaults, and the choice a content button
 * makes between them. See THEMING.md, "Knopstijlen".
 *
 *   A STYLE is a set of closed words (appearance, shape, size, border,
 *   shadow, type, icon, hover) and colours. A colour is a theme colour word
 *   (ButtonStyleCss::COLORS, it follows the active palette and a page theme)
 *   or a fixed #RRGGBB (it stays what it is).
 *   THE DEFAULTS. `primary` is the website's standard button: every .btn
 *   without a choice of its own — a content button left on "Standaard", and
 *   every functional button (cart, checkout, form, cookies). `secondary` is
 *   the second button next to it (.btn--ghost). Exactly one style per role,
 *   stored in button_style_defaults with a RESTRICT foreign key.
 *   A CHOICE is a content button's `…button_style_id` (NULL = the role's
 *   default), stored through ButtonStyleRepository::saveChoice().
 *
 * ONE READER FOR THE PAGE. Nothing but this class reads button_styles to
 * draw a page: ButtonStyleCss gets the defaults and the chosen styles from
 * here, and a partial asks classes() which classes its button gets. The old
 * Knopvorm (theme_settings.button_shape) is the defaults' shape now:
 * App\Service\Theme\ThemeSettings reads and writes it through
 * defaultShape() / saveDefaultShape(), exactly like its colours go to the
 * active palette.
 *
 * Core, like the rest of the site theme; a module never gets a style of its
 * own, it only names where its blocks store a choice
 * (ModuleDefinition::buttonStyleSlots()).
 */
final class ButtonStyles
{
    public const MAX_NAME_LENGTH = 80;

    public const APPEARANCES = ['filled', 'outline', 'ghost', 'text'];
    public const ICON_POSITIONS = ['before', 'after'];

    /** What a delete can answer besides "deleted". */
    public const DELETE_DELETED = 'deleted';
    public const DELETE_DEFAULT = 'default';
    public const DELETE_IN_USE = 'in_use';
    public const DELETE_MISSING = 'missing';

    /** The colour fields, and whether "unchanged" (empty) is allowed. */
    private const COLOR_FIELDS = [
        'fill_color' => false,
        'text_color' => false,
        'border_color' => false,
        'hover_fill_color' => true,
        'hover_text_color' => true,
        'hover_border_color' => true,
    ];

    private const FLAGS = ['fill_gradient', 'uppercase', 'underline', 'icon_motion'];

    /** @var array<int, array<string, mixed>>|null id => shaped style, per request */
    private static ?array $styles = null;

    /** @var array<string, int>|null role => id, per request */
    private static ?array $defaults = null;

    /** @var array<int, int>|null id => content buttons, per request */
    private static ?array $usage = null;

    /**
     * Every style, by name, with `roles` (the defaults it is) and `uses`
     * (how many content buttons chose it).
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $roles = self::defaultIds();
        $usage = self::usageCounts();
        $out = [];

        foreach (self::styles() as $id => $style) {
            $out[] = $style + [
                'roles' => array_keys($roles, $id, true),
                'uses' => $usage[$id] ?? 0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return $out;
    }

    /** @return array<string, mixed>|null one style, validated */
    public static function find(int $id): ?array
    {
        return $id > 0 ? (self::styles()[$id] ?? null) : null;
    }

    /** @return array<string, mixed>|null the style a role uses by default */
    public static function defaultFor(string $role): ?array
    {
        $id = self::defaultIds()[$role] ?? null;

        return $id === null ? null : self::find($id);
    }

    /** @return array<string, int> role => style id */
    public static function defaultIds(): array
    {
        return self::$defaults ??= (new ButtonStyleRepository())->defaults();
    }

    /**
     * Who uses a style: the default roles it has and how many content
     * buttons chose it.
     *
     * @return array{roles: list<string>, buttons: int}
     */
    public static function usage(int $id): array
    {
        return [
            'roles' => array_keys(self::defaultIds(), $id, true),
            'buttons' => self::usageCounts()[$id] ?? 0,
        ];
    }

    /** @param array{roles: list<string>, buttons: int} $usage */
    public static function inUse(array $usage): bool
    {
        return $usage['roles'] !== [] || $usage['buttons'] > 0;
    }

    /**
     * What a NEW style starts with: the website's standard button, never a
     * design written into this file — the editor changes what should be
     * different.
     *
     * @return array<string, mixed>
     */
    public static function startingValues(): array
    {
        $style = self::defaultFor('primary') ?? self::shape([]);
        unset($style['id']);
        $style['name'] = '';

        return $style;
    }

    /**
     * Validates a submitted style. Every field is required and must be on
     * its closed list; a colour is a theme colour word or a #RRGGBB (the
     * form's "custom" choice sends it in `<field>_custom`). The name is
     * required, at most MAX_NAME_LENGTH characters and unique.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, string|int|null>, errors: array<string, string>}
     */
    public static function validate(array $input, ?int $id = null): array
    {
        $errors = [];
        $values = [];

        $name = is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '';
        if ($name === '') {
            $errors['name'] = AdminTranslator::trans('buttons.error_name_required');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = AdminTranslator::trans('buttons.error_name_length', ['max' => self::MAX_NAME_LENGTH]);
        } elseif ((new ButtonStyleRepository())->nameTaken($name, $id)) {
            $errors['name'] = AdminTranslator::trans('buttons.error_name_taken');
        }
        $values['name'] = $name;

        foreach (self::choices() as $field => $allowed) {
            $value = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';
            if (!in_array($value, $allowed, true)) {
                $errors[$field] = AdminTranslator::trans('buttons.error_choice');
                continue;
            }
            $values[$field] = $value;
        }

        foreach (self::COLOR_FIELDS as $field => $optional) {
            $raw = is_scalar($input[$field] ?? null) ? trim((string) $input[$field]) : '';
            if ($raw === 'custom') {
                $raw = is_scalar($input[$field . '_custom'] ?? null) ? trim((string) $input[$field . '_custom']) : '';
            }

            $color = self::normaliseColor($raw, $optional);
            if ($color === false) {
                $errors[$field] = AdminTranslator::trans('buttons.error_color');
                continue;
            }
            $values[$field] = $color;
        }

        foreach (self::FLAGS as $flag) {
            $values[$flag] = !empty($input[$flag]) ? 1 : 0;
        }

        // An outline without a border is not an outline.
        if (($values['appearance'] ?? '') === 'outline' && ($values['border_width'] ?? '') === 'none') {
            $values['border_width'] = 'normal';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * A new style. Never a default: creating one never changes the website.
     *
     * @param array<string, string|int|null> $values the output of validate()
     */
    public static function create(array $values): int
    {
        $id = (new ButtonStyleRepository())->create($values);
        self::clearCache();

        return $id;
    }

    /**
     * Saves a style. Every content button that chose it — and, for a
     * default, every button that did not choose — shows the change at once.
     *
     * @param array<string, string|int|null> $values the output of validate()
     */
    public static function update(int $id, array $values): void
    {
        (new ButtonStyleRepository())->update($id, $values);
        self::clearCache();
    }

    /**
     * A copy with the same design, named "<name> (kopie)" — or "(kopie 2)",
     * … while that is taken — and no default, no user. Null when the style
     * is gone.
     */
    public static function duplicate(int $id): ?int
    {
        $style = self::find($id);
        if ($style === null) {
            return null;
        }

        $values = array_intersect_key($style, array_flip(ButtonStyleRepository::COLUMNS));
        $values['name'] = self::copyName((string) $style['name']);

        return self::create($values);
    }

    /**
     * Makes a style a role's default. False when the style is gone.
     */
    public static function setDefault(string $role, int $id): bool
    {
        if (!in_array($role, ButtonStyleRepository::ROLES, true) || self::find($id) === null) {
            return false;
        }

        (new ButtonStyleRepository())->setDefault($role, $id);
        self::clearCache();

        return true;
    }

    /**
     * Deletes a style nobody uses: never a default and never one a content
     * button chose. The foreign keys refuse both as well, for a choice made
     * between the check and the delete.
     *
     * @return string one of the DELETE_* constants
     */
    public static function delete(int $id): string
    {
        if (self::find($id) === null) {
            return self::DELETE_MISSING;
        }

        $usage = self::usage($id);
        if ($usage['roles'] !== []) {
            return self::DELETE_DEFAULT;
        }
        if ($usage['buttons'] > 0) {
            return self::DELETE_IN_USE;
        }

        $deleted = (new ButtonStyleRepository())->delete($id);
        self::clearCache();

        return $deleted ? self::DELETE_DELETED : self::DELETE_IN_USE;
    }

    /**
     * The sentence for a refused delete: who uses the style, and what to do.
     * Text only; the screen escapes it.
     */
    public static function refusal(string $result, int $id): string
    {
        return match ($result) {
            self::DELETE_DEFAULT => AdminTranslator::trans('buttons.error_delete_default'),
            self::DELETE_IN_USE => AdminTranslator::trans('buttons.error_delete_in_use', ['count' => self::usage($id)['buttons']]),
            default => AdminTranslator::trans('buttons.error_delete'),
        };
    }

    /**
     * The classes a content button gets, and whether it still draws the
     * arrow it always drew. The one resolver every partial asks; it reads
     * nothing, so a block renders the same with or without a database.
     *
     *   No choice: the button's own classes — `btn`, `btn btn--ghost` — and
     *   so the role's default, and its old inline arrow
     *   (`<svg class="btn__arrow">`). A default with an icon of its own
     *   hides that arrow in CSS (ButtonStyleCss::styleBlock()), so a button
     *   never shows two.
     *   A choice: `btn btn-style-<id>`, and no inline arrow: the style
     *   decides the icon. The foreign key guarantees the style exists.
     *
     * Layout classes (btn--block) are kept either way; a look class of the
     * old markup (btn--ghost, btn--sm) is part of $legacyClasses and gives
     * way to the chosen style.
     *
     * @param list<string> $legacyClasses the classes the button always had
     * @param list<string> $layout classes that are about layout, kept either way
     * @return array{class: string, legacy_icon: bool}
     */
    public static function classes(?int $choice, array $legacyClasses, array $layout = []): array
    {
        if ($choice !== null && $choice > 0) {
            return [
                'class' => implode(' ', ['btn', ButtonStyleCss::className($choice), ...$layout]),
                'legacy_icon' => false,
            ];
        }

        return [
            'class' => implode(' ', [...$legacyClasses, ...$layout]),
            'legacy_icon' => true,
        ];
    }

    /**
     * A content button's choice from a posted form: '' = the default (NULL),
     * else the id of a style that exists. A form without the field keeps
     * what is stored. A forged or stale id is refused with a message at the
     * field; the caller keeps the stored value then.
     *
     * @param array<string, mixed> $post
     * @return array{0: ?int, 1: ?string} the choice and an error
     */
    public static function choiceFromRequest(array $post, string $field, ?int $stored): array
    {
        if (!array_key_exists($field, $post)) {
            return [$stored, null];
        }

        $raw = is_scalar($post[$field]) ? trim((string) $post[$field]) : null;
        if ($raw === '') {
            return [null, null];
        }

        if ($raw !== null && ctype_digit($raw) && self::find((int) $raw) !== null) {
            return [(int) $raw, null];
        }

        return [$stored, AdminTranslator::trans('buttons.error_choice_unknown')];
    }

    /** A stored `…button_style_id` as a choice: a positive int or null. */
    public static function storedChoice(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * The <style id="site-buttons"> block for a page, or '' when the
     * defaults look as shipped and no content button chose a style. A
     * library that cannot be read prints nothing: core.css draws the
     * shipped buttons on its own.
     */
    public static function styleBlock(): string
    {
        try {
            $used = [];
            foreach (array_keys(self::usageCounts()) as $id) {
                $style = self::find($id);
                if ($style !== null) {
                    $used[$id] = $style;
                }
            }

            return ButtonStyleCss::styleBlock(self::defaultFor('primary'), self::defaultFor('secondary'), $used);
        } catch (\Throwable $e) {
            error_log('[ButtonStyles] library unavailable: ' . $e->getMessage());

            return '';
        }
    }

    /** Prints styleBlock(); the shape App\Service\PageAssets calls. */
    public static function renderStyleBlock(): void
    {
        echo self::styleBlock();
    }

    /**
     * The shape of the website's standard button: what ThemeSettings calls
     * `button_shape`. Throws when the library cannot be read (ThemeSettings
     * then reads the old row).
     */
    public static function defaultShape(): ?string
    {
        $style = self::defaultFor('primary');

        return $style === null ? null : (string) $style['shape'];
    }

    /**
     * Gives BOTH default styles this shape — what the old Knopvorm did to
     * every .btn — for ThemeSettings::save() and so the Setup Wizard.
     */
    public static function saveDefaultShape(string $shape): void
    {
        if (!array_key_exists($shape, ButtonStyleCss::SHAPES)) {
            return;
        }

        $repository = new ButtonStyleRepository();
        foreach (array_unique(array_values(self::defaultIds())) as $id) {
            $repository->update($id, ['shape' => $shape]);
        }
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$styles = null;
        self::$defaults = null;
        self::$usage = null;
    }

    /**
     * A row as a style: every field on its closed list, and a stored value
     * that no longer validates (a hand-edited row) replaced by the shipped
     * primary button's — it never reaches a stylesheet.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        $style = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];

        $fallback = [
            'appearance' => 'filled', 'shape' => 'pill', 'size' => 'normal', 'border_width' => 'none',
            'shadow' => 'none', 'font_weight' => 'bold', 'font_role' => 'body', 'icon' => 'none',
            'icon_position' => 'after', 'icon_gap' => 'normal', 'hover_effect' => 'glow',
        ];
        foreach (self::choices() as $field => $allowed) {
            $value = (string) ($row[$field] ?? '');
            $style[$field] = in_array($value, $allowed, true) ? $value : $fallback[$field];
        }

        $colorFallback = ['fill_color' => 'primary', 'text_color' => 'on_primary', 'border_color' => 'primary'];
        foreach (self::COLOR_FIELDS as $field => $optional) {
            $color = self::normaliseColor((string) ($row[$field] ?? ''), $optional);
            $style[$field] = $color === false ? ($colorFallback[$field] ?? null) : $color;
        }

        foreach (self::FLAGS as $flag) {
            $style[$flag] = (int) ($row[$flag] ?? ($flag === 'icon_motion' ? 1 : 0)) === 1;
        }

        return $style;
    }

    /**
     * The closed list per word field.
     *
     * @return array<string, list<string>>
     */
    public static function choices(): array
    {
        return [
            'appearance' => self::APPEARANCES,
            'shape' => array_keys(ButtonStyleCss::SHAPES),
            'size' => array_keys(ButtonStyleCss::SIZES),
            'border_width' => array_keys(ButtonStyleCss::BORDERS),
            'shadow' => array_keys(ButtonStyleCss::SHADOWS),
            'font_weight' => array_keys(ButtonStyleCss::WEIGHTS),
            'font_role' => array_keys(ButtonStyleCss::FONTS),
            'icon' => ButtonIcons::keys(),
            'icon_position' => self::ICON_POSITIONS,
            'icon_gap' => array_keys(ButtonStyleCss::GAPS),
            'hover_effect' => array_keys(ButtonStyleCss::HOVERS),
        ];
    }

    /**
     * A colour word, a #RRGGBB, or (where allowed) '' => null. False for
     * anything else.
     */
    private static function normaliseColor(string $value, bool $optional): string|null|false
    {
        if ($value === '') {
            return $optional ? null : false;
        }

        if (isset(ButtonStyleCss::COLORS[$value])) {
            return $value;
        }

        return ThemeColor::normalise($value) ?? false;
    }

    /** @return array<int, array<string, mixed>> */
    private static function styles(): array
    {
        if (self::$styles !== null) {
            return self::$styles;
        }

        $styles = [];
        foreach ((new ButtonStyleRepository())->findAll() as $row) {
            $styles[(int) $row['id']] = self::shape($row);
        }

        return self::$styles = $styles;
    }

    /** @return array<int, int> */
    private static function usageCounts(): array
    {
        return self::$usage ??= (new ButtonStyleRepository())->usageCounts();
    }

    private static function copyName(string $name): string
    {
        $repository = new ButtonStyleRepository();
        $room = self::MAX_NAME_LENGTH - 12;
        $base = mb_strlen($name) > $room ? rtrim(mb_substr($name, 0, $room)) : $name;

        $candidate = $base . ' (kopie)';
        for ($n = 2; $repository->nameTaken($candidate); $n++) {
            $candidate = $base . ' (kopie ' . $n . ')';
        }

        return $candidate;
    }
}
