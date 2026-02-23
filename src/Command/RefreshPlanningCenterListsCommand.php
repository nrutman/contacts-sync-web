<?php

namespace App\Command;

use App\Client\PlanningCenter\PlanningCenterClientFactory;
use App\Entity\Organization;
use App\Entity\SyncList;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'planning-center:refresh',
    description: 'Refreshes a Planning Center list so it contains the most up-to-date contacts.',
),]
class RefreshPlanningCenterListsCommand extends Command
{
    public function __construct(
        private readonly PlanningCenterClientFactory $planningCenterClientFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'list-name',
            InputArgument::REQUIRED,
            'The name of the list to refresh. Pass `all` to refresh all lists.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $listName = $input->getArgument('list-name');

        $organization = $this->entityManager
            ->getRepository(Organization::class)
            ->findOneBy([]);

        if ($organization === null) {
            $io->error(
                'No organization found. Please run app:setup first to create an organization.',
            );

            return Command::FAILURE;
        }

        $planningCenterClient = $this->planningCenterClientFactory->create(
            $organization,
        );

        $lists = $this->resolveLists($listName);

        if ($lists === null) {
            $io->error(sprintf('Unknown list specified: %s', $listName));

            return Command::FAILURE;
        }

        foreach ($lists as $list) {
            $io->writeln(
                sprintf('Refreshing list <comment>%s</comment>', $list),
            );
            $planningCenterClient->refreshList($list);
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }

    /**
     * Resolves the list names to refresh.
     *
     * @return string[]|null returns null if the list name is unknown
     */
    private function resolveLists(string $listName): ?array
    {
        $repository = $this->entityManager->getRepository(SyncList::class);

        if ($listName === 'all') {
            $syncLists = $repository->findBy(['isEnabled' => true]);

            return array_map(
                static fn (SyncList $syncList) => $syncList->getName(),
                $syncLists,
            );
        }

        $syncList = $repository->findOneBy(['name' => $listName]);

        if ($syncList === null) {
            return null;
        }

        return [$syncList->getName()];
    }
}
