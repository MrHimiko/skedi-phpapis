<?php

namespace App\Plugins\Account\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\Account\Repository\UserAvailabilityRepository")]
#[ORM\Table(name: "user_availability")]
#[ORM\HasLifecycleCallbacks]
class UserAvailabilityEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $user;

    #[ORM\Column(name: "title", type: "string", length: 255, nullable: false)]
    private string $title;

    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: "start_time", type: "datetime", nullable: false)]
    private DateTimeInterface $startTime;

    #[ORM\Column(name: "end_time", type: "datetime", nullable: false)]
    private DateTimeInterface $endTime;

    #[ORM\Column(name: "source", type: "string", length: 50, nullable: false)]
    private string $source;

    #[ORM\Column(name: "source_id", type: "string", length: 255, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\ManyToOne(targetEntity: "App\Plugins\Events\Entity\EventEntity")]
    #[ORM\JoinColumn(name: "event_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?\App\Plugins\Events\Entity\EventEntity $event = null;

    #[ORM\ManyToOne(targetEntity: "App\Plugins\Events\Entity\EventBookingEntity")]
    #[ORM\JoinColumn(name: "booking_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?\App\Plugins\Events\Entity\EventBookingEntity $booking = null;

    #[ORM\Column(name: "status", type: "string", length: 50, options: ["default" => "confirmed"])]
    private string $status = 'confirmed';

    #[ORM\Column(name: "last_synced", type: "datetime", nullable: true)]
    private ?DateTimeInterface $lastSynced = null;

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
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function setUser(UserEntity $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }

    public function setSourceId(?string $sourceId): self
    {
        $this->sourceId = $sourceId;
        return $this;
    }

    public function getEvent(): ?\App\Plugins\Events\Entity\EventEntity
    {
        return $this->event;
    }

    public function setEvent(?\App\Plugins\Events\Entity\EventEntity $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function getBooking(): ?\App\Plugins\Events\Entity\EventBookingEntity
    {
        return $this->booking;
    }

    public function setBooking(?\App\Plugins\Events\Entity\EventBookingEntity $booking): self
    {
        $this->booking = $booking;
        return $this;
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

    public function getLastSynced(): ?DateTimeInterface
    {
        return $this->lastSynced;
    }

    public function setLastSynced(?DateTimeInterface $lastSynced): self
    {
        $this->lastSynced = $lastSynced;
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

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updated = new DateTime();
    }

    /**
     * Returns a minimal representation without sensitive details
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'start_time' => $this->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $this->getEndTime()->format('Y-m-d H:i:s'),
            'status' => $this->getStatus()
        ];
    }

    /**
     * Returns a complete representation with all details
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'start_time' => $this->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $this->getEndTime()->format('Y-m-d H:i:s'),
            'source' => $this->getSource(),
            'source_id' => $this->getSourceId(),
            'status' => $this->getStatus(),
            'deleted' => $this->isDeleted(),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s')
        ];

        if ($this->getEvent()) {
            $data['event_id'] = $this->getEvent()->getId();
        }

        if ($this->getBooking()) {
            $data['booking_id'] = $this->getBooking()->getId();
        }

        if ($this->getLastSynced()) {
            $data['last_synced'] = $this->getLastSynced()->format('Y-m-d H:i:s');
        }

        return $data;
    }
}