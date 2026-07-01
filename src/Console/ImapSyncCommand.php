<?php

declare(strict_types=1);

namespace App\Console;

use App\Mail\MailboxSyncDispatcher;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Beérkező e-mailek szinkronizálása minden iroda IMAP-fiókjából (cronból).
 */
final class ImapSyncCommand extends Command
{
    public function __construct(
        private PDO $pdo,
        private MailboxSyncDispatcher $mailbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('imap:sync')
            ->setDescription('Beérkező levelek szinkronizálása minden iroda IMAP-fiókjából.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $offices = $this->pdo->query('SELECT id, name FROM offices WHERE is_active = 1')->fetchAll();

        foreach ($offices as $office) {
            $id = (int) $office['id'];
            if (!$this->mailbox->isConfigured($id)) {
                continue;
            }
            $result = $this->mailbox->sync($id);
            if ($result['error'] !== null) {
                $output->writeln(sprintf('[%s] hiba: %s', $office['name'], $result['error']));
            } else {
                $output->writeln(sprintf('[%s] %d új levél (%s).', $office['name'], $result['count'], $result['via']));
            }
        }

        return Command::SUCCESS;
    }
}
