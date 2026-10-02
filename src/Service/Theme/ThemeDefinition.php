<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Service\AssetPath;

/**
 * One Global Theme as trusted code declares it: a key, a label for the
 * editor, and the stylesheet that gives the website its presentation —
 * shape, depth, surfaces, ornament, motion. Nothing else: no colours, no
 * fonts, no button styles (those stay the owner's, see THEMING.md), no
 * callbacks and no PHP that renders anything.
 *
 * A definition is DATA written in code. It never comes from the database or
 * a request: the database only stores a key, and App\Service\Theme\ThemeRegistry
 * looks that key up in its closed list. So the two checks here are about
 * shape, not about trust:
 *
 *   key         a technical word: lowercase letter first, then lowercase
 *               letters, digits and hyphens, 2 to 40 characters. No dot,
 *               slash, backslash, whitespace or anything URL-like, so a key
 *               can never pass for a path even by accident.
 *   stylesheet  null (the theme adds no stylesheet: `legacy`), or a local
 *               project-relative .css path in the shape App\Service\AssetPath
 *               allows: under assets/, no traversal, no protocol, no query
 *               or fragment.
 *
 * Whether the file is really there is NOT checked here: that is runtime
 * availability, which PageAssets checks when it prints the link. A value
 * object does no filesystem I/O and knows nothing of PageAssets, the
 * renderer that consumes it later: PageAssets reaches the definitions
 * through ThemeRegistry, so the arrow only ever points that way. The stylesheet is deliberately not tied to
 * assets/css/themes/<key>.css: a first-party theme lives there by
 * convention (a contract test can hold Core themes to it), but the path is
 * always written out, never derived from the key.
 */
final class ThemeDefinition
{
    /** The shape of a theme key. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{1,39}\z/';

    /**
     * @throws \InvalidArgumentException when the key or the stylesheet has
     *         a shape no theme may have; a developer error, never user input
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $stylesheet = null,
    ) {
        if (!self::isValidKey($key)) {
            throw new \InvalidArgumentException('Invalid theme key: ' . json_encode($key));
        }

        if (trim($label) === '') {
            throw new \InvalidArgumentException('Theme "' . $key . '" has no label.');
        }

        if ($stylesheet !== null && !self::isValidStylesheet($stylesheet)) {
            throw new \InvalidArgumentException('Theme "' . $key . '" has an invalid stylesheet path: ' . json_encode($stylesheet));
        }
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /** A well-formed local stylesheet path, judged on its shape alone. */
    public static function isValidStylesheet(string $path): bool
    {
        return str_ends_with($path, '.css') && AssetPath::isValid($path);
    }
}
