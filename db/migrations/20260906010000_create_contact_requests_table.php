<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Persistent storage for the "Offerte aanvragen" contact form
 * (POST /api/contact.php). The database row is now the source of truth for
 * a valid submission — the notification email sent to the shop owner is a
 * best-effort side effect (see notification_sent_at) rather than the only
 * copy of the enquiry. Deliberately small/explicit, not a CRM: no stages,
 * no customer accounts, no internal notes, no subject field.
 */
final class CreateContactRequestsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('contact_requests');
        $table
            ->addColumn('name', 'string', ['limit' => 150])
            ->addColumn('email', 'string', ['limit' => 190])
            ->addColumn('phone', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('audience', 'string', ['limit' => 20, 'default' => 'particulier'])
            ->addColumn('message', 'text')
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'nieuw'])
            ->addColumn('notification_sent_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['status'])
            ->addIndex(['created_at'])
            ->create();
    }
}
