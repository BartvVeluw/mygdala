<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All content_block_drafts SQL: the record of a block chosen in the block
 * picker whose editor has not saved it yet (App\Service\Blocks\ContentBlockDrafts,
 * db/migrations/20261006100000_create_content_block_drafts.php). A row is the
 * one proof that an unattached content row is a draft and may be placed on
 * its page. Every read joins its page for the page's content_key, which is
 * what the block's own content row is keyed by.
 */
final class ContentBlockDraftRepository extends Repository
{
    private const SELECT = 'SELECT d.*, p.content_key AS page_slug
        FROM content_block_drafts d
        JOIN pages p ON p.id = d.page_id';

    public function create(int $pageId, string $sectionType, ?string $sectionKey, int $sectionId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO content_block_drafts (page_id, section_type, section_key, section_id, created_at)
             VALUES (:page_id, :section_type, :section_key, :section_id, NOW())'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'section_type' => $sectionType,
            'section_key' => $sectionKey,
            'section_id' => $sectionId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The draft of this content row, or null. With $lock, the row is locked
     * until the caller's transaction ends, so two saves of the same new block
     * (a double click) place it once: the second waits, then finds no draft.
     *
     * @return array<string, mixed>|null
     */
    public function findByRow(string $sectionType, int $sectionId, bool $lock = false): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE d.section_type = :section_type AND d.section_id = :section_id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['section_type' => $sectionType, 'section_id' => $sectionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The draft a cancelling editor names: its page, its type and its key,
     * the three things the editor's own URL carries.
     *
     * @return array<string, mixed>|null
     */
    public function findOnPage(int $pageId, string $sectionType, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE d.page_id = :page_id AND d.section_type = :section_type AND d.section_key = :section_key LIMIT 1');
        $stmt->execute(['page_id' => $pageId, 'section_type' => $sectionType, 'section_key' => $sectionKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findForPage(int $pageId): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE d.page_id = :page_id ORDER BY d.id');
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /**
     * Drafts older than $hours, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findOlderThan(int $hours): array
    {
        $stmt = $this->db->prepare(self::SELECT . ' WHERE d.created_at < (NOW() - INTERVAL :hours HOUR) ORDER BY d.id LIMIT 100');
        $stmt->bindValue('hours', $hours, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function countForPage(int $pageId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM content_block_drafts WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM content_block_drafts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Locks one page row until the caller's transaction ends, so two drafts
     * placed on the same page at once cannot take the same position.
     */
    public function lockPage(int $pageId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM pages WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $pageId]);
    }
}
