<?php

declare(strict_types=1);

namespace App\Update;

use App\Repository\DatabaseSchemaRepository;

/**
 * After the files and the migrations: is this installation now, verifiably,
 * the release it was updated to? Maintenance mode is only lifted when every
 * check here passes (Updater, step `health`).
 *
 *   version     VERSION and release.json both say the target version
 *   files       every file the new release.json lists is on disk with its hash
 *   database    reachable, and Phinx has run exactly the release's migrations
 *               — none pending, none unknown, the newest one the release named
 *   bootstrap   this check itself runs in a fresh request on the NEW code:
 *               the autoloader, the updater and its endpoint loaded; on top
 *               of that the module registry and the site settings are asked
 *               for their answer, the two services every page starts from
 *   http        the home page and the login page, requested over HTTP with
 *               the maintenance health token, answer no worse than they did
 *               before the update (the baseline Preflight recorded). Skipped,
 *               with a warning, where the server cannot reach its own URL —
 *               common on shared hosting and inside Docker.
 */
final class HealthCheck
{
    public function __construct(
        private readonly string $root,
        private readonly ?DatabaseSchemaRepository $database = null
    ) {
    }

    /**
     * @param array<string, int> $baseline path => status before the update ([] = not reachable then)
     *
     * @return list<PreflightCheck>
     */
    public function run(ReleaseManifest $target, string $baseUrl, array $baseline, string $healthToken): array
    {
        AppVersion::clearCache();
        $checks = [];

        try {
            $version = AppVersion::current($this->root);
            $descriptor = ReleaseDescriptor::installed($this->root);

            $checks[] = $version === $target->version && $descriptor?->version === $target->version
                ? PreflightCheck::ok('version', 'update.health.version_ok', ['version' => $version])
                : PreflightCheck::error('version', 'update.health.version_wrong', ['version' => $version, 'target' => $target->version]);

            if ($descriptor !== null) {
                $changes = LocalChanges::detect($this->root, $descriptor);
                $checks[] = $changes->isClean()
                    ? PreflightCheck::ok('files', 'update.health.files_ok', ['count' => count($descriptor->files)])
                    : PreflightCheck::error('files', 'update.health.files_wrong', [
                        'count' => count($changes->modified) + count($changes->missing),
                        'paths' => implode(', ', array_slice([...$changes->modified, ...$changes->missing], 0, 10)),
                    ]);
            }
        } catch (\Throwable $e) {
            $checks[] = PreflightCheck::error('version', 'update.health.version_wrong', ['version' => '?', 'target' => $target->version], $e->getMessage());
        }

        try {
            ($this->database ?? new DatabaseSchemaRepository())->serverVersion();
            $status = (new MigrationRunner($this->root))->status();

            $checks[] = $status['pending'] === [] && $status['missing'] === [] && $status['latest'] === $target->latestMigration
                ? PreflightCheck::ok('database', 'update.health.database_ok', ['latest' => $status['latest']])
                : PreflightCheck::error('database', 'update.health.database_wrong', [
                    'pending' => count($status['pending']),
                    'missing' => count($status['missing']),
                    'latest' => $status['latest'],
                    'expected' => $target->latestMigration,
                ]);
        } catch (\Throwable $e) {
            $checks[] = PreflightCheck::error('database', 'update.preflight.database_unreachable', [], $e->getMessage());
        }

        try {
            \App\Module\ModuleRegistry::reset();
            \App\Module\ModuleRegistry::enabled();
            \App\Service\SiteSettings::all();
            $checks[] = PreflightCheck::ok('bootstrap', 'update.health.bootstrap_ok');
        } catch (\Throwable $e) {
            $checks[] = PreflightCheck::error('bootstrap', 'update.health.bootstrap_failed', [], get_class($e) . ': ' . $e->getMessage());
        }

        $checks[] = $this->http($baseUrl, $baseline, $healthToken);

        return $checks;
    }

    /**
     * Status codes of the pages the HTTP check compares, from outside PHP's
     * own process. Used before the update (the baseline, without a token)
     * and after it (with the token).
     *
     * @return array<string, int> path => status; [] when the site cannot reach itself
     */
    public static function probe(string $baseUrl, string $healthToken = ''): array
    {
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return [];
        }

        $statuses = [];
        foreach (['/', '/admin/login.php'] as $path) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", array_filter([
                        'User-Agent: Mygdala-Updater-HealthCheck',
                        $healthToken !== '' ? MaintenanceGuard::HEALTH_HEADER . ': ' . $healthToken : '',
                    ])),
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $body = @file_get_contents(rtrim($baseUrl, '/') . $path, false, $context, 0, 262144);
            $headers = $http_response_header ?? [];

            if ($body === false && $headers === []) {
                return [];
            }

            $status = 0;
            foreach ($headers as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $match) === 1) {
                    $status = (int) $match[1];
                }
            }
            $statuses[$path] = $status;
        }

        return $statuses;
    }

    /** @param array<string, int> $baseline */
    private function http(string $baseUrl, array $baseline, string $healthToken): PreflightCheck
    {
        if ($baseline === []) {
            return PreflightCheck::warning('http', 'update.health.http_skipped');
        }

        $now = self::probe($baseUrl, $healthToken);

        if ($now === []) {
            return PreflightCheck::error('http', 'update.health.http_unreachable', [], 'The site answered before the update and does not now');
        }

        foreach ($now as $path => $status) {
            $before = (int) ($baseline[$path] ?? 0);
            // Worse means: it worked (below 500) and now it does not.
            if ($status >= 500 && $before > 0 && $before < 500) {
                return PreflightCheck::error('http', 'update.health.http_failed', ['path' => $path, 'status' => $status]);
            }
        }

        return PreflightCheck::ok('http', 'update.health.http_ok', ['statuses' => implode(', ', array_map(
            static fn (string $path, int $status): string => $path . ' ' . $status,
            array_keys($now),
            $now
        ))]);
    }
}
