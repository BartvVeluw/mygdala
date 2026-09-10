<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The (at most one, today) file a visitor attaches to a contact request.
 * A separate child table rather than columns on contact_requests: an
 * attachment is its own resource with its own file-storage metadata, and
 * this shape needs no schema change if the form ever accepts more than one
 * file. ON DELETE CASCADE: deleting a contact request always removes its
 * attachment row too (the physical file itself is removed by application
 * code — see App\Service\ContactAttachmentStorage — since a DB cascade
 * can't touch the filesystem).
 */
final class CreateContactRequestAttachmentsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('contact_request_attachments');
        $table
            ->addColumn('contact_request_id', 'integer', ['signed' => false])
            ->addColumn('stored_filename', 'string', ['limit' => 255])
            ->addColumn('original_filename', 'string', ['limit' => 255])
            ->addColumn('mime_type', 'string', ['limit' => 100])
            ->addColumn('file_size', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addForeignKey('contact_request_id', 'contact_requests', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['contact_request_id'])
            ->create();
    }
}
