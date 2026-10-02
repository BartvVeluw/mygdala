<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The shape of a local frontend asset path, judged on the string alone: a
 * project-relative .css or .js path under assets/, built from letters,
 * digits, `_`, `-` and `/` only. So no leading slash, no `..`, no
 * backslash, no protocol, no query, no fragment, no whitespace.
 *
 * Pure on purpose: no filesystem, no settings, no other class. That lets
 * both sides use it without knowing each other — App\Service\PageAssets
 * (which adds "and present on disk" in isLoadable() before it prints a
 * path) and a declaration such as App\Service\Theme\ThemeDefinition (which
 * only checks that trusted code wrote a well-formed path). It says nothing
 * about whether a path may be printed on this request; PageAssets decides
 * that.
 */
final class AssetPath
{
    public static function isValid(string $path): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }

        return preg_match('#^assets/[A-Za-z0-9_/-]+\.(css|js)\z#', $path) === 1;
    }
}
