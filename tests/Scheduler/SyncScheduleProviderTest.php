<?php

namespace App\Tests\Scheduler;

use App\Entity\Organization;
use App\Entity\SyncList;
use App\Message\SyncMessage;
use App\Scheduler\SyncScheduleProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery as m;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Contracts\Cache\CacheInterface;

class SyncScheduleProviderTest extends MockeryTestCase
{
    private EntityManagerInterface|m\LegacyMockInterface $entityManager;
    private CacheInterface|m\LegacyMockInterface $cache;
    private EntityRepository|m\LegacyMockInterface $syncListRepository;
    private SyncScheduleProvider $provider;

    protected function setUp(): void
    {
        $this->entityManager = m::mock(EntityManagerInterface::class);
        $this->cache = m::mock(CacheInterface::class);
        $this->syncListRepository = m::mock(EntityRepository::class);

        $this->entityManager
            ->shouldReceive('getRepository')
            ->with(SyncList::class)
            ->andReturn($this->syncListRepository);

        $this->cache->shouldReceive('clear')->byDefault();

        $this->provider = new SyncScheduleProvider(
            $this->entityManager,
            $this->cache,
        );
    }

    public function testGetScheduleWithNoSyncLists(): void
    {
        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->once()
            ->andReturn([]);

        $schedule = $this->provider->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(0, $messages);
    }

    public function testGetScheduleWithEnabledListsWithCronExpressions(): void
    {
        $organization = $this->createOrganization();

        $list1 = new SyncList();
        $list1->setName('List One');
        $list1->setOrganization($organization);
        $list1->setCronExpression('0 * * * *');

        $list2 = new SyncList();
        $list2->setName('List Two');
        $list2->setOrganization($organization);
        $list2->setCronExpression('*/30 * * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->once()
            ->andReturn([$list1, $list2]);

        $schedule = $this->provider->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(2, $messages);
    }

    public function testGetScheduleSkipsListsWithNullCronExpression(): void
    {
        $organization = $this->createOrganization();

        $listWithCron = new SyncList();
        $listWithCron->setName('With Cron');
        $listWithCron->setOrganization($organization);
        $listWithCron->setCronExpression('0 6 * * *');

        $listWithoutCron = new SyncList();
        $listWithoutCron->setName('Without Cron');
        $listWithoutCron->setOrganization($organization);
        $listWithoutCron->setCronExpression(null);

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->once()
            ->andReturn([$listWithCron, $listWithoutCron]);

        $schedule = $this->provider->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(1, $messages);
    }

    public function testGetScheduleSkipsListsWithEmptyCronExpression(): void
    {
        $organization = $this->createOrganization();

        $listWithEmptyCron = new SyncList();
        $listWithEmptyCron->setName('Empty Cron');
        $listWithEmptyCron->setOrganization($organization);
        $listWithEmptyCron->setCronExpression('');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->once()
            ->andReturn([$listWithEmptyCron]);

        $schedule = $this->provider->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(0, $messages);
    }

    public function testGetScheduleCreatesSyncMessagesWithCorrectProperties(): void
    {
        $organization = $this->createOrganization();

        $syncList = new SyncList();
        $syncList->setName('Scheduled List');
        $syncList->setOrganization($organization);
        $syncList->setCronExpression('0 2 * * *');

        $this->syncListRepository
            ->shouldReceive('findBy')
            ->with(['isEnabled' => true])
            ->once()
            ->andReturn([$syncList]);

        $schedule = $this->provider->getSchedule();

        $messages = $schedule->getRecurringMessages();
        self::assertCount(1, $messages);

        /** @var RecurringMessage $recurringMessage */
        $recurringMessage = $messages[0];

        $trigger = $recurringMessage->getTrigger();
        self::assertInstanceOf(CronExpressionTrigger::class, $trigger);

        $context = new MessageContext(
            name: 'sync',
            id: $recurringMessage->getId(),
            trigger: $trigger,
            triggeredAt: new \DateTimeImmutable(),
        );

        $generatedMessages = iterator_to_array(
            $recurringMessage->getMessages($context),
        );
        self::assertCount(1, $generatedMessages);

        $syncMessage = $generatedMessages[0];
        self::assertInstanceOf(SyncMessage::class, $syncMessage);
        self::assertSame((string) $syncList->getId(), $syncMessage->syncListId);
        self::assertSame('schedule', $syncMessage->trigger);
        self::assertFalse($syncMessage->dryRun);
        self::assertNull($syncMessage->triggeredByUserId);
        self::assertNull($syncMessage->syncRunId);
    }

    private function createOrganization(): Organization
    {
        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setPlanningCenterAppId('pc-id');
        $organization->setPlanningCenterAppSecret('pc-secret');
        $organization->setGoogleOAuthCredentials('{}');
        $organization->setGoogleDomain('example.com');

        return $organization;
    }
}
