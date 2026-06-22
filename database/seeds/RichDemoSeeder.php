<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Gazdag demó adat a teljes rendszer kipróbálásához: ügyfelek, szerződések
 * (közelgő lejáratokkal/évfordulókkal), dokumentumok, feladatok, leadek,
 * jutalékok, biztosítók + címlisták, e-mail sablonok/folyamatok/napló,
 * tanácsadói anyagok, beérkező levelek és egy AI-kinyerés.
 */
final class RichDemoSeeder extends AbstractSeed
{
    public function run(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->getAdapter()->getConnection();
        $now = date('Y-m-d H:i:s');

        $office = $pdo->query("SELECT id FROM offices WHERE slug = 'demo' LIMIT 1")->fetchColumn();
        if ($office === false) {
            return;
        }
        $office = (int) $office;
        $agent = (int) ($pdo->query("SELECT id FROM users WHERE email = 'ugynok@aegis.test' LIMIT 1")->fetchColumn() ?: 0);
        $portalClientId = (int) ($pdo->query("SELECT id FROM clients WHERE email = 'ugyfel@aegis.test' AND office_id = $office LIMIT 1")->fetchColumn() ?: 0);

        $d = static fn (string $rel): string => date('Y-m-d', strtotime($rel));
        $dt = static fn (string $rel): string => date('Y-m-d H:i:s', strtotime($rel));
        $md = static fn (string $rel): string => date('m.d', strtotime($rel));

        // --- Biztosítók ---
        $insurers = [];
        foreach ([
            ['UNION Biztosító', 'kar@union.hu, ajanlat@union.hu'],
            ['Allianz Hungária', 'beerkezo@allianz.hu'],
            ['Generali Biztosító', 'ugyfel@generali.hu'],
            ['MetLife', 'kotveny@metlife.hu'],
            ['CIG Pannónia', 'iroda@cig.hu'],
        ] as [$n, $emails]) {
            $pdo->prepare('INSERT INTO insurers (office_id, name, default_emails, is_active, created_at, updated_at) VALUES (?,?,?,1,?,?)')
                ->execute([$office, $n, $emails, $now, $now]);
            $insurers[$n] = (int) $pdo->lastInsertId();
        }
        // Egy terméktípus-specifikus címlista
        $pdo->prepare('INSERT INTO insurer_email_routes (office_id, insurer_id, category, emails, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$office, $insurers['UNION Biztosító'], 'elet_egeszseg', 'elet@union.hu', $now, $now]);
        $pdo->prepare('INSERT INTO insurer_email_routes (office_id, insurer_id, category, emails, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$office, $insurers['Allianz Hungária'], 'vagyon', 'lakas@allianz.hu, gepjarmu@allianz.hu', $now, $now]);

        // --- Ügyfelek ---
        $clientNames = [
            ['Nagy Péter', 'nagy.peter@example.hu', '+36 30 111 2233', '1051 Budapest, Fő utca 12.'],
            ['Kiss Erzsébet', 'kiss.erzsebet@example.hu', '+36 20 222 3344', '6720 Szeged, Kárász u. 5.'],
            ['Tóth Gábor', 'toth.gabor@example.hu', '+36 70 333 4455', '4024 Debrecen, Piac u. 30.'],
            ['Szabó Réka', 'szabo.reka@example.hu', '+36 30 444 5566', '7621 Pécs, Király u. 8.'],
            ['Horváth László', 'horvath.laszlo@example.hu', '+36 20 555 6677', '9021 Győr, Baross út 14.'],
            ['Varga Anna', 'varga.anna@example.hu', '+36 70 666 7788', '3530 Miskolc, Széchenyi u. 22.'],
            ['Németh József', 'nemeth.jozsef@example.hu', '+36 30 777 8899', '8000 Székesfehérvár, Fő u. 3.'],
            ['Balogh Katalin', 'balogh.katalin@example.hu', '+36 20 888 9900', '2400 Dunaújváros, Vasmű út 41.'],
            ['Farkas Tamás', 'farkas.tamas@example.hu', '+36 70 999 0011', '5000 Szolnok, Kossuth tér 1.'],
            ['Molnár Ágnes', 'molnar.agnes@example.hu', '+36 30 121 3141', '8900 Zalaegerszeg, Kossuth u. 9.'],
            ['B-Lux General Kft.', 'blux@example.hu', '+36 1 198 8000', '1222 Budapest, Vöröskereszt u. 8.'],
            ['Sz. Nagy Gábor', 'nagy.gabor.sz@example.hu', '+36 70 771 1866', '5540 Szarvas, Állomás u. 2.'],
        ];
        $clientIds = [];
        foreach ($clientNames as $i => [$name, $email, $phone, $address]) {
            $pdo->prepare('INSERT INTO clients (office_id, owner_user_id, name, email, phone, mobile, address, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$office, $agent, $name, $email, $phone, $phone, $address, $i === 9 ? 'inactive' : 'active', $now, $now]);
            $clientIds[] = (int) $pdo->lastInsertId();
        }
        if ($portalClientId > 0) {
            $clientIds[] = $portalClientId; // a portál-ügyfél is kap szerződéseket
        }

        // --- Szerződések (közelgő lejáratokkal/évfordulókkal) ---
        $catList = ['elet_egeszseg', 'vagyon', 'nyugdij_megtakaritas', 'befektetes'];
        $modByCat = [
            'elet_egeszseg' => ['Kockázati életbiztosítás', 'Bárka életbiztosítás'],
            'vagyon' => ['Lakásbiztosítás', 'KGFB — gépjármű', 'Casco'],
            'nyugdij_megtakaritas' => ['Nyugdíjbiztosítás', 'Nyitány megtakarítás'],
            'befektetes' => ['MyLife Extra befektetés', 'Eszencia befektetés'],
        ];
        $insurerNames = array_keys($insurers);
        $freq = ['Havi', 'Negyedéves', 'Éves'];
        $endRels = ['+8 days', '+21 days', '+45 days', '+120 days', '+1 year', '-30 days'];
        $contractIds = [];
        $n = 0;
        foreach ($clientIds as $ci) {
            $count = ($n % 3) + 1; // 1-3 szerződés ügyfelenként
            for ($k = 0; $k < $count; $k++) {
                $cat = $catList[($n + $k) % 4];
                $mods = $modByCat[$cat];
                $insName = $insurerNames[($n + $k) % count($insurerNames)];
                $endRel = $endRels[($n + $k) % count($endRels)];
                $annRel = ['+5 days', '+18 days', '+60 days', '+200 days'][($n + $k) % 4];
                $status = $endRel === '-30 days' ? 'terminated' : 'active';
                $pdo->prepare(
                    'INSERT INTO contracts (office_id, client_id, insurer_id, category, insurer_name, module_name, policy_number, start_date, end_date, anniversary, annual_fee, status, payment_frequency, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $office, $ci, $insurers[$insName], $cat, $insName, $mods[$k % count($mods)],
                    sprintf('POL-%05d', 10000 + $n * 3 + $k), $d('-2 years'), $d($endRel), $md($annRel),
                    (string) (12000 + (($n + $k) % 9) * 8500), $status, $freq[($n + $k) % 3], $now, $now,
                ]);
                $contractIds[] = ['id' => (int) $pdo->lastInsertId(), 'client' => $ci];
                $n++;
            }
        }

        // --- Dokumentumok (+ pár valódi placeholder fájl a portál-ügyfélnek) ---
        $uploadBase = dirname(__DIR__, 2) . '/storage/uploads/office_' . $office;
        if (!is_dir($uploadBase)) {
            @mkdir($uploadBase, 0775, true);
        }
        $docs = [
            ['Kötvény másolat.pdf', 'kotveny', 'shared', $portalClientId],
            ['Ajánlat 2026.pdf', 'ajanlat', 'shared', $portalClientId],
            ['Személyi igazolvány.jpg', 'azonosito', 'agent_only', $clientIds[0]],
            ['Aláírt megbízás.pdf', 'megbizas', 'agent_only', $clientIds[1]],
            ['Kárbejelentő.pdf', 'kar', 'shared', $clientIds[2]],
        ];
        foreach ($docs as $i => [$orig, $type, $vis, $cid]) {
            $stored = 'office_' . $office . '/demo_' . $i . '.txt';
            @file_put_contents(dirname(__DIR__, 2) . '/storage/uploads/' . $stored, "Demó dokumentum: $orig\n");
            $pdo->prepare('INSERT INTO documents (office_id, client_id, type, original_name, stored_path, mime, size_bytes, visibility, uploaded_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$office, $cid, $type, $orig, $stored, 'text/plain', 42, $vis, $agent, $now, $now]);
        }

        // --- Feladatok ---
        $tasks = [
            ['Ügyfél visszahívása – Nagy Péter', 'today 14:00', 'open', 'high', $clientIds[0]],
            ['KGFB megújítás jóváhagyása', 'tomorrow 10:00', 'open', 'normal', $clientIds[2]],
            ['Hiányzó dokumentum bekérése', '+2 days 09:00', 'open', 'normal', $clientIds[1]],
            ['AI-kinyerés ellenőrzése', '+3 days 11:00', 'open', 'high', $clientIds[3]],
            ['Évforduló e-mail jóváhagyása', '-1 day 16:00', 'done', 'normal', $clientIds[4]],
            ['Ajánlat kiküldése', '+5 days 13:00', 'open', 'low', $clientIds[5]],
        ];
        foreach ($tasks as [$title, $due, $st, $pr, $cid]) {
            $pdo->prepare('INSERT INTO tasks (office_id, client_id, assigned_to, title, due_at, status, priority, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$office, $cid, $agent, $title, $dt($due), $st, $pr, $now, $now]);
        }

        // --- Leadek ---
        $leads = [
            ['Kovács Bence', 'kovacs.bence@example.hu', '+36 30 100 2000', 'Weboldal', 'new'],
            ['Szilágyi Mária', 'szilagyi.maria@example.hu', '+36 20 200 3000', 'Ajánlás', 'contacted'],
            ['Fekete Dániel', 'fekete.daniel@example.hu', '+36 70 300 4000', 'Facebook', 'offer'],
            ['Lakatos Júlia', 'lakatos.julia@example.hu', '+36 30 400 5000', 'Hideghívás', 'won'],
            ['Oláh Zoltán', 'olah.zoltan@example.hu', '+36 20 500 6000', 'Weboldal', 'lost'],
            ['Pataki Eszter', 'pataki.eszter@example.hu', '+36 70 600 7000', 'Ajánlás', 'contacted'],
        ];
        foreach ($leads as [$nm, $em, $ph, $src, $stg]) {
            $pdo->prepare('INSERT INTO leads (office_id, name, email, phone, source, stage, assigned_to, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$office, $nm, $em, $ph, $src, $stg, $agent, $now, $now]);
        }

        // --- Jutalékok ---
        foreach (array_slice($contractIds, 0, 8) as $i => $c) {
            $settled = $i % 2 === 0;
            $pdo->prepare('INSERT INTO commissions (office_id, contract_id, user_id, amount, status, settled_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$office, $c['id'], $agent, (string) (15000 + $i * 4200), $settled ? 'settled' : 'pending', $settled ? $d('-10 days') : null, $now, $now]);
        }

        // --- E-mail sablonok + folyamatok + napló ---
        $pdo->prepare('INSERT INTO email_templates (office_id, name, subject, body, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$office, 'Évforduló emlékeztető', 'Közeleg a szerződése évfordulója', '<p>Tisztelt Ügyfelünk! Hamarosan esedékes a szerződése évfordulója.</p>', $now, $now]);
        $tpl1 = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO email_templates (office_id, name, subject, body, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$office, 'Üdvözlő levél', 'Üdvözöljük az irodánknál', '<p>Köszönjük a bizalmát!</p>', $now, $now]);
        $pdo->prepare('INSERT INTO email_workflows (office_id, name, template_id, trigger_type, trigger_days, audience, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,1,?,?)')
            ->execute([$office, 'Évforduló – 30 nappal előtte', $tpl1, 'anniversary', 30, 'all_clients', $now, $now]);
        $pdo->prepare('INSERT INTO email_workflows (office_id, name, template_id, trigger_type, audience, is_active, created_at, updated_at) VALUES (?,?,?,?,?,1,?,?)')
            ->execute([$office, 'Hírlevél (kézi)', $tpl1, 'newsletter', 'all_clients', $now, $now]);
        foreach ([['nagy.peter@example.hu', 'sent'], ['kiss.erzsebet@example.hu', 'sent'], ['toth.gabor@example.hu', 'failed']] as [$to, $st]) {
            $pdo->prepare('INSERT INTO email_sends (office_id, to_email, subject, status, error, sent_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$office, $to, 'Közeleg a szerződése évfordulója', $st, $st === 'failed' ? 'SMTP nincs beállítva' : null, $st === 'sent' ? $now : null, $now, $now]);
        }

        // --- Tanácsadói anyagok ---
        foreach ([
            ['Mire figyeljen lakásbiztosításnál?', 'Néhány hasznos tipp a megfelelő fedezet kiválasztásához.', null],
            ['Nyugdíj-előtakarékosság alapjai', 'Hogyan tervezzen tudatosan a nyugdíjas évekre.', null],
            ['Kárbejelentés lépésről lépésre', 'Mit tegyen, ha káresemény történik.', $portalClientId],
        ] as [$t, $b, $cid]) {
            $pdo->prepare('INSERT INTO advisory_resources (office_id, title, body, client_id, is_published, created_at, updated_at) VALUES (?,?,?,?,1,?,?)')
                ->execute([$office, $t, $b, $cid, $now, $now]);
        }

        // --- Beérkező e-mailek ---
        foreach ([
            ['nagy.peter@example.hu', 'Ajánlatkérés lakásbiztosításra', 'Szeretnék ajánlatot kérni a lakásomra.', 'ajanlatkeres'],
            ['kar@union.hu', 'Kárrendezés – POL-10001', 'A kárbejelentését feldolgoztuk.', 'kar'],
            ['szabo.reka@example.hu', 'Adatmódosítás', 'Megváltozott a telefonszámom.', 'adminisztrativ'],
            ['info@allianz.hu', 'Díjértesítő', 'Esedékes díj értesítő.', 'adminisztrativ'],
        ] as $i => [$from, $subj, $body, $cat]) {
            $pdo->prepare('INSERT INTO incoming_emails (office_id, message_uid, from_email, subject, body, category, received_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$office, 'demo-' . $i, $from, $subj, $body, $cat, $dt('-' . ($i + 1) . ' hours'), $now, $now]);
        }

        // --- AI-kinyerés (jóváhagyásra váró) ---
        $fields = json_encode([
            'client_name' => 'Kovács Bence', 'client_email' => 'kovacs.bence@example.hu',
            'client_phone' => '+36 30 100 2000', 'insurer_name' => 'Generali Biztosító',
            'module_name' => 'Lakásbiztosítás', 'policy_number' => 'AJ-2026-0042',
            'start_date' => $d('+10 days'), 'annual_fee' => '54000',
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO extracted_data (office_id, fields, status, model, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$office, $fields, 'pending', 'claude-opus-4-8', $agent, $now, $now]);

        // --- Néhány értesítés ---
        foreach (['Új lead érkezett a weboldalról', 'AI-kinyerés vár jóváhagyásra', '3 szerződés hamarosan lejár'] as $t) {
            $pdo->prepare('INSERT INTO notifications (office_id, user_id, type, title, created_at) VALUES (?,?,?,?,?)')
                ->execute([$office, $agent, 'info', $t, $now]);
        }
    }
}
