<?php

namespace App\Tests\Command;

use App\Command\MigrateConfigToDbCommand;
use App\Command\SetupCommand;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

class SetupCommandTest extends MockeryTestCase
{
    private EntityManagerInterface|m\MockInterface $entityManager;
    private UserPasswordHasherInterface|m\MockInterface $passwordHasher;
    private EntityRepository|m\MockInterface $userRepository;
    private EntityRepository|m\MockInterface $organizationRepository;
    private string $tempDir;
    private string $varPath;
    private string $configDir;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->passwordHasher = m::mock(UserPasswordHasherInterface::class);
        $this->userRepository = m::mock(EntityRepository::class);
        $this->organizationRepository = m::mock(EntityRepository::class);

        $this->tempDir = sys_get_temp_dir().'/contacts_sync_test_'.uniqid();
        mkdir($this->tempDir);
        $this->varPath = $this->tempDir.'/var';
        mkdir($this->varPath);
        $this->configDir = $this->tempDir.'/config';
        mkdir($this->configDir);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(User::class)
            ->andReturn($this->userRepository)
            ->byDefault();

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(Organization::class)
            ->andReturn($this->organizationRepository)
            ->byDefault();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up temp files
        $envLocalPath = $this->tempDir.'/.env.local';

        if (file_exists($envLocalPath)) {
            unlink($envLocalPath);
        }

        $configFile = $this->configDir.'/parameters.yml';

        if (file_exists($configFile)) {
            unlink($configFile);
        }

        if (is_dir($this->configDir)) {
            rmdir($this->configDir);
        }

        if (is_dir($this->varPath)) {
            rmdir($this->varPath);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testHasExistingConfigReturnsFalseWhenFileDoesNotExist(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, '/nonexistent/path.yml');

        self::assertFalse($result);
    }

    public function testHasExistingConfigReturnsFalseWhenPlaceholders(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: '{{PlanningCenter Application ID}}',
            googleDomain: '{{G Suite Domain}}',
            lists: ['list1'],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, $configPath);

