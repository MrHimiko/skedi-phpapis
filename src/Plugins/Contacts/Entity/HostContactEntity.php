<?php

namespace App\Plugins\Contacts\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;

#[ORM\Entity]
#[ORM\Table(name: "host_contacts")]
#[ORM\HasLifecycleCallbacks]
class HostContactEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContactEntity::class)]
    #[ORM\JoinColumn(name: "contact_id", referencedColumnName: "id", nullable: false)]
    private ContactEntity $contact;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "host_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $host;

    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;

    #[ORM\Column(name: "relationship_type", type: "string", length: 50, options: ["default" => "contact"])]
    private string $relationshipType = 'contact';

    #[ORM\Column(name: "first_meeting", type: "datetime", nullable: true)]
    private ?DateTimeInterface $firstMeeting = null;

    #[ORM\Column(name: "last_meeting", type: "datetime", nullable: true)]
    private ?DateTimeInterface $lastMeeting = null;

    #[ORM\Column(name: "meeting_count", type: "integer", options: ["default" => 0])]
    private int $meetingCount = 0;

    #[ORM\Column(name: "notes", type: "text", nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: "is_favorite", type: "boolean", options: ["default" => false])]
    private bool $isFavorite = false;


    #[ORM\Column(name: "created", type: "datetime", nullable: false)]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false)]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    // Add all getters and setters here...
    public function getId(): ?int { return $this->id; }
    
    public function getContact(): ContactEntity { return $this->contact; }
    public function setContact(ContactEntity $contact): self { $this->contact = $contact; return $this; }
    
    public function getHost(): UserEntity { return $this->host; }
    public function setHost(UserEntity $host): self { $this->host = $host; return $this; }
    
    public function getOrganization(): OrganizationEntity { return $this->organization; }
    public function setOrganization(OrganizationEntity $organization): self { $this->organization = $organization; return $this; }
    
    public function getRelationshipType(): string { return $this->relationshipType; }
    public function setRelationshipType(string $relationshipType): self { $this->relationshipType = $relationshipType; return $this; }
    
    public function getFirstMeeting(): ?DateTimeInterface { return $this->firstMeeting; }
    public function setFirstMeeting(?DateTimeInterface $firstMeeting): self { $this->firstMeeting = $firstMeeting; return $this; }
    
    public function getLastMeeting(): ?DateTimeInterface { return $this->lastMeeting; }
    public function setLastMeeting(?DateTimeInterface $lastMeeting): self { $this->lastMeeting = $lastMeeting; return $this; }
    
    public function getMeetingCount(): int { return $this->meetingCount; }
    public function setMeetingCount(int $meetingCount): self { $this->meetingCount = $meetingCount; return $this; }
    
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    
    public function getCreated(): DateTimeInterface { return $this->created; }
    public function getUpdated(): DateTimeInterface { return $this->updated; }
    
    public function isDeleted(): bool { return $this->deleted; }
    public function setDeleted(bool $deleted): self { $this->deleted = $deleted; return $this; }

    public function isFavorite(): bool 
    { 
        return $this->isFavorite; 
    }

    public function setIsFavorite(bool $isFavorite): self 
    { 
        $this->isFavorite = $isFavorite; 
        return $this; 
    }
    
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }
}