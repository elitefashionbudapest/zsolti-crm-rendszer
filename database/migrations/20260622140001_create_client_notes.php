<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateClientNotes extends AbstractMigration
{
    public function change(): void
    {
        // Belső megjegyzések a partnerhez (csak az iroda látja)
        $this->table('client_notes')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('body', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['office_id'])
            ->addIndex(['client_id'])
            ->create();
    }
}
