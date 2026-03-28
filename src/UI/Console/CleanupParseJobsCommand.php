<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\Parsing\Repository\ParseJobRepositoryInterface;
use App\Domain\Parsing\Repository\ParseResultRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:parse:cleanup',
    description: 'Delete parse jobs, results, and uploaded files older than a given threshold.',
)]
final class CleanupParseJobsCommand extends Command
{
    public function __construct(
        private readonly ParseJobRepositoryInterface $parseJobRepository,
        private readonly ParseResultRepositoryInterface $parseResultRepository,
        #[Autowire(param: 'app.upload_dir')] private readonly string $uploadDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'Delete jobs older than this duration (e.g. 24h, 7d, 1w)',
            '24h',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $olderThan = (string) $input->getOption('older-than');
        $threshold = $this->parseThreshold($olderThan);

        if (null === $threshold) {
            $io->error(sprintf('Invalid duration format "%s". Use e.g. 24h, 7d, 1w.', $olderThan));

            return Command::FAILURE;
        }

        $jobs = $this->parseJobRepository->findOlderThan($threshold);
        $count = 0;

        foreach ($jobs as $job) {
            $this->parseResultRepository->deleteByJobId($job->getId());

            $filePath = rtrim($this->uploadDir, '/').'/'.$job->getId().'.pdf';
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $this->parseJobRepository->delete($job);
            ++$count;
        }

        $io->success(sprintf('Deleted %d job(s) older than %s.', $count, $olderThan));

        return Command::SUCCESS;
    }

    private function parseThreshold(string $duration): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d+)(h|d|w)$/', $duration, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];
        $unit = match ($matches[2]) {
            'h' => 'hours',
            'd' => 'days',
            'w' => 'weeks',
        };

        $interval = \DateInterval::createFromDateString("{$amount} {$unit}");

        if (false === $interval) {
            return null;
        }

        return (new \DateTimeImmutable())->sub($interval);
    }
}
