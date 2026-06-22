<?php

declare(strict_types=1);

namespace App\Console;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Egyszerű háttér-worker a `jobs` táblához. A hosszú futású feladatokat (pl.
 * tömeges e-mail, AI-feldolgozás) ide lehet betenni; jelenleg a sor ürítését
 * végzi. Supervisor alatt fut a VPS-en.
 */
final class WorkerCommand extends Command
{
    public function __construct(private PDO $pdo)
    {
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
            $processed = $this->drain();
            if ($processed === 0) {
                if ($once) {
                    break;
                }
                sleep(3);
            }
        } while (!$once);

        $output->writeln('Worker leállt.');

        return Command::SUCCESS;
    }

    private function drain(): int
    {
        $rows = $this->pdo->query('SELECT * FROM jobs WHERE reserved_at IS NULL ORDER BY id LIMIT 10')->fetchAll();
        $count = 0;
        foreach ($rows as $job) {
            // Itt lehetne típus szerinti feldolgozás; jelenleg a feladatot lezárjuk.
            $this->pdo->prepare('DELETE FROM jobs WHERE id = :id')->execute(['id' => $job['id']]);
            $count++;
        }

        return $count;
    }
}
