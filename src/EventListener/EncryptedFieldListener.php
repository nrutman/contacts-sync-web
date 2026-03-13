<?php

namespace App\EventListener;

use App\Attribute\Encrypted;
use App\Security\EncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
class EncryptedFieldListener
{
    /**
     * Tracks which objects have already been decrypted, keyed by spl_object_id.
     * This prevents double-decryption if an entity is loaded multiple times
     * within the same request.
     *
     * @var array<int, true>
     */
    private array $decrypted = [];

    public function __construct(
        private readonly EncryptionService $encryptionService,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->encryptFields($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $this->encryptFields($entity);

        // Doctrine's preUpdate change set is computed before the listener fires.
        // We need to re-compute it so Doctrine sees the encrypted values.
        $em = $args->getObjectManager();
        $classMetadata = $em->getClassMetadata($entity::class);
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet($classMetadata, $entity);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        // After preUpdate encrypts the in-memory fields, re-decrypt so the entity
        // remains usable within the same request (e.g. when multiple sync lists
        // share the same ProviderCredential and a token refresh triggers a flush).
        $this->decryptFields($args->getObject());
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->decryptFields($args->getObject());
    }

    private function encryptFields(object $entity): void
    {
        $properties = $this->getEncryptedProperties($entity);

        foreach ($properties as $property) {
            $value = $property->getValue($entity);

            if ($value === null || $value === '') {
                continue;
            }

            $property->setValue($entity, $this->encryptionService->encrypt($value));
        }

        // Remove from decrypted tracking since the in-memory values are now ciphertext.
        // After flush, Doctrine will trigger postLoad or the UoW will have the encrypted
        // values. We mark it so that postLoad will decrypt again.
        unset($this->decrypted[spl_object_id($entity)]);
    }

    private function decryptFields(object $entity): void
    {
        $objectId = spl_object_id($entity);

        if (isset($this->decrypted[$objectId])) {
            return;
        }

        $properties = $this->getEncryptedProperties($entity);

        foreach ($properties as $property) {
            $value = $property->getValue($entity);

            if ($value === null || $value === '') {
                continue;
            }

            $property->setValue($entity, $this->encryptionService->decrypt($value));
        }

        $this->decrypted[$objectId] = true;
    }

    /**
     * @return \ReflectionProperty[]
     */
    private function getEncryptedProperties(object $entity): array
    {
        $reflection = new \ReflectionClass($entity);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Encrypted::class);

            if ($attributes === []) {
                continue;
            }

            $properties[] = $property;
        }

        return $properties;
    }
}
