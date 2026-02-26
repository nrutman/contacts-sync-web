<?php

namespace App\Command;

use App\Client\Provider\OAuthProviderInterface;
use App\Client\Provider\ProviderRegistry;
use App\Entity\ProviderCredential;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sync:configure',
    description: 'Configures OAuth authentication for provider credentials that require it',
),]
class ConfigureSyncCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderCredentialRepository $providerCredentialRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'An interactive command that prompts for OAuth authentication for providers that require it (e.g. Google Groups).',
        );

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Forces the configuration prompts whether or not values are currently set.',
        );

        $this->addOption(
            'credential',
            'c',
            InputOption::VALUE_REQUIRED,
            'The ID of a specific ProviderCredential to configure.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $credentialId = $input->getOption('credential');

        if ($credentialId !== null) {
            $credentials = [$this->providerCredentialRepository->find($credentialId)];

            if ($credentials[0] === null) {
                $io->error(sprintf('Provider credential "%s" not found.', $credentialId));

                return Command::FAILURE;
            }
        } else {
            $credentials = $this->providerCredentialRepository->findAll();
        }

        if (count($credentials) === 0) {
            $io->error(
                'No provider credentials found. Please create credentials via the web UI first.',
            );

            return Command::FAILURE;
        }

        $configured = 0;

        foreach ($credentials as $credential) {
            $provider = $this->providerRegistry->get($credential->getProviderName());

            if (!$provider instanceof OAuthProviderInterface) {
                continue;
            }

            if ($this->isOAuthConfigured($provider, $credential) && !$input->getOption('force')) {
                $io->writeln(sprintf(
                    'Credential "%s" (%s) is already configured. Use --force to reconfigure.',
                    $credential->getDisplayLabel(),
                    $provider->getDisplayName(),
                ));

                continue;
            }

            $io->section(sprintf('Configure %s: %s', $provider->getDisplayName(), $credential->getDisplayLabel()));

            $creds = $credential->getCredentialsArray();
            $domain = $creds['domain'] ?? 'your Google domain';

            $io->block(
                sprintf(
                    'This requires a valid token for authentication with your Google account (%s). Please visit the following URL in a web browser and paste the provided authentication code into the prompt below.',
                    $domain,
                ),
            );

            $callbackUrl = 'urn:ietf:wg:oauth:2.0:oob';
            $authUrl = $provider->getOAuthStartUrl($credential, $callbackUrl);
            $io->writeln($authUrl.PHP_EOL);

            $authCode = trim($io->ask('Paste the auth code here:') ?? '');

            if (!$authCode) {
                $io->error('A Google authentication code must be provided.');

                return Command::FAILURE;
            }

            $updatedCreds = $provider->handleOAuthCallback($credential, $authCode, $callbackUrl);
            $credential->setCredentialsArray($updatedCreds);
            $this->entityManager->flush();

            $io->success(sprintf('%s authentication configured successfully.', $provider->getDisplayName()));
            ++$configured;
        }

        if ($configured === 0) {
            $io->writeln('No OAuth-requiring credentials needed configuration.');
        }

        return Command::SUCCESS;
    }

    private function isOAuthConfigured(OAuthProviderInterface $provider, ProviderCredential $credential): bool
    {
        $creds = $credential->getCredentialsArray();

        return isset($creds['token']) && $creds['token'] !== null;
    }
}
