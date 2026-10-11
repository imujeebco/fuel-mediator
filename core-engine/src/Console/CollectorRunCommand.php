<?php

declare(strict_types=1);

namespace App\Console;

use App\Collector\CollectorRunner;
use App\Pts\Endpoint\CommandResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function array_filter;
use function array_map;
use function explode;
use function json_encode;
use function usleep;

#[AsCommand(
    name: 'collector:run',
    description: 'Observe-mode collector: read-only PumpGetStatus polling of enabled controllers.',
)]
final class CollectorRunCommand extends Command
{
    private const RESOLVE_EVERY_SECONDS = 30;

    public function __construct(
        private readonly CollectorRunner $runner,
        private readonly CommandResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('station', null, InputOption::VALUE_REQUIRED, 'Comma-separated mediator station ids (default: all enabled).')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run a single pass and exit.')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Exit after this many seconds (0 = run forever).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $stationOption */
        $stationOption = $input->getOption('station');
        $stationIds = $stationOption === null ? [] : array_values(array_filter(array_map('intval', explode(',', $stationOption))));
        $once = (bool) $input->getOption('once');
        $maxSeconds = (int) $input->getOption('max-seconds');
        $started = hrtime(true) / 1e9;

        // Graceful stop on Ctrl+C / SIGTERM where supported (Linux); on Windows the loop is interrupted directly.
        $stop = false;
        if (\function_exists('pcntl_async_signals') && \defined('SIGTERM') && \defined('SIGINT')) {
            pcntl_async_signals(true);
            foreach ([(int) \constant('SIGTERM'), (int) \constant('SIGINT')] as $signal) {
                pcntl_signal($signal, static function () use (&$stop): void {
                    $stop = true;
                });
            }
        }

        $lastResolve = 0.0;
        try {
            do {
                // Unknown command outcomes are resolved from the evidence the collector just gathered.
                if (hrtime(true) / 1e9 - $lastResolve >= self::RESOLVE_EVERY_SECONDS) {
                    $lastResolve = hrtime(true) / 1e9;
                    $resolved = array_filter($this->resolver->resolve());
                    if ($resolved !== []) {
                        $output->writeln('commands resolved ' . (json_encode($resolved) ?: '{}'));
                    }
                }
                $outcomes = $this->runner->runOnce($stationIds, ignoreInterval: $once);
                foreach ($outcomes as $controllerId => $outcome) {
                    $output->writeln(
                        $this->runner->clock()->now()->format('H:i:s') . " controller #$controllerId "
                        . (json_encode($outcome, JSON_UNESCAPED_SLASHES) ?: '{}'),
                        $outcome['status'] === 'ok' && ($outcome['fills'] ?? 0) === 0 ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL,
                    );
                }
                if ($once || ($maxSeconds > 0 && hrtime(true) / 1e9 - $started >= $maxSeconds)) {
                    break;
                }
                usleep(500_000);
            } while (!$stop);
        } finally {
            $this->runner->releaseLeases($stationIds);
        }

        return Command::SUCCESS;
    }
}
