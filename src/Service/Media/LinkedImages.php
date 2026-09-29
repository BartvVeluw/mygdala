<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Module\ModuleRegistry;
use App\Service\Routing\LinkTargets;
use App\Service\Routing\RequestLanguage;

/**
 * An item of the site shown as a picture that links to it — a product, a
 * Portfolio project, a blog post — wherever a block lets an editor pick one
 * instead of a Media Library picture (Detailsectie 2.0: a gallery item;
 * CONTENT-BLOCKS.md, "Detailsectie").
 *
 * NOT A SECOND LINK SYSTEM. Which kinds of item exist, what an editor can
 * choose (with picture and status), the item's name and its public address
 * are the Destination Picker's (App\Service\Routing\LinkTargets, from each
 * module's linkTargets()). The one thing a destination does not have is a
 * PICTURE, and that is what a module adds here, per kind, through
 * ModuleDefinition::linkedImages(): the picture its own storefront or cards
 * show for that item. A kind is offered only when both are there, so a
 * future Articles module that contributes a destination and a picture is a
 * gallery source without Detailsectie changing.
 *
 * LIVE, NEVER A SNAPSHOT. resolve() is asked at every render: a new slug, a
 * new main picture, a new name show at once. And it answers null for
 * whatever a visitor cannot open — a draft, an inactive product, a hidden
 * project, an item that is gone, a module that is off — so a block leaves
 * that item out rather than showing a picture that leads nowhere or leaking
 * an unpublished one.
 *
 * A kind comes from a request only after isAvailable(); an id is an integer.
 */
final class LinkedImages
{
    /**
     * The kinds an editor can choose right now: a LinkTargets type whose
     * module is on and that has a picture, in the Destination Picker's order.
     *
     * @return array<string, string> kind => the editor's name for it
     */
    public static function kinds(): array
    {
        $kinds = [];
        foreach (self::availableKinds() as $kind) {
            $kinds[$kind] = LinkTargets::label($kind);
        }

        return $kinds;
    }

    /**
     * Whether an editor can choose this kind right now. Without the labels:
     * a label is the CMS's word in the CMS language, and asking for it on a
     * public page would start the admin session (AdminLocale).
     */
    public static function isAvailable(string $kind): bool
    {
        return in_array($kind, self::availableKinds(), true);
    }

    /** @return list<string> */
    private static function availableKinds(): array
    {
        $pictures = ModuleRegistry::collectMap('linkedImages');
        $kinds = [];

        foreach (array_keys(LinkTargets::types()) as $kind) {
            if ($kind !== LinkTargets::PAGE && isset($pictures[$kind])) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * Whether some module, on or off, contributes this kind: a stored choice
     * of it is kept, and the editor can say which module it waits for.
     */
    public static function isKnown(string $kind): bool
    {
        return ModuleRegistry::ownerOf('linkedImages', $kind) !== null;
    }

    /** The label of the module a known kind belongs to, while it is off; null otherwise. */
    public static function disabledModuleOf(string $kind): ?string
    {
        if (self::isAvailable($kind)) {
            return null;
        }

        $module = ModuleRegistry::ownerOf('linkedImages', $kind);

        return $module === null ? null : ModuleRegistry::label($module);
    }

    /**
     * What an editor can choose for one kind: the Destination Picker's own
     * list (id, name, status note, thumbnail).
     *
     * @return list<array<string, mixed>>
     */
    public static function choices(string $kind): array
    {
        return self::isAvailable($kind) ? LinkTargets::choices($kind) : [];
    }

    /**
     * The item as a visitor sees it now, in the language of the request, or
     * null when there is nothing a visitor may see: the kind unavailable, the
     * item not public or gone, or without a picture. Never throws.
     *
     * @return array{image_path: string, alt: string, width: int|null, height: int|null, href: string, title: string}|null
     */
    public static function resolve(string $kind, int $id): ?array
    {
        if ($id < 1 || !self::isAvailable($kind)) {
            return null;
        }

        $href = LinkTargets::href($kind, $id);
        if ($href === null) {
            return null;
        }

        $picture = ModuleRegistry::collectMap('linkedImages')[$kind] ?? null;

        try {
            $image = is_callable($picture) ? $picture($id) : null;
        } catch (\Throwable $e) {
            error_log('[LinkedImages] the picture of ' . $kind . ' #' . $id . ' failed: ' . $e->getMessage());
            $image = null;
        }

        if (!is_array($image) || trim((string) ($image['image_path'] ?? '')) === '') {
            return null;
        }

        return [
            'image_path' => '/' . ltrim((string) $image['image_path'], '/'),
            'alt' => (string) ($image['alt'] ?? ''),
            'width' => isset($image['width']) ? (int) $image['width'] : null,
            'height' => isset($image['height']) ? (int) $image['height'] : null,
            'href' => $href,
            'title' => (string) (LinkTargets::title($kind, $id, RequestLanguage::current()) ?? ''),
        ];
    }
}
