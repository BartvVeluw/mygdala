<?php

declare(strict_types=1);

namespace App\Service\Publishing;

use App\Service\AdminAuth;
use App\Service\Language\AdminTranslator;

/**
 * Changing the publication of one record of any kind, by type and id — what
 * a "Publiceren", "Archiveren" or "Terug naar concept" button outside the
 * owner's own editor does (api/admin/update-publication.php,
 * docs/publishing/ARCHITECTURE.md "Een publicatie wijzigen").
 *
 * The order is the whole point:
 *
 *   1. the type must be a registered kind of an ENABLED module, and the id a
 *      record of THAT kind (Publishables::find()): a blog post's id named as
 *      another type, or a forged type, finds nothing;
 *   2. the signed-in editor must hold that kind's own permission — the
 *      engine has no rights model of its own, the owner's decides;
 *   3. PublicationRules: a status this kind offers, a real moment, and the
 *      owner's own "can publish";
 *   4. only then the owner saves.
 *
 * A refusal at any step changes nothing, so there is never a half-published
 * record. The owner's own editor (one form, one endpoint) does not come
 * through here: it runs the same PublicationRules inside its own save.
 */
final class PublishingService
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';
    public const INVALID = 'invalid';
    public const SAVED = 'saved';

    /**
     * @param \Closure(string): bool|null $can who may (defaults to the signed-in administrator)
     * @return array{outcome: string, errors: list<string>, provider: ?Publishable, id: ?int}
     */
    public static function change(mixed $type, mixed $id, mixed $status, mixed $publishedAt, ?\Closure $can = null): array
    {
        $found = Publishables::find($type, $id);

        if ($found === null) {
            return ['outcome' => self::NOT_FOUND, 'errors' => [AdminTranslator::trans('publishing.error.not_found')], 'provider' => null, 'id' => null];
        }

        [$provider] = $found;
        $id = (int) $id;
        $can ??= static fn (string $permission): bool => AdminAuth::can($permission);

        if (!$can($provider->permission())) {
            return ['outcome' => self::FORBIDDEN, 'errors' => [], 'provider' => $provider, 'id' => $id];
        }

        $errors = PublicationRules::validate($status, $publishedAt, $provider->statuses(), $provider, $id);

        if ($errors !== []) {
            return ['outcome' => self::INVALID, 'errors' => $errors, 'provider' => $provider, 'id' => $id];
        }

        $status = PublicationStatus::normalize($status);
        $provider->savePublication($id, $status, PublicationRules::resolvePublishedAt($status, $publishedAt));

        return ['outcome' => self::SAVED, 'errors' => [], 'provider' => $provider, 'id' => $id];
    }

    /**
     * Step 1 of the endpoint, right after the login: refuse anyone who may
     * change the publication of NO enabled kind, before a single field is
     * read. Which kind is meant, and that kind's own permission, is change()'s
     * step 2 — the shape of ContentBlockAccess::requireAnyForApi().
     */
    public static function requireAnyForApi(): void
    {
        AdminAuth::requireLoginForApi();

        if (!AdminAuth::canAny(self::permissions())) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Forbidden: missing permission.');
        }
    }

    /**
     * Every permission that may change publication of some enabled kind, for
     * the endpoint's permission guard before it reads which kind is meant.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $permissions = [];

        foreach (Publishables::all() as $provider) {
            $permissions[$provider->permission()] = true;
        }

        return array_keys($permissions);
    }
}
