<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddModuleTables extends AbstractMigration
{
    public function change(): void
    {
        // Biztosítói e-mail útvonalak (biztosító + opcionális terméktípus → címek)
        $this->table('insurer_email_routes')
            ->addColumn('office_id', 'integer')
            ->addColumn('insurer_id', 'integer')
            ->addColumn('category', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('emails', 'text')
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['insurer_id'])
            ->create();

        // Biztosítói küldés napló
        $this->table('insurer_dispatches')
            ->addColumn('office_id', 'integer')
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('insurer_id', 'integer', ['null' => true])
            ->addColumn('recipients', 'text', ['null' => true])
            ->addColumn('document_ids', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 30, 'default' => 'queued'])
            ->addColumn('error', 'text', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Dokumentum-sablonok (AcroForm / lapos PDF / DOCX)
        $this->table('document_templates')
            ->addColumn('office_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('kind', 'string', ['limit' => 20]) // acroform | overlay | docx
            ->addColumn('stored_path', 'string', ['limit' => 255])
            ->addColumn('field_map', 'text', ['null' => true]) // JSON
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Generált (kitöltött) dokumentumok
        $this->table('generated_documents')
            ->addColumn('office_id', 'integer')
            ->addColumn('template_id', 'integer', ['null' => true])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('stored_path', 'string', ['limit' => 255])
            ->addColumn('original_name', 'string', ['limit' => 255])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // AI által kinyert adatok (jóváhagyásra)
        $this->table('extracted_data')
            ->addColumn('office_id', 'integer')
            ->addColumn('document_id', 'integer', ['null' => true])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('fields', 'text', ['null' => true]) // JSON
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending']) // pending|approved|rejected
            ->addColumn('model', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('created_by', 'integer', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['status'])
            ->create();

        // E-mail sablonok
        $this->table('email_templates')
            ->addColumn('office_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('body', 'text')
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // E-mail folyamatok
        $this->table('email_workflows')
            ->addColumn('office_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('template_id', 'integer', ['null' => true])
            ->addColumn('trigger_type', 'string', ['limit' => 40, 'default' => 'manual']) // manual|anniversary|expiry|welcome|newsletter
            ->addColumn('trigger_days', 'integer', ['null' => true])
            ->addColumn('audience', 'text', ['null' => true]) // JSON: szegmens/címlista
            ->addColumn('schedule_at', 'datetime', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // E-mail kiküldési napló
        $this->table('email_sends')
            ->addColumn('office_id', 'integer')
            ->addColumn('workflow_id', 'integer', ['null' => true])
            ->addColumn('to_email', 'string', ['limit' => 191])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'queued']) // queued|sent|failed
            ->addColumn('error', 'text', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Ügyfél által beküldött adatok (jóváhagyásra)
        $this->table('client_intake_submissions')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('submitted_by', 'integer', ['null' => true])
            ->addColumn('payload', 'text') // JSON
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Tanácsadói anyagok
        $this->table('advisory_resources')
            ->addColumn('office_id', 'integer')
            ->addColumn('title', 'string', ['limit' => 191])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('stored_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('client_id', 'integer', ['null' => true]) // null = mindenkinek
            ->addColumn('is_published', 'boolean', ['default' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // IMAP postafiókok
        $this->table('mailboxes')
            ->addColumn('office_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('email', 'string', ['limit' => 191])
            ->addColumn('imap_host', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('imap_port', 'integer', ['null' => true])
            ->addColumn('username_enc', 'text', ['null' => true])
            ->addColumn('password_enc', 'text', ['null' => true])
            ->addColumn('last_sync_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Beérkező e-mailek (AI-feldolgozással)
        $this->table('incoming_emails')
            ->addColumn('office_id', 'integer')
            ->addColumn('mailbox_id', 'integer', ['null' => true])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('message_uid', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('from_email', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('category', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('received_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Naptáresemények (Google sync)
        $this->table('calendar_events')
            ->addColumn('office_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('google_event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('title', 'string', ['limit' => 191])
            ->addColumn('starts_at', 'datetime', ['null' => true])
            ->addColumn('ends_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Feladatok
        $this->table('tasks')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('assigned_to', 'integer', ['null' => true])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('due_at', 'datetime', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'open']) // open|done
            ->addColumn('priority', 'string', ['limit' => 20, 'default' => 'normal'])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['status'])
            ->create();

        // Jutalékok
        $this->table('commissions')
            ->addColumn('office_id', 'integer')
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('amount', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending']) // pending|settled
            ->addColumn('settled_at', 'date', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();

        // Leadek
        $this->table('leads')
            ->addColumn('office_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('email', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('stage', 'string', ['limit' => 40, 'default' => 'new']) // new|contacted|offer|won|lost
            ->addColumn('assigned_to', 'integer', ['null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['stage'])
            ->create();

        // Értesítések
        $this->table('notifications')
            ->addColumn('office_id', 'integer')
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('type', 'string', ['limit' => 50])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['office_id'])
            ->create();

        // Emlékeztetők (lejárat/évforduló)
        $this->table('reminders')
            ->addColumn('office_id', 'integer')
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('type', 'string', ['limit' => 40]) // expiry|anniversary
            ->addColumn('remind_on', 'date', ['null' => true])
            ->addColumn('handled', 'boolean', ['default' => false])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->create();
    }
}
