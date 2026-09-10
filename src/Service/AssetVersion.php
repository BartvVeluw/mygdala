<?php

namespace App\Service;

/**
 * Appends a `?v=<mtime>` cache-busting query string to a static asset path
 * (CSS/JS), derived from the file's own last-modified time on disk — never a
 * manually-bumped version number, so every edit invalidates old browser
 * caches automatically without a build step.
 *
 * Exists because assets/css/core.css and admin/assets/admin.css (and their
 * *.js counterparts) are served with no Cache-Control/Expires header, so
 * browsers fall back to heuristic caching and can silently keep serving an
 * old cached copy after a deploy/edit — confirmed as the actual root cause
 * of an "my CSS change isn't showing up" report during Hero testing
 * (2026-09-06): the server was always serving the correct file, but
 * `document.styleSheets` in the real browser showed the new rules were
 * simply never loaded.
 */
class AssetVersion
{
    /**
     * @param string $relativePath e.g. 'assets/css/core.css' or '/admin/assets/admin.css'
     */
    public static function url(string $relativePath): string
    {
        $absolute = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
        $version = is_file($absolute) ? (string) filemtime($absolute) : (string) time();

        return $relativePath . '?v=' . $version;
    }
}
