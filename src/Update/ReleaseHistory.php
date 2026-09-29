<?php

declare(strict_types=1);

namespace App\Update;

/**
 * The list of earlier releases on the Updates screen ("Eerdere updates"):
 * every published Mygdala release with its notes, to READ back what changed
 * in a version. Never to install one — there is no downgrade and no
 * rollback to an older release (docs/updates/ARCHITECTURE.md, "Eerdere
 * updates").
 *
 * WHERE IT COMES FROM. The signed feed (HttpUpdateSource) names only the
 * newest release. The release host has the rest: the project's feed lives
 * on GitHub Releases, whose public API lists every release with its notes
 * in ONE request (`GET /repos/{owner}/{repo}/releases`). The address is
 * derived from the configured manifest URL (UpdateConfig::releaseHistoryUrl)
 * or set explicitly; a feed somewhere else simply has no history, and the
 * screen says so. No request parameter reaches the URL.
 *
 * WHY UNSIGNED IS ACCEPTABLE HERE. The list is not signed, so it is treated
 * as text from a stranger: it can only ever be SHOWN. Nothing in it reaches
 * the updater — no package URL, no checksum, no version to install is read
 * from it; install decisions stay with the signed manifest alone. What is
 * kept is validated to a closed shape (ReleaseNote: a MAJOR.MINOR.PATCH
 * version, a date, a capped title and capped notes), drafts and
 * pre-releases are dropped, and the notes are rendered by
 * ReleaseNotesMarkdown, which escapes everything before it formats.
 *
 * CACHED, NOT FETCHED ON RENDER. refresh() runs with "Controleren op
 * updates" (api/admin/updates-check.php), next to Updater::check(), and
 * writes release-history.json in the updater's own storage directory. The
 * screen only reads that file. A failed refresh keeps the releases fetched
 * last time and records why it failed, so the history survives a host that
 * is offline; it never throws, and it never affects the update check.
 */
final class ReleaseHistory
{
    public const FILE = 'release-history.json';

    /** Releases kept: plenty for years of history, one page of the API. */
    public const MAX_RELEASES = 50;

    /** A release list with 50 sets of notes is some hundreds of kilobytes. */
    private const MAX_BYTES = 2097152;

    /** @var \Closure(string, int): string */
    private readonly \Closure $fetch;

    /**
     * @param (\Closure(string, int): string)|null $fetch fetches a URL with a byte limit; HttpFetcher::get() by default
     */
    public function __construct(
        private readonly string $storagePath,
        private readonly ?string $url,
        ?\Closure $fetch = null
    ) {
        $this->fetch = $fetch ?? static fn (string $url, int $maxBytes): string => (new HttpFetcher())->get($url, $maxBytes);
    }

    public static function fromConfig(): self
    {
        return new self(UpdateConfig::storagePath(), UpdateConfig::releaseHistoryUrl());
    }

    /** Is there a release host to ask at all? */
    public function isAvailable(): bool
    {
        return $this->url !== null;
    }

    /**
     * Fetches the list and records it. Never throws: a failure is recorded
     * next to the releases fetched last time.
     *
     * @return array{fetched_at?: string, releases: list<array<string, string>>, error?: array{key: string, detail: string}, failed_at?: string}
     */
    public function refresh(): array
    {
        $previous = $this->read();
        $record = ['releases' => array_map(static fn (ReleaseNote $note): array => $note->toArray(), $previous['releases'])];
        if (isset($previous['fetched_at'])) {
            $record['fetched_at'] = $previous['fetched_at'];
        }

        if ($this->url === null) {
            return $record + ['error' => ['key' => 'update.releases.error.no_source', 'detail' => 'No release list for this feed']];
        }

        try {
            $notes = self::parseGitHub(($this->fetch)($this->url, self::MAX_BYTES));
            $record = [
                'fetched_at' => UpdateState::now(),
                'releases' => array_map(static fn (ReleaseNote $note): array => $note->toArray(), $notes),
            ];
        } catch (UpdateException $e) {
            $record['error'] = ['key' => 'update.releases.error.unreadable', 'detail' => $e->getMessage()];
            $record['failed_at'] = UpdateState::now();
        } catch (\Throwable $e) {
            $record['error'] = ['key' => 'update.releases.error.unreadable', 'detail' => get_class($e) . ': ' . $e->getMessage()];
            $record['failed_at'] = UpdateState::now();
        }

        try {
            if (!is_dir($this->storagePath) && !@mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
                throw new \RuntimeException('Cannot create ' . $this->storagePath);
            }
            UpdateStateStore::writeAtomically(
                $this->storagePath . '/' . self::FILE,
                json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n"
            );
        } catch (\Throwable) {
            // A history that cannot be cached is shown as not fetched; the
            // update check itself is unaffected.
        }

        return $record;
    }

