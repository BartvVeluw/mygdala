<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\Media\MediaUploader;

/**
 * The real Media Library uploader, with exactly one thing changed: it accepts
 * a file the test wrote instead of one PHP received through a multipart
 * request.
 *
 * WHY THIS AND NOT A MOCK. Everything worth testing about an upload is what
 * the real class does — the header check that rejects a script named .png,
 * the random filename, the 25 MB cap, the single writable folder, the
 * optimizer pass and the thumbnail. A mock would prove none of it. Overriding
 * the two functions that ask "did this arrive with the request?"
 * (is_uploaded_file / move_uploaded_file, neither of which can be true for a
 * file a test created) leaves every one of those rules in force.
 *
 * It lives in the test suite and is never autoloadable from src/, so nothing
 * in production can reach it.
 */
final class TestMediaUploader extends MediaUploader
{
    protected function isUploadedFile(string $path): bool
    {
        return is_file($path);
    }

    protected function moveUploadedFile(string $from, string $to): bool
    {
        // rename() rather than copy(): the real move consumes the temp file,
        // and a test that left its source behind would not be exercising the
        // same sequence.
        return @rename($from, $to);
    }
}
