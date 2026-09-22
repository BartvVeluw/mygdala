<?php

declare(strict_types=1);

/**
 * Test support, never part of the application: a router for PHP's built-in
 * server that serves a release feed directory the way release hosts behave —
 * and misbehave — while a package is downloaded (tests/Update/
 * ResumableDownloadTest.php, Tests\Support\UpdaterSandbox).
 *
 * The built-in server itself ignores Range: every answer is a 200 with the
 * whole file. This router answers `Range: bytes=N-` for a *.zip with a 206,
 * honouring If-Range against a strong ETag, unless `mode.json` in the served
 * directory says otherwise:
 *
 *   ranges        "honour" (default), "ignore", "wrong-start",
 *                 "wrong-total" or "no-content-range"
 *   etag          the ETag to send (default: from the file's content)
 *   drop_after    close the connection after this many body bytes …
 *   drop_times    … only in the first this-many package answers
 *   stall_after   stop after this many body bytes and hold the connection
 *                 open for 30 seconds
 *   rate          bytes per second
 *
 * Every package request is appended to requests.log as one JSON line (its
 * Range and If-Range), so a test can see exactly what the updater asked for.
 * Everything else — the manifest, its signature, a 404 — is the built-in
 * server's own answer.
 */

$root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath($root . $path);

if ($file === false || !is_file($file) || !str_ends_with($file, '.zip') || !str_starts_with($file, (string) realpath($root) . '/')) {
    return false;
}

$mode = json_decode((string) @file_get_contents($root . '/mode.json'), true);
$mode = is_array($mode) ? $mode : [];

// Which package answer this is, for drop_times.
$counter = fopen($root . '/package-requests.count', 'c+');
flock($counter, LOCK_EX);
$answer = (int) stream_get_contents($counter) + 1;
ftruncate($counter, 0);
rewind($counter);
fwrite($counter, (string) $answer);
flock($counter, LOCK_UN);
fclose($counter);

file_put_contents($root . '/requests.log', json_encode([
    'range' => $_SERVER['HTTP_RANGE'] ?? null,
    'if_range' => $_SERVER['HTTP_IF_RANGE'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

$size = (int) filesize($file);
$etag = (string) ($mode['etag'] ?? '"' . substr((string) hash_file('sha256', $file), 0, 16) . '"');
$ranges = (string) ($mode['ranges'] ?? 'honour');
$status = 200;
$start = 0;

if ($ranges !== 'ignore' && preg_match('/^bytes=(\d+)-$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $match) === 1) {
    $ifRange = $_SERVER['HTTP_IF_RANGE'] ?? null;
    if ($ifRange === null || $ifRange === $etag) {
        $status = 206;
        $start = (int) $match[1];
    }
}

if ($status === 206 && $start >= $size) {
    http_response_code(416);
    header('Content-Range: bytes */' . $size);
    exit;
}

// Where the body really starts, and what the Content-Range header claims.
$from = $start;
$claimed = [$start, $size - 1, $size];
if ($status === 206 && $ranges === 'wrong-start') {
    $from = max(0, $start - 100);
    $claimed = [$from, $size - 1, $size];
}
if ($status === 206 && $ranges === 'wrong-total') {
    $claimed[2] = $size + 1000;
}

http_response_code($status);
header('ETag: ' . $etag);
header('Accept-Ranges: bytes');
header('Content-Type: application/zip');
if ($status === 206 && $ranges !== 'no-content-range') {
    header('Content-Range: bytes ' . $claimed[0] . '-' . $claimed[1] . '/' . $claimed[2]);
}
header('Content-Length: ' . ($size - $from));

$drop = isset($mode['drop_after']) && (!isset($mode['drop_times']) || $answer <= (int) $mode['drop_times']) ? (int) $mode['drop_after'] : null;
$stall = isset($mode['stall_after']) ? (int) $mode['stall_after'] : null;
$rate = (int) ($mode['rate'] ?? 0);

$in = fopen($file, 'rb');
fseek($in, $from);
$sent = 0;
$total = $size - $from;

while ($sent < $total) {
    $length = min(65536, $total - $sent);
    if ($drop !== null) {
        $length = min($length, $drop - $sent);
    }
    if ($stall !== null && $stall - $sent <= 0) {
        flush();
        sleep(30);
        break;
    }
    if ($stall !== null) {
        $length = min($length, $stall - $sent);
    }
    if ($length <= 0) {
        break;
    }

    echo fread($in, $length);
    flush();
    $sent += $length;

    if ($rate > 0) {
        usleep((int) (1000000 * $length / $rate));
    }
}

exit;
