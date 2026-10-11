<?php

declare(strict_types=1);

namespace App\Console;

use App\Odoo\OdooException;
use App\Odoo\OdooReadClientInterface;
use App\Station\Import\StationImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'station:import',
    description: 'Import stations, PTS controllers, pumps and nozzles from Odoo (read-only).',
)]
final class StationImportCommand extends Command
{
    public function __construct(
        private readonly StationImporter $importer,
        private readonly OdooReadClientInterface $odoo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read and validate, but roll back all changes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->importer->import($this->odoo, (bool) $input->getOption('dry-run'));
        } catch (OdooException $e) {
            $io->error('Odoo read failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $summary = [];
        foreach ($result->toArray() as $item => $value) {
            $summary[] = [$item, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
        }
        $io->table(['Item', 'Count'], $summary);
        $io->table(['Odoo id', 'Station', 'Active', 'Mode'], array_map(
            static fn(array $s): array => [(string) $s['odoo_id'], $s['name'], $s['active'] ? 'yes' : 'no', $s['mode']],
            $result->stationsDetail,
        ));
        $io->success($result->dryRun ? 'Dry run complete; no changes saved.' : 'Import complete. New controllers are disabled.');

        return Command::SUCCESS;
    }
}
