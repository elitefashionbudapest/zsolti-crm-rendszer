<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * HTML-törzs a beérkező leveleknél + mellékletek tárolása (partnerhez menthető,
 * később AI-kinyeréshez).
 */
final class AddEmailHtmlAndAttachments extends AbstractMigration
{
    public function change(): void
    {
        $this->table('incoming_emails')
            ->addColumn('body_html', 'text', ['null' => true, 'after' => 'body'])
            ->update();

        $this->table('incoming_email_attachments')
            ->addColumn('office_id', 'integer')
            ->addColumn('email_id', 'integer')
            ->addColumn('filename', 'string', ['limit' => 255])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('size_bytes', 'integer', ['null' => true])
            ->addColumn('stored_path', 'string', ['limit' => 255])
            ->addColumn('saved_document_id', 'integer', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['email_id'])
            ->create();
    }
}
