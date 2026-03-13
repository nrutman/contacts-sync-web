<?php

namespace App\Command;

use App\Entity\SyncList;
use App\Notification\SyncNotificationService;
use App\Repository\SyncRunRepository;
use App\Sync\SyncService;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sync:run',
    description: 'Syncs contacts from source to destination for configured sync lists',
),]
class RunSyncCommand extends Command
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly SyncRunRepository $syncRunRepository,
        private readonly SyncNotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'Fetches contacts from the configured source provider and syncs them to the configured destination.',
        );

        $this->addOption(
            'dry-run',
            'd',
            InputOption::VALUE_NONE,
            'Completes a dry run by showing output but without writing any data.',
        );

        $this->addOption(
            'list',
            'l',
            InputOption::VALUE_REQUIRED,
            'The name of a specific list to sync. If omitted, all enabled lists are synced.',
        );

        $this->addOption(
            'scheduled',
            's',
            InputOption::VALUE_NONE,
            'Only sync lists that are due according to their cron expression. Use this with system-level cron.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $listFilter = $input->getOption('list');
        $scheduled = (bool) $input->getOption('scheduled');

        if ($dryRun) {
            $io->success(
                'NOTE: This is a dry run. The destination list will not be altered!'.
                    PHP_EOL,
            );
        }

        $syncLists = $this->resolveSyncLists($listFilter);

        if (count($syncLists) === 0) {
            $io->warning('No sync lists found.');

            return Command::FAILURE;
        }

        if ($scheduled) {
            $syncLists = $this->filterDueLists($syncLists, $io);

            if (count($syncLists) === 0) {
                $io->info('No sync lists are due. Nothing to do.');

                return Command::SUCCESS;
            }
        }

        $trigger = $scheduled ? 'schedule' : 'cli';
        $hasFailure = false;
        $syncRuns = [];

        foreach ($syncLists as $listIndex => $syncList) {
            if ($listIndex > 0) {
                $output->writeln('');
            }

            $io->writeln(
                sprintf(
                    '<comment>Processing %s (%d/%d)</comment>',
                    $syncList->getName(),
                    $listIndex + 1,
                    count($syncLists),
                ),
            );

            $result = $this->syncService->executeSync(
                syncList: $syncList,
                dryRun: $dryRun,
                trigger: $trigger,
            );

            if ($result->syncRun !== null) {
                $syncRuns[] = $result->syncRun;
            }

            // Display the sync result summary
            $io->table(
                ['Source', 'Destination', 'To Remove', 'To Add'],
                [
                    [
                        $result->sourceCount,
                        $result->destinationCount,
                        $result->removedCount,
                        $result->addedCount,
                    ],
                ],
            );

            // Display the log output
            $output->writeln($result->log);

            if (!$result->success) {
                $io->error(sprintf('Sync failed: %s', $result->errorMessage));
                $hasFailure = true;
            }
        }

        // Send batch notification and display results
        if ($syncRuns !== []) {
            $this->notificationService->sendBatchNotification($syncRuns);

            foreach ($this->notificationService->getLastResults() as $notification) {
                if ($notification['success']) {
                    $io->writeln(sprintf('<info>Notification sent to %s</info>', $notification['email']));
                } else {
                    $io->writeln(sprintf('<error>Notification to %s failed: %s</error>', $notification['email'], $notification['error']));
                }
            }
        }

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolves which SyncList entities to process.
     *
     * @return SyncList[]
     */
    private function resolveSyncLists(?string $listFilter): array
    {
        $repository = $this->entityManager->getRepository(SyncList::class);

        if ($listFilter !== null) {
            return $repository->findBy(['name' => $listFilter]);
        }

        return $repository->findBy(['isEnabled' => true]);
    }

    /**
     * Filters sync lists to only those that are due according to their cron expression.
     *
     * @param SyncList[] $syncLists
     *
     * @return SyncList[]
     */
    private function filterDueLists(array $syncLists, SymfonyStyle $io): array
    {
        $now = new \DateTimeImmutable();
        $dueLists = [];

        foreach ($syncLists as $syncList) {
            $cronExpr = $syncList->getCronExpression();

            if ($cronExpr === null) {
                $io->writeln(sprintf(
                    '<comment>Skipping %s — no cron expression configured.</comment>',
                    $syncList->getName(),
                ));

                continue;
            }

            $lastRun = $this->syncRunRepository->findLastBySyncList($syncList);

            if ($lastRun === null) {
                $io->writeln(sprintf(
                    '<info>%s — never synced, due now.</info>',
                    $syncList->getName(),
                ));
                $dueLists[] = $syncList;

                continue;
            }

            $cron = new CronExpression($cronExpr);
            $nextDue = $cron->getNextRunDate($lastRun->getCreatedAt());

            if ($nextDue <= $now) {
                $io->writeln(sprintf(
                    '<info>%s — due (next run was %s).</info>',
                    $syncList->getName(),
                    $nextDue->format('Y-m-d H:i:s'),
                ));
                $dueLists[] = $syncList;
            } else {
                $io->writeln(sprintf(
                    '<comment>Skipping %s — not due until %s.</comment>',
                    $syncList->getName(),
                    $nextDue->format('Y-m-d H:i:s'),
                ));
            }
        }

        return $dueLists;
    }
}
