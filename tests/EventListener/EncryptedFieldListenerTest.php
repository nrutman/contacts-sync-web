<?php

namespace App\Tests\EventListener;

use App\Attribute\Encrypted;
use App\EventListener\EncryptedFieldListener;
use App\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class EncryptedFieldListenerTest extends MockeryTestCase
{
    private EncryptionService $encryptionService;
    private EncryptedFieldListener $listener;

    protected function setUp(): void
    {
        $this->encryptionService = new EncryptionService(bin2hex(random_bytes(32)));
        $this->listener = new EncryptedFieldListener($this->encryptionService);
    }

    public function testPrePersistEncryptsFields(): void
    {
        $entity = new EncryptedFieldTestEntity('secret-value');
        $em = \Mockery::mock(EntityManagerInterface::class);
        $args = new PrePersistEventArgs($entity, $em);

        $this->listener->prePersist($args);

        self::assertNotSame('secret-value', $entity->secret);
        self::assertSame('secret-value', $this->encryptionService->decrypt($entity->secret));
    }

    public function testPostLoadDecryptsFields(): void
    {
        $entity = new EncryptedFieldTestEntity($this->encryptionService->encrypt('secret-value'));
        $em = \Mockery::mock(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);

        $this->listener->postLoad($args);

        self::assertSame('secret-value', $entity->secret);
    }

    public function testPostLoadSkipsAlreadyDecryptedEntity(): void
    {
        $encrypted = $this->encryptionService->encrypt('secret-value');
        $entity = new EncryptedFieldTestEntity($encrypted);
        $em = \Mockery::mock(EntityManagerInterface::class);
        $args = new PostLoadEventArgs($entity, $em);

        $this->listener->postLoad($args);
        self::assertSame('secret-value', $entity->secret);

        // Manually set to something else — postLoad should skip since already tracked
        $entity->secret = 'modified';
        $this->listener->postLoad($args);
        self::assertSame('modified', $entity->secret);
    }

    public function testEntityUsableAfterFlushCycle(): void
    {
        // This test simulates the exact bug: entity is loaded, modified, flushed,
        // then accessed again in the same request.
        $entity = new EncryptedFieldTestEntity($this->encryptionService->encrypt('{"token":"abc"}'));
        $em = \Mockery::mock(EntityManagerInterface::class);

        // 1. Load and decrypt
        $this->listener->postLoad(new PostLoadEventArgs($entity, $em));
        self::assertSame('{"token":"abc"}', $entity->secret);

        // 2. Modify (simulates token refresh)
        $entity->secret = '{"token":"xyz"}';

        // 3. Flush triggers preUpdate → encrypts
        $uow = \Mockery::mock(UnitOfWork::class);
        $uow->shouldReceive('recomputeSingleEntityChangeSet')->once();
        $em->shouldReceive('getClassMetadata')->with($entity::class)->andReturn(
            \Mockery::mock(ClassMetadata::class),
        );
        $em->shouldReceive('getUnitOfWork')->andReturn($uow);

        $changeSet = [];
        $this->listener->preUpdate(new PreUpdateEventArgs($entity, $em, $changeSet));

        // 4. postUpdate re-decrypts
        $this->listener->postUpdate(new PostUpdateEventArgs($entity, $em));

        // 5. Entity should still be usable — this is the line that would throw
        //    JsonException before the fix
        $decoded = json_decode($entity->secret, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('xyz', $decoded['token']);
    }

    public function testNullFieldsAreSkipped(): void
    {
        $entity = new EncryptedFieldTestEntity(null);
        $em = \Mockery::mock(EntityManagerInterface::class);

        $this->listener->prePersist(new PrePersistEventArgs($entity, $em));
        self::assertNull($entity->secret);

        $this->listener->postLoad(new PostLoadEventArgs($entity, $em));
        self::assertNull($entity->secret);
    }

    public function testEmptyStringFieldsAreSkipped(): void
    {
        $entity = new EncryptedFieldTestEntity('');
        $em = \Mockery::mock(EntityManagerInterface::class);

        $this->listener->prePersist(new PrePersistEventArgs($entity, $em));
        self::assertSame('', $entity->secret);

        $this->listener->postLoad(new PostLoadEventArgs($entity, $em));
        self::assertSame('', $entity->secret);
    }

    public function testNonEncryptedPropertiesAreIgnored(): void
    {
        $entity = new MixedFieldTestEntity('plain', 'secret-data');
        $em = \Mockery::mock(EntityManagerInterface::class);

        $this->listener->prePersist(new PrePersistEventArgs($entity, $em));

        // Non-encrypted field untouched
        self::assertSame('plain', $entity->plainField);
        // Encrypted field was encrypted
        self::assertNotSame('secret-data', $entity->secretField);
        self::assertSame('secret-data', $this->encryptionService->decrypt($entity->secretField));
    }
}

class EncryptedFieldTestEntity
{
    #[Encrypted]
    public ?string $secret;

    public function __construct(?string $secret)
    {
        $this->secret = $secret;
    }
}

class MixedFieldTestEntity
{
    public string $plainField;

    #[Encrypted]
    public string $secretField;

    public function __construct(string $plainField, string $secretField)
    {
        $this->plainField = $plainField;
        $this->secretField = $secretField;
    }
}
