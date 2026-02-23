<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class SyncList
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'syncLists')]
    #[ORM\JoinColumn(nullable: false)]
    private Organization $organization;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private bool $isEnabled = true;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cronExpression = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, SyncRun>
     */
    #[ORM\OneToMany(targetEntity: SyncRun::class, mappedBy: 'syncList', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $syncRuns;

    /**
     * @var Collection<int, InMemoryContact>
     */
    #[ORM\ManyToMany(targetEntity: InMemoryContact::class, mappedBy: 'syncLists')]
    private Collection $inMemoryContacts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->syncRuns = new ArrayCollection();
        $this->inMemoryContacts = new ArrayCollection();
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

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function getCronExpression(): ?string
    {
        return $this->cronExpression;
    }

    public function setCronExpression(?string $cronExpression): static
    {
        $this->cronExpression = $cronExpression;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, SyncRun>
     */
    public function getSyncRuns(): Collection
    {
        return $this->syncRuns;
    }

    public function addSyncRun(SyncRun $syncRun): static
    {
        if (!$this->syncRuns->contains($syncRun)) {
            $this->syncRuns->add($syncRun);
            $syncRun->setSyncList($this);
        }

        return $this;
    }

    public function removeSyncRun(SyncRun $syncRun): static
    {
        $this->syncRuns->removeElement($syncRun);

        return $this;
    }

    /**
     * @return Collection<int, InMemoryContact>
     */
    public function getInMemoryContacts(): Collection
    {
        return $this->inMemoryContacts;
    }

    public function addInMemoryContact(InMemoryContact $inMemoryContact): static
    {
        if (!$this->inMemoryContacts->contains($inMemoryContact)) {
            $this->inMemoryContacts->add($inMemoryContact);
            $inMemoryContact->addSyncList($this);
        }

        return $this;
    }

    public function removeInMemoryContact(InMemoryContact $inMemoryContact): static
    {
        if ($this->inMemoryContacts->removeElement($inMemoryContact)) {
            $inMemoryContact->removeSyncList($this);
        }

        return $this;
    }
}
