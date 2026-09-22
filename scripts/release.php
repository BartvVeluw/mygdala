<?php

/**
 * Makes a Mygdala release. A development tool: it is not part of any release
 * itself (App\Update\Ownership), and it publishes nothing — it writes a
 * directory that whoever makes the release uploads to the feed host.
 * docs/updates/RELEASES.md is the whole procedure.
 *
 *   php scripts/release.php keygen --out=<directory outside this repository>
 *
 *       Makes the Ed25519 release key pair: <out>/mygdala-release.key (the
 *       SECRET key, keep it offline) and prints the public key that goes into
 *       App\Update\ReleaseKeys or MYGDALA_UPDATE_PUBLIC_KEY.
 *
 *   php scripts/release.php build --version=0.2.0
 *       (--source-zip=<git archive zip> | --source-dir=<directory> | --ref=<git ref>)
 *       [--vendor=composer | --vendor-dir=<directory>]
 *       [--key-file=<secret key file>] [--notes-file=<text file>]
 *       [--minimum-source=0.1.0] [--package-url=<url>] [--released-at=<ISO 8601>]
 *       [--build-id=<id>] [--out=dist]
 *
 *       Builds dist/mygdala-<version>.zip, manifest.json, manifest.json.sig
 *       and release.json. The source is ONE revision of the repository:
 *       `git archive --format=zip -o src.zip v0.2.0` made anywhere git runs,
 *       or --ref when git runs here too. vendor/ is a fresh
 *       `composer install --no-dev` unless --vendor-dir names one.
 *
 *   php scripts/release.php verify --dir=dist --public-key=<base64>
 *
 *       Checks what build wrote, the way an installation will: the signature
 *       over manifest.json, and the package against its SHA-256 and size.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Update\Build\ReleaseBuilder;
use App\Update\Build\ReleaseSource;
use App\Update\Filesystem;
use App\Update\ReleaseManifest;
use App\Update\ReleasePackage;
use App\Update\ReleaseSignature;
use App\Update\UpdateException;

$root = dirname(__DIR__);
$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2] ?? '1';
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "\n  release: {$message}\n\n");
    exit(1);
}

/**
 * @param list<string> $command
 *
 * @return array{0: int, 1: string}
 */
