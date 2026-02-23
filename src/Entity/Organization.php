<?php

namespace App\Entity;

use App\Attribute\Encrypted;
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

    #[Encrypted]
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Planning Center App ID is required.')]
    private string $planningCenterAppId;

    #[Encrypted]
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Planning Center App Secret is required.')]
    private string $planningCenterAppSecret;

    #[Encrypted]
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Google OAuth credentials are required.')]
    #[Assert\Json(message: 'Google OAuth credentials must be valid JSON.')]
    private string $googleOAuthCredentials;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Google domain must not be blank.')]
    #[Assert\Length(max: 255)]
    private string $googleDomain;

    #[Encrypted]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $googleToken = null;

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
     * @var Collection<int, InMemoryContact>
     */
    #[ORM\OneToMany(
        targetEntity: InMemoryContact::class,
        mappedBy: 'organization',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    ),]
    private Collection $inMemoryContacts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->syncLists = new ArrayCollection();
        $this->inMemoryContacts = new ArrayCollection();
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

    public function getPlanningCenterAppId(): string
    {
        return $this->planningCenterAppId;
    }

    public function setPlanningCenterAppId(string $planningCenterAppId): static
    {
        $this->planningCenterAppId = $planningCenterAppId;

        return $this;
    }

    public function getPlanningCenterAppSecret(): string
    {
        return $this->planningCenterAppSecret;
    }

    public function setPlanningCenterAppSecret(
        string $planningCenterAppSecret,
    ): static {
        $this->planningCenterAppSecret = $planningCenterAppSecret;

        return $this;
    }

    public function getGoogleOAuthCredentials(): string
    {
        return $this->googleOAuthCredentials;
    }

    public function setGoogleOAuthCredentials(
        string $googleOAuthCredentials,
    ): static {
        $this->googleOAuthCredentials = $googleOAuthCredentials;

        return $this;
    }

    public function getGoogleDomain(): string
    {
        return $this->googleDomain;
    }

    public function setGoogleDomain(string $googleDomain): static
    {
        $this->googleDomain = $googleDomain;

        return $this;
    }

    public function getGoogleToken(): ?string
    {
        return $this->googleToken;
    }

    public function setGoogleToken(?string $googleToken): static
    {
        $this->googleToken = $googleToken;

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
            $inMemoryContact->setOrganization($this);
        }

        return $this;
    }

    public function removeInMemoryContact(
        InMemoryContact $inMemoryContact,
    ): static {
        $this->inMemoryContacts->removeElement($inMemoryContact);

        return $this;
    }
}
