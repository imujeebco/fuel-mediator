<?php

declare(strict_types=1);

namespace App\Console;

use App\Reconciliation\FillMatcher;
use App\Reconciliation\VolumeReconciler;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

#[AsCommand(
    name: 'recon:volume',
    description: 'Volume reconciliation for a station window: controller dispensed litres vs Odoo sold litres.',
)]
final class ReconVolumeCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly VolumeReconciler $reconciler,
        private readonly FillMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Mediator station id')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start, station local time (YYYY-MM-DD HH:MM)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end, station local time (default: now)')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Minutes allowed for a sale to be registered after its fill (fill matching)', '30')
            ->addOption('no-persist', null, InputOption::VALUE_NONE, 'Do not write discrepancies / run records')
            ->addOption('by-pump', null, InputOption::VALUE_NONE, 'Group by physical pump (only meaningful where devices run with PTS on)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stationId = (int) $input->getOption('station');
        /** @var array{name: string, utc_offset_minutes: string}|null $station */
        $station = (new Query($this->db))->select(['name', 'utc_offset_minutes'])->from('stations')->where(['id' => $stationId])->one();
        if ($station === null || $input->getOption('from') === null) {
            $io->error('Usage: recon:volume --station=<id> --from="YYYY-MM-DD HH:MM" [--to=...]');
            return Command::INVALID;
        }

        $minutes = (int) $station['utc_offset_minutes'];
        /** @var non-empty-string $offset */
        $offset = sprintf('%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
        $zone = new DateTimeZone($offset);
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable((string) $input->getOption('from'), $zone);
        /** @var string|null $toOption */
        $toOption = $input->getOption('to');
        $to = $toOption === null ? new DateTimeImmutable('now', $zone) : new DateTimeImmutable($toOption, $zone);

        // Link fills to sales first so late-registered sales count in their fill's window.
        $this->matcher->match(
            $stationId,
            $from->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            (int) $input->getOption('grace'),
        );
        $report = $this->reconciler->reconcile(
            $stationId,
            $from->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            (int) $input->getOption('grace'),
            !$input->getOption('no-persist'),
            $input->getOption('by-pump') ? VolumeReconciler::BY_PUMP : VolumeReconciler::BY_GRADE,
        );

        $io->title(sprintf('%s  %s → %s (local)', $station['name'], $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i')));
        $rows = [];
        foreach ($report->lines as $line) {
            $rows[] = [
                $line->label, $line->fills, $line->missedFills, $line->dispensed, $line->sales, $line->sold,
                $line->gap, $line->coverage, $line->flagged ? 'FLAG' : '',
            ];
        }
        $io->table([$report->groupBy === VolumeReconciler::BY_PUMP ? 'Pump' : 'Grade', 'Fills', 'Missed', 'Dispensed L', 'Sales', 'Sold L', 'Gap L', 'Coverage', ''], $rows);
        $io->definitionList(...array_map(
            static fn(string $k, int|string $v): array => [$k => (string) $v],
            array_keys($report->totals()),
            array_values($report->totals()),
        ));

        return Command::SUCCESS;
    }
}
