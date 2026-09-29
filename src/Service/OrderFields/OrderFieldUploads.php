<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

use App\Database;
use App\Repository\OrderFieldRepository;
use App\Repository\OrderFieldUploadRepository;
use App\Repository\ProductRepository;
use App\Service\Language\SiteText;
use App\Service\PurchaseMode;
use PDO;

/**
 * The life of a customer's picture for an "Afbeelding uploaden" order
 * question (Shop Admin UX & Order Fields 2.0, MODULES.md "Bestelvelden"),
 * from the product page to the order:
 *
 *   1. upload()   the product page sends the picture as soon as it is chosen
 *                 (api/order-field-upload.php). It is checked completely
 *                 (OrderFieldUploadValidator), stored privately
 *                 (OrderFieldUploadStorage) and answered with a TOKEN: 256
 *                 random bits the browser keeps on the cart line as the
 *                 question's answer. The database keeps only its SHA-256.
 *                 The upload is bound to the product and the question it was
 *                 sent for, and expires after OrderFieldUploadPolicy::TTL_HOURS.
 *   2. discard()  the customer replaced or removed it on the page: row and
 *                 files are gone at once. Only a temporary upload; the token
 *                 is the only key.
 *   3. resolve()  cart check and checkout ask again, every time: the token
 *                 exists, fits this product and this question, is not
 *                 claimed, not expired, and its file is still there.
 *   4. claim      inside the checkout's transaction the answer's snapshot row
 *                 is written and the upload is bound to it by one conditional
 *                 UPDATE (OrderFieldUploadRepository::claim()). A second
 *                 order with the same token, or an upload that expired a
 *                 second ago, makes the claim fail and the WHOLE order roll
 *                 back. The file never moves, so there is nothing on disk to
 *                 undo: a rolled-back claim leaves a temporary upload that
 *                 simply expires. An order whose payment then ends without
 *                 money (could not start, failed, canceled, expired) gives
 *                 its pictures back to the cart — temporary again with a
 *                 fresh lifetime — because the cart stays in the browser until
 *                 an order is paid (OrderFieldUploadRepository::releaseForOrder(),
 *                 called next to the stock release).
 *   5. sweep()    expired temporary uploads are deleted with their files — on
 *                 roughly one in SWEEP_CHANCE uploads, and by
 *                 scripts/prune-order-field-uploads.php whenever a host runs
 *                 it. A claimed picture is never swept; it lives as long as
 *                 its order.
 *
 * WHY A TOKEN AND NO SESSION: the cart is entirely client-side (localStorage)
 * and the storefront sets no session. The token is the browser's capability:
 * unguessable, never stored in the clear, bound to one product and one
 * question, usable for one order. Someone else's token cannot be found; a
 * token of another question or product is refused; a used one is refused.
 *
 * NEVER IN THE MEDIA LIBRARY: these are private order files. Nothing here
 * writes to `media`, `assets/` or any public folder.
 */
final class OrderFieldUploads
{
    /** 1-in-N chance that a successful upload also sweeps expired ones. */
    public const SWEEP_CHANCE = 20;

    private PDO $db;
    private OrderFieldUploadRepository $repository;
    private OrderFieldUploadStorage $storage;
    private OrderFieldUploadValidator $validator;

    public function __construct(?PDO $db = null, ?OrderFieldUploadStorage $storage = null, ?OrderFieldUploadValidator $validator = null)
    {
        $this->db = $db ?? Database::connection();
        $this->repository = new OrderFieldUploadRepository($this->db);
        $this->storage = $storage ?? new OrderFieldUploadStorage();
        $this->validator = $validator ?? new OrderFieldUploadValidator();
    }

    public function storage(): OrderFieldUploadStorage
    {
        return $this->storage;
    }

    /**
     * The image question of a product that is for sale and asks its
     * questions, or null.
     *
     * @return array<string, mixed>|null the question row (OrderFieldRepository::fieldsForProduct())
     */
    public function imageQuestion(int $productId, int $fieldId): ?array
    {
        $product = (new ProductRepository($this->db))->findActiveById($productId);
        if ($product === null || PurchaseMode::isInquiry($product['purchase_mode'] ?? null)) {
            return null;
        }

        $fields = new OrderFieldRepository($this->db);
        if (!$fields->isEnabled($productId)) {
            return null;
        }

        foreach ($fields->fieldsForProduct($productId) as $field) {
            if ($field['id'] === $fieldId) {
                return OrderFieldType::isImage($field['field_type']) ? $field : null;
            }
        }

        return null;
    }

