<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Which files are THE APPLICATION and which files are THIS SITE.
 *
 * This repository is both. Roughly 40 MB of it is Van Veluw Laserdesign's own
 * photography, and live pages, products and portfolio items point at those
 * files by path — so making the tree generic by deleting them would break a
 * working site to tidy up for a site that does not exist yet. The boundary is
 * therefore not a deletion but an export: the tree stays whole, and
 * `scripts/create_fresh_site_copy.php` walks it once, asking this class about
 * every path.
 *
 * IT LIVES HERE, AND NOT IN THE SCRIPT, so it can be tested. A boundary
 * nobody checks is a boundary that moves, and the only thing worse than no
 * export is one that quietly starts carrying somebody's product photos again.
 * The script is deliberately thin: it copies, it creates directories, it
 * prints — every decision is below.
 *
 * FOUR GROUPS COME OUT:
 *
 *   1. version control    `.git` — the new site gets its own history, plus
 *                         the per-machine parts of `.claude`. The agent
 *                         skills and shared settings under it are how this
 *                         CMS is worked on, so those do travel.
 *   2. secrets and state  everything `.gitignore` names: `.env`, `vendor/`,
 *                         uploaded media, logs, caches. {@see EXCLUDED_PATHS}
 *                         restates that list rather than parsing the file,
 *                         and Tests\Install\FreshSiteCopyTest checks the two
 *                         against each other so neither can drift.
 *   3. site content       `assets/images/**` — product photography, portfolio
 *                         work, this company's logos, the raw source files.
 *   4. site history       MAIN.MD and the two archived documents under
 *                         `docs/`. PROJECT-MAP.md already calls all three
 *                         history rather than a description of the code.
 *
 * WHAT IT DOES NOT DECIDE: what a file SAYS. Prose that still names this site
 * — README.md most obviously — is reported for a human to rewrite, never
 * edited. Rewriting prose automatically is guessing, and a half-renamed site
 * is worse than a list.
 */
final class FreshSiteCopyPolicy
{
    /**
     * Paths that never leave this repository, as prefixes: an entry matches
     * the path itself and everything beneath it.
     *
     * Group 2 and group 3 together. The ignored paths are spelled out rather
     * than read from `.gitignore` because a `.gitignore` is a pattern
     * language and this is a list of places — but the two must agree, and
     * a test holds them to it.
     */
    public const EXCLUDED_PATHS = [
        // Group 1: version control, and the local tooling that sits beside
        // it. `.claude` itself is NOT excluded: it carries the project's
        // agent skills and shared settings, which describe how this CMS is
        // developed and therefore travel with the application. Only the two
        // per-machine things under it stay behind — the throwaway worktrees,
        // and whatever personal overrides this developer put in
        // `settings.local.json`.
        '.git',
        '.claude/worktrees',
        '.claude/settings.local.json',

        // Group 2: secrets, dependencies and per-installation runtime state.
        '.env',
        'vendor',
        'node_modules',
        'logs',
        'storage',
        'docker/mysql-data',
        'docker/volumes',
        '.phpunit.result.cache',

        // Group 3: this site's own pictures, in every form. Broad on
        // purpose — `assets/images` holds the site's photography AND the
        // directories the CMS uploads into, and neither belongs to a copy.
        'assets/images',
        'assets/media',
        'assets/videos',
        'assets/fonts/personalization',

        // Group 4: this site's project history.
        'MAIN.MD',
        'docs/CMS_CONTENT_AUDIT.md',
        'docs/content-blocks/ROADMAP.md',
    ];

    /**
     * Excluded above as content, but the running application expects the
     * path to exist — an uploader that cannot create its directory fails at
     * the moment somebody uploads, which is the worst moment to find out. A
     * copy therefore gets each of these back, empty, with a `.gitkeep` so
     * the new site's first commit carries the shape and nobody's files.
     */
    public const WRITABLE_DIRECTORIES = [
        'assets/images',
        'assets/images/branding',
        'assets/images/hero',
        'assets/images/products',
        'assets/images/sections',
        'assets/images/sections/thumbs',
        'assets/images/personalization',
        'assets/media',
        'assets/media/thumbs',
        'assets/videos',
        'assets/videos/sections',
    ];

    /**
     * Files inside an excluded directory that are still part of the
     * application: `assets/fonts/personalization/.htaccess` carries the
     * MIME-type rules the uploader relies on, and its `.gitkeep` is what
     * keeps the folder in git. `.gitignore` makes the same two exceptions.
     */
    public const KEPT_INSIDE_EXCLUDED = [
        'assets/fonts/personalization/.gitkeep',
        'assets/fonts/personalization/.htaccess',
    ];

    /**
     * What a reviewer reads afterwards: the copied text files that still
     * name this site. Matched case-insensitively.
     */
    public const REVIEW_NEEDLES = ['Van Veluw', 'vanveluwlaserdesign'];

    /**
     * Copied, but never listed for rewriting.
     *
     * A migration is a historical artefact — INSTALL-BOOTSTRAP.md is explicit
     * that they must still run years from now exactly as written — so telling
     * an operator to edit sixty of them because their docblocks explain which
     * site's history they replay would be advice that damages the database.
     * They run on a fresh install and seed nothing (`InstallState`), which is
     * the property that actually matters, and Tests\Install\FreshInstallTest
     * is what guards it.
     */
    public const NOT_WORTH_REVIEWING = ['db/migrations/'];

    /** Whether a copied file is worth putting in front of a human afterwards. */
    public static function isWorthReviewing(string $relativePath): bool
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');

        foreach (self::NOT_WORTH_REVIEWING as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** Whether this repository-relative path belongs in a copy. */
    public static function includes(string $relativePath): bool
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '') {
            return false;
        }

        if (in_array($path, self::KEPT_INSIDE_EXCLUDED, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PATHS as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }

        return !self::isTransient(basename($path));
    }

    /**
     * Whether a directory is worth walking into at all. Skipping an excluded
     * directory here rather than filtering its files one by one is what keeps
     * the export from reading 40 MB of photographs it will not copy — and
     * from descending into `vendor/`, which is most of the file count.
     */
    public static function descendsInto(string $relativePath): bool
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '') {
            return true;
        }

        foreach (self::KEPT_INSIDE_EXCLUDED as $kept) {
            if (str_starts_with($kept, $path . '/')) {
                return true;
            }
        }

        foreach (self::EXCLUDED_PATHS as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Editor leftovers and build noise, matched on the filename. These are
     * in `.gitignore` as patterns rather than paths, so they are recognised
     * the same way here.
     */
    private static function isTransient(string $filename): bool
    {
        foreach (['.log', '.tmp', '.bak', '.orig', '.swp', '.swo', '.sqlite', '.sqlite3'] as $extension) {
            if (str_ends_with($filename, $extension)) {
                return true;
            }
        }

        // `.env.local` and `.env.<anything>.local`, the two forms
        // `.gitignore` names — and pointedly NOT `.env.example`, which is
        // the one file every new installation starts from.
        return in_array($filename, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true)
            || (str_starts_with($filename, '.env.') && str_ends_with($filename, '.local'));
    }
}
