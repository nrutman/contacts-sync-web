<?php

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Organization
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Organization name must not be blank.')]
    #[Assert\Length(max: 255)]
    private string $name;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, SyncList>
     */
    #[ORM\OneToMany(
        targetEntity: SyncList::class,
        mappedBy: 'organization',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    ),]
    private Collection $syncLists;

    /**
     * @var Collection<int, ProviderCredential>
     */
    #[ORM\OneToMany(
        targetEntity: ProviderCredential::class,
        mappedBy: 'organization',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    ),]
    private Collection $providerCredentials;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $retentionDays = null;

    /**
     * @var Collection<int, ManualContact>
     */
    #[ORM\OneToMany(
        targetEntity: ManualContact::class,
        mappedBy: 'organization',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    ),]
    private Collection $manualContacts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->syncLists = new ArrayCollection();
        $this->providerCredentials = new ArrayCollection();
        $this->manualContacts = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getRetentionDays(): ?int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(?int $retentionDays): static
    {
        $this->retentionDays = $retentionDays;

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
            $syncList->setOrganization($this);
        }

        return $this;
    }

    public function removeSyncList(SyncList $syncList): static
    {
        $this->syncLists->removeElement($syncList);

        return $this;
    }

    /**
     * @return Collection<int, ProviderCredential>
     */
    public function getProviderCredentials(): Collection
    {
        return $this->providerCredentials;
    }

    public function addProviderCredential(ProviderCredential $credential): static
    {
        if (!$this->providerCredentials->contains($credential)) {
            $this->providerCredentials->add($credential);
            $credential->setOrganization($this);
        }

        return $this;
    }

    public function removeProviderCredential(ProviderCredential $credential): static
    {
        $this->providerCredentials->removeElement($credential);

        return $this;
    }

    /**
     * @return Collection<int, ManualContact>
     */
    public function getManualContacts(): Collection
    {
        return $this->manualContacts;
    }

    public function addManualContact(ManualContact $manualContact): static
    {
        if (!$this->manualContacts->contains($manualContact)) {
            $this->manualContacts->add($manualContact);
            $manualContact->setOrganization($this);
        }

        return $this;
    }

    public function removeManualContact(
        ManualContact $manualContact,
    ): static {
        $this->manualContacts->removeElement($manualContact);

        return $this;
    }
}
