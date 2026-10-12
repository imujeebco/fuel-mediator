<?php

declare(strict_types=1);

namespace App\Console;

use App\Price\PriceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'prices:push',
    description: "Send Odoo's prices for a station to its controller and verify them by reading them back (D25).",
)]
final class PricesPushCommand extends Command
{
    public function __construct(private readonly PriceService $prices)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Mediator station id')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Send even when the controller already has the prices (e.g. after a controller restart)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $station = $input->getOption('station');
        if (!is_string($station) || $station === '') {
            $io->error('Usage: prices:push --station=<id> [--resend]');
            return Command::INVALID;
        }
        $result = $this->prices->push((int) $station, 'console', true, (bool) $input->getOption('resend'));
        $ok = in_array($result['state'], ['verified', 'unchanged'], true);
        $ok ? $io->success("[{$result['state']}] {$result['message']}") : $io->error("[{$result['state']}] {$result['message']}");

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
