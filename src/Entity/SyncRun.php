<?php

namespace App\Entity;

use App\Repository\SyncRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Doctrine\Type\StringUuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SyncRunRepository::class)]
#[ORM\HasLifecycleCallbacks]
class SyncRun
{
    #[ORM\Id]
    #[ORM\Column(type: StringUuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: SyncList::class, inversedBy: 'syncRuns')]
    #[ORM\JoinColumn(nullable: false)]
    private SyncList $syncList;

    #[ORM\Column(length: 20)]
    private string $triggeredBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $triggeredByUser = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(nullable: true)]
    private ?int $sourceCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $destinationCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $addedCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $removedCount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $log = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, SyncRunContact>
     */
    #[ORM\OneToMany(
        targetEntity: SyncRunContact::class,
        mappedBy: 'syncRun',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $syncRunContacts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->syncRunContacts = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSyncList(): SyncList
    {
        return $this->syncList;
    }

    public function setSyncList(SyncList $syncList): static
    {
        $this->syncList = $syncList;

        return $this;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(string $triggeredBy): static
    {
        $this->triggeredBy = $triggeredBy;

        return $this;
    }

    public function getTriggeredByUser(): ?User
    {
        return $this->triggeredByUser;
    }

    public function setTriggeredByUser(?User $triggeredByUser): static
    {
        $this->triggeredByUser = $triggeredByUser;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSourceCount(): ?int
    {
        return $this->sourceCount;
    }

    public function setSourceCount(?int $sourceCount): static
    {
        $this->sourceCount = $sourceCount;

        return $this;
    }

    public function getDestinationCount(): ?int
    {
        return $this->destinationCount;
    }

    public function setDestinationCount(?int $destinationCount): static
    {
        $this->destinationCount = $destinationCount;

        return $this;
    }

    public function getAddedCount(): ?int
    {
        return $this->addedCount;
    }

    public function setAddedCount(?int $addedCount): static
    {
        $this->addedCount = $addedCount;

        return $this;
    }

    public function getRemovedCount(): ?int
    {
        return $this->removedCount;
    }

    public function setRemovedCount(?int $removedCount): static
    {
        $this->removedCount = $removedCount;

        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function setLog(?string $log): static
    {
        $this->log = $log;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the duration of the sync run in seconds, or null if not yet complete.
     */
    public function getDurationSeconds(): ?float
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return null;
        }

        return (float) $this->completedAt->format('U.u') -
            (float) $this->startedAt->format('U.u');
    }

    /**
     * @return Collection<int, SyncRunContact>
     */
    public function getSyncRunContacts(): Collection
    {
        return $this->syncRunContacts;
    }

    public function addSyncRunContact(SyncRunContact $contact): static
    {
        if (!$this->syncRunContacts->contains($contact)) {
            $this->syncRunContacts->add($contact);
            $contact->setSyncRun($this);
        }

        return $this;
    }
}