    /**
     * What the last refresh recorded. A missing or damaged file is an empty
     * history, never an exception.
     *
     * @return array{releases: list<ReleaseNote>, fetched_at?: string, failed_at?: string, error?: array{key: string, detail: string}}
     */
    public function read(): array
    {
        $path = $this->storagePath . '/' . self::FILE;
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (!is_array($data)) {
            return ['releases' => []];
        }

        $releases = [];
        foreach (is_array($data['releases'] ?? null) ? $data['releases'] : [] as $row) {
            $note = ReleaseNote::fromArray($row);
            if ($note !== null) {
                $releases[] = $note;
            }
        }

        $result = ['releases' => array_slice(self::sorted($releases), 0, self::MAX_RELEASES)];
        foreach (['fetched_at', 'failed_at'] as $key) {
            if (is_string($data[$key] ?? null) && ReleaseNote::date($data[$key]) !== '') {
                $result[$key] = $data[$key];
            }
        }
        if (is_array($data['error'] ?? null) && is_string($data['error']['key'] ?? null)) {
            $result['error'] = [
                'key' => str_starts_with($data['error']['key'], 'update.releases.error.') ? $data['error']['key'] : 'update.releases.error.unreadable',
                'detail' => is_string($data['error']['detail'] ?? null) ? mb_substr($data['error']['detail'], 0, 500) : '',
            ];
        }

        return $result;
    }

    /**
     * The GitHub Releases API's answer as ReleaseNotes, newest version
     * first. Everything is checked, because nothing in it is signed: an
     * answer that is not a list is an error, and a release that is a draft,
     * a pre-release, or has no MAJOR.MINOR.PATCH tag is left out.
     *
     * @return list<ReleaseNote>
     *
     * @throws UpdateException when the answer is not a release list
     */
    public static function parseGitHub(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UpdateException('update.releases.error.unreadable', [], 'Release list is not JSON: ' . $e->getMessage());
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new UpdateException('update.releases.error.unreadable', [], 'Release list is not a JSON list');
        }

        $notes = [];
        $seen = [];
        foreach ($data as $release) {
            if (!is_array($release) || ($release['draft'] ?? false) !== false || ($release['prerelease'] ?? false) !== false) {
                continue;
            }

            $tag = $release['tag_name'] ?? null;
            $version = is_string($tag) ? (string) preg_replace('/\Av/', '', $tag) : '';
            if (!SemVer::isValid($version) || isset($seen[$version])) {
                continue;
            }

            $publishedAt = is_string($release['published_at'] ?? null) ? ReleaseNote::date($release['published_at']) : '';
            $title = is_string($release['name'] ?? null) ? trim($release['name']) : '';
            $body = is_string($release['body'] ?? null) ? $release['body'] : '';

            $note = ReleaseNote::fromArray([
                'version' => $version,
                'published_at' => $publishedAt,
                'title' => $title,
                'notes' => str_replace("\r\n", "\n", $body),
            ]);
            if ($note !== null) {
                $notes[] = $note;
                $seen[$version] = true;
            }
        }

        return array_slice(self::sorted($notes), 0, self::MAX_RELEASES);
    }

    /**
     * @param list<ReleaseNote> $notes
     *
     * @return list<ReleaseNote> newest version first
     */
    private static function sorted(array $notes): array
    {
        usort($notes, static fn (ReleaseNote $a, ReleaseNote $b): int => $b->semver()->compare($a->semver()));

        return $notes;
    }
}
