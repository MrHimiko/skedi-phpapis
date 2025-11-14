<?php

namespace App\Plugins\Events\Entity;
use App\Plugins\Account\Entity\UserEntity;
use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: "event_bookings")]
#[ORM\HasLifecycleCallbacks]
class EventBookingEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: EventEntity::class)]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: false)]
    private EventEntity $event;
    
    #[ORM\Column(name: "start_time", type: "datetime", nullable: false)]
    private DateTimeInterface $startTime;
    
    #[ORM\Column(name: "end_time", type: "datetime", nullable: false)]
    private DateTimeInterface $endTime;
    
    
    #[ORM\Column(name: "status", type: "string", length: 50, options: ["default" => "confirmed"])]
    private string $status = 'confirmed';
    
    #[ORM\Column(name: "form_data", type: "text", nullable: true)]
    private ?string $formData = null;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "assigned_to", referencedColumnName: "id", nullable: true)]
    private ?UserEntity $assignedTo = null;
    
    #[ORM\Column(name: "cancelled", type: "boolean", options: ["default" => false])]
    private bool $cancelled = false;

    #[ORM\Column(name: "booking_token", type: "string", length: 64, unique: true, nullable: true)]
    private ?string $bookingToken = null;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }
    
    public function getEvent(): EventEntity
    {
        return $this->event;
    }
    
    public function setEvent(EventEntity $event): self
    {
        $this->event = $event;
        return $this;
    }
    
    public function getStartTime(): DateTimeInterface
    {
        return $this->startTime;
    }
    
    public function setStartTime(DateTimeInterface $startTime): self
    {
        $this->startTime = $startTime;
        return $this;
    }
    
    public function getEndTime(): DateTimeInterface
    {
        return $this->endTime;
    }
    
    public function setEndTime(DateTimeInterface $endTime): self
    {
        $this->endTime = $endTime;
        return $this;
    }
    
    public function getAssignedTo(): ?UserEntity
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?UserEntity $assignedTo): void
    {
        $this->assignedTo = $assignedTo;
    }


    
    public function getStatus(): string
    {
        return $this->status;
    }
    
    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    public function getFormData(): ?string
    {
        return $this->formData;
    }
    
    public function getFormDataAsArray(): ?array
    {
        return $this->formData ? json_decode($this->formData, true) : null;
    }
    
    public function setFormData(?string $formData): self
    {
        $this->formData = $formData;
        return $this;
    }
    
    public function setFormDataFromArray(?array $formData): self
    {
        $this->formData = $formData ? json_encode($formData) : null;
        return $this;
    }
    
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
    
    public function setCancelled(bool $cancelled): self
    {
        $this->cancelled = $cancelled;
        return $this;
    }

    public function getBookingToken(): ?string
    {
        return $this->bookingToken;
    }

    public function setBookingToken(?string $bookingToken): self
    {
        $this->bookingToken = $bookingToken;
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

    public function toArray(): array
    {
        $data = [
            'id' => $this->getId(),
            'event_id' => $this->getEvent()->getId(),
            'start_time' => $this->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $this->getEndTime()->format('Y-m-d H:i:s'),
            'status' => $this->getStatus(),
            'form_data' => $this->getFormDataAsArray(),
            'cancelled' => $this->isCancelled(),
            'assigned_to' => $this->assignedTo ? $this->assignedTo->getId() : null,
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
        

        return $data;
    }
}