        self::assertFalse($result);
    }

    public function testHasExistingConfigReturnsTrueWhenRealValues(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: 'real-app-id',
            googleDomain: 'example.com',
            lists: ['list1@example.com'],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, $configPath);

        self::assertTrue($result);
    }

    public function testHasExistingConfigReturnsFalseWhenEmptyAppId(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: '',
            googleDomain: 'example.com',
            lists: ['list1@example.com'],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, $configPath);

        self::assertFalse($result);
    }

    public function testHasExistingConfigReturnsFalseWhenEmptyDomain(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: 'real-app-id',
            googleDomain: '',
            lists: ['list1@example.com'],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, $configPath);

        self::assertFalse($result);
    }

    public function testHasExistingConfigReturnsFalseWhenNoLists(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: 'real-app-id',
            googleDomain: 'example.com',
            lists: [],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'hasExistingConfig');
        $result = $reflection->invoke($command, $configPath);

        self::assertFalse($result);
    }

    public function testWriteEnvLocalCreatesNewFile(): void
    {
        $command = $this->createSetupCommand();
        $envLocalPath = $this->tempDir.'/.env.local';

        $reflection = new \ReflectionMethod($command, 'writeEnvLocal');
        $reflection->invoke($command, $envLocalPath, [
            'DATABASE_URL' => 'postgresql://user:pass@localhost:5432/db?serverVersion=16&charset=utf8',
            'APP_ENCRYPTION_KEY' => 'abc123def456',
            'MAILER_DSN' => 'null://null',
        ]);

        self::assertFileExists($envLocalPath);
        $contents = file_get_contents($envLocalPath);
        self::assertStringContainsString(
            'DATABASE_URL="postgresql://user:pass@localhost:5432/db?serverVersion=16&charset=utf8"',
            $contents,
        );
        self::assertStringContainsString(
            'APP_ENCRYPTION_KEY=abc123def456',
            $contents,
        );
        self::assertStringContainsString('MAILER_DSN="null://null"', $contents);
    }

    public function testWriteEnvLocalMergesWithExistingFile(): void
    {
        $command = $this->createSetupCommand();
        $envLocalPath = $this->tempDir.'/.env.local';

        // Write an initial file with some values
        file_put_contents(
            $envLocalPath,
            "EXISTING_KEY=existing_value\nAPP_ENCRYPTION_KEY=old_key\n",
        );

        $reflection = new \ReflectionMethod($command, 'writeEnvLocal');
        $reflection->invoke($command, $envLocalPath, [
            'APP_ENCRYPTION_KEY' => 'new_key',
            'DATABASE_URL' => 'postgresql://localhost/test',
        ]);

        $contents = file_get_contents($envLocalPath);

        // Existing unrelated keys should be preserved
        self::assertStringContainsString(
            'EXISTING_KEY=existing_value',
            $contents,
        );
        // Updated key should have the new value
        self::assertStringContainsString(
            'APP_ENCRYPTION_KEY=new_key',
            $contents,
        );
        // Old value should be gone
        self::assertStringNotContainsString('old_key', $contents);
        // New key should be appended
        self::assertStringContainsString('DATABASE_URL', $contents);
    }

    public function testWriteEnvLocalPreservesOrderOfExistingKeys(): void
    {
        $command = $this->createSetupCommand();
        $envLocalPath = $this->tempDir.'/.env.local';

        file_put_contents(
            $envLocalPath,
            "FIRST_KEY=1\nSECOND_KEY=2\nTHIRD_KEY=3\n",
        );

        $reflection = new \ReflectionMethod($command, 'writeEnvLocal');
        $reflection->invoke($command, $envLocalPath, [
            'SECOND_KEY' => 'updated',
        ]);

        $contents = file_get_contents($envLocalPath);
        $lines = array_filter(explode("\n", trim($contents)));

        self::assertSame('FIRST_KEY=1', $lines[0]);
        self::assertSame('SECOND_KEY=updated', $lines[1]);
        self::assertSame('THIRD_KEY=3', $lines[2]);
    }

    public function testReadEnvLocalValueReturnsValueForExistingKey(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $envLocalPath = $this->tempDir.'/.env.local';

        file_put_contents(
            $envLocalPath,
            "APP_ENCRYPTION_KEY=abc123\nDATABASE_URL=\"postgresql://localhost\"\n",
        );

        $reflection = new \ReflectionMethod($command, 'readEnvLocalValue');

        $result = $reflection->invoke($command, 'APP_ENCRYPTION_KEY');
        self::assertSame('abc123', $result);
    }

    public function testReadEnvLocalValueReturnsQuotedValue(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $envLocalPath = $this->tempDir.'/.env.local';

        file_put_contents(
            $envLocalPath,
            "DATABASE_URL=\"postgresql://user:pass@localhost:5432/db\"\n",
        );

        $reflection = new \ReflectionMethod($command, 'readEnvLocalValue');

        $result = $reflection->invoke($command, 'DATABASE_URL');
        self::assertSame('postgresql://user:pass@localhost:5432/db', $result);
    }

    public function testReadEnvLocalValueUnescapesDoublePercent(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $envLocalPath = $this->tempDir.'/.env.local';

        file_put_contents(
            $envLocalPath,
            "DATABASE_URL=\"mysql://user:7%%24%%21K@localhost:3306/db\"\n",
        );

        $reflection = new \ReflectionMethod($command, 'readEnvLocalValue');

        $result = $reflection->invoke($command, 'DATABASE_URL');
        self::assertSame('mysql://user:7%24%21K@localhost:3306/db', $result);
    }

    public function testReadEnvLocalValueReturnsNullForMissingKey(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $envLocalPath = $this->tempDir.'/.env.local';

        file_put_contents($envLocalPath, "SOME_KEY=value\n");

        $reflection = new \ReflectionMethod($command, 'readEnvLocalValue');

        $result = $reflection->invoke($command, 'MISSING_KEY');
        self::assertNull($result);
    }

    public function testReadEnvLocalValueReturnsNullWhenFileDoesNotExist(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);

        $reflection = new \ReflectionMethod($command, 'readEnvLocalValue');

        $result = $reflection->invoke($command, 'ANY_KEY');
        self::assertNull($result);
    }

    public function testFormatEnvLineQuotesUrlValues(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'formatEnvLine');

        $result = $reflection->invoke(
            $command,
            'DATABASE_URL',
            'postgresql://user:pass@localhost:5432/db',
        );
        self::assertSame(
            'DATABASE_URL="postgresql://user:pass@localhost:5432/db"',
            $result,
        );
    }

    public function testFormatEnvLineDoesNotQuoteSimpleValues(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'formatEnvLine');

        $result = $reflection->invoke(
            $command,
            'APP_ENCRYPTION_KEY',
            'abc123def456',
        );
        self::assertSame('APP_ENCRYPTION_KEY=abc123def456', $result);
    }

    public function testFormatEnvLineEscapesPercentSigns(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'formatEnvLine');

        // URL-encoded password: 7$!K(Wy&g5x → 7%24%21K%284Wy%26g5x
        $result = $reflection->invoke(
            $command,
            'DATABASE_URL',
            'mysql://user:7%24%21K%284Wy%26g5x@127.0.0.1:3306/db?serverVersion=8.0&charset=utf8mb4',
        );

        // % must be doubled so Symfony's resolve processor doesn't
        // interpret %24% as a parameter reference
        self::assertSame(
            'DATABASE_URL="mysql://user:7%%24%%21K%%284Wy%%26g5x@127.0.0.1:3306/db?serverVersion=8.0&charset=utf8mb4"',
            $result,
        );
    }

    public function testFormatEnvLineQuotesValuesWithSpaces(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'formatEnvLine');

        $result = $reflection->invoke(
            $command,
            'SOME_KEY',
            'value with spaces',
        );
        self::assertSame('SOME_KEY="value with spaces"', $result);
    }

    public function testGetProjectDirDerivedFromVarPath(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'getProjectDir');

        $result = $reflection->invoke($command);
        self::assertSame($this->tempDir, $result);
    }

    public function testStepCreateAdminUserSkipsInNonInteractiveWhenUsersExist(): void
    {
        $existingUser = new User();
        $existingUser->setEmail('existing@example.com');
        $existingUser->setFirstName('Existing');
        $existingUser->setLastName('User');

        $this->userRepository
            ->shouldReceive('findAll')
            ->andReturn([$existingUser]);

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'stepCreateAdminUser');

        // Initialize io on the command via reflection
        $io = $this->initializeIo($command);

        $input = m::mock(
            \Symfony\Component\Console\Input\InputInterface::class,
        );
        $input->shouldReceive('isInteractive')->andReturn(false);

        $reflection->invoke($command, $input);

        // No persist/flush should be called — user creation is skipped
        $this->entityManager->shouldNotHaveReceived('persist');
    }

    public function testStepImportConfigurationSkipsWhenNoLegacyConfigOption(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod(
            $command,
            'stepImportConfiguration',
        );
        $this->initializeIo($command);

        $input = m::mock(
            \Symfony\Component\Console\Input\InputInterface::class,
        );
        $input->shouldReceive('isInteractive')->andReturn(false);
        $input->shouldReceive('getOption')->with('legacy-config')->andReturn(null);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $reflection->invoke($command, $input, $output);

        // No persist/flush should be called — import is skipped
        $this->entityManager->shouldNotHaveReceived('persist');
    }

    public function testStepImportConfigurationSkipsWhenConfigHasPlaceholders(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: '{{PlanningCenter Application ID}}',
            googleDomain: '{{G Suite Domain}}',
            lists: ['list1'],
        );

        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod(
            $command,
            'stepImportConfiguration',
        );
        $this->initializeIo($command);

        $input = m::mock(
            \Symfony\Component\Console\Input\InputInterface::class,
        );
        $input->shouldReceive('isInteractive')->andReturn(false);
        $input->shouldReceive('getOption')->with('legacy-config')->andReturn($configPath);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $reflection->invoke($command, $input, $output);

        // No persist/flush should be called — import is skipped due to placeholders
        $this->entityManager->shouldNotHaveReceived('persist');
    }

    public function testStepImportConfigurationDelegatesToMigrateCommand(): void
    {
        $configPath = $this->writeConfigFile(
            planningCenterAppId: 'real-app-id',
            googleDomain: 'example.com',
            lists: ['list1@example.com'],
        );

        $command = $this->createSetupCommand();

        $migrateCommand = m::mock(MigrateConfigToDbCommand::class);
        $migrateCommand->shouldReceive('run')->once()->andReturn(Command::SUCCESS);

        $stubCommand = $this->createMigrateConfigStub($migrateCommand);

        $application = new Application();
        $application->addCommand($command);
        $application->addCommand($stubCommand);

        $reflection = new \ReflectionMethod(
            $command,
            'stepImportConfiguration',
        );
        $this->initializeIo($command);

        $input = m::mock(
            \Symfony\Component\Console\Input\InputInterface::class,
        );
        $input->shouldReceive('isInteractive')->andReturn(false);
        $input->shouldReceive('getOption')->with('legacy-config')->andReturn($configPath);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $reflection->invoke($command, $input, $output);
    }

    public function testStepEncryptionKeyGeneratesNewKeyWhenNoExisting(): void
    {
        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $this->initializeIo($command);

        $reflection = new \ReflectionMethod($command, 'stepEncryptionKey');
        $reflection->invoke($command);

        // Read back the encryptionKey property
        $keyProperty = new \ReflectionProperty($command, 'encryptionKey');
        $key = $keyProperty->getValue($command);

        // A newly generated key should be 64 hex characters
        self::assertSame(64, strlen($key));
        self::assertTrue(ctype_xdigit($key));
    }

    public function testStepEncryptionKeyKeepsExistingByDefault(): void
    {
        $existingKey = bin2hex(sodium_crypto_secretbox_keygen());
        $envLocalPath = $this->tempDir.'/.env.local';
        file_put_contents(
            $envLocalPath,
            sprintf("APP_ENCRYPTION_KEY=%s\n", $existingKey),
        );

        $command = $this->createSetupCommand();
        $this->setProjectDir($command);

        // Build a SymfonyStyle with a stream that answers "yes" to the confirm prompt
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "yes\n");
        rewind($stream);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream($stream);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(
            $input,
            $output,
        );

        $ioProperty = new \ReflectionProperty($command, 'io');
        $ioProperty->setValue($command, $io);

        $reflection = new \ReflectionMethod($command, 'stepEncryptionKey');
        $reflection->invoke($command);

        // Read back the encryptionKey property — it should be the existing key
        $keyProperty = new \ReflectionProperty($command, 'encryptionKey');
        $key = $keyProperty->getValue($command);

        self::assertSame($existingKey, $key);
    }

    public function testStepDatabaseConnectionKeepsExistingUrl(): void
    {
        $existingUrl = 'mysql://myuser:p%40ss@127.0.0.1:3306/mydb?serverVersion=8.0&charset=utf8mb4';
        $envLocalPath = $this->tempDir.'/.env.local';
        // Write with %% escaping, matching what formatEnvLine produces
        file_put_contents(
            $envLocalPath,
            sprintf("DATABASE_URL=\"%s\"\n", str_replace('%', '%%', $existingUrl)),
        );

        $command = $this->createSetupCommand();
        $this->setProjectDir($command);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "yes\n");
        rewind($stream);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream($stream);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);

        $ioProperty = new \ReflectionProperty($command, 'io');
        $ioProperty->setValue($command, $io);

        $reflection = new \ReflectionMethod($command, 'stepDatabaseConnection');
        $result = $reflection->invoke($command, $input);

        self::assertTrue($result);

        $urlProperty = new \ReflectionProperty($command, 'databaseUrl');
        self::assertSame($existingUrl, $urlProperty->getValue($command));

        $driverProperty = new \ReflectionProperty($command, 'driver');
        self::assertSame('MySQL', $driverProperty->getValue($command));
    }

    public function testStepDatabaseConnectionSkipsPlaceholderUrl(): void
    {
        $envLocalPath = $this->tempDir.'/.env.local';
        file_put_contents(
            $envLocalPath,
            "DATABASE_URL=\"postgresql://contacts_sync:password@127.0.0.1:5432/contacts_sync?serverVersion=16&charset=utf8\"\n",
        );

        $command = $this->createSetupCommand();
        $this->setProjectDir($command);

        // The prompt will fall through to the normal flow which calls io->choice().
        // In non-interactive mode this returns the default (PostgreSQL).
        // Then io->ask() returns defaults and askHidden() returns null.
        // testDatabaseConnection will fail (no DB), so it returns false.
        $this->initializeIo($command);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);

        $reflection = new \ReflectionMethod($command, 'stepDatabaseConnection');
        $result = $reflection->invoke($command, $input);

        // Connection fails with defaults — the point is it didn't keep the placeholder
        self::assertFalse($result);

        $urlProperty = new \ReflectionProperty($command, 'databaseUrl');
        self::assertStringStartsWith('postgresql://', $urlProperty->getValue($command));
    }

    public function testStepEmailConfigurationKeepsExistingDsn(): void
    {
        $existingDsn = 'smtp://user:pass@smtp.example.com:587';
        $envLocalPath = $this->tempDir.'/.env.local';
        file_put_contents(
            $envLocalPath,
            sprintf("MAILER_DSN=\"%s\"\n", $existingDsn),
        );

        $command = $this->createSetupCommand();
        $this->setProjectDir($command);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "yes\n");
        rewind($stream);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream($stream);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);

        $ioProperty = new \ReflectionProperty($command, 'io');
        $ioProperty->setValue($command, $io);

        $reflection = new \ReflectionMethod($command, 'stepEmailConfiguration');
        $reflection->invoke($command, $input);

        $dsnProperty = new \ReflectionProperty($command, 'mailerDsn');
        self::assertSame($existingDsn, $dsnProperty->getValue($command));
    }

    public function testStepEmailConfigurationPromptsWhenNullDsn(): void
    {
        $envLocalPath = $this->tempDir.'/.env.local';
        file_put_contents($envLocalPath, "MAILER_DSN=\"null://null\"\n");

        $command = $this->createSetupCommand();
        $this->setProjectDir($command);
        $this->initializeIo($command);

        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);

        $reflection = new \ReflectionMethod($command, 'stepEmailConfiguration');
        $reflection->invoke($command, $input);

        // In non-interactive mode, ask() returns the default 'null://null'
        $dsnProperty = new \ReflectionProperty($command, 'mailerDsn');
        self::assertSame('null://null', $dsnProperty->getValue($command));
    }

    public function testDatabaseConnectionTestWithInvalidHost(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'testDatabaseConnection');

        // Use localhost on a port that nothing is listening on — connection
        // is refused immediately instead of hanging until a timeout.
        $result = $reflection->invoke(
            $command,
            '127.0.0.1',
            '1',
            'test',
            'user',
            'pass',
        );

        self::assertIsString($result);
        self::assertNotSame(true, $result);
    }

    public function testQuoteIdentifierPostgresql(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'quoteIdentifier');

        self::assertSame(
            '"contacts_sync"',
            $reflection->invoke($command, 'contacts_sync'),
        );
        self::assertSame(
            '"my""database"',
            $reflection->invoke($command, 'my"database'),
        );
    }

    public function testQuoteIdentifierMysql(): void
    {
        $command = $this->createSetupCommand();
        $this->setDriver($command, 'MySQL');

        $reflection = new \ReflectionMethod($command, 'quoteIdentifier');

        self::assertSame(
            '`contacts_sync`',
            $reflection->invoke($command, 'contacts_sync'),
        );
        self::assertSame(
            '`my``database`',
            $reflection->invoke($command, 'my`database'),
        );
    }

    public function testBuildDatabaseUrlPostgresql(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'buildDatabaseUrl');

        $url = $reflection->invoke(
            $command,
            '127.0.0.1',
            '5432',
            'contacts_sync',
            'user',
            'pass',
        );

        self::assertStringStartsWith('postgresql://', $url);
        self::assertStringContainsString('serverVersion=16', $url);
        self::assertStringContainsString('charset=utf8', $url);
        self::assertStringContainsString('user:pass@127.0.0.1:5432/contacts_sync', $url);
    }

    public function testBuildDatabaseUrlMysql(): void
    {
        $command = $this->createSetupCommand();
        $this->setDriver($command, 'MySQL');

        $reflection = new \ReflectionMethod($command, 'buildDatabaseUrl');

        $url = $reflection->invoke(
            $command,
            '127.0.0.1',
            '3306',
            'contacts_sync',
            'user',
            'pass',
        );

        self::assertStringStartsWith('mysql://', $url);
        self::assertStringContainsString('serverVersion=8.0', $url);
        self::assertStringContainsString('charset=utf8mb4', $url);
        self::assertStringContainsString('user:pass@127.0.0.1:3306/contacts_sync', $url);
    }

    public function testBuildDatabaseUrlEncodesSpecialCharacters(): void
    {
        $command = $this->createSetupCommand();

        $reflection = new \ReflectionMethod($command, 'buildDatabaseUrl');

        $url = $reflection->invoke(
            $command,
            '127.0.0.1',
            '5432',
            'mydb',
            'user@name',
            'p@ss:word',
        );

        self::assertStringContainsString('user%40name', $url);
        self::assertStringContainsString('p%40ss%3Aword', $url);
    }

    private function createSetupCommand(): SetupCommand
    {
        return new SetupCommand(
            $this->entityManager,
            $this->passwordHasher,
            $this->varPath,
        );
    }

    private function writeConfigFile(
        string $planningCenterAppId = 'REPLACE_ME',
        string $planningCenterAppSecret = 'REPLACE_ME',
        array $googleConfiguration = [],
        string $googleDomain = 'REPLACE_ME',
        array $lists = [],
        array $manualContacts = [],
    ): string {
        $config = [
            'parameters' => [
                'planning_center.app.id' => $planningCenterAppId,
                'planning_center.app.secret' => $planningCenterAppSecret,
                'google.authentication' => $googleConfiguration,
                'google.domain' => $googleDomain,
                'lists' => $lists,
                'contacts' => $manualContacts,
            ],
        ];

        $path = $this->configDir.'/parameters.yml';
        file_put_contents($path, Yaml::dump($config, 4));

        return $path;
    }

    private function createMigrateConfigStub(m\MockInterface $mock): Command
    {
        $stub = new class ($mock) extends Command {
            private m\MockInterface $mock;

            public function __construct(m\MockInterface $mock)
            {
                $this->mock = $mock;
                parent::__construct('app:migrate-config');
            }

            protected function configure(): void
            {
                $this->addArgument('config-file', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output,
            ): int {
                return $this->mock->run($input, $output);
            }
        };

        return $stub;
    }

    private function createCommandTester(SetupCommand $command): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('app:setup'));
    }

    private function setProjectDir(SetupCommand $command): void
    {
        $property = new \ReflectionProperty($command, 'projectDir');
        $property->setValue($command, $this->tempDir);
    }

    private function setDriver(SetupCommand $command, string $driver): void
    {
        $property = new \ReflectionProperty($command, 'driver');
        $property->setValue($command, $driver);
    }

    /**
     * Initializes the SymfonyStyle `io` property on the command for unit-testing individual steps.
     */
    private function initializeIo(
        SetupCommand $command,
    ): \Symfony\Component\Console\Style\SymfonyStyle {
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $input->setInteractive(false);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(
            $input,
            $output,
        );

        $ioProperty = new \ReflectionProperty($command, 'io');
        $ioProperty->setValue($command, $io);

        return $io;
    }
}
