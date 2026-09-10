<?php

namespace App\Service;

/**
 * Resizes/re-encodes a newly uploaded Portfolio image with GD: corrects JPEG
 * EXIF orientation, caps the long edge at MAX_LONG_EDGE (never upscales),
 * re-encodes at a sensible web-quality setting, and derives a small CMS
 * preview thumbnail from the same decode. Re-encoding through GD also
 * strips EXIF/ICC metadata for free — a GD image resource never carries
 * that data through in the first place, so no separate "strip metadata"
 * step is needed.
 *
 * Pure image processing only — no filesystem/path conventions and no
 * knowledge of "assets/images/sections/" (that's
 * App\Service\PortfolioImageProcessor's job, which calls this only after
 * App\Service\SectionImageUploader has already validated and safely stored
 * the upload: real image content via magic bytes, random filename, 25 MB
 * cap). The dimension/pixel-count guard below is a second, independent
 * line of defense against a small file that decodes into an enormous pixel
 * buffer (a "decompression bomb") — not the primary upload security
 * boundary, which stays SectionImageUploader's job.
 */
class ImageOptimizer
{
    public const MAX_LONG_EDGE = 2560;
    public const THUMBNAIL_LONG_EDGE = 480;

    // Hard safety caps against decompression bombs: a file can pass the
    // 25 MB SectionImageUploader size check yet still decode into a pixel
    // buffer many times larger than the file itself (a highly-compressed
    // PNG especially). 40 megapixels comfortably covers real camera/phone
    // photos while keeping the resulting GD truecolor buffer (roughly
    // width * height * 4 bytes, doubled again for GD's own working
    // memory) within the memory budget this class ever asks for.
    private const MAX_SOURCE_PIXELS = 40_000_000;
    private const MAX_SOURCE_DIMENSION = 15_000; // per side — defense-in-depth alongside the pixel cap

    // Requested memory_limit ceiling when a specific image's estimated need
    // (see estimateMemoryNeed()) exceeds the current limit. Sized to cover
    // the worst case under MAX_SOURCE_PIXELS above with headroom — but
    // ensureMemoryBudget() never trusts that the request actually succeeds
    // (see its docblock).
    private const MEMORY_LIMIT_BUDGET = '320M';
    private const BYTES_PER_PIXEL_ESTIMATE = 4; // truecolor RGBA buffer
    private const MEMORY_SAFETY_MULTIPLIER = 1.8; // GD decode/working-buffer overhead
    private const MEMORY_BASELINE_BYTES = 8 * 1024 * 1024; // interpreter/autoloader/etc, already using part of memory_limit

    // JPEG/WebP: 82-88 is the sensible "high visual quality without huge
    // files" range for real photos — 85 sits in the middle.
    private const JPEG_QUALITY = 85;
    private const WEBP_QUALITY = 85;
    private const PNG_COMPRESSION = 6; // 0 (none) - 9 (max, slower) — lossless either way

    public static function isSupported(int $imageType): bool
    {
        return in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    /**
     * @return array{
     *     full: array{bytes:string, width:int, height:int},
     *     thumbnail: array{bytes:string, width:int, height:int}
     * }
     * @throws \RuntimeException with a Dutch, user-facing message
     */
    public static function process(string $sourcePath, int $imageType): array
    {
        // Defensive, not just theoretical: this class assumes GD is
        // installed (it is in Docker — see docker/Dockerfile — but that
        // isn't guaranteed on every hosting environment, e.g. before the
        // GD extension is confirmed enabled on Vimexx). Without this check,
        // a missing GD would make decode() call an undefined function,
        // which PHP treats as an uncaught fatal error, not a catchable
        // exception — @-suppression does not protect against that.
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Afbeelding kan niet worden geoptimaliseerd: de GD-afbeeldingsbibliotheek ontbreekt op deze server.');
        }

        if (!self::isSupported($imageType)) {
            throw new \RuntimeException('Dit afbeeldingstype kan niet worden geoptimaliseerd.');
        }

        $size = @getimagesize($sourcePath);

        if ($size === false) {
            throw new \RuntimeException('Afbeelding kon niet worden gelezen.');
        }

        [$width, $height] = $size;

        if ($width < 1 || $height < 1 || $width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION) {
            throw new \RuntimeException('Afbeelding heeft ongeldige of extreme afmetingen.');
        }

        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            throw new \RuntimeException('Afbeelding heeft te veel pixels om veilig te verwerken.');
        }

        // Decoding a large-but-within-limits photo (e.g. a 40MP source) can
        // need well over PHP's default memory_limit. This asks to raise it
        // for this request's script execution only (never lowered, never
        // global) — but never assumes that succeeds; see the method
        // docblock for why a hosting environment may refuse or cap it.
        self::ensureMemoryBudget(self::estimateMemoryNeed($width, $height));

        $image = self::decode($sourcePath, $imageType);
        $full = null;
        $thumbnail = null;

