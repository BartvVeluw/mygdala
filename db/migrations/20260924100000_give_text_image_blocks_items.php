<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tekst met afbeelding 2.0 (CONTENT-BLOCKS.md): one block holds a list of
 * ITEMS, each a text beside one picture with its own layout. Until now a
 * block was one text (an eyebrow, a title, a list of plain paragraphs and a
 * button) beside a list of pictures.
 *
 *   text_image_split_items
 *     text_image_split_id   the block (ON DELETE CASCADE, like its other children)
 *     media_id              the picture, a Media Library item (ON DELETE RESTRICT,
 *                           for the reason 20260909260000 gives), or NULL
 *     image_path            the legacy path beside it, as on text_image_split_images
 *     image_side            'left' | 'right'
 *     image_column          '25' | '50' | '75': the picture's share of the row, in percent
 *     image_height          'small' | 'medium' | 'large' (assets/css/blocks/text-image-split.css)
 *     image_focus           App\Service\Media\ImageFocus
 *     button_url            the optional button's address (TypedLink), as the block had it
 *     sort_order
 *
 * An item's words are in block_translations against the item
 * (TextImageSplitBlock::translatableFields()): eyebrow, title, body (rich
 * text), button_label and alt.
 *
 * EVERY EXISTING BLOCK BECOMES ITS OWN FIRST ITEM, so it shows the same words
 * and the same pictures:
 *
 *   - the block's eyebrow, title and button label, in every language they
 *     have, byte for byte, and its button URL;
 *   - its paragraphs as the body: `<p>` per paragraph, the text escaped, in
 *     the order they had. The website showed a paragraph only when it had its
 *     text in the default language, and a translation fell back per
 *     paragraph; so a paragraph without default-language text is left out,
 *     and a language gets a body only when at least one paragraph had its
 *     words, the others taking the default language's as the website did;
 *   - layout image_left/image_right as image_side left/right; 50 / 50;
 *     focus centre; height 'large', the one closest to the tall 4:5 frame a
 *     single picture had;
 *   - its FIRST picture, with its alt text in every language.
 *
 *   Every further picture (a block with two or more showed them as a small
 *   gallery) becomes an item of its own right after the first, picture and
 *   alt text only, on the same side and 'medium' high: nothing is lost, and
 *   an item keeps exactly one picture.
 *
 * A block with no words, no paragraph and no picture showed nothing and
 * gets no item.
 *
 * A MOVE, NOT A COPY: once a block's item exists, the words of the block row,
 * of its paragraphs and of its pictures are removed from block_translations,
 * and the paragraph and picture rows themselves, in the same transaction.
 * Two copies is how one of them goes stale, and a picture row left behind
 * would keep its media item undeletable (the RESTRICT key) without anything
 * saying where it is used. The TABLES and the block's layout and button_url
 * COLUMNS stay: forward-only, and nothing is dropped here.
 *
 * SAFE TO RUN TWICE: a block that already has an item is left alone.
 * Schema first, no fresh-install guard (db/migrations/CLAUDE.md).
 */
