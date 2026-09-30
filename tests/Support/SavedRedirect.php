<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What a successful save's redirect looks like, for the HTTP tests that ask
 * "did it save?" rather than "where did it land?".
 *
 * Since Content Blocks Lifecycle 1.0 a block editor's save lands on the list
 * the block stands in, naming it — `…&saved=<page_sections id>#blok-<id>`
 * (ContentBlockAccess::afterSaveUrl()) — where every other editor still
 * lands on itself with `saved=1`. A plain substring check for "saved=1"
 * would pass on `saved=12#blok-12` by accident and fail on
 * `saved=7#blok-7`, so these tests match this pattern instead. Where the
 * destination itself is the point, Tests\Service\ContentBlockLifecycleHttpTest
 * checks it exactly.
 */
final class SavedRedirect
{
    public const PATTERN = '~[?&]saved=(?:1(?=&|#|$)|[1-9][0-9]*#blok-[0-9]+$)~';
}
