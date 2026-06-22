<?php

declare(strict_types=1);

namespace App\Console;

use App\Support\Encryption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateKeyCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('app:generate-key')
            ->setDescription('Új titkosítási kulcs generálása az APP_KEY-hez.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = Encryption::generateKey();
        $output->writeln('Másold be az .env APP_KEY mezőjébe:');
        $output->writeln('APP_KEY=' . $key);

        return Command::SUCCESS;
    }
}