final class GiveTextImageBlocksItems extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('text_image_split_items')) {
            $this->table('text_image_split_items')
                ->addColumn('text_image_split_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference; wins over image_path when set'])
                ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('image_side', 'string', ['limit' => 5, 'null' => false, 'default' => 'right', 'comment' => "'left' | 'right'"])
                ->addColumn('image_column', 'string', ['limit' => 2, 'null' => false, 'default' => '50', 'comment' => "the picture's share of the row in percent: '25' | '50' | '75'"])
                ->addColumn('image_height', 'string', ['limit' => 6, 'null' => false, 'default' => 'medium', 'comment' => "'small' | 'medium' | 'large'"])
                ->addColumn('image_focus', 'string', ['limit' => 12, 'null' => false, 'default' => 'center', 'comment' => 'App\Service\Media\ImageFocus: which part of a cropped picture stays in view'])
                ->addColumn('button_url', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['text_image_split_id', 'sort_order'])
                ->addForeignKey('text_image_split_id', 'text_image_splits', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }

        $this->moveBlocksIntoItems();
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md): the words moved onto the
        // items are not moved back.
    }

    private function moveBlocksIntoItems(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $default = (string) ($pdo->query('SELECT code FROM site_languages WHERE is_default = 1 LIMIT 1')->fetchColumn() ?: 'nl');

        $blocks = $pdo->query(
            'SELECT s.* FROM text_image_splits s
              WHERE NOT EXISTS (SELECT 1 FROM text_image_split_items i WHERE i.text_image_split_id = s.id)
              ORDER BY s.id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($blocks as $block) {
            // One block at a time in a transaction of its own, unless Phinx
            // already runs this migration inside one (it may, when there is
            // no schema change left to make); then that one covers it.
            $own = !$pdo->inTransaction();
            if ($own) {
                $pdo->beginTransaction();
            }

            try {
                $this->moveBlock($pdo, $block, $default);
                if ($own) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        }
    }

    /** @param array<string, mixed> $block */
    private function moveBlock(\PDO $pdo, array $block, string $default): void
    {
        $blockId = (int) $block['id'];
        $side = ($block['layout'] ?? null) === 'image_left' ? 'left' : 'right';

        $paragraphs = $this->rows($pdo, 'SELECT id FROM text_image_split_paragraphs WHERE text_image_split_id = ? ORDER BY sort_order ASC, id ASC', [$blockId]);
        $images = $this->rows($pdo, 'SELECT id, media_id, image_path FROM text_image_split_images WHERE text_image_split_id = ? ORDER BY sort_order ASC, id ASC', [$blockId]);

        $words = $this->words($pdo, 'text_image_splits', [$blockId])[$blockId] ?? [];
        $paragraphWords = $this->words($pdo, 'text_image_split_paragraphs', array_map(static fn (array $row): int => (int) $row['id'], $paragraphs));
        $imageWords = $this->words($pdo, 'text_image_split_images', array_map(static fn (array $row): int => (int) $row['id'], $images));

        // The body per language, built exactly as the website showed the paragraphs.
        $shown = array_values(array_filter(
            array_map(static fn (array $row): array => $paragraphWords[(int) $row['id']] ?? [], $paragraphs),
            static fn (array $languages): bool => self::hasWords($languages[$default]['content'] ?? null)
        ));
        $languages = [];
        foreach ($shown as $paragraph) {
            foreach ($paragraph as $language => $fields) {
                if (self::hasWords($fields['content'] ?? null)) {
                    $languages[$language] = true;
                }
            }
        }
        foreach (array_keys($languages) as $language) {
            $body = '';
            foreach ($shown as $paragraph) {
                $text = self::hasWords($paragraph[$language]['content'] ?? null) ? $paragraph[$language]['content'] : $paragraph[$default]['content'];
                $body .= ($body === '' ? '' : "\n") . '<p>' . htmlspecialchars((string) $text, ENT_NOQUOTES, 'UTF-8') . '</p>';
            }
            $words[$language]['body'] = $body;
        }

        $first = $images[0] ?? null;
        $hasWords = $words !== [];

        if ($hasWords || $first !== null) {
            $itemWords = $words;
            if ($first !== null) {
                foreach ($imageWords[(int) $first['id']] ?? [] as $language => $fields) {
                    if (self::hasWords($fields['alt'] ?? null)) {
                        $itemWords[$language]['alt'] = $fields['alt'];
                    }
                }
            }

            $this->insertItem($pdo, $blockId, 0, [
                'media_id' => $first['media_id'] ?? null,
                'image_path' => $first['image_path'] ?? null,
                'image_side' => $side,
                'image_height' => 'large',
                'button_url' => $block['button_url'] ?? null,
            ], $itemWords);
        }

        foreach (array_slice($images, 1) as $position => $image) {
            $altWords = [];
            foreach ($imageWords[(int) $image['id']] ?? [] as $language => $fields) {
                if (self::hasWords($fields['alt'] ?? null)) {
                    $altWords[$language]['alt'] = $fields['alt'];
                }
            }

            $this->insertItem($pdo, $blockId, $position + 1, [
                'media_id' => $image['media_id'],
                'image_path' => $image['image_path'],
                'image_side' => $side,
                'image_height' => 'medium',
                'button_url' => null,
            ], $altWords);
        }

        // The move's second half: the old places of these words and rows.
        $delete = $pdo->prepare('DELETE FROM block_translations WHERE owner_table = ? AND owner_id = ?');
        $delete->execute(['text_image_splits', $blockId]);
        foreach ($paragraphs as $row) {
            $delete->execute(['text_image_split_paragraphs', (int) $row['id']]);
        }
        foreach ($images as $row) {
            $delete->execute(['text_image_split_images', (int) $row['id']]);
        }
        $pdo->prepare('DELETE FROM text_image_split_paragraphs WHERE text_image_split_id = ?')->execute([$blockId]);
        $pdo->prepare('DELETE FROM text_image_split_images WHERE text_image_split_id = ?')->execute([$blockId]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, string>> $words language => field => words
     */
    private function insertItem(\PDO $pdo, int $blockId, int $sortOrder, array $values, array $words): void
    {
        $mediaId = (int) ($values['media_id'] ?? 0);
        $pdo->prepare(
            "INSERT INTO text_image_split_items
                (text_image_split_id, media_id, image_path, image_side, image_column, image_height, image_focus, button_url, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, '50', ?, 'center', ?, ?, NOW(), NOW())"
        )->execute([
            $blockId,
            $mediaId > 0 ? $mediaId : null,
            $values['image_path'] ?? null,
            $values['image_side'],
            $values['image_height'],
            $values['button_url'] ?? null,
            $sortOrder,
        ]);
        $itemId = (int) $pdo->lastInsertId();

        $insert = $pdo->prepare(
            "INSERT INTO block_translations (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             VALUES ('text_image_split_items', ?, ?, ?, ?, NOW(), NOW())"
        );
        foreach ($words as $language => $fields) {
            foreach ($fields as $field => $value) {
                $insert->execute([$itemId, $language, $field, $value]);
            }
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, array<string, string>>> owner id => language => field => words
     */
    private function words(\PDO $pdo, string $table, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT owner_id, language_code, field, value FROM block_translations
              WHERE owner_table = ? AND owner_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY owner_id, language_code, field'
        );
        $stmt->execute(array_merge([$table], $ids));

        $words = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (self::hasWords($row['value'])) {
                $words[(int) $row['owner_id']][(string) $row['language_code']][(string) $row['field']] = (string) $row['value'];
            }
        }

        return $words;
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDO $pdo, string $sql, array $parameters): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Words, as every reader judged them: not NULL, not only whitespace. */
    private static function hasWords(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
