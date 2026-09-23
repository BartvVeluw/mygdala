<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\AdminTranslator;

/**
 * A block button's destination as an editor chooses it: nothing, an item of
 * the website itself (App\Service\Routing\LinkTargets: a page, a blog post, a
 * product), or a typed address. Stored as three values on the block's row:
 *
 *     <prefix>link_type        NULL (no button), 'url', or a LinkTargets type
 *     <prefix>link_target_id   the item's id for an internal type, else NULL
 *     <prefix>url              the typed address, kept whatever the type
 *
 * An internal target is an id, never an address, so a later slug change, a
 * renamed page or a new website language follows through by itself: href()
 * resolves it per render in the language being read. A typed address goes
 * through TypedLink, as it always did.
 *
 * ONE RULE FOR EVERY BLOCK THAT HAS ONE. The Kaarten-carrousel's cards had it
 * first (api/admin/update-carousel-card.php); the Homepage Hero's two buttons
 * use the same class and the same editor field (admin/_link_target_field.php),
 * so neither is a copy of the other. What is NOT here: the navigation and
 * footer rows, whose destination has kinds of its own (a route, "nergens
 * heen", an action) and lives in App\Service\LinkResolver.
 *
 * A ROW FROM BEFORE THE TYPE EXISTED has an address and no type, and is an
 * address: storedType() reads it so, and href() renders it so.
 */
final class LinkChoice
{
    public const NONE = 'none';
    public const URL = 'url';

    /** Schemes a typed address may carry; a relative one needs none. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * The type a stored row really has: its own, or 'url' for a row written
     * before types existed that has an address, or NONE.
     */
    public static function storedType(?string $type, string $url): string
    {
        $type = (string) $type;

        if ($type === '' && trim($url) !== '') {
            return self::URL;
        }

        return $type === '' ? self::NONE : $type;
    }

    /**
     * Checks what the editor posted and turns it into the two stored values.
     * A type whose module is switched off stays exactly as stored when it is
     * sent back unchanged; anything else unknown is refused.
     *
     * @param bool $allowNone whether "no button" is an answer (a required button says no)
     *
     * @return array{link_type: string|null, link_target_id: int|null, error: string|null}
     *         error is a sentence for the editor, or null
     */
    public static function fromRequest(
        string $type,
        mixed $target,
        string $url,
        string $storedType = '',
        int $storedTargetId = 0,
        bool $allowNone = true
    ): array {
        $url = trim($url);
        $targetId = is_numeric($target) ? (int) $target : 0;

        if ($type === self::NONE || $type === '') {
            return [
                'link_type' => null,
                'link_target_id' => null,
                'error' => $allowNone ? null : AdminTranslator::trans('link_choice.error_required'),
            ];
        }

        if ($type === self::URL) {
            $error = null;
            if ($url === '') {
                $error = AdminTranslator::trans('link_choice.error_url_empty');
            } elseif (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $scheme) === 1 && !in_array(strtolower($scheme[1]), self::URL_SCHEMES, true)) {
                $error = AdminTranslator::trans('link_choice.error_url_scheme');
            }

            return ['link_type' => self::URL, 'link_target_id' => null, 'error' => $error];
        }

        if (LinkTargets::isAvailable($type)) {
            return [
                'link_type' => $type,
                'link_target_id' => $targetId,
                'error' => $targetId < 1 || !LinkTargets::exists($type, $targetId) ? AdminTranslator::trans('link_choice.error_target') : null,
            ];
        }

        if ($type === $storedType && !in_array($storedType, ['', self::NONE, self::URL], true)) {
            // A kind whose module is off, left alone: kept exactly as stored.
            return ['link_type' => $storedType, 'link_target_id' => $storedTargetId, 'error' => null];
        }

        return ['link_type' => null, 'link_target_id' => null, 'error' => AdminTranslator::trans('link_choice.error_target')];
    }

    /**
     * The address a visitor's button goes to, in the language being read, or
     * '' for no button (no type, or a target that is gone or not public).
     */
    public static function href(?string $type, mixed $targetId, string $url): string
    {
        $type = self::storedType($type, $url);

        if ($type === self::URL) {
            return TypedLink::href(trim($url));
        }

        if ($type === self::NONE) {
            return '';
        }

        return LinkTargets::href($type, (int) $targetId) ?? '';
    }
}
