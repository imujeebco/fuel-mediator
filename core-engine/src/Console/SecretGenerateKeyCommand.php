<?php

declare(strict_types=1);

namespace App\Console;

use App\Shared\Crypto\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'secret:generate-key',
    description: 'Print a new MEDIATOR_SECRET_KEY (for core-engine/.env). Changing the key makes stored passwords unreadable until the next station import.',
)]
final class SecretGenerateKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('MEDIATOR_SECRET_KEY=' . SecretBox::generateKey());

        return Command::SUCCESS;
    }
}
