<?php

declare(strict_types=1);

namespace App\Console;

use App\Reconciliation\FillMatcher;
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
    name: 'recon:fills',
    description: 'Fill-level reconciliation: link controller fills to Odoo sales; report unmatched fills, sales without fill, duplicate candidates.',
)]
final class ReconFillsCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FillMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Mediator station id')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start, station local time')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end, station local time (default: now)')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Minutes allowed for a sale to be registered after a fill', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stationId = (int) $input->getOption('station');
        /** @var array{name: string, utc_offset_minutes: string}|null $station */
        $station = (new Query($this->db))->select(['name', 'utc_offset_minutes'])->from('stations')->where(['id' => $stationId])->one();
        if ($station === null || $input->getOption('from') === null) {
            $io->error('Usage: recon:fills --station=<id> --from="YYYY-MM-DD HH:MM" [--to=...] [--grace=30]');
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

        $result = $this->matcher->match(
            $stationId,
            $from->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s.v'),
            (int) $input->getOption('grace'),
        );

        $io->title(sprintf('%s  %s → %s (local)', $station['name'], $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i')));
        $rows = [];
        foreach ($result->toArray() as $item => $count) {
            $rows[] = [$item, (string) $count];
        }
        $io->table(['Result', 'Count'], $rows);

        return Command::SUCCESS;
    }
}
