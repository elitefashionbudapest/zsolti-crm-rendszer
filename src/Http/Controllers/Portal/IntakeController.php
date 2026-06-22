<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Ügyfélportál: Adataim önkitöltő varázsló (3 lépés).
 * A beküldött adatok jóváhagyásra várnak (client_intake_submissions, status='pending').
 */
final class IntakeController
{
    /** A varázslóban kezelt mezők. */
    private const FIELDS = [
        'vezeteknev', 'keresztnev', 'szuletesi_datum', 'szuletesi_hely',
        'anyja_neve', 'adoazonosito', 'email', 'telefon',
        'iranyitoszam', 'varos', 'kozterulet', 'hazszam',
    ];

    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();

        return $this->twig->render($response, 'portal/intake.twig', [
            'active' => 'data',
            'form' => $this->prefill($clientId, $officeId),
            'has_pending' => $this->hasPending($clientId, $officeId),
            'submitted' => false,
            'flash' => $this->flash(),
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();

        $body = (array) $request->getParsedBody();
        $data = [];
        foreach (self::FIELDS as $f) {
            $data[$f] = trim((string) ($body[$f] ?? ''));
        }

        if ($clientId !== null && $officeId !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO client_intake_submissions '
                . '(office_id, client_id, submitted_by, payload, status, created_at, updated_at) '
                . 'VALUES (:office_id, :client_id, :submitted_by, :payload, :status, :created_at, :updated_at)'
            );
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $stmt->execute([
                'office_id' => $officeId,
                'client_id' => $clientId,
                'submitted_by' => $this->auth->id(),
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->twig->render($response, 'portal/intake.twig', [
            'active' => 'data',
            'form' => $data,
            'has_pending' => true,
            'submitted' => true,
            'flash' => null,
        ]);
    }

    /**
     * Előtöltés a clients sorból (best-effort névbontással).
     *
     * @return array<string,string>
     */
    private function prefill(?int $clientId, ?int $officeId): array
    {
        $data = array_fill_keys(self::FIELDS, '');

        if ($clientId === null || $officeId === null) {
            return $data;
        }

        $stmt = $this->pdo->prepare(
            'SELECT name, email, phone, mobile, address, tax_id, birth_date, birth_place, mother_name '
            . 'FROM clients WHERE id = :id AND office_id = :office_id LIMIT 1'
        );
        $stmt->execute(['id' => $clientId, 'office_id' => $officeId]);
        $client = $stmt->fetch();
        if ($client === false) {
            return $data;
        }

        // Név bontása: az utolsó szó a keresztnév, a többi a vezetéknév (magyar névsorrend).
        $name = trim((string) ($client['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [$name];
            if (count($parts) >= 2) {
                $data['keresztnev'] = (string) array_pop($parts);
                $data['vezeteknev'] = implode(' ', $parts);
            } else {
                $data['vezeteknev'] = $name;
            }
        }

        $data['email'] = (string) ($client['email'] ?? '');
        $data['telefon'] = (string) ($client['phone'] ?? $client['mobile'] ?? '');
        $data['szuletesi_datum'] = (string) ($client['birth_date'] ?? '');
        $data['szuletesi_hely'] = (string) ($client['birth_place'] ?? '');
        $data['anyja_neve'] = (string) ($client['mother_name'] ?? '');
        $data['adoazonosito'] = (string) ($client['tax_id'] ?? '');
        $data['kozterulet'] = (string) ($client['address'] ?? '');

        return $data;
    }

    private function hasPending(?int $clientId, ?int $officeId): bool
    {
        if ($clientId === null || $officeId === null) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM client_intake_submissions '
            . 'WHERE client_id = :client_id AND office_id = :office_id AND status = :status LIMIT 1'
        );
        $stmt->execute(['client_id' => $clientId, 'office_id' => $officeId, 'status' => 'pending']);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    private function flash(): ?array
    {
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($f) ? $f : null;
    }
}
