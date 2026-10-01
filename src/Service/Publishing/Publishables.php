<?php

declare(strict_types=1);

namespace App\Service\Publishing;

use App\Module\ModuleRegistry;

/**
 * The closed registry of kinds that publish: whatever the ENABLED modules
 * contribute through ModuleDefinition::publishables(), and nothing else
 * (docs/publishing/ARCHITECTURE.md, "Het providercontract").
 *
 * A type string from a request or a row is only ever a LOOKUP key here —
 * never a class name, never a table name. An unknown type and a type whose
 * module is switched off answer the same: null. That is also how "a module
 * that is off publishes nothing" holds without any check of its own.
 *
 * find() binds a type to an id: a record is only ever acted on through the
 * provider of the type it was asked for, and that provider only answers for
 * its own table. Naming a blog post's id under another type finds nothing.
 */
final class Publishables
{
    /** @var array<string, Publishable>|null */
    private static ?array $cache = null;

    /** @return array<string, Publishable> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $providers = [];

            foreach (ModuleRegistry::collectMap('publishables') as $type => $provider) {
                if ($provider instanceof Publishable && $provider->type() === $type && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type) === 1) {
                    $providers[$type] = $provider;
                }
            }

            self::$cache = $providers;
        }

        return self::$cache;
    }

    public static function get(mixed $type): ?Publishable
    {
        return is_string($type) ? (self::all()[$type] ?? null) : null;
    }

    /**
     * A provider and the publication facts of one of its records, or null
     * for an unknown or disabled type, a malformed id or an id that is not
     * a record of this type.
     *
     * @return array{0: Publishable, 1: array{status: string, published_at: ?string}}|null
     */
    public static function find(mixed $type, mixed $id): ?array
    {
        $provider = self::get($type);
        $id = is_int($id) || is_string($id) ? filter_var($id, FILTER_VALIDATE_INT) : false;

        if ($provider === null || $id === false || $id < 1) {
            return null;
        }

        $publication = $provider->publication($id);

        return $publication === null ? null : [$provider, $publication];
    }

    /** Forget the resolved set; ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
