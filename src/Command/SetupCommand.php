<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'app:setup',
    description: 'Interactive first-run setup wizard for Contacts Sync',
),]
class SetupCommand extends Command
{
    private SymfonyStyle $io;
    private string $databaseUrl = '';
    private string $encryptionKey = '';
    private string $mailerDsn = '';
    private ?string $adminEmail = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $varPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Interactive wizard that configures the database, encryption, email, '.
                    'imports existing configuration, and creates the first admin user. '.
                    'Safe to run multiple times — existing values are preserved by default.',
            )
            ->addOption(
                'legacy-config',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the legacy parameters.yml file to import',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contacts Sync — Setup Wizard');

        $projectDir = $this->getProjectDir();

        // Step 1: Database connection
        if ($this->stepDatabaseConnection($input) === false) {
            return Command::FAILURE;
        }

        // Step 2: Encryption key
        $this->stepEncryptionKey($projectDir);

        // Step 3: Email configuration
        $this->stepEmailConfiguration($input);

        // Step 4: Write .env.local and create schema
        if (
            $this->stepWriteEnvAndMigrate($input, $output, $projectDir) ===
            false
        ) {
            return Command::FAILURE;
        }

        // Step 5: Import existing configuration
        $this->stepImportConfiguration($input, $output);

        // Step 6: Create admin user
        $this->stepCreateAdminUser($input);

        // Summary
        $this->printSummary();

        return Command::SUCCESS;
    }

    private function stepDatabaseConnection(InputInterface $input): bool
    {
        $this->io->section('Step 1/6: Database Connection');

        $maxAttempts = $input->isInteractive() ? 3 : 1;

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $host = $this->io->ask('Database host', '127.0.0.1');
            $port = $this->io->ask('Database port', '5432');
            $dbName = $this->io->ask('Database name', 'contacts_sync');
            $dbUser = $this->io->ask('Database user', 'contacts_sync');
            $dbPassword = $this->io->askHidden('Database password') ?? '';

            $this->databaseUrl = sprintf(
                'postgresql://%s:%s@%s:%s/%s?serverVersion=16&charset=utf8',
                urlencode($dbUser),
                urlencode($dbPassword),
                $host,
                $port,
                $dbName,
            );

            $connectionResult = $this->testDatabaseConnection(
                $host,
                $port,
                $dbName,
                $dbUser,
                $dbPassword,
            );

            if ($connectionResult === true) {
                $this->io->text(' Database connection... <info>✓</info>');

                return true;
            }

            if ($connectionResult === 'db_missing') {
                if (
                    $input->isInteractive()
                    && $this->io->confirm(
                        sprintf(
                            'Database "%s" does not exist. Create it?',
                            $dbName,
                        ),
                        true,
                    )
                ) {
                    if (
                        $this->createDatabase(
                            $host,
                            $port,
                            $dbName,
                            $dbUser,
                            $dbPassword,
                        )
                    ) {
                        $this->io->text(' Database created... <info>✓</info>');

                        return true;
                    }
                    $this->io->error('Failed to create database.');
                } else {
                    $this->io->error(
                        sprintf('Database "%s" does not exist.', $dbName),
                    );
                }
            } else {
                $this->io->error(
                    sprintf(
                        'Database connection failed: %s',
                        $connectionResult,
                    ),
                );
            }

            if ($attempt < $maxAttempts - 1) {
                $this->io->text('Please try again.');
            }
        }

        $this->io->error(
            'Could not establish a database connection after multiple attempts.',
        );

        return false;
    }

    /**
     * @return true|string returns true on success, 'db_missing' if the database doesn't exist, or an error message
     */
    private function testDatabaseConnection(
        string $host,
        string $port,
        string $dbName,
        string $user,
        string $password,
    ): true|string {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3',
            $host,
            $port,
            $dbName,
        );

        try {
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 3,
            ]);
            $pdo->query('SELECT 1');

            return true;
        } catch (\PDOException $e) {
            // PostgreSQL error code 08006 = connection failure, but also check for "does not exist"
            if (str_contains($e->getMessage(), 'does not exist')) {
                return 'db_missing';
            }

            return $e->getMessage();
        }
    }

    private function createDatabase(
        string $host,
        string $port,
        string $dbName,
        string $user,
        string $password,
    ): bool {
        try {
            $dsn = sprintf('pgsql:host=%s;port=%s', $host, $port);
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec(
                sprintf('CREATE DATABASE %s', $this->quoteIdentifier($dbName)),
            );

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function stepEncryptionKey(string $projectDir): void
    {
        $this->io->section('Step 2/6: Encryption Key');

        $existingKey = $this->readEnvLocalValue(
            $projectDir,
            'APP_ENCRYPTION_KEY',
        );

        if (
            $existingKey !== null
            && $existingKey !== ''
            && $existingKey !== str_repeat('0', 64)
        ) {
            $keepExisting = $this->io->confirm(
                'An encryption key already exists in .env.local. Keep the existing key? (Recommended — changing it will break already-encrypted data.)',
                true,
            );

            if ($keepExisting) {
                $this->encryptionKey = $existingKey;
                $this->io->text(
                    ' Keeping existing encryption key... <info>✓</info>',
                );

                return;
            }
        }

        $this->encryptionKey = bin2hex(sodium_crypto_secretbox_keygen());
        $this->io->text(
            sprintf(
                ' Generated new encryption key: <comment>%s...%s</comment>',
                substr($this->encryptionKey, 0, 8),
                substr($this->encryptionKey, -8),
            ),
        );
        $this->io->text(' Encryption key... <info>✓</info>');
    }

    private function stepEmailConfiguration(InputInterface $input): void
    {
        $this->io->section('Step 3/6: Email Configuration');

        $this->io->text([
            'The application sends email notifications after sync runs.',
            'Enter a mailer DSN, or press Enter to skip for now.',
            '',
            'Common examples:',
            '  SMTP:      smtp://user:pass@smtp.example.com:587',
            '  Gmail:     gmail+smtp://user:pass@default',
            '  Mailgun:   mailgun+api://KEY:DOMAIN@default',
            '  (skip):    null://null',
        ]);
        $this->io->newLine();

        $this->mailerDsn =
            $this->io->ask('Mailer DSN', 'null://null') ?? 'null://null';
        $this->io->text(' Email configuration... <info>✓</info>');
    }

    private function stepWriteEnvAndMigrate(
        InputInterface $input,
        OutputInterface $output,
        string $projectDir,
    ): bool {
        $this->io->section('Step 4/6: Database Setup');

        // Write .env.local
        $envLocalPath = $projectDir.'/.env.local';
        $newValues = [
            'DATABASE_URL' => $this->databaseUrl,
            'APP_ENCRYPTION_KEY' => $this->encryptionKey,
            'MAILER_DSN' => $this->mailerDsn,
        ];

        $this->writeEnvLocal($envLocalPath, $newValues);
        $this->io->text(' Writing .env.local... <info>✓</info>');

        // Run migrations
        try {
            $migrateCommand = $this->getApplication()?->find(
                'doctrine:migrations:migrate',
            );

            if ($migrateCommand === null) {
                $this->io->warning(
                    'Could not find doctrine:migrations:migrate command. Please run it manually.',
                );

                return true;
            }

            $migrateInput = new ArrayInput([
                '--no-interaction' => true,
                '--allow-no-migration' => true,
            ]);
            $migrateInput->setInteractive(false);

            $returnCode = $migrateCommand->run($migrateInput, $output);

            if ($returnCode !== Command::SUCCESS) {
                $this->io->error(
                    'Migration failed. Please check the output above and run "bin/console doctrine:migrations:migrate" manually.',
                );

                return false;
            }

            $this->io->text(' Running migrations... <info>✓</info>');
        } catch (\Throwable $e) {
            $this->io->error('Migration failed: '.$e->getMessage());
            $this->io->text(
                'You may need to run "bin/console doctrine:migrations:migrate" manually after fixing the issue.',
            );

            return false;
        }

        return true;
    }

    private function stepImportConfiguration(InputInterface $input, OutputInterface $output): void
    {
        $this->io->section('Step 5/6: Import Configuration');

        $legacyConfigPath = $input->getOption('legacy-config');

        if ($legacyConfigPath === null) {
            $this->io->text(
                ' No --legacy-config option provided — skipping import.',
            );
            $this->io->text(
                ' You can configure your organization via the Settings page after logging in.',
            );

            return;
        }

        if (!$this->hasExistingConfig($legacyConfigPath)) {
            $this->io->warning(
                'The legacy config file does not exist or contains only placeholder values — skipping import.',
            );
            $this->io->text(
                ' You can configure your organization via the Settings page after logging in.',
            );

            return;
        }

        if ($input->isInteractive()) {
            $import = $this->io->confirm(
                'Found existing configuration in the legacy config file. Import it into the database?',
                true,
            );

            if (!$import) {
                $this->io->text(' Skipping configuration import.');

                return;
            }
        }

        try {
            $migrateCommand = $this->getApplication()?->find('app:migrate-config');

            if ($migrateCommand === null) {
                $this->io->warning(
                    'Could not find app:migrate-config command. Please run it manually.',
                );

                return;
            }

            $migrateInput = new ArrayInput([
                'config-file' => $legacyConfigPath,
            ]);
            $migrateInput->setInteractive($input->isInteractive());

            $returnCode = $migrateCommand->run($migrateInput, $output);

            if ($returnCode !== Command::SUCCESS) {
                $this->io->warning(
                    'Configuration import failed. You can retry later using: bin/console app:migrate-config <path-to-parameters.yml>',
                );

                return;
            }

            $this->io->text(' Configuration imported... <info>✓</info>');
        } catch (\Throwable $e) {
            $this->io->warning(
                'Configuration import failed: '.$e->getMessage(),
            );
            $this->io->text(
                ' You can import later using the "app:migrate-config" command.',
            );
        }
    }

    private function stepCreateAdminUser(InputInterface $input): void
    {
        $this->io->section('Step 6/6: Create Admin User');

        $existingUsers = $this->entityManager
            ->getRepository(User::class)
            ->findAll();

        if (count($existingUsers) > 0) {
            if (!$input->isInteractive()) {
                $this->io->text(
                    ' Users already exist — skipping admin creation in non-interactive mode.',
                );

                return;
            }

            $create = $this->io->confirm(
                sprintf(
                    '%d user(s) already exist. Create an additional admin user?',
                    count($existingUsers),
                ),
                false,
            );

            if (!$create) {
                $this->io->text(' Skipping admin user creation.');

                return;
            }
        }

        $this->io->text(
            'This creates an administrator account for the web interface.',
        );
        $this->io->newLine();

        $email = $this->askAdminEmail($input);

        if ($email === null) {
            $this->io->warning('Admin user creation skipped.');

            return;
        }

        $firstName = $this->io->ask('First name');

        if ($firstName === null || $firstName === '') {
            $this->io->warning(
                'First name is required. Admin user creation skipped.',
            );

            return;
        }

        $lastName = $this->io->ask('Last name');

        if ($lastName === null || $lastName === '') {
            $this->io->warning(
                'Last name is required. Admin user creation skipped.',
            );

            return;
        }

        $password = $this->askAdminPassword($input);

        if ($password === null) {
            $this->io->warning('Admin user creation skipped.');

            return;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsVerified(true);
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->adminEmail = $email;
        $this->io->text(
            sprintf(' Admin user "%s" created... <info>✓</info>', $email),
        );
    }

    private function printSummary(): void
    {
        $this->io->section('Setup Complete');

        // Mask the password in the DATABASE_URL for display
        $displayUrl = preg_replace('/:([^@]+)@/', ':****@', $this->databaseUrl);

        $summary = [
            sprintf('Database: %s', $displayUrl),
            sprintf(
                'Encryption: Key %s in .env.local',
                $this->encryptionKey !== '' ? 'stored' : 'not configured',
            ),
            sprintf('Email: %s', $this->mailerDsn),
        ];

        if ($this->adminEmail !== null) {
            $summary[] = sprintf('Admin user: %s', $this->adminEmail);
        }

        $this->io->listing($summary);

        $this->io->text([
            '<info>Next steps:</info>',
            '  1. Start the web server:  <comment>symfony server:start</comment>',
            '  2. Visit:                 <comment>https://127.0.0.1:8000</comment>',
            '  3. Log in with the admin account created above.',
            '  4. (Optional) Connect Google: visit Settings → Google Connection',
            '  5. (Optional) Start the worker: <comment>bin/console messenger:consume async scheduler_sync</comment>',
        ]);
    }

    private function hasExistingConfig(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        try {
            $parsed = Yaml::parseFile($filePath);
        } catch (\Throwable) {
            return false;
        }

        $params = $parsed['parameters'] ?? [];

        $appId = $params['planning_center.app.id'] ?? '';

        if ($appId === '' || str_starts_with($appId, '{{')) {
            return false;
        }

        $domain = $params['google.domain'] ?? '';

        if ($domain === '' || str_starts_with($domain, '{{')) {
            return false;
        }

        $lists = $params['lists'] ?? [];

        if ($lists === []) {
            return false;
        }

        return true;
    }

    private function writeEnvLocal(string $path, array $newValues): void
    {
        $existingLines = [];
        $existingKeys = [];

        if (file_exists($path)) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $existingLines = explode("\n", $contents);

                foreach ($existingLines as $line) {
                    if (preg_match('/^([A-Z_]+)=/', $line, $matches)) {
                        $existingKeys[$matches[1]] = true;
                    }
                }
            }
        }

        // Update existing lines in place
        $updatedLines = [];

        foreach ($existingLines as $line) {
            $replaced = false;

            foreach ($newValues as $key => $value) {
                if (str_starts_with($line, $key.'=')) {
                    $updatedLines[] = $this->formatEnvLine($key, $value);
                    $replaced = true;
                    unset($newValues[$key]);

                    break;
                }
            }

            if (!$replaced) {
                $updatedLines[] = $line;
            }
        }

        // Append any new keys that weren't already in the file
        foreach ($newValues as $key => $value) {
            $updatedLines[] = $this->formatEnvLine($key, $value);
        }

        // Remove trailing empty lines then add a single trailing newline
        while (count($updatedLines) > 0 && end($updatedLines) === '') {
            array_pop($updatedLines);
        }

        file_put_contents($path, implode("\n", $updatedLines)."\n");
    }

    private function formatEnvLine(string $key, string $value): string
    {
        // Quote values that contain special characters
        if (
            preg_match('/[#\s"\'\\\\]/', $value)
            || str_contains($value, '://')
        ) {
            return sprintf('%s="%s"', $key, addcslashes($value, '"\\'));
        }

        return sprintf('%s=%s', $key, $value);
    }

    private function readEnvLocalValue(string $projectDir, string $key): ?string
    {
        $path = $projectDir.'/.env.local';

        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, $key.'=')) {
                $value = substr($line, strlen($key) + 1);

                // Strip surrounding quotes
                if (
                    strlen($value) >= 2
                    && $value[0] === '"'
                    && $value[-1] === '"'
                ) {
                    $value = stripcslashes(substr($value, 1, -1));
                }

                return $value;
            }
        }

        return null;
    }

    private function getProjectDir(): string
    {
        // Navigate from var/ path to project root
        return dirname($this->varPath);
    }

    private function askAdminEmail(InputInterface $input): ?string
    {
        $email = $this->io->ask('Email');

        if ($email === null || $email === '') {
            return null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->io->error(
                sprintf('"%s" is not a valid email address.', $email),
            );

            return $input->isInteractive()
                ? $this->askAdminEmail($input)
                : null;
        }

        $existing = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]);

        if ($existing !== null) {
            $this->io->error(
                sprintf('A user with the email "%s" already exists.', $email),
            );

            return $input->isInteractive()
                ? $this->askAdminEmail($input)
                : null;
        }

        return $email;
    }

    private function askAdminPassword(InputInterface $input): ?string
    {
        $password = $this->io->askHidden('Password (min 8 characters)');

        if ($password === null || strlen($password) < 8) {
            $this->io->error('Password must be at least 8 characters.');

            return $input->isInteractive()
                ? $this->askAdminPassword($input)
                : null;
        }

        $confirm = $this->io->askHidden('Confirm password');

        if ($confirm !== $password) {
            $this->io->error('Passwords do not match.');

            return $input->isInteractive()
                ? $this->askAdminPassword($input)
                : null;
        }

        return $password;
    }
}
