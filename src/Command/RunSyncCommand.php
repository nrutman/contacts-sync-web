<?php

namespace App\Command;

use App\Entity\SyncList;
use App\Sync\SyncService;
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
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $listFilter = $input->getOption('list');

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

        $hasFailure = false;

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
                trigger: 'cli',
            );

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
}
