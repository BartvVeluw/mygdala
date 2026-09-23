<?php

/**
 * CLI helper: lists every SVG this installation serves from a stored path
 * and says whether App\Service\Media\SvgSanitizer would accept it
 * (App\Service\Media\SvgAudit; MEDIA.md, "SVG").
 *
 * Why this exists: since the Media Library takes SVG, every NEW SVG is
 * rebuilt by the sanitizer on the way in. An SVG stored before that — the
 * logo a site was installed with, a file from an older branding upload — never
 * went through it. This reports those files; it never changes one. A file
 * reported as refused is replaced by an editor through the Media Library.
 *
 * Usage:
 *   php scripts/audit-svg.php    (report; exit 1 when a file is refused, missing or unreadable)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Media\SvgAudit;

$report = SvgAudit::run(dirname(__DIR__));
$problems = 0;

foreach ($report as $entry) {
    $verdict = $entry['verdict'] . ($entry['reason'] !== null ? ' (' . $entry['reason'] . ')' : '');
    if ($entry['verdict'] === 'ok' && $entry['rebuilt_differs']) {
        $verdict .= ', a rebuild would only drop editor metadata';
    }
    if ($entry['verdict'] !== 'ok') {
        $problems++;
    }

    echo $entry['path'], "\n    ", $verdict, "\n    from: ", implode(', ', $entry['sources']), "\n";
}

echo count($report), ' SVG file(s), ', $problems, " not accepted by the sanitizer.\n";

exit($problems === 0 ? 0 : 1);