function run(array $command, ?string $cwd = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return [1, 'could not start ' . $command[0]];
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

function isInside(string $path, string $root): bool
{
    $real = realpath($path) ?: $path;

    return str_starts_with(str_replace('\\', '/', $real) . '/', str_replace('\\', '/', (string) realpath($root)) . '/');
}

try {
    switch ($command) {
        case 'keygen':
            $out = (string) ($options['out'] ?? '');
            if ($out === '' || !is_dir($out)) {
                fail('--out must name an existing directory');
            }
            if (isInside($out, $root)) {
                fail('refusing to write the secret key inside this repository; choose a directory outside it');
            }
            $keyFile = rtrim($out, '/\\') . '/mygdala-release.key';
            if (file_exists($keyFile)) {
                fail($keyFile . ' already exists; a release key is made once');
            }
            $pair = ReleaseSignature::generateKeyPair();
            file_put_contents($keyFile, base64_encode($pair['secret']) . "\n");
            @chmod($keyFile, 0600);
            echo "Secret key written to {$keyFile}\n";
            echo "Keep it offline. Whoever holds it can sign Mygdala releases.\n\n";
            echo 'Public key (App\Update\ReleaseKeys / MYGDALA_UPDATE_PUBLIC_KEY): ' . base64_encode($pair['public']) . "\n";
            echo 'Key id: ' . ReleaseSignature::keyId($pair['public']) . "\n";
            exit(0);

        case 'build':
            $version = (string) ($options['version'] ?? '');
            $out = (string) ($options['out'] ?? $root . '/dist');
            $work = sys_get_temp_dir() . '/mygdala-release-' . bin2hex(random_bytes(4));
            mkdir($work, 0775, true);

            try {
                $buildId = (string) ($options['build-id'] ?? '');
                $sourceDir = $work . '/source';

                if (isset($options['source-dir'])) {
                    $sourceDir = (string) $options['source-dir'];
                } elseif (isset($options['source-zip']) || isset($options['ref'])) {
                    $zipPath = (string) ($options['source-zip'] ?? $work . '/source.zip');
                    if (isset($options['ref'])) {
                        [$status, $output] = run(['git', 'archive', '--format=zip', '-o', $zipPath, (string) $options['ref']], $root);
                        if ($status !== 0) {
                            fail("git archive failed:\n" . $output);
                        }
                    }
                    $zip = new ZipArchive();
                    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
                        fail('cannot open ' . $zipPath);
                    }
                    // `git archive` stores the commit id as the archive comment.
                    $commit = (string) $zip->getArchiveComment();
                    $zip->extractTo($sourceDir);
                    $zip->close();
                    if ($buildId === '' && preg_match('/^[0-9a-f]{40}$/', $commit) === 1) {
                        $buildId = $version . '+' . substr($commit, 0, 12);
                    }
                } else {
                    fail('name the source: --source-zip, --source-dir or --ref');
                }

                $vendorDir = (string) ($options['vendor-dir'] ?? '');
                if ($vendorDir === '') {
                    $vendorWork = $work . '/vendor-build';
                    mkdir($vendorWork, 0775, true);
                    copy($sourceDir . '/composer.json', $vendorWork . '/composer.json');
                    copy($sourceDir . '/composer.lock', $vendorWork . '/composer.lock');
                    // The autoloader must see src/ to register the guard file.
                    symlink($sourceDir . '/src', $vendorWork . '/src');
                    [$status, $output] = run([
                        'composer', 'install', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress',
                        '--optimize-autoloader', '--no-scripts', '--working-dir=' . $vendorWork,
                    ]);
                    if ($status !== 0) {
                        fail("composer install failed:\n" . $output);
                    }
                    $vendorDir = $vendorWork . '/vendor';
                }

                $secret = null;
                if (isset($options['key-file'])) {
                    $secret = base64_decode(trim((string) file_get_contents((string) $options['key-file'])), true);
                    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                        fail('--key-file does not hold a base64 Ed25519 secret key');
                    }
                }

                $buildOptions = ['version' => $version, 'build_id' => $buildId !== '' ? $buildId : $version, 'secret_key' => $secret];
                foreach ([
                    'released-at' => 'released_at',
                    'minimum-source' => 'minimum_source_version',
                    'package-url' => 'package_url',
                ] as $flag => $key) {
                    if (isset($options[$flag])) {
                        $buildOptions[$key] = (string) $options[$flag];
                    }
                }
                if (isset($options['notes-file'])) {
                    $buildOptions['notes'] = (string) file_get_contents((string) $options['notes-file']);
                }

                $result = (new ReleaseBuilder())->build(ReleaseSource::fromDirectory($sourceDir, $vendorDir), $buildOptions, $out);
            } finally {
                Filesystem::remove($work);
            }

            printf("Built Mygdala %s (%s)\n", $version, $result['descriptor']->buildId);
            printf("  package   %s (%d bytes, %d files)\n", $result['package'], $result['size'], count($result['descriptor']->files));
            printf("  sha256    %s\n", $result['sha256']);
            printf("  manifest  %s\n", $result['manifest']);
            printf("  signature %s\n", $result['signature'] ?? 'NONE — unsigned; no installation will accept it');
            exit(0);

        case 'verify':
            $dir = rtrim((string) ($options['dir'] ?? $root . '/dist'), '/\\');
            $public = base64_decode((string) ($options['public-key'] ?? ''), true);
            if ($public === false || strlen($public) !== 32) {
                fail('--public-key must be a base64 Ed25519 public key');
            }
            $bytes = (string) file_get_contents($dir . '/manifest.json');
            $keyId = ReleaseSignature::verify($bytes, (string) @file_get_contents($dir . '/manifest.json.sig'), [ReleaseSignature::keyId($public) => $public]);
            $manifest = ReleaseManifest::fromJson($bytes, 'https://verify.invalid/manifest.json');
            (new ReleasePackage($dir . '/' . basename(parse_url($manifest->packageUrl, PHP_URL_PATH) ?: ''), $manifest))->verify();
            echo "OK: Mygdala {$manifest->version}, signed with key {$keyId}, package matches its SHA-256 and size.\n";
            exit(0);

        default:
            fail('usage: php scripts/release.php keygen|build|verify … (see the docblock)');
    }
} catch (UpdateException $e) {
    fail($e->getMessage());
}
