<?php

namespace App\Entity;

use App\Repository\InMemoryContactRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InMemoryContactRepository::class)]
#[ORM\HasLifecycleCallbacks]
class InMemoryContact
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(
        targetEntity: Organization::class,
        inversedBy: 'inMemoryContacts',
    ),]
    #[ORM\JoinColumn(nullable: false)]
    private Organization $organization;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, SyncList>
     */
    #[ORM\ManyToMany(
        targetEntity: SyncList::class,
        inversedBy: 'inMemoryContacts',
    ),]
    #[ORM\JoinTable(name: 'in_memory_contact_sync_list')]
    private Collection $syncLists;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->syncLists = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, SyncList>
     */
    public function getSyncLists(): Collection
    {
        return $this->syncLists;
    }

    public function addSyncList(SyncList $syncList): static
    {
        if (!$this->syncLists->contains($syncList)) {
            $this->syncLists->add($syncList);
        }

        return $this;
    }

    public function removeSyncList(SyncList $syncList): static
    {
        $this->syncLists->removeElement($syncList);

        return $this;
    }
}
