<?php

declare(strict_types=1);

namespace App\Console;

use App\Mail\MailService;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Ütemezett feladatok: lejárat-/évforduló-emlékeztetők generálása és az esedékes
 * e-mail folyamatok kiküldése. Cronból percenként/óránként hívva.
 */
final class ScheduleRunCommand extends Command
{
    public function __construct(
        private PDO $pdo,
        private MailService $mail,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('schedule:run')
            ->setDescription('Emlékeztetők generálása és esedékes e-mail folyamatok kiküldése.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reminders = $this->generateReminders();
        $output->writeln(sprintf('Emlékeztetők létrehozva: %d', $reminders));

        $sent = $this->runDueWorkflows($output);
        $output->writeln(sprintf('Esedékes folyamatok feldolgozva: %d', $sent));

        return Command::SUCCESS;
    }

    private function generateReminders(): int
    {
        $today = date('Y-m-d');
        $in30 = date('Y-m-d', strtotime('+30 days'));
        $now = date('Y-m-d H:i:s');
        $created = 0;

        // Lejárat-emlékeztetők
        $stmt = $this->pdo->prepare(
            'SELECT id, office_id, client_id, end_date FROM contracts
             WHERE end_date IS NOT NULL AND end_date >= :t AND end_date <= :i'
        );
        $stmt->execute(['t' => $today, 'i' => $in30]);
        foreach ($stmt->fetchAll() as $c) {
            if ($this->reminderExists((int) $c['id'], 'expiry')) {
                continue;
            }
            $this->insertReminder((int) $c['office_id'], (int) $c['id'], (int) $c['client_id'], 'expiry', (string) $c['end_date'], $now);
            $this->insertNotification((int) $c['office_id'], 'Közelgő lejárat', 'Egy szerződés lejár: ' . $c['end_date'], $now);
            $created++;
        }

        // Évforduló-emlékeztetők (anniversary = "HH.NN")
        $annStmt = $this->pdo->query(
            "SELECT id, office_id, client_id, anniversary FROM contracts WHERE anniversary IS NOT NULL AND anniversary <> ''"
        );
        foreach ($annStmt->fetchAll() as $c) {
            $date = $this->anniversaryDate((string) $c['anniversary']);
            if ($date === null || $date < $today || $date > $in30) {
                continue;
            }
            if ($this->reminderExists((int) $c['id'], 'anniversary')) {
                continue;
            }
            $this->insertReminder((int) $c['office_id'], (int) $c['id'], (int) $c['client_id'], 'anniversary', $date, $now);
            $this->insertNotification((int) $c['office_id'], 'Közelgő évforduló', 'Egy szerződés évfordulója: ' . $date, $now);
            $created++;
        }

        return $created;
    }

    private function anniversaryDate(string $raw): ?string
    {
        if (!preg_match('/^(\d{1,2})[.\-\/](\d{1,2})$/', trim($raw), $m)) {
            return null;
        }
        $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $day = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $year = (int) date('Y');
        $candidate = sprintf('%d-%s-%s', $year, $month, $day);
        if ($candidate < date('Y-m-d')) {
            $candidate = sprintf('%d-%s-%s', $year + 1, $month, $day);
        }

        return checkdate((int) $month, (int) $day, $year) ? $candidate : null;
    }

    private function reminderExists(int $contractId, string $type): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM reminders WHERE contract_id = :c AND type = :t LIMIT 1');
        $stmt->execute(['c' => $contractId, 't' => $type]);

        return $stmt->fetchColumn() !== false;
    }

    private function insertReminder(int $office, int $contract, int $client, string $type, string $on, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reminders (office_id, contract_id, client_id, type, remind_on, handled, created_at, updated_at)
             VALUES (:o, :ct, :cl, :ty, :on, 0, :c, :u)'
        );
        $stmt->execute(['o' => $office, 'ct' => $contract, 'cl' => $client, 'ty' => $type, 'on' => $on, 'c' => $now, 'u' => $now]);
    }

    private function insertNotification(int $office, string $title, string $body, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (office_id, type, title, body, created_at) VALUES (:o, :ty, :t, :b, :c)'
        );
        $stmt->execute(['o' => $office, 'ty' => 'reminder', 't' => $title, 'b' => $body, 'c' => $now]);
    }

    private function runDueWorkflows(OutputInterface $output): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM email_workflows WHERE is_active = 1 AND schedule_at IS NOT NULL AND schedule_at <= :now'
        );
        $stmt->execute(['now' => $now]);
        $processed = 0;

        foreach ($stmt->fetchAll() as $wf) {
            $officeId = (int) $wf['office_id'];
            if (!$this->mail->isConfigured($officeId)) {
                $output->writeln(sprintf('  - "%s": SMTP nincs beállítva, kihagyva.', $wf['name']));
                continue;
            }
            $tpl = $this->template((int) ($wf['template_id'] ?? 0), $officeId);
            if ($tpl === null) {
                continue;
            }
            foreach ($this->clientsWithEmail($officeId) as $email) {
                try {
                    $this->mail->send($officeId, $email, (string) $tpl['subject'], (string) $tpl['body']);
                    $this->logSend($officeId, (int) $wf['id'], $email, (string) $tpl['subject'], 'sent', null, $now);
                } catch (Throwable $e) {
                    $this->logSend($officeId, (int) $wf['id'], $email, (string) $tpl['subject'], 'failed', $e->getMessage(), $now);
                }
            }
            // Egyszeri ütemezés törlése, hogy ne ismétlődjön
            $this->pdo->prepare('UPDATE email_workflows SET schedule_at = NULL WHERE id = :id')->execute(['id' => $wf['id']]);
            $processed++;
        }

        return $processed;
    }

    /** @return array<string,mixed>|null */
    private function template(int $id, int $officeId): ?array
    {
        if ($id === 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE id = :id AND office_id = :o LIMIT 1');
        $stmt->execute(['id' => $id, 'o' => $officeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<string> */
    private function clientsWithEmail(int $officeId): array
    {
        $stmt = $this->pdo->prepare("SELECT email FROM clients WHERE office_id = :o AND email IS NOT NULL AND email <> ''");
        $stmt->execute(['o' => $officeId]);

        return array_values(array_filter(
            array_map(static fn (array $r): string => (string) $r['email'], $stmt->fetchAll()),
            static fn (string $e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL),
        ));
    }

    private function logSend(int $office, int $workflow, string $to, string $subject, string $status, ?string $error, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_sends (office_id, workflow_id, to_email, subject, status, error, sent_at, created_at, updated_at)
             VALUES (:o, :w, :t, :s, :st, :e, :sa, :c, :u)'
        );
        $stmt->execute([
            'o' => $office, 'w' => $workflow, 't' => $to, 's' => $subject, 'st' => $status,
            'e' => $error, 'sa' => $status === 'sent' ? $now : null, 'c' => $now, 'u' => $now,
        ]);
    }
}
