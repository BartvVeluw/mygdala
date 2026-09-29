<?php

declare(strict_types=1);

namespace App\Service\ContentOwners;

use App\Module\ModuleRegistry;

/**
 * The closed list of content owners: whatever the registered modules
 * contribute through ModuleDefinition::contentOwners(), keyed by kind. No
 * scan, no reflection, and a kind is only ever looked up here — a request can
 * name a kind, never a class.
 *
 * Two readings on purpose, the same split ModuleRegistry has:
 *
 *   - all()/get(): every owner of every registered module, switched on or
 *     off. What a content page is FOR does not change when its module is
 *     switched off; the CMS still names it, and deleting its owner still
 *     takes it along.
 *   - enabled()/getEnabled(): only the owners of modules that are on. What
 *     can be ADDED to or edited through an owner's editor.
 */
final class ContentOwners
{
    /** The owner kind of an ordinary page (pages.owner_type NULL). */
    public const PAGE = 'page';

    /** @var array<string, ContentOwner>|null */
    private static ?array $all = null;

    /** @return array<string, ContentOwner> kind => owner */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $owners = [];
        foreach (ModuleRegistry::all() as $module) {
            foreach ($module->contentOwners() as $owner) {
                if (!$owner instanceof ContentOwner || isset($owners[$owner->kind()]) || $owner->kind() === self::PAGE) {
                    continue;
                }

                $owners[$owner->kind()] = $owner;
            }
        }

        return self::$all = $owners;
    }

    /** @return array<string, ContentOwner> kind => owner, of modules that are on */
    public static function enabled(): array
    {
        return array_filter(
            self::all(),
            static fn (ContentOwner $owner): bool => ModuleRegistry::isEnabled($owner->moduleKey())
        );
    }

    public static function get(string $kind): ?ContentOwner
    {
        return self::all()[$kind] ?? null;
    }

    public static function getEnabled(string $kind): ?ContentOwner
    {
        return self::enabled()[$kind] ?? null;
    }

    /** Forgets the list (tests that switch modules). */
    public static function reset(): void
    {
        self::$all = null;
    }
}
