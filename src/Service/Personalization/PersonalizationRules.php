<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * The single registry of every rule, limit and coordinate convention product
 * personalization has — deliberately one class, in the spirit of
 * App\Service\AdminPermissions and App\Service\Shipping\ShippingProfile.
 *
 * Nothing outside this class may decide what a valid engraving area or a
 * valid content transform is. The CMS editor, the public product page, the
 * checkout validator and the admin's order-detail reconstruction all read
 * their numbers from here, so the rectangle an administrator drags, the
 * preview a customer sees, the values the server accepts and the picture the
 * owner gets back in the CMS can never drift apart.
 *
 * ## Coordinate systems (both relative — never pixels)
 *
 * AREA (the engraving zone) is expressed as percentages of the personalization
 * PREVIEW IMAGE's own box: `area_x`/`area_y` are the top-left corner,
 * `area_width`/`area_height` the size, all 0-100. The preview image scales
 * responsively; the percentages do not, so the zone always covers the same
 * physical part of the product.
 *
 * TRANSFORM (where the customer put their content INSIDE that zone) is
 * expressed as fractions of the zone box: `x`/`y` in 0..1 are the CENTRE of
 * the content, so 0.5/0.5 is dead centre. `scale` is a multiplier on the base
 * size below, `rotation` is degrees. Clamping x/y to 0..1 keeps the content's
 * centre inside the zone; the zone element itself also clips its overflow, so
 * customer content can never be dragged outside the administrator's area.
 *
 * ## Base sizes
 *
 * A scale of 1.0 means: text is rendered at TEXT_BASE_HEIGHT_RATIO of the
 * ZONE's height, an image at IMAGE_BASE_WIDTH_RATIO of the ZONE's width. Both
 * are therefore resolution independent — the same stored transform produces
 * the same picture on a phone, on a desktop and in the CMS.
 */
class PersonalizationRules
{
    /**
     * The key a product's single implicit zone/view carries. Phase 1 wrote it
     * on every zone and every order row, and the Phase 2 migration gave every
     * existing product a view under the same key, so it stays the name of
     * "the one that was always there".
     */
    public const DEFAULT_ZONE_KEY = 'default';
    public const DEFAULT_VIEW_KEY = 'default';

    /**
     * Sanity ceilings, not business rules. A product with fifty engraving
     * zones is a mistake or an attack, and either way the CMS should say so
     * rather than render an unusable editor.
     */
    public const MAX_VIEWS_PER_PRODUCT = 8;
    public const MAX_ZONES_PER_VIEW = 12;

    /**
     * How a configured product may be BOUGHT.
     *
     *   'optional' — personalization is an extra. The customer may add the
     *                product plain, exactly as they always could.
     *   'required' — the product only exists as a personalized product. The
     *                customer must complete every required zone before it can
     *                go in the cart, and the ordinary add-to-cart path is not
     *                a way around that: PersonalizationValidator rejects an
     *                empty personalization for such a product server-side,
     *                whatever the browser sent.
     *
     * 'optional' is the default and is what every product configured before
     * this setting existed keeps, so adding the setting changed nothing.
     */
    public const PURCHASE_OPTIONAL = 'optional';
    public const PURCHASE_REQUIRED = 'required';

    /** @return list<string> */
    public static function purchaseModes(): array
    {
        return [self::PURCHASE_OPTIONAL, self::PURCHASE_REQUIRED];
    }

    /**
     * Anything stored or submitted, reduced to one of the two real modes.
     * Unknown values resolve to 'optional' — the safe direction: a corrupted
     * value can only ever make a product buyable the way it always was,
     * never silently block an existing product's checkout.
     */
    public static function purchaseMode(mixed $value): string
    {
        return $value === self::PURCHASE_REQUIRED ? self::PURCHASE_REQUIRED : self::PURCHASE_OPTIONAL;
    }

    /** Content modes a zone can offer, derived from allow_text/allow_image. */
    public const MODE_TEXT = 'text';
    public const MODE_IMAGE = 'image';
    public const MODE_BOTH = 'both';

    /** A guard rail on what an administrator can type into a surcharge field. */
    public const MAX_SURCHARGE_CENTS = 100_000; // € 1.000,00

    /* ------------------------------------------------------------------ */
    /* Text                                                                */
    /* ------------------------------------------------------------------ */

    public const MIN_TEXT_LENGTH_SETTING = 1;
    public const MAX_TEXT_LENGTH_SETTING = 200;
    public const DEFAULT_TEXT_LENGTH_SETTING = 30;

    /* ------------------------------------------------------------------ */
    /* Uploads                                                             */
    /* ------------------------------------------------------------------ */

    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

