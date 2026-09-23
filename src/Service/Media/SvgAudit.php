<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Database;

/**
 * Finds every SVG this installation serves from a stored path — the Media
 * Library's rows, the branding paths in site_settings, and the public upload
 * folders — and judges each one with App\Service\Media\SvgSanitizer, the one
 * and only SVG rule in this project. MEDIA.md, "SVG".
 *
 * READ-ONLY, ON PURPOSE. It never rewrites, moves or deletes a file. An SVG
 * that was stored before the sanitizer existed (an adopted logo, a file from
 * the old branding uploader) is reported as it is:
 *
 *   ok        nothing active in it; the sanitizer would accept it. Whether a
 *             rebuild would also drop editor metadata is reported beside it
 *             (`rebuilt_differs`), because that is not a safety question.
 *   refused   it contains something the sanitizer refuses, with the reason.
 *             An editor replaces it through the Media Library; nothing here
 *             decides what the fixed drawing should look like.
 *   missing / unreadable   the path points at nothing readable.
 *
 * scripts/audit-svg.php is the command line around it.
 */
final class SvgAudit
{
    /** Public folders an uploader of this project writes (or once wrote) into. */
    public const UPLOAD_FOLDERS = [
        'assets/media',
        'assets/images/sections',
        'assets/images/products',
        'assets/images/branding',
        'assets/images/personalization',
        'uploads',
    ];

    /**
     * @return list<array{path: string, sources: list<string>, verdict: string, reason: string|null, rebuilt_differs: bool}>
     */
    public static function run(string $root, ?\PDO $db = null): array
    {
        $root = rtrim($root, '/') . '/';
        $found = [];

        $add = static function (string $path, string $source) use (&$found): void {
            $path = ltrim(trim($path), '/');
            if ($path === '' || strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION)) !== 'svg') {
                return;
            }
            $found[$path][] = $source;
        };

        $db ??= Database::connection();

        foreach ($db->query("SELECT id, path FROM media WHERE mime_type = 'image/svg+xml' OR LOWER(path) LIKE '%.svg'")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $add((string) $row['path'], 'media #' . (int) $row['id']);
        }

        foreach ($db->query("SELECT setting_key, setting_value FROM site_settings WHERE LOWER(setting_value) LIKE '%.svg'")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $add((string) $row['setting_value'], 'site_settings.' . $row['setting_key']);
        }

        foreach (self::UPLOAD_FOLDERS as $folder) {
            if (!is_dir($root . $folder)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $add(substr($file->getPathname(), strlen($root)), 'folder ' . $folder);
                }
            }
        }

        ksort($found);
        $report = [];

        foreach ($found as $path => $sources) {
            $report[] = ['path' => $path, 'sources' => array_values(array_unique($sources))] + self::judge($root . $path);
        }

        return $report;
    }

    /**
     * One file's verdict, without changing it.
     *
     * @return array{verdict: string, reason: string|null, rebuilt_differs: bool}
     */
    public static function judge(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return ['verdict' => 'missing', 'reason' => null, 'rebuilt_differs' => false];
        }

        $source = @file_get_contents($absolutePath);

        if (!is_string($source)) {
            return ['verdict' => 'unreadable', 'reason' => null, 'rebuilt_differs' => false];
        }

        try {
            $clean = SvgSanitizer::sanitize($source);
        } catch (SvgRefused $e) {
            return ['verdict' => 'refused', 'reason' => $e->reason, 'rebuilt_differs' => false];
        }

        return ['verdict' => 'ok', 'reason' => null, 'rebuilt_differs' => trim($clean['svg']) !== trim($source)];
    }
}
