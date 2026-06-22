<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * A demó ügyfelek és szerződések MINDEN mezőjének kitöltése (a meglévő üres
 * mezőkbe ír — COALESCE), hogy minden adat látszódjon. Pár belső megjegyzés is.
 */
final class FillDemoFieldsSeeder extends AbstractSeed
{
    public function run(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->getAdapter()->getConnection();
        $office = $pdo->query("SELECT id FROM offices WHERE slug = 'demo' LIMIT 1")->fetchColumn();
        if ($office === false) {
            return;
        }
        $office = (int) $office;
        $now = date('Y-m-d H:i:s');

        $birthPlaces = ['Budapest', 'Szeged', 'Debrecen', 'Pécs', 'Győr', 'Miskolc', 'Székesfehérvár', 'Szolnok', 'Kecskemét', 'Nyíregyháza'];
        $motherNames = ['Nagy Mária', 'Kiss Erzsébet', 'Tóth Ilona', 'Szabó Katalin', 'Kovács Éva', 'Horváth Anna', 'Varga Judit', 'Németh Zsuzsanna'];
        $payMethods = ['Csoportos beszedés', 'Átutalás', 'Csekk', 'Bankkártya'];

        // --- Ügyfelek ---
        $clients = $pdo->prepare('SELECT id, address FROM clients WHERE office_id = :o');
        $clients->execute(['o' => $office]);
        $upd = $pdo->prepare(
            'UPDATE clients SET
                tax_id = COALESCE(tax_id, :tax),
                birth_date = COALESCE(birth_date, :bd),
                birth_place = COALESCE(birth_place, :bp),
                mother_name = COALESCE(mother_name, :mn),
                notes = COALESCE(notes, :nt),
                updated_at = :u
             WHERE id = :id AND office_id = :o'
        );
        $i = 0;
        foreach ($clients->fetchAll() as $c) {
            $id = (int) $c['id'];
            $upd->execute([
                'tax' => '8' . str_pad((string) (100000000 + $id * 137), 9, '0', STR_PAD_LEFT),
                'bd' => sprintf('19%02d-%02d-%02d', 60 + ($id % 35), 1 + ($id % 12), 1 + ($id % 27)),
                'bp' => $birthPlaces[$i % count($birthPlaces)],
                'mn' => $motherNames[$i % count($motherNames)],
                'nt' => 'Demó ügyfél – minden adat kitöltve a bemutatóhoz.',
                'u' => $now, 'id' => $id, 'o' => $office,
            ]);
            $i++;
        }

        // --- Szerződések ---
        $contracts = $pdo->prepare('SELECT ct.id, ct.category, ct.status, cl.address AS caddr FROM contracts ct LEFT JOIN clients cl ON cl.id = ct.client_id WHERE ct.office_id = :o');
        $contracts->execute(['o' => $office]);
        $updc = $pdo->prepare(
            'UPDATE contracts SET
                offer_number = COALESCE(offer_number, :off),
                agent_code = COALESCE(agent_code, :ac),
                agent_name = COALESCE(agent_name, :an),
                payment_method = COALESCE(payment_method, :pm),
                risk_location = COALESCE(risk_location, :rl),
                terminated_reason = CASE WHEN status = :st1 THEN COALESCE(terminated_reason, :tr) ELSE terminated_reason END,
                plate = CASE WHEN category = :cat1 THEN COALESCE(plate, :pl) ELSE plate END,
                updated_at = :u
             WHERE id = :id AND office_id = :o'
        );
        $j = 0;
        foreach ($contracts->fetchAll() as $c) {
            $id = (int) $c['id'];
            $plates = ['ABC-' . (100 + $j), 'MYJ-' . (200 + $j), 'NWV-' . (300 + $j)];
            $updc->execute([
                'off' => sprintf('AJ-%06d', 200000 + $id),
                'ac' => 'KB' . str_pad((string) (1 + ($j % 5)), 3, '0', STR_PAD_LEFT),
                'an' => 'Kis Balázs',
                'pm' => $payMethods[$j % count($payMethods)],
                'rl' => (string) ($c['caddr'] ?? 'Budapest'),
                'st1' => 'terminated',
                'tr' => 'Díj-nemfizetés',
                'cat1' => 'vagyon',
                'pl' => $plates[$j % 3],
                'u' => $now, 'id' => $id, 'o' => $office,
            ]);
            $j++;
        }

        // --- Pár belső megjegyzés (ha még nincs) ---
        $has = (int) $pdo->query("SELECT COUNT(*) FROM client_notes WHERE office_id = $office")->fetchColumn();
        if ($has === 0) {
            $agent = (int) ($pdo->query("SELECT id FROM users WHERE email = 'ugynok@aegis.test' LIMIT 1")->fetchColumn() ?: 0);
            $someClients = $pdo->query("SELECT id FROM clients WHERE office_id = $office LIMIT 4")->fetchAll();
            $sampleNotes = [
                'Telefonon egyeztetve, jövő héten visszahívni az ajánlattal.',
                'Érdeklődik a nyugdíjbiztosítás iránt is — küldeni kell tájékoztatót.',
                'Kárügy folyamatban, dokumentumok bekérve.',
                'Elégedett ügyfél, ajánlást ígért.',
            ];
            foreach ($someClients as $k => $cl) {
                $pdo->prepare('INSERT INTO client_notes (office_id, client_id, user_id, body, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$office, (int) $cl['id'], $agent, $sampleNotes[$k % count($sampleNotes)], $now]);
            }
        }
    }
}
