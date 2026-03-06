<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Creates a new user account for the web interface',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant the user ROLE_ADMIN')
            ->setHelp('Interactive command that creates a new user. CLI-created users are auto-verified and can log in immediately.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create User');

        $email = $this->askEmail($io);

        if ($email === null) {
            return Command::FAILURE;
        }

        $firstName = $io->ask('First name');

        if ($firstName === null || $firstName === '') {
            $io->error('First name is required.');

            return Command::FAILURE;
        }

        $lastName = $io->ask('Last name');

        if ($lastName === null || $lastName === '') {
            $io->error('Last name is required.');

            return Command::FAILURE;
        }

        $password = $this->askPassword($io);

        if ($password === null) {
            return Command::FAILURE;
        }

        $isAdmin = $input->getOption('admin');

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsVerified(true);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $roles = ['ROLE_USER'];

        if ($isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'User "%s" (%s) created successfully%s.',
            $user->getFullName(),
            $user->getEmail(),
            $isAdmin ? ' with ROLE_ADMIN' : '',
        ));

        return Command::SUCCESS;
    }

    private function askEmail(SymfonyStyle $io): ?string
    {
        $email = $io->ask('Email');

        if ($email === null || $email === '') {
            $io->error('Email is required.');

            return null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error(sprintf('"%s" is not a valid email address.', $email));

            return null;
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing !== null) {
            $io->error(sprintf('A user with the email "%s" already exists.', $email));

            return null;
        }

        return $email;
    }

    private function askPassword(SymfonyStyle $io): ?string
    {
        $password = $io->askHidden('Password (min 8 characters)');

        if ($password === null || strlen($password) < 8) {
            $io->error('Password must be at least 8 characters.');

            return null;
        }

        $confirm = $io->askHidden('Confirm password');

        if ($confirm !== $password) {
            $io->error('Passwords do not match.');

            return null;
        }

        return $password;
    }
}
