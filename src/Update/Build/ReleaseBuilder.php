<?php

declare(strict_types=1);

namespace App\Update\Build;

use App\Update\AppVersion;
use App\Update\Ownership;
use App\Update\PackageValidator;
use App\Update\RelativePath;
use App\Update\ReleaseDescriptor;
use App\Update\ReleaseSignature;
use App\Update\SemVer;
use App\Update\UpdateException;
use App\Update\UpdateState;

/**
 * Builds a Mygdala release from a ReleaseSource:
 *
 *     <out>/mygdala-<version>.zip       the package
 *     <out>/manifest.json               the feed manifest (ReleaseManifest)
 *     <out>/manifest.json.sig           its Ed25519 signature, when a key is given
 *     <out>/release.json                a copy of the one inside the package
 *
 * docs/updates/RELEASES.md is the procedure; scripts/release.php is the
 * command. Nothing here publishes anything: the output is a directory that
 * whoever makes the release uploads to the feed host.
 *
 * What it guarantees, so an installation's updater never meets a surprise:
 *
 *   - only files Ownership says a release owns, every path RelativePath-safe;
 *   - VERSION in the source IS the version asked for — a release can never
 *     carry one number in its name and another in its code;
 *   - the files the next update depends on are there (PackageValidator's
 *     list), and vendor/'s autoloader runs the maintenance guard;
 *   - release.json lists every file with its SHA-256 and the migrations the
 *     release brings, and the manifest repeats what the updater checks
 *     before downloading;
 *   - every repository file under the line-ending contract (LineEndings):
 *     text with LF only, binaries and vendor/ byte for byte, and a file type
 *     the contract does not know refused. The same commit built from a
 *     Windows working copy or from a Linux archive is the same release;
 *   - DETERMINISTIC: entries sorted, one fixed timestamp and one fixed mode
 *     for every entry, so the same source and options give the same bytes
 *     and therefore the same SHA-256.
 */