    /**
     * Checks and stores one picture for one image question.
     *
     * @return array{token: string, original_filename: string, width: int, height: int, byte_size: int}
     *
     * @throws OrderFieldUploadException
     */
    public function upload(int $productId, int $fieldId, mixed $fileEntry, string $languageCode): array
    {
        $question = $this->imageQuestion($productId, $fieldId);
        if ($question === null) {
            throw new OrderFieldUploadException('question', SiteText::pick([
                'nl' => 'Bij dit product kun je hier geen afbeelding meesturen. Laad de pagina opnieuw.',
                'en' => 'You cannot send a picture here with this product. Please reload the page.',
            ], $languageCode), 404);
        }

        $maxBytes = OrderFieldUploadPolicy::effectiveMaxBytes($question['max_file_size_mb'] ?? null);
        $file = $this->validator->validate($fileEntry, $maxBytes, $languageCode);

        if ($this->repository->temporaryBytes() + $file['size'] > OrderFieldUploadPolicy::MAX_TEMPORARY_BYTES) {
            // Make room from what expired before refusing anyone.
            $this->sweep();
            if ($this->repository->temporaryBytes() + $file['size'] > OrderFieldUploadPolicy::MAX_TEMPORARY_BYTES) {
                error_log('[OrderFieldUploads] the ceiling on temporary pictures is reached; an upload was refused');
                throw new OrderFieldUploadException('busy', SiteText::pick([
                    'nl' => 'Uploaden lukt op dit moment niet. Probeer het later opnieuw.',
                    'en' => 'Uploading is not possible right now. Please try again later.',
                ], $languageCode), 503);
            }
        }

        $token = OrderFieldUploadPolicy::newToken();
        $storageName = OrderFieldUploadStorage::newStorageName();

        // The row first, then the files: if storing fails the row goes, and a
        // crash in between leaves a row whose file is missing — which
        // resolve() refuses and the sweep removes — never a file nobody knows.
        $id = $this->repository->create([
            'token_hash' => OrderFieldUploadPolicy::tokenHash($token),
            'product_id' => $productId,
            'field_id' => $fieldId,
            'storage_name' => $storageName,
            'extension' => $file['extension'],
            'original_filename' => $file['original_filename'],
            'mime_type' => $file['mime'],
            'byte_size' => $file['size'],
            'image_width' => $file['width'],
            'image_height' => $file['height'],
        ], OrderFieldUploadPolicy::TTL_HOURS);

        try {
            $this->storage->store($storageName, $file['extension'], $file['tmp_path'], $file['thumbnail_bytes']);
        } catch (\Throwable $e) {
            $this->repository->deleteUnclaimed($id);
            error_log('[OrderFieldUploads] ' . $e->getMessage());
            throw new OrderFieldUploadException('failed', OrderFieldUploadValidator::message('failed', $languageCode), 500);
        }

        return [
            'token' => $token,
            'original_filename' => $file['original_filename'],
            'width' => $file['width'],
            'height' => $file['height'],
            'byte_size' => $file['size'],
        ];
    }

    /** The customer replaced or removed the picture: a temporary upload goes at once. */
    public function discard(mixed $token): bool
    {
        if (!OrderFieldUploadPolicy::isToken($token)) {
            return false;
        }

        $deleted = $this->repository->deleteUnclaimedByTokenHash(OrderFieldUploadPolicy::tokenHash($token));
        if ($deleted === null) {
            return false;
        }
        $this->storage->delete($deleted['storage_name'], $deleted['extension']);

        return true;
    }

    /**
     * The upload a token names, if it may answer THIS question of THIS
     * product now.
     *
     * @return array<string, mixed> the upload row
     *
     * @throws OrderFieldUploadException kind missing, expired, claimed or mismatch
     */
    public function resolve(mixed $token, int $productId, int $fieldId, string $label, string $languageCode): array
    {
        $row = OrderFieldUploadPolicy::isToken($token)
            ? $this->repository->findByTokenHash(OrderFieldUploadPolicy::tokenHash($token))
            : null;

        if ($row === null) {
            throw $this->refuse('missing', $label, $languageCode);
        }
        if ((int) $row['product_id'] !== $productId || (int) $row['field_id'] !== $fieldId) {
            throw $this->refuse('mismatch', $label, $languageCode);
        }
        if ($row['claimed_at'] !== null) {
            throw $this->refuse('claimed', $label, $languageCode);
        }
        if ((int) $row['expired'] === 1 || !$this->storage->exists((string) $row['storage_name'], (string) $row['extension'])) {
            throw $this->refuse('expired', $label, $languageCode);
        }

        return $row;
    }

    /**
     * Deletes up to $limit expired temporary uploads with their files, and
     * any file older than the lifetime that no row names any more (a crash
     * between deleting a row and its files); returns how many uploads went.
     */
    public function sweep(int $limit = 50): int
    {
        $count = 0;
        foreach ($this->repository->findExpiredUnclaimed($limit) as $expired) {
            if ($this->repository->deleteUnclaimed($expired['id'])) {
                $this->storage->delete($expired['storage_name'], $expired['extension']);
                $count++;
            }
        }

        $old = $this->storage->namesOlderThan(OrderFieldUploadPolicy::TTL_HOURS * 3600, $limit);
        foreach ($this->repository->unknownStorageNames(array_keys($old)) as $orphan) {
            $this->storage->delete($orphan, $old[$orphan]);
        }

        return $count;
    }

    private function refuse(string $kind, string $label, string $languageCode): OrderFieldUploadException
    {
        $sentence = match ($kind) {
            'expired' => [
                'nl' => 'De afbeelding bij "{label}" is niet meer beschikbaar. Voeg het product opnieuw toe met je afbeelding.',
                'en' => 'The picture for "{label}" is no longer available. Please add the product again with your picture.',
            ],
            'claimed' => [
                'nl' => 'De afbeelding bij "{label}" hoort al bij een bestelling. Voeg het product opnieuw toe met je afbeelding.',
                'en' => 'The picture for "{label}" already belongs to an order. Please add the product again with your picture.',
            ],
            default => [
                'nl' => 'De afbeelding bij "{label}" kan niet worden gebruikt. Voeg het product opnieuw toe met je afbeelding.',
                'en' => 'The picture for "{label}" cannot be used. Please add the product again with your picture.',
            ],
        };

        return new OrderFieldUploadException($kind, strtr(SiteText::pick($sentence, $languageCode), ['{label}' => $label]), 409);
    }
}
