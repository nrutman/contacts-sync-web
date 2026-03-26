<?php

namespace App\Command;

use App\Client\Provider\RefreshableProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Entity\SyncList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'source:refresh',
    description: 'Refreshes a source list so it contains the most up-to-date contacts.',
),]
class RefreshSourceListsCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'list-name',
            InputArgument::REQUIRED,
            'The name of the sync list to refresh. Pass `all` to refresh all enabled lists.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $listName = $input->getArgument('list-name');

        $lists = $this->resolveLists($listName);

        if ($lists === null) {
            $io->error(sprintf('Unknown list specified: %s', $listName));

            return Command::FAILURE;
        }

        if (count($lists) === 0) {
            $io->warning('No sync lists found.');

            return Command::SUCCESS;
        }

        $hasFailure = false;

        foreach ($lists as $syncList) {
            $sourceCredential = $syncList->getSourceCredential();

            if ($sourceCredential === null) {
                $io->warning(sprintf('Sync list "%s" has no source credential — skipping.', $syncList->getName()));

                continue;
            }

            $provider = $this->providerRegistry->get($sourceCredential->getProviderName());

            if (!$provider instanceof RefreshableProviderInterface) {
                $io->note(sprintf('Refresh not supported for provider "%s" — skipping "%s".', $provider->getDisplayName(), $syncList->getName()));

                continue;
            }

            $listId = $syncList->getSourceListIdentifier() ?? $syncList->getName();
            $io->writeln(
                sprintf('Refreshing list <comment>%s</comment> (%s)', $syncList->getName(), $listId),
            );

            try {
                $provider->refreshList($sourceCredential, $listId);
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to refresh "%s": %s', $syncList->getName(), $e->getMessage()));
                $hasFailure = true;

                continue;
            }
        }

        $io->success('Done.');

        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return SyncList[]|null returns null if the list name is unknown
     */
    private function resolveLists(string $listName): ?array
    {
        $repository = $this->entityManager->getRepository(SyncList::class);

        if ($listName === 'all') {
            return $repository->findBy(['isEnabled' => true]);
        }

        $syncList = $repository->findOneBy(['name' => $listName]);

        if ($syncList === null) {
            return null;
        }

        return [$syncList];
    }
}
