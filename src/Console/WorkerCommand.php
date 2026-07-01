<?php

declare(strict_types=1);

namespace App\Console;

use App\Ai\ClaudeClient;
use App\Documents\DocumentStorage;
use App\Settings\SettingsService;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Háttér-worker a `jobs` táblához. A hosszú futású feladatokat (pl. AI-adatkinyerés)
 * itt dolgozzuk fel — CLI-ben nincs web request-timeout. Cronból: `queue:work --once`.
 */
final class WorkerCommand extends Command
{
    public function __construct(
        private PDO $pdo,
        private ClaudeClient $claude,
        private SettingsService $settings,
        private DocumentStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('queue:work')
            ->setDescription('Háttérfeladatok feldolgozása a jobs sorból.')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Csak egy kört fut, majd kilép.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once = (bool) $input->getOption('once');

        do {
            $processed = $this->drain($output);
            if ($processed === 0) {
                if ($once) {
                    break;
                }
                sleep(3);
            }
        } while (!$once);

        return Command::SUCCESS;
    }

    private function drain(OutputInterface $output): int
    {
        $rows = $this->pdo->query('SELECT * FROM jobs WHERE reserved_at IS NULL ORDER BY id LIMIT 10')->fetchAll();
        $count = 0;
        foreach ($rows as $job) {
            try {
                if ((string) ($job['type'] ?? '') === 'ai_extract') {
                    $this->handleExtract((array) $job, $output);
                }
            } catch (Throwable $e) {
                $output->writeln('[job ' . ($job['id'] ?? '?') . '] hiba: ' . $e->getMessage());
            }
            $this->pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute(['id' => $job['id']]);
            $count++;
        }

        return $count;
    }

    /** @param array<string,mixed> $job */
    private function handleExtract(array $job, OutputInterface $output): void
    {
        $p = json_decode((string) ($job['payload'] ?? ''), true);
        if (!is_array($p)) {
            return;
        }
        $eid = (int) ($p['extraction_id'] ?? 0);
        $oid = (int) ($p['office_id'] ?? 0);
        $path = (string) ($p['stored_path'] ?? '');
        $mime = (string) ($p['mime'] ?? '');
        $model = (string) ($p['model'] ?? '');
        if ($eid <= 0 || $oid <= 0) {
            return;
        }

        try {
            $binary = (string) @file_get_contents($this->storage->fullPath($path));
            $apiKey = (string) $this->settings->get($oid, 'anthropic_api_key', '');
            if ($apiKey === '' || $binary === '') {
                throw new \RuntimeException('Hiányzó API-kulcs vagy a fájl nem elérhető.');
            }
            ['schema' => $schema, 'instruction' => $instruction] = ClaudeClient::clientContractSchema();
            $result = $this->claude->extract($binary, $mime, $apiKey, $model, $schema, $instruction);
            $this->finish($eid, $oid, (string) json_encode($result, JSON_UNESCAPED_UNICODE), 'pending');
            $output->writeln('[extract ' . $eid . '] kész');
        } catch (Throwable $e) {
            $this->finish($eid, $oid, (string) json_encode(['_error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), 'failed');
            $output->writeln('[extract ' . $eid . '] hiba: ' . $e->getMessage());
        }
    }

    private function finish(int $eid, int $officeId, string $fields, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE extracted_data SET fields = :f, status = :s, updated_at = :u WHERE id = :id AND office_id = :o'
        )->execute(['f' => $fields, 's' => $status, 'u' => date('Y-m-d H:i:s'), 'id' => $eid, 'o' => $officeId]);
    }
}