    /**
     * Version 1 accepts PNG and JPEG only, both confirmed from the file's
     * actual contents. SVG is deliberately NOT supported: it is an XML
     * document that can carry scripts, external references and entity
     * expansion, and safely accepting one needs a sanitisation architecture
     * this project does not have yet. See MAIN.MD.
     *
     * @var array<int, array{0: string, 1: string}> IMAGETYPE_* => [extension, mime]
     */
    public const ALLOWED_UPLOAD_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
    ];

    public const MAX_UPLOAD_DIMENSION = 8000;   // px per side
    public const MAX_UPLOAD_PIXELS = 30_000_000;

    /**
     * How long an UNCLAIMED upload (uploaded, never ordered) is kept before
     * the opportunistic sweep may delete it. Generous on purpose: a customer
     * may leave a personalized product in their cart overnight.
     */
    public const UNCLAIMED_UPLOAD_TTL_HOURS = 72;

    /* ------------------------------------------------------------------ */
    /* Geometry                                                            */
    /* ------------------------------------------------------------------ */

    public const MIN_AREA_SIZE_PERCENT = 2.0;
    public const MIN_SCALE = 0.2;
    public const MAX_SCALE = 3.0;
    public const MAX_ROTATION = 180.0;

    /** A scale of 1.0 renders text at 30% of the zone's height. */
    public const TEXT_BASE_HEIGHT_RATIO = 0.30;
    /** A scale of 1.0 renders an image at 60% of the zone's width. */
    public const IMAGE_BASE_WIDTH_RATIO = 0.60;

    /**
     * The neutral transform: centred, unscaled, unrotated. Used as the
     * starting point in the customer editor, as the "reset" target, and
     * whenever a submitted transform is missing or unusable.
     *
     * @return array{x: float, y: float, scale: float, rotation: float}
     */
    public static function defaultTransform(): array
    {
        return ['x' => 0.5, 'y' => 0.5, 'scale' => 1.0, 'rotation' => 0.0];
    }

    /**
     * Clamps one submitted transform into the allowed range. Deliberately
     * clamping rather than rejecting: a value slightly out of range is a
     * rounding artefact of a drag on a real device, not an attack, and
     * refusing an order over it would be hostile. Anything that is not a
     * number at all falls back to the neutral value for that field, so a
     * hand-crafted payload can only ever produce a valid, centred result.
     *
     * `$allowRotation` false forces the angle to 0: a zone whose
     * administrator disabled rotation must come out unrotated no matter what
     * the request claims, rather than merely having its slider hidden.
     *
     * @param mixed $transform whatever the request sent
     * @return array{x: float, y: float, scale: float, rotation: float}
     */
    public static function normalizeTransform(mixed $transform, bool $allowRotation = true): array
    {
        $default = self::defaultTransform();

        if (!is_array($transform)) {
            return $default;
        }

        return [
            'x' => self::clampNumber($transform['x'] ?? null, 0.0, 1.0, $default['x']),
            'y' => self::clampNumber($transform['y'] ?? null, 0.0, 1.0, $default['y']),
            'scale' => self::clampNumber($transform['scale'] ?? null, self::MIN_SCALE, self::MAX_SCALE, $default['scale']),
            'rotation' => $allowRotation
                ? self::clampNumber($transform['rotation'] ?? null, -self::MAX_ROTATION, self::MAX_ROTATION, $default['rotation'])
                : 0.0,
        ];
    }

    /**
     * The full transform record stored with an order line: one entry per
     * content kind. Both are always present and always valid, so every reader
     * (cart, checkout, admin reconstruction) can index them without guarding.
     *
     * @return array{text: array{x: float, y: float, scale: float, rotation: float}, image: array{x: float, y: float, scale: float, rotation: float}}
     */
    public static function normalizeTransformSet(mixed $transforms, bool $allowRotation = true): array
    {
        $transforms = is_array($transforms) ? $transforms : [];

        return [
            'text' => self::normalizeTransform($transforms['text'] ?? null, $allowRotation),
            'image' => self::normalizeTransform($transforms['image'] ?? null, $allowRotation),
        ];
    }

    /**
     * Validates an engraving area submitted by the CMS. Unlike a customer
     * transform this one is REJECTED rather than clamped: an administrator
     * saving a nonsensical rectangle must be told, not silently corrected
     * into an area they never chose.
     *
     * @param array<string, mixed> $input raw x/y/width/height (strings from a form are fine)
     * @param list<string> $errors collected Dutch, admin-facing messages (appended to)
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function validateArea(array $input, array &$errors): array
    {
        $values = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $raw = $input[$key] ?? null;
            $raw = is_string($raw) ? trim(str_replace(',', '.', $raw)) : $raw;

            if ($raw === null || $raw === '' || !is_numeric($raw)) {
                $errors[] = 'Het personalisatiegebied is onvolledig of bevat een ongeldige waarde.';
                return ['x' => 25.0, 'y' => 35.0, 'width' => 50.0, 'height' => 30.0];
            }

            $values[$key] = round((float) $raw, 3);
        }

        if ($values['x'] < 0 || $values['y'] < 0) {
            $errors[] = 'Het personalisatiegebied mag niet buiten de afbeelding beginnen.';
        }

        if ($values['width'] < self::MIN_AREA_SIZE_PERCENT || $values['height'] < self::MIN_AREA_SIZE_PERCENT) {
            $errors[] = 'Het personalisatiegebied is te klein (minimaal '
                . rtrim(rtrim(number_format(self::MIN_AREA_SIZE_PERCENT, 1, ',', ''), '0'), ',') . '% breed en hoog).';
        }

        if ($values['x'] + $values['width'] > 100.0 || $values['y'] + $values['height'] > 100.0) {
            $errors[] = 'Het personalisatiegebied valt buiten de afbeelding.';
        }

        return $values;
    }

    /**
     * Turns a stored/validated area into the four percentages the preview
     * markup needs, guaranteed inside the image even for a row written before
     * a rule changed. Purely defensive; the CMS never stores anything else.
     *
     * @param array<string, mixed> $area
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function clampArea(array $area): array
    {
        $x = self::clampNumber($area['x'] ?? null, 0.0, 100.0, 25.0);
        $y = self::clampNumber($area['y'] ?? null, 0.0, 100.0, 35.0);
        $width = self::clampNumber($area['width'] ?? null, self::MIN_AREA_SIZE_PERCENT, 100.0, 50.0);
        $height = self::clampNumber($area['height'] ?? null, self::MIN_AREA_SIZE_PERCENT, 100.0, 30.0);

        if ($x + $width > 100.0) {
            $x = max(0.0, 100.0 - $width);
        }
        if ($y + $height > 100.0) {
            $y = max(0.0, 100.0 - $height);
        }

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    /**
     * The maximum text length an administrator may configure, normalised.
     * Out-of-range values are clamped rather than rejected because this is a
     * plain number field with an obvious intent.
     */
    public static function clampMaxTextLength(mixed $value): int
    {
        $value = is_string($value) ? trim($value) : $value;

        if (!is_numeric($value)) {
            return self::DEFAULT_TEXT_LENGTH_SETTING;
        }

        return (int) max(
            self::MIN_TEXT_LENGTH_SETTING,
            min(self::MAX_TEXT_LENGTH_SETTING, (int) round((float) $value))
        );
    }

    /**
     * True for a syntactically possible upload token. Checked before any
     * database lookup and before any filename is derived from it, so a token
     * can never carry a path component, a wildcard or anything but 32
     * lowercase hex characters.
     */
    public static function isValidUploadToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) === 1;
    }

    public static function newUploadToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /* ------------------------------------------------------------------ */
    /* Zone and view keys                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * A zone or view key ends up in an order row, in a URL parameter and in
     * a JSON payload, and it identifies one zone for the lifetime of every
     * order that used it. So it is a strict, boring identifier — never a
     * label, never anything a customer typed.
     */
    public static function isValidKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $key) === 1;
    }

    /**
     * Turns whatever the administrator typed into a usable key. Only used
     * when CREATING a zone or view: an existing key is never rewritten,
     * because order rows point at it.
     */
    public static function toKey(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? $value : '';
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : $value;
        $key = strtolower((string) ($ascii !== false ? $ascii : $value));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key === '' || !self::isValidKey($key)) {
            $key = $fallback;
        }

        return substr($key, 0, 32);
    }

    /* ------------------------------------------------------------------ */
    /* Zone content mode                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * What a zone actually offers, as one value the UI can switch on instead
     * of re-deriving two booleans everywhere. A zone that allows neither is
     * not a mode — it is a zone that should not be shown at all, which
     * ProductPersonalizationContent already filters out.
     */
    public static function contentMode(bool $allowText, bool $allowImage): string
    {
        if ($allowText && $allowImage) {
            return self::MODE_BOTH;
        }

        return $allowImage ? self::MODE_IMAGE : self::MODE_TEXT;
    }

    /* ------------------------------------------------------------------ */
    /* Surcharges                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * A zone's surcharge as submitted by the CMS, in whole cents. Rejected
     * rather than clamped when it is negative or absurd: an administrator
     * typing a price deserves to be told, not silently corrected into an
     * amount they never chose. Money never passes through a float — see
     * App\Service\Personalization\Money.
     *
     * @param list<string> $errors collected Dutch, admin-facing messages (appended to)
     */
    public static function validateSurcharge(mixed $value, array &$errors): int
    {
        $raw = is_string($value) ? trim($value) : $value;

        if ($raw === null || $raw === '') {
            return 0;
        }

        $normalized = is_string($raw) ? str_replace(',', '.', $raw) : $raw;

        if (!is_numeric($normalized)) {
            $errors[] = 'De meerprijs moet een geldig bedrag zijn (bijvoorbeeld 7,50).';
            return 0;
        }

        $cents = Money::toCents($raw);

        if ($cents < 0) {
            $errors[] = 'De meerprijs kan niet negatief zijn.';
            return 0;
        }

        if ($cents > self::MAX_SURCHARGE_CENTS) {
            $errors[] = 'De meerprijs is te hoog (maximaal € '
                . Money::formatDutch(self::MAX_SURCHARGE_CENTS) . ').';
            return 0;
        }

        return $cents;
    }

    private static function clampNumber(mixed $value, float $min, float $max, float $fallback): float
    {
        if (is_string($value)) {
            $value = trim(str_replace(',', '.', $value));
        }

        if (!is_numeric($value)) {
            return $fallback;
        }

        return round(max($min, min($max, (float) $value)), 4);
    }
}
