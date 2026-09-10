<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL for `content_translation_state` — what the CMS remembers about a
 * translation, never the translation itself.
 *
 * Every identifier this class puts into a statement (`entity_type`, `field`,
 * `language`) is a bound parameter, not an interpolated one. They look like
 * schema names but they are data here: this table stores the NAME of a column
 * as a value, and it never builds a query against the table it names.
 */
final class TranslationStateRepository extends Repository
{
    /**
     * Every recorded translation for one row, keyed "field:language".
     *
     * One query per editor screen rather than one per field, for the same
     * reason MediaUsageProvider answers a whole list at once.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT field, language, source_hash, provider, is_manual, translated_at
             FROM content_translation_state
             WHERE entity_type = :entity_type AND entity_id = :entity_id'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);

        $states = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['is_manual'] = (int) $row['is_manual'] === 1;
            $states[$row['field'] . ':' . $row['language']] = $row;
        }

        return $states;
    }

    /** @return array<string, mixed>|null */
    public function find(string $entityType, int $entityId, string $field, string $language): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT field, language, source_hash, provider, is_manual, translated_at
             FROM content_translation_state
             WHERE entity_type = :entity_type AND entity_id = :entity_id
               AND field = :field AND language = :language
             LIMIT 1'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'language' => $language,
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $row['is_manual'] = (int) $row['is_manual'] === 1;

        return $row;
    }

    /**
     * Record that $field was translated into $language from a source with
     * this hash.
     *
     * Upsert rather than delete-then-insert: the identity of a translation is
     * its target, and two editors translating the same block at once must end
     * with one row, not with a gap between the delete and the insert.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $field,
        string $language,
        string $sourceHash,
        ?string $provider,
        bool $isManual,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO content_translation_state
                 (entity_type, entity_id, field, language, source_hash, provider, is_manual, translated_at, created_at, updated_at)
             VALUES (:entity_type, :entity_id, :field, :language, :source_hash, :provider, :is_manual, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 source_hash = VALUES(source_hash),
                 provider = VALUES(provider),
                 is_manual = VALUES(is_manual),
                 translated_at = VALUES(translated_at),
                 updated_at = NOW()'
        );

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'language' => $language,
            'source_hash' => $sourceHash,
            'provider' => $provider,
            'is_manual' => $isManual ? 1 : 0,
        ]);
    }

    /**
     * Mark an existing record as edited by a person.
     *
     * Deliberately an UPDATE that inserts nothing: a field with no record was
     * never machine translated, so there is nothing to protect from being
     * overwritten and nothing worth a row.
     */
    public function markManual(string $entityType, int $entityId, string $field, string $language): void
    {
        $stmt = $this->db->prepare(
            'UPDATE content_translation_state
             SET is_manual = 1, updated_at = NOW()
             WHERE entity_type = :entity_type AND entity_id = :entity_id
               AND field = :field AND language = :language'
        );

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'language' => $language,
        ]);
    }

    /**
     * Drop everything remembered about one content row.
     *
     * Called when that row itself is deleted. State about a block that no
     * longer exists is not history, it is litter — and its (entity_type,
     * entity_id) pair would eventually be handed to a different row.
     */
    public function deleteForEntity(string $entityType, int $entityId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM content_translation_state WHERE entity_type = :entity_type AND entity_id = :entity_id'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
    }
}
