<?php

declare(strict_types=1);

/**
 * Copies this application out of this site, so a new one can start from it.
 *
 * Usage (from the project root):
 *
 *     php scripts/create_fresh_site_copy.php ../nieuwe-site
 *     php scripts/create_fresh_site_copy.php ../nieuwe-site --force
 *
 * WHY A COPY AND NOT A DELETION. A downstream installation is a working site
 * as well as an application: its tree holds that site's photography, and live
 * pages point at those files by path — deleting them to make the tree generic
 * would break the live site to tidy up for a site that does not exist yet. So
 * the tree stays whole and the export is one-way. Mygdala itself carries no
 * such content; the walk is the same either way.
 *
 * EVERY DECISION IS IN App\Install\FreshSiteCopyPolicy, not here. This file
 * walks, copies, creates directories and prints; the policy says what belongs
 * in a copy and what does not, and Tests\Install\FreshSiteCopyTest holds it to
 * that — including against `.gitignore`, so the two cannot drift apart. A
 * script nobody can test is a boundary nobody checks.
 *
 * IT EDITS NOTHING. Files that still name this site — README.md most
 * obviously — are listed at the end for a human to rewrite. Rewriting prose
 * automatically is guessing, and a half-renamed site is worse than a list.
 *
 * IT WALKS THE FILESYSTEM, not git's index, so that the export can be tested
 * where there is no git client — which is where this project's tests run. The
 * cost is that an UNTRACKED file in the working tree is copied like any
 * other: check `git status` before exporting, or delete the copy's stray
 * files afterwards. Everything git ignores is excluded by the policy anyway,
 * and Tests\Install\FreshSiteCopyTest reads `.gitignore` to hold it to that.
 *
 * See SETUP.md, "Een tweede site beginnen", for the whole procedure this is
 * the first step of.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Install\FreshSiteCopyPolicy;

$root = str_replace('\\', '/', dirname(__DIR__));
$arguments = array_slice($argv, 1);
$force = in_array('--force', $arguments, true);
$positional = array_values(array_filter($arguments, static fn (string $a): bool => !str_starts_with($a, '--')));

if ($positional === []) {
    fwrite(STDERR, "Usage: php scripts/create_fresh_site_copy.php <destination> [--force]\n");
    exit(1);
}

$destination = rtrim(str_replace('\\', '/', $positional[0]), '/');

if ($destination === '') {
    fwrite(STDERR, "The destination cannot be empty.\n");
    exit(1);
}

if (!is_dir(dirname($destination))) {
    fwrite(STDERR, "The destination's parent directory does not exist: " . dirname($destination) . "\n");
    exit(1);
}

if (str_starts_with($destination . '/', $root . '/')) {
    fwrite(STDERR, "The destination is inside this project. Choose a directory outside it.\n");
    exit(1);
}

if (is_dir($destination) && !$force) {
    $existing = array_diff(scandir($destination) ?: [], ['.', '..']);
    if ($existing !== []) {
        fwrite(STDERR, "{$destination} is not empty. Pass --force to write into it anyway.\n");
        exit(1);
    }
}

$copied = 0;
$review = [];

copyDirectory($root, $destination, '', $copied, $review);

foreach (FreshSiteCopyPolicy::WRITABLE_DIRECTORIES as $directory) {
    ensureDirectory($destination . '/' . $directory);
    $keep = $destination . '/' . $directory . '/.gitkeep';
    if (!file_exists($keep)) {
        file_put_contents($keep, '');
    }
}

echo "Copied {$copied} files to {$destination}.\n";
echo 'Recreated ' . count(FreshSiteCopyPolicy::WRITABLE_DIRECTORIES) . " writable directories, empty.\n";

if ($review !== []) {
    ksort($review);
    echo "\nStill mentions this site — read and rewrite before publishing:\n";
    foreach ($review as $relative => $needles) {
        echo '  ' . $relative . '  (' . implode(', ', $needles) . ")\n";
    }
}

echo "\nNext: cd {$destination} && git init, then follow SETUP.md \"Een tweede site beginnen\".\n";

exit(0);

// --------------------------------------------------------------- functions

/**
 * @param array<string, list<string>> $review
 */
function copyDirectory(string $sourceRoot, string $destinationRoot, string $relative, int &$copied, array &$review): void
{
    $source = $relative === '' ? $sourceRoot : $sourceRoot . '/' . $relative;

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
        $childSource = $sourceRoot . '/' . $childRelative;

        if (is_dir($childSource)) {
            if (FreshSiteCopyPolicy::descendsInto($childRelative)) {
                copyDirectory($sourceRoot, $destinationRoot, $childRelative, $copied, $review);
            }

            continue;
        }

        if (!FreshSiteCopyPolicy::includes($childRelative)) {
            continue;
        }

        $target = $destinationRoot . '/' . $childRelative;
        ensureDirectory(dirname($target));

        if (!copy($childSource, $target)) {
            fwrite(STDERR, "Could not copy {$childRelative}\n");
            exit(1);
        }

        $copied++;

        if (!FreshSiteCopyPolicy::isWorthReviewing($childRelative)) {
            continue;
        }

        $needles = mentionsThisSite($childSource);
        if ($needles !== []) {
            $review[$childRelative] = $needles;
        }
    }
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0o775, true) && !is_dir($path)) {
        fwrite(STDERR, "Could not create {$path}\n");
        exit(1);
    }
}

/**
 * Which of the policy's review needles a copied text file contains. Binary
 * files and anything over a megabyte are skipped: this is a prose check, and
 * a match inside a compiled asset would not be actionable anyway.
 *
 * @return list<string>
 */
function mentionsThisSite(string $path): array
{
    if ((int) filesize($path) > 1_048_576) {
        return [];
    }

    $contents = (string) file_get_contents($path);

    if (str_contains($contents, "\0")) {
        return [];
    }

    return FreshSiteCopyPolicy::reviewNeedlesIn($contents);
}
