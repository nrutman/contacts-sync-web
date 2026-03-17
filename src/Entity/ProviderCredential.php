<?php

namespace App\Entity;

use App\Attribute\Encrypted;
use App\Repository\ProviderCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\StringUuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProviderCredentialRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProviderCredential
{
    #[ORM\Id]
    #[ORM\Column(type: StringUuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'providerCredentials')]
    #[ORM\JoinColumn(nullable: false)]
    private Organization $organization;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $providerName;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    #[Encrypted]
    #[ORM\Column(type: 'text')]
    private string $credentials = '{}';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function setProviderName(string $providerName): static
    {
        $this->providerName = $providerName;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getCredentials(): string
    {
        return $this->credentials;
    }

    public function setCredentials(string $credentials): static
    {
        $this->credentials = $credentials;

        return $this;
    }

    /**
     * Returns the credentials as a decoded array.
     *
     * @return array<string, mixed>
     */
    public function getCredentialsArray(): array
    {
        return json_decode($this->credentials, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Sets the credentials from an array, encoding to JSON.
     *
     * @param array<string, mixed> $credentials
     */
    public function setCredentialsArray(array $credentials): static
    {
        $this->credentials = json_encode($credentials, JSON_THROW_ON_ERROR);

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadataValue(string $key, mixed $value): static
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;

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
     * Display label: uses custom label or falls back to provider name.
     */
    public function getDisplayLabel(): string
    {
        return $this->label ?? $this->providerName;
    }

    public function __toString(): string
    {
        return $this->getDisplayLabel();
    }
}
