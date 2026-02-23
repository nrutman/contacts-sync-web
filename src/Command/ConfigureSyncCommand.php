<?php

namespace App\Command;

use App\Client\Google\GoogleClientFactory;
use App\Client\Google\InvalidGoogleTokenException;
use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Google\Exception as GoogleException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

#[AsCommand(
    name: 'sync:configure',
    description: 'Configures the utility so it can sync member lists',
),]
class ConfigureSyncCommand extends Command
{
    public function __construct(
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            sprintf(
                'An interactive command that prompts for all configuration needed to sync member lists via the %s command.',
                'sync:run',
            ),
        );

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Forces the configuration prompts whether or not values are currently set.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $organization = $this->entityManager
            ->getRepository(Organization::class)
            ->findOneBy([]);

        if ($organization === null) {
            $io->error(
                'No organization found. Please run app:setup first to create an organization.',
            );

            return Command::FAILURE;
        }

        $googleClient = $this->googleClientFactory->create($organization);

        if (
            !$this->isGoogleClientConfigured($googleClient)
            || $input->getOption('force')
        ) {
            $io->block(
                sprintf(
                    'This utility requires a valid token for authentication with your Google account (%s). Please visit the following URL in a web browser and paste the provided authentication code into the prompt below.',
                    $organization->getGoogleDomain(),
                ),
            );
            $io->writeln($googleClient->createAuthUrl().PHP_EOL);

            $authCode = trim($io->ask('Paste the auth code here:') ?? '');

            if (!$authCode) {
                $io->error('A Google authentication code must be provided.');

                return Command::FAILURE;
            }

            $googleClient->setAuthCode($authCode);

            // Persist the new token to the Organization entity
            $tokenData = $googleClient->getTokenData();

            if ($tokenData !== null) {
                $organization->setGoogleToken(
                    json_encode($tokenData, JSON_THROW_ON_ERROR),
                );
                $this->entityManager->flush();
            }

            $io->success('Google authentication configured successfully.');

            return Command::SUCCESS;
        }

        $io->writeln(
            'Google is already configured. If you want to reconfigure, use the --force option.',
        );

        return Command::SUCCESS;
    }

    /**
     * Determines whether the Google Client can be initialized without error.
     *
     * @throws GoogleException
     */
    private function isGoogleClientConfigured(
        \App\Client\Google\GoogleClient $googleClient,
    ): bool {
        try {
            $googleClient->initialize();
        } catch (InvalidGoogleTokenException|FileNotFoundException) {
            return false;
        }

        return true;
    }
}
