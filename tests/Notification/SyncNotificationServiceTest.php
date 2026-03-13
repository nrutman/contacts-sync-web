<?php

namespace App\Tests\Notification;

use App\Entity\SyncList;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Notification\SyncNotificationService;
use App\Repository\UserRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SyncNotificationServiceTest extends MockeryTestCase
{
    /** @var UserRepository|m\LegacyMockInterface|m\MockInterface */
    private $userRepository;

    /** @var MailerInterface|m\LegacyMockInterface|m\MockInterface */
    private $mailer;

    /** @var Environment|m\LegacyMockInterface|m\MockInterface */
    private $twig;

    private SyncNotificationService $service;

    protected function setUp(): void
    {
        $this->userRepository = m::mock(UserRepository::class);
        $this->mailer = m::mock(MailerInterface::class);
        $this->twig = m::mock(Environment::class);

        $this->service = new SyncNotificationService(
            $this->userRepository,
            $this->mailer,
            $this->twig,
            m::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    public function testSuccessWithChangesNotifiesUsersWithNotifyOnSuccess(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 3, removedCount: 1);

        $user = $this->makeUser('alice@example.com', notifyOnSuccess: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->with('email/sync_notification.html.twig', m::on(function (array $args) use ($user, $syncRun) {
                return $args['user'] === $user && $args['syncRuns'] === [$syncRun];
            }))
            ->andReturn('<html>notification</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::on(function (Email $email) {
                return $email->getTo()[0]->getAddress() === 'alice@example.com'
                    && str_contains($email->getSubject(), 'Completed')
                    && str_contains($email->getSubject(), 'test-list');
            }));

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testSuccessWithChangesDoesNotNotifyUsersWithNotifyOnSuccessDisabled(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 2, removedCount: 0);

        $user = $this->makeUser('bob@example.com', notifyOnSuccess: false);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testSuccessWithNoChangesNotifiesUsersWithNotifyOnNoChanges(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 0, removedCount: 0);

        $user = $this->makeUser('carol@example.com', notifyOnNoChanges: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>no changes</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::on(function (Email $email) {
                return $email->getTo()[0]->getAddress() === 'carol@example.com';
            }));

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testSuccessWithNoChangesDoesNotNotifyUsersWithOnlyNotifyOnSuccess(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 0, removedCount: 0);

        $user = $this->makeUser('dave@example.com', notifyOnSuccess: true, notifyOnNoChanges: false);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testFailureNotifiesUsersWithNotifyOnFailure(): void
    {
        $syncRun = $this->makeSyncRun('failed');

        $user = $this->makeUser('eve@example.com', notifyOnFailure: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>failure</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->with(m::on(function (Email $email) {
                return $email->getTo()[0]->getAddress() === 'eve@example.com'
                    && str_contains($email->getSubject(), 'Failed');
            }));

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testFailureDoesNotNotifyUsersWithNotifyOnFailureDisabled(): void
    {
        $syncRun = $this->makeSyncRun('failed');

        $user = $this->makeUser('frank@example.com', notifyOnFailure: false);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testUnverifiedUsersAreNeverNotified(): void
    {
        $syncRun = $this->makeSyncRun('failed');

        $user = $this->makeUser(
            'unverified@example.com',
            notifyOnFailure: true,
            verified: false,
        );

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testMultipleRecipientsEachReceiveAnEmail(): void
    {
        $syncRun = $this->makeSyncRun('failed');

        $user1 = $this->makeUser('alice@example.com', notifyOnFailure: true);
        $user2 = $this->makeUser('bob@example.com', notifyOnFailure: true);
        $user3 = $this->makeUser('carol@example.com', notifyOnFailure: false);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user1, $user2, $user3]);

        $this->twig
            ->shouldReceive('render')
            ->twice()
            ->andReturn('<html>notification</html>');

        $this->mailer
            ->shouldReceive('send')
            ->twice();

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testNoRecipientsResultsInNoEmails(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 1, removedCount: 0);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testAllPreferencesDisabledReceivesNoEmails(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 5, removedCount: 2);

        $user = $this->makeUser(
            'quiet@example.com',
            notifyOnSuccess: false,
            notifyOnFailure: false,
            notifyOnNoChanges: false,
        );

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->mailer->shouldNotReceive('send');

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testSuccessWithOnlyAddsCountsAsChanges(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 5, removedCount: 0);

        $user = $this->makeUser('add-only@example.com', notifyOnSuccess: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>added</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once();

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testSuccessWithOnlyRemovesCountsAsChanges(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 0, removedCount: 3);

        $user = $this->makeUser('remove-only@example.com', notifyOnSuccess: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>removed</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once();

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testNullCountsTreatedAsZeroForNoChangesNotification(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: null, removedCount: null);

        $user = $this->makeUser('null-counts@example.com', notifyOnNoChanges: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>null counts</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once();

        $this->service->sendBatchNotification([$syncRun]);
    }

    public function testMailerExceptionIsHandledGracefully(): void
    {
        $syncRun = $this->makeSyncRun('failed');
        $user = $this->makeUser('admin@example.com', notifyOnFailure: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user]);

        $this->twig
            ->shouldReceive('render')
            ->once()
            ->andReturn('<html>error</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('SMTP connection failed'));

        // Should not throw — exception is caught and logged
        $this->service->sendBatchNotification([$syncRun]);

        $results = $this->service->getLastResults();
        self::assertCount(1, $results);
        self::assertFalse($results[0]['success']);
        self::assertSame('SMTP connection failed', $results[0]['error']);
    }

    public function testGetLastResultsTracksSuccessAndFailure(): void
    {
        $syncRun = $this->makeSyncRun('failed');

        $user1 = $this->makeUser('alice@example.com', notifyOnFailure: true);
        $user2 = $this->makeUser('bob@example.com', notifyOnFailure: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$user1, $user2]);

        $this->twig
            ->shouldReceive('render')
            ->twice()
            ->andReturn('<html>notification</html>');

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->ordered();

        $this->mailer
            ->shouldReceive('send')
            ->once()
            ->ordered()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->service->sendBatchNotification([$syncRun]);

        $results = $this->service->getLastResults();
        self::assertCount(2, $results);
        self::assertSame('alice@example.com', $results[0]['email']);
        self::assertTrue($results[0]['success']);
        self::assertNull($results[0]['error']);
        self::assertSame('bob@example.com', $results[1]['email']);
        self::assertFalse($results[1]['success']);
        self::assertSame('Connection refused', $results[1]['error']);
    }

    public function testGetLastResultsResetsOnEachInvocation(): void
    {
        $syncRun = $this->makeSyncRun('success', addedCount: 0, removedCount: 0);

        $this->userRepository
            ->shouldReceive('findAll')
            ->andReturn([]);

        $this->service->sendBatchNotification([$syncRun]);
        self::assertSame([], $this->service->getLastResults());

        $this->service->sendBatchNotification([$syncRun]);
        self::assertSame([], $this->service->getLastResults());
    }

    public function testBatchWithMixedResultsNotifiesBothPreferences(): void
    {
        $successRun = $this->makeSyncRun('success', addedCount: 2, removedCount: 0);
        $failedRun = $this->makeSyncRun('failed');

        // User only wants failure notifications — should receive because batch has a failure
        $failureUser = $this->makeUser('fail-watcher@example.com', notifyOnFailure: true);
        // User only wants success notifications — should receive because batch has success with changes
        $successUser = $this->makeUser('success-watcher@example.com', notifyOnSuccess: true);

        $this->userRepository
            ->shouldReceive('findAll')
            ->once()
            ->andReturn([$failureUser, $successUser]);

        $this->twig
            ->shouldReceive('render')
            ->twice()
            ->andReturn('<html>batch</html>');

        $sentTo = [];
        $this->mailer
            ->shouldReceive('send')
            ->twice()
            ->with(m::on(function (Email $email) use (&$sentTo) {
                $sentTo[] = $email->getTo()[0]->getAddress();

                return str_contains($email->getSubject(), '2 lists');
            }));

        $this->service->sendBatchNotification([$successRun, $failedRun]);

        self::assertContains('fail-watcher@example.com', $sentTo);
        self::assertContains('success-watcher@example.com', $sentTo);
    }

    private function makeSyncRun(
        string $status,
        ?int $addedCount = null,
        ?int $removedCount = null,
    ): SyncRun {
        $syncList = new SyncList();
        $syncList->setName('test-list');

        $syncRun = new SyncRun();
        $syncRun->setSyncList($syncList);
        $syncRun->setTriggeredBy('manual');
        $syncRun->setStatus($status);
        $syncRun->setStartedAt(new \DateTimeImmutable());
        $syncRun->setCompletedAt(new \DateTimeImmutable());

        if ($addedCount !== null) {
            $syncRun->setAddedCount($addedCount);
        }

        if ($removedCount !== null) {
            $syncRun->setRemovedCount($removedCount);
        }

        return $syncRun;
    }

    private function makeUser(
        string $email,
        bool $notifyOnSuccess = false,
        bool $notifyOnFailure = false,
        bool $notifyOnNoChanges = false,
        bool $verified = true,
    ): User {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setIsVerified($verified);
        $user->setNotifyOnSuccess($notifyOnSuccess);
        $user->setNotifyOnFailure($notifyOnFailure);
        $user->setNotifyOnNoChanges($notifyOnNoChanges);

        return $user;
    }
}
