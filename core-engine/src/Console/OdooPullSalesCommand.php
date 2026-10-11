<?php

declare(strict_types=1);

namespace App\Console;

use App\Odoo\OdooException;
use App\Odoo\OdooReadClientInterface;
use App\Odoo\Sync\OdooSalesReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

#[AsCommand(
    name: 'odoo:pull-sales',
    description: 'Incrementally mirror pts.terminal.sale from Odoo (read-only) for active stations.',
)]
final class OdooPullSalesCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly OdooReadClientInterface $odoo,
        private readonly OdooSalesReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Comma-separated mediator station ids (default: all active)')
            ->addOption('lookback-days', null, InputOption::VALUE_REQUIRED, 'Initial lookback when a station has no cursor yet', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $query = (new Query($this->db))->select(['id', 'name', 'odoo_station_id', 'odoo_company_id'])->from('stations')
            ->where(['active' => 1])->andWhere(['not', ['odoo_station_id' => null]])->andWhere(['not', ['odoo_company_id' => null]])
            ->orderBy(['id' => SORT_ASC]);
        /** @var string|null $stationOption */
        $stationOption = $input->getOption('station');
        if ($stationOption !== null) {
            $query->andWhere(['id' => array_map('intval', explode(',', $stationOption))]);
        }

        $failures = 0;
        $rows = [];
        $lookbackDays = (int) $input->getOption('lookback-days');
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$lookbackDays} days")->format('Y-m-d H:i:s');
        /** @var array{id: string, name: string, odoo_station_id: string, odoo_company_id: string} $station */
        foreach ($query->all() as $station) {
            try {
                $count = $this->reader->pull($this->odoo, (int) $station['id'], (int) $station['odoo_company_id'], $lookbackDays);
                $wrong = $this->reader->misattributedCount($this->odoo, (int) $station['odoo_station_id'], (int) $station['odoo_company_id'], $since);
                $rows[] = [$station['name'], (string) $count, (string) $wrong, 'ok'];
            } catch (OdooException $e) {
                // One station's failure does not stop the others.
                $failures++;
                $rows[] = [$station['name'], '0', '-', 'error: ' . $e->getMessage()];
            }
        }
        $io->table(['Station', 'Sales upserted', 'Tagged here by other companies', 'Status'], $rows);

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
