<?php

namespace App\Plugins\Contacts\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;

#[ORM\Entity(repositoryClass: "App\Plugins\Contacts\Repository\OrganizationContactRepository")]
#[ORM\Table(name: "organization_contacts")]
#[ORM\HasLifecycleCallbacks]
class OrganizationContactEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContactEntity::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id", nullable: false)]
    private ContactEntity $contact;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\Column(name: "first_interaction", type: "datetime", nullable: false)]
    private DateTimeInterface $firstInteraction;

    #[ORM\Column(name: "last_interaction", type: "datetime", nullable: false)]
    private DateTimeInterface $lastInteraction;

    #[ORM\Column(name: "interaction_count", type: "integer", options: ["default" => 1])]
    private int $interactionCount = 1;

    #[ORM\Column(name: "tags", type: "json", nullable: true)]
    private ?array $tags = [];

    #[ORM\Column(name: "notes", type: "text", nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: "custom_fields", type: "json", nullable: true)]
    private ?array $customFields = [];

    #[ORM\Column(name: "created", type: "datetime", nullable: false)]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false)]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    #[ORM\Column(name: "is_favorite", type: "boolean", options: ["default" => false])]
    private bool $isFavorite = false;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
        $this->firstInteraction = new DateTime();
        $this->lastInteraction = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContact(): ContactEntity
    {
        return $this->contact;
    }

    public function setContact(ContactEntity $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function getOrganization(): OrganizationEntity
    {
        return $this->organization;
    }

    public function setOrganization(OrganizationEntity $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    public function getFirstInteraction(): DateTimeInterface
    {
        return $this->firstInteraction;
    }

    public function setFirstInteraction(DateTimeInterface $firstInteraction): self
    {
        $this->firstInteraction = $firstInteraction;
        return $this;
    }

    public function getLastInteraction(): DateTimeInterface
    {
        return $this->lastInteraction;
    }

    public function setLastInteraction(DateTimeInterface $lastInteraction): self
    {
        $this->lastInteraction = $lastInteraction;
        return $this;
    }

    public function getInteractionCount(): int
    {
        return $this->interactionCount;
    }

    public function setInteractionCount(int $interactionCount): self
    {
        $this->interactionCount = $interactionCount;
        return $this;
    }

    public function incrementInteractionCount(): self
    {
        $this->interactionCount++;
        $this->lastInteraction = new DateTime();
        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): self
    {
        $this->isFavorite = $isFavorite;
        return $this;
    }

    public function getCustomFields(): ?array
    {
        return $this->customFields;
    }

    public function setCustomFields(?array $customFields): self
    {
        $this->customFields = $customFields;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'contact' => $this->getContact()->toArray(),
            'organization_id' => $this->getOrganization()->getId(),
            'first_interaction' => $this->getFirstInteraction()->format('Y-m-d H:i:s'),
            'last_interaction' => $this->getLastInteraction()->format('Y-m-d H:i:s'),
            'interaction_count' => $this->getInteractionCount(),
            'tags' => $this->getTags(),
            'notes' => $this->getNotes(),
            'custom_fields' => $this->getCustomFields(),
            'is_favorite' => $this->isFavorite(), 
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];
    }
}