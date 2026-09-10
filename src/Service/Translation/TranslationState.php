<?php

declare(strict_types=1);

namespace App\Service\Translation;

use App\Repository\TranslationStateRepository;

/**
 * What the CMS knows about one translated field: the four states an editor
 * can act on, and the rule that protects their own words.
 *
 *     MISSING      nothing translated yet
 *     MACHINE      a provider wrote it and nobody has touched it since
 *     MANUAL       a person wrote or corrected it
 *     OUTDATED     the source text changed after the translation was made
 *
 * OUTDATED is computed, never stored: it is "the stored hash no longer
 * matches the source text I am looking at". Storing it would mean every edit
 * to a source field had to remember to invalidate its translations, and the
 * one that forgot would show an editor a stale translation as current.
 *
 * THE RULE THIS CLASS EXISTS FOR — automatic translation never silently
 * overwrites a manual edit. ::mayOverwrite() is the single place that
 * decides, and both the endpoint and the screen ask it, so the confirmation
 * an editor sees and the refusal the server performs cannot drift apart.
 */
final class TranslationState
{
    public const MISSING = 'missing';
    public const MACHINE = 'machine';
    public const MANUAL = 'manual';
    public const OUTDATED = 'outdated';

    /**
     * The hash that decides whether a translation is still current.
     *
     * Of the SOURCE text only, normalised for whitespace so that reflowing a
     * paragraph in the editor does not mark every translation stale. sha256
     * because this is an identity check and the column is sized for it; there
     * is nothing secret about a block of website copy.
     */
    public static function hash(string $sourceText): string
    {
        return hash('sha256', self::normalise($sourceText));
    }

    /** Do these two source texts count as the same text? */
    public static function matches(string $sourceText, ?string $storedHash): bool
    {
        return $storedHash !== null && $storedHash !== '' && hash_equals($storedHash, self::hash($sourceText));
    }

    /**
     * Which of the four states one field is in.
     *
     * @param array<string, mixed>|null $record the row from content_translation_state
     */
    public static function classify(string $sourceText, string $translatedText, ?array $record): string
    {
        if (trim($translatedText) === '') {
            return self::MISSING;
        }

        // A translation with no record was written by a person before this
        // feature existed, or by hand without ever pressing translate. Either
        // way it is somebody's own text and is protected as such.
        if ($record === null) {
            return self::MANUAL;
        }

        if (!self::matches($sourceText, (string) ($record['source_hash'] ?? ''))) {
            return self::OUTDATED;
        }

        return ($record['is_manual'] ?? false) === true ? self::MANUAL : self::MACHINE;
    }

    /**
     * May automatic translation replace what is in this field?
     *
     * Yes when there is nothing there, or when the machine put it there and
     * nobody has corrected it. No when a person wrote it — that needs an
     * explicit confirmation from the editor, which arrives as $force.
     */
    public static function mayOverwrite(string $state, bool $force = false): bool
    {
        if ($force) {
            return true;
        }

        return $state === self::MISSING || $state === self::MACHINE || $state === self::OUTDATED;
    }

    /**
     * Every state for one content row, keyed "field:language" — what an
     * editor screen needs to draw its badges, in one query.
     *
     * @param array<string, string> $sourceTexts field => the primary language's text
     * @param array<string, array<string, string>> $translations language => field => text
     * @return array<string, string> "field:language" => one of the constants
     */
    public static function forEntity(
        string $entityType,
        int $entityId,
        array $sourceTexts,
        array $translations,
        ?TranslationStateRepository $repository = null,
    ): array {
        $records = [];
        try {
            $records = ($repository ?? new TranslationStateRepository())->forEntity($entityType, $entityId);
        } catch (\Throwable $e) {
            // A translation badge is a convenience. Losing it must never take
            // the editor screen down with it, so an unreachable table degrades
            // to "no records", which classifies existing text as MANUAL — the
            // protective direction.
            error_log('[TranslationState] could not read translation state: ' . $e->getMessage());
        }

        $states = [];
        foreach ($translations as $language => $fields) {
            foreach ($fields as $field => $text) {
                $key = $field . ':' . $language;
                $states[$key] = self::classify(
                    $sourceTexts[$field] ?? '',
                    (string) $text,
                    $records[$key] ?? null,
                );
            }
        }

        return $states;
    }

    /**
     * Collapse the source of a field to what actually matters for "did this
     * change". Leading and trailing space and runs of whitespace are editing
     * noise; a different word is not.
     */
    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