        try {
            if ($imageType === IMAGETYPE_JPEG) {
                $image = self::applyExifOrientation($image, $sourcePath);
            }

            $full = self::resizeWithin($image, self::MAX_LONG_EDGE);
            $thumbnail = self::resizeWithin($full, self::THUMBNAIL_LONG_EDGE);

            return [
                'full' => [
                    'bytes' => self::encode($full, $imageType),
                    'width' => imagesx($full),
                    'height' => imagesy($full),
                ],
                'thumbnail' => [
                    'bytes' => self::encode($thumbnail, $imageType),
                    'width' => imagesx($thumbnail),
                    'height' => imagesy($thumbnail),
                ],
            ];
        } finally {
            foreach ([$image, $full, $thumbnail] as $resource) {
                if ($resource instanceof \GdImage) {
                    imagedestroy($resource);
                }
            }
        }
    }

    private static function decode(string $path, int $imageType): \GdImage
    {
        $image = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Afbeelding kon niet worden verwerkt.');
        }

        return $image;
    }

    /**
     * Standard EXIF Orientation table (1-8). 3/6/8 (plain 180/90/270
     * rotation) cover virtually every real phone/camera photo; 2/4/5/7
     * (mirrored variants) are rare in practice but handled too since the
     * combination is cheap once the rotation case exists. Returns a new
     * GdImage when a rotation happened (imagerotate() never mutates its
     * input) — the caller's $image variable is reassigned to it and the
     * original is destroyed here to avoid leaking it. imageflip() mutates
     * in place, so no extra resource is created for the mirror-only step.
     */
    private static function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        if ($orientation === 1) {
            return $image;
        }

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        // GD's imagerotate() angle is anticlockwise for positive values, so
        // "rotate 90 CW" (orientation 6) is angle -90, etc.
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated instanceof \GdImage) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }

    /**
     * Resamples $image so its long edge is at most $maxLongEdge, preserving
     * aspect ratio and never upscaling (scale is capped at 1.0). Always
     * returns a fresh canvas — even when no resize is needed — so the
     * recompression pass in encode() still applies (part of "optimize for
     * web use", not just resizing). Transparency-safe: the destination
     * canvas starts fully transparent and alpha blending is disabled during
     * the copy so source alpha values are copied as-is rather than
     * flattened onto a background (matters for PNG; harmless no-op for
     * opaque JPEG/WebP source content).
     */
    private static function resizeWithin(\GdImage $image, int $maxLongEdge): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longEdge = max($width, $height);

        $scale = $longEdge > $maxLongEdge ? $maxLongEdge / $longEdge : 1.0;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    private static function encode(\GdImage $image, int $imageType): string
    {
        ob_start();
        $ok = match ($imageType) {
            IMAGETYPE_JPEG => imagejpeg($image, null, self::JPEG_QUALITY),
            IMAGETYPE_PNG => imagepng($image, null, self::PNG_COMPRESSION),
            IMAGETYPE_WEBP => imagewebp($image, null, self::WEBP_QUALITY),
            default => false,
        };
        $bytes = ob_get_clean();

        if (!$ok || $bytes === false || $bytes === '') {
            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen.');
        }

        return $bytes;
    }

    /**
     * Conservative estimate of the peak memory a decode+resample of an
     * image this size needs: a truecolor RGBA buffer (4 bytes/pixel) with a
     * safety multiplier for GD's own internal decode/working buffers, plus
     * a fixed baseline for whatever the interpreter/autoloader/already-
     * loaded code already occupies of memory_limit's budget before this
     * function even runs. Deliberately generous — under-estimating here
     * would defeat the point of ensureMemoryBudget() below.
     */
    private static function estimateMemoryNeed(int $width, int $height): int
    {
        return (int) ($width * $height * self::BYTES_PER_PIXEL_ESTIMATE * self::MEMORY_SAFETY_MULTIPLIER)
            + self::MEMORY_BASELINE_BYTES;
    }

    /**
     * Raises memory_limit for this request only when the current limit
     * can't cover $neededBytes — and, critically, never assumes that
     * request succeeds. A shared-hosting environment (Vimexx included) may
     * run with ini_set() disabled entirely (disable_functions), or honor it
     * but silently cap the result below what was asked for (a hard
     * host-level ceiling). Either way this re-reads the actual resulting
     * memory_limit afterwards rather than trusting the call, and rejects
     * the image cleanly if the real, effective limit still can't cover it
     * — the alternative would be proceeding anyway and risking a raw
     * "Allowed memory size exhausted" fatal error instead of this class's
     * normal, user-facing RuntimeException.
     *
     * @throws \RuntimeException with a Dutch, user-facing message when the effective limit is insufficient
     */
    private static function ensureMemoryBudget(int $neededBytes): void
    {
        $current = self::iniBytes((string) ini_get('memory_limit'));

        if ($current === -1) {
            return; // already unlimited
        }

        if ($current < $neededBytes && function_exists('ini_set')) {
            @ini_set('memory_limit', self::MEMORY_LIMIT_BUDGET);
            $current = self::iniBytes((string) ini_get('memory_limit'));
        }

        if ($current !== -1 && $current < $neededBytes) {
            throw new \RuntimeException('Afbeelding kan niet worden verwerkt: onvoldoende servergeheugen beschikbaar voor deze afmetingen.');
        }
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
