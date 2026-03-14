<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import',
    description: 'Import organization configuration from a JSON file',
)]
class ImportCommand extends Command
{
    private const ABORT = 2;

    public function __construct(
        private readonly OrganizationRepository $orgRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the import JSON file');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt when existing data will be overwritten');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');

        if (!file_exists($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::FAILURE;
        }

        $contents = file_get_contents($path);

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $io->error('Invalid JSON in import file.');

            return Command::FAILURE;
        }

        $version = $data['version'] ?? null;

        if ($version !== 1) {
            $io->error(sprintf('Unsupported version: %s. This command supports version 1.', $version ?? 'missing'));

            return Command::FAILURE;
        }

        $requiredKeys = ['organization', 'providerCredentials', 'syncLists', 'manualContacts'];
        $missingKeys = array_diff($requiredKeys, array_keys($data));

        if ($missingKeys !== []) {
            $io->error(sprintf('Missing required keys: %s', implode(', ', $missingKeys)));

            return Command::FAILURE;
        }

        $existingOrg = $this->orgRepository->findOne();

        if ($existingOrg !== null) {
            if (!$input->getOption('force')) {
                if (!$input->isInteractive()) {
                    $io->error('Existing data already exists. Use --force to overwrite in non-interactive mode.');

                    return Command::FAILURE;
                }

                $confirm = $io->confirm(
                    'Existing data will be permanently deleted and replaced. Continue?',
                    false,
                );

                if (!$confirm) {
                    $io->warning('Import aborted.');

                    return self::ABORT;
                }
            }
        }

        return Command::SUCCESS;
    }
}
