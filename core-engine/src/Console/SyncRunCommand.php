<?php

declare(strict_types=1);

namespace App\Console;

use App\Sync\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sync:run',
    description: 'One sync pass: mirror Odoo sales and POS sessions for every active station, then match fills where the mediator collects (read-only).',
)]
final class SyncRunCommand extends Command
{
    public function __construct(private readonly SyncService $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Mediator station id (default: all active stations)')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Sales pages of 500 per station', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $station */
        $station = $input->getOption('station');
        $results = $this->sync->run($station === null ? null : (int) $station, max(1, (int) $input->getOption('max-pages')));

        $rows = [];
        $failures = 0;
        foreach ($results as $r) {
            $failures += $r['error'] === null ? 0 : 1;
            $rows[] = [$r['station'], (string) $r['sales'], (string) $r['sessions'], $r['matched'] === null ? '-' : (string) $r['matched'],
                $r['unmatched'] === null ? '-' : (string) $r['unmatched'], $r['error'] ?? 'ok'];
        }
        (new SymfonyStyle($input, $output))->table(['Station', 'Sales', 'Sessions', 'Fills linked', 'Fills unmatched', 'Status'], $rows);

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
