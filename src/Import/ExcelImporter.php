<?php

declare(strict_types=1);

namespace App\Import;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * A meglévő ügyfél- és szerződésállomány importja egy .xlsx fájlból.
 *
 * A munkalap soronként egy szerződést tartalmaz, az ügyféladatok ismétlődnek.
 * Az ügyfeleket az import futásán belül a nevük (kisbetűsítve) alapján
 * deduplikáljuk, így egy ügyfélhez több szerződés is kapcsolódhat.
 */
final class ExcelImporter
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    /**
     * @return array{clients:int, contracts:int, skipped:int, errors:list<string>}
     */
    public function import(string $xlsxPath, int $officeId, ?int $ownerUserId): array
    {
        $clientsCreated = 0;
        $contractsCreated = 0;
        $skipped = 0;
        $errors = [];

        $spreadsheet = IOFactory::load($xlsxPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        if ($rows === []) {
            return ['clients' => 0, 'contracts' => 0, 'skipped' => 0, 'errors' => ['A munkalap üres.']];
        }

        $headerRow = array_shift($rows);
        $map = $this->buildHeaderMap(is_array($headerRow) ? $headerRow : []);

        if (!isset($map['ügyfél neve'])) {
            return [
                'clients' => 0,
                'contracts' => 0,
                'skipped' => 0,
                'errors' => ['Nem található az „Ügyfél neve” oszlop a fejlécben.'],
            ];
        }

        $clientStmt = $this->pdo->prepare(
            'INSERT INTO clients (office_id, owner_user_id, name, address, phone, mobile, email, status, created_at, updated_at)
             VALUES (:office_id, :owner_user_id, :name, :address, :phone, :mobile, :email, :status, :created_at, :updated_at)'
        );

        $contractStmt = $this->pdo->prepare(
            'INSERT INTO contracts
                (office_id, client_id, insurer_name, module_code, module_name, policy_number, offer_number,
                 plate, annual_fee, status, payment_frequency, payment_method, agent_code, agent_name,
                 risk_location, anniversary, start_date, end_date, created_at, updated_at)
             VALUES
                (:office_id, :client_id, :insurer_name, :module_code, :module_name, :policy_number, :offer_number,
                 :plate, :annual_fee, :status, :payment_frequency, :payment_method, :agent_code, :agent_name,
                 :risk_location, :anniversary, :start_date, :end_date, :created_at, :updated_at)'
        );

        /** @var array<string,int> $clientCache  kisbetűs név => client id */
        $clientCache = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                if (!is_array($row) || $this->isEmptyRow($row)) {
                    continue;
                }

                $rowNumber = $i + 2; // fejléc + 1-alapú számozás

                try {
                    $name = trim((string) $this->cell($row, $map, 'ügyfél neve'));
                    if ($name === '') {
                        $skipped++;
                        continue;
                    }

                    $cacheKey = mb_strtolower($name);
                    $now = date('Y-m-d H:i:s');

                    if (isset($clientCache[$cacheKey])) {
                        $clientId = $clientCache[$cacheKey];
                    } else {
                        $clientId = $this->findExistingClient($officeId, $name, $this->nullable($this->cell($row, $map, 'ügyfél email')));
                        if ($clientId === null) {
                            $clientStmt->execute([
                                'office_id' => $officeId,
                                'owner_user_id' => $ownerUserId,
                                'name' => $name,
                                'address' => $this->nullable($this->cell($row, $map, 'ügyfél címe')),
                                'phone' => $this->nullable($this->cell($row, $map, 'ügyfél telefon')),
                                'mobile' => $this->nullable($this->cell($row, $map, 'ügyfél mobil')),
                                'email' => $this->nullable($this->cell($row, $map, 'ügyfél email')),
                                'status' => 'active',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                            $clientId = (int) $this->pdo->lastInsertId();
                            $clientsCreated++;
                        }
                        $clientCache[$cacheKey] = $clientId;
                    }

                    $contractStmt->execute([
                        'office_id' => $officeId,
                        'client_id' => $clientId,
                        'insurer_name' => $this->nullable($this->cell($row, $map, 'biztosító')),
                        'module_code' => $this->nullable($this->cell($row, $map, 'módozat')),
                        'module_name' => $this->nullable($this->cell($row, $map, 'módozat neve')),
                        'policy_number' => $this->nullable($this->cell($row, $map, 'kötvényszám')),
                        'offer_number' => $this->nullable($this->cell($row, $map, 'ajánlatszám')),
                        'plate' => $this->nullable($this->cell($row, $map, 'rendszám')),
                        'annual_fee' => $this->parseNumber($this->cell($row, $map, 'éves díj')),
                        'status' => $this->nullable($this->cell($row, $map, 'státusz')),
                        'payment_frequency' => $this->nullable($this->cell($row, $map, 'díjfiz.gyak')),
                        'payment_method' => $this->nullable($this->cell($row, $map, 'díjfiz.módja')),
                        'agent_code' => $this->nullable($this->cell($row, $map, 'üzletkötő kód')),
                        'agent_name' => $this->nullable($this->cell($row, $map, 'üzletkötő név')),
                        'risk_location' => $this->nullable($this->cell($row, $map, 'kock. hely')),
                        'anniversary' => $this->nullable($this->cell($row, $map, 'évf.')),
                        'start_date' => $this->parseStartDate($row, $map),
                        'end_date' => $this->parseDate($this->cell($row, $map, 'bizt. vége')),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $contractsCreated++;
                } catch (Throwable $e) {
                    $skipped++;
                    if (count($errors) < 20) {
                        $errors[] = sprintf('%d. sor kihagyva: %s', $rowNumber, $e->getMessage());
                    }
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'clients' => $clientsCreated,
            'contracts' => $contractsCreated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Fejléc => oszlopindex leképezés (trim, kisbetűsítés).
     *
     * @param array<int,mixed> $headerRow
     * @return array<string,int>
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $index => $value) {
            $key = mb_strtolower(trim((string) $value));
            if ($key !== '' && !isset($map[$key])) {
                $map[$key] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * Egy ügyfél keresése az irodában név (és ha van, email) alapján.
     */
    private function findExistingClient(int $officeId, string $name, ?string $email): ?int
    {
        if ($email !== null && $email !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM clients WHERE office_id = :o AND LOWER(name) = LOWER(:n) AND LOWER(email) = LOWER(:e) LIMIT 1'
            );
            $stmt->execute(['o' => $officeId, 'n' => $name, 'e' => $email]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM clients WHERE office_id = :o AND LOWER(name) = LOWER(:n) LIMIT 1'
            );
            $stmt->execute(['o' => $officeId, 'n' => $name]);
        }

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<int,mixed> $row
     * @param array<string,int> $map
     */
    private function cell(array $row, array $map, string $header): string
    {
        $key = mb_strtolower($header);
        if (!isset($map[$key])) {
            return '';
        }
        $index = $map[$key];

        return isset($row[$index]) ? (string) $row[$index] : '';
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Szám-érték kinyerése (szóközök/ezres elválasztók nélkül).
     */
    private function parseNumber(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Szóközök és nem-törő szóközök eltávolítása, tizedesvessző -> pont.
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);
        $clean = str_replace(',', '.', $clean);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean) ?? '';
        if ($clean === '' || !is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    /**
     * Kezdő dátum az Év / Hó / Nap mezőkből, ha mindhárom megvan (ÉÉÉÉ-HH-NN).
     *
     * @param array<int,mixed> $row
     * @param array<string,int> $map
     */
    private function parseStartDate(array $row, array $map): ?string
    {
        $y = trim($this->cell($row, $map, 'év'));
        $m = trim($this->cell($row, $map, 'hó'));
        $d = trim($this->cell($row, $map, 'nap'));

        if ($y === '' || $m === '' || $d === '') {
            return null;
        }

        $yi = (int) preg_replace('/\D/', '', $y);
        $mi = (int) preg_replace('/\D/', '', $m);
        $di = (int) preg_replace('/\D/', '', $d);

        if ($yi < 1900 || $mi < 1 || $mi > 12 || $di < 1 || $di > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $yi, $mi, $di);
    }

    /**
     * Dátummá alakítás, ha az érték dátumnak tűnik (ÉÉÉÉ-HH-NN-re normálizálva).
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Gyakori formátumok: 2024-01-31, 2024.01.31, 2024/01/31
        if (preg_match('#^(\d{4})[.\-/](\d{1,2})[.\-/](\d{1,2})\.?$#', $value, $mm) === 1) {
            $y = (int) $mm[1];
            $m = (int) $mm[2];
            $d = (int) $mm[3];
            if ($m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
