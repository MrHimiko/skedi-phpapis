<?php

namespace App\Plugins\Integrations\Google\Meet\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity; 
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\Integrations\Google\Meet\Repository\GoogleMeetEventRepository")]
#[ORM\Table(name: "integration_google_meet_events")]
#[ORM\Index(columns: ["user_id"])]
#[ORM\Index(columns: ["integration_id"])]
#[ORM\Index(columns: ["event_id"])]
#[ORM\Index(columns: ["booking_id"])]
#[ORM\Index(columns: ["start_time", "end_time"])]
class GoogleMeetEventEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $user;

    #[ORM\ManyToOne(targetEntity: IntegrationEntity::class)]
    #[ORM\JoinColumn(name: "integration_id", referencedColumnName: "id", nullable: false)]
    private IntegrationEntity $integration;

    #[ORM\Column(name: "event_id", type: "bigint", nullable: true)]
    private ?int $eventId = null;

    #[ORM\Column(name: "booking_id", type: "bigint", nullable: true)]
    private ?int $bookingId = null;

    #[ORM\Column(name: "meet_id", type: "string", length: 255)]
    private string $meetId;

    #[ORM\Column(name: "meet_link", type: "string", length: 512)]
    private string $meetLink;

    #[ORM\Column(name: "conference_data", type: "json", nullable: true)]
    private ?array $conferenceData = null;

    #[ORM\Column(name: "title", type: "string", length: 255)]
    private string $title;

    #[ORM\Column(name: "description", type: "text", nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: "start_time", type: "datetime", nullable: false)]
    private DateTimeInterface $startTime;

    #[ORM\Column(name: "end_time", type: "datetime", nullable: false)]
    private DateTimeInterface $endTime;

    #[ORM\Column(name: "status", type: "string", length: 50, options: ["default" => "active"])]
    private string $status = 'active';

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): ?int
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

    public function getIntegration(): IntegrationEntity
    {
        return $this->integration;
    }

    public function setIntegration(IntegrationEntity $integration): self
    {
        $this->integration = $integration;
        return $this;
    }

    public function getEventId(): ?int
    {
        return $this->eventId;
    }

    public function setEventId(?int $eventId): self
    {
        $this->eventId = $eventId;
        return $this;
    }

    public function getBookingId(): ?int
    {
        return $this->bookingId;
    }

    public function setBookingId(?int $bookingId): self
    {
        $this->bookingId = $bookingId;
        return $this;
    }

    public function getMeetId(): string
    {
        return $this->meetId;
    }

    public function setMeetId(string $meetId): self
    {
        $this->meetId = $meetId;
        return $this;
    }

    public function getMeetLink(): string
    {
        return $this->meetLink;
    }

    public function setMeetLink(string $meetLink): self
    {
        $this->meetLink = $meetLink;
        return $this;
    }

    public function getConferenceData(): ?array
    {
        return $this->conferenceData;
    }

    public function setConferenceData(?array $conferenceData): self
    {
        $this->conferenceData = $conferenceData;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
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

    public function setUpdated(DateTimeInterface $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'integration_id' => $this->getIntegration()->getId(),
            'event_id' => $this->getEventId(),
            'booking_id' => $this->getBookingId(),
            'meet_id' => $this->getMeetId(),
            'meet_link' => $this->getMeetLink(),
            'conference_data' => $this->getConferenceData(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'start_time' => $this->getStartTime()->format('Y-m-d H:i:s'),
            'end_time' => $this->getEndTime()->format('Y-m-d H:i:s'),
            'status' => $this->getStatus(),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];
    }
}