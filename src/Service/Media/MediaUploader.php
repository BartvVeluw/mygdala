<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Service\ImageOptimizer;

/**
 * Validates, optimizes and stores a file for the Media Library, and reports
 * everything the `media` row needs to know about it.
 *
 * SAME SECURITY MODEL AS THE REST OF THIS PROJECT, on purpose. Nothing new
 * is invented here: the rules are the ones
 * App\Service\SectionImageUploader has enforced since the CMS got its first
 * upload field, because they are the ones that have been reviewed.
 *
 *   - the file must really be an image, decided by its HEADER
 *     (getimagesize()), never by its name, its extension or the Content-Type
 *     the browser sent. A .php renamed to .jpg fails here;
 *   - the stored filename is 32 random hex characters plus the extension
 *     that belongs to the type actually found. A client-supplied name never
 *     becomes a filesystem name, so there is no traversal, no overwrite of
 *     an existing file, and no way to place a name the web server would
 *     execute;
 *   - is_uploaded_file() must agree it came from this request;
 *   - 25 MB, the same cap as the section uploader, comfortably inside
 *     docker/php-uploads.ini;
 *   - the file is written to ONE folder this class is the only writer of,
 *     and chmod 0644 so it is readable and never executable.
 *
 * NO SVG. Same reasoning as App\Service\BrandingAssetUploader, and it
 * matters more here: an SVG is a document that can carry script, and one
 * served from this site's own origin is stored XSS the moment somebody opens
 * its URL. This project has no SVG sanitizer, so the library will not accept
 * one. An SVG that is already deployed and already referenced keeps working
 * and can even be ADOPTED as a media item — the library then owns its
 * identity and its alt text, not its creation. That is exactly the case of
 * this site's own logo.
 *
 * OPTIMIZATION. JPEG, PNG and WebP go through App\Service\ImageOptimizer,
 * which is where this project's "safe to re-encode" rules already live: EXIF
 * orientation corrected, long edge capped, metadata dropped by virtue of
 * going through GD, plus its decompression-bomb guards. It also hands back a
 * thumbnail from the same decode, which is what keeps the admin grid from
 * loading full-size photos. GIF is stored verbatim and gets no thumbnail:
 * GD would flatten an animated one to a single frame, and silently
 * destroying an animation is worse than a slightly heavier grid cell.
 */
