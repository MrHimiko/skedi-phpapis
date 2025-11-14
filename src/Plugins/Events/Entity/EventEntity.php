<?php

namespace App\Plugins\Events\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Account\Entity\UserEntity;

#[ORM\Entity]
#[ORM\Table(name: "events")]
#[ORM\HasLifecycleCallbacks]
class EventEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\Column(name: "name", type: "string", length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: "slug", type: "string", length: 255, nullable: false)]
    private string $slug;
    
    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;
    
    #[ORM\ManyToOne(targetEntity: OrganizationEntity::class)]
    #[ORM\JoinColumn(name: "organization_id", referencedColumnName: "id", nullable: false)]
    private OrganizationEntity $organization;
    
    #[ORM\ManyToOne(targetEntity: TeamEntity::class)]
    #[ORM\JoinColumn(name: "team_id", referencedColumnName: "id", nullable: true)]
    private ?TeamEntity $team = null;   

    #[ORM\Column(name: "duration", type: "json", nullable: true)]
    private ?array $duration = null;
    
    #[ORM\Column(name: "schedule", type: "json", nullable: true)]
    private ?array $schedule = null;

    #[ORM\Column(name: "buffer_time", type: "integer", options: ["default" => 0])]
    private int $bufferTime = 0;

    #[ORM\Column(name: "advance_notice_minutes", type: "integer", options: ["default" => 60])]
    private int $advanceNoticeMinutes = 60;

    #[ORM\Column(name: "location", type: "json", nullable: true)]
    private ?array $location = null;

    #[ORM\Column(name: "availability_type", type: "string", length: 50, nullable: false, options: ["default" => "one_host_available"])]
    private string $availabilityType = 'one_host_available';




    #[ORM\Column(name: "acceptance_required", type: "boolean", options: ["default" => false])]
    private bool $acceptanceRequired = false;


    #[ORM\Column(name: "routing_enabled", type: "boolean", options: ["default" => false])]
    private bool $routingEnabled = false;

    #[ORM\Column(name: "routing_instructions", type: "text", nullable: true)]
    private ?string $routingInstructions = null;

    #[ORM\Column(name: "routing_fallback", type: "string", length: 50, options: ["default" => "round_robin"])]
    private string $routingFallback = 'round_robin';

    
    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "created_by", referencedColumnName: "id", nullable: false)]
    private UserEntity $createdBy;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
        // Default schedule with Mon-Fri enabled, Sat-Sun disabled
        $this->schedule = [
            'monday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'tuesday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'wednesday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'thursday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'friday' => ['enabled' => true, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'saturday' => ['enabled' => false, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []],
            'sunday' => ['enabled' => false, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'breaks' => []]
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getBufferTime(): int
    {
        return $this->bufferTime;
    }

    public function setBufferTime(int $bufferTime): self
    {
        $this->bufferTime = $bufferTime;
        return $this;
    }

    public function getAdvanceNoticeMinutes(): int
    {
        return $this->advanceNoticeMinutes;
    }

    public function setAdvanceNoticeMinutes(int $advanceNoticeMinutes): self
    {
        $this->advanceNoticeMinutes = $advanceNoticeMinutes;
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
    
    public function getTeam(): ?TeamEntity
    {
        return $this->team;
    }
    
    public function setTeam(?TeamEntity $team): self
    {
        $this->team = $team;
        return $this;
    }


    public function getDuration(): ?array
    {
        return $this->duration;
    }
    

    public function setDuration(?array $duration): self
    {
        if ($duration === null) {
            $duration = [
                [
                    'title' => 'Standard Meeting',
                    'description' => '',
                    'duration' => 30
                ]
            ];
        }
        

        foreach ($duration as &$option) {
            if (!isset($option['title'])) {
                $option['title'] = 'Untitled Meeting';
            }
            if (!isset($option['description'])) {
                $option['description'] = '';
            }
            if (!isset($option['duration']) || !is_numeric($option['duration'])) {
                $option['duration'] = 30;
            }
        }
        
        $this->duration = $duration;
        return $this;
    }   
 

    public function getSchedule(): ?array
    {
        return $this->schedule;
    }
    
    public function setSchedule(?array $schedule): self
    {
        $this->schedule = $schedule;
        return $this;
    }

    public function getRoutingEnabled(): bool
    {
        return $this->routingEnabled;
    }

    public function setRoutingEnabled(bool $routingEnabled): void
    {
        $this->routingEnabled = $routingEnabled;
    }

    public function isRoutingEnabled(): bool
    {
        return $this->routingEnabled;
    }

    public function getRoutingInstructions(): ?string
    {
        return $this->routingInstructions;
    }

    public function setRoutingInstructions(?string $routingInstructions): void
    {
        $this->routingInstructions = $routingInstructions;
    }

    public function getRoutingFallback(): string
    {
        return $this->routingFallback;
    }

    public function setRoutingFallback(string $routingFallback): void
    {
        $this->routingFallback = $routingFallback;
    }



    public function getAvailabilityType(): string
    {
        return $this->availabilityType;
    }

    public function setAvailabilityType(string $availabilityType): self
    {
        $this->availabilityType = $availabilityType;
        return $this;
    }

    public function isAcceptanceRequired(): bool
    {
        return $this->acceptanceRequired;
    }

    public function setAcceptanceRequired(bool $acceptanceRequired): self
    {
        $this->acceptanceRequired = $acceptanceRequired;
        return $this;
    }

    
    public function getCreatedBy(): UserEntity
    {
        return $this->createdBy;
    }
    
    public function setCreatedBy(UserEntity $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
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

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }
    
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }




    public function getLocation(): ?array
    {
        return $this->location;
    }

    public function setLocation($location): self
    {
        $this->location = $location;
        return $this;
    }


    public function toArray(): array
    {
        $data = [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'duration' => $this->getDuration(),
            'description' => $this->getDescription(),
            'schedule' => $this->getSchedule(),
            'availabilityType' => $this->getAvailabilityType(),
            'acceptanceRequired' => $this->isAcceptanceRequired(),
            'organization_id' => $this->getOrganization()->getId(),
            'created_by' => $this->getCreatedBy()->getId(),
            'buffer_time' => $this->getBufferTime(),
            'advance_notice_minutes' => $this->getAdvanceNoticeMinutes(),
            'routing_enabled' => $this->routingEnabled,
            'routing_instructions' => $this->routingInstructions,
            'routing_fallback' => $this->routingFallback,
            'deleted' => $this->isDeleted(),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            
        ];
        
        if ($this->getTeam()) {
            $data['team_id'] = $this->getTeam()->getId();
        }
        
        return $data;
    }
}