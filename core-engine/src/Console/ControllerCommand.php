<?php

declare(strict_types=1);

namespace App\Console;

use App\Collector\ConfigSync;
use App\Pts\ControllerLock;
use App\Pts\ControllerRepository;
use App\Pts\PtsException;
use App\Shared\Audit\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

#[AsCommand(
    name: 'controller',
    description: 'Manage a PTS controller: enable | disable | sync-config | show. Identified by Odoo backend config id.',
)]
final class ControllerCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ControllerRepository $controllers,
        private readonly ConfigSync $configSync,
        private readonly ControllerLock $lock,
        private readonly AuditLog $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'enable | disable | sync-config | show')
            ->addArgument('backend', InputArgument::REQUIRED, 'Odoo pts.backend.config id (e.g. 2 for Station 11)')
            ->addOption('poll-ms', null, InputOption::VALUE_REQUIRED, 'Poll interval in milliseconds (enable only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $backendId = (int) $input->getArgument('backend');

        /** @var array<string, mixed>|null $row */
        $row = (new Query($this->db))
            ->select(['c.*', 'utc_offset_minutes' => 's.utc_offset_minutes', 'station_name' => 's.name', 'station_mode' => 's.mode'])
            ->from(['c' => 'controllers'])
            ->innerJoin(['s' => 'stations'], 's.id = c.station_id')
            ->where(['c.odoo_backend_config_id' => $backendId])
            ->one();
        if ($row === null) {
            $io->error("No controller for Odoo backend config #$backendId. Run station:import first.");
            return Command::FAILURE;
        }
        $id = (int) $row['id'];

        switch ($action) {
            case 'enable':
                $columns = ['enabled' => 1, 'consecutive_failures' => 0, 'circuit_open_until' => null];
                /** @var string|null $pollMs */
                $pollMs = $input->getOption('poll-ms');
                if ($pollMs !== null) {
                    $columns['poll_interval_ms'] = max(2000, (int) $pollMs);
                }
                $this->db->createCommand()->update('controllers', $columns, ['id' => $id])->execute();
                $this->audit->record('controller', $id, 'enabled', 'console:controller', (int) $row['station_id'], data: $columns);
                $io->success("Collector enabled for {$row['name']} ({$row['station_name']}, mode {$row['station_mode']}).");
                return Command::SUCCESS;

            case 'disable':
                $this->db->createCommand()->update('controllers', ['enabled' => 0], ['id' => $id])->execute();
                $this->audit->record('controller', $id, 'disabled', 'console:controller', (int) $row['station_id']);
                $io->success("Collector disabled for {$row['name']}.");
                return Command::SUCCESS;

            case 'sync-config':
                try {
                    $endpoint = $this->controllers->endpoint($row);
                    $stats = $this->lock->run($id, 5.0, fn() => $this->configSync->sync($endpoint));
                } catch (PtsException $e) {
                    $io->error("[{$e->kind}] {$e->getMessage()}");
                    return Command::FAILURE;
                }
                $io->table(['Item', 'Value'], array_map(
                    static fn(string $k, mixed $v): array => [$k, (string) $v],
                    array_keys($stats),
                    array_values($stats),
                ));
                return Command::SUCCESS;

            case 'show':
                $io->table(['Field', 'Value'], array_map(
                    static fn(string $k, mixed $v): array => [$k, $v === null ? 'NULL' : (string) $v],
                    array_keys($row),
                    array_values($row),
                ));
                return Command::SUCCESS;
        }

        $io->error("Unknown action \"$action\".");
        return Command::INVALID;
    }
}
