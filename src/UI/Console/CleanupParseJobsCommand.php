<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:parse:cleanup',
    description: 'Wipe CV payload from ParseResult records older than the retention period. Job rows and ParseResult rows are never deleted.',
)]
final class CleanupParseJobsCommand extends Command
{
    public function __construct(
        private readonly ParseResultRepositoryInterface $parseResultRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'payload-retention-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Wipe payload from ParseResult records older than this many days',
                30,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Log what would be done without writing any changes',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retentionDays = (int) $input->getOption('payload-retention-days');
        $dryRun = (bool) $input->getOption('dry-run');

        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $retentionDays));

        if ($dryRun) {
            $count = $this->parseResultRepository->countPayloadsOlderThan($threshold);
            $io->info(sprintf('[DRY RUN] Would wipe payload for %d ParseResult record(s) (older than %d days).', $count, $retentionDays));

            return Command::SUCCESS;
        }

        $count = $this->parseResultRepository->wipePayloadsOlderThan($threshold);

        $io->success(sprintf('Wiped payload for %d ParseResult record(s) (older than %d days).', $count, $retentionDays));

        return Command::SUCCESS;
    }
}