class MediaUploader
{
    /** The same ceiling App\Service\SectionImageUploader uses. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** Image types the library accepts, and the extension each is stored as. */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF => 'gif',
    ];

    /**
     * The extensions a file's own name may carry, and the image type each one
     * promises.
     *
     * The name never decides what a file IS — the header does, in store() —
     * but it does decide what the item is CALLED: a file whose extension
     * matches what it really is keeps that extension in its name, and one
     * whose extension is wrong is named after what it really is
     * (nameExtension()).
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg' => IMAGETYPE_JPEG,
        'jpeg' => IMAGETYPE_JPEG,
        // What some Windows browsers call a JPEG they save; it is an ordinary JPEG.
        'jfif' => IMAGETYPE_JPEG,
        'png' => IMAGETYPE_PNG,
        'webp' => IMAGETYPE_WEBP,
        'gif' => IMAGETYPE_GIF,
    ];

    private const MIME_FOR_TYPE = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF => 'image/gif',
    ];

    /**
     * The library's own folder. NEW uploads live here and nowhere else;
     * legacy files adopted in place keep their original path. MEDIA.md
     * explains why the two coexist.
     */
    public const PUBLIC_PREFIX = 'assets/media/';
    public const THUMBNAIL_PREFIX = 'assets/media/thumbs/';

    private string $root;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 3) . '/';
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     *
     * @return array{path:string, thumbnail_path:string|null, original_filename:string, name_extension:string, mime_type:string, width:int, height:int, file_size:int, checksum:string}
     *
     * @throws \RuntimeException with a Dutch, user-facing message
     */
    public function store(array $file): array
    {
        $tmpName = $this->validate($file);

        $info = @getimagesize($tmpName);

        if ($info === false || !isset(self::ALLOWED_TYPES[$info[2]])) {
            throw new \RuntimeException('Alleen JPG, PNG, WEBP of GIF afbeeldingen zijn toegestaan.');
        }

        $imageType = (int) $info[2];
        $extension = self::ALLOWED_TYPES[$imageType];

        $this->ensureDirectory($this->root . self::PUBLIC_PREFIX);

        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = self::PUBLIC_PREFIX . $filename;
        $destination = $this->root . $path;

        // Suppressed deliberately: a permissions problem must come back as
        // the message below rather than as a warning printed into the
        // response body, which would also break the PRG redirect that follows.
        if (!$this->moveUploadedFile($tmpName, $destination)) {
            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen. Controleer of de map ' . self::PUBLIC_PREFIX . ' schrijfbaar is.');
        }

        @chmod($destination, 0644);

        $thumbnailPath = null;

        if (ImageOptimizer::isSupported($imageType)) {
            try {
                $thumbnailPath = $this->optimizeInPlace($destination, $filename, $imageType);
            } catch (\RuntimeException $e) {
                // The optimizer's failures are this project's safety boundary
                // against a decompression bomb and against content that
                // slipped past the lighter header check. Rejected outright,
                // never kept unprocessed.
                $this->deleteFile($path);

                throw $e;
            }
        }

        $size = @getimagesize($destination);

        return [
            'path' => $path,
            'thumbnail_path' => $thumbnailPath,
            // Kept for humans and for search only. It has already been
            // stripped of any directory component and never touches the
            // filesystem.
            'original_filename' => $this->safeOriginalName($file['name'] ?? ''),
            // The extension the item's NAME carries; MediaService names it.
            // Not a path: the stored file keeps the extension of its type.
            'name_extension' => $this->nameExtension((string) ($file['name'] ?? ''), $imageType),
            'mime_type' => (string) ($size['mime'] ?? self::MIME_FOR_TYPE[$imageType] ?? ''),
            'width' => (int) ($size[0] ?? 0),
            'height' => (int) ($size[1] ?? 0),
            'file_size' => (int) (filesize($destination) ?: 0),
            'checksum' => (string) hash_file('sha256', $destination),
        ];
    }

    /**
     * The SHA-256 of an upload BEFORE it is stored, so a caller can notice
     * that this exact file is already in the library and reuse the existing
     * item instead of writing a second copy of the same bytes.
     *
     * Note that it hashes what the browser sent, while a stored item's
     * checksum is of the OPTIMIZED result. Those differ for a photo that the
     * optimizer resized — which is the honest answer: an image that gets
     * re-encoded is not byte-identical to anything already stored, and
     * claiming otherwise would hand back an item with different pixels. The
     * match therefore fires for exactly the case it is meant for: the same
     * already-web-sized file uploaded twice.
     */
    public function checksumOf(array $file): ?string
    {
        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !$this->isUploadedFile($tmpName)) {
            return null;
        }

        $hash = @hash_file('sha256', $tmpName);

        return $hash === false ? null : $hash;
    }

    /**
     * Removes a file this uploader created — and ONLY one it created. A path
     * outside the library's own folder is a no-op, so deleting a media item
     * that was adopted in place can never remove a file the library did not
     * put there. That is the same rule
     * App\Service\SectionImageUploader::delete() follows, for the same reason.
     */
    public function deleteFile(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $path = ltrim($path, '/');

        $prefix = null;
        foreach ([self::THUMBNAIL_PREFIX, self::PUBLIC_PREFIX] as $candidate) {
            if (str_starts_with($path, $candidate)) {
                $prefix = $candidate;
                break;
            }
        }

        if ($prefix === null) {
            return false;
        }

        // basename() strips any traversal component, so this can only ever
        // resolve to a file directly inside the folder it named.
        $file = $this->root . $prefix . basename($path);

        return is_file($file) ? @unlink($file) : false;
    }

    /** Whether this path is one the library itself created and therefore owns. */
    public static function ownsPath(?string $path): bool
    {
        $path = ltrim((string) $path, '/');

        return str_starts_with($path, self::PUBLIC_PREFIX);
    }

    /**
     * "Did this file really arrive with this request?" — is_uploaded_file(),
     * and the ONE thing a test may answer differently.
     *
     * It is a seam rather than an option because it has no legitimate second
     * answer in production: any caller that could switch it off could also
     * hand over an arbitrary server path, which is exactly what this check
     * exists to prevent. A test subclass overrides this one method and
     * inherits every real rule — the header check, the random filename, the
     * size cap, the single writable folder — so what the suite proves is what
     * the site does. See Tests\Support\TestMediaUploader.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /**
     * The move itself, and the second half of the same seam:
     * move_uploaded_file() refuses anything is_uploaded_file() refuses, so a
     * test that overrides only the first would fail here instead. Suppressed
     * on purpose — a permissions problem must come back as the Dutch message
     * above rather than as a warning printed into the response body, which
     * would also break the PRG redirect that follows.
     */
    protected function moveUploadedFile(string $from, string $to): bool
    {
        return @move_uploaded_file($from, $to);
    }

    /**
     * @return string the tmp_name, once every reason to refuse is ruled out
     *
     * @throws \RuntimeException
     */
    private function validate(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \RuntimeException('Geen bestand geselecteerd.');
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('Afbeelding is te groot (max. 25 MB).');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Uploaden van de afbeelding is mislukt. Probeer het opnieuw.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !$this->isUploadedFile($tmpName)) {
            throw new \RuntimeException('Ongeldige upload.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('Afbeelding is te groot (max. 25 MB).');
        }

        return $tmpName;
    }

    /**
     * Rewrites the just-stored file with the optimized bytes and writes the
     * thumbnail next to it. Same path for the full image, so the media row's
     * `path` is decided before any of this and cannot end up pointing at a
     * file that was never written.
     *
     * @return string|null the thumbnail's stored path, or null when it could not be written
     */
    private function optimizeInPlace(string $destination, string $filename, int $imageType): ?string
    {
        $processed = ImageOptimizer::process($destination, $imageType);

        if (@file_put_contents($destination, $processed['full']['bytes']) === false) {
            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen.');
        }

        @chmod($destination, 0644);

        $thumbnailDir = $this->root . self::THUMBNAIL_PREFIX;

        if (!$this->ensureDirectory($thumbnailDir)) {
            // A missing thumbnail is a display detail, not a failed upload:
            // App\Service\Media\MediaItem falls back to the full image and
            // the admin sizes it with CSS.
            return null;
        }

        $thumbnailPath = self::THUMBNAIL_PREFIX . $filename;

        if (@file_put_contents($this->root . $thumbnailPath, $processed['thumbnail']['bytes']) === false) {
            return null;
        }

        @chmod($this->root . $thumbnailPath, 0644);

        return $thumbnailPath;
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0755, true) && is_dir($directory);
    }

    /**
     * The extension an item's name gets: the one the editor's file had when
     * it matches what the file really is ("zomer.jpeg" stays .jpeg), and the
     * one its type is stored under when it does not (a PNG called "logo.jpg"
     * becomes "logo.png").
     */
    private function nameExtension(string $clientName, int $imageType): string
    {
        $claimed = MediaFilename::extension(basename(str_replace('\\', '/', $clientName)));

        return (self::ALLOWED_EXTENSIONS[$claimed] ?? null) === $imageType
            ? $claimed
            : self::ALLOWED_TYPES[$imageType];
    }

    /**
     * The client's own filename, kept only as a label. Any directory
     * component is stripped, control characters are removed and the result
     * is length-capped — it is stored text, never a path.
     */
    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return mb_substr($name, 0, 255);
    }
}
