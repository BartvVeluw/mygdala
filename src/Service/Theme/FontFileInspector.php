<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Service\Language\AdminTranslator;

/**
 * Decides whether an uploaded file is a web font the Font Library may keep,
 * and which of the four formats it really is.
 *
 * ## Which formats, and why these four
 *
 * WOFF2, WOFF, TTF and OTF are all formats a browser uses directly through
 * `@font-face`, so nothing is ever converted: no executable, no Node.js, no
 * extension PHP on shared hosting (Vimexx) does not have. TTF matters most
 * in practice: the "Download family" ZIP of Google Fonts holds TTF files,
 * not WOFF2, and refusing those would make the feature useless for exactly
 * the person the CMS help is written for. TTF/OTF are bigger downloads,
 * that is all. The same four are what the engraving font library accepts
 * (App\Service\Personalization\PersonalizationFontUploader).
 *
 * Refused: TrueType collections (`ttcf`; `@font-face` cannot pick a face
 * out of one), EOT and SVG fonts (obsolete), and every archive — a ZIP is
 * never unpacked, the administrator uploads the font files themselves.
 *
 * ## What is checked, in this order
 *
 *   1. a real, successful PHP upload, not empty, at most MAX_BYTES;
 *   2. the extension of the client's name is one of the four — the name is
 *      used for nothing else, never for a path;
 *   3. the MIME type the BROWSER sent may not say it is something else
 *      (text, an image, a script, an archive). Only a deny list: browsers
 *      send anything from `font/ttf` to `application/octet-stream` to an
 *      OpenDocument type for `.otf`, so an allow list would refuse real
 *      fonts;
 *   4. the MIME type the SERVER sees (finfo, the file's own bytes) must be a
 *      font or unknown binary — an allow list, because this one is ours;
 *   5. the container is read from the bytes (`wOF2`, `wOFF`, an sfnt
 *      version) and must agree with the extension (.ttf and .otf both
 *      accept either sfnt flavour, because foundries mix them up);
 *   6. the structure: the header's own length and table count, every table
 *      record inside the file, the tables a font cannot render without
 *      (cmap, head, hhea, hmtx, maxp, name, and outlines), and the magic
 *      number of the `head` table where it can be read.
 *
 * Step 6 is as far as a server can reliably go without decompressing
 * Brotli (WOFF2), which PHP here cannot. It is enough to keep every
 * non-font out; a font that passes and is still broken inside is refused by
 * the browser itself (every current browser runs web fonts through a
 * sanitizer, OTS, before using them) and the page falls back to the
 * fallback stack, readable as always.
 *
 * Pure reading: this class never writes, moves or names a file. Storage is
 * App\Service\Theme\FontStorage.
 */
final class FontFileInspector
{
    /** Per file. A full Latin family's biggest TTF is well under 1 MB. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** extension => the CSS `format()` hint for it. */
    public const FORMATS = [
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
        'otf' => 'opentype',
    ];

    /** What the server's own sniffing may call a font file. */
    private const SERVER_MIME_ALLOWED = '#^(font/[a-z0-9.+-]+|application/(font-[a-z0-9.+-]+|x-font-[a-z0-9.+-]+|vnd\.ms-opentype|octet-stream))$#i';

    /** What a browser's claimed type may NOT be. */
    private const CLIENT_MIME_REFUSED = '#^(text|image|audio|video|multipart|message|model)/|php|javascript|ecmascript|html|xml|json|zip|compressed|gzip|tar|rar|7z|pdf|msdownload|executable|x-sh|x-msi#i';

    private const SFNT_TRUETYPE = ["\x00\x01\x00\x00", 'true'];
    private const SFNT_CFF = 'OTTO';

    /** Without these a font cannot be rendered; OTS refuses it too. */
    private const REQUIRED_TABLES = ['cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name'];

    private const HEAD_MAGIC = 0x5F0F3CF5;

