<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCoreSchema extends AbstractMigration
{
    public function change(): void
    {
        // --- Irodák (legfelső bérlő) ---
        $this->table('offices')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('slug', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->create();

        // --- Irodánkénti titkosított beállítások ---
        $this->table('office_settings')
            ->addColumn('office_id', 'integer')
            ->addColumn('key', 'string', ['limit' => 100])
            ->addColumn('value_encrypted', 'text', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id', 'key'], ['unique' => true])            ->create();

        // --- Szerepek és jogosultságok (RBAC) ---
        $this->table('roles')
            ->addColumn('code', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 100])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('permissions')
            ->addColumn('code', 'string', ['limit' => 100])
            ->addColumn('name', 'string', ['limit' => 150])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // --- Felhasználók ---
        $this->table('users')
            ->addColumn('office_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('email', 'string', ['limit' => 191])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('twofa_secret', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['email'], ['unique' => true])
            ->addIndex(['office_id'])
            ->create();

        $this->table('role_user')
            ->addColumn('user_id', 'integer')
            ->addColumn('role_id', 'integer')
            ->addIndex(['user_id', 'role_id'], ['unique' => true])            ->create();

        $this->table('permission_role')
            ->addColumn('permission_id', 'integer')
            ->addColumn('role_id', 'integer')
            ->addIndex(['permission_id', 'role_id'], ['unique' => true])
            ->create();

        // --- Partnerek (ügyfelek) ---
        $this->table('clients')
            ->addColumn('office_id', 'integer')
            ->addColumn('owner_user_id', 'integer', ['null' => true])
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('address', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('mobile', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('email', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('tax_id', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('birth_date', 'date', ['null' => true])
            ->addColumn('birth_place', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('mother_name', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 50, 'default' => 'active'])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['name'])            ->create();

        // --- Biztosítók ---
        $this->table('insurers')
            ->addColumn('office_id', 'integer')
            ->addColumn('name', 'string', ['limit' => 191])
            ->addColumn('default_emails', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])            ->create();

        // --- Szerződések / kötvények (az Excel-mezők alapján) ---
        $this->table('contracts')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer')
            ->addColumn('insurer_id', 'integer', ['null' => true])
            ->addColumn('category', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('insurer_name', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('module_code', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('module_name', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('policy_number', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('offer_number', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('start_date', 'date', ['null' => true])
            ->addColumn('end_date', 'date', ['null' => true])
            ->addColumn('anniversary', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('plate', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('annual_fee', 'decimal', ['precision' => 12, 'scale' => 2, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('terminated_reason', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('agent_code', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('agent_name', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('payment_frequency', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('payment_method', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('risk_location', 'string', ['limit' => 255, 'null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['client_id'])
            ->addIndex(['end_date'])
            ->create();

        // --- Dokumentumok ---
        $this->table('documents')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer', ['null' => true])
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('original_name', 'string', ['limit' => 255])
            ->addColumn('stored_path', 'string', ['limit' => 255])
            ->addColumn('mime', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('size_bytes', 'integer', ['null' => true])
            ->addColumn('visibility', 'string', ['limit' => 20, 'default' => 'agent_only'])
            ->addColumn('uploaded_by', 'integer', ['null' => true])
            ->addTimestamps()
            ->addIndex(['office_id'])
            ->addIndex(['client_id'])            ->create();

        // --- Háttérfeladatok (egyszerű DB-alapú sor) ---
        $this->table('jobs')
            ->addColumn('queue', 'string', ['limit' => 50, 'default' => 'default'])
            ->addColumn('type', 'string', ['limit' => 100])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('attempts', 'integer', ['default' => 0])
            ->addColumn('available_at', 'datetime', ['null' => true])
            ->addColumn('reserved_at', 'datetime', ['null' => true])
            ->addTimestamps()
            ->addIndex(['queue', 'reserved_at'])
            ->create();

        // --- Audit napló ---
        $this->table('audit_logs')
            ->addColumn('office_id', 'integer', ['null' => true])
            ->addColumn('user_id', 'integer', ['null' => true])
            ->addColumn('action', 'string', ['limit' => 100])
            ->addColumn('entity', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('entity_id', 'integer', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['office_id'])
            ->create();
    }
}
