<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Auth\Auth;
use PDO;
use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HomeController
{
    public function __construct(
        private Twig $twig,
        private Auth $auth,
        private PDO $pdo,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $name = (string) ($this->auth->user()['name'] ?? 'Ügyfél');
        $parts = explode(' ', trim($name));
        $first = end($parts) ?: $name;

        $clientId = $this->auth->clientId();
        $officeId = $this->auth->officeId();

        $contracts = [];
        if ($clientId !== null && $officeId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, category, insurer_name, module_name, policy_number, '
                . 'start_date, end_date, anniversary, annual_fee, status '
                . 'FROM contracts WHERE client_id = :client_id AND office_id = :office_id '
                . 'ORDER BY id DESC'
            );
            $stmt->execute(['client_id' => $clientId, 'office_id' => $officeId]);
            $contracts = $stmt->fetchAll();
        }

        $today = new \DateTimeImmutable('today');

        // Következő évforduló: a legközelebbi jövőbeli anniversary (hó-nap alapon).
        $nextAnniversary = null;
        foreach ($contracts as $c) {
            $occ = $this->nextOccurrence((string) ($c['anniversary'] ?? ''), $today);
            if ($occ === null) {
                continue;
            }
            if ($nextAnniversary === null || $occ['date'] < $nextAnniversary['date']) {
                $nextAnniversary = ['date' => $occ['date'], 'days' => $occ['days'], 'contract' => $c];
            }
        }

        // Közelgő lejárat: a legközelebbi jövőbeli end_date.
        $nextExpiry = null;
        foreach ($contracts as $c) {
            $end = $this->parseDate((string) ($c['end_date'] ?? ''));
            if ($end === null || $end < $today) {
                continue;
            }
            $days = (int) $today->diff($end)->format('%a');
            if ($nextExpiry === null || $end < $nextExpiry['date']) {
                $nextExpiry = ['date' => $end, 'days' => $days, 'contract' => $c];
            }
        }

        return $this->twig->render($response, 'portal/home.twig', [
            'active' => 'home',
            'first_name' => $first,
            'contracts' => array_slice($contracts, 0, 5),
            'contract_count' => count($contracts),
            'next_anniversary' => $nextAnniversary !== null ? [
                'date' => $this->huDate($nextAnniversary['date']),
                'days' => $nextAnniversary['days'],
                'label' => $this->contractLabel($nextAnniversary['contract']),
            ] : null,
            'next_expiry' => $nextExpiry !== null ? [
                'date' => $this->huDate($nextExpiry['date']),
                'days' => $nextExpiry['days'],
                'label' => $this->contractLabel($nextExpiry['contract']),
            ] : null,
        ]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date === false ? null : $date;
    }

    /**
     * A megadott dátum (évforduló) következő előfordulása mától számítva, hó-nap alapon.
     *
     * @return array{date: \DateTimeImmutable, days: int}|null
     */
    private function nextOccurrence(string $value, \DateTimeImmutable $today): ?array
    {
        $base = $this->parseDate($value);
        if ($base === null) {
            return null;
        }
        $occ = $base->setDate((int) $today->format('Y'), (int) $base->format('n'), (int) $base->format('j'));
        if ($occ < $today) {
            $occ = $occ->modify('+1 year');
        }
        $days = (int) $today->diff($occ)->format('%a');

        return ['date' => $occ, 'days' => $days];
    }

    private function huDate(\DateTimeImmutable $date): string
    {
        $months = [1 => 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

        return sprintf('%s. %s %d.', $date->format('Y'), $months[(int) $date->format('n')], (int) $date->format('j'));
    }

    /** @param array<string,mixed> $contract */
    private function contractLabel(array $contract): string
    {
        $parts = array_filter([
            (string) ($contract['module_name'] ?? ''),
            (string) ($contract['insurer_name'] ?? ''),
        ], static fn (string $v): bool => trim($v) !== '');

        return $parts === [] ? 'Szerződés' : implode(' · ', $parts);
    }
}
