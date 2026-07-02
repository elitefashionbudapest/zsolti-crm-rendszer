<?php

declare(strict_types=1);

namespace App\Console;

use App\Ai\ClaudeClient;
use App\Documents\DocumentStorage;
use App\Settings\SettingsService;
use App\Support\PdfTrimmer;
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
        private PdfTrimmer $pdfTrimmer,
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
            $count++;
            if ((string) ($job['type'] ?? '') !== 'ai_extract') {
                $this->deleteJob((int) $job['id']);
                continue;
            }
            // handleExtract dönt a job törléséről (siker/terminál hiba); ha a
            // folyamatot közben megöli a host, a job megmarad és újrapróbáljuk.
            $this->handleExtract((array) $job, $output);
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
            $this->deleteJob((int) ($job['id'] ?? 0));
            return;
        }

        // A próbálkozást MÉG a (megszakítható, költséges) hívás előtt rögzítjük, hogy
        // egy megölt folyamat ne indítsa újra a drága kinyerést. 1 megszakadás után feladjuk.
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $this->pdo->prepare('UPDATE jobs SET attempts = :a, updated_at = :u WHERE id = :id')
            ->execute(['a' => $attempts, 'u' => date('Y-m-d H:i:s'), 'id' => (int) $job['id']]);
        if ($attempts > 1) {
            $this->finish($eid, $oid, (string) json_encode(['_error' => 'A feldolgozás megszakadt (a dokumentum túl nagy vagy a modell túl lassú a tárhely időkeretéhez). Tölts fel kisebb / kevesebb oldalas fájlt.'], JSON_UNESCAPED_UNICODE), 'failed');
            $this->deleteJob((int) $job['id']);
            $output->writeln('[extract ' . $eid . '] feladva (megszakadt, nem próbáljuk újra a költség miatt)');
            return;
        }

        try {
            $binary = (string) @file_get_contents($this->storage->fullPath($path));
            $apiKey = (string) $this->settings->get($oid, 'anthropic_api_key', '');
            if ($apiKey === '' || $binary === '') {
                throw new \RuntimeException('Hiányzó API-kulcs vagy a fájl nem elérhető.');
            }
            // Nagy PDF-eknél csak az első 10 oldalt küldjük a modellnek (ott vannak az
            // adatok; a többi csak szerződési szöveg) — így sokkal kevesebb token/kredit.
            if (str_contains($mime, 'pdf')) {
                $binary = $this->pdfTrimmer->firstPages($binary, 10);
            }
            ['schema' => $schema, 'instruction' => $instruction] = ClaudeClient::clientContractSchema();
            $result = $this->claude->extract($binary, $mime, $apiKey, $model, $schema, $instruction);
            $this->finish($eid, $oid, (string) json_encode($result, JSON_UNESCAPED_UNICODE), 'pending');
            $this->deleteJob((int) $job['id']);
            $output->writeln('[extract ' . $eid . '] kész');
        } catch (Throwable $e) {
            $this->finish($eid, $oid, (string) json_encode(['_error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), 'failed');
            $this->deleteJob((int) $job['id']);
            $output->writeln('[extract ' . $eid . '] hiba: ' . $e->getMessage());
        }
    }

    private function deleteJob(int $id): void
    {
        if ($id > 0) {
            $this->pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute(['id' => $id]);
        }
    }

    private function finish(int $eid, int $officeId, string $fields, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE extracted_data SET fields = :f, status = :s, updated_at = :u WHERE id = :id AND office_id = :o'
        )->execute(['f' => $fields, 's' => $status, 'u' => date('Y-m-d H:i:s'), 'id' => $eid, 'o' => $officeId]);
    }
}
