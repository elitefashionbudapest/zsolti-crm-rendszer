<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateClientAttributes extends AbstractMigration
{
    public function change(): void
    {
        // Címezhető kulcs-érték adatok a partnerhez (AI-kinyerésből vagy kézzel).
        // Minden kinyert extra mező egy sor — így másik dokumentum (sablon) is
        // kitölthető belőle a TemplateFiller lapos [kulcs => érték] térképén át.
        $this->table('client_attributes')
            ->addColumn('office_id', 'integer')
            ->addColumn('client_id', 'integer')
            ->addColumn('contract_id', 'integer', ['null' => true])
            ->addColumn('extraction_id', 'integer', ['null' => true])
            // csoport: szerzodo / biztositott_1 / biztositott_2 / kedvezmenyezett / bank / szerzodes / egyeb
            ->addColumn('attr_group', 'string', ['limit' => 50, 'default' => 'egyeb'])
            // stabil, gépi kulcs (snake_case), pl. iban, foglalkozas, okmany_szam
            ->addColumn('attr_key', 'string', ['limit' => 80])
            // magyar felirat a megjelenítéshez
            ->addColumn('label', 'string', ['limit' => 191])
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['office_id'])
            ->addIndex(['client_id'])
            // egy partneren belül (adott szerződéshez és csoporthoz) a kulcs egyedi
            // → az ismételt kinyerés felül tud írni, nem duplikál
            ->addIndex(['client_id', 'contract_id', 'attr_group', 'attr_key'], ['unique' => true])
            ->create();
    }
}
