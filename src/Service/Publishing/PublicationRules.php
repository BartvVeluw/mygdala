<?php

declare(strict_types=1);

namespace App\Service\Publishing;

use App\Service\Language\AdminTranslator;

/**
 * Whether a requested publication change may be saved, and what it stores
 * (docs/publishing/ARCHITECTURE.md, "Publiceren valideren").
 *
 *   1. The status must be one of PublicationStatus::ALL AND one this kind
 *      offers (Publishable::statuses()). Anything else is refused, never
 *      read as a draft: a refused save keeps the editor's draft as it was.
 *   2. The date, when one is typed, must be a real moment in an accepted
 *      shape (PublishingClock::fromInput()). Nothing relative, no 30 February.
 *   3. Scheduled needs a date: a scheduled item without a moment would sit
 *      invisible for ever while claiming to be scheduled.
 *   4. Going public (published, scheduled, archived) asks the owner's own
 *      rule, Publishable::publishErrors() — the engine never knows that a
 *      blog post needs a body.
 *
 * Every message is CMS text for the editor. Nothing here writes: the owner
 * saves, after validate() returned no errors, the value resolvePublishedAt()
 * gives — so there is no half-published state to clean up.
 */
final class PublicationRules
{
    /**
     * @param list<string>     $offered  the statuses this kind offers
     * @param Publishable|null $provider asked for its own rule when going public (null: a record that does not exist yet)
     * @return list<string> the editor's errors; [] means it may be saved
     */
    public static function validate(mixed $status, mixed $publishedAt, array $offered, ?Publishable $provider = null, ?int $id = null): array
    {
        $errors = [];

        if (!PublicationStatus::isValid($status) || !in_array(PublicationStatus::normalize($status), $offered, true)) {
            return [AdminTranslator::trans('publishing.error.status')];
        }

        $status = PublicationStatus::normalize($status);
        $moment = PublishingClock::fromInput($publishedAt);

        if ($moment === false) {
            $errors[] = AdminTranslator::trans('publishing.error.date');
        } elseif ($status === PublicationStatus::SCHEDULED && $moment === null) {
            $errors[] = AdminTranslator::trans('publishing.error.scheduled_needs_date');
        }

        if ($errors === [] && $provider !== null && $id !== null && $status !== PublicationStatus::DRAFT) {
            foreach ($provider->publishErrors($id, $status) as $message) {
                $errors[] = $message;
            }
        }

        return $errors;
    }

    /**
     * The moment a valid save stores:
     *
     *   a typed moment             -> that moment, whatever the status
     *   published without one      -> now: picking "published" and saving IS publishing
     *   anything else without one  -> NULL (a draft has no moment yet; archived keeps none)
     *
     * Call only after validate() returned no errors.
     */
    public static function resolvePublishedAt(string $status, mixed $publishedAt): ?string
    {
        $moment = PublishingClock::fromInput($publishedAt);

        if (is_string($moment)) {
            return $moment;
        }

        return PublicationStatus::normalize($status) === PublicationStatus::PUBLISHED
            ? PublishingClock::nowForSql()
            : null;
    }
}
