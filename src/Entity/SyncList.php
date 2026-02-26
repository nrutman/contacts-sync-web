<?php

namespace App\Entity;

use App\Repository\SyncListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SyncListRepository::class)]
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
    #[Assert\NotBlank(message: 'List name must not be blank.')]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: ProviderCredential::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProviderCredential $sourceCredential = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceListIdentifier = null;

    #[ORM\ManyToOne(targetEntity: ProviderCredential::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ProviderCredential $destinationCredential = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $destinationListIdentifier = null;

    #[ORM\Column]
    private bool $isEnabled = true;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[\App\Validator\CronExpression]
    private ?string $cronExpression = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, SyncRun>
     */
    #[ORM\OneToMany(
        targetEntity: SyncRun::class,
        mappedBy: 'syncList',
        cascade: ['remove'],
        orphanRemoval: true,
    ),]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $syncRuns;

    /**
     * @var Collection<int, InMemoryContact>
     */
    #[ORM\ManyToMany(
        targetEntity: InMemoryContact::class,
        mappedBy: 'syncLists',
    ),]
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

    public function getSourceCredential(): ?ProviderCredential
    {
        return $this->sourceCredential;
    }

    public function setSourceCredential(?ProviderCredential $sourceCredential): static
    {
        $this->sourceCredential = $sourceCredential;

        return $this;
    }

    public function getSourceListIdentifier(): ?string
    {
        return $this->sourceListIdentifier;
    }

    public function setSourceListIdentifier(?string $sourceListIdentifier): static
    {
        $this->sourceListIdentifier = $sourceListIdentifier;

        return $this;
    }

    public function getDestinationCredential(): ?ProviderCredential
    {
        return $this->destinationCredential;
    }

    public function setDestinationCredential(?ProviderCredential $destinationCredential): static
    {
        $this->destinationCredential = $destinationCredential;

        return $this;
    }

    public function getDestinationListIdentifier(): ?string
    {
        return $this->destinationListIdentifier;
    }

    public function setDestinationListIdentifier(?string $destinationListIdentifier): static
    {
        $this->destinationListIdentifier = $destinationListIdentifier;

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

    public function removeInMemoryContact(
        InMemoryContact $inMemoryContact,
    ): static {
        if ($this->inMemoryContacts->removeElement($inMemoryContact)) {
            $inMemoryContact->removeSyncList($this);
        }

        return $this;
    }
}
