<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * Validates and stores a font file uploaded by an administrator for the
 * global engraving font library (`personalization_fonts`).
 *
 * ## Which formats, and why these
 *
 * All four are formats a browser can use directly through `@font-face`, with
 * NO conversion step anywhere: nothing here shells out, and there is no
 * Node.js pipeline. On the PHP/Apache side a font file is just a static byte
 * stream, so shared hosting (Vimexx) serves it as happily as it serves an
 * image — the only thing that has to be arranged is the MIME type, which
 * assets/fonts/personalization/.htaccess sets explicitly rather than trusting
 * the host's mime.types to be current.
 *
 *   woff2  — the format to prefer. Roughly 30% smaller than WOFF for the same
 *            outlines, supported by every browser this shop targets.
 *   woff   — the compatible older wrapper; still universally supported.
 *   ttf/otf— accepted because the owner's own font purchases usually arrive
 *            as one of these and requiring a conversion tool first would make
 *            the feature unusable. They are simply bigger downloads; both are
 *            supported by every current browser.
 *
 * Web-only wrappers (EOT, SVG fonts) and archives (ZIP) are rejected: the
 * first two are obsolete, and an archive would need to be unpacked, which is
 * an attack surface with no upside.
 *
 * ## What is actually checked
 *
 * The client-supplied filename and MIME type are never trusted — not for the
 * format, and above all not for the path. Validation is:
 *   1. a real, successful PHP upload (is_uploaded_file);
 *   2. a size within MAX_BYTES;
 *   3. the extension is one of the four (a first, cheap filter);
 *   4. the file's actual SIGNATURE matches that extension. This is the check
 *      that counts: a .woff2 whose first four bytes are not `wOF2` is not a
 *      font, whatever it claims.
 *
 * The stored filename is generated here (32 random hex characters plus the
 * verified extension), so nothing a browser sent ever becomes part of a path.
 * The original name is kept only as display metadata on the database row.
 */
class PersonalizationFontUploader
{
    /** Generous for a font: a large OTF with a full glyph set is ~1-2 MB. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /** The only folder this service writes to or deletes from. */
    private const PUBLIC_PREFIX = 'assets/fonts/personalization/';

    /**
     * extension => the byte signature(s) that file must actually start with.
     *
     * TTF has two legal sniffs: the version tag 0x00010000 and the legacy
     * Apple 'true' tag. An OTF (CFF outlines) starts with 'OTTO'. Some
     * foundries ship an OTF-flavoured file with a .ttf name and vice versa,
     * so both extensions accept both desktop signatures — the extension only
     * decides the `format()` hint, and getting that slightly wrong costs a
     * warning at worst, while rejecting the owner's real font costs the
     * feature.
     *
     * @var array<string, list<string>>
     */
    private const SIGNATURES = [
        'woff2' => ["wOF2"],
        'woff' => ["wOFF"],
        'ttf' => ["\x00\x01\x00\x00", 'true', 'ttcf', 'OTTO'],
        'otf' => ['OTTO', "\x00\x01\x00\x00", 'true', 'ttcf'],
    ];

    private string $uploadDir;

    public function __construct(?string $uploadDir = null)
    {
        $this->uploadDir = $uploadDir !== null
            ? rtrim($uploadDir, '/\\') . '/'
            : dirname(__DIR__, 3) . '/' . self::PUBLIC_PREFIX;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /** @return list<string> the accepted extensions, in preference order */
    public static function allowedExtensions(): array
    {
        return array_keys(self::SIGNATURES);
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     * @return array{file_path: string, file_format: string, original_filename: string, byte_size: int}
     * @throws \RuntimeException with a Dutch, admin-facing message
     */
    public function store(array $file): array
    {
        $extension = $this->validate($file);

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->uploadDir . $filename;

        // Suppressed on purpose: a write that fails (a folder the web user
        // cannot write to, a full disk) must surface as the clean message
        // below, not as a raw PHP warning printed before any header —
        // which would break the redirect as well as leaking a server path.
        if (!@move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Het lettertype kon niet worden opgeslagen.');
        }

        chmod($destination, 0644);

        return [
            'file_path' => self::PUBLIC_PREFIX . $filename,
            'file_format' => $extension,
            'original_filename' => self::displayName((string) ($file['name'] ?? '')),
            'byte_size' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * The same validation as store(), without moving anything — so the file
     * can be checked before a database row is created, and so tests can
     * exercise the rules without a real PHP upload.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param bool $requireUploadedFile false only for a file that did not
     *        arrive through PHP's upload machinery (tests, seeds)
     * @return string the verified extension
     * @throws \RuntimeException
     */
    public function validate(array $file, bool $requireUploadedFile = true): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Kies een lettertypebestand.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('Het lettertype is te groot (maximaal ' . self::maxMegabytes() . ' MB).');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van het lettertype is mislukt. Probeer het opnieuw.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_file($tmpName) || ($requireUploadedFile && !is_uploaded_file($tmpName))) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        $size = (int) ($file['size'] ?? filesize($tmpName));

        if ($size <= 0) {
            throw new \RuntimeException('Het lettertypebestand is leeg.');
        }

        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException('Het lettertype is te groot (maximaal ' . self::maxMegabytes() . ' MB).');
        }

        // The client-supplied name is used for ONE thing: reading its
        // extension. It never becomes part of a path.
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!isset(self::SIGNATURES[$extension])) {
            throw new \RuntimeException(
                'Alleen ' . strtoupper(implode(', ', self::allowedExtensions())) . ' lettertypebestanden zijn toegestaan.'
            );
        }

        $header = (string) @file_get_contents($tmpName, false, null, 0, 4);

        if (!self::signatureMatches($header, $extension)) {
            throw new \RuntimeException(
                'Dit bestand is geen geldig ' . strtoupper($extension) . '-lettertype. Controleer het bestand en probeer het opnieuw.'
            );
        }

        return $extension;
    }

    /**
     * Deletes a previously uploaded font file, but only when it actually
     * lives in the personalization font folder — a no-op for anything else,
     * so removing a library row can never remove a shared site asset.
     */
    public function delete(?string $filePath): void
    {
        if ($filePath === null || !str_starts_with($filePath, self::PUBLIC_PREFIX)) {
            return;
        }

        // basename() strips any directory component a corrupted row could
        // hold, so this can only ever resolve inside uploadDir.
        $path = $this->uploadDir . basename($filePath);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function maxMegabytes(): int
    {
        return (int) round(self::MAX_BYTES / (1024 * 1024));
    }

    private static function signatureMatches(string $header, string $extension): bool
    {
        foreach (self::SIGNATURES[$extension] as $signature) {
            if (str_starts_with($header, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The original filename, reduced to something safe to PRINT. Never a
     * path: any directory component is stripped, control characters removed
     * and the result truncated to the column width.
     */
    private static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'lettertype' : mb_substr($name, 0, 255);
    }
}