    /** The WOFF2 "known table" list (spec §5.1): a flags byte of 0-62 names one of these. */
    private const WOFF2_KNOWN_TAGS = [
        'cmap', 'head', 'hhea', 'hmtx', 'maxp', 'name', 'OS/2', 'post', 'cvt ', 'fpgm', 'glyf', 'loca', 'prep', 'CFF ', 'VORG', 'EBDT',
        'EBLC', 'gasp', 'hdmx', 'kern', 'LTSH', 'PCLT', 'VDMX', 'vhea', 'vmtx', 'BASE', 'GDEF', 'GPOS', 'GSUB', 'EBSC', 'JSTF', 'MATH',
        'CBDT', 'CBLC', 'COLR', 'CPAL', 'SVG ', 'sbix', 'acnt', 'avar', 'bdat', 'bloc', 'bsln', 'cvar', 'fdsc', 'feat', 'fmtx', 'fvar',
        'gvar', 'hsty', 'just', 'lcar', 'mort', 'morx', 'opbd', 'prop', 'trak', 'Zapf', 'Silf', 'Glat', 'Gloc', 'Feat', 'Sill',
    ];

    /** A real font has a few dozen tables at most; this only bounds the loop. */
    private const MAX_TABLES = 128;

    /**
     * Checks one entry of $_FILES and returns the format it really is (one
     * of the FORMATS keys; the extension the stored file gets).
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $file
     * @param bool $requireUploadedFile false only for a file that did not
     *        arrive through PHP's upload machinery (tests)
     * @throws \RuntimeException with a Dutch message for the administrator, naming the file
     */
    public function inspectUpload(array $file, bool $requireUploadedFile = true): string
    {
        $name = self::displayName((string) ($file['name'] ?? ''));
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException(self::t('fonts.error_no_file'));
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException(self::t('fonts.error_too_large', ['file' => $name, 'max' => self::maxMegabytes()]));
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::t('fonts.error_upload_failed', ['file' => $name]));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName) || ($requireUploadedFile && !is_uploaded_file($tmpName))) {
            throw new \RuntimeException(self::t('fonts.error_upload_failed', ['file' => $name]));
        }

        return $this->inspectFile($tmpName, $name, (string) ($file['type'] ?? ''));
    }

    /**
     * The same checks for a file on disk: steps 1-6 of the class docblock,
     * without the upload bookkeeping.
     *
     * @throws \RuntimeException
     */
    public function inspectFile(string $path, string $clientName, string $clientType = ''): string
    {
        $name = self::displayName($clientName);
        $size = (int) @filesize($path);

        if ($size <= 0) {
            throw new \RuntimeException(self::t('fonts.error_empty', ['file' => $name]));
        }

        if ($size > self::MAX_BYTES) {
            throw new \RuntimeException(self::t('fonts.error_too_large', ['file' => $name, 'max' => self::maxMegabytes()]));
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::FORMATS)) {
            throw new \RuntimeException(self::t('fonts.error_extension', ['file' => $name]));
        }

        if (trim($clientType) !== '' && preg_match(self::CLIENT_MIME_REFUSED, trim($clientType)) === 1) {
            throw new \RuntimeException(self::t('fonts.error_not_a_font', ['file' => $name]));
        }

        $serverType = self::serverMime($path);
        if ($serverType !== null && preg_match(self::SERVER_MIME_ALLOWED, $serverType) !== 1) {
            throw new \RuntimeException(self::t('fonts.error_not_a_font', ['file' => $name]));
        }

        $bytes = (string) @file_get_contents($path);
        if (strlen($bytes) !== $size) {
            throw new \RuntimeException(self::t('fonts.error_upload_failed', ['file' => $name]));
        }

        $format = self::formatOf($bytes);
        if ($format === null) {
            throw new \RuntimeException(self::t('fonts.error_not_a_font', ['file' => $name]));
        }

        $sfntExtension = in_array($extension, ['ttf', 'otf'], true);
        $sfntFormat = in_array($format, ['ttf', 'otf'], true);
        if ($format !== $extension && !($sfntExtension && $sfntFormat)) {
            throw new \RuntimeException(self::t('fonts.error_wrong_extension', ['file' => $name, 'format' => strtoupper($format)]));
        }

        if (!self::structureIsSound($bytes, $format)) {
            throw new \RuntimeException(self::t('fonts.error_damaged', ['file' => $name]));
        }

        return $format;
    }

    /**
     * The container the bytes are, or null for anything else — including a
     * TrueType collection, which a web page cannot use.
     */
    public static function formatOf(string $bytes): ?string
    {
        $signature = substr($bytes, 0, 4);

        return match (true) {
            $signature === 'wOF2' => 'woff2',
            $signature === 'wOFF' => 'woff',
            $signature === self::SFNT_CFF => 'otf',
            in_array($signature, self::SFNT_TRUETYPE, true) => 'ttf',
            default => null,
        };
    }

    public static function maxMegabytes(): int
    {
        return (int) round(self::MAX_BYTES / (1024 * 1024));
    }

    /**
     * The original filename, reduced to something safe to PRINT (and to
     * read an extension from). Never a path: directory parts, control
     * characters and anything past 255 characters are gone.
     */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'lettertype' : mb_substr($name, 0, 255);
    }

    private static function structureIsSound(string $bytes, string $format): bool
    {
        return match ($format) {
            'woff2' => self::woff2IsSound($bytes),
            'woff' => self::woffIsSound($bytes),
            default => self::sfntIsSound($bytes),
        };
    }

    /** A plain TTF/OTF: the table directory and every record inside the file. */
    private static function sfntIsSound(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 12) {
            return false;
        }

        $numTables = self::u16($bytes, 4);
        $directoryEnd = 12 + 16 * $numTables;
        if ($numTables < 1 || $numTables > self::MAX_TABLES || $directoryEnd > $length) {
            return false;
        }

        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + 16 * $i;
            $tag = substr($bytes, $record, 4);
            $offset = self::u32($bytes, $record + 8);
            $size = self::u32($bytes, $record + 12);

            if (!self::isTag($tag) || isset($tables[$tag]) || $offset < $directoryEnd || $offset + $size > $length) {
                return false;
            }

            $tables[$tag] = [$offset, $size];
        }

        if (!self::hasRequiredTables(array_keys($tables), substr($bytes, 0, 4) === self::SFNT_CFF)) {
            return false;
        }

        [$headOffset, $headSize] = $tables['head'];

        return $headSize >= 54 && self::u32($bytes, $headOffset + 12) === self::HEAD_MAGIC;
    }

    /** WOFF 1.0: its own header, and a zlib-compressed copy of each table. */
    private static function woffIsSound(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 44) {
            return false;
        }

        $flavor = substr($bytes, 4, 4);
        $numTables = self::u16($bytes, 12);
        $directoryEnd = 44 + 20 * $numTables;

        if (!self::isSfntFlavor($flavor)
            || self::u32($bytes, 8) !== $length
            || $numTables < 1 || $numTables > self::MAX_TABLES
            || self::u16($bytes, 14) !== 0
            || $directoryEnd > $length
            || self::u32($bytes, 16) < 12 + 16 * $numTables
        ) {
            return false;
        }

        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $entry = 44 + 20 * $i;
            $tag = substr($bytes, $entry, 4);
            $offset = self::u32($bytes, $entry + 4);
            $compressed = self::u32($bytes, $entry + 8);
            $original = self::u32($bytes, $entry + 12);

            if (!self::isTag($tag) || isset($tables[$tag]) || $offset < $directoryEnd || $compressed < 1
                || $compressed > $original || $offset + $compressed > $length
            ) {
                return false;
            }

            $tables[$tag] = [$offset, $compressed, $original];
        }

        if (!self::hasRequiredTables(array_keys($tables), $flavor === self::SFNT_CFF)) {
            return false;
        }

        [$offset, $compressed, $original] = $tables['head'];
        $head = substr($bytes, $offset, $compressed);
        if ($compressed < $original) {
            $head = @gzuncompress($head, 1024);
            if (!is_string($head) || strlen($head) !== $original) {
                return false;
            }
        }

        return strlen($head) >= 54 && self::u32($head, 12) === self::HEAD_MAGIC;
    }

    /**
     * WOFF2: header, the variable-length table directory, and the one
     * Brotli stream after it. The stream itself is not decompressed (see the
     * class docblock); its declared size must fit the file.
     */
    private static function woff2IsSound(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 48) {
            return false;
        }

        $flavor = substr($bytes, 4, 4);
        $numTables = self::u16($bytes, 12);
        $compressedSize = self::u32($bytes, 20);

        if (!self::isSfntFlavor($flavor)
            || self::u32($bytes, 8) !== $length
            || $numTables < 1 || $numTables > self::MAX_TABLES
            || self::u16($bytes, 14) !== 0
            || self::u32($bytes, 16) < 12 + 16 * $numTables
            || $compressedSize < 1
        ) {
            return false;
        }

        $position = 48;
        $tags = [];
        for ($i = 0; $i < $numTables; $i++) {
            if ($position >= $length) {
                return false;
            }

            $flags = ord($bytes[$position++]);
            $tagIndex = $flags & 0x3F;

            if ($tagIndex === 63) {
                $tag = substr($bytes, $position, 4);
                $position += 4;
            } else {
                $tag = self::WOFF2_KNOWN_TAGS[$tagIndex] ?? '';
            }

            if (!self::isTag($tag) || isset($tags[$tag])) {
                return false;
            }

            if (self::base128($bytes, $position) === null) {
                return false;
            }

            // glyf and loca carry a transform length unless transform 3
            // (none); every other table only for a non-zero transform.
            $transform = ($flags >> 6) & 0x03;
            $hasTransformLength = in_array($tag, ['glyf', 'loca'], true) ? $transform === 0 : $transform !== 0;
            if ($hasTransformLength && self::base128($bytes, $position) === null) {
                return false;
            }

            $tags[$tag] = true;
        }

        if ($position + $compressedSize > $length) {
            return false;
        }

        return self::hasRequiredTables(array_keys($tags), $flavor === self::SFNT_CFF);
    }

    /** @param list<string> $tags */
    private static function hasRequiredTables(array $tags, bool $cff): bool
    {
        foreach (self::REQUIRED_TABLES as $required) {
            if (!in_array($required, $tags, true)) {
                return false;
            }
        }

        if ($cff) {
            return in_array('CFF ', $tags, true) || in_array('CFF2', $tags, true);
        }

        return in_array('glyf', $tags, true) && in_array('loca', $tags, true);
    }

    private static function isSfntFlavor(string $flavor): bool
    {
        return $flavor === self::SFNT_CFF || in_array($flavor, self::SFNT_TRUETYPE, true);
    }

    /** Four printable ASCII characters, like every OpenType tag. */
    private static function isTag(string $tag): bool
    {
        return strlen($tag) === 4 && preg_match('/^[\x20-\x7E]{4}$/', $tag) === 1;
    }

    /**
     * A WOFF2 UIntBase128 at $position, advancing it; null when it is not
     * one (a leading zero byte, more than five bytes, an overflow).
     */
    private static function base128(string $bytes, int &$position): ?int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            if ($position >= strlen($bytes)) {
                return null;
            }

            $byte = ord($bytes[$position++]);
            if ($i === 0 && $byte === 0x80) {
                return null;
            }

            if (($value & 0xFE000000) !== 0) {
                return null;
            }

            $value = ($value << 7) | ($byte & 0x7F);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function u16(string $bytes, int $offset): int
    {
        $part = substr($bytes, $offset, 2);

        return strlen($part) === 2 ? (int) unpack('n', $part)[1] : -1;
    }

    private static function u32(string $bytes, int $offset): int
    {
        $part = substr($bytes, $offset, 4);

        return strlen($part) === 4 ? (int) unpack('N', $part)[1] : -1;
    }

    private static function serverMime(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) && $type !== '' ? $type : null;
    }

    /** @param array<string, string|int> $replace */
    private static function t(string $key, array $replace = []): string
    {
        return AdminTranslator::trans($key, $replace);
    }
}