final class ReleaseBuilder
{
    /** What a release needs from PHP, mirrored from composer.lock's ext-* requirements. */
    public const DEFAULT_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'zlib', 'zip', 'sodium', 'openssl', 'curl', 'dom', 'iconv'];

    public const DEFAULT_MINIMUM_PHP = '8.2';
    public const DEFAULT_MINIMUM_MYSQL = '5.7';
    public const DEFAULT_MINIMUM_MARIADB = '10.3';

    /** The first release that can update itself; the oldest a release accepts by default. */
    public const DEFAULT_MINIMUM_SOURCE = '0.1.0';

    /**
     * @param array{
     *     version: string,
     *     released_at?: string,
     *     build_id?: string,
     *     notes?: string,
     *     minimum_php?: string,
     *     minimum_mysql?: string,
     *     minimum_mariadb?: string,
     *     extensions?: list<string>,
     *     minimum_source_version?: string,
     *     package_url?: string,
     *     secret_key?: string|null
     * } $options
     *
     * @return array{package: string, manifest: string, signature: string|null, descriptor: ReleaseDescriptor, sha256: string, size: int}
     *
     * @throws UpdateException when the source is not a releasable Mygdala
     */
    public function build(ReleaseSource $source, array $options, string $outDirectory): array
    {
        $version = (string) $options['version'];
        if (!SemVer::isValid($version)) {
            throw self::refuse('version "' . $version . '" is not MAJOR.MINOR.PATCH');
        }

        $files = [];
        foreach ($source->files() as $path => $absolute) {
            if (!RelativePath::isSafe($path)) {
                throw self::refuse('unsafe path in the source: ' . RelativePath::printable($path));
            }
            if (Ownership::isShipped($path) && $path !== Ownership::RELEASE_MANIFEST) {
                $files[$path] = $absolute;
            }
        }
        ksort($files, SORT_STRING);

        // What the release ships for each repository file: a text file as
        // its LF bytes, held here; a binary or a vendor/ file as it is on
        // disk, so it has no entry.
        $texts = [];
        foreach ($files as $path => $absolute) {
            $class = LineEndings::classify($path);
            if ($class === null) {
                throw self::refuse('no line-ending rule for ' . RelativePath::printable($path) . '; give its file type a line in .gitattributes and in App\Update\Build\LineEndings');
            }
            if ($class !== LineEndings::TEXT) {
                continue;
            }
            $bytes = @file_get_contents($absolute);
            if ($bytes === false) {
                throw self::refuse('cannot read ' . RelativePath::printable($path));
            }
            $text = LineEndings::canonicalText($bytes);
            if ($text === null) {
                throw self::refuse('a carriage return that ends no line in ' . RelativePath::printable($path));
            }
            $texts[$path] = $text;
        }

        $sourceVersion = isset($texts[AppVersion::VERSION_FILE]) ? trim($texts[AppVersion::VERSION_FILE]) : '';
        if ($sourceVersion !== $version) {
            throw self::refuse(sprintf('VERSION in the source says "%s", asked to build %s', $sourceVersion, $version));
        }

        foreach (PackageValidator::REQUIRED_PATHS as $required) {
            if (!isset($files[$required])) {
                throw self::refuse('the source lacks ' . $required);
            }
        }

        $autoloadFiles = (string) @file_get_contents($files['vendor/composer/autoload_files.php'] ?? '');
        if (!str_contains($autoloadFiles, 'src/Update/maintenance-guard.php')) {
            throw self::refuse('vendor/ does not run the maintenance guard; build vendor/ with composer from this composer.json');
        }

        $folded = [];
        foreach (array_keys($files) as $path) {
            $key = strtolower($path);
            if (isset($folded[$key])) {
                throw self::refuse('two paths that are one file on a case-insensitive file system: ' . $folded[$key] . ', ' . $path);
            }
            $folded[$key] = $path;
        }

        $hashes = [];
        foreach ($files as $path => $absolute) {
            $hashes[$path] = isset($texts[$path]) ? hash('sha256', $texts[$path]) : (string) hash_file('sha256', $absolute);
        }

        $migrations = array_values(array_filter(
            array_keys($files),
            static fn (string $path): bool => preg_match('#^db/migrations/\d{14}_[A-Za-z0-9_]+\.php$#', $path) === 1
        ));
        $latestMigration = $migrations === [] ? '' : substr(basename((string) end($migrations)), 0, 14);

        $releasedAt = (string) ($options['released_at'] ?? UpdateState::now());
        $descriptor = new ReleaseDescriptor(
            $version,
            (string) ($options['build_id'] ?? $version),
            $releasedAt,
            (string) ($options['minimum_php'] ?? self::DEFAULT_MINIMUM_PHP),
            (string) ($options['minimum_mysql'] ?? self::DEFAULT_MINIMUM_MYSQL),
            (string) ($options['minimum_mariadb'] ?? self::DEFAULT_MINIMUM_MARIADB),
            array_values($options['extensions'] ?? self::DEFAULT_EXTENSIONS),
            count($migrations),
            $latestMigration,
            UpdateState::FORMAT,
            $hashes
        );
        // Round-trip through the reader the updater uses: what this builder
        // writes must be what an installation accepts.
        $descriptorJson = $descriptor->toJson();
        ReleaseDescriptor::fromJson($descriptorJson);

        if (!is_dir($outDirectory) && !@mkdir($outDirectory, 0775, true) && !is_dir($outDirectory)) {
            throw self::refuse('cannot create ' . $outDirectory);
        }

        $packageName = 'mygdala-' . $version . '.zip';
        $packagePath = $outDirectory . '/' . $packageName;
        $this->writePackage($packagePath, $files, $texts, $descriptorJson, $releasedAt);

        $sha256 = (string) hash_file('sha256', $packagePath);
        $size = (int) filesize($packagePath);

        $manifest = [
            'manifest_version' => 1,
            'product' => ReleaseDescriptor::PRODUCT,
            'version' => $version,
            'build_id' => $descriptor->buildId,
            'released_at' => $releasedAt,
            'package_url' => (string) ($options['package_url'] ?? $packageName),
            'sha256' => $sha256,
            'size' => $size,
            'minimum_php' => $descriptor->minimumPhp,
            'minimum_mysql' => $descriptor->minimumMysql,
            'minimum_mariadb' => $descriptor->minimumMariadb,
            'required_extensions' => $descriptor->extensions,
            'minimum_source_version' => (string) ($options['minimum_source_version'] ?? self::DEFAULT_MINIMUM_SOURCE),
            'updater_protocol' => UpdateState::FORMAT,
            'migrations' => ['count' => $descriptor->migrationCount, 'latest' => $descriptor->latestMigration],
            'notes' => (string) ($options['notes'] ?? ''),
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        file_put_contents($outDirectory . '/manifest.json', $manifestJson);
        file_put_contents($outDirectory . '/release.json', $descriptorJson);

        $signaturePath = null;
        $secret = $options['secret_key'] ?? null;
        if (is_string($secret) && $secret !== '') {
            $signaturePath = $outDirectory . '/manifest.json.sig';
            file_put_contents($signaturePath, ReleaseSignature::sign($manifestJson, $secret));
        } else {
            @unlink($outDirectory . '/manifest.json.sig');
        }

        return [
            'package' => $packagePath,
            'manifest' => $outDirectory . '/manifest.json',
            'signature' => $signaturePath,
            'descriptor' => $descriptor,
            'sha256' => $sha256,
            'size' => $size,
        ];
    }

    /**
     * @param array<string, string> $files relative => absolute, sorted
     * @param array<string, string> $texts relative => LF bytes, for the text files among them
     */
    private function writePackage(string $path, array $files, array $texts, string $descriptorJson, string $releasedAt): void
    {
        @unlink($path);
        $timestamp = strtotime($releasedAt) ?: 315532800;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw self::refuse('cannot create ' . $path);
        }

        $entries = $files + [Ownership::RELEASE_MANIFEST => null];
        ksort($entries, SORT_STRING);

        foreach ($entries as $relative => $absolute) {
            $added = match (true) {
                $absolute === null => $zip->addFromString($relative, $descriptorJson),
                isset($texts[$relative]) => $zip->addFromString($relative, $texts[$relative]),
                default => $zip->addFile($absolute, $relative),
            };

            if (!$added) {
                $zip->close();
                throw self::refuse('cannot add ' . $relative);
            }

            $zip->setCompressionName($relative, \ZipArchive::CM_DEFLATE);
            $zip->setMtimeName($relative, $timestamp);
            $zip->setExternalAttributesName($relative, \ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }

        if (!$zip->close()) {
            throw self::refuse('cannot write ' . $path);
        }
    }

    private static function refuse(string $detail): UpdateException
    {
        return new UpdateException('update.error.package_invalid', [], 'Release build: ' . $detail);
    }
}